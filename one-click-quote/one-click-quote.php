<?php
/**
 * Plugin Name: One-Click Quote Generator
 * Description: A simple quote generator for service-based businesses (cleaning, repairs, design). Allows customers to get instant quotes with one click.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class OneClickQuoteGenerator {
    public function __construct() {
        add_action('admin_menu', [ $this, 'add_admin_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
        add_shortcode('one_click_quote', [ $this, 'render_quote_generator' ]);
        add_action('wp_enqueue_scripts', [ $this, 'enqueue_scripts' ]);
    }

    // Admin Page
    public function add_admin_page() {
        add_menu_page(
            'Quote Generator',
            'Quote Generator',
            'manage_options',
            'one-click-quote',
            [ $this, 'settings_page_html' ],
            'dashicons-calculator'
        );
    }

    public function register_settings() {
        register_setting('one_click_quote_settings', 'ocq_services');
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>One-Click Quote Generator</h1>
            <form method="post" action="options.php">
                <?php settings_fields('one_click_quote_settings'); ?>
                <?php $services = get_option('ocq_services', []); ?>

                <table class="form-table" id="ocq-service-table">
                    <thead>
                        <tr>
                            <th>Service Name</th>
                            <th>Base Price ($)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($services)) : ?>
                            <?php foreach ($services as $i => $service) : ?>
                                <tr>
                                    <td><input type="text" name="ocq_services[<?php echo $i; ?>][name]" value="<?php echo esc_attr($service['name']); ?>" required></td>
                                    <td><input type="number" step="0.01" name="ocq_services[<?php echo $i; ?>][price]" value="<?php echo esc_attr($service['price']); ?>" required></td>
                                    <td><button type="button" class="button ocq-remove">Remove</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="ocq-add-service">+ Add Service</button></p>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const table = document.querySelector('#ocq-service-table tbody');
            document.querySelector('#ocq-add-service').addEventListener('click', function() {
                const rowCount = table.rows.length;
                const row = document.createElement('tr');
                row.innerHTML = `<td><input type="text" name="ocq_services[${rowCount}][name]" required></td>
                                 <td><input type="number" step="0.01" name="ocq_services[${rowCount}][price]" required></td>
                                 <td><button type="button" class="button ocq-remove">Remove</button></td>`;
                table.appendChild(row);
            });

            table.addEventListener('click', function(e) {
                if (e.target.classList.contains('ocq-remove')) {
                    e.target.closest('tr').remove();
                }
            });
        });
        </script>
        <?php
    }

    // Frontend Shortcode
    public function render_quote_generator() {
        $services = get_option('ocq_services', []);
        ob_start();
        ?>
        <div class="ocq-quote-generator">
            <h3>Get Your Instant Quote</h3>
            <?php if (empty($services)) : ?>
                <p>No services available. Please configure in admin panel.</p>
            <?php else : ?>
                <form id="ocq-form">
                    <?php foreach ($services as $i => $service) : ?>
                        <label>
                            <input type="checkbox" name="services[]" value="<?php echo esc_attr($service['price']); ?>">
                            <?php echo esc_html($service['name']); ?> ($<?php echo esc_html($service['price']); ?>)
                        </label><br>
                    <?php endforeach; ?>
                    <button type="button" id="ocq-generate" class="button">Generate Quote</button>
                </form>
                <div id="ocq-result" style="margin-top:15px;font-weight:bold;"></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_scripts() {
        wp_add_inline_script('jquery-core', "
            jQuery(document).ready(function($){
                $('#ocq-generate').on('click', function(){
                    let total = 0;
                    $('#ocq-form input[name=\"services[]\"]:checked').each(function(){
                        total += parseFloat($(this).val());
                    });
                    $('#ocq-result').text('Your Quote: $' + total.toFixed(2));
                });
            });
        ");
    }
}

new OneClickQuoteGenerator();
