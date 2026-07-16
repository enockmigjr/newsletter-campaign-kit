<?php
/**
 * WordPress runtime verification for the HTTP provider and signed events.
 *
 * Run with: wp eval-file tests/runtime-http-provider.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_http_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$email             = 'http-provider-runtime@photovault.test';
$email_hash        = newsletter_campaign_kit_hash_email( $email );
$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
$campaigns_table   = newsletter_campaign_kit_get_campaigns_table();
$queue_table       = newsletter_campaign_kit_get_queue_table();
$suppressions      = newsletter_campaign_kit_get_suppressions_table();
$events_table      = newsletter_campaign_kit_get_provider_events_table();
$old_provider      = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$subscriber_id     = 0;
$campaign_id       = 0;
$queue_id          = 0;
$event_ids         = array( 'runtime-event-valid', 'runtime-event-stale' );
$event_keys        = array_map( static fn( $event_id ) => hash_hmac( 'sha256', $event_id, wp_salt( 'auth' ) ), $event_ids );
$runtime_config    = array(
	'endpoint'       => 'https://provider.example.test/v1/send',
	'api_key'        => 'runtime-provider-api-key',
	'webhook_secret' => str_repeat( 'w', 40 ),
	'timeout'        => 8,
);
$http_mode         = 'success';
$captured_request  = array();
$native_secrets    = array(
	'NEWSLETTER_CAMPAIGN_KIT_BREVO_API_KEY'  => 'runtime-brevo-key',
	'NEWSLETTER_CAMPAIGN_KIT_RESEND_API_KEY' => 'runtime-resend-key',
);

$config_filter = static function () use ( &$runtime_config ) {
	return $runtime_config;
};
$http_filter = static function ( $preempt, $args, $url ) use ( &$http_mode, &$captured_request ) {
	$captured_request = array( 'url' => $url, 'args' => $args );
	if ( 'transport_error' === $http_mode ) {
		return new WP_Error( 'runtime_transport_error', 'Simulated provider transport error.' );
	}

	$status = 'rejected' === $http_mode ? 503 : 202;

	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( array( 'provider_message_id' => 'runtime-message' ) ),
		'response' => array( 'code' => $status, 'message' => 'Runtime response' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
$secret_filter = static function ( $value, $name ) use ( &$native_secrets ) {
	return $native_secrets[ $name ] ?? $value;
};

add_filter( 'newsletter_campaign_kit_http_provider_config', $config_filter );
add_filter( 'pre_http_request', $http_filter, 10, 3 );
add_filter( 'newsletter_campaign_kit_delivery_secret', $secret_filter, 10, 2 );

try {
	newsletter_campaign_kit_activate();
	newsletter_http_runtime_assert( newsletter_campaign_kit_table_exists( $events_table ), 'Provider events table was not installed.' );
	$wpdb->delete( $suppressions, array( 'email_hash' => $email_hash ), array( '%s' ) );
	foreach ( $event_keys as $event_key ) {
		$wpdb->delete( $events_table, array( 'event_key' => $event_key ), array( '%s' ) );
	}
	$existing_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ) ) );
	foreach ( $existing_ids as $existing_id ) {
		$wpdb->delete( $queue_table, array( 'subscriber_id' => $existing_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $existing_id ), array( '%d' ) );
	}

	newsletter_http_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_http_provider', 'Runtime provider consent' ), 'Runtime subscriber could not be created.' );
	$subscriber    = newsletter_campaign_kit_get_subscriber_by_email( $email );
	$subscriber_id = absint( $subscriber['id'] );
	$now           = current_time( 'mysql', true );
	$title         = 'HTTP provider runtime ' . wp_generate_password( 5, false, false );
	$wpdb->insert(
		$campaigns_table,
		array( 'title' => $title, 'slug' => sanitize_title( $title ), 'subject' => $title, 'body' => '<p>Runtime HTTP body</p>', 'text_body' => 'Runtime HTTP body', 'status' => 'sending', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$campaign_id = (int) $wpdb->insert_id;
	$campaign    = newsletter_campaign_kit_get_campaign( $campaign_id );
	$wpdb->insert(
		$queue_table,
		array( 'campaign_id' => $campaign_id, 'subscriber_id' => $subscriber_id, 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now ),
		array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
	);
	$queue_id   = (int) $wpdb->insert_id;
	$queue_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $queue_id ), ARRAY_A );
	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'http_api', 'from_name' => 'PhotoVault', 'from_email' => 'wordpress@photovault.local' ), false );

	$sent = newsletter_campaign_kit_send_with_http_api( false, $campaign, $subscriber, $queue_item );
	newsletter_http_runtime_assert( true === $sent, 'HTTP provider did not accept a successful 2xx response.' );
	$body = json_decode( $captured_request['args']['body'], true );
	$key  = newsletter_campaign_kit_get_delivery_idempotency_key( $campaign, $subscriber, $queue_item );
	newsletter_http_runtime_assert( $runtime_config['endpoint'] === $captured_request['url'], 'HTTP request used an unexpected endpoint.' );
	newsletter_http_runtime_assert( $key === $captured_request['args']['headers']['Idempotency-Key'] && $key === $body['message_id'], 'Delivery idempotency key was not stable across headers and body.' );
	newsletter_http_runtime_assert( $email === $body['recipient']['email'] && false !== strpos( $body['text'], 'Runtime HTTP body' ) && false !== strpos( $body['text'], 'Manage preferences' ), 'Provider payload omitted the recipient or text alternative.' );
	newsletter_http_runtime_assert( 0 === strpos( $captured_request['args']['headers']['Authorization'], 'Bearer ' ), 'Provider authorization header is missing.' );
	$test_sent = newsletter_campaign_kit_send_provider_test( 'provider-test-http@photovault.test' );
	$test_body = json_decode( $captured_request['args']['body'], true );
	newsletter_http_runtime_assert( true === $test_sent && ! empty( $test_body['diagnostic'] ) && 'provider-test-http@photovault.test' === $test_body['recipient']['email'], 'HTTP provider diagnostic did not use the isolated test contract.' );

	$http_mode = 'rejected';
	$sent      = newsletter_campaign_kit_send_with_http_api( false, $campaign, $subscriber, $queue_item );
	newsletter_http_runtime_assert( is_wp_error( $sent ) && 'newsletter_http_provider_rejected' === $sent->get_error_code(), 'Provider rejection did not fail closed.' );
	$http_mode = 'transport_error';
	$sent      = newsletter_campaign_kit_send_with_http_api( false, $campaign, $subscriber, $queue_item );
	newsletter_http_runtime_assert( is_wp_error( $sent ) && 'newsletter_http_provider_unavailable' === $sent->get_error_code(), 'Provider transport failure was not normalized.' );
	$runtime_config['api_key'] = '';
	$sent = newsletter_campaign_kit_send_with_http_api( false, $campaign, $subscriber, $queue_item );
	newsletter_http_runtime_assert( is_wp_error( $sent ) && 'newsletter_http_provider_not_configured' === $sent->get_error_code(), 'Missing provider secret did not fail closed.' );
	$runtime_config['api_key'] = 'runtime-provider-api-key';

	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'brevo', 'from_name' => 'PhotoVault', 'from_email' => 'sender@photovault.test' ), false );
	$http_mode = 'success';
	$sent      = newsletter_campaign_kit_send_with_brevo( false, $campaign, $subscriber, $queue_item );
	$body      = json_decode( $captured_request['args']['body'], true );
	newsletter_http_runtime_assert( true === $sent && 'https://api.brevo.com/v3/smtp/email' === $captured_request['url'], 'Brevo delivery did not use its fixed endpoint.' );
	newsletter_http_runtime_assert( 'runtime-brevo-key' === $captured_request['args']['headers']['api-key'] && $email === $body['to'][0]['email'], 'Brevo payload or authentication is invalid.' );
	newsletter_http_runtime_assert( false !== strpos( $body['htmlContent'], '<!doctype html>' ) && false !== strpos( $body['textContent'], 'Runtime HTTP body' ), 'Brevo payload omitted HTML or text content.' );
	$test_sent = newsletter_campaign_kit_send_provider_test( 'provider-test-brevo@photovault.test' );
	$test_body = json_decode( $captured_request['args']['body'], true );
	newsletter_http_runtime_assert( true === $test_sent && 'provider-test-brevo@photovault.test' === $test_body['to'][0]['email'] && in_array( 'provider-test', $test_body['tags'], true ), 'Brevo provider diagnostic did not use the branded test payload.' );

	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'resend', 'from_name' => 'PhotoVault', 'from_email' => 'sender@photovault.test' ), false );
	$sent = newsletter_campaign_kit_send_with_resend( false, $campaign, $subscriber, $queue_item );
	$body = json_decode( $captured_request['args']['body'], true );
	newsletter_http_runtime_assert( true === $sent && 'https://api.resend.com/emails' === $captured_request['url'], 'Resend delivery did not use its fixed endpoint.' );
	newsletter_http_runtime_assert( 'Bearer runtime-resend-key' === $captured_request['args']['headers']['Authorization'] && $email === $body['to'][0], 'Resend payload or authentication is invalid.' );
	newsletter_http_runtime_assert( newsletter_campaign_kit_get_delivery_idempotency_key( $campaign, $subscriber, $queue_item ) === $captured_request['args']['headers']['Idempotency-Key'], 'Native provider idempotency is not stable.' );
	$test_sent = newsletter_campaign_kit_send_provider_test( 'provider-test-resend@photovault.test' );
	$test_body = json_decode( $captured_request['args']['body'], true );
	newsletter_http_runtime_assert( true === $test_sent && 'provider-test-resend@photovault.test' === $test_body['to'][0] && 'provider_test' === $test_body['tags'][0]['value'], 'Resend provider diagnostic did not use the branded test payload.' );
	$native_secrets['NEWSLETTER_CAMPAIGN_KIT_RESEND_API_KEY'] = '';
	$sent = newsletter_campaign_kit_send_with_resend( false, $campaign, $subscriber, $queue_item );
	newsletter_http_runtime_assert( is_wp_error( $sent ) && 'newsletter_resend_not_configured' === $sent->get_error_code(), 'Missing Resend secret did not fail closed.' );
	$native_secrets['NEWSLETTER_CAMPAIGN_KIT_RESEND_API_KEY'] = 'runtime-resend-key';
	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'external_filter', 'from_name' => 'PhotoVault', 'from_email' => 'sender@photovault.test' ), false );
	$external_test = static function ( $result, $recipient, $message ) {
		return 'provider-test-external@photovault.test' === $recipient && false !== strpos( $message['html'], 'Delivery is connected' );
	};
	add_filter( 'newsletter_campaign_kit_send_test_email', $external_test, 10, 3 );
	newsletter_http_runtime_assert( true === newsletter_campaign_kit_send_provider_test( 'provider-test-external@photovault.test' ), 'External provider diagnostic filter was not invoked.' );
	remove_filter( 'newsletter_campaign_kit_send_test_email', $external_test, 10 );

	do_action( 'rest_api_init', rest_get_server() );
	newsletter_http_runtime_assert( isset( rest_get_server()->get_routes()['/newsletter-campaign-kit/v1/provider-events'] ), 'Signed provider REST route was not registered.' );
	$valid_body = wp_json_encode( array( 'id' => $event_ids[0], 'type' => 'complaint', 'email' => $email ) );
	$timestamp  = (string) time();
	$request    = new WP_REST_Request( 'POST', '/newsletter-campaign-kit/v1/provider-events' );
	$request->set_body( $valid_body );
	$request->set_header( 'x-newsletter-timestamp', $timestamp );
	$request->set_header( 'x-newsletter-signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $valid_body, $runtime_config['webhook_secret'] ) );

	$invalid = clone $request;
	$invalid->set_header( 'x-newsletter-signature', str_repeat( '0', 64 ) );
	$response = newsletter_campaign_kit_handle_provider_event( $invalid );
	newsletter_http_runtime_assert( is_wp_error( $response ) && 401 === $response->get_error_data()['status'], 'Invalid webhook signature was accepted.' );
	$response = newsletter_campaign_kit_handle_provider_event( $request );
	newsletter_http_runtime_assert( $response instanceof WP_REST_Response && 202 === $response->get_status(), 'Valid complaint webhook was not processed.' );
	newsletter_http_runtime_assert( newsletter_campaign_kit_is_email_hash_suppressed( $email_hash ), 'Complaint webhook did not create a durable suppression.' );
	$queue_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$queue_table} WHERE id = %d", $queue_id ) );
	newsletter_http_runtime_assert( 'cancelled' === $queue_status, 'Complaint webhook did not cancel pending deliveries.' );
	$response = newsletter_campaign_kit_handle_provider_event( $request );
	newsletter_http_runtime_assert( 200 === $response->get_status() && 'duplicate' === $response->get_data()['status'], 'Webhook replay was not idempotent.' );

	$stored_event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events_table} WHERE event_key = %s", $event_keys[0] ), ARRAY_A );
	newsletter_http_runtime_assert( 'processed' === $stored_event['status'] && $subscriber_id === (int) $stored_event['subscriber_id'], 'Provider event registry did not retain its minimal processing proof.' );
	newsletter_http_runtime_assert( ! in_array( 'email', array_keys( $stored_event ), true ), 'Provider event table unexpectedly retains raw email.' );
	$privacy_export = newsletter_campaign_kit_privacy_exporter( $email, 1 );
	$export_values  = wp_json_encode( $privacy_export['data'] );
	newsletter_http_runtime_assert( false !== strpos( $export_values, 'Delivery provider events' ), 'Privacy export omitted linked provider events.' );

	$stale_body = wp_json_encode( array( 'id' => $event_ids[1], 'type' => 'bounce', 'email' => $email ) );
	$stale_time = (string) ( time() - 600 );
	$stale      = new WP_REST_Request( 'POST', '/newsletter-campaign-kit/v1/provider-events' );
	$stale->set_body( $stale_body );
	$stale->set_header( 'x-newsletter-timestamp', $stale_time );
	$stale->set_header( 'x-newsletter-signature', hash_hmac( 'sha256', $stale_time . '.' . $stale_body, $runtime_config['webhook_secret'] ) );
	$response = newsletter_campaign_kit_handle_provider_event( $stale );
	newsletter_http_runtime_assert( is_wp_error( $response ) && 401 === $response->get_error_data()['status'], 'Expired webhook signature was accepted.' );
	$erased = newsletter_campaign_kit_privacy_eraser( $email, 1 );
	newsletter_http_runtime_assert( true === $erased['items_removed'] && true === $erased['items_retained'], 'Privacy erasure did not remove the subscriber and retain minimized proofs.' );
	$stored_subscriber_id = $wpdb->get_var( $wpdb->prepare( "SELECT subscriber_id FROM {$events_table} WHERE event_key = %s", $event_keys[0] ) );
	newsletter_http_runtime_assert( null === $stored_subscriber_id, 'Privacy erasure retained a subscriber link in provider events.' );

	echo wp_json_encode(
		array(
			'http_delivery'       => 'success_and_fail_closed',
			'native_delivery'     => array( 'brevo', 'resend' ),
			'provider_diagnostics' => array( 'http_api', 'brevo', 'resend', 'external_filter' ),
			'idempotency'         => 'stable_delivery_and_webhook_replay',
			'webhook_signature'   => 'hmac_timestamp_validated',
			'suppression'         => 'complaint_applied_and_queue_cancelled',
			'event_minimization'  => 'raw_email_absent_and_privacy_unlinked',
		)
	);
} finally {
	remove_filter( 'newsletter_campaign_kit_http_provider_config', $config_filter );
	remove_filter( 'pre_http_request', $http_filter, 10 );
	remove_filter( 'newsletter_campaign_kit_delivery_secret', $secret_filter, 10 );
	update_option( 'newsletter_campaign_kit_provider_settings', $old_provider, false );
	foreach ( $event_keys as $event_key ) {
		$wpdb->delete( $events_table, array( 'event_key' => $event_key ), array( '%s' ) );
	}
	$wpdb->delete( $suppressions, array( 'email_hash' => $email_hash ), array( '%s' ) );
	if ( $queue_id ) {
		$wpdb->delete( $queue_table, array( 'id' => $queue_id ), array( '%d' ) );
	}
	if ( $campaign_id ) {
		$wpdb->delete( $campaigns_table, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	if ( $subscriber_id ) {
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
}
