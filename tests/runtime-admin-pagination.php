<?php
/**
 * WordPress runtime verification for paginated administration screens.
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
$campaign_table   = newsletter_campaign_kit_get_campaigns_table();
$template_table   = newsletter_campaign_kit_get_templates_table();
$block_table      = newsletter_campaign_kit_get_blocks_table();
$queue_table      = newsletter_campaign_kit_get_queue_table();
$suppression_table = newsletter_campaign_kit_get_suppressions_table();
$previous_user    = get_current_user_id();
$previous_get     = $_GET;
$previous_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null;
$prefix           = 'admin-page-runtime-' . strtolower( wp_generate_password( 6, false, false ) );
$created_ids      = array();
$campaign_ids     = array();
$template_ids     = array();
$block_ids        = array();
$queue_ids        = array();
$suppression_ids  = array();

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

		$fixture_time = sprintf( '2099-12-31 23:59:%02d', 59 - $index );
		$wpdb->insert(
			$campaign_table,
			array(
				'title'       => $prefix . ' campaign ' . $index,
				'slug'        => $prefix . '-campaign-' . $index,
				'subject'     => 'Runtime pagination campaign',
				'body'        => '<p>Runtime pagination</p>',
				'text_body'   => 'Runtime pagination',
				'status'      => 'draft',
				'created_by'  => get_current_user_id(),
				'updated_by'  => get_current_user_id(),
				'created_at'  => $fixture_time,
				'updated_at'  => $fixture_time,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		newsletter_admin_pagination_assert( 0 < (int) $wpdb->insert_id, 'A runtime campaign could not be inserted.' );
		$campaign_ids[] = (int) $wpdb->insert_id;

		$wpdb->insert(
			$template_table,
			array(
				'name'         => $prefix . ' template ' . $index,
				'slug'         => $prefix . '-template-' . $index,
				'subject'      => 'Runtime pagination template',
				'preview_text' => 'Runtime pagination',
				'html_body'    => '<p>Runtime pagination</p>',
				'text_body'    => 'Runtime pagination',
				'status'       => 'active',
				'created_by'   => get_current_user_id(),
				'updated_by'   => get_current_user_id(),
				'created_at'   => $fixture_time,
				'updated_at'   => $fixture_time,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		newsletter_admin_pagination_assert( 0 < (int) $wpdb->insert_id, 'A runtime template could not be inserted.' );
		$template_ids[] = (int) $wpdb->insert_id;

		$wpdb->insert(
			$block_table,
			array(
				'name'        => $prefix . ' block ' . $index,
				'slug'        => $prefix . '-block-' . $index,
				'category'    => 'announcement',
				'html_body'   => '<p>Runtime pagination</p>',
				'text_body'   => 'Runtime pagination',
				'status'      => 'active',
				'created_by'  => get_current_user_id(),
				'updated_by'  => get_current_user_id(),
				'created_at'  => $fixture_time,
				'updated_at'  => $fixture_time,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		newsletter_admin_pagination_assert( 0 < (int) $wpdb->insert_id, 'A runtime block could not be inserted.' );
		$block_ids[] = (int) $wpdb->insert_id;

		$wpdb->insert(
			$queue_table,
			array(
				'campaign_id'    => end( $campaign_ids ),
				'subscriber_id'  => end( $created_ids ),
				'status'         => 'failed',
				'attempts'       => 5,
				'next_attempt_at' => $fixture_time,
				'last_error'      => 'Runtime pagination failure',
				'created_at'      => $fixture_time,
				'updated_at'      => $fixture_time,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		newsletter_admin_pagination_assert( 0 < (int) $wpdb->insert_id, 'A runtime queue item could not be inserted.' );
		$queue_ids[] = (int) $wpdb->insert_id;

		$wpdb->insert(
			$suppression_table,
			array(
				'email_hash' => hash( 'sha256', $prefix . '-suppression-' . $index ),
				'status'     => 'active',
				'reason'     => 'manual',
				'source'     => 'runtime_admin_pagination',
				'created_at' => $fixture_time,
				'updated_at' => $fixture_time,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		newsletter_admin_pagination_assert( 0 < (int) $wpdb->insert_id, 'A runtime suppression could not be inserted.' );
		$suppression_ids[] = (int) $wpdb->insert_id;
	}

	newsletter_admin_pagination_assert( 31 === newsletter_campaign_kit_count_subscribers( array( 'search' => $prefix ) ), 'Filtered subscriber count is incorrect.' );
	$second_page = newsletter_campaign_kit_get_subscribers( array( 'search' => $prefix, 'limit' => 25, 'offset' => 25 ) );
	newsletter_admin_pagination_assert( 6 === count( $second_page ), 'Subscriber SQL pagination is incorrect.' );
	$second_campaign_page = newsletter_campaign_kit_get_campaigns( 25, 25 );
	newsletter_admin_pagination_assert( 6 <= count( array_filter( $second_campaign_page, static function ( $campaign ) use ( $prefix ) { return 0 === strpos( $campaign['slug'], $prefix ); } ) ), 'Campaign SQL pagination is incorrect.' );
	$second_template_page = newsletter_campaign_kit_get_templates( true, 25, 25 );
	newsletter_admin_pagination_assert( 6 <= count( array_filter( $second_template_page, static function ( $template ) use ( $prefix ) { return 0 === strpos( $template['slug'], $prefix ); } ) ), 'Template SQL pagination is incorrect.' );
	$second_block_page = newsletter_campaign_kit_get_blocks( true, 25, 25 );
	newsletter_admin_pagination_assert( 6 <= count( array_filter( $second_block_page, static function ( $block ) use ( $prefix ) { return 0 === strpos( $block['slug'], $prefix ); } ) ), 'Block SQL pagination is incorrect.' );
	$second_queue_page = newsletter_campaign_kit_get_recent_queue_items( 25, 25, 'failed' );
	newsletter_admin_pagination_assert( 6 <= count( array_filter( $second_queue_page, static function ( $item ) { return 'Runtime pagination failure' === $item['last_error']; } ) ), 'Filtered queue SQL pagination is incorrect.' );
	$second_suppression_page = newsletter_campaign_kit_get_suppressions( 20, 20 );
	newsletter_admin_pagination_assert( 11 <= count( array_filter( $second_suppression_page, static function ( $suppression ) { return 'runtime_admin_pagination' === $suppression['source']; } ) ), 'Suppression SQL pagination is incorrect.' );

	$_GET = array( 'page' => 'newsletter-campaign-kit', 's' => $prefix, 'status' => 'subscribed', 'paged' => 1 );
	ob_start();
	newsletter_campaign_kit_render_subscribers_page();
	$subscriber_markup = ob_get_clean();
	newsletter_admin_pagination_assert( false !== strpos( $subscriber_markup, 'nck-pagination' ) && false !== strpos( $subscriber_markup, 'page-numbers' ), 'Subscriber pagination controls are missing.' );
	newsletter_admin_pagination_assert( false !== strpos( $subscriber_markup, 'suppression_page' ), 'Independent suppression pagination controls are missing.' );
	newsletter_admin_pagination_assert( false !== strpos( $subscriber_markup, 'nck-table-wrap' ), 'Subscriber table is not responsive.' );

	$_GET = array( 'page' => 'newsletter-campaign-kit-queue', 'status' => 'failed', 'paged' => 1 );
	ob_start();
	newsletter_campaign_kit_render_queue_page();
	$queue_markup = ob_get_clean();
	newsletter_admin_pagination_assert( false !== strpos( $queue_markup, 'nck-queue-status' ) && false !== strpos( $queue_markup, 'nck-pagination' ), 'Filtered queue controls are missing.' );
	newsletter_admin_pagination_assert( false !== strpos( $queue_markup, 'Runtime pagination failure' ), 'Filtered queue details are missing.' );

	$_GET = array( 'page' => 'newsletter-campaign-kit-audit', 'event' => 'runtime_admin_pagination', 'status' => 'success', 'paged' => 1 );
	ob_start();
	newsletter_campaign_kit_render_audit_page();
	$audit_markup = ob_get_clean();
	newsletter_admin_pagination_assert( false !== strpos( $audit_markup, 'nck-pagination' ), 'Audit pagination controls are missing.' );
	newsletter_admin_pagination_assert( false !== strpos( $audit_markup, 'nck-audit-details' ) && false !== strpos( $audit_markup, 'Runtime Admin Browser' ), 'Detailed audit context is missing.' );
	newsletter_admin_pagination_assert( false === strpos( $audit_markup, 'must-not-appear' ), 'A protected audit token was rendered.' );

	ob_start();
	newsletter_campaign_kit_render_pagination( 2, 31, 25, array( 'page' => 'newsletter-campaign-kit-queue', 'status' => 'failed' ) );
	$pagination_markup = ob_get_clean();
	newsletter_admin_pagination_assert( false !== strpos( $pagination_markup, 'status=failed' ), 'Pagination does not preserve the active filter.' );

	echo wp_json_encode( array( 'subscriber_pagination' => true, 'audit_pagination' => true, 'campaign_pagination' => true, 'template_pagination' => true, 'block_pagination' => true, 'queue_pagination' => true, 'suppression_pagination' => true, 'queue_details' => true, 'audit_details' => true, 'responsive_tables' => true ) );
} finally {
	$_GET = $previous_get;
	if ( null === $previous_user_agent ) {
		unset( $_SERVER['HTTP_USER_AGENT'] );
	} else {
		$_SERVER['HTTP_USER_AGENT'] = $previous_user_agent;
	}
	wp_set_current_user( $previous_user );
	if ( $queue_ids ) {
		$wpdb->query( 'DELETE FROM ' . $queue_table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $queue_ids ) ) . ')' );
	}
	if ( $suppression_ids ) {
		$wpdb->query( 'DELETE FROM ' . $suppression_table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $suppression_ids ) ) . ')' );
	}
	if ( $block_ids ) {
		$wpdb->query( 'DELETE FROM ' . $block_table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $block_ids ) ) . ')' );
	}
	if ( $template_ids ) {
		$wpdb->query( 'DELETE FROM ' . $template_table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $template_ids ) ) . ')' );
	}
	if ( $campaign_ids ) {
		$wpdb->query( 'DELETE FROM ' . $campaign_table . ' WHERE id IN (' . implode( ',', array_map( 'absint', $campaign_ids ) ) . ')' );
	}
	if ( $created_ids ) {
		$ids = implode( ',', array_map( 'absint', $created_ids ) );
		$wpdb->query( "DELETE FROM {$audit_table} WHERE subscriber_id IN ({$ids}) OR event = 'runtime_admin_pagination'" );
		$wpdb->query( "DELETE FROM {$subscriber_table} WHERE id IN ({$ids})" );
	}
}
