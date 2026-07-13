<?php
/**
 * Subscriber handling for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a safe redirect URL after subscription attempts.
 *
 * @param string $status Result status.
 * @return string
 */
function newsletter_campaign_kit_get_redirect_url( $status ) {
	$referer = wp_get_referer();
	$url     = $referer ? $referer : home_url( '/' );

	return add_query_arg( 'newsletter', sanitize_key( $status ), $url );
}

/**
 * Return a normalized IP hash without storing the raw address.
 *
 * @return string
 */
function newsletter_campaign_kit_get_request_ip_hash() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( empty( $ip ) ) {
		return '';
	}

	return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
}


/**
 * Create an opaque unsubscribe token.
 *
 * @param string $email_hash Email HMAC hash.
 * @return string
 */
function newsletter_campaign_kit_create_unsubscribe_token( $email_hash ) {
	$entropy = wp_generate_password( 64, true, true );

	return hash_hmac( 'sha256', sanitize_text_field( $email_hash ) . '|' . $entropy, wp_salt( 'secure_auth' ) );
}

/**
 * Check the shape of an unsubscribe capability token.
 *
 * @param string $token Unsubscribe token.
 * @return bool
 */
function newsletter_campaign_kit_is_valid_unsubscribe_token( $token ) {
	return 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $token );
}

/**
 * Build a public unsubscribe URL from a token.
 *
 * @param string $token Unsubscribe token.
 * @return string
 */
function newsletter_campaign_kit_get_unsubscribe_url( $token ) {
	return add_query_arg(
		array(
			'action' => 'newsletter_campaign_kit_unsubscribe',
			'token'  => sanitize_text_field( $token ),
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Unsubscribe a recipient through an opaque capability token.
 *
 * The operation is idempotent so mailbox providers may safely retry it.
 *
 * @param string $token Unsubscribe token.
 * @return true|WP_Error
 */
function newsletter_campaign_kit_unsubscribe_by_token( $token ) {
	global $wpdb;

	$token = sanitize_text_field( $token );
	if ( ! newsletter_campaign_kit_is_valid_unsubscribe_token( $token ) ) {
		return new WP_Error( 'newsletter_invalid_unsubscribe_token', __( 'The unsubscribe request is invalid.', 'newsletter-campaign-kit' ) );
	}

	$table_name = newsletter_campaign_kit_get_subscribers_table();
	$subscriber = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, status FROM {$table_name} WHERE unsubscribe_token = %s LIMIT 1",
			$token
		),
		ARRAY_A
	);

	if ( ! $subscriber ) {
		return new WP_Error( 'newsletter_unknown_unsubscribe_token', __( 'The unsubscribe request is invalid.', 'newsletter-campaign-kit' ) );
	}

	if ( 'unsubscribed' === $subscriber['status'] ) {
		return true;
	}

	$updated = $wpdb->update(
		$table_name,
		array(
			'status'     => 'unsubscribed',
			'updated_at' => current_time( 'mysql', true ),
		),
		array(
			'id'                => (int) $subscriber['id'],
			'unsubscribe_token' => $token,
		),
		array( '%s', '%s' ),
		array( '%d', '%s' )
	);

	if ( false === $updated ) {
		return new WP_Error( 'newsletter_unsubscribe_db_error', __( 'The unsubscribe request could not be saved.', 'newsletter-campaign-kit' ) );
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_unsubscribe', 'success', (int) $subscriber['id'] );
	}

	return true;
}

/**
 * Validate the standardized RFC 8058 POST body.
 *
 * @param string $method HTTP method.
 * @param string $value  List-Unsubscribe form value.
 * @return bool
 */
function newsletter_campaign_kit_is_one_click_request( $method, $value ) {
	return 'POST' === strtoupper( (string) $method ) && 'One-Click' === (string) $value;
}

/**
 * Return a neutral response for mailbox-provider one-click requests.
 *
 * @param int $status_code HTTP status code.
 */
function newsletter_campaign_kit_send_one_click_response( $status_code ) {
	status_header( (int) $status_code );
	nocache_headers();
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo esc_html__( 'Unsubscribe request processed.', 'newsletter-campaign-kit' );
	exit;
}

/**
 * Subscribe or reactivate an email address.
 *
 * @param string $email        Email address.
 * @param string $source       Subscription source.
 * @param string $consent_text Consent label shown to the user.
 * @return true|WP_Error
 */
function newsletter_campaign_kit_subscribe_email( $email, $source, $consent_text ) {
	global $wpdb;

	$email = sanitize_email( $email );
	if ( empty( $email ) || ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'newsletter-campaign-kit' ) );
	}

	$table_name = newsletter_campaign_kit_get_subscribers_table();
	$email_hash = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
	$now        = current_time( 'mysql', true );
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, status, unsubscribe_token FROM {$table_name} WHERE email_hash = %s LIMIT 1",
			$email_hash
		),
		ARRAY_A
	);

	if ( $existing && 'suppressed' === $existing['status'] ) {
		return new WP_Error( 'email_suppressed', __( 'This address cannot be subscribed.', 'newsletter-campaign-kit' ) );
	}

	$unsubscribe_token = $existing && 'subscribed' === $existing['status']
		? $existing['unsubscribe_token']
		: newsletter_campaign_kit_create_unsubscribe_token( $email_hash );

	$data = array(
		'email'             => $email,
		'unsubscribe_token' => $unsubscribe_token,
		'status'            => 'subscribed',
		'source'            => sanitize_key( $source ),
		'consent_text'      => sanitize_textarea_field( $consent_text ),
		'ip_hash'           => newsletter_campaign_kit_get_request_ip_hash(),
		'user_agent'        => $user_agent,
		'updated_at'        => $now,
	);

	if ( $existing ) {
		$updated = $wpdb->update(
			$table_name,
			$data,
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_error', __( 'Subscription could not be saved.', 'newsletter-campaign-kit' ) );
		}

		if ( function_exists( 'newsletter_campaign_kit_get_default_list_id' ) ) {
			newsletter_campaign_kit_assign_subscriber_to_list( (int) $existing['id'], newsletter_campaign_kit_get_default_list_id() );
		}

		if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
			$event = 'subscribed' === $existing['status'] ? 'newsletter_subscribe_refreshed' : 'newsletter_subscribe_reactivated';
			newsletter_campaign_kit_log_event( $event, 'success', (int) $existing['id'], array( 'source' => sanitize_key( $source ) ) );
		}

		return true;
	}

	$data['email_hash'] = $email_hash;
	$data['created_at'] = $now;

	$inserted = $wpdb->insert(
		$table_name,
		$data,
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'db_error', __( 'Subscription could not be saved.', 'newsletter-campaign-kit' ) );
	}

	if ( function_exists( 'newsletter_campaign_kit_get_default_list_id' ) ) {
		newsletter_campaign_kit_assign_subscriber_to_list( (int) $wpdb->insert_id, newsletter_campaign_kit_get_default_list_id() );
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_subscribe_created', 'success', (int) $wpdb->insert_id, array( 'source' => sanitize_key( $source ) ) );
	}

	return true;
}

