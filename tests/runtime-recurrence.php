<?php
/**
 * WordPress runtime verification for recurring campaign occurrences.
 *
 * Run with: wp eval-file tests/runtime-recurrence.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_recurrence_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix          = strtolower( wp_generate_password( 8, false, false ) );
$campaigns_table = newsletter_campaign_kit_get_campaigns_table();
$queue_table     = newsletter_campaign_kit_get_queue_table();
$snapshots_table = newsletter_campaign_kit_get_audience_snapshots_table();
$members_table   = newsletter_campaign_kit_get_audience_snapshot_members_table();
$master_id       = 0;
$occurrence_ids  = array();

try {
	$master_id = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => 'Runtime recurrence ' . $suffix,
			'subject'         => 'Runtime recurrence',
			'html_body'       => '<p>Runtime recurrence</p>',
			'text_body'       => 'Runtime recurrence',
			'target_audience' => 'all',
		),
		1
	);
	newsletter_recurrence_runtime_assert( is_int( $master_id ), 'Recurring master could not be created.' );
	$wpdb->update( $campaigns_table, array( 'status' => 'ready' ), array( 'id' => $master_id ), array( '%s' ), array( '%d' ) );

	$master = newsletter_campaign_kit_get_campaign( $master_id );
	$review = newsletter_campaign_kit_prepare_campaign_delivery_review( $master );
	$first  = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
	$until  = gmdate( 'Y-m-d', time() + ( 3 * DAY_IN_SECONDS ) );
	$result = newsletter_campaign_kit_schedule_confirmed_recurrence( $master_id, $first, 2, $until, $master['title'], $review['fingerprint'], 1 );
	newsletter_recurrence_runtime_assert( true === $result, 'Recurring master could not be scheduled.' );

	$wpdb->update(
		$campaigns_table,
		array( 'next_occurrence_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ),
		array( 'id' => $master_id ),
		array( '%s' ),
		array( '%d' )
	);
	$occurrence_ids = newsletter_campaign_kit_claim_due_recurrences( 1 );
	newsletter_recurrence_runtime_assert( 1 === count( $occurrence_ids ), 'Due recurrence did not create exactly one occurrence.' );

	$occurrence = newsletter_campaign_kit_get_campaign( $occurrence_ids[0] );
	$master     = newsletter_campaign_kit_get_campaign( $master_id );
	newsletter_recurrence_runtime_assert( $master_id === absint( $occurrence['parent_campaign_id'] ?? 0 ), 'Occurrence lost its master relationship.' );
	newsletter_recurrence_runtime_assert( 1 === absint( $occurrence['occurrence_number'] ?? 0 ), 'Occurrence sequence is invalid.' );
	newsletter_recurrence_runtime_assert( 'recurring' === $master['status'] && ! empty( $master['next_occurrence_at'] ), 'Recurring master did not advance to its next run.' );

	echo wp_json_encode(
		array(
			'master'         => 'advanced',
			'occurrence'     => 'created',
			'sequence'       => 1,
			'interval_days'  => 2,
		)
	);
} finally {
	foreach ( $occurrence_ids as $occurrence_id ) {
		$snapshot_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$snapshots_table} WHERE campaign_id = %d", $occurrence_id ) );
		foreach ( $snapshot_ids as $snapshot_id ) {
			$wpdb->delete( $members_table, array( 'snapshot_id' => $snapshot_id ), array( '%d' ) );
		}
		$wpdb->delete( $snapshots_table, array( 'campaign_id' => $occurrence_id ), array( '%d' ) );
		$wpdb->delete( $queue_table, array( 'campaign_id' => $occurrence_id ), array( '%d' ) );
		$wpdb->delete( $campaigns_table, array( 'id' => $occurrence_id ), array( '%d' ) );
	}
	if ( $master_id ) {
		$wpdb->delete( $campaigns_table, array( 'id' => $master_id ), array( '%d' ) );
	}
}
