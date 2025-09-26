<?php
/**
 * Plugin Name: Offline Mode with Sync
 * Description: Allows visitors to browse cached versions of posts while offline and auto-syncs cached content when back online.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: offline-mode-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Offline_Mode_Sync {
    const OPTION_KEY = 'offline_mode_sync_settings';

    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
    }

    public function activate() {
        // Set defaults
        $defaults = array(
            'precache_posts' => 10,
            'cache_name' => 'offline-mode-cache-v1',
        );
        add_option( self::OPTION_KEY, $defaults );
    }

    public function init() {
        // Nothing heavy for now
    }

    public function admin_menu() {
        add_options_page(
            'Offline Mode Sync',
            'Offline Mode Sync',
            'manage_options',
            'offline-mode-sync',
            array( $this, 'settings_page' )
        );

        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_settings() {
        register_setting( 'offline_mode_sync_group', self::OPTION_KEY );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $opts = get_option( self::OPTION_KEY );
        ?>
        <div class="wrap">
            <h1>Offline Mode with Sync — Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'offline_mode_sync_group' ); ?>
                <?php do_settings_sections( 'offline_mode_sync_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="precache_posts">Number of posts to precache</label></th>
                        <td>
                            <input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[precache_posts]" type="number" id="precache_posts" value="<?php echo esc_attr( $opts['precache_posts'] ?? 10 ); ?>" class="small-text" min="0" />
                            <p class="description">When users first visit, the plugin will try to prefetch this many latest posts to the service worker cache.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cache_name">Cache name</label></th>
                        <td>
                            <input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_name]" type="text" id="cache_name" value="<?php echo esc_attr( $opts['cache_name'] ?? 'offline-mode-cache-v1' ); ?>" class="regular-text" />
                            <p class="description">Change this to force clients to re-cache (useful for breaking updates).</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        // Frontend registration script
        $opts = get_option( self::OPTION_KEY );
        $precache = intval( $opts['precache_posts'] ?? 10 );

        wp_register_script( 'offline-mode-sync-register', '' );
        $inline = "(function(){
    if ('serviceWorker' in navigator) {
        // Register the service worker from our REST route
        navigator.serviceWorker.register('" . esc_url_raw( rest_url( 'offline-mode/v1/sw' ) ) . "').then(function(reg){
            console.log('Offline Mode SW registered:', reg);

            // Ask SW to precache latest posts
            try {
                if (navigator.serviceWorker.controller) {
                    navigator.serviceWorker.controller.postMessage({action:'precacheLatestPosts',count:" . $precache . "});
                }
            } catch(e) { console.warn(e); }

        }).catch(function(err){
            console.warn('SW registration failed:', err);
        });

        // When connection is regained, tell SW to sync
        window.addEventListener('online', function(){
            if (navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({action:'syncNow'});
            }
        });
    }
})();";
        wp_add_inline_script( 'offline-mode-sync-register', $inline );
        wp_enqueue_script( 'offline-mode-sync-register' );
    }

    public function register_rest_routes() {
        register_rest_route( 'offline-mode/v1', '/sw', array(
            'methods' => 'GET',
            'callback' => array( $this, 'serve_service_worker' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'offline-mode/v1', '/precache-posts', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_posts_for_precache' ),
            'permission_callback' => '__return_true',
        ) );

    }

    public function serve_service_worker( 
        WP_REST_Request $request
    ) {
        header( 'Content-Type: application/javascript' );
        $opts = get_option( self::OPTION_KEY );
        $cache_name = esc_js( $opts['cache_name'] ?? 'offline-mode-cache-v1' );

        // Service worker JavaScript
        $sw = "// Offline Mode Service Worker\n\nvar CACHE_NAME = '" . $cache_name . "';\nvar PRECACHE_URLS = [ '/offline-fallback/' ]; // plugin will not create this page, theme should provide or fallback used\n\nself.addEventListener('install', function(event) {\n    console.log('[OfflineMode] install');\n    self.skipWaiting();\n    event.waitUntil(\n        caches.open(CACHE_NAME).then(function(cache) {\n            return cache.addAll(PRECACHE_URLS);\n        })\n    );\n});\n\nself.addEventListener('activate', function(event) {\n    console.log('[OfflineMode] activate');\n    event.waitUntil(self.clients.claim());\n});\n\n// Utility: fetch & cache update\nfunction fetchAndUpdateCache(request){\n    return fetch(request).then(function(resp){\n        if(!resp || resp.status !== 200) return resp;\n        var copy = resp.clone();\n        caches.open(CACHE_NAME).then(function(cache){ cache.put(request, copy); });\n        return resp;\n    }).catch(function(){ return caches.match(request); });\n}\n\nself.addEventListener('fetch', function(event) {\n    var req = event.request;\n    // Only handle GET navigations and same-origin requests for posts/pages\n    if (req.method !== 'GET') return;\n    var url = new URL(req.url);\n    var isNavigation = req.mode === 'navigate';\n
    // If request looks like an API call for wp-json, prefer network (stale-while-revalidate)
    if (url.pathname.indexOf('/wp-json/') === 0) {
        event.respondWith(
            fetch(req).catch(function(){ return caches.match(req); })
        );
        return;
    }

    // For navigation (HTML pages), try network first, fall back to cache
    if (isNavigation) {
        event.respondWith(
            fetch(req).then(function(networkResp){
                // Update cache
                var copy = networkResp.clone();
                caches.open(CACHE_NAME).then(function(cache){ cache.put(req, copy); });
                return networkResp;
            }).catch(function(){
                return caches.match(req).then(function(cached){
                    if (cached) return cached;
                    return caches.match('/offline-fallback/');
                });
            })
        );
        return;
    }

    // For other same-origin requests (images, CSS, JS), use cache-first then network
    if (url.origin === location.origin) {
        event.respondWith(
            caches.match(req).then(function(resp){
                if (resp) return resp;
                return fetchAndUpdateCache(req);
            })
        );
        return;
    }

});\n\n// Message handler: precache or sync now\nself.addEventListener('message', function(event){
    var data = event.data || {};
    if (data.action === 'precacheLatestPosts') {
        var count = parseInt(data.count) || 10;
        precacheLatestPosts(count);
    }
    if (data.action === 'syncNow') {
        precacheLatestPosts();
    }
});

function precacheLatestPosts(count) {
    count = count || 10;
    // Fetch list of latest posts (only front-end accessible fields)
    fetch('/wp-json/offline-mode/v1/precache-posts?per_page=' + count).then(function(resp){
        return resp.json();
    }).then(function(list){
        if (!Array.isArray(list)) return;
        caches.open(CACHE_NAME).then(function(cache){
            list.forEach(function(item){
                // prefer fetching the full page (navigation) so HTML is cached
                try { cache.add(item.link); } catch(e){ console.warn(e); }
            });
        });
    }).catch(function(e){ console.warn('precache error', e); });
}
";

        echo $sw;
        exit;
    }

    public function get_posts_for_precache( WP_REST_Request $request ) {
        $per_page = intval( $request->get_param( 'per_page' ) ?: 10 );
        $args = array(
            'numberposts' => $per_page,
            'post_status' => 'publish',
        );
        $posts = get_posts( $args );
        $out = array();
        foreach ( $posts as $p ) {
            $out[] = array(
                'id' => $p->ID,
                'title' => get_the_title( $p ),
                'link' => get_permalink( $p ),
                'modified' => get_post_modified_time( 'c', false, $p ),
            );
        }
        return rest_ensure_response( $out );
    }

}

new Offline_Mode_Sync();

// Short instructions to create an optional offline fallback page: The plugin expects a page at /offline-fallback/ to exist (simple static HTML) which will be served when offline and a requested page is not cached. Theme authors may create a page with slug "offline-fallback" and simple content describing offline status.
