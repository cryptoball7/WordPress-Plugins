<?php
/**
 * Plugin Name: Fake Review Filter
 * Plugin URI:  https://example.com/fake-review-filter
 * Description: Flags suspicious WooCommerce product reviews using configurable heuristics and adds admin tools to review flagged comments.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Author URI:  https://cryptoball7.github.io
 * Text Domain: fake-review-filter
 * Domain Path: /languages
 *
 * @package FakeReviewFilter
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fake_Review_Filter {

	const OPTION_KEY = 'frf_options';
	const META_KEY   = 'frf_flagged_score';

	private $defaults = array(
		'min_length'              => 20,    // reviews shorter than this add suspicion points
		'exclamation_threshold'   => 5,     // too many !
		'max_reviews_same_ip'     => 3,     // if same IP posts more than this in window -> suspicious
		'review_window_hours'     => 48,
		'blacklisted_domains'     => 'mailinator.com,tempmail.com,10minutemail.com',
		'score_threshold'         => 60,    // >= becomes flagged
		'auto_moderate'           => 'hold',// 'hold' (set to pending) or 'spam' or 'none'
		'enable_admin_notice'     => 1,     // show admin notices about flagged counts
		'check_short_words_ratio' => 0.6,   // proportion of words with length <=2 that's suspicious
	);

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// admin settings
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// scan on new comment (WooCommerce product reviews are comments on product post type)
		add_action( 'comment_post', array( $this, 'scan_comment' ), 20, 2 );
		add_action( 'edit_comment', array( $this, 'rescan_comment_on_edit' ), 10, 2 );

		// add column to comment admin
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_comment_column' ) );
		add_filter( 'manage_comments_custom_column', array( $this, 'render_comment_column' ), 10, 2 );

		// quick actions
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_frf_rescan_comment', array( $this, 'ajax_rescan_comment' ) );
		add_action( 'wp_ajax_frf_unflag_comment', array( $this, 'ajax_unflag_comment' ) );

		// admin notice
		add_action( 'admin_notices', array( $this, 'admin_notice_flagged_count' ) );
	}

	public function activate() {
		// populate default options if not exist
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, $this->defaults );
		} else {
			// ensure keys exist
			$current = get_option( self::OPTION_KEY, array() );
			$merged  = wp_parse_args( $current, $this->defaults );
			update_option( self::OPTION_KEY, $merged );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'fake-review-filter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/* -----------------------
	 * Admin settings and menu
	 * ----------------------- */

	public function add_admin_menu() {
		add_options_page(
			__( 'Fake Review Filter', 'fake-review-filter' ),
			__( 'Fake Review Filter', 'fake-review-filter' ),
			'manage_options',
			'fake-review-filter',
			array( $this, 'settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'frf_settings', self::OPTION_KEY, array( $this, 'sanitize_options' ) );

		add_settings_section( 'frf_main', __( 'Main Settings', 'fake-review-filter' ), null, 'fake-review-filter' );

		$fields = array(
			'min_length' => __( 'Minimum review length (chars)', 'fake-review-filter' ),
			'exclamation_threshold' => __( 'Exclamation count threshold', 'fake-review-filter' ),
			'max_reviews_same_ip' => __( 'Max reviews allowed from same IP (window)', 'fake-review-filter' ),
			'review_window_hours' => __( 'Window hours to check multiple reviews from same IP', 'fake-review-filter' ),
			'blacklisted_domains' => __( 'Blacklisted disposable email domains (comma separated)', 'fake-review-filter' ),
			'score_threshold' => __( 'Flag score threshold (0-100)', 'fake-review-filter' ),
			'auto_moderate' => __( 'Auto action when flagged', 'fake-review-filter' ),
			'enable_admin_notice' => __( 'Show admin notice for flagged counts', 'fake-review-filter' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_field' ),
				'fake-review-filter',
				'frf_main',
				array( 'key' => $key )
			);
		}
	}

	public function sanitize_options( $input ) {
		$opts = wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->defaults );
		foreach ( $opts as $k => $v ) {
			if ( isset( $input[ $k ] ) ) {
				$opts[ $k ] = sanitize_text_field( $input[ $k ] );
			}
		}
		// cast numeric fields
		$opts['min_length'] = max( 0, intval( $opts['min_length'] ) );
		$opts['exclamation_threshold'] = max( 0, intval( $opts['exclamation_threshold'] ) );
		$opts['max_reviews_same_ip'] = max( 1, intval( $opts['max_reviews_same_ip'] ) );
		$opts['review_window_hours'] = max( 1, intval( $opts['review_window_hours'] ) );
		$opts['score_threshold'] = min( 100, max( 0, intval( $opts['score_threshold'] ) ) );
		$opts['enable_admin_notice'] = intval( $opts['enable_admin_notice'] ) ? 1 : 0;
		return $opts;
	}

	public function render_field( $args ) {
		$key = $args['key'];
		$opts = get_option( self::OPTION_KEY, $this->defaults );
		$value = isset( $opts[ $key ] ) ? $opts[ $key ] : '';
		switch ( $key ) {
			case 'auto_moderate':
				?>
				<select name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>">
					<option value="none" <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'None (only flag)', 'fake-review-filter' ); ?></option>
					<option value="hold" <?php selected( $value, 'hold' ); ?>><?php esc_html_e( 'Hold for moderation (pending)', 'fake-review-filter' ); ?></option>
					<option value="spam" <?php selected( $value, 'spam' ); ?>><?php esc_html_e( 'Mark as spam', 'fake-review-filter' ); ?></option>
				</select>
				<?php
				break;
			case 'enable_admin_notice':
				?>
				<select name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>">
					<option value="1" <?php selected( $value, 1 ); ?>><?php esc_html_e( 'Yes', 'fake-review-filter' ); ?></option>
					<option value="0" <?php selected( $value, 0 ); ?>><?php esc_html_e( 'No', 'fake-review-filter' ); ?></option>
				</select>
				<?php
				break;
			default:
				?>
				<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>"/>
				<?php
		}
	}

	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fake Review Filter Settings', 'fake-review-filter' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'frf_settings' );
				do_settings_sections( 'fake-review-filter' );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Manual Scan', 'fake-review-filter' ); ?></h2>
			<p><?php esc_html_e( 'Enter a comment ID to rescan it and see the suspicion score. You can also rescan existing comments from the Comments admin screen.', 'fake-review-filter' ); ?></p>
			<form id="frf-rescan-form">
				<input type="number" id="frf_comment_id" name="comment_id" placeholder="<?php esc_attr_e( 'Comment ID', 'fake-review-filter' ); ?>" required>
				<?php wp_nonce_field( 'frf_rescan', 'frf_nonce' ); ?>
				<button class="button button-primary" id="frf_rescan_btn"><?php esc_html_e( 'Rescan', 'fake-review-filter' ); ?></button>
				<span id="frf_rescan_result" style="margin-left:12px;"></span>
			</form>
		</div>
		<?php
	}

	/* -----------------------
	 * Heuristic scanning
	 * ----------------------- */

	/**
	 * Scan a comment when it's posted.
	 *
	 * @param int $comment_ID
	 * @param int $comment_approved
	 */
	public function scan_comment( $comment_ID, $comment_approved ) {
		$comment = get_comment( $comment_ID );
		if ( ! $comment ) {
			return;
		}

		// only scan product reviews (WooCommerce stores reviews as comments on 'product' post type)
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		$score = $this->calculate_suspicion_score( $comment );

		// store meta with score
		update_comment_meta( $comment_ID, self::META_KEY, $score );

		$options = get_option( self::OPTION_KEY, $this->defaults );
		$threshold = intval( $options['score_threshold'] );

		if ( $score >= $threshold ) {
			// flag
			do_action( 'frf_comment_flagged', $comment_ID, $score, $comment );

			$action = isset( $options['auto_moderate'] ) ? $options['auto_moderate'] : 'none';
			if ( 'hold' === $action ) {
				wp_set_comment_status( $comment_ID, 'hold' );
			} elseif ( 'spam' === $action ) {
				wp_spam_comment( $comment_ID );
			} else {
				// leave as-is but add meta
			}
		}
	}

	/**
	 * Re-scan when a comment is edited by admin.
	 *
	 * @param int $comment_id
	 * @param object $comment
	 */
	public function rescan_comment_on_edit( $comment_id, $comment ) {
		// only for product comments
		$post = get_post( $comment->comment_post_ID );
		if ( $post && 'product' === $post->post_type ) {
			$this->scan_comment( $comment_id, $comment->comment_approved );
		}
	}

	/**
	 * Main scoring function.
	 *
	 * Returns 0-100 where higher => more suspicious.
	 *
	 * @param WP_Comment $comment
	 * @return int
	 */
	private function calculate_suspicion_score( $comment ) {
		$options = get_option( self::OPTION_KEY, $this->defaults );
		$score = 0;

		$text = trim( $comment->comment_content );
		$length = strlen( wp_strip_all_tags( $text ) );

		// 1) Short review
		if ( $length < intval( $options['min_length'] ) ) {
			// more suspicious the shorter it is
			$score += ( ( intval( $options['min_length'] ) - $length ) / max(1, intval( $options['min_length'] ) ) ) * 25;
		}

		// 2) Lots of exclamation marks
		$exclamations = substr_count( $text, '!' );
		if ( $exclamations >= intval( $options['exclamation_threshold'] ) ) {
			$score += min( 20, ( $exclamations - intval( $options['exclamation_threshold'] ) + 1 ) * 5 );
		}

		// 3) Short-words-heavy (like "good good good!" or "ok ok")
		$words = preg_split( '/\s+/', strip_tags( $text ) );
		if ( is_array( $words ) && count( $words ) > 0 ) {
			$short_words = 0;
			foreach ( $words as $w ) {
				if ( strlen( $w ) <= 2 ) {
					$short_words++;
				}
			}
			$ratio = $short_words / count( $words );
			if ( $ratio >= floatval( $options['check_short_words_ratio'] ) ) {
				$score += 15;
			}
		}

		// 4) Duplicate reviews from same IP in last window
		if ( ! empty( $comment->comment_author_IP ) ) {
			$ip = $comment->comment_author_IP;
			$window = intval( $options['review_window_hours'] );
			$since = gmdate( 'Y-m-d H:i:s', time() - ( $window * HOUR_IN_SECONDS ) );
			global $wpdb;
			$count = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->comments WHERE comment_author_IP = %s AND comment_date_gmt >= %s AND comment_approved != 'trash'",
				$ip,
				$since
			) );
			if ( $count && $count >= intval( $options['max_reviews_same_ip'] ) ) {
				$score += min( 30, ( $count - intval( $options['max_reviews_same_ip'] ) + 1 ) * 10 );
			}
		}

		// 5) Disposable email domains
		$email = $comment->comment_author_email;
		if ( ! empty( $email ) && strpos( $email, '@' ) !== false ) {
			$domain = strtolower( substr( strrchr( $email, "@" ), 1 ) );
			$black = array_map( 'trim', explode( ',', strtolower( $options['blacklisted_domains'] ) ) );
			foreach ( $black as $bd ) {
				if ( empty( $bd ) ) {
					continue;
				}
				// check exact or ends with (for subdomains)
				if ( $domain === $bd || substr( $domain, - ( strlen( $bd ) + 1 ) ) === '.' . $bd ) {
					$score += 30;
					break;
				}
			}
		}

		// 6) Repetitive characters / long repeated words (like "besttttt")
		if ( preg_match( '/(.)\\1{4,}/', $text ) ) {
			$score += 10;
		}

		// 7) Rating-only reviews (WooCommerce may store rating in comment meta 'rating')
		$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
		if ( ! $text || trim( $text ) === '' ) {
			// empty content but has rating
			$score += 40;
		} elseif ( $rating && $length < 40 ) {
			$score += 10;
		}

		// cap score 0-100
		$score = max( 0, min( 100, round( $score ) ) );
		return (int) $score;
	}

	/* --------------------------
	 * Admin comment column/UI
	 * -------------------------- */

	public function add_comment_column( $columns ) {
		// add after author
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'author' === $key ) {
				$new['frf_flag'] = __( 'FRF', 'fake-review-filter' );
			}
		}
		return $new;
	}

	public function render_comment_column( $column, $comment_ID ) {
		if ( 'frf_flag' !== $column ) {
			return;
		}
		$score = get_comment_meta( $comment_ID, self::META_KEY, true );
		if ( $score === '' ) {
			echo '<span style="color:#888;">—</span>';
			return;
		}
		$score = intval( $score );
		$class = 'frf-score-low';
		if ( $score >= 80 ) {
			$class = 'frf-score-high';
		} elseif ( $score >= 50 ) {
			$class = 'frf-score-medium';
		}
		?>
		<div class="frf-flag <?php echo esc_attr( $class ); ?>" data-comment-id="<?php echo esc_attr( $comment_ID ); ?>">
			<strong><?php echo esc_html( $score ); ?></strong>
			<span class="frf-actions">
				<button class="button frf-rescan" data-id="<?php echo esc_attr( $comment_ID ); ?>"><?php esc_html_e( 'Rescan', 'fake-review-filter' ); ?></button>
				<button class="button frf-unflag" data-id="<?php echo esc_attr( $comment_ID ); ?>"><?php esc_html_e( 'Unflag', 'fake-review-filter' ); ?></button>
			</span>
		</div>
		<?php
	}

	/* --------------------------
	 * Admin scripts & AJAX
	 * -------------------------- */

	public function enqueue_admin_assets( $hook ) {
		// only load on comments.php or our settings page
		if ( 'edit-comments.php' !== $hook && 'settings_page_fake-review-filter' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'frf-admin', plugin_dir_url( __FILE__ ) . 'frf-admin.js', array( 'jquery' ), '1.0.0', true );
		wp_localize_script( 'frf-admin', 'frf_ajax', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'frf_ajax_nonce' ),
		) );
		wp_enqueue_style( 'frf-admin-css', plugin_dir_url( __FILE__ ) . 'frf-admin.css' );
	}

	/**
	 * AJAX rescan single comment.
	 */
	public function ajax_rescan_comment() {
		check_ajax_referer( 'frf_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_comments' ) ) {
			wp_send_json_error( array( 'message' => 'No permission' ), 403 );
		}
		$cid = intval( $_POST['comment_id'] ?? 0 );
		if ( $cid <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid comment id' ), 400 );
		}
		$comment = get_comment( $cid );
		if ( ! $comment ) {
			wp_send_json_error( array( 'message' => 'Comment not found' ), 404 );
		}
		$score = $this->calculate_suspicion_score( $comment );
		update_comment_meta( $cid, self::META_KEY, $score );
		wp_send_json_success( array( 'score' => $score ) );
	}

	/**
	 * AJAX unflag comment (remove meta only).
	 */
	public function ajax_unflag_comment() {
		check_ajax_referer( 'frf_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_comments' ) ) {
			wp_send_json_error( array( 'message' => 'No permission' ), 403 );
		}
		$cid = intval( $_POST['comment_id'] ?? 0 );
		if ( $cid <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid comment id' ), 400 );
		}
		delete_comment_meta( $cid, self::META_KEY );
		wp_send_json_success( array( 'message' => 'unflagged' ) );
	}

	/* --------------------------
	 * Admin notice
	 * -------------------------- */

	public function admin_notice_flagged_count() {
		$options = get_option( self::OPTION_KEY, $this->defaults );
		if ( empty( $options['enable_admin_notice'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$threshold = intval( $options['score_threshold'] );
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->commentmeta m JOIN $wpdb->comments c ON c.comment_ID = m.comment_id WHERE m.meta_key = %s AND CAST(m.meta_value AS SIGNED) >= %d",
			self::META_KEY,
			$threshold
		) );
		if ( intval( $count ) > 0 ) {
			$view = admin_url( 'edit-comments.php?comment_status=all' );
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Fake Review Filter:', 'fake-review-filter' ); ?></strong>
					<?php echo sprintf( esc_html( _n( '%d flagged review', '%d flagged reviews', $count, 'fake-review-filter' ) ), intval( $count ) ); ?>
					<a href="<?php echo esc_url( $view ); ?>" style="margin-left:12px;"><?php esc_html_e( 'View comments', 'fake-review-filter' ); ?></a>
				</p>
			</div>
			<?php
		}
	}

} // end class

