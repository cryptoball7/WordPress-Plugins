<?php
/**
 * Plugin Name: AI Security Guard
 * Description: Lightweight AI-style security assistant: logs login events, detects unusual login patterns, scans plugins for outdated versions and suspicious code constructs, and surfaces alerts in an admin dashboard.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 *
 * Notes:
 * - Requires WordPress 5.0+ (uses WP REST and HTTP API).
 * - Stores events in its own DB table on activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AI_Security_Guard {
	private static $instance = null;
	public $version = '1.0.0';
	public $table_events;
	public $option_name = 'aisg_options';

	/** Singleton */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table_events = $wpdb->prefix . 'aisg_login_events';

		register_activation_hook( __FILE__, array( $this, 'on_activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'on_deactivation' ) );

		// Hooks for login events
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 10, 2 );               // successful login
		add_action( 'wp_login_failed', array( $this, 'on_wp_login_failed' ) );       // failed
		add_action( 'wp_logout', array( $this, 'on_wp_logout' ) );                  // logout

		// Admin menu and AJAX
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_aisg_manual_scan', array( $this, 'handle_manual_scan' ) );

		// Schedule daily plugin scan
		add_action( 'aisg_daily_scan_event', array( $this, 'daily_plugin_scan' ) );

		// REST endpoint for stats (optional)
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Default options
		add_action( 'init', array( $this, 'maybe_set_default_options' ) );
	}

	/* ---------- Activation / Deactivation ---------- */

	public function on_activation() {
		$this->create_tables();
		$this->maybe_set_default_options();
		if ( ! wp_next_scheduled( 'aisg_daily_scan_event' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'aisg_daily_scan_event' ); // run daily
		}
	}

	public function on_deactivation() {
		// clear scheduled event
		$timestamp = wp_next_scheduled( 'aisg_daily_scan_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'aisg_daily_scan_event' );
		}
	}

	private function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table = $this->table_events;

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_time DATETIME NOT NULL,
			event_type VARCHAR(60) NOT NULL,
			username VARCHAR(191) NULL,
			ip VARCHAR(100) NULL,
			user_agent TEXT NULL,
			details TEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_time (event_time),
			KEY idx_ip (ip)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ---------- Options ---------- */

	public function maybe_set_default_options() {
		$defaults = array(
			'failed_threshold' => 8,         // failed attempts from single IP within window triggers alert
			'failed_window_minutes' => 15,   // window in minutes
			'account_from_same_ip_threshold' => 6, // number distinct accounts tried from same IP within window
			'impossible_travel_minutes' => 60, // if same user logs in from different IPs within X minutes -> flag
			'email_alerts' => 0,
			'email_to' => get_option( 'admin_email' ),
			'last_scan' => 0,
			'scan_options' => array(
				'check_wp_org_version' => 1,  // check plugin versions against WP.org
				'pattern_checks' => 1,       // scan plugin files for suspicious tokens
			),
		);

		$opts = get_option( $this->option_name );
		if ( ! $opts ) {
			update_option( $this->option_name, $defaults );
		} else {
			// merge missing keys
			$merged = wp_parse_args( $opts, $defaults );
			update_option( $this->option_name, $merged );
		}
	}

	/* ---------- Helpers ---------- */

	private function get_ip() {
		// Prefer WP's method if available
		if ( function_exists( 'wp_get_raw_referer' ) ) {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		} else {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		}
		// sanitize
		return sanitize_text_field( $ip );
	}

	private function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
	}

	private function record_event( $type, $username = '', $details = '' ) {
		global $wpdb;
		$wpdb->insert(
			$this->table_events,
			array(
				'event_time' => current_time( 'mysql', 1 ), // GMT time in DB
				'event_type' => sanitize_text_field( $type ),
				'username'   => $username ? sanitize_text_field( $username ) : null,
				'ip'         => $this->get_ip(),
				'user_agent' => $this->user_agent(),
				'details'    => $details ? wp_json_encode( $details ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/* ---------- Login hooks ---------- */

	public function on_wp_login( $user_login, $user ) {
		$this->record_event( 'login_success', $user_login );

		// After recording, run checks for impossible travel
		$this->detect_impossible_travel( $user_login );
	}

	public function on_wp_login_failed( $username ) {
		$this->record_event( 'login_failed', $username );

		// run heuristics
		$this->detect_burst_failures();
		$this->detect_many_accounts_from_ip();
	}

	public function on_wp_logout() {
		$this->record_event( 'logout', wp_get_current_user()->user_login ?? '' );
	}

	/* ---------- Detection heuristics ---------- */

	private function get_recent_events( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'seconds' => 3600,
			'event_type' => '',
			'ip' => '',
			'username' => '',
		);
		$args = wp_parse_args( $args, $defaults );
		$time_from = gmdate( 'Y-m-d H:i:s', time() - (int) $args['seconds'] );

		$sql = "SELECT * FROM {$this->table_events} WHERE event_time >= %s";
		$params = array( $time_from );

		if ( $args['event_type'] ) {
			$sql .= " AND event_type = %s";
			$params[] = $args['event_type'];
		}
		if ( $args['ip'] ) {
			$sql .= " AND ip = %s";
			$params[] = $args['ip'];
		}
		if ( $args['username'] ) {
			$sql .= " AND username = %s";
			$params[] = $args['username'];
		}
		$sql .= " ORDER BY event_time DESC LIMIT 1000";

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	private function detect_burst_failures() {
		$opts = get_option( $this->option_name );
		$window = (int) $opts['failed_window_minutes'];
		$threshold = (int) $opts['failed_threshold'];
		$ip = $this->get_ip();
		if ( ! $ip ) {
			return;
		}

		$events = $this->get_recent_events( array( 'seconds' => $window * 60, 'event_type' => 'login_failed', 'ip' => $ip ) );
		if ( count( $events ) >= $threshold ) {
			// create an alert record
			$this->record_event( 'alert_burst_failures', '', array(
				'ip' => $ip,
				'count' => count( $events ),
				'window_minutes' => $window,
			) );
			$this->maybe_send_email_alert( 'Burst failed login attempts', sprintf(
				'IP %s had %d failed login attempts within %d minutes.',
				$ip,
				count( $events ),
				$window
			) );
		}
	}

	private function detect_many_accounts_from_ip() {
		$opts = get_option( $this->option_name );
		$window = (int) $opts['failed_window_minutes'];
		$threshold = (int) $opts['account_from_same_ip_threshold'];
		$ip = $this->get_ip();
		if ( ! $ip ) {
			return;
		}
		$events = $this->get_recent_events( array( 'seconds' => $window * 60, 'event_type' => 'login_failed', 'ip' => $ip ) );
		$usernames = array();
		foreach ( $events as $e ) {
			if ( $e->username ) {
				$usernames[ $e->username ] = true;
			}
		}
		$num = count( $usernames );
		if ( $num >= $threshold ) {
			$this->record_event( 'alert_many_accounts', '', array(
				'ip' => $ip,
				'accounts_count' => $num,
				'accounts' => array_keys( $usernames ),
			) );
			$this->maybe_send_email_alert( 'Many account login attempts from same IP', sprintf(
				'IP %s attempted logins for %d distinct accounts within %d minutes.',
				$ip,
				$num,
				$window
			) );
		}
	}

	private function detect_impossible_travel( $username ) {
		$opts = get_option( $this->option_name );
		$window = (int) $opts['impossible_travel_minutes'];
		if ( ! $username ) {
			return;
		}
		// Find last successful login for this user before now (excluding current)
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s', time() );
		$sql = $wpdb->prepare( "SELECT * FROM {$this->table_events} WHERE username = %s AND event_type = %s ORDER BY event_time DESC LIMIT 2", $username, 'login_success' );
		$rows = $wpdb->get_results( $sql );
		if ( count( $rows ) < 2 ) {
			return; // not enough history
		}
		$last = $rows[1]; // previous one
		$current_ip = $this->get_ip();
		if ( ! $last->ip || ! $current_ip || $last->ip === $current_ip ) {
			return;
		}
		$last_time = strtotime( $last->event_time );
		$diff_min = abs( ( time() - $last_time ) / 60 );
		if ( $diff_min <= $window ) {
			// crude impossible travel heuristic: if IPs differ in the first 2 octets (IPv4) quickly, flag
			$short_last = $this->ip_shorthand( $last->ip );
			$short_cur  = $this->ip_shorthand( $current_ip );
			if ( $short_last !== $short_cur ) {
				$this->record_event( 'alert_impossible_travel', $username, array(
					'from_ip' => $last->ip,
					'to_ip' => $current_ip,
					'minutes_apart' => $diff_min,
				) );
				$this->maybe_send_email_alert( 'Impossible travel detected', sprintf(
					'User %s logged in from %s then from %s within %.1f minutes.',
					$username, $last->ip, $current_ip, $diff_min
				) );
			}
		}
	}

	private function ip_shorthand( $ip ) {
		// reduce to first two octets for IPv4, or first 4 groups for IPv6
		if ( strpos( $ip, ':' ) !== false ) {
			$parts = explode( ':', $ip );
			return implode( ':', array_slice( $parts, 0, 4 ) );
		} else {
			$parts = explode( '.', $ip );
			return implode( '.', array_slice( $parts, 0, 2 ) );
		}
	}

	/* ---------- Alerts / Email ---------- */

	private function maybe_send_email_alert( $subject, $message ) {
		$opts = get_option( $this->option_name );
		if ( isset( $opts['email_alerts'] ) && (int) $opts['email_alerts'] === 1 ) {
			wp_mail( sanitize_email( $opts['email_to'] ), '[AI Security Guard] ' . $subject, $message );
		}
	}

	/* ---------- Plugin scanning ---------- */

	public function daily_plugin_scan() {
		// perform a scan similar to manual scan
		$this->scan_plugins_and_record();
	}

	public function handle_manual_scan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}
		check_admin_referer( 'aisg_manual_scan_action', 'aisg_manual_scan_nonce' );
		$this->scan_plugins_and_record();
		wp_safe_redirect( admin_url( 'tools.php?page=aisg-dashboard&scan=done' ) );
		exit;
	}

	private function scan_plugins_and_record() {
		$results = $this->scan_plugins();
		// record summary
		$this->record_event( 'plugin_scan', '', array( 'summary' => $results ) );
		update_option( $this->option_name, array_merge( get_option( $this->option_name ), array( 'last_scan' => time() ) ) );
		return $results;
	}

	public function scan_plugins() {
		$opts = get_option( $this->option_name );
		$check_versions = (bool) $opts['scan_options']['check_wp_org_version'];
		$pattern_checks = (bool) $opts['scan_options']['pattern_checks'];

		$active_plugins = get_plugins();
		$results = array();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( $active_plugins as $path => $plugin ) {
			// $path is like "akismet/akismet.php"
			$slug = dirname( $path );
			$installed_version = isset( $plugin['Version'] ) ? $plugin['Version'] : 'unknown';
			$entry = array(
				'name' => $plugin['Name'],
				'slug' => $slug,
				'installed_version' => $installed_version,
				'status' => 'ok',
				'notes' => array(),
			);

			// 1) WP.org version check
			if ( $check_versions ) {
				$remote = $this->fetch_wporg_plugin_info( $slug );
				if ( is_array( $remote ) && ! empty( $remote['version'] ) ) {
					$remote_version = $remote['version'];
					if ( version_compare( $installed_version, $remote_version, '<' ) ) {
						$entry['status'] = 'outdated';
						$entry['notes'][] = sprintf( 'Installed %s, latest %s on WP.org', $installed_version, $remote_version );
					}
				}
			}

			// 2) pattern checks: scan plugin files for suspicious tokens
			if ( $pattern_checks ) {
				$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;
				$patterns = $this->suspicious_patterns();
				$file_matches = $this->scan_directory_for_patterns( $plugin_dir, $patterns );
				if ( ! empty( $file_matches ) ) {
					$entry['status'] = ( $entry['status'] === 'ok' ) ? 'suspicious' : $entry['status'];
					$entry['notes'][] = 'Suspicious code patterns found in files: ' . implode( ', ', array_keys( $file_matches ) );
					$entry['file_matches'] = $file_matches;
				}
			}

			// 3) basic world-writable check
			$writable_files = $this->scan_plugin_writable_files( $plugin_dir );
			if ( ! empty( $writable_files ) ) {
				$entry['notes'][] = 'Writable files: ' . implode( ', ', $writable_files );
				$entry['status'] = ( $entry['status'] === 'ok' ) ? 'warning' : $entry['status'];
			}

			$results[] = $entry;
		}

		return $results;
	}

	private function fetch_wporg_plugin_info( $slug ) {
		// Use WP.org Plugins API
		$api = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug );
		$res = wp_remote_get( $api, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return null;
		}
		return $data;
	}

	private function suspicious_patterns() {
		// Patterns to flag for manual review
		return array(
			'base64_decode\\s*\\(',
			'eval\\s*\\(',
			'create_function\\s*\\(',
			'preg_replace\\s*\\(\\s*.*\\s*,\\s*.*\\s*,\\s*.*\\s*,\\s*\\w*e\\w*\\)',
			'shell_exec\\s*\\(',
			'passthru\\s*\\(',
			'system\\s*\\(',
			'assert\\s*\\(',
			'gzuncompress\\s*\\(',
		);
	}

	private function scan_directory_for_patterns( $dir, $patterns ) {
		$matches = array();
		if ( ! is_dir( $dir ) ) {
			return $matches;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				continue;
			}
			$path = $file->getRealPath();
			// limit scan to php files and small files
			$ext = pathinfo( $path, PATHINFO_EXTENSION );
			if ( ! in_array( strtolower( $ext ), array( 'php', 'inc' ), true ) ) {
				continue;
			}
			$size = filesize( $path );
			if ( $size === 0 || $size > 1024 * 500 ) { // skip >500KB to avoid huge files
				continue;
			}
			$contents = @file_get_contents( $path );
			if ( $contents === false ) {
				continue;
			}
			foreach ( $patterns as $pat ) {
				if ( preg_match( '/' . $pat . '/i', $contents ) ) {
					$matches[ $path ][] = $pat;
				}
			}
		}
		return $matches;
	}

	private function scan_plugin_writable_files( $dir ) {
		$writable = array();
		if ( ! is_dir( $dir ) ) {
			return $writable;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				continue;
			}
			$path = $file->getRealPath();
			if ( is_writable( $path ) ) {
				$writable[] = $path;
			}
		}
		return $writable;
	}

	/* ---------- Admin UI ---------- */

	public function admin_menu() {
		add_management_page( 'AI Security Guard', 'AI Security Guard', 'manage_options', 'aisg-dashboard', array( $this, 'admin_page' ) );
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$opts = get_option( $this->option_name );
		$last_scan = isset( $opts['last_scan'] ) && $opts['last_scan'] ? date( 'Y-m-d H:i:s', (int) $opts['last_scan'] ) : 'never';

		// Quick stats
		$recent_failures = $this->get_recent_events( array( 'seconds' => 24 * 3600, 'event_type' => 'login_failed' ) );
		$recent_success = $this->get_recent_events( array( 'seconds' => 24 * 3600, 'event_type' => 'login_success' ) );
		$alerts = $this->get_recent_events( array( 'seconds' => 7 * 24 * 3600 ) );

		?>
		<div class="wrap">
			<h1>AI Security Guard</h1>

			<h2>Overview</h2>
			<p>Last plugin scan: <strong><?php echo esc_html( $last_scan ); ?></strong></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'aisg_manual_scan_action', 'aisg_manual_scan_nonce' ); ?>
				<input type="hidden" name="action" value="aisg_manual_scan">
				<?php submit_button( 'Run manual plugin scan' ); ?>
			</form>

			<h2>Recent activity (24h)</h2>
			<ul>
				<li>Failed login attempts: <strong><?php echo count( $recent_failures ); ?></strong></li>
				<li>Successful logins: <strong><?php echo count( $recent_success ); ?></strong></li>
				<li>Recent alerts/events (7 days): <strong><?php echo count( $alerts ); ?></strong></li>
			</ul>

			<h2>Recent alerts (7 days)</h2>
			<table class="widefat fixed striped">
				<thead>
					<tr><th>Time (UTC)</th><th>Type</th><th>Username</th><th>IP</th><th>Details</th></tr>
				</thead>
				<tbody>
				<?php
				global $wpdb;
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_events} WHERE event_time >= %s ORDER BY event_time DESC LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - 7 * 24 * 3600 ) ) );
				foreach ( $rows as $r ) {
					$details = $r->details ? esc_html( wp_json_encode( json_decode( $r->details, true ), JSON_PRETTY_PRINT ) ) : '';
					echo '<tr>';
					echo '<td>' . esc_html( $r->event_time ) . '</td>';
					echo '<td>' . esc_html( $r->event_type ) . '</td>';
					echo '<td>' . esc_html( $r->username ) . '</td>';
					echo '<td>' . esc_html( $r->ip ) . '</td>';
					echo '<td><pre style="max-height:120px;overflow:auto;margin:0;">' . $details . '</pre></td>';
					echo '</tr>';
				}
				?>
				</tbody>
			</table>

			<h2>Settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'aisg_options_group' ); ?>
				<?php $options = get_option( $this->option_name ); ?>
				<table class="form-table">
					<tr>
						<th><label for="failed_threshold">Failed attempts threshold</label></th>
						<td><input name="<?php echo esc_attr( $this->option_name ); ?>[failed_threshold]" type="number" value="<?php echo esc_attr( $options['failed_threshold'] ); ?>" min="1" /></td>
					</tr>
					<tr>
						<th><label for="failed_window_minutes">Window (minutes)</label></th>
						<td><input name="<?php echo esc_attr( $this->option_name ); ?>[failed_window_minutes]" type="number" value="<?php echo esc_attr( $options['failed_window_minutes'] ); ?>" min="1" /></td>
					</tr>
					<tr>
						<th><label for="account_from_same_ip_threshold">Distinct accounts from same IP threshold</label></th>
						<td><input name="<?php echo esc_attr( $this->option_name ); ?>[account_from_same_ip_threshold]" type="number" value="<?php echo esc_attr( $options['account_from_same_ip_threshold'] ); ?>" min="1" /></td>
					</tr>
					<tr>
						<th><label for="impossible_travel_minutes">Impossible travel window (minutes)</label></th>
						<td><input name="<?php echo esc_attr( $this->option_name ); ?>[impossible_travel_minutes]" type="number" value="<?php echo esc_attr( $options['impossible_travel_minutes'] ); ?>" min="1" /></td>
					</tr>
					<tr>
						<th><label for="email_alerts">Email alerts</label></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[email_alerts]" value="1" <?php checked( $options['email_alerts'], 1 ); ?> /> Enable</label><br />
							<input name="<?php echo esc_attr( $this->option_name ); ?>[email_to]" type="email" value="<?php echo esc_attr( $options['email_to'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th><label>Scan options</label></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[scan_options][check_wp_org_version]" value="1" <?php checked( $options['scan_options']['check_wp_org_version'], 1 ); ?> /> Check WP.org for latest plugin versions</label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[scan_options][pattern_checks]" value="1" <?php checked( $options['scan_options']['pattern_checks'], 1 ); ?> /> Scan plugin files for suspicious tokens (eval/base64/etc)</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<h2>Plugin scan results (last run)</h2>
			<?php
			$last_scan_events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_events} WHERE event_type = %s ORDER BY event_time DESC LIMIT 1", 'plugin_scan' ) );
			if ( empty( $last_scan_events ) ) {
				echo '<p>No scan data yet. Run a manual scan.</p>';
			} else {
				$scan = $last_scan_events[0];
				$details = json_decode( $scan->details, true );
				echo '<pre style="max-height:400px;overflow:auto;background:#fff;padding:10px;border:1px solid #ddd;">' . esc_html( wp_json_encode( $details, JSON_PRETTY_PRINT ) ) . '</pre>';
			}
			?>

			<p style="margin-top:1.5em;font-size:90%">Tip: This plugin runs heuristics to help you spot suspicious login activity and potentially vulnerable plugin code, but it does not replace a security audit. Flagged items require manual review.</p>
		</div>
		<?php
	}

	/* ---------- REST routes (optional) ---------- */

	public function register_rest_routes() {
		register_rest_route( 'aisg/v1', '/stats', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'rest_stats' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	public function rest_stats( $request ) {
		$failures = $this->get_recent_events( array( 'seconds' => 24 * 3600, 'event_type' => 'login_failed' ) );
		$success  = $this->get_recent_events( array( 'seconds' => 24 * 3600, 'event_type' => 'login_success' ) );
		return rest_ensure_response( array(
			'failed' => count( $failures ),
			'success' => count( $success ),
		) );
	}
}

/* Initialize plugin */
AI_Security_Guard::instance();

/* Helper: get_plugins() fallback if not available (in some contexts) */
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
