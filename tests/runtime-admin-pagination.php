<?php
/**
 * WordPress runtime verification for paginated subscriber and audit screens.
 *
 * Run with: wp eval-file tests/runtime-admin-pagination.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_admin_pagination_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$subscriber_table = newsletter_campaign_kit_get_subscribers_table();
$audit_table      = newsletter_campaign_kit_get_audit_table();
$previous_user    = get_current_user_id();
$previous_get     = $_GET;
$previous_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null;
$prefix           = 'admin-page-runtime-' . strtolower( wp_generate_password( 6, false, false ) );
$created_ids      = array();

try {
	$administrator = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	newsletter_admin_pagination_assert( ! empty( $administrator ), 'No administrator is available.' );
	wp_set_current_user( (int) $administrator[0] );
	$_SERVER['HTTP_USER_AGENT'] = 'Runtime Admin Browser';
	$now = current_time( 'mysql', true );
	for ( $index = 1; $index <= 31; ++$index ) {
		$email = $prefix . '-' . $index . '@photovault.test';
		$ok    = $wpdb->insert(
			$subscriber_table,
			array(
				'email_hash'        => newsletter_campaign_kit_hash_email( $email ),
				'email'             => $email,
				'unsubscribe_token' => newsletter_campaign_kit_create_unsubscribe_token( newsletter_campaign_kit_hash_email( $email ) ),
				'status'            => 'subscribed',
				'source'            => 'runtime_admin_pagination',
				'consent_text'      => 'Runtime admin pagination',
				'ip_hash'           => '',
				'user_agent'        => 'Runtime Admin Browser',
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		newsletter_admin_pagination_assert( false !== $ok, 'A runtime subscriber could not be inserted.' );
		$created_ids[] = (int) $wpdb->insert_id;
		newsletter_campaign_kit_log_event( 'runtime_admin_pagination', 'success', (int) $wpdb->insert_id, array( 'batch' => $prefix, 'row' => $index, 'token' => 'must-not-appear' ) );
	}

	newsletter_admin_pagination_assert( 31 === newsletter_campaign_kit_count_subscribers( array( 'search' => $prefix ) ), 'Filtered subscriber count is incorrect.' );
	$second_page = newsletter_campaign_kit_get_subscribers( array( 'search' => $prefix, 'limit' => 25, 'offset' => 25 ) );
	newsletter_admin_pagination_assert( 6 === count( $second_page ), 'Subscriber SQL pagination is incorrect.' );

	$_GET = array( 'page' => 'newsletter-campaign-kit', 's' => $prefix, 'status' => 'subscribed', 'paged' => 1 );
	ob_start();
	newsletter_campaign_kit_render_subscribers_page();
	$subscriber_markup = ob_get_clean();
	newsletter_admin_pagination_assert( false !== strpos( $subscriber_markup, 'nck-pagination' ) && false !== strpos( $subscriber_markup, 'page-numbers' ), 'Subscriber pagination controls are missing.' );
	newsletter_admin_pagination_assert( false !== strpos( $subscriber_markup, 'nck-table-wrap' ), 'Subscriber table is not responsive.' );

	$_GET = array( 'page' => 'newsletter-campaign-kit-audit', 'event' => 'runtime_admin_pagination', 'status' => 'success', 'paged' => 1 );
	ob_start();
	newsletter_campaign_kit_render_audit_page();
	$audit_markup = ob_get_clean();
	newsletter_admin_pagination_assert( false !== strpos( $audit_markup, 'nck-pagination' ), 'Audit pagination controls are missing.' );
	newsletter_admin_pagination_assert( false !== strpos( $audit_markup, 'nck-audit-details' ) && false !== strpos( $audit_markup, 'Runtime Admin Browser' ), 'Detailed audit context is missing.' );
	newsletter_admin_pagination_assert( false === strpos( $audit_markup, 'must-not-appear' ), 'A protected audit token was rendered.' );

	echo wp_json_encode( array( 'subscriber_pagination' => true, 'audit_pagination' => true, 'audit_details' => true, 'responsive_tables' => true ) );
} finally {
	$_GET = $previous_get;
	if ( null === $previous_user_agent ) {
		unset( $_SERVER['HTTP_USER_AGENT'] );
	} else {
		$_SERVER['HTTP_USER_AGENT'] = $previous_user_agent;
	}
	wp_set_current_user( $previous_user );
	if ( $created_ids ) {
		$ids = implode( ',', array_map( 'absint', $created_ids ) );
		$wpdb->query( "DELETE FROM {$audit_table} WHERE subscriber_id IN ({$ids}) OR event = 'runtime_admin_pagination'" );
		$wpdb->query( "DELETE FROM {$subscriber_table} WHERE id IN ({$ids})" );
	}
}
