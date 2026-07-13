<?php
/**
 * Standalone unsubscribe protocol tests.
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}
function add_filter() {}
function sanitize_text_field( $value ) {
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}
function wp_generate_password() {
	static $counter = 0;
	++$counter;

	return str_repeat( (string) $counter, 64 );
}
function wp_salt() {
	return 'test-salt';
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function admin_url() {
	return 'https://example.test/wp-admin/admin-post.php';
}
function wp_parse_url( $url, $component ) {
	return parse_url( $url, $component );
}
function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}

require dirname( __DIR__ ) . '/inc/subscribers.php';
require dirname( __DIR__ ) . '/inc/providers.php';

function newsletter_unsubscribe_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$email_hash = str_repeat( 'b', 64 );
$token      = newsletter_campaign_kit_create_unsubscribe_token( $email_hash );
$next_token = newsletter_campaign_kit_create_unsubscribe_token( $email_hash );

newsletter_unsubscribe_assert( newsletter_campaign_kit_is_valid_unsubscribe_token( $token ), 'Generated tokens must be valid opaque capabilities.' );
newsletter_unsubscribe_assert( $token !== $next_token, 'A reactivation must rotate the unsubscribe token.' );
newsletter_unsubscribe_assert( ! newsletter_campaign_kit_is_valid_unsubscribe_token( '../invalid' ), 'Malformed tokens must be rejected.' );
newsletter_unsubscribe_assert( newsletter_campaign_kit_is_one_click_request( 'POST', 'One-Click' ), 'The RFC 8058 POST body must be accepted.' );
newsletter_unsubscribe_assert( ! newsletter_campaign_kit_is_one_click_request( 'GET', 'One-Click' ), 'GET requests must not trigger the automated protocol path.' );

$headers = newsletter_campaign_kit_get_one_click_headers(
	array( 'unsubscribe_token' => $token ),
	array( 'one_click_enabled' => true, 'dkim_confirmed' => true )
);
newsletter_unsubscribe_assert( 2 === count( $headers ), 'A secure configured sender must emit both RFC 8058 headers.' );
newsletter_unsubscribe_assert( false !== strpos( $headers[0], 'https://example.test/' ), 'List-Unsubscribe must contain an HTTPS endpoint.' );
newsletter_unsubscribe_assert( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' === $headers[1], 'The one-click header value must be exact.' );
newsletter_unsubscribe_assert( array() === newsletter_campaign_kit_get_one_click_headers( array( 'unsubscribe_token' => $token ), array() ), 'Headers must remain disabled until explicitly configured.' );

echo "Unsubscribe protocol tests passed.\n";
