<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SSPA_DB {

	private static $table;
	private static $wpdb;
	private static $plugin_dir;

	public static function init( $plugin_dir ) {
		global $wpdb;
		self::$wpdb = $wpdb;
		self::$plugin_dir = $plugin_dir;
		self::$table = $wpdb->prefix . SSPA_Plugin::instance() ? 'sspa_stats' : $wpdb->prefix . 'sspa_stats';
		// Use the constant defined in main for table name to be safe:
		self::$table = $wpdb->prefix . 'sspa_stats';
	}

	/**
	 * Create table for storing share counts.
	 * Columns:
	 *  id (PK), post_id, platform, share_count, recorded_at, meta (JSON)
	 */
	public static function create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sspa_stats';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			platform VARCHAR(100) NOT NULL,
			share_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			recorded_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			meta LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY platform (platform),
			KEY recorded_at (recorded_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Save DB version
		update_option( SSPA_Plugin::OPTION_DB_VER, SSPA_Plugin::DB_VERSION );
	}

	/**
	 * Insert a stat row. $data must contain post_id, platform, share_count, recorded_at (Y-m-d H:i:s).
	 * meta optional (array -> JSON).
	 * Returns inserted ID or WP_Error.
	 */
	public static function insert_stat( $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sspa_stats';

		$insert = array(
			'post_id'     => intval( $data['post_id'] ),
			'platform'    => sanitize_text_field( $data['platform'] ),
			'share_count' => intval( $data['share_count'] ),
			'recorded_at' => sanitize_text_field( $data['recorded_at'] ),
			'meta'        => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
		);

		$format = array( '%d', '%s', '%d', '%s', '%s' );
		$success = $wpdb->insert( $table, $insert, $format );

		if ( $success === false ) {
			return new WP_Error( 'db_insert_error', __( 'Could not insert stat', 'sspa' ) );
		}

		return $wpdb->insert_id;
	}

	/**
	 * Query aggregated stats by post and platform between dates.
	 * Returns rows with post_id, platform, total_shares.
	 */
	public static function get_aggregated( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sspa_stats';

		$defaults = array(
			'date_from' => null,
			'date_to'   => null,
			'post_id'   => null,
			'platform'  => null,
			'limit'     => 100,
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array();
		$params = array();

		if ( $args['date_from'] ) {
			$where[] = 'recorded_at >= %s';
			$params[] = $args['date_from'];
		}
		if ( $args['date_to'] ) {
			$where[] = 'recorded_at <= %s';
			$params[] = $args['date_to'];
		}
		if ( $args['post_id'] ) {
			$where[] = 'post_id = %d';
			$params[] = $args['post_id'];
		}
		if ( $args['platform'] ) {
			$where[] = 'platform = %s';
			$params[] = $args['platform'];
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$sql = $wpdb->prepare(
			"SELECT post_id, platform, SUM(share_count) as total_shares
			 FROM {$table}
			 {$where_sql}
			 GROUP BY post_id, platform
			 ORDER BY total_shares DESC
			 LIMIT %d",
			array_merge( $params, array( intval( $args['limit'] ) ) )
		);

		$results = $wpdb->get_results( $sql, ARRAY_A );
		return $results;
	}

	/**
	 * For REST or downloads: raw rows optionally filtered
	 */
	public static function get_rows( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sspa_stats';

		$defaults = array(
			'date_from' => null,
			'date_to'   => null,
			'limit'     => 500,
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array();
		$params = array();

		if ( $args['date_from'] ) {
			$where[] = 'recorded_at >= %s';
			$params[] = $args['date_from'];
		}
		if ( $args['date_to'] ) {
			$where[] = 'recorded_at <= %s';
			$params[] = $args['date_to'];
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
		}

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where_sql} ORDER BY recorded_at DESC LIMIT %d",
			array_merge( $params, array( intval( $args['limit'] ) ) )
		);

		return $wpdb->get_results( $sql, ARRAY_A );
	}
}
