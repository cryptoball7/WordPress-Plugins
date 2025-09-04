<?php
/**
 * Plugin Name: Secret Konami Game
 * Description: Adds a hidden Konami-code easter egg. Enter ↑↑↓↓←→←→ B A to reveal a mini-game. Playful UX, no external assets.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: secret-konami-game
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Secret_Konami_Game {

    const VERSION = '1.0.0';
    const SLUG = 'secret-konami-game';

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'print_container' ) );
    }

    public function enqueue_assets() {
        // Only on the front-end
        if ( is_admin() ) {
            return;
        }

        $plugin_url = plugin_dir_url( __FILE__ );

        wp_register_style(
            self::SLUG . '-styles',
            $plugin_url . 'assets/secret-konami-game.css',
            array(),
            self::VERSION
        );

        wp_register_script(
            self::SLUG . '-script',
            $plugin_url . 'assets/secret-konami-game.js',
            array(),
            self::VERSION,
            true
        );

        // Data passed to JS (for future expansion)
        wp_localize_script(
            self::SLUG . '-script',
            'SKGSettings',
            array(
                'nonce' => wp_create_nonce( 'skg_nonce' ),
                'version' => self::VERSION,
            )
        );

        wp_enqueue_style( self::SLUG . '-styles' );
        wp_enqueue_script( self::SLUG . '-script' );
    }

    public function print_container() {
        // Print the modal container used by JS. Hidden initially.
        ?>
        <div id="skg-overlay" aria-hidden="true" role="dialog" aria-modal="true">
            <div id="skg-modal" role="document">
                <button id="skg-close" aria-label="<?php esc_attr_e( 'Close game', 'secret-konami-game' ); ?>">&times;</button>
                <div id="skg-header">
                    <h2 id="skg-title"><?php esc_html_e( 'Secret Game', 'secret-konami-game' ); ?></h2>
                    <p id="skg-subtitle"><?php esc_html_e( 'Catch the eggs! Move with ← → or touch. Press Esc to close.', 'secret-konami-game' ); ?></p>
                </div>
                <div id="skg-game-area">
                    <canvas id="skg-canvas" width="640" height="360" aria-label="<?php esc_attr_e( 'Game canvas', 'secret-konami-game' ); ?>"></canvas>
                    <div id="skg-ui">
                        <div id="skg-score">Score: <span id="skg-score-val">0</span></div>
                        <div id="skg-lives">Lives: <span id="skg-lives-val">3</span></div>
                        <div id="skg-highscore">High: <span id="skg-highscore-val">0</span></div>
                    </div>
                </div>
                <div id="skg-controls">
                    <button id="skg-start"><?php esc_html_e( 'Start Game', 'secret-konami-game' ); ?></button>
                    <button id="skg-reset-high"><?php esc_html_e( 'Reset High Score', 'secret-konami-game' ); ?></button>
                </div>
                <footer id="skg-footer">
                    <small><?php esc_html_e( 'Hidden easter egg delivered with love 🥚', 'secret-konami-game' ); ?></small>
                </footer>
            </div>
        </div>
        <?php
    }
}

new Secret_Konami_Game();
