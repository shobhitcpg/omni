<?php
/**
 * Azure OpenAI Service.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class Azure_OpenAI extends OpenAI_Compatible {

	public function __construct( $id, $label, array $config = array() ) {
		parent::__construct(
			$id,
			$label,
			$config,
			array( 'description' => __( 'Enterprise-grade OpenAI models hosted in your Azure tenant (data residency, private networking).', 'omnithread-ai' ) )
		);
	}

	public function fields() {
		return array(
			'api_key'         => array(
				'label'    => __( 'API key', 'omnithread-ai' ),
				'type'     => 'secret',
				'default'  => '',
				'required' => true,
			),
			'endpoint'        => array(
				'label'    => __( 'Endpoint', 'omnithread-ai' ),
				'type'     => 'url',
				'default'  => '',
				'required' => true,
				'help'     => __( 'e.g. https://my-resource.openai.azure.com', 'omnithread-ai' ),
			),
			'deployment'      => array(
				'label'    => __( 'Deployment name', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => '',
				'required' => true,
			),
			'api_version'     => array(
				'label'    => __( 'API version', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => '2024-10-21',
				'required' => true,
			),
			'reasoning_model' => array(
				'label'   => __( 'Deployment is a reasoning model (o-series / GPT-5)', 'omnithread-ai' ),
				'type'    => 'checkbox',
				'default' => 0,
			),
		);
	}

	protected function endpoint() {
		return sprintf(
			'%s/openai/deployments/%s/chat/completions?api-version=%s',
			untrailingslashit( (string) $this->get( 'endpoint' ) ),
			rawurlencode( (string) $this->get( 'deployment' ) ),
			rawurlencode( (string) $this->get( 'api_version' ) )
		);
	}

	protected function headers() {
		return array( 'api-key' => (string) $this->get( 'api_key' ) );
	}

	protected function is_reasoning_model( $model ) {
		return ! empty( $this->get( 'reasoning_model' ) );
	}

	protected function build_body( array $messages, array $params, $model ) {
		$body = parent::build_body( $messages, $params, $model );
		unset( $body['model'] );
		return $body;
	}

	public function chat( array $messages, array $params ) {
		$result = parent::chat( $messages, $params );
		if ( is_array( $result ) && '' === $result['model'] ) {
			$result['model'] = (string) $this->get( 'deployment' );
		}
		return $result;
	}
}
