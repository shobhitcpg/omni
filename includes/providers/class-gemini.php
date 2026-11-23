<?php
/**
 * Google Gemini (Generative Language API).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class Gemini extends Base_Provider {

	public function description() {
		return __( 'Google Gemini models. Get a key at aistudio.google.com.', 'omnithread-ai' );
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
				'default'  => 'gemini-2.5-flash',
				'required' => true,
			),
			'base_url' => array(
				'label'    => __( 'Base URL', 'omnithread-ai' ),
				'type'     => 'url',
				'default'  => 'https://generativelanguage.googleapis.com/v1beta',
				'required' => true,
			),
		);
	}

	public function supports() {
		return array( 'temperature', 'top_p', 'top_k', 'max_tokens' );
	}

	public function chat( array $messages, array $params ) {
		list( $system, $turns ) = self::split_system( $messages );
		$model                  = (string) $this->get( 'model' );

		$contents = array();
		foreach ( $turns as $turn ) {
			$contents[] = array(
				'role'  => 'assistant' === $turn['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $turn['content'] ) ),
			);
		}

		$generation = array(
			'temperature'     => (float) self::param( $params, 'temperature', 0.4 ),
			'topP'            => (float) self::param( $params, 'top_p', 0.9 ),
			'topK'            => (int) self::param( $params, 'top_k', 40 ),
			'maxOutputTokens' => (int) self::param( $params, 'max_tokens', 600 ),
		);
		// Gemini 2.5 Flash "thinks" by default and spends output tokens on it; disable for snappy chat answers.
		if ( false !== stripos( $model, '2.5-flash' ) ) {
			$generation['thinkingConfig'] = array( 'thinkingBudget' => 0 );
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => $generation,
		);
		if ( '' !== $system ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $system ) ) );
		}

		$url  = sprintf( '%s/models/%s:generateContent', untrailingslashit( (string) $this->get( 'base_url' ) ), rawurlencode( $model ) );
		$data = $this->post_json( $url, $body, array( 'x-goog-api-key' => (string) $this->get( 'api_key' ) ), self::param( $params, 'timeout', 30 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = '';
		if ( ! empty( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
					$text .= $part['text'];
				}
			}
		}
		if ( '' === $text && isset( $data['promptFeedback']['blockReason'] ) ) {
			return new \WP_Error( 'otai_blocked', sprintf( 'Gemini blocked the request: %s', $data['promptFeedback']['blockReason'] ) );
		}

		return self::result(
			$text,
			isset( $data['modelVersion'] ) ? $data['modelVersion'] : $model,
			isset( $data['usageMetadata']['promptTokenCount'] ) ? $data['usageMetadata']['promptTokenCount'] : 0,
			isset( $data['usageMetadata']['candidatesTokenCount'] ) ? $data['usageMetadata']['candidatesTokenCount'] : 0
		);
	}
}
