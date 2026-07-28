<?php
/**
 * REST transport for public newsletter workflows.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Submit a public newsletter subscription without a page reload. */
function newsletter_campaign_kit_rest_subscribe( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	$params = is_array( $params ) ? $params : $request->get_params();
	$nonce  = sanitize_text_field( $params['newsletter_campaign_kit_nonce'] ?? '' );

	if ( ! wp_verify_nonce( $nonce, 'newsletter_campaign_kit_subscribe' ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => __( 'Security verification failed. Refresh the page and try again.', 'newsletter-campaign-kit' ),
				'data'    => array(),
				'errors'  => array(),
			),
			403
		);
	}

	$result = newsletter_campaign_kit_process_subscription( $params );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $result->get_error_message(),
				'data'    => array(),
				'errors'  => array(),
			),
			422
		);
	}

	$message = 'confirmation_required' === $result['status']
		? __( 'Check your inbox to confirm your subscription.', 'newsletter-campaign-kit' )
		: __( 'Your subscription is active.', 'newsletter-campaign-kit' );

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => $message,
			'data'    => $result,
			'errors'  => array(),
		),
		201
	);
}

/** Register public newsletter endpoints. */
function newsletter_campaign_kit_register_public_rest_routes() {
	register_rest_route(
		'newsletter-campaign-kit/v1',
		'/subscriptions',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'newsletter_campaign_kit_rest_subscribe',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'newsletter_campaign_kit_register_public_rest_routes' );
