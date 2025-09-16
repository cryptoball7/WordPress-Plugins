<?php
/**
 * Plugin Name: Activity Stream Enhancer
 * Description: Adds multi-type reactions (👍 ❤️ 😂), activity meta, AJAX integration and UX improvements for an activity stream.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: ase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activity_Stream_Enhancer {

	const VERSION = '1.0.0';
	const NONCE_ACTION = 'ase_reaction_nonce_action';
	const NONCE_NAME = 'ase_reaction_nonce';

	private static $instance = null;
	private $reactions = array(
		'like'  => array( 'label' => 'Like',    'emoji' => '👍' ),
		'love'  => array( 'label' => 'Love',    'emoji' => '❤️' ),
		'lol'   => array( 'label' => 'Haha',    'emoji' => '😂' ),
	);

	/**
	 * Singleton
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		// Register CPT
		add_action( 'init', array( $this, 'register_activity_cpt' ) );

		// Enqueue scripts/styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Shortcode to show activity stream
		add_shortcode( 'ase_activity_stream', array( $this, 'shortcode_activity_stream' ) );

		// AJAX handlers (both logged-in and not)
		add_action( 'wp_ajax_ase_toggle_reaction', array( $this, 'ajax_toggle_reaction' ) );
		add_action( 'wp_ajax_nopriv_ase_toggle_reaction', array( $this, 'ajax_toggle_reaction' ) );

		// Admin columns & meta
		add_filter( 'manage_ase_activity_posts_columns', array( $this, 'activity_columns' ), 10, 1 );
		add_action( 'manage_ase_activity_posts_custom_column', array( $this, 'activity_columns_content' ), 10, 2 );

		// Add REST support for reaction counts (helpful for other themes)
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );

		// Activation hook to create sample content (optional)
		register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
	}

	/* ---------------------------
	 * CPT
	 * ---------------------------*/
	public function register_activity_cpt() {
		$labels = array(
			'name'               => __( 'Activities', 'ase' ),
			'singular_name'      => __( 'Activity', 'ase' ),
			'add_new_item'       => __( 'Add New Activity', 'ase' ),
			'edit_item'          => __( 'Edit Activity', 'ase' ),
			'new_item'           => __( 'New Activity', 'ase' ),
			'view_item'          => __( 'View Activity', 'ase' ),
			'search_items'       => __( 'Search Activities', 'ase' ),
			'not_found'          => __( 'No activities found', 'ase' ),
			'not_found_in_trash' => __( 'No activities found in Trash', 'ase' ),
		);

		register_post_type( 'ase_activity', array(
			'labels'             => $labels,
			'public'             => true,
			'show_in_rest'       => true,
			'supports'           => array( 'title', 'editor', 'author', 'comments' ),
			'has_archive'        => true,
			'rewrite'            => array( 'slug' => 'activities' ),
			'show_in_menu'       => true,
			'capability_type'    => 'post',
		) );
	}

	/* ---------------------------
	 * Assets
	 * ---------------------------*/
	public function enqueue_assets() {
		$plugin_url = plugin_dir_url( __FILE__ );

		wp_enqueue_style( 'ase-style', $plugin_url . 'assets/css/ase-style.css', array(), self::VERSION );
		wp_enqueue_script( 'ase-frontend', $plugin_url . 'assets/js/ase-frontend.js', array( 'jquery' ), self::VERSION, true );

		// Localize script with data for AJAX/REST
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		wp_localize_script( 'ase-frontend', 'ASE', array(
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => $nonce,
			'reactions'    => $this->reactions,
			'user_id'      => get_current_user_id(),
			'strings'      => array(
				'you' => __( 'You', 'ase' ),
			),
		) );
	}

	/* ---------------------------
	 * Shortcode
	 * ---------------------------*/
	public function shortcode_activity_stream( $atts ) {
		$atts = shortcode_atts( array(
			'posts_per_page' => 10,
		), $atts, 'ase_activity_stream' );

		$args = array(
			'post_type'      => 'ase_activity',
			'posts_per_page' => intval( $atts['posts_per_page'] ),
			'post_status'    => 'publish',
		);

		$query = new WP_Query( $args );

		ob_start();

		echo '<div class="ase-activity-stream" data-ase-context="stream">';

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$this->render_activity_item( get_post() );
			}
			wp_reset_postdata();
		} else {
			echo '<p class="ase-no-activities">' . esc_html__( 'No activities yet.', 'ase' ) . '</p>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	/* ---------------------------
	 * Render item HTML
	 * ---------------------------*/
	public function render_activity_item( $post ) {
		$post_id = $post->ID;
		$author = get_userdata( $post->post_author );
		$permalink = get_permalink( $post_id );
		$time = get_the_date( '', $post_id );
		$excerpt = apply_filters( 'the_content', $post->post_content );

		// Reaction data
		$reaction_meta = $this->get_reaction_meta_for_post( $post_id );

		?>
		<article class="ase-activity" id="ase-activity-<?php echo esc_attr( $post_id ); ?>" data-ase-post-id="<?php echo esc_attr( $post_id ); ?>">
			<header class="ase-activity-header">
				<h2 class="ase-activity-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></h2>
				<div class="ase-activity-meta">
					<span class="ase-activity-author"><?php echo esc_html( $author ? $author->display_name : __( 'Unknown', 'ase' ) ); ?></span>
					<span class="ase-activity-time"><?php echo esc_html( $time ); ?></span>
				</div>
			</header>

			<div class="ase-activity-content">
				<?php echo wp_kses_post( $excerpt ); ?>
			</div>

			<footer class="ase-activity-footer">
				<div class="ase-reactions" data-ase-post-id="<?php echo esc_attr( $post_id ); ?>">
					<?php foreach ( $this->reactions as $key => $meta ) : 
						$count = isset( $reaction_meta[ $key ] ) ? count( (array) $reaction_meta[ $key ] ) : 0;
					?>
						<button class="ase-reaction-btn" data-reaction="<?php echo esc_attr( $key ); ?>" aria-pressed="false" title="<?php echo esc_attr( $meta['label'] ); ?>">
							<span class="ase-emoji"><?php echo esc_html( $meta['emoji'] ); ?></span>
							<span class="ase-count" data-ase-count="<?php echo esc_attr( $count ); ?>"><?php echo intval( $count ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="ase-reaction-visuals">
					<!-- optionally show avatars or names in tooltip populated by JS -->
					<div class="ase-tooltip" aria-hidden="true"></div>
				</div>

			</footer>
		</article>
		<?php
	}

	/* ---------------------------
	 * Reaction meta - storage format:
	 * post meta key: ase_reactions
	 * value: array( 'like' => array( user_id1, user_id2 ), 'love' => array( user_id3 ), ... )
	 * ---------------------------*/
	private function get_reaction_meta_for_post( $post_id ) {
		$meta = get_post_meta( $post_id, 'ase_reactions', true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		// Ensure keys exist
		foreach ( $this->reactions as $k => $v ) {
			if ( ! isset( $meta[ $k ] ) || ! is_array( $meta[ $k ] ) ) {
				$meta[ $k ] = array();
			}
		}
		return $meta;
	}

	/* ---------------------------
	 * AJAX endpoint: toggle reaction
	 * Request: POST { post_id, reaction_key, nonce }
	 * Response: JSON { success: bool, counts: {like: n, ...}, userReacted: bool }
	 * ---------------------------*/
	public function ajax_toggle_reaction() {
		// Check nonce
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce', 'ase' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$reaction = isset( $_POST['reaction'] ) ? sanitize_key( wp_unslash( $_POST['reaction'] ) ) : '';

		if ( ! $post_id || ! in_array( $reaction, array_keys( $this->reactions ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request', 'ase' ) ), 400 );
		}

		$user_id = get_current_user_id();

		// If user not logged in, use IP fingerprint fallback stored in transient? For simplicity we'll allow anonymous via session key stored in cookie
		if ( ! $user_id ) {
			// Use a per-user unique id stored in cookie
			if ( empty( $_COOKIE['ase_anonymous_id'] ) ) {
				$anon = wp_generate_uuid4();
				// set cookie for 1 year
				setcookie( 'ase_anonymous_id', $anon, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				$_COOKIE['ase_anonymous_id'] = $anon;
			}
			$user_id = 'anon_' . sanitize_text_field( wp_unslash( $_COOKIE['ase_anonymous_id'] ) );
		}

		$reactions_meta = $this->get_reaction_meta_for_post( $post_id );

		// Toggle: if user already in list, remove, else add
		$userIndex = array_search( (string) $user_id, array_map( 'strval', $reactions_meta[ $reaction ] ), true );
		$userReacted = false;

		if ( $userIndex !== false ) {
			// remove
			unset( $reactions_meta[ $reaction ][ $userIndex ] );
			$reactions_meta[ $reaction ] = array_values( $reactions_meta[ $reaction ] );
			$userReacted = false;
		} else {
			// add
			$reactions_meta[ $reaction ][] = (string) $user_id;
			$userReacted = true;
			// Optionally, prevent multiple reaction types per user (uncomment to enforce)
			/*
			foreach ( $reactions_meta as $k => $list ) {
				if ( $k !== $reaction ) {
					$reactions_meta[$k] = array_diff( $reactions_meta[$k], array( (string) $user_id ) );
				}
			}
			*/
		}

		// Save
		update_post_meta( $post_id, 'ase_reactions', $reactions_meta );

		// Build counts
		$counts = array();
		foreach ( $reactions_meta as $k => $list ) {
			$counts[ $k ] = is_array( $list ) ? count( $list ) : 0;
		}

		wp_send_json_success( array(
			'counts'      => $counts,
			'userReacted' => $userReacted,
			'post_id'     => $post_id,
			'reaction'    => $reaction,
		) );
	}

	/* ---------------------------
	 * Admin columns
	 * ---------------------------*/
	public function activity_columns( $columns ) {
		$columns_before = array();
		$columns_before['cb'] = $columns['cb'] ?? '';
		$columns_before['title'] = __( 'Title', 'ase' );
		$columns_before['author'] = __( 'Author', 'ase' );
		$columns_before['reactions'] = __( 'Reactions', 'ase' );
		$columns_before['date'] = __( 'Date', 'ase' );
		return $columns_before;
	}

	public function activity_columns_content( $column, $post_id ) {
		if ( 'reactions' === $column ) {
			$meta = $this->get_reaction_meta_for_post( $post_id );
			foreach ( $this->reactions as $k => $r ) {
				$count = isset( $meta[ $k ] ) ? count( (array) $meta[ $k ] ) : 0;
				echo '<div class="ase-admin-reaction"><strong>' . esc_html( $r['emoji'] ) . ' ' . esc_html( $r['label'] ) . ':</strong> ' . intval( $count ) . '</div>';
			}
		}
	}

	/* ---------------------------
	 * REST field for reaction counts
	 * ---------------------------*/
	public function register_rest_fields() {
		register_rest_field( 'ase_activity', 'ase_reactions', array(
			'get_callback'    => function ( $object ) {
				$post_id = $object['id'];
				$meta = $this->get_reaction_meta_for_post( $post_id );
				$counts = array();
				foreach ( $meta as $k => $list ) {
					$counts[ $k ] = is_array( $list ) ? count( $list ) : 0;
				}
				return $counts;
			},
			'schema'          => null,
		) );
	}

	/* ---------------------------
	 * Activation: optional sample content (only if no activities exist)
	 * ---------------------------*/
	public function on_activate() {
		// create a few sample activities if none
		$existing = get_posts( array( 'post_type' => 'ase_activity', 'posts_per_page' => 1 ) );
		if ( empty( $existing ) ) {
			wp_insert_post( array(
				'post_type'    => 'ase_activity',
				'post_title'   => 'Welcome to the enhanced activity stream',
				'post_content' => 'This is a sample activity you can react to. Try the 👍 ❤️ 😂 buttons!',
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id() ?: 1,
			) );
		}
	}
}

Activity_Stream_Enhancer::instance();
