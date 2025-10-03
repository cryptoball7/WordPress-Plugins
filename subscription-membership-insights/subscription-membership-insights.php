<?php
/**
 * Plugin Name: Subscription & Membership Insights
 * Description: Tracks churn, member behavior, and retention analytics. Hooks into WooCommerce Subscriptions when present and records generic membership events. Admin dashboard with charts and exportable member events.
 * Version: 1.0.0
 * Author: ChatGPT (generated)
 * Text Domain: smi
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMI_Plugin {
	const TABLE_EVENTS = 'smi_member_events';
	const VERSION = '1.0.0';
	private static $instance = null;
	/** @var wpdb */
	private $wpdb;
	private $table_name;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->table_name = $wpdb->prefix . self::TABLE_EVENTS;

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX endpoints for admin
		add_action( 'wp_ajax_smi_get_metrics', array( $this, 'ajax_get_metrics' ) );
		add_action( 'wp_ajax_smi_get_members', array( $this, 'ajax_get_members' ) );
		add_action( 'wp_ajax_smi_export_events', array( $this, 'ajax_export_events' ) );

		// Public endpoint to record events (for webhooks / external integrations)
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Hook into user registration and profile updates
		add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'on_user_login' ), 10, 2 );

		// If WooCommerce Subscriptions is active, hook into subscription status updates
		add_action( 'plugins_loaded', array( $this, 'maybe_hook_woocommerce_subscriptions' ) );

		// Daily scheduled computation
		add_action( 'smi_daily_job', array( $this, 'daily_job' ) );
	}

	/* ---------- Activation / Deactivation ---------- */

	public function activate() {
		$this->create_tables();
		if ( ! wp_next_scheduled( 'smi_daily_job' ) ) {
			wp_schedule_event( time(), 'daily', 'smi_daily_job' );
		}
	}

	public function deactivate() {
		wp_clear_scheduled_hook( 'smi_daily_job' );
		// keep data (do not drop tables) to be safe — remove table code if you want to drop on uninstall
	}

	/* ---------- DB Setup ---------- */

	private function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$this->table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NULL,
			event_type VARCHAR(100) NOT NULL,
			event_subtype VARCHAR(100) NULL,
			event_meta LONGTEXT NULL,
			source VARCHAR(100) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/* ---------- Event Recording ---------- */

	/**
	 * Record an event in the events table
	 *
	 * @param array $args keys: user_id, event_type, event_subtype, event_meta (array|string), source
	 * @return int|false inserted id or false
	 */
	public function record_event( $args ) {
		$defaults = array(
			'user_id'      => null,
			'event_type'   => 'generic',
			'event_subtype'=> null,
			'event_meta'   => null,
			'source'       => null,
		);
		$data = wp_parse_args( $args, $defaults );

		$insert = array(
			'user_id'      => $data['user_id'],
			'event_type'   => sanitize_text_field( $data['event_type'] ),
			'event_subtype'=> $data['event_subtype'] ? sanitize_text_field( $data['event_subtype'] ) : null,
			'event_meta'   => is_array( $data['event_meta'] ) ? maybe_serialize( $data['event_meta'] ) : $data['event_meta'],
			'source'       => $data['source'] ? sanitize_text_field( $data['source'] ) : null,
			'created_at'   => current_time( 'mysql' ),
		);

		$format = array( '%d', '%s', '%s', '%s', '%s', '%s' );
		$success = $this->wpdb->insert( $this->table_name, $insert, $format );
		if ( $success ) {
			return (int) $this->wpdb->insert_id;
		}
		return false;
	}

	/* ---------- WordPress hooks to capture membershipy events ---------- */

	public function on_user_register( $user_id ) {
		$this->record_event( array(
			'user_id'    => $user_id,
			'event_type' => 'register',
			'source'     => 'wordpress',
		) );
	}

	public function on_profile_update( $user_id, $old_user_data ) {
		$this->record_event( array(
			'user_id'    => $user_id,
			'event_type' => 'profile_update',
			'event_meta' => array( 'old_display_name' => $old_user_data->display_name ),
			'source'     => 'wordpress',
		) );
	}

	public function on_user_login( $user_login, $user ) {
		$this->record_event( array(
			'user_id'    => $user->ID,
			'event_type' => 'login',
			'source'     => 'wordpress',
		) );
	}

	/* ---------- WooCommerce Subscriptions integration (if available) ---------- */

	public function maybe_hook_woocommerce_subscriptions() {
		// detect WooCommerce Subscriptions by checking for a common function/class
		if ( class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscription' ) || defined( 'WC_SUBSCRIPTIONS_PLUGIN_FILE' ) ) {
			// Hook to subscription status change. There are multiple hooks depending on version; register common ones
			add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_wc_subscription_status_updated' ), 10, 3 );
			add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'on_wc_subscription_cancelled' ), 10, 1 );
		}
	}

	public function on_wc_subscription_status_updated( $subscription, $new_status, $old_status ) {
		try {
			// $subscription can be object or subscription id; try to get user_id
			$user_id = null;
			if ( is_object( $subscription ) && method_exists( $subscription, 'get_user_id' ) ) {
				$user_id = (int) $subscription->get_user_id();
			} elseif ( is_numeric( $subscription ) ) {
				$sub = wcs_get_subscription( $subscription );
				if ( $sub ) {
					$user_id = (int) $sub->get_user_id();
				}
			}
			$this->record_event( array(
				'user_id'      => $user_id,
				'event_type'   => 'subscription_status_change',
				'event_subtype'=> $old_status . '_to_' . $new_status,
				'event_meta'   => array( 'old' => $old_status, 'new' => $new_status, 'subscription_id' => is_object($subscription) && method_exists($subscription,'get_id') ? $subscription->get_id() : $subscription ),
				'source'       => 'woocommerce_subscriptions',
			) );
		} catch ( Exception $e ) {
			// ignore to avoid breaking WC flows
		}
	}

	public function on_wc_subscription_cancelled( $subscription ) {
		// cancellation event
		$user_id = null;
		if ( is_object( $subscription ) && method_exists( $subscription, 'get_user_id' ) ) {
			$user_id = (int) $subscription->get_user_id();
		}
		$this->record_event( array(
			'user_id'     => $user_id,
			'event_type'  => 'subscription_cancelled',
			'source'      => 'woocommerce_subscriptions',
		) );
	}

	/* ---------- Admin UI ---------- */

	public function admin_menu() {
		add_menu_page(
			'Subscription Insights',
			'Sub & Member Insights',
			'manage_options',
			'smi_dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			60
		);

		add_submenu_page( 'smi_dashboard', 'Events', 'Events', 'manage_options', 'smi_events', array( $this, 'render_events_page' ) );
		add_submenu_page( 'smi_dashboard', 'Settings', 'Settings', 'manage_options', 'smi_settings', array( $this, 'render_settings_page' ) );
	}

	public function enqueue_admin_assets( $hook ) {
		$allowed_pages = array( 'toplevel_page_smi_dashboard', 'subscription-membership-insights_page_smi_events', 'subscription-membership-insights_page_smi_settings' );
		if ( ! in_array( $hook, $allowed_pages, true ) ) {
			return;
		}

		// Chart.js from CDN
		wp_enqueue_script( 'smi-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true );
		wp_enqueue_script( 'smi-admin-js', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery', 'smi-chartjs' ), self::VERSION, true );
		wp_localize_script( 'smi-admin-js', 'smi_ajax', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'smi_nonce' ),
			'rest_base'=> esc_url_raw( rest_url( '/smi/v1/' ) ),
		) );

		wp_enqueue_style( 'smi-admin-css', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), self::VERSION );
	}

	/* ---------- Admin Page Renderers (simple markup, JS will build charts) ---------- */

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No access' );
		}
		?>
		<div class="wrap">
			<h1>Subscription & Membership Insights</h1>
			<p>Overview of churn, retention, and member behavior. Charts are interactive — hover for details.</p>

			<div id="smi-charts" style="display:flex;gap:30px;flex-wrap:wrap;">
				<div style="flex:1;min-width:360px;">
					<h2>Churn (last 90 days)</h2>
					<canvas id="smi-churn-chart" width="600" height="300"></canvas>
				</div>

				<div style="flex:1;min-width:360px;">
					<h2>Active Members (last 90 days)</h2>
					<canvas id="smi-active-chart" width="600" height="300"></canvas>
				</div>

				<div style="width:100%;max-width:900px;">
					<h2>Retention Curve (cohort by signup month)</h2>
					<canvas id="smi-retention-chart" width="900" height="400"></canvas>
				</div>
			</div>

			<hr>
			<h2>Quick Actions</h2>
			<button id="smi-refresh-btn" class="button button-primary">Refresh Metrics</button>
			<p id="smi-last-updated" style="margin-top:8px;color:#666;"></p>
		</div>
		<?php
	}

	public function render_events_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No access' );
		}

		// Basic UI — server side returns JSON table via AJAX
		?>
		<div class="wrap">
			<h1>Member Events</h1>
			<p>All recorded membership-related events.</p>

			<div style="margin-bottom:12px;">
				<select id="smi-event-filter">
					<option value="">All event types</option>
					<option value="register">Register</option>
					<option value="login">Login</option>
					<option value="subscription_cancelled">Subscription Cancelled</option>
					<option value="subscription_status_change">Subscription Status Change</option>
					<option value="profile_update">Profile Update</option>
				</select>

				<button id="smi-load-events" class="button">Load events</button>
				<button id="smi-export-events" class="button">Export CSV</button>
			</div>

			<table id="smi-events-table" class="widefat fixed">
				<thead><tr><th>ID</th><th>User</th><th>Event</th><th>Subtype</th><th>Source</th><th>Meta</th><th>Date</th></tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No access' );
		}
		?>
		<div class="wrap">
			<h1>SMI Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'smi_settings_group' );
				do_settings_sections( 'smi_settings' );
				submit_button();
				?>
			</form>

			<h2>Integrations</h2>
			<ul>
				<li>WooCommerce Subscriptions: <?php echo class_exists( 'WC_Subscriptions' ) || class_exists( 'WC_Subscription' ) || defined( 'WC_SUBSCRIPTIONS_PLUGIN_FILE' ) ? '<strong>Detected</strong>' : 'Not detected'; ?></li>
				<li>External systems can record events using the REST endpoint <code>/wp-json/smi/v1/event</code> (POST) with a JSON body.</li>
			</ul>
		</div>
		<?php
	}

	/* ---------- AJAX Handlers ---------- */

	public function ajax_get_metrics() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access', 403 );
		}
		check_ajax_referer( 'smi_nonce', '_nonce' );

		$days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 90;
		if ( $days <= 0 ) {
			$days = 90;
		}

		$metrics = $this->compute_metrics_over_days( $days );
		wp_send_json_success( $metrics );
	}

	public function ajax_get_members() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access', 403 );
		}
		check_ajax_referer( 'smi_nonce', '_nonce' );

		$limit = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 100;
		$offset= isset( $_GET['offset'] ) ? (int) $_GET['offset'] : 0;
		$event_type = isset( $_GET['event_type'] ) ? sanitize_text_field( $_GET['event_type'] ) : '';

		$where = '';
		$params = array();
		if ( $event_type ) {
			$where = $this->wpdb->prepare( "WHERE event_type = %s", $event_type );
		}
		$sql = "SELECT * FROM {$this->table_name} $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$prepared_sql = $this->wpdb->prepare( "SELECT * FROM {$this->table_name} " . ( $where ? "WHERE event_type = %s " : "" ) . " ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$event_type ? $event_type : '', $limit, $offset );

		// Alternative: build simpler depending on if filter set
		if ( $event_type ) {
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE event_type = %s ORDER BY created_at DESC LIMIT %d OFFSET %d", $event_type, $limit, $offset ), ARRAY_A );
		} else {
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}

		// attach basic user display name
		foreach ( $rows as &$r ) {
			$r['display_name'] = $r['user_id'] ? get_the_author_meta( 'display_name', $r['user_id'] ) : '';
			$r['event_meta'] = is_serialized( $r['event_meta'] ) ? maybe_unserialize( $r['event_meta'] ) : $r['event_meta'];
		}

		wp_send_json_success( $rows );
	}

	public function ajax_export_events() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No access', 403 );
		}
		check_ajax_referer( 'smi_nonce', '_nonce' );

		// Basic CSV export of last 10k events (safe limit)
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table_name} ORDER BY created_at DESC LIMIT %d", 10000 ), ARRAY_A );

		$filename = 'smi-events-' . date( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'user_id', 'display_name', 'event_type', 'event_subtype', 'source', 'event_meta', 'created_at' ) );
		foreach ( $rows as $r ) {
			$display = $r['user_id'] ? get_the_author_meta( 'display_name', $r['user_id'] ) : '';
			$meta = is_serialized( $r['event_meta'] ) ? maybe_unserialize( $r['event_meta'] ) : $r['event_meta'];
			fputcsv( $out, array( $r['id'], $r['user_id'], $display, $r['event_type'], $r['event_subtype'], $r['source'], is_array( $meta ) ? json_encode( $meta ) : $meta, $r['created_at'] ) );
		}
		fclose( $out );
		exit;
	}

	/* ---------- REST API for external event ingestion ---------- */

	public function register_rest_routes() {
		register_rest_route( 'smi/v1', '/event', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_record_event' ),
			'permission_callback' => function ( WP_REST_Request $request ) {
				// Accepts either a valid nonce in header (optional) OR a secret token saved in options.
				$headers = $request->get_headers();
				$nonce = isset( $headers['x-wp-nonce'] ) ? $headers['x-wp-nonce'][0] : '';
				if ( $nonce && wp_verify_nonce( $nonce, 'smi_nonce' ) ) {
					return true;
				}
				$token = isset( $headers['x-smi-token'] ) ? $headers['x-smi-token'][0] : '';
				$saved = get_option( 'smi_api_token', '' );
				if ( $token && $saved && hash_equals( $saved, $token ) ) {
					return true;
				}
				// else require authentication (admins)
				return current_user_can( 'manage_options' );
			}
		) );
	}

	public function rest_record_event( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( empty( $body ) ) {
			return new WP_REST_Response( array( 'error' => 'Empty body' ), 400 );
		}
		$user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : null;
		$evt = isset( $body['event_type'] ) ? sanitize_text_field( $body['event_type'] ) : 'external';
		$sub = isset( $body['event_subtype'] ) ? sanitize_text_field( $body['event_subtype'] ) : null;
		$meta = isset( $body['event_meta'] ) ? $body['event_meta'] : null;
		$source = isset( $body['source'] ) ? sanitize_text_field( $body['source'] ) : 'external';

		$id = $this->record_event( array(
			'user_id'      => $user_id,
			'event_type'   => $evt,
			'event_subtype'=> $sub,
			'event_meta'   => $meta,
			'source'       => $source,
		) );

		if ( $id ) {
			return new WP_REST_Response( array( 'success' => true, 'id' => $id ), 201 );
		}
		return new WP_REST_Response( array( 'success' => false ), 500 );
	}

	/* ---------- Metrics computation (simple, performant) ---------- */

	/**
	 * Compute churn and active counts across the last $days days.
	 * Returns arrays suitable for Chart.js consumption.
	 */
	private function compute_metrics_over_days( $days = 90 ) {
		$days = max( 7, min( 365, (int) $days ) );
		$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . $days . ' days' ) );
		$sql = $this->wpdb->prepare(
			"SELECT DATE(created_at) as d, event_type, COUNT(*) as cnt
			 FROM {$this->table_name}
			 WHERE created_at >= %s
			 GROUP BY DATE(created_at), event_type
			 ORDER BY DATE(created_at) ASC",
			$start
		);
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		// Initialize date map
		$labels = array();
		$by_date = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d = date( 'Y-m-d', strtotime( "-$i days" ) );
			$labels[] = $d;
			$by_date[ $d ] = array();
		}

		foreach ( $rows as $r ) {
			$d = $r['d'];
			if ( ! isset( $by_date[ $d ] ) ) {
				$by_date[ $d ] = array();
			}
			$by_date[ $d ][ $r['event_type'] ] = (int) $r['cnt'];
		}

		$churn = array();
		$active = array();
		$logins = array();
		foreach ( $labels as $d ) {
			$churn[] = isset( $by_date[ $d ]['subscription_cancelled'] ) ? $by_date[ $d ]['subscription_cancelled'] : 0;
			$active[] = isset( $by_date[ $d ]['login'] ) ? $by_date[ $d ]['login'] : 0; // proxy for activity
			$logins[] = isset( $by_date[ $d ]['login'] ) ? $by_date[ $d ]['login'] : 0;
		}

		// retention cohorts (simple): compute cohort table by month for last 6 months
		$cohorts = $this->compute_simple_retention_cohorts( 6 );

		return array(
			'labels'  => $labels,
			'churn'   => $churn,
			'active'  => $active,
			'logins'  => $logins,
			'cohorts' => $cohorts,
			'computed_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Simple retention cohorts - cohorts by signup month with retention measured by presence of login events in subsequent months.
	 *
	 * @param int $months number of months to include
	 * @return array labels + data matrix
	 */
	private function compute_simple_retention_cohorts( $months = 6 ) {
		$months = max( 3, min( 24, (int) $months ) );
		// Get users who registered in last $months months
		$start = date( 'Y-m-01 00:00:00', strtotime( "-$months months" ) );
		$sql_users = $this->wpdb->prepare( "SELECT ID, user_registered FROM {$this->wpdb->users} WHERE user_registered >= %s", $start );
		$users = $this->wpdb->get_results( $sql_users, ARRAY_A );

		// Map user->cohort month (YYYY-MM)
		$cohort_map = array();
		foreach ( $users as $u ) {
			$cohort = date( 'Y-m', strtotime( $u['user_registered'] ) );
			$cohort_map[ $u['ID'] ] = $cohort;
		}

		// Get login events for these users
		if ( empty( $cohort_map ) ) {
			return array( 'labels' => array(), 'data' => array(), 'cohort_sizes' => array() );
		}

		$user_ids = array_keys( $cohort_map );
		$ids_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$sql = "SELECT user_id, DATE(created_at) as d, event_type FROM {$this->table_name} WHERE user_id IN ($ids_placeholders) AND event_type = 'login' ORDER BY created_at";
		$args = $user_ids;
		$prepared = call_user_func_array( array( $this->wpdb, 'prepare' ), array_merge( array( $sql ), $args ) );
		$login_rows = $this->wpdb->get_results( $prepared, ARRAY_A );

		// bucket logins by user and month
		$presence = array(); // cohort => [month_index => set(user_ids)]
		$cohort_sizes = array();
		$labels = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$labels[] = date( 'Y-m', strtotime( "-$i months" ) );
		}
		$labels = array_reverse( $labels );

		foreach ( $cohort_map as $uid => $cohort ) {
			if ( ! isset( $cohort_sizes[ $cohort ] ) ) {
				$cohort_sizes[ $cohort ] = 0;
			}
			$cohort_sizes[ $cohort ]++;
		}

		// initialize presence sets for each cohort and month index
		foreach ( $cohort_sizes as $cohort => $size ) {
			$presence[ $cohort ] = array_fill( 0, count( $labels ), array() );
		}

		foreach ( $login_rows as $lr ) {
			$uid = $lr['user_id'];
			$dt = strtotime( $lr['d'] );
			$month = date( 'Y-m', $dt );
			// month index relative to labels
			$idx = array_search( $month, $labels, true );
			if ( $idx === false ) {
				continue;
			}
			$cohort = isset( $cohort_map[ $uid ] ) ? $cohort_map[ $uid ] : null;
			if ( ! $cohort ) {
				continue;
			}
			$presence[ $cohort ][ $idx ][ $uid ] = true;
		}

		// Build retention matrix (percentage)
		$data = array();
		foreach ( $presence as $cohort => $months_array ) {
			$row = array();
			$size = max( 1, $cohort_sizes[ $cohort ] );
			foreach ( $months_array as $idx => $set ) {
				$row[] = round( ( count( (array) $set ) / $size ) * 100, 1 );
			}
			$data[ $cohort ] = $row;
		}

		return array( 'labels' => $labels, 'data' => $data, 'cohort_sizes' => $cohort_sizes );
	}

	/* ---------- Daily job (example: could precompute expensive metrics) ---------- */

	public function daily_job() {
		// Example: compute and store some summary stats as options
		$metrics = $this->compute_metrics_over_days( 30 );
		update_option( 'smi_last_30d_metrics', $metrics );
	}

} // end class

