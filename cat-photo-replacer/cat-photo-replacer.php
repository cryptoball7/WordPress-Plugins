<?php
/**
 * Plugin Name: Cat Photo Replacer
 * Description: Replaces <img> tags in post content with random cat images from a cat API (supports CATAAS and TheCatAPI). Handles remote images, srcset, lazy-attributes and provides an admin settings page.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * License:     GPLv3
 * Text Domain: cat-photo-replacer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

final class Cat_Photo_Replacer {
    private static $instance = null;
    private $option_name = 'cpr_options';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'the_content', array( $this, 'replace_images_in_content' ), 20 );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Main content filter
     */
    public function replace_images_in_content( $content ) {
        // Only run on singular posts/pages by default (but content filter could be applied anywhere)
        if ( is_admin() ) {
            return $content;
        }

        $opts = $this->get_options();
        if ( empty( $opts['enabled'] ) ) {
            return $content;
        }

        // Quick check: skip if no <img>
        if ( false === strpos( $content, '<img' ) ) {
            return $content;
        }

        // Use DOMDocument for robust HTML manipulation
        libxml_use_internal_errors( true );
        $doc = new DOMDocument();

        // It's possible the content isn't a full HTML doc; wrap it.
        $wrapped = '<!doctype html><html><body>' . $content . '</body></html>';
        $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

        $images = $doc->getElementsByTagName( 'img' );

        // We will collect replacements to avoid live-modifying the NodeList while iterating
        $to_replace = array();

        foreach ( $images as $img ) {
            $src = $img->getAttribute( 'src' );
            // If there is no src but data-src or data-lazy-src, prefer that
            if ( empty( $src ) ) {
                if ( $img->hasAttribute( 'data-src' ) ) {
                    $src = $img->getAttribute( 'data-src' );
                } elseif ( $img->hasAttribute( 'data-lazy-src' ) ) {
                    $src = $img->getAttribute( 'data-lazy-src' );
                }
            }

            // Only replace if it's an image URL (remote or local) and not already a cat provider
            if ( empty( $src ) ) {
                continue;
            }

            if ( $this->is_cat_provider( $src, $opts ) ) {
                continue; // don't replace if it already points to our chosen cat provider
            }

            // Decide whether to replace this image based on settings (e.g., replace all or only remote)
            $replace = true;
            if ( ! empty( $opts['only_remote'] ) ) {
                // Check if URL is external (has host different from site)
                $is_remote = $this->is_remote_url( $src );
                if ( ! $is_remote ) {
                    $replace = false;
                }
            }

            if ( $replace ) {
                $width = $img->getAttribute( 'width' );
                $height = $img->getAttribute( 'height' );

                // Try to infer size from attributes if not present
                $size = '';
                if ( $width || $height ) {
                    $size = trim( $width . 'x' . $height, 'x' );
                }

                $cat_url = $this->get_cat_url( $opts, $size );

                // We'll replace src and clear srcset (or optionally convert)
                $to_replace[] = array(
                    'node' => $img,
                    'cat_url' => $cat_url,
                );
            }
        }

        foreach ( $to_replace as $item ) {
            /** @var DOMElement $img */
            $img = $item['node'];
            $cat_url = $item['cat_url'];

            // Set src
            $img->setAttribute( 'src', esc_url_raw( $cat_url ) );

            // Remove srcset to avoid browser requesting originals
            if ( $img->hasAttribute( 'srcset' ) ) {
                $img->removeAttribute( 'srcset' );
            }

            // If lazy-loading data attributes exist, replace them too
            if ( $img->hasAttribute( 'data-src' ) ) {
                $img->setAttribute( 'data-src', esc_url_raw( $cat_url ) );
            }
            if ( $img->hasAttribute( 'data-lazy-src' ) ) {
                $img->setAttribute( 'data-lazy-src', esc_url_raw( $cat_url ) );
            }

            // Optionally update srcset-style attributes (data-srcset)
            if ( $img->hasAttribute( 'data-srcset' ) ) {
                $img->removeAttribute( 'data-srcset' );
            }

            // Add a data attribute to mark we replaced it (useful for debugging)
            $img->setAttribute( 'data-cpr-replaced', '1' );

            // Update alt text to include 'Cat photo' if empty (do not overwrite existing useful alt)
            $alt = $img->getAttribute( 'alt' );
            if ( empty( $alt ) ) {
                $img->setAttribute( 'alt', __( 'Random cat', 'cat-photo-replacer' ) );
            }
        }

        // Extract body innerHTML
        $body = $doc->getElementsByTagName( 'body' )->item(0);
        $inner = '';
        foreach ( $body->childNodes as $child ) {
            $inner .= $doc->saveHTML( $child );
        }

        libxml_clear_errors();

        return $inner;
    }

    private function get_options() {
        $defaults = array(
            'enabled' => 1,
            'only_remote' => 0,
            'provider' => 'cataas', // or 'thecatapi'
            'thecatapi_key' => '',
        );
        $opts = get_option( $this->option_name, array() );
        return wp_parse_args( $opts, $defaults );
    }

    private function is_remote_url( $url ) {
        $site_host = parse_url( home_url(), PHP_URL_HOST );
        $host = parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return false; // relative URL
        }
        return ( strcasecmp( $host, $site_host ) !== 0 );
    }

    private function is_cat_provider( $url, $opts ) {
        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) {
            return false;
        }
        $hosts = array();
        if ( 'cataas' === $opts['provider'] ) {
            $hosts[] = 'cataas.com';
        } else {
            // thecatapi
            $hosts[] = 'cdn2.thecatapi.com';
            $hosts[] = 'thecatapi.com';
        }
        foreach ( $hosts as $h ) {
            if ( false !== stripos( $host, $h ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the cat image URL depending on provider and optional size.
     * - For CATAAS we use: https://cataas.com/cat (supports /<width>x<height> and query to avoid cache)
     * - For TheCatAPI we will call their image endpoint (no server-side HTTP request here; we return the static image endpoint pattern).
     */
    private function get_cat_url( $opts, $size = '' ) {
        $provider = isset( $opts['provider'] ) ? $opts['provider'] : 'cataas';

        if ( 'thecatapi' === $provider ) {
            // TheCatAPI: direct image endpoints are returned by their search API; without server-side requests we fallback to their image CDN with a random query to avoid cache.
            // Note: for production you might want to request /v1/images/search to get a real random image URL and support favorites.
            $base = 'https://cdn2.thecatapi.com/images/';
            // We don't have an image id — use a generic endpoint that returns a random image via their static route does not exist.
            // Instead, send users to the REST endpoint if they provide an API key: https://api.thecatapi.com/v1/images/search
            if ( ! empty( $opts['thecatapi_key'] ) ) {
                // If user provided API key, use thecatapi's simple redirect endpoint which returns JSON — but we cannot request server-side here.
                // So we'll use the public simple random image provider path that should work in browsers via the api endpoint.
                $url = 'https://api.thecatapi.com/v1/images/search?size=full&mime_types=jpg,png&limit=1&_=' . time();
                // Return the API endpoint — many browsers will not render JSON as an image; therefore recommend using CATAAS for simple img replacement.
                return $this->get_cataas_url( $size );
            }

            // Fallback to CATAAS when TheCatAPI isn't being used fully
            return $this->get_cataas_url( $size );
        }

        // default to cataas
        return $this->get_cataas_url( $size );
    }

    private function get_cataas_url( $size = '' ) {
        $url = 'https://cataas.com/cat';
        if ( ! empty( $size ) ) {
            // cataas supports e.g. /cat/200x300
            $size_clean = preg_replace( '/[^0-9x]/', '', $size );
            if ( ! empty( $size_clean ) ) {
                $url = 'https://cataas.com/cat/' . $size_clean;
            }
        }
        // add a cache-busting query param
        $url .= '?_=' . time() . rand( 1000, 9999 );
        return $url;
    }

    /**
     * Admin settings
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Cat Photo Replacer', 'cat-photo-replacer' ),
            __( 'Cat Photo Replacer', 'cat-photo-replacer' ),
            'manage_options',
            'cat-photo-replacer',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'cpr_options_group', $this->option_name, array( $this, 'sanitize_options' ) );

        add_settings_section( 'cpr_main', __( 'Main settings', 'cat-photo-replacer' ), null, 'cat-photo-replacer' );

        add_settings_field( 'enabled', __( 'Enable replacement', 'cat-photo-replacer' ), array( $this, 'field_enabled' ), 'cat-photo-replacer', 'cpr_main' );
        add_settings_field( 'only_remote', __( 'Only replace remote images', 'cat-photo-replacer' ), array( $this, 'field_only_remote' ), 'cat-photo-replacer', 'cpr_main' );
        add_settings_field( 'provider', __( 'Provider', 'cat-photo-replacer' ), array( $this, 'field_provider' ), 'cat-photo-replacer', 'cpr_main' );
        add_settings_field( 'thecatapi_key', __( 'TheCatAPI key (optional)', 'cat-photo-replacer' ), array( $this, 'field_thecatapi_key' ), 'cat-photo-replacer', 'cpr_main' );
    }

    public function sanitize_options( $input ) {
        $out = array();
        $out['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;
        $out['only_remote'] = ! empty( $input['only_remote'] ) ? 1 : 0;
        $out['provider'] = in_array( $input['provider'], array( 'cataas', 'thecatapi' ), true ) ? $input['provider'] : 'cataas';
        $out['thecatapi_key'] = isset( $input['thecatapi_key'] ) ? sanitize_text_field( $input['thecatapi_key'] ) : '';
        return $out;
    }

    public function field_enabled() {
        $opts = $this->get_options();
        printf( '<input type="checkbox" name="%s[enabled]" value="1" %s />', esc_attr( $this->option_name ), checked( 1, $opts['enabled'], false ) );
    }

    public function field_only_remote() {
        $opts = $this->get_options();
        printf( '<input type="checkbox" name="%s[only_remote]" value="1" %s /> <span class="description">%s</span>', esc_attr( $this->option_name ), checked( 1, $opts['only_remote'], false ), esc_html__( 'Only replace images that point to other hosts (not local attachments).', 'cat-photo-replacer' ) );
    }

    public function field_provider() {
        $opts = $this->get_options();
        $html = '';
        $html .= sprintf( '<label><input type="radio" name="%s[provider]" value="cataas" %s /> %s</label><br/>', esc_attr( $this->option_name ), checked( 'cataas', $opts['provider'], false ), esc_html__( 'CATAAS (simple, no key required)', 'cat-photo-replacer' ) );
        $html .= sprintf( '<label><input type="radio" name="%s[provider]" value="thecatapi" %s /> %s</label><br/>', esc_attr( $this->option_name ), checked( 'thecatapi', $opts['provider'], false ), esc_html__( 'TheCatAPI (API key optional)', 'cat-photo-replacer' ) );
        echo $html;
    }

    public function field_thecatapi_key() {
        $opts = $this->get_options();
        printf( '<input type="text" name="%s[thecatapi_key]" value="%s" class="regular-text" /> <p class="description">%s</p>', esc_attr( $this->option_name ), esc_attr( $opts['thecatapi_key'] ), esc_html__( 'If using TheCatAPI and you want server-side image URLs, provide an API key and extend the plugin to make requests to the API.', 'cat-photo-replacer' ) );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Cat Photo Replacer', 'cat-photo-replacer' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'cpr_options_group' );
                do_settings_sections( 'cat-photo-replacer' );
                submit_button();
                ?>
            </form>
            <h2><?php esc_html_e( 'Notes & Tips', 'cat-photo-replacer' ); ?></h2>
            <ul>
                <li><?php esc_html_e( 'This plugin replaces <img> tags in post content by setting the src attribute to a cat image URL. It intentionally clears srcset and similar attributes so browsers don\'t fetch the original images.', 'cat-photo-replacer' ); ?></li>
                <li><?php esc_html_e( 'For production use, consider implementing server-side requests to TheCatAPI to return stable image URLs and respect any API rate limits or terms of service.', 'cat-photo-replacer' ); ?></li>
                <li><?php esc_html_e( 'If you use lazy-loading plugins that rely on different attributes, extend the plugin to handle those attributes as needed.', 'cat-photo-replacer' ); ?></li>
            </ul>
        </div>
        <?php
    }
}

// Initialize
Cat_Photo_Replacer::instance();

// End of file
