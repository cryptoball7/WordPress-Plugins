<?php
/**
 * Plugin Name: AI-Powered A/B Testing
 * Description: Automatically tests variations of titles, CTAs, and small layout snippets. Generates variants via AI (OpenAI compatible), randomizes exposure, tracks impressions & conversions, and auto-tunes traffic allocation.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: ai-ab-testing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIAB_FILE', __FILE__ );
define( 'AIAB_DIR', plugin_dir_path( AIAB_FILE ) );
define( 'AIAB_URL', plugin_dir_url( AIAB_FILE ) );

register_activation_hook( __FILE__, 'aiab_activate' );
register_uninstall_hook( __FILE__, 'aiab_uninstall' );

function aiab_activate() {
    // Nothing heavy for now; you could create DB tables here if desired.
}

function aiab_uninstall() {
    // Optional: clean up options / post types. Keep conservative: only remove plugin options
    delete_option( 'aiab_settings' );
}

/**
 * Register custom post type for experiments
 */
add_action( 'init', 'aiab_register_post_type' );
function aiab_register_post_type() {
    $labels = array(
        'name' => __( 'A/B Experiments', 'ai-ab-testing' ),
        'singular_name' => __( 'A/B Experiment', 'ai-ab-testing' ),
    );
    $args = array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // we'll add a custom menu
        'supports' => array( 'title' ),
        'capability_type' => 'post'
    );
    register_post_type( 'aiab_experiment', $args );
}

/**
 * Admin menu and pages
 */
add_action( 'admin_menu', 'aiab_admin_menu' );
function aiab_admin_menu() {
    add_menu_page(
        __( 'AI A/B Testing', 'ai-ab-testing' ),
        __( 'AI A/B Testing', 'ai-ab-testing' ),
        'manage_options',
        'aiab_main',
        'aiab_admin_page',
        'dashicons-chart-bar',
        58
    );

    add_submenu_page( 'aiab_main', __( 'Experiments', 'ai-ab-testing' ), __( 'Experiments', 'ai-ab-testing' ), 'manage_options', 'edit.php?post_type=aiab_experiment' );
    add_submenu_page( 'aiab_main', __( 'Settings', 'ai-ab-testing' ), __( 'Settings', 'ai-ab-testing' ), 'manage_options', 'aiab_settings', 'aiab_settings_page' );
}

/**
 * Enqueue admin assets
 */
add_action( 'admin_enqueue_scripts', 'aiab_admin_assets' );
function aiab_admin_assets( $hook ) {
    // Only on our plugin pages
    if ( strpos( $hook, 'aiab' ) !== false || get_post_type() === 'aiab_experiment' ) {
        wp_enqueue_script( 'aiab-admin-js', AIAB_URL . 'assets/admin.js', array( 'jquery' ), '1.0', true );
        wp_localize_script( 'aiab-admin-js', 'aiab_admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'aiab_admin_nonce' ),
        ) );
        wp_enqueue_style( 'aiab-admin-css', AIAB_URL . 'assets/admin.css', array(), '1.0' );
    }
}

/**
 * Enqueue frontend assets
 */
add_action( 'wp_enqueue_scripts', 'aiab_frontend_assets' );
function aiab_frontend_assets() {
    wp_enqueue_script( 'aiab-frontend-js', AIAB_URL . 'assets/frontend.js', array( 'jquery' ), '1.0', true );
    wp_localize_script( 'aiab-frontend-js', 'aiab_frontend', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'aiab_frontend_nonce' ),
    ) );
}

/**
 * Admin main page callback
 */
function aiab_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__( 'AI-Powered A/B Testing', 'ai-ab-testing' ) . '</h1>';
    echo '<p>' . esc_html__( 'Create experiments, auto-generate variants using AI, and view stats.', 'ai-ab-testing' ) . '</p>';
    echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=aiab_experiment' ) ) . '">' . esc_html__( 'Create New Experiment', 'ai-ab-testing' ) . '</a></p>';
    echo '<h2>' . esc_html__( 'Quick actions', 'ai-ab-testing' ) . '</h2>';
    echo '<p>' . esc_html__( 'You can also go to the Experiments list to edit an experiment. Each experiment stores variants in post meta (aiab_variants JSON).' , 'ai-ab-testing' ) . '</p>';
    echo '</div>';
}

