<?php
/*
Plugin Name: Dark Mode & Accessibility Enhancer
Description: Adds toggles for Dark Mode, larger font size, and dyslexia-friendly fonts for better accessibility.
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class DarkModeAccessibilityEnhancer {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'add_toggle_buttons']);
    }

    public function enqueue_assets() {
        // Enqueue styles and scripts
        wp_enqueue_style('dmae-style', plugin_dir_url(__FILE__) . 'style.css');
        wp_enqueue_script('dmae-script', plugin_dir_url(__FILE__) . 'script.js', [], false, true);
    }

    public function add_toggle_buttons() {
        ?>
        <div id="accessibility-controls">
            <button id="toggle-darkmode" aria-label="Toggle Dark Mode">🌓 Dark Mode</button>
            <button id="toggle-fontsize" aria-label="Toggle Larger Font Size">🔠 Large Text</button>
            <button id="toggle-dyslexia" aria-label="Toggle Dyslexia-Friendly Font">🔤 Dyslexia Font</button>
        </div>
        <?php
    }
}

new DarkModeAccessibilityEnhancer();
