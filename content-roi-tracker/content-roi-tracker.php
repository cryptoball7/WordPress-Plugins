<?php
/**
 * Plugin Name: Content ROI Tracker
 * Description: Track views, leads and revenue per post/page. Provides admin dashboard, post metabox and optional WooCommerce attribution.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: content-roi-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRT_Content_ROI_Tracker {

	const META_VIEWS   = '_crt_views';
	const META_LEADS   = '_crt_leads';
	const META_REVENUE = '_crt_revenue';
	const COOKIE_LAST_POST = 'crt_last_post_viewed';

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Public trackers
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Admin
		add_action( 'add_meta_boxes', array( $this, 'add_post_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
		add_action( 'save_post', array( $this, 'save_post_manual_adjust' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

		// WP-CLI (if available) or activation hooks not required for postmeta.

		// WooCommerce integration (if active)
		add_action( 'woocommerce_thankyou', array( $this, 'maybe_record_woocommerce_order' ), 10, 1 );
	}

	/* -----------------------------
	 * Public / frontend scripts
	 * ----------------------------- */

	public function enqueue_scripts() {
		if ( is_singular() ) {
			wp_enqueue_script( 'crt-frontend', plugin_dir_url( __FILE__ ) . 'assets/crt-frontend.js', array( 'jquery' ), '1.0.0', true );
			wp_localize_script( 'crt-frontend', 'crt_settings', array(
				'rest_url' => esc_url_raw( rest_url( 'crt/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'post_id'  => get_the_ID(),
				'cookie_name' => self::COOKIE_LAST_POST,
			) );
		}
	}

	/* -----------------------------
	 * REST API routes
	 * ----------------------------- */

	public function register_rest_routes() {
		register_rest_route( 'crt/v1', '/view', array(
			'methods' => 'POST',
			'callback' => array( $this, 'rest_increment_view' ),
			'permission_callback' => '__return_true', // we check nonce inside
		) );

		register_rest_route( 'crt/v1', '/conversion', array(
			'methods' => 'POST',
			'callback' => array( $this, 'rest_add_conversion' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'crt/v1', '/metrics/(?P<id>\d+)', array(
			'methods' => 'GET',
			'callback' => array( $this, 'rest_get_metrics' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		) );
	}

	private function verify_rest_nonce() {
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) : '';
		return wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public function rest_increment_view( WP_REST_Request $request ) {
		if ( ! $this->verify_rest_nonce() ) {
			return new WP_REST_Response( array( 'error' => 'bad_nonce' ), 403 );
		}

		$body = $request->get_json_params();
		$post_id = isset( $body['post_id'] ) ? absint( $body['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post' ), 400 );
		}

		// Simple bot protection: require referer header be same host (optional)
		$current = (int) get_post_meta( $post_id, self::META_VIEWS, true );
		$current++;
		update_post_meta( $post_id, self::META_VIEWS, $current );

		// Set a cookie to mark last post viewed for attribution (7 days)
		setcookie( self::COOKIE_LAST_POST, $post_id, time() + DAY_IN_SECONDS * 7, COOKIEPATH ?: '/' , COOKIE_DOMAIN ?: '', is_ssl(), true );

		return new WP_REST_Response( array( 'views' => $current ), 200 );
	}

	public function rest_add_conversion( WP_REST_Request $request ) {
		if ( ! $this->verify_rest_nonce() ) {
			return new WP_REST_Response( array( 'error' => 'bad_nonce' ), 403 );
		}

		$body = $request->get_json_params();
		$post_id = isset( $body['post_id'] ) ? absint( $body['post_id'] ) : 0;
		$leads   = isset( $body['leads'] ) ? (int) $body['leads'] : 0;
		$revenue = isset( $body['revenue'] ) ? floatval( $body['revenue'] ) : 0.0;

		if ( ! $post_id && isset( $_COOKIE[ self::COOKIE_LAST_POST ] ) ) {
			$post_id = absint( $_COOKIE[ self::COOKIE_LAST_POST ] );
		}

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post' ), 400 );
		}

		$current_leads = (int) get_post_meta( $post_id, self::META_LEADS, true );
		$current_rev   = (float) get_post_meta( $post_id, self::META_REVENUE, true );

		$current_leads += $leads;
		$current_rev   += $revenue;

		update_post_meta( $post_id, self::META_LEADS, $current_leads );
		update_post_meta( $post_id, self::META_REVENUE, $current_rev );

		return new WP_REST_Response( array(
			'post_id' => $post_id,
			'leads' => $current_leads,
			'revenue' => $current_rev,
		), 200 );
	}

	public function rest_get_metrics( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_post' ), 400 );
		}
		$views = (int) get_post_meta( $post_id, self::META_VIEWS, true );
		$leads = (int) get_post_meta( $post_id, self::META_LEADS, true );
		$rev   = (float) get_post_meta( $post_id, self::META_REVENUE, true );

		return new WP_REST_Response( array(
			'post_id' => $post_id,
			'views' => $views,
			'leads' => $leads,
			'revenue' => $rev,
			'revenue_per_view' => $views ? round( $rev / $views, 6 ) : 0,
			'leads_per_view' => $views ? round( $leads / $views, 6 ) : 0,
		), 200 );
	}

	/* -----------------------------
	 * Admin: metabox & admin assets
	 * ----------------------------- */

	public function add_post_metabox() {
		$screens = array( 'post', 'page' );
		foreach ( $screens as $screen ) {
			add_meta_box( 'crt_metrics', __( 'Content ROI', 'content-roi-tracker' ), array( $this, 'render_metabox' ), $screen, 'side', 'high' );
		}
	}

	public function render_metabox( $post ) {
		$views = (int) get_post_meta( $post->ID, self::META_VIEWS, true );
		$leads = (int) get_post_meta( $post->ID, self::META_LEADS, true );
		$rev   = (float) get_post_meta( $post->ID, self::META_REVENUE, true );

		wp_nonce_field( 'crt_metabox_save', 'crt_metabox_nonce' );

		?>
		<p><strong><?php esc_html_e( 'Views:', 'content-roi-tracker' ); ?></strong> <?php echo esc_html( $views ); ?></p>
		<p><strong><?php esc_html_e( 'Leads:', 'content-roi-tracker' ); ?></strong> <?php echo esc_html( $leads ); ?></p>
		<p><strong><?php esc_html_e( 'Revenue:', 'content-roi-tracker' ); ?></strong> <?php echo esc_html( number_format( $rev, 2 ) ); ?></p>

		<hr/>
		<p><em><?php esc_html_e( 'Manual adjustments (positive or negative). Values will be added to totals.', 'content-roi-tracker' ); ?></em></p>
		<p>
			<label><?php esc_html_e( 'Add leads', 'content-roi-tracker' ); ?></label><br/>
			<input type="number" name="crt_add_leads" value="0" min="-999999" step="1" />
		</p>
		<p>
			<label><?php esc_html_e( 'Add revenue', 'content-roi-tracker' ); ?></label><br/>
			<input type="number" name="crt_add_revenue" value="0.00" step="0.01" />
		</p>
		<p>
			<label><?php esc_html_e( 'Set views (absolute, leave blank to keep)', 'content-roi-tracker' ); ?></label><br/>
			<input type="number" name="crt_set_views" value="" step="1" />
		</p>
		<?php
	}

	public function admin_enqueue( $hook ) {
		// Only load on edit screens and our admin page
		if ( in_array( $hook, array( 'post.php', 'post-new.php', 'toplevel_page_crt_reports' ), true ) ) {
			wp_enqueue_style( 'crt-admin', plugin_dir_url( __FILE__ ) . 'assets/crt-admin.css', array(), '1.0.0' );
			wp_enqueue_script( 'crt-admin', plugin_dir_url( __FILE__ ) . 'assets/crt-admin.js', array( 'jquery' ), '1.0.0', true );
			wp_localize_script( 'crt-admin', 'crtAdmin', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'crt_admin_nonce' ),
			) );
		}
	}

	public function save_post_manual_adjust( $post_id, $post ) {
		// Only run for actual posts and if metabox nonce exists
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['crt_metabox_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['crt_metabox_nonce'] ), 'crt_metabox_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Add leads
		if ( isset( $_POST['crt_add_leads'] ) ) {
			$add_leads = intval( $_POST['crt_add_leads'] );
			$current = (int) get_post_meta( $post_id, self::META_LEADS, true );
			$current += $add_leads;
			if ( $current < 0 ) $current = 0;
			update_post_meta( $post_id, self::META_LEADS, $current );
		}

		// Add revenue
		if ( isset( $_POST['crt_add_revenue'] ) ) {
			$add_rev = floatval( $_POST['crt_add_revenue'] );
			$current = (float) get_post_meta( $post_id, self::META_REVENUE, true );
			$current += $add_rev;
			if ( $current < 0 ) $current = 0.0;
			update_post_meta( $post_id, self::META_REVENUE, $current );
		}

		// Set views absolute
		if ( isset( $_POST['crt_set_views'] ) && $_POST['crt_set_views'] !== '' ) {
			$set_views = intval( $_POST['crt_set_views'] );
			if ( $set_views < 0 ) $set_views = 0;
			update_post_meta( $post_id, self::META_VIEWS, $set_views );
		}
	}

	/* -----------------------------
	 * Admin menu & dashboard page
	 * ----------------------------- */

	public function register_admin_menu() {
		add_menu_page(
			__( 'Content ROI', 'content-roi-tracker' ),
			__( 'Content ROI', 'content-roi-tracker' ),
			'manage_options',
			'crt_reports',
			array( $this, 'render_admin_page' ),
			'dashicons-chart-area',
			26
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'content-roi-tracker' ) );
		}

		// Simple query: list the latest 100 posts with metrics
		$args = array(
			'post_type' => array( 'post', 'page' ),
			'posts_per_page' => 100,
			'post_status' => 'publish',
			'orderby' => 'date',
			'order' => 'DESC',
		);
		$posts = get_posts( $args );

		echo '<div class="wrap"><h1>' . esc_html__( 'Content ROI Tracker', 'content-roi-tracker' ) . '</h1>';
		echo '<p>' . esc_html__( 'This table lists views, leads and revenue for your posts/pages. Export the visible rows to CSV using the button below.', 'content-roi-tracker' ) . '</p>';

		echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( 'crt_export', '1' ) ) . '">' . esc_html__( 'Export CSV (current sample)', 'content-roi-tracker' ) . '</a></p>';

		// Handle export
		if ( isset( $_GET['crt_export'] ) && '1' === $_GET['crt_export'] ) {
			$this->export_csv( $posts );
		}

		// Table
		echo '<table class="widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'content-roi-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Views', 'content-roi-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Leads', 'content-roi-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Revenue', 'content-roi-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Revenue / View', 'content-roi-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Leads / View', 'content-roi-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $posts as $p ) {
			$views = (int) get_post_meta( $p->ID, self::META_VIEWS, true );
			$leads = (int) get_post_meta( $p->ID, self::META_LEADS, true );
			$rev   = (float) get_post_meta( $p->ID, self::META_REVENUE, true );
			$rpv   = $views ? round( $rev / $views, 6 ) : 0;
			$lpv   = $views ? round( $leads / $views, 6 ) : 0;

			echo '<tr>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . ' <small>[' . esc_html( $p->ID ) . ']</small></a></td>';
			echo '<td>' . esc_html( $views ) . '</td>';
			echo '<td>' . esc_html( $leads ) . '</td>';
			echo '<td>' . esc_html( number_format( $rev, 2 ) ) . '</td>';
			echo '<td>' . esc_html( $rpv ) . '</td>';
			echo '<td>' . esc_html( $lpv ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	private function export_csv( $posts ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'content-roi-tracker' ) );
		}

		$filename = 'crt-export-' . date( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'post_id', 'title', 'views', 'leads', 'revenue', 'revenue_per_view', 'leads_per_view' ) );

		foreach ( $posts as $p ) {
			$views = (int) get_post_meta( $p->ID, self::META_VIEWS, true );
			$leads = (int) get_post_meta( $p->ID, self::META_LEADS, true );
			$rev   = (float) get_post_meta( $p->ID, self::META_REVENUE, true );
			$rpv   = $views ? round( $rev / $views, 6 ) : 0;
			$lpv   = $views ? round( $leads / $views, 6 ) : 0;
			fputcsv( $out, array( $p->ID, html_entity_decode( get_the_title( $p ) ), $views, $leads, number_format( $rev, 2, '.', '' ), $rpv, $lpv ) );
		}
		fclose( $out );
		exit;
	}

	/* -----------------------------
	 * WooCommerce integration
	 * ----------------------------- */

	public function maybe_record_woocommerce_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Try to attribute to last post viewed cookie
		if ( isset( $_COOKIE[ self::COOKIE_LAST_POST ] ) ) {
			$post_id = absint( $_COOKIE[ self::COOKIE_LAST_POST ] );
			if ( $post_id && get_post( $post_id ) ) {
				// Add revenue and one lead by default for an order
				$total = (float) $order->get_total();

				$current_rev = (float) get_post_meta( $post_id, self::META_REVENUE, true );
				$current_leads = (int) get_post_meta( $post_id, self::META_LEADS, true );

				$current_rev += $total;
				$current_leads += 1;

				update_post_meta( $post_id, self::META_REVENUE, $current_rev );
				update_post_meta( $post_id, self::META_LEADS, $current_leads );

				// Optionally, store a lightweight audit (in order meta) for traceability
				$history = (array) $order->get_meta( '_crt_attribution_history', true );
				$history[] = array(
					'post_id' => $post_id,
					'revenue' => $total,
					'time' => current_time( 'mysql' ),
				);
				$order->update_meta_data( '_crt_attribution_history', $history );
				$order->save();
			}
		}
	}

	/* -----------------------------
	 * Shortcode (basic)
	 * ----------------------------- */

	public function shortcode_dashboard( $atts ) {
		$atts = shortcode_atts( array(
			'post_id' => 0,
		), $atts, 'crt_dashboard' );

		$post_id = (int) $atts['post_id'];
		if ( ! $post_id ) {
			return '';
		}

		$views = (int) get_post_meta( $post_id, self::META_VIEWS, true );
		$leads = (int) get_post_meta( $post_id, self::META_LEADS, true );
		$rev   = (float) get_post_meta( $post_id, self::META_REVENUE, true );
		$rpv   = $views ? round( $rev / $views, 6 ) : 0;
		$lpv   = $views ? round( $leads / $views, 6 ) : 0;

		ob_start();
		?>
		<div class="crt-public-report">
			<div><strong>Views:</strong> <?php echo esc_html( $views ); ?></div>
			<div><strong>Leads:</strong> <?php echo esc_html( $leads ); ?></div>
			<div><strong>Revenue:</strong> <?php echo esc_html( number_format( $rev, 2 ) ); ?></div>
			<div><strong>Revenue / View:</strong> <?php echo esc_html( $rpv ); ?></div>
			<div><strong>Leads / View:</strong> <?php echo esc_html( $lpv ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}
}

add_action( 'init', function() {
	CRT_Content_ROI_Tracker::init();
	// Register shortcode
	add_shortcode( 'crt_dashboard', array( CRT_Content_ROI_Tracker::init(), 'shortcode_dashboard' ) );
} );
