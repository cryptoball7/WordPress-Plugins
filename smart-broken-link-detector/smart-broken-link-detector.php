<?php
/*
Plugin Name: Smart Broken Link Detector
Description: Scans site content for broken links and suggests relevant replacement links by searching your site and (optionally) an external web-search API. Includes one-click replace.
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
Text Domain: smart-broken-link-detector
*/

if (!defined('ABSPATH')) {
    exit;
}

class SmartBrokenLinkDetector {
    const OPTION_KEY = 'sbl_detector_results';
    const CRON_HOOK = 'sbl_detector_daily_scan';
    private $cap = 'manage_options';
    private $scan_limit = 300; // max links to check per manual scan to avoid timeouts

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_sbl_run_scan', [$this, 'ajax_run_scan']);
        add_action('wp_ajax_sbl_replace_link', [$this, 'ajax_replace_link']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'maybe_schedule_cron']);
        add_action(self::CRON_HOOK, [$this, 'cron_scan']);
    }

    public function admin_menu() {
        add_management_page(
            __('Smart Broken Link Detector', 'smart-broken-link-detector'),
            __('Smart Broken Link Detector', 'smart-broken-link-detector'),
            $this->cap,
            'sbl-detector',
            [$this, 'admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_sbl-detector') return;
        wp_enqueue_style('sbl-admin-css', plugins_url('assets/sbl-admin.css', __FILE__));
        wp_enqueue_script('sbl-admin-js', plugins_url('assets/sbl-admin.js', __FILE__), ['jquery'], false, true);
        wp_localize_script('sbl-admin-js', 'SBL_Ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sbl_nonce'),
            'scan_limit' => $this->scan_limit,
        ]);
    }

    public function register_settings() {
        register_setting('sbl_settings_group', 'sbl_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => [
                'enable_cron' => '0',
                'external_search_api' => '',
                'external_search_endpoint' => '',
                'external_search_enabled' => '0',
            ]
        ]);

        add_settings_section('sbl_main_section', __('Settings', 'smart-broken-link-detector'), null, 'sbl-detector');

        add_settings_field('sbl_enable_cron', __('Enable daily scan (WP-Cron)', 'smart-broken-link-detector'),
            function() {
                $opts = get_option('sbl_settings', []);
                $v = isset($opts['enable_cron']) ? $opts['enable_cron'] : '0';
                echo '<label><input type="checkbox" name="sbl_settings[enable_cron]" value="1" '.checked(1, intval($v), false).' /> ' . __('Run a daily automatic scan', 'smart-broken-link-detector') . '</label>';
            },
            'sbl-detector', 'sbl_main_section');

        add_settings_field('sbl_external_search', __('External Web Search (optional)', 'smart-broken-link-detector'),
            function() {
                $opts = get_option('sbl_settings', []);
                $enabled = isset($opts['external_search_enabled']) ? $opts['external_search_enabled'] : '0';
                $apiKey = isset($opts['external_search_api']) ? esc_attr($opts['external_search_api']) : '';
                $endpoint = isset($opts['external_search_endpoint']) ? esc_attr($opts['external_search_endpoint']) : '';
                echo '<label><input type="checkbox" name="sbl_settings[external_search_enabled]" value="1" '.checked(1, intval($enabled), false).' /> ' . __('Enable external web search', 'smart-broken-link-detector') . '</label><br/>';
                echo '<p class="description">Provide an API key and endpoint for an external search engine (e.g., Bing Web Search or Google CSE). The plugin will call your endpoint with the '\''q'\'' query param and your key in headers or a query param - implement per your provider.</p>';
                echo '<p><strong>API Key</strong><br/><input type="text" name="sbl_settings[external_search_api]" value="'.$apiKey.'" style="width:100%"/></p>';
                echo '<p><strong>Endpoint</strong><br/><input type="text" name="sbl_settings[external_search_endpoint]" value="'.$endpoint.'" style="width:100%"/></p>';
            }, 'sbl-detector', 'sbl_main_section');
    }

    public function sanitize_settings($input) {
        $out = [];
        $out['enable_cron'] = isset($input['enable_cron']) && $input['enable_cron'] ? '1' : '0';
        $out['external_search_enabled'] = isset($input['external_search_enabled']) && $input['external_search_enabled'] ? '1' : '0';
        $out['external_search_api'] = isset($input['external_search_api']) ? sanitize_text_field($input['external_search_api']) : '';
        $out['external_search_endpoint'] = isset($input['external_search_endpoint']) ? esc_url_raw($input['external_search_endpoint']) : '';
        return $out;
    }

    public function admin_page() {
        if (!current_user_can($this->cap)) {
            wp_die(__('Insufficient permissions', 'smart-broken-link-detector'));
        }

        $results = get_option(self::OPTION_KEY, []);
        ?>
        <div class="wrap">
            <h1><?php _e('Smart Broken Link Detector', 'smart-broken-link-detector'); ?></h1>

            <h2><?php _e('Quick Scan', 'smart-broken-link-detector'); ?></h2>
            <p><?php _e('Click "Run scan" to detect broken links in posts and pages. The scanner checks up to a limited number of links to avoid timeouts. Use the settings below to configure automatic scans and external search.', 'smart-broken-link-detector'); ?></p>

            <p><button id="sbl-run-scan" class="button button-primary"><?php _e('Run scan', 'smart-broken-link-detector'); ?></button>
            <span id="sbl-scan-status"></span></p>

            <h2><?php _e('Results', 'smart-broken-link-detector'); ?></h2>

            <div id="sbl-results">
                <?php $this->render_results($results); ?>
            </div>

            <h2><?php _e('Settings', 'smart-broken-link-detector'); ?></h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('sbl_settings_group');
                do_settings_sections('sbl-detector');
                submit_button();
                ?>
            </form>

            <h2><?php _e('Notes & Limitations', 'smart-broken-link-detector'); ?></h2>
            <ul>
                <li><?php _e('HTTP checks use HEAD first, fall back to GET. Some sites block HEAD or throttle requests.', 'smart-broken-link-detector'); ?></li>
                <li><?php _e('External search requires you to supply an API endpoint & key; the plugin will pass the query as "q" by default and send the key via "Ocp-Apim-Subscription-Key" header if provided, but you may need to adapt based on your provider.', 'smart-broken-link-detector'); ?></li>
                <li><?php _e('Automatic one-click replace modifies post content. Always backup your site or test on staging.', 'smart-broken-link-detector'); ?></li>
            </ul>
        </div>
        <?php
    }

    private function render_results($results) {
        if (empty($results) || empty($results['broken'])) {
            echo '<p><em>' . __('No broken links found (last scan).', 'smart-broken-link-detector') . '</em></p>';
            return;
        }

        echo '<table class="widefat fixed" cellspacing="0"><thead><tr><th>Post</th><th>Broken URL</th><th>Anchor Text</th><th>Status</th><th>Suggestions</th><th>Action</th></tr></thead><tbody>';
        foreach ($results['broken'] as $item) {
            $post_id = intval($item['post_id']);
            $post_title = get_the_title($post_id) ?: sprintf(__('(ID %d)', 'smart-broken-link-detector'), $post_id);
            $edit_link = get_edit_post_link($post_id);
            $broken_url = esc_url($item['url']);
            $anchor = esc_html($item['anchor']);
            $status = esc_html($item['status']);
            $suggestions = isset($item['suggestions']) ? $item['suggestions'] : [];
            echo '<tr>';
            echo '<td><a href="'.esc_url($edit_link).'" target="_blank">'.esc_html($post_title).'</a></td>';
            echo '<td><a href="'.esc_url($broken_url).'" target="_blank">'.esc_html($broken_url).'</a></td>';
            echo '<td>'.( $anchor ?: '<em>(no anchor)</em>' ).'</td>';
            echo '<td>'.$status.'</td>';
            echo '<td>';
            if (empty($suggestions)) {
                echo '<em>No suggestions</em>';
            } else {
                echo '<ol>';
                foreach ($suggestions as $s) {
                    $label = isset($s['title']) ? esc_html($s['title']) : esc_html($s['url']);
                    $url   = esc_url($s['url']);
                    echo '<li><a href="'. $url .'" target="_blank">'. $label .'</a></li>';
                }
                echo '</ol>';
            }
            echo '</td>';
            echo '<td>';
            echo '<button class="button sbl-replace" data-post="'.esc_attr($post_id).'" data-broken="'.esc_attr($broken_url).'">'.__('Replace', 'smart-broken-link-detector').'</button>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // AJAX: run a scan
    public function ajax_run_scan() {
        check_ajax_referer('sbl_nonce', 'nonce');
        if (!current_user_can($this->cap)) {
            wp_send_json_error('insufficient_permissions', 403);
        }

        // Run scan with time limit and limit number of links
        $results = $this->run_scan($this->scan_limit);
        update_option(self::OPTION_KEY, $results);
        wp_send_json_success($results);
    }

    // AJAX: replace broken link in post content with a chosen suggested URL
    public function ajax_replace_link() {
        check_ajax_referer('sbl_nonce', 'nonce');
        if (!current_user_can($this->cap)) {
            wp_send_json_error('insufficient_permissions', 403);
        }
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $broken_url = isset($_POST['broken_url']) ? esc_url_raw($_POST['broken_url']) : '';
        $replacement = isset($_POST['replacement']) ? esc_url_raw($_POST['replacement']) : '';

        if (!$post_id || !$broken_url || !$replacement) {
            wp_send_json_error('invalid_parameters', 400);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('post_not_found', 404);
        }

        // Replace all occurrences of broken_url inside post_content; preserve HTML attributes
        $content = $post->post_content;
        // to avoid accidental replacements, do a DOM-based replace by searching anchor href attributes
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            // fallback to string replace
            $new_content = str_replace($broken_url, $replacement, $content);
        } else {
            $changed = false;
            $anchors = $dom->getElementsByTagName('a');
            foreach ($anchors as $a) {
                $href = $a->getAttribute('href');
                if ($href === $broken_url) {
                    $a->setAttribute('href', $replacement);
                    $changed = true;
                }
            }
            if ($changed) {
                $new_content = $dom->saveHTML();
                // strip the xml encoding we injected
                $new_content = preg_replace('/^<\?xml.*?\?>/','', $new_content);
            } else {
                $new_content = $content;
            }
        }

        if ($new_content === $content) {
            wp_send_json_error('no_change', 409);
        }

        $updated = wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content
        ], true);

        if (is_wp_error($updated)) {
            wp_send_json_error('update_failed', 500);
        }

        // After replace, re-run a small scan for that post to update stored results
        $all_results = get_option(self::OPTION_KEY, []);
        $post_results = $this->scan_post_for_links($post);
        // Replace or add broken items for this post
        $all_results['broken'] = array_filter($all_results['broken'] ?? [], function($i) use ($post_id) {
            return intval($i['post_id']) !== $post_id;
        });
        foreach ($post_results['broken'] as $b) {
            $all_results['broken'][] = $b;
        }
        update_option(self::OPTION_KEY, $all_results);

        wp_send_json_success(['updated' => true]);
    }

    // Schedule cron if enabled
    public function maybe_schedule_cron() {
        $settings = get_option('sbl_settings', []);
        $enabled = isset($settings['enable_cron']) && $settings['enable_cron'] === '1';
        if ($enabled && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        } elseif (!$enabled && wp_next_scheduled(self::CRON_HOOK)) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    // Cron scan handler
    public function cron_scan() {
        if (!current_user_can($this->cap)) {
            // Cron runs as WP; skip capability check in cron
        }
        $results = $this->run_scan(500); // allow larger in cron
        update_option(self::OPTION_KEY, $results);
    }

    // Full scan orchestration
    public function run_scan($limit = 300) {
        $start = microtime(true);
        $posts = get_posts([
            'post_type' => ['post','page'],
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
        ]);

        $checked = 0;
        $broken = [];

        foreach ($posts as $pid) {
            if ($checked >= $limit) break;
            $post = get_post($pid);
            $res = $this->scan_post_for_links($post);
            foreach ($res['broken'] as $b) {
                $broken[] = $b;
            }
            $checked += count($res['checked']);
            // small sleep to avoid hammering remote hosts if there are many links
            usleep(20000); // 20ms
        }

        $results = [
            'scanned_posts' => count($posts),
            'checked_links' => $checked,
            'broken' => $broken,
            'scanned_at' => gmdate('c'),
            'duration_seconds' => round(microtime(true) - $start, 2),
        ];
        return $results;
    }

    // Scan a single WP_Post object: find links, check their status, make suggestions for broken ones
    private function scan_post_for_links($post) {
        $content = $post->post_content;
        $links = $this->extract_links_from_html($content);

        $checked = [];
        $broken = [];

        foreach ($links as $l) {
            $url = $l['href'];
            $anchor = $l['text'];
            if (empty($url)) continue;

            $status = $this->check_url_status($url);
            $checked[] = [
                'post_id' => $post->ID,
                'url' => $url,
                'anchor' => $anchor,
                'status' => $status,
            ];
            if ($this->is_broken_status($status)) {
                $suggestions = $this->suggest_replacements($url, $anchor, $post);
                $broken[] = [
                    'post_id' => $post->ID,
                    'url' => $url,
                    'anchor' => $anchor,
                    'status' => $status,
                    'suggestions' => $suggestions,
                ];
            }
        }

        return [
            'checked' => $checked,
            'broken' => $broken,
        ];
    }

    // Extract links using DOMDocument
    private function extract_links_from_html($html) {
        $out = [];
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            // fallback: simple regex
            if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m)) {
                foreach ($m[1] as $i => $href) {
                    $text = strip_tags($m[2][$i]);
                    $out[] = ['href' => html_entity_decode($href), 'text' => trim($text)];
                }
            }
            return $out;
        }
        $anchors = $dom->getElementsByTagName('a');
        foreach ($anchors as $a) {
            $href = $a->getAttribute('href');
            $text = trim($a->textContent);
            $out[] = ['href' => $href, 'text' => $text];
        }
        return $out;
    }

    // Check URL status: try HEAD then GET if necessary
    private function check_url_status($url) {
        // If internal link (site URL), do a direct check without remote request
        $site_url = get_site_url();
        if (strpos($url, $site_url) === 0) {
            // map to internal post if possible
            $path = str_replace($site_url, '', $url);
            $path = strtok($path, '#?');
            $page = url_to_postid($url);
            if ($page) return '200 (internal)';
            // else continue to remote check
        }

        $args = [
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'user-agent' => 'SmartBrokenLinkDetector/1.0 (+'.get_site_url().')',
        ];

        // HEAD attempt
        $args_head = $args;
        $args_head['method'] = 'HEAD';
        $resp = wp_remote_request($url, $args_head);
        if (is_wp_error($resp)) {
            // fallback to GET
            $args_get = $args;
            $args_get['method'] = 'GET';
            $resp2 = wp_remote_request($url, $args_get);
            if (is_wp_error($resp2)) {
                return 'error: '.$resp2->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($resp2);
                return (string)$code;
            }
        } else {
            $code = wp_remote_retrieve_response_code($resp);
            if ($code === 405 || $code === 403 || $code === 501) {
                // Some servers forbid HEAD; fallback to GET
                $args_get = $args;
                $args_get['method'] = 'GET';
                $resp2 = wp_remote_request($url, $args_get);
                if (is_wp_error($resp2)) {
                    return 'error: '.$resp2->get_error_message();
                } else {
                    $code2 = wp_remote_retrieve_response_code($resp2);
                    return (string)$code2;
                }
            }
            return (string)$code;
        }
    }

    private function is_broken_status($status) {
        if (strpos($status, 'error:') === 0) return true;
        if (preg_match('/^\d+$/', $status)) {
            $code = intval($status);
            if ($code >= 400) return true;
            return false;
        }
        // treat unknown statuses as broken
        return true;
    }

    // Suggest replacement URLs based on anchor text and optionally external web search
    private function suggest_replacements($broken_url, $anchor_text, $source_post) {
        $candidates = [];

        // 1) Internal site search by anchor text and words from source_post
        $keywords = $this->extract_keywords($anchor_text ?: $source_post->post_title . ' ' . wp_strip_all_tags($source_post->post_excerpt . ' ' . $source_post->post_content));
        if (!empty($keywords)) {
            $query_string = implode(' ', array_slice($keywords, 0, 6)); // limit
            $internal = $this->search_site($query_string, $source_post->ID, 10);
            foreach ($internal as $i) {
                $candidates[] = ['url' => $i['url'], 'title' => $i['title'], 'score' => $i['score'], 'source' => 'internal'];
            }
        }

        // 2) External search (optional)
        $settings = get_option('sbl_settings', []);
        if (!empty($settings['external_search_enabled']) && $settings['external_search_enabled'] === '1' &&
            !empty($settings['external_search_endpoint']) && !empty($settings['external_search_api'])) {
            $q = $anchor_text ?: $source_post->post_title;
            $ext = $this->external_search($q, $settings);
            foreach ($ext as $e) {
                $candidates[] = ['url' => $e['url'], 'title' => $e['title'], 'score' => $e['score'], 'source' => 'external'];
            }
        }

        // Deduplicate & sort by score
        $seen = [];
        usort($candidates, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $out = [];
        foreach ($candidates as $c) {
            $u = esc_url_raw($c['url']);
            if (empty($u) || in_array($u, $seen)) continue;
            // filter out suggestions that equal to broken url
            if ($u === $broken_url) continue;
            $seen[] = $u;
            $out[] = [
                'url' => $u,
                'title' => $c['title'] ?: $u,
                'score' => $c['score'],
                'source' => $c['source'],
            ];
            if (count($out) >= 6) break;
        }

        return $out;
    }

    // Extract simple keywords from text
    private function extract_keywords($text) {
        $text = strtolower(strip_tags($text));
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = preg_split('/\s+/', $text);
        $stop = $this->stopwords();
        $freq = [];
        foreach ($words as $w) {
            if (strlen($w) < 3) continue;
            if (in_array($w, $stop)) continue;
            if (!isset($freq[$w])) $freq[$w] = 0;
            $freq[$w]++;
        }
        arsort($freq);
        return array_keys($freq);
    }

    private function stopwords() {
        // small set of common stopwords; extend as needed
        return ['the','and','for','with','from','that','this','your','you','are','have','was','were','but','not','with','when','where','what'];
    }

    // Search the WP site for posts/pages matching query - returns top results with simple score by word overlap
    private function search_site($query, $exclude_post_id = 0, $limit = 10) {
        $query = trim($query);
        if (empty($query)) return [];

        $args = [
            'post_type' => ['post','page'],
            's' => $query,
            'posts_per_page' => $limit,
            'post_status' => 'publish',
        ];
        $qp = new WP_Query($args);
        $words = $this->extract_keywords($query);
        $results = [];
        foreach ($qp->posts as $p) {
            if ($p->ID == $exclude_post_id) continue;
            $score = $this->compute_match_score($words, $p);
            $results[] = [
                'url' => get_permalink($p),
                'title' => get_the_title($p),
                'score' => $score,
            ];
        }
        usort($results, function($a,$b){return $b['score'] <=> $a['score'];});
        return $results;
    }

    // Compute score of overlap between keywords and post content/title
    private function compute_match_score($keywords, $post) {
        $text = strtolower(get_the_title($post).' '.wp_strip_all_tags($post->post_content));
        $score = 0;
        foreach ($keywords as $i => $w) {
            if (strpos($text, $w) !== false) {
                // stronger weight for earlier keywords
                $score += max(1, 10 - $i);
            }
        }
        return $score;
    }

    // Example external search: you may need to adapt to your provider's expected format
    private function external_search($q, $settings) {
        $endpoint = $settings['external_search_endpoint'];
        $apiKey = $settings['external_search_api'];

        if (empty($endpoint) || empty($apiKey)) return [];

        // Assume the provider accepts ?q=... and a header "Ocp-Apim-Subscription-Key" (Bing style).
        $url = add_query_arg('q', rawurlencode($q), $endpoint);

        $args = [
            'timeout' => 10,
            'headers' => [
                'Ocp-Apim-Subscription-Key' => $apiKey,
                'User-Agent' => 'SmartBrokenLinkDetector/1.0',
            ],
        ];

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) return [];

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $results = [];

        if ($code >= 200 && $code < 300) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                // Try common bucket names
                if (!empty($json['webPages']['value'])) {
                    foreach ($json['webPages']['value'] as $r) {
                        $results[] = [
                            'url' => $r['url'] ?? '',
                            'title' => $r['name'] ?? ($r['title'] ?? ''),
                            'score' => intval($r['rank'] ?? 50),
                        ];
                    }
                } elseif (!empty($json['items'])) { // google cse style
                    foreach ($json['items'] as $r) {
                        $results[] = [
                            'url' => $r['link'] ?? ($r['url'] ?? ''),
                            'title' => $r['title'] ?? '',
                            'score' => intval($r['score'] ?? 50),
                        ];
                    }
                } else {
                    // fallback: try to pull top-level results array
                    foreach ($json as $k => $v) {
                        if (is_array($v)) {
                            foreach ($v as $r) {
                                if (is_array($r) && !empty($r['url'])) {
                                    $results[] = [
                                        'url' => $r['url'],
                                        'title' => $r['title'] ?? '',
                                        'score' => intval($r['rank'] ?? 40),
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
        return $results;
    }
}

new SmartBrokenLinkDetector();

/*
 * Minimal admin JS and CSS embedded below for convenience.
 * In production, you might want to split into separate files and use proper enqueuing.
 */

// Create assets if not present
register_activation_hook(__FILE__, function() {
    // nothing to do on activation currently
});

// Serve inline JS & CSS if separate files not present - create them programmatically
// But we'll also register direct inline content via admin_footer for the admin page
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'tools_page_sbl-detector') {
        ?>
        <style>
        /* Basic admin styling */
        #sbl-results ol { margin: 0 0 0 1.2em; padding: 0; }
        .sbl-suggestion { margin-bottom: .5em; }
        </style>
        <script>
        (function($){
            $('#sbl-run-scan').on('click', function(e){
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#sbl-scan-status').text(' Running...');
                $.post(SBL_Ajax.ajax_url, {
                    action: 'sbl_run_scan',
                    nonce: SBL_Ajax.nonce
                }, function(resp){
                    $btn.prop('disabled', false);
                    if (resp.success) {
                        $('#sbl-results').html(renderResults(resp.data));
                        $('#sbl-scan-status').text(' Done. Scanned: '+resp.data.scanned_posts+' posts; checked links: '+resp.data.checked_links);
                    } else {
                        $('#sbl-scan-status').text(' Error: '+(resp.data || 'unknown'));
                    }
                }).fail(function(xhr){
                    $btn.prop('disabled', false);
                    $('#sbl-scan-status').text(' Request failed.');
                });
            });

            function renderResults(data) {
                if (!data || !data.broken || data.broken.length === 0) {
                    return '<p><em>No broken links found.</em></p>';
                }
                var html = '<table class="widefat fixed" cellspacing="0"><thead><tr><th>Post</th><th>Broken URL</th><th>Anchor</th><th>Status</th><th>Suggestions</th><th>Action</th></tr></thead><tbody>';
                data.broken.forEach(function(item){
                    var title = item.post_title || ('Post ID '+item.post_id);
                    var edit_url = '#';
                    html += '<tr>';
                    html += '<td>' + escapeHtml(title) + '</td>';
                    html += '<td><a href="'+escapeAttr(item.url)+'" target="_blank">'+escapeHtml(item.url)+'</a></td>';
                    html += '<td>' + escapeHtml(item.anchor || '') + '</td>';
                    html += '<td>' + escapeHtml(item.status || '') + '</td>';
                    html += '<td>';
                    if (item.suggestions && item.suggestions.length) {
                        html += '<ol>';
                        item.suggestions.forEach(function(s){
                            html += '<li><a href="'+escapeAttr(s.url)+'" target="_blank">'+escapeHtml(s.title||s.url)+'</a></li>';
                        });
                        html += '</ol>';
                    } else {
                        html += '<em>No suggestions</em>';
                    }
                    html += '</td>';
                    html += '<td>';
                    html += '<button class="button sbl-replace" data-post="'+item.post_id+'" data-broken="'+escapeAttr(item.url)+'">Replace</button>';
                    html += '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                return html;
            }

            // Delegate replace click
            $(document).on('click', '.sbl-replace', function(e){
                e.preventDefault();
                var $b = $(this);
                var post_id = $b.data('post');
                var broken = $b.data('broken');
                // Prompt user to input replacement (we could pick first suggestion automatically, but ask for safety)
                var replacement = prompt('Enter replacement URL (or paste suggestion URL):');
                if (!replacement) return;
                $b.prop('disabled', true);
                $.post(SBL_Ajax.ajax_url, {
                    action: 'sbl_replace_link',
                    nonce: SBL_Ajax.nonce,
                    post_id: post_id,
                    broken_url: broken,
                    replacement: replacement
                }, function(resp){
                    $b.prop('disabled', false);
                    if (resp.success) {
                        alert('Replacement applied. Result list updated.');
                        // refresh display by clicking Run scan again or fetching stored results
                        $('#sbl-run-scan').trigger('click');
                    } else {
                        alert('Replace failed: ' + JSON.stringify(resp.data));
                    }
                }).fail(function(){
                    $b.prop('disabled', false);
                    alert('Request failed.');
                });
            });

            function escapeHtml(s){ if(!s) return ''; return s.replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }
            function escapeAttr(s){ return escapeHtml(s); }

        })(jQuery);
        </script>
        <?php
    }
});
