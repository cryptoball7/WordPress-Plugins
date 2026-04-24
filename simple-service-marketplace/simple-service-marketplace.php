<?php
/**
 * Plugin Name: Simple Service Marketplace
 * Description: A lightweight Fiverr-style service ordering system.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) exit;

class Pro_Service_Marketplace {

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_action('init', [$this, 'register_order_status']);
        add_shortcode('psm_services', [$this, 'services_shortcode']);
        add_shortcode('psm_dashboard', [$this, 'dashboard_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_psm_order', [$this, 'handle_order']);
        add_action('admin_post_nopriv_psm_order', [$this, 'handle_order']);
    }

    public function assets() {
        wp_enqueue_style('psm-style', plugin_dir_url(__FILE__) . 'style.css');
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

    public function register_order_status() {
        register_post_status('pending', ['label' => 'Pending','public' => true]);
        register_post_status('in_progress', ['label' => 'In Progress','public' => true]);
        register_post_status('completed', ['label' => 'Completed','public' => true]);
    }

    public function services_shortcode() {
        $q = new WP_Query(['post_type'=>'psm_service']);
        ob_start();

        echo '<div class="psm-grid">';
        while($q->have_posts()): $q->the_post();
            $price = get_post_meta(get_the_ID(),'price',true);
            ?>
            <div class="psm-card">
                <h3><?php the_title(); ?></h3>
                <p><?php echo wp_trim_words(get_the_content(),15); ?></p>
                <strong>$<?php echo esc_html($price); ?></strong>
                <a href="?psm_order=<?php the_ID(); ?>">Order</a>
            </div>
            <?php
        endwhile;
        echo '</div>';

        if(isset($_GET['psm_order'])) {
            echo $this->order_form(intval($_GET['psm_order']));
        }

        return ob_get_clean();
    }

    private function order_form($service_id) {
        if(!is_user_logged_in()) return '<p>Please login to order.</p>';

        ob_start(); ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="psm_order">
            <input type="hidden" name="service_id" value="<?php echo $service_id; ?>">

            <textarea name="details" placeholder="Describe your requirements" required></textarea>
            <button type="submit">Place Order</button>
        </form>
        <?php return ob_get_clean();
    }

    public function handle_order() {
        if(!is_user_logged_in()) wp_die('Login required');

        $user_id = get_current_user_id();
        $service_id = intval($_POST['service_id']);
        $details = sanitize_textarea_field($_POST['details']);

        $order_id = wp_insert_post([
            'post_type'=>'psm_order',
            'post_status'=>'pending',
            'post_author'=>$user_id,
            'post_title'=>'Order #'.time()
        ]);

        update_post_meta($order_id,'service_id',$service_id);
        update_post_meta($order_id,'details',$details);

        wp_redirect(home_url('/dashboard'));
        exit;
    }

    public function dashboard_shortcode() {
        if(!is_user_logged_in()) return '<p>Please login.</p>';

        $orders = get_posts([
            'post_type'=>'psm_order',
            'author'=>get_current_user_id()
        ]);

        ob_start();
        echo '<h2>My Orders</h2>';

        foreach($orders as $order) {
            $status = get_post_status($order->ID);
            echo '<div class="psm-order">';
            echo '<strong>'.$order->post_title.'</strong>';
            echo '<p>Status: '.$status.'</p>';
            echo '</div>';
        }

        return ob_get_clean();
    }
}

new Pro_Service_Marketplace();