new Fake_Review_Filter();

/* --------------------------
 * Minimal admin JS (inlined file fallback)
 * -------------------------- */

/**
 * Create small JS and CSS files if they don't exist so we don't force a multi-file plugin.
 * If you prefer, place them in separate files named frf-admin.js and frf-admin.css
 * in the same plugin directory. We'll output them here inline for portability.
 */

// Inline JS output if the actual file doesn't exist
$js_file = plugin_dir_path( __FILE__ ) . 'frf-admin.js';
if ( ! file_exists( $js_file ) ) {
	file_put_contents( $js_file, <<<'JS'
jQuery(document).ready(function($){
    // Rescan from settings page
    $('#frf-rescan-form').on('submit', function(e){
        e.preventDefault();
        var cid = $('#frf_comment_id').val();
        var data = {
            action: 'frf_rescan_comment',
            comment_id: cid,
            nonce: frf_ajax.nonce
        };
        $('#frf_rescan_btn').attr('disabled', true);
        $('#frf_rescan_result').text('Scanning...');
        $.post(frf_ajax.ajax_url, data, function(resp){
            $('#frf_rescan_btn').attr('disabled', false);
            if (resp && resp.success) {
                $('#frf_rescan_result').text('Score: ' + resp.data.score);
            } else {
                $('#frf_rescan_result').text('Error');
            }
        }, 'json');
    });

    // Rescan button in comments list
    $('.frf-rescan').on('click', function(e){
        e.preventDefault();
        var id = $(this).data('id');
        var button = $(this);
        button.attr('disabled', true).text('Scanning...');
        $.post(frf_ajax.ajax_url, { action: 'frf_rescan_comment', comment_id: id, nonce: frf_ajax.nonce }, function(resp){
            button.attr('disabled', false).text('Rescan');
            if (resp && resp.success) {
                button.closest('.frf-flag').find('strong').text(resp.data.score);
            } else {
                alert('Rescan failed');
            }
        }, 'json');
    });

    // Unflag button
    $('.frf-unflag').on('click', function(e){
        e.preventDefault();
        if (!confirm('Unflag this comment (remove FRF metadata)?')) {
            return;
        }
        var id = $(this).data('id');
        var btn = $(this);
        btn.attr('disabled', true).text('Unflagging...');
        $.post(frf_ajax.ajax_url, { action: 'frf_unflag_comment', comment_id: id, nonce: frf_ajax.nonce }, function(resp){
            if (resp && resp.success) {
                btn.closest('.frf-flag').find('strong').text('—');
                btn.closest('.frf-flag').fadeOut(400);
            } else {
                alert('Unflag failed');
                btn.attr('disabled', false).text('Unflag');
            }
        }, 'json');
    });
});
JS
);
}

// Minimal CSS
$css_file = plugin_dir_path( __FILE__ ) . 'frf-admin.css';
if ( ! file_exists( $css_file ) ) {
	file_put_contents( $css_file, <<<'CSS'
.frf-flag { display:flex; align-items:center; gap:8px; }
.frf-flag strong { display:inline-block; min-width:36px; text-align:center; background:#eee; border-radius:4px; padding:2px 6px; }
.frf-score-low strong { background: #e7f7e7; color:#1f6f2d; }
.frf-score-medium strong { background: #fff4e5; color:#b26a00; }
.frf-score-high strong { background: #ffecec; color:#a00; }
.frf-actions button { margin-left:6px; }
CSS
);
}
