<?php
/**
 * WordPress runtime verification for report queries and filters.
 *
 * Run with: wp eval-file tests/runtime-reports.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_runtime_reports_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$all_count = newsletter_campaign_kit_count_campaign_reports();
$rows      = newsletter_campaign_kit_get_campaign_reports( array( 'limit' => 3, 'offset' => 0 ) );
newsletter_runtime_reports_assert( count( $rows ) <= 3, 'Report pagination limit was not respected.' );
newsletter_runtime_reports_assert( $all_count >= count( $rows ), 'Report count is smaller than the first page.' );

if ( $rows ) {
	$campaign_id = absint( $rows[0]['id'] );
	$filtered    = newsletter_campaign_kit_get_campaign_reports( array( 'campaign_id' => $campaign_id, 'limit' => 25 ) );
	newsletter_runtime_reports_assert( 1 === count( $filtered ), 'Campaign filter did not return exactly one report.' );
	newsletter_runtime_reports_assert( $campaign_id === absint( $filtered[0]['id'] ), 'Campaign filter returned another campaign.' );
	newsletter_runtime_reports_assert( 1 === newsletter_campaign_kit_count_campaign_reports( array( 'campaign_id' => $campaign_id ) ), 'Filtered report count is inconsistent.' );
}

$subscriptions = newsletter_campaign_kit_get_subscription_breakdowns();
newsletter_runtime_reports_assert( isset( $subscriptions['monthly'], $subscriptions['sources'] ), 'Subscription breakdown shape is incomplete.' );
newsletter_runtime_reports_assert( is_array( $subscriptions['monthly'] ) && is_array( $subscriptions['sources'] ), 'Subscription breakdown values must be arrays.' );

$provider = newsletter_campaign_kit_get_provider_event_totals();
newsletter_runtime_reports_assert( isset( $provider['bounce'], $provider['complaint'] ), 'Provider outcome totals are incomplete.' );

echo "Newsletter report runtime tests passed.\n";
