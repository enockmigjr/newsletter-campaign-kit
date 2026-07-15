<?php
/**
 * HTTP verification for the public double opt-in form and confirmation link.
 *
 * Run with: wp eval-file tests/runtime-double-opt-in-http.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_double_opt_in_http_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function newsletter_double_opt_in_http_mailpit_message( $email ) {
	$response = wp_remote_get( 'http://mailpit:8025/api/v1/messages', array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$payload = json_decode( wp_remote_retrieve_body( $response ), true );
	foreach ( $payload['messages'] ?? array() as $message ) {
		foreach ( $message['To'] ?? array() as $recipient ) {
			if ( $email === ( $recipient['Address'] ?? '' ) ) {
				$detail = wp_remote_get( 'http://mailpit:8025/api/v1/message/' . rawurlencode( $message['ID'] ), array( 'timeout' => 10 ) );

				return is_wp_error( $detail ) ? null : json_decode( wp_remote_retrieve_body( $detail ), true );
			}
		}
	}

	return null;
}

global $wpdb;

$suffix        = strtolower( wp_generate_password( 8, false, false ) );
$email         = 'double-opt-in-http-' . $suffix . '@photovault.test';
$invalid_email = 'double-opt-in-invalid-' . $suffix . '@photovault.test';
$table         = newsletter_campaign_kit_get_subscribers_table();
$queue         = newsletter_campaign_kit_get_queue_table();
$audit         = newsletter_campaign_kit_get_audit_table();
$list_map      = newsletter_campaign_kit_get_subscriber_lists_table();
$topic_map     = newsletter_campaign_kit_get_subscriber_topics_table();
$tag_map       = newsletter_campaign_kit_get_subscriber_tags_table();
$old_settings  = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$subscriber_id = 0;
$rate_keys      = array();
$settings      = array(
	'provider'                         => 'wp_mail',
	'from_name'                        => 'PhotoVault',
	'from_email'                       => 'wordpress@photovault.local',
	'double_opt_in_enabled'            => true,
	'confirmation_ttl_hours'           => 1,
	'confirmation_resend_minutes'      => 15,
	'subscription_attempts_per_window' => 1,
	'subscription_window_minutes'      => 15,
	'one_click_enabled'                => false,
	'dkim_confirmed'                   => false,
);

try {
	update_option( 'newsletter_campaign_kit_provider_settings', $settings, false );
	$endpoint = 'http://nginx/wp-admin/admin-post.php';
	$invalid  = wp_remote_post(
		$endpoint,
		array(
			'redirection' => 0,
			'timeout'     => 15,
			'body'        => array(
				'action'                         => 'newsletter_campaign_kit_subscribe',
				'newsletter_campaign_kit_nonce' => 'invalid',
				'newsletter_email'               => $invalid_email,
				'newsletter_consent'             => '1',
				'newsletter_source'              => 'runtime_http',
			),
		)
	);
	newsletter_double_opt_in_http_assert( 302 === wp_remote_retrieve_response_code( $invalid ) && false !== strpos( wp_remote_retrieve_header( $invalid, 'location' ), 'newsletter=security_failed' ), 'Invalid nonce did not receive the expected neutral redirect.' );
	newsletter_double_opt_in_http_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", newsletter_campaign_kit_hash_email( $invalid_email ) ) ), 'Invalid nonce created a subscriber.' );

	$body = array(
		'action'                         => 'newsletter_campaign_kit_subscribe',
		'newsletter_campaign_kit_nonce' => wp_create_nonce( 'newsletter_campaign_kit_subscribe' ),
		'newsletter_email'               => $email,
		'newsletter_consent'             => '1',
		'newsletter_source'              => 'runtime_http',
	);
	$valid = wp_remote_post( $endpoint, array( 'redirection' => 0, 'timeout' => 15, 'body' => $body ) );
	newsletter_double_opt_in_http_assert( 302 === wp_remote_retrieve_response_code( $valid ) && false !== strpos( wp_remote_retrieve_header( $valid, 'location' ), 'newsletter=confirmation_required' ), 'Valid public request did not return the confirmation-required redirect.' );
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", newsletter_campaign_kit_hash_email( $email ) ), ARRAY_A );
	$subscriber_id = absint( $subscriber['id'] ?? 0 );
	$rate_keys     = newsletter_campaign_kit_get_subscription_rate_limit_keys( $email, $subscriber['ip_hash'] ?? '' );
	newsletter_double_opt_in_http_assert( $subscriber_id && 'pending' === $subscriber['status'], 'Valid public request did not create a pending subscriber.' );
	$first_hash = $subscriber['confirmation_token_hash'];

	$limited = wp_remote_post( $endpoint, array( 'redirection' => 0, 'timeout' => 15, 'body' => $body ) );
	newsletter_double_opt_in_http_assert( 302 === wp_remote_retrieve_response_code( $limited ) && false !== strpos( wp_remote_retrieve_header( $limited, 'location' ), 'newsletter=confirmation_required' ), 'Rate-limited request exposed a distinct public response.' );
	newsletter_double_opt_in_http_assert( $first_hash === $wpdb->get_var( $wpdb->prepare( "SELECT confirmation_token_hash FROM {$table} WHERE id = %d", $subscriber_id ) ), 'Rate-limited request rotated the pending token.' );

	$message = newsletter_double_opt_in_http_mailpit_message( $email );
	newsletter_double_opt_in_http_assert( is_array( $message ) && false !== strpos( $message['HTML'] ?? '', '<table role="presentation"' ) && false !== strpos( $message['Text'] ?? '', 'Confirm your email address' ), 'Mailpit did not receive the professional multipart confirmation.' );
	preg_match( '/href="([^"]+action=newsletter_campaign_kit_confirm_subscription[^"]+)"/', html_entity_decode( $message['HTML'] ), $link_match );
	$confirmation_url = isset( $link_match[1] ) ? str_replace( 'http://localhost:8080', 'http://nginx', $link_match[1] ) : '';
	newsletter_double_opt_in_http_assert( '' !== $confirmation_url, 'Confirmation URL could not be extracted from the delivered email.' );
	$confirmation = wp_remote_get( $confirmation_url, array( 'redirection' => 0, 'timeout' => 15 ) );
	newsletter_double_opt_in_http_assert( 302 === wp_remote_retrieve_response_code( $confirmation ) && false !== strpos( wp_remote_retrieve_header( $confirmation, 'location' ), 'newsletter=confirmed' ), 'Confirmation link did not redirect to the confirmed state.' );
	newsletter_double_opt_in_http_assert( 'subscribed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $subscriber_id ) ), 'HTTP confirmation did not activate the subscriber.' );

	echo wp_json_encode(
		array(
			'nonce_rejection'   => 'no_write',
			'public_response'   => 'neutral_under_rate_limit',
			'mailpit_delivery'  => 'html_and_text',
			'confirmation_link' => 'activates_once',
		)
	);
} finally {
	update_option( 'newsletter_campaign_kit_provider_settings', $old_settings, false );
	foreach ( $rate_keys as $rate_key ) {
		delete_transient( $rate_key );
	}
	if ( $subscriber_id ) {
		$wpdb->delete( $queue, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $list_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $topic_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
}
