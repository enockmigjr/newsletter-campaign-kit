<?php
/**
 * Scheduled campaign orchestration for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_add_cron_schedule( $schedules ) {
	$schedules['newsletter_campaign_kit_minute'] = array(
		'interval' => MINUTE_IN_SECONDS,
		'display'  => __( 'Every minute (Newsletter Campaign Kit)', 'newsletter-campaign-kit' ),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'newsletter_campaign_kit_add_cron_schedule' );

function newsletter_campaign_kit_schedule_runner() {
	if ( ! wp_next_scheduled( 'newsletter_campaign_kit_run_scheduled' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'newsletter_campaign_kit_minute', 'newsletter_campaign_kit_run_scheduled' );
	}
}
add_action( 'init', 'newsletter_campaign_kit_schedule_runner' );

function newsletter_campaign_kit_claim_due_campaigns( $limit = 10 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_campaigns_table_exists() ) {
		return array();
	}

	$table   = newsletter_campaign_kit_get_campaigns_table();
	$limit   = max( 1, min( 50, absint( $limit ) ) );
	$now     = current_time( 'mysql', true );
	$ids     = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY scheduled_at ASC, id ASC LIMIT %d",
			$now,
			$limit
		)
	);
	$claimed = array();

	foreach ( $ids as $campaign_id ) {
		$campaign_id = absint( $campaign_id );
		$wpdb->query( 'START TRANSACTION' );
		$updated     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'sending', updated_at = %s WHERE id = %d AND status = 'scheduled' AND scheduled_at <= %s",
				$now,
				$campaign_id,
				$now
			)
		);
		if ( 1 === $updated ) {
			$enqueued = newsletter_campaign_kit_enqueue_campaign( $campaign_id, false );
			if ( is_wp_error( $enqueued ) ) {
				$wpdb->query( 'ROLLBACK' );
				if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
					newsletter_campaign_kit_log_event( 'newsletter_campaign_schedule_claim_failed', 'failure', 0, array( 'campaign_id' => $campaign_id, 'reason' => $enqueued->get_error_code() ) );
				}
				continue;
			}
			$wpdb->query( 'COMMIT' );
			$claimed[] = $campaign_id;
		} else {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	return $claimed;
}

function newsletter_campaign_kit_finalize_campaigns() {
	global $wpdb;

	if ( ! newsletter_campaign_kit_campaigns_table_exists() || ! newsletter_campaign_kit_queue_table_exists() ) {
		return 0;
	}

	$campaigns = newsletter_campaign_kit_get_campaigns_table();
	$queue     = newsletter_campaign_kit_get_queue_table();
	$now       = current_time( 'mysql', true );

	return (int) $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$campaigns} c SET c.status = 'sent', c.sent_at = %s, c.updated_at = %s WHERE c.status = 'sending' AND NOT EXISTS (SELECT 1 FROM {$queue} q WHERE q.campaign_id = c.id AND q.status IN ('pending','processing','paused'))",
			$now,
			$now
		)
	);
}

/** Acquire a small database-backed operation lock that also survives object-cache differences. */
function newsletter_campaign_kit_acquire_operation_lock( $name, $ttl = 900 ) {
	$name = sanitize_key( $name );
	$ttl  = max( MINUTE_IN_SECONDS, absint( $ttl ) );
	$key  = 'newsletter_campaign_kit_lock_' . $name;
	$now  = time();
	if ( add_option( $key, $now, '', false ) ) {
		return true;
	}
	$locked_at = absint( get_option( $key, 0 ) );
	if ( $locked_at && $now - $locked_at <= $ttl ) {
		return false;
	}
	delete_option( $key );

	return add_option( $key, $now, '', false );
}

function newsletter_campaign_kit_release_operation_lock( $name ) {
	delete_option( 'newsletter_campaign_kit_lock_' . sanitize_key( $name ) );
}

