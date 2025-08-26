<?php
/**
 * Plugin Name: Custom Login Page Styler
 * Description: Adds a settings page to customize the WordPress login screen (logo, background, and button colors).
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Custom_Login_Styler {
    private $options;

    public function __construct() {
        $this->options = get_option( 'cls_options' );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'login_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    /**
     * Add settings page under Appearance
     */
    public function add_admin_menu() {
        add_theme_page(
            'Login Styler',
            'Login Styler',
            'manage_options',
            'custom-login-styler',
            [ $this, 'settings_page' ]
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'cls_group', 'cls_options', [ $this, 'sanitize' ] );

        add_settings_section('cls_section', 'Customize Login Page', null, 'custom-login-styler');

        add_settings_field('logo', 'Logo', [ $this, 'logo_field' ], 'custom-login-styler', 'cls_section');
        add_settings_field('bg_color', 'Background Color', [ $this, 'bg_color_field' ], 'custom-login-styler', 'cls_section');
        add_settings_field('btn_color', 'Button Color', [ $this, 'btn_color_field' ], 'custom-login-styler', 'cls_section');
    }

    public function sanitize( $input ) {
        $new = [];
        $new['logo'] = sanitize_text_field( $input['logo'] ?? '' );
        $new['bg_color'] = sanitize_hex_color( $input['bg_color'] ?? '' );
        $new['btn_color'] = sanitize_hex_color( $input['btn_color'] ?? '' );
        return $new;
    }

    public function logo_field() {
        $val = $this->options['logo'] ?? '';
        echo '<input type="text" id="logo" name="cls_options[logo]" value="' . esc_attr($val) . '" style="width:60%" />';
        echo '<button class="button cls-upload">Upload</button>';
    }

    public function bg_color_field() {
        $val = $this->options['bg_color'] ?? '';
        echo '<input type="text" class="cls-color-picker" name="cls_options[bg_color]" value="' . esc_attr($val) . '" />';
    }

    public function btn_color_field() {
        $val = $this->options['btn_color'] ?? '';
        echo '<input type="text" class="cls-color-picker" name="cls_options[btn_color]" value="' . esc_attr($val) . '" />';
    }

    /**
     * Output settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Custom Login Styler</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'cls_group' );
                do_settings_sections( 'custom-login-styler' );
                submit_button();
                ?>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($){
            if ($('.cls-color-picker').length) {
                $('.cls-color-picker').wpColorPicker();
            }
            $('.cls-upload').on('click', function(e){
                e.preventDefault();
                var button = $(this);
                var input = $('#logo');
                var custom_uploader = wp.media({
                    title: 'Select Logo',
                    button: { text: 'Use this logo' },
                    multiple: false
                }).on('select', function(){
                    var attachment = custom_uploader.state().get('selection').first().toJSON();
                    input.val(attachment.url);
                }).open();
            });
        });
        </script>
        <?php
    }

    /**
     * Add custom styles to login page
     */
    public function enqueue_styles() {
        $logo = $this->options['logo'] ?? '';
        $bg = $this->options['bg_color'] ?? '#f1f1f1';
        $btn = $this->options['btn_color'] ?? '#2271b1';
        ?>
        <style>
            body.login {
                background-color: <?php echo esc_attr($bg); ?> !important;
            }
            #login h1 a {
                <?php if ($logo): ?>
                    background-image: url('<?php echo esc_url($logo); ?>');
                    background-size: contain;
                    width: 100%;
                    height: 80px;
                <?php endif; ?>
            }
            .wp-core-ui .button-primary {
                background-color: <?php echo esc_attr($btn); ?> !important;
                border-color: <?php echo esc_attr($btn); ?> !important;
            }
        </style>
        <?php
    }
}

new Custom_Login_Styler();
