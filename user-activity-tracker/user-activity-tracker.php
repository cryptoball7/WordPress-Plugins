<?php
/**
 * Plugin Name: User Activity Tracker
 * Description: Logs user logins and last activity, stores them in a custom table and usermeta, and displays an admin table.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 * Text Domain: user-activity-tracker
 *
 * IMPORTANT: Place this file in wp-content/plugins/user-activity-tracker/user-activity-tracker.php
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $uat_db_version;
$uat_db_version = '1.0';

// --- Core class --------------------------------------------------------------
class UAT_User_Activity_Tracker {

	/** @var wpdb */
	private $wpdb;
	private $table_name;
	private $meta_last_activity = 'uat_last_activity';
	private $meta_last_login = 'uat_last_login';
	private $update_threshold = 60; // seconds

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->table_name = $wpdb->prefix . 'user_activity_log';

		// Hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_uninstall_hook( __FILE__, array( 'UAT_User_Activity_Tracker', 'uninstall' ) );

		add_action( 'wp_login', array( $this, 'handle_login' ), 10, 2 );
		add_action( 'init', array( $this, 'maybe_update_activity' ) );

		// Admin
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	/**
	 * Activation: create table if not exists
	 */
	public function activate() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table_name} (
			id BIGINT(20) NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) NOT NULL,
			last_login DATETIME DEFAULT NULL,
			last_activity DATETIME DEFAULT NULL,
			ip VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(191) DEFAULT NULL,
			activity_count BIGINT(20) DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;";

		dbDelta( $sql );

		add_option( 'uat_db_version', $GLOBALS['uat_db_version'] );
	}

	/**
	 * Uninstall: clean up table and meta (static because WP uses this signature)
	 */
	public static function uninstall() {
		global $wpdb;
		$table = $wpdb->prefix . 'user_activity_log';
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		// Delete meta keys for all users
		$meta_keys = array( 'uat_last_activity', 'uat_last_login' );
		foreach ( $meta_keys as $key ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$key
			) );
		}
		delete_option( 'uat_db_version' );
	}

	/**
	 * Handle wp_login action
	 *
	 * @param string  $user_login
	 * @param WP_User $user
	 */
	public function handle_login( $user_login, $user ) {
		$user_id = intval( $user->ID );
		$now     = current_time( 'mysql', 1 ); // GMT time for DB consistency
		$ip      = $this->get_ip();
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_trim_words( wp_strip_all_tags( $_SERVER['HTTP_USER_AGENT'] ), 40, '' ) : '';

		// Insert or update DB row
		$existing = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT id, activity_count FROM {$this->table_name} WHERE user_id = %d",
			$user_id
		) );

		if ( $existing ) {
			$this->wpdb->update(
				$this->table_name,
				array(
					'last_login'     => $now,
					'last_activity'  => $now,
					'ip'             => $ip,
					'user_agent'     => $ua,
					'activity_count' => intval( $existing->activity_count ) + 1,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
		} else {
			$this->wpdb->insert(
				$this->table_name,
				array(
					'user_id'        => $user_id,
					'last_login'     => $now,
					'last_activity'  => $now,
					'ip'             => $ip,
					'user_agent'     => $ua,
					'activity_count' => 1,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		// Also store in usermeta for quick retrieval
		update_user_meta( $user_id, $this->meta_last_login, $now );
		update_user_meta( $user_id, $this->meta_last_activity, $now );
	}

	/**
	 * Update last_activity for logged-in users with throttling.
	 */
	public function maybe_update_activity() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Throttle: keep last update time in a transient keyed by user id
		$transient_key = "uat_last_update_{$user_id}";
		$last_stamp = get_transient( $transient_key );

		if ( $last_stamp && ( time() - intval( $last_stamp ) ) < $this->update_threshold ) {
			// Too soon to update again
			return;
		}

		$now_gmt = current_time( 'mysql', 1 );
		$ip      = $this->get_ip();
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_trim_words( wp_strip_all_tags( $_SERVER['HTTP_USER_AGENT'] ), 40, '' ) : '';

		// Upsert: update last_activity and increment activity_count (once per session threshold)
		$existing = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT id, activity_count FROM {$this->table_name} WHERE user_id = %d",
			$user_id
		) );

		if ( $existing ) {
			$this->wpdb->update(
				$this->table_name,
				array(
					'last_activity'  => $now_gmt,
					'ip'             => $ip,
					'user_agent'     => $ua,
					'activity_count' => intval( $existing->activity_count ) + 1,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
		} else {
			$this->wpdb->insert(
				$this->table_name,
				array(
					'user_id'        => $user_id,
					'last_login'     => null,
					'last_activity'  => $now_gmt,
					'ip'             => $ip,
					'user_agent'     => $ua,
					'activity_count' => 1,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		// Update usermeta
		update_user_meta( $user_id, $this->meta_last_activity, $now_gmt );

		// Set transient
		set_transient( $transient_key, time(), $this->update_threshold );
	}

	/**
	 * Get visitor IP addressing common headers.
	 *
	 * @return string
	 */
	private function get_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
			$ip  = trim( reset( $ips ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return esc_attr( $ip );
	}

	/* -------------------- Admin UI -------------------- */

	public function admin_menu() {
		add_users_page(
			__( 'User Activity', 'user-activity-tracker' ),
			__( 'User Activity', 'user-activity-tracker' ),
			'manage_options',
			'uat-user-activity',
			array( $this, 'admin_page' )
		);
	}

	public function admin_assets( $hook ) {
		// Only load assets on our page
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'uat-user-activity' ) {
			wp_enqueue_style( 'uat-admin-css', plugins_url( 'css/uat-admin.css', __FILE__ ) );
		}
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions', 'user-activity-tracker' ) );
		}

		// Include WP_List_Table class
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		require_once __DIR__ . '/includes/class-uat-list-table.php';

		$uat_list = new UAT_List_Table( $this->table_name );
		$uat_list->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'User Activity', 'user-activity-tracker' ) . '</h1>';
		echo '<form method="post">';
		$uat_list->search_box( 'Search Users', 'uat_search' );
		$uat_list->display();
		echo '</form>';
		echo '</div>';
	}
}

// Instantiate
$uat_tracker = new UAT_User_Activity_Tracker();


// -------------------- WP_List_Table implementation --------------------
// We'll include the list table class in a separate require file to keep things tidy.
// If the includes file does not exist (since this is a single-file plugin), create the class inline.

if ( ! file_exists( __DIR__ . '/includes/class-uat-list-table.php' ) ) :

	// Inline definition of the list table class
	if ( ! class_exists( 'UAT_List_Table' ) ) {

		class UAT_List_Table extends WP_List_Table {

			private $table_name;
			private $per_page = 20;
			private $columns;

			public function __construct( $table_name ) {
				parent::__construct( array(
					'singular' => 'user_activity',
					'plural'   => 'user_activities',
					'ajax'     => false,
				) );

				$this->table_name = $table_name;
				$this->columns    = array(
					'cb'            => '<input type="checkbox" />',
					'user'          => __( 'User', 'user-activity-tracker' ),
					'last_login'    => __( 'Last Login (UTC)', 'user-activity-tracker' ),
					'last_activity' => __( 'Last Activity (UTC)', 'user-activity-tracker' ),
					'ip'            => __( 'IP', 'user-activity-tracker' ),
					'activity_count'=> __( 'Activity Count', 'user-activity-tracker' ),
				);
			}

			/**
			 * Provide columns.
			 */
			public function get_columns() {
				return $this->columns;
			}

			/**
			 * Bulk actions: delete
			 */
			public function get_bulk_actions() {
				return array(
					'delete' => __( 'Delete', 'user-activity-tracker' ),
				);
			}

			/**
			 * Prepare items: load from DB with pagination and search
			 */
			public function prepare_items() {
				global $wpdb;

				$columns  = $this->get_columns();
				$hidden   = array();
				$sortable = $this->get_sortable_columns();

				$this->_column_headers = array( $columns, $hidden, $sortable );

				$per_page = $this->per_page;
				$paged    = $this->get_pagenum();

				$where = '';
				$search = ( isset( $_REQUEST['s'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

				if ( ! empty( $search ) ) {
					$like = '%' . $wpdb->esc_like( $search ) . '%';
					$where = $wpdb->prepare( " WHERE u.user_login LIKE %s OR u.display_name LIKE %s OR a.ip LIKE %s", $like, $like, $like );
				}

				// Sorting
				$orderby = ( isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], array( 'last_login', 'last_activity', 'activity_count' ), true ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_activity';
				$order   = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';

				$total_items = $wpdb->get_var( "SELECT COUNT(a.id) FROM {$this->table_name} a LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID {$where}" );

				$offset = ( $paged - 1 ) * $per_page;

				$sql = $wpdb->prepare(
					"SELECT a.*, u.user_login, u.display_name
					 FROM {$this->table_name} a
					 LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
					 {$where}
					 ORDER BY {$orderby} {$order}
					 LIMIT %d OFFSET %d",
					$per_page,
					$offset
				);

				$rows = $wpdb->get_results( $sql );

				$this->items = $rows ? $rows : array();

				$this->set_pagination_args( array(
					'total_items' => $total_items,
					'per_page'    => $per_page,
					'total_pages' => ceil( $total_items / $per_page ),
				) );
			}

			public function get_sortable_columns() {
				return array(
					'last_login'     => array( 'last_login', true ),
					'last_activity'  => array( 'last_activity', true ),
					'activity_count' => array( 'activity_count', true ),
				);
			}

			/**
			 * Bulk action processor
			 */
			public function process_bulk_action() {
				global $wpdb;

				if ( 'delete' === $this->current_action() && ! empty( $_POST['user_activity'] ) ) {
					$ids = array_map( 'intval', (array) $_POST['user_activity'] );
					foreach ( $ids as $id ) {
						$wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );
					}
				}
			}

			/**
			 * Checkbox column
			 */
			public function column_cb( $item ) {
				return sprintf( '<input type="checkbox" name="user_activity[]" value="%d" />', intval( $item->id ) );
			}

			/**
			 * Default column renderer.
			 */
			public function column_default( $item, $column_name ) {
				switch ( $column_name ) {
					case 'user':
						$login = isset( $item->user_login ) ? esc_html( $item->user_login ) : '(ID ' . intval( $item->user_id ) . ')';
						$display = isset( $item->display_name ) ? esc_html( $item->display_name ) : '';
						return sprintf( '%s<br/><small>%s</small>', $login, $display );

					case 'last_login':
						return $item->last_login ? esc_html( $item->last_login ) : '-';

					case 'last_activity':
						return $item->last_activity ? esc_html( $item->last_activity ) : '-';

					case 'ip':
						return $item->ip ? esc_html( $item->ip ) : '-';

					case 'activity_count':
						return intval( $item->activity_count );

					default:
						return print_r( $item, true );
				}
			}
		}
	}
endif;

// If the includes file is present (in case user added it) prefer that. But plugin works with inline class.
