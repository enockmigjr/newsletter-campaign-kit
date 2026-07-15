<?php
/**
 * Secure operational CSV exports.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Neutralize spreadsheet formulas while preserving readable UTF-8 values. */
function newsletter_campaign_kit_csv_safe_cell( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? '1' : '0';
	}
	if ( null === $value ) {
		return '';
	}
	if ( is_array( $value ) || is_object( $value ) ) {
		$value = wp_json_encode( $value );
	}
	$value = str_replace( "\0", '', (string) $value );
	if ( preg_match( '/^[\x00-\x20]*[=+\-@]/u', $value ) || preg_match( '/^[\t\r]/', $value ) ) {
		$value = "'" . $value;
	}

	return $value;
}

/** Return a bounded, filterable maximum for one operational export. */
function newsletter_campaign_kit_export_row_limit() {
	return max( 100, min( 50000, absint( apply_filters( 'newsletter_campaign_kit_export_row_limit', 10000 ) ) ) );
}

/** Build one export dataset independently from HTTP output. */
function newsletter_campaign_kit_get_export_dataset( $kind ) {
	global $wpdb;

	$kind  = sanitize_key( $kind );
	$limit = newsletter_campaign_kit_export_row_limit();
	$rows  = array();

	if ( 'subscribers' === $kind ) {
		$table = newsletter_campaign_kit_get_subscribers_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, status, source, created_at, updated_at FROM {$table} ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A );
		return array( 'filename' => 'newsletter-subscribers.csv', 'headers' => array( 'id', 'email', 'status', 'source', 'created_at', 'updated_at' ), 'rows' => $rows );
	}

	if ( 'lists' === $kind ) {
		foreach ( newsletter_campaign_kit_get_lists() as $list ) {
			$rows[] = array( $list['id'], $list['name'], $list['slug'], $list['status'], absint( $list['subscribers_count'] ), $list['description'], $list['created_at'], $list['updated_at'] );
		}
		return array( 'filename' => 'newsletter-lists.csv', 'headers' => array( 'id', 'name', 'slug', 'status', 'subscriber_count', 'description', 'created_at', 'updated_at' ), 'rows' => $rows );
	}

	if ( 'tags' === $kind ) {
		foreach ( newsletter_campaign_kit_get_tags() as $tag ) {
			$rows[] = array( $tag['id'], $tag['name'], $tag['slug'], $tag['color'], absint( $tag['subscribers_count'] ), $tag['created_at'], $tag['updated_at'] );
		}
		return array( 'filename' => 'newsletter-tags.csv', 'headers' => array( 'id', 'name', 'slug', 'color', 'subscriber_count', 'created_at', 'updated_at' ), 'rows' => $rows );
	}

	if ( 'segments' === $kind ) {
		foreach ( newsletter_campaign_kit_get_segments( true ) as $segment ) {
			$count  = 'active' === $segment['status'] ? newsletter_campaign_kit_get_segment_audience_count( $segment['id'] ) : 0;
			$rows[] = array( $segment['id'], $segment['name'], $segment['slug'], $segment['status'], $segment['match_type'], $count, $segment['description'], $segment['rules'], $segment['created_at'], $segment['updated_at'] );
		}
		return array( 'filename' => 'newsletter-segments.csv', 'headers' => array( 'id', 'name', 'slug', 'status', 'match_type', 'estimated_recipients', 'description', 'rules', 'created_at', 'updated_at' ), 'rows' => $rows );
	}

	if ( 'topics' === $kind ) {
		$topics = newsletter_campaign_kit_get_topics_table();
		$map    = newsletter_campaign_kit_get_subscriber_topics_table();
		$sql    = "SELECT t.id, t.name, t.slug, t.description, t.color, t.status, t.created_at, t.updated_at,
			SUM(CASE WHEN st.status = 'subscribed' THEN 1 ELSE 0 END) AS subscribed_count,
			SUM(CASE WHEN st.status = 'unsubscribed' THEN 1 ELSE 0 END) AS unsubscribed_count
			FROM {$topics} t LEFT JOIN {$map} st ON st.topic_id = t.id GROUP BY t.id ORDER BY t.id ASC LIMIT %d";
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A ) as $topic ) {
			$rows[] = array( $topic['id'], $topic['name'], $topic['slug'], $topic['status'], $topic['color'], absint( $topic['subscribed_count'] ), absint( $topic['unsubscribed_count'] ), $topic['description'], $topic['created_at'], $topic['updated_at'] );
		}
		return array( 'filename' => 'newsletter-topics.csv', 'headers' => array( 'id', 'name', 'slug', 'status', 'color', 'subscribed_count', 'unsubscribed_count', 'description', 'created_at', 'updated_at' ), 'rows' => $rows );
	}

	if ( 'campaigns' === $kind ) {
		foreach ( newsletter_campaign_kit_get_campaign_reports( $limit ) as $report ) {
			$rows[] = array(
				$report['id'], $report['title'], $report['subject'], $report['status'], $report['audience_type'], $report['audience_label'], $report['topic_label'],
				$report['snapshot_recipient_count'], $report['queued_total'], $report['sent_total'], $report['failed_total'], $report['pending_total'], $report['processing_total'],
				$report['paused_total'], $report['cancelled_total'], $report['delivery_rate'], $report['last_sent_at'], $report['updated_at'], $report['snapshot_rules'],
			);
		}
		return array(
			'filename' => 'newsletter-campaign-reports.csv',
			'headers'  => array( 'id', 'title', 'subject', 'status', 'audience_type', 'audience_label', 'topic', 'snapshot_recipients', 'queued', 'sent', 'failed', 'pending', 'processing', 'paused', 'cancelled', 'delivery_rate_percent', 'last_sent_at', 'updated_at', 'snapshot_rules' ),
			'rows'     => $rows,
		);
	}

	return new WP_Error( 'newsletter_export_invalid', __( 'The requested export is invalid.', 'newsletter-campaign-kit' ) );
}