/**
 * Settings page
 */
function aiab_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $options = get_option( 'aiab_settings', array() );
    $api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'AI A/B Testing Settings', 'ai-ab-testing' ); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field( 'aiab_save_settings', 'aiab_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="aiab_api_key"><?php esc_html_e( 'AI API Key', 'ai-ab-testing' ); ?></label></th>
                    <td>
                        <input name="aiab_api_key" type="password" id="aiab_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Paste an OpenAI-compatible API key here. This key is stored in WP options (consider secure storage).', 'ai-ab-testing' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php

    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer( 'aiab_save_settings', 'aiab_settings_nonce' ) ) {
        $new_key = isset( $_POST['aiab_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['aiab_api_key'] ) ) : '';
        $options = get_option( 'aiab_settings', array() );
        $options['api_key'] = $new_key;
        update_option( 'aiab_settings', $options );
        echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'ai-ab-testing' ) . '</p></div>';
    }
}

/**
 * Save experiment meta when saving the experiment post type
 */
add_action( 'save_post_aiab_experiment', 'aiab_save_experiment_meta', 10, 3 );
function aiab_save_experiment_meta( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Expecting fields: aiab_selector, aiab_variants (JSON), aiab_goal
    if ( isset( $_POST['aiab_selector'] ) ) {
        update_post_meta( $post_id, 'aiab_selector', sanitize_text_field( wp_unslash( $_POST['aiab_selector'] ) ) );
    }
    if ( isset( $_POST['aiab_goal'] ) ) {
        update_post_meta( $post_id, 'aiab_goal', sanitize_text_field( wp_unslash( $_POST['aiab_goal'] ) ) );
    }
    if ( isset( $_POST['aiab_variants'] ) ) {
        // Expect JSON — sanitize minimally and store
        $variants_raw = wp_unslash( $_POST['aiab_variants'] );
        $variants = json_decode( $variants_raw, true );
        if ( is_array( $variants ) ) {
            // Reset metrics when variants are changed
            foreach ( $variants as $idx => $v ) {
                $variants[ $idx ]['impressions'] = isset( $v['impressions'] ) ? intval( $v['impressions'] ) : 0;
                $variants[ $idx ]['conversions'] = isset( $v['conversions'] ) ? intval( $v['conversions'] ) : 0;
                $variants[ $idx ]['weight'] = isset( $v['weight'] ) ? floatval( $v['weight'] ) : (1/ count($variants));
            }
            update_post_meta( $post_id, 'aiab_variants', wp_json_encode( $variants ) );
        }
    }
}

/**
 * Add meta box to experiments edit screen
 */
add_action( 'add_meta_boxes', 'aiab_add_meta_boxes' );
function aiab_add_meta_boxes() {
    add_meta_box( 'aiab_experiment_meta', __( 'Experiment Settings', 'ai-ab-testing' ), 'aiab_experiment_meta_cb', 'aiab_experiment', 'normal', 'high' );
}

