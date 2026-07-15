<?php
/**
 * Generic HTTP delivery adapter and signed provider events.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return server-side HTTP provider configuration without persisting secrets. */
function newsletter_campaign_kit_get_http_provider_config() {
	$config = array(
		'endpoint'       => defined( 'NEWSLETTER_CAMPAIGN_KIT_HTTP_ENDPOINT' ) ? NEWSLETTER_CAMPAIGN_KIT_HTTP_ENDPOINT : '',
		'api_key'        => defined( 'NEWSLETTER_CAMPAIGN_KIT_HTTP_API_KEY' ) ? NEWSLETTER_CAMPAIGN_KIT_HTTP_API_KEY : '',
		'webhook_secret' => defined( 'NEWSLETTER_CAMPAIGN_KIT_WEBHOOK_SECRET' ) ? NEWSLETTER_CAMPAIGN_KIT_WEBHOOK_SECRET : '',
		'timeout'        => defined( 'NEWSLETTER_CAMPAIGN_KIT_HTTP_TIMEOUT' ) ? NEWSLETTER_CAMPAIGN_KIT_HTTP_TIMEOUT : 15,
	);
	$config = apply_filters( 'newsletter_campaign_kit_http_provider_config', $config );
	$config = is_array( $config ) ? $config : array();

	return array(
		'endpoint'       => isset( $config['endpoint'] ) ? esc_url_raw( trim( (string) $config['endpoint'] ) ) : '',
		'api_key'        => isset( $config['api_key'] ) ? trim( (string) $config['api_key'] ) : '',
		'webhook_secret' => isset( $config['webhook_secret'] ) ? trim( (string) $config['webhook_secret'] ) : '',
		'timeout'        => isset( $config['timeout'] ) ? max( 5, min( 30, absint( $config['timeout'] ) ) ) : 15,
	);
}

/** Return non-secret provider readiness flags for administrators. */
function newsletter_campaign_kit_get_http_provider_status() {
	$config   = newsletter_campaign_kit_get_http_provider_config();
	$is_https = 'https' === wp_parse_url( $config['endpoint'], PHP_URL_SCHEME );

	return array(
		'delivery_ready' => $is_https && '' !== $config['api_key'],
		'webhook_ready'  => strlen( $config['webhook_secret'] ) >= 32,
	);
}

/** Build a stable idempotency key for one queue delivery. */
function newsletter_campaign_kit_get_delivery_idempotency_key( $campaign, $subscriber, $queue_item ) {
	$identity = implode(
		':',
		array(
			absint( $campaign['id'] ?? 0 ),
			absint( $subscriber['id'] ?? 0 ),
			absint( $queue_item['id'] ?? 0 ),
		)
	);

	return hash_hmac( 'sha256', $identity, wp_salt( 'nonce' ) );
}

/** Deliver a campaign through the reusable JSON HTTP contract. */
function newsletter_campaign_kit_send_with_http_api( $current_result, $campaign, $subscriber, $queue_item ) {
	if ( true === $current_result ) {
		return true;
	}

	$settings = newsletter_campaign_kit_get_provider_settings();
	if ( 'http_api' !== $settings['provider'] ) {
		return $current_result;
	}
	if ( empty( $subscriber['email'] ) || ! is_email( $subscriber['email'] ) ) {
		return new WP_Error( 'newsletter_invalid_recipient', __( 'Recipient email is invalid.', 'newsletter-campaign-kit' ) );
	}

	$ineligibility_reason = newsletter_campaign_kit_get_recipient_ineligibility_reason( $subscriber, $campaign );
	if ( '' !== $ineligibility_reason ) {
		return new WP_Error( 'newsletter_recipient_ineligible', __( 'The recipient is no longer eligible for this campaign.', 'newsletter-campaign-kit' ), array( 'reason' => $ineligibility_reason ) );
	}

	$config = newsletter_campaign_kit_get_http_provider_config();
	if ( 'https' !== wp_parse_url( $config['endpoint'], PHP_URL_SCHEME ) || '' === $config['api_key'] ) {
		return new WP_Error( 'newsletter_http_provider_not_configured', __( 'The HTTP provider is not securely configured.', 'newsletter-campaign-kit' ) );
	}

	$subject = isset( $campaign['subject'] ) ? sanitize_text_field( $campaign['subject'] ) : '';
	if ( '' === $subject ) {
		return new WP_Error( 'newsletter_missing_subject', __( 'Campaign subject is missing.', 'newsletter-campaign-kit' ) );
	}

	$idempotency_key = newsletter_campaign_kit_get_delivery_idempotency_key( $campaign, $subscriber, $queue_item );
	$payload         = array(
		'message_id' => $idempotency_key,
		'recipient'  => array( 'email' => $subscriber['email'] ),
		'from'       => array( 'name' => $settings['from_name'], 'email' => $settings['from_email'] ),
		'subject'    => $subject,
		'html'       => newsletter_campaign_kit_render_campaign_body( $campaign, $subscriber ),
		'text'       => newsletter_campaign_kit_render_campaign_text( $campaign, $subscriber ),
		'headers'    => newsletter_campaign_kit_get_one_click_headers( $subscriber, $settings ),
	);
	$response        = wp_safe_remote_post(
		$config['endpoint'],
		array(
			'timeout'     => $config['timeout'],
			'redirection' => 0,
			'headers'     => array(
				'Accept'          => 'application/json',
				'Authorization'   => 'Bearer ' . $config['api_key'],
				'Content-Type'    => 'application/json; charset=utf-8',
				'Idempotency-Key' => $idempotency_key,
			),
			'body'        => wp_json_encode( $payload ),
			'data_format' => 'body',
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'newsletter_http_provider_unavailable', __( 'The HTTP provider could not be reached.', 'newsletter-campaign-kit' ) );
	}

	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		return new WP_Error( 'newsletter_http_provider_rejected', sprintf( __( 'The HTTP provider returned status %d.', 'newsletter-campaign-kit' ), absint( $status_code ) ) );
	}

	return true;
}
add_filter( 'newsletter_campaign_kit_send_email', 'newsletter_campaign_kit_send_with_http_api', 9, 4 );

