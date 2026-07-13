<?php
/**
 * WordPress runtime verification for campaign and segment lifecycles.
 *
 * Run with: wp eval-file tests/runtime-lifecycle.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_lifecycle_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix            = strtolower( wp_generate_password( 8, false, false ) );
$email             = 'lifecycle-' . $suffix . '@photovault.test';
$email_hash        = newsletter_campaign_kit_hash_email( $email );
$lists_table       = newsletter_campaign_kit_get_lists_table();
$segments_table    = newsletter_campaign_kit_get_segments_table();
$campaigns_table   = newsletter_campaign_kit_get_campaigns_table();
$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
$queue_table       = newsletter_campaign_kit_get_queue_table();
$audit_table       = newsletter_campaign_kit_get_audit_table();
$list_map_table    = newsletter_campaign_kit_get_subscriber_lists_table();
$campaign_ids      = array();
$segment_ids       = array();
$subscriber_id     = 0;
$list_id           = 0;
$previous_user_id  = get_current_user_id();

try {
	newsletter_campaign_kit_activate();
	$now = current_time( 'mysql', true );
	$wpdb->insert(
		$lists_table,
		array(
			'name'        => 'Lifecycle ' . $suffix,
			'slug'        => 'lifecycle-' . $suffix,
			'description' => 'Runtime lifecycle audience',
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		)
	);
	$list_id = (int) $wpdb->insert_id;
	newsletter_lifecycle_runtime_assert( $list_id > 0, 'Runtime list creation failed.' );

	$wpdb->delete( $subscribers_table, array( 'email_hash' => $email_hash ), array( '%s' ) );
	$subscribed = newsletter_campaign_kit_subscribe_email( $email, 'runtime_lifecycle', 'Lifecycle verification consent' );
	newsletter_lifecycle_runtime_assert( true === $subscribed, 'Runtime subscriber creation failed.' );
	$subscriber_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ) );
	newsletter_lifecycle_runtime_assert( $subscriber_id > 0 && newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_id, $list_id ), 'Runtime subscriber assignment failed.' );

	$segment_id = newsletter_campaign_kit_create_segment(
		array(
			'name'        => 'Lifecycle segment ' . $suffix,
			'description' => 'Initial runtime rule',
			'match_type'  => 'all',
			'rules'       => array( 'list_ids' => array( $list_id ) ),
		)
	);
	newsletter_lifecycle_runtime_assert( ! is_wp_error( $segment_id ), 'Segment service creation failed.' );
	$segment_ids[] = (int) $segment_id;
	newsletter_lifecycle_runtime_assert( 1 === newsletter_campaign_kit_get_segment_audience_count( $segment_id ), 'Segment audience estimate is incorrect.' );

	$segment_updated = newsletter_campaign_kit_update_segment(
		$segment_id,
		array(
			'name'        => 'Lifecycle segment updated ' . $suffix,
			'description' => 'Updated runtime rule',
			'match_type'  => 'any',
			'rules'       => array( 'list_ids' => array( $list_id ), 'sources' => array( 'runtime_lifecycle' ) ),
		)
	);
	newsletter_lifecycle_runtime_assert( true === $segment_updated, 'Active segment update failed.' );
	$segment = newsletter_campaign_kit_get_segment( $segment_id );
	newsletter_lifecycle_runtime_assert( 'any' === $segment['match_type'] && 1 === newsletter_campaign_kit_get_segment_audience_count( $segment_id ), 'Updated segment rules were not applied.' );

	$segment_duplicate_id = newsletter_campaign_kit_duplicate_segment( $segment_id );
	newsletter_lifecycle_runtime_assert( ! is_wp_error( $segment_duplicate_id ), 'Segment duplication failed.' );
	$segment_ids[] = (int) $segment_duplicate_id;
	newsletter_lifecycle_runtime_assert( 'active' === newsletter_campaign_kit_get_segment( $segment_duplicate_id, true )['status'], 'A duplicated segment was not active.' );

	$campaign_input = array(
		'title'           => 'Lifecycle campaign ' . $suffix,
		'subject'         => 'Lifecycle subject',
		'preview_text'    => 'Lifecycle preview',
		'html_body'       => '<p>Lifecycle campaign body</p>',
		'text_body'       => 'Lifecycle campaign body',
		'target_audience' => 'segment:' . $segment_id,
	);
	$campaign_id = newsletter_campaign_kit_create_campaign( $campaign_input, $previous_user_id );
	newsletter_lifecycle_runtime_assert( ! is_wp_error( $campaign_id ), 'Campaign service creation failed.' );
	$campaign_ids[] = (int) $campaign_id;
	newsletter_lifecycle_runtime_assert( 1 === newsletter_campaign_kit_get_campaign_audience_count( newsletter_campaign_kit_get_campaign( $campaign_id ) ), 'Campaign audience estimate is incorrect.' );

	$campaign_input['title']   = 'Lifecycle campaign updated ' . $suffix;
	$campaign_input['subject'] = 'Lifecycle subject updated';
	newsletter_lifecycle_runtime_assert( true === newsletter_campaign_kit_update_campaign( $campaign_id, $campaign_input, $previous_user_id ), 'Draft campaign update failed.' );
	$wpdb->update( $campaigns_table, array( 'status' => 'ready' ), array( 'id' => $campaign_id ), array( '%s' ), array( '%d' ) );
	$locked_update = newsletter_campaign_kit_update_campaign( $campaign_id, $campaign_input, $previous_user_id );
	newsletter_lifecycle_runtime_assert( is_wp_error( $locked_update ) && 'newsletter_campaign_locked' === $locked_update->get_error_code(), 'A ready campaign remained editable.' );

	$campaign_duplicate_id = newsletter_campaign_kit_duplicate_campaign( $campaign_id, $previous_user_id );
	newsletter_lifecycle_runtime_assert( ! is_wp_error( $campaign_duplicate_id ), 'Campaign duplication failed.' );
	$campaign_ids[] = (int) $campaign_duplicate_id;
	$campaign_duplicate = newsletter_campaign_kit_get_campaign( $campaign_duplicate_id );
	newsletter_lifecycle_runtime_assert( 'draft' === $campaign_duplicate['status'] && empty( $campaign_duplicate['scheduled_at'] ) && empty( $campaign_duplicate['sent_at'] ), 'A campaign duplicate inherited delivery state.' );
	$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE campaign_id = %d", $campaign_duplicate_id ) );
	newsletter_lifecycle_runtime_assert( 0 === $queued, 'Campaign duplication unexpectedly created queue entries.' );

	$archive_blocked = newsletter_campaign_kit_set_segment_status( $segment_id, 'archived' );
	newsletter_lifecycle_runtime_assert( is_wp_error( $archive_blocked ) && 'newsletter_segment_in_use' === $archive_blocked->get_error_code(), 'An in-use segment was archived.' );

	$administrator = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	newsletter_lifecycle_runtime_assert( ! empty( $administrator ), 'No administrator is available for the lifecycle UI test.' );
	wp_set_current_user( (int) $administrator[0] );
	$_GET['edit'] = $campaign_duplicate_id;
	ob_start();
	newsletter_campaign_kit_render_campaigns_page();
	$campaign_markup = ob_get_clean();
	unset( $_GET['edit'] );
	$_GET['segment_edit'] = $segment_id;
	ob_start();
	newsletter_campaign_kit_render_segments_page();
	$segment_markup = ob_get_clean();
	unset( $_GET['segment_edit'] );
	newsletter_lifecycle_runtime_assert( false !== strpos( $campaign_markup, 'newsletter_campaign_kit_update_campaign' ) && false !== strpos( $campaign_markup, 'newsletter_campaign_kit_duplicate_campaign' ), 'Campaign admin UI is missing lifecycle actions.' );
	newsletter_lifecycle_runtime_assert( false !== strpos( $segment_markup, 'newsletter_campaign_kit_update_segment' ) && false !== strpos( $segment_markup, 'newsletter_campaign_kit_duplicate_segment' ) && false !== strpos( $segment_markup, 'newsletter_campaign_kit_segment_status' ), 'Segment admin UI is missing lifecycle actions.' );

	foreach ( $campaign_ids as $runtime_campaign_id ) {
		$wpdb->update( $campaigns_table, array( 'status' => 'cancelled' ), array( 'id' => $runtime_campaign_id ), array( '%s' ), array( '%d' ) );
	}
	newsletter_lifecycle_runtime_assert( true === newsletter_campaign_kit_set_segment_status( $segment_id, 'archived' ), 'Unused segment archive failed.' );
	newsletter_lifecycle_runtime_assert( null === newsletter_campaign_kit_get_segment( $segment_id ) && 0 === newsletter_campaign_kit_get_segment_audience_count( $segment_id ), 'Archived segment remained available to audience resolution.' );
	newsletter_lifecycle_runtime_assert( true === newsletter_campaign_kit_set_segment_status( $segment_id, 'active' ), 'Segment restore failed.' );
	newsletter_lifecycle_runtime_assert( 1 === newsletter_campaign_kit_get_segment_audience_count( $segment_id ), 'Restored segment did not recover its audience.' );

	echo wp_json_encode(
		array(
			'campaign_lifecycle' => 'create_update_lock_duplicate',
			'segment_lifecycle'  => 'create_update_duplicate_archive_restore',
			'audience_estimates' => true,
			'archive_guard'      => true,
			'admin_ui'           => true,
		)
	);
} finally {
	wp_set_current_user( $previous_user_id );
	foreach ( array_unique( array_filter( $campaign_ids ) ) as $campaign_id ) {
		$wpdb->delete( $queue_table, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns_table, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $segment_ids ) ) as $segment_id ) {
		$wpdb->delete( $segments_table, array( 'id' => $segment_id ), array( '%d' ) );
	}
	if ( $subscriber_id ) {
		$wpdb->delete( $list_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	if ( $list_id ) {
		$wpdb->delete( $lists_table, array( 'id' => $list_id ), array( '%d' ) );
	}
}
