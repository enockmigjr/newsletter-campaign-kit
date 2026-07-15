<?php
/**
 * WordPress runtime verification for scheduler operations and pending retention.
 *
 * Run with: wp eval-file tests/runtime-scheduler-operations.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_scheduler_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$subscribers    = newsletter_campaign_kit_get_subscribers_table();
$campaigns      = newsletter_campaign_kit_get_campaigns_table();
$queue          = newsletter_campaign_kit_get_queue_table();
$audit          = newsletter_campaign_kit_get_audit_table();
$list_map       = newsletter_campaign_kit_get_subscriber_lists_table();
$topic_map      = newsletter_campaign_kit_get_subscriber_topics_table();
$tag_map        = newsletter_campaign_kit_get_subscriber_tags_table();
$old_settings   = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$missing_option  = '__newsletter_campaign_kit_missing__';
$old_scheduler   = get_option( 'newsletter_campaign_kit_scheduler_state', $missing_option );
$old_maintenance = get_option( 'newsletter_campaign_kit_maintenance_state', $missing_option );
$subscriber_ids = array();
$campaign_id    = 0;
$cleared_cron   = false;
$now            = current_time( 'mysql', true );
$suffix         = strtolower( wp_generate_password( 7, false, false ) );
$settings       = newsletter_campaign_kit_get_provider_settings();
$settings['provider']               = 'external_filter';
$settings['queue_batch_size']       = 2;
$settings['pending_retention_days'] = 7;
$settings['cron_late_after_minutes'] = 2;

$provider = static function () {
	return true;
};
add_filter( 'newsletter_campaign_kit_send_email', $provider, 1, 4 );

try {
	newsletter_campaign_kit_activate();
	$unrelated = absint(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$campaigns} WHERE status = 'sending' OR (status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= %s)",
				$now
			)
		)
	);
	newsletter_scheduler_runtime_assert( 0 === $unrelated, 'Runtime scheduler test refused to alter existing active campaigns.' );

	update_option( 'newsletter_campaign_kit_provider_settings', $settings, false );
	$extreme = $settings;
	$extreme['queue_batch_size']        = 1000;
	$extreme['pending_retention_days']  = 0;
	$extreme['cron_late_after_minutes'] = 1000;
	update_option( 'newsletter_campaign_kit_provider_settings', $extreme, false );
	$bounded = newsletter_campaign_kit_get_provider_settings();
	newsletter_scheduler_runtime_assert( 100 === $bounded['queue_batch_size'] && 1 === $bounded['pending_retention_days'] && 60 === $bounded['cron_late_after_minutes'], 'Operational settings were not bounded.' );
	update_option( 'newsletter_campaign_kit_provider_settings', $settings, false );

	$pending_rows = array(
		array( 'email' => 'pending-old-' . $suffix . '@photovault.test', 'expires' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ),
		array( 'email' => 'pending-recent-' . $suffix . '@photovault.test', 'expires' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
	);
	foreach ( $pending_rows as $row ) {
		$email_hash = newsletter_campaign_kit_hash_email( $row['email'] );
		$wpdb->insert(
			$subscribers,
			array(
				'email_hash' => $email_hash,
				'email' => $row['email'],
				'unsubscribe_token' => newsletter_campaign_kit_create_unsubscribe_token( $email_hash ),
				'status' => 'pending',
				'confirmation_token_hash' => hash_hmac( 'sha256', wp_generate_password( 32, true, true ), wp_salt( 'secure_auth' ) ),
				'confirmation_expires_at' => $row['expires'],
				'confirmation_sent_at' => $row['expires'],
				'source' => 'runtime_scheduler',
				'consent_text' => 'Runtime scheduler retention consent',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$subscriber_ids[] = (int) $wpdb->insert_id;
	}
	$old_pending_id    = $subscriber_ids[0];
	$recent_pending_id = $subscriber_ids[1];
	newsletter_campaign_kit_log_event( 'runtime_pending_retention', 'info', $old_pending_id );
	newsletter_campaign_kit_assign_subscriber_to_list( $old_pending_id, newsletter_campaign_kit_get_default_list_id() );
	$cleaned = newsletter_campaign_kit_cleanup_expired_pending_subscribers( 7 );
	newsletter_scheduler_runtime_assert( 1 === $cleaned, 'Pending cleanup did not delete exactly the expired retained contact.' );
	newsletter_scheduler_runtime_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers} WHERE id = %d", $old_pending_id ) ), 'Old expired pending contact was retained.' );
	newsletter_scheduler_runtime_assert( (string) $recent_pending_id === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers} WHERE id = %d", $recent_pending_id ) ), 'Recent pending contact was deleted before retention elapsed.' );
	newsletter_scheduler_runtime_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE subscriber_id = %d", $old_pending_id ) ), 'Pending cleanup retained linked audit data.' );

	newsletter_scheduler_runtime_assert( newsletter_campaign_kit_acquire_operation_lock( 'runtime_scheduler_lock', 60 ), 'Operation lock could not be acquired.' );
	newsletter_scheduler_runtime_assert( ! newsletter_campaign_kit_acquire_operation_lock( 'runtime_scheduler_lock', 60 ), 'Operation lock allowed overlap.' );
	newsletter_campaign_kit_release_operation_lock( 'runtime_scheduler_lock' );
	newsletter_scheduler_runtime_assert( newsletter_campaign_kit_acquire_operation_lock( 'runtime_scheduler_lock', 60 ), 'Released operation lock could not be reacquired.' );
	newsletter_campaign_kit_release_operation_lock( 'runtime_scheduler_lock' );

	for ( $index = 1; $index <= 3; $index++ ) {
		$email = 'scheduler-batch-' . $index . '-' . $suffix . '@photovault.test';
		newsletter_scheduler_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_scheduler', 'Runtime scheduler delivery consent' ), 'Batch subscriber could not be created.' );
		$subscriber = newsletter_campaign_kit_get_subscriber_by_email( $email );
		$subscriber_ids[] = (int) $subscriber['id'];
	}
	$title = 'Scheduler operations ' . $suffix;
	$wpdb->insert(
		$campaigns,
		array( 'title' => $title, 'slug' => sanitize_title( $title ), 'subject' => $title, 'body' => '<p>Scheduler runtime</p>', 'text_body' => 'Scheduler runtime', 'status' => 'sending', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$campaign_id = (int) $wpdb->insert_id;
	foreach ( array_slice( $subscriber_ids, 2 ) as $subscriber_id ) {
		$wpdb->insert(
			$queue,
			array( 'campaign_id' => $campaign_id, 'subscriber_id' => $subscriber_id, 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now ),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
	}
	update_option( 'newsletter_campaign_kit_maintenance_state', array( 'last_pending_cleanup_at' => $now ), false );
	newsletter_campaign_kit_schedule_runner();
	$first_run = newsletter_campaign_kit_run_scheduler();
	newsletter_scheduler_runtime_assert( ! is_wp_error( $first_run ) && 2 === $first_run['processed']['processed'], 'Scheduler did not honor the configured batch size.' );
	newsletter_scheduler_runtime_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND status = 'pending'", $campaign_id ) ), 'First scheduler batch processed an unexpected number of rows.' );
	$health = newsletter_campaign_kit_get_scheduler_health();
	newsletter_scheduler_runtime_assert( 'healthy' === $health['status'] && 2 === (int) $health['last_result']['processed'], 'Successful scheduler heartbeat was not reported as healthy.' );
	$second_run = newsletter_campaign_kit_run_scheduler();
	newsletter_scheduler_runtime_assert( ! is_wp_error( $second_run ) && 1 === $second_run['processed']['processed'], 'Second scheduler batch did not process the remaining row.' );
	newsletter_scheduler_runtime_assert( 'sent' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$campaigns} WHERE id = %d", $campaign_id ) ), 'Scheduler did not finalize the completed campaign.' );
	$throwing_provider = static function () {
		throw new RuntimeException( 'Runtime provider exception' );
	};
	add_filter( 'newsletter_campaign_kit_send_email', $throwing_provider, 0, 4 );
	$retry_queue_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$queue} WHERE campaign_id = %d ORDER BY id ASC LIMIT 1", $campaign_id ) );
	$wpdb->update( $campaigns, array( 'status' => 'sending', 'sent_at' => null ), array( 'id' => $campaign_id ), array( '%s', '%s' ), array( '%d' ) );
	$wpdb->update( $queue, array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'sent_at' => null ), array( 'id' => $retry_queue_id ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) );
	$exception_run = newsletter_campaign_kit_run_scheduler();
	remove_filter( 'newsletter_campaign_kit_send_email', $throwing_provider, 0 );
	$retry_row = $wpdb->get_row( $wpdb->prepare( "SELECT status, attempts, locked_at FROM {$queue} WHERE id = %d", $retry_queue_id ), ARRAY_A );
	newsletter_scheduler_runtime_assert( ! is_wp_error( $exception_run ) && 'pending' === $retry_row['status'] && 1 === (int) $retry_row['attempts'] && null === $retry_row['locked_at'], 'Provider exception left the queue locked instead of scheduling a retry.' );
	$wpdb->update( $queue, array( 'status' => 'sent', 'sent_at' => $now ), array( 'id' => $retry_queue_id ), array( '%s', '%s' ), array( '%d' ) );
	$wpdb->update( $campaigns, array( 'status' => 'sent', 'sent_at' => $now ), array( 'id' => $campaign_id ), array( '%s', '%s' ), array( '%d' ) );

	update_option( 'newsletter_campaign_kit_scheduler_state', array( 'last_status' => 'success', 'last_completed_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ), false );
	newsletter_scheduler_runtime_assert( 'late' === newsletter_campaign_kit_get_scheduler_health()['status'], 'Late scheduler heartbeat was not detected.' );
	update_option( 'newsletter_campaign_kit_scheduler_state', array( 'last_status' => 'failed', 'last_failed_at' => $now ), false );
	newsletter_scheduler_runtime_assert( 'failed' === newsletter_campaign_kit_get_scheduler_health()['status'], 'Failed scheduler heartbeat was not exposed.' );
	wp_clear_scheduled_hook( 'newsletter_campaign_kit_run_scheduled' );
	$cleared_cron = true;
	newsletter_scheduler_runtime_assert( 'unscheduled' === newsletter_campaign_kit_get_scheduler_health()['status'], 'Missing recurring event was not detected.' );
	newsletter_campaign_kit_schedule_runner();
	$cleared_cron = false;
	delete_option( 'newsletter_campaign_kit_scheduler_state' );
	newsletter_scheduler_runtime_assert( 'pending' === newsletter_campaign_kit_get_scheduler_health()['status'], 'Registered scheduler without heartbeat was not reported as pending.' );

	echo wp_json_encode(
		array(
			'pending_retention' => 'bounded_transactional',
			'operation_lock'    => 'overlap_prevented',
			'batch_size'        => 'configured_and_enforced',
			'provider_exception' => 'retry_without_stale_lock',
			'heartbeat'         => 'success_duration_and_counts',
			'health_states'     => array( 'healthy', 'late', 'failed', 'unscheduled', 'pending' ),
		)
	);
} finally {
	remove_filter( 'newsletter_campaign_kit_send_email', $provider, 1 );
	if ( isset( $throwing_provider ) ) {
		remove_filter( 'newsletter_campaign_kit_send_email', $throwing_provider, 0 );
	}
	newsletter_campaign_kit_release_operation_lock( 'runtime_scheduler_lock' );
	newsletter_campaign_kit_release_operation_lock( 'scheduler' );
	newsletter_campaign_kit_release_operation_lock( 'pending_cleanup' );
	if ( $cleared_cron || ! wp_next_scheduled( 'newsletter_campaign_kit_run_scheduled' ) ) {
		newsletter_campaign_kit_schedule_runner();
	}
	update_option( 'newsletter_campaign_kit_provider_settings', $old_settings, false );
	if ( $missing_option === $old_scheduler ) {
		delete_option( 'newsletter_campaign_kit_scheduler_state' );
	} else {
		update_option( 'newsletter_campaign_kit_scheduler_state', $old_scheduler, false );
	}
	if ( $missing_option === $old_maintenance ) {
		delete_option( 'newsletter_campaign_kit_maintenance_state' );
	} else {
		update_option( 'newsletter_campaign_kit_maintenance_state', $old_maintenance, false );
	}
	if ( $campaign_id ) {
		$wpdb->delete( $queue, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $subscriber_ids ) ) as $subscriber_id ) {
		$wpdb->delete( $queue, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $list_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $topic_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
}
