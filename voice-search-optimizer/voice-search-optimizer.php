<?php
/**
 * Plugin Name: Voice Search Optimizer
 * Description: Analyze a WordPress site for voice-search SEO readiness and generate actionable recommendations.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: voice-search-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'VSO_Plugin' ) ) :

class VSO_Plugin {
    private static $instance = null;
    const VERSION = '1.0.0';
    const MENU_SLUG = 'voice-search-optimizer';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_vso_run_scan', array( $this, 'ajax_run_scan' ) );
        add_action( 'init', array( $this, 'load_textdomain' ) );
    }

    public function activate() {
        // Capability for role management if needed in future
        $role = get_role( 'administrator' );
        if ( $role && ! $role->has_cap( 'manage_vso' ) ) {
            $role->add_cap( 'manage_vso' );
        }
    }

    public function deactivate() {
        $role = get_role( 'administrator' );
        if ( $role && $role->has_cap( 'manage_vso' ) ) {
            $role->remove_cap( 'manage_vso' );
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'voice-search-optimizer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function admin_menu() {
        add_menu_page(
            __( 'Voice Search Optimizer', 'voice-search-optimizer' ),
            __( 'Voice Search', 'voice-search-optimizer' ),
            'manage_vso',
            self::MENU_SLUG,
            array( $this, 'admin_page' ),
            'dashicons-microphone',
            80
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }

        wp_enqueue_style( 'vso-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
        wp_enqueue_script( 'vso-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), self::VERSION, true );
        wp_localize_script( 'vso-admin', 'vso_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'vso-scan' ),
        ) );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Voice Search Optimizer', 'voice-search-optimizer' ); ?></h1>
            <p><?php esc_html_e( 'Run checks to measure how voice-search friendly your site is and get recommendations to improve discoverability by voice assistants.', 'voice-search-optimizer' ); ?></p>

            <button id="vso-run-scan" class="button button-primary"><?php esc_html_e( 'Run Site Scan', 'voice-search-optimizer' ); ?></button>
            <span id="vso-scan-spinner" style="display:none; margin-left:10px;"><?php esc_html_e( 'Scanning...', 'voice-search-optimizer' ); ?></span>

            <div id="vso-results" style="margin-top:20px;"></div>

            <h2><?php esc_html_e( 'About', 'voice-search-optimizer' ); ?></h2>
            <p><?php esc_html_e( 'This plugin performs a set of heuristic checks for voice search readiness:', 'voice-search-optimizer' ); ?></p>
            <ul>
                <li><?php esc_html_e( 'Structured data (JSON-LD, FAQ, QAPage)', 'voice-search-optimizer' ); ?></li>
                <li><?php esc_html_e( 'Question-style content (headings and sentences that are queries)', 'voice-search-optimizer' ); ?></li>
                <li><?php esc_html_e( 'Mobile and HTTPS checks', 'voice-search-optimizer' ); ?></li>
                <li><?php esc_html_e( 'Presence of XML sitemap & robots.txt', 'voice-search-optimizer' ); ?></li>
                <li><?php esc_html_e( 'Image alt attributes and concise meta descriptions', 'voice-search-optimizer' ); ?></li>
            </ul>
        </div>
        <?php
    }

    public function ajax_run_scan() {
        if ( ! current_user_can( 'manage_vso' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'voice-search-optimizer' ) ) );
        }
        check_ajax_referer( 'vso-scan', 'nonce' );

        $report = $this->run_site_scan();
        wp_send_json_success( $report );
    }

    private function run_site_scan() {
        $report = array();

        // Site-level checks
        $report['https'] = $this->check_https();
        $report['mobile_responsive'] = $this->check_mobile_responsive();
        $report['sitemap'] = $this->check_sitemap();
        $report['robots'] = $this->check_robots();

        // Content checks (sample recent posts)
        $report['content_checks'] = $this->analyze_content_samples();

        // Structured data checks
        $report['structured_data'] = $this->check_structured_data_on_posts();

        // Images & alt text summary
        $report['images'] = $this->check_images_alt_text();

        // Summary score
        $report['score'] = $this->compute_score( $report );

        $report['generated_at'] = current_time( 'mysql' );

        return $report;
    }

    private function compute_score( $report ) {
        // simple weighted scoring
        $score = 0;
        $score += $report['https'] ? 20 : 0;
        $score += $report['mobile_responsive'] ? 20 : 0;
        $score += $report['sitemap'] ? 10 : 0;
        $score += $report['robots'] ? 5 : 0;

        // content checks: average of sample scores
        $content_avg = 0;
        if ( ! empty( $report['content_checks'] ) ) {
            $sum = 0;
            foreach ( $report['content_checks'] as $c ) {
                $sum += isset( $c['score'] ) ? $c['score'] : 0;
            }
            $content_avg = $sum / max(1, count( $report['content_checks'] ));
        }
        $score += round( $content_avg * 0.35 ); // scale

        // structured data checks
        $sd_score = 0;
        if ( ! empty( $report['structured_data'] ) ) {
            $sd_score = min( 20, 5 * array_sum( array_map( function( $x ) { return $x ? 1 : 0; }, $report['structured_data'] ) ) );
        }
        $score += $sd_score;

        return min( 100, round( $score ) );
    }

    private function check_https() {
        return is_ssl() || 0 === strpos( home_url(), 'https' );
    }

    private function check_mobile_responsive() {
        // Best-effort: check theme supports responsive meta tag or viewport
        // Try to fetch the home page and look for viewport meta
        $home = home_url( '/' );
        $resp = wp_remote_get( $home, array( 'timeout' => 10 ) );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $resp );
        if ( strpos( $body, 'name="viewport"' ) !== false || strpos( $body, 'name=\"viewport\"' ) !== false ) {
            return true;
        }
        return false;
    }

    private function check_sitemap() {
        // Common sitemap locations
        $candidates = array( home_url( '/sitemap.xml' ), home_url( '/sitemap_index.xml' ), home_url( '/sitemap' ) );
        foreach ( $candidates as $url ) {
            $resp = wp_remote_head( $url, array( 'timeout' => 6 ) );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) == 200 ) {
                return $url;
            }
        }
        return false;
    }

    private function check_robots() {
        $url = home_url( '/robots.txt' );
        $resp = wp_remote_get( $url, array( 'timeout' => 6 ) );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            return false;
        }
        $body = wp_remote_retrieve_body( $resp );
        return ! empty( $body );
    }

    private function analyze_content_samples() {
        // Sample most recent 20 public posts and pages
        $args = array(
            'post_type' => array( 'post', 'page' ),
            'post_status' => 'publish',
            'posts_per_page' => 20,
        );
        $q = new WP_Query( $args );
        $results = array();
        if ( $q->have_posts() ) {
            while ( $q->have_posts() ) {
                $q->the_post();
                $post_id = get_the_ID();
                $content = wp_strip_all_tags( get_the_content( null, false, $post_id ) );
                $title = get_the_title( $post_id );

                $check = array();
                $check['post_id'] = $post_id;
                $check['title'] = $title;

                // Check for question headings (h1-h3)
                $questions_in_headings = $this->count_question_headings( $post_id );
                $check['question_headings'] = $questions_in_headings;

                // Check for question sentences in first 300 words
                $check['questions_in_content'] = $this->count_question_sentences( $content, 300 );

                // Meta description presence
                $meta = $this->get_meta_description( $post_id );
                $check['meta_description'] = ! empty( $meta );

                // Average readability (very simple estimate: avg sentence length)
                $check['avg_sentence_length'] = $this->avg_sentence_length( $content );

                // Score per-content: heuristics
                $score = 0;
                if ( $check['question_headings'] > 0 ) $score += 25;
                if ( $check['questions_in_content'] > 0 ) $score += 25;
                if ( $check['meta_description'] ) $score += 20;
                // prefer short sentences for voice: average sentence length < 20 -> good
                if ( $check['avg_sentence_length'] > 0 && $check['avg_sentence_length'] < 20 ) $score += 30;
                $check['score'] = min( 100, $score );

                $results[] = $check;
            }
            wp_reset_postdata();
        }
        return $results;
    }

    private function count_question_headings( $post_id ) {
        $content = get_post_field( 'post_content', $post_id );
        if ( ! $content ) return 0;
        // parse headings from post_content
        $count = 0;
        if ( preg_match_all( '/<h([1-3])[^>]*>(.*?)<\/h\1>/is', $content, $matches ) ) {
            foreach ( $matches[2] as $heading ) {
                if ( strpos( $heading, '?' ) !== false ) $count++;
            }
        }
        return $count;
    }

    private function count_question_sentences( $text, $max_words = 300 ) {
        if ( empty( $text ) ) return 0;
        $words = preg_split( '/\s+/', $text );
        $slice = array_slice( $words, 0, $max_words );
        $short = implode( ' ', $slice );
        // split into sentences simple
        $sentences = preg_split( '/(?<=[.!?])\s+/', $short );
        $count = 0;
        foreach ( $sentences as $s ) {
            if ( strpos( $s, '?' ) !== false ) $count++;
        }
        return $count;
    }

    private function get_meta_description( $post_id ) {
        // Common SEO plugins store meta description in post meta (Yoast: _yoast_wpseo_metadesc)
        $candidates = array( '_yoast_wpseo_metadesc', '_aioseo_description', 'meta_description' );
        foreach ( $candidates as $key ) {
            $v = get_post_meta( $post_id, $key, true );
            if ( ! empty( $v ) ) return $v;
        }
        // Try core excerpt
        $excerpt = get_post_field( 'post_excerpt', $post_id );
        if ( ! empty( $excerpt ) ) return $excerpt;
        return false;
    }

    private function avg_sentence_length( $text ) {
        if ( empty( $text ) ) return 0;
        $sentences = preg_split( '/(?<=[.!?])\s+/', $text );
        $total_words = 0;
        $num = 0;
        foreach ( $sentences as $s ) {
            $s = trim( strip_tags( $s ) );
            if ( empty( $s ) ) continue;
            $num++;
            $total_words += str_word_count( $s );
        }
        if ( $num === 0 ) return 0;
        return round( $total_words / $num, 1 );
    }

    private function check_structured_data_on_posts() {
        // Check whether posts/pages output JSON-LD in their content or via filters
        // We'll sample most recent 20 posts and look for <script type="application/ld+json">
        $args = array(
            'post_type' => array( 'post', 'page' ),
            'post_status' => 'publish',
            'posts_per_page' => 20,
        );
        $q = new WP_Query( $args );
        $results = array(
            'json_ld_present' => 0,
            'faq_schema_present' => 0,
            'qapage_present' => 0,
            'count' => 0,
        );
        if ( $q->have_posts() ) {
            while ( $q->have_posts() ) {
                $q->the_post();
                $results['count']++;
                $post_id = get_the_ID();
                $content = get_post_field( 'post_content', $post_id );
                if ( strpos( $content, '<script type="application/ld+json"' ) !== false || strpos( $content, "application/ld+json" ) !== false ) {
                    $results['json_ld_present']++;
                    // crude checks for FAQ or QAPage in JSON-LD
                    if ( stripos( $content, 'FAQPage' ) !== false || stripos( $content, 'faqpage' ) !== false ) $results['faq_schema_present']++;
                    if ( stripos( $content, 'QAPage' ) !== false ) $results['qapage_present']++;
                } else {
                    // Some themes/plugins add JSON-LD in head. Try to fetch the single page and inspect.
                    $permalink = get_permalink( $post_id );
                    $resp = wp_remote_get( $permalink, array( 'timeout' => 6 ) );
                    if ( ! is_wp_error( $resp ) ) {
                        $body = wp_remote_retrieve_body( $resp );
                        if ( stripos( $body, 'application/ld+json' ) !== false ) {
                            $results['json_ld_present']++;
                            if ( stripos( $body, 'FAQPage' ) !== false ) $results['faq_schema_present']++;
                            if ( stripos( $body, 'QAPage' ) !== false ) $results['qapage_present']++;
                        }
                    }
                }
            }
            wp_reset_postdata();
        }
        return $results;
    }

    private function check_images_alt_text() {
        global $wpdb;
        // count images used in recent posts and how many have alt text
        $query = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') ORDER BY post_date DESC LIMIT 50";
        $rows = $wpdb->get_results( $query );
        $total_images = 0;
        $images_with_alt = 0;
        foreach ( $rows as $r ) {
            if ( preg_match_all( '/<img[^>]+>/i', $r->post_content, $matches ) ) {
                foreach ( $matches[0] as $img ) {
                    $total_images++;
                    if ( preg_match( '/alt=[\"\']([^\"\']*)[\"\']/', $img ) ) {
                        $images_with_alt++;
                    }
                }
            }
        }
        return array(
            'checked_posts' => count( $rows ),
            'total_images' => $total_images,
            'images_with_alt' => $images_with_alt,
            'percent_with_alt' => $total_images > 0 ? round( ( $images_with_alt / $total_images ) * 100, 1 ) : 0,
        );
    }
}

// Initialize plugin
VSO_Plugin::instance();





?>
