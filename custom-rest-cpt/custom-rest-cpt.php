<?php
/**
 * Plugin Name: Custom REST CPT Endpoint
 * Description: Adds a custom post type and REST endpoint with root + fallback logic.
 * Version: 1.1
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register Custom Post Type
 */
function crce_register_cpt() {
    register_post_type( 'crce_item', array(
        'label' => 'Items',
        'public' => true,
        'show_in_rest' => true,
        'supports' => array( 'title', 'editor', 'custom-fields' ),
        'has_archive' => true,
    ));
}
add_action( 'init', 'crce_register_cpt' );

/**
 * Create default posts if they don't exist
 */
function crce_ensure_default_posts() {

    $defaults = array(
        'root' => array(
            'title' => 'Root Endpoint',
            'content' => '
                <h2>Welcome to the API</h2>
                <p>This is the default "root" response.</p>
                <p>Use the endpoint with a slug to retrieve specific content:</p>
                <pre>/wp-json/crce/v1/item/{slug}</pre>
                <p>Create new Items in WordPress with matching slugs to expand this API.</p>
            '
        ),
        'not-found' => array(
            'title' => 'Content Not Found',
            'content' => '
                <h2>Not Found</h2>
                <p>The requested resource could not be found.</p>
                <p>Check the slug or create a new Item in the admin.</p>
            '
        )
    );

    foreach ( $defaults as $slug => $data ) {

        $existing = get_page_by_path( $slug, OBJECT, 'crce_item' );

        if ( ! $existing ) {
            wp_insert_post( array(
                'post_title'   => $data['title'],
                'post_name'    => $slug,
                'post_content' => $data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'crce_item',
            ));
        }
    }
}

/**
 * Run on activation
 */
function crce_activate_plugin() {
    crce_register_cpt();
    flush_rewrite_rules();
    crce_ensure_default_posts();
}
register_activation_hook( __FILE__, 'crce_activate_plugin' );

/**
 * Also ensure posts exist during runtime (safety net)
 */
add_action( 'init', 'crce_ensure_default_posts' );

/**
 * Register REST Route
 */
function crce_register_rest_route() {
    register_rest_route( 'crce/v1', '/item(?:/(?P<slug>[a-zA-Z0-9-_]+))?', array(
        'methods'  => 'GET',
        'callback' => 'crce_get_item',
        'permission_callback' => '__return_true',
    ));
}
add_action( 'rest_api_init', 'crce_register_rest_route' );

/**
 * REST Callback
 */
function crce_get_item( $request ) {

    $slug = $request->get_param( 'slug' );

    $target_slug = empty( $slug ) ? 'root' : sanitize_title( $slug );

    $query = new WP_Query( array(
        'post_type' => 'crce_item',
        'name'      => $target_slug,
        'posts_per_page' => 1,
    ));

    if ( $query->have_posts() ) {
        $query->the_post();
        return crce_format_post( get_post() );
    }

    // fallback
    $fallback = new WP_Query( array(
        'post_type' => 'crce_item',
        'name'      => 'not-found',
        'posts_per_page' => 1,
    ));

    if ( $fallback->have_posts() ) {
        $fallback->the_post();
        return crce_format_post( get_post() );
    }

    return new WP_REST_Response( array(
        'error' => 'No content found.',
    ), 404 );
}

/**
 * Format Post for API Output
 */
function crce_format_post( $post ) {

    $raw_content = $post->post_content;

    // Split into paragraphs
    $paragraphs = array_filter( array_map( function( $p ) {
        return trim( wp_strip_all_tags( $p ) );
    }, preg_split( '/\n\s*\n/', $raw_content ) ) );

    return array(
        'id'         => $post->ID,
        'slug'       => $post->post_name,
        'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
        'content'    => implode( "\n\n", $paragraphs ),
        'paragraphs' => array_values( $paragraphs ),
    );
}

/**
 * Output API notice in HTML source
 */
function crce_output_api_notice() {

    if ( is_admin() ) return;

    $base_url = get_rest_url( null, 'crce/v1/item' );

    // Use the custom message (fallback to default)

    $custom_message = get_option( 'crce_api_notice_message', '' );

    if ( empty( $custom_message ) ) {
        $message = "Custom REST API Available\n";
        $message .= "Root endpoint: {$base_url}\n\n";
        $message .= "Usage:\n";
        $message .= "- GET {$base_url}\n";
        $message .= "- GET {$base_url}/{slug}\n";
    } else {
        $message = $custom_message;
    }

    echo "\n<!--\n" . esc_html( $message ) . "\n-->\n";
}
add_action( 'wp_head', 'crce_output_api_notice' );

// Register a setting

function crce_register_settings() {
    register_setting( 'general', 'crce_api_notice_message', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'default' => ''
    ));
}
add_action( 'admin_init', 'crce_register_settings' );

Add a field to General Settings

function crce_add_settings_field() {
    add_settings_field(
        'crce_api_notice_message',
        'API Source Notice',
        'crce_settings_field_html',
        'general'
    );
}
add_action( 'admin_init', 'crce_add_settings_field' );

function crce_settings_field_html() {
    $value = get_option( 'crce_api_notice_message', '' );
    echo '<textarea name="crce_api_notice_message" rows="5" cols="50" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
    echo '<p class="description">Message shown in page source (HTML comment).</p>';
}