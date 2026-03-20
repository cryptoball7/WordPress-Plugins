<?php
/**
 * Plugin Name: Static Page Generator
 * Description: Automatically generates a static HTML version of any page, post, or custom content type when created or updated.
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class Static_Page_Generator {

    private $output_dir;

    public function __construct() {
        // Set the directory to save static files (inside uploads folder by default)
        $upload_dir = wp_upload_dir();
        $this->output_dir = $upload_dir['basedir'] . '/static-pages/';

        // Ensure the directory exists
        if (!file_exists($this->output_dir)) {
            wp_mkdir_p($this->output_dir);
        }

        // Hook into post save
        add_action('save_post', [$this, 'generate_static_html'], 10, 3);
    }

    public function generate_static_html($post_ID, $post, $update) {

        // Ignore auto-saves and revisions
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
            return;
        }

        // Only public post types
        if ($post->post_status !== 'publish') return;

        // Get the URL of the post
        $permalink = get_permalink($post_ID);

        // Use WordPress HTTP API to fetch the rendered page
        $response = wp_remote_get($permalink);

        if (is_wp_error($response)) return;

        $html = wp_remote_retrieve_body($response);

        // Sanitize post slug for filename
        $slug = $post->post_name;
        if (empty($slug)) $slug = $post_ID;

        $file_path = $this->output_dir . $slug . '.html';

        // Save HTML to file
        file_put_contents($file_path, $html);
    }
}

new Static_Page_Generator();