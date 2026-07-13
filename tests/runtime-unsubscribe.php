<?php
/**
 * WordPress runtime verification for unsubscribe and suppression behavior.
 *
 * Run with: wp eval-file tests/runtime-unsubscribe.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$email             = 'one-click-runtime@photovault.test';
$email_hash        = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
$campaigns_table   = newsletter_campaign_kit_get_campaigns_table();
$queue_table       = newsletter_campaign_kit_get_queue_table();
$audit_table       = newsletter_campaign_kit_get_audit_table();
$old_settings      = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$subscriber_id     = 0;
$campaign_id       = 0;
$subject           = 'One-click runtime ' . gmdate( 'Ymd-His' );

try {
	$old_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ) );
	if ( $old_id ) {
		$wpdb->delete( $queue_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $old_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $old_id ), array( '%d' ) );
	}

	$result = newsletter_campaign_kit_subscribe_email( $email, 'runtime_test', 'Runtime test consent' );
	newsletter_runtime_assert( true === $result, 'Initial subscription failed.' );
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ), ARRAY_A );
	newsletter_runtime_assert( ! empty( $subscriber['id'] ) && 'subscribed' === $subscriber['status'], 'Subscriber was not persisted.' );
	$subscriber_id = (int) $subscriber['id'];
	$first_token   = $subscriber['unsubscribe_token'];

	$endpoint = add_query_arg(
		array(
			'action' => 'newsletter_campaign_kit_unsubscribe',
			'token'  => $first_token,
		),
		'http://nginx/wp-admin/admin-post.php'
	);
	$response = wp_remote_post(
		$endpoint,
		array(
			'body'        => array( 'List-Unsubscribe' => 'One-Click' ),
			'redirection' => 0,
			'timeout'     => 10,
		)
	);
	newsletter_runtime_assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'RFC 8058 endpoint did not return HTTP 200.' );
	newsletter_runtime_assert( 'unsubscribed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$subscribers_table} WHERE id = %d", $subscriber_id ) ), 'Endpoint did not unsubscribe the recipient.' );

	$response = wp_remote_post( $endpoint, array( 'body' => array( 'List-Unsubscribe' => 'One-Click' ), 'redirection' => 0, 'timeout' => 10 ) );
	newsletter_runtime_assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'Repeated one-click request was not idempotent.' );

	newsletter_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_test', 'Runtime reactivation consent' ), 'Reactivation failed.' );
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$subscribers_table} WHERE id = %d", $subscriber_id ), ARRAY_A );
	newsletter_runtime_assert( 'subscribed' === $subscriber['status'] && $first_token !== $subscriber['unsubscribe_token'], 'Reactivation did not rotate the capability token.' );

	$wpdb->update( $subscribers_table, array( 'status' => 'suppressed' ), array( 'id' => $subscriber_id ), array( '%s' ), array( '%d' ) );
	$result = newsletter_campaign_kit_subscribe_email( $email, 'runtime_test', 'Suppression bypass attempt' );
	newsletter_runtime_assert( is_wp_error( $result ) && 'email_suppressed' === $result->get_error_code(), 'A suppressed recipient was reactivated.' );

	$now = current_time( 'mysql', true );
	$wpdb->insert(
		$campaigns_table,
		array(
			'title'      => $subject,
			'slug'       => sanitize_title( $subject ) . '-' . wp_generate_password( 6, false, false ),
			'subject'    => $subject,
			'body'       => '<p>Runtime verification</p>',
			'status'     => 'sending',
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$campaign_id = (int) $wpdb->insert_id;
	$wpdb->insert(
		$queue_table,
		array(
			'campaign_id'    => $campaign_id,
			'subscriber_id'  => $subscriber_id,
			'status'         => 'pending',
			'attempts'       => 0,
			'next_attempt_at' => $now,
			'created_at'     => $now,
			'updated_at'     => $now,
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
	);
	$queue_id = (int) $wpdb->insert_id;
	newsletter_campaign_kit_process_queue_batch( 10 );
	newsletter_runtime_assert( 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$queue_table} WHERE id = %d", $queue_id ) ), 'Suppression was not enforced immediately before delivery.' );

	$wpdb->update( $subscribers_table, array( 'status' => 'subscribed' ), array( 'id' => $subscriber_id ), array( '%s' ), array( '%d' ) );
	$subscriber['status'] = 'subscribed';
	update_option(
		'newsletter_campaign_kit_provider_settings',
		array(
			'provider'          => 'wp_mail',
			'from_name'         => 'PhotoVault',
			'from_email'        => 'wordpress@photovault.local',
			'one_click_enabled' => true,
			'dkim_confirmed'    => true,
		),
		false
	);
	add_filter(
		'admin_url',
		static function ( $url ) {
			return str_replace( 'http://localhost:8080', 'https://photovault.local', $url );
		}
	);
	$send = newsletter_campaign_kit_send_with_wp_mail(
		false,
		array( 'subject' => $subject, 'body' => '<p>Runtime one-click header verification</p>' ),
		$subscriber,
		array()
	);
	newsletter_runtime_assert( true === $send, 'Runtime message could not be handed to wp_mail.' );

	echo wp_json_encode(
		array(
			'subject'                 => $subject,
			'endpoint_status'         => 200,
			'idempotent'              => true,
			'token_rotated'           => true,
			'suppression_reactivation' => 'blocked',
			'queue_status'            => 'cancelled',
			'wp_mail'                 => true,
		)
	);
} finally {
	update_option( 'newsletter_campaign_kit_provider_settings', $old_settings, false );
	if ( $campaign_id ) {
		$wpdb->delete( $queue_table, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns_table, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	if ( $subscriber_id ) {
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
}
