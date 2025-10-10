<?php
/**
 * Plugin Name: Heatmap + Scrollmap (Built-in)
 * Description: Records visitor mouse positions & scroll depths and provides built-in heatmap + scrollmap visualizations without 3rd-party tools.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: heatmap-scrollmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HS_Heatmap_Scrollmap {
	const DB_VERSION = '1.0';
	const TABLE_EVENTS = 'hs_events';

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	public function activate() {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TABLE_EVENTS;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id VARCHAR(64) NOT NULL,
			event_type VARCHAR(32) NOT NULL,
			page_url TEXT NOT NULL,
			x INT NULL,
			y INT NULL,
			viewport_w INT NULL,
			viewport_h INT NULL,
			scroll_percent FLOAT NULL,
			user_agent TEXT NULL,
			ip_addr VARCHAR(45) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY session_idx (session_id),
			KEY event_type_idx (event_type),
			KEY created_idx (created_at)
		) {$charset_collate};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		add_option( 'hs_db_version', self::DB_VERSION );
	}

	public function deactivate() {
		// Optionally keep data. Do not drop table by default.
	}

	public function register_rest_routes() {
		register_rest_route( 'hs/v1', '/record', array(
			'methods'  => 'POST',
			'callback' => array( $this, 'rest_record_event' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'hs/v1', '/events', array(
			'methods'  => 'GET',
			'callback' => array( $this, 'rest_get_events' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
			'args' => array(
				'page' => array( 'validate_callback' => 'is_numeric' ),
				'per_page' => array( 'validate_callback' => 'is_numeric' ),
				'event_type' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'url' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'after' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'before' => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	public function enqueue_frontend_assets() {
		// Only inject recorder script on front-end, for non-admin pages
		if ( is_admin() ) {
			return;
		}

		$ver = '1.0.0';
		wp_enqueue_script( 'hs-recorder', plugin_dir_url( __FILE__ ) . 'assets/js/recorder.js', array(), $ver, true );

		$rest_url = esc_url_raw( rest_url( 'hs/v1/record' ) );

		wp_localize_script( 'hs-recorder', 'HS_RECORDER', array(
			'rest_url' => $rest_url,
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'plugin_version' => $ver,
		) );
	}

	public function enqueue_admin_assets( $hook ) {
		$ver = '1.0.0';
		wp_enqueue_style( 'hs-admin-css', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), $ver );
		wp_enqueue_script( 'hs-admin-js', plugin_dir_url( __FILE__ ) . 'assets/js/admin-visualizer.js', array(), $ver, true );

		$rest_list = esc_url_raw( rest_url( 'hs/v1/events' ) );
		wp_localize_script( 'hs-admin-js', 'HS_ADMIN', array(
			'rest_events_url' => $rest_list,
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}

	public function rest_record_event( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_REST_Response( array( 'success' => false, 'reason' => 'invalid_payload' ), 400 );
		}

		// Basic rate limiting: per-IP transient
		$ip = $this->get_ip();
		$transient_key = 'hs_rate_' . md5( $ip );
		$count = intval( get_transient( $transient_key ) );
		if ( $count > 2000 ) { // very high threshold to avoid DoS
			return new WP_REST_Response( array( 'success' => false, 'reason' => 'rate_limited' ), 429 );
		}
		set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );

		$allowed_types = array( 'move', 'click', 'scroll', 'viewport' );
		$event_type = isset( $params['event_type'] ) ? sanitize_text_field( $params['event_type'] ) : '';

		if ( ! in_array( $event_type, $allowed_types, true ) ) {
			return new WP_REST_Response( array( 'success' => false, 'reason' => 'invalid_event_type' ), 400 );
		}

		$session_id = isset( $params['session_id'] ) ? sanitize_text_field( $params['session_id'] ) : wp_generate_password( 12, false );
		$page_url   = isset( $params['page_url'] ) ? esc_url_raw( $params['page_url'] ) : wp_get_referer();
		$x = isset( $params['x'] ) ? intval( $params['x'] ) : null;
		$y = isset( $params['y'] ) ? intval( $params['y'] ) : null;
		$viewport_w = isset( $params['viewport_w'] ) ? intval( $params['viewport_w'] ) : null;
		$viewport_h = isset( $params['viewport_h'] ) ? intval( $params['viewport_h'] ) : null;
		$scroll_percent = isset( $params['scroll_percent'] ) ? floatval( $params['scroll_percent'] ) : null;

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_EVENTS;

		$inserted = $wpdb->insert(
			$table,
			array(
				'session_id'    => $session_id,
				'event_type'    => $event_type,
				'page_url'      => $page_url,
				'x'             => $x,
				'y'             => $y,
				'viewport_w'    => $viewport_w,
				'viewport_h'    => $viewport_h,
				'scroll_percent'=> $scroll_percent,
				'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null,
				'ip_addr'       => $ip,
				'created_at'    => current_time( 'mysql', 1 ),
			),
			array(
				'%s','%s','%s','%d','%d','%d','%d','%f','%s','%s','%s',
			)
		);

		if ( $inserted === false ) {
			return new WP_REST_Response( array( 'success' => false, 'reason' => 'db_error' ), 500 );
		}

		return new WP_REST_Response( array( 'success' => true ), 201 );
	}

	public function rest_get_events( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_EVENTS;

		$per_page = max( 1, min( 5000, intval( $request->get_param( 'per_page' ) ?: 1000 ) ) );
		$page = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = array();
		$where_vals = array();

		if ( $event_type = $request->get_param( 'event_type' ) ) {
			$where[] = 'event_type = %s';
			$where_vals[] = sanitize_text_field( $event_type );
		}
		if ( $url = $request->get_param( 'url' ) ) {
			$where[] = 'page_url LIKE %s';
			$where_vals[] = '%' . $wpdb->esc_like( sanitize_text_field( $url ) ) . '%';
		}
		if ( $after = $request->get_param( 'after' ) ) {
			$where[] = 'created_at >= %s';
			$where_vals[] = sanitize_text_field( $after );
		}
		if ( $before = $request->get_param( 'before' ) ) {
			$where[] = 'created_at <= %s';
			$where_vals[] = sanitize_text_field( $before );
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = ' WHERE ' . implode( ' AND ', $where );
		}

		$sql = $wpdb->prepare( "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", array_merge( $where_vals, array( $per_page, $offset ) ) );

		$results = $wpdb->get_results( $sql, ARRAY_A );

		return rest_ensure_response( $results );
	}

	private function get_ip() {
		$ip_keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = $_SERVER[ $key ];
				// X-Forwarded-For may be a list
				if ( strpos( $ip, ',' ) !== false ) {
					$parts = explode( ',', $ip );
					$ip = trim( $parts[0] );
				}
				return substr( sanitize_text_field( $ip ), 0, 45 );
			}
		}
		return '';
	}

public function admin_menu() {
	add_menu_page(
		'Heatmap & Scrollmap',
		'Heatmap',
		'manage_options',
		'hs-heatmap',
		array( $this, 'admin_page' ),
		'dashicons-welcome-view-site',
		75
	);
}

public function admin_page() {
	?>
	<div class="wrap">
		<h1>Heatmap & Scrollmap</h1>
		<p>Generate visualizations from recorded events. Enter target page URL path (e.g. <code>/your-page/</code>), choose event type, and click an action.</p>

		<div id="hs-side-panel">
			<div id="hs-tools">
				<label for="hs-target-url">Page URL (path or full URL):</label>
				<input id="hs-target-url" placeholder="/sample-page/" />
				<label for="hs-perpage">Records:</label>
				<input id="hs-perpage" type="number" value="2000" min="10" max="5000" />
				<button id="hs-generate-heatmap" class="hs-btn">Generate Heatmap (Clicks)</button>
				<button id="hs-generate-scrollmap" class="hs-btn">Generate Scrollmap</button>
			</div>
		</div>

		<div id="hs-visual-container"></div>
	</div>
	<?php
}


}

HS_Heatmap_Scrollmap::instance();
