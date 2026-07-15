<?php
/**
 * WordPress runtime verification for immutable campaign audience snapshots.
 *
 * Run with: wp eval-file tests/runtime-audience-snapshots.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_snapshot_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix            = strtolower( wp_generate_password( 8, false, false ) );
$emails            = array(
	'stable' => 'snapshot-stable-' . $suffix . '@photovault.test',
	'left'   => 'snapshot-left-' . $suffix . '@photovault.test',
	'new'    => 'snapshot-new-' . $suffix . '@photovault.test',
);
$subscriber_ids    = array();
$campaign_ids      = array();
$snapshot_ids      = array();
$list_id           = 0;
$lists_table       = newsletter_campaign_kit_get_lists_table();
$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
$list_map_table    = newsletter_campaign_kit_get_subscriber_lists_table();
$tag_map_table     = newsletter_campaign_kit_get_subscriber_tags_table();
$preferences_table = newsletter_campaign_kit_get_subscriber_topics_table();
$suppressions_table = newsletter_campaign_kit_get_suppressions_table();
$campaigns_table   = newsletter_campaign_kit_get_campaigns_table();
$queue_table       = newsletter_campaign_kit_get_queue_table();
$snapshots_table   = newsletter_campaign_kit_get_audience_snapshots_table();
$members_table     = newsletter_campaign_kit_get_audience_snapshot_members_table();
$audit_table       = newsletter_campaign_kit_get_audit_table();
$original_user_id  = get_current_user_id();
$provider = static function () {
	return true;
};

try {
	newsletter_snapshot_runtime_assert( newsletter_campaign_kit_audience_snapshot_tables_exist(), 'Audience snapshot tables were not migrated.' );
	$now       = current_time( 'mysql', true );
	$list_name = 'Runtime snapshot list ' . $suffix;
	$wpdb->insert(
		$lists_table,
		array( 'name' => $list_name, 'slug' => sanitize_title( $list_name ), 'description' => 'Immutable runtime audience', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$list_id = (int) $wpdb->insert_id;
	foreach ( $emails as $email ) {
		newsletter_snapshot_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_snapshot', 'Runtime snapshot consent' ), 'A snapshot fixture subscriber could not be created.' );
		$subscriber = newsletter_campaign_kit_get_subscriber_by_email( $email );
		$subscriber_ids[ array_search( $email, $emails, true ) ] = (int) $subscriber['id'];
	}
	newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_ids['stable'], $list_id );
	newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_ids['left'], $list_id );

	$campaign_id = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => 'Runtime snapshot campaign ' . $suffix,
			'subject'         => 'Runtime immutable audience',
			'html_body'       => '<p>Snapshot runtime body</p>',
			'text_body'       => 'Snapshot runtime body',
			'target_audience' => 'list:' . $list_id,
		),
		1
	);
	newsletter_snapshot_runtime_assert( is_int( $campaign_id ), 'The snapshot campaign could not be created.' );
	$campaign_ids[] = $campaign_id;
	$wpdb->update( $campaigns_table, array( 'status' => 'sending' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );
	$created = newsletter_campaign_kit_enqueue_campaign( $campaign_id );
	newsletter_snapshot_runtime_assert( 2 === $created, 'The initial queue did not capture both intended subscribers.' );
	$snapshot = newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id );
	newsletter_snapshot_runtime_assert( $snapshot && 2 === (int) $snapshot['recipient_count'], 'Snapshot metadata has the wrong recipient count.' );
	$snapshot_ids[] = (int) $snapshot['id'];
	$initial_members = newsletter_campaign_kit_get_audience_snapshot_member_ids( $snapshot['id'] );
	sort( $initial_members );
	$expected_members = array( $subscriber_ids['stable'], $subscriber_ids['left'] );
	sort( $expected_members );
	newsletter_snapshot_runtime_assert( $expected_members === $initial_members, 'Snapshot members do not match the initial list.' );

	$wpdb->delete( $list_map_table, array( 'subscriber_id' => $subscriber_ids['left'], 'list_id' => $list_id ), array( '%d', '%d' ) );
	newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_ids['new'], $list_id );
	$wpdb->update( $lists_table, array( 'name' => 'Renamed after snapshot', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $list_id ), array( '%s', '%s' ), array( '%d' ) );
	newsletter_snapshot_runtime_assert( 0 === newsletter_campaign_kit_enqueue_campaign( $campaign_id ), 'A repeated enqueue duplicated or expanded the frozen queue.' );
	newsletter_snapshot_runtime_assert( $initial_members === newsletter_campaign_kit_get_audience_snapshot_member_ids( $snapshot['id'] ), 'The snapshot changed after live audience mutation.' );
	newsletter_snapshot_runtime_assert( $list_name === newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id )['audience_label'], 'The stored audience label followed a later list rename.' );
	newsletter_snapshot_runtime_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$queue_table} WHERE campaign_id = %d AND subscriber_id = %d", $campaign_id, $subscriber_ids['new'] ) ), 'A later list member leaked into the frozen queue.' );

	newsletter_campaign_kit_set_subscriber_status( $subscriber_ids['left'], 'unsubscribed', 'runtime_snapshot' );
	add_filter( 'newsletter_campaign_kit_send_email', $provider, 99 );
	$processed = newsletter_campaign_kit_process_queue_batch( 10 );
	remove_filter( 'newsletter_campaign_kit_send_email', $provider, 99 );
	newsletter_snapshot_runtime_assert( 1 === $processed['sent'], 'The still-eligible snapshot member was not delivered.' );
	$cancelled_reason = $wpdb->get_var( $wpdb->prepare( "SELECT last_error FROM {$queue_table} WHERE campaign_id = %d AND subscriber_id = %d", $campaign_id, $subscriber_ids['left'] ) );
	newsletter_snapshot_runtime_assert( 'not_subscribed' === $cancelled_reason, 'Final eligibility did not cancel a snapshot member who later unsubscribed.' );

	$reports = newsletter_campaign_kit_get_campaign_reports( 100 );
	$report  = current( array_filter( $reports, static function ( $item ) use ( $campaign_id ) { return (int) $item['id'] === $campaign_id; } ) );
	newsletter_snapshot_runtime_assert( $report && (int) $report['snapshot_recipient_count'] === 2 && $list_name === $report['audience_label'], 'Campaign reports do not expose the immutable snapshot.' );
	$member_columns = array_map( 'strtolower', $wpdb->get_col( 'SHOW COLUMNS FROM ' . $members_table ) );
	newsletter_snapshot_runtime_assert( in_array( 'member_key', $member_columns, true ) && ! in_array( 'email', $member_columns, true ) && ! in_array( 'email_hash', $member_columns, true ), 'Snapshot members do not use the expected minimized schema.' );

	$scheduled_id = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => 'Runtime scheduled snapshot ' . $suffix,
			'subject'         => 'Runtime scheduled audience',
			'html_body'       => '<p>Scheduled snapshot</p>',
			'text_body'       => 'Scheduled snapshot',
			'target_audience' => 'list:' . $list_id,
		),
		1
	);
	newsletter_snapshot_runtime_assert( is_int( $scheduled_id ), 'The scheduled snapshot campaign could not be created.' );
	$campaign_ids[] = $scheduled_id;
	$wpdb->update( $campaigns_table, array( 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ), array( 'id' => $scheduled_id ), array( '%s', '%s' ), array( '%d' ) );
	$claimed = newsletter_campaign_kit_claim_due_campaigns( 10 );
	newsletter_snapshot_runtime_assert( in_array( $scheduled_id, $claimed, true ), 'The scheduler did not claim the due campaign.' );
	$scheduled_snapshot = newsletter_campaign_kit_get_campaign_audience_snapshot( $scheduled_id );
	newsletter_snapshot_runtime_assert( $scheduled_snapshot && 'sending' === newsletter_campaign_kit_get_campaign( $scheduled_id )['status'], 'Scheduled claim did not atomically create its snapshot and sending state.' );
	$snapshot_ids[] = (int) $scheduled_snapshot['id'];

	$failure_id = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => 'Runtime failed snapshot ' . $suffix,
			'subject'         => 'Runtime failed audience',
			'html_body'       => '<p>Failed snapshot</p>',
			'text_body'       => 'Failed snapshot',
			'target_audience' => 'list:' . $list_id,
		),
		1
	);
	newsletter_snapshot_runtime_assert( is_int( $failure_id ), 'The failure-case campaign could not be created.' );
	$campaign_ids[] = $failure_id;
	$wpdb->update( $campaigns_table, array( 'target_list_id' => 999999999 ), array( 'id' => $failure_id ), array( '%d' ), array( '%d' ) );
	$failed_enqueue = newsletter_campaign_kit_enqueue_campaign( $failure_id );
	newsletter_snapshot_runtime_assert( is_wp_error( $failed_enqueue ), 'A campaign with a missing audience did not fail closed.' );
	newsletter_snapshot_runtime_assert( null === newsletter_campaign_kit_get_campaign_audience_snapshot( $failure_id ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$queue_table} WHERE campaign_id = %d", $failure_id ) ), 'Failed snapshot creation left partial delivery data.' );

	$administrator_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	newsletter_snapshot_runtime_assert( ! empty( $administrator_ids ), 'An administrator is required for report rendering.' );
	wp_set_current_user( (int) $administrator_ids[0] );
	ob_start();
	newsletter_campaign_kit_render_reports_page();
	$report_html = ob_get_clean();
	newsletter_snapshot_runtime_assert( false !== strpos( $report_html, $list_name ) && false !== strpos( $report_html, 'Stored rules' ), 'The admin report did not render stored audience evidence.' );

	$privacy_export = newsletter_campaign_kit_privacy_exporter( $emails['left'], 1 );
	$privacy_values = wp_list_pluck( $privacy_export['data'][0]['data'], 'value', 'name' );
	$snapshot_export_label = __( 'Campaign audience snapshots', 'newsletter-campaign-kit' );
	newsletter_snapshot_runtime_assert( 1 === (int) ( $privacy_values[ $snapshot_export_label ] ?? 0 ), 'Privacy export did not disclose snapshot membership.' );
	$left_member_key = hash_hmac( 'sha256', 'snapshot:' . $snapshot['id'] . ':subscriber:' . $subscriber_ids['left'], wp_salt( 'nonce' ) );
	$privacy_erasure = newsletter_campaign_kit_privacy_eraser( $emails['left'], 1 );
	$anonymized_member = $wpdb->get_row( $wpdb->prepare( "SELECT subscriber_id, member_key FROM {$members_table} WHERE snapshot_id = %d AND member_key = %s LIMIT 1", $snapshot['id'], $left_member_key ), ARRAY_A );
	newsletter_snapshot_runtime_assert( true === $privacy_erasure['items_removed'] && true === $privacy_erasure['items_retained'], 'Privacy erasure did not report retained opaque snapshot evidence.' );
	newsletter_snapshot_runtime_assert( $anonymized_member && null === $anonymized_member['subscriber_id'] && 64 === strlen( $anonymized_member['member_key'] ), 'Privacy erasure did not detach the subscriber ID from its opaque membership proof.' );

	echo wp_json_encode(
		array(
			'snapshot_creation'   => 'atomic_with_queue',
			'audience_immutability' => true,
			'enqueue_idempotence' => true,
			'final_eligibility'   => 'enforced',
			'scheduled_claim'     => 'snapshot_and_status',
			'failure_rollback'    => 'no_partial_delivery_data',
			'data_minimization'   => 'ids_then_opaque_key_after_erasure',
			'privacy_tools'       => 'export_and_anonymization',
			'admin_reporting'     => 'metadata_and_rules',
		)
	);
} finally {
	remove_filter( 'newsletter_campaign_kit_send_email', $provider, 99 );
	wp_set_current_user( $original_user_id );
	foreach ( array_unique( array_filter( $campaign_ids ) ) as $campaign_id ) {
		$audit_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$audit_table} WHERE context LIKE %s OR context LIKE %s", '%"campaign_id":' . $campaign_id . '%', '%' . $wpdb->esc_like( $suffix ) . '%' ) );
		foreach ( $audit_ids as $audit_id ) {
			$wpdb->delete( $audit_table, array( 'id' => absint( $audit_id ) ), array( '%d' ) );
		}
		$wpdb->delete( $queue_table, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns_table, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $snapshot_ids ) ) as $snapshot_id ) {
		$wpdb->delete( $members_table, array( 'snapshot_id' => $snapshot_id ), array( '%d' ) );
		$wpdb->delete( $snapshots_table, array( 'id' => $snapshot_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $subscriber_ids ) ) as $subscriber_id ) {
		$wpdb->delete( $list_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $preferences_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	foreach ( $emails as $email ) {
		$wpdb->delete( $suppressions_table, array( 'email_hash' => newsletter_campaign_kit_hash_email( $email ) ), array( '%s' ) );
	}
	if ( $list_id ) {
		$wpdb->delete( $list_map_table, array( 'list_id' => $list_id ), array( '%d' ) );
		$wpdb->delete( $lists_table, array( 'id' => $list_id ), array( '%d' ) );
	}
}
