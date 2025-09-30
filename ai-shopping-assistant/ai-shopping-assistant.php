<?php
/**
 * Plugin Name: AI Shopping Assistant
 * Description: Chatbot-style personalized product recommendations. Works with OpenAI (or similar) and optionally integrates product context from WooCommerce.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: ai-shopping-assistant
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AI_Shopping_Assistant {
    const VERSION = '1.0.0';
    const OPTION_KEY = 'ai_shopping_assistant_options';
    const NONCE_ACTION = 'ai_shopping_assistant_nonce_action';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        // Activation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Shortcode for embedding chat anywhere
        add_shortcode('ai_shopping_assistant', array($this, 'render_chat_shortcode'));
    }

    public function activate() {
        // set defaults if not present
        $defaults = array(
            'provider' => 'openai',
            'openai_api_key' => '',
            'model' => 'gpt-4o-mini', // example default; user can change
            'enable_wc_context' => 1,
            'max_tokens' => 350,
            'cache_minutes' => 10,
        );
        if (!get_option(self::OPTION_KEY)) {
            update_option(self::OPTION_KEY, $defaults);
        }
    }

    public function deactivate() {
        // nothing critical
    }

    public function register_rest_routes() {
        register_rest_route('ai-shopping-assistant/v1', '/chat', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_chat_request'),
            'permission_callback' => '__return_true',
        ));
    }

    public function add_admin_menu() {
        add_options_page(
            'AI Shopping Assistant',
            'AI Shopping Assistant',
            'manage_options',
            'ai-shopping-assistant',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, array($this, 'validate_options'));
    }

    public function validate_options($input) {
        $out = get_option(self::OPTION_KEY, array());

        $out['provider'] = sanitize_text_field($input['provider'] ?? $out['provider'] ?? 'openai');
        $out['openai_api_key'] = sanitize_text_field($input['openai_api_key'] ?? $out['openai_api_key'] ?? '');
        $out['model'] = sanitize_text_field($input['model'] ?? $out['model'] ?? 'gpt-4o-mini');
        $out['enable_wc_context'] = !empty($input['enable_wc_context']) ? 1 : 0;
        $out['max_tokens'] = intval($input['max_tokens'] ?? $out['max_tokens'] ?? 350);
        $out['cache_minutes'] = intval($input['cache_minutes'] ?? $out['cache_minutes'] ?? 10);

        return $out;
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options = get_option(self::OPTION_KEY, array());
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="wrap">
            <h1>AI Shopping Assistant Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_KEY);
                do_settings_sections(self::OPTION_KEY);
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="provider">AI Provider</label></th>
                        <td>
                            <select id="provider" name="<?php echo esc_attr(self::OPTION_KEY); ?>[provider]">
                                <option value="openai" <?php selected($options['provider'] ?? '', 'openai'); ?>>OpenAI</option>
                                <option value="custom" <?php selected($options['provider'] ?? '', 'custom'); ?>>Custom Endpoint</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_api_key">OpenAI API Key</label></th>
                        <td>
                            <input id="openai_api_key" name="<?php echo esc_attr(self::OPTION_KEY); ?>[openai_api_key]" value="<?php echo esc_attr($options['openai_api_key'] ?? ''); ?>" class="regular-text" />
                            <p class="description">Store your OpenAI API key here (server-side). If using another provider, leave blank and choose custom endpoint in code.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="model">Model</label></th>
                        <td>
                            <input id="model" name="<?php echo esc_attr(self::OPTION_KEY); ?>[model]" value="<?php echo esc_attr($options['model'] ?? 'gpt-4o-mini'); ?>" class="regular-text" />
                            <p class="description">Model name (provider-specific).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WooCommerce product context</th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_wc_context]" value="1" <?php checked($options['enable_wc_context'] ?? 1, 1); ?>/> Include WooCommerce products as context for better recommendations (if WooCommerce active)</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_tokens">Max tokens (server request)</label></th>
                        <td>
                            <input id="max_tokens" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_tokens]" value="<?php echo esc_attr($options['max_tokens'] ?? 350); ?>" class="small-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cache_minutes">Cache minutes</label></th>
                        <td>
                            <input id="cache_minutes" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cache_minutes]" value="<?php echo esc_attr($options['cache_minutes'] ?? 10); ?>" class="small-text" />
                            <p class="description">Cache repeated identical queries for this many minutes to reduce API calls.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
            <h2>Usage</h2>
            <p>Place the chat on any page via the shortcode <code>[ai_shopping_assistant]</code>, or allow it to be enqueued globally so it appears site-wide.</p>
        </div>
        <?php
    }

    public function enqueue_scripts() {
        // only enqueue if not in admin
        wp_register_style('ai-shopping-assistant-css', plugins_url('assets/css/ai-shopping-assistant.css', __FILE__));
        wp_enqueue_style('ai-shopping-assistant-css');

        wp_register_script('ai-shopping-assistant-js', plugins_url('assets/js/ai-shopping-assistant.js', __FILE__), array('jquery'), self::VERSION, true);

        $options = get_option(self::OPTION_KEY, array());
        $rest_url = rest_url('ai-shopping-assistant/v1/chat');

        $data = array(
            'rest_url' => $rest_url,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'options' => $options,
        );

        wp_localize_script('ai-shopping-assistant-js', 'AI_SHOPPING_ASSISTANT', $data);
        wp_enqueue_script('ai-shopping-assistant-js');
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_ai-shopping-assistant') return;
        wp_enqueue_script('ai-shopping-assistant-admin', plugins_url('assets/js/admin.js', __FILE__), array('jquery'), self::VERSION, true);
    }

    public function render_chat_shortcode($atts = array()) {
        // Simple wrapper; the JS will mount UI
        $atts = shortcode_atts(array(
            'title' => 'Shopping Assistant',
        ), $atts, 'ai_shopping_assistant');

        ob_start();
        ?>
        <div class="ai-shopping-assistant-widget" id="ai-shopping-assistant-root" data-title="<?php echo esc_attr($atts['title']); ?>">
            <!-- JS will fill -->
            <button class="asa-toggle" aria-expanded="false">Chat</button>
            <div class="asa-panel" hidden>
                <div class="asa-header">
                    <strong><?php echo esc_html($atts['title']); ?></strong>
                </div>
                <div class="asa-messages" role="log" aria-live="polite"></div>
                <form class="asa-form">
                    <input type="text" name="message" placeholder="Ask about a product, budget, use case..." required />
                    <button type="submit">Send</button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_chat_request(WP_REST_Request $request) {
        // Basic permission: allow for any visitor, but implement rate limiting by IP.
        $params = $request->get_json_params();
        $message = sanitize_text_field($params['message'] ?? '');
        $nonce = $params['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new WP_REST_Response(array('error' => 'Invalid nonce'), 403);
        }
        if (empty($message)) {
            return new WP_REST_Response(array('error' => 'Empty message'), 400);
        }

        // Simple rate limiting by IP using transients
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'asa_rate_' . md5($ip);
        $count = intval(get_transient($key) ?? 0);
        if ($count > 50) { // arbitrary cap
            return new WP_REST_Response(array('error' => 'Rate limit exceeded'), 429);
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS * 60); // keep for 60 minutes

        // Build context: if WooCommerce active and enabled
        $options = get_option(self::OPTION_KEY, array());
        $context_text = '';
        if (!empty($options['enable_wc_context']) && in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true)) {
            $context_text = $this->build_woocommerce_context();
        }

        // Optional caching to reduce APICalls for identical queries
        $cache_minutes = intval($options['cache_minutes'] ?? 10);
        $cache_key = 'asa_cache_' . md5($message . $context_text . ($options['model'] ?? ''));
        $cached = get_transient($cache_key);
        if ($cached) {
            return rest_ensure_response(array('ok' => true, 'cached' => true, 'response' => $cached));
        }

        // Compose prompt for the AI
        $prompt = $this->compose_prompt($message, $context_text);

        // Call provider (OpenAI example)
        $provider = $options['provider'] ?? 'openai';
        if ($provider === 'openai') {
            $resp = $this->call_openai($prompt, $options);
        } else {
            // Placeholder: if custom provider you'd implement here
            $resp = array('error' => 'Provider not configured');
        }

        if (is_wp_error($resp)) {
            return new WP_REST_Response(array('error' => $resp->get_error_message()), 500);
        }

        // Cache response
        if (!empty($resp['response'])) {
            set_transient($cache_key, $resp['response'], MINUTE_IN_SECONDS * $cache_minutes);
        }

        return rest_ensure_response(array('ok' => true, 'cached' => false, 'response' => $resp['response'], 'raw' => $resp['raw'] ?? null));
    }

    private function build_woocommerce_context() {
        if (!class_exists('WooCommerce')) {
            return '';
        }
        // Pull a small set of bestsellers / top products to provide to AI
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 6,
            'meta_key' => 'total_sales',
            'orderby' => 'meta_value_num',
        );
        $products = get_posts($args);
        $lines = array();
        foreach ($products as $p) {
            $prod = wc_get_product($p->ID);
            if (!$prod) continue;
            $lines[] = sprintf("- %s (ID:%d) - %s - $%s", $prod->get_name(), $p->ID, substr(wp_strip_all_tags($prod->get_short_description() ?: $prod->get_description()), 0, 120), $prod->get_price());
        }
        if (empty($lines)) return '';
        return "Site top products:\n" . implode("\n", $lines) . "\n\n";
    }

    private function compose_prompt($message, $context_text = '') {
        // Keep prompt clear: system instruction + context + user message
        $system = "You are a friendly shopping assistant. Provide personalized product recommendations. Use provided site product context if available. For each recommendation include: product name, short reason, approximate price, product page URL if available, and a confidence rating (low/medium/high). Don't hallucinate product URLs—if you don't know a URL, say 'link unavailable'.";
        $prompt = $system . "\n\nContext:\n" . $context_text . "\nUser message:\n" . $message;
        return $prompt;
    }

    private function call_openai($prompt, $options) {
        $api_key = trim($options['openai_api_key'] ?? '');
        if (empty($api_key)) {
            return new WP_Error('missing_key', 'OpenAI API key not set in plugin settings');
        }

        // Build request. This example uses OpenAI Chat Completions endpoint; adapt model and fields as needed.
        $model = sanitize_text_field($options['model'] ?? 'gpt-4o-mini');
        $max_tokens = intval($options['max_tokens'] ?? 350);

        $body = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => "You are a shopping assistant."),
                array('role' => 'user', 'content' => $prompt),
            ),
            'max_tokens' => $max_tokens,
        );

        $args = array(
            'body' => wp_json_encode($body),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'timeout' => 30,
        );

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('api_error', 'AI provider returned HTTP ' . $code . ': ' . $body);
        }

        $data = json_decode($body, true);
        // Extract text safely
        $content = '';
        if (!empty($data['choices'][0]['message']['content'])) {
            $content = $data['choices'][0]['message']['content'];
        } elseif (!empty($data['choices'][0]['text'])) {
            $content = $data['choices'][0]['text'];
        }

        return array('raw' => $data, 'response' => $content);
    }
}

AI_Shopping_Assistant::instance();
