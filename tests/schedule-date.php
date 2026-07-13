<?php
/**
 * Standalone schedule date validation tests.
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action() {}
function sanitize_text_field( $value ) {
	return is_scalar( $value ) ? trim( (string) $value ) : '';
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

class WP_Error {
	public $code;

	public function __construct( $code ) {
		$this->code = $code;
	}
}

require dirname( __DIR__ ) . '/inc/campaigns.php';

function newsletter_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$invalid = newsletter_campaign_kit_parse_schedule_datetime( '2026-02-30T12:00' );
newsletter_test_assert( is_wp_error( $invalid ), 'An impossible date must be rejected.' );

$past = newsletter_campaign_kit_parse_schedule_datetime( '2020-01-01T00:00' );
newsletter_test_assert( is_wp_error( $past ), 'A past date must be rejected.' );

$future_value = gmdate( 'Y-m-d\\TH:i', time() + 300 );
$future       = newsletter_campaign_kit_parse_schedule_datetime( $future_value );
newsletter_test_assert( ! is_wp_error( $future ), 'A future date must be accepted.' );
newsletter_test_assert( $future === gmdate( 'Y-m-d H:i:00', time() + 300 ), 'The accepted date must be normalized to UTC.' );

echo "Schedule date tests passed.\n";
