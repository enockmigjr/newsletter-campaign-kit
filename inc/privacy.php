<?php
/**
 * WordPress personal-data export and erasure integration.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register Newsletter Campaign Kit with the WordPress personal-data exporter. */
function newsletter_campaign_kit_register_privacy_exporter( $exporters ) {
	$exporters['newsletter-campaign-kit'] = array(
		'exporter_friendly_name' => __( 'Newsletter subscriptions', 'newsletter-campaign-kit' ),
		'callback'               => 'newsletter_campaign_kit_privacy_exporter',
	);

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'newsletter_campaign_kit_register_privacy_exporter' );

/** Export consent, audiences and topic choices without operational secrets. */
function newsletter_campaign_kit_privacy_exporter( $email_address, $page = 1 ) {
	global $wpdb;

	$data = array();
	if ( 1 !== absint( $page ) ) {
		return array( 'data' => $data, 'done' => true );
	}
	$email_hash = newsletter_campaign_kit_hash_email( $email_address );
	if ( ! $email_hash ) {
		return array( 'data' => $data, 'done' => true );
	}

	$subscribers = newsletter_campaign_kit_get_subscribers_table();
	$subscriber  = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, status, source, consent_text, created_at, updated_at FROM {$subscribers} WHERE email_hash = %s LIMIT 1", $email_hash ), ARRAY_A );
	if ( $subscriber ) {
		$lists_table = newsletter_campaign_kit_get_lists_table();
		$list_map    = newsletter_campaign_kit_get_subscriber_lists_table();
		$lists       = $wpdb->get_col( $wpdb->prepare( "SELECT l.name FROM {$lists_table} l INNER JOIN {$list_map} sl ON sl.list_id = l.id WHERE sl.subscriber_id = %d ORDER BY l.name ASC", $subscriber['id'] ) );
		$topics      = newsletter_campaign_kit_get_subscriber_topic_preferences( $subscriber['id'] );
		$topic_data  = array_map( static function ( $topic ) {
			return $topic['name'] . ': ' . $topic['preference_status'];
		}, $topics );
		$data[]      = array(
			'group_id'    => 'newsletter-campaign-kit',
			'group_label' => __( 'Newsletter subscriptions', 'newsletter-campaign-kit' ),
			'item_id'     => 'newsletter-subscriber-' . absint( $subscriber['id'] ),
			'data'        => array(
				array( 'name' => __( 'Email', 'newsletter-campaign-kit' ), 'value' => $subscriber['email'] ),
				array( 'name' => __( 'Status', 'newsletter-campaign-kit' ), 'value' => $subscriber['status'] ),
				array( 'name' => __( 'Source', 'newsletter-campaign-kit' ), 'value' => $subscriber['source'] ),
				array( 'name' => __( 'Consent', 'newsletter-campaign-kit' ), 'value' => $subscriber['consent_text'] ),
				array( 'name' => __( 'Lists', 'newsletter-campaign-kit' ), 'value' => implode( ', ', $lists ) ),
				array( 'name' => __( 'Topic preferences', 'newsletter-campaign-kit' ), 'value' => implode( ', ', $topic_data ) ),
				array( 'name' => __( 'Created', 'newsletter-campaign-kit' ), 'value' => $subscriber['created_at'] ),
				array( 'name' => __( 'Updated', 'newsletter-campaign-kit' ), 'value' => $subscriber['updated_at'] ),
			),
		);
		if ( newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
			$snapshot_members = newsletter_campaign_kit_get_audience_snapshot_members_table();
			$snapshots        = newsletter_campaign_kit_get_audience_snapshots_table();
			$snapshot_count   = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$snapshot_members} sm INNER JOIN {$snapshots} sn ON sn.id = sm.snapshot_id WHERE sm.subscriber_id = %d", $subscriber['id'] ) ) );
			if ( $snapshot_count ) {
				$data[ count( $data ) - 1 ]['data'][] = array( 'name' => __( 'Campaign audience snapshots', 'newsletter-campaign-kit' ), 'value' => $snapshot_count );
			}
		}
		$provider_events = newsletter_campaign_kit_get_provider_events_table();
		if ( newsletter_campaign_kit_table_exists( $provider_events ) ) {
			$provider_event_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$provider_events} WHERE subscriber_id = %d", $subscriber['id'] ) ) );
			if ( $provider_event_count ) {
				$data[ count( $data ) - 1 ]['data'][] = array( 'name' => __( 'Delivery provider events', 'newsletter-campaign-kit' ), 'value' => $provider_event_count );
			}
		}
	}

	$suppressions = newsletter_campaign_kit_get_suppressions_table();
	$suppression  = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, reason, source, created_at, updated_at FROM {$suppressions} WHERE email_hash = %s AND status = 'active' LIMIT 1", $email_hash ), ARRAY_A );
	if ( $suppression ) {
		$data[] = array(
			'group_id'    => 'newsletter-campaign-kit-suppression',
			'group_label' => __( 'Newsletter delivery suppression', 'newsletter-campaign-kit' ),
			'item_id'     => 'newsletter-suppression-' . absint( $suppression['id'] ),
			'data'        => array(
				array( 'name' => __( 'Status', 'newsletter-campaign-kit' ), 'value' => $suppression['status'] ),
				array( 'name' => __( 'Reason', 'newsletter-campaign-kit' ), 'value' => $suppression['reason'] ),
				array( 'name' => __( 'Source', 'newsletter-campaign-kit' ), 'value' => $suppression['source'] ),
				array( 'name' => __( 'Created', 'newsletter-campaign-kit' ), 'value' => $suppression['created_at'] ),
				array( 'name' => __( 'Updated', 'newsletter-campaign-kit' ), 'value' => $suppression['updated_at'] ),
			),
		);
	}

	return array( 'data' => $data, 'done' => true );
}

