<?php
/**
 * Plugin Name: AI Title/Excerpt Generator
 * Description: Adds an AI-powered title and excerpt generator to the block editor. Uses a configurable external API via a secure WordPress REST endpoint.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 * Text Domain: ai-title-excerpt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Title_Excerpt_Generator {
	const OPTION_GROUP = 'ai_title_excerpt_options_group';
	const OPTION_NAME  = 'ai_title_excerpt_options';
	const NONCE_ACTION = 'ai_title_excerpt_nonce_action';
	const REST_NS      = 'ai-title-excerpt/v1';
	const REST_ROUTE   = '/suggest';

	public function __construct() {
		register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );

		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	public static function activate() {
		$defaults = [
			'api_url'      => '',
			'api_key'      => '',
			'model'        => '',
			'temperature'  => '0.7',
			'timeout'      => 20,
		];
		$existing = get_option( self::OPTION_NAME );
		if ( ! $existing ) {
			add_option( self::OPTION_NAME, $defaults );
		}
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, [ $this, 'sanitize_options' ] );

		add_settings_section(
			'ai_te_section_main',
			__( 'External API Settings', 'ai-title-excerpt' ),
			function() {
				echo '<p>' . esc_html__( 'Configure the external AI API endpoint and credentials. The plugin proxies requests through a secure WordPress REST route so your API key is never exposed to the browser.', 'ai-title-excerpt' ) . '</p>';
			},
			'ai_te_settings'
		);

		add_settings_field( 'api_url', __( 'API URL', 'ai-title-excerpt' ), [ $this, 'field_api_url' ], 'ai_te_settings', 'ai_te_section_main' );
		add_settings_field( 'api_key', __( 'API Key', 'ai-title-excerpt' ), [ $this, 'field_api_key' ], 'ai_te_settings', 'ai_te_section_main' );
		add_settings_field( 'model', __( 'Model (optional)', 'ai-title-excerpt' ), [ $this, 'field_model' ], 'ai_te_settings', 'ai_te_section_main' );
		add_settings_field( 'temperature', __( 'Temperature', 'ai-title-excerpt' ), [ $this, 'field_temperature' ], 'ai_te_settings', 'ai_te_section_main' );
		add_settings_field( 'timeout', __( 'HTTP Timeout (seconds)', 'ai-title-excerpt' ), [ $this, 'field_timeout' ], 'ai_te_settings', 'ai_te_section_main' );
	}

	public function sanitize_options( $opts ) {
		$opts              = is_array( $opts ) ? $opts : [];
		$sanitized         = [];
		$sanitized['api_url']     = isset( $opts['api_url'] ) ? esc_url_raw( trim( $opts['api_url'] ) ) : '';
		$sanitized['api_key']     = isset( $opts['api_key'] ) ? sanitize_text_field( $opts['api_key'] ) : '';
		$sanitized['model']       = isset( $opts['model'] ) ? sanitize_text_field( $opts['model'] ) : '';
		$sanitized['temperature'] = isset( $opts['temperature'] ) ? sanitize_text_field( $opts['temperature'] ) : '0.7';
		$sanitized['timeout']     = isset( $opts['timeout'] ) ? absint( $opts['timeout'] ) : 20;
		return $sanitized;
	}

	public function add_settings_page() {
		add_options_page(
			__( 'AI Title/Excerpt', 'ai-title-excerpt' ),
			__( 'AI Title/Excerpt', 'ai-title-excerpt' ),
			'manage_options',
			'ai_te_settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function field_api_url() {
		$opts = get_option( self::OPTION_NAME );
		echo '<input type="url" class="regular-text ltr" name="' . esc_attr( self::OPTION_NAME ) . '[api_url]" value="' . esc_attr( $opts['api_url'] ?? '' ) . '" placeholder="https://api.example.com/generate" />';
	}

	public function field_api_key() {
		$opts = get_option( self::OPTION_NAME );
		echo '<input type="password" class="regular-text ltr" name="' . esc_attr( self::OPTION_NAME ) . '[api_key]" value="' . esc_attr( $opts['api_key'] ?? '' ) . '" autocomplete="new-password" />';
	}

	public function field_model() {
		$opts = get_option( self::OPTION_NAME );
		echo '<input type="text" class="regular-text ltr" name="' . esc_attr( self::OPTION_NAME ) . '[model]" value="' . esc_attr( $opts['model'] ?? '' ) . '" placeholder="e.g. gpt-4.1-mini" />';
	}

	public function field_temperature() {
		$opts  = get_option( self::OPTION_NAME );
		$val   = isset( $opts['temperature'] ) ? $opts['temperature'] : '0.7';
		echo '<input type="number" step="0.1" min="0" max="2" class="small-text" name="' . esc_attr( self::OPTION_NAME ) . '[temperature]" value="' . esc_attr( $val ) . '" />';
	}

	public function field_timeout() {
		$opts = get_option( self::OPTION_NAME );
		$val  = isset( $opts['timeout'] ) ? absint( $opts['timeout'] ) : 20;
		echo '<input type="number" class="small-text" min="5" max="120" name="' . esc_attr( self::OPTION_NAME ) . '[timeout]" value="' . esc_attr( $val ) . '" />';
	}

	public function render_settings_page() { ?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Title/Excerpt Generator', 'ai-title-excerpt' ); ?></h1>
			<form action="options.php" method="post">
				<?php
					settings_fields( self::OPTION_GROUP );
					do_settings_sections( 'ai_te_settings' );
					submit_button();
				?>
			</form>
			<hr />
			<p><?php esc_html_e( 'Tip: Keep your API key private. Requests are proxied through the server-side REST route below:', 'ai-title-excerpt' ); ?></p>
			<code><?php echo esc_html( rest_url( self::REST_NS . self::REST_ROUTE ) ); ?></code>
		</div>
	<?php }

	public function enqueue_editor_assets() {
		$asset_handle = 'ai-title-excerpt-editor';

		// Register a dummy script file; we'll inject the JS via wp_add_inline_script to keep the plugin single-file.
		wp_register_script(
			$asset_handle,
			plugins_url( 'editor.js', __FILE__ ),
			[ 'wp-plugins', 'wp-edit-post', 'wp-components', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-i18n' ],
			'1.0.0',
			true
		);

		wp_enqueue_script( $asset_handle );

		$nonce = wp_create_nonce( 'wp_rest' );
		$opts  = get_option( self::OPTION_NAME, [] );

		wp_add_inline_script( $asset_handle, 'window.AI_TE_SETTINGS = ' . wp_json_encode( [
			'restUrl'     => esc_url_raw( rest_url( self::REST_NS . self::REST_ROUTE ) ),
			'nonce'       => $nonce,
			'hasApi'      => ! empty( $opts['api_url'] ) && ! empty( $opts['api_key'] ),
		] ) . ';', 'before' );

		// Inline the editor UI script.
		wp_add_inline_script( $asset_handle, $this->get_editor_js() );
	}

	private function get_editor_js() {
		// Note: No transpilation; keep to editor-compatible JS APIs available in modern WP.
		return <<<JS
		( function( wp ) {
			const { registerPlugin } = wp.plugins;
			const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
			const { Button, Spinner, PanelBody, Notice, SelectControl } = wp.components;
			const { __ } = wp.i18n;
			const { useState } = wp.element;
			const { select, dispatch } = wp.data;
			const apiFetch = wp.apiFetch;

			const Suggestor = () => {
				const [loading, setLoading] = useState(false);
				const [error, setError] = useState('');
				const [tone, setTone] = useState('concise');

				const doSuggest = async ( target ) => {
					setError('');
					if ( !window.AI_TE_SETTINGS?.hasApi ) {
						setError(__('API not configured. Set it in Settings → AI Title/Excerpt.', 'ai-title-excerpt'));
						return;
					}

					setLoading(true);
					try {
						const post = select('core/editor').getCurrentPost();
						const res = await apiFetch({
							path: window.AI_TE_SETTINGS.restUrl.replace(/^.*?\/wp-json\//, ''),
							method: 'POST',
							headers: { 'X-WP-Nonce': window.AI_TE_SETTINGS.nonce },
							data: {
								title: post.title || '',
								excerpt: post.excerpt?.raw || post.excerpt || '',
								content: post.content?.raw || post.content || '',
								tone,
								target
							}
						});

						if (res?.success && res?.data) {
							const { title_suggestion, excerpt_suggestion } = res.data;
							const update = {};
							if ( target === 'title' && title_suggestion ) update.title = title_suggestion;
							if ( target === 'excerpt' && excerpt_suggestion ) update.excerpt = excerpt_suggestion;
							if ( target === 'both' ) {
								if ( title_suggestion ) update.title = title_suggestion;
								if ( excerpt_suggestion ) update.excerpt = excerpt_suggestion;
							}
							dispatch('core/editor').editPost(update);
						} else {
							setError(res?.data?.message || __('Unexpected response from server', 'ai-title-excerpt'));
						}
					} catch (e) {
						setError(e?.message || __('Request failed', 'ai-title-excerpt'));
					} finally {
						setLoading(false);
					}
				};

				return wp.element.createElement( wp.element.Fragment, null,
					!window.AI_TE_SETTINGS?.hasApi && wp.element.createElement(Notice, {status: 'warning', isDismissible: false}, __('API not configured. Go to Settings → AI Title/Excerpt.', 'ai-title-excerpt')),
					wp.element.createElement(PanelBody, { title: __('AI Suggestions', 'ai-title-excerpt'), initialOpen: true },
						wp.element.createElement(SelectControl, {
							label: __('Tone / Style', 'ai-title-excerpt'),
							value: tone,
							onChange: setTone,
							options: [
								{ label: __('Concise', 'ai-title-excerpt'), value: 'concise' },
								{ label: __('Catchy', 'ai-title-excerpt'), value: 'catchy' },
								{ label: __('Professional', 'ai-title-excerpt'), value: 'professional' },
								{ label: __('Friendly', 'ai-title-excerpt'), value: 'friendly' },
							]
						}),
						wp.element.createElement(Button, {variant: 'primary', onClick: () => doSuggest('both'), disabled: loading}, loading ? wp.element.createElement(Spinner, null) : __('Generate Title + Excerpt', 'ai-title-excerpt') ),
						wp.element.createElement('div', {style: {height: '8px'}}),
						wp.element.createElement(Button, {onClick: () => doSuggest('title'), disabled: loading}, loading ? wp.element.createElement(Spinner, null) : __('Only Title', 'ai-title-excerpt') ),
						wp.element.createElement('span', {style: {margin: '0 6px'}}),
						wp.element.createElement(Button, {onClick: () => doSuggest('excerpt'), disabled: loading}, loading ? wp.element.createElement(Spinner, null) : __('Only Excerpt', 'ai-title-excerpt') ),
					),
					error && wp.element.createElement(Notice, {status: 'error', onRemove: () => setError('')}, error)
				);
			};

			const Sidebar = () => (
				wp.element.createElement( PluginSidebar, { name: 'ai-title-excerpt-sidebar', title: __('AI Title/Excerpt', 'ai-title-excerpt') },
					wp.element.createElement(Suggestor, null)
				)
			);

			registerPlugin('ai-title-excerpt', {
				render: () => (
					wp.element.createElement( wp.element.Fragment, null,
						wp.element.createElement( PluginSidebarMoreMenuItem, { target: 'ai-title-excerpt-sidebar' }, __('AI Title/Excerpt', 'ai-title-excerpt') ),
						wp.element.createElement( Sidebar, null )
					)
				)
			});

		} )( window.wp );
		JS;
	}

	public function register_rest_routes() {
		register_rest_route( self::REST_NS, self::REST_ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_suggest' ],
			'permission_callback' => function ( $request ) {
				return current_user_can( 'edit_posts' );
			},
			'args' => [
				'title'   => [ 'type' => 'string', 'required' => false ],
				'excerpt' => [ 'type' => 'string', 'required' => false ],
				'content' => [ 'type' => 'string', 'required' => false ],
				'tone'    => [ 'type' => 'string', 'required' => false, 'default' => 'concise' ],
				'target'  => [ 'type' => 'string', 'required' => false, 'enum' => [ 'title', 'excerpt', 'both' ], 'default' => 'both' ],
			],
		] );
	}

	public function handle_suggest( WP_REST_Request $request ) {
		// Check nonce header (added by apiFetch) for extra safety.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Invalid nonce', 'ai-title-excerpt' ) ], 403 );
		}

		$opts = get_option( self::OPTION_NAME );
		if ( empty( $opts['api_url'] ) || empty( $opts['api_key'] ) ) {
			return new WP_Error( 'ai_te_no_api', __( 'API not configured', 'ai-title-excerpt' ), [ 'status' => 500 ] );
		}

		$title   = wp_strip_all_tags( (string) $request->get_param( 'title' ) );
		$excerpt = wp_strip_all_tags( (string) $request->get_param( 'excerpt' ) );
		$content = (string) $request->get_param( 'content' );
		$tone    = sanitize_text_field( (string) $request->get_param( 'tone' ) );
		$target  = sanitize_text_field( (string) $request->get_param( 'target' ) );

		$payload = $this->build_api_payload( $title, $excerpt, $content, $tone, $target, $opts );

		$response = wp_remote_post( $opts['api_url'], [
			'headers' => array_filter( [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $opts['api_key'],
			] ),
			'timeout' => max( 5, (int) ( $opts['timeout'] ?? 20 ) ),
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_te_http', $response->get_error_message(), [ 'status' => 500 ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error( 'ai_te_bad_response', __( 'Unexpected API response', 'ai-title-excerpt' ), [ 'status' => 500, 'body' => $body, 'code' => $code ] );
		}

		list( $title_suggestion, $excerpt_suggestion ) = $this->extract_suggestions_from_api( $data );

		return rest_ensure_response( [
			'success' => true,
			'data'    => compact( 'title_suggestion', 'excerpt_suggestion' ),
		] );
	}

	private function build_api_payload( $title, $excerpt, $content, $tone, $target, $opts ) {
		// You can adapt this structure to your API. This is a generic, LLM-friendly payload.
		$system = 'You are a helpful assistant that writes excellent WordPress post titles (<= 70 chars) and excerpts (<= 160 chars). Return concise, human-friendly text only.';
		$user   = [
			'instructions' => 'Suggest improved title and excerpt for the following post.',
			'target'       => $target,
			'tone'         => $tone,
			'current'      => [ 'title' => $title, 'excerpt' => $excerpt ],
			'content'      => wp_strip_all_tags( $content ),
		];

		$payload = [
			'model'       => $opts['model'] ?: null,
			'temperature' => is_numeric( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7,
			'messages'    => [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => wp_json_encode( $user ) ],
			],
		];

		// Remove nulls to keep payload tidy.
		return array_filter( $payload, function ( $v ) { return ! is_null( $v ); } );
	}

	private function extract_suggestions_from_api( array $data ) {
		// Try to be flexible with popular API shapes (OpenAI/Anthropic-like, or custom JSON).
		$title = '';
		$excerpt = '';

		// 1) Generic: {title: '', excerpt: ''}
		if ( isset( $data['title'] ) || isset( $data['excerpt'] ) ) {
			$title   = isset( $data['title'] ) ? (string) $data['title'] : '';
			$excerpt = isset( $data['excerpt'] ) ? (string) $data['excerpt'] : '';
		}

		// 2) OpenAI-like: choices[0].message.content contains JSON or text lines
		if ( empty( $title ) && isset( $data['choices'][0]['message']['content'] ) ) {
			$txt = trim( (string) $data['choices'][0]['message']['content'] );
			// Try to parse JSON first
			$maybe = json_decode( $txt, true );
			if ( is_array( $maybe ) ) {
				$title   = (string) ( $maybe['title'] ?? '' );
				$excerpt = (string) ( $maybe['excerpt'] ?? '' );
			} else {
				// Fallback: naive parsing from lines
				$lines = preg_split('/\r?\n/', $txt);
				foreach ( $lines as $line ) {
					if ( ! $title && preg_match('/^\s*Title\s*[:\-]\s*(.+)$/i', $line, $m) ) { $title = trim($m[1]); }
					if ( ! $excerpt && preg_match('/^\s*Excerpt\s*[:\-]\s*(.+)$/i', $line, $m) ) { $excerpt = trim($m[1]); }
				}
			}
		}

		// 3) Anthropic-like: content[0].text
		if ( empty( $title ) && isset( $data['content'][0]['text'] ) ) {
			$txt = trim( (string) $data['content'][0]['text'] );
			$maybe = json_decode( $txt, true );
			if ( is_array( $maybe ) ) {
				$title   = (string) ( $maybe['title'] ?? '' );
				$excerpt = (string) ( $maybe['excerpt'] ?? '' );
			}
		}

		// Clip to reasonable lengths
		$title   = mb_substr( wp_strip_all_tags( $title ), 0, 70 );
		$excerpt = mb_substr( wp_strip_all_tags( $excerpt ), 0, 160 );

		return [ $title, $excerpt ];
	}
}

new AI_Title_Excerpt_Generator();
