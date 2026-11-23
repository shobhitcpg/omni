<?php
/**
 * OmniThread AI backend (the NestJS orchestration service in the omnithreadai monorepo).
 *
 * Flow: POST {base}/sessions/init (siteApiKey, websiteContext, url) → sessionId, then POST {base}/chat/message.
 * The backend keeps its own conversation history, so only the latest user turn (with retrieved context) is sent.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class OmniThread extends Base_Provider {

	public function description() {
		return __( 'Route chats through your self-hosted OmniThread AI backend (central orchestration, analytics and vertical strategies across many sites).', 'omnithread-ai' );
	}

	public function fields() {
		return array(
			'base_url'     => array(
				'label'    => __( 'Backend API URL', 'omnithread-ai' ),
				'type'     => 'url',
				'default'  => 'http://localhost:3000/api',
				'required' => true,
			),
			'site_api_key' => array(
				'label'    => __( 'Site API key', 'omnithread-ai' ),
				'type'     => 'secret',
				'default'  => '',
				'required' => true,
				'help'     => __( 'The siteApiKey of the ClientWebsite record registered for this domain.', 'omnithread-ai' ),
			),
		);
	}

	public function supports() {
		return array();
	}

	public function chat( array $messages, array $params ) {
		list( $system, $turns ) = self::split_system( $messages );
		$last                   = end( $turns );
		if ( ! $last || 'user' !== $last['role'] ) {
			return new \WP_Error( 'otai_no_message', __( 'No user message to send.', 'omnithread-ai' ) );
		}
		$base    = untrailingslashit( (string) $this->get( 'base_url' ) );
		$timeout = self::param( $params, 'timeout', 30 );
		$headers = array( 'Origin' => home_url() );
		$local   = isset( $params['session'] ) ? (string) $params['session'] : wp_generate_uuid4();
		$key     = 'otai_omni_' . md5( $local );
		$remote  = get_transient( $key );

		if ( ! $remote ) {
			$init = $this->post_json(
				$base . '/sessions/init',
				array(
					'siteApiKey'     => (string) $this->get( 'site_api_key' ),
					'websiteContext' => wp_html_excerpt( $system, 5000 ),
					'url'            => home_url( '/' ),
				),
				$headers,
				$timeout
			);
			if ( is_wp_error( $init ) ) {
				return $init;
			}
			if ( empty( $init['success'] ) || empty( $init['data']['sessionId'] ) ) {
				return new \WP_Error( 'otai_omni_init', isset( $init['error'] ) ? (string) $init['error'] : __( 'OmniThread session init failed.', 'omnithread-ai' ) );
			}
			$remote = (string) $init['data']['sessionId'];
			set_transient( $key, $remote, DAY_IN_SECONDS );
		}

		$data = $this->post_json(
			$base . '/chat/message',
			array(
				'sessionId' => $remote,
				'message'   => empty( $params['context'] ) ? $last['content'] : "Reference context (from the website):\n" . $params['context'] . "\n\nVisitor question: " . $last['content'],
			),
			$headers,
			$timeout
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['success'] ) ) {
			delete_transient( $key );
			return new \WP_Error( 'otai_omni_message', isset( $data['error'] ) ? (string) $data['error'] : __( 'OmniThread request failed.', 'omnithread-ai' ) );
		}
		return self::result( isset( $data['data']['reply'] ) ? $data['data']['reply'] : '', 'omnithread' );
	}
}
