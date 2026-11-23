<?php
/**
 * Anthropic Claude (Messages API).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class Anthropic extends Base_Provider {

	public function description() {
		return __( 'Anthropic Claude models. Get a key at console.anthropic.com.', 'omnithread-ai' );
	}

	public function fields() {
		return array(
			'api_key'  => array(
				'label'    => __( 'API key', 'omnithread-ai' ),
				'type'     => 'secret',
				'default'  => '',
				'required' => true,
			),
			'model'    => array(
				'label'    => __( 'Model', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => 'claude-haiku-4-5',
				'required' => true,
			),
			'base_url' => array(
				'label'    => __( 'Base URL', 'omnithread-ai' ),
				'type'     => 'url',
				'default'  => 'https://api.anthropic.com/v1',
				'required' => true,
			),
		);
	}

	public function supports() {
		// Recent Claude models reject temperature + top_p together, so top_p is intentionally not sent.
		return array( 'temperature', 'top_k', 'max_tokens' );
	}

	public function chat( array $messages, array $params ) {
		list( $system, $turns ) = self::split_system( $messages );
		$model                  = (string) $this->get( 'model' );

		$body = array(
			'model'       => $model,
			'max_tokens'  => (int) self::param( $params, 'max_tokens', 600 ),
			'temperature' => min( 1, (float) self::param( $params, 'temperature', 0.4 ) ),
			'top_k'       => (int) self::param( $params, 'top_k', 40 ),
			'messages'    => $turns,
		);
		if ( '' !== $system ) {
			$body['system'] = $system;
		}

		$data = $this->post_json(
			untrailingslashit( (string) $this->get( 'base_url' ) ) . '/messages',
			$body,
			array(
				'x-api-key'         => (string) $this->get( 'api_key' ),
				'anthropic-version' => '2023-06-01',
			),
			self::param( $params, 'timeout', 30 )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = '';
		foreach ( isset( $data['content'] ) ? (array) $data['content'] : array() as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return self::result(
			$text,
			isset( $data['model'] ) ? $data['model'] : $model,
			isset( $data['usage']['input_tokens'] ) ? $data['usage']['input_tokens'] : 0,
			isset( $data['usage']['output_tokens'] ) ? $data['usage']['output_tokens'] : 0
		);
	}
}
