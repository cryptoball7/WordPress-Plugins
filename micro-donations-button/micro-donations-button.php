<?php
/*
Plugin Name: Micro Donations Button
Description: Adds a simple micro-donation ($1 tip) button to blog posts.
Version: 1.0.0
Author: Cryptoball cryptoball7@gmail.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Enqueue CSS
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('micro-donations-button', plugin_dir_url(__FILE__) . 'assets/button.css');
});

// Add donation button after post content
add_filter('the_content', function ($content) {
    if (is_single() && is_main_query()) {
        $donation_link = esc_url(get_option('micro_donation_link', '#'));
        $button = '
        <div class="micro-donation-container">
            <a href="' . $donation_link . '" target="_blank" class="micro-donation-btn">
                ☕ Support with $1
            </a>
        </div>';
        return $content . $button;
    }
    return $content;
});

// Add settings page
add_action('admin_menu', function () {
    add_options_page(
        'Micro Donations Settings',
        'Micro Donations',
        'manage_options',
        'micro-donations',
        'micro_donations_settings_page'
    );
});

// Register donation link setting
add_action('admin_init', function () {
    register_setting('micro_donations_settings', 'micro_donation_link');
});

function micro_donations_settings_page() {
    ?>
    <div class="wrap">
        <h1>Micro Donations Button Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('micro_donations_settings'); ?>
            <?php do_settings_sections('micro_donations_settings'); ?>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Donation Link</th>
                    <td>
                        <input type="text" name="micro_donation_link" value="<?php echo esc_attr(get_option('micro_donation_link', 'https://www.paypal.me/yourusername/1')); ?>" size="50" />
                        <p class="description">Enter your PayPal/Stripe/BuyMeACoffee donation link.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