function aiab_experiment_meta_cb( $post ) {
    $selector = get_post_meta( $post->ID, 'aiab_selector', true );
    $goal = get_post_meta( $post->ID, 'aiab_goal', true );
    $variants_json = get_post_meta( $post->ID, 'aiab_variants', true );
    $variants = array();
    if ( $variants_json ) {
        $variants = json_decode( $variants_json, true );
    }
    if ( ! is_array( $variants ) || empty( $variants ) ) {
        // default one control variant
        $variants = array(
            array( 'name' => 'control', 'type' => 'title', 'content' => '{{current}}', 'impressions' => 0, 'conversions' => 0, 'weight' => 1.0 )
        );
    }

    wp_nonce_field( 'aiab_save_experiment', 'aiab_experiment_nonce' );
    ?>
    <p>
        <label><?php esc_html_e( 'CSS selector to target (e.g. .post-title, #hero .cta)', 'ai-ab-testing' ); ?></label><br/>
        <input type="text" name="aiab_selector" value="<?php echo esc_attr( $selector ); ?>" class="regular-text" />
    </p>
    <p>
        <label><?php esc_html_e( 'Conversion goal (CSS selector or event name)', 'ai-ab-testing' ); ?></label><br/>
        <input type="text" name="aiab_goal" value="<?php echo esc_attr( $goal ); ?>" class="regular-text" />
        <br/><span class="description"><?php esc_html_e( 'If you provide a CSS selector, clicks on that element count as conversions; otherwise you can trigger conversions via JS event aiabTrackConversion(experimentId).', 'ai-ab-testing' ); ?></span>
    </p>

    <h4><?php esc_html_e( 'Variants (JSON array)', 'ai-ab-testing' ); ?></h4>
    <p><textarea name="aiab_variants" rows="10" class="large-text"><?php echo esc_textarea( wp_json_encode( $variants, JSON_PRETTY_PRINT ) ); ?></textarea></p>

    <p>
        <button id="aiab-generate" class="button"><?php esc_html_e( 'Auto-generate variants (AI)', 'ai-ab-testing' ); ?></button>
        <span class="description"><?php esc_html_e( 'Select some text on the page or add a hint in the variants JSON before requesting AI suggestions.', 'ai-ab-testing' ); ?></span>
    </p>

    <h4><?php esc_html_e( 'Stats', 'ai-ab-testing' ); ?></h4>
    <table class="widefat fixed">
        <thead><tr><th><?php esc_html_e( 'Variant', 'ai-ab-testing' ); ?></th><th><?php esc_html_e( 'Impressions', 'ai-ab-testing' ); ?></th><th><?php esc_html_e( 'Conversions', 'ai-ab-testing' ); ?></th><th><?php esc_html_e( 'Conversion Rate', 'ai-ab-testing' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $variants as $v ): ?>
            <tr>
                <td><?php echo esc_html( $v['name'] ); ?></td>
                <td><?php echo intval( $v['impressions'] ); ?></td>
                <td><?php echo intval( $v['conversions'] ); ?></td>
                <td><?php
                    $imp = max( 1, intval( $v['impressions'] ) );
                    echo esc_html( number_format( ( intval( $v['conversions'] ) / $imp ) * 100, 2 ) . '%' );
                ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
}

/**
 * AJAX: Generate variants via AI (uses stored API key)
 * admin action only
 */
