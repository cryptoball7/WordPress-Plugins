<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSPA_REST {

	public static function register_routes() {
		register_rest_route( 'sspa/v1', '/aggregated', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_aggregated' ),
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'date_from' => array( 'validate_callback' => 'rest_validate_request_arg' ),
				'date_to'   => array( 'validate_callback' => 'rest_validate_request_arg' ),
				'post_id'   => array( 'validate_callback' => 'rest_validate_request_arg' ),
				'platform'  => array( 'validate_callback' => 'rest_validate_request_arg' ),
				'limit'     => array( 'validate_callback' => 'is_numeric' ),
			),
		) );

		register_rest_route( 'sspa/v1', '/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'export_csv' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'date_from' => array( 'validate_callback' => 'rest_validate_request_arg' ),
				'date_to'   => array( 'validate_callback' => 'rest_validate_request_arg' ),
			),
		) );
	}

	public static function get_aggregated( WP_REST_Request $request ) {
		$params = $request->get_params();
		$args = array(
			'date_from' => isset( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : null,
			'date_to'   => isset( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : null,
			'post_id'   => isset( $params['post_id'] ) ? intval( $params['post_id'] ) : null,
			'platform'  => isset( $params['platform'] ) ? sanitize_text_field( $params['platform'] ) : null,
			'limit'     => isset( $params['limit'] ) ? intval( $params['limit'] ) : 50,
		);

		$data = SSPA_DB::get_aggregated( $args );

		// Add post titles for convenience
		foreach ( $data as &$row ) {
			$post = get_post( $row['post_id'] );
			$row['post_title'] = $post ? get_the_title( $post ) : sprintf( __( 'Post %d', 'sspa' ), $row['post_id'] );
		}

		return rest_ensure_response( $data );
	}

	public static function export_csv( WP_REST_Request $request ) {
		$params = $request->get_params();
		$args = array(
			'date_from' => isset( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : null,
			'date_to'   => isset( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : null,
			'limit'     => 1000,
		);

		$rows = SSPA_DB::get_rows( $args );

		$filename = 'sspa-export-' . date( 'Ymd-His' ) . '.csv';

		// Prepare CSV output
		$csv_lines = array();
		$header = array( 'id', 'post_id', 'platform', 'share_count', 'recorded_at', 'meta' );
		$csv_lines[] = $header;

		foreach ( $rows as $r ) {
			$csv_lines[] = array(
				$r['id'],
				$r['post_id'],
				$r['platform'],
				$r['share_count'],
				$r['recorded_at'],
				$r['meta'],
			);
		}

		// Return as CSV body (for browsers / fetch)
		$body = fopen( 'php://temp', 'r+' );
		foreach ( $csv_lines as $line ) {
			fputcsv( $body, $line );
		}
		rewind( $body );
		$csv = stream_get_contents( $body );
		fclose( $body );

		return new WP_REST_Response( $csv, 200, array(
			'Content-Type'        => 'text/csv',
			'Content-Disposition' => "attachment; filename={$filename}",
		) );
	}
}
