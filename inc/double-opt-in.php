<?php
/**
 * Public newsletter double opt-in workflow.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_create_confirmation_token() {
	return bin2hex( random_bytes( 32 ) );
}

function newsletter_campaign_kit_hash_confirmation_token( $token ) {
	$token = strtolower( trim( (string) $token ) );

	return 1 === preg_match( '/^[a-f0-9]{64}$/', $token ) ? hash_hmac( 'sha256', $token, wp_salt( 'secure_auth' ) ) : '';
}

function newsletter_campaign_kit_get_confirmation_url( $token ) {
	return add_query_arg(
		array(
			'action' => 'newsletter_campaign_kit_confirm_subscription',
			'token'  => sanitize_text_field( $token ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** Return independent network and address buckets without exposing either value. */
function newsletter_campaign_kit_get_subscription_rate_limit_keys( $email, $ip_hash = '' ) {
	$email   = strtolower( sanitize_email( $email ) );
	$ip_hash = '' !== $ip_hash ? sanitize_text_field( $ip_hash ) : newsletter_campaign_kit_get_request_ip_hash();

	return array(
		'nck_sub_ip_' . hash_hmac( 'sha256', $ip_hash, wp_salt( 'nonce' ) ),
		'nck_sub_email_' . hash_hmac( 'sha256', $email, wp_salt( 'nonce' ) ),
	);
}

/** Bound public attempts independently by network fingerprint and address. */
function newsletter_campaign_kit_public_subscription_rate_limit( $email, $settings = array() ) {
	$settings = is_array( $settings ) ? $settings : newsletter_campaign_kit_get_provider_settings();
	$limit    = max( 1, min( 30, absint( $settings['subscription_attempts_per_window'] ?? 5 ) ) );
	$window   = max( 1, min( 1440, absint( $settings['subscription_window_minutes'] ?? 15 ) ) ) * MINUTE_IN_SECONDS;
	$keys     = newsletter_campaign_kit_get_subscription_rate_limit_keys( $email );
	foreach ( $keys as $key ) {
		if ( absint( get_transient( $key ) ) >= $limit ) {
			return false;
		}
	}
	foreach ( $keys as $key ) {
		set_transient( $key, absint( get_transient( $key ) ) + 1, $window );
	}

	return true;
}

function newsletter_campaign_kit_render_confirmation_email_text( $url, $ttl_hours ) {
	return implode(
		"\n\n",
		array(
			__( 'Confirm your newsletter subscription', 'newsletter-campaign-kit' ),
			__( 'You requested editorial updates from this website.', 'newsletter-campaign-kit' ),
			__( 'Confirm your email address by opening this secure link:', 'newsletter-campaign-kit' ) . "\n" . esc_url_raw( $url ),
			sprintf( __( 'This link expires in %d hours. If you did not request this subscription, ignore this message.', 'newsletter-campaign-kit' ), absint( $ttl_hours ) ),
		)
	);
}

function newsletter_campaign_kit_render_confirmation_email_html( $url, $ttl_hours ) {
	$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	ob_start();
	?><!doctype html><html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"><head><meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e( 'Confirm your newsletter subscription', 'newsletter-campaign-kit' ); ?></title></head><body style="margin:0;padding:0;background:#f3f1ec;color:#20231f;font-family:Arial,sans-serif;"><div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;"><?php esc_html_e( 'One last step before receiving editorial updates.', 'newsletter-campaign-kit' ); ?></div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f1ec;padding:24px 12px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background:#fff;border:1px solid #dedbd4;"><tr><td style="padding:24px 32px;border-bottom:1px solid #e9e6df;font-family:Georgia,serif;font-size:22px;color:#171a17;"><?php echo esc_html( $site_name ); ?></td></tr><tr><td style="padding:40px 32px;"><p style="margin:0 0 12px;color:#1f6f54;font-size:12px;font-weight:bold;text-transform:uppercase;"><?php esc_html_e( 'Editorial newsletter', 'newsletter-campaign-kit' ); ?></p><h1 style="margin:0 0 24px;font-family:Georgia,serif;font-size:32px;line-height:1.2;font-weight:normal;"><?php esc_html_e( 'Confirm your subscription', 'newsletter-campaign-kit' ); ?></h1><p style="margin:0 0 16px;font-size:16px;line-height:1.7;"><?php esc_html_e( 'You requested editorial updates from this website. Confirm that this email address belongs to you before it is added to an audience.', 'newsletter-campaign-kit' ); ?></p><table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;"><tr><td style="background:#1f6f54;"><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:14px 22px;color:#fff;text-decoration:none;font-size:15px;font-weight:bold;"><?php esc_html_e( 'Confirm my subscription', 'newsletter-campaign-kit' ); ?></a></td></tr></table><p style="margin:0 0 20px;font-size:12px;line-height:1.6;color:#656b65;overflow-wrap:anywhere;"><?php echo esc_html( $url ); ?></p><div style="margin-top:28px;padding:16px;border-left:3px solid #1f6f54;background:#f7f7f5;font-size:13px;line-height:1.6;color:#4a4f49;"><?php echo esc_html( sprintf( __( 'This link expires in %d hours. If you did not request this subscription, ignore this message.', 'newsletter-campaign-kit' ), absint( $ttl_hours ) ) ); ?></div></td></tr><tr><td style="padding:20px 32px;border-top:1px solid #e9e6df;font-size:12px;color:#686d68;"><?php echo esc_html( sprintf( __( 'Automated message from %s.', 'newsletter-campaign-kit' ), $site_name ) ); ?></td></tr></table></td></tr></table></body></html><?php

	return (string) ob_get_clean();
}

