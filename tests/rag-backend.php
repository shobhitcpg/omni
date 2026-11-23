<?php
/**
 * Real Elasticsearch/OpenSearch integration with deterministic local embeddings.
 *
 * Uses tests/bootstrap.php and OTAI_TEST_BACKEND_URL=http://127.0.0.1:19442
 * (Elasticsearch), or :19443 (OpenSearch). No real embedding API is called.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Settings;
use OmniThreadAI\Installer;
use OmniThreadAI\Index_Queue as Queue;
use OmniThreadAI\RAG;
use OmniThreadAI\Search_Engine;

$url = getenv( 'OTAI_TEST_BACKEND_URL' );
if ( ! in_array( $url, array( 'http://127.0.0.1:19442', 'http://127.0.0.1:19443' ), true ) ) {
	throw new RuntimeException( 'Use only a dedicated loopback integration backend.' );
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_backend_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$wpdb->prefix = $prefix;
$settings = array_merge( Settings::defaults(), array(
	'auto_sync' => 0, 'rag_retrieval' => 'hybrid', 'rag_embed_provider' => 'ollama',
	'rag_embed_model' => 'deterministic-local-test', 'rag_embed_dimensions' => 3,
	'rag_embed_url' => 'http://localhost:19441', 'es_url' => $url,
	'es_index' => 'otai-integration-' . strtolower( wp_generate_password( 10, false, false ) ),
	'search_engine' => 'elasticsearch', 'store_conversations' => 0,
) );
$filter = function () use ( $settings ) { return $settings; };
add_filter( 'pre_option_' . Settings::OPTION, $filter );
Settings::flush();
$embedding_inputs = 0;
$mock = function ( $pre, $args, $request_url ) use ( &$embedding_inputs, $url ) {
	if ( 0 === strpos( $request_url, $url . '/' ) ) {
		return $pre;
	}
	if ( 'http://localhost:19441/api/embed' !== $request_url ) {
		return new WP_Error( 'test_network', 'Unapproved test network request.' );
	}
	$input = json_decode( $args['body'], true )['input'];
	$embedding_inputs += count( $input );
	$vectors = array();
	foreach ( $input as $text ) {
		$vectors[] = preg_match( '/tuition|funding/i', $text ) ? array( 0.1, 1.0, 0.1 ) : array( 1.0, 0.1, 0.1 );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'embeddings' => $vectors ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$id = 0;
$errors = 0;
function verify_backend( $ok, $message ) {
	global $errors;
	if ( ! $ok ) {
		++$errors;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $message . "\n";
}
$saved = array();
foreach ( array( 'otai_db_version', 'otai_fulltext', 'otai_needs_index', 'otai_rag_error', 'otai_es_last_error', 'otai_es_stale', 'otai_last_indexed' ) as $key ) {
	$saved[ $key ] = get_option( $key, null );
}
try {
	Installer::install();
	$backend = RAG::backend();
	if ( is_wp_error( $backend ) ) {
		throw new RuntimeException( $backend->get_error_message() );
	}
	echo 'BACKEND ' . $backend['distribution'] . ' ' . $backend['version'] . "\n";
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Backend integration fixture',
		'post_content' => '<p>Tuition assistance is available to students enrolling in accredited courses.</p><p>Shipping deliveries take ten working days.</p>' ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	Queue::enqueue( $id );
	verify_backend( 1 === Queue::run( 1 ), 'real keyword/vector indexing job completes' );
	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT status,error FROM ' . Queue::table() . ' WHERE object_id=%d', $id ), ARRAY_A );
	if ( 'done' !== $job['status'] ) {
		throw new RuntimeException( $job['error'] );
	}
	$count = Search_Engine::request( 'GET', rawurlencode( RAG::index() ) . '/_count' );
	verify_backend( ! is_wp_error( $count ) && 3 === (int) $count['count'], 'vector index contains all three passages' );
	$result = RAG::retrieve( 'funding', 2 );
	verify_backend( ! is_wp_error( $result ) && $result && false !== strpos( $result[0]['content'], 'Tuition' ), 'actual ANN search returns the meaning-matched passage' );
	verify_backend( empty( RAG::$trace['degraded'] ), 'hybrid retrieval did not degrade to keyword only' );
	$before = $embedding_inputs;
	Queue::enqueue( $id );
	verify_backend( 1 === Queue::run( 1 ) && $before === $embedding_inputs, 'unchanged document reuses persisted vectors' );
	wp_update_post( array( 'ID' => $id, 'post_content' => '<p>Tuition assistance is available to students enrolling in approved courses.</p><p>Shipping deliveries take ten working days.</p>' ) );
	Queue::enqueue( $id );
	verify_backend( 1 === Queue::run( 1 ) && $embedding_inputs === $before + 1, 'one-word edit embeds one passage on a real backend' );
	$rows = RAG::retrieve( 'funding', 2 );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
	verify_backend( array() === RAG::hydrate( $rows ), 'private content cannot be served from stale remote vectors' );
	verify_backend( 1 === Queue::run( 1 ), 'private-content cleanup job completes' );
	$count = Search_Engine::request( 'GET', rawurlencode( RAG::index() ) . '/_count' );
	verify_backend( ! is_wp_error( $count ) && 0 === (int) $count['count'], 'obsolete vectors removed from the real index' );
} catch ( Throwable $exception ) {
	++$errors;
	echo 'FAIL ' . $exception->getMessage() . "\n";
} finally {
	if ( $id && ! is_wp_error( $id ) ) {
		wp_delete_post( $id, true );
	}
	foreach ( array( RAG::index(), Search_Engine::config()['index'] ) as $index ) {
		$result = Search_Engine::request( 'DELETE', rawurlencode( $index ) );
		if ( is_wp_error( $result ) && 'otai_es_404' !== $result->get_error_code() ) {
			++$errors;
			echo 'FAIL test-index cleanup: ' . $result->get_error_message() . "\n";
		}
	}
	foreach ( Installer::table_names() as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$table}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $filter );
	remove_filter( 'pre_http_request', $mock, 10 );
	Settings::flush();
	foreach ( $saved as $key => $value ) {
		null === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	delete_transient( 'otai_vector_backend_' . md5( $url ) );
}
echo "RESULT real backend: {$errors} failures\n";
exit( $errors ? 1 : 0 );
