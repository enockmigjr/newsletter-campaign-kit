<?php
/**
 * Convert published WordPress articles into newsletter campaigns.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build campaign content from the canonical article without duplicating editorial work. */
function newsletter_campaign_kit_get_post_campaign_input( $post_id ) {
	$post = get_post( absint( $post_id ) );
	if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return new WP_Error( 'newsletter_post_unavailable', __( 'The published article is unavailable.', 'newsletter-campaign-kit' ) );
	}

	$title      = get_the_title( $post );
	$permalink  = get_permalink( $post );
	$excerpt    = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 32 );
	$content    = do_shortcode( do_blocks( $post->post_content ) );
	$image      = get_the_post_thumbnail_url( $post, 'large' );
	$image_html = $image ? '<p><a href="' . esc_url( $permalink ) . '"><img src="' . esc_url( $image ) . '" alt="" style="display:block;width:100%;height:auto;"></a></p>' : '';
	$html       = $image_html . '<h1>' . esc_html( $title ) . '</h1>' . wpautop( wp_kses_post( $content ) ) . '<p><a href="' . esc_url( $permalink ) . '">' . esc_html__( 'Read the article on PhotoVault', 'newsletter-campaign-kit' ) . '</a></p>';
	$text       = $title . "\n\n" . wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) . "\n\n" . $permalink;
	$settings   = newsletter_campaign_kit_get_provider_settings();

	return array(
		'title'           => sprintf( __( 'Article: %s', 'newsletter-campaign-kit' ), $title ),
		'subject'         => $title,
		'preview_text'    => $excerpt,
		'html_body'       => $html,
		'text_body'       => $text,
		'target_audience' => 'all',
		'topic_id'        => absint( $settings['post_newsletter_topic_id'] ),
	);
}

/** Create one idempotent campaign for an article. */
function newsletter_campaign_kit_create_post_campaign( $post_id, $actor_user_id = 0 ) {
	$post_id     = absint( $post_id );
	$campaign_id = absint( get_post_meta( $post_id, '_newsletter_campaign_kit_campaign_id', true ) );
	if ( $campaign_id && newsletter_campaign_kit_get_campaign( $campaign_id ) ) {
		return $campaign_id;
	}

	$input = newsletter_campaign_kit_get_post_campaign_input( $post_id );
	if ( is_wp_error( $input ) ) {
		return $input;
	}
	$campaign_id = newsletter_campaign_kit_create_campaign( $input, $actor_user_id );
	if ( ! is_wp_error( $campaign_id ) ) {
		update_post_meta( $post_id, '_newsletter_campaign_kit_campaign_id', $campaign_id );
		newsletter_campaign_kit_log_event( 'newsletter_post_campaign_created', 'success', 0, array( 'post_id' => $post_id, 'campaign_id' => $campaign_id ) );
	}

	return $campaign_id;
}

/** Queue a generated campaign through the immutable-audience delivery path. */
function newsletter_campaign_kit_queue_post_campaign( $campaign_id, $actor_user_id = 0 ) {
	global $wpdb;
	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	if ( ! $campaign || 'draft' !== $campaign['status'] ) {
		return new WP_Error( 'newsletter_post_campaign_invalid', __( 'The article campaign is not a draft.', 'newsletter-campaign-kit' ) );
	}
	$updated = $wpdb->update( newsletter_campaign_kit_get_campaigns_table(), array( 'status' => 'ready', 'updated_by' => absint( $actor_user_id ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $campaign_id ), 'status' => 'draft' ), array( '%s', '%d', '%s' ), array( '%d', '%s' ) );
	if ( false === $updated ) {
		return new WP_Error( 'newsletter_post_campaign_ready_failed', __( 'The article campaign could not be prepared.', 'newsletter-campaign-kit' ) );
	}
	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	$review   = newsletter_campaign_kit_prepare_campaign_delivery_review( $campaign );
	if ( is_wp_error( $review ) ) {
		return $review;
	}

	return newsletter_campaign_kit_start_confirmed_campaign_delivery( $campaign_id, $campaign['title'], $review['fingerprint'], $actor_user_id );
}

/** Apply the configured workflow only on the first transition to published. */
function newsletter_campaign_kit_handle_published_post( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type || wp_is_post_revision( $post ) ) {
		return;
	}
	$settings = newsletter_campaign_kit_get_provider_settings();
	if ( 'disabled' === $settings['post_newsletter_mode'] ) {
		return;
	}
	$campaign_id = newsletter_campaign_kit_create_post_campaign( $post->ID, get_current_user_id() );
	if ( 'send' === $settings['post_newsletter_mode'] && ! is_wp_error( $campaign_id ) ) {
		$result = newsletter_campaign_kit_queue_post_campaign( $campaign_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			newsletter_campaign_kit_log_event( 'newsletter_post_campaign_queue_failed', 'failure', 0, array( 'post_id' => $post->ID, 'campaign_id' => $campaign_id, 'reason' => $result->get_error_code() ) );
		}
	}
}
add_action( 'transition_post_status', 'newsletter_campaign_kit_handle_published_post', 20, 3 );

/** Add a compact manual action to the article editor. */
function newsletter_campaign_kit_register_post_campaign_meta_box() {
	if ( current_user_can( 'newsletter_create_campaigns' ) ) {
		add_meta_box( 'newsletter-campaign-kit-post', __( 'Newsletter campaign', 'newsletter-campaign-kit' ), 'newsletter_campaign_kit_render_post_campaign_meta_box', 'post', 'side', 'default' );
	}
}
add_action( 'add_meta_boxes_post', 'newsletter_campaign_kit_register_post_campaign_meta_box' );

function newsletter_campaign_kit_render_post_campaign_meta_box( $post ) {
	$campaign_id = absint( get_post_meta( $post->ID, '_newsletter_campaign_kit_campaign_id', true ) );
	if ( $campaign_id ) {
		echo '<p>' . esc_html__( 'A campaign already exists for this article.', 'newsletter-campaign-kit' ) . '</p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&campaign_id=' . $campaign_id ) ) . '">' . esc_html__( 'Open campaign', 'newsletter-campaign-kit' ) . '</a>';
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		echo '<p>' . esc_html__( 'Publish the article before creating its newsletter draft.', 'newsletter-campaign-kit' ) . '</p>';
		return;
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_create_post_campaign&post_id=' . absint( $post->ID ) ), 'newsletter_campaign_kit_create_post_campaign_' . absint( $post->ID ) );
	echo '<p>' . esc_html__( 'Reuse the title, featured image, excerpt and article content.', 'newsletter-campaign-kit' ) . '</p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Create newsletter draft', 'newsletter-campaign-kit' ) . '</a>';
}

function newsletter_campaign_kit_handle_manual_post_campaign() {
	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
	if ( ! current_user_can( 'newsletter_create_campaigns' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( esc_html__( 'You are not allowed to create this campaign.', 'newsletter-campaign-kit' ) );
	}
	check_admin_referer( 'newsletter_campaign_kit_create_post_campaign_' . $post_id );
	$result = newsletter_campaign_kit_create_post_campaign( $post_id, get_current_user_id() );
	wp_safe_redirect( is_wp_error( $result ) ? get_edit_post_link( $post_id, 'url' ) : admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&campaign_id=' . absint( $result ) . '&created=post' ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_post_campaign', 'newsletter_campaign_kit_handle_manual_post_campaign' );