function newsletter_campaign_kit_send_confirmation_email( $email, $token, $settings ) {
	$email     = sanitize_email( $email );
	$url       = newsletter_campaign_kit_get_confirmation_url( $token );
	$ttl_hours = absint( $settings['confirmation_ttl_hours'] );
	$text      = newsletter_campaign_kit_render_confirmation_email_text( $url, $ttl_hours );
	$html      = newsletter_campaign_kit_render_confirmation_email_html( $url, $ttl_hours );
	$from_name = sanitize_text_field( $settings['from_name'] );
	$from_name = '' !== $from_name ? $from_name : get_bloginfo( 'name' );
	$from_email = is_email( $settings['from_email'] ) ? $settings['from_email'] : get_option( 'admin_email' );
	$set_alt_body = static function ( $phpmailer ) use ( $text ) {
		$phpmailer->AltBody = $text;
	};
	add_action( 'phpmailer_init', $set_alt_body );
	try {
		return wp_mail(
			$email,
			sprintf( __( '[%s] Confirm your newsletter subscription', 'newsletter-campaign-kit' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . $from_email . '>' )
		);
	} finally {
		remove_action( 'phpmailer_init', $set_alt_body );
	}
}

/** Create or refresh a pending public subscription without exposing membership. */
function newsletter_campaign_kit_request_subscription_confirmation( $email, $source, $consent_text, $settings = array() ) {
	global $wpdb;

	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'newsletter-campaign-kit' ) );
	}
	$settings   = wp_parse_args( is_array( $settings ) ? $settings : array(), newsletter_campaign_kit_get_provider_settings() );
	$table      = newsletter_campaign_kit_get_subscribers_table();
	$email_hash = newsletter_campaign_kit_hash_email( $email );
	$existing   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s LIMIT 1", $email_hash ), ARRAY_A );
	if ( newsletter_campaign_kit_is_email_hash_suppressed( $email_hash ) || ( $existing && 'suppressed' === $existing['status'] ) ) {
		return new WP_Error( 'email_suppressed', __( 'This address cannot be subscribed.', 'newsletter-campaign-kit' ) );
	}
	if ( $existing && 'subscribed' === $existing['status'] ) {
		return true;
	}

	$now = current_time( 'mysql', true );
	$cooldown_cutoff = gmdate( 'Y-m-d H:i:s', time() - absint( $settings['confirmation_resend_minutes'] ) * MINUTE_IN_SECONDS );
	if ( $existing && ! empty( $existing['confirmation_sent_at'] ) ) {
		$next_send = strtotime( $existing['confirmation_sent_at'] . ' UTC' ) + absint( $settings['confirmation_resend_minutes'] ) * MINUTE_IN_SECONDS;
		if ( $next_send > time() ) {
			return true;
		}
	}

	$token      = newsletter_campaign_kit_create_confirmation_token();
	$token_hash = newsletter_campaign_kit_hash_confirmation_token( $token );
	$expires_at = gmdate( 'Y-m-d H:i:s', time() + absint( $settings['confirmation_ttl_hours'] ) * HOUR_IN_SECONDS );
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	$data       = array(
		'email'                   => $email,
		'status'                  => 'pending',
		'confirmation_token_hash' => $token_hash,
		'confirmation_expires_at' => $expires_at,
		'confirmation_sent_at'    => $now,
		'confirmed_at'            => null,
		'source'                  => sanitize_key( $source ),
		'consent_text'            => sanitize_textarea_field( $consent_text ),
		'ip_hash'                 => newsletter_campaign_kit_get_request_ip_hash(),
		'user_agent'              => $user_agent,
		'updated_at'              => $now,
	);
	if ( $existing ) {
		$subscriber_id = absint( $existing['id'] );
		$saved         = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET email = %s, status = 'pending', confirmation_token_hash = %s, confirmation_expires_at = %s, confirmation_sent_at = %s, confirmed_at = NULL, source = %s, consent_text = %s, ip_hash = %s, user_agent = %s, updated_at = %s WHERE id = %d AND status IN ('pending','unsubscribed') AND (confirmation_sent_at IS NULL OR confirmation_sent_at <= %s)",
				$email,
				$token_hash,
				$expires_at,
				$now,
				sanitize_key( $source ),
				sanitize_textarea_field( $consent_text ),
				newsletter_campaign_kit_get_request_ip_hash(),
				$user_agent,
				$now,
				$subscriber_id,
				$cooldown_cutoff
			)
		);
		if ( 0 === $saved ) {
			return true;
		}
	} else {
		$data['email_hash']        = $email_hash;
		$data['unsubscribe_token'] = newsletter_campaign_kit_create_unsubscribe_token( $email_hash );
		$data['created_at']        = $now;
		$saved = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$subscriber_id = (int) $wpdb->insert_id;
	}
	if ( false === $saved ) {
		if ( ! $existing && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s LIMIT 1", $email_hash ) ) ) {
			return true;
		}
		return new WP_Error( 'db_error', __( 'Subscription could not be saved.', 'newsletter-campaign-kit' ) );
	}
	if ( ! newsletter_campaign_kit_send_confirmation_email( $email, $token, $settings ) ) {
		$wpdb->update( $table, array( 'confirmation_sent_at' => null ), array( 'id' => $subscriber_id, 'confirmation_token_hash' => $token_hash ), array( '%s' ), array( '%d', '%s' ) );
		return new WP_Error( 'confirmation_email_failed', __( 'The confirmation email could not be sent.', 'newsletter-campaign-kit' ) );
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_confirmation_sent', 'success', $subscriber_id, array( 'source' => sanitize_key( $source ) ) );
	}

	return true;
}

