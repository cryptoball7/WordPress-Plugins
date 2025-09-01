<?php
/**
 * Plugin Name: Dynamic Image Watermarking
 * Description: Automatically add an image watermark to uploaded media. Uses GD and WordPress image filters.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 * Text Domain: dynamic-image-watermarking
 */

if (!defined('ABSPATH')) { exit; }

class DIW_Dynamic_Image_Watermarking {
    const OPTION_KEY = 'diw_options';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'on_activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Apply watermark after WordPress generates all image sizes
        add_filter('wp_generate_attachment_metadata', [$this, 'watermark_attachment_metadata'], 20, 2);

        // Add a column to media list to indicate watermark status
        add_filter('manage_upload_columns', function($cols){
            $cols['diw_watermarked'] = __('Watermarked', 'dynamic-image-watermarking');
            return $cols;
        });
        add_action('manage_media_custom_column', function($column_name, $post_id){
            if ($column_name === 'diw_watermarked') {
                $flag = get_post_meta($post_id, '_diw_watermarked', true);
                echo $flag ? '✓' : '—';
            }
        }, 10, 2);

        // Bulk action to (re)watermark selected media
        add_filter('bulk_actions-upload', function($actions){
            $actions['diw_rewatermark'] = __('Apply Watermark', 'dynamic-image-watermarking');
            return $actions;
        });
        add_filter('handle_bulk_actions-upload', [$this, 'handle_bulk_action'], 10, 3);
    }

    public function on_activate() {
        if (!extension_loaded('gd')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('Dynamic Image Watermarking requires the GD extension. Please enable GD and try again.', 'dynamic-image-watermarking'));
        }
    }

    public function admin_menu() {
        add_options_page(
            __('Image Watermark', 'dynamic-image-watermarking'),
            __('Image Watermark', 'dynamic-image-watermarking'),
            'manage_options',
            'diw-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_options']);

        add_settings_section('diw_main', __('Watermark Settings', 'dynamic-image-watermarking'), function(){
            echo '<p>'.esc_html__('Configure how and where the watermark is applied.', 'dynamic-image-watermarking').'</p>';
        }, 'diw-settings');

        add_settings_field('watermark_url', __('Watermark Image URL', 'dynamic-image-watermarking'), [$this, 'field_watermark_url'], 'diw-settings', 'diw_main');
        add_settings_field('position', __('Position', 'dynamic-image-watermarking'), [$this, 'field_position'], 'diw-settings', 'diw_main');
        add_settings_field('margin', __('Margin (px)', 'dynamic-image-watermarking'), [$this, 'field_margin'], 'diw-settings', 'diw_main');
        add_settings_field('opacity', __('Opacity (0–100)', 'dynamic-image-watermarking'), [$this, 'field_opacity'], 'diw-settings', 'diw_main');
        add_settings_field('scale', __('Scale (% of image width)', 'dynamic-image-watermarking'), [$this, 'field_scale'], 'diw-settings', 'diw_main');
        add_settings_field('sizes', __('Image Sizes', 'dynamic-image-watermarking'), [$this, 'field_sizes'], 'diw-settings', 'diw_main');
        add_settings_field('apply_full', __('Apply to Original File', 'dynamic-image-watermarking'), [$this, 'field_apply_full'], 'diw-settings', 'diw_main');
    }

    public function default_options() {
        return [
            'watermark_url' => '',
            'position' => 'bottom-right',
            'margin' => 24,
            'opacity' => 70,
            'scale' => 20, // watermark width will be 20% of target image width
            'sizes' => [],
            'apply_full' => 1,
        ];
    }

    public function get_options() {
        $opts = get_option(self::OPTION_KEY, []);
        return wp_parse_args($opts, $this->default_options());
    }

    public function sanitize_options($input) {
        $out = $this->get_options();
        $out['watermark_url'] = esc_url_raw($input['watermark_url'] ?? '');
        $allowed_positions = ['top-left','top-right','bottom-left','bottom-right','center'];
        $pos = $input['position'] ?? 'bottom-right';
        $out['position'] = in_array($pos, $allowed_positions, true) ? $pos : 'bottom-right';
        $out['margin'] = max(0, intval($input['margin'] ?? 24));
        $out['opacity'] = min(100, max(0, intval($input['opacity'] ?? 70)));
        $out['scale'] = min(100, max(1, intval($input['scale'] ?? 20)));
        $out['sizes'] = array_map('sanitize_text_field', (array)($input['sizes'] ?? []));
        $out['apply_full'] = empty($input['apply_full']) ? 0 : 1;
        return $out;
    }

    // === Settings fields ===
    public function field_watermark_url() {
        $opts = $this->get_options();
        echo '<input type="url" name="'.esc_attr(self::OPTION_KEY).'[watermark_url]" value="'.esc_attr($opts['watermark_url']).'" class="regular-text" placeholder="https://.../watermark.png" />';
        echo '<p class="description">'.esc_html__('Recommended: transparent PNG. You can upload to Media Library and paste the file URL.', 'dynamic-image-watermarking').'</p>';
    }

    public function field_position() {
        $opts = $this->get_options();
        $positions = [
            'top-left' => __('Top Left','dynamic-image-watermarking'),
            'top-right' => __('Top Right','dynamic-image-watermarking'),
            'bottom-left' => __('Bottom Left','dynamic-image-watermarking'),
            'bottom-right' => __('Bottom Right','dynamic-image-watermarking'),
            'center' => __('Center','dynamic-image-watermarking'),
        ];
        echo '<select name="'.esc_attr(self::OPTION_KEY).'[position]">';
        foreach ($positions as $val => $label) {
            echo '<option value="'.esc_attr($val).'" '.selected($opts['position'],$val,false).'>'.esc_html($label).'</option>';
        }
        echo '</select>';
    }

    public function field_margin() {
        $opts = $this->get_options();
        echo '<input type="number" min="0" name="'.esc_attr(self::OPTION_KEY).'[margin]" value="'.esc_attr($opts['margin']).'" />';
    }

    public function field_opacity() {
        $opts = $this->get_options();
        echo '<input type="number" min="0" max="100" name="'.esc_attr(self::OPTION_KEY).'[opacity]" value="'.esc_attr($opts['opacity']).'" />';
    }

    public function field_scale() {
        $opts = $this->get_options();
        echo '<input type="number" min="1" max="100" name="'.esc_attr(self::OPTION_KEY).'[scale]" value="'.esc_attr($opts['scale']).'" />';
    }

    public function field_sizes() {
        $opts = $this->get_options();
        $reg_sizes = wp_get_registered_image_subsizes();
        foreach ($reg_sizes as $size => $def) {
            $checked = in_array($size, (array)$opts['sizes'], true) ? 'checked' : '';
            echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="'.esc_attr(self::OPTION_KEY).'[sizes][]" value="'.esc_attr($size).'" '.$checked.'/> '.esc_html($size).' ('.intval($def['width']).'×'.intval($def['height']).')</label>';
        }
        echo '<p class="description">'.esc_html__('Leave all unchecked to watermark every generated size.', 'dynamic-image-watermarking').'</p>';
    }

    public function field_apply_full() {
        $opts = $this->get_options();
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION_KEY).'[apply_full]" value="1" '.checked(1,$opts['apply_full'],false).'/> '.esc_html__('Also watermark the original uploaded file (full size).', 'dynamic-image-watermarking').'</label>';
    }

    public function render_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Dynamic Image Watermarking', 'dynamic-image-watermarking').'</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        do_settings_sections('diw-settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    // === Watermark on upload ===
    public function watermark_attachment_metadata($metadata, $attachment_id) {
        $mime = get_post_mime_type($attachment_id);
        if (strpos($mime, 'image/') !== 0) {
            return $metadata; // not an image
        }

        $opts = $this->get_options();
        if (empty($opts['watermark_url'])) {
            return $metadata; // not configured
        }

        $already = get_post_meta($attachment_id, '_diw_watermarked', true);
        if ($already) {
            return $metadata; // avoid double-apply
        }

        $file = get_attached_file($attachment_id); // absolute path to original
        $base_dir = dirname($file);

        // Decide which sizes to process
        $target_sizes = (array)$opts['sizes'];
        $restrict = !empty($target_sizes);

        // Original file
        if (!empty($opts['apply_full'])) {
            $this->maybe_apply_to_path($file, $opts);
        }

        // Generated sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                if ($restrict && !in_array($size, $target_sizes, true)) {
                    continue;
                }
                $path = path_join($base_dir, $data['file']);
                $this->maybe_apply_to_path($path, $opts);
            }
        }

        update_post_meta($attachment_id, '_diw_watermarked', 1);
        return $metadata;
    }

    private function maybe_apply_to_path($path, $opts) {
        if (!file_exists($path) || !is_readable($path) || !is_writable($path)) {
            return;
        }
        $type = wp_check_filetype($path);
        $ext = strtolower($type['ext'] ?? '');
        if (!in_array($ext, ['jpg','jpeg','png'], true)) {
            return; // skip unsupported (gif/webp/svg etc.)
        }
        $this->apply_watermark_gd($path, $opts);
    }

    // Core watermarking using GD only
    private function apply_watermark_gd($target_path, $opts) {
        $wm_url = $opts['watermark_url'];
        $wm_path = $this->url_to_local_path($wm_url);
        if (!$wm_path || !file_exists($wm_path)) {
            return;
        }

        // Load target
        $target_img = $this->gd_image_from_file($target_path);
        if (!$target_img) return;

        // Load watermark
        $wm_img = $this->gd_image_from_file($wm_path);
        if (!$wm_img) { imagedestroy($target_img); return; }

        $t_w = imagesx($target_img); $t_h = imagesy($target_img);
        $w_w = imagesx($wm_img);    $w_h = imagesy($wm_img);

        // Scale watermark to N% of target width
        $scale_pct = max(1, min(100, intval($opts['scale'])));
        $dest_w = max(1, (int) round(($scale_pct / 100) * $t_w));
        $ratio  = $w_h / $w_w;
        $dest_h = max(1, (int) round($dest_w * $ratio));

        $scaled = imagecreatetruecolor($dest_w, $dest_h);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $wm_img, 0,0,0,0, $dest_w,$dest_h, $w_w,$w_h);

        // Compute position
        $margin = max(0, intval($opts['margin']));
        [$dst_x, $dst_y] = $this->compute_position($opts['position'], $t_w, $t_h, $dest_w, $dest_h, $margin);

        // Opacity
        $opacity = max(0, min(100, intval($opts['opacity'])));
        $this->gd_copy_with_opacity($target_img, $scaled, $dst_x, $dst_y, $opacity);

        // Save back to file (preserve original format)
        $this->gd_save_to_path($target_img, $target_path);

        imagedestroy($scaled);
        imagedestroy($wm_img);
        imagedestroy($target_img);
    }

    private function compute_position($pos, $t_w, $t_h, $w_w, $w_h, $m) {
        switch ($pos) {
            case 'top-left':
                return [$m, $m];
            case 'top-right':
                return [$t_w - $w_w - $m, $m];
            case 'bottom-left':
                return [$m, $t_h - $w_h - $m];
            case 'center':
                return [ (int) round(($t_w - $w_w) / 2), (int) round(($t_h - $w_h) / 2) ];
            case 'bottom-right':
            default:
                return [$t_w - $w_w - $m, $t_h - $w_h - $m];
        }
    }

    private function gd_copy_with_opacity($dst, $src, $dst_x, $dst_y, $opacity) {
        // For PNG with alpha, blend properly with adjustable opacity
        $w = imagesx($src); $h = imagesy($src);
        if ($opacity >= 100) {
            imagealphablending($dst, true);
            imagecopy($dst, $src, $dst_x, $dst_y, 0, 0, $w, $h);
            return;
        }
        // Create a temp image to apply global opacity
        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);

        // Walk pixels to adjust alpha based on desired opacity
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($src, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24; // 0 (opaque) .. 127 (transparent)
                $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
                $alpha = 127 - (127 - $a) * ($opacity / 100);
                $col = imagecolorallocatealpha($tmp, $r, $g, $b, (int)round($alpha));
                imagesetpixel($tmp, $x, $y, $col);
            }
        }
        imagealphablending($dst, true);
        imagecopy($dst, $tmp, $dst_x, $dst_y, 0, 0, $w, $h);
        imagedestroy($tmp);
    }

    private function gd_image_from_file($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg': case 'jpeg':
                return @imagecreatefromjpeg($path);
            case 'png':
                return @imagecreatefrompng($path);
            case 'gif':
                return @imagecreatefromgif($path);
            default:
                return false;
        }
    }

    private function gd_save_to_path($img, $path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'png') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            @imagepng($img, $path, 6);
        } elseif ($ext === 'gif') {
            // GIF will lose animation (we skip processing GIF earlier), but just in case
            @imagegif($img, $path);
        } else {
            // default jpeg quality 85
            @imagejpeg($img, $path, 85);
        }
    }

    private function url_to_local_path($url) {
        // If the URL is within uploads dir, translate to local path
        $uploads = wp_get_upload_dir();
        if (strpos($url, $uploads['baseurl']) === 0) {
            $rel = ltrim(str_replace($uploads['baseurl'], '', $url), '/');
            return path_join($uploads['basedir'], $rel);
        }
        // For absolute file paths mistakenly pasted
        if (file_exists($url)) { return $url; }
        return false;
    }

    // === Bulk action ===
    public function handle_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'diw_rewatermark') return $redirect_to;
        $count = 0;
        foreach ($post_ids as $id) {
            delete_post_meta($id, '_diw_watermarked'); // force re-apply
            $meta = wp_generate_attachment_metadata($id, get_attached_file($id));
            if ($meta) {
                wp_update_attachment_metadata($id, $meta);
                $count++;
            }
        }
        $redirect_to = add_query_arg('diw_watermarked', $count, $redirect_to);
        add_action('admin_notices', function() use ($count){
            echo '<div class="notice notice-success is-dismissible"><p>'
                .sprintf(esc_html__('%d attachment(s) watermarked.', 'dynamic-image-watermarking'), intval($count)).'</p></div>';
        });
        return $redirect_to;
    }
}

new DIW_Dynamic_Image_Watermarking();
