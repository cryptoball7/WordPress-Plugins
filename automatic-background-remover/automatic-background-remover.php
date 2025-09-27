<?php
/**
 * Plugin Name: Automatic Image Background Remover
 * Description: Adds a "Remove Background" button to images in the Media Library that uses AI to strip backgrounds.
 * Version: 1.1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Auto_Background_Remover_Button {

    private $api_key_option = 'auto_bg_remover_api_key';

    public function __construct() {
        add_action('admin_menu', [$this, 'settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Add custom button in media modal
        add_filter('attachment_fields_to_edit', [$this, 'add_remove_button'], 10, 2);

        // AJAX handler
        add_action('wp_ajax_remove_bg_from_image', [$this, 'ajax_remove_bg']);
    }

    /**
     * Settings page
     */
    public function settings_page() {
        add_options_page(
            'Background Remover',
            'Background Remover',
            'manage_options',
            'auto-bg-remover',
            [$this, 'settings_page_html']
        );
    }

    public function register_settings() {
        register_setting('auto_bg_remover_options', $this->api_key_option);
        add_settings_section('general_section', 'General Settings', null, 'auto-bg-remover');
        add_settings_field(
            'api_key',
            'API Key',
            [$this, 'api_key_field_html'],
            'auto-bg-remover',
            'general_section'
        );
    }

    public function api_key_field_html() {
        $value = get_option($this->api_key_option, '');
        echo '<input type="text" name="' . esc_attr($this->api_key_option) . '" value="' . esc_attr($value) . '" style="width:300px;">';
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>Automatic Background Remover</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields('auto_bg_remover_options');
                    do_settings_sections('auto-bg-remover');
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Add button to Media Library attachment fields
     */
    public function add_remove_button($form_fields, $post) {
        $form_fields['remove_bg'] = [
            'label' => 'Background Remover',
            'input' => 'html',
            'html'  => '<button type="button" class="button remove-bg-btn" data-attachment-id="' . esc_attr($post->ID) . '">Remove Background</button>
                        <div class="remove-bg-status" id="remove-bg-status-' . esc_attr($post->ID) . '"></div>
                        <script>
                            jQuery(document).ready(function($){
                                $(".remove-bg-btn").off("click").on("click", function(){
                                    var btn = $(this);
                                    var id = btn.data("attachment-id");
                                    var status = $("#remove-bg-status-"+id);
                                    status.text("Processing...");
                                    $.post(ajaxurl, {
                                        action: "remove_bg_from_image",
                                        attachment_id: id,
                                        _wpnonce: "' . wp_create_nonce("remove_bg_nonce") . '"
                                    }, function(response){
                                        if(response.success){
                                            status.text("Background removed successfully! Refresh to see changes.");
                                        } else {
                                            status.text("Error: " + response.data);
                                        }
                                    });
                                });
                            });
                        </script>',
        ];
        return $form_fields;
    }

    /**
     * AJAX: Remove background
     */
    public function ajax_remove_bg() {
        check_ajax_referer('remove_bg_nonce');

        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        $api_key       = get_option($this->api_key_option);

        if ( ! $attachment_id || ! $api_key ) {
            wp_send_json_error('Invalid request or missing API key.');
        }

        $file_path = get_attached_file($attachment_id);
        if ( ! file_exists($file_path) ) {
            wp_send_json_error('File not found.');
        }

        // Call background remover API
        $response = wp_remote_post('https://api.remove.bg/v1.0/removebg', [
            'headers' => [
                'X-Api-Key' => $api_key,
            ],
            'body' => [
                'image_file' => curl_file_create($file_path),
                'size'       => 'auto',
            ],
            'timeout' => 60,
        ]);

        if ( is_wp_error($response) ) {
            wp_send_json_error('API request failed.');
        }

        $body = wp_remote_retrieve_body($response);

        if ( ! $body ) {
            wp_send_json_error('Empty response from API.');
        }

        // Save processed image as a new file
        $pathinfo = pathinfo($file_path);
        $new_file = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-nobg.' . $pathinfo['extension'];

        file_put_contents($new_file, $body);

        // Insert as new attachment in Media Library
        $wp_filetype = wp_check_filetype(basename($new_file), null);
        $attachment = [
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name($pathinfo['filename'] . '-nobg'),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $new_file);

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $new_file);
        wp_update_attachment_metadata($attach_id, $attach_data);

        wp_send_json_success('New image created (no background).');
    }
}

new WP_Auto_Background_Remover_Button();