add_action( 'wp_ajax_aiab_generate_variants', 'aiab_ajax_generate_variants' );
function aiab_ajax_generate_variants() {
    check_ajax_referer( 'aiab_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'unauthorized', 403 );
    }
    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    $hint = isset( $_POST['hint'] ) ? sanitize_text_field( wp_unslash( $_POST['hint'] ) ) : '';
    if ( ! $post_id ) {
        wp_send_json_error( 'missing_post_id', 400 );
    }

    $options = get_option( 'aiab_settings', array() );
    $api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';

    if ( empty( $api_key ) ) {
        wp_send_json_error( 'no_api_key', 400 );
    }

    // Build prompt: ask AI to suggest 3 short variants for title/CTA/layout based on hint
    $prompt = "You are an expert conversion optimization assistant. Provide 3 concise variants for a web element (titles or CTA text). Return JSON array with objects: {name, type ('title'|'cta'|'layout'), content}. Keep content short and usable. Context/hint: " . $hint;

    $body = array(
        'model' => 'gpt-4o-mini', // use a generic model name; users can change if needed
        'messages' => array(
            array( 'role' => 'system', 'content' => 'You are a helpful assistant that outputs concise JSON.' ),
            array( 'role' => 'user', 'content' => $prompt ),
        ),
        'max_tokens' => 300,
        'temperature' => 0.8,
    );

    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode( $body ),
        'timeout' => 20,
    ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( $response->get_error_message(), 500 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    if ( $code !== 200 ) {
        wp_send_json_error( 'AI API error: ' . substr( $body, 0, 300 ), $code );
    }

    $decoded = json_decode( $body, true );
    if ( ! $decoded || ! isset( $decoded['choices'][0]['message']['content'] ) ) {
        wp_send_json_error( 'invalid_ai_response', 500 );
    }

    $ai_text = $decoded['choices'][0]['message']['content'];

    // Attempt to extract JSON from the AI text; if it includes backticks or text, try to find JSON substring
    $json = aiab_extract_json( $ai_text );
    if ( $json === false ) {
        wp_send_json_error( 'could_not_parse_ai_json: ' . substr( $ai_text, 0, 200 ), 500 );
    }

    // Normalize variants: ensure impressions/conversions/weight present
    $variants = json_decode( $json, true );
    if ( ! is_array( $variants ) ) {
        wp_send_json_error( 'ai_json_not_array', 500 );
    }
    $count = count( $variants );
    foreach ( $variants as $i => $v ) {
        $variants[$i]['name'] = isset( $v['name'] ) ? sanitize_text_field( $v['name'] ) : 'variant-' . ($i+1);
        $variants[$i]['type'] = isset( $v['type'] ) ? sanitize_text_field( $v['type'] ) : 'title';
        $variants[$i]['content'] = isset( $v['content'] ) ? sanitize_text_field( $v['content'] ) : '';
        $variants[$i]['impressions'] = 0;
        $variants[$i]['conversions'] = 0;
        $variants[$i]['weight'] = 1 / max(1, $count);
    }

    wp_send_json_success( array( 'variants' => $variants ) );
}

function aiab_extract_json( $text ) {
    // Try to find first [... ] or {...} JSON-like substring
    $start = null;
    $end = null;
    $first_brace = strpos( $text, '[' );
    $first_obj = strpos( $text, '{' );
    if ( $first_brace === false && $first_obj === false ) return false;
    // Prefer array
    if ( $first_brace !== false ) {
        $start = $first_brace;
        // find matching closing ]
        $level = 0;
        for ( $i = $start; $i < strlen( $text ); $i++ ) {
            if ( $text[$i] === '[' ) $level++;
            if ( $text[$i] === ']' ) {
                $level--;
                if ( $level === 0 ) {
                    $end = $i;
                    break;
                }
            }
        }
    } else {
        $start = $first_obj;
        $level = 0;
        for ( $i = $start; $i < strlen( $text ); $i++ ) {
            if ( $text[$i] === '{' ) $level++;
            if ( $text[$i] === '}' ) {
                $level--;
                if ( $level === 0 ) {
                    $end = $i;
                    break;
                }
            }
        }
    }
    if ( $start !== null && $end !== null ) {
        $json = substr( $text, $start, $end - $start + 1 );
        return $json;
    }
    return false;
}

/**
 * AJAX: record impression or conversion
 */
add_action( 'wp_ajax_aiab_track', 'aiab_ajax_track' );
add_action( 'wp_ajax_nopriv_aiab_track', 'aiab_ajax_track' );
function aiab_ajax_track() {
    check_ajax_referer( 'aiab_frontend_nonce', 'nonce' );

    $action = isset( $_POST['track_action'] ) ? sanitize_text_field( wp_unslash( $_POST['track_action'] ) ) : '';
    $experiment_id = isset( $_POST['experiment_id'] ) ? intval( $_POST['experiment_id'] ) : 0;
    $variant_idx = isset( $_POST['variant_idx'] ) ? intval( $_POST['variant_idx'] ) : 0;
    if ( ! $experiment_id || $variant_idx < 0 ) {
        wp_send_json_error( 'missing_params', 400 );
    }

    $variants_json = get_post_meta( $experiment_id, 'aiab_variants', true );
    $variants = $variants_json ? json_decode( $variants_json, true ) : array();
    if ( ! is_array( $variants ) || ! isset( $variants[ $variant_idx ] ) ) {
        wp_send_json_error( 'invalid_variant', 400 );
    }

    // Update counts safely
    $variants[ $variant_idx ]['impressions'] = isset( $variants[ $variant_idx ]['impressions'] ) ? intval( $variants[ $variant_idx ]['impressions'] ) : 0;
    $variants[ $variant_idx ]['conversions'] = isset( $variants[ $variant_idx ]['conversions'] ) ? intval( $variants[ $variant_idx ]['conversions'] ) : 0;

    if ( $action === 'impression' ) {
        $variants[ $variant_idx ]['impressions']++;
    } elseif ( $action === 'conversion' ) {
        $variants[ $variant_idx ]['conversions']++;
        // optional: quick reallocation step (epsilon-greedy)
        $variants = aiab_reallocate_weights( $variants );
    } else {
        wp_send_json_error( 'unknown_action', 400 );
    }

    update_post_meta( $experiment_id, 'aiab_variants', wp_json_encode( $variants ) );
    wp_send_json_success( array( 'variants' => $variants ) );
}

/**
 * Simple epsilon-greedy reallocation after a conversion
 * - Calculates conversion rates and increases weight of best performing variant
 */
function aiab_reallocate_weights( $variants ) {
    $epsilon = 0.1; // exploration factor
    $best_idx = 0;
    $best_rate = -1;
    foreach ( $variants as $i => $v ) {
        $imp = max( 1, intval( $v['impressions'] ) );
        $conv = intval( $v['conversions'] );
        $rate = $conv / $imp;
        if ( $rate > $best_rate ) {
            $best_rate = $rate;
            $best_idx = $i;
        }
    }
    $n = count( $variants );
    foreach ( $variants as $i => $v ) {
        if ( $i === $best_idx ) {
            $variants[$i]['weight'] = (1 - $epsilon) + ($epsilon / $n);
        } else {
            $variants[$i]['weight'] = $epsilon / $n;
        }
    }
    return $variants;
}

/**
 * Shortcode to run an experiment by ID
 * Usage: [aiab_experiment id="123"]
 * It will attempt to find selector, variants, choose variant and output wrapper with data attributes.
 */
add_shortcode( 'aiab_experiment', 'aiab_shortcode_experiment' );
function aiab_shortcode_experiment( $atts ) {
    $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'aiab_experiment' );
    $id = intval( $atts['id'] );
    if ( ! $id ) return '';

    $variants_json = get_post_meta( $id, 'aiab_variants', true );
    $variants = $variants_json ? json_decode( $variants_json, true ) : array();
    $selector = get_post_meta( $id, 'aiab_selector', true );

    // pick variant by cookie or weights
    $chosen = aiab_choose_variant_for_user( $id, $variants );

    // output placeholder wrapper for frontend.js to mount into selector
    // We'll let frontend.js find the selector and replace content
    ob_start();
    ?>
    <div class="aiab-experiment" data-experiment-id="<?php echo esc_attr( $id ); ?>" data-variant-idx="<?php echo esc_attr( $chosen ); ?>" data-selector="<?php echo esc_attr( $selector ); ?>"></div>
    <?php
    return ob_get_clean();
}