/** Remove old unconfirmed contacts and their operational links in one transaction. */
function newsletter_campaign_kit_cleanup_expired_pending_subscribers( $retention_days = 7, $limit = 200 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return 0;
	}
	$retention_days = max( 1, min( 90, absint( $retention_days ) ) );
	$limit          = max( 1, min( 500, absint( $limit ) ) );
	$cutoff         = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );
	$subscribers    = newsletter_campaign_kit_get_subscribers_table();
	$ids            = array_map(
		'absint',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$subscribers} WHERE status = 'pending' AND confirmation_expires_at IS NOT NULL AND confirmation_expires_at <= %s ORDER BY confirmation_expires_at ASC, id ASC LIMIT %d",
				$cutoff,
				$limit
			)
		)
	);
	if ( empty( $ids ) ) {
		return 0;
	}

	$wpdb->query( 'START TRANSACTION' );
	foreach ( $ids as $subscriber_id ) {
		$tables = array(
			newsletter_campaign_kit_get_queue_table(),
			newsletter_campaign_kit_get_subscriber_topics_table(),
			newsletter_campaign_kit_get_subscriber_lists_table(),
			newsletter_campaign_kit_get_subscriber_tags_table(),
			newsletter_campaign_kit_get_audit_table(),
		);
		foreach ( $tables as $table ) {
			if ( newsletter_campaign_kit_table_exists( $table ) && false === $wpdb->delete( $table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'newsletter_pending_cleanup_failed', __( 'Expired pending contacts could not be cleaned.', 'newsletter-campaign-kit' ) );
			}
		}
		if ( newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
			$updated = $wpdb->update( newsletter_campaign_kit_get_audience_snapshot_members_table(), array( 'subscriber_id' => null ), array( 'subscriber_id' => $subscriber_id ), array( '%d' ), array( '%d' ) );
			if ( false === $updated ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'newsletter_pending_cleanup_failed', __( 'Expired pending contacts could not be cleaned.', 'newsletter-campaign-kit' ) );
			}
		}
		$deleted = $wpdb->delete( $subscribers, array( 'id' => $subscriber_id, 'status' => 'pending' ), array( '%d', '%s' ) );
		if ( 1 !== $deleted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'newsletter_pending_cleanup_conflict', __( 'An expired pending contact changed while maintenance was running.', 'newsletter-campaign-kit' ) );
		}
	}
	$wpdb->query( 'COMMIT' );

	return count( $ids );
}

/** Run pending cleanup at most once per hour, even though delivery runs every minute. */
function newsletter_campaign_kit_maybe_cleanup_expired_pending_subscribers( $force = false ) {
	$state = get_option( 'newsletter_campaign_kit_maintenance_state', array() );
	$state = is_array( $state ) ? $state : array();
	if ( ! $force && ! empty( $state['last_pending_cleanup_at'] ) && time() - strtotime( $state['last_pending_cleanup_at'] . ' UTC' ) < HOUR_IN_SECONDS ) {
		return 0;
	}
	if ( ! newsletter_campaign_kit_acquire_operation_lock( 'pending_cleanup', 10 * MINUTE_IN_SECONDS ) ) {
		return 0;
	}
	$state['last_pending_cleanup_at'] = current_time( 'mysql', true );
	update_option( 'newsletter_campaign_kit_maintenance_state', $state, false );
	$settings = newsletter_campaign_kit_get_provider_settings();
	try {
		$cleaned = newsletter_campaign_kit_cleanup_expired_pending_subscribers( $settings['pending_retention_days'] );
	} finally {
		newsletter_campaign_kit_release_operation_lock( 'pending_cleanup' );
	}
	$state['last_pending_cleanup_count']  = is_wp_error( $cleaned ) ? 0 : absint( $cleaned );
	$state['last_pending_cleanup_status'] = is_wp_error( $cleaned ) ? 'failed' : 'success';
	update_option( 'newsletter_campaign_kit_maintenance_state', $state, false );

	return $cleaned;
}

/** Return a non-sensitive operational health summary for administrators. */
function newsletter_campaign_kit_get_scheduler_health() {
	$settings = newsletter_campaign_kit_get_provider_settings();
	$state    = get_option( 'newsletter_campaign_kit_scheduler_state', array() );
	$state    = is_array( $state ) ? $state : array();
	$next_run = wp_next_scheduled( 'newsletter_campaign_kit_run_scheduled' );
	$last_at  = $state['last_completed_at'] ?? ( $state['last_failed_at'] ?? '' );
	$last_ts  = $last_at ? strtotime( $last_at . ' UTC' ) : 0;
	$status   = 'healthy';
	$message  = __( 'The recurring delivery event is running normally.', 'newsletter-campaign-kit' );
	if ( false === $next_run ) {
		$status  = 'unscheduled';
		$message = __( 'The recurring delivery event is missing and will be recreated on the next WordPress request.', 'newsletter-campaign-kit' );
	} elseif ( 'failed' === ( $state['last_status'] ?? '' ) ) {
		$status  = 'failed';
		$message = __( 'The last scheduler execution failed. Review the newsletter audit log.', 'newsletter-campaign-kit' );
	} elseif ( ! $last_ts ) {
		$is_overdue = $next_run && time() - $next_run > absint( $settings['cron_late_after_minutes'] ) * MINUTE_IN_SECONDS;
		$status     = $is_overdue ? 'late' : 'pending';
		$message    = $is_overdue
			? __( 'The first scheduler heartbeat is overdue. Verify WP-Cron or the external cron runner.', 'newsletter-campaign-kit' )
			: __( 'The scheduler is registered but has not completed its first observed run.', 'newsletter-campaign-kit' );
	} elseif ( time() - $last_ts > absint( $settings['cron_late_after_minutes'] ) * MINUTE_IN_SECONDS ) {
		$status  = 'late';
		$message = __( 'The scheduler heartbeat is late. Verify WP-Cron or the external cron runner.', 'newsletter-campaign-kit' );
	}

	return array(
		'status'            => $status,
		'message'           => $message,
		'next_run'          => false === $next_run ? 0 : absint( $next_run ),
		'last_completed_at' => $state['last_completed_at'] ?? '',
		'last_duration_ms'  => absint( $state['last_duration_ms'] ?? 0 ),
		'last_result'       => isset( $state['last_result'] ) && is_array( $state['last_result'] ) ? $state['last_result'] : array(),
	);
}