/** Register the public transport endpoint; authenticity is checked in the callback. */
function newsletter_campaign_kit_register_provider_event_route() {
	register_rest_route(
		'newsletter-campaign-kit/v1',
		'/provider-events',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'newsletter_campaign_kit_handle_provider_event',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'newsletter_campaign_kit_register_provider_event_route' );

/** Verify the timestamped HMAC attached to a provider event. */
function newsletter_campaign_kit_verify_provider_event_signature( WP_REST_Request $request, $secret ) {
	$timestamp = $request->get_header( 'x-newsletter-timestamp' );
	$signature = $request->get_header( 'x-newsletter-signature' );
	if ( ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
		return false;
	}
	if ( 0 === strpos( $signature, 'sha256=' ) ) {
		$signature = substr( $signature, 7 );
	}
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/i', $signature ) ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );

	return hash_equals( $expected, strtolower( $signature ) );
}

/** Persist and apply an authenticated bounce or complaint exactly once. */
function newsletter_campaign_kit_handle_provider_event( WP_REST_Request $request ) {
	global $wpdb;

	$config = newsletter_campaign_kit_get_http_provider_config();
	if ( strlen( $config['webhook_secret'] ) < 32 ) {
		return new WP_Error( 'newsletter_webhook_not_configured', __( 'The provider webhook is not configured.', 'newsletter-campaign-kit' ), array( 'status' => 503 ) );
	}
	if ( ! newsletter_campaign_kit_verify_provider_event_signature( $request, $config['webhook_secret'] ) ) {
		return new WP_Error( 'newsletter_invalid_webhook_signature', __( 'The provider event signature is invalid or expired.', 'newsletter-campaign-kit' ), array( 'status' => 401 ) );
	}

	$payload = json_decode( $request->get_body(), true );
	$event_id = is_array( $payload ) && isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';
	$type     = is_array( $payload ) && isset( $payload['type'] ) ? sanitize_key( $payload['type'] ) : '';
	$email    = is_array( $payload ) && isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
	if ( '' === $event_id || strlen( $event_id ) > 190 || ! in_array( $type, array( 'bounce', 'complaint' ), true ) || ! is_email( $email ) ) {
		return new WP_Error( 'newsletter_invalid_provider_event', __( 'The provider event payload is invalid.', 'newsletter-campaign-kit' ), array( 'status' => 400 ) );
	}

	$table     = newsletter_campaign_kit_get_provider_events_table();
	$event_key = hash_hmac( 'sha256', $event_id, wp_salt( 'auth' ) );
	$now       = current_time( 'mysql', true );
	$inserted  = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (event_key, event_type, status, created_at) VALUES (%s, %s, 'received', %s)",
			$event_key,
			$type,
			$now
		)
	);
	if ( false === $inserted ) {
		return new WP_Error( 'newsletter_provider_event_storage_failed', __( 'The provider event could not be stored.', 'newsletter-campaign-kit' ), array( 'status' => 500 ) );
	}
	if ( 0 === $inserted ) {
		$reclaimed = $wpdb->update(
			$table,
			array( 'status' => 'received', 'processed_at' => null ),
			array( 'event_key' => $event_key, 'status' => 'failed' ),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);
		if ( ! $reclaimed ) {
			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}
	}

	$subscriber    = function_exists( 'newsletter_campaign_kit_get_subscriber_by_email' ) ? newsletter_campaign_kit_get_subscriber_by_email( $email ) : null;
	$subscriber_id = is_array( $subscriber ) ? absint( $subscriber['id'] ) : 0;
	$suppressed    = newsletter_campaign_kit_suppress_email_hash( newsletter_campaign_kit_hash_email( $email ), $type, 'http_provider_webhook', $subscriber_id );
	if ( is_wp_error( $suppressed ) ) {
		$wpdb->update( $table, array( 'status' => 'failed', 'processed_at' => $now ), array( 'event_key' => $event_key ), array( '%s', '%s' ), array( '%s' ) );
		return new WP_Error( 'newsletter_provider_event_failed', __( 'The provider event could not be applied.', 'newsletter-campaign-kit' ), array( 'status' => 500 ) );
	}

	$wpdb->update(
		$table,
		array( 'status' => 'processed', 'subscriber_id' => $subscriber_id ? $subscriber_id : null, 'processed_at' => $now ),
		array( 'event_key' => $event_key ),
		array( '%s', '%d', '%s' ),
		array( '%s' )
	);

	return new WP_REST_Response( array( 'status' => 'processed' ), 202 );
}
