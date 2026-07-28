<?php
/**
 * Dynamic WordPress content sources for campaign bodies.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return supported campaign content source modes. */
function newsletter_campaign_kit_get_content_source_types() {
	return array(
		'manual'         => __( 'Manual editorial content', 'newsletter-campaign-kit' ),
		'latest_posts'   => __( 'Latest published content', 'newsletter-campaign-kit' ),
		'recent_window'  => __( 'Content published in a recent time window', 'newsletter-campaign-kit' ),
		'category_posts' => __( 'Latest articles from one category', 'newsletter-campaign-kit' ),
		'selected_posts' => __( 'A hand-picked content selection', 'newsletter-campaign-kit' ),
	);
}

/** Return public post types administrators may use as campaign content. */
function newsletter_campaign_kit_get_content_source_post_types() {
	$types = array( 'post' );
	if ( post_type_exists( 'media_item' ) ) {
		$types[] = 'media_item';
	}

	$types = apply_filters( 'newsletter_campaign_kit_content_source_post_types', $types );
	$types = array_values(
		array_filter(
			array_unique( array_map( 'sanitize_key', (array) $types ) ),
			static function ( $post_type ) {
				$object = get_post_type_object( $post_type );
				return $object && $object->public && 'attachment' !== $post_type;
			}
		)
	);

	return $types ?: array( 'post' );
}

/** Return labels for all allowed campaign source post types. */
function newsletter_campaign_kit_get_content_source_post_type_labels() {
	$labels = array();
	foreach ( newsletter_campaign_kit_get_content_source_post_types() as $post_type ) {
		$object               = get_post_type_object( $post_type );
		$labels[ $post_type ] = $object && $object->labels ? $object->labels->name : $post_type;
	}

	return $labels;
}

/** Validate and serialize content source options. */
function newsletter_campaign_kit_prepare_content_source( $input ) {
	$types       = newsletter_campaign_kit_get_content_source_types();
	$source_type = sanitize_key( $input['source_type'] ?? 'manual' );
	if ( ! isset( $types[ $source_type ] ) ) {
		return new WP_Error( 'newsletter_invalid_content_source', __( 'The campaign content source is invalid.', 'newsletter-campaign-kit' ) );
	}

	$post_type = sanitize_key( $input['source_post_type'] ?? 'post' );
	if ( ! in_array( $post_type, newsletter_campaign_kit_get_content_source_post_types(), true ) ) {
		return new WP_Error( 'newsletter_invalid_content_source_post_type', __( 'The selected content type is unavailable.', 'newsletter-campaign-kit' ) );
	}

	$config = array(
		'post_type'    => $post_type,
		'post_count'   => max( 1, min( 20, absint( $input['source_post_count'] ?? 5 ) ) ),
		'window_hours' => max( 1, min( 720, absint( $input['source_window_hours'] ?? 24 ) ) ),
		'category_id'  => absint( $input['source_category_id'] ?? 0 ),
		'post_ids'     => array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['source_post_ids'] ?? array() ) ) ) ) ),
	);
	if ( 'category_posts' === $source_type && ( 'post' !== $post_type || ! $config['category_id'] || ! term_exists( $config['category_id'], 'category' ) ) ) {
		return new WP_Error( 'newsletter_invalid_source_category', __( 'Choose an available article category.', 'newsletter-campaign-kit' ) );
	}
	if ( 'selected_posts' === $source_type && empty( $config['post_ids'] ) ) {
		return new WP_Error( 'newsletter_empty_source_selection', __( 'Choose at least one published article.', 'newsletter-campaign-kit' ) );
	}

	return array(
		'source_type'   => $source_type,
		'source_config' => wp_json_encode( $config ),
	);
}

/** Read normalized source options from a persisted campaign. */
function newsletter_campaign_kit_get_campaign_source_config( $campaign ) {
	$config = json_decode( (string) ( $campaign['source_config'] ?? '' ), true );
	$config = is_array( $config ) ? $config : array();

	return wp_parse_args(
		$config,
		array(
			'post_type'    => 'post',
			'post_count'   => 5,
			'window_hours' => 24,
			'category_id'  => 0,
			'post_ids'     => array(),
		)
	);
}