// Initialize plugin
SMI_Plugin::instance();

/* ---------- Simple bundled admin.js and admin.css assets (create files in assets/) ---------- */
/**
 * Note: Because this is a single-file plugin drop-in, below I include sample content for the JS and CSS files
 * that should be placed in plugin_dir/assets/admin.js and plugin_dir/assets/admin.css respectively.
 *
 * Create folder subscription-membership-insights/assets and add admin.js and admin.css with the content below.
 */

/* --------------- admin.js ---------------
jQuery(document).ready(function($){
    function fetchMetrics(days){
        days = days || 90;
        $('#smi-last-updated').text('Loading...');
        $.get( smi_ajax.ajax_url, { action: 'smi_get_metrics', _nonce: smi_ajax.nonce, days: days } )
            .done(function(resp){
                if(!resp.success){ $('#smi-last-updated').text('Failed to load'); return; }
                renderCharts(resp.data);
                $('#smi-last-updated').text('Last computed: ' + resp.data.computed_at);
            });
    }

    function renderCharts(data){
        // churn chart
        const churnCtx = document.getElementById('smi-churn-chart').getContext('2d');
        if(window.smiChurnChart) window.smiChurnChart.destroy();
        window.smiChurnChart = new Chart(churnCtx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{ label: 'Churn (cancellations)', data: data.churn, tension:0.2 }]
            },
            options: { responsive:true, maintainAspectRatio:false }
        });

        const activeCtx = document.getElementById('smi-active-chart').getContext('2d');
        if(window.smiActiveChart) window.smiActiveChart.destroy();
        window.smiActiveChart = new Chart(activeCtx, {
            type: 'bar',
            data: { labels: data.labels, datasets: [{ label: 'Active (logins)', data: data.active }] },
            options: { responsive:true, maintainAspectRatio:false }
        });

        // retention: simple multiple-line per cohort
        const retentionCtx = document.getElementById('smi-retention-chart').getContext('2d');
        if(window.smiRetentionChart) window.smiRetentionChart.destroy();
        const cohorts = data.cohorts;
        const labels = cohorts.labels;
        const datasets = [];
        Object.keys(cohorts.data).forEach(function(cohort, idx){
            datasets.push({
                label: cohort + ' (n=' + (cohorts.cohort_sizes[cohort] || 0) + ')',
                data: cohorts.data[cohort],
                fill: false,
                tension:0.2
            });
        });
        window.smiRetentionChart = new Chart(retentionCtx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: { responsive:true, maintainAspectRatio:false }
        });
    }

    $('#smi-refresh-btn').on('click', function(){ fetchMetrics(90); });

    // events page
    $('#smi-load-events').on('click', function(){
        const type = $('#smi-event-filter').val();
        $('#smi-events-table tbody').html('<tr><td colspan="7">Loading...</td></tr>');
        $.get( smi_ajax.ajax_url, { action:'smi_get_members', _nonce: smi_ajax.nonce, event_type: type, limit:100 } )
            .done(function(resp){
                if(!resp.success){ $('#smi-events-table tbody').html('<tr><td colspan="7">Failed to load</td></tr>'); return; }
                const rows = resp.data;
                let html = '';
                rows.forEach(function(r){
                    html += '<tr>';
                    html += '<td>' + r.id + '</td>';
                    html += '<td>' + (r.display_name || r.user_id || '') + '</td>';
                    html += '<td>' + r.event_type + '</td>';
                    html += '<td>' + (r.event_subtype || '') + '</td>';
                    html += '<td>' + (r.source || '') + '</td>';
                    html += '<td>' + (typeof r.event_meta === 'object' ? JSON.stringify(r.event_meta) : (r.event_meta||'')) + '</td>';
                    html += '<td>' + r.created_at + '</td>';
                    html += '</tr>';
                });
                $('#smi-events-table tbody').html(html);
            });
    });

    $('#smi-export-events').on('click', function(){
        // open iframe to trigger download
        const loc = smi_ajax.ajax_url + '?action=smi_export_events&_nonce=' + smi_ajax.nonce;
        window.open(loc, '_blank');
    });

    // initial load if on dashboard
    if($('#smi-churn-chart').length) fetchMetrics(90);
});
-------------------------------------- */

/* --------------- admin.css ---------------
#smi-charts canvas { background: #fff; border: 1px solid #eee; padding: 8px; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,.02); }
#smi-events-table td, #smi-events-table th { vertical-align: middle; }
-------------------------------------- */

