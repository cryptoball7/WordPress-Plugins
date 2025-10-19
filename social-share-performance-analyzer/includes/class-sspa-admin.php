<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSPA_Admin {

	public static function register_menu() {
		add_menu_page(
			__( 'Social Share Performance', 'sspa' ),
			__( 'Share Performance', 'sspa' ),
			'manage_options',
			'sspa',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-chart-bar',
			25
		);
	}

	public static function enqueue_assets( $hook ) {
		// Only on our plugin page
		if ( $hook !== 'toplevel_page_sspa' ) {
			return;
		}

		$plugin_url = plugin_dir_url( __FILE__ ) . '../';
		wp_enqueue_style( 'sspa-admin', $plugin_url . 'assets/css/admin.css', array(), SSPA_Plugin::VERSION );
		// Chart.js from CDN (simple; no bundling). You can replace with a local copy if required.
		wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', array(), '4.4.0', true );
		wp_enqueue_script( 'sspa-admin-js', $plugin_url . 'assets/js/admin.js', array( 'jquery', 'chartjs' ), SSPA_Plugin::VERSION, true );

		wp_localize_script( 'sspa-admin-js', 'SSPA_Admin', array(
			'nonce' => wp_create_nonce( 'sspa_admin_nonce' ),
			'rest_url' => esc_url_raw( rest_url( 'sspa/v1' ) ),
			'strings' => array(
				'import_success' => __( 'CSV imported successfully.', 'sspa' ),
				'import_error'   => __( 'Failed to import CSV. Check format.', 'sspa' ),
			),
		) );
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'sspa' ) );
		}

		?>
		<div class="wrap sspa-wrap">
			<h1><?php esc_html_e( 'Social Share Performance Analyzer', 'sspa' ); ?></h1>

			<section class="sspa-import">
				<h2><?php esc_html_e( 'CSV Import', 'sspa' ); ?></h2>
				<p>
					<?php esc_html_e( 'Upload a CSV of share counts. Required columns: post_id, platform, share_count, recorded_at (YYYY-MM-DD HH:MM:SS). Optional column: meta (JSON).', 'sspa' ); ?>
				</p>
				<form id="sspa-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'sspa_import_csv', 'sspa_import_nonce' ); ?>
					<input type="file" name="sspa_csv" accept=".csv" required />
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Import CSV', 'sspa' ); ?></button>
					<span id="sspa-import-status" aria-live="polite"></span>
				</form>
			</section>

			<section class="sspa-charts">
				<h2><?php esc_html_e( 'Top Content by Shares', 'sspa' ); ?></h2>
				<div id="sspa-filter-row">
					<label>
						<?php esc_html_e( 'Date from', 'sspa' ); ?>
						<input type="date" id="sspa-date-from" />
					</label>
					<label>
						<?php esc_html_e( 'Date to', 'sspa' ); ?>
						<input type="date" id="sspa-date-to" />
					</label>
					<button id="sspa-refresh" class="button"><?php esc_html_e( 'Refresh', 'sspa' ); ?></button>
					<button id="sspa-export" class="button"><?php esc_html_e( 'Export CSV', 'sspa' ); ?></button>
				</div>

				<canvas id="sspa-chart" width="900" height="400"></canvas>

				<h3><?php esc_html_e( 'Detailed Table', 'sspa' ); ?></h3>
				<table id="sspa-table" class="widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'sspa' ); ?></th>
							<th><?php esc_html_e( 'Platform', 'sspa' ); ?></th>
							<th><?php esc_html_e( 'Total Shares', 'sspa' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</section>
		</div>
		<?php
	}

	/**
	 * Handle CSV import (synchronous POST when file uploaded).
	 * We'll process the file and insert rows using SSPA_DB::insert_stat
	 */
	public static function handle_csv_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'sspa' ), 403 );
		}
		check_admin_referer( 'sspa_import_csv', 'sspa_import_nonce' );

		if ( empty( $_FILES['sspa_csv'] ) || $_FILES['sspa_csv']['error'] ) {
			wp_send_json_error( __( 'No file uploaded', 'sspa' ), 400 );
		}

		$csv = $_FILES['sspa_csv']['tmp_name'];
		$handle = fopen( $csv, 'r' );
		if ( $handle === false ) {
			wp_send_json_error( __( 'Unable to open file', 'sspa' ), 500 );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			wp_send_json_error( __( 'Empty CSV', 'sspa' ), 400 );
		}

		$required = array( 'post_id', 'platform', 'share_count', 'recorded_at' );
		$map = array_flip( array_map( 'trim', $header ) );

		foreach ( $required as $col ) {
			if ( ! isset( $map[ $col ] ) ) {
				fclose( $handle );
				wp_send_json_error( sprintf( __( 'Missing required column: %s', 'sspa' ), $col ), 400 );
			}
		}

		$inserted = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$record = array();
			foreach ( $map as $col => $idx ) {
				$record[ $col ] = isset( $row[ $idx ] ) ? $row[ $idx ] : null;
			}
			// Validate and normalize
			$record['post_id'] = intval( $record['post_id'] );
			$record['platform'] = sanitize_text_field( $record['platform'] );
			$record['share_count'] = intval( $record['share_count'] );

			// Try to parse recorded_at; assume already in correct format else use current time.
			$recorded_at = sanitize_text_field( $record['recorded_at'] );
			$dt = date_create_from_format( 'Y-m-d H:i:s', $recorded_at );
			if ( ! $dt ) {
				// try Y-m-d
				$dt2 = date_create_from_format( 'Y-m-d', $recorded_at );
				if ( $dt2 ) {
					$recorded_at = $dt2->format( 'Y-m-d' ) . ' 00:00:00';
				} else {
					$recorded_at = current_time( 'mysql' );
				}
			}
			$record['recorded_at'] = $recorded_at;

			$meta = null;
			if ( isset( $record['meta'] ) && ! empty( $record['meta'] ) ) {
				$meta_json = json_decode( $record['meta'], true );
				$meta = $meta_json ? $meta_json : array( 'raw' => $record['meta'] );
			}
			$record['meta'] = $meta;

			$res = SSPA_DB::insert_stat( $record );
			if ( ! is_wp_error( $res ) ) {
				$inserted++;
			}
		}

		fclose( $handle );

		wp_send_json_success( array( 'inserted' => $inserted ), 200 );
	}
}

// Hook the AJAX endpoint for CSV import to admin-ajax for compatibility (optional)
add_action( 'wp_ajax_sspa_import_csv', array( 'SSPA_Admin', 'handle_csv_import' ) );
