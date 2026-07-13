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
		$updated     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'sending', updated_at = %s WHERE id = %d AND status = 'scheduled' AND scheduled_at <= %s",
				$now,
				$campaign_id,
				$now
			)
		);
		if ( 1 === $updated ) {
			newsletter_campaign_kit_enqueue_campaign( $campaign_id );
			$claimed[] = $campaign_id;
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

function newsletter_campaign_kit_run_scheduler() {
	$recovered = newsletter_campaign_kit_recover_stale_queue_locks();
	$claimed   = newsletter_campaign_kit_claim_due_campaigns();
	$processed = newsletter_campaign_kit_process_queue_batch( 20 );
	$finalized = newsletter_campaign_kit_finalize_campaigns();

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) && ( $recovered || $claimed || $processed['processed'] || $finalized ) ) {
		newsletter_campaign_kit_log_event(
			'newsletter_scheduler_run',
			'info',
			0,
			array(
				'recovered' => $recovered,
				'claimed'   => count( $claimed ),
				'processed' => $processed['processed'],
				'finalized' => $finalized,
			)
		);
	}

	return array(
		'recovered' => $recovered,
		'claimed'   => count( $claimed ),
		'processed' => $processed,
		'finalized' => $finalized,
	);
}
add_action( 'newsletter_campaign_kit_run_scheduled', 'newsletter_campaign_kit_run_scheduler' );
