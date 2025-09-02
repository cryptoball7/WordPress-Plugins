<?php
/**
 * Plugin Name: Multisite Admin Tool
 * Description: Network-level dashboard tools for searching and managing posts and users across a WordPress Multisite network. Supports advanced queries, permissions, REST endpoints and bulk actions.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Network: true
 * License: GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

final class MSAT_Multisite_Admin_Tool {

    const VERSION = '1.0.0';
    const REST_NAMESPACE = 'msat/v1';

    private static $instance = null;

    private function __construct() {
        // Only load on networks
        if ( ! is_multisite() ) {
            add_action( 'network_admin_notices', array( $this, 'not_multisite_notice' ) );
            return;
        }

        add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
        add_action( 'network_admin_edit_msat_bulk_action', array( $this, 'handle_bulk_action' ) );

        add_action( 'network_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // REST API
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Capability on activation
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );
    }

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function not_multisite_notice() {
        echo '<div class="error"><p>Multisite Admin Tool requires WordPress Multisite (Network) to be enabled.</p></div>';
    }

    public function add_network_menu() {
        $cap = $this->network_capability();
        add_menu_page(
            'Multisite Admin Tool',
            'MS Admin Tool',
            $cap,
            'msat-tool',
            array( $this, 'render_admin_page' ),
            'dashicons-screenoptions',
            3
        );
    }

    private function network_capability() {
        // Allow other plugins to override the capability needed to use this tool
        return apply_filters( 'msat_required_capability', 'manage_network' );
    }

    public function render_admin_page() {
        if ( ! current_user_can( $this->network_capability() ) ) {
            wp_die( 'You do not have sufficient permissions to access this page.' );
        }
        ?>
        <div class="wrap">
            <h1>Multisite Admin Tool</h1>
            <p>Search posts, manage users and run advanced queries across your network.</p>

            <h2>Quick Search</h2>
            <form id="msat-search-form" method="get">
                <label>Query (post title/content): <input type="search" name="q" id="msat-q"></label>
                <label>Post type: <input name="post_type" id="msat-post-type" placeholder="post,page"></label>
                <label>Sites (comma-separated IDs or domain/path): <input name="sites" id="msat-sites"></label>
                <button id="msat-search-button" class="button">Search</button>
            </form>

            <div id="msat-results"></div>

            <h2>User Tools</h2>
            <form id="msat-user-form" method="get">
                <label>User query: <input id="msat-user-q" name="q"></label>
                <button id="msat-user-search" class="button">Search Users</button>
            </form>
            <div id="msat-user-results"></div>

            <h2>Bulk Actions</h2>
            <p>Selected items from search results can be acted on with bulk operations (network-level).</p>

        </div>
        <?php
    }

    public function enqueue_assets() {
        $screen = get_current_screen();
        if ( 'toplevel_page_msat-tool' !== $screen->id ) {
            return;
        }

        wp_register_script( 'msat-admin', plugins_url( 'assets/msat-admin.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
        wp_localize_script( 'msat-admin', 'msatParams', array(
            'restRoot' => esc_url_raw( rest_url( self::REST_NAMESPACE ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'capability' => $this->network_capability(),
        ) );
        wp_enqueue_script( 'msat-admin' );

        wp_register_style( 'msat-admin-style', plugins_url( 'assets/msat-admin.css', __FILE__ ), array(), self::VERSION );
        wp_enqueue_style( 'msat-admin-style' );
    }

    public function register_rest_routes() {
        $capability = $this->network_capability();

        register_rest_route( self::REST_NAMESPACE, '/search/posts', array(
            'methods' => 'GET',
            'callback' => array( $this, 'rest_search_posts' ),
            'permission_callback' => function ( $request ) use ( $capability ) {
                return current_user_can( $capability );
            }
        ) );

        register_rest_route( self::REST_NAMESPACE, '/search/users', array(
            'methods' => 'GET',
            'callback' => array( $this, 'rest_search_users' ),
            'permission_callback' => function ( $request ) use ( $capability ) {
                return current_user_can( $capability );
            }
        ) );

        register_rest_route( self::REST_NAMESPACE, '/bulk/action', array(
            'methods' => 'POST',
            'callback' => array( $this, 'rest_bulk_action' ),
            'permission_callback' => function ( $request ) use ( $capability ) {
                return current_user_can( $capability );
            }
        ) );
    }

    /**
     * REST: Search posts across sites.
     * Accepts: q, post_type, sites (comma separated ids/domains), per_page, page
     */
    public function rest_search_posts( WP_REST_Request $request ) {
        $q = $request->get_param( 'q' );
        $post_type = $request->get_param( 'post_type' );
        $sites = $request->get_param( 'sites' );
        $per_page = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $page = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );

        $sites_to_search = $this->parse_sites_input( $sites );
        if ( empty( $sites_to_search ) ) {
            // default to all sites
            $sites_to_search = get_sites( array( 'fields' => 'ids', 'number' => 1000 ) );
        }

        $results = array();
        $total = 0;

        foreach ( $sites_to_search as $blog_id ) {
            switch_to_blog( $blog_id );

            $args = array(
                's' => $q,
                'post_type' => $post_type ?: 'any',
                'posts_per_page' => $per_page,
                'paged' => $page,
                'no_found_rows' => false,
            );

            // Allow advanced modifications
            $args = apply_filters( 'msat_post_query_args', $args, $blog_id, $request );

            $query = new WP_Query( $args );

            if ( $query->have_posts() ) {
                foreach ( $query->posts as $post ) {
                    $results[] = array(
                        'site_id' => $blog_id,
                        'site_url' => get_site_url( $blog_id ),
                        'ID' => $post->ID,
                        'post_title' => get_the_title( $post ),
                        'post_type' => $post->post_type,
                        'post_status' => $post->post_status,
                        'permalink' => get_permalink( $post ),
                    );
                }
            }

            $total += (int) $query->found_posts;

            restore_current_blog();
        }

        return rest_ensure_response( array(
            'total' => $total,
            'per_page' => $per_page,
            'page' => $page,
            'results' => $results,
        ) );
    }

    private function parse_sites_input( $sites ) {
        if ( empty( $sites ) ) {
            return array();
        }
        $sites_raw = preg_split( '/[\s,;]+/', trim( $sites ) );
        $ids = array();
        foreach ( $sites_raw as $s ) {
            if ( is_numeric( $s ) ) {
                $id = intval( $s );
                if ( get_blog_details( $id ) ) {
                    $ids[] = $id;
                }
            } else {
                // try domain/path
                $details = get_blog_details( array( 'domain' => $s, 'path' => '/' ) );
                if ( $details ) {
                    $ids[] = $details->blog_id;
                } else {
                    // try by domain match
                    $found = get_sites( array( 'search' => $s, 'fields' => 'ids' ) );
                    if ( $found ) {
                        $ids = array_merge( $ids, $found );
                    }
                }
            }
        }
        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * REST: Search users across network sites.
     * Accepts: q, per_page, page
     */
    public function rest_search_users( WP_REST_Request $request ) {
        $q = $request->get_param( 'q' );
        $per_page = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $page = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );

        // Build WP_User_Query across network: gather users and the sites they belong to
        global $wpdb;

        $like = '%' . $wpdb->esc_like( $q ) . '%';

        $sql = $wpdb->prepare( "SELECT u.ID, u.user_login, u.user_email, u.display_name
            FROM {$wpdb->users} u
            WHERE u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s
            LIMIT %d, %d",
            $like, $like, $like, ( ( $page - 1 ) * $per_page ), $per_page
        );

        $rows = $wpdb->get_results( $sql );

        $results = array();
        foreach ( $rows as $row ) {
            $user_id = intval( $row->ID );
            $sites = get_blogs_of_user( $user_id, true );
            $site_list = array();
            foreach ( $sites as $s ) {
                $site_list[] = array( 'blog_id' => $s->userblog_id, 'siteurl' => get_site_url( $s->userblog_id ) );
            }
            $results[] = array(
                'ID' => $user_id,
                'user_login' => $row->user_login,
                'user_email' => $row->user_email,
                'display_name' => $row->display_name,
                'sites' => $site_list,
            );
        }

        return rest_ensure_response( array(
            'total' => count( $results ),
            'page' => $page,
            'per_page' => $per_page,
            'results' => $results,
        ) );
    }

    /**
     * REST: Bulk actions across sites
     * Accepts: action, items[] where each item = { site_id, type(post|user), id }
     */
    public function rest_bulk_action( WP_REST_Request $request ) {
        $action = $request->get_param( 'action' );
        $items = $request->get_param( 'items' );

        if ( empty( $action ) || empty( $items ) || ! is_array( $items ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid parameters' ), 400 );
        }

        $allowed_actions = apply_filters( 'msat_allowed_bulk_actions', array( 'delete_post', 'trash_post', 'unpublish_post', 'remove_user_from_site', 'promote_user' ) );
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            return new WP_REST_Response( array( 'error' => 'Action not allowed' ), 403 );
        }

        $report = array();

        foreach ( $items as $item ) {
            if ( empty( $item['site_id'] ) || empty( $item['type'] ) || empty( $item['id'] ) ) {
                $report[] = array( 'item' => $item, 'status' => 'skipped', 'reason' => 'malformed' );
                continue;
            }

            $site_id = intval( $item['site_id'] );
            $type = sanitize_text_field( $item['type'] );
            $id = intval( $item['id'] );

            switch_to_blog( $site_id );

            try {
                if ( 'post' === $type ) {
                    if ( 'delete_post' === $action ) {
                        $success = wp_delete_post( $id, true );
                        $report[] = array( 'item' => $item, 'status' => $success ? 'deleted' : 'failed' );
                    } elseif ( 'trash_post' === $action ) {
                        $success = wp_trash_post( $id );
                        $report[] = array( 'item' => $item, 'status' => $success ? 'trashed' : 'failed' );
                    } elseif ( 'unpublish_post' === $action ) {
                        $p = array( 'ID' => $id, 'post_status' => 'draft' );
                        $res = wp_update_post( $p, true );
                        $report[] = array( 'item' => $item, 'status' => is_wp_error( $res ) ? 'failed' : 'updated' );
                    } else {
                        $report[] = array( 'item' => $item, 'status' => 'skipped', 'reason' => 'unknown post action' );
                    }
                } elseif ( 'user' === $type ) {
                    if ( 'remove_user_from_site' === $action ) {
                        $res = remove_user_from_blog( $id, $site_id );
                        $report[] = array( 'item' => $item, 'status' => $res ? 'removed' : 'failed' );
                    } elseif ( 'promote_user' === $action ) {
                        // Promote to administrator on that site (dangerous) — plugin owner must allow this
                        $role = apply_filters( 'msat_promote_role', 'administrator' );
                        $user = new WP_User( $id );
                        $user->set_role( $role );
                        $report[] = array( 'item' => $item, 'status' => 'role_set:' . $role );
                    } else {
                        $report[] = array( 'item' => $item, 'status' => 'skipped', 'reason' => 'unknown user action' );
                    }
                } else {
                    $report[] = array( 'item' => $item, 'status' => 'skipped', 'reason' => 'unknown type' );
                }
            } catch ( Exception $e ) {
                $report[] = array( 'item' => $item, 'status' => 'error', 'message' => $e->getMessage() );
            }

            restore_current_blog();
        }

        return rest_ensure_response( array( 'report' => $report ) );
    }

    public function handle_bulk_action() {
        // Legacy support if using form POST to network_admin_edit_msat_bulk_action
        // Not implemented in this file; prefer REST endpoint
    }

    public function on_activate() {
        // Activation tasks
        // Optionally create a custom capability and assign to network admins
        $cap = $this->network_capability();
        // network admin role may be 'administrator' on main site; do not assume
        // Provide a hook for admins to assign this capability to roles
        do_action( 'msat_activated', $this );
    }

    public function on_deactivate() {
        do_action( 'msat_deactivated', $this );
    }

}

// Initialize
MSAT_Multisite_Admin_Tool::instance();

add_action( 'network_admin_menu', function() {
    // Do nothing; assets are enqueued when needed. This placeholder ensures plugin file loaded.
} );

?>
