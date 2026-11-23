<?php
/**
 * Durable per-document jobs with coalescing, leases and retryable failures.
 *
 * @package OmniThreadAI
 */

namespace OmniThreadAI;

defined( 'ABSPATH' ) || exit;

class Index_Queue {

	const HOOK = 'otai_index_worker';

	public static function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function schedules( $schedules ) {
		$schedules['otai_minute'] = array( 'interval' => 60, 'display' => __( 'Every minute (OmniThread AI)', 'omnithread-ai' ) );
		return $schedules;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'otai_index_jobs';
	}

	public static function enqueue( $object_id ) {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (object_id,generation,status,attempts,available_at,error)
			VALUES (%d,1,'pending',0,%s,'')
			ON DUPLICATE KEY UPDATE generation=generation+1,status=IF(status='running','running','pending'),attempts=0,available_at=VALUES(available_at),error=''",
			$object_id, $now
		) );
		if ( false === $result ) {
			RAG::error( new \WP_Error( 'otai_queue_storage', __( 'Cannot persist an indexing job.', 'omnithread-ai' ) ) );
			return false;
		}
		return true;
	}

	public static function on_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! Knowledge_Base::eligible( $post ) ) {
			Knowledge_Base::remove_post( $post_id );
		} elseif ( Settings::get( 'auto_sync' ) ) {
			self::enqueue( $post_id );
		}
	}

	public static function on_meta( $meta_id, $post_id ) {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, Knowledge_Base::indexable_post_types(), true ) && Settings::get( 'auto_sync' ) ) {
			self::enqueue( $post_id );
		}
	}

	public static function on_terms( $object_id ) {
		self::on_meta( 0, $object_id );
	}

	/**
	 * A keyset scan also visits old/private/deleted indexed objects. It does not
	 * rely on imports firing WordPress save hooks.
	 */
	public static function reconcile() {
		global $wpdb;
		if ( ! Settings::get( 'auto_sync' ) ) {
			return;
		}
		$cursor = (int) get_option( 'otai_reconcile_cursor', 0 );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_type NOT IN ('revision','attachment','nav_menu_item') ORDER BY ID LIMIT 100", $cursor
		) );
		if ( $wpdb->last_error ) {
			RAG::error( new \WP_Error( 'otai_reconcile', __( 'Content reconciliation query failed.', 'omnithread-ai' ) ) );
			return;
		}
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( Knowledge_Base::eligible( $post ) ) {
				$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::table() . ' WHERE object_id=%d', $id ) );
				if ( $wpdb->last_error ) {
					RAG::error( new \WP_Error( 'otai_reconcile', __( 'Cannot read reconciliation job state.', 'omnithread-ai' ) ) );
					return;
				}
				if ( ! $status || 'done' === $status ) {
					self::enqueue( $id );
				}
			}
		}
		if ( null !== $wpdb->last_error && '' !== $wpdb->last_error ) {
			RAG::error( new \WP_Error( 'otai_reconcile', __( 'Content reconciliation query failed.', 'omnithread-ai' ) ) );
			return;
		}
		update_option( 'otai_reconcile_cursor', $ids ? (int) end( $ids ) : 0, false );
		$invalid = $wpdb->get_col( "SELECT DISTINCT k.object_id FROM " . Knowledge_Base::table() . " k LEFT JOIN {$wpdb->posts} p ON p.ID=k.object_id WHERE k.object_id>0 AND (p.ID IS NULL OR p.post_status<>'publish' OR p.post_password<>'') LIMIT 100" );
		if ( $wpdb->last_error ) {
			RAG::error( new \WP_Error( 'otai_reconcile', __( 'Cannot find revoked indexing sources.', 'omnithread-ai' ) ) );
			return;
		}
		foreach ( $invalid as $id ) {
			Knowledge_Base::remove_post( $id );
		}
	}

	public static function run( $limit = 5 ) {
		global $wpdb;
		if ( Settings::get( 'rag_queue_paused' ) ) {
			return 0;
		}
		if ( time() - (int) get_option( 'otai_last_reconcile', 0 ) >= 60 ) {
			self::reconcile();
			update_option( 'otai_last_reconcile', time(), false );
		}
		$table = self::table();
		$start = microtime( true );
		$done  = 0;
		for ( $i = 0; $i < min( 20, max( 1, (int) $limit ) ) && microtime( true ) - $start < 25; ++$i ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$job = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE (status='pending' AND available_at<=%s) OR (status='running' AND lease_until<%s) ORDER BY available_at,id LIMIT 1", $now, $now
			), ARRAY_A );
			if ( $wpdb->last_error ) {
				RAG::error( new \WP_Error( 'otai_queue_storage', __( 'Cannot read pending indexing jobs.', 'omnithread-ai' ) ) );
				break;
			}
			if ( ! $job ) {
				break;
			}
			$token = wp_generate_uuid4();
			$claim = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status='running',lease_token=%s,lease_until=%s WHERE id=%d AND generation=%d AND ((status='pending' AND available_at<=%s) OR (status='running' AND lease_until<%s))",
				$token, gmdate( 'Y-m-d H:i:s', time() + 3600 ), $job['id'], $job['generation'], $now, $now
			) );
			if ( false === $claim ) {
				RAG::error( new \WP_Error( 'otai_queue_storage', __( 'Cannot claim an indexing job.', 'omnithread-ai' ) ) );
				break;
			}
			if ( 1 !== $claim ) {
				continue;
			}
			$id = (int) $job['object_id'];
			$result = 0 === $id ? Knowledge_Base::index_manual_entries( false ) : Knowledge_Base::index_post( $id, false );
			if ( ! is_wp_error( $result ) ) {
				// A newer save must not be completed by an older job.
				$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT generation FROM {$table} WHERE id=%d AND lease_token=%s", $job['id'], $token ) );
				if ( $wpdb->last_error ) {
					$result = new \WP_Error( 'otai_queue_storage', __( 'Cannot verify the indexing job generation.', 'omnithread-ai' ) );
				} elseif ( $current !== (int) $job['generation'] ) {
					$result = false;
				} else {
					$result = RAG::sync( $id );
				}
			}
			$attempts = is_wp_error( $result ) ? (int) $job['attempts'] + 1 : 0;
			$status   = true === $result ? 'done' : ( $attempts >= 5 ? 'failed' : 'pending' );
			$error    = is_wp_error( $result ) ? $result->get_error_message() : '';
			if ( is_wp_error( $result ) ) {
				RAG::error( $result );
			}
			$delay = $attempts ? min( 3600, 30 * ( 2 ** $attempts ) ) : 1;
			$finished = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status=IF(generation=%d,%s,'pending'),attempts=IF(generation=%d,%d,0),error=IF(generation=%d,%s,''),available_at=%s,lease_token='',lease_until=NULL WHERE id=%d AND lease_token=%s",
				$job['generation'], $status, $job['generation'], $attempts, $job['generation'], $error,
				gmdate( 'Y-m-d H:i:s', time() + $delay ), $job['id'], $token
			) );
			if ( false === $finished ) {
				RAG::error( new \WP_Error( 'otai_queue_complete', __( 'Cannot persist the indexing job outcome; the lease will be retried.', 'omnithread-ai' ) ) );
				break;
			}
			if ( true === $result && 1 === $finished ) {
				$generation = $wpdb->get_var( $wpdb->prepare( "SELECT generation FROM {$table} WHERE id=%d AND status='done'", $job['id'] ) );
				if ( $wpdb->last_error ) {
					RAG::error( new \WP_Error( 'otai_queue_storage', __( 'Cannot verify the completed indexing job.', 'omnithread-ai' ) ) );
					break;
				}
				if ( (int) $generation === (int) $job['generation'] ) {
					++$done;
				}
			}
		}
		return $done;
	}

	public static function retry() {
		global $wpdb;
		$result = $wpdb->query( "UPDATE " . self::table() . " SET status='pending',attempts=0,error='',available_at=UTC_TIMESTAMP() WHERE status='failed'" );
		if ( false === $result ) {
			RAG::error( new \WP_Error( 'otai_queue_storage', __( 'Cannot retry failed indexing jobs.', 'omnithread-ai' ) ) );
		}
		return $result;
	}

	public static function stats() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status,COUNT(*) AS jobs FROM ' . self::table() . ' GROUP BY status', ARRAY_A );
		if ( $wpdb->last_error ) {
			RAG::error( new \WP_Error( 'otai_queue_storage', __( 'Cannot read indexing queue statistics.', 'omnithread-ai' ) ) );
			return array();
		}
		return $rows;
	}
}
