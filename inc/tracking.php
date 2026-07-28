<?php
/**
 * First-party campaign engagement and conversion tracking.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Encode bytes for URL-safe capability tokens. */
function newsletter_campaign_kit_tracking_base64url_encode( $value ) {
	return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
}

/** Decode one URL-safe capability token part. */
function newsletter_campaign_kit_tracking_base64url_decode( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
	$value .= str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );

	return base64_decode( strtr( $value, '-_', '+/' ), true );
}

/** Create a signed, expiring event token for one queue recipient. */
function newsletter_campaign_kit_create_tracking_token( $queue_item, $event_type, $destination_url = '' ) {
	$event_type      = sanitize_key( $event_type );
	$destination_url = esc_url_raw( $destination_url, array( 'http', 'https' ) );
	$payload         = array(
		'q' => absint( $queue_item['id'] ?? 0 ),
		'c' => absint( $queue_item['campaign_id'] ?? 0 ),
		's' => absint( $queue_item['subscriber_id'] ?? 0 ),
		't' => $event_type,
		'e' => time() + ( 90 * DAY_IN_SECONDS ),
		'h' => $destination_url ? hash( 'sha256', $destination_url ) : '',
	);
	if ( ! $payload['q'] || ! $payload['c'] || ! $payload['s'] || ! in_array( $event_type, array( 'open', 'click' ), true ) ) {
		return '';
	}

	$encoded   = newsletter_campaign_kit_tracking_base64url_encode( wp_json_encode( $payload ) );
	$signature = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );

	return $encoded . '.' . $signature;
}

/** Verify a tracking token and its queue ownership. */
function newsletter_campaign_kit_validate_tracking_token( $token, $event_type, $destination_url = '' ) {
	global $wpdb;

	$parts = explode( '.', sanitize_text_field( $token ), 2 );
	if ( 2 !== count( $parts ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/', $parts[1] ) ) {
		return new WP_Error( 'newsletter_tracking_invalid', __( 'The tracking link is invalid.', 'newsletter-campaign-kit' ) );
	}
	$expected = hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected, $parts[1] ) ) {
		return new WP_Error( 'newsletter_tracking_invalid', __( 'The tracking link is invalid.', 'newsletter-campaign-kit' ) );
	}

	$decoded = newsletter_campaign_kit_tracking_base64url_decode( $parts[0] );
	$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
	if ( ! is_array( $payload ) || absint( $payload['e'] ?? 0 ) < time() || sanitize_key( $payload['t'] ?? '' ) !== sanitize_key( $event_type ) ) {
		return new WP_Error( 'newsletter_tracking_expired', __( 'The tracking link has expired.', 'newsletter-campaign-kit' ) );
	}

	$destination_url = esc_url_raw( $destination_url, array( 'http', 'https' ) );
	if ( $destination_url && ! hash_equals( (string) ( $payload['h'] ?? '' ), hash( 'sha256', $destination_url ) ) ) {
		return new WP_Error( 'newsletter_tracking_destination_invalid', __( 'The tracking destination is invalid.', 'newsletter-campaign-kit' ) );
	}

	$queue = newsletter_campaign_kit_get_queue_table();
	$valid = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$queue} WHERE id = %d AND campaign_id = %d AND subscriber_id = %d AND status = 'sent' LIMIT 1",
			absint( $payload['q'] ?? 0 ),
			absint( $payload['c'] ?? 0 ),
			absint( $payload['s'] ?? 0 )
		)
	);
	if ( ! $valid ) {
		return new WP_Error( 'newsletter_tracking_unknown', __( 'The tracking link is invalid.', 'newsletter-campaign-kit' ) );
	}

	return array(
		'queue_id'      => absint( $payload['q'] ),
		'campaign_id'   => absint( $payload['c'] ),
		'subscriber_id' => absint( $payload['s'] ),
	);
}

/** Identify common security scanners and automated preview clients. */
function newsletter_campaign_kit_tracking_request_is_bot() {
	$user_agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
	return (bool) preg_match( '/bot|spider|crawler|scanner|preview|headless|curl|wget|python|facebookexternalhit|googleimageproxy/', $user_agent );
}

/** Store one privacy-limited engagement event. */
function newsletter_campaign_kit_record_tracking_event( $context, $event_type, $destination_url = '', $label = '', $value = null ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_tracking_events_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return new WP_Error( 'newsletter_tracking_storage_unavailable', __( 'Tracking storage is unavailable.', 'newsletter-campaign-kit' ) );
	}
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

	$inserted = $wpdb->insert(
		$table,
		array(
			'campaign_id'     => absint( $context['campaign_id'] ?? 0 ),
			'queue_id'        => absint( $context['queue_id'] ?? 0 ),
			'subscriber_id'   => absint( $context['subscriber_id'] ?? 0 ),
			'event_type'      => sanitize_key( $event_type ),
			'destination_url' => $destination_url ? substr( esc_url_raw( $destination_url, array( 'http', 'https' ) ), 0, 2048 ) : null,
			'destination_hash' => $destination_url ? hash( 'sha256', esc_url_raw( $destination_url, array( 'http', 'https' ) ) ) : null,
			'event_label'     => $label ? substr( sanitize_text_field( $label ), 0, 120 ) : null,
			'event_value'     => is_numeric( $value ) ? round( (float) $value, 2 ) : null,
			'is_bot'          => newsletter_campaign_kit_tracking_request_is_bot() ? 1 : 0,
			'ip_hash'         => $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ) : null,
			'user_agent'      => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 255 ),
			'created_at'      => current_time( 'mysql', true ),
		)
	);

	return false === $inserted ? new WP_Error( 'newsletter_tracking_write_failed', __( 'The tracking event could not be stored.', 'newsletter-campaign-kit' ) ) : true;
}

