<?php
/**
 * Provider registry.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

use OmniThreadAI\Settings;

defined( 'ABSPATH' ) || exit;

class Registry {

	private static $definitions = null;
	private static $blueprints  = array();

	/**
	 * id => [ class, label, preset (OpenAI-compatible only) ].
	 */
	public static function definitions() {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}
		$defs = array(
			'ollama'       => array( Ollama::class, 'Ollama (self-hosted)' ),
			'openai'       => array( OpenAI::class, 'OpenAI' ),
			'gemini'       => array( Gemini::class, 'Google Gemini' ),
			'anthropic'    => array( Anthropic::class, 'Anthropic Claude' ),
			'azure_openai' => array( Azure_OpenAI::class, 'Azure OpenAI' ),
			'bedrock'      => array( Bedrock::class, 'Amazon Bedrock' ),
			'mistral'      => array(
				OpenAI_Compatible::class,
				'Mistral AI',
				array(
					'base_url'    => 'https://api.mistral.ai/v1',
					'model'       => 'mistral-small-latest',
					'description' => 'European-hosted Mistral models (GDPR-friendly).',
				),
			),
			'groq'         => array(
				OpenAI_Compatible::class,
				'Groq',
				array(
					'base_url'    => 'https://api.groq.com/openai/v1',
					'model'       => 'llama-3.1-8b-instant',
					'description' => 'Ultra-low-latency inference for open models.',
				),
			),
			'deepseek'     => array(
				OpenAI_Compatible::class,
				'DeepSeek',
				array(
					'base_url'    => 'https://api.deepseek.com/v1',
					'model'       => 'deepseek-chat',
					'description' => 'Low-cost high-quality models.',
				),
			),
			'openrouter'   => array(
				OpenAI_Compatible::class,
				'OpenRouter',
				array(
					'base_url'    => 'https://openrouter.ai/api/v1',
					'model'       => 'openrouter/auto',
					'description' => 'One key for hundreds of models from many vendors.',
				),
			),
			'custom'       => array(
				OpenAI_Compatible::class,
				'Custom OpenAI-compatible',
				array(
					'base_url'     => 'http://localhost:8000/v1',
					'model'        => '',
					'key_required' => false,
					'local'        => true,
					'description'  => 'vLLM, LM Studio, LocalAI, llama.cpp server, Together, Fireworks, xAI or any /chat/completions endpoint.',
				),
			),
			'omnithread'   => array( OmniThread::class, 'OmniThread AI Backend' ),
			'agent_service' => array( Agent_Service::class, 'Agent Service (FastAPI / LangGraph)' ),
		);

		/**
		 * Register additional providers: id => [ class extending Base_Provider, label, optional preset ].
		 */
		self::$definitions = apply_filters( 'otai_providers', $defs );
		return self::$definitions;
	}

	public static function ids() {
		return array_keys( self::definitions() );
	}

	/**
	 * Build a provider instance. When $config is null the saved settings are used.
	 *
	 * @return Base_Provider|null
	 */
	public static function make( $id, $config = null ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $id ] ) ) {
			return null;
		}
		$def   = $defs[ $id ];
		$class = $def[0];
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$config = null === $config ? Settings::provider_config( $id ) : $config;
		if ( isset( $def[2] ) ) {
			return new $class( $id, $def[1], $config, $def[2] );
		}
		return new $class( $id, $def[1], $config );
	}

	/**
	 * Unconfigured instance for reading field definitions.
	 *
	 * @return Base_Provider|null
	 */
	public static function blueprint( $id ) {
		if ( ! array_key_exists( $id, self::$blueprints ) ) {
			self::$blueprints[ $id ] = self::make( $id, array() );
		}
		return self::$blueprints[ $id ];
	}

	/**
	 * Providers in the configured routing order.
	 *
	 * @return Base_Provider[]
	 */
	public static function ordered() {
		$order = array_filter( explode( ',', (string) Settings::get( 'provider_order', '' ) ) );
		$ids   = array_values( array_unique( array_merge( array_intersect( $order, self::ids() ), self::ids() ) ) );
		$out   = array();
		foreach ( $ids as $id ) {
			$provider = self::make( $id );
			if ( $provider ) {
				$out[ $id ] = $provider;
			}
		}
		return $out;
	}
}
