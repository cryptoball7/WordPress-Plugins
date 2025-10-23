<?php
/**
 * Plugin Name: Dead Plugin Detector
 * Plugin URI:  https://example.com/dead-plugin-detector
 * Description: Detects possibly abandoned, outdated, removed, or security-risky plugins and warns administrators. Includes scheduled scans, admin dashboard, plugin-row warnings, and optional WPScan/VulnDB integration.
 * Version:     1.0.0
 * Author:      ChatGPT
 * License:     GPLv2+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Dead_Plugin_Detector {
    const OPTION_KEY = 'dpd_scan_results';
    const OPTION_SETTINGS = 'dpd_settings';
    const CRON_HOOK = 'dpd_weekly_scan';

    private $defaults = array(
        'abandoned_months' => 12, // months without update to consider "abandoned"
        'risky_months'     => 24, // months to flag as security-risky
        'active_installs_threshold' => 100, // below this count mark low adoption
        'last_scan'        => 0,
        'wpscan_api_key'   => '', // optional
        'auto_scan'        => 1,
    );

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_admin_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        add_action( 'after_plugin_row', array( $this, 'plugin_row_warning' ), 10, 2 );

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( self::CRON_HOOK, array( $this, 'run_scan' ) );

        // AJAX manual scan
        add_action( 'wp_ajax_dpd_manual_scan', array( $this, 'ajax_manual_scan' ) );
    }

    public function activate() {
        $settings = get_option( self::OPTION_SETTINGS, array() );
        $settings = wp_parse_args( $settings, $this->defaults );
        update_option( self::OPTION_SETTINGS, $settings );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) && $settings['auto_scan'] ) {
            wp_schedule_event( time() + 60, 'weekly', self::CRON_HOOK );
        }

        // run an initial scan
        $this->run_scan();
    }

    public function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function register_settings() {
        register_setting( 'dpd_settings_group', self::OPTION_SETTINGS, array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        $input['abandoned_months'] = absint( $input['abandoned_months'] );
        if ( $input['abandoned_months'] <= 0 ) $input['abandoned_months'] = 12;
        $input['risky_months'] = absint( $input['risky_months'] );
        if ( $input['risky_months'] <= 0 ) $input['risky_months'] = 24;
        $input['active_installs_threshold'] = absint( $input['active_installs_threshold'] );
        $input['wpscan_api_key'] = sanitize_text_field( $input['wpscan_api_key'] );
        $input['auto_scan'] = isset( $input['auto_scan'] ) ? 1 : 0;
        return $input;
    }

    public function admin_menu() {
        add_management_page( 'Dead Plugin Detector', 'Dead Plugin Detector', 'manage_options', 'dead-plugin-detector', array( $this, 'settings_page' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'dead-plugin-detector' ) === false && strpos( $hook, 'plugins.php' ) === false ) return;
        wp_enqueue_style( 'dpd-css', plugins_url( 'css/dpd.css', __FILE__ ), array(), '1.0' );
        wp_enqueue_script( 'dpd-js', plugins_url( 'js/dpd.js', __FILE__ ), array( 'jquery' ), '1.0', true );
        wp_localize_script( 'dpd-js', 'dpd_ajax', array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dpd-manual-scan' ) ) );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $settings = get_option( self::OPTION_SETTINGS, $this->defaults );
        $results = get_option( self::OPTION_KEY, array() );
        ?>
        <div class="wrap">
            <h1>Dead Plugin Detector</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'dpd_settings_group' ); ?>
                <?php do_settings_sections( 'dpd_settings_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="abandoned_months">Abandoned threshold (months)</label></th>
                        <td><input name="dpd_settings[abandoned_months]" type="number" id="abandoned_months" value="<?php echo esc_attr( $settings['abandoned_months'] ); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="risky_months">Security-risk threshold (months)</label></th>
                        <td><input name="dpd_settings[risky_months]" type="number" id="risky_months" value="<?php echo esc_attr( $settings['risky_months'] ); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="active_installs_threshold">Active installs threshold</label></th>
                        <td><input name="dpd_settings[active_installs_threshold]" type="number" id="active_installs_threshold" value="<?php echo esc_attr( $settings['active_installs_threshold'] ); ?>" class="small-text" /><p class="description">Plugins with fewer active installs than this are flagged as low-adoption.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wpscan_api_key">WPScan / VulnDB API Key (optional)</label></th>
                        <td><input name="dpd_settings[wpscan_api_key]" type="text" id="wpscan_api_key" value="<?php echo esc_attr( $settings['wpscan_api_key'] ); ?>" class="regular-text" /><p class="description">Provide a WPScan or vulnerability-db API key to check for known reported vulnerabilities. Not required; plugin will use heuristics otherwise.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Auto weekly scan</th>
                        <td><label><input type="checkbox" name="dpd_settings[auto_scan]" value="1" <?php checked( 1, $settings['auto_scan'] ); ?> /> Enable weekly automatic scans</label></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2>Latest scan</h2>
            <p>Last scan: <?php echo $results && isset( $results['scanned_at'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $results['scanned_at'] ) : 'Never'; ?></p>
            <p><a href="#" class="button button-primary" id="dpd-run-scan">Run manual scan now</a></p>

            <?php if ( ! empty( $results['issues'] ) ) : ?>
                <table class="widefat fixed">
                    <thead><tr><th>Plugin</th><th>Issue</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ( $results['issues'] as $issue ) : ?>
                        <tr>
                            <td><?php echo esc_html( $issue['name'] ); ?></td>
                            <td><?php echo esc_html( $issue['status'] ); ?></td>
                            <td><pre style="white-space:pre-wrap;"><?php echo esc_html( $issue['notes'] ); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No issues detected in the last scan.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function maybe_show_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $results = get_option( self::OPTION_KEY, array() );
        if ( empty( $results['issues'] ) ) return;

        // Show a small admin notice summarizing problems
        $count = count( $results['issues'] );
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Dead Plugin Detector:</strong> ' . sprintf( _n( '%s plugin may need attention.', '%s plugins may need attention.', $count ), number_format_i18n( $count ) ) . ' <a href="tools.php?page=dead-plugin-detector">View details</a>.</p></div>';
    }

    public function plugin_row_warning( $plugin_file, $plugin_data ) {
        // only for admins
        if ( ! current_user_can( 'manage_options' ) ) return;

        $results = get_option( self::OPTION_KEY, array() );
        if ( empty( $results['issues'] ) ) return;

        $plugin_basename = plugin_basename( $plugin_file );
        foreach ( $results['issues'] as $issue ) {
            if ( isset( $issue['plugin_basename'] ) && $issue['plugin_basename'] === $plugin_basename ) {
                $label = esc_html( $issue['status'] );
                $message = esc_html( $issue['notes'] );
                echo '<tr class="plugin-update-tr"><td colspan="3" class="plugin-update"><div class="update-message notice inline notice-warning notice-alt"><p><strong>Dead Plugin Detector:</strong> ' . $label . ' — ' . $message . '</p></div></td></tr>';
                break;
            }
        }
    }

    public function run_scan() {
        if ( ! current_user_can( 'manage_options' ) && did_action( 'init' ) ) {
            // If run by CRON, capabilities aren't available in a normal way; we'll still run but suppress admin checks.
        }

        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins();
        $settings = get_option( self::OPTION_SETTINGS, $this->defaults );
        $issues = array();

        foreach ( $all as $file => $data ) {
            $plugin_basename = plugin_basename( $file );
            $folder = dirname( $file );
            if ( $folder === '.' ) $folder = sanitize_title_with_dashes( $data['Name'] );

            $remote = $this->fetch_wporg_plugin_info( $folder );
            $issue = $this->assess_plugin( $data, $file, $folder, $remote, $settings );
            if ( $issue ) $issues[] = $issue;
        }

        $result = array(
            'scanned_at' => time(),
            'issues' => $issues,
        );

        update_option( self::OPTION_KEY, $result );
        return $result;
    }

    private function fetch_wporg_plugin_info( $slug ) {
        if ( empty( $slug ) ) return false;
        $url = "https://api.wordpress.org/plugins/info/1.0/" . rawurlencode( $slug ) . ".json";
        $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $resp ) ) return false;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) return false;
        $body = wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) return false;
        return $json;
    }

    private function assess_plugin( $local_data, $file, $folder, $remote, $settings ) {
        $now = time();
        $notes = array();
        $status = '';
        $plugin_basename = plugin_basename( $file );

        // If not in WP.org repo
        if ( ! $remote ) {
            // Heuristics for external plugins
            $last_updated = isset( $local_data['Version'] ) ? null : null; // local plugins don't contain last updated date
            $notes[] = 'Not found in the WordPress.org plugin directory. Cannot verify remote metadata.';
            $status = 'external';

            // flag if version hasn't changed in a while by comparing to filemtime of plugin main file
            $main_file_path = WP_PLUGIN_DIR . '/' . $file;
            if ( file_exists( $main_file_path ) ) {
                $mtime = filemtime( $main_file_path );
                if ( $mtime && ( ( $now - $mtime ) / ( 60 * 60 * 24 * 30 ) ) > $settings['abandoned_months'] ) {
                    $status = 'abandoned';
                    $notes[] = 'Plugin files not modified for more than ' . intval( $settings['abandoned_months'] ) . ' months (based on file modification time).';
                }
            }

            return array(
                'plugin_basename' => $plugin_basename,
                'name' => $local_data['Name'],
                'status' => $status,
                'notes' => implode( ' ', $notes ),
            );
        }

        // For plugins in WP.org repo, analyze remote fields
        $remote_updated = isset( $remote['last_updated'] ) ? strtotime( $remote['last_updated'] ) : 0;
        $remote_version = isset( $remote['version'] ) ? $remote['version'] : '';
        $remote_installs = isset( $remote['active_installs'] ) ? intval( $remote['active_installs'] ) : 0;
        $tested = isset( $remote['tested'] ) ? $remote['tested'] : '';

        // Removed from repo check: API returns 404 earlier; this branch only runs when remote exists.

        // Outdated check
        if ( version_compare( $local_data['Version'], $remote_version, '<' ) ) {
            $status = 'outdated';
            $notes[] = sprintf( 'Installed version %s is older than repository version %s.', $local_data['Version'], $remote_version );
        }

        // Abandoned check
        $months_since_update = $remote_updated ? ( ( $now - $remote_updated ) / ( 60 * 60 * 24 * 30 ) ) : PHP_INT_MAX;
        if ( $remote_updated && $months_since_update > $settings['abandoned_months'] ) {
            $status = $status ? $status . ', abandoned' : 'abandoned';
            $notes[] = sprintf( 'Last updated %s (%d months ago).', date_i18n( get_option( 'date_format' ), $remote_updated ), intval( $months_since_update ) );
        }

        // Low adoption
        if ( $remote_installs && $remote_installs < $settings['active_installs_threshold'] ) {
            $status = $status ? $status . ', low-adoption' : 'low-adoption';
            $notes[] = sprintf( 'Active installs: %s (below threshold %d).', number_format_i18n( $remote_installs ), $settings['active_installs_threshold'] );
        }

        // Compatibility check: tested against WP version
        global $wp_version;
        if ( ! empty( $tested ) && version_compare( $tested, $wp_version, '<' ) ) {
            $status = $status ? $status . ', untested' : 'untested';
            $notes[] = sprintf( 'Plugin tested up to WordPress %s but your site runs %s.', $tested, $wp_version );
        }

        // Security heuristics: last update > risky_months or untested results
        if ( $remote_updated && ( ( $now - $remote_updated ) / ( 60 * 60 * 24 * 30 ) ) > $settings['risky_months'] ) {
            $status = $status ? $status . ', security-risk' : 'security-risk';
            $notes[] = sprintf( 'No updates in %d+ months — potential security risk.', $settings['risky_months'] );
        }

        // Optional: check vulnerabilities using WPScan-like API if API key provided
        if ( ! empty( $settings['wpscan_api_key'] ) ) {
            $vulns = $this->check_vulnerabilities_wpscan( $remote['slug'], $settings['wpscan_api_key'] );
            if ( $vulns && is_array( $vulns ) && ! empty( $vulns ) ) {
                $status = $status ? $status . ', vuln-reported' : 'vuln-reported';
                $notes[] = 'Known reported vulnerabilities: ' . implode( ', ', $vulns );
            }
        }

        if ( empty( $status ) ) return false;

        return array(
            'plugin_basename' => $plugin_basename,
            'name' => $local_data['Name'],
            'status' => $status,
            'notes' => implode( ' ', $notes ),
        );
    }

    private function check_vulnerabilities_wpscan( $slug, $api_key ) {
        // This is an integration point placeholder. WPScan / other services have paid APIs.
        // We'll show how to call a hypothetical API endpoint; users must supply an API key and confirm terms.

        if ( empty( $slug ) ) return false;

        $url = "https://wpscan.com/api/v3/plugins/" . rawurlencode( $slug );
        $resp = wp_remote_get( $url, array( 'headers' => array( 'Authorization' => 'Token token="' . esc_attr( $api_key ) . '"' ), 'timeout' => 20 ) );
        if ( is_wp_error( $resp ) ) return false;
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) return false;
        $body = wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) return false;

        $vulns = array();
        if ( isset( $json['vulnerabilities'] ) && is_array( $json['vulnerabilities'] ) ) {
            foreach ( $json['vulnerabilities'] as $v ) {
                $vulns[] = isset( $v['title'] ) ? $v['title'] : ( isset( $v['id'] ) ? $v['id'] : 'unknown' );
            }
        }
        return $vulns;
    }

    public function ajax_manual_scan() {
        check_ajax_referer( 'dpd-manual-scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $res = $this->run_scan();
        wp_send_json_success( $res );
    }
}

new Dead_Plugin_Detector();

// Simple assets to make admin UX friendly (optional). We'll create small inline fallbacks to avoid separate files being required.
add_action( 'admin_head', function() {
    // Inline CSS for plugin page and plugin-row
    echo '<style>.dpd-badge{display:inline-block;background:#f7b500;color:#000;padding:2px 6px;border-radius:3px;font-weight:600;margin-left:6px}.plugin-update .notice-alt p{margin:0}</style>';
});

add_action( 'admin_footer', function() {
    // Inline JS to trigger manual scan
    ?>
    <script>
    (function($){
        $(document).on('click', '#dpd-run-scan', function(e){
            e.preventDefault();
            var $btn = $(this);
            $btn.addClass('updating-message');
            $.post( dpd_ajax.ajax_url, { action: 'dpd_manual_scan', nonce: dpd_ajax.nonce }, function( res ){
                $btn.removeClass('updating-message');
                if ( res.success ) {
                    location.reload();
                } else {
                    alert('Scan failed: ' + (res.data || 'unknown'));
                }
            });
        });
    })(jQuery);
    </script>
    <?php
} );

// Register weekly recurrence if missing
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['weekly'] ) ) {
        $schedules['weekly'] = array( 'interval' => 7 * 24 * 60 * 60, 'display' => __( 'Weekly' ) );
    }
    return $schedules;
} );

?>
