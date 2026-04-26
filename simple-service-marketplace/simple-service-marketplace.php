<?php
/**
 * Plugin Name: Simple Service Marketplace
 * Description: A lightweight Fiverr-style service ordering system.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/vendor/autoload.php';

class Pro_Service_Marketplace {

    private $stripe_secret = 'sk_test_xxx';
    private $webhook_secret = 'whsec_xxx';

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_shortcode('psm_services', [$this, 'services_shortcode']);
        add_shortcode('psm_dashboard', [$this, 'dashboard_shortcode']);

        add_action('admin_post_psm_send_message', [$this, 'send_message']);
        add_action('admin_post_nopriv_psm_send_message', [$this, 'send_message']);

        add_action('rest_api_init', function () {
            register_rest_route('psm/v1', '/stripe-webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'stripe_webhook'],
                'permission_callback' => '__return_true'
            ]);
        });
    }

    public function register_post_types() {
        register_post_type('psm_service', [
            'label' => 'Services',
            'public' => true,
            'supports' => ['title','editor','thumbnail','author'],
        ]);

        register_post_type('psm_order', [
            'label' => 'Orders',
            'public' => false,
            'show_ui' => true,
            'supports' => ['title','author'],
        ]);

        register_post_type('psm_message', [
            'label' => 'Messages',
            'public' => false,
            'show_ui' => false,
            'supports' => ['editor','author'],
        ]);
    }

    public function services_shortcode() {
        $q = new WP_Query(['post_type'=>'psm_service']);
        ob_start();

        while($q->have_posts()): $q->the_post();
            $price = get_post_meta(get_the_ID(),'price',true);
            ?>
            <div>
                <h3><?php the_title(); ?></h3>
                <p><?php echo wp_trim_words(get_the_content(),15); ?></p>
                <strong>$<?php echo esc_html($price); ?></strong>
                <form method="post">
                    <?php wp_nonce_field('psm_checkout','psm_nonce'); ?>
                    <input type="hidden" name="service_id" value="<?php the_ID(); ?>">
                    <button name="psm_checkout">Order</button>
                </form>
            </div>
            <?php
        endwhile;

        if(isset($_POST['psm_checkout'])) {
            return $this->create_checkout();
        }

        return ob_get_clean();
    }

    private function create_checkout() {
        if(!is_user_logged_in()) return 'Login required';
        if(!wp_verify_nonce($_POST['psm_nonce'],'psm_checkout')) return 'Invalid request';

        $service_id = intval($_POST['service_id']);
        $price = get_post_meta($service_id,'price',true);

        \Stripe\Stripe::setApiKey($this->stripe_secret);

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => get_the_title($service_id),
                    ],
                    'unit_amount' => $price * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'metadata' => [
                'service_id' => $service_id,
                'user_id' => get_current_user_id()
            ],
            'success_url' => home_url('/success'),
            'cancel_url' => home_url('/cancel'),
        ]);

        wp_redirect($session->url);
        exit;
    }

    public function stripe_webhook($request) {
        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $this->webhook_secret);
        } catch (Exception $e) {
            return new WP_REST_Response('Invalid', 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            $service_id = $session->metadata->service_id;
            $user_id = $session->metadata->user_id;
            $service = get_post($service_id);

            $order_id = wp_insert_post([
                'post_type'=>'psm_order',
                'post_status'=>'publish',
                'post_author'=>$user_id,
                'post_title'=>'Order #'.$session->id
            ]);

            update_post_meta($order_id,'service_id',$service_id);
            update_post_meta($order_id,'seller_id',$service->post_author);
            update_post_meta($order_id,'stripe_session',$session->id);
        }

        return new WP_REST_Response('OK', 200);
    }

    public function dashboard_shortcode() {
        if(!is_user_logged_in()) return 'Login required';

        $orders = get_posts([
            'post_type'=>'psm_order',
            'author'=>get_current_user_id()
        ]);

        ob_start();
        echo '<h2>My Orders</h2>';

        foreach($orders as $order) {
            echo '<div>';
            echo '<strong>'.$order->post_title.'</strong>';
            echo '<a href="?order_id='.$order->ID.'">View</a>';
            echo '</div>';
        }

        if(isset($_GET['order_id'])) {
            echo $this->render_messages(intval($_GET['order_id']));
        }

        return ob_get_clean();
    }

    private function render_messages($order_id) {
        $messages = get_posts([
            'post_type'=>'psm_message',
            'meta_key'=>'order_id',
            'meta_value'=>$order_id,
            'orderby'=>'date',
            'order'=>'ASC'
        ]);

        ob_start();

        echo '<h3>Conversation</h3>';

        foreach($messages as $msg) {
            $author = get_userdata($msg->post_author);
            echo '<div style="margin-bottom:10px;">';
            echo '<strong>'.$author->display_name.':</strong>';
            echo '<p>'.esc_html($msg->post_content).'</p>';
            echo '</div>';
        }

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('psm_message','psm_msg_nonce'); ?>
            <input type="hidden" name="action" value="psm_send_message">
            <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
            <textarea name="message" required placeholder="Type your message..."></textarea>
            <button type="submit">Send</button>
        </form>
        <?php

        return ob_get_clean();
    }

    public function send_message() {
        if(!is_user_logged_in()) wp_die('Login required');
        if(!wp_verify_nonce($_POST['psm_msg_nonce'],'psm_message')) wp_die('Invalid');

        $order_id = intval($_POST['order_id']);
        $message = sanitize_textarea_field($_POST['message']);

        $msg_id = wp_insert_post([
            'post_type'=>'psm_message',
            'post_content'=>$message,
            'post_status'=>'publish',
            'post_author'=>get_current_user_id()
        ]);

        update_post_meta($msg_id,'order_id',$order_id);

        wp_redirect(wp_get_referer());
        exit;
    }
}

new Pro_Service_Marketplace();
