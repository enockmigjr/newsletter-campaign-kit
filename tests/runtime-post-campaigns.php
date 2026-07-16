<?php
/**
 * Runtime verification for subscription topic capture and article campaigns.
 *
 * Run with: wp eval-file tests/runtime-post-campaigns.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_post_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;
$suffix       = strtolower( wp_generate_password( 8, false, false ) );
$email        = 'post-campaign-' . $suffix . '@photovault.test';
$topic_id     = 0;
$subscriber_id = 0;
$post_ids     = array();
$campaign_ids = array();
$old_settings = get_option( 'newsletter_campaign_kit_provider_settings', array() );

try {
	$now = current_time( 'mysql', true );
	$wpdb->insert(
		newsletter_campaign_kit_get_topics_table(),
		array( 'name' => 'Runtime journal ' . $suffix, 'slug' => 'runtime-journal-' . $suffix, 'description' => 'Runtime article topic', 'color' => '#1f6f54', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$topic_id = (int) $wpdb->insert_id;

	$wpdb->insert(
		newsletter_campaign_kit_get_subscribers_table(),
		array( 'email' => $email, 'email_hash' => newsletter_campaign_kit_hash_email( $email ), 'unsubscribe_token' => newsletter_campaign_kit_create_unsubscribe_token( newsletter_campaign_kit_hash_email( $email ) ), 'status' => 'pending', 'source' => 'runtime_post', 'consent_text' => 'Runtime consent', 'created_at' => $now, 'updated_at' => $now ),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
	$subscriber_id = (int) $wpdb->insert_id;
	newsletter_post_runtime_assert( true === newsletter_campaign_kit_set_initial_topic_preferences( $subscriber_id, array( $topic_id ) ), 'Pending subscription topics were not stored.' );
	newsletter_post_runtime_assert( newsletter_campaign_kit_subscriber_accepts_topic( $subscriber_id, $topic_id ), 'Selected pending topic was rejected.' );
	newsletter_post_runtime_assert( is_wp_error( newsletter_campaign_kit_set_topic_preferences( $subscriber_id, array() ) ), 'The public preference API accepted an unconfirmed subscriber.' );

	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'wp_mail', 'post_newsletter_mode' => 'draft', 'post_newsletter_topic_id' => $topic_id ), false );
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'Runtime article ' . $suffix,
			'post_excerpt' => 'A concise runtime excerpt.',
			'post_content' => '<p>A complete editorial article reused by the campaign.</p>',
		),
		true
	);
	newsletter_post_runtime_assert( ! is_wp_error( $post_id ), 'The article fixture could not be created.' );
	$post_ids[] = (int) $post_id;
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$campaign_id = absint( get_post_meta( $post_id, '_newsletter_campaign_kit_campaign_id', true ) );
	$campaign_ids[] = $campaign_id;
	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	newsletter_post_runtime_assert( $campaign && 'draft' === $campaign['status'], 'Publishing did not create an editable campaign draft.' );
	newsletter_post_runtime_assert( $topic_id === absint( $campaign['topic_id'] ) && false !== strpos( $campaign['body'], 'complete editorial article' ), 'The article content or configured topic was not reused.' );
	newsletter_post_runtime_assert( $campaign_id === newsletter_campaign_kit_create_post_campaign( $post_id, 1 ), 'Article campaign creation was not idempotent.' );

	echo wp_json_encode(
		array(
			'pending_topic_capture' => true,
			'post_campaign_draft'   => true,
			'idempotent'            => true,
		)
	);
} finally {
	update_option( 'newsletter_campaign_kit_provider_settings', $old_settings, false );
	foreach ( array_filter( $campaign_ids ) as $campaign_id ) {
		$wpdb->delete( newsletter_campaign_kit_get_queue_table(), array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( newsletter_campaign_kit_get_campaigns_table(), array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $subscriber_id ) {
		$wpdb->delete( newsletter_campaign_kit_get_subscriber_topics_table(), array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( newsletter_campaign_kit_get_subscribers_table(), array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	if ( $topic_id ) {
		$wpdb->delete( newsletter_campaign_kit_get_topics_table(), array( 'id' => $topic_id ), array( '%d' ) );
	}
}
