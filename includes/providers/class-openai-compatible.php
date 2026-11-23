<?php
/**
 * Generic OpenAI-compatible Chat Completions provider (Mistral, Groq, DeepSeek, OpenRouter, vLLM, LM Studio, LocalAI…).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class OpenAI_Compatible extends Base_Provider {

	protected $preset;

	public function __construct( $id, $label, array $config = array(), array $preset = array() ) {
		parent::__construct( $id, $label, $config );
		$this->preset = wp_parse_args(
			$preset,
			array(
				'base_url'     => '',
				'model'        => '',
				'key_required' => true,
				'description'  => '',
				'local'        => false,
			)
		);
	}

	public function description() {
		return $this->preset['description'];
	}

	public function is_local() {
		return (bool) $this->preset['local'];
	}

	public function fields() {
		return array(
			'api_key'  => array(
				'label'    => __( 'API key', 'omnithread-ai' ),
				'type'     => 'secret',
				'default'  => '',
				'required' => (bool) $this->preset['key_required'],
			),
			'base_url' => array(
				'label'    => __( 'Base URL', 'omnithread-ai' ),
				'type'     => 'url',
				'default'  => $this->preset['base_url'],
				'required' => true,
				'help'     => __( 'Endpoint root, e.g. https://api.example.com/v1 (the plugin appends /chat/completions).', 'omnithread-ai' ),
			),
			'model'    => array(
				'label'    => __( 'Model', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => $this->preset['model'],
				'required' => true,
			),
		);
	}

	public function supports() {
		return array( 'temperature', 'top_p', 'max_tokens', 'presence_penalty', 'frequency_penalty' );
	}

	protected function endpoint() {
		return untrailingslashit( (string) $this->get( 'base_url' ) ) . '/chat/completions';
	}

	protected function headers() {
		$headers = array();
		$key     = (string) $this->get( 'api_key' );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		if ( 'openrouter' === $this->id ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = get_bloginfo( 'name' );
		}
		return $headers;
	}

	protected function is_reasoning_model( $model ) {
		return (bool) preg_match( '/^(o\d|gpt-5)/i', (string) $model );
	}

	protected function build_body( array $messages, array $params, $model ) {
		list( $system, $turns ) = self::split_system( $messages );
		$payload_messages       = array();
		if ( '' !== $system ) {
			$payload_messages[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}
		$body = array(
			'model'    => $model,
			'messages' => array_merge( $payload_messages, $turns ),
			'stream'   => false,
		);

		$max_tokens = (int) self::param( $params, 'max_tokens', 600 );
		if ( $this->is_reasoning_model( $model ) ) {
			// Reasoning models reject sampling params and count hidden reasoning tokens against the limit.
			$body['max_completion_tokens'] = $max_tokens + 2000;
			return $body;
		}

		$body['temperature'] = (float) self::param( $params, 'temperature', 0.4 );
		$body['top_p']       = (float) self::param( $params, 'top_p', 0.9 );
		$body['max_tokens']  = $max_tokens;
		foreach ( array( 'presence_penalty', 'frequency_penalty' ) as $penalty ) {
			$value = (float) self::param( $params, $penalty, 0 );
			if ( 0.0 !== $value ) {
				$body[ $penalty ] = $value;
			}
		}
		return $body;
	}

	public function chat( array $messages, array $params ) {
		$model = (string) $this->get( 'model' );
		$data  = $this->post_json( $this->endpoint(), $this->build_body( $messages, $params, $model ), $this->headers(), self::param( $params, 'timeout', 30 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		return self::result(
			$text,
			isset( $data['model'] ) ? $data['model'] : $model,
			isset( $data['usage']['prompt_tokens'] ) ? $data['usage']['prompt_tokens'] : 0,
			isset( $data['usage']['completion_tokens'] ) ? $data['usage']['completion_tokens'] : 0
		);
	}
}
