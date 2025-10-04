<?php
/**
 * Plugin Name: Interactive Story Builder
 * Description: Adds an Interactive Story Builder for scroll-based storytelling posts (custom post type + admin builder UI + front-end renderer + shortcode).
 * Version: 1.0.0
 * Author: ChatGPT
 * License: GPLv2 or later
 * Text Domain: interactive-story-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

final class ISB_Plugin {
    public function __construct() {
        add_action( 'init', [ $this, 'register_story_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_builder_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'save_post', [ $this, 'save_builder_data' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );
        add_shortcode( 'interactive_story', [ $this, 'render_shortcode' ] );
        add_filter( 'single_template', [ $this, 'single_template' ] );
    }

    public function register_story_cpt() {
        $labels = [
            'name'               => __( 'Interactive Stories', 'interactive-story-builder' ),
            'singular_name'      => __( 'Interactive Story', 'interactive-story-builder' ),
            'add_new'            => __( 'Add New Story', 'interactive-story-builder' ),
            'add_new_item'       => __( 'Add New Story', 'interactive-story-builder' ),
            'edit_item'          => __( 'Edit Story', 'interactive-story-builder' ),
            'new_item'           => __( 'New Story', 'interactive-story-builder' ),
            'view_item'          => __( 'View Story', 'interactive-story-builder' ),
            'search_items'       => __( 'Search Stories', 'interactive-story-builder' ),
            'not_found'          => __( 'No stories found', 'interactive-story-builder' ),
            'not_found_in_trash' => __( 'No stories found in Trash', 'interactive-story-builder' )
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'show_in_rest'       => true,
            'has_archive'        => true,
            'rewrite'            => [ 'slug' => 'stories' ],
            'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-book-alt',
        ];

        register_post_type( 'interactive_story', $args );
    }

    public function add_builder_metabox() {
        add_meta_box(
            'isb_builder',
            __( 'Interactive Story Builder', 'interactive-story-builder' ),
            [ $this, 'builder_metabox_callback' ],
            'interactive_story',
            'normal',
            'high'
        );
    }

    public function builder_metabox_callback( $post ) {
        wp_nonce_field( 'isb_save_builder', 'isb_builder_nonce' );

        $data = get_post_meta( $post->ID, '_isb_data', true );
        if ( empty( $data ) ) {
            $data = [
                'sections' => [
                    [
                        'id' => 'sec-' . wp_generate_password( 6, false, false ),
                        'title' => 'Intro',
                        'content' => '<p>Start your story here.</p>',
                        'bg_color' => '#ffffff',
                        'text_color' => '#111111',
                        'pin' => false
                    ]
                ]
            ];
        }

        // Hidden field that will store JSON before saving
        echo '<input type="hidden" id="_isb_data_field" name="_isb_data_field" value="' . esc_attr( wp_json_encode( $data ) ) . '" />';

        // Builder container
        echo '<div id="isb-builder-root"></div>';

        // Basic instructions
        echo '<p class="description">' . esc_html__( 'Use the builder to add, reorder and customize sections. When saving the post, your story structure will be stored as structured JSON.', 'interactive-story-builder' ) . '</p>';
    }

    public function admin_assets( $hook ) {
        global $post_type;

        if ( ( $hook === 'post-new.php' || $hook === 'post.php' ) && $post_type === 'interactive_story' ) {
            // Enqueue styles
            wp_enqueue_style( 'isb-admin-style', plugins_url( 'assets/admin.css', __FILE__ ), [], '1.0' );

            // Use wp_enqueue_script with dependencies
            wp_enqueue_script( 'isb-admin-script', plugins_url( 'assets/admin.js', __FILE__ ), [ 'jquery' ], '1.0', true );

            // Pass initial data
            $data = get_post_meta( get_the_ID(), '_isb_data', true );
            if ( empty( $data ) ) {
                $data = [];
            }
            wp_localize_script( 'isb-admin-script', 'ISB_ADMIN', [
                'nonce' => wp_create_nonce( 'isb_admin_nonce' ),
                'initial' => $data,
                'strings' => [
                    'add_section' => __( 'Add Section', 'interactive-story-builder' ),
                    'remove_section' => __( 'Remove', 'interactive-story-builder' ),
                    'move_up' => __( 'Move up', 'interactive-story-builder' ),
                    'move_down' => __( 'Move down', 'interactive-story-builder' ),
                ]
            ] );
        }
    }

    public function save_builder_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['post_type'] ) || $_POST['post_type'] !== 'interactive_story' ) {
            return;
        }

        if ( ! isset( $_POST['isb_builder_nonce'] ) || ! wp_verify_nonce( $_POST['isb_builder_nonce'], 'isb_save_builder' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['_isb_data_field'] ) ) {
            $raw = wp_unslash( $_POST['_isb_data_field'] );
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                // Sanitize each section
                foreach ( $decoded['sections'] as &$section ) {
                    $section['id'] = sanitize_text_field( $section['id'] ?? wp_generate_password( 6, false, false ) );
                    $section['title'] = sanitize_text_field( $section['title'] ?? '' );
                    // Allow some HTML in content but sanitize
                    if ( isset( $section['content'] ) ) {
                        $section['content'] = wp_kses_post( $section['content'] );
                    } else {
                        $section['content'] = '';
                    }
                    $section['bg_color'] = sanitize_hex_color( $section['bg_color'] ?? '#ffffff' );
                    $section['text_color'] = sanitize_hex_color( $section['text_color'] ?? '#111111' );
                    $section['pin'] = isset( $section['pin'] ) ? (bool) $section['pin'] : false;
                }
                update_post_meta( $post_id, '_isb_data', $decoded );
            }
        }
    }

    public function frontend_assets() {
        // Enqueue CSS and JS for the front-end renderer
        wp_enqueue_style( 'isb-frontend-style', plugins_url( 'assets/frontend.css', __FILE__ ), [], '1.0' );
        wp_enqueue_script( 'isb-frontend-script', plugins_url( 'assets/frontend.js', __FILE__ ), [], '1.0', true );

        // Localize with settings
        wp_localize_script( 'isb-frontend-script', 'ISB_FRONTEND', [
            'nonce' => wp_create_nonce( 'isb_frontend_nonce' ),
        ] );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'interactive_story' );
        $post_id = intval( $atts['id'] );
        if ( ! $post_id ) {
            return '<p>Interactive Story: invalid id.</p>';
        }
        $data = get_post_meta( $post_id, '_isb_data', true );
        if ( empty( $data ) || empty( $data['sections'] ) ) {
            return '<p>No story sections found.</p>';
        }

        // Build HTML output
        ob_start();
        ?>
        <div class="isb-story" data-story-id="<?php echo esc_attr( $post_id ); ?>">
            <?php foreach ( $data['sections'] as $section ) :
                $sec_id = esc_attr( $section['id'] );
                $title = esc_html( $section['title'] );
                $content = $section['content'];
                $bg = esc_attr( $section['bg_color'] ?? '' );
                $tc = esc_attr( $section['text_color'] ?? '' );
                $pin = ! empty( $section['pin'] ) ? 'true' : 'false';
                ?>
                <section class="isb-section" id="<?php echo $sec_id; ?>" data-pin="<?php echo $pin; ?>" style="background-color: <?php echo $bg; ?>; color: <?php echo $tc; ?>;">
                    <div class="isb-section-inner">
                        <h2 class="isb-section-title"><?php echo $title; ?></h2>
                        <div class="isb-section-content"><?php echo $content; ?></div>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function single_template( $single ) {
        global $post;

        if ( $post->post_type === 'interactive_story' ) {
            // Prefer theme's single-interactive_story.php if exists, otherwise use plugin renderer
            $theme_file = locate_template( [ 'single-interactive_story.php' ] );
            if ( $theme_file ) {
                return $theme_file;
            }

            // Fallback to a simple wrapper template in plugin
            return plugin_dir_path( __FILE__ ) . 'templates/single-interactive_story.php';
        }

        return $single;
    }
}

new ISB_Plugin();

// Create simple template file content on plugin activation if not present
register_activation_hook( __FILE__, function() {
    $template_dir = plugin_dir_path( __FILE__ ) . 'templates';
    if ( ! file_exists( $template_dir ) ) {
        wp_mkdir_p( $template_dir );
    }

    $tpl = $template_dir . '/single-interactive_story.php';
    if ( ! file_exists( $tpl ) ) {
        $content = "<?php\n// Fallback single template for Interactive Story CPT\nget_header();\nif ( have_posts() ) : while ( have_posts() ) : the_post();\n    echo do_shortcode('[interactive_story id=' . get_the_ID() . ']');\nendwhile; endif;\nget_footer();\n";
        file_put_contents( $tpl, $content );
    }
} );