/** Build a clean event URL. */
function newsletter_campaign_kit_get_tracking_url( $event_type, $token, $destination_url = '' ) {
	$url = home_url( '/newsletter/' . sanitize_key( $event_type ) . '/' );
	$args = array( 't' => $token );
	if ( $destination_url ) {
		$args['u'] = $destination_url;
	}

	return add_query_arg( $args, $url );
}

/** Rewrite campaign links with signed first-party redirects. */
function newsletter_campaign_kit_add_click_tracking( $html, $queue_item ) {
	if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) {
		return $html;
	}

	$document = new DOMDocument( '1.0', 'UTF-8' );
	$previous = libxml_use_internal_errors( true );
	$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?><div id="nck-tracking-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( ! $loaded ) {
		return $html;
	}

	foreach ( $document->getElementsByTagName( 'a' ) as $link ) {
		$destination = trim( $link->getAttribute( 'href' ) );
		if ( '' === $destination || 0 === strpos( $destination, '#' ) || preg_match( '/^(mailto|tel):/i', $destination ) ) {
			continue;
		}
		if ( '/' === substr( $destination, 0, 1 ) ) {
			$destination = home_url( $destination );
		}
		$destination = esc_url_raw( $destination, array( 'http', 'https' ) );
		if ( ! $destination ) {
			continue;
		}
		$token = newsletter_campaign_kit_create_tracking_token( $queue_item, 'click', $destination );
		if ( $token ) {
			$link->setAttribute( 'href', newsletter_campaign_kit_get_tracking_url( 'click', $token, $destination ) );
		}
	}

	$root = $document->getElementById( 'nck-tracking-root' );
	if ( ! $root ) {
		return $html;
	}
	$output = '';
	foreach ( $root->childNodes as $node ) {
		$output .= $document->saveHTML( $node );
	}

	return $output;
}

/** Append signed open and click tracking to one recipient HTML body. */
function newsletter_campaign_kit_apply_campaign_tracking( $html, $queue_item ) {
	$html  = newsletter_campaign_kit_add_click_tracking( $html, $queue_item );
	$token = newsletter_campaign_kit_create_tracking_token( $queue_item, 'open' );
	if ( ! $token ) {
		return $html;
	}
	$pixel = newsletter_campaign_kit_get_tracking_url( 'open', $token );

	return $html . '<img src="' . esc_url( $pixel ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0" />';
}

/** Serve the open pixel without disclosing whether the token was accepted. */
function newsletter_campaign_kit_handle_open_tracking() {
	$token   = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) );
	$context = newsletter_campaign_kit_validate_tracking_token( $token, 'open' );
	if ( ! is_wp_error( $context ) ) {
		newsletter_campaign_kit_record_tracking_event( $context, 'open' );
	}

	status_header( 200 );
	nocache_headers();
	$pixel = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
	header( 'Content-Type: image/gif' );
	header( 'Content-Length: ' . strlen( $pixel ) );
	echo $pixel;
	exit;
}

/** Record a signed click, set attribution, and redirect to the reviewed URL. */
function newsletter_campaign_kit_handle_click_tracking() {
	$token       = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) );
	$destination = esc_url_raw( wp_unslash( $_GET['u'] ?? '' ), array( 'http', 'https' ) );
	$context     = newsletter_campaign_kit_validate_tracking_token( $token, 'click', $destination );
	if ( is_wp_error( $context ) || ! $destination ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	newsletter_campaign_kit_record_tracking_event( $context, 'click', $destination );
	setcookie(
		'newsletter_campaign_attribution',
		$token,
		array(
			'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	wp_redirect( $destination, 302, 'Newsletter Campaign Kit' );
	exit;
}

/** Attribute a first-party business conversion to the most recent newsletter click. */
function newsletter_campaign_kit_record_current_conversion( $label = 'site_conversion', $value = null ) {
	global $wpdb;

	$token = sanitize_text_field( wp_unslash( $_COOKIE['newsletter_campaign_attribution'] ?? '' ) );
	if ( '' === $token ) {
		return false;
	}
	$parts   = explode( '.', $token, 2 );
	$decoded = isset( $parts[0] ) ? newsletter_campaign_kit_tracking_base64url_decode( $parts[0] ) : false;
	$payload = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
	if ( ! is_array( $payload ) || empty( $payload['h'] ) ) {
		return false;
	}
	// The destination is not needed for conversion attribution, but the token signature still is.
	$expected = isset( $parts[1] ) ? hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ) : '';
	if ( ! $expected || ! hash_equals( $expected, (string) $parts[1] ) || absint( $payload['e'] ?? 0 ) < time() ) {
		return false;
	}
	$context = array(
		'queue_id'      => absint( $payload['q'] ?? 0 ),
		'campaign_id'   => absint( $payload['c'] ?? 0 ),
		'subscriber_id' => absint( $payload['s'] ?? 0 ),
	);
	$queue = newsletter_campaign_kit_get_queue_table();
	$valid = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$queue} WHERE id = %d AND campaign_id = %d AND subscriber_id = %d AND status = 'sent' LIMIT 1",
			$context['queue_id'],
			$context['campaign_id'],
			$context['subscriber_id']
		)
	);
	if ( ! $valid ) {
		return false;
	}

	return newsletter_campaign_kit_record_tracking_event( $context, 'conversion', '', $label, $value );
}

/** Public action hook for loosely coupled PhotoVault conversions. */
function newsletter_campaign_kit_capture_conversion_action( $label = 'site_conversion', $value = null ) {
	newsletter_campaign_kit_record_current_conversion( $label, $value );
}
add_action( 'newsletter_campaign_kit_conversion', 'newsletter_campaign_kit_capture_conversion_action', 10, 2 );