function newsletter_campaign_kit_run_scheduler() {
	if ( ! newsletter_campaign_kit_acquire_operation_lock( 'scheduler', 15 * MINUTE_IN_SECONDS ) ) {
		return array( 'skipped' => true, 'reason' => 'already_running' );
	}
	$started_at = microtime( true );
	$started     = current_time( 'mysql', true );
	$state       = get_option( 'newsletter_campaign_kit_scheduler_state', array() );
	$state       = is_array( $state ) ? $state : array();
	$state['last_status']     = 'running';
	$state['last_started_at'] = $started;
	update_option( 'newsletter_campaign_kit_scheduler_state', $state, false );
	try {
		$settings  = newsletter_campaign_kit_get_provider_settings();
		$recovered = newsletter_campaign_kit_recover_stale_queue_locks();
		$claimed   = newsletter_campaign_kit_claim_due_campaigns();
		$processed = newsletter_campaign_kit_process_queue_batch( $settings['queue_batch_size'] );
		$finalized = newsletter_campaign_kit_finalize_campaigns();
		$cleaned   = newsletter_campaign_kit_maybe_cleanup_expired_pending_subscribers();
		if ( is_wp_error( $cleaned ) ) {
			throw new RuntimeException( 'newsletter_pending_cleanup_failed' );
		}
	} catch ( Throwable $error ) {
		$state = array(
			'last_status'      => 'failed',
			'last_started_at'  => $started,
			'last_failed_at'   => current_time( 'mysql', true ),
			'last_duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
		);
		update_option( 'newsletter_campaign_kit_scheduler_state', $state, false );
		if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
			newsletter_campaign_kit_log_event( 'newsletter_scheduler_failed', 'failure', 0, array( 'reason' => 'scheduler_exception' ) );
		}
		newsletter_campaign_kit_release_operation_lock( 'scheduler' );

		return new WP_Error( 'newsletter_scheduler_failed', __( 'The newsletter scheduler failed.', 'newsletter-campaign-kit' ) );
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) && ( $recovered || $claimed || $processed['processed'] || $finalized || $cleaned ) ) {
		newsletter_campaign_kit_log_event(
			'newsletter_scheduler_run',
			'info',
			0,
			array(
				'recovered' => $recovered,
				'claimed'   => count( $claimed ),
				'processed' => $processed['processed'],
				'finalized' => $finalized,
				'cleaned'   => absint( $cleaned ),
			)
		);
	}
	$result = array(
		'recovered' => $recovered,
		'claimed'   => count( $claimed ),
		'processed' => $processed,
		'finalized' => $finalized,
		'cleaned'   => absint( $cleaned ),
	);
	$state  = array(
		'last_status'      => 'success',
		'last_started_at'  => $started,
		'last_completed_at' => current_time( 'mysql', true ),
		'last_duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
		'last_result'      => array(
			'recovered' => absint( $recovered ),
			'claimed'   => count( $claimed ),
			'processed' => absint( $processed['processed'] ),
			'finalized' => absint( $finalized ),
			'cleaned'   => absint( $cleaned ),
		),
	);
	update_option( 'newsletter_campaign_kit_scheduler_state', $state, false );
	newsletter_campaign_kit_release_operation_lock( 'scheduler' );

	return $result;
}
add_action( 'newsletter_campaign_kit_run_scheduled', 'newsletter_campaign_kit_run_scheduler' );
