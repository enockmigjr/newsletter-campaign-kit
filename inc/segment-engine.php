<?php
/**
 * Dynamic audiences and campaign topics.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_dynamic_tables_exist() {
	return newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_segments_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_topics_table() );
}

function newsletter_campaign_kit_normalize_id_list( $values, $limit = 20 ) {
	$values = is_array( $values ) ? $values : array();
	$ids    = array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );

	return array_slice( $ids, 0, max( 1, absint( $limit ) ) );
}

function newsletter_campaign_kit_normalize_segment_date( $value, $end_of_day = false ) {
	$value = sanitize_text_field( $value );
	if ( '' === $value ) {
		return '';
	}

	$is_persisted = (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value );
	$format       = $is_persisted ? '!Y-m-d H:i:s' : '!Y-m-d';
	$timezone     = $is_persisted ? new DateTimeZone( 'UTC' ) : wp_timezone();
	$date         = DateTimeImmutable::createFromFormat( $format, $value, $timezone );
	$errors = DateTimeImmutable::getLastErrors();
	if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
		return new WP_Error( 'newsletter_invalid_segment_date', __( 'A segment date is invalid.', 'newsletter-campaign-kit' ) );
	}

	if ( $end_of_day && ! $is_persisted ) {
		$date = $date->setTime( 23, 59, 59 );
	}

	return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function newsletter_campaign_kit_segment_rule_records_exist( $rules ) {
	global $wpdb;

	foreach ( $rules['list_ids'] as $list_id ) {
		if ( ! newsletter_campaign_kit_record_is_active( newsletter_campaign_kit_get_lists_table(), $list_id ) ) {
			return false;
		}
	}
	foreach ( $rules['tag_ids'] as $tag_id ) {
		$table = newsletter_campaign_kit_get_tags_table();
		if ( ! newsletter_campaign_kit_table_exists( $table ) || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d LIMIT 1", $tag_id ) ) ) {
			return false;
		}
	}

	return true;
}

function newsletter_campaign_kit_normalize_segment_rules( $input ) {
	$input   = is_array( $input ) ? $input : array();
	$sources = isset( $input['sources'] ) ? $input['sources'] : array();
	if ( is_string( $sources ) ) {
		$sources = preg_split( '/[\s,]+/', $sources, -1, PREG_SPLIT_NO_EMPTY );
	}
	$sources = is_array( $sources ) ? $sources : array();
	$sources = array_values( array_unique( array_filter( array_map( 'sanitize_key', $sources ) ) ) );
	$sources = array_slice( $sources, 0, 10 );

	$after  = newsletter_campaign_kit_normalize_segment_date( isset( $input['created_after'] ) ? $input['created_after'] : '' );
	$before = newsletter_campaign_kit_normalize_segment_date( isset( $input['created_before'] ) ? $input['created_before'] : '', true );
	if ( is_wp_error( $after ) ) {
		return $after;
	}
	if ( is_wp_error( $before ) ) {
		return $before;
	}
	if ( $after && $before && $after > $before ) {
		return new WP_Error( 'newsletter_invalid_segment_range', __( 'The segment start date must precede its end date.', 'newsletter-campaign-kit' ) );
	}

	$rules = array(
		'list_ids'       => newsletter_campaign_kit_normalize_id_list( isset( $input['list_ids'] ) ? $input['list_ids'] : array() ),
		'tag_ids'        => newsletter_campaign_kit_normalize_id_list( isset( $input['tag_ids'] ) ? $input['tag_ids'] : array() ),
		'sources'        => $sources,
		'created_after'  => $after,
		'created_before' => $before,
	);

	if ( empty( $rules['list_ids'] ) && empty( $rules['tag_ids'] ) && empty( $rules['sources'] ) && ! $after && ! $before ) {
		return new WP_Error( 'newsletter_empty_segment', __( 'A segment must contain at least one condition.', 'newsletter-campaign-kit' ) );
	}

	return $rules;
}

function newsletter_campaign_kit_get_segments() {
	global $wpdb;

	if ( ! newsletter_campaign_kit_dynamic_tables_exist() ) {
		return array();
	}

	$table = newsletter_campaign_kit_get_segments_table();
	return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY updated_at DESC", ARRAY_A );
}

function newsletter_campaign_kit_get_segment( $segment_id ) {
	global $wpdb;

	$segment_id = absint( $segment_id );
	if ( ! $segment_id || ! newsletter_campaign_kit_dynamic_tables_exist() ) {
		return null;
	}

	$table = newsletter_campaign_kit_get_segments_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'active' LIMIT 1", $segment_id ), ARRAY_A );
}

function newsletter_campaign_kit_get_topics() {
	global $wpdb;

	if ( ! newsletter_campaign_kit_dynamic_tables_exist() ) {
		return array();
	}

	$table = newsletter_campaign_kit_get_topics_table();
	return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY name ASC", ARRAY_A );
}

function newsletter_campaign_kit_record_is_active( $table, $record_id ) {
	global $wpdb;

	$record_id = absint( $record_id );
	if ( ! $record_id || ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND status = 'active' LIMIT 1", $record_id ) );
}

function newsletter_campaign_kit_build_segment_conditions( $rules, $match_type, &$params ) {
	global $wpdb;

	$conditions = array();
	$params     = array();
	$lists_map  = newsletter_campaign_kit_get_subscriber_lists_table();
	$tags_map   = newsletter_campaign_kit_get_subscriber_tags_table();

	foreach ( $rules['list_ids'] as $list_id ) {
		$conditions[] = "EXISTS (SELECT 1 FROM {$lists_map} nck_sl WHERE nck_sl.subscriber_id = s.id AND nck_sl.list_id = %d)";
		$params[]     = $list_id;
	}
	foreach ( $rules['tag_ids'] as $tag_id ) {
		$conditions[] = "EXISTS (SELECT 1 FROM {$tags_map} nck_st WHERE nck_st.subscriber_id = s.id AND nck_st.tag_id = %d)";
		$params[]     = $tag_id;
	}
	if ( ! empty( $rules['sources'] ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $rules['sources'] ), '%s' ) );
		$conditions[] = "s.source IN ({$placeholders})";
		$params       = array_merge( $params, $rules['sources'] );
	}
	if ( ! empty( $rules['created_after'] ) ) {
		$conditions[] = 's.created_at >= %s';
		$params[]     = $rules['created_after'];
	}
	if ( ! empty( $rules['created_before'] ) ) {
		$conditions[] = 's.created_at <= %s';
		$params[]     = $rules['created_before'];
	}

	$glue = 'any' === $match_type ? ' OR ' : ' AND ';
	return '(' . implode( $glue, $conditions ) . ')';
}

function newsletter_campaign_kit_get_segment_recipients( $segment_id ) {
	global $wpdb;

	$segment = newsletter_campaign_kit_get_segment( $segment_id );
	if ( ! $segment ) {
		return array();
	}

	$rules = json_decode( $segment['rules'], true );
	$rules = newsletter_campaign_kit_normalize_segment_rules( $rules );
	if ( is_wp_error( $rules ) ) {
		return array();
	}

	$params     = array();
	$conditions = newsletter_campaign_kit_build_segment_conditions( $rules, $segment['match_type'], $params );
	$table      = newsletter_campaign_kit_get_subscribers_table();
	$sql        = "SELECT DISTINCT s.id, s.email, s.email_hash, s.unsubscribe_token FROM {$table} s WHERE s.status = %s AND {$conditions} ORDER BY s.id ASC";
	array_unshift( $params, 'subscribed' );

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

function newsletter_campaign_kit_handle_create_segment() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to create newsletter segments.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_segment' );

	$name        = isset( $_POST['segment_name'] ) ? sanitize_text_field( wp_unslash( $_POST['segment_name'] ) ) : '';
	$description = isset( $_POST['segment_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['segment_description'] ) ) : '';
	$match_type  = isset( $_POST['segment_match_type'] ) && 'any' === sanitize_key( wp_unslash( $_POST['segment_match_type'] ) ) ? 'any' : 'all';
	$rules       = newsletter_campaign_kit_normalize_segment_rules(
		array(
			'list_ids'       => isset( $_POST['segment_list_ids'] ) ? wp_unslash( $_POST['segment_list_ids'] ) : array(),
			'tag_ids'        => isset( $_POST['segment_tag_ids'] ) ? wp_unslash( $_POST['segment_tag_ids'] ) : array(),
			'sources'        => isset( $_POST['segment_sources'] ) ? wp_unslash( $_POST['segment_sources'] ) : '',
			'created_after'  => isset( $_POST['segment_created_after'] ) ? wp_unslash( $_POST['segment_created_after'] ) : '',
			'created_before' => isset( $_POST['segment_created_before'] ) ? wp_unslash( $_POST['segment_created_before'] ) : '',
		)
	);

	if ( '' === $name || is_wp_error( $rules ) || ! newsletter_campaign_kit_dynamic_tables_exist() || ! newsletter_campaign_kit_segment_rule_records_exist( $rules ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&segment=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_segments_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'name'        => $name,
			'slug'        => newsletter_campaign_kit_generate_unique_slug( $table, $name ),
			'description' => $description,
			'match_type'  => $match_type,
			'rules'       => wp_json_encode( $rules ),
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_segment_create_failed' : 'newsletter_segment_created', false === $ok ? 'failure' : 'success', 0, array( 'name' => $name ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&segment=' . ( false === $ok ? 'failed' : 'created' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_segment', 'newsletter_campaign_kit_handle_create_segment' );

function newsletter_campaign_kit_handle_create_topic() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to create newsletter topics.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_topic' );
	$name        = isset( $_POST['topic_name'] ) ? sanitize_text_field( wp_unslash( $_POST['topic_name'] ) ) : '';
	$description = isset( $_POST['topic_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topic_description'] ) ) : '';
	$color       = isset( $_POST['topic_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['topic_color'] ) ) : '';
	if ( '' === $name || ! newsletter_campaign_kit_dynamic_tables_exist() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&topic=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_topics_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'name'        => $name,
			'slug'        => newsletter_campaign_kit_generate_unique_slug( $table, $name ),
			'description' => $description,
			'color'       => $color ? $color : '#111827',
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_topic_create_failed' : 'newsletter_topic_created', false === $ok ? 'failure' : 'success', 0, array( 'name' => $name ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&topic=' . ( false === $ok ? 'failed' : 'created' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_topic', 'newsletter_campaign_kit_handle_create_topic' );

function newsletter_campaign_kit_handle_update_assignment() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to update newsletter assignments.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_update_assignment' );

	$subscriber_id = isset( $_POST['subscriber_id'] ) ? absint( $_POST['subscriber_id'] ) : 0;
	$audience      = isset( $_POST['audience'] ) ? sanitize_text_field( wp_unslash( $_POST['audience'] ) ) : '';
	$operation     = isset( $_POST['assignment_operation'] ) ? sanitize_key( wp_unslash( $_POST['assignment_operation'] ) ) : '';
	if ( ! $subscriber_id || ! in_array( $operation, array( 'add', 'remove' ), true ) || ! preg_match( '/^(list|tag):(\d+)$/', $audience, $matches ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&assignment=invalid' ) );
		exit;
	}

	global $wpdb;
	$subscriber_exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . newsletter_campaign_kit_get_subscribers_table() . ' WHERE id = %d LIMIT 1', $subscriber_id ) );
	$audience_id       = absint( $matches[2] );
	$is_list           = 'list' === $matches[1];
	$audience_table    = $is_list ? newsletter_campaign_kit_get_lists_table() : newsletter_campaign_kit_get_tags_table();
	$audience_exists   = $is_list
		? newsletter_campaign_kit_record_is_active( $audience_table, $audience_id )
		: (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$audience_table} WHERE id = %d LIMIT 1", $audience_id ) );

	if ( ! $subscriber_exists || ! $audience_exists ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&assignment=invalid' ) );
		exit;
	}

	$map_table = $is_list ? newsletter_campaign_kit_get_subscriber_lists_table() : newsletter_campaign_kit_get_subscriber_tags_table();
	$key       = $is_list ? 'list_id' : 'tag_id';
	if ( 'remove' === $operation ) {
		$ok = false !== $wpdb->delete( $map_table, array( 'subscriber_id' => $subscriber_id, $key => $audience_id ), array( '%d', '%d' ) );
	} else {
		$ok = $is_list
			? newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_id, $audience_id )
			: newsletter_campaign_kit_assign_subscriber_to_tag( $subscriber_id, $audience_id );
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event(
			$ok ? 'newsletter_assignment_updated' : 'newsletter_assignment_failed',
			$ok ? 'success' : 'failure',
			$subscriber_id,
			array( 'audience_type' => $matches[1], 'audience_id' => $audience_id, 'operation' => $operation )
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&assignment=' . ( $ok ? 'success' : 'failed' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_update_assignment', 'newsletter_campaign_kit_handle_update_assignment' );
