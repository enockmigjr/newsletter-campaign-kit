<?php
/**
 * WordPress runtime verification for preferences, suppressions and privacy.
 *
 * Run with: wp eval-file tests/runtime-preferences.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_preferences_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$email              = 'preferences-runtime@photovault.test';
$email_hash         = newsletter_campaign_kit_hash_email( $email );
$subscribers_table  = newsletter_campaign_kit_get_subscribers_table();
$topics_table       = newsletter_campaign_kit_get_topics_table();
$preferences_table  = newsletter_campaign_kit_get_subscriber_topics_table();
$suppressions_table = newsletter_campaign_kit_get_suppressions_table();
$campaigns_table    = newsletter_campaign_kit_get_campaigns_table();
$queue_table        = newsletter_campaign_kit_get_queue_table();
$audit_table        = newsletter_campaign_kit_get_audit_table();
$list_map_table     = newsletter_campaign_kit_get_subscriber_lists_table();
$lists_table        = newsletter_campaign_kit_get_lists_table();
$tag_map_table      = newsletter_campaign_kit_get_subscriber_tags_table();
$old_provider       = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$subscriber_ids     = array();
$topic_ids          = array();
$campaign_ids       = array();
$list_id            = 0;

try {
	$wpdb->delete( $suppressions_table, array( 'email_hash' => $email_hash ), array( '%s' ) );
	$old_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ) ) );
	foreach ( $old_ids as $old_id ) {
		$wpdb->delete( $queue_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $preferences_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $list_map_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $tag_map_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $old_id ), array( '%d' ) );
	}

	$now = current_time( 'mysql', true );
	$list_name = 'Runtime preference audience ' . wp_generate_password( 5, false, false );
	$wpdb->insert(
		$lists_table,
		array( 'name' => $list_name, 'slug' => sanitize_title( $list_name ), 'description' => 'Isolated runtime audience', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$list_id = (int) $wpdb->insert_id;
	foreach ( array( 'Runtime portraits', 'Runtime exhibitions' ) as $topic_name ) {
		$wpdb->insert(
			$topics_table,
			array( 'name' => $topic_name, 'slug' => sanitize_title( $topic_name ) . '-' . wp_generate_password( 5, false, false ), 'description' => $topic_name . ' preference', 'color' => '#1f6f54', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$topic_ids[] = (int) $wpdb->insert_id;
	}

	$result = newsletter_campaign_kit_subscribe_email( $email, 'runtime_preferences', 'Runtime preference consent' );
	newsletter_preferences_runtime_assert( true === $result, 'Initial preference-test subscription failed.' );
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ), ARRAY_A );
	$subscriber_ids[] = (int) $subscriber['id'];
	newsletter_preferences_runtime_assert( newsletter_campaign_kit_assign_subscriber_to_list( $subscriber['id'], $list_id ), 'The runtime subscriber could not be assigned to its isolated list.' );
	newsletter_preferences_runtime_assert( count( newsletter_campaign_kit_get_subscriber_topic_preferences( $subscriber['id'] ) ) >= 2, 'Active topics were not exposed by the preference service.' );

	$unsubscribe_endpoint = add_query_arg( array( 'action' => 'newsletter_campaign_kit_unsubscribe', 'token' => $subscriber['unsubscribe_token'] ), 'http://nginx/wp-admin/admin-post.php' );
	$response = wp_remote_get( $unsubscribe_endpoint, array( 'redirection' => 0, 'timeout' => 10 ) );
	newsletter_preferences_runtime_assert( ! is_wp_error( $response ) && 302 === wp_remote_retrieve_response_code( $response ), 'A browser GET did not redirect to the preference center.' );
	newsletter_preferences_runtime_assert( 'subscribed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$subscribers_table} WHERE id = %d", $subscriber['id'] ) ), 'A browser GET changed subscription state.' );

	$preferences_endpoint = add_query_arg( array( 'action' => 'newsletter_campaign_kit_preferences', 'token' => $subscriber['unsubscribe_token'] ), 'http://nginx/wp-admin/admin-post.php' );
	$response = wp_remote_get( $preferences_endpoint, array( 'redirection' => 0, 'timeout' => 10 ) );
	$body     = wp_remote_retrieve_body( $response );
	newsletter_preferences_runtime_assert( 200 === wp_remote_retrieve_response_code( $response ) && false !== strpos( $body, 'Runtime portraits' ), 'The public preference center did not render active topics.' );
	preg_match( '/name="_wpnonce" value="([A-Za-z0-9]+)"/', $body, $nonce_matches );
	$preference_nonce = $nonce_matches[1] ?? '';
	newsletter_preferences_runtime_assert( '' !== $preference_nonce, 'The public preference form did not contain a CSRF nonce.' );

	$update_endpoint = 'http://nginx/wp-admin/admin-post.php';
	$response = wp_remote_post(
		$update_endpoint,
		array( 'body' => array( 'action' => 'newsletter_campaign_kit_update_preferences', 'token' => $subscriber['unsubscribe_token'], '_wpnonce' => 'invalid', 'topic_ids' => array( $topic_ids[1] ) ), 'redirection' => 0, 'timeout' => 10 )
	);
	newsletter_preferences_runtime_assert( 403 === wp_remote_retrieve_response_code( $response ), 'The preference endpoint accepted an invalid nonce.' );
	newsletter_preferences_runtime_assert( newsletter_campaign_kit_subscriber_accepts_topic( $subscriber['id'], $topic_ids[0] ), 'Invalid preference request changed topic state.' );

	$response = wp_remote_post(
		$update_endpoint,
		array( 'body' => array( 'action' => 'newsletter_campaign_kit_update_preferences', 'token' => $subscriber['unsubscribe_token'], '_wpnonce' => $preference_nonce, 'topic_ids' => array( $topic_ids[0] ) ), 'redirection' => 0, 'timeout' => 10 )
	);
	newsletter_preferences_runtime_assert( 302 === wp_remote_retrieve_response_code( $response ), 'Valid topic preferences were not accepted by the public endpoint.' );
	newsletter_preferences_runtime_assert( newsletter_campaign_kit_subscriber_accepts_topic( $subscriber['id'], $topic_ids[0] ), 'Selected topic was rejected.' );
	newsletter_preferences_runtime_assert( ! newsletter_campaign_kit_subscriber_accepts_topic( $subscriber['id'], $topic_ids[1] ), 'Deselected topic remained eligible.' );

	foreach ( array( $topic_ids[0], $topic_ids[1], 0 ) as $topic_id ) {
		$title = 'Preference runtime ' . $topic_id . ' ' . wp_generate_password( 5, false, false );
		$wpdb->insert(
			$campaigns_table,
			array( 'title' => $title, 'slug' => sanitize_title( $title ), 'subject' => $title, 'body' => '<p>Preference runtime</p>', 'status' => 'sending', 'target_list_id' => $list_id, 'topic_id' => $topic_id ? $topic_id : null, 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$campaign_ids[] = (int) $wpdb->insert_id;
	}

	$accepted_campaign = newsletter_campaign_kit_get_campaign( $campaign_ids[0] );
	$rejected_campaign = newsletter_campaign_kit_get_campaign( $campaign_ids[1] );
	$neutral_campaign  = newsletter_campaign_kit_get_campaign( $campaign_ids[2] );
	newsletter_preferences_runtime_assert( 1 === count( newsletter_campaign_kit_get_campaign_recipients( $accepted_campaign ) ), 'Selected topic did not include its subscriber.' );
	newsletter_preferences_runtime_assert( 0 === count( newsletter_campaign_kit_get_campaign_recipients( $rejected_campaign ) ), 'Deselected topic remained in the resolved audience.' );
	newsletter_preferences_runtime_assert( 1 === count( newsletter_campaign_kit_get_campaign_recipients( $neutral_campaign ) ), 'A topic-neutral campaign was incorrectly filtered.' );

	$wpdb->insert(
		$queue_table,
		array( 'campaign_id' => $campaign_ids[1], 'subscriber_id' => $subscriber['id'], 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now ),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
	);
	$queue_id = (int) $wpdb->insert_id;
	newsletter_campaign_kit_process_queue_batch( 10 );
	$queue_row = $wpdb->get_row( $wpdb->prepare( "SELECT status, last_error FROM {$queue_table} WHERE id = %d", $queue_id ), ARRAY_A );
	newsletter_preferences_runtime_assert( 'cancelled' === $queue_row['status'] && 'topic_opt_out' === $queue_row['last_error'], 'Final delivery control did not cancel a thematic opt-out.' );

	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'wp_mail', 'from_name' => 'PhotoVault', 'from_email' => 'wordpress@photovault.local' ), false );
	$send = newsletter_campaign_kit_send_with_wp_mail( false, $accepted_campaign, $subscriber, array() );
	newsletter_preferences_runtime_assert( true === $send, 'An eligible thematic campaign was not handed to wp_mail.' );
	$send = newsletter_campaign_kit_send_with_wp_mail( false, $rejected_campaign, $subscriber, array() );
	newsletter_preferences_runtime_assert( is_wp_error( $send ) && 'newsletter_recipient_ineligible' === $send->get_error_code(), 'The provider accepted a thematic opt-out.' );

	$result = newsletter_campaign_kit_set_subscriber_status( $subscriber['id'], 'suppressed', 'runtime_test' );
	newsletter_preferences_runtime_assert( true === $result && newsletter_campaign_kit_is_email_hash_suppressed( $email_hash ), 'Durable suppression was not created.' );
	$suppression_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$suppressions_table} WHERE email_hash = %s", $email_hash ) );
	newsletter_preferences_runtime_assert( 0 === count( newsletter_campaign_kit_get_campaign_recipients( $neutral_campaign ) ), 'Suppressed address remained in the resolved audience.' );

	$export = newsletter_campaign_kit_privacy_exporter( $email, 1 );
	newsletter_preferences_runtime_assert( ! empty( $export['data'] ) && true === $export['done'], 'WordPress privacy export did not include newsletter data.' );
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	newsletter_preferences_runtime_assert( isset( $exporters['newsletter-campaign-kit'], $erasers['newsletter-campaign-kit'] ), 'Newsletter privacy callbacks were not registered with WordPress.' );
	$erased = newsletter_campaign_kit_privacy_eraser( $email, 1 );
	newsletter_preferences_runtime_assert( true === $erased['items_removed'] && true === $erased['items_retained'], 'Privacy erasure did not remove the contact while retaining active suppression proof.' );
	newsletter_preferences_runtime_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ) ), 'Privacy erasure retained the subscriber record.' );
	newsletter_preferences_runtime_assert( newsletter_campaign_kit_is_email_hash_suppressed( $email_hash ), 'Privacy erasure removed the active suppression proof.' );
	$result = newsletter_campaign_kit_subscribe_email( $email, 'runtime_preferences', 'Suppression bypass attempt' );
	newsletter_preferences_runtime_assert( is_wp_error( $result ) && 'email_suppressed' === $result->get_error_code(), 'A deleted but suppressed contact was recreated.' );

	newsletter_preferences_runtime_assert( true === newsletter_campaign_kit_release_suppression( $suppression_id ), 'Suppression release failed.' );
	newsletter_preferences_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_preferences', 'Fresh consent after release' ), 'Fresh consent was rejected after explicit suppression release.' );
	$new_subscriber_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ) );
	$subscriber_ids[]  = $new_subscriber_id;

	echo wp_json_encode(
		array(
			'get_is_non_mutating'    => true,
			'topic_preferences'      => 'audience_and_provider_enforced',
			'suppression_persistence' => 'validated_after_erasure',
			'privacy_export_erase'   => true,
			'wp_mail'                => true,
		)
	);
} finally {
	update_option( 'newsletter_campaign_kit_provider_settings', $old_provider, false );
	foreach ( array_unique( array_filter( $campaign_ids ) ) as $campaign_id ) {
		$wpdb->delete( $queue_table, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns_table, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $subscriber_ids ) ) as $subscriber_id ) {
		$wpdb->delete( $queue_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $preferences_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $list_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $topic_ids ) ) as $topic_id ) {
		$wpdb->delete( $preferences_table, array( 'topic_id' => $topic_id ), array( '%d' ) );
		$wpdb->delete( $topics_table, array( 'id' => $topic_id ), array( '%d' ) );
	}
	if ( $list_id ) {
		$wpdb->delete( $list_map_table, array( 'list_id' => $list_id ), array( '%d' ) );
		$wpdb->delete( $lists_table, array( 'id' => $list_id ), array( '%d' ) );
	}
	$wpdb->delete( $suppressions_table, array( 'email_hash' => $email_hash ), array( '%s' ) );
}
