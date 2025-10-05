<?php
/**
 * Plugin Name: Gamification Layer
 * Description: Adds points, badges, and leaderboards to comments and WooCommerce purchases.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Text Domain: gamification-layer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class WP_Gamification_Layer {
    private static $instance = null;
    public $version = '1.0.0';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->setup_constants();
            self::$instance->includes();
            self::$instance->hooks();
        }
        return self::$instance;
    }

    private function setup_constants() {
        if (!defined('WPLGAM_DIR')) define('WPLGAM_DIR', plugin_dir_path(__FILE__));
        if (!defined('WPLGAM_URL')) define('WPLGAM_URL', plugin_dir_url(__FILE__));
        if (!defined('WPLGAM_VERSION')) define('WPLGAM_VERSION', $this->version);
    }

    private function includes() {
        // nothing external for now; keep single-file for simplicity
    }

    private function hooks() {
        register_activation_hook(__FILE__, array($this, 'activation'));
        register_deactivation_hook(__FILE__, array($this, 'deactivation'));

        add_action('init', array($this, 'load_textdomain'));

        // Award points on comment submit (only when approved)
        add_action('comment_post', array($this, 'on_comment_post'), 10, 3);
        add_action('transition_comment_status', array($this, 'on_comment_status_change'), 10, 3);

        // WooCommerce: award points on completed orders
        add_action('woocommerce_order_status_completed', array($this, 'on_woocommerce_order_completed'));

        // Admin menu
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Shortcodes
        add_shortcode('gamify_leaderboard', array($this, 'shortcode_leaderboard'));
        add_shortcode('gamify_points', array($this, 'shortcode_points'));
        add_shortcode('gamify_badges', array($this, 'shortcode_badges'));

        // REST endpoints (basic)
        add_action('rest_api_init', function () {
            register_rest_route('gamify/v1', '/user/(?P<id>\d+)', array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_user_info'),
                'permission_callback' => '__return_true',
            ));
        });
    }

    public function activation() {
        // default options
        $defaults = array(
            'points_per_comment' => 10,
            'points_per_dollar' => 1, // for purchases
            'badge_thresholds' => array(
                'Bronze' => 100,
                'Silver' => 500,
                'Gold' => 1000,
                'Platinum' => 5000,
            ),
        );
        add_option('wplgam_options', $defaults);

        global $wpdb;
        $table = $wpdb->prefix . 'wplgam_badge_log';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            badge VARCHAR(191) NOT NULL,
            awarded_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function deactivation() {
        // nothing destructive
    }

    public function load_textdomain() {
        load_plugin_textdomain('gamification-layer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /** Points management **/
    public function add_points($user_id, $amount, $context = '') {
        $user_id = absint($user_id);
        if (!$user_id || !is_numeric($amount)) return false;
        $amount = intval($amount);
        $current = intval(get_user_meta($user_id, 'wplgam_points', true));
        $new = $current + $amount;
        update_user_meta($user_id, 'wplgam_points', $new);

        // log
        $logs = get_user_meta($user_id, 'wplgam_logs', true);
        if (!is_array($logs)) $logs = array();
        $logs[] = array('delta' => $amount, 'context' => $context, 'time' => current_time('mysql'));
        update_user_meta($user_id, 'wplgam_logs', $logs);

        // maybe award badges after points change
        $this->maybe_award_badge_by_points($user_id, $new);

        return $new;
    }

    public function remove_points($user_id, $amount, $context = '') {
        $user_id = absint($user_id);
        $amount = intval($amount);
        $current = intval(get_user_meta($user_id, 'wplgam_points', true));
        $new = max(0, $current - $amount);
        update_user_meta($user_id, 'wplgam_points', $new);

        $logs = get_user_meta($user_id, 'wplgam_logs', true);
        if (!is_array($logs)) $logs = array();
        $logs[] = array('delta' => -$amount, 'context' => $context, 'time' => current_time('mysql'));
        update_user_meta($user_id, 'wplgam_logs', $logs);

        return $new;
    }

    /** Badges **/
    public function award_badge($user_id, $badge_name) {
        $user_id = absint($user_id);
        if (!$user_id || empty($badge_name)) return false;

        // check if user already has badge
        $existing = $this->get_user_badges($user_id);
        if (in_array($badge_name, $existing)) return false;

        add_user_meta($user_id, 'wplgam_badge', $badge_name);

        global $wpdb;
        $table = $wpdb->prefix . 'wplgam_badge_log';
        $wpdb->insert($table, array(
            'user_id' => $user_id,
            'badge' => $badge_name,
            'awarded_at' => current_time('mysql'),
        ));

        do_action('wplgam_badge_awarded', $user_id, $badge_name);
        return true;
    }

    public function get_user_badges($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return array();
        $badges = get_user_meta($user_id, 'wplgam_badge');
        if (!is_array($badges)) $badges = array();
        return array_values(array_unique(array_filter($badges)));
    }

    public function maybe_award_badge_by_points($user_id, $points) {
        $opts = get_option('wplgam_options', array());
        $thresholds = isset($opts['badge_thresholds']) ? $opts['badge_thresholds'] : array();
        if (!is_array($thresholds)) return;

        foreach ($thresholds as $label => $threshold) {
            if ($points >= intval($threshold)) {
                $this->award_badge($user_id, $label);
            }
        }
    }

    /** Hooks **/
    public function on_comment_post($comment_id, $comment_approved, $commentdata) {
        // if auto-approved, award immediately
        if (1 === $comment_approved) {
            $user_id = (int) $commentdata['user_id'];
            if ($user_id) {
                $opts = get_option('wplgam_options');
                $pts = intval($opts['points_per_comment']);
                if ($pts) $this->add_points($user_id, $pts, 'comment');
            }
        }
    }

    public function on_comment_status_change($new, $old, $comment) {
        // when a comment becomes approved (e.g., moderation), give points
        if ($old !== 'approved' && $new === 'approved') {
            $user_id = (int) $comment->user_id;
            if ($user_id) {
                $opts = get_option('wplgam_options');
                $pts = intval($opts['points_per_comment']);
                if ($pts) $this->add_points($user_id, $pts, 'comment_approved');
            }
        }
        // if it becomes unapproved, optionally remove points? Not by default.
    }

    public function on_woocommerce_order_completed($order_id) {
        if (!class_exists('WC_Order')) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        $user_id = (int) $order->get_user_id();
        if (!$user_id) return;

        $total = floatval($order->get_total());
        $opts = get_option('wplgam_options');
        $per_dollar = isset($opts['points_per_dollar']) ? intval($opts['points_per_dollar']) : 0;
        if ($per_dollar <= 0) return;
        $points = intval(round($total * $per_dollar));
        if ($points > 0) $this->add_points($user_id, $points, 'order_'.$order_id);
    }

    /** Admin UI **/
    public function admin_menu() {
        add_menu_page('Gamification', 'Gamification', 'manage_options', 'wplgam-dashboard', array($this, 'admin_dashboard'), 'dashicons-awards', 60);
        add_submenu_page('wplgam-dashboard', 'Settings', 'Settings', 'manage_options', 'wplgam-settings', array($this, 'admin_settings'));
        add_submenu_page('wplgam-dashboard', 'Leaderboard', 'Leaderboard', 'manage_options', 'wplgam-leaderboard', array($this, 'admin_leaderboard'));
    }

    public function register_settings() {
        register_setting('wplgam_options_group', 'wplgam_options', array($this, 'sanitize_options'));
    }

    public function sanitize_options($input) {
        $out = array();
        $out['points_per_comment'] = isset($input['points_per_comment']) ? intval($input['points_per_comment']) : 10;
        $out['points_per_dollar'] = isset($input['points_per_dollar']) ? intval($input['points_per_dollar']) : 1;
        // decode thresholds from textarea (simple key:value per line)
        $out['badge_thresholds'] = array();
        if (!empty($input['badge_thresholds_raw'])) {
            $lines = preg_split('/\r?\n/', $input['badge_thresholds_raw']);
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, ':') !== false) {
                    list($label, $val) = array_map('trim', explode(':', $line, 2));
                    $out['badge_thresholds'][$label] = intval($val);
                }
            }
        }
        return $out;
    }

    public function admin_dashboard() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Gamification Layer</h1>
            <p>Quick stats:</p>
            <ul>
                <li>Total users with points: <?php echo $this->count_users_with_points(); ?></li>
                <li>Total badges awarded: <?php echo $this->count_badge_awards(); ?></li>
            </ul>
            <p>Shortcodes:</p>
            <ul>
                <li><code>[gamify_leaderboard limit=10]</code> — show leaderboard</li>
                <li><code>[gamify_points user_id=]</code> — show points for user</li>
                <li><code>[gamify_badges user_id=]</code> — show user's badges</li>
            </ul>
        </div>
        <?php
    }

    public function admin_settings() {
        if (!current_user_can('manage_options')) return;
        $opts = get_option('wplgam_options');
        $raw = '';
        if (!empty($opts['badge_thresholds']) && is_array($opts['badge_thresholds'])) {
            foreach ($opts['badge_thresholds'] as $label => $val) {
                $raw .= $label . ': ' . intval($val) . "\n";
            }
        }
        ?>
        <div class="wrap">
            <h1>Gamification Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wplgam_options_group'); do_settings_sections('wplgam_options_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="points_per_comment">Points per comment</label></th>
                        <td><input name="wplgam_options[points_per_comment]" type="number" id="points_per_comment" value="<?php echo esc_attr($opts['points_per_comment']); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="points_per_dollar">Points per $ spent</label></th>
                        <td><input name="wplgam_options[points_per_dollar]" type="number" id="points_per_dollar" value="<?php echo esc_attr($opts['points_per_dollar']); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="badge_thresholds_raw">Badge thresholds (one per line "Label: number")</label></th>
                        <td><textarea name="wplgam_options[badge_thresholds_raw]" id="badge_thresholds_raw" rows="6" cols="40" class="large-text code"><?php echo esc_textarea($raw); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function admin_leaderboard() {
        if (!current_user_can('manage_options')) return;
        $limit = 50;
        $board = $this->get_leaderboard($limit);
        ?>
        <div class="wrap">
            <h1>Leaderboard (top <?php echo $limit; ?>)</h1>
            <table class="widefat fixed striped">
                <thead>
                    <tr><th>Rank</th><th>User</th><th>Points</th><th>Badges</th></tr>
                </thead>
                <tbody>
                <?php $rank = 1; foreach ($board as $row): ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><?php echo esc_html($row->display_name . ' (' . $row->user_login . ')'); ?></td>
                        <td><?php echo intval($row->points); ?></td>
                        <td><?php echo esc_html(implode(', ', $this->get_user_badges($row->ID))); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /** Shortcodes **/
    public function shortcode_leaderboard($atts) {
        $atts = shortcode_atts(array('limit' => 10), $atts, 'gamify_leaderboard');
        $limit = intval($atts['limit']);
        $rows = $this->get_leaderboard($limit);
        ob_start();
        echo '<div class="wplgam-leaderboard">';
        echo '<ol>';
        foreach ($rows as $r) {
            $badges = $this->get_user_badges($r->ID);
            echo '<li>' . esc_html($r->display_name ?: $r->user_login) . ' — <strong>' . intval($r->points) . '</strong> pts';
            if (!empty($badges)) echo ' <em>(' . esc_html(implode(', ', $badges)) . ')</em>';
            echo '</li>';
        }
        echo '</ol>';
        echo '</div>';
        return ob_get_clean();
    }

    public function shortcode_points($atts) {
        $atts = shortcode_atts(array('user_id' => 0), $atts, 'gamify_points');
        $uid = intval($atts['user_id']) ?: get_current_user_id();
        $points = intval(get_user_meta($uid, 'wplgam_points', true));
        return '<span class="wplgam-points">' . esc_html($points) . ' pts</span>';
    }

    public function shortcode_badges($atts) {
        $atts = shortcode_atts(array('user_id' => 0), $atts, 'gamify_badges');
        $uid = intval($atts['user_id']) ?: get_current_user_id();
        $badges = $this->get_user_badges($uid);
        if (empty($badges)) return '<span class="wplgam-badges">No badges yet</span>';
        $out = '<div class="wplgam-badges">';
        foreach ($badges as $b) {
            $out .= '<span class="wplgam-badge">' . esc_html($b) . '</span> ';
        }
        $out .= '</div>';
        return $out;
    }

    /** Utilities **/
    public function get_leaderboard($limit = 10) {
        global $wpdb;
        $users_table = $wpdb->users;
        $meta_table = $wpdb->usermeta;
        $q = $wpdb->prepare("SELECT u.ID, u.user_login, u.display_name, CAST(um.meta_value AS SIGNED) as points
            FROM $users_table u
            INNER JOIN $meta_table um ON um.user_id = u.ID AND um.meta_key = 'wplgam_points'
            ORDER BY points DESC
            LIMIT %d", $limit);
        $rows = $wpdb->get_results($q);
        return $rows;
    }

    public function count_users_with_points() {
        global $wpdb;
        $meta_table = $wpdb->usermeta;
        $count = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $meta_table WHERE meta_key = 'wplgam_points' AND meta_value > 0");
        return intval($count);
    }

    public function count_badge_awards() {
        global $wpdb;
        $table = $wpdb->prefix . 'wplgam_badge_log';
        $cnt = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table"));
        return intval($cnt);
    }

    /** REST callback **/
    public function rest_user_info($request) {
        $id = intval($request['id']);
        if (!$id) return new WP_Error('invalid_user', 'Invalid user ID', array('status' => 400));
        $data = array(
            'id' => $id,
            'points' => intval(get_user_meta($id, 'wplgam_points', true)),
            'badges' => $this->get_user_badges($id),
        );
        return rest_ensure_response($data);
    }
}

// bootstrap
WP_Gamification_Layer::instance();

// Expose a couple helper functions for theme / developers
if (!function_exists('wplgam_add_points')) {
    function wplgam_add_points($user_id, $amount, $context = '') {
        return WP_Gamification_Layer::instance()->add_points($user_id, $amount, $context);
    }
}

if (!function_exists('wplgam_award_badge')) {
    function wplgam_award_badge($user_id, $badge_name) {
        return WP_Gamification_Layer::instance()->award_badge($user_id, $badge_name);
    }
}

?>