/** Register Newsletter Campaign Kit with the WordPress personal-data eraser. */
function newsletter_campaign_kit_register_privacy_eraser( $erasers ) {
	$erasers['newsletter-campaign-kit'] = array(
		'eraser_friendly_name' => __( 'Newsletter subscriptions', 'newsletter-campaign-kit' ),
		'callback'             => 'newsletter_campaign_kit_privacy_eraser',
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'newsletter_campaign_kit_register_privacy_eraser' );

/** Add transparent storage and retention details to the WordPress privacy guide. */
function newsletter_campaign_kit_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = '<p>' . esc_html__( 'Newsletter Campaign Kit stores subscription status, consent source, list assignments and thematic preferences. Delivery suppressions retain a keyed email hash and a reason so an erased or re-imported contact is not contacted again. Campaign snapshots and provider event proofs retain opaque keys after erasure, without the email or subscriber ID, so historical audience totals and suppression processing remain explainable. Administrators can export or erase identifiable subscription data with the native WordPress privacy tools.', 'newsletter-campaign-kit' ) . '</p>';
	wp_add_privacy_policy_content( __( 'Newsletter subscriptions', 'newsletter-campaign-kit' ), wp_kses_post( $content ) );
}
add_action( 'admin_init', 'newsletter_campaign_kit_add_privacy_policy_content' );

/** Remove identifiable subscriber data while retaining an active anti-delivery HMAC. */
function newsletter_campaign_kit_privacy_eraser( $email_address, $page = 1 ) {
	global $wpdb;

	$response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
	if ( 1 !== absint( $page ) ) {
		return $response;
	}
	$email_hash = newsletter_campaign_kit_hash_email( $email_address );
	if ( ! $email_hash ) {
		return $response;
	}

	$subscribers      = newsletter_campaign_kit_get_subscribers_table();
	$suppressions     = newsletter_campaign_kit_get_suppressions_table();
	$subscriber_id    = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$subscribers} WHERE email_hash = %s LIMIT 1", $email_hash ) ) );
	$active_suppression = absint( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$suppressions} WHERE email_hash = %s AND status = 'active' LIMIT 1", $email_hash ) ) );

	if ( $subscriber_id ) {
		$wpdb->query( 'START TRANSACTION' );
		$success = true;
		$snapshot_members_retained = false;
		$provider_events_retained  = false;
		if ( newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
			$snapshot_members = newsletter_campaign_kit_get_audience_snapshot_members_table();
			$member_count     = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$snapshot_members} WHERE subscriber_id = %d", $subscriber_id ) ) );
			if ( $member_count ) {
				$success = false !== $wpdb->update( $snapshot_members, array( 'subscriber_id' => null ), array( 'subscriber_id' => $subscriber_id ), array( '%d' ), array( '%d' ) );
				$snapshot_members_retained = $success;
			}
		}
		$provider_events = newsletter_campaign_kit_get_provider_events_table();
		if ( $success && newsletter_campaign_kit_table_exists( $provider_events ) ) {
			$provider_event_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$provider_events} WHERE subscriber_id = %d", $subscriber_id ) ) );
			if ( $provider_event_count ) {
				$success                  = false !== $wpdb->update( $provider_events, array( 'subscriber_id' => null ), array( 'subscriber_id' => $subscriber_id ), array( '%d' ), array( '%d' ) );
				$provider_events_retained = $success;
			}
		}
		$tables = array(
			newsletter_campaign_kit_get_queue_table(),
			newsletter_campaign_kit_get_subscriber_topics_table(),
			newsletter_campaign_kit_get_subscriber_lists_table(),
			newsletter_campaign_kit_get_subscriber_tags_table(),
			newsletter_campaign_kit_get_audit_table(),
		);
		foreach ( $tables as $table ) {
			if ( false === $wpdb->delete( $table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) ) ) {
				$success = false;
				break;
			}
		}
		if ( $success && false === $wpdb->delete( $subscribers, array( 'id' => $subscriber_id ), array( '%d' ) ) ) {
			$success = false;
		}
		if ( $success && false === $wpdb->update( $suppressions, array( 'subscriber_id' => null ), array( 'subscriber_id' => $subscriber_id ), array( '%d' ), array( '%d' ) ) ) {
			$success = false;
		}
		$wpdb->query( $success ? 'COMMIT' : 'ROLLBACK' );
		if ( ! $success ) {
			$response['messages'][] = __( 'Newsletter data could not be fully erased because the storage operation failed.', 'newsletter-campaign-kit' );
			return $response;
		}
		$response['items_removed'] = true;
		if ( $snapshot_members_retained ) {
			$response['items_retained'] = true;
			$response['messages'][]     = __( 'Opaque campaign-specific audience membership keys were retained without the email or subscriber ID to preserve historical campaign totals.', 'newsletter-campaign-kit' );
		}
		if ( $provider_events_retained ) {
			$response['items_retained'] = true;
			$response['messages'][]     = __( 'Opaque provider event proofs were retained without the email or subscriber ID to preserve bounce and complaint processing history.', 'newsletter-campaign-kit' );
		}
	}

	if ( $active_suppression ) {
		$response['items_retained'] = true;
		$response['messages'][]     = __( 'A non-reversible email HMAC and suppression reason were retained to prevent future delivery to an actively suppressed address.', 'newsletter-campaign-kit' );
	} else {
		$wpdb->delete( $suppressions, array( 'email_hash' => $email_hash ), array( '%s' ) );
	}

	return $response;
}
