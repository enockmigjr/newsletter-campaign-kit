<?php
/**
 * Fixed-endpoint Brevo and Resend delivery adapters.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_get_delivery_secret( $name ) {
	if ( defined( $name ) ) {
		$value = trim( (string) constant( $name ) );
	} else {
		$environment = getenv( $name );
		$value       = false === $environment ? '' : trim( (string) $environment );
	}

	return trim( (string) apply_filters( 'newsletter_campaign_kit_delivery_secret', $value, $name ) );
}

function newsletter_campaign_kit_get_native_provider_status() {
	return array(
		'brevo'  => '' !== newsletter_campaign_kit_get_delivery_secret( 'NEWSLETTER_CAMPAIGN_KIT_BREVO_API_KEY' ),
		'resend' => '' !== newsletter_campaign_kit_get_delivery_secret( 'NEWSLETTER_CAMPAIGN_KIT_RESEND_API_KEY' ),
	);
}

function newsletter_campaign_kit_get_provider_headers_object( $headers ) {
	$result = array();
	foreach ( $headers as $header ) {
		$parts = explode( ':', (string) $header, 2 );
		if ( 2 === count( $parts ) ) {
			$name = trim( $parts[0] );
			if ( preg_match( '/^[A-Za-z0-9-]{1,80}$/', $name ) ) {
				$result[ $name ] = trim( $parts[1] );
			}
		}
	}

	return $result;
}

function newsletter_campaign_kit_validate_native_delivery( $provider, $campaign, $subscriber ) {
	$settings = newsletter_campaign_kit_get_provider_settings();
	if ( $provider !== $settings['provider'] ) {
		return false;
	}
	if ( empty( $subscriber['email'] ) || ! is_email( $subscriber['email'] ) ) {
		return new WP_Error( 'newsletter_invalid_recipient', __( 'Recipient email is invalid.', 'newsletter-campaign-kit' ) );
	}
	$reason = newsletter_campaign_kit_get_recipient_ineligibility_reason( $subscriber, $campaign );
	if ( '' !== $reason ) {
		return new WP_Error( 'newsletter_recipient_ineligible', __( 'The recipient is no longer eligible for this campaign.', 'newsletter-campaign-kit' ), array( 'reason' => $reason ) );
	}
	if ( empty( $campaign['subject'] ) ) {
		return new WP_Error( 'newsletter_missing_subject', __( 'Campaign subject is missing.', 'newsletter-campaign-kit' ) );
	}

	return $settings;
}

function newsletter_campaign_kit_send_native_request( $provider, $url, $headers, $payload ) {
	$response = wp_safe_remote_post(
		$url,
		array(
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => wp_json_encode( $payload ),
			'data_format' => 'body',
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'newsletter_' . $provider . '_unavailable', __( 'The delivery provider could not be reached.', 'newsletter-campaign-kit' ) );
	}
	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error( 'newsletter_' . $provider . '_rejected', sprintf( __( 'The delivery provider returned status %d.', 'newsletter-campaign-kit' ), absint( $status ) ) );
	}

	return true;
}

function newsletter_campaign_kit_send_with_brevo( $current_result, $campaign, $subscriber, $queue_item ) {
	if ( true === $current_result ) {
		return true;
	}
	$settings = newsletter_campaign_kit_validate_native_delivery( 'brevo', $campaign, $subscriber );
	if ( false === $settings || is_wp_error( $settings ) ) {
		return false === $settings ? $current_result : $settings;
	}
	$api_key = newsletter_campaign_kit_get_delivery_secret( 'NEWSLETTER_CAMPAIGN_KIT_BREVO_API_KEY' );
	if ( '' === $api_key ) {
		return new WP_Error( 'newsletter_brevo_not_configured', __( 'Brevo is not configured.', 'newsletter-campaign-kit' ) );
	}
	$idempotency = newsletter_campaign_kit_get_delivery_idempotency_key( $campaign, $subscriber, $queue_item );
	$payload = array(
		'sender'      => array( 'name' => $settings['from_name'], 'email' => $settings['from_email'] ),
		'to'          => array( array( 'email' => $subscriber['email'] ) ),
		'subject'     => sanitize_text_field( $campaign['subject'] ),
		'htmlContent' => newsletter_campaign_kit_render_campaign_body( $campaign, $subscriber ),
		'textContent' => newsletter_campaign_kit_render_campaign_text( $campaign, $subscriber ),
		'headers'     => newsletter_campaign_kit_get_provider_headers_object( newsletter_campaign_kit_get_one_click_headers( $subscriber, $settings ) ),
		'tags'        => array( 'campaign-' . absint( $campaign['id'] ?? 0 ) ),
	);

	return newsletter_campaign_kit_send_native_request(
		'brevo',
		'https://api.brevo.com/v3/smtp/email',
		array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'api-key' => $api_key, 'Idempotency-Key' => $idempotency ),
		$payload
	);
}
add_filter( 'newsletter_campaign_kit_send_email', 'newsletter_campaign_kit_send_with_brevo', 7, 4 );

function newsletter_campaign_kit_send_with_resend( $current_result, $campaign, $subscriber, $queue_item ) {
	if ( true === $current_result ) {
		return true;
	}
	$settings = newsletter_campaign_kit_validate_native_delivery( 'resend', $campaign, $subscriber );
	if ( false === $settings || is_wp_error( $settings ) ) {
		return false === $settings ? $current_result : $settings;
	}
	$api_key = newsletter_campaign_kit_get_delivery_secret( 'NEWSLETTER_CAMPAIGN_KIT_RESEND_API_KEY' );
	if ( '' === $api_key ) {
		return new WP_Error( 'newsletter_resend_not_configured', __( 'Resend is not configured.', 'newsletter-campaign-kit' ) );
	}
	$idempotency = newsletter_campaign_kit_get_delivery_idempotency_key( $campaign, $subscriber, $queue_item );
	$payload = array(
		'from'    => $settings['from_name'] . ' <' . $settings['from_email'] . '>',
		'to'      => array( $subscriber['email'] ),
		'subject' => sanitize_text_field( $campaign['subject'] ),
		'html'    => newsletter_campaign_kit_render_campaign_body( $campaign, $subscriber ),
		'text'    => newsletter_campaign_kit_render_campaign_text( $campaign, $subscriber ),
		'headers' => newsletter_campaign_kit_get_provider_headers_object( newsletter_campaign_kit_get_one_click_headers( $subscriber, $settings ) ),
		'tags'    => array( array( 'name' => 'campaign_id', 'value' => (string) absint( $campaign['id'] ?? 0 ) ) ),
	);

	return newsletter_campaign_kit_send_native_request(
		'resend',
		'https://api.resend.com/emails',
		array( 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key, 'Idempotency-Key' => $idempotency ),
		$payload
	);
}
add_filter( 'newsletter_campaign_kit_send_email', 'newsletter_campaign_kit_send_with_resend', 7, 4 );
