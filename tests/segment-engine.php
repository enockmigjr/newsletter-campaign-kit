<?php
/**
 * Standalone dynamic segment tests.
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}
function absint( $value ) {
	return abs( (int) $value );
}
function sanitize_text_field( $value ) {
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( sanitize_text_field( $value ) ) );
}
function __( $message ) {
	return $message;
}
function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function newsletter_campaign_kit_get_subscriber_lists_table() {
	return 'wp_newsletter_campaign_subscriber_lists';
}
function newsletter_campaign_kit_get_subscriber_tags_table() {
	return 'wp_newsletter_campaign_subscriber_tags';
}

class WP_Error {
	public $code;

	public function __construct( $code ) {
		$this->code = $code;
	}
}

require dirname( __DIR__ ) . '/inc/segment-engine.php';

function newsletter_segment_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$empty = newsletter_campaign_kit_normalize_segment_rules( array() );
newsletter_segment_assert( is_wp_error( $empty ) && 'newsletter_empty_segment' === $empty->code, 'Empty segments must be rejected.' );

$rules = newsletter_campaign_kit_normalize_segment_rules(
	array(
		'list_ids'       => array( '4', 4, -2, 0 ),
		'tag_ids'        => array( 9 ),
		'sources'        => 'Front_Footer, checkout, <script>',
		'created_after'  => '2026-01-01',
		'created_before' => '2026-12-31',
	)
);
newsletter_segment_assert( ! is_wp_error( $rules ), 'Valid rules must be accepted.' );
newsletter_segment_assert( array( 4, 2 ) === $rules['list_ids'], 'IDs must be positive and deduplicated.' );
newsletter_segment_assert( array( 'front_footer', 'checkout', 'script' ) === $rules['sources'], 'Sources must be normalized to safe keys.' );
newsletter_segment_assert( '2026-01-01 00:00:00' === $rules['created_after'], 'Start date must be UTC normalized.' );
newsletter_segment_assert( '2026-12-31 23:59:59' === $rules['created_before'], 'End date must include the full UTC day.' );

$round_trip = newsletter_campaign_kit_normalize_segment_rules( $rules );
newsletter_segment_assert( ! is_wp_error( $round_trip ) && $rules === $round_trip, 'Persisted UTC rules must survive normalization unchanged.' );

$params = array();
$sql    = newsletter_campaign_kit_build_segment_conditions( $rules, 'all', $params );
newsletter_segment_assert( false !== strpos( $sql, 'EXISTS' ) && false !== strpos( $sql, 's.source IN' ), 'SQL must use whitelisted condition templates.' );
newsletter_segment_assert( array( 4, 2, 9, 'front_footer', 'checkout', 'script', '2026-01-01 00:00:00', '2026-12-31 23:59:59' ) === $params, 'Every dynamic value must remain a prepare parameter.' );

$range = newsletter_campaign_kit_normalize_segment_rules(
	array(
		'created_after'  => '2026-12-31',
		'created_before' => '2026-01-01',
	)
);
newsletter_segment_assert( is_wp_error( $range ) && 'newsletter_invalid_segment_range' === $range->code, 'Invalid date ranges must be rejected.' );

echo "Segment engine tests passed.\n";
