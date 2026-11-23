<?php
/**
 * Public REST endpoints used by the chat widget.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {

	const NAMESPACE_V1 = 'omnithread-ai/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
					'session' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9\-]{8,64}$',
					),
					'history' => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => 60,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'role'    => array( 'type' => 'string' ),
								'content' => array( 'type' => 'string' ),
							),
						),
					),
					'page'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/feedback',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'feedback' ),
				'permission_callback' => array( __CLASS__, 'public_permission' ),
				'args'                => array(
					'session'    => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9\-]{8,64}$',
					),
					'message_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'rating'     => array(
						'type'     => 'integer',
						'required' => true,
						'enum'     => array( -1, 1 ),
					),
				),
			)
		);
	}

	/**
	 * Public endpoint: guarded by enable switch, same-origin check and rate limiting instead of capabilities.
	 */
	public static function public_permission( \WP_REST_Request $request ) {
		if ( ! Setup::published() && ! current_user_can( Admin::capability() ) ) {
			return new \WP_Error( 'otai_disabled', __( 'The assistant is currently disabled.', 'omnithread-ai' ), array( 'status' => 403 ) );
		}
		if ( Settings::get( 'strict_origin' ) && ! self::origin_allowed( $request ) ) {
			return new \WP_Error( 'otai_origin', __( 'Origin not allowed.', 'omnithread-ai' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function origin_allowed( \WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			$origin = $request->get_header( 'referer' );
		}
		if ( ! $origin ) {
			return true;
		}
		$host    = wp_parse_url( $origin, PHP_URL_HOST );
		$allowed = array_unique(
			array_filter(
				array(
					wp_parse_url( home_url(), PHP_URL_HOST ),
					wp_parse_url( site_url(), PHP_URL_HOST ),
				)
			)
		);
		/**
		 * Filter hosts allowed to call the chat API (e.g. headless front-ends).
		 */
		$allowed = (array) apply_filters( 'otai_allowed_origins', $allowed );
		return $host && in_array( strtolower( $host ), array_map( 'strtolower', $allowed ), true );
	}

	public static function chat( \WP_REST_Request $request ) {
		$limited = Rate_Limiter::check();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$result = Chat_Service::handle(
			array(
				'message' => $request->get_param( 'message' ),
				'session' => $request->get_param( 'session' ),
				'history' => $request->get_param( 'history' ),
				'page'    => $request->get_param( 'page' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	public static function feedback( \WP_REST_Request $request ) {
		$ok = Conversations::feedback( $request->get_param( 'session' ), (int) $request->get_param( 'message_id' ), (int) $request->get_param( 'rating' ) );
		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}
}
