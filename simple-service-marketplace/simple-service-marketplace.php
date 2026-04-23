<?php
/**
 * Plugin Name: Simple Service Marketplace
 * Description: A lightweight Fiverr-style service ordering system.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) exit;

class Simple_Service_Marketplace {

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_shortcode('service_list', [$this, 'service_list_shortcode']);
        add_shortcode('service_order_form', [$this, 'order_form_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_submit_order', [$this, 'handle_order']);
        add_action('admin_post_nopriv_submit_order', [$this, 'handle_order']);
    }

    public function enqueue_assets() {
        wp_enqueue_style('ssm-style', plugin_dir_url(__FILE__) . 'style.css');
    }

    public function register_post_types() {
        register_post_type('service', [
            'labels' => ['name' => 'Services'],
            'public' => true,
            'has_archive' => true,
            'supports' => ['title', 'editor', 'thumbnail'],
        ]);

        register_post_type('order', [
            'labels' => ['name' => 'Orders'],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
        ]);
    }

    public function service_list_shortcode() {
        $services = get_posts(['post_type' => 'service']);
        $output = '<div class="services">';

        foreach ($services as $service) {
            $output .= '<div class="service">';
            $output .= '<h3>' . esc_html($service->post_title) . '</h3>';
            $output .= '<p>' . esc_html(wp_trim_words($service->post_content, 20)) . '</p>';
            $output .= '<a href="?order_service=' . $service->ID . '">Order</a>';
            $output .= '</div>';
        }

        $output .= '</div>';
        return $output;
    }

    public function order_form_shortcode() {
        if (!isset($_GET['order_service'])) return '';

        $service_id = intval($_GET['order_service']);
        $service = get_post($service_id);

        if (!$service) return '';

        ob_start();
        ?>
        <h2>Order: <?php echo esc_html($service->post_title); ?></h2>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="submit_order">
            <input type="hidden" name="service_id" value="<?php echo $service_id; ?>">

            <label>Your Name</label>
            <input type="text" name="name" required>

            <label>Your Email</label>
            <input type="email" name="email" required>

            <label>Details</label>
            <textarea name="details" required></textarea>

            <button type="submit">Place Order</button>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_order() {
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $details = sanitize_textarea_field($_POST['details']);
        $service_id = intval($_POST['service_id']);

        $order_id = wp_insert_post([
            'post_type' => 'order',
            'post_title' => 'Order from ' . $name,
            'post_status' => 'publish'
        ]);

        if ($order_id) {
            update_post_meta($order_id, 'email', $email);
            update_post_meta($order_id, 'details', $details);
            update_post_meta($order_id, 'service_id', $service_id);
        }

        wp_redirect(home_url('/thank-you'));
        exit;
    }
}

new Simple_Service_Marketplace();
