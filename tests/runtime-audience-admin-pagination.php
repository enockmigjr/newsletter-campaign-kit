<?php
/**
 * WordPress runtime verification for paginated audience catalogues.
 *
 * Run with: wp eval-file tests/runtime-audience-admin-pagination.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_audience_pagination_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$tables = array(
	'lists'    => newsletter_campaign_kit_get_lists_table(),
	'tags'     => newsletter_campaign_kit_get_tags_table(),
	'segments' => newsletter_campaign_kit_get_segments_table(),
	'topics'   => newsletter_campaign_kit_get_topics_table(),
);
$created_ids  = array_fill_keys( array_keys( $tables ), array() );
$previous_get = $_GET;
$previous_user = get_current_user_id();
$prefix        = 'audience-page-' . strtolower( wp_generate_password( 6, false, false ) );

try {
	$administrator = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	newsletter_audience_pagination_assert( ! empty( $administrator ), 'No administrator is available.' );
	wp_set_current_user( (int) $administrator[0] );

	for ( $index = 1; $index <= 41; ++$index ) {
		$fixture_time = sprintf( '2099-12-30 23:59:%02d', 59 - $index );
		$fixtures     = array(
			'lists' => array(
				'data'   => array( 'name' => $prefix . ' list ' . $index, 'slug' => $prefix . '-list-' . $index, 'description' => 'Runtime audience pagination', 'status' => 'active', 'created_at' => $fixture_time, 'updated_at' => $fixture_time ),
				'format' => array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			),
			'tags' => array(
				'data'   => array( 'name' => $prefix . ' tag ' . $index, 'slug' => $prefix . '-tag-' . $index, 'color' => '#123456', 'created_at' => $fixture_time, 'updated_at' => $fixture_time ),
				'format' => array( '%s', '%s', '%s', '%s', '%s' ),
			),
			'segments' => array(
				'data'   => array( 'name' => $prefix . ' segment ' . $index, 'slug' => $prefix . '-segment-' . $index, 'description' => 'Runtime audience pagination', 'match_type' => 'all', 'rules' => wp_json_encode( array( 'list_ids' => array(), 'tag_ids' => array(), 'sources' => array( 'runtime-no-match' ), 'created_after' => '', 'created_before' => '' ) ), 'status' => 'active', 'created_at' => $fixture_time, 'updated_at' => $fixture_time ),
				'format' => array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			),
			'topics' => array(
				'data'   => array( 'name' => '000 ' . $prefix . ' topic ' . sprintf( '%02d', $index ), 'slug' => $prefix . '-topic-' . $index, 'description' => 'Runtime audience pagination', 'color' => '#654321', 'status' => 'active', 'created_at' => $fixture_time, 'updated_at' => $fixture_time ),
				'format' => array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			),
		);

		foreach ( $fixtures as $kind => $fixture ) {
			$inserted = $wpdb->insert( $tables[ $kind ], $fixture['data'], $fixture['format'] );
			newsletter_audience_pagination_assert( false !== $inserted && 0 < (int) $wpdb->insert_id, 'A runtime ' . $kind . ' fixture could not be inserted.' );
			$created_ids[ $kind ][] = (int) $wpdb->insert_id;
		}
	}

	$pages = array(
		'lists'    => newsletter_campaign_kit_get_lists( 20, 20 ),
		'tags'     => newsletter_campaign_kit_get_tags( 20, 20 ),
		'segments' => newsletter_campaign_kit_get_segments( true, 20, 20 ),
		'topics'   => newsletter_campaign_kit_get_topics( 20, 20 ),
	);
	foreach ( $pages as $kind => $rows ) {
		$fixture_rows = array_filter( $rows, static function ( $row ) use ( $prefix ) { return false !== strpos( $row['slug'], $prefix ); } );
		newsletter_audience_pagination_assert( 20 === count( $fixture_rows ), ucfirst( $kind ) . ' SQL pagination is incorrect.' );
	}

	$_GET = array( 'page' => 'newsletter-campaign-kit-segments', 'list_page' => 1, 'tag_page' => 1, 'segment_page' => 1, 'topic_page' => 1 );
	ob_start();
	newsletter_campaign_kit_render_segments_page();
	$markup = ob_get_clean();
	foreach ( array( 'list_page', 'tag_page', 'segment_page', 'topic_page' ) as $page_key ) {
		newsletter_audience_pagination_assert( false !== strpos( $markup, $page_key ), 'Pagination control is missing for ' . $page_key . '.' );
	}
	newsletter_audience_pagination_assert( 4 <= substr_count( $markup, 'nck-table-wrap' ), 'Audience tables are not responsive.' );

	echo wp_json_encode( array( 'lists' => true, 'tags' => true, 'segments' => true, 'topics' => true, 'independent_pagination' => true, 'responsive_tables' => true ) );
} finally {
	$_GET = $previous_get;
	wp_set_current_user( $previous_user );
	foreach ( array( 'segments', 'topics', 'tags', 'lists' ) as $kind ) {
		if ( $created_ids[ $kind ] ) {
			$wpdb->query( 'DELETE FROM ' . $tables[ $kind ] . ' WHERE id IN (' . implode( ',', array_map( 'absint', $created_ids[ $kind ] ) ) . ')' );
		}
	}
}
