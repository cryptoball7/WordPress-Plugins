<?php
/**
 * Plugin Name: Multi-Site Security Sync (MSSS) - Site Agent
 * Plugin URI:  https://example.com/msss
 * Description: Send security events (login failures, user changes, plugin/theme activity, admin logins) to a centralized collector for multi-site monitoring.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Author URI:  https://cryptoball7.github.io
 * Text Domain: msss
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'MSSS_Site_Agent' ) ) :

class MSSS_Site_Agent {
    private static $instance = null;
    private $option_name = 'msss_settings';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_uninstall_hook( __FILE__, array( 'MSSS_Site_Agent', 'uninstall' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Security hooks
        add_action( 'wp_login_failed', array( $this, 'handle_login_failed' ), 10, 1 );
        add_action( 'user_register', array( $this, 'handle_user_register' ), 10, 1 );
        add_action( 'profile_update', array( $this, 'handle_profile_update' ), 10, 2 );
        add_action( 'wp_login', array( $this, 'handle_wp_login' ), 10, 2 );

        // Plugin/theme activity
        add_action( 'activated_plugin', array( $this, 'handle_plugin_activated' ), 10, 2 );
        add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivated' ), 10, 2 );
        add_action( 'switch_theme', array( $this, 'handle_theme_switched' ), 10, 2 );

        // Heartbeat: send daily status
        add_action( 'msss_daily_cron', array( $this, 'send_heartbeat' ) );
        if ( ! wp_next_scheduled( 'msss_daily_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'msss_daily_cron' );
        }

        // REST endpoint for remote commands (optional)
        add_action( 'rest_api_init', function() {
            register_rest_route( 'msss/v1', '/command', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_handle_command' ),
                'permission_callback' => '__return_true', // we'll check signature inside
            ) );
        } );
    }

    public function activate() {
        $defaults = array(
            'collector_url' => '',
            'api_key' => '',
            'site_label' => get_bloginfo( 'url' ),
            'send_events' => array('login_failed' => 1, 'user_register' => 1, 'profile_update' => 1, 'login' => 1, 'plugin' => 1, 'theme' => 1),
            'log_local' => 1,
        );
        add_option( $this->option_name, $defaults );
    }

    public static function uninstall() {
        delete_option( 'msss_settings' );
    }

    public function admin_menu() {
        add_options_page( 'MSSS Settings', 'Multi-Site Security Sync', 'manage_options', 'msss-settings', array( $this, 'settings_page' ) );
    }

    public function register_settings() {
        register_setting( 'msss_options', $this->option_name );
    }

    private function get_settings() {
        $s = get_option( $this->option_name );
        if ( ! is_array( $s ) ) return array();
        return $s;
    }

    public function settings_page() {
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>Multi-Site Security Sync</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'msss_options' );
                do_settings_sections( 'msss_options' );
                $collector_url = esc_attr( $s['collector_url'] ?? '' );
                $api_key = esc_attr( $s['api_key'] ?? '' );
                $site_label = esc_attr( $s['site_label'] ?? get_bloginfo( 'url' ) );
                $log_local = isset( $s['log_local'] ) ? (bool) $s['log_local'] : true;
                $send_events = $s['send_events'] ?? array();
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="msss_collector_url">Collector URL</label></th>
                        <td><input name="msss_settings[collector_url]" type="url" id="msss_collector_url" value="<?php echo $collector_url; ?>" class="regular-text" placeholder="https://central.example.com/msss/receive" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msss_api_key">API Key / Shared Secret</label></th>
                        <td><input name="msss_settings[api_key]" type="text" id="msss_api_key" value="<?php echo $api_key; ?>" class="regular-text" /><p class="description">Shared secret used to HMAC-sign outbound events to the collector.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="msss_site_label">Site Label</label></th>
                        <td><input name="msss_settings[site_label]" type="text" id="msss_site_label" value="<?php echo $site_label; ?>" class="regular-text" /><p class="description">Human-readable label for the site (shown on central dashboard).</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Events to send</th>
                        <td>
                            <?php $this->checkbox_line( 'login_failed', 'Login failures', $send_events ); ?>
                            <?php $this->checkbox_line( 'user_register', 'User registrations', $send_events ); ?>
                            <?php $this->checkbox_line( 'profile_update', 'Profile updates', $send_events ); ?>
                            <?php $this->checkbox_line( 'login', 'Successful admin logins', $send_events ); ?>
                            <?php $this->checkbox_line( 'plugin', 'Plugin activation/deactivation', $send_events ); ?>
                            <?php $this->checkbox_line( 'theme', 'Theme switches', $send_events ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Local logging</th>
                        <td>
                            <label><input type="checkbox" name="msss_settings[log_local]" value="1" <?php checked( $log_local ); ?>/> Keep a local log (wp-content/uploads/msss.log)</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Manual Send / Test</h2>
            <form method="post">
                <?php wp_nonce_field( 'msss_manual_send' ); ?>
                <input type="hidden" name="msss_manual_action" value="send_heartbeat" />
                <?php submit_button( 'Send test heartbeat' ); ?>
            </form>
        </div>
        <?php

        if ( isset( $_POST['msss_manual_action'] ) && check_admin_referer( 'msss_manual_send' ) ) {
            $this->send_heartbeat();
            echo '<div class="updated"><p>Test heartbeat sent (check collector).</p></div>';
        }
    }

    private function checkbox_line( $key, $label, $send_events ) {
        $checked = isset( $send_events[ $key ] ) && $send_events[ $key ];
        printf( '<label><input type="checkbox" name="msss_settings[send_events][%s]" value="1" %s/> %s</label><br/>', esc_attr( $key ), checked( true, $checked, false ), esc_html( $label ) );
    }

    private function should_send( $event ) {
        $s = $this->get_settings();
        return ! empty( $s['send_events'][ $event ] );
    }

    private function local_log( $entry ) {
        $s = $this->get_settings();
        if ( empty( $s['log_local'] ) ) return;
        $dir = wp_upload_dir();
        $file = trailingslashit( $dir['basedir'] ) . 'msss.log';
        $line = '[' . date( 'c' ) . '] ' . json_encode( $entry ) . "\n";
        @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
    }

    private function build_event( $type, $data = array() ) {
        $s = $this->get_settings();
        return array(
            'site' => $s['site_label'] ?? get_bloginfo( 'url' ),
            'site_url' => get_bloginfo( 'url' ),
            'timestamp' => time(),
            'type' => $type,
            'data' => $data,
            'wp_version' => get_bloginfo( 'version' ),
            'php_version' => phpversion(),
        );
    }

    private function send_event( $event ) {
        $s = $this->get_settings();
        if ( empty( $s['collector_url'] ) || empty( $s['api_key'] ) ) return false;

        $body = wp_json_encode( $event );
        $signature = hash_hmac( 'sha256', $body, $s['api_key'] );

        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-MSSS-Signature' => $signature,
                'X-MSSS-Site' => $s['site_label'] ?? get_bloginfo( 'url' ),
            ),
            'body' => $body,
            'timeout' => 15,
        );

        $resp = wp_remote_post( $s['collector_url'], $args );
        $this->local_log( array( 'outgoing' => $event, 'response' => $resp ) );
        return $resp;
    }

    // Event handlers
    public function handle_login_failed( $username ) {
        if ( ! $this->should_send( 'login_failed' ) ) return;
        $event = $this->build_event( 'login_failed', array( 'username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $this->send_event( $event );
    }

    public function handle_user_register( $user_id ) {
        if ( ! $this->should_send( 'user_register' ) ) return;
        $user = get_userdata( $user_id );
        $event = $this->build_event( 'user_register', array( 'user_id' => $user_id, 'user_login' => $user->user_login ) );
        $this->send_event( $event );
    }

    public function handle_profile_update( $user_id, $old_data ) {
        if ( ! $this->should_send( 'profile_update' ) ) return;
        $user = get_userdata( $user_id );
        $event = $this->build_event( 'profile_update', array( 'user_id' => $user_id, 'user_login' => $user->user_login ) );
        $this->send_event( $event );
    }

    public function handle_wp_login( $user_login, $user ) {
        if ( ! $this->should_send( 'login' ) ) return;
        // Only report admin-level logins to reduce noise
        if ( ! user_can( $user, 'manage_options' ) ) return;
        $event = $this->build_event( 'admin_login', array( 'user_id' => $user->ID, 'user_login' => $user_login, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $this->send_event( $event );
    }

    public function handle_plugin_activated( $plugin, $network_wide ) {
        if ( ! $this->should_send( 'plugin' ) ) return;
        $event = $this->build_event( 'plugin_activated', array( 'plugin' => $plugin, 'network' => (bool) $network_wide ) );
        $this->send_event( $event );
    }

    public function handle_plugin_deactivated( $plugin, $network_wide ) {
        if ( ! $this->should_send( 'plugin' ) ) return;
        $event = $this->build_event( 'plugin_deactivated', array( 'plugin' => $plugin, 'network' => (bool) $network_wide ) );
        $this->send_event( $event );
    }

    public function handle_theme_switched( $new_theme ) {
        if ( ! $this->should_send( 'theme' ) ) return;
        $event = $this->build_event( 'theme_switched', array( 'theme' => $new_theme ) );
        $this->send_event( $event );
    }

    public function send_heartbeat() {
        $event = $this->build_event( 'heartbeat', array( 'memory_limit' => ini_get( 'memory_limit' ) ) );
        return $this->send_event( $event );
    }

    // REST receiver for commands from central collector (signed)
    public function rest_handle_command( $request ) {
        $s = $this->get_settings();
        $signature = $request->get_header( 'x-msss-signature' );
        $body = $request->get_body();
        if ( empty( $s['api_key'] ) || empty( $signature ) ) {
            return new WP_Error( 'msss_no_auth', 'Missing signature or configuration', array( 'status' => 401 ) );
        }
        $calc = hash_hmac( 'sha256', $body, $s['api_key'] );
        if ( ! hash_equals( $calc, $signature ) ) {
            return new WP_Error( 'msss_bad_sig', 'Invalid signature', array( 'status' => 403 ) );
        }

        $payload = json_decode( $body, true );
        if ( empty( $payload['command'] ) ) {
            return new WP_Error( 'msss_bad_request', 'No command', array( 'status' => 400 ) );
        }

        // Support simple commands: ping, fetch-logs (returns last local lines), update-settings (not implemented fully)
        switch ( $payload['command'] ) {
            case 'ping':
                return rest_ensure_response( array( 'pong' => true, 'site' => $s['site_label'] ?? get_bloginfo( 'url' ) ) );
            case 'fetch-logs':
                $dir = wp_upload_dir();
                $file = trailingslashit( $dir['basedir'] ) . 'msss.log';
                if ( ! file_exists( $file ) ) return rest_ensure_response( array( 'lines' => array() ) );
                $lines = array_slice( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), -200 );
                return rest_ensure_response( array( 'lines' => $lines ) );
            default:
                return new WP_Error( 'msss_unknown', 'Unknown command', array( 'status' => 400 ) );
        }
    }

}

// Bootstrap
MSSS_Site_Agent::instance();

?>
