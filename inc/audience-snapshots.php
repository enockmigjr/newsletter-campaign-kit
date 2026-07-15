<?php
/**
 * Immutable campaign audience snapshots.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return whether both audience snapshot tables are available. */
function newsletter_campaign_kit_audience_snapshot_tables_exist() {
	return newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_audience_snapshots_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_audience_snapshot_members_table() );
}

/** Resolve immutable display metadata for the current campaign audience. */
function newsletter_campaign_kit_prepare_audience_snapshot_metadata( $campaign ) {
	global $wpdb;

	$campaign = is_array( $campaign ) ? $campaign : array();
	$type     = 'all';
	$id       = 0;
	$label    = __( 'All active subscribers', 'newsletter-campaign-kit' );
	$rules    = array( 'match_type' => 'all', 'rules' => array() );

	if ( ! empty( $campaign['target_segment_id'] ) ) {
		$type    = 'segment';
		$id      = absint( $campaign['target_segment_id'] );
		$segment = function_exists( 'newsletter_campaign_kit_get_segment' ) ? newsletter_campaign_kit_get_segment( $id, true ) : null;
		if ( ! $segment ) {
			return new WP_Error( 'newsletter_snapshot_segment_missing', __( 'The target segment is unavailable.', 'newsletter-campaign-kit' ) );
		}
		$label         = sanitize_text_field( $segment['name'] );
		$decoded_rules = json_decode( $segment['rules'], true );
		$rules         = array(
			'match_type' => in_array( $segment['match_type'], array( 'all', 'any' ), true ) ? $segment['match_type'] : 'all',
			'rules'      => is_array( $decoded_rules ) ? $decoded_rules : array(),
		);
	} elseif ( ! empty( $campaign['target_list_id'] ) ) {
		$type = 'list';
		$id   = absint( $campaign['target_list_id'] );
		$list = $wpdb->get_row( $wpdb->prepare( 'SELECT name, slug FROM ' . newsletter_campaign_kit_get_lists_table() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );
		if ( ! $list ) {
			return new WP_Error( 'newsletter_snapshot_list_missing', __( 'The target list is unavailable.', 'newsletter-campaign-kit' ) );
		}
		$label = sanitize_text_field( $list['name'] );
		$rules = array( 'list_slug' => sanitize_title( $list['slug'] ) );
	}

	$topic_id    = ! empty( $campaign['topic_id'] ) ? absint( $campaign['topic_id'] ) : 0;
	$topic_label = '';
	if ( $topic_id ) {
		$topic = $wpdb->get_row( $wpdb->prepare( 'SELECT name, slug FROM ' . newsletter_campaign_kit_get_topics_table() . ' WHERE id = %d LIMIT 1', $topic_id ), ARRAY_A );
		if ( ! $topic ) {
			return new WP_Error( 'newsletter_snapshot_topic_missing', __( 'The campaign topic is unavailable.', 'newsletter-campaign-kit' ) );
		}
		$topic_label    = sanitize_text_field( $topic['name'] );
		$rules['topic'] = sanitize_title( $topic['slug'] );
	}

	return array(
		'audience_type'  => $type,
		'audience_id'    => $id ? $id : null,
		'audience_label' => $label,
		'topic_id'       => $topic_id ? $topic_id : null,
		'topic_label'    => $topic_label,
		'rules'          => wp_json_encode( $rules ),
	);
}

/** Fetch the immutable snapshot metadata for a campaign. */
function newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id ) {
	global $wpdb;

	$campaign_id = absint( $campaign_id );
	if ( ! $campaign_id || ! newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
		return null;
	}

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . newsletter_campaign_kit_get_audience_snapshots_table() . ' WHERE campaign_id = %d LIMIT 1', $campaign_id ), ARRAY_A );
}

