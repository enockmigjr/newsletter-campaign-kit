<?php
/**
 * Standalone campaign report rate tests.
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}
function absint( $value ) {
	return abs( (int) $value );
}

require dirname( __DIR__ ) . '/inc/reports.php';

function newsletter_report_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}. Expected {$expected}, got {$actual}.\n" );
		exit( 1 );
	}
}

$rates = newsletter_campaign_kit_calculate_report_rates(
	array(
		'queued_total'            => 200,
		'sent_total'              => 180,
		'unique_open_total'       => 90,
		'unique_click_total'      => 36,
		'unique_conversion_total' => 9,
	)
);
newsletter_report_assert_same( 90.0, $rates['delivery_rate'], 'Delivery rate' );
newsletter_report_assert_same( 50.0, $rates['open_rate'], 'Open rate' );
newsletter_report_assert_same( 20.0, $rates['click_rate'], 'Click rate' );
newsletter_report_assert_same( 40.0, $rates['click_to_open_rate'], 'Click-to-open rate' );
newsletter_report_assert_same( 25.0, $rates['conversion_rate'], 'Conversion rate' );

$empty = newsletter_campaign_kit_calculate_report_rates( array() );
foreach ( $empty as $name => $value ) {
	newsletter_report_assert_same( 0, $value, 'Zero denominator for ' . $name );
}

$rounded = newsletter_campaign_kit_calculate_report_rates(
	array(
		'queued_total'            => 3,
		'sent_total'              => 2,
		'unique_open_total'       => 1,
		'unique_click_total'      => 1,
		'unique_conversion_total' => 1,
	)
);
newsletter_report_assert_same( 66.7, $rounded['delivery_rate'], 'One-decimal rounding' );

echo "Campaign report rate tests passed.\n";
