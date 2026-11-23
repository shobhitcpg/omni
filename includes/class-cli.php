<?php
/**
 * WP-CLI commands.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

use OmniThreadAI\Providers\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the OmniThread AI assistant.
 */
class CLI {

	/**
	 * Run local readiness checks without calling AI or search services.
	 *
	 * @subcommand readiness
	 */
	public function readiness() {
		$checks = Setup::checks();
		\WP_CLI\Utils\format_items( 'table', $checks, array( 'id', 'status', 'remedy' ) );
		foreach ( $checks as $check ) {
			if ( 'blocked' === $check['status'] ) {
				\WP_CLI::error( 'Readiness is blocked. Resolve the checks shown above.' );
			}
		}
		\WP_CLI::success( 'No blocking configuration checks. Review warnings and validate answer quality before launch.' );
	}

	/**
	 * Print an allowlisted support report without secrets or site content.
	 *
	 * @subcommand support
	 */
	public function support() {
		\WP_CLI::line( wp_json_encode( Setup::report(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Show configuration, providers and knowledge-base status.
	 *
	 * @subcommand status
	 */
	public function status() {
		\WP_CLI::log( 'Enabled:   ' . ( Settings::get( 'enabled' ) ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Vertical:  ' . Settings::get( 'vertical' ) . ' (detected: ' . Verticals::detect() . ')' );
		\WP_CLI::log( 'Mode:      ' . Settings::get( 'mode' ) );
		\WP_CLI::log( 'FULLTEXT:  ' . ( get_option( 'otai_fulltext' ) ? 'yes' : 'no (LIKE fallback)' ) );
		\WP_CLI::log( 'Search:    ' . Search_Engine::label( Search_Engine::engine() ) . ( Search_Engine::is_down() ? ' (server unreachable – using database)' : '' ) );
		$rows = array();
		foreach ( Registry::ordered() as $provider ) {
			$rows[] = array(
				'provider'   => $provider->id(),
				'enabled'    => $provider->is_enabled() ? 'yes' : 'no',
				'configured' => $provider->is_configured() ? 'yes' : 'no',
				'today'      => Usage::count_today( $provider->id() ),
				'limit'      => $provider->daily_limit() ? $provider->daily_limit() : '∞',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'provider', 'enabled', 'configured', 'today', 'limit' ) );
		$stats = Knowledge_Base::stats();
		if ( $stats ) {
			\WP_CLI\Utils\format_items( 'table', $stats, array( 'object_type', 'objects', 'chunks' ) );
		} else {
			\WP_CLI::warning( 'Knowledge base is empty. Run: wp omnithread-ai reindex' );
		}
	}

	/**
	 * Rebuild the knowledge base.
	 *
	 * [--batch=<n>]
	 * : Posts per batch. Default 50.
	 *
	 * @subcommand reindex
	 */
	public function reindex( $args, $assoc ) {
		$batch  = isset( $assoc['batch'] ) ? max( 1, (int) $assoc['batch'] ) : 50;
		$offset = 0;
		do {
			$result = Knowledge_Base::reindex_batch( $offset, $batch );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$offset = $result['processed'];
			\WP_CLI::log( sprintf( 'Queued %d / %d', $result['processed'], $result['total'] ) );
		} while ( ! $result['done'] );
		\WP_CLI::success( 'Content queued. Run wp omnithread-ai work or allow the background worker to process it.' );
	}

	/**
	 * Process a bounded batch of pending indexing jobs.
	 *
	 * [--limit=<n>]
	 * : Jobs per invocation (1-20). Default 5.
	 *
	 * @subcommand work
	 */
	public function work( $args, $assoc ) {
		$count = Index_Queue::run( isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 5 );
		\WP_CLI::log( sprintf( '%d jobs completed.', $count ) );
		\WP_CLI\Utils\format_items( 'table', Index_Queue::stats(), array( 'status', 'jobs' ) );
	}

	/**
	 * Ask the assistant a question (runs the full pipeline).
	 *
	 * <question>
	 * : The question.
	 *
	 * @subcommand ask
	 */
	public function ask( $args ) {
		$result = Chat_Service::handle(
			array(
				'message' => $args[0],
				'session' => 'cli-' . wp_generate_password( 12, false ),
			)
		);
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::log( $result['reply'] );
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '[source: %s, provider: %s]', $result['source'], $result['provider'] ? $result['provider'] : '-' ) );
		foreach ( $result['sources'] as $source ) {
			\WP_CLI::log( ' - ' . $source['title'] . ' ' . $source['url'] );
		}
	}

	/**
	 * Send a test prompt to one provider.
	 *
	 * <provider>
	 * : Provider ID (e.g. openai, gemini, ollama, bedrock).
	 *
	 * @subcommand test
	 */
	public function test( $args ) {
		$result = Admin::test_provider( $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( sprintf( '%s replied in %d ms: %s', $result['model'], $result['latency_ms'], $result['text'] ) );
	}

	/**
	 * Delete stored conversations.
	 *
	 * [--days=<n>]
	 * : Only delete conversations older than N days. Omit to delete all.
	 *
	 * @subcommand purge
	 */
	public function purge( $args, $assoc ) {
		Conversations::purge( isset( $assoc['days'] ) ? (int) $assoc['days'] : 0 );
		\WP_CLI::success( 'Conversations purged.' );
	}
}
