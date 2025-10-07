<?php
/**
 * Plugin Name: AI Image Captioner
 * Description: Auto-generates engaging captions for uploaded images using any OpenAI-compatible AI endpoint. Adds settings to configure API credentials and model. Generates captions on upload and provides a manual regenerate action in the Media Library.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: ai-image-captioner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class AI_Image_Captioner {
    const OPTION_KEY = 'aic_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // When a new attachment is added
        add_action( 'add_attachment', array( $this, 'maybe_generate_caption_on_upload' ) );

        // Add a Media row action (Generate caption)
        add_filter( 'media_row_actions', array( $this, 'media_row_actions' ), 10, 2 );
        add_action( 'admin_post_aic_regen_caption', array( $this, 'handle_regen_request' ) );

        // Add a column with last-generation status (optional)
        add_filter( 'manage_media_columns', array( $this, 'manage_media_columns' ) );
        add_action( 'manage_media_custom_column', array( $this, 'manage_media_custom_column' ), 10, 2 );
    }

    public function register_settings_page() {
        add_options_page(
            'AI Image Captioner',
            'AI Image Captioner',
            'manage_options',
            'ai-image-captioner',
            array( $this, 'settings_page_html' )
        );
    }

    public function register_settings() {
        register_setting( 'aic_options', self::OPTION_KEY, array( $this, 'validate_options' ) );

        add_settings_section( 'aic_main', 'API Settings', null, 'ai-image-captioner' );

        add_settings_field( 'api_url', 'API Base URL', array( $this, 'field_api_url' ), 'ai-image-captioner', 'aic_main' );
        add_settings_field( 'api_key', 'API Key (Bearer)', array( $this, 'field_api_key' ), 'ai-image-captioner', 'aic_main' );
        add_settings_field( 'model', 'Model', array( $this, 'field_model' ), 'ai-image-captioner', 'aic_main' );
        add_settings_field( 'auto_generate', 'Auto-generate on upload', array( $this, 'field_auto_generate' ), 'ai-image-captioner', 'aic_main' );
        add_settings_field( 'prompt_template', 'Prompt Template', array( $this, 'field_prompt_template' ), 'ai-image-captioner', 'aic_main' );
    }

    public function validate_options( $input ) {
        $out = array();
        $out['api_url'] = isset( $input['api_url'] ) ? esc_url_raw( trim( $input['api_url'] ) ) : '';
        $out['api_key'] = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '';
        $out['model'] = isset( $input['model'] ) ? sanitize_text_field( trim( $input['model'] ) ) : 'gpt-4o-mini';
        $out['auto_generate'] = isset( $input['auto_generate'] ) ? 1 : 0;
        $out['prompt_template'] = isset( $input['prompt_template'] ) ? sanitize_textarea_field( $input['prompt_template'] ) : 'Write a short, engaging caption for this image: {image_url}';
        return $out;
    }

    /* ===== settings fields ===== */
    public function field_api_url() {
        $opts = $this->get_opts();
        printf('<input type="text" name="%s[api_url]" value="%s" style="width:60%%" placeholder="https://api.openai.com/v1/...">', self::OPTION_KEY, esc_attr( $opts['api_url'] ?? '' ) );
        echo '<p class="description">Base endpoint for your AI provider (must accept requests described in plugin docs). Example: https://api.openai.com/v1/completions or your proxy endpoint.</p>';
    }
    public function field_api_key() {
        $opts = $this->get_opts();
        printf('<input type="password" name="%s[api_key]" value="%s" style="width:40%%">', self::OPTION_KEY, esc_attr( $opts['api_key'] ?? '' ) );
        echo '<p class="description">Your API key. Stored in options (not encrypted). For increased security consider using a server-side proxy or environment storage.</p>';
    }
    public function field_model() {
        $opts = $this->get_opts();
        printf('<input type="text" name="%s[model]" value="%s" style="width:25%%">', self::OPTION_KEY, esc_attr( $opts['model'] ?? 'gpt-4o-mini' ) );
        echo '<p class="description">Model identifier (optional depending on provider).</p>';
    }
    public function field_auto_generate() {
        $opts = $this->get_opts();
        $checked = ! empty( $opts['auto_generate'] ) ? 'checked' : '';
        printf('<input type="checkbox" name="%s[auto_generate]" %s> Enable', self::OPTION_KEY, $checked );
        echo '<p class="description">If enabled, captions are generated automatically when images are uploaded.</p>';
    }
    public function field_prompt_template() {
        $opts = $this->get_opts();
        $val = esc_textarea( $opts['prompt_template'] ?? 'Write a short, engaging caption for this image: {image_url}' );
        printf('<textarea name="%s[prompt_template]" rows="4" style="width:80%%">%s</textarea>', self::OPTION_KEY, $val );
        echo '<p class="description">You can use {image_url} and {filename} placeholders in the prompt. Keep it concise.</p>';
    }

    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>AI Image Captioner — Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'aic_options' );
                do_settings_sections( 'ai-image-captioner' );
                submit_button();
                ?>
            </form>
            <h2>How it works</h2>
            <ol>
                <li>Configure your API base URL and API key. The plugin sends a JSON POST with model/prompt/image_url to that endpoint.</li>
                <li>Upload an image or use the "Generate caption" action in Media Library.</li>
                <li>The generated caption is written to the attachment's <code>Caption</code> (post_excerpt).</li>
            </ol>
            <p>Notes: the plugin does not store images externally. If your endpoint cannot fetch private media URLs, you can configure a proxy that accepts base64 image contents (modify <code>call_ai_caption_api()</code> accordingly).</p>
        </div>
        <?php
    }

    private function get_opts() {
        $opts = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opts ) ) $opts = array();
        return $opts;
    }

    /* ===== core caption flow ===== */
    public function maybe_generate_caption_on_upload( $post_id ) {
        $opts = $this->get_opts();
        if ( empty( $opts['auto_generate'] ) ) return; // feature disabled

        $mime = get_post_mime_type( $post_id );
        if ( strpos( $mime, 'image/' ) !== 0 ) return; // not an image

        // Ensure file is available and public
        $image_url = wp_get_attachment_url( $post_id );
        if ( ! $image_url ) return;

        $this->generate_and_save_caption( $post_id, $image_url );
    }

    public function generate_and_save_caption( $attachment_id, $image_url ) {
        $opts = $this->get_opts();
        if ( empty( $opts['api_url'] ) || empty( $opts['api_key'] ) ) {
            $this->log_error( $attachment_id, 'Missing API settings' );
            return false;
        }

        $filename = basename( parse_url( $image_url, PHP_URL_PATH ) );

        $prompt = str_replace( array( '{image_url}', '{filename}' ), array( $image_url, $filename ), ( $opts['prompt_template'] ?? '' ) );

        $resp = $this->call_ai_caption_api( $opts, $prompt, $image_url );

        if ( is_wp_error( $resp ) ) {
            $this->log_error( $attachment_id, $resp->get_error_message() );
            return false;
        }

        $caption = $this->extract_caption_from_response( $resp );

        if ( ! $caption ) {
            $this->log_error( $attachment_id, 'No caption returned by API' );
            return false;
        }

        // Save caption to post_excerpt (Caption field)
        $update = wp_update_post( array(
            'ID' => $attachment_id,
            'post_excerpt' => wp_strip_all_tags( mb_substr( $caption, 0, 300 ) ),
        ), true );

        if ( is_wp_error( $update ) ) {
            $this->log_error( $attachment_id, 'WP update error: ' . $update->get_error_message() );
            return false;
        }

        // Save last-generated meta
        update_post_meta( $attachment_id, '_aic_last_caption', $caption );
        update_post_meta( $attachment_id, '_aic_last_generated', current_time( 'mysql' ) );

        return true;
    }

    private function call_ai_caption_api( $opts, $prompt, $image_url ) {
        $body = array(
            'model' => $opts['model'] ?? '',
            'prompt' => $prompt,
            'image_url' => $image_url,
            'max_tokens' => 60,
            'temperature' => 0.7,
        );

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $opts['api_key'],
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
            'timeout' => 20,
        );

        $response = wp_remote_post( $opts['api_url'], $args );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'aic_api_error', "API returned HTTP $code: $body" );
        }

        $decoded = json_decode( $body, true );
        if ( null === $decoded ) {
            return new WP_Error( 'aic_api_error', 'Unable to decode API response: ' . wp_trim_words( $body, 40 ) );
        }

        return $decoded;
    }

    private function extract_caption_from_response( $resp ) {
        // Be permissive: many AI endpoints use different response shapes
        // Common: {choices:[{text: '...'}]} or {output: '...'} or {caption: '...'} or {data:[{caption:'...'}]}
        if ( isset( $resp['choices'] ) && is_array( $resp['choices'] ) ) {
            $first = reset( $resp['choices'] );
            if ( is_array( $first ) ) {
                if ( ! empty( $first['text'] ) ) return trim( $first['text'] );
                if ( ! empty( $first['message']['content'] ) ) return trim( $first['message']['content'] );
            }
        }
        if ( ! empty( $resp['caption'] ) ) return trim( $resp['caption'] );
        if ( ! empty( $resp['output'] ) ) return trim( is_array( $resp['output'] ) ? ( is_string( $resp['output'][0] ) ? $resp['output'][0] : json_encode( $resp['output'] ) ) : $resp['output'] );
        if ( ! empty( $resp['data'] ) && is_array( $resp['data'] ) ) {
            $first = reset( $resp['data'] );
            if ( is_array( $first ) && ! empty( $first['caption'] ) ) return trim( $first['caption'] );
            if ( is_array( $first ) && ! empty( $first['text'] ) ) return trim( $first['text'] );
        }
        // Try nested openai v1 chat-like shape
        if ( isset( $resp['choices'][0]['message']['content']['parts'][0] ) ) {
            return trim( $resp['choices'][0]['message']['content']['parts'][0] );
        }
        return false;
    }

    private function log_error( $attachment_id, $message ) {
        update_post_meta( $attachment_id, '_aic_last_error', $message );
        error_log( "AI Image Captioner: attachment $attachment_id - $message" );
    }

    /* ===== media row actions ===== */
    public function media_row_actions( $actions, $post ) {
        if ( strpos( get_post_mime_type( $post ), 'image/' ) === 0 ) {
            $url = wp_nonce_url( admin_url( 'admin-post.php?action=aic_regen_caption&attachment_id=' . $post->ID ), 'aic_regen' );
            $actions['aic_regen_caption'] = '<a href="' . esc_url( $url ) . '">Generate caption</a>';
        }
        return $actions;
    }

    public function handle_regen_request() {
        if ( ! current_user_can( 'upload_files' ) ) wp_die( 'Permission denied' );
        $att = intval( $_GET['attachment_id'] ?? 0 );
        if ( ! $att ) wp_redirect( wp_get_referer() ?: admin_url( 'upload.php' ) );

        check_admin_referer( 'aic_regen' );

        $url = wp_get_attachment_url( $att );
        if ( ! $url ) {
            wp_redirect( add_query_arg( 'aic_msg', 'no_image', wp_get_referer() ?: admin_url( 'upload.php' ) ) );
            exit;
        }

        $success = $this->generate_and_save_caption( $att, $url );

        $redirect = wp_get_referer() ?: admin_url( 'upload.php' );
        $redirect = add_query_arg( 'aic_msg', $success ? 'ok' : 'error', $redirect );
        wp_redirect( $redirect );
        exit;
    }

    /* ===== admin columns ===== */
    public function manage_media_columns( $cols ) {
        $cols['aic_status'] = 'AI Caption';
        return $cols;
    }

    public function manage_media_custom_column( $column_name, $post_id ) {
        if ( 'aic_status' !== $column_name ) return;
        $caption = get_post_meta( $post_id, '_aic_last_caption', true );
        $err = get_post_meta( $post_id, '_aic_last_error', true );
        if ( $caption ) {
            echo esc_html( wp_trim_words( $caption, 10 ) );
        } elseif ( $err ) {
            echo '<span style="color:#a00">Error: ' . esc_html( $err ) . '</span>';
        } else {
            echo '<em>—</em>';
        }
    }
}

new AI_Image_Captioner();

/* End of plugin */
