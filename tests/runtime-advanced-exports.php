<?php
/**
 * WordPress runtime verification for advanced operational CSV exports.
 *
 * Run with: wp eval-file tests/runtime-advanced-exports.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_exports_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix         = strtolower( wp_generate_password( 8, false, false ) );
$subscriber_ids = array();
$campaign_ids   = array();
$list_id        = 0;
$tag_id         = 0;
$segment_id     = 0;
$topic_id       = 0;
$user_id        = 0;
$original_user  = get_current_user_id();
$subscribers    = newsletter_campaign_kit_get_subscribers_table();
$lists          = newsletter_campaign_kit_get_lists_table();
$tags           = newsletter_campaign_kit_get_tags_table();
$segments       = newsletter_campaign_kit_get_segments_table();
$topics         = newsletter_campaign_kit_get_topics_table();
$campaigns      = newsletter_campaign_kit_get_campaigns_table();
$queue          = newsletter_campaign_kit_get_queue_table();
$audit          = newsletter_campaign_kit_get_audit_table();

try {
	$administrators = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	newsletter_exports_runtime_assert( ! empty( $administrators ), 'An administrator is required for export verification.' );
	wp_set_current_user( (int) $administrators[0] );
	newsletter_exports_runtime_assert( current_user_can( 'newsletter_view_reports' ), 'The administrator does not have report export access.' );

	$now = current_time( 'mysql', true );
	for ( $index = 0; $index < 105; $index++ ) {
		$email  = sprintf( 'export-%03d-%s@photovault.test', $index, $suffix );
		$source = 0 === $index ? '=HYPERLINK("https://invalid.test")' : 'runtime_export_' . $suffix;
		$wpdb->insert(
			$subscribers,
			array(
				'email_hash'       => newsletter_campaign_kit_hash_email( $email ),
				'email'            => $email,
				'unsubscribe_token'=> hash( 'sha256', $email . wp_generate_password( 12, false, false ) ),
				'status'           => 'subscribed',
				'source'           => $source,
				'consent_text'     => 'Runtime export consent',
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		newsletter_exports_runtime_assert( 0 < (int) $wpdb->insert_id, 'A subscriber export fixture could not be inserted.' );
		$subscriber_ids[] = (int) $wpdb->insert_id;
	}

	$wpdb->insert( $lists, array( 'name' => '=Runtime list ' . $suffix, 'slug' => 'runtime-list-' . $suffix, 'description' => '+List description', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
	$list_id = (int) $wpdb->insert_id;
	$wpdb->insert( $tags, array( 'name' => '+Runtime tag ' . $suffix, 'slug' => 'runtime-tag-' . $suffix, 'color' => '#112233', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
	$tag_id = (int) $wpdb->insert_id;
	$rules = wp_json_encode( array( 'list_ids' => array(), 'tag_ids' => array(), 'sources' => array( 'runtime-no-match-' . $suffix ), 'created_after' => '', 'created_before' => '' ) );
	$wpdb->insert( $segments, array( 'name' => '@Runtime segment ' . $suffix, 'slug' => 'runtime-segment-' . $suffix, 'description' => "\tSegment description", 'match_type' => 'all', 'rules' => $rules, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	$segment_id = (int) $wpdb->insert_id;
	$wpdb->insert( $topics, array( 'name' => '-Runtime topic ' . $suffix, 'slug' => 'runtime-topic-' . $suffix, 'description' => "\rTopic description", 'color' => '#334455', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	$topic_id = (int) $wpdb->insert_id;

	$campaign_id = newsletter_campaign_kit_create_campaign( array( 'title' => '=Runtime campaign ' . $suffix, 'subject' => '+Runtime report', 'html_body' => '<p>Export report</p>', 'text_body' => 'Export report', 'target_audience' => 'all' ), get_current_user_id() );
	newsletter_exports_runtime_assert( is_int( $campaign_id ), 'The campaign export fixture could not be created.' );
	$campaign_ids[] = $campaign_id;

	$subscriber_export = newsletter_campaign_kit_get_export_dataset( 'subscribers' );
	$fixture_rows      = array_filter( $subscriber_export['rows'], static function ( $row ) use ( $suffix ) { return false !== strpos( $row['email'], $suffix ); } );
	newsletter_exports_runtime_assert( 105 === count( $fixture_rows ), 'Subscriber export still truncates at 100 rows.' );
	$subscriber_stream = fopen( 'php://temp', 'w+' );
	$streamed_count    = newsletter_campaign_kit_stream_subscribers_csv( $subscriber_stream, 100 );
	rewind( $subscriber_stream );
	$subscriber_csv = stream_get_contents( $subscriber_stream );
	fclose( $subscriber_stream );
	preg_match_all( '/export-[0-9]{3}-' . preg_quote( $suffix, '/' ) . '@photovault\.test/', $subscriber_csv, $fixture_matches );
	newsletter_exports_runtime_assert( ! is_wp_error( $streamed_count ) && 105 === count( $fixture_matches[0] ), 'The streamed subscriber export omitted rows after a batch boundary.' );

	$datasets = array();
	foreach ( array( 'lists', 'tags', 'segments', 'topics', 'campaigns' ) as $kind ) {
		$datasets[ $kind ] = newsletter_campaign_kit_get_export_dataset( $kind );
		newsletter_exports_runtime_assert( ! is_wp_error( $datasets[ $kind ] ) && ! empty( $datasets[ $kind ]['headers'] ), 'An advanced export dataset is unavailable: ' . $kind );
	}
	newsletter_exports_runtime_assert( is_wp_error( newsletter_campaign_kit_get_export_dataset( 'unknown' ) ), 'An unknown export kind was accepted.' );
	newsletter_exports_runtime_assert( in_array( $list_id, array_map( 'absint', wp_list_pluck( $datasets['lists']['rows'], 0 ) ), true ), 'The list export omitted its fixture.' );
	newsletter_exports_runtime_assert( in_array( $tag_id, array_map( 'absint', wp_list_pluck( $datasets['tags']['rows'], 0 ) ), true ), 'The tag export omitted its fixture.' );
	newsletter_exports_runtime_assert( in_array( $segment_id, array_map( 'absint', wp_list_pluck( $datasets['segments']['rows'], 0 ) ), true ), 'The segment export omitted its fixture.' );
	newsletter_exports_runtime_assert( in_array( $topic_id, array_map( 'absint', wp_list_pluck( $datasets['topics']['rows'], 0 ) ), true ), 'The topic export omitted its fixture.' );
	newsletter_exports_runtime_assert( in_array( $campaign_id, array_map( 'absint', wp_list_pluck( $datasets['campaigns']['rows'], 0 ) ), true ), 'The campaign report export omitted its fixture.' );
	$report_totals = newsletter_campaign_kit_get_campaign_report_totals();
	newsletter_exports_runtime_assert( 1 <= $report_totals['campaigns'], 'Global report totals omitted campaigns outside the report page query.' );

	$stream = fopen( 'php://temp', 'w+' );
	$custom = array( 'headers' => array( '=header', 'value' ), 'rows' => array( array( '=formula', '+command' ), array( '-number', '@lookup' ), array( "\tTabbed", "\rCarriage" ) ) );
	newsletter_exports_runtime_assert( 3 === newsletter_campaign_kit_write_csv_dataset( $stream, $custom ), 'The CSV writer returned the wrong row count.' );
	rewind( $stream );
	$csv = stream_get_contents( $stream );
	fclose( $stream );
	newsletter_exports_runtime_assert( 0 === strpos( $csv, "\xEF\xBB\xBF" ), 'The UTF-8 CSV BOM is missing.' );
	foreach ( array( "'=header", "'=formula", "'+command", "'-number", "'@lookup", "'\tTabbed", "'\rCarriage" ) as $safe_value ) {
		newsletter_exports_runtime_assert( false !== strpos( $csv, $safe_value ), 'A spreadsheet formula prefix was not neutralized.' );
	}

	ob_start();
	newsletter_campaign_kit_render_segments_page();
	$segments_html = ob_get_clean();
	ob_start();
	newsletter_campaign_kit_render_reports_page();
	$reports_html = ob_get_clean();
	newsletter_exports_runtime_assert( false !== strpos( $segments_html, 'newsletter_campaign_kit_operational_export' ) && false !== strpos( $segments_html, 'kind=segments' ), 'The advanced audience export controls are missing.' );
	newsletter_exports_runtime_assert( false !== strpos( $reports_html, 'kind=campaigns' ), 'The campaign report export control is missing.' );
	newsletter_exports_runtime_assert( false !== strpos( newsletter_campaign_kit_get_export_url( 'lists' ), '_wpnonce=' ), 'The operational export URL is not nonce-protected.' );

	$user_id = wp_create_user( 'export-reader-' . $suffix, wp_generate_password( 24, true, true ), 'export-reader-' . $suffix . '@photovault.test' );
	newsletter_exports_runtime_assert( is_int( $user_id ), 'The capability fixture account could not be created.' );
	wp_set_current_user( $user_id );
	newsletter_exports_runtime_assert( ! current_user_can( 'newsletter_view_reports' ), 'A subscriber unexpectedly has export access.' );

	WP_CLI::success(
		wp_json_encode(
			array(
				'subscriber_rows' => 'more_than_100',
				'audiences'       => array( 'lists', 'tags', 'segments', 'topics' ),
				'campaigns'       => 'delivery_report_export',
				'csv_security'     => 'formula_prefixes_neutralized',
				'access'           => 'capability_and_nonce',
			)
		)
	);
} finally {
	wp_set_current_user( $original_user );
	foreach ( $campaign_ids as $campaign_id ) {
		$wpdb->delete( $queue, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( $subscriber_ids as $subscriber_id ) {
		$wpdb->delete( $subscribers, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	if ( $list_id ) $wpdb->delete( $lists, array( 'id' => $list_id ), array( '%d' ) );
	if ( $tag_id ) $wpdb->delete( $tags, array( 'id' => $tag_id ), array( '%d' ) );
	if ( $segment_id ) $wpdb->delete( $segments, array( 'id' => $segment_id ), array( '%d' ) );
	if ( $topic_id ) $wpdb->delete( $topics, array( 'id' => $topic_id ), array( '%d' ) );
	if ( $user_id ) {
		$wpdb->delete( $audit, array( 'actor_user_id' => $user_id ), array( '%d' ) );
		wp_delete_user( $user_id );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE context LIKE %s", '%' . $wpdb->esc_like( $suffix ) . '%' ) );
}
