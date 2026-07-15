<?php
/**
 * Durable delivery suppression registry.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build the stable HMAC used to match an email without retaining it in the registry. */
function newsletter_campaign_kit_hash_email( $email ) {
	$email = strtolower( sanitize_email( $email ) );

	return is_email( $email ) ? hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ) : '';
}

/** Return whether an email hash is actively suppressed. */
function newsletter_campaign_kit_is_email_hash_suppressed( $email_hash ) {
	global $wpdb;

	$email_hash = sanitize_text_field( $email_hash );
	$table      = newsletter_campaign_kit_get_suppressions_table();
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $email_hash ) || ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	return 'active' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE email_hash = %s LIMIT 1", $email_hash ) );
}

/** Add or reactivate a durable suppression and cancel queued deliveries. */
function newsletter_campaign_kit_suppress_email_hash( $email_hash, $reason = 'manual', $source = 'admin', $subscriber_id = 0 ) {
	global $wpdb;

	$email_hash    = sanitize_text_field( $email_hash );
	$subscriber_id = absint( $subscriber_id );
	$reasons       = apply_filters( 'newsletter_campaign_kit_suppression_reasons', array( 'manual', 'bounce', 'complaint', 'abuse', 'invalid_recipient' ) );
	$reasons       = is_array( $reasons ) ? array_values( array_unique( array_map( 'sanitize_key', $reasons ) ) ) : array( 'manual' );
	$reason        = sanitize_key( $reason );
	$reason        = in_array( $reason, $reasons, true ) ? $reason : 'manual';
	$source        = substr( sanitize_key( $source ), 0, 100 );
	$table         = newsletter_campaign_kit_get_suppressions_table();
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $email_hash ) || ! newsletter_campaign_kit_table_exists( $table ) ) {
		return new WP_Error( 'newsletter_suppression_storage_unavailable', __( 'The suppression could not be stored.', 'newsletter-campaign-kit' ) );
	}

	$now = current_time( 'mysql', true );
	$sql = "INSERT INTO {$table} (email_hash, subscriber_id, status, reason, source, created_at, updated_at, released_at)
		VALUES (%s, NULLIF(%d, 0), 'active', %s, %s, %s, %s, NULL)
		ON DUPLICATE KEY UPDATE subscriber_id = VALUES(subscriber_id), status = 'active', reason = VALUES(reason), source = VALUES(source), updated_at = VALUES(updated_at), released_at = NULL";
	$ok  = $wpdb->query( $wpdb->prepare( $sql, $email_hash, $subscriber_id, $reason, $source, $now, $now ) );
	if ( false === $ok ) {
		return new WP_Error( 'newsletter_suppression_db_error', __( 'The suppression could not be stored.', 'newsletter-campaign-kit' ) );
	}

	if ( $subscriber_id && newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_queue_table() ) ) {
		$queue = newsletter_campaign_kit_get_queue_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue} SET status = 'cancelled', locked_at = NULL, last_error = %s, updated_at = %s WHERE subscriber_id = %d AND status IN ('pending','processing','paused')",
				'address_suppressed',
				$now,
				$subscriber_id
			)
		);
	}
	if ( $subscriber_id && newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscribers_table() ) ) {
		$wpdb->update(
			newsletter_campaign_kit_get_subscribers_table(),
			array( 'status' => 'suppressed', 'updated_at' => $now ),
			array( 'id' => $subscriber_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_suppression_added', 'success', $subscriber_id, array( 'reason' => $reason, 'source' => $source ) );
	}

	return true;
}

/** Release a suppression without silently resubscribing its contact. */
function newsletter_campaign_kit_release_suppression( $suppression_id ) {
	global $wpdb;

	$suppression_id = absint( $suppression_id );
	$table          = newsletter_campaign_kit_get_suppressions_table();
	if ( ! $suppression_id || ! newsletter_campaign_kit_table_exists( $table ) ) {
		return new WP_Error( 'newsletter_suppression_not_found', __( 'The suppression was not found.', 'newsletter-campaign-kit' ) );
	}

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, subscriber_id, status FROM {$table} WHERE id = %d LIMIT 1", $suppression_id ), ARRAY_A );
	if ( ! $row ) {
		return new WP_Error( 'newsletter_suppression_not_found', __( 'The suppression was not found.', 'newsletter-campaign-kit' ) );
	}
	if ( 'released' === $row['status'] ) {
		return true;
	}

	$now     = current_time( 'mysql', true );
	$updated = $wpdb->update(
		$table,
		array( 'status' => 'released', 'updated_at' => $now, 'released_at' => $now ),
		array( 'id' => $suppression_id, 'status' => 'active' ),
		array( '%s', '%s', '%s' ),
		array( '%d', '%s' )
	);
	if ( false === $updated ) {
		return new WP_Error( 'newsletter_suppression_db_error', __( 'The suppression could not be released.', 'newsletter-campaign-kit' ) );
	}

	$subscriber_id = absint( $row['subscriber_id'] );
	if ( $subscriber_id && newsletter_campaign_kit_subscribers_table_exists() ) {
		$wpdb->update(
			newsletter_campaign_kit_get_subscribers_table(),
			array( 'status' => 'unsubscribed', 'updated_at' => $now ),
			array( 'id' => $subscriber_id, 'status' => 'suppressed' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_suppression_released', 'warning', $subscriber_id, array( 'suppression_id' => $suppression_id ) );
	}

	return true;
}

/** Fetch recent suppression entries without exposing hashes in full. */
function newsletter_campaign_kit_get_suppressions( $limit = 50, $offset = 0 ) {
	global $wpdb;

	$table       = newsletter_campaign_kit_get_suppressions_table();
	$subscribers = newsletter_campaign_kit_get_subscribers_table();
	$limit       = max( 1, min( 100, absint( $limit ) ) );
	$offset      = absint( $offset );
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return array();
	}

	$sql = "SELECT sp.id, sp.email_hash, sp.subscriber_id, sp.status, sp.reason, sp.source, sp.created_at, sp.updated_at, sp.released_at, s.email
		FROM {$table} sp LEFT JOIN {$subscribers} s ON s.id = sp.subscriber_id ORDER BY sp.updated_at DESC LIMIT %d OFFSET %d";

	return $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ), ARRAY_A );
}

