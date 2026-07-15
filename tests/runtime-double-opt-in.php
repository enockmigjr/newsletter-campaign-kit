<?php
/**
 * WordPress runtime verification for public newsletter double opt-in.
 *
 * Run with: wp eval-file tests/runtime-double-opt-in.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_double_opt_in_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$emails = array(
	'valid'      => 'double-opt-in-valid@photovault.test',
	'expired'    => 'double-opt-in-expired@photovault.test',
	'suppressed' => 'double-opt-in-suppressed@photovault.test',
	'limited'    => 'double-opt-in-limited@photovault.test',
);
$table         = newsletter_campaign_kit_get_subscribers_table();
$queue         = newsletter_campaign_kit_get_queue_table();
$audit         = newsletter_campaign_kit_get_audit_table();
$list_map      = newsletter_campaign_kit_get_subscriber_lists_table();
$topic_map     = newsletter_campaign_kit_get_subscriber_topics_table();
$tag_map       = newsletter_campaign_kit_get_subscriber_tags_table();
$suppressions  = newsletter_campaign_kit_get_suppressions_table();
$old_settings  = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$messages      = array();
$alt_bodies    = array();
$subscriber_ids = array();
$rate_keys     = newsletter_campaign_kit_get_subscription_rate_limit_keys( $emails['limited'] );
$settings      = array(
	'provider'                         => 'wp_mail',
	'from_name'                        => 'PhotoVault',
	'from_email'                       => 'wordpress@photovault.local',
	'double_opt_in_enabled'            => true,
	'confirmation_ttl_hours'           => 1,
	'confirmation_resend_minutes'      => 15,
	'subscription_attempts_per_window' => 2,
	'subscription_window_minutes'      => 15,
	'one_click_enabled'                => false,
	'dkim_confirmed'                   => false,
);

$capture_mail = static function ( $atts ) use ( &$messages ) {
	$messages[] = $atts;

	return $atts;
};
$capture_alt_body = static function ( $phpmailer ) use ( &$alt_bodies ) {
	$alt_bodies[] = $phpmailer->AltBody;
};
add_filter( 'wp_mail', $capture_mail );
add_action( 'phpmailer_init', $capture_alt_body, 20 );

try {
	newsletter_campaign_kit_activate();
	update_option( 'newsletter_campaign_kit_provider_settings', $settings, false );
	foreach ( $rate_keys as $rate_key ) {
		delete_transient( $rate_key );
	}
	foreach ( $emails as $email ) {
		$email_hash = newsletter_campaign_kit_hash_email( $email );
		$old_ids    = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $email_hash ) ) );
		foreach ( $old_ids as $old_id ) {
			$wpdb->delete( $queue, array( 'subscriber_id' => $old_id ), array( '%d' ) );
			$wpdb->delete( $audit, array( 'subscriber_id' => $old_id ), array( '%d' ) );
			$wpdb->delete( $list_map, array( 'subscriber_id' => $old_id ), array( '%d' ) );
			$wpdb->delete( $topic_map, array( 'subscriber_id' => $old_id ), array( '%d' ) );
			$wpdb->delete( $tag_map, array( 'subscriber_id' => $old_id ), array( '%d' ) );
			$wpdb->delete( $table, array( 'id' => $old_id ), array( '%d' ) );
		}
		$wpdb->delete( $suppressions, array( 'email_hash' => $email_hash ), array( '%s' ) );
	}

	$result = newsletter_campaign_kit_request_subscription_confirmation( $emails['valid'], 'runtime_double_opt_in', 'Runtime double opt-in consent', $settings );
	newsletter_double_opt_in_assert( true === $result && 1 === count( $messages ), 'Initial confirmation request did not send exactly one email.' );
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", newsletter_campaign_kit_hash_email( $emails['valid'] ) ), ARRAY_A );
	$subscriber_ids[] = (int) $subscriber['id'];
	newsletter_double_opt_in_assert( 'pending' === $subscriber['status'] && ! empty( $subscriber['confirmation_token_hash'] ) && ! empty( $subscriber['confirmation_expires_at'] ), 'New public subscriber was not stored as pending.' );
	newsletter_double_opt_in_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$list_map} WHERE subscriber_id = %d", $subscriber['id'] ) ), 'Pending subscriber was assigned to an audience.' );
	newsletter_double_opt_in_assert( false !== strpos( $messages[0]['message'], '<table role="presentation"' ), 'Confirmation email did not use the professional HTML layout.' );
	newsletter_double_opt_in_assert( ! empty( $alt_bodies ) && false !== strpos( end( $alt_bodies ), 'Confirm your email address' ), 'Confirmation email did not include a plain-text alternative.' );
	preg_match( '/[?&](?:amp;)?token=([a-f0-9]{64})/', html_entity_decode( $messages[0]['message'] ), $token_match );
	$token = $token_match[1] ?? '';
	newsletter_double_opt_in_assert( 64 === strlen( $token ) && $token !== $subscriber['confirmation_token_hash'], 'Raw confirmation token was missing or stored directly.' );
	newsletter_double_opt_in_assert( newsletter_campaign_kit_hash_confirmation_token( $token ) === $subscriber['confirmation_token_hash'], 'Stored confirmation HMAC does not match the emailed token.' );

	$result = newsletter_campaign_kit_request_subscription_confirmation( $emails['valid'], 'runtime_double_opt_in', 'Runtime repeat consent', $settings );
	$after_cooldown_request = $wpdb->get_row( $wpdb->prepare( "SELECT confirmation_token_hash FROM {$table} WHERE id = %d", $subscriber['id'] ), ARRAY_A );
	newsletter_double_opt_in_assert( true === $result && 1 === count( $messages ) && $subscriber['confirmation_token_hash'] === $after_cooldown_request['confirmation_token_hash'], 'Resend cooldown sent or rotated a confirmation token.' );

	newsletter_double_opt_in_assert( true === newsletter_campaign_kit_confirm_subscription( $token ), 'Valid confirmation token was rejected.' );
	$confirmed = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $subscriber['id'] ), ARRAY_A );
	newsletter_double_opt_in_assert( 'subscribed' === $confirmed['status'] && null === $confirmed['confirmation_token_hash'] && ! empty( $confirmed['confirmed_at'] ), 'Confirmation did not activate and clear the pending capability.' );
	newsletter_double_opt_in_assert( is_wp_error( newsletter_campaign_kit_confirm_subscription( $token ) ), 'Confirmation token replay was accepted.' );

	$result = newsletter_campaign_kit_request_subscription_confirmation( $emails['expired'], 'runtime_double_opt_in', 'Runtime expiry consent', $settings );
	$expired = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", newsletter_campaign_kit_hash_email( $emails['expired'] ) ), ARRAY_A );
	$subscriber_ids[] = (int) $expired['id'];
	preg_match( '/[?&](?:amp;)?token=([a-f0-9]{64})/', html_entity_decode( $messages[1]['message'] ), $expired_match );
	$wpdb->update( $table, array( 'confirmation_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $expired['id'] ), array( '%s' ), array( '%d' ) );
	newsletter_double_opt_in_assert( is_wp_error( newsletter_campaign_kit_confirm_subscription( $expired_match[1] ?? '' ) ), 'Expired confirmation token was accepted.' );
	newsletter_double_opt_in_assert( 'pending' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $expired['id'] ) ), 'Expired confirmation changed subscriber state.' );

	newsletter_double_opt_in_assert( true === newsletter_campaign_kit_subscribe_email( $emails['suppressed'], 'runtime_double_opt_in', 'Runtime suppression fixture' ), 'Suppression fixture could not be created.' );
	$suppressed = newsletter_campaign_kit_get_subscriber_by_email( $emails['suppressed'] );
	$subscriber_ids[] = (int) $suppressed['id'];
	newsletter_double_opt_in_assert( true === newsletter_campaign_kit_set_subscriber_status( $suppressed['id'], 'suppressed', 'runtime_double_opt_in' ), 'Suppression fixture could not be suppressed.' );
	$before_suppressed_request = count( $messages );
	$result = newsletter_campaign_kit_request_subscription_confirmation( $emails['suppressed'], 'runtime_double_opt_in', 'Suppression bypass attempt', $settings );
	newsletter_double_opt_in_assert( is_wp_error( $result ) && 'email_suppressed' === $result->get_error_code() && $before_suppressed_request === count( $messages ), 'Suppressed address received a confirmation email.' );

	newsletter_double_opt_in_assert( newsletter_campaign_kit_public_subscription_rate_limit( $emails['limited'], $settings ), 'Rate limiter rejected the first attempt.' );
	newsletter_double_opt_in_assert( newsletter_campaign_kit_public_subscription_rate_limit( $emails['limited'], $settings ), 'Rate limiter rejected the allowed second attempt.' );
	newsletter_double_opt_in_assert( ! newsletter_campaign_kit_public_subscription_rate_limit( $emails['limited'], $settings ), 'Rate limiter accepted an attempt above the configured limit.' );

	echo wp_json_encode(
		array(
			'pending_isolation' => true,
			'token_storage'     => 'hmac_only',
			'email'             => 'professional_multipart',
			'cooldown'          => 'no_resend_or_rotation',
			'confirmation'      => 'atomic_single_use',
			'expiration'        => 'fail_closed',
			'suppression'       => 'no_bypass',
			'rate_limit'        => 'ip_and_email_bounded',
		)
	);
} finally {
	remove_filter( 'wp_mail', $capture_mail );
	remove_action( 'phpmailer_init', $capture_alt_body, 20 );
	update_option( 'newsletter_campaign_kit_provider_settings', $old_settings, false );
	foreach ( $rate_keys as $rate_key ) {
		delete_transient( $rate_key );
	}
	foreach ( array_unique( array_filter( $subscriber_ids ) ) as $subscriber_id ) {
		$wpdb->delete( $queue, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $list_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $topic_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	foreach ( $emails as $email ) {
		$wpdb->delete( $suppressions, array( 'email_hash' => newsletter_campaign_kit_hash_email( $email ) ), array( '%s' ) );
	}
}