/** Resolve the articles selected by one campaign source. */
function newsletter_campaign_kit_get_campaign_source_posts( $campaign ) {
	$source_type = sanitize_key( $campaign['source_type'] ?? 'manual' );
	if ( 'manual' === $source_type ) {
		return array();
	}

	$config = newsletter_campaign_kit_get_campaign_source_config( $campaign );
	$args   = array(
		'post_type'           => in_array( $config['post_type'], newsletter_campaign_kit_get_content_source_post_types(), true ) ? $config['post_type'] : 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, min( 20, absint( $config['post_count'] ) ) ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'orderby'             => 'date',
		'order'               => 'DESC',
	);
	if ( 'media_item' === $args['post_type'] ) {
		$args['meta_query'] = array(
			'relation' => 'OR',
			array(
				'key'     => 'is_protected',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => 'is_protected',
				'value' => '0',
			),
		);
	}
	if ( 'recent_window' === $source_type ) {
		$args['date_query'] = array(
			array(
				'after'     => gmdate( 'Y-m-d H:i:s', time() - max( 1, absint( $config['window_hours'] ) ) * HOUR_IN_SECONDS ),
				'inclusive' => true,
				'column'    => 'post_date_gmt',
			),
		);
	} elseif ( 'category_posts' === $source_type ) {
		$args['cat'] = absint( $config['category_id'] );
	} elseif ( 'selected_posts' === $source_type ) {
		$args['post__in']       = array_values( array_filter( array_map( 'absint', (array) $config['post_ids'] ) ) );
		$args['orderby']        = 'post__in';
		$args['posts_per_page'] = min( 20, count( $args['post__in'] ) );
	}

	return get_posts( apply_filters( 'newsletter_campaign_kit_content_source_query', $args, $campaign ) );
}

/** Render an email-safe editorial article selection. */
function newsletter_campaign_kit_render_campaign_source_posts( $posts ) {
	if ( empty( $posts ) ) {
		return '';
	}

	$html = '<section><h2>' . esc_html__( 'Selected stories and works', 'newsletter-campaign-kit' ) . '</h2>';
	foreach ( $posts as $post ) {
		$title   = get_the_title( $post );
		$url     = get_permalink( $post );
		$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 34 );
		$image   = get_the_post_thumbnail_url( $post, 'medium_large' );
		$label   = 'media_item' === $post->post_type ? __( 'View the work', 'newsletter-campaign-kit' ) : __( 'Read the complete article', 'newsletter-campaign-kit' );
		$html   .= '<article style="margin:28px 0;padding-top:24px;border-top:1px solid #e9e6df">';
		if ( $image ) {
			$html .= '<p><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $image ) . '" alt="" width="576" style="display:block;width:100%;height:auto;border:0"></a></p>';
		}
		$html .= '<p style="font-size:12px;color:#686d68">' . esc_html( get_the_date( '', $post ) ) . '</p>';
		$html .= '<h3><a href="' . esc_url( $url ) . '" style="color:#171a17">' . esc_html( $title ) . '</a></h3>';
		$html .= '<p>' . esc_html( $excerpt ) . '</p>';
		$html .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p></article>';
	}

	return $html . '</section>';
}

/** Resolve the final body once per campaign during one request. */
function newsletter_campaign_kit_resolve_dynamic_campaign_body( $campaign ) {
	static $cache = array();

	$cache_key = absint( $campaign['id'] ?? 0 ) . ':' . (string) ( $campaign['updated_at'] ?? '' );
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}
	$base  = (string) ( $campaign['body'] ?? '' );
	$posts = newsletter_campaign_kit_get_campaign_source_posts( $campaign );
	$feed  = newsletter_campaign_kit_render_campaign_source_posts( $posts );

	$cache[ $cache_key ] = trim( $base . $feed );
	return $cache[ $cache_key ];
}
