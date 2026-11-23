<?php
/**
 * Activation, database schema, cron and multisite provisioning.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Installer {

	const CRON_HOOK = 'otai_daily_maintenance';

	public static function activate( $network_wide = false ) {
		if ( ! function_exists( 'mb_strtolower' ) || ! Crypto::available() ) {
			wp_die( esc_html__( 'OmniThread AI requires mbstring and OpenSSL. Ask your host to enable these PHP extensions, then activate again.', 'omnithread-ai' ) );
		}
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( $blog_id );
				$result = self::install();
				restore_current_blog();
				if ( is_wp_error( $result ) ) {
					wp_die( esc_html( $result->get_error_message() ) );
				}
			}
			return;
		}
		$result = self::install();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
	}

	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				self::deactivate();
				restore_current_blog();
			}
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Index_Queue::HOOK );
	}

	/**
	 * Provision a newly created site when the plugin is network-active.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( OTAI_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install();
			restore_current_blog();
		}
	}

	public static function on_delete_site( $tables ) {
		global $wpdb;
		foreach ( self::table_names() as $name ) {
			$tables[] = $wpdb->prefix . $name;
		}
		return $tables;
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'otai_db_version' );
		if ( $installed !== OTAI_DB_VERSION ) {
			if ( is_wp_error( self::install() ) ) {
				return;
			}
			if ( false !== $installed && version_compare( (string) $installed, '3', '<' ) ) {
				// v2/v3 changed how tables, lists and titles are indexed.
				update_option( 'otai_needs_index', 1, false );
			}
			if ( false !== $installed && version_compare( (string) $installed, '4', '<' ) ) {
				update_option( 'otai_needs_index', 1, false );
			}
		}
	}

	public static function table_names() {
		return array( 'otai_knowledge', 'otai_conversations', 'otai_messages', 'otai_usage', 'otai_index_jobs', 'otai_embeddings', 'otai_embedding_budget', 'otai_request_budget' );
	}

	public static function install() {
		$result = self::create_tables();
		if ( is_wp_error( $result ) ) {
			RAG::error( $result );
			return $result;
		}

		if ( false === get_option( Settings::OPTION, false ) ) {
			$vertical = Verticals::detect();
			$defaults = array_merge(
				Settings::defaults(),
				array( 'vertical' => $vertical ),
				Verticals::recommended_settings( $vertical ),
				array( 'enabled' => 0, 'display' => 'shortcode_only', 'mode' => 'local_only', 'store_conversations' => 0 )
			);
			add_option( 'otai_setup_required', 1, '', false );
			add_option( Settings::OPTION, $defaults, '', false );
			if ( ! get_option( 'otai_setup_required' ) || false === get_option( Settings::OPTION, false ) ) {
				$error = new \WP_Error( 'otai_setup_storage', __( 'Cannot persist the initial unpublished setup state. Check database permissions and reactivate.', 'omnithread-ai' ) );
				RAG::error( $error );
				return $error;
			}
			update_option( 'otai_needs_index', 1, false );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( Index_Queue::HOOK ) ) {
			wp_schedule_event( time() + 60, 'otai_minute', Index_Queue::HOOK );
		}
		update_option( 'otai_db_version', OTAI_DB_VERSION, true );
		Settings::flush();
		return true;
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}otai_knowledge (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  chunk_index int(11) NOT NULL DEFAULT 0,
  content_hash char(64) NOT NULL DEFAULT '',
  source_modified varchar(32) NOT NULL DEFAULT '',
  title text NOT NULL,
  url varchar(2048) NOT NULL DEFAULT '',
  content longtext NOT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY object_ref (object_type,object_id),
  KEY content_hash (content_hash)
) $charset;
CREATE TABLE {$p}otai_index_jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  generation bigint(20) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts int(11) NOT NULL DEFAULT 0,
  available_at datetime NOT NULL,
  lease_until datetime NULL,
  lease_token varchar(64) NOT NULL DEFAULT '',
  error text NOT NULL,
  synced_hash char(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY object_id (object_id),
  KEY ready (status,available_at)
) $charset;
CREATE TABLE {$p}otai_embeddings (
  cache_key char(64) NOT NULL,
  profile char(64) NOT NULL,
  vector longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (cache_key),
  KEY profile (profile)
) $charset;
CREATE TABLE {$p}otai_embedding_budget (
  day date NOT NULL,
  used int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day)
) $charset;
CREATE TABLE {$p}otai_request_budget (
  bucket_key char(64) NOT NULL,
  used bigint(20) unsigned NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (bucket_key),
  KEY expires_at (expires_at)
) $charset;
CREATE TABLE {$p}otai_conversations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_key varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip_hash char(64) NOT NULL DEFAULT '',
  page_url varchar(2048) NOT NULL DEFAULT '',
  message_count int(11) NOT NULL DEFAULT 0,
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_key (session_key),
  KEY updated_at (updated_at)
) $charset;
CREATE TABLE {$p}otai_messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  role varchar(20) NOT NULL DEFAULT '',
  content longtext NOT NULL,
  provider varchar(40) NOT NULL DEFAULT '',
  answer_source varchar(20) NOT NULL DEFAULT '',
  feedback tinyint(2) NOT NULL DEFAULT 0,
  created_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id),
  KEY created_at (created_at)
) $charset;
CREATE TABLE {$p}otai_usage (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NULL DEFAULT NULL,
  provider varchar(40) NOT NULL DEFAULT '',
  model varchar(191) NOT NULL DEFAULT '',
  answer_source varchar(20) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  latency_ms int(10) unsigned NOT NULL DEFAULT 0,
  success tinyint(1) NOT NULL DEFAULT 1,
  error varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY provider_date (provider,created_at),
  KEY created_at (created_at)
) $charset;"
		);

		$required = array(
			'otai_request_budget' => array( 'bucket_key', 'used', 'expires_at' ),
			'otai_knowledge' => array( 'content_hash', 'source_modified' ),
			'otai_index_jobs' => array( 'generation', 'status', 'available_at', 'lease_until', 'lease_token', 'synced_hash' ),
			'otai_embeddings' => array( 'cache_key', 'profile', 'vector', 'created_at' ),
			'otai_embedding_budget' => array( 'day', 'used' ),
		);
		foreach ( self::table_names() as $name ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$p}{$name}`" );
			if ( $wpdb->last_error || ! $columns || array_diff( $required[ $name ] ?? array(), $columns ) ) {
				return new \WP_Error( 'otai_schema', __( 'OmniThread AI database installation is incomplete. Check CREATE/ALTER permissions and database errors before retrying.', 'omnithread-ai' ) );
			}
		}

		// FULLTEXT is added separately so that hosts without FULLTEXT support still get a working table.
		$table = $p . 'otai_knowledge';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'otai_ft'" );
		if ( ! $has ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD FULLTEXT KEY otai_ft (title, content)" );
			$wpdb->suppress_errors( $suppress );
			$has = $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'otai_ft'" );
		}
		// phpcs:enable
		update_option( 'otai_fulltext', $has ? 1 : 0, true );
		return true;
	}
}
