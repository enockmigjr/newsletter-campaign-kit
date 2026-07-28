<?php
/**
 * HTTP verification for clean public tracking routes and attribution.
 *
 * Run with: wp eval-file tests/runtime-public-tracking.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_public_tracking_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix            = strtolower( wp_generate_password( 8, false, false ) );
$email             = 'tracking-' . $suffix . '@photovault.test';
$destination       = 'https://example.com/photovault-' . $suffix;
$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
$campaigns_table   = newsletter_campaign_kit_get_campaigns_table();
$queue_table       = newsletter_campaign_kit_get_queue_table();
$events_table      = newsletter_campaign_kit_get_tracking_events_table();
$audit_table       = newsletter_campaign_kit_get_audit_table();
$subscriber_id     = 0;
$campaign_id       = 0;
$queue_id          = 0;
$previous_cookie   = $_COOKIE['newsletter_campaign_attribution'] ?? null;
$request_args      = array(
	'redirection' => 0,
	'timeout'     => 15,
	'headers'     => array( 'Host' => 'localhost:8080' ),
);

try {
	$invalid_open  = wp_remote_get( 'http://nginx/newsletter/open/?t=invalid', $request_args );
	$invalid_click = wp_remote_get( 'http://nginx/newsletter/click/?t=invalid&u=https%3A%2F%2Fexample.com', $request_args );

	newsletter_public_tracking_assert( ! is_wp_error( $invalid_open ), 'Open tracking route was unreachable.' );
	newsletter_public_tracking_assert( 200 === wp_remote_retrieve_response_code( $invalid_open ), 'Open tracking did not return HTTP 200.' );
	newsletter_public_tracking_assert( 'image/gif' === wp_remote_retrieve_header( $invalid_open, 'content-type' ), 'Open tracking did not return a GIF.' );
	newsletter_public_tracking_assert( ! is_wp_error( $invalid_click ), 'Click tracking route was unreachable.' );
	newsletter_public_tracking_assert( 302 === wp_remote_retrieve_response_code( $invalid_click ), 'Invalid click tracking did not redirect safely.' );

	$invalid_location = (string) wp_remote_retrieve_header( $invalid_click, 'location' );
	newsletter_public_tracking_assert( home_url( '/' ) === $invalid_location, 'Invalid click tracking did not return to the public home page.' );
	newsletter_public_tracking_assert( false === strpos( $invalid_location, '/wp-admin/' ), 'Public tracking exposed an admin URL.' );

	newsletter_public_tracking_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_tracking', 'Runtime tracking consent' ), 'Tracking subscriber could not be created.' );
	$subscriber   = newsletter_campaign_kit_get_subscriber_by_email( $email );
	$subscriber_id = absint( $subscriber['id'] ?? 0 );
	$now          = current_time( 'mysql', true );
	$wpdb->insert(
		$campaigns_table,
		array(
			'title'      => 'Runtime tracking ' . $suffix,
			'slug'       => 'runtime-tracking-' . $suffix,
			'subject'    => 'Runtime tracking',
			'body'       => '<p>Runtime tracking</p>',
			'status'     => 'sent',
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$campaign_id = absint( $wpdb->insert_id );
	$wpdb->insert(
		$queue_table,
		array(
			'campaign_id'   => $campaign_id,
			'subscriber_id' => $subscriber_id,
			'status'        => 'sent',
			'attempts'      => 1,
			'sent_at'       => $now,
			'created_at'    => $now,
			'updated_at'    => $now,
		),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
	);
	$queue_id   = absint( $wpdb->insert_id );
	$queue_item = array(
		'id'            => $queue_id,
		'campaign_id'   => $campaign_id,
		'subscriber_id' => $subscriber_id,
	);

	$open_token = newsletter_campaign_kit_create_tracking_token( $queue_item, 'open' );
	$open_url   = str_replace( 'http://localhost:8080', 'http://nginx', newsletter_campaign_kit_get_tracking_url( 'open', $open_token ) );
	$open       = wp_remote_get( $open_url, $request_args );
	newsletter_public_tracking_assert( ! is_wp_error( $open ) && 200 === wp_remote_retrieve_response_code( $open ), 'Valid open tracking failed.' );

	$click_token = newsletter_campaign_kit_create_tracking_token( $queue_item, 'click', $destination );
	$click_url   = str_replace( 'http://localhost:8080', 'http://nginx', newsletter_campaign_kit_get_tracking_url( 'click', $click_token, $destination ) );
	$click       = wp_remote_get( $click_url, $request_args );
	newsletter_public_tracking_assert( ! is_wp_error( $click ) && 302 === wp_remote_retrieve_response_code( $click ), 'Valid click tracking failed.' );
	newsletter_public_tracking_assert( $destination === wp_remote_retrieve_header( $click, 'location' ), 'Valid click did not preserve its signed destination.' );

	$_COOKIE['newsletter_campaign_attribution'] = $click_token;
	newsletter_public_tracking_assert( true === newsletter_campaign_kit_record_current_conversion( 'runtime_booking', 12500 ), 'Signed conversion attribution failed.' );

	$event_types = $wpdb->get_col( $wpdb->prepare( "SELECT event_type FROM {$events_table} WHERE queue_id = %d ORDER BY id ASC", $queue_id ) );
	newsletter_public_tracking_assert( array( 'open', 'click', 'conversion' ) === $event_types, 'The complete tracking funnel was not persisted.' );

	echo wp_json_encode(
		array(
			'open_status'       => 200,
			'open_content_type' => 'image/gif',
			'invalid_click'     => 'public_home_redirect',
			'signed_funnel'     => $event_types,
		)
	);
} finally {
	if ( null === $previous_cookie ) {
		unset( $_COOKIE['newsletter_campaign_attribution'] );
	} else {
		$_COOKIE['newsletter_campaign_attribution'] = $previous_cookie;
	}
	if ( $queue_id ) {
		$wpdb->delete( $events_table, array( 'queue_id' => $queue_id ), array( '%d' ) );
		$wpdb->delete( $queue_table, array( 'id' => $queue_id ), array( '%d' ) );
	}
	if ( $campaign_id ) {
		$wpdb->delete( $campaigns_table, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	if ( $subscriber_id ) {
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
}
