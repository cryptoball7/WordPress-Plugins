<?php
/**
 * Plugin Name: Front-End Like Button (AJAX, Meta, Nonces)
 * Description: Adds a lightweight front-end "Like" button to posts without requiring login. Uses AJAX, custom post meta, and nonces.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 * Text Domain: fe-like
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class FELikeButtonPlugin {
    const META_KEY = 'fe_like_count';
    const NONCE_ACTION = 'fe_like_nonce';
    const COOKIE_PREFIX = 'fe_liked_'; // fe_liked_{POSTID}
    const TRANSIENT_PREFIX = 'fe_like_'; // fe_like_{POSTID}_{IPHASH}

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_fe_like', [ $this, 'handle_like' ] );
        add_action( 'wp_ajax_nopriv_fe_like', [ $this, 'handle_like' ] );
        add_filter( 'the_content', [ $this, 'append_button_to_content' ] );
        add_shortcode( 'fe_like_button', [ $this, 'render_button_shortcode' ] );
        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
    }

    public static function activate() {
        // Nothing required at activation, but ensure meta exists for existing posts could be added here if needed.
    }

    public function enqueue_assets() {
        if ( ! is_singular( 'post' ) && ! is_page() ) { return; } // keep it light; still works where shortcode is used on pages

        $handle = 'fe-like-js';
        $src = plugins_url( 'assets/js/like.js', __FILE__ );
        wp_enqueue_script( $handle, $src, [ 'jquery' ], '1.0.0', true );

        $post_id = get_the_ID();
        $count   = $post_id ? (int) get_post_meta( $post_id, self::META_KEY, true ) : 0;

        $has_cookie = false;
        if ( $post_id && isset( $_COOKIE[ self::COOKIE_PREFIX . $post_id ] ) ) {
            $has_cookie = true;
        }

        wp_localize_script( $handle, 'FELikeData', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
            'post_id'    => $post_id,
            'count'      => $count,
            'has_liked'  => $has_cookie ? true : false,
            'texts'      => [
                'like'   => __( 'Like', 'fe-like' ),
                'liked'  => __( 'Liked', 'fe-like' ),
            ],
        ]);

        // basic styles
        wp_enqueue_style( 'fe-like-css', plugins_url( 'assets/css/like.css', __FILE__ ), [], '1.0.0' );
    }

    public function append_button_to_content( $content ) {
        if ( is_singular( 'post' ) && in_the_loop() && is_main_query() ) {
            $content .= $this->render_button( get_the_ID() );
        }
        return $content;
    }

    public function render_button_shortcode( $atts = [] ) {
        $post_id = get_the_ID();
        return $this->render_button( $post_id );
    }

    private function render_button( $post_id ) {
        if ( ! $post_id ) { return ''; }
        $count = (int) get_post_meta( $post_id, self::META_KEY, true );
        $liked_attr = isset( $_COOKIE[ self::COOKIE_PREFIX . $post_id ] ) ? ' data-liked="1"' : '';
        $label = isset( $_COOKIE[ self::COOKIE_PREFIX . $post_id ] ) ? esc_html__( 'Liked', 'fe-like' ) : esc_html__( 'Like', 'fe-like' );

        ob_start();
        ?>
        <div class="fe-like-wrap" data-post-id="<?php echo esc_attr( $post_id ); ?>"<?php echo $liked_attr; ?>>
            <button type="button" class="fe-like-btn" aria-pressed="<?php echo isset($_COOKIE[self::COOKIE_PREFIX.$post_id]) ? 'true':'false'; ?>">
                <span class="fe-like-label"><?php echo $label; ?></span>
                <span class="fe-like-count"><?php echo esc_html( $count ); ?></span>
            </button>
            <span class="fe-like-feedback" aria-live="polite"></span>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_like() {
        if ( ! wp_doing_ajax() ) { wp_send_json_error( [ 'message' => 'Invalid request' ], 400 ); }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_send_json_error( [ 'message' => 'Bad nonce' ], 403 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid post' ], 400 );
        }

        // Simple abuse-throttle: block repeated likes from same IP for this post
        $ip = $this->get_user_ip();
        $iphash = $ip ? wp_hash( $ip ) : 'anon';
        $tkey = self::TRANSIENT_PREFIX . $post_id . '_' . $iphash;

        if ( get_transient( $tkey ) ) {
            wp_send_json_error( [ 'message' => 'already_liked' ], 409 );
        }

        // Prevent double increment if cookie is present
        if ( isset( $_COOKIE[ self::COOKIE_PREFIX . $post_id ] ) ) {
            wp_send_json_error( [ 'message' => 'already_liked' ], 409 );
        }

        $count = (int) get_post_meta( $post_id, self::META_KEY, true );
        $count++;
        update_post_meta( $post_id, self::META_KEY, $count );

        // Set cookie for one year
        $cookie_name = self::COOKIE_PREFIX . $post_id;
        $secure = is_ssl();
        setcookie( $cookie_name, '1', time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, true );

        // Set transient to stop immediate repeats from the same IP for ~1 year
        set_transient( $tkey, 1, YEAR_IN_SECONDS );

        wp_send_json_success( [ 'count' => $count ] );
    }

    private function get_user_ip() {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $_SERVER ) ) {
                foreach ( explode( ',', $_SERVER[ $key ] ) as $ip ) {
                    $ip = trim( $ip );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        return $ip;
                    }
                }
            }
        }
        return '';
    }
}

new FELikeButtonPlugin();
