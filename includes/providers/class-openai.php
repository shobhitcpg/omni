<?php
/**
 * OpenAI (api.openai.com).
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class OpenAI extends OpenAI_Compatible {

	public function __construct( $id, $label, array $config = array() ) {
		parent::__construct(
			$id,
			$label,
			$config,
			array(
				'base_url'    => 'https://api.openai.com/v1',
				'model'       => 'gpt-4o-mini',
				'description' => __( 'GPT-4o, GPT-4.1, GPT-5 and o-series models. Get a key at platform.openai.com.', 'omnithread-ai' ),
			)
		);
	}

	public function fields() {
		$fields                 = parent::fields();
		$fields['organization'] = array(
			'label'   => __( 'Organization ID (optional)', 'omnithread-ai' ),
			'type'    => 'text',
			'default' => '',
		);
		return $fields;
	}

	protected function headers() {
		$headers = parent::headers();
		$org     = (string) $this->get( 'organization' );
		if ( '' !== $org ) {
			$headers['OpenAI-Organization'] = $org;
		}
		return $headers;
	}

	protected function build_body( array $messages, array $params, $model ) {
		$body = parent::build_body( $messages, $params, $model );
		if ( isset( $body['max_tokens'] ) ) {
			$body['max_completion_tokens'] = $body['max_tokens'];
			unset( $body['max_tokens'] );
		}
		return $body;
	}
}