/** Return the subscriber IDs captured in a snapshot. */
function newsletter_campaign_kit_get_audience_snapshot_member_ids( $snapshot_id ) {
	global $wpdb;

	$snapshot_id = absint( $snapshot_id );
	if ( ! $snapshot_id || ! newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
		return array();
	}

	return array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT subscriber_id FROM ' . newsletter_campaign_kit_get_audience_snapshot_members_table() . ' WHERE snapshot_id = %d AND subscriber_id IS NOT NULL ORDER BY subscriber_id ASC', $snapshot_id ) ) );
}

/** Create a snapshot once. Callers must hold the campaign transaction lock. */
function newsletter_campaign_kit_create_audience_snapshot( $campaign, $recipients, $actor_user_id = 0 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
		return new WP_Error( 'newsletter_snapshot_storage_unavailable', __( 'Audience snapshot storage is unavailable.', 'newsletter-campaign-kit' ) );
	}
	$campaign_id = absint( $campaign['id'] ?? 0 );
	if ( ! $campaign_id ) {
		return new WP_Error( 'newsletter_snapshot_campaign_invalid', __( 'The campaign is invalid.', 'newsletter-campaign-kit' ) );
	}
	$existing = newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id );
	if ( $existing ) {
		return $existing;
	}
	$metadata = newsletter_campaign_kit_prepare_audience_snapshot_metadata( $campaign );
	if ( is_wp_error( $metadata ) ) {
		return $metadata;
	}
	$recipient_ids = array_values( array_unique( array_filter( array_map( static function ( $recipient ) {
		return absint( is_array( $recipient ) ? ( $recipient['id'] ?? 0 ) : $recipient );
	}, (array) $recipients ) ) ) );
	$now = current_time( 'mysql', true );
	$inserted = $wpdb->insert(
		newsletter_campaign_kit_get_audience_snapshots_table(),
		array_merge(
			$metadata,
			array(
				'campaign_id'     => $campaign_id,
				'recipient_count' => count( $recipient_ids ),
				'created_by'      => absint( $actor_user_id ),
				'created_at'      => $now,
			)
		),
		array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
	);
	if ( false === $inserted ) {
		return new WP_Error( 'newsletter_snapshot_create_failed', __( 'The audience snapshot could not be created.', 'newsletter-campaign-kit' ) );
	}
	$snapshot_id = (int) $wpdb->insert_id;
	foreach ( $recipient_ids as $subscriber_id ) {
		$member_key = hash_hmac( 'sha256', 'snapshot:' . $snapshot_id . ':subscriber:' . $subscriber_id, wp_salt( 'nonce' ) );
		$member_inserted = $wpdb->insert(
			newsletter_campaign_kit_get_audience_snapshot_members_table(),
			array( 'snapshot_id' => $snapshot_id, 'subscriber_id' => $subscriber_id, 'member_key' => $member_key, 'created_at' => $now ),
			array( '%d', '%d', '%s', '%s' )
		);
		if ( false === $member_inserted ) {
			return new WP_Error( 'newsletter_snapshot_member_failed', __( 'An audience snapshot member could not be stored.', 'newsletter-campaign-kit' ) );
		}
	}

	return newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id );
}

/** Describe stored snapshot metadata without recalculating the live audience. */
function newsletter_campaign_kit_describe_audience_snapshot( $snapshot ) {
	if ( ! is_array( $snapshot ) || ( empty( $snapshot['id'] ) && empty( $snapshot['snapshot_id'] ) ) ) {
		return __( 'No snapshot', 'newsletter-campaign-kit' );
	}
	$type_labels = array(
		'all'     => __( 'All', 'newsletter-campaign-kit' ),
		'list'    => __( 'List', 'newsletter-campaign-kit' ),
		'segment' => __( 'Segment', 'newsletter-campaign-kit' ),
	);
	$type        = sanitize_key( $snapshot['audience_type'] );
	$description = sprintf( '%s: %s', $type_labels[ $type ] ?? $type_labels['all'], sanitize_text_field( $snapshot['audience_label'] ) );
	if ( ! empty( $snapshot['topic_label'] ) ) {
		$description .= ' / ' . sprintf( __( 'Topic: %s', 'newsletter-campaign-kit' ), sanitize_text_field( $snapshot['topic_label'] ) );
	}

	return $description;
}
