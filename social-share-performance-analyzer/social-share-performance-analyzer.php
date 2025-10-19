<?php
/**
 * Plugin Name: Social Share Performance Analyzer
 * Plugin URI:  https://example.com/plugins/social-share-performance-analyzer
 * Description: Track and analyze how your content performs across social networks. Import share counts, view charts, and export data.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Text Domain: sspa
 * Domain Path: /languages
 *
 * Notes:
 * - This plugin provides a lightweight local storage & analytics layer for share counts.
 * - It includes a CSV import, admin charts, REST endpoints for front-end charts, and export functionality.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SSPA_Plugin' ) ) :

class SSPA_Plugin {

	const VERSION = '1.0.0';
	const DB_VERSION = '1.0';
	const OPTION_DB_VER = 'sspa_db_version';
	const TABLE = 'sspa_stats';

	private static $instance = null;
	private $plugin_dir;
	private $plugin_url;
	private $includes;

	private function __construct() {
		$this->plugin_dir = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Includes
		$this->includes();

		// Admin & REST hooks
		if ( is_admin() ) {
			add_action( 'admin_menu', array( 'SSPA_Admin', 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( 'SSPA_Admin', 'enqueue_assets' ) );
		} else {
			// Frontend scripts (if needed)
			add_action( 'wp_enqueue_scripts', array( 'SSPA_Public', 'enqueue_assets' ) );
		}

		add_action( 'rest_api_init', array( 'SSPA_REST', 'register_routes' ) );
	}

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function includes() {
		require_once $this->plugin_dir . 'includes/class-sspa-db.php';
		require_once $this->plugin_dir . 'includes/class-sspa-admin.php';
		require_once $this->plugin_dir . 'includes/class-sspa-rest.php';

		// Initialize DB helper
		SSPA_DB::init( $this->plugin_dir );
	}

	public function activate() {
		// Create DB table
		SSPA_DB::create_table();
	}

	public function deactivate() {
		// Nothing destructive on deactivate.
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'sspa', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
}

SSPA_Plugin::instance();
