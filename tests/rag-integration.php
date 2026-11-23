<?php
/**
 * WordPress integration and HTTP-contract tests, without external API calls.
 *
 * Run: php rag-integration.php (with the dedicated bootstrap environment).
 * Uses isolated plugin tables and deletes only the posts/tables it creates.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use OmniThreadAI\Settings;
use OmniThreadAI\Installer;
use OmniThreadAI\Knowledge_Base as KB;
use OmniThreadAI\Index_Queue as Queue;
use OmniThreadAI\Embeddings;
use OmniThreadAI\RAG;
use OmniThreadAI\Smart_Router;
use OmniThreadAI\Chat_Service;

if ( ! class_exists( RAG::class ) ) {
	fwrite( STDERR, "Activate OmniThread AI before running these tests.\n" );
	exit( 2 );
}
$failures = 0;
$checks = 0;
function check( $condition, $label ) {
	global $failures, $checks;
	++$checks;
	if ( ! $condition ) {
		++$failures;
	}
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function response( array $data, $code = 200 ) {
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array() );
}
function test_vector( $text ) {
	$v = array( 0.1, 0.1, 0.1 );
	if ( preg_match( '/tuition|funding|scholarship/i', $text ) ) {
		$v[1] = 1.0;
	} elseif ( preg_match( '/cookie|privacy/i', $text ) ) {
		$v[2] = 1.0;
	} else {
		$v[0] = 1.0;
	}
	return $v;
}
$original_prefix = $wpdb->prefix;
$prefix = $original_prefix . 'otai_test_' . strtolower( wp_generate_password( 8, false, false ) ) . '_';
$wpdb->prefix = $prefix;
$options = array();
foreach ( array( 'otai_db_version', 'otai_needs_index', 'otai_fulltext', 'otai_rag_error', 'otai_last_indexed', 'otai_last_reconcile', 'otai_reconcile_cursor' ) as $key ) {
	$options[ $key ] = get_option( $key, null );
}
$test_settings = array_merge( Settings::defaults(), array(
	'auto_sync' => 0, 'rag_retrieval' => 'hybrid', 'rag_embed_provider' => 'ollama',
	'rag_embed_model' => 'fixture-model', 'rag_embed_dimensions' => 3,
	'rag_embed_url' => 'http://localhost:19441', 'es_url' => 'http://localhost:19440',
	'search_engine' => 'database', 'store_conversations' => 0,
) );
$settings_filter = function () use ( &$test_settings ) { return $test_settings; };
add_filter( 'pre_option_' . Settings::OPTION, $settings_filter );
Settings::flush();
$posts = array();
$server = array();
$embedded = 0;
$http_calls = 0;
$distribution = 'elasticsearch';
$malformed = false;
$during_embed = null;
$contracts = array();
$mock = function ( $pre, $args, $url ) use ( &$server, &$embedded, &$http_calls, &$distribution, &$malformed, &$contracts, &$during_embed ) {
	if ( ! preg_match( '#^https?://localhost:1944#', $url ) ) {
		return new WP_Error( 'test_external', 'Unexpected network call blocked by integration test.' );
	}
	++$http_calls;
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( false !== strpos( $path, 'embeddings' ) ) {
		$body = json_decode( $args['body'], true );
		$contracts[] = array( 'body' => $body, 'headers' => $args['headers'], 'url' => $url, 'redirects' => $args['redirection'] );
		$data = array();
		foreach ( $body['input'] as $i => $text ) {
			$data[] = array( 'index' => $i, 'embedding' => test_vector( $text ) );
		}
		return response( array( 'data' => array_reverse( $data ) ) );
	}
	if ( '/api/embed' === $path ) {
		$body = json_decode( $args['body'], true );
		$embedded += count( $body['input'] );
		$vectors = array_map( 'test_vector', $body['input'] );
		if ( $malformed ) {
			array_pop( $vectors );
		}
		if ( $during_embed ) {
			$callback = $during_embed;
			$during_embed = null;
			$callback();
		}
		return response( array( 'embeddings' => $vectors ) );
	}
	if ( '/' === $path ) {
		return response( array( 'version' => array( 'number' => 'opensearch' === $distribution ? '2.19.0' : '8.19.4', 'distribution' => $distribution ) ) );
	}
	$parts = explode( '/', trim( $path, '/' ) );
	$index = $parts[0];
	if ( 'PUT' === $args['method'] && count( $parts ) === 1 ) {
		$server[ $index ] = array( 'mapping' => json_decode( $args['body'], true ), 'docs' => array() );
		return response( array( 'acknowledged' => true ) );
	}
	if ( ! isset( $server[ $index ] ) ) {
		return response( array( 'error' => array( 'reason' => 'index missing' ) ), 404 );
	}
	if ( '_mapping' === ( $parts[1] ?? '' ) ) {
		return response( array( $index => array( 'mappings' => $server[ $index ]['mapping']['mappings'] ) ) );
	}
	if ( '_bulk' === ( $parts[1] ?? '' ) ) {
		$lines = explode( "\n", trim( $args['body'] ) );
		$items = array();
		for ( $i = 0; $i < count( $lines ); $i += 2 ) {
			$meta = json_decode( $lines[ $i ], true );
			$doc = json_decode( $lines[ $i + 1 ], true );
			$server[ $index ]['docs'][ $meta['index']['_id'] ] = $doc;
			$items[] = array( 'index' => array( 'status' => 201 ) );
		}
		return response( array( 'errors' => false, 'items' => $items ) );
	}
	if ( '_delete_by_query' === ( $parts[1] ?? '' ) ) {
		$body = json_decode( $args['body'], true );
		$id = $body['query']['bool']['filter'][0]['term']['object_id'];
		$keep = $body['query']['bool']['must_not'][0]['ids']['values'] ?? array();
		$deleted = 0;
		foreach ( $server[ $index ]['docs'] as $key => $doc ) {
			if ( (int) $doc['object_id'] === (int) $id && ! in_array( (string) $key, $keep, true ) ) {
				unset( $server[ $index ]['docs'][ $key ] );
				++$deleted;
			}
		}
		return response( array( 'deleted' => $deleted, 'failures' => array(), 'timed_out' => false ) );
	}
	if ( '_search' === ( $parts[1] ?? '' ) ) {
		$body = json_decode( $args['body'], true );
		$vector = $body['knn']['query_vector'] ?? ( $body['query']['knn']['vector']['vector'] ?? array() );
		$hits = array();
		foreach ( $server[ $index ]['docs'] as $doc ) {
			$score = 0.0;
			foreach ( $vector as $i => $value ) {
				$score += $value * $doc['vector'][ $i ];
			}
			$hits[] = array( '_score' => $score, '_source' => $doc );
		}
		usort( $hits, function ( $a, $b ) { return $b['_score'] <=> $a['_score']; } );
		return response( array( 'hits' => array( 'hits' => array_slice( $hits, 0, $body['size'] ) ) ) );
	}
	return new WP_Error( 'test_contract', 'Unexpected search contract: ' . $path );
};
add_filter( 'pre_http_request', $mock, 10, 3 );

try {
	Installer::install();
	$id = wp_insert_post( array(
		'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'RAG fixture',
		'post_content' => "<p>Tuition assistance is available for students enrolling in accredited courses.</p><p>Cookies are managed through the privacy consent panel on the website.</p><p>Shipping deliveries are handled within ten working days of approval.</p>",
	), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$posts[] = $id;
	Queue::enqueue( $id );
	check( 1 === Queue::run( 1 ), 'background job completes' );
	check( 4 === $embedded, 'initial indexing embeds each of four stable passages' );
	$before = $wpdb->get_results( $wpdb->prepare( 'SELECT id,content_hash FROM ' . KB::table() . ' WHERE object_id=%d ORDER BY chunk_index', $id ), ARRAY_A );
	$count = $http_calls;
	Queue::enqueue( $id );
	check( 1 === Queue::run( 1 ), 'unchanged job completes' );
	check( 4 === $embedded && $count === $http_calls, 'unchanged content performs zero embedding or backend calls' );
	wp_update_post( array( 'ID' => $id, 'post_content' => "<p>Tuition assistance is available for students enrolling in approved courses.</p><p>Cookies are managed through the privacy consent panel on the website.</p><p>Shipping deliveries are handled within ten working days of approval.</p>" ) );
	Queue::enqueue( $id );
	check( 1 === Queue::run( 1 ), 'edited job completes' );
	check( 5 === $embedded, 'one-word edit embeds only one changed passage' );
	$after = $wpdb->get_results( $wpdb->prepare( 'SELECT id,content_hash FROM ' . KB::table() . ' WHERE object_id=%d ORDER BY chunk_index', $id ), ARRAY_A );
	check( $before[2]['id'] === $after[2]['id'] && $before[3]['id'] === $after[3]['id'], 'unchanged passage IDs survive edits' );
	$rows = RAG::retrieve( 'funding', 2 );
	check( ! is_wp_error( $rows ) && $rows && false !== strpos( $rows[0]['content'], 'Tuition' ), 'hybrid retrieval finds meaning without matching words' );
	check( isset( RAG::$trace['semantic_candidates'] ), 'semantic retrieval diagnostics recorded' );
	$rows = RAG::retrieve( 'funding', 2 );
	$before_query = $embedded;
	RAG::retrieve( 'funding', 2 );
	check( $before_query === $embedded, 'query embedding cache prevents repeated calls' );
	$fused = RAG::fuse( array( array( array( 'id' => 1 ), array( 'id' => 2 ) ), array( array( 'id' => 2 ), array( 'id' => 3 ) ) ) );
	check( 2 === $fused[0]['id'], 'RRF favours evidence present in both rankings' );
	$test_settings['rag_parent_chars'] = 4000;
	$test_settings['rag_context_chars'] = 1000;
	Settings::flush();
	$context = RAG::context( $rows );
	check( array_sum( array_map( function ( $c ) { return mb_strlen( $c['content'] ); }, $context ) ) <= 1000, 'expanded evidence respects character budget' );
	check( is_wp_error( Embeddings::validate( array( 1, 2 ), 3 ) ), 'wrong dimensions rejected' );
	check( is_wp_error( Embeddings::validate( array( 0, 0, 0 ), 3 ) ), 'zero vector rejected' );
	check( is_wp_error( Embeddings::validate( array( 1, INF, 0 ), 3 ) ), 'nonfinite vector rejected' );
	$malformed = true;
	check( is_wp_error( Embeddings::embed( array( 'batch test' ) ) ), 'partial provider batch rejected' );
	$malformed = false;
	$test_settings['mode'] = 'local_only';
	$test_settings['rag_embed_provider'] = 'openai';
	$test_settings['rag_cloud_consent'] = 1;
	$test_settings['rag_embed_url'] = 'https://api.openai.com/v1';
	Settings::flush();
	$count = $http_calls;
	check( is_wp_error( Embeddings::embed( array( 'private query' ) ) ) && $count === $http_calls, 'local-only blocks cloud embedding even with consent' );
	check( array() === Smart_Router::candidates( 'local_only' ), 'local-only has no generation candidates' );
	$test_settings['mode'] = 'smart';
	$test_settings['rag_embed_url'] = 'https://localhost:19443/v1';
	$test_settings['rag_embed_model'] = 'text-embedding-3-small';
	$test_settings['rag_embed_key'] = OmniThreadAI\Crypto::encrypt( 'test-key-not-a-real-secret' );
	Settings::flush();
	$openai = Embeddings::embed( array( 'tuition', 'cookie' ) );
	check( ! is_wp_error( $openai ) && (float) $openai[0][1] === 1.0, 'OpenAI response order validated and restored' );
	$contract = end( $contracts );
	check( isset( $contract['headers']['Authorization'] ) && 3 === $contract['body']['dimensions'] && 0 === $contract['redirects'], 'OpenAI dimensions/auth and no credential redirects' );
	$test_settings['rag_embed_provider'] = 'azure';
	$test_settings['rag_embed_url'] = 'https://localhost:19443/openai/deployments/test/embeddings?api-version=2024-02-01';
	Settings::flush();
	check( ! is_wp_error( Embeddings::embed( array( 'tuition' ) ) ), 'Azure embedding response contract' );
	$contract = end( $contracts );
	check( isset( $contract['headers']['api-key'] ) && false !== strpos( $contract['url'], 'api-version=' ), 'Azure deployment URL and api-key contract' );
	$test_settings['rag_embed_provider'] = 'ollama';
	$test_settings['rag_embed_model'] = 'fixture-model';
	$test_settings['rag_embed_url'] = 'http://localhost:19441';
	Settings::flush();
	$distribution = 'opensearch';
	delete_transient( 'otai_vector_backend_' . md5( $test_settings['es_url'] ) );
	$server = array();
	check( ! is_wp_error( RAG::ensure_index() ), 'OpenSearch index creation contract' );
	check( 'knn_vector' === $server[ RAG::index() ]['mapping']['mappings']['properties']['vector']['type'], 'OpenSearch uses knn_vector mapping' );
	$server[ RAG::index() ]['mapping']['mappings']['properties']['vector']['type'] = 'float';
	check( is_wp_error( RAG::ensure_index() ), 'matching dimensions do not hide an invalid vector field type' );
	$server[ RAG::index() ]['mapping']['mappings']['properties']['vector']['type'] = 'knn_vector';
	$os = RAG::vector_query( 'opensearch', array( 1, 0, 0 ), 10, array( 'page' ) );
	$es = RAG::vector_query( 'elasticsearch', array( 1, 0, 0 ), 10, array( 'page' ) );
	check( isset( $os['query']['knn']['vector']['filter'] ) && isset( $es['knn']['filter'] ), 'both vector dialects include source filters' );
	$wpdb->query( 'UPDATE ' . Queue::table() . " SET synced_hash=''" );
	Queue::enqueue( $id );
	check( 1 === Queue::run( 1 ), 'OpenSearch sync completes using cached compatible vectors' );
	$rows = RAG::retrieve( 'funding', 2 );
	check( $rows && ! is_wp_error( $rows ), 'OpenSearch retrieval contract' );
	$text = RAG::guard_answer( 'See [1] and [999] or [bad](https://invalid.example).', $rows );
	check( false === strpos( $text, '[999]' ) && false === strpos( $text, '](https://invalid.example)' ), 'invented citations and Markdown links removed' );
	wp_update_post( array( 'ID' => $id, 'post_password' => 'protected' ) );
	check( array() === RAG::hydrate( $rows ), 'password protection revokes retrieval immediately' );
	check( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . KB::table() . ' WHERE object_id=%d', $id ) ), 'restricted content removed from local knowledge' );
	Queue::run( 1 );
	check( empty( $server[ RAG::index() ]['docs'] ), 'remote obsolete vectors removed by deletion job' );
	$test_settings['rag_retrieval'] = 'keyword';
	$test_settings['rag_grounded_only'] = 1;
	Settings::flush();
	$result = Chat_Service::handle( array( 'message' => 'quantumunanswerableword', 'session' => '' ) );
	check( ! is_wp_error( $result ) && 'fallback' === $result['source'], 'no-evidence grounded request returns fallback without LLM' );
	$test_settings['rag_retrieval'] = 'semantic';
	$test_settings['rag_embed_provider'] = 'openai';
	$test_settings['rag_embed_url'] = 'https://api.openai.com/v1';
	$test_settings['rag_cloud_consent'] = 0;
	Settings::flush();
	check( is_wp_error( RAG::retrieve( 'question', 2 ) ), 'semantic-only fails explicitly without approval' );
	$test_settings['rag_retrieval'] = 'hybrid';
	Settings::flush();
	check( is_array( RAG::retrieve( 'question', 2 ) ) && isset( RAG::$trace['degraded'] ), 'hybrid degradation is reported, not hidden' );
	$test_settings['rag_embed_provider'] = 'ollama';
	$test_settings['rag_embed_url'] = 'http://localhost:19441';
	Settings::flush();
	$body = '';
	for ( $i = 0; $i < 10; ++$i ) {
		$body .= '<p>Tuition passage ' . $i . ' contains distinct eligibility information for this integration fixture.</p>';
	}
	$large = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Large RAG fixture', 'post_content' => $body ), true );
	if ( is_wp_error( $large ) ) {
		throw new RuntimeException( $large->get_error_message() );
	}
	$posts[] = $large;
	Queue::enqueue( $large );
	$count = $embedded;
	check( 0 === Queue::run( 1 ) && $embedded === $count + 8, 'large document checkpoints after eight new embedding inputs' );
	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Queue::table() . ' WHERE object_id=%d', $large ), ARRAY_A );
	check( 'pending' === $job['status'] && 0 === (int) $job['attempts'], 'useful partial progress does not consume failure retries' );
	$wpdb->query( 'UPDATE ' . Queue::table() . " SET available_at=UTC_TIMESTAMP() WHERE status='pending'" );
	check( 1 === Queue::run( 1 ) && $embedded === $count + 11, 'large document resumes without re-embedding its first batch' );
	Queue::enqueue( $large );
	Queue::enqueue( $large );
	check( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Queue::table() . ' WHERE object_id=%d', $large ) ), 'repeated events coalesce to one durable document job' );
	$wpdb->query( $wpdb->prepare( "UPDATE " . Queue::table() . " SET status='running',lease_until='2000-01-01 00:00:00',lease_token='expired' WHERE object_id=%d", $large ) );
	check( 1 === Queue::run( 1 ), 'expired worker lease is safely reclaimed' );
	$fenced = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Generation fence fixture', 'post_content' => '<p>Tuition support is available before registration.</p>' ), true );
	if ( is_wp_error( $fenced ) ) {
		throw new RuntimeException( $fenced->get_error_message() );
	}
	$posts[] = $fenced;
	Queue::enqueue( $fenced );
	$during_embed = function () use ( $fenced ) {
		check( 0 === Queue::run( 1 ), 'a second worker cannot claim an unexpired lease' );
		wp_update_post( array( 'ID' => $fenced, 'post_content' => '<p>Tuition support is available after registration.</p>' ) );
		Queue::enqueue( $fenced );
	};
	check( 0 === Queue::run( 1 ), 'save during embedding cannot be reported as latest-generation completion' );
	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Queue::table() . ' WHERE object_id=%d', $fenced ), ARRAY_A );
	check( 'pending' === $job['status'], 'new generation remains pending after old worker finishes' );
	$wpdb->query( 'UPDATE ' . Queue::table() . " SET available_at=UTC_TIMESTAMP() WHERE status='pending'" );
	check( 1 === Queue::run( 1 ), 'new generation indexes its current content' );
	check( false !== strpos( $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(content) FROM ' . KB::table() . ' WHERE object_id=%d', $fenced ) ), 'after registration' ), 'generation fence retains latest passage text' );
	wp_update_post( array( 'ID' => $fenced, 'post_content' => '<p>A distinct retry fixture requiring another embedding.</p>' ) );
	Queue::enqueue( $fenced );
	$malformed = true;
	for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
		$wpdb->query( 'UPDATE ' . Queue::table() . " SET available_at=UTC_TIMESTAMP() WHERE status='pending'" );
		Queue::run( 1 );
	}
	$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Queue::table() . ' WHERE object_id=%d', $fenced ), ARRAY_A );
	check( 'failed' === $job['status'] && 5 === (int) $job['attempts'] && $job['error'], 'provider failures stop after five attempts with a visible error' );
	$malformed = false;
	check( 1 === Queue::retry() && 1 === Queue::run( 1 ), 'explicit retry recovers a failed document without losing its content' );
	$method = new ReflectionMethod( OmniThreadAI\Admin::class, 'tab_rag' );
	$method->setAccessible( true );
	ob_start();
	$method->invoke( null );
	$html = ob_get_clean();
	check( false !== strpos( $html, 'rag_embed_model' ) && false !== strpos( $html, 'otai-rag-action' ), 'RAG settings and operation controls render in WordPress' );
	$test_settings['rag_embed_daily'] = 1;
	Settings::flush();
	check( is_wp_error( Embeddings::embed( array( 'limit test' ) ) ), 'daily budget exhausted explicitly' );
} catch ( Throwable $error ) {
	++$failures;
	echo 'FAIL exception: ' . $error->getMessage() . "\n";
} finally {
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( Installer::table_names() as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$prefix}{$table}`" );
	}
	$wpdb->prefix = $original_prefix;
	remove_filter( 'pre_option_' . Settings::OPTION, $settings_filter );
	remove_filter( 'pre_http_request', $mock, 10 );
	Settings::flush();
	foreach ( $options as $key => $value ) {
		if ( null === $value ) {
			delete_option( $key );
		} else {
			update_option( $key, $value );
		}
	}
	delete_transient( 'otai_vector_backend_' . md5( 'http://localhost:19440' ) );
}
echo "RESULT {$checks} checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
