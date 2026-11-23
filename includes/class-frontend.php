<?php
/**
 * Front-end widget loader and shortcode.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Frontend {

	const META_KEY = '_otai_widget';

	private static $enqueued = false;

	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_shortcode( 'omnithread_ai_chat', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Global checks shared by the floating bubble and the shortcode.
	 */
	public static function is_allowed_here() {
		if ( ! Setup::published() || is_admin() || is_feed() || wp_is_json_request() ) {
			return false;
		}
		$visibility = Settings::get( 'visibility', 'all' );
		if ( ( 'logged_in' === $visibility && ! is_user_logged_in() ) || ( 'guests' === $visibility && is_user_logged_in() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the floating bubble should load on the current request.
	 */
	public static function should_show() {
		$display = Settings::get( 'display', 'floating' );
		if ( 'shortcode_only' === $display || ! self::is_allowed_here() ) {
			return false;
		}
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;
		$override = $post_id ? (string) get_post_meta( $post_id, self::META_KEY, true ) : '';

		if ( 'hide' === $override
			|| ( $post_id && in_array( $post_id, self::ids( 'exclude_ids' ), true ) )
			|| self::path_matches( Settings::get( 'exclude_paths', '' ) ) ) {
			$show = false;
		} elseif ( 'floating' === $display ) {
			$show = true;
		} else {
			$show = 'show' === $override
				|| ( $post_id && in_array( $post_id, self::ids( 'show_ids' ), true ) )
				|| ( $post_id && in_array( get_post_type( $post_id ), (array) Settings::get( 'show_post_types', array() ), true ) )
				|| self::path_matches( Settings::get( 'show_paths', '' ) );
		}
		return (bool) apply_filters( 'otai_show_widget', $show, $post_id );
	}

	private static function ids( $key ) {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) Settings::get( $key, '' ) ) ) ) );
	}

	/**
	 * Current request path relative to the site root, e.g. "/shop/shoes/" → "/shop/shoes".
	 */
	public static function current_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && '/' !== $base && 0 === strpos( $path, $base ) ) {
			$path = '/' . substr( $path, strlen( $base ) );
		}
		$path = '/' . trim( rawurldecode( $path ), '/' );
		return $path;
	}

	/**
	 * Match the current path against one pattern per line; "*" is a wildcard ("/blog/*").
	 */
	public static function path_matches( $patterns, $path = null ) {
		$patterns = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $patterns ) ) );
		if ( ! $patterns ) {
			return false;
		}
		$path = null === $path ? self::current_path() : $path;
		foreach ( $patterns as $pattern ) {
			$pattern = '/' . trim( $pattern, '/' );
			$regex   = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	public static function maybe_enqueue() {
		if ( self::should_show() ) {
			self::enqueue( true );
		}
	}

	private static function enqueue( $floating ) {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		wp_enqueue_script( 'otai-widget', OTAI_URL . 'assets/js/widget.js', array(), OTAI_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'otai-widget', 'strategy', 'defer' );
		}
		wp_add_inline_script( 'otai-widget', 'window.OTAI_CONFIG = ' . wp_json_encode( self::config( $floating ) ) . ';', 'before' );
	}

	public static function config( $floating = true ) {
		$vertical = Verticals::get( Settings::get( 'vertical', 'general' ) );
		$starters = trim( (string) Settings::get( 'starters', '' ) );
		$starters = '' !== $starters ? array_values( array_filter( array_map( 'trim', preg_split( "/\r?\n/", $starters ) ) ) ) : $vertical['starters'];
		$welcome  = trim( (string) Settings::get( 'welcome_message', '' ) );
		$disclaim = trim( (string) Settings::get( 'disclaimer', '' ) );

		return array(
			'restUrl'       => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 . '/' ) ),
			'nonce'         => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'cssUrl'        => OTAI_URL . 'assets/css/widget.css?ver=' . OTAI_VERSION,
			'floating'      => (bool) $floating,
			'botName'       => Settings::get( 'bot_name', 'AI Assistant' ),
			'welcome'       => '' !== $welcome ? $welcome : $vertical['welcome'],
			'starters'      => array_slice( $starters, 0, 4 ),
			'disclaimer'    => '' !== $disclaim ? $disclaim : $vertical['disclaimer'],
			'consent'       => Settings::get( 'consent_notice', '' ),
			'color'         => Settings::get( 'primary_color', '#2563eb' ),
			'position'      => Settings::get( 'position', 'right' ),
			'launcherLabel' => Settings::get( 'launcher_label', '' ),
			'maxLength'     => (int) Settings::get( 'max_message_length', 1000 ),
			'historyTurns'  => (int) Settings::get( 'history_turns', 6 ),
			'storageKey'    => 'otai_chat_' . substr( md5( home_url() ), 0, 8 ),
			'i18n'          => array(
				'placeholder' => __( 'Type your message…', 'omnithread-ai' ),
				'send'        => __( 'Send', 'omnithread-ai' ),
				'open'        => __( 'Open chat', 'omnithread-ai' ),
				'close'       => __( 'Close chat', 'omnithread-ai' ),
				'reset'       => __( 'Start a new conversation', 'omnithread-ai' ),
				'error'       => __( 'Sorry, something went wrong. Please try again.', 'omnithread-ai' ),
				'sources'     => __( 'Sources', 'omnithread-ai' ),
				'helpful'     => __( 'Helpful', 'omnithread-ai' ),
				'notHelpful'  => __( 'Not helpful', 'omnithread-ai' ),
				'thanks'      => __( 'Thanks for the feedback!', 'omnithread-ai' ),
				'poweredBy'   => __( 'AI-generated answers may be inaccurate.', 'omnithread-ai' ),
			),
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'height' => '560px',
			),
			$atts,
			'omnithread_ai_chat'
		);
		if ( ! self::is_allowed_here() ) {
			return '';
		}
		self::enqueue( false );
		$height = preg_match( '/^\d+(px|vh|rem|em|%)$/', $atts['height'] ) ? $atts['height'] : '560px';
		return '<div class="otai-inline-chat" data-otai-inline="1" style="height:' . esc_attr( $height ) . ';max-width:100%;"></div>';
	}
}
