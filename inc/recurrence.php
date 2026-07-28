<?php
/**
 * Recurring campaign orchestration.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Schedule a reviewed campaign as a recurring master. */
function newsletter_campaign_kit_schedule_confirmed_recurrence( $campaign_id, $first_at, $interval_days, $until, $confirmed_title, $fingerprint, $actor_user_id = 0 ) {
	global $wpdb;

	$campaign_id   = absint( $campaign_id );
	$interval_days = max( 1, min( 365, absint( $interval_days ) ) );
	$first         = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', sanitize_text_field( $first_at ), new DateTimeZone( 'UTC' ) );
	$until_date    = DateTimeImmutable::createFromFormat( '!Y-m-d', sanitize_text_field( $until ), new DateTimeZone( 'UTC' ) );
	$errors        = DateTimeImmutable::getLastErrors();
	if ( ! $first || ! $until_date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $first <= new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) || $until_date < $first->setTime( 0, 0 ) ) {
		return new WP_Error( 'newsletter_invalid_recurrence', __( 'Choose a valid first delivery, interval, and end date.', 'newsletter-campaign-kit' ) );
	}

	$table    = newsletter_campaign_kit_get_campaigns_table();
	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	if ( ! $campaign || ! in_array( $campaign['status'], array( 'ready', 'scheduled' ), true ) ) {
		return new WP_Error( 'newsletter_campaign_recurrence_invalid', __( 'This campaign cannot become recurring from its current state.', 'newsletter-campaign-kit' ) );
	}
	$confirmation = newsletter_campaign_kit_validate_campaign_delivery_confirmation( $campaign, $confirmed_title, $fingerprint );
	if ( is_wp_error( $confirmation ) ) {
		return $confirmation;
	}

	$updated = $wpdb->update(
		$table,
		array(
			'status'                   => 'recurring',
			'scheduled_at'             => null,
			'recurrence_interval_days' => $interval_days,
			'recurrence_until'         => $until_date->format( 'Y-m-d' ),
			'next_occurrence_at'       => $first->format( 'Y-m-d H:i:s' ),
			'updated_by'               => absint( $actor_user_id ),
			'updated_at'               => current_time( 'mysql', true ),
		),
		array( 'id' => $campaign_id, 'status' => $campaign['status'] )
	);

	return false === $updated ? new WP_Error( 'newsletter_campaign_recurrence_failed', __( 'The recurring campaign could not be scheduled.', 'newsletter-campaign-kit' ) ) : true;
}

/** Create an immutable sending occurrence from one recurring master. */
function newsletter_campaign_kit_create_recurrence_occurrence( $master ) {
	global $wpdb;

	$table      = newsletter_campaign_kit_get_campaigns_table();
	$master_id  = absint( $master['id'] ?? 0 );
	$occurrence = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(occurrence_number) FROM {$table} WHERE parent_campaign_id = %d", $master_id ) );
	$now        = current_time( 'mysql', true );
	$title      = sprintf( __( '%1$s - occurrence %2$d', 'newsletter-campaign-kit' ), $master['title'], $occurrence );
	$data       = array(
		'title'             => substr( sanitize_text_field( $title ), 0, 190 ),
		'slug'              => newsletter_campaign_kit_generate_unique_slug( $table, $title ),
		'subject'           => $master['subject'],
		'preview_text'      => $master['preview_text'],
		'body'              => $master['body'],
		'text_body'         => $master['text_body'],
		'template_id'       => $master['template_id'],
		'status'            => 'sending',
		'target_list_id'    => $master['target_list_id'],
		'target_segment_id' => $master['target_segment_id'],
		'topic_id'          => $master['topic_id'],
		'source_type'       => $master['source_type'],
		'source_config'     => $master['source_config'],
		'parent_campaign_id' => $master_id,
		'occurrence_number' => $occurrence,
		'created_by'        => $master['created_by'],
		'updated_by'        => $master['updated_by'],
		'created_at'        => $now,
		'updated_at'        => $now,
	);
	if ( false === $wpdb->insert( $table, $data ) ) {
		return new WP_Error( 'newsletter_recurrence_occurrence_failed', __( 'The recurring campaign occurrence could not be created.', 'newsletter-campaign-kit' ) );
	}

	return absint( $wpdb->insert_id );
}

/** Claim due recurring masters and enqueue one occurrence for each. */
function newsletter_campaign_kit_claim_due_recurrences( $limit = 10 ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_campaigns_table();
	$now   = current_time( 'mysql', true );
	$limit = max( 1, min( 50, absint( $limit ) ) );
	$ids   = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'recurring' AND next_occurrence_at IS NOT NULL AND next_occurrence_at <= %s ORDER BY next_occurrence_at ASC, id ASC LIMIT %d",
			$now,
			$limit
		)
	);
	$created = array();

	foreach ( $ids as $campaign_id ) {
		$wpdb->query( 'START TRANSACTION' );
		$master = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'recurring' FOR UPDATE", absint( $campaign_id ) ), ARRAY_A );
		if ( ! $master || empty( $master['next_occurrence_at'] ) || $master['next_occurrence_at'] > $now ) {
			$wpdb->query( 'ROLLBACK' );
			continue;
		}

		$current = new DateTimeImmutable( $master['next_occurrence_at'], new DateTimeZone( 'UTC' ) );
		$until   = new DateTimeImmutable( $master['recurrence_until'] . ' 23:59:59', new DateTimeZone( 'UTC' ) );
		if ( $current > $until ) {
			$wpdb->update( $table, array( 'status' => 'completed', 'next_occurrence_at' => null, 'updated_at' => $now ), array( 'id' => absint( $campaign_id ), 'status' => 'recurring' ) );
			$wpdb->query( 'COMMIT' );
			continue;
		}

		$occurrence_id = newsletter_campaign_kit_create_recurrence_occurrence( $master );
		if ( is_wp_error( $occurrence_id ) ) {
			$wpdb->query( 'ROLLBACK' );
			continue;
		}
		$enqueued = newsletter_campaign_kit_enqueue_campaign( $occurrence_id, false );
		if ( is_wp_error( $enqueued ) ) {
			$wpdb->query( 'ROLLBACK' );
			continue;
		}

		$next        = $current->modify( '+' . max( 1, absint( $master['recurrence_interval_days'] ) ) . ' days' );
		$next_status = $next > $until ? 'completed' : 'recurring';
		$wpdb->update(
			$table,
			array(
				'status'             => $next_status,
				'next_occurrence_at' => 'completed' === $next_status ? null : $next->format( 'Y-m-d H:i:s' ),
				'updated_at'         => $now,
			),
			array( 'id' => absint( $campaign_id ), 'status' => 'recurring' )
		);
		$wpdb->query( 'COMMIT' );
		$created[] = $occurrence_id;
	}

	if ( $created && function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_recurrence_occurrences_created', 'success', 0, array( 'campaign_ids' => $created, 'count' => count( $created ) ) );
	}

	return $created;
}