function newsletter_campaign_kit_confirm_subscription( $token ) {
	global $wpdb;

	$token_hash = newsletter_campaign_kit_hash_confirmation_token( $token );
	if ( '' === $token_hash ) {
		return new WP_Error( 'newsletter_confirmation_invalid', __( 'The confirmation link is invalid or expired.', 'newsletter-campaign-kit' ) );
	}
	$table      = newsletter_campaign_kit_get_subscribers_table();
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT id, email_hash, status, confirmation_expires_at FROM {$table} WHERE confirmation_token_hash = %s LIMIT 1", $token_hash ), ARRAY_A );
	if ( ! $subscriber || 'pending' !== $subscriber['status'] || empty( $subscriber['confirmation_expires_at'] ) || strtotime( $subscriber['confirmation_expires_at'] . ' UTC' ) < time() ) {
		return new WP_Error( 'newsletter_confirmation_invalid', __( 'The confirmation link is invalid or expired.', 'newsletter-campaign-kit' ) );
	}

	$now     = current_time( 'mysql', true );
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'subscribed', unsubscribe_token = %s, confirmation_token_hash = NULL, confirmation_expires_at = NULL, confirmation_sent_at = NULL, confirmed_at = %s, updated_at = %s WHERE id = %d AND status = 'pending' AND confirmation_token_hash = %s",
			newsletter_campaign_kit_create_unsubscribe_token( $subscriber['email_hash'] ),
			$now,
			$now,
			absint( $subscriber['id'] ),
			$token_hash
		)
	);
	if ( 1 !== $updated ) {
		return new WP_Error( 'newsletter_confirmation_invalid', __( 'The confirmation link is invalid or expired.', 'newsletter-campaign-kit' ) );
	}
	if ( function_exists( 'newsletter_campaign_kit_get_default_list_id' ) ) {
		newsletter_campaign_kit_assign_subscriber_to_list( absint( $subscriber['id'] ), newsletter_campaign_kit_get_default_list_id() );
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_subscription_confirmed', 'success', absint( $subscriber['id'] ) );
	}

	return true;
}

function newsletter_campaign_kit_handle_subscription_confirmation() {
	$token  = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$result = newsletter_campaign_kit_confirm_subscription( $token );
	wp_safe_redirect( add_query_arg( 'newsletter', is_wp_error( $result ) ? 'confirmation_invalid' : 'confirmed', home_url( '/' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_newsletter_campaign_kit_confirm_subscription', 'newsletter_campaign_kit_handle_subscription_confirmation' );
add_action( 'admin_post_newsletter_campaign_kit_confirm_subscription', 'newsletter_campaign_kit_handle_subscription_confirmation' );
