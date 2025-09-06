<?php
/**
 * Plugin Name: AI-Powered Content Optimizer
 * Description: Suggests SEO improvements, readability tweaks, and keyword density analysis in real time for Gutenberg and Classic editors. (Local analysis — no external API calls.)
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: ai-content-optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Content_Optimizer {
    public function __construct() {
        add_action('init', array($this, 'register_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('add_meta_boxes', array($this, 'add_classic_metabox'));
        add_action('save_post', array($this, 'save_focus_keyword'));
    }

    public function register_meta() {
        register_post_meta('', 'aco_focus_keyword', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() { return current_user_can('edit_posts'); }
        ));
    }

    public function enqueue_admin_assets($hook) {
        // Only load on post edit screens
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) return;

        // Main JS (Gutenberg + Classic support). Uses WP-provided dependencies when available.
        wp_enqueue_script(
            'ai-content-optimizer-js',
            plugins_url('build/ai-content-optimizer.js', __FILE__),
            array('wp-plugins','wp-edit-post','wp-element','wp-data','wp-components','wp-compose'),
            filemtime(plugin_dir_path(__FILE__).'build/ai-content-optimizer.js')
        );

        wp_enqueue_style(
            'ai-content-optimizer-css',
            plugins_url('build/ai-content-optimizer.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__).'build/ai-content-optimizer.css')
        );

        // Localize to pass PHP data
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        wp_localize_script('ai-content-optimizer-js', 'ACOData', array(
            'ajaxNonce' => wp_create_nonce('wp_rest'),
            'postId' => $post_id,
            'strings' => array(
                'focusKeywordLabel' => __('Focus keyword', 'ai-content-optimizer'),
                'suggestionsTitle' => __('Suggestions', 'ai-content-optimizer'),
            ),
        ));
    }

    public function add_classic_metabox() {
        add_meta_box('aco_classic_sidebar', __('AI Content Optimizer', 'ai-content-optimizer'), array($this, 'render_classic_metabox'), 'post', 'side', 'high');
    }

    public function render_classic_metabox($post) {
        $keyword = get_post_meta($post->ID, 'aco_focus_keyword', true);
        ?>
        <p>
            <label for="aco_focus_keyword_field"><?php esc_html_e('Focus keyword', 'ai-content-optimizer'); ?></label>
            <input type="text" name="aco_focus_keyword" id="aco_focus_keyword_field" value="<?php echo esc_attr($keyword); ?>" style="width:100%;" />
        </p>
        <div id="aco_classic_panel"></div>
        <p style="font-size:11px;color:#666;"><?php esc_html_e('Real-time suggestions will appear as you type in the main editor area.', 'ai-content-optimizer'); ?></p>
        <?php
    }

    public function save_focus_keyword($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['aco_focus_keyword'])) {
            $val = sanitize_text_field($_POST['aco_focus_keyword']);
            update_post_meta($post_id, 'aco_focus_keyword', $val);
        }
    }
}

new AI_Content_Optimizer();

// Create build assets if not present: the plugin contains inline fallback that registers assets if real files don't exist.

// Fallback loader: if build files are missing, print inline JS and CSS to keep plugin functional.
add_action('admin_print_footer_scripts', function() {
    $screen = get_current_screen();
    if (!in_array($screen->base, array('post','post-new'))) return;

    $js_file = plugin_dir_path(__FILE__).'build/ai-content-optimizer.js';
    $css_file = plugin_dir_path(__FILE__).'build/ai-content-optimizer.css';

    if (file_exists($css_file)) {
        // rely on enqueued file
    } else {
        // Print small CSS fallback
        ?>
        <style id="aco-inline-css">
        .aco-panel { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        .aco-score { font-size: 14px; font-weight:600; }
        .aco-suggestion { margin:6px 0; padding:8px; background:#fff; border-left:4px solid #0073aa; }
        .aco-badge { display:inline-block; padding:2px 8px; background:#f1f1f1; border-radius:12px; margin-right:6px; font-size:12px; }
        </style>
        <?php
    }

    if (file_exists($js_file)) {
        // rely on enqueued file
    } else {
        // Inline JS fallback providing core functionality for Classic editor only and a light Gutenberg integration if possible.
        ?>
        <script id="aco-inline-js">
        (function(){
            // Utility analysis functions
            function tokenize(text){
                return text.replace(/<[^>]*>/g,'').replace(/[^\w'-]+/g,' ').trim().split(/\s+/).filter(Boolean);
            }

            function wordCount(text){ return tokenize(text).length; }

            function sentenceCount(text){
                var s = text.replace(/<[^>]*>/g,'');
                var matches = s.match(/[.!?]+/g);
                return matches ? matches.length : Math.max(1, s.split(/\n+/).filter(Boolean).length);
            }

            function fleschReadingEase(text){
                var words = tokenize(text).length;
                var sentences = sentenceCount(text);
                var syllables = estimateSyllables(text);
                if (words === 0 || sentences === 0) return 0;
                var ASL = words / sentences; // average sentence length
                var ASW = syllables / words; // average syllables per word
                var score = 206.835 - (1.015 * ASL) - (84.6 * ASW);
                return Math.round(score);
            }

            function estimateSyllables(text){
                var words = tokenize(text);
                var count = 0;
                words.forEach(function(w){
                    w = w.toLowerCase();
                    if (w.length <= 3) { count++; return; }
                    w = w.replace(/(?:[^laeiouy]es|ed|[^laeiouy]e)$/, '');
                    w = w.replace(/^y/, '');
                    var syl = (w.match(/[aeiouy]{1,2}/g) || []).length;
                    count += Math.max(1, syl);
                });
                return count;
            }

            function keywordDensity(text, keyword){
                if (!keyword) return 0;
                var words = tokenize(text).map(function(w){return w.toLowerCase();});
                var kw = keyword.toLowerCase().trim();
                if (!kw) return 0;
                var kwTokens = kw.split(/\s+/);
                var matches = 0;
                for (var i=0;i<words.length;i++){
                    var slice = words.slice(i, i + kwTokens.length).join(' ');
                    if (slice === kw) matches++;
                }
                return (matches / Math.max(1, words.length)) * 100; // percentage
            }

            function analyze(text, keyword){
                var wc = wordCount(text);
                var flesch = fleschReadingEase(text);
                var density = Math.round(keywordDensity(text, keyword) * 100)/100;
                var titleLen = (document.getElementById('title') && document.getElementById('title').value) ? document.getElementById('title').value.length : 0;
                var suggestions = [];
                if (titleLen === 0) suggestions.push('Write a compelling title (50-60 characters recommended).');
                if (titleLen > 60) suggestions.push('Title looks long — consider shortening to 50-60 characters.');
                if (wc < 300) suggestions.push('Consider increasing content length — aim for 800+ words for comprehensive articles.');
                if (flesch < 50) suggestions.push('Readability is low. Use shorter sentences and simpler words to improve reading ease.');
                if (density === 0) suggestions.push('Focus keyword not found in content — add it naturally in the intro and headings.');
                if (density > 3) suggestions.push('Keyword density is high — consider reducing repetitions to avoid keyword stuffing.');

                return {
                    wordCount: wc,
                    flesch: flesch,
                    density: density,
                    suggestions: suggestions
                };
            }

            function renderClassicPanel(result){
                var container = document.getElementById('aco_classic_panel');
                if (!container) return;
                container.innerHTML = '';
                var out = document.createElement('div'); out.className='aco-panel';
                var s = '<div class="aco-score">Words: '+result.wordCount+' — Flesch: '+result.flesch+' — Keyword density: '+result.density+'%</div>';
                s += '<div style="margin-top:8px">';
                if (result.suggestions.length===0) s += '<div class="aco-suggestion">No suggestions — looks good!</div>';
                result.suggestions.forEach(function(r){ s += '<div class="aco-suggestion">'+r+'</div>'; });
                s += '</div>';
                out.innerHTML = s;
                container.appendChild(out);
            }

            // Classic editor binding
            var textarea = document.getElementById('content');
            var keywordField = document.getElementById('aco_focus_keyword_field');
            if (textarea) {
                function runClassicAnalyze(){
                    var text = textarea.value || '';
                    var keyword = keywordField ? keywordField.value : '';
                    var res = analyze(text, keyword);
                    renderClassicPanel(res);
                }
                textarea.addEventListener('keyup', runClassicAnalyze);
                textarea.addEventListener('change', runClassicAnalyze);
                if (keywordField) keywordField.addEventListener('input', runClassicAnalyze);
                // initial run
                setTimeout(runClassicAnalyze, 600);
            }

            // Gutenberg integration (light fallback): try to show a floating widget in edit screen
            if (window.wp && wp.data && wp.data.select && wp.plugins) {
                try{
                    var select = wp.data.select('core/editor');
                    var subscribe = wp.data.subscribe;
                    var last = '';
                    var app = document.createElement('div');
                    app.id = 'aco-floating';
                    app.style.position='fixed'; app.style.right='20px'; app.style.bottom='20px'; app.style.width='300px'; app.style.zIndex=9999; app.style.maxHeight='60vh'; app.style.overflow='auto'; app.style.boxShadow='0 6px 18px rgba(0,0,0,.1)'; app.style.padding='12px'; app.style.background='#fff'; app.style.borderRadius='8px';
                    document.body.appendChild(app);

                    subscribe(function(){
                        var content = select.getEditedPostContent();
                        var title = select.getEditedPostAttribute('title');
                        var keyword = '';
                        try{ keyword = wp.data.select('core/editor').getEditedPostAttribute('meta').aco_focus_keyword || ''; } catch(e){}
                        if (content === last) return; last = content;
                        var res = analyze(content || '', keyword || '');
                        app.innerHTML = '<strong>AI Content Optimizer</strong><div style="font-size:13px;margin-top:6px">Words: '+res.wordCount+' • Flesch: '+res.flesch+' • Density: '+res.density+'%</div>';
                        if (res.suggestions.length>0){
                            var ul = document.createElement('div'); ul.style.marginTop='8px';
                            res.suggestions.forEach(function(s){ var el = document.createElement('div'); el.className='aco-suggestion'; el.textContent = s; ul.appendChild(el); });
                            app.appendChild(ul);
                        } else {
                            var ok = document.createElement('div'); ok.className='aco-suggestion'; ok.textContent='No suggestions — looks good!'; app.appendChild(ok);
                        }
                    });
                } catch(e){ /* ignore */ }
            }

        })();
        </script>
        <?php
    }
});

?>
