<?php
/**
 * Clean public URLs for newsletter capability links.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Match the current request against one public newsletter path. */
function newsletter_campaign_kit_is_public_path( $path ) {
	$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$target_path  = wp_parse_url( home_url( $path ), PHP_URL_PATH );

	return untrailingslashit( (string) $request_path ) === untrailingslashit( (string) $target_path );
}

/** Dispatch clean public newsletter URLs before a theme 404 is rendered. */
function newsletter_campaign_kit_dispatch_public_routes() {
	if ( newsletter_campaign_kit_is_public_path( '/newsletter/subscribe/' ) ) {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		newsletter_campaign_kit_handle_subscribe();
	}

	if ( newsletter_campaign_kit_is_public_path( '/newsletter/confirm/' ) ) {
		newsletter_campaign_kit_handle_subscription_confirmation();
	}

	if ( newsletter_campaign_kit_is_public_path( '/newsletter/preferences/' ) ) {
		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
			$intent = sanitize_key( wp_unslash( $_POST['newsletter_intent'] ?? '' ) );
			if ( 'update_preferences' === $intent ) {
				newsletter_campaign_kit_handle_update_preferences();
			}
			if ( 'confirm_unsubscribe' === $intent ) {
				newsletter_campaign_kit_handle_confirm_unsubscribe();
			}
		}
		newsletter_campaign_kit_handle_preferences();
	}

	if ( newsletter_campaign_kit_is_public_path( '/newsletter/unsubscribe/' ) ) {
		newsletter_campaign_kit_handle_unsubscribe();
	}
}
add_action( 'template_redirect', 'newsletter_campaign_kit_dispatch_public_routes', 0 );
