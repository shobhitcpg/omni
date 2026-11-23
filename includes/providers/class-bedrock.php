<?php
/**
 * Amazon Bedrock (Converse API) with AWS Signature Version 4 – no AWS SDK required.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI\Providers;

defined( 'ABSPATH' ) || exit;

class Bedrock extends Base_Provider {

	public function description() {
		return __( 'Claude, Llama, Mistral, Amazon Nova and more inside your AWS account. The IAM user needs bedrock:InvokeModel permission and model access enabled in the Bedrock console.', 'omnithread-ai' );
	}

	public function fields() {
		return array(
			'access_key'    => array(
				'label'    => __( 'AWS access key ID', 'omnithread-ai' ),
				'type'     => 'secret',
				'default'  => '',
				'required' => true,
			),
			'secret_key'    => array(
				'label'    => __( 'AWS secret access key', 'omnithread-ai' ),
				'type'     => 'secret',
				'default'  => '',
				'required' => true,
			),
			'session_token' => array(
				'label'   => __( 'Session token (optional, for temporary credentials)', 'omnithread-ai' ),
				'type'    => 'secret',
				'default' => '',
			),
			'region'        => array(
				'label'    => __( 'Region', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => 'us-east-1',
				'required' => true,
			),
			'model'         => array(
				'label'    => __( 'Model ID / inference profile', 'omnithread-ai' ),
				'type'     => 'text',
				'default'  => 'amazon.nova-lite-v1:0',
				'required' => true,
				'help'     => __( 'e.g. amazon.nova-lite-v1:0, us.anthropic.claude-3-5-haiku-20241022-v1:0, meta.llama3-1-8b-instruct-v1:0', 'omnithread-ai' ),
			),
		);
	}

	public function chat( array $messages, array $params ) {
		list( $system, $turns ) = self::split_system( $messages );
		$model                  = (string) $this->get( 'model' );
		$region                 = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $this->get( 'region' ) ) );

		$converse = array();
		foreach ( $turns as $turn ) {
			$converse[] = array(
				'role'    => $turn['role'],
				'content' => array( array( 'text' => $turn['content'] ) ),
			);
		}
		$body = array(
			'messages'        => $converse,
			'inferenceConfig' => array(
				'maxTokens'   => (int) self::param( $params, 'max_tokens', 600 ),
				'temperature' => min( 1, (float) self::param( $params, 'temperature', 0.4 ) ),
				'topP'        => (float) self::param( $params, 'top_p', 0.9 ),
			),
		);
		if ( '' !== $system ) {
			$body['system'] = array( array( 'text' => $system ) );
		}

		$json      = wp_json_encode( $body );
		$host      = 'bedrock-runtime.' . $region . '.amazonaws.com';
		$path      = '/model/' . rawurlencode( $model ) . '/converse';
		$headers   = $this->sign( 'POST', $host, $path, $json, $region, 'bedrock' );
		$response  = wp_remote_post(
			'https://' . $host . $path,
			array(
				'timeout'     => max( 5, (int) self::param( $params, 'timeout', 30 ) ),
				'headers'     => $headers,
				'body'        => $json,
				'data_format' => 'body',
				'redirection' => 0,
				'sslverify'   => true,
			)
		);
		$data = $this->decode_response( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = '';
		foreach ( isset( $data['output']['message']['content'] ) ? (array) $data['output']['message']['content'] : array() as $block ) {
			if ( isset( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}
		return self::result(
			$text,
			$model,
			isset( $data['usage']['inputTokens'] ) ? $data['usage']['inputTokens'] : 0,
			isset( $data['usage']['outputTokens'] ) ? $data['usage']['outputTokens'] : 0
		);
	}

	/**
	 * AWS SigV4 signing. Returns the headers to send (Host is set by the HTTP client but is signed).
	 */
	private function sign( $method, $host, $path, $payload, $region, $service ) {
		$access  = (string) $this->get( 'access_key' );
		$secret  = (string) $this->get( 'secret_key' );
		$token   = (string) $this->get( 'session_token' );
		$amzdate = gmdate( 'Ymd\THis\Z' );
		$date    = substr( $amzdate, 0, 8 );

		$signed = array(
			'content-type' => 'application/json',
			'host'         => $host,
			'x-amz-date'   => $amzdate,
		);
		if ( '' !== $token ) {
			$signed['x-amz-security-token'] = $token;
		}
		ksort( $signed );

		$canonical_headers = '';
		foreach ( $signed as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( $value ) . "\n";
		}
		$signed_headers = implode( ';', array_keys( $signed ) );

		// Non-S3 services require each path segment to be URI-encoded twice in the canonical request.
		$segments       = explode( '/', $path );
		$canonical_uri  = implode( '/', array_map( 'rawurlencode', $segments ) );
		$payload_hash   = hash( 'sha256', $payload );
		$canonical      = implode( "\n", array( $method, $canonical_uri, '', $canonical_headers, $signed_headers, $payload_hash ) );
		$scope          = $date . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = implode( "\n", array( 'AWS4-HMAC-SHA256', $amzdate, $scope, hash( 'sha256', $canonical ) ) );

		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$headers = array(
			'Content-Type'  => 'application/json',
			'X-Amz-Date'    => $amzdate,
			'Authorization' => sprintf( 'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s', $access, $scope, $signed_headers, $signature ),
		);
		if ( '' !== $token ) {
			$headers['X-Amz-Security-Token'] = $token;
		}
		return $headers;
	}
}
