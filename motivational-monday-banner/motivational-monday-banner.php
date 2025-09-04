<?php
/**
 * Plugin Name: Motivational Monday Banner
 * Description: Displays a motivational banner site-wide every Monday.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 * Text Domain: motivational-monday
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class MotivationalMondayBanner {

    private $messages = array(
        "Believe in yourself and all that you are.",
        "Every Monday is a chance to start fresh.",
        "Your only limit is your mind.",
        "Small steps lead to big changes.",
        "Dream it. Wish it. Do it.",
        "Start where you are. Use what you have. Do what you can.",
    );

    public function __construct() {
        add_action( 'wp_footer', array( $this, 'render_banner' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    public function is_monday() {
        return ( date( 'N' ) == 1 ); // 1 = Monday
    }

    public function get_message() {
        return $this->messages[ array_rand( $this->messages ) ];
    }

    public function render_banner() {
        if ( ! $this->is_monday() ) {
            return;
        }
        $message = esc_html( $this->get_message() );
        ?>
        <div class="motivational-monday-banner">
            <p><?php echo $message; ?></p>
        </div>
        <?php
    }

    public function enqueue_styles() {
        wp_add_inline_style(
            'wp-block-library',
            '.motivational-monday-banner {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: #0073aa;
                color: #fff;
                text-align: center;
                padding: 15px;
                font-size: 18px;
                font-weight: bold;
                z-index: 9999;
                box-shadow: 0 -2px 8px rgba(0,0,0,0.2);
            }'
        );
    }
}

new MotivationalMondayBanner();