/**
 * Handle public newsletter subscription submissions.
 */
function newsletter_campaign_kit_handle_subscribe() {
	if ( ! isset( $_POST['newsletter_campaign_kit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['newsletter_campaign_kit_nonce'] ) ), 'newsletter_campaign_kit_subscribe' ) ) {
		wp_safe_redirect( newsletter_campaign_kit_get_redirect_url( 'security_failed' ) );
		exit;
	}

	if ( empty( $_POST['newsletter_consent'] ) ) {
		wp_safe_redirect( newsletter_campaign_kit_get_redirect_url( 'consent_required' ) );
		exit;
	}

	$email        = isset( $_POST['newsletter_email'] ) ? sanitize_email( wp_unslash( $_POST['newsletter_email'] ) ) : '';
	$source       = isset( $_POST['newsletter_source'] ) ? sanitize_key( wp_unslash( $_POST['newsletter_source'] ) ) : 'front_footer';
	$consent_text = __( 'I agree to receive PhotoVault editorial updates.', 'newsletter-campaign-kit' );
	$result       = newsletter_campaign_kit_subscribe_email( $email, $source, $consent_text );

	if ( is_wp_error( $result ) ) {
		$error_code = $result->get_error_code();
		if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
			newsletter_campaign_kit_log_event( 'newsletter_subscribe_rejected', 'warning', 0, array( 'reason' => $error_code, 'source' => $source ) );
		}
		// Do not expose suppression-list membership through the public response.
		$public_status = 'email_suppressed' === $error_code ? 'subscribed' : $error_code;
		wp_safe_redirect( newsletter_campaign_kit_get_redirect_url( $public_status ) );
		exit;
	}

	wp_safe_redirect( newsletter_campaign_kit_get_redirect_url( 'subscribed' ) );
	exit;
}
add_action( 'admin_post_nopriv_newsletter_campaign_kit_subscribe', 'newsletter_campaign_kit_handle_subscribe' );
add_action( 'admin_post_newsletter_campaign_kit_subscribe', 'newsletter_campaign_kit_handle_subscribe' );

/**
 * Handle public unsubscribe requests.
 */
function newsletter_campaign_kit_handle_unsubscribe() {
	$method    = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
	$post_value = isset( $_POST['List-Unsubscribe'] ) ? sanitize_text_field( wp_unslash( $_POST['List-Unsubscribe'] ) ) : '';
	$token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

	if ( 'post' === $method ) {
		if ( ! newsletter_campaign_kit_is_one_click_request( $method, $post_value ) || ! newsletter_campaign_kit_is_valid_unsubscribe_token( $token ) ) {
			newsletter_campaign_kit_send_one_click_response( 400 );
		}

		$result = newsletter_campaign_kit_unsubscribe_by_token( $token );
		if ( is_wp_error( $result ) && 'newsletter_unsubscribe_db_error' === $result->get_error_code() ) {
			if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
				newsletter_campaign_kit_log_event( 'newsletter_unsubscribe_failed', 'failure', 0, array( 'reason' => $result->get_error_code() ) );
			}
			newsletter_campaign_kit_send_one_click_response( 503 );
		}

		// Unknown but well-formed tokens receive the same response to avoid enumeration.
		newsletter_campaign_kit_send_one_click_response( 200 );
	}

	if ( 'get' !== $method || ! newsletter_campaign_kit_is_valid_unsubscribe_token( $token ) ) {
		wp_safe_redirect( add_query_arg( 'newsletter', 'unsubscribe_invalid', home_url( '/' ) ) );
		exit;
	}

	$result = newsletter_campaign_kit_unsubscribe_by_token( $token );
	$status = is_wp_error( $result ) ? 'unsubscribe_invalid' : 'unsubscribed';
	wp_safe_redirect( add_query_arg( 'newsletter', $status, home_url( '/' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_newsletter_campaign_kit_unsubscribe', 'newsletter_campaign_kit_handle_unsubscribe' );
add_action( 'admin_post_newsletter_campaign_kit_unsubscribe', 'newsletter_campaign_kit_handle_unsubscribe' );
