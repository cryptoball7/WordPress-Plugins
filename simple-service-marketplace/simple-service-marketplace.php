<?php
/**
 * Plugin Name: Simple Service Marketplace
 * Description: A lightweight Fiverr-style service ordering system.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH'))
    exit;

class SSO_Plugin
{

    public function __construct()
    {
        add_action('init', [$this, 'register_post_types']);
        add_shortcode('sso_order_form', [$this, 'order_form_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_sso_submit_order', [$this, 'handle_order']);
        add_action('wp_ajax_nopriv_sso_submit_order', [$this, 'handle_order']);

        add_action('init', function () {
            wp_register_script(
                'sso-block',
                plugin_dir_url(__FILE__) . 'block.js',
                ['wp-blocks', 'wp-element', 'wp-editor'],
                null,
                true
            );

            register_block_type('sso/order-form', [
                'editor_script' => 'sso-block',
                'render_callback' => function ($attributes) {
                    return do_shortcode('[sso_order_form]');
                }
            ]);
        });

        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('sso-css', plugin_dir_url(__FILE__) . 'sso.css');
        });

        add_action('add_meta_boxes', function () {
            add_meta_box(
                'sso_order_details',
                'Order Details',
                'sso_render_order_details',
                'sso_order',
                'normal',
                'high'
            );
        });

        function sso_render_order_details($post)
        {
            $name = get_post_meta($post->ID, 'name', true);
            $email = get_post_meta($post->ID, 'email', true);
            $requirements = get_post_meta($post->ID, 'requirements', true);
            $file_id = get_post_meta($post->ID, 'file', true);
            $link = get_post_meta($post->ID, 'link', true);

            echo '<p><strong>Name:</strong> ' . esc_html($name) . '</p>';

            echo '<p><strong>Email:</strong> ' . esc_html($email) . '</p>';

            echo '<p><strong>Requirements:</strong><br>' . nl2br(esc_html($requirements)) . '</p>';

            if ($file_id) {
                $url = wp_get_attachment_url($file_id);
                echo '<p><strong>File:</strong> <a href="' . esc_url($url) . '" target="_blank">Download</a></p>';
            }

            echo '<p><strong>Link:</strong> <a href="' . esc_html($link) . '">' . esc_html($link) . '</a></p>';
        }

        add_filter('manage_sso_order_posts_columns', function ($columns) {
            $columns['email'] = 'Email';
            $columns['status'] = 'Status';
            return $columns;
        });

        add_action('manage_sso_order_posts_custom_column', function ($column, $post_id) {
            if ($column === 'email') {
                echo esc_html(get_post_meta($post_id, 'email', true));
            }

            if ($column === 'status') {
                echo esc_html(get_post_meta($post_id, 'status', true) ?: 'New');
            }
        }, 10, 2);

        register_activation_hook(__FILE__, function () {

            $page = get_page_by_path('order-view');

            if (!$page) {
                wp_insert_post([
                    'post_title' => 'Order View',
                    'post_name' => 'order-view',
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_content' => '[sso_order_view]'
                ]);
            }
        });

add_action('wp_ajax_sso_get_messages', 'sso_get_messages');
add_action('wp_ajax_nopriv_sso_get_messages', 'sso_get_messages');

function sso_get_messages() {

    $order_id = intval($_POST['order_id']);

    $messages = get_posts([
        'post_type' => 'sso_message',
        'meta_key' => 'order_id',
        'meta_value' => $order_id,
        'orderby' => 'date',
        'order' => 'ASC',
        'numberposts' => -1
    ]);

    $data = [];

    foreach ($messages as $msg) {

        $data[] = [
            'id' => $msg->ID,
            'content' => wpautop($msg->post_content),
            'sender' => get_post_meta($msg->ID, 'sender', true),
            'date' => get_the_date('M j, g:i A', $msg)
        ];
    }

    wp_send_json_success($data);
}


    }

    public function register_post_types()
    {
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

    public function enqueue_scripts()
    {
        wp_enqueue_script('sso-js', plugin_dir_url(__FILE__) . 'sso.js', ['jquery'], null, true);
        wp_localize_script('sso-js', 'sso_ajax', [
            'url' => admin_url('admin-ajax.php')
        ]);
    }

    public function order_form_shortcode($atts)
    {
        ob_start(); ?>
        <form id="sso-order-form" class="sso-form" enctype="multipart/form-data">
            <div class="sso-field">
                <label>Name</label>
                <input type="text" name="name" required>
            </div>

            <div class="sso-field">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>

            <div class="sso-field">
                <label>Project Details</label>
                <textarea name="requirements"></textarea>
            </div>

            <div class="sso-field">
                <label>Upload Files</label>
                <input type="file" name="file">
            </div>

            <button class="sso-submit">Submit Order</button>
        </form>
        <div id="sso-response"></div>
        <?php return ob_get_clean();
    }

    public function handle_order()
    {
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $requirements = sanitize_textarea_field($_POST['requirements']);

        $order_id = wp_insert_post([
            'post_type' => 'sso_order',
            'post_title' => 'Order - ' . $name,
            'post_status' => 'publish'
        ]);

        update_post_meta($order_id, 'status', 'Pending');

        update_post_meta($order_id, 'name', $name);
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

        update_post_meta($order_id, 'link', $link);

        wp_send_json_success(['link' => $link]);
    }
}

new SSO_Plugin();

// Messaging system (basic)
add_action('init', function () {
    register_post_type('sso_message', [
        'label' => 'Messages',
        'public' => false,
        'show_ui' => true
    ]);
});

add_shortcode('sso_order_view', function () {
    $id = intval($_GET['id']);
    $key = sanitize_text_field($_GET['key']);

    $saved_key = get_post_meta($id, 'secret', true);

    if ($key !== $saved_key)
        return 'Invalid access';

    $current_user = wp_get_current_user();

    $name = $current_user->display_name;

    if("" == $name) {
        $name = $current_user->user_login;

        if("" == $name) {
            $name = get_post_meta($id, 'name', true);
        }
    }

    if (isset($_POST['send_msg'])) {
        $msg = sanitize_textarea_field($_POST['message']);
        $msg_id = wp_insert_post([
            'post_type' => 'sso_message',
            'post_content' => $msg,
            'post_status' => 'publish'
        ]);
        update_post_meta($msg_id, 'order_id', $id);
        update_post_meta($msg_id, 'sender', $name); // or 'admin'
        update_post_meta($msg_id, 'type', 'message'); // message | status | system
        echo '<p>Message sent</p>';
    }

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
        echo '<div><strong>' . esc_html(get_post_meta($msg->ID, 'sender', true)) . '</strong></div>';
        echo '<div>' . esc_html($msg->post_content) . '</div>';
    }

    echo '<form method="post">';
    echo '<div><strong>'. $name .'</strong></div>';
    echo '<textarea name="message"></textarea>';
    echo '<button type="submit" name="send_msg">Send</button>';
    echo '</form>';

    return ob_get_clean();
});

add_action('wp_ajax_sso_send_message', 'sso_send_message');
add_action('wp_ajax_nopriv_sso_send_message', 'sso_send_message');

function sso_send_message() {
    $order_id = intval($_POST['order_id']);
    $message = sanitize_textarea_field($_POST['message']);

    $msg_id = wp_insert_post([
        'post_type' => 'sso_message',
        'post_content' => $message,
        'post_status' => 'publish'
    ]);

    update_post_meta($msg_id, 'order_id', $order_id);
    update_post_meta($msg_id, 'sender', 'client');

    wp_send_json_success();
}


// WooCommerce integration toggle
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        // Hook services into WooCommerce products
    }
});
