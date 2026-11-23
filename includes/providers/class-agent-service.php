<?php
/**
 * Opt-in public-context bridge; never grants staff connector privileges.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

use OmniThreadAI\Site_Context;

defined( 'ABSPATH' ) || exit;

class Agent_Service extends Base_Provider {

	public function description() {
		return __( 'Optional separate FastAPI/LangGraph service. Sends public site metadata, the current question and approved retrieved context. Staff CRM/MCP credentials must never be entered here.', 'omnithread-ai' );
	}

	public function fields() {
		return array(
			'base_url' => array(
				'label' => __( 'Agent service URL', 'omnithread-ai' ), 'type' => 'url', 'default' => '',
				'required' => true,
			),
			'site_key' => array(
				'label' => __( 'Public-chat site credential (not operator key)', 'omnithread-ai' ),
				'type' => 'secret', 'default' => '', 'required' => true,
			),
			'agent_id' => array(
				'label' => __( 'Published agent ID (optional)', 'omnithread-ai' ),
				'type' => 'text', 'default' => '',
				'help' => __( 'Copy a published public agent ID from the central builder. Blank keeps the server tenant default. Staff agents cannot be used in visitor chat.', 'omnithread-ai' ),
			),
			'consent' => array(
				'label' => __( 'I approve sending the public site profile, current question and retrieved context to this service.', 'omnithread-ai' ),
				'type' => 'checkbox', 'default' => 0, 'required' => true,
			),
		);
	}

	public function supports() {
		return array();
	}

	public function allows_response_cache() {
		return false;
	}

	public function is_configured() {
		return parent::is_configured() && ! empty( $this->config['consent'] );
	}

	public function chat( array $messages, array $params ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'otai_agent_consent', __( 'Configure the agent service and explicitly approve outbound processing.', 'omnithread-ai' ) );
		}
		$base = untrailingslashit( (string) $this->get( 'base_url' ) );
		$parts = wp_parse_url( $base );
		$loopback = isset( $parts['host'] ) && in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
		if ( ! $parts || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ( 'https' !== ( $parts['scheme'] ?? '' ) && ! ( $loopback && 'http' === ( $parts['scheme'] ?? '' ) ) ) ) {
			return new \WP_Error( 'otai_agent_url', __( 'Use an HTTPS agent endpoint without URL credentials, query or fragment. HTTP is allowed only on loopback for development.', 'omnithread-ai' ) );
		}
		list( , $turns ) = self::split_system( $messages );
		$last = end( $turns );
		if ( ! $last || 'user' !== $last['role'] ) {
			return new \WP_Error( 'otai_no_message', __( 'No user message to send.', 'omnithread-ai' ) );
		}
		$context = isset( $params['context'] ) ? (string) $params['context'] : '';
		$key = (string) $this->get( 'site_key' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,256}$/D', $key ) ) {
			return new \WP_Error( 'otai_agent_key', __( 'Use a 32-256 character URL-safe random site credential.', 'omnithread-ai' ) );
		}
		if ( mb_strlen( $last['content'] ) > 4000 || mb_strlen( $context ) > 24000 ) {
			return new \WP_Error( 'otai_agent_context', __( 'Agent question/context exceeds the service limits. Reduce message or RAG context size.', 'omnithread-ai' ) );
		}
		$agent_id = trim( (string) $this->get( 'agent_id' ) );
		if ( '' !== $agent_id && ! preg_match( '/^[a-z0-9][a-z0-9_-]{2,63}$/D', $agent_id ) ) {
			return new \WP_Error( 'otai_agent_id', __( 'Use a valid published public agent ID from the central builder.', 'omnithread-ai' ) );
		}
		$body = array(
			'site' => Site_Context::profile(), 'question' => $last['content'], 'context' => $context,
			'diagnostic' => ! empty( $params['diagnostic'] ),
		);
		if ( '' !== $agent_id ) {
			$body['agent_id'] = $agent_id;
		}
		$result = $this->post_json(
			$base . '/v1/chat',
			$body,
			array( 'Authorization' => 'Bearer ' . $key ),
			self::param( $params, 'timeout', 30 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['text'], $result['model'] ) || ! is_string( $result['text'] ) || ! is_string( $result['model'] ) ) {
			return new \WP_Error( 'otai_agent_response', __( 'Agent service returned an invalid answer contract.', 'omnithread-ai' ) );
		}
		return self::result( $result['text'], $result['model'] );
	}
}