/** Write a dataset to a CSV stream with UTF-8 BOM and formula neutralization. */
function newsletter_campaign_kit_write_csv_dataset( $stream, $dataset ) {
	if ( ! is_resource( $stream ) || ! is_array( $dataset ) || empty( $dataset['headers'] ) || ! isset( $dataset['rows'] ) ) {
		return new WP_Error( 'newsletter_export_stream_invalid', __( 'The export stream is unavailable.', 'newsletter-campaign-kit' ) );
	}
	fwrite( $stream, "\xEF\xBB\xBF" );
	fputcsv( $stream, array_map( 'newsletter_campaign_kit_csv_safe_cell', $dataset['headers'] ), ',', '"', '' );
	foreach ( $dataset['rows'] as $row ) {
		fputcsv( $stream, array_map( 'newsletter_campaign_kit_csv_safe_cell', (array) $row ), ',', '"', '' );
	}

	return count( $dataset['rows'] );
}

/** Stream every subscriber with keyset pagination to avoid memory truncation. */
function newsletter_campaign_kit_stream_subscribers_csv( $stream, $batch_size = 500 ) {
	global $wpdb;

	if ( ! is_resource( $stream ) || ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return new WP_Error( 'newsletter_export_stream_invalid', __( 'The subscriber export stream is unavailable.', 'newsletter-campaign-kit' ) );
	}
	$table      = newsletter_campaign_kit_get_subscribers_table();
	$batch_size = max( 100, min( 1000, absint( $batch_size ) ) );
	$last_id    = 0;
	$count      = 0;
	fwrite( $stream, "\xEF\xBB\xBF" );
	fputcsv( $stream, array( 'id', 'email', 'status', 'source', 'created_at', 'updated_at' ), ',', '"', '' );

	do {
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, status, source, created_at, updated_at FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$last_id,
				$batch_size
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			fputcsv( $stream, array_map( 'newsletter_campaign_kit_csv_safe_cell', $row ), ',', '"', '' );
			$last_id = absint( $row['id'] );
			++$count;
		}
	} while ( count( $rows ) === $batch_size );

	return $count;
}

function newsletter_campaign_kit_handle_operational_export() {
	if ( ! current_user_can( 'newsletter_view_reports' ) ) {
		wp_die( esc_html__( 'You are not allowed to export newsletter data.', 'newsletter-campaign-kit' ), '', array( 'response' => 403 ) );
	}
	$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
	check_admin_referer( 'newsletter_campaign_kit_export_' . $kind );
	$dataset = 'subscribers' === $kind
		? array( 'filename' => 'newsletter-subscribers.csv', 'headers' => array(), 'rows' => array() )
		: newsletter_campaign_kit_get_export_dataset( $kind );
	if ( is_wp_error( $dataset ) ) {
		wp_die( esc_html( $dataset->get_error_message() ), '', array( 'response' => 400 ) );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $dataset['filename'] ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	$output = fopen( 'php://output', 'w' );
	if ( false === $output ) {
		wp_die( esc_html__( 'The export stream could not be opened.', 'newsletter-campaign-kit' ) );
	}
	$count = 'subscribers' === $kind ? newsletter_campaign_kit_stream_subscribers_csv( $output ) : newsletter_campaign_kit_write_csv_dataset( $output, $dataset );
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_operational_export', is_wp_error( $count ) ? 'failure' : 'success', 0, array( 'kind' => $kind, 'count' => is_wp_error( $count ) ? 0 : $count ) );
	}
	fclose( $output );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_operational_export', 'newsletter_campaign_kit_handle_operational_export' );

/** Build a protected export URL for an allowed dataset. */
function newsletter_campaign_kit_get_export_url( $kind ) {
	$kind = sanitize_key( $kind );

	return wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_operational_export&kind=' . $kind ), 'newsletter_campaign_kit_export_' . $kind );
}
