<?php
/**
 * Plugin Name: Simple Service Marketplace
 * Description: A lightweight Fiverr-style service ordering system.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) exit;

// ⚠️ Install Stripe via Composer in plugin folder:
// composer require stripe/stripe-php
require_once __DIR__ . '/vendor/autoload.php';

class Pro_Service_Marketplace {

    private $stripe_secret = 'sk_test_xxx';
    private $webhook_secret = 'whsec_xxx';

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_shortcode('psm_services', [$this, 'services_shortcode']);
        add_shortcode('psm_dashboard', [$this, 'dashboard_shortcode']);

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
    }

    public function services_shortcode() {
        $q = new WP_Query(['post_type'=>'psm_service']);
        ob_start();

        echo '<div>';
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
        echo '</div>';

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
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $this->webhook_secret
            );
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
            echo '</div>';
        }

        return ob_get_clean();
    }
}

new Pro_Service_Marketplace();
