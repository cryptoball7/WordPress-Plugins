<?php
/*
Plugin Name: Customizable Content Reveal
Plugin URI: https://example.com
Description: Unlock hidden content after specific user actions (share, subscribe, click, etc.)
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
*/

if (!defined('ABSPATH')) exit;

define('CCR_PATH', plugin_dir_path(__FILE__));
define('CCR_URL', plugin_dir_url(__FILE__));

// Enqueue scripts and styles
function ccr_enqueue_scripts() {
    wp_enqueue_style('ccr-style', CCR_URL . 'assets/css/reveal.css');
    wp_enqueue_script('ccr-script', CCR_URL . 'assets/js/reveal.js', ['jquery'], '1.0', true);

    $settings = get_option('ccr_settings');
    wp_localize_script('ccr-script', 'ccrData', [
        'unlock_type' => $settings['unlock_type'] ?? 'button',
    ]);
}
add_action('wp_enqueue_scripts', 'ccr_enqueue_scripts');

// Shortcode
function ccr_shortcode($atts, $content = null) {
    ob_start(); ?>
    <div class="ccr-container">
        <div class="ccr-hidden-content" style="display:none;">
            <?php echo do_shortcode($content); ?>
        </div>
        <div class="ccr-lock">
            <?php
            $settings = get_option('ccr_settings');
            $type = $settings['unlock_type'] ?? 'button';
            if ($type === 'button') {
                echo '<button class="ccr-unlock-btn">Unlock Content</button>';
            } elseif ($type === 'share') {
                echo '<p>Share this post to unlock content!</p>';
                echo '<button class="ccr-share-btn">Share Now</button>';
            } elseif ($type === 'subscribe') {
                echo '<input type="email" placeholder="Enter your email" class="ccr-email" />';
                echo '<button class="ccr-subscribe-btn">Subscribe</button>';
            }
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('reveal_content', 'ccr_shortcode');

// Admin settings page
function ccr_add_admin_menu() {
    add_options_page('Content Reveal Settings', 'Content Reveal', 'manage_options', 'ccr-settings', 'ccr_settings_page');
}
add_action('admin_menu', 'ccr_add_admin_menu');

function ccr_settings_init() {
    register_setting('ccr_settings_group', 'ccr_settings');

    add_settings_section('ccr_settings_section', 'Unlock Options', null, 'ccr-settings');

    add_settings_field('unlock_type', 'Unlock Type', 'ccr_unlock_type_field', 'ccr-settings', 'ccr_settings_section');
}
add_action('admin_init', 'ccr_settings_init');

function ccr_unlock_type_field() {
    $options = get_option('ccr_settings');
    $type = $options['unlock_type'] ?? 'button'; ?>
    <select name="ccr_settings[unlock_type]">
        <option value="button" <?php selected($type, 'button'); ?>>Button Click</option>
        <option value="share" <?php selected($type, 'share'); ?>>Social Share</option>
        <option value="subscribe" <?php selected($type, 'subscribe'); ?>>Email Subscribe</option>
    </select>
<?php }

function ccr_settings_page() { ?>
    <div class="wrap">
        <h1>Customizable Content Reveal Settings</h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('ccr_settings_group');
            do_settings_sections('ccr-settings');
            submit_button();
            ?>
        </form>
    </div>
<?php }
