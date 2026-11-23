<?php
/**
 * Base class for all LLM providers.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

abstract class Base_Provider {

	protected $id;
	protected $label;
	protected $config;

	public function __construct( $id, $label, array $config = array() ) {
		$this->id     = $id;
		$this->label  = $label;
		$this->config = $config;
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return $this->label;
	}

	public function description() {
		return '';
	}

	/**
	 * Field definitions: key => [ label, type (secret|text|url|number|checkbox|select), default, required, help, options ].
	 */
	abstract public function fields();

	/**
	 * Send a chat completion.
	 *
	 * @param array $messages List of [ role => system|user|assistant, content => string ].
	 * @param array $params   temperature, top_p, top_k, max_tokens, presence_penalty, frequency_penalty, timeout, session.
	 * @return array|\WP_Error [ text, model, input_tokens, output_tokens ].
	 */
	abstract public function chat( array $messages, array $params );

	/**
	 * Generation parameters honoured by this provider (for admin hints).
	 */
	public function supports() {
		return array( 'temperature', 'top_p', 'max_tokens' );
	}

	/**
	 * Self-hosted / zero marginal cost provider.
	 */
	public function is_local() {
		return false;
	}

	public function get( $key ) {
		if ( array_key_exists( $key, $this->config ) ) {
			return $this->config[ $key ];
		}
		$fields = $this->fields();
		return isset( $fields[ $key ]['default'] ) ? $fields[ $key ]['default'] : '';
	}

	public function is_enabled() {
		return ! empty( $this->config['enabled'] );
	}

	public function is_configured() {
		foreach ( $this->fields() as $key => $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) $this->get( $key ) ) ) {
				return false;
			}
		}
		return true;
	}

	public function daily_limit() {
		return isset( $this->config['daily_limit'] ) ? absint( $this->config['daily_limit'] ) : 0;
	}

	/**
	 * Separate system prompt and normalise turns so roles alternate and the first turn is from the user
	 * (required by Anthropic, Gemini and Bedrock).
	 *
	 * @return array [ string $system, array $turns ].
	 */
	protected static function split_system( array $messages ) {
		$system = array();
		$turns  = array();
		foreach ( $messages as $message ) {
			$role    = isset( $message['role'] ) ? $message['role'] : 'user';
			$content = trim( (string) ( isset( $message['content'] ) ? $message['content'] : '' ) );
			if ( '' === $content ) {
				continue;
			}
			if ( 'system' === $role ) {
				$system[] = $content;
				continue;
			}
			$role = 'assistant' === $role ? 'assistant' : 'user';
			if ( ! $turns && 'assistant' === $role ) {
				continue;
			}
			$last = count( $turns ) - 1;
			if ( $last >= 0 && $turns[ $last ]['role'] === $role ) {
				$turns[ $last ]['content'] .= "\n\n" . $content;
			} else {
				$turns[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
		}
		return array( implode( "\n\n", $system ), $turns );
	}

	/**
	 * POST JSON and decode the response.
	 *
	 * @return array|\WP_Error
	 */
	protected function post_json( $url, array $body, array $headers = array(), $timeout = 30 ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => max( 5, (int) $timeout ),
				'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
				'user-agent'  => 'OmniThreadAI/' . OTAI_VERSION . '; ' . home_url( '/' ),
			)
		);
		return $this->decode_response( $response );
	}

	protected function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'otai_http', sprintf( '%s: %s', $this->label, $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'otai_http_' . $code, sprintf( '%s (HTTP %d): %s', $this->label, $code, self::error_message( $data, $raw ) ), array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'otai_bad_json', sprintf( '%s: %s', $this->label, __( 'Invalid JSON response.', 'omnithread-ai' ) ) );
		}
		return $data;
	}

	private static function error_message( $data, $raw ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				return (string) $data['error']['message'];
			}
			if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				return $data['error'];
			}
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				return $data['message'];
			}
			if ( isset( $data[0]['error']['message'] ) ) {
				return (string) $data[0]['error']['message'];
			}
		}
		return wp_html_excerpt( wp_strip_all_tags( (string) $raw ), 200, '…' );
	}

	protected static function result( $text, $model, $input_tokens = 0, $output_tokens = 0 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return new \WP_Error( 'otai_empty', __( 'The model returned an empty response.', 'omnithread-ai' ) );
		}
		return array(
			'text'          => $text,
			'model'         => (string) $model,
			'input_tokens'  => (int) $input_tokens,
			'output_tokens' => (int) $output_tokens,
		);
	}

	protected static function param( array $params, $key, $fallback ) {
		return isset( $params[ $key ] ) && '' !== $params[ $key ] ? $params[ $key ] : $fallback;
	}
}
