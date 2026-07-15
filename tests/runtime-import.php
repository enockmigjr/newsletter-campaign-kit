<?php
/**
 * WordPress runtime verification for secure CSV imports.
 *
 * Run with: wp eval-file tests/runtime-import.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_import_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix             = strtolower( wp_generate_password( 8, false, false ) );
$active_email       = 'import-active-' . $suffix . '@photovault.test';
$inactive_email     = 'import-inactive-' . $suffix . '@photovault.test';
$reactivate_email   = 'import-reactivate-' . $suffix . '@photovault.test';
$suppressed_email   = 'import-suppressed-' . $suffix . '@photovault.test';
$unknown_email      = 'import-unknown-' . $suffix . '@photovault.test';
$all_emails         = array( $active_email, $inactive_email, $reactivate_email, $suppressed_email, $unknown_email );
$subscribers_table  = newsletter_campaign_kit_get_subscribers_table();
$lists_table        = newsletter_campaign_kit_get_lists_table();
$tags_table         = newsletter_campaign_kit_get_tags_table();
$list_map_table     = newsletter_campaign_kit_get_subscriber_lists_table();
$tag_map_table      = newsletter_campaign_kit_get_subscriber_tags_table();
$suppressions_table = newsletter_campaign_kit_get_suppressions_table();
$audit_table        = newsletter_campaign_kit_get_audit_table();
$path               = wp_tempnam( 'newsletter-runtime-import.csv' );
$list_id            = 0;
$tag_id             = 0;
$subscriber_ids     = array();
$original_user_id   = get_current_user_id();

try {
	$now       = current_time( 'mysql', true );
	$list_name = 'Runtime import list ' . $suffix;
	$tag_name  = 'Runtime import tag ' . $suffix;
	$wpdb->insert( $lists_table, array( 'name' => $list_name, 'slug' => sanitize_title( $list_name ), 'description' => 'Runtime CSV import', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
	$list_id = (int) $wpdb->insert_id;
	$wpdb->insert( $tags_table, array( 'name' => $tag_name, 'slug' => sanitize_title( $tag_name ), 'color' => '#111827', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
	$tag_id = (int) $wpdb->insert_id;

	newsletter_import_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $reactivate_email, 'runtime_import', 'Initial runtime consent' ), 'Could not create the reactivation fixture.' );
	$reactivate = newsletter_campaign_kit_get_subscriber_by_email( $reactivate_email );
	$subscriber_ids[] = (int) $reactivate['id'];
	newsletter_import_runtime_assert( true === newsletter_campaign_kit_set_subscriber_status( $reactivate['id'], 'unsubscribed', 'runtime_import' ), 'Could not unsubscribe the reactivation fixture.' );
	$suppression = newsletter_campaign_kit_suppress_email_hash( newsletter_campaign_kit_hash_email( $suppressed_email ), 'manual', 'runtime_import', 0 );
	newsletter_import_runtime_assert( ! is_wp_error( $suppression ), 'Could not create the suppression fixture.' );

	$csv = "email,status,lists,tags,consent_text\n";
	$csv .= $active_email . ',subscribed,"' . $list_name . '","' . $tag_name . '",Fresh CSV consent' . "\n";
	$csv .= "not-an-email,subscribed,,,Invalid row\n";
	$csv .= $active_email . ",subscribed,,,Duplicate row\n";
	$csv .= $suppressed_email . ",subscribed,,,Suppression bypass\n";
	$csv .= $inactive_email . ",unsubscribed,,,\n";
	$csv .= $reactivate_email . ",subscribed,,,Fresh reactivation consent\n";
	$csv .= $unknown_email . ",subscribed,missing-runtime-list,,Fresh consent\n";
	file_put_contents( $path, $csv );

	$options = array( 'default_consent' => '', 'allow_reactivate' => false );
	$preview = newsletter_campaign_kit_process_csv_import( $path, $options );
	newsletter_import_runtime_assert( is_array( $preview ) && 'preview' === $preview['mode'], 'Preview mode did not return a report.' );
	newsletter_import_runtime_assert( 7 === $preview['total'] && 2 === $preview['valid'] && 0 === $preview['applied'] && 5 === $preview['errors'], 'Preview counts do not match validation rules.' );
	newsletter_import_runtime_assert( null === newsletter_campaign_kit_get_subscriber_by_email( $active_email ), 'Preview mode mutated subscriber storage.' );

	$options['apply']            = true;
	$options['allow_reactivate'] = true;
	$report = newsletter_campaign_kit_process_csv_import( $path, $options );
	newsletter_import_runtime_assert( is_array( $report ) && 3 === $report['valid'] && 3 === $report['applied'] && 4 === $report['errors'], 'Apply report counts do not match validation rules.' );
	$active     = newsletter_campaign_kit_get_subscriber_by_email( $active_email );
	$inactive   = newsletter_campaign_kit_get_subscriber_by_email( $inactive_email );
	$reactivate = newsletter_campaign_kit_get_subscriber_by_email( $reactivate_email );
	newsletter_import_runtime_assert( $active && 'subscribed' === $active['status'], 'The valid active subscriber was not imported.' );
	newsletter_import_runtime_assert( $inactive && 'unsubscribed' === $inactive['status'], 'The inactive subscriber was not imported without fabricated consent.' );
	newsletter_import_runtime_assert( $reactivate && 'subscribed' === $reactivate['status'], 'Explicit reactivation with fresh consent failed.' );
	$subscriber_ids[] = (int) $active['id'];
	$subscriber_ids[] = (int) $inactive['id'];
	newsletter_import_runtime_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SELECT subscriber_id FROM {$list_map_table} WHERE subscriber_id = %d AND list_id = %d", $active['id'], $list_id ) ), 'Imported list assignment is missing.' );
	newsletter_import_runtime_assert( (bool) $wpdb->get_var( $wpdb->prepare( "SELECT subscriber_id FROM {$tag_map_table} WHERE subscriber_id = %d AND tag_id = %d", $active['id'], $tag_id ) ), 'Imported tag assignment is missing.' );
	newsletter_import_runtime_assert( null === newsletter_campaign_kit_get_subscriber_by_email( $unknown_email ), 'Unknown audiences caused a partial subscriber creation.' );
	newsletter_import_runtime_assert( newsletter_campaign_kit_is_email_hash_suppressed( newsletter_campaign_kit_hash_email( $suppressed_email ) ), 'The import bypassed durable suppression.' );

	$administrator_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	newsletter_import_runtime_assert( ! empty( $administrator_ids ), 'No administrator is available for the import-screen test.' );
	wp_set_current_user( (int) $administrator_ids[0] );
	ob_start();
	newsletter_campaign_kit_render_import_page();
	$screen = ob_get_clean();
	newsletter_import_runtime_assert( false !== strpos( $screen, 'newsletter_campaign_kit_import_csv' ) && false !== strpos( $screen, 'confirm_apply' ), 'The protected import screen is incomplete.' );

	echo wp_json_encode(
		array(
			'preview_non_mutating' => true,
			'deduplication'        => 'validated',
			'suppression'          => 'enforced',
			'consent_reactivation' => 'explicit',
			'audience_assignments' => 'validated',
			'row_transactions'     => true,
			'admin_screen'         => true,
		)
	);
} finally {
	wp_set_current_user( $original_user_id );
	if ( $path && file_exists( $path ) ) {
		unlink( $path );
	}
	foreach ( array_unique( array_filter( $subscriber_ids ) ) as $subscriber_id ) {
		$wpdb->delete( $list_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $tag_map_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $audit_table, array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	foreach ( $all_emails as $email ) {
		$wpdb->delete( $suppressions_table, array( 'email_hash' => newsletter_campaign_kit_hash_email( $email ) ), array( '%s' ) );
	}
	if ( $list_id ) {
		$wpdb->delete( $list_map_table, array( 'list_id' => $list_id ), array( '%d' ) );
		$wpdb->delete( $lists_table, array( 'id' => $list_id ), array( '%d' ) );
	}
	if ( $tag_id ) {
		$wpdb->delete( $tag_map_table, array( 'tag_id' => $tag_id ), array( '%d' ) );
		$wpdb->delete( $tags_table, array( 'id' => $tag_id ), array( '%d' ) );
	}
}
