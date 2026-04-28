<?php
/**
 * Plugin Name: Simple Service Marketplace
 * Description: A lightweight Fiverr-style service ordering system.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) exit;

class SSO_Plugin {

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_shortcode('sso_order_form', [$this, 'order_form_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_sso_submit_order', [$this, 'handle_order']);
        add_action('wp_ajax_nopriv_sso_submit_order', [$this, 'handle_order']);

add_action('init', function() {
    wp_register_script(
        'sso-block',
        plugin_dir_url(__FILE__) . 'block.js',
        ['wp-blocks', 'wp-element', 'wp-editor'],
        null,
        true
    );

    register_block_type('sso/order-form', [
        'editor_script' => 'sso-block',
        'render_callback' => function($attributes) {
            return do_shortcode('[sso_order_form]');
        }
    ]);
});
    }

    public function register_post_types() {
        register_post_type('sso_service', [
            'label' => 'Services',
            'public' => true,
            'supports' => ['title', 'editor']
        ]);

        register_post_type('sso_order', [
            'label' => 'Orders',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title']
        ]);
    }

    public function enqueue_scripts() {
        wp_enqueue_script('sso-js', plugin_dir_url(__FILE__) . 'sso.js', ['jquery'], null, true);
        wp_localize_script('sso-js', 'sso_ajax', [
            'url' => admin_url('admin-ajax.php')
        ]);
    }

    public function order_form_shortcode($atts) {
        ob_start(); ?>
        <form id="sso-order-form" enctype="multipart/form-data">
            <input type="text" name="name" placeholder="Your Name" required>
            <input type="email" name="email" placeholder="Email" required>
            <textarea name="requirements" placeholder="Describe your needs"></textarea>
            <input type="file" name="file">
            <button type="submit">Submit Order</button>
        </form>
        <div id="sso-response"></div>
        <?php return ob_get_clean();
    }

    public function handle_order() {
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $requirements = sanitize_textarea_field($_POST['requirements']);

        $order_id = wp_insert_post([
            'post_type' => 'sso_order',
            'post_title' => 'Order - ' . $name,
            'post_status' => 'publish'
        ]);

        update_post_meta($order_id, 'email', $email);
        update_post_meta($order_id, 'requirements', $requirements);

        if (!empty($_FILES['file']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            $uploaded = media_handle_upload('file', $order_id);
            update_post_meta($order_id, 'file', $uploaded);
        }

        $secret = wp_generate_password(20, false);
        update_post_meta($order_id, 'secret', $secret);

        $link = site_url('/order-view/?id=' . $order_id . '&key=' . $secret);

        wp_send_json_success(['link' => $link]);
    }
}

new SSO_Plugin();

// Messaging system (basic)
add_action('init', function() {
    register_post_type('sso_message', [
        'label' => 'Messages',
        'public' => false,
        'show_ui' => true
    ]);
});

add_shortcode('sso_order_view', function() {
    $id = intval($_GET['id']);
    $key = sanitize_text_field($_GET['key']);

    $saved_key = get_post_meta($id, 'secret', true);

    if ($key !== $saved_key) return 'Invalid access';

    ob_start();

    echo '<h2>Order Details</h2>';
    echo '<p>' . esc_html(get_post_meta($id, 'requirements', true)) . '</p>';

    echo '<h3>Messages</h3>';

    $messages = get_posts([
        'post_type' => 'sso_message',
        'meta_key' => 'order_id',
        'meta_value' => $id
    ]);

    foreach ($messages as $msg) {
        echo '<div>' . esc_html($msg->post_content) . '</div>';
    }

    ?>
    <form method="post">
        <textarea name="message"></textarea>
        <button name="send_msg">Send</button>
    </form>
    <?php

    if (isset($_POST['send_msg'])) {
        $msg = sanitize_textarea_field($_POST['message']);
        $msg_id = wp_insert_post([
            'post_type' => 'sso_message',
            'post_content' => $msg,
            'post_status' => 'publish'
        ]);
        update_post_meta($msg_id, 'order_id', $id);
        echo '<p>Message sent</p>';
    }

    return ob_get_clean();
});

// WooCommerce integration toggle
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        // Hook services into WooCommerce products
    }
});
