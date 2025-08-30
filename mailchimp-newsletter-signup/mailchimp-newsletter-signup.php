<?php
/*
Plugin Name: Mailchimp Newsletter Signup
Plugin URI:  https://example.com/
Description: Simple newsletter signup form connected to a Mailchimp list. Shortcode: [mc_newsletter]
Version:     1.0.0
Author:      Cryptoball cryptoball7@gmail.com
Author URI:  https://github.com/cryptoball7
License:     GPLv3
Text Domain: mc-newsletter
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'MC_Newsletter_Plugin' ) ) :

class MC_Newsletter_Plugin {

    const OPTION_API_KEY  = 'mcns_api_key';
    const OPTION_LIST_ID  = 'mcns_list_id';
    const NONCE_ACTION    = 'mcns_submit';
    const AJAX_ACTION     = 'mcns_submit_action';

    public function __construct() {
        // Admin
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Frontend
        add_shortcode( 'mc_newsletter', array( $this, 'render_form_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
    }

    /* -------------------------
       Admin: settings UI
       ------------------------- */
    public function admin_menu() {
        add_options_page(
            __( 'Mailchimp Newsletter', 'mc-newsletter' ),
            __( 'Mailchimp Newsletter', 'mc-newsletter' ),
            'manage_options',
            'mc-newsletter',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'mcns_options_group', self::OPTION_API_KEY, array( $this, 'sanitize_api_key' ) );
        register_setting( 'mcns_options_group', self::OPTION_LIST_ID, array( 'sanitize_text_field' ) );

        add_settings_section(
            'mcns_main_section',
            __( 'Mailchimp API settings', 'mc-newsletter' ),
            null,
            'mc-newsletter'
        );

        add_settings_field(
            self::OPTION_API_KEY,
            __( 'Mailchimp API Key', 'mc-newsletter' ),
            array( $this, 'field_api_key' ),
            'mc-newsletter',
            'mcns_main_section'
        );

        add_settings_field(
            self::OPTION_LIST_ID,
            __( 'Mailchimp List/Audience ID', 'mc-newsletter' ),
            array( $this, 'field_list_id' ),
            'mc-newsletter',
            'mcns_main_section'
        );
    }

    public function sanitize_api_key( $key ) {
        $key = trim( sanitize_text_field( $key ) );
        // basic check for API key form (contains a dash and dc)
        if ( ! empty( $key ) && strpos( $key, '-' ) === false ) {
            add_settings_error( self::OPTION_API_KEY, 'mcns_api_key_invalid', __( 'Mailchimp API keys usually contain a dash and a data center (e.g. "xxxxxx-us1"). Please check your key.', 'mc-newsletter' ) );
        }
        return $key;
    }

    public function field_api_key() {
        $val = esc_attr( get_option( self::OPTION_API_KEY, '' ) );
        printf(
            '<input type="text" name="%1$s" value="%2$s" style="width:50%%" placeholder="%3$s" />',
            esc_attr( self::OPTION_API_KEY ),
            $val,
            esc_attr__( 'your-mailchimp-api-key-usX', 'mc-newsletter' )
        );
        echo '<p class="description">' . esc_html__( 'Find your API key in Mailchimp > Profile > Extras > API keys. The key contains the data center after a dash, e.g. -us1.', 'mc-newsletter' ) . '</p>';
    }

    public function field_list_id() {
        $val = esc_attr( get_option( self::OPTION_LIST_ID, '' ) );
        printf(
            '<input type="text" name="%1$s" value="%2$s" style="width:50%%" placeholder="%3$s" />',
            esc_attr( self::OPTION_LIST_ID ),
            $val,
            esc_attr__( 'your-list-id', 'mc-newsletter' )
        );
        echo '<p class="description">' . esc_html__( 'You can find the List (Audience) ID in Mailchimp under Audience -> Settings -> Audience name and defaults.', 'mc-newsletter' ) . '</p>';
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Mailchimp Newsletter Signup', 'mc-newsletter' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'mcns_options_group' );
                do_settings_sections( 'mc-newsletter' );
                submit_button();
                ?>
            </form>

            <h2><?php esc_html_e( 'Usage', 'mc-newsletter' ); ?></h2>
            <p><?php esc_html_e( 'Place the following shortcode in a post or page to show the signup form:', 'mc-newsletter' ); ?></p>
            <code>[mc_newsletter]</code>

            <h2><?php esc_html_e( 'Behavior', 'mc-newsletter' ); ?></h2>
            <p><?php esc_html_e( 'The form uses AJAX and will attempt to subscribe the user to your Mailchimp audience. Errors from Mailchimp (invalid email, already subscribed, etc.) are shown to the user.', 'mc-newsletter' ); ?></p>
        </div>
        <?php
    }

    /* -------------------------
       Frontend form + assets
       ------------------------- */
    public function enqueue_assets() {
        // Only load scripts if shortcode present on page would be better,
        // but to keep it simple we always enqueue - small footprint.
        wp_enqueue_script( 'mcns-main', plugin_dir_url( __FILE__ ) . 'mcns-main.js', array( 'jquery' ), '1.0.0', true );

        $ajax_params = array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'action'   => self::AJAX_ACTION,
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
        );
        wp_localize_script( 'mcns-main', 'mcns_ajax', $ajax_params );

        wp_enqueue_style( 'mcns-style', plugin_dir_url( __FILE__ ) . 'mcns-style.css', array(), '1.0.0' );
    }

    public function render_form_shortcode( $atts = array() ) {
        $atts = shortcode_atts( array(
            'show_names' => '1',
            'button_text' => __( 'Subscribe', 'mc-newsletter' ),
        ), $atts, 'mc_newsletter' );

        ob_start();
        ?>
        <form id="mcns-form" class="mcns-form" method="post" novalidate>
            <div id="mcns-messages" role="status" aria-live="polite"></div>

            <?php if ( '1' === $atts['show_names'] ) : ?>
            <p>
                <label for="mcns_fname"><?php esc_html_e( 'First name', 'mc-newsletter' ); ?></label><br/>
                <input type="text" id="mcns_fname" name="fname" />
            </p>
            <p>
                <label for="mcns_lname"><?php esc_html_e( 'Last name', 'mc-newsletter' ); ?></label><br/>
                <input type="text" id="mcns_lname" name="lname" />
            </p>
            <?php endif; ?>

            <p>
                <label for="mcns_email"><?php esc_html_e( 'Email address', 'mc-newsletter' ); ?> *</label><br/>
                <input type="email" id="mcns_email" name="email" required />
            </p>

            <p>
                <button type="submit" id="mcns-submit"><?php echo esc_html( $atts['button_text'] ); ?></button>
            </p>

            <!-- hidden inputs -->
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>" />
        </form>
        <?php
        return ob_get_clean();
    }

    /* -------------------------
       AJAX handler
       ------------------------- */
    public function handle_ajax() {
        // Allow only POST
        if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request method', 'mc-newsletter' ) ), 405 );
        }

        // Check nonce
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_send_json_error( array( 'message' => __( 'Security validation failed (invalid nonce).', 'mc-newsletter' ) ), 403 );
        }

        // Input: email required
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $fname = isset( $_POST['fname'] ) ? sanitize_text_field( wp_unslash( $_POST['fname'] ) ) : '';
        $lname = isset( $_POST['lname'] ) ? sanitize_text_field( wp_unslash( $_POST['lname'] ) ) : '';

        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'mc-newsletter' ) ), 400 );
        }

        // Get API credentials
        $api_key = trim( get_option( self::OPTION_API_KEY, '' ) );
        $list_id = trim( get_option( self::OPTION_LIST_ID, '' ) );

        if ( empty( $api_key ) || empty( $list_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Mailchimp API key or List ID is not configured. Please set them in WP admin > Settings > Mailchimp Newsletter.', 'mc-newsletter' ) ), 500 );
        }

        // Parse data center from API key (after the dash)
        $dc = $this->extract_datacenter( $api_key );
        if ( ! $dc ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Mailchimp API key format. Expecting data center (e.g. -us1) after the key.', 'mc-newsletter' ) ), 500 );
        }

        $url = sprintf( 'https://%s.api.mailchimp.com/3.0/lists/%s/members/', $dc, rawurlencode( $list_id ) );

        // Mailchimp expects subscriber hash (sometimes used for PUT to update), but POST to members creates new.
        $body = array(
            'email_address' => $email,
            // 'status' => 'subscribed' // if you have double opt-in enabled, use 'pending' instead.
            'status' => 'pending', // safer default (double opt-in). Change to 'subscribed' if you want direct subscribe.
            'merge_fields' => array(
                'FNAME' => $fname,
                'LNAME' => $lname,
            ),
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( 'user:' . $api_key ),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        );

        // Do the request
        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => __( 'Request failed: ', 'mc-newsletter' ) . $response->get_error_message() ), 500 );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $resp_body = wp_remote_retrieve_body( $response );

        $decoded = json_decode( $resp_body, true );

        // Success: Mailchimp returns 200 or 201 typically
        if ( $http_code >= 200 && $http_code < 300 ) {
            $msg = isset( $decoded['status'] ) && 'subscribed' === $decoded['status']
                ? __( 'Thanks! You are subscribed.', 'mc-newsletter' )
                : __( 'Thanks! Please check your email to confirm subscription (double opt-in).', 'mc-newsletter' );

            wp_send_json_success( array( 'message' => $msg ) );
        }

        // Mailchimp returns error JSON with 'title' and 'detail'
        if ( isset( $decoded['title'] ) && isset( $decoded['detail'] ) ) {
            $title  = sanitize_text_field( $decoded['title'] );
            $detail = sanitize_text_field( $decoded['detail'] );

            // Common: "Member Exists" - already subscribed
            if ( strpos( strtolower( $title ), 'member exists' ) !== false || strpos( strtolower( $detail ), 'is already a list member' ) !== false ) {
                wp_send_json_error( array( 'message' => __( 'This email is already subscribed to the list.', 'mc-newsletter' ) ), 409 );
            }

            wp_send_json_error( array( 'message' => $detail ), $http_code ? $http_code : 400 );
        }

        // fallback
        wp_send_json_error( array( 'message' => __( 'An unknown error occurred while contacting Mailchimp.', 'mc-newsletter' ) ), $http_code ? $http_code : 400 );
    }

    private function extract_datacenter( $api_key ) {
        // API key is usually like: xxxxxxx-us1
        if ( false === strpos( $api_key, '-' ) ) {
            return false;
        }
        $parts = explode( '-', $api_key );
        return array_pop( $parts );
    }
}

new MC_Newsletter_Plugin();