function newsletter_campaign_kit_count_suppressions() {
	global $wpdb;

	$table = newsletter_campaign_kit_get_suppressions_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return 0;
	}

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

/** Apply a subscriber status transition with suppression and queue invariants. */
function newsletter_campaign_kit_set_subscriber_status( $subscriber_id, $status, $source = 'admin' ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$status        = sanitize_key( $status );
	$table         = newsletter_campaign_kit_get_subscribers_table();
	if ( ! $subscriber_id || ! in_array( $status, array( 'subscribed', 'unsubscribed', 'suppressed' ), true ) || ! newsletter_campaign_kit_table_exists( $table ) ) {
		return new WP_Error( 'newsletter_invalid_status_transition', __( 'The subscriber status is invalid.', 'newsletter-campaign-kit' ) );
	}

	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT id, email_hash, status FROM {$table} WHERE id = %d LIMIT 1", $subscriber_id ), ARRAY_A );
	if ( ! $subscriber ) {
		return new WP_Error( 'newsletter_subscriber_not_found', __( 'The subscriber was not found.', 'newsletter-campaign-kit' ) );
	}
	if ( 'subscribed' === $status && newsletter_campaign_kit_is_email_hash_suppressed( $subscriber['email_hash'] ) ) {
		return new WP_Error( 'newsletter_suppression_active', __( 'Release the active suppression before changing this subscriber.', 'newsletter-campaign-kit' ) );
	}
	if ( 'suppressed' === $status ) {
		$suppressed = newsletter_campaign_kit_suppress_email_hash( $subscriber['email_hash'], 'manual', $source, $subscriber_id );
		if ( is_wp_error( $suppressed ) ) {
			return $suppressed;
		}
	}

	$now  = current_time( 'mysql', true );
	$data = array( 'status' => $status, 'updated_at' => $now );
	$types = array( '%s', '%s' );
	if ( 'subscribed' === $status && 'subscribed' !== $subscriber['status'] ) {
		$data['unsubscribe_token'] = newsletter_campaign_kit_create_unsubscribe_token( $subscriber['email_hash'] );
		$types[]                   = '%s';
	}
	if ( 'pending' !== $status ) {
		$data['confirmation_token_hash'] = null;
		$data['confirmation_expires_at'] = null;
		$data['confirmation_sent_at']    = null;
		$types[]                         = '%s';
		$types[]                         = '%s';
		$types[]                         = '%s';
	}
	if ( 'subscribed' === $status ) {
		$data['confirmed_at'] = $now;
		$types[]               = '%s';
	}
	$updated = $wpdb->update( $table, $data, array( 'id' => $subscriber_id ), $types, array( '%d' ) );
	if ( false === $updated ) {
		return new WP_Error( 'newsletter_status_db_error', __( 'The subscriber status could not be saved.', 'newsletter-campaign-kit' ) );
	}

	if ( 'unsubscribed' === $status && newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_queue_table() ) ) {
		$queue = newsletter_campaign_kit_get_queue_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue} SET status = 'cancelled', locked_at = NULL, last_error = %s, updated_at = %s WHERE subscriber_id = %d AND status IN ('pending','processing','paused')",
				'not_subscribed',
				$now,
				$subscriber_id
			)
		);
	}

	return true;
}

/** Handle an explicit administrator suppression release. */
function newsletter_campaign_kit_handle_release_suppression() {
	if ( ! current_user_can( 'newsletter_manage_subscribers' ) ) {
		wp_die( esc_html__( 'You are not allowed to release suppressions.', 'newsletter-campaign-kit' ) );
	}
	$suppression_id = isset( $_POST['suppression_id'] ) ? absint( $_POST['suppression_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_release_suppression_' . $suppression_id );
	$result = newsletter_campaign_kit_release_suppression( $suppression_id );
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit&suppression=' . ( is_wp_error( $result ) ? 'failed' : 'released' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_release_suppression', 'newsletter_campaign_kit_handle_release_suppression' );
