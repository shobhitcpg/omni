<?php
/**
 * Ollama – self-hosted open models (Llama, Mistral, Qwen, Gemma, Phi…).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class Ollama extends Base_Provider {

	public function description() {
		return __( 'Run open-source models on your own server – no per-token cost and data never leaves your infrastructure. Install from ollama.com, then run e.g. "ollama pull llama3.1:8b".', 'omnithread-ai' );
	}

	public function is_local() {
		return true;
	}

	public function fields() {
		return array(
			'base_url'   => array(
				'label'    => __( 'Server URL', 'omnithread-ai' ),
				'type'     => 'url',
				'default'  => 'http://localhost:11434',
				'required' => true,
				'help'     => __( 'Must be reachable from the web server (not from the visitor’s browser).', 'omnithread-ai' ),
			),
			'model'      => array(
				'label'    => __( 'Model', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => 'llama3.1:8b',
				'required' => true,
			),
			'api_key'    => array(
				'label'   => __( 'Bearer token (optional)', 'omnithread-ai' ),
				'type'    => 'secret',
				'default' => '',
				'help'    => __( 'Only needed if Ollama sits behind an authenticating reverse proxy.', 'omnithread-ai' ),
			),
			'num_ctx'    => array(
				'label'   => __( 'Context window (num_ctx)', 'omnithread-ai' ),
				'type'    => 'number',
				'default' => 8192,
			),
			'keep_alive' => array(
				'label'   => __( 'Keep model loaded for', 'omnithread-ai' ),
				'type'    => 'text',
				'default' => '10m',
			),
		);
	}

	public function supports() {
		return array( 'temperature', 'top_p', 'top_k', 'max_tokens', 'frequency_penalty' );
	}

	public function chat( array $messages, array $params ) {
		list( $system, $turns ) = self::split_system( $messages );
		$payload                = array();
		if ( '' !== $system ) {
			$payload[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}
		$model = (string) $this->get( 'model' );
		$body  = array(
			'model'      => $model,
			'messages'   => array_merge( $payload, $turns ),
			'stream'     => false,
			'keep_alive' => (string) $this->get( 'keep_alive' ),
			'options'    => array(
				'temperature'    => (float) self::param( $params, 'temperature', 0.4 ),
				'top_p'          => (float) self::param( $params, 'top_p', 0.9 ),
				'top_k'          => (int) self::param( $params, 'top_k', 40 ),
				'num_predict'    => (int) self::param( $params, 'max_tokens', 600 ),
				'num_ctx'        => max( 2048, (int) $this->get( 'num_ctx' ) ),
				'repeat_penalty' => 1 + max( 0, (float) self::param( $params, 'frequency_penalty', 0 ) ) / 2,
			),
		);

		$headers = array();
		$token   = (string) $this->get( 'api_key' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		// Local models can be slow on first load; allow extra time.
		$timeout = max( 60, (int) self::param( $params, 'timeout', 30 ) );
		$data    = $this->post_json( untrailingslashit( (string) $this->get( 'base_url' ) ) . '/api/chat', $body, $headers, $timeout );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::result(
			isset( $data['message']['content'] ) ? $data['message']['content'] : '',
			isset( $data['model'] ) ? $data['model'] : $model,
			isset( $data['prompt_eval_count'] ) ? $data['prompt_eval_count'] : 0,
			isset( $data['eval_count'] ) ? $data['eval_count'] : 0
		);
	}
}
