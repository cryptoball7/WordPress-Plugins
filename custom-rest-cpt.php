<?php
/**
 * Plugin Name: Custom REST CPT Endpoint
 * Description: Adds a custom post type and REST endpoint with root + fallback logic.
 * Version: 1.0
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

    // Determine target slug
    if ( empty( $slug ) ) {
        $target_slug = 'root';
    } else {
        $target_slug = sanitize_title( $slug );
    }

    // Query for the requested post
    $query = new WP_Query( array(
        'post_type' => 'crce_item',
        'name'      => $target_slug,
        'posts_per_page' => 1,
    ));

    if ( $query->have_posts() ) {
        $query->the_post();
        return crce_format_post( get_post() );
    }

    // Fallback to "not-found" post
    $fallback = new WP_Query( array(
        'post_type' => 'crce_item',
        'name'      => 'not-found',
        'posts_per_page' => 1,
    ));

    if ( $fallback->have_posts() ) {
        $fallback->the_post();
        return crce_format_post( get_post() );
    }

    // Absolute fallback (if even not-found doesn't exist)
    return new WP_REST_Response( array(
        'error' => 'No content found.',
    ), 404 );
}

/**
 * Format Post for API Output
 */
function crce_format_post( $post ) {
    return array(
        'id'      => $post->ID,
        'slug'    => $post->post_name,
        'title'   => get_the_title( $post ),
        'content' => apply_filters( 'the_content', $post->post_content ),
    );
}