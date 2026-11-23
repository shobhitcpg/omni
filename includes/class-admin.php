<?php
/**
 * Admin UI: dashboard, settings (tabs), knowledge base tools, conversations.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

use OmniThreadAI\Providers\Registry;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'omnithread-ai';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_ajax_otai_test_provider', array( __CLASS__, 'ajax_test_provider' ) );
		add_action( 'wp_ajax_otai_reindex', array( __CLASS__, 'ajax_reindex' ) );
		add_action( 'wp_ajax_otai_search_test', array( __CLASS__, 'ajax_search_test' ) );
		add_action( 'wp_ajax_otai_test_search_engine', array( __CLASS__, 'ajax_test_search_engine' ) );
		add_action( 'wp_ajax_otai_rag_action', array( __CLASS__, 'ajax_rag_action' ) );
		add_action( 'admin_post_otai_purge', array( __CLASS__, 'handle_purge' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OTAI_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'option_page_capability_' . Settings::GROUP, array( __CLASS__, 'capability' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	public static function add_meta_box() {
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		add_meta_box( 'otai-widget', __( 'OmniThread AI', 'omnithread-ai' ), array( __CLASS__, 'render_meta_box' ), array_values( $types ), 'side', 'low' );
	}

	public static function render_meta_box( $post ) {
		$value   = (string) get_post_meta( $post->ID, Frontend::META_KEY, true );
		$display = Settings::get( 'display', 'floating' );
		$labels  = array(
			'floating'       => __( 'entire site', 'omnithread-ai' ),
			'selected'       => __( 'selected pages only', 'omnithread-ai' ),
			'shortcode_only' => __( 'shortcode only', 'omnithread-ai' ),
		);
		wp_nonce_field( 'otai_meta', 'otai_meta_nonce' );
		echo '<p><label for="otai-widget-mode">' . esc_html__( 'Chat bubble on this page', 'omnithread-ai' ) . '</label></p>';
		echo '<select id="otai-widget-mode" name="otai_widget" class="widefat">';
		foreach ( array(
			''     => __( 'Use site setting', 'omnithread-ai' ),
			'show' => __( 'Always show here', 'omnithread-ai' ),
			'hide' => __( 'Never show here', 'omnithread-ai' ),
		) as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
		/* translators: %s: current display mode */
		echo '<p class="description">' . esc_html( sprintf( __( 'Site setting: %s.', 'omnithread-ai' ), isset( $labels[ $display ] ) ? $labels[ $display ] : $display ) ) . ' ';
		esc_html_e( 'To embed the chat inside the content, add the [omnithread_ai_chat] shortcode.', 'omnithread-ai' );
		echo '</p>';
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['otai_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['otai_meta_nonce'] ), 'otai_meta' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$value = isset( $_POST['otai_widget'] ) ? sanitize_key( wp_unslash( $_POST['otai_widget'] ) ) : '';
		if ( in_array( $value, array( 'show', 'hide' ), true ) ) {
			update_post_meta( $post_id, Frontend::META_KEY, $value );
		} else {
			delete_post_meta( $post_id, Frontend::META_KEY );
		}
	}

	public static function capability() {
		return apply_filters( 'otai_capability', 'manage_options' );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( Setup::url() ) . '">' . esc_html__( 'Setup', 'omnithread-ai' ) . '</a>' );
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) . '">' . esc_html__( 'Settings', 'omnithread-ai' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		$cap = self::capability();
		add_menu_page( 'OmniThread AI', 'OmniThread AI', $cap, self::SLUG, array( __CLASS__, 'page_dashboard' ), 'dashicons-format-chat', 58 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'omnithread-ai' ), __( 'Dashboard', 'omnithread-ai' ), $cap, self::SLUG, array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'omnithread-ai' ), __( 'Settings', 'omnithread-ai' ), $cap, self::SLUG . '-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'Knowledge Base', 'omnithread-ai' ), __( 'Knowledge Base', 'omnithread-ai' ), $cap, self::SLUG . '-knowledge', array( __CLASS__, 'page_knowledge' ) );
		add_submenu_page( self::SLUG, __( 'Conversations', 'omnithread-ai' ), __( 'Conversations', 'omnithread-ai' ), $cap, self::SLUG . '-conversations', array( __CLASS__, 'page_conversations' ) );
	}

	public static function register_setting() {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'OmniThreadAI\\Settings', 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'otai-admin', OTAI_URL . 'assets/css/admin.css', array(), OTAI_VERSION );
		wp_enqueue_script( 'otai-admin', OTAI_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), OTAI_VERSION, true );

		$verticals = array();
		foreach ( Verticals::all() as $key => $vertical ) {
			$verticals[ $key ] = array(
				'label'       => $vertical['label'],
				'description' => $vertical['description'],
				'params'      => $vertical['params'],
				'rationale'   => $vertical['rationale'],
				'welcome'     => $vertical['welcome'],
				'starters'    => $vertical['starters'],
			);
		}
		wp_localize_script(
			'otai-admin',
			'OTAI_ADMIN',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'otai_admin' ),
				'restUrl'   => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 . '/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'verticals' => $verticals,
				'i18n'      => array(
					'testing'    => __( 'Testing…', 'omnithread-ai' ),
					'indexing'   => __( 'Indexing…', 'omnithread-ai' ),
					'indexed'    => __( 'Content queued for background indexing.', 'omnithread-ai' ),
					'confirm'    => __( 'Delete all stored conversations? This cannot be undone.', 'omnithread-ai' ),
					'thinking'   => __( 'Thinking…', 'omnithread-ai' ),
					'noResults'  => __( 'No matching content.', 'omnithread-ai' ),
					'recommend'  => __( 'Recommended', 'omnithread-ai' ),
				),
			)
		);
	}

	public static function notices() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		if ( get_option( 'otai_needs_index' ) ) {
			printf(
				'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
				esc_html__( 'OmniThread AI: build your knowledge base so the assistant can answer from your content (free).', 'omnithread-ai' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-knowledge' ) ),
				esc_html__( 'Build now', 'omnithread-ai' )
			);
		}
		if ( Search_Engine::is_stale() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a class="button" href="%s">%s</a></p></div>',
				esc_html__( 'OmniThread AI: the Elasticsearch index missed some content changes while the search server was unreachable (the database search covered for it). Rebuild to catch up.', 'omnithread-ai' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-knowledge' ) ),
				esc_html__( 'Rebuild', 'omnithread-ai' )
			);
		}
		if ( 'local_only' !== Settings::get( 'mode' ) && ! Smart_Router::candidates() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'No AI provider is enabled and configured yet – the assistant will only answer from your FAQ and knowledge base.', 'omnithread-ai' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings&tab=providers' ) ),
				esc_html__( 'Connect a provider', 'omnithread-ai' )
			);
		}
		if ( ! Crypto::available() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The OpenSSL PHP extension is missing, so API keys cannot be encrypted at rest. Consider defining keys in wp-config.php instead.', 'omnithread-ai' ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Form helpers
	 * ------------------------------------------------------------------- */

	private static function name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	private static function row( $label, $control, $help = '', $for = '' ) {
		echo '<tr><th scope="row">' . ( $for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function checkbox( $key, $label, $help = '' ) {
		$control = sprintf(
			'<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
			esc_attr( self::name( $key ) ),
			checked( (int) Settings::get( $key ), 1, false ),
			esc_html( $label )
		);
		self::row( '', $control, $help );
	}

	private static function text( $key, $label, $help = '', $type = 'text', $attrs = '' ) {
		$id      = 'otai-' . $key;
		$class   = false === strpos( $attrs, 'class=' ) ? 'class="regular-text"' : '';
		$control = sprintf( '<input type="%s" id="%s" name="%s" value="%s" %s %s>', esc_attr( $type ), esc_attr( $id ), esc_attr( self::name( $key ) ), esc_attr( (string) Settings::get( $key ) ), $class, $attrs );
		self::row( $label, $control, $help, $id );
	}

	private static function textarea( $key, $label, $help = '', $placeholder = '', $rows = 4 ) {
		$id      = 'otai-' . $key;
		$control = sprintf( '<textarea id="%s" name="%s" rows="%d" class="large-text" placeholder="%s">%s</textarea>', esc_attr( $id ), esc_attr( self::name( $key ) ), (int) $rows, esc_attr( $placeholder ), esc_textarea( (string) Settings::get( $key ) ) );
		self::row( $label, $control, $help, $id );
	}

	private static function select( $key, $label, array $options, $help = '' ) {
		$id      = 'otai-' . $key;
		$current = (string) Settings::get( $key );
		$html    = '';
		foreach ( $options as $value => $text ) {
			$html .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, (string) $value, false ), esc_html( $text ) );
		}
		self::row( $label, sprintf( '<select id="%s" name="%s">%s</select>', esc_attr( $id ), esc_attr( self::name( $key ) ), $html ), $help, $id );
	}

	private static function slider( $key, $label, $min, $max, $step, $help = '' ) {
		$id       = 'otai-' . $key;
		$value    = Settings::get( $key );
		$vertical = Verticals::get( Settings::get( 'vertical' ) );
		$rec      = isset( $vertical['params'][ $key ] ) ? $vertical['params'][ $key ] : null;
		$control  = sprintf(
			'<div class="otai-slider"><input type="range" min="%1$s" max="%2$s" step="%3$s" value="%4$s" data-sync="%5$s"> <input type="number" id="%5$s" name="%6$s" min="%1$s" max="%2$s" step="%3$s" value="%4$s" class="small-text">%7$s</div>',
			esc_attr( $min ),
			esc_attr( $max ),
			esc_attr( $step ),
			esc_attr( $value ),
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			null !== $rec ? ' <span class="otai-rec" data-param="' . esc_attr( $key ) . '">' . esc_html__( 'Recommended', 'omnithread-ai' ) . ': <strong>' . esc_html( $rec ) . '</strong></span>' : ''
		);
		self::row( $label, $control, $help, $id );
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------- */

	public static function page_dashboard() {
		$summary  = Usage::summary( 30 );
		$totals   = $summary['totals'];
		$sources  = $summary['sources'];
		$answered = array_sum( $sources );
		$free     = ( isset( $sources['faq'] ) ? $sources['faq'] : 0 ) + ( isset( $sources['knowledge'] ) ? $sources['knowledge'] : 0 ) + ( isset( $sources['cache'] ) ? $sources['cache'] : 0 );
		$feedback = Conversations::feedback_totals();
		$vertical = Verticals::get( Settings::get( 'vertical' ) );
		$chunks   = array_sum( wp_list_pluck( Knowledge_Base::stats(), 'chunks' ) );
		$ready    = Smart_Router::candidates();
		?>
		<div class="wrap otai-wrap">
			<h1>OmniThread AI <span class="otai-badge"><?php echo esc_html( $vertical['label'] ); ?></span></h1>

			<div class="otai-checklist">
				<?php
				$checks = array(
					array( Setup::published(), __( 'Assistant published on the site', 'omnithread-ai' ), 'general' ),
					array( (bool) $ready || 'local_only' === Settings::get( 'mode' ), $ready ? sprintf( /* translators: %s: providers */ __( 'AI providers ready: %s', 'omnithread-ai' ), implode( ', ', array_map( function ( $p ) { return $p->label(); }, $ready ) ) ) : __( 'Connect at least one AI provider (or use Local-only mode)', 'omnithread-ai' ), 'providers' ),
					array( $chunks > 0, sprintf( /* translators: %d: chunks */ __( 'Knowledge base: %d passages indexed', 'omnithread-ai' ), $chunks ), 'knowledge' ),
					array( '' !== trim( (string) Settings::get( 'faq' ) ), __( 'FAQ answers added (instant, free answers)', 'omnithread-ai' ), 'knowledge' ),
					array( '' !== trim( (string) Settings::get( 'contact_info' ) ), __( 'Human hand-off contact information set', 'omnithread-ai' ), 'general' ),
				);
				foreach ( $checks as $check ) {
					printf(
						'<a class="otai-check %s" href="%s"><span class="dashicons %s"></span> %s</a>',
						$check[0] ? 'is-ok' : 'is-todo',
						esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings&tab=' . $check[2] ) ),
						$check[0] ? 'dashicons-yes-alt' : 'dashicons-marker',
						esc_html( $check[1] )
					);
				}
				?>
			</div>

			<h2><?php esc_html_e( 'Last 30 days', 'omnithread-ai' ); ?></h2>
			<div class="otai-cards">
				<div class="otai-card"><span><?php esc_html_e( 'Answers', 'omnithread-ai' ); ?></span><strong><?php echo esc_html( number_format_i18n( $answered ) ); ?></strong></div>
				<div class="otai-card"><span><?php esc_html_e( 'Answered for free', 'omnithread-ai' ); ?></span><strong><?php echo esc_html( $answered ? round( 100 * $free / $answered ) . '%' : '–' ); ?></strong><small><?php esc_html_e( 'FAQ + knowledge base + cache', 'omnithread-ai' ); ?></small></div>
				<div class="otai-card"><span><?php esc_html_e( 'Tokens (in / out)', 'omnithread-ai' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) ( isset( $totals['input_tokens'] ) ? $totals['input_tokens'] : 0 ) ) . ' / ' . number_format_i18n( (int) ( isset( $totals['output_tokens'] ) ? $totals['output_tokens'] : 0 ) ) ); ?></strong></div>
				<div class="otai-card"><span><?php esc_html_e( 'Feedback', 'omnithread-ai' ); ?></span><strong>👍 <?php echo (int) $feedback['up']; ?> · 👎 <?php echo (int) $feedback['down']; ?></strong></div>
			</div>

			<div class="otai-columns">
				<div class="otai-panel-box">
					<h2><?php esc_html_e( 'Answer sources', 'omnithread-ai' ); ?></h2>
					<table class="widefat striped"><tbody>
					<?php
					$labels = array(
						'faq'       => __( 'FAQ (free)', 'omnithread-ai' ),
						'knowledge' => __( 'Knowledge base (free)', 'omnithread-ai' ),
						'cache'     => __( 'Cache (free)', 'omnithread-ai' ),
						'llm'       => __( 'AI model', 'omnithread-ai' ),
						'fallback'  => __( 'Fallback', 'omnithread-ai' ),
					);
					foreach ( $labels as $key => $label ) {
						echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( number_format_i18n( isset( $sources[ $key ] ) ? $sources[ $key ] : 0 ) ) . '</td></tr>';
					}
					?>
					</tbody></table>

					<h2><?php esc_html_e( 'Providers', 'omnithread-ai' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Provider', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Model', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Requests', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Success', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Tokens', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Avg latency', 'omnithread-ai' ); ?></th></tr></thead>
						<tbody>
						<?php if ( ! $summary['providers'] ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'No AI calls yet.', 'omnithread-ai' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $summary['providers'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['provider'] ); ?></td>
								<td><?php echo esc_html( $row['model'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['requests'] ) ); ?></td>
								<td><?php echo esc_html( $row['requests'] ? round( 100 * $row['ok'] / $row['requests'] ) . '%' : '–' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['input_tokens'] + $row['output_tokens'] ) ); ?></td>
								<td><?php echo esc_html( round( (float) $row['latency'] ) . ' ms' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $summary['last_errors'] ) : ?>
						<h2><?php esc_html_e( 'Recent errors', 'omnithread-ai' ); ?></h2>
						<table class="widefat striped"><tbody>
						<?php foreach ( $summary['last_errors'] as $error ) : ?>
							<tr><td><?php echo esc_html( $error['created_at'] ); ?></td><td><?php echo esc_html( $error['provider'] ); ?></td><td><?php echo esc_html( $error['error'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</div>

				<div class="otai-panel-box">
					<h2><?php esc_html_e( 'Playground', 'omnithread-ai' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Test saved settings, including while unpublished. Authenticated administrators can preview here; visitors cannot. Tests may call your configured AI services and incur charges.', 'omnithread-ai' ); ?></p>
					<div id="otai-playground-log" class="otai-playground-log"></div>
					<form id="otai-playground" class="otai-playground">
						<input type="text" class="large-text" placeholder="<?php esc_attr_e( 'Ask something a visitor would ask…', 'omnithread-ai' ); ?>" required>
						<button class="button button-primary"><?php esc_html_e( 'Send', 'omnithread-ai' ); ?></button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	private static function tabs() {
		return array(
			'general'    => __( 'General & Industry', 'omnithread-ai' ),
			'providers'  => __( 'AI Providers', 'omnithread-ai' ),
			'routing'    => __( 'Smart Routing', 'omnithread-ai' ),
			'tuning'     => __( 'Model Tuning', 'omnithread-ai' ),
			'knowledge'  => __( 'Knowledge', 'omnithread-ai' ),
			'search'     => __( 'Search Engine', 'omnithread-ai' ),
			'rag'        => __( 'RAG Pipeline', 'omnithread-ai' ),
			'appearance' => __( 'Appearance', 'omnithread-ai' ),
			'privacy'    => __( 'Privacy & Security', 'omnithread-ai' ),
		);
	}

	public static function page_settings() {
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		?>
		<div class="wrap otai-wrap">
			<h1><?php esc_html_e( 'OmniThread AI Settings', 'omnithread-ai' ); ?></h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings&tab=' . $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="options.php" class="otai-settings-form">
				<?php settings_fields( Settings::GROUP ); ?>
				<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
				<p class="submit">
					<?php submit_button( __( 'Save settings', 'omnithread-ai' ), 'primary', 'submit', false ); ?>
					<?php if ( in_array( $tab, array( 'general', 'tuning' ), true ) ) : ?>
						<button type="submit" class="button" name="<?php echo esc_attr( self::name( '_apply_recommended' ) ); ?>" value="1"><?php esc_html_e( 'Save & apply recommended settings for this industry', 'omnithread-ai' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	private static function tab_general() {
		$detected  = Verticals::detect();
		$current   = Settings::get( 'vertical' );
		$verticals = Verticals::all();
		$vertical  = Verticals::get( $current );
		echo '<h2>' . esc_html__( 'Industry', 'omnithread-ai' ) . '</h2>';
		if ( $detected !== $current && 'general' !== $detected ) {
			/* translators: %s: vertical */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Based on your plugins and content types this looks like a "%s" site.', 'omnithread-ai' ), $verticals[ $detected ]['label'] ) ) . '</p></div>';
		}
		echo '<div class="otai-verticals">';
		foreach ( $verticals as $key => $v ) {
			printf(
				'<label class="otai-vertical %1$s"><input type="radio" name="%2$s" value="%3$s" %4$s><span class="dashicons %5$s"></span><strong>%6$s</strong><small>%7$s</small>%8$s</label>',
				$key === $current ? 'is-selected' : '',
				esc_attr( self::name( 'vertical' ) ),
				esc_attr( $key ),
				checked( $current, $key, false ),
				esc_attr( $v['icon'] ),
				esc_html( $v['label'] ),
				esc_html( $v['description'] ),
				$key === $detected ? '<em class="otai-detected">' . esc_html__( 'Detected', 'omnithread-ai' ) . '</em>' : ''
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Each industry ships with a tailored prompt, guardrails (e.g. no medical or financial advice), conversation starters and recommended model parameters. Use the "apply recommended" button below to load them.', 'omnithread-ai' ) . '</p>';

		echo '<h2>' . esc_html__( 'Assistant', 'omnithread-ai' ) . '</h2><table class="form-table" role="presentation">';
		self::checkbox( 'enabled', __( 'Enable the assistant on the website', 'omnithread-ai' ) );
		self::text( 'bot_name', __( 'Assistant name', 'omnithread-ai' ) );
		self::textarea( 'welcome_message', __( 'Welcome message', 'omnithread-ai' ), __( 'Leave empty to use the industry default.', 'omnithread-ai' ), $vertical['welcome'], 2 );
		self::textarea( 'persona', __( 'Extra instructions / brand voice', 'omnithread-ai' ), __( 'e.g. "Always answer in a formal tone. Mention our 30-day money-back guarantee when relevant."', 'omnithread-ai' ) );
		self::textarea( 'contact_info', __( 'Human hand-off contact', 'omnithread-ai' ), __( 'Shared when the assistant cannot help – e.g. "support@example.com, +1 555 0100, or https://example.com/contact".', 'omnithread-ai' ), '', 2 );
		self::select(
			'display',
			__( 'Where to show the chat bubble', 'omnithread-ai' ),
			array(
				'floating'       => __( 'Entire site (every page)', 'omnithread-ai' ),
				'selected'       => __( 'Only on selected pages / posts / sections', 'omnithread-ai' ),
				'shortcode_only' => __( 'No bubble – only where the [omnithread_ai_chat] shortcode is placed', 'omnithread-ai' ),
			),
			__( 'The <code>[omnithread_ai_chat]</code> shortcode always works and embeds the chat inline; on those pages the bubble is not shown.', 'omnithread-ai' )
		);
		self::select(
			'visibility',
			__( 'Show to', 'omnithread-ai' ),
			array(
				'all'       => __( 'Everyone', 'omnithread-ai' ),
				'logged_in' => __( 'Logged-in users only', 'omnithread-ai' ),
				'guests'    => __( 'Guests only', 'omnithread-ai' ),
			)
		);
		echo '</table>';

		echo '<table class="form-table otai-when-selected" role="presentation"' . ( 'selected' === Settings::get( 'display' ) ? '' : ' hidden' ) . '>';
		self::text( 'show_ids', __( 'Show on page/post IDs', 'omnithread-ai' ), __( 'Comma-separated IDs. Tip: you can also pick pages one by one with the <strong>OmniThread AI</strong> box in the page/post editor sidebar.', 'omnithread-ai' ) );
		$types   = get_post_types( array( 'public' => true ), 'objects' );
		$current = (array) Settings::get( 'show_post_types', array() );
		$boxes   = '<input type="hidden" name="' . esc_attr( self::name( 'show_post_types' ) ) . '[]" value="">';
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$boxes .= sprintf( '<label class="otai-inline-check"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr( self::name( 'show_post_types' ) ), esc_attr( $type->name ), checked( in_array( $type->name, $current, true ), true, false ), esc_html( $type->labels->name ) );
		}
		self::row( __( 'Show on every…', 'omnithread-ai' ), $boxes, __( 'e.g. every Product or every Post.', 'omnithread-ai' ) );
		self::textarea( 'show_paths', __( 'Show on URLs', 'omnithread-ai' ), __( 'One path per line; <code>*</code> is a wildcard. Examples: <code>/</code> (home page), <code>/support</code>, <code>/shop/*</code>, <code>/blog/*</code>.', 'omnithread-ai' ), "e.g. /shop/*", 3 );
		echo '</table>';

		echo '<table class="form-table otai-when-bubble" role="presentation"' . ( 'shortcode_only' === Settings::get( 'display' ) ? ' hidden' : '' ) . '>';
		self::text( 'exclude_ids', __( 'Never show on page/post IDs', 'omnithread-ai' ), __( 'Comma-separated IDs, e.g. checkout or landing pages.', 'omnithread-ai' ) );
		self::textarea( 'exclude_paths', __( 'Never show on URLs', 'omnithread-ai' ), __( 'One path per line; <code>*</code> is a wildcard, e.g. <code>/checkout/*</code>. Exclusions always win.', 'omnithread-ai' ), '', 2 );
		echo '</table>';
	}

	private static function tab_providers() {
		echo '<p>' . esc_html__( 'Enable one or more providers. In Smart mode they are tried in the order set on the Smart Routing tab, falling back automatically on errors, timeouts or daily limits. Save before testing a connection.', 'omnithread-ai' ) . '</p>';
		echo '<p class="description">' . wp_kses_post( __( 'Enterprise tip: define secrets in <code>wp-config.php</code> instead of the database, e.g. <code>define( \'OTAI_OPENAI_API_KEY\', \'sk-…\' );</code> – pattern <code>OTAI_{PROVIDER}_{FIELD}</code>.', 'omnithread-ai' ) ) . '</p>';
		$providers = Settings::get( 'providers', array() );
		echo '<div class="otai-providers">';
		foreach ( Registry::ordered() as $id => $provider ) {
			$stored = isset( $providers[ $id ] ) ? $providers[ $id ] : array();
			$base   = self::name( 'providers' ) . '[' . $id . ']';
			printf(
				'<div class="otai-provider %s" id="otai-provider-%s"><div class="otai-provider-head"><label class="otai-toggle"><input type="hidden" name="%s[enabled]" value="0"><input type="checkbox" name="%s[enabled]" value="1" %s> <strong>%s</strong></label>%s%s</div>',
				$provider->is_enabled() ? 'is-enabled' : '',
				esc_attr( $id ),
				esc_attr( $base ),
				esc_attr( $base ),
				checked( $provider->is_enabled(), true, false ),
				esc_html( $provider->label() ),
				$provider->is_local() ? '<span class="otai-pill otai-pill--free">' . esc_html__( 'Self-hosted', 'omnithread-ai' ) . '</span>' : '',
				$provider->is_configured() ? '<span class="otai-pill otai-pill--ok">' . esc_html__( 'Configured', 'omnithread-ai' ) . '</span>' : '<span class="otai-pill">' . esc_html__( 'Not configured', 'omnithread-ai' ) . '</span>'
			);
			if ( $provider->description() ) {
				echo '<p class="description">' . esc_html( $provider->description() ) . '</p>';
			}
			echo '<table class="form-table" role="presentation">';
			foreach ( $provider->fields() as $key => $field ) {
				$field_id = 'otai-' . $id . '-' . $key;
				$name     = $base . '[' . $key . ']';
				$const    = Settings::constant_name( $id, $key );
				$locked   = defined( $const );
				$value    = array_key_exists( $key, $stored ) ? $stored[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
				$help     = isset( $field['help'] ) ? $field['help'] : '';
				if ( $locked ) {
					/* translators: %s: constant name */
					$help = sprintf( __( 'Defined in wp-config.php via %s.', 'omnithread-ai' ), '<code>' . esc_html( $const ) . '</code>' );
				}
				switch ( $field['type'] ) {
					case 'secret':
						$plain   = Crypto::decrypt( $value );
						$control = sprintf(
							'<input type="password" id="%s" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s" %s>',
							esc_attr( $field_id ),
							esc_attr( $name ),
							esc_attr( $locked ? '••••••••' : ( '' !== $plain ? Crypto::mask( $plain ) : '' ) ),
							disabled( $locked, true, false )
						);
						if ( '' !== $plain && ! $locked ) {
							$control .= sprintf( ' <label><input type="checkbox" name="%s[clear_%s]" value="1"> %s</label>', esc_attr( $base ), esc_attr( $key ), esc_html__( 'Remove', 'omnithread-ai' ) );
							$help     = trim( $help . ' ' . __( 'Leave blank to keep the saved value.', 'omnithread-ai' ) );
						}
						break;
					case 'checkbox':
						$control = sprintf( '<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s %4$s>', esc_attr( $name ), esc_attr( $field_id ), checked( (int) $value, 1, false ), disabled( $locked, true, false ) );
						break;
					case 'select':
						$options = '';
						foreach ( $field['options'] as $opt_value => $opt_label ) {
							$options .= sprintf( '<option value="%s" %s>%s</option>', esc_attr( $opt_value ), selected( (string) $value, (string) $opt_value, false ), esc_html( $opt_label ) );
						}
						$control = sprintf( '<select id="%s" name="%s" %s>%s</select>', esc_attr( $field_id ), esc_attr( $name ), disabled( $locked, true, false ), $options );
						break;
					default:
						$control = sprintf(
							'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" %s>',
							'number' === $field['type'] ? 'number' : ( 'url' === $field['type'] ? 'url' : 'text' ),
							esc_attr( $field_id ),
							esc_attr( $name ),
							esc_attr( $locked ? '' : (string) $value ),
							disabled( $locked, true, false )
						);
				}
				$label = $field['label'] . ( ! empty( $field['required'] ) ? ' *' : '' );
				self::row( $label, $control, $help, $field_id );
			}
			self::row(
				__( 'Daily request-attempt limit (UTC)', 'omnithread-ai' ),
				sprintf( '<input type="number" min="0" name="%s[daily_limit]" value="%d" class="small-text">', esc_attr( $base ), isset( $stored['daily_limit'] ) ? (int) $stored['daily_limit'] : 0 ),
				__( '0 = unlimited. Atomically reserved before each model call, including failed calls and connection tests. Smart mode can try the next enabled provider. This is a request cap, not a currency/token budget; set provider-side spending limits too.', 'omnithread-ai' )
			);
			echo '</table>';
			$supports = $provider->supports();
			if ( $supports ) {
				echo '<p class="description">' . esc_html__( 'Supported tuning parameters:', 'omnithread-ai' ) . ' <code>' . esc_html( implode( ', ', $supports ) ) . '</code></p>';
			}
			printf( '<p><button type="button" class="button otai-test" data-provider="%s">%s</button> <span class="otai-test-result" aria-live="polite"></span></p></div>', esc_attr( $id ), esc_html__( 'Test connection', 'omnithread-ai' ) );
		}
		echo '</div>';
	}

	private static function tab_routing() {
		$mode  = Settings::get( 'mode' );
		$modes = array(
			'smart'      => array( __( 'Smart Query Mode (recommended)', 'omnithread-ai' ), __( 'FAQ → your content (Elasticsearch + database) → cache → self-hosted models (Ollama) → paid clouds in your order, with automatic failover. Lowest cost, highest availability.', 'omnithread-ai' ) ),
			'single'     => array( __( 'Single provider', 'omnithread-ai' ), __( 'Always use the primary provider below (after optional free local answers). Predictable behaviour and billing.', 'omnithread-ai' ) ),
			'local_only' => array( __( 'Free local only (no AI)', 'omnithread-ai' ), __( 'Answer purely from your FAQ and site content using Elasticsearch (if available) and the database search. Zero cost, no data leaves your server.', 'omnithread-ai' ) ),
		);
		echo '<h2>' . esc_html__( 'Routing mode', 'omnithread-ai' ) . '</h2><div class="otai-modes">';
		foreach ( $modes as $key => $m ) {
			printf(
				'<label class="otai-mode %s"><input type="radio" name="%s" value="%s" %s><strong>%s</strong><small>%s</small></label>',
				$key === $mode ? 'is-selected' : '',
				esc_attr( self::name( 'mode' ) ),
				esc_attr( $key ),
				checked( $mode, $key, false ),
				esc_html( $m[0] ),
				esc_html( $m[1] )
			);
		}
		echo '</div>';

		$options = array();
		foreach ( Registry::ids() as $id ) {
			$options[ $id ] = Registry::blueprint( $id )->label();
		}
		echo '<table class="form-table" role="presentation">';
		self::select( 'primary_provider', __( 'Primary provider (Single mode)', 'omnithread-ai' ), $options );

		$list = '<ul id="otai-order" class="otai-order">';
		foreach ( Registry::ordered() as $id => $provider ) {
			$state = $provider->is_enabled() && $provider->is_configured() ? 'is-active' : 'is-inactive';
			$list .= sprintf(
				'<li class="%s" data-id="%s"><span class="dashicons dashicons-menu"></span> %s %s</li>',
				esc_attr( $state ),
				esc_attr( $id ),
				esc_html( $provider->label() ),
				'is-active' === $state ? '' : '<em>(' . esc_html__( 'inactive', 'omnithread-ai' ) . ')</em>'
			);
		}
		$list .= '</ul><input type="hidden" id="otai-provider-order" name="' . esc_attr( self::name( 'provider_order' ) ) . '" value="' . esc_attr( Settings::get( 'provider_order' ) ) . '">';
		self::row( __( 'Provider order (Smart mode)', 'omnithread-ai' ), $list, __( 'Drag to reorder. Tip: put self-hosted Ollama first and a reliable cloud provider second.', 'omnithread-ai' ) );
		self::checkbox( 'free_first', __( 'Always try self-hosted (free) models such as Ollama before paid cloud providers', 'omnithread-ai' ), __( 'Keeps your order within each group.', 'omnithread-ai' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Free local answers', 'omnithread-ai' ) . '</h2>';
		/* translators: %s: search engine name */
		echo '<p class="description">' . wp_kses_post( sprintf( __( 'Site content is searched with: <strong>%s</strong>.', 'omnithread-ai' ), esc_html( Search_Engine::label( Search_Engine::engine() ) ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings&tab=search' ) ) . '">' . esc_html__( 'Change search engine', 'omnithread-ai' ) . '</a></p>';
		echo '<table class="form-table" role="presentation">';
		self::checkbox( 'local_first', __( 'Try FAQ and knowledge-base answers before calling an AI provider', 'omnithread-ai' ) );
		self::checkbox( 'local_answer_content', __( 'Allow direct (extractive) answers from site content when confidence is high', 'omnithread-ai' ), __( 'When off, only FAQ matches are answered locally; site content is still used to ground AI answers.', 'omnithread-ai' ) );
		self::slider( 'faq_threshold', __( 'FAQ match threshold', 'omnithread-ai' ), 0.3, 1, 0.05, __( 'How similar a question must be to an FAQ question. 0.6 is a good default.', 'omnithread-ai' ) );
		self::slider( 'local_threshold', __( 'Content confidence threshold', 'omnithread-ai' ), 0.3, 1, 0.05, __( 'Keyword coverage required for a direct answer. Higher = more AI calls but more fluent answers.', 'omnithread-ai' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Performance', 'omnithread-ai' ) . '</h2><table class="form-table" role="presentation">';
		self::text( 'cache_ttl', __( 'Response cache (seconds)', 'omnithread-ai' ), __( 'Identical first questions from guests are served from cache. 0 disables. 3600 = 1 hour.', 'omnithread-ai' ), 'number', 'min="0" class="small-text"' );
		self::text( 'timeout', __( 'Provider timeout (seconds)', 'omnithread-ai' ), __( 'After this, Smart mode moves to the next provider.', 'omnithread-ai' ), 'number', 'min="5" max="120"' );
		self::textarea( 'fallback_message', __( 'Fallback message', 'omnithread-ai' ), __( 'Shown when nothing could answer. Contact info is appended automatically.', 'omnithread-ai' ), '', 2 );
		echo '</table>';
	}

	private static function tab_tuning() {
		$vertical = Verticals::get( Settings::get( 'vertical' ) );
		/* translators: 1: vertical label 2: rationale */
		echo '<div class="notice notice-info inline"><p>' . wp_kses_post( sprintf( __( '<strong>Recommended for %1$s:</strong> %2$s', 'omnithread-ai' ), esc_html( $vertical['label'] ), esc_html( $vertical['rationale'] ) ) ) . '</p></div>';
		echo '<table class="form-table" role="presentation">';
		self::slider( 'temperature', __( 'Temperature', 'omnithread-ai' ), 0, 1.5, 0.05, __( 'Randomness. 0–0.3 factual & regulated (healthcare, finance, legal) · 0.4–0.6 conversational (retail, education) · 0.7+ creative (agencies).', 'omnithread-ai' ) );
		self::slider( 'top_p', __( 'Top P', 'omnithread-ai' ), 0, 1, 0.05, __( 'Nucleus sampling: only consider the most likely words whose probabilities add up to P. Lower = safer wording. Adjust temperature or top_p, not both drastically.', 'omnithread-ai' ) );
		self::slider( 'top_k', __( 'Top K', 'omnithread-ai' ), 1, 100, 1, __( 'Only sample from the K most likely next tokens (Gemini, Claude, Ollama). 20 conservative · 40 balanced · 60+ creative.', 'omnithread-ai' ) );
		self::slider( 'max_tokens', __( 'Max answer tokens', 'omnithread-ai' ), 64, 2048, 16, __( 'Caps answer length and cost (~0.75 words per token).', 'omnithread-ai' ) );
		self::slider( 'presence_penalty', __( 'Presence penalty', 'omnithread-ai' ), -2, 2, 0.1, __( 'Encourages new topics (OpenAI-compatible providers). Keep 0–0.5.', 'omnithread-ai' ) );
		self::slider( 'frequency_penalty', __( 'Frequency penalty', 'omnithread-ai' ), -2, 2, 0.1, __( 'Reduces repetition. Keep 0–0.5.', 'omnithread-ai' ) );
		self::slider( 'history_turns', __( 'Conversation memory (turns)', 'omnithread-ai' ), 0, 20, 1, __( 'Previous exchanges sent with each question. More = better follow-ups, more tokens.', 'omnithread-ai' ) );
		self::slider( 'context_chunks', __( 'Knowledge passages per question', 'omnithread-ai' ), 0, 10, 1, __( 'Site content passages injected to ground answers. 3–6 is typical.', 'omnithread-ai' ) );
		echo '</table>';
	}

	private static function tab_knowledge() {
		echo '<h2>' . esc_html__( 'Content to learn from', 'omnithread-ai' ) . '</h2><table class="form-table" role="presentation">';
		$types   = get_post_types( array( 'public' => true ), 'objects' );
		$current = (array) Settings::get( 'post_types', array() );
		$boxes   = '<input type="hidden" name="' . esc_attr( self::name( 'post_types' ) ) . '[]" value="">';
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$boxes .= sprintf( '<label class="otai-inline-check"><input type="checkbox" name="%s[]" value="%s" %s> %s <code>%s</code></label>', esc_attr( self::name( 'post_types' ) ), esc_attr( $type->name ), checked( in_array( $type->name, $current, true ), true, false ), esc_html( $type->labels->name ), esc_html( $type->name ) );
		}
		self::row( __( 'Post types', 'omnithread-ai' ), $boxes, __( 'Only published, non-password-protected content is indexed. Rebuild the index after changing this.', 'omnithread-ai' ) );
		echo '</table><table class="form-table" role="presentation">';
		self::checkbox( 'auto_sync', __( 'Keep the index in sync automatically when content is published, updated or deleted', 'omnithread-ai' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			self::checkbox( 'woo_products', __( 'WooCommerce: add live price & stock to product answers', 'omnithread-ai' ) );
			self::checkbox( 'woo_orders', __( 'WooCommerce: let logged-in customers ask about their own orders', 'omnithread-ai' ) );
		}
		self::textarea(
			'faq',
			__( 'FAQ (instant, free answers)', 'omnithread-ai' ),
			__( 'Format: blocks separated by a blank line. <code>Q:</code> question, <code>A:</code> answer, optional <code>URL:</code> link.', 'omnithread-ai' ),
			"Q: What are your opening hours?\nA: Monday to Friday, 9am–6pm.\nURL: https://example.com/contact\n\nQ: Do you offer refunds?\nA: Yes, within 30 days of purchase.",
			10
		);
		self::textarea( 'custom_knowledge', __( 'Extra knowledge', 'omnithread-ai' ), __( 'Any facts not published on the site: policies, pricing notes, internal FAQs…', 'omnithread-ai' ), '', 8 );
		echo '</table>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-knowledge' ) ) . '">' . esc_html__( 'Open Knowledge Base tools (rebuild & test search)', 'omnithread-ai' ) . '</a></p>';
	}

	private static function tab_search() {
		$ep     = Search_Engine::elasticpress();
		$engine = Search_Engine::engine();
		$error  = Search_Engine::last_error();

		echo '<h2>' . esc_html__( 'Free local search', 'omnithread-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every question is first searched in your own content – no AI cost. Elasticsearch (fuzzy matching, stemming, better ranking) is used when available; the built-in database search is always there as a fallback. Only if that cannot answer does the assistant move on to self-hosted models (Ollama) and then paid AI providers.', 'omnithread-ai' ) . '</p>';

		echo '<table class="widefat striped otai-engines"><tbody>';
		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td><td>%s</td></tr>',
			esc_html__( 'Currently answering with', 'omnithread-ai' ),
			'<span class="otai-pill otai-pill--ok">' . esc_html( Search_Engine::label( $engine ) ) . '</span>',
			Search_Engine::is_down() ? '<span class="otai-pill">' . esc_html__( 'Search server unreachable – using the database until it is back', 'omnithread-ai' ) . '</span>' : ''
		);
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $ep['label'] ),
			$ep['available'] ? '<span class="otai-pill otai-pill--ok">' . esc_html__( 'Detected', 'omnithread-ai' ) . '</span>' : '<span class="otai-pill">' . esc_html__( 'Not available', 'omnithread-ai' ) . '</span>',
			esc_html( $ep['message'] )
		);
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html__( 'Own Elasticsearch / OpenSearch server', 'omnithread-ai' ),
			Search_Engine::custom_configured() ? '<span class="otai-pill otai-pill--ok">' . esc_html__( 'Configured', 'omnithread-ai' ) . '</span>' : '<span class="otai-pill">' . esc_html__( 'Not configured', 'omnithread-ai' ) . '</span>',
			esc_html__( 'Free and open source. The plugin creates and syncs its own index.', 'omnithread-ai' )
		);
		printf(
			'<tr><td>%s</td><td><span class="otai-pill otai-pill--ok">%s</span></td><td>%s</td></tr>',
			esc_html__( 'Database search', 'omnithread-ai' ),
			esc_html__( 'Always on', 'omnithread-ai' ),
			esc_html( get_option( 'otai_fulltext' ) ? 'MySQL FULLTEXT' : __( 'Keyword (LIKE) search', 'omnithread-ai' ) )
		);
		echo '</tbody></table>';
		if ( $error ) {
			/* translators: 1: time ago 2: error */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last search server error (%1$s ago): %2$s', 'omnithread-ai' ), human_time_diff( $error['time'] ), $error['message'] ) ) . '</p>';
		}

		self::search_connection_fields( $ep );
	}

	private static function tab_rag() {
		echo '<h2>' . esc_html__( 'Retrieval-augmented answers', 'omnithread-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Keyword search works without embeddings. Semantic and hybrid search require a separately selected embedding model and your own Elasticsearch 8+ or OpenSearch 2.4+ endpoint (Search Engine tab). Existing ElasticPress mappings are never modified.', 'omnithread-ai' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		self::select( 'rag_retrieval', __( 'Retrieval mode', 'omnithread-ai' ), array(
			'keyword' => __( 'Keyword only (no embedding API)', 'omnithread-ai' ),
			'hybrid' => __( 'Hybrid: keyword + semantic, with rank fusion', 'omnithread-ai' ),
			'semantic' => __( 'Semantic only (explicit error if unavailable)', 'omnithread-ai' ),
		), __( 'Hybrid falls back to keyword search with diagnostics if semantic retrieval fails. Similarity scores are not confidence probabilities.', 'omnithread-ai' ) );
		self::select( 'rag_embed_provider', __( 'Embedding provider', 'omnithread-ai' ), array( 'openai' => 'OpenAI', 'azure' => 'Azure OpenAI', 'ollama' => 'Ollama' ) );
		self::text( 'rag_embed_model', __( 'Embedding model / deployment', 'omnithread-ai' ), __( 'OpenAI: text-embedding-3-small. Ollama: a downloaded embedding model, e.g. nomic-embed-text (768 dimensions). Chat models are selected independently in AI Providers.', 'omnithread-ai' ) );
		self::text( 'rag_embed_url', __( 'Embedding endpoint', 'omnithread-ai' ), __( 'OpenAI: base URL (empty uses https://api.openai.com/v1). Ollama: base URL (empty uses http://localhost:11434). Azure: full HTTPS deployment embeddings URL including api-version. Credentials are never put in the URL.', 'omnithread-ai' ), 'url' );
		self::secret( 'rag_embed_key', __( 'Embedding API key', 'omnithread-ai' ), __( 'Independent from the chat API key. Optional overrides: OTAI_EMBED_PROVIDER, OTAI_EMBED_MODEL, OTAI_EMBED_URL, OTAI_EMBED_KEY, OTAI_EMBED_DIMENSIONS.', 'omnithread-ai' ) );
		self::text( 'rag_embed_dimensions', __( 'Vector dimensions', 'omnithread-ai' ), __( 'Must match the actual model output. OpenAI small defaults to 1536. Changing model/dimensions selects a new plugin-owned index and reuses only compatible cached embeddings.', 'omnithread-ai' ), 'number', 'min="1" max="4096"' );
		self::checkbox( 'rag_cloud_consent', __( 'Approve embedding text and questions being sent to the configured external endpoint', 'omnithread-ai' ), __( 'Redaction applies before embedding when enabled. Local-only mode blocks cloud/remote embeddings regardless of this consent; only loopback Ollama is permitted.', 'omnithread-ai' ) );
		self::text( 'rag_embed_daily', __( 'Daily embedding text-input limit (UTC)', 'omnithread-ai' ), __( 'Atomic limit shared by ingestion and queries. Each batch input counts once; failed requests retain their reservation. It is a usage cap, not a currency estimate.', 'omnithread-ai' ), 'number', 'min="1" max="100000"' );
		self::checkbox( 'rag_grounded_only', __( 'Do not call an answer model when no site evidence is available', 'omnithread-ai' ) );
		echo '</table><h2>' . esc_html__( 'Advanced retrieval and indexing', 'omnithread-ai' ) . '</h2><table class="form-table" role="presentation">';
		self::text( 'rag_candidates', __( 'Retrieval candidate window', 'omnithread-ai' ), '', 'number', 'min="4" max="100"' );
		self::text( 'rag_rrf_constant', __( 'Rank fusion constant', 'omnithread-ai' ), __( 'Default 60. Combines independent ranks, not incompatible keyword and vector scores.', 'omnithread-ai' ), 'number', 'min="1" max="200"' );
		self::text( 'rag_chunk_chars', __( 'Passage size limit (characters)', 'omnithread-ai' ), __( 'Paragraph-aware splitting keeps unchanged sections independent of earlier edits. This is a character limit, not a tokenizer. Requeue content after changing it.', 'omnithread-ai' ), 'number', 'min="200" max="4000"' );
		self::text( 'rag_parent_chars', __( 'Neighbour context expansion (characters)', 'omnithread-ai' ), __( '0 disables. Adds authorised neighbouring passages when they fit; useful for long documents. This is bounded neighbour expansion, not a semantic section hierarchy.', 'omnithread-ai' ), 'number', 'min="0" max="12000"' );
		self::text( 'rag_context_chars', __( 'Evidence size limit (characters)', 'omnithread-ai' ), __( 'Bounds indexed evidence. Choose a limit suitable for the answer model; provider token limits still apply.', 'omnithread-ai' ), 'number', 'min="1000" max="60000"' );
		self::text( 'rag_document_chars', __( 'Document size limit (characters)', 'omnithread-ai' ), __( 'Oversized documents fail explicitly instead of being silently truncated.', 'omnithread-ai' ), 'number', 'min="4000" max="500000"' );
		self::checkbox( 'rag_queue_paused', __( 'Pause background indexing', 'omnithread-ai' ), __( 'Content/access checks remain enforced. Pending changes wait until the queue is resumed.', 'omnithread-ai' ) );
		echo '</table>';
		echo '<p>' . esc_html__( 'Save settings before testing. Embedding tests consume one text input. Queue runs may send approved source content to the embedding endpoint.', 'omnithread-ai' ) . '</p>';
		foreach ( array( 'embedding' => __( 'Test embedding', 'omnithread-ai' ), 'backend' => __( 'Test vector backend', 'omnithread-ai' ), 'run' => __( 'Process pending jobs', 'omnithread-ai' ), 'retry' => __( 'Retry failed jobs', 'omnithread-ai' ) ) as $operation => $label ) {
			echo '<button type="button" class="button otai-rag-action" data-operation="' . esc_attr( $operation ) . '">' . esc_html( $label ) . '</button> ';
		}
		echo '<p id="otai-rag-result" aria-live="polite"></p>';
		echo '<h2>' . esc_html__( 'Pipeline diagnostics', 'omnithread-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Vector index:', 'omnithread-ai' ) . ' <code>' . esc_html( RAG::index() ) . '</code></p>';
		echo '<ul>';
		foreach ( Index_Queue::stats() as $row ) {
			echo '<li>' . esc_html( $row['status'] . ': ' . $row['jobs'] ) . '</li>';
		}
		echo '</ul>';
		$error = get_option( 'otai_rag_error' );
		if ( is_array( $error ) ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'Last recorded pipeline error:', 'omnithread-ai' ) . ' ' . esc_html( $error['message'] ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Jobs use persistent leases, coalescing and five bounded retries. WordPress cron is traffic-driven; use wp omnithread-ai work from a server scheduler for reliable large-site processing. Membership-protected content requires an otai_index_post_allowed integration; only public content is supported by default.', 'omnithread-ai' ) . '</p>';
	}

	private static function search_connection_fields( array $ep ) {
		echo '<table class="form-table" role="presentation">';
		self::select(
			'search_engine',
			__( 'Search engine', 'omnithread-ai' ),
			array(
				'auto'          => __( 'Automatic (recommended): ElasticPress/VIP if present → own server → database', 'omnithread-ai' ),
				'elasticpress'  => __( 'ElasticPress / WP VIP Enterprise Search', 'omnithread-ai' ),
				'elasticsearch' => __( 'Own Elasticsearch / OpenSearch server', 'omnithread-ai' ),
				'database'      => __( 'Database only', 'omnithread-ai' ),
			),
			__( 'If the chosen engine is unavailable, the database search is used automatically.', 'omnithread-ai' )
		);
		echo '</table>';

		if ( ! $ep['available'] && ! Search_Engine::custom_configured() ) {
			echo '<div class="notice notice-info inline otai-es-install"><p><strong>' . esc_html__( 'No Elasticsearch found on this site. Want smarter free search?', 'omnithread-ai' ) . '</strong> ' . esc_html__( 'Install the free, open-source Elasticsearch or OpenSearch on your server (or use a hosted one), then enter its URL below and rebuild the knowledge base.', 'omnithread-ai' ) . '</p>';
			echo '<p>' . esc_html__( 'Elasticsearch with Docker (development – security disabled):', 'omnithread-ai' ) . '</p>';
			echo '<pre class="otai-code">docker run -d --name otai-es -p 9200:9200 -e discovery.type=single-node -e xpack.security.enabled=false -e ES_JAVA_OPTS="-Xms1g -Xmx1g" docker.elastic.co/elasticsearch/elasticsearch:8.19.4</pre>';
			echo '<p>' . esc_html__( 'OpenSearch (Apache 2.0) with Docker:', 'omnithread-ai' ) . '</p>';
			echo '<pre class="otai-code">docker run -d --name otai-os -p 9200:9200 -e discovery.type=single-node -e DISABLE_SECURITY_PLUGIN=true opensearchproject/opensearch:2</pre>';
			echo '<p>' . wp_kses_post( __( 'Without Docker: download from <a href="https://www.elastic.co/downloads/elasticsearch" target="_blank" rel="noopener">elastic.co</a> or <a href="https://opensearch.org/downloads.html" target="_blank" rel="noopener">opensearch.org</a> and run <code>bin/elasticsearch</code>. Then use URL <code>http://localhost:9200</code>. In production keep security enabled and use a username/password or API key.', 'omnithread-ai' ) ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Own Elasticsearch / OpenSearch server', 'omnithread-ai' ) . '</h2><table class="form-table" role="presentation">';
		self::text( 'es_url', __( 'Server URL', 'omnithread-ai' ), __( 'e.g. http://localhost:9200 or https://my-cluster.es.example.com:443 – must be reachable from the web server. Leave empty to not use an own server.', 'omnithread-ai' ), 'url', 'placeholder="http://localhost:9200"' );
		self::text( 'es_index', __( 'Index name', 'omnithread-ai' ), __( 'Created automatically. Leave empty for the default.', 'omnithread-ai' ), 'text', 'placeholder="' . esc_attr( Search_Engine::default_index() ) . '"' );
		self::select(
			'es_auth',
			__( 'Authentication', 'omnithread-ai' ),
			array(
				'none'    => __( 'None', 'omnithread-ai' ),
				'basic'   => __( 'Username & password', 'omnithread-ai' ),
				'api_key' => __( 'API key', 'omnithread-ai' ),
			)
		);
		self::text( 'es_username', __( 'Username', 'omnithread-ai' ), '', 'text', 'autocomplete="off"' );
		self::secret( 'es_password', __( 'Password', 'omnithread-ai' ) );
		self::secret( 'es_api_key', __( 'API key', 'omnithread-ai' ), __( 'Base64 "id:api_key" value from Elasticsearch.', 'omnithread-ai' ) );
		self::checkbox( 'es_verify_ssl', __( 'Verify SSL certificate', 'omnithread-ai' ), __( 'Only turn off for self-signed certificates on private networks.', 'omnithread-ai' ) );
		self::row(
			'',
			sprintf( '<button type="button" class="button" id="otai-test-search">%s</button> <span id="otai-test-search-result" aria-live="polite"></span>', esc_html__( 'Test connection', 'omnithread-ai' ) ),
			wp_kses_post( __( 'Save first. After connecting, go to <strong>Knowledge Base → Rebuild</strong> to fill the index. Enterprise: define <code>OTAI_ES_URL</code>, <code>OTAI_ES_INDEX</code>, <code>OTAI_ES_USERNAME</code>, <code>OTAI_ES_PASSWORD</code> or <code>OTAI_ES_API_KEY</code> in wp-config.php to keep credentials out of the database.', 'omnithread-ai' ) )
		);
		echo '</table>';
	}

	private static function secret( $key, $label, $help = '' ) {
		$id    = 'otai-' . $key;
		$plain = Crypto::decrypt( (string) Settings::get( $key ) );
		$html  = sprintf(
			'<input type="password" id="%s" name="%s" value="" class="regular-text" autocomplete="new-password" placeholder="%s">',
			esc_attr( $id ),
			esc_attr( self::name( $key ) ),
			esc_attr( '' !== $plain ? Crypto::mask( $plain ) : '' )
		);
		if ( '' !== $plain ) {
			$html .= sprintf( ' <label><input type="checkbox" name="%s" value="1"> %s</label>', esc_attr( self::name( 'clear_' . $key ) ), esc_html__( 'Remove', 'omnithread-ai' ) );
			$help  = trim( $help . ' ' . __( 'Leave blank to keep the saved value.', 'omnithread-ai' ) );
		}
		self::row( $label, $html, $help, $id );
	}

	private static function tab_appearance() {
		$vertical = Verticals::get( Settings::get( 'vertical' ) );
		echo '<table class="form-table" role="presentation">';
		self::text( 'primary_color', __( 'Brand colour', 'omnithread-ai' ), '', 'text', 'class="otai-color"' );
		self::select(
			'position',
			__( 'Bubble position', 'omnithread-ai' ),
			array(
				'right' => __( 'Bottom right', 'omnithread-ai' ),
				'left'  => __( 'Bottom left', 'omnithread-ai' ),
			)
		);
		self::text( 'launcher_label', __( 'Launcher label', 'omnithread-ai' ), __( 'Optional text next to the chat icon, e.g. "Need help?"', 'omnithread-ai' ) );
		self::textarea( 'starters', __( 'Conversation starters', 'omnithread-ai' ), __( 'One per line (max 4). Leave empty for industry defaults.', 'omnithread-ai' ), implode( "\n", $vertical['starters'] ), 4 );
		self::checkbox( 'show_sources', __( 'Show source links under answers', 'omnithread-ai' ) );
		echo '</table>';
		echo '<h2>' . esc_html__( 'Embedding', 'omnithread-ai' ) . '</h2>';
		echo '<p>' . wp_kses_post( __( 'Place an inline chat anywhere with the shortcode <code>[omnithread_ai_chat height="600px"]</code> (works in the block editor Shortcode block, classic editor and page builders). The widget renders inside a Shadow DOM so your theme’s CSS never breaks it.', 'omnithread-ai' ) ) . '</p>';
	}

	private static function tab_privacy() {
		echo '<table class="form-table" role="presentation">';
		self::checkbox( 'redact_pii', __( 'Redact emails, phone, card and ID numbers before sending text to AI providers', 'omnithread-ai' ) );
		self::checkbox( 'store_conversations', __( 'Store conversation transcripts (for review and feedback)', 'omnithread-ai' ) );
		self::text( 'retention_days', __( 'Delete transcripts after (days)', 'omnithread-ai' ), '', 'number', 'min="1" class="small-text"' );
		self::text( 'rate_limit_minute', __( 'Messages per visitor per minute', 'omnithread-ai' ), '', 'number', 'min="1" class="small-text"' );
		self::text( 'rate_limit_day', __( 'Messages per visitor per day', 'omnithread-ai' ), '', 'number', 'min="1" class="small-text"' );
		self::text( 'max_message_length', __( 'Max message length (characters)', 'omnithread-ai' ), '', 'number', 'min="50" class="small-text"' );
		self::checkbox( 'strict_origin', __( 'Reject chat API calls from other websites (same-origin check)', 'omnithread-ai' ), __( 'Headless front-ends can be allowed via the <code>otai_allowed_origins</code> filter.', 'omnithread-ai' ) );
		self::textarea( 'disclaimer', __( 'Disclaimer', 'omnithread-ai' ), __( 'Shown under the chat. Leave empty for the industry default (e.g. “not medical advice”).', 'omnithread-ai' ), Verticals::get( Settings::get( 'vertical' ) )['disclaimer'], 2 );
		self::textarea( 'consent_notice', __( 'Consent / privacy notice', 'omnithread-ai' ), __( 'e.g. “By chatting you agree to our Privacy Policy.”', 'omnithread-ai' ), '', 2 );
		self::checkbox( 'delete_on_uninstall', __( 'Delete all plugin data when the plugin is uninstalled', 'omnithread-ai' ) );
		echo '</table>';
		echo '<h2>' . esc_html__( 'Security status', 'omnithread-ai' ) . '</h2><ul class="otai-status">';
		echo '<li>' . ( Crypto::available() ? '✅ ' . esc_html__( 'API keys are encrypted at rest (AES-256 + HMAC).', 'omnithread-ai' ) : '⚠️ ' . esc_html__( 'OpenSSL missing – keys stored obfuscated only.', 'omnithread-ai' ) ) . '</li>';
		echo '<li>' . ( defined( 'OTAI_ENCRYPTION_KEY' ) ? '✅ ' . esc_html__( 'Custom OTAI_ENCRYPTION_KEY defined.', 'omnithread-ai' ) : 'ℹ️ ' . wp_kses_post( __( 'Using WordPress salts as the encryption key. Define <code>OTAI_ENCRYPTION_KEY</code> in wp-config.php to keep keys valid if salts rotate.', 'omnithread-ai' ) ) ) . '</li>';
		echo '<li>✅ ' . esc_html__( 'API keys are never sent to the browser.', 'omnithread-ai' ) . '</li>';
		echo '</ul>';
	}

	public static function page_knowledge() {
		$stats = Knowledge_Base::stats();
		$last  = (int) get_option( 'otai_last_indexed', 0 );
		?>
		<div class="wrap otai-wrap">
			<h1><?php esc_html_e( 'Knowledge Base', 'omnithread-ai' ); ?></h1>
			<p><?php esc_html_e( 'Content is indexed by a durable background queue. Rebuild queues all current sources without deleting the live index. Check RAG Pipeline diagnostics for pending or failed jobs.', 'omnithread-ai' ); ?></p>
			<div class="otai-columns">
				<div class="otai-panel-box">
					<h2><?php esc_html_e( 'Index', 'omnithread-ai' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Type', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Items', 'omnithread-ai' ); ?></th><th><?php esc_html_e( 'Passages', 'omnithread-ai' ); ?></th></tr></thead>
						<tbody>
						<?php if ( ! $stats ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'Empty – click Rebuild.', 'omnithread-ai' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $stats as $row ) : ?>
							<tr><td><code><?php echo esc_html( $row['object_type'] ); ?></code></td><td><?php echo esc_html( in_array( $row['object_type'], array( 'faq', 'custom' ), true ) ? '–' : $row['objects'] ); ?></td><td><?php echo esc_html( $row['chunks'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: date 2: search engine */
								__( 'Last full rebuild: %1$s · Search engine: %2$s', 'omnithread-ai' ),
								$last ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) : __( 'never', 'omnithread-ai' ),
								Search_Engine::label( Search_Engine::engine() )
							)
						);
						?>
					</p>
					<p><button type="button" class="button button-primary" id="otai-reindex"><?php esc_html_e( 'Rebuild knowledge base', 'omnithread-ai' ); ?></button></p>
					<div class="otai-progress" hidden><div class="otai-progress-bar"></div></div>
					<p id="otai-reindex-status" aria-live="polite"></p>
					<p class="description"><?php esc_html_e( 'Large sites: wp omnithread-ai reindex queues sources; wp omnithread-ai work processes bounded batches. Use a server scheduler for reliable processing.', 'omnithread-ai' ); ?></p>
				</div>
				<div class="otai-panel-box">
					<h2><?php esc_html_e( 'Test retrieval', 'omnithread-ai' ); ?></h2>
					<p class="description"><?php esc_html_e( 'See which FAQ and passages a question matches, and their confidence scores – without calling any AI.', 'omnithread-ai' ); ?></p>
					<form id="otai-search-test" class="otai-playground">
						<input type="text" class="large-text" placeholder="<?php esc_attr_e( 'e.g. What is your return policy?', 'omnithread-ai' ); ?>" required>
						<button class="button"><?php esc_html_e( 'Search', 'omnithread-ai' ); ?></button>
					</form>
					<div id="otai-search-results"></div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function page_conversations() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['conversation'] ) ? absint( $_GET['conversation'] ) : 0;
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$base = admin_url( 'admin.php?page=' . self::SLUG . '-conversations' );
		echo '<div class="wrap otai-wrap"><h1>' . esc_html__( 'Conversations', 'omnithread-ai' ) . '</h1>';
		if ( ! Settings::get( 'store_conversations' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Transcript storage is disabled in Privacy & Security settings.', 'omnithread-ai' ) . '</p></div>';
		}

		if ( $view ) {
			echo '<p><a href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All conversations', 'omnithread-ai' ) . '</a></p><div class="otai-transcript">';
			foreach ( Conversations::messages( $view ) as $message ) {
				$meta = $message['created_at'];
				if ( 'assistant' === $message['role'] ) {
					$meta .= ' · ' . $message['answer_source'] . ( $message['provider'] ? ' / ' . $message['provider'] : '' );
					$meta .= 1 === (int) $message['feedback'] ? ' · 👍' : ( -1 === (int) $message['feedback'] ? ' · 👎' : '' );
				}
				printf( '<div class="otai-t otai-t--%s"><div class="otai-t-body">%s</div><div class="otai-t-meta">%s</div></div>', esc_attr( 'user' === $message['role'] ? 'user' : 'bot' ), nl2br( esc_html( $message['content'] ) ), esc_html( $meta ) );
			}
			echo '</div></div>';
			return;
		}

		$per   = 20;
		$total = Conversations::count();
		$rows  = Conversations::recent( $per, ( $paged - 1 ) * $per );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Last activity (UTC)', 'omnithread-ai' ) . '</th><th>' . esc_html__( 'Messages', 'omnithread-ai' ) . '</th><th>' . esc_html__( 'User', 'omnithread-ai' ) . '</th><th>' . esc_html__( 'Page', 'omnithread-ai' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No conversations yet.', 'omnithread-ai' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$user = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">%s</a></td></tr>',
				esc_html( $row['updated_at'] ),
				(int) $row['message_count'],
				esc_html( $user ? $user->display_name : __( 'Guest', 'omnithread-ai' ) ),
				$row['page_url'] ? '<a href="' . esc_url( $row['page_url'] ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $row['page_url'], PHP_URL_PATH ) ? wp_parse_url( $row['page_url'], PHP_URL_PATH ) : '/' ) . '</a>' : '–',
				esc_url( add_query_arg( 'conversation', (int) $row['id'], $base ) ),
				esc_html__( 'View', 'omnithread-ai' )
			);
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', $base ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="otai-purge-form" style="margin-top:20px">
			<input type="hidden" name="action" value="otai_purge">
			<?php wp_nonce_field( 'otai_purge' ); ?>
			<button class="button button-link-delete"><?php esc_html_e( 'Delete all conversations', 'omnithread-ai' ); ?></button>
		</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	private static function verify_ajax() {
		check_ajax_referer( 'otai_admin' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'omnithread-ai' ) ), 403 );
		}
	}

	/**
	 * @return array|\WP_Error text, model, latency_ms.
	 */
	public static function test_provider( $id ) {
		$provider = Registry::make( sanitize_key( $id ) );
		if ( ! $provider ) {
			return new \WP_Error( 'otai_unknown', __( 'Unknown provider.', 'omnithread-ai' ) );
		}
		if ( ! $provider->is_configured() ) {
			return new \WP_Error( 'otai_unconfigured', __( 'Fill in the required fields and save first.', 'omnithread-ai' ) );
		}
		$reservation = Request_Budget::provider( $provider->id(), $provider->daily_limit() );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		$start  = microtime( true );
		$result = $provider->chat(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are a connectivity test. Reply with a very short greeting.',
				),
				array(
					'role'    => 'user',
					'content' => 'Say hello in five words or fewer.',
				),
			),
			array(
				'temperature' => 0.2,
				'top_p'       => 0.9,
				'top_k'       => 20,
				'max_tokens'  => 256,
				'timeout'     => (int) Settings::get( 'timeout', 30 ),
				'session'     => 'test-' . get_current_user_id(),
				'diagnostic'  => true,
			)
		);
		Usage::log( array(
			'provider' => $provider->id(), 'answer_source' => 'provider_test',
			'success' => ! is_wp_error( $result ),
			'model' => is_wp_error( $result ) ? '' : $result['model'],
			'input_tokens' => is_wp_error( $result ) ? 0 : $result['input_tokens'],
			'output_tokens' => is_wp_error( $result ) ? 0 : $result['output_tokens'],
			'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'error' => is_wp_error( $result ) ? $result->get_error_message() : '',
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['latency_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
		return $result;
	}

	public static function ajax_test_provider() {
		self::verify_ajax();
		$id     = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$result = self::test_provider( $id );
		if ( $id === Settings::get( 'primary_provider' ) ) {
			Setup::record_test( 'provider', $result );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				/* translators: 1: model 2: ms 3: reply */
				'message' => sprintf( __( '✅ %1$s replied in %2$d ms: “%3$s”', 'omnithread-ai' ), $result['model'], $result['latency_ms'], wp_html_excerpt( $result['text'], 80, '…' ) ),
			)
		);
	}

	public static function ajax_reindex() {
		self::verify_ajax();
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$result = Knowledge_Base::reindex_batch( $offset, 25 );
		if ( is_wp_error( $result ) ) {
			RAG::error( $result );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( $result );
	}

	public static function ajax_test_search_engine() {
		self::verify_ajax();
		Search_Engine::reset_health();
		$result = Search_Engine::ping();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => '❌ ' . $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: distribution 2: version 3: index 4: status */
					__( '✅ Connected to %1$s %2$s · index “%3$s”: %4$s', 'omnithread-ai' ),
					'opensearch' === $result['distribution'] ? 'OpenSearch' : 'Elasticsearch',
					$result['version'],
					$result['index'],
					$result['index_exists']
						/* translators: %d: number of passages */
						? sprintf( _n( '%d passage', '%d passages', $result['docs'], 'omnithread-ai' ), $result['docs'] )
						: __( 'not created yet – rebuild the knowledge base', 'omnithread-ai' )
				),
			)
		);
	}

	public static function ajax_search_test() {
		self::verify_ajax();
		$query  = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		$faq    = Knowledge_Base::match_faq( $query );
		$chunks = RAG::retrieve( $query, max( 1, (int) Settings::get( 'context_chunks', 4 ) ) );
		if ( is_wp_error( $chunks ) ) {
			wp_send_json_error( array( 'message' => $chunks->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'engine'          => Search_Engine::label( Search_Engine::$last_engine ),
				'rag'             => RAG::$trace,
				'faq'             => $faq,
				'faq_threshold'   => (float) Settings::get( 'faq_threshold' ),
				'local_threshold' => (float) Settings::get( 'local_threshold' ),
				'chunks'          => array_map(
					function ( $c ) {
						return array(
							'title'   => $c['title'],
							'url'     => $c['url'],
							'type'    => $c['type'],
							'score'   => $c['score'],
							'excerpt' => wp_html_excerpt( $c['content'], 220, '…' ),
						);
					},
					$chunks
				),
			)
		);
	}

	public static function ajax_rag_action() {
		self::verify_ajax();
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		switch ( $operation ) {
			case 'embedding':
				$result = Embeddings::embed( array( 'Connectivity test for site search.' ) );
				break;
			case 'backend':
				$result = RAG::ensure_index();
				break;
			case 'run':
				$result = Index_Queue::run( 5 );
				break;
			case 'retry':
				$result = Index_Queue::retry();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Unknown pipeline operation.', 'omnithread-ai' ) ), 400 );
		}
		if ( in_array( $operation, array( 'embedding', 'backend' ), true ) ) {
			Setup::record_test( $operation, $result );
		}
		if ( is_wp_error( $result ) || false === $result ) {
			$error = is_wp_error( $result ) ? $result : new \WP_Error( 'otai_rag_action', __( 'Cannot update pipeline jobs.', 'omnithread-ai' ) );
			RAG::error( $error );
			wp_send_json_error( array( 'message' => $error->get_error_message() ) );
		}
		if ( 'run' === $operation ) {
			$message = sprintf( __( '%d jobs completed in this bounded run. Refresh to see pending and failed jobs.', 'omnithread-ai' ), $result );
		} else {
			$message = __( 'Operation completed. Refresh to see updated diagnostics.', 'omnithread-ai' );
		}
		wp_send_json_success( array( 'message' => $message ) );
	}

	public static function handle_purge() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'omnithread-ai' ), 403 );
		}
		check_admin_referer( 'otai_purge' );
		Conversations::purge( 0 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-conversations' ) );
		exit;
	}
}
