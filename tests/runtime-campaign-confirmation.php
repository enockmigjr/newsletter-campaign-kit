<?php
/**
 * WordPress runtime verification for reviewed campaign delivery.
 *
 * Run with: wp eval-file tests/runtime-campaign-confirmation.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_confirmation_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix          = strtolower( wp_generate_password( 8, false, false ) );
$emails          = array(
	'initial' => 'confirmation-initial-' . $suffix . '@photovault.test',
	'changed' => 'confirmation-changed-' . $suffix . '@photovault.test',
	'later'   => 'confirmation-later-' . $suffix . '@photovault.test',
);
$subscriber_ids  = array();
$campaign_ids    = array();
$snapshot_ids    = array();
$list_id         = 0;
$lists_table     = newsletter_campaign_kit_get_lists_table();
$subscribers     = newsletter_campaign_kit_get_subscribers_table();
$list_map        = newsletter_campaign_kit_get_subscriber_lists_table();
$tag_map         = newsletter_campaign_kit_get_subscriber_tags_table();
$topic_map       = newsletter_campaign_kit_get_subscriber_topics_table();
$suppressions    = newsletter_campaign_kit_get_suppressions_table();
$campaigns       = newsletter_campaign_kit_get_campaigns_table();
$queue           = newsletter_campaign_kit_get_queue_table();
$snapshots       = newsletter_campaign_kit_get_audience_snapshots_table();
$snapshot_members = newsletter_campaign_kit_get_audience_snapshot_members_table();
$audit           = newsletter_campaign_kit_get_audit_table();
$original_user   = get_current_user_id();

try {
	$now       = current_time( 'mysql', true );
	$list_name = 'Confirmation list ' . $suffix;
	$wpdb->insert(
		$lists_table,
		array( 'name' => $list_name, 'slug' => sanitize_title( $list_name ), 'description' => 'Runtime delivery review', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$list_id = (int) $wpdb->insert_id;

	foreach ( $emails as $key => $email ) {
		newsletter_confirmation_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_confirmation', 'Runtime confirmation consent' ), 'A fixture subscriber could not be created.' );
		$subscriber_ids[ $key ] = (int) newsletter_campaign_kit_get_subscriber_by_email( $email )['id'];
	}
	newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_ids['initial'], $list_id );

	$title       = 'Confirmed campaign ' . $suffix;
	$campaign_id = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => $title,
			'subject'         => 'Runtime confirmed delivery',
			'html_body'       => '<p>Confirmed delivery</p>',
			'text_body'       => 'Confirmed delivery',
			'target_audience' => 'list:' . $list_id,
		),
		1
	);
	newsletter_confirmation_runtime_assert( is_int( $campaign_id ), 'The immediate campaign could not be created.' );
	$campaign_ids[] = $campaign_id;
	$wpdb->update( $campaigns, array( 'status' => 'ready' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );
	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	$review   = newsletter_campaign_kit_prepare_campaign_delivery_review( $campaign );
	newsletter_confirmation_runtime_assert( ! is_wp_error( $review ) && 1 === $review['recipient_count'], 'The initial review has the wrong audience.' );
	newsletter_confirmation_runtime_assert( 'newsletter_campaign_title_mismatch' === newsletter_campaign_kit_validate_campaign_delivery_confirmation( $campaign, 'Wrong title', $review['fingerprint'] )->get_error_code(), 'A wrong confirmation title was accepted.' );

	newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_ids['changed'], $list_id );
	$stale = newsletter_campaign_kit_start_confirmed_campaign_delivery( $campaign_id, $title, $review['fingerprint'], 1 );
	newsletter_confirmation_runtime_assert( is_wp_error( $stale ) && 'newsletter_campaign_review_stale' === $stale->get_error_code(), 'A stale audience proof was accepted.' );
	newsletter_confirmation_runtime_assert( 'ready' === newsletter_campaign_kit_get_campaign( $campaign_id )['status'], 'A rejected confirmation changed the campaign state.' );
	newsletter_confirmation_runtime_assert( null === newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id ), 'A rejected confirmation left an audience snapshot.' );

	$fresh_review = newsletter_campaign_kit_prepare_campaign_delivery_review( newsletter_campaign_kit_get_campaign( $campaign_id ) );
	$started      = newsletter_campaign_kit_start_confirmed_campaign_delivery( $campaign_id, $title, $fresh_review['fingerprint'], 1 );
	newsletter_confirmation_runtime_assert( 2 === $started, 'Confirmed immediate delivery did not queue the reviewed audience.' );
	newsletter_confirmation_runtime_assert( 'sending' === newsletter_campaign_kit_get_campaign( $campaign_id )['status'], 'Confirmed immediate delivery did not enter sending state.' );
	$immediate_snapshot = newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id );
	$snapshot_ids[]     = (int) $immediate_snapshot['id'];
	newsletter_confirmation_runtime_assert( 2 === (int) $immediate_snapshot['recipient_count'], 'The immediate snapshot count is incorrect.' );

	$wpdb->update( $campaigns, array( 'status' => 'paused' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );
	$wpdb->update( $queue, array( 'status' => 'paused' ), array( 'campaign_id' => $campaign_id ), array( '%s' ), array( '%d' ) );
	$resume_review = newsletter_campaign_kit_prepare_campaign_delivery_review( newsletter_campaign_kit_get_campaign( $campaign_id ) );
	$resumed       = newsletter_campaign_kit_start_confirmed_campaign_delivery( $campaign_id, $title, $resume_review['fingerprint'], 1 );
	$pending_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d AND status = 'pending'", $campaign_id ) );
	newsletter_confirmation_runtime_assert( 0 === $resumed && 2 === $pending_count, 'A confirmed resume did not reactivate the paused queue.' );

	$scheduled_title = 'Scheduled confirmation ' . $suffix;
	$scheduled_id    = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => $scheduled_title,
			'subject'         => 'Runtime frozen schedule',
			'html_body'       => '<p>Frozen schedule</p>',
			'text_body'       => 'Frozen schedule',
			'target_audience' => 'list:' . $list_id,
		),
		1
	);
	newsletter_confirmation_runtime_assert( is_int( $scheduled_id ), 'The scheduled campaign could not be created.' );
	$campaign_ids[] = $scheduled_id;
	$wpdb->update( $campaigns, array( 'status' => 'ready' ), array( 'id' => $scheduled_id ), array( '%s' ), array( '%d' ) );
	$scheduled_review = newsletter_campaign_kit_prepare_campaign_delivery_review( newsletter_campaign_kit_get_campaign( $scheduled_id ) );
	$invalid_schedule = newsletter_campaign_kit_schedule_confirmed_campaign( $scheduled_id, '2026-99-99 25:61:00', $scheduled_title, $scheduled_review['fingerprint'], 1 );
	newsletter_confirmation_runtime_assert( is_wp_error( $invalid_schedule ) && 'newsletter_invalid_schedule' === $invalid_schedule->get_error_code(), 'The scheduling service accepted an invalid date.' );
	$future           = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
	$scheduled_result = newsletter_campaign_kit_schedule_confirmed_campaign( $scheduled_id, $future, $scheduled_title, $scheduled_review['fingerprint'], 1 );
	newsletter_confirmation_runtime_assert( ! is_wp_error( $scheduled_result ) && 2 === (int) $scheduled_result['recipient_count'], 'Scheduling did not atomically freeze the reviewed audience.' );
	$snapshot_ids[] = (int) $scheduled_result['id'];

	newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_ids['later'], $list_id );
	$wpdb->update( $campaigns, array( 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ), array( 'id' => $scheduled_id ), array( '%s' ), array( '%d' ) );
	$claimed = newsletter_campaign_kit_claim_due_campaigns( 5 );
	$queued  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE campaign_id = %d", $scheduled_id ) );
	newsletter_confirmation_runtime_assert( in_array( $scheduled_id, $claimed, true ) && 2 === $queued, 'The scheduler did not preserve the confirmed audience snapshot.' );

	$administrator_ids = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	newsletter_confirmation_runtime_assert( ! empty( $administrator_ids ), 'An administrator is required to render the review page.' );
	$wpdb->update( $campaigns, array( 'status' => 'paused' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );
	wp_set_current_user( (int) $administrator_ids[0] );
	$_GET['campaign_id'] = $campaign_id;
	ob_start();
	newsletter_campaign_kit_render_campaign_review_page();
	$review_html = ob_get_clean();
	newsletter_confirmation_runtime_assert( false !== strpos( $review_html, 'Eligible recipients' ) && false !== strpos( $review_html, 'campaign_confirmation_fingerprint' ) && false !== strpos( $review_html, 'Confirm and send now' ), 'The protected review screen is incomplete.' );

	WP_CLI::success(
		wp_json_encode(
			array(
				'wrong_title'             => 'rejected',
				'stale_audience'           => 'rejected_without_side_effects',
				'immediate_delivery'       => 'snapshot_and_queue_atomic',
				'paused_delivery'          => 'queue_reactivated',
				'scheduled_delivery'       => 'audience_frozen_at_confirmation',
				'admin_review'             => 'rendered_with_proof',
			)
		)
	);
} finally {
	wp_set_current_user( $original_user );
	unset( $_GET['campaign_id'] );
	foreach ( array_unique( array_filter( $campaign_ids ) ) as $campaign_id ) {
		$wpdb->delete( $queue, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $snapshot_ids ) ) as $snapshot_id ) {
		$wpdb->delete( $snapshot_members, array( 'snapshot_id' => $snapshot_id ), array( '%d' ) );
		$wpdb->delete( $snapshots, array( 'id' => $snapshot_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $subscriber_ids ) ) as $subscriber_id ) {
		$wpdb->delete( $list_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $topic_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	foreach ( $emails as $email ) {
		$wpdb->delete( $suppressions, array( 'email_hash' => newsletter_campaign_kit_hash_email( $email ) ), array( '%s' ) );
	}
	if ( $list_id ) {
		$wpdb->delete( $list_map, array( 'list_id' => $list_id ), array( '%d' ) );
		$wpdb->delete( $lists_table, array( 'id' => $list_id ), array( '%d' ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE context LIKE %s", '%' . $wpdb->esc_like( $suffix ) . '%' ) );
}