function aiab_choose_variant_for_user( $experiment_id, $variants ) {
    if ( empty( $variants ) ) return 0;
    $cookie_name = 'aiab_exp_' . $experiment_id;
    if ( isset( $_COOKIE[ $cookie_name ] ) && is_numeric( $_COOKIE[ $cookie_name ] ) ) {
        $idx = intval( $_COOKIE[ $cookie_name ] );
        if ( isset( $variants[ $idx ] ) ) return $idx;
    }
    // sample by weights
    $weights = array_map( function ( $v ) { return isset($v['weight']) ? floatval($v['weight']) : 1; }, $variants );
    $sum = array_sum( $weights );
    $r = mt_rand() / mt_getrandmax() * $sum;
    $acc = 0;
    foreach ( $weights as $i => $w ) {
        $acc += $w;
        if ( $r <= $acc ) {
            $idx = $i;
            break;
        }
    }
    if ( ! isset( $idx ) ) $idx = 0;

    // set cookie for 30 days
    setcookie( $cookie_name, $idx, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/' , COOKIE_DOMAIN );

    return $idx;
}

add_action( 'wp_ajax_aiab_get_variants', 'aiab_ajax_get_variants' );
add_action( 'wp_ajax_nopriv_aiab_get_variants', 'aiab_ajax_get_variants' );
function aiab_ajax_get_variants() {
    check_ajax_referer( 'aiab_frontend_nonce', 'nonce' );
    $exp = isset( $_POST['experiment_id'] ) ? intval( $_POST['experiment_id'] ) : 0;
    if ( ! $exp ) {
        wp_send_json_error( 'missing_experiment' );
    }
    $variants_json = get_post_meta( $exp, 'aiab_variants', true );
    $variants = $variants_json ? json_decode( $variants_json, true ) : array();
    $goal_selector = get_post_meta( $exp, 'aiab_goal', true );
    // Only expose safe parts of variant to front-end
    $out = array();
    foreach ( $variants as $v ) {
        $out[] = array(
            'name' => sanitize_text_field( $v['name'] ),
            'type' => sanitize_text_field( $v['type'] ),
            'content' => wp_kses_post( $v['content'] ),
        );
    }
    wp_send_json_success( array( 'variants' => $out, 'goal_selector' => $goal_selector ) );
}
