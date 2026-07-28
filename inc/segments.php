<?php
/**
 * Lists and tags for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_segments_tables_exist() {
	return newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_lists_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_tags_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscriber_lists_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscriber_tags_table() );
}

function newsletter_campaign_kit_generate_unique_slug( $table_name, $name ) {
	global $wpdb;

	$base = sanitize_title( $name );
	$base = '' !== $base ? $base : 'segment';
	$slug = $base;
	$i    = 2;

	while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE slug = %s LIMIT 1", $slug ) ) ) {
		$slug = $base . '-' . $i;
		$i++;
	}

	return $slug;
}

function newsletter_campaign_kit_get_default_list_id() {
	global $wpdb;

	$table = newsletter_campaign_kit_get_lists_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return 0;
	}

	$list_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", 'editorial-updates' ) );
	if ( $list_id ) {
		return $list_id;
	}

	$now = current_time( 'mysql', true );
	$wpdb->insert(
		$table,
		array(
			'name'        => __( 'Editorial updates', 'newsletter-campaign-kit' ),
			'slug'        => 'editorial-updates',
			'description' => __( 'Default list for public newsletter subscriptions.', 'newsletter-campaign-kit' ),
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

function newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_id, $list_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$list_id       = absint( $list_id );
	if ( ! $subscriber_id || ! $list_id ) {
		return false;
	}

	$table = newsletter_campaign_kit_get_subscriber_lists_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT subscriber_id FROM {$table} WHERE subscriber_id = %d AND list_id = %d LIMIT 1",
			$subscriber_id,
			$list_id
		)
	);
	if ( $existing ) {
		return true;
	}

	return false !== $wpdb->insert(
		$table,
		array(
			'subscriber_id' => $subscriber_id,
			'list_id'       => $list_id,
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s' )
	);
}

function newsletter_campaign_kit_assign_subscriber_to_tag( $subscriber_id, $tag_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$tag_id        = absint( $tag_id );
	if ( ! $subscriber_id || ! $tag_id ) {
		return false;
	}

	$table = newsletter_campaign_kit_get_subscriber_tags_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	return false !== $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (subscriber_id, tag_id, created_at) VALUES (%d, %d, %s)",
			$subscriber_id,
			$tag_id,
			current_time( 'mysql', true )
		)
	);
}

function newsletter_campaign_kit_get_lists( $limit = 0, $offset = 0, $include_archived = false ) {
	global $wpdb;

	$lists_table = newsletter_campaign_kit_get_lists_table();
	$map_table   = newsletter_campaign_kit_get_subscriber_lists_table();
	if ( ! newsletter_campaign_kit_segments_tables_exist() ) {
		return array();
	}

	$where  = $include_archived ? '' : " WHERE l.status = 'active'";
	$sql    = "SELECT l.*, COUNT(sl.subscriber_id) AS subscribers_count FROM {$lists_table} l LEFT JOIN {$map_table} sl ON sl.list_id = l.id{$where} GROUP BY l.id ORDER BY l.updated_at DESC";
	$limit  = absint( $limit );
	$offset = absint( $offset );
	if ( $limit ) {
		$sql = $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', min( 100, $limit ), $offset );
	}

	return $wpdb->get_results( $sql, ARRAY_A );
}

function newsletter_campaign_kit_count_lists( $include_archived = false ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_lists_table();
	$where = $include_archived ? '' : " WHERE status = 'active'";
	return newsletter_campaign_kit_table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" ) : 0;
}

function newsletter_campaign_kit_get_tags( $limit = 0, $offset = 0 ) {
	global $wpdb;

	$tags_table = newsletter_campaign_kit_get_tags_table();
	$map_table  = newsletter_campaign_kit_get_subscriber_tags_table();
	if ( ! newsletter_campaign_kit_segments_tables_exist() ) {
		return array();
	}

	$sql    = "SELECT t.*, COUNT(st.subscriber_id) AS subscribers_count FROM {$tags_table} t LEFT JOIN {$map_table} st ON st.tag_id = t.id GROUP BY t.id ORDER BY t.updated_at DESC";
	$limit  = absint( $limit );
	$offset = absint( $offset );
	if ( $limit ) {
		$sql = $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', min( 100, $limit ), $offset );
	}

	return $wpdb->get_results( $sql, ARRAY_A );
}

function newsletter_campaign_kit_count_tags() {
	global $wpdb;

	$table = newsletter_campaign_kit_get_tags_table();
	return newsletter_campaign_kit_table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0;
}

function newsletter_campaign_kit_handle_create_list() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter lists.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_list' );

	$name        = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
	$description = isset( $_POST['list_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_description'] ) ) : '';
	if ( '' === $name || ! newsletter_campaign_kit_segments_tables_exist() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_lists_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'name'        => $name,
			'slug'        => newsletter_campaign_kit_generate_unique_slug( $table, $name ),
			'description' => $description,
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_list_create_failed' : 'newsletter_list_created', false === $ok ? 'failure' : 'success', 0, array( 'name' => $name ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=' . ( false === $ok ? 'failed' : 'list' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_list', 'newsletter_campaign_kit_handle_create_list' );

function newsletter_campaign_kit_handle_create_tag() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter tags.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_tag' );

	$name  = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
	$color = isset( $_POST['tag_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['tag_color'] ) ) : '';
	if ( '' === $name || ! newsletter_campaign_kit_segments_tables_exist() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_tags_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'name'       => $name,
			'slug'       => newsletter_campaign_kit_generate_unique_slug( $table, $name ),
			'color'      => $color ? $color : '#111827',
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_tag_create_failed' : 'newsletter_tag_created', false === $ok ? 'failure' : 'success', 0, array( 'name' => $name ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=' . ( false === $ok ? 'failed' : 'tag' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_tag', 'newsletter_campaign_kit_handle_create_tag' );

/** Save a list from the unified create/edit dialog. */
function newsletter_campaign_kit_handle_save_list() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter lists.', 'newsletter-campaign-kit' ) );
	}
	$list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_save_list_' . $list_id );
	$name        = isset( $_POST['list_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['list_name'] ) ), 0, 120 ) : '';
	$description = isset( $_POST['list_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_description'] ) ) : '';
	$result      = false;
	if ( '' !== $name && newsletter_campaign_kit_segments_tables_exist() ) {
		global $wpdb;
		$table = newsletter_campaign_kit_get_lists_table();
		$now   = current_time( 'mysql', true );
		if ( $list_id ) {
			$result = $wpdb->update( $table, array( 'name' => $name, 'description' => $description, 'updated_at' => $now ), array( 'id' => $list_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$result = $wpdb->insert( $table, array( 'name' => $name, 'slug' => newsletter_campaign_kit_generate_unique_slug( $table, $name ), 'description' => $description, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $result ? 'newsletter_list_save_failed' : ( $list_id ? 'newsletter_list_updated' : 'newsletter_list_created' ), false === $result ? 'failure' : 'success', 0, array( 'list_id' => $list_id, 'name' => $name ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&audience_result=' . ( false === $result ? 'failed' : ( $list_id ? 'updated' : 'created' ) ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_save_list', 'newsletter_campaign_kit_handle_save_list' );

/** Save a tag from the unified create/edit dialog. */
function newsletter_campaign_kit_handle_save_tag() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter tags.', 'newsletter-campaign-kit' ) );
	}
	$tag_id = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_save_tag_' . $tag_id );
	$name   = isset( $_POST['tag_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ), 0, 80 ) : '';
	$color  = isset( $_POST['tag_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['tag_color'] ) ) : '';
	$result = false;
	if ( '' !== $name && newsletter_campaign_kit_segments_tables_exist() ) {
		global $wpdb;
		$table = newsletter_campaign_kit_get_tags_table();
		$now   = current_time( 'mysql', true );
		if ( $tag_id ) {
			$result = $wpdb->update( $table, array( 'name' => $name, 'color' => $color ?: '#111827', 'updated_at' => $now ), array( 'id' => $tag_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$result = $wpdb->insert( $table, array( 'name' => $name, 'slug' => newsletter_campaign_kit_generate_unique_slug( $table, $name ), 'color' => $color ?: '#111827', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s' ) );
		}
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $result ? 'newsletter_tag_save_failed' : ( $tag_id ? 'newsletter_tag_updated' : 'newsletter_tag_created' ), false === $result ? 'failure' : 'success', 0, array( 'tag_id' => $tag_id, 'name' => $name ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&audience_result=' . ( false === $result ? 'failed' : ( $tag_id ? 'updated' : 'created' ) ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_save_tag', 'newsletter_campaign_kit_handle_save_tag' );

/** Save a subscriber-facing topic from the unified create/edit dialog. */
function newsletter_campaign_kit_handle_save_topic() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter topics.', 'newsletter-campaign-kit' ) );
	}
	$topic_id = isset( $_POST['topic_id'] ) ? absint( $_POST['topic_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_save_topic_' . $topic_id );
	$name        = isset( $_POST['topic_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['topic_name'] ) ), 0, 100 ) : '';
	$description = isset( $_POST['topic_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topic_description'] ) ) : '';
	$color       = isset( $_POST['topic_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['topic_color'] ) ) : '';
	$result      = false;
	if ( '' !== $name && newsletter_campaign_kit_dynamic_tables_exist() ) {
		global $wpdb;
		$table = newsletter_campaign_kit_get_topics_table();
		$now   = current_time( 'mysql', true );
		if ( $topic_id ) {
			$result = $wpdb->update( $table, array( 'name' => $name, 'description' => $description, 'color' => $color ?: '#111827', 'updated_at' => $now ), array( 'id' => $topic_id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$result = $wpdb->insert( $table, array( 'name' => $name, 'slug' => newsletter_campaign_kit_generate_unique_slug( $table, $name ), 'description' => $description, 'color' => $color ?: '#111827', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $result ? 'newsletter_topic_save_failed' : ( $topic_id ? 'newsletter_topic_updated' : 'newsletter_topic_created' ), false === $result ? 'failure' : 'success', 0, array( 'topic_id' => $topic_id, 'name' => $name ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&audience_result=' . ( false === $result ? 'failed' : ( $topic_id ? 'updated' : 'created' ) ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_save_topic', 'newsletter_campaign_kit_handle_save_topic' );

/** Return whether an audience definition is referenced by active or historical data. */
function newsletter_campaign_kit_audience_entity_is_used( $kind, $entity_id ) {
	global $wpdb;

	$entity_id = absint( $entity_id );
	if ( ! $entity_id ) {
		return true;
	}
	if ( 'list' === $kind ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT subscriber_id FROM ' . newsletter_campaign_kit_get_subscriber_lists_table() . ' WHERE list_id = %d LIMIT 1', $entity_id ) ) ) {
			return true;
		}
		if ( newsletter_campaign_kit_campaigns_table_exists() && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . newsletter_campaign_kit_get_campaigns_table() . ' WHERE target_list_id = %d LIMIT 1', $entity_id ) ) ) {
			return true;
		}
	} elseif ( 'tag' === $kind ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT subscriber_id FROM ' . newsletter_campaign_kit_get_subscriber_tags_table() . ' WHERE tag_id = %d LIMIT 1', $entity_id ) ) ) {
			return true;
		}
	} elseif ( 'topic' === $kind ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT subscriber_id FROM ' . newsletter_campaign_kit_get_subscriber_topics_table() . ' WHERE topic_id = %d LIMIT 1', $entity_id ) ) ) {
			return true;
		}
		if ( newsletter_campaign_kit_campaigns_table_exists() && $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . newsletter_campaign_kit_get_campaigns_table() . ' WHERE topic_id = %d LIMIT 1', $entity_id ) ) ) {
			return true;
		}
	}
	if ( in_array( $kind, array( 'list', 'tag' ), true ) ) {
		foreach ( newsletter_campaign_kit_get_segments( true ) as $segment ) {
			$rules = json_decode( $segment['rules'], true );
			$key   = 'list' === $kind ? 'list_ids' : 'tag_ids';
			if ( is_array( $rules ) && in_array( $entity_id, array_map( 'absint', $rules[ $key ] ?? array() ), true ) ) {
				return true;
			}
		}
	}

	return false;
}

/** Archive, restore or safely delete list, tag and topic definitions. */
function newsletter_campaign_kit_handle_audience_entity_action() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter audiences.', 'newsletter-campaign-kit' ) );
	}
	$kind      = isset( $_POST['entity_kind'] ) ? sanitize_key( wp_unslash( $_POST['entity_kind'] ) ) : '';
	$entity_id = isset( $_POST['entity_id'] ) ? absint( $_POST['entity_id'] ) : 0;
	$operation = isset( $_POST['entity_operation'] ) ? sanitize_key( wp_unslash( $_POST['entity_operation'] ) ) : '';
	check_admin_referer( 'newsletter_campaign_kit_audience_entity_' . $kind . '_' . $entity_id );
	$tables = array(
		'list'  => newsletter_campaign_kit_get_lists_table(),
		'tag'   => newsletter_campaign_kit_get_tags_table(),
		'topic' => newsletter_campaign_kit_get_topics_table(),
	);
	$result = false;
	if ( isset( $tables[ $kind ] ) && $entity_id && in_array( $operation, array( 'archive', 'restore', 'delete' ), true ) ) {
		global $wpdb;
		if ( 'delete' === $operation ) {
			$result = ! newsletter_campaign_kit_audience_entity_is_used( $kind, $entity_id )
				&& false !== $wpdb->delete( $tables[ $kind ], array( 'id' => $entity_id ), array( '%d' ) );
		} elseif ( 'tag' !== $kind ) {
			$result = false !== $wpdb->update( $tables[ $kind ], array( 'status' => 'restore' === $operation ? 'active' : 'archived', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $entity_id ), array( '%s', '%s' ), array( '%d' ) );
		}
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( $result ? 'newsletter_audience_entity_changed' : 'newsletter_audience_entity_change_failed', $result ? 'success' : 'failure', 0, array( 'kind' => $kind, 'entity_id' => $entity_id, 'operation' => $operation ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&audience_result=' . ( $result ? $operation : 'in_use' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_audience_entity_action', 'newsletter_campaign_kit_handle_audience_entity_action' );

function newsletter_campaign_kit_render_segments_page() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter segments.', 'newsletter-campaign-kit' ) );
	}
	global $wpdb;

	$per_page     = 20;
	$list_page    = isset( $_GET['list_page'] ) ? max( 1, absint( $_GET['list_page'] ) ) : 1;
	$tag_page     = isset( $_GET['tag_page'] ) ? max( 1, absint( $_GET['tag_page'] ) ) : 1;
	$segment_page = isset( $_GET['segment_page'] ) ? max( 1, absint( $_GET['segment_page'] ) ) : 1;
	$topic_page   = isset( $_GET['topic_page'] ) ? max( 1, absint( $_GET['topic_page'] ) ) : 1;
	$lists        = newsletter_campaign_kit_get_lists();
	$tags         = newsletter_campaign_kit_get_tags();
	$display_lists = newsletter_campaign_kit_get_lists( $per_page, ( $list_page - 1 ) * $per_page, true );
	$display_tags  = newsletter_campaign_kit_get_tags( $per_page, ( $tag_page - 1 ) * $per_page );
	$segments      = function_exists( 'newsletter_campaign_kit_get_segments' ) ? newsletter_campaign_kit_get_segments( true, $per_page, ( $segment_page - 1 ) * $per_page ) : array();
	$topics        = function_exists( 'newsletter_campaign_kit_get_topics' ) ? newsletter_campaign_kit_get_topics( $per_page, ( $topic_page - 1 ) * $per_page, true ) : array();
	$list_total    = newsletter_campaign_kit_count_lists( true );
	$tag_total     = newsletter_campaign_kit_count_tags();
	$segment_total = function_exists( 'newsletter_campaign_kit_count_segments' ) ? newsletter_campaign_kit_count_segments( true ) : 0;
	$topic_total   = function_exists( 'newsletter_campaign_kit_count_topics' ) ? newsletter_campaign_kit_count_topics( true ) : 0;
	$pagination_args = array( 'page' => 'newsletter-campaign-kit-segments', 'list_page' => $list_page, 'tag_page' => $tag_page, 'segment_page' => $segment_page, 'topic_page' => $topic_page );
	$subscribers = function_exists( 'newsletter_campaign_kit_get_subscribers' ) ? newsletter_campaign_kit_get_subscribers( array( 'limit' => 100 ) ) : array();
	$list_edit_id  = isset( $_GET['list_edit'] ) ? absint( $_GET['list_edit'] ) : 0;
	$tag_edit_id   = isset( $_GET['tag_edit'] ) ? absint( $_GET['tag_edit'] ) : 0;
	$topic_edit_id = isset( $_GET['topic_edit'] ) ? absint( $_GET['topic_edit'] ) : 0;
	$list_editing  = $list_edit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . newsletter_campaign_kit_get_lists_table() . ' WHERE id = %d LIMIT 1', $list_edit_id ), ARRAY_A ) : null;
	$tag_editing   = $tag_edit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . newsletter_campaign_kit_get_tags_table() . ' WHERE id = %d LIMIT 1', $tag_edit_id ), ARRAY_A ) : null;
	$topic_editing = $topic_edit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . newsletter_campaign_kit_get_topics_table() . ' WHERE id = %d LIMIT 1', $topic_edit_id ), ARRAY_A ) : null;
	$edit_id       = isset( $_GET['segment_edit'] ) ? absint( $_GET['segment_edit'] ) : 0;
	$editing       = $edit_id && function_exists( 'newsletter_campaign_kit_get_segment' ) ? newsletter_campaign_kit_get_segment( $edit_id, true ) : null;
	$editing       = $editing && 'active' === $editing['status'] ? $editing : null;
	$editing_rules = $editing ? json_decode( $editing['rules'], true ) : array();
	$editing_rules = is_array( $editing_rules ) ? $editing_rules : array();
	$segment_result = isset( $_GET['segment'] ) ? sanitize_key( wp_unslash( $_GET['segment'] ) ) : '';
	$segment_messages = array(
		'created'                            => array( 'success', __( 'The dynamic segment was created and is visible below.', 'newsletter-campaign-kit' ) ),
		'updated'                            => array( 'success', __( 'The dynamic segment was updated.', 'newsletter-campaign-kit' ) ),
		'duplicated'                         => array( 'success', __( 'A new editable segment copy was created.', 'newsletter-campaign-kit' ) ),
		'newsletter_invalid_segment'         => array( 'error', __( 'Add a segment name.', 'newsletter-campaign-kit' ) ),
		'newsletter_empty_segment'           => array( 'error', __( 'Choose at least one list, tag, source or date condition.', 'newsletter-campaign-kit' ) ),
		'newsletter_invalid_segment_records' => array( 'error', __( 'One selected list or tag is no longer available.', 'newsletter-campaign-kit' ) ),
		'invalid'                            => array( 'error', __( 'The segment could not be saved. Review its conditions.', 'newsletter-campaign-kit' ) ),
	);
	$audience_result = isset( $_GET['audience_result'] ) ? sanitize_key( wp_unslash( $_GET['audience_result'] ) ) : '';
	$audience_messages = array(
		'created'  => array( 'success', __( 'The audience definition was created.', 'newsletter-campaign-kit' ) ),
		'updated'  => array( 'success', __( 'The audience definition was updated.', 'newsletter-campaign-kit' ) ),
		'archive'  => array( 'success', __( 'The audience definition was archived.', 'newsletter-campaign-kit' ) ),
		'restore'  => array( 'success', __( 'The audience definition was restored.', 'newsletter-campaign-kit' ) ),
		'delete'   => array( 'success', __( 'The unused audience definition was deleted.', 'newsletter-campaign-kit' ) ),
		'in_use'   => array( 'error', __( 'This item is still used by subscribers, segments, campaigns or historical data. Archive it instead of deleting it.', 'newsletter-campaign-kit' ) ),
		'failed'   => array( 'error', __( 'The audience definition could not be saved.', 'newsletter-campaign-kit' ) ),
	);
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<div class="nck-admin-toolbar">
			<div><h1><?php esc_html_e( 'Lists & segments', 'newsletter-campaign-kit' ); ?></h1><p><?php esc_html_e( 'Build explicit and rule-based audiences without mixing their responsibilities.', 'newsletter-campaign-kit' ); ?></p></div>
			<div class="nck-inline-actions">
				<button class="button button-primary" type="button" data-nck-dialog-open="nck-list-create"><?php esc_html_e( 'New list', 'newsletter-campaign-kit' ); ?></button>
				<button class="button" type="button" data-nck-dialog-open="nck-tag-create"><?php esc_html_e( 'New tag', 'newsletter-campaign-kit' ); ?></button>
				<button class="button" type="button" data-nck-dialog-open="nck-segment-create"><?php esc_html_e( 'New segment', 'newsletter-campaign-kit' ); ?></button>
				<button class="button" type="button" data-nck-dialog-open="nck-topic-create"><?php esc_html_e( 'New topic', 'newsletter-campaign-kit' ); ?></button>
				<button class="button" type="button" data-nck-dialog-open="nck-audience-assignment"><?php esc_html_e( 'Assign audience', 'newsletter-campaign-kit' ); ?></button>
			</div>
		</div>
		<div class="nck-concept-guide">
			<div><strong><?php esc_html_e( 'Lists', 'newsletter-campaign-kit' ); ?></strong><span><?php esc_html_e( 'Explicit groups used as stable campaign audiences.', 'newsletter-campaign-kit' ); ?></span></div>
			<div><strong><?php esc_html_e( 'Tags', 'newsletter-campaign-kit' ); ?></strong><span><?php esc_html_e( 'Internal attributes manually attached to subscribers.', 'newsletter-campaign-kit' ); ?></span></div>
			<div><strong><?php esc_html_e( 'Segments', 'newsletter-campaign-kit' ); ?></strong><span><?php esc_html_e( 'Dynamic audiences recalculated from rules at send time.', 'newsletter-campaign-kit' ); ?></span></div>
			<div><strong><?php esc_html_e( 'Topics', 'newsletter-campaign-kit' ); ?></strong><span><?php esc_html_e( 'Editorial preferences subscribers can choose themselves.', 'newsletter-campaign-kit' ); ?></span></div>
		</div>
		<?php if ( current_user_can( 'newsletter_view_reports' ) ) : ?>
			<p class="nck-inline-actions">
				<?php foreach ( array( 'lists' => __( 'Export lists', 'newsletter-campaign-kit' ), 'tags' => __( 'Export tags', 'newsletter-campaign-kit' ), 'segments' => __( 'Export segments', 'newsletter-campaign-kit' ), 'topics' => __( 'Export topics', 'newsletter-campaign-kit' ) ) as $export_kind => $export_label ) : ?>
					<a class="button" href="<?php echo esc_url( newsletter_campaign_kit_get_export_url( $export_kind ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php echo esc_html( $export_label ); ?></a>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<?php if ( ! newsletter_campaign_kit_segments_tables_exist() ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Segment tables are not installed yet. Reactivate or upgrade the plugin with the database available.', 'newsletter-campaign-kit' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $segment_messages[ $segment_result ] ) ) : ?><div class="notice notice-<?php echo esc_attr( $segment_messages[ $segment_result ][0] ); ?> inline"><p><?php echo esc_html( $segment_messages[ $segment_result ][1] ); ?></p></div><?php endif; ?>
		<?php if ( isset( $audience_messages[ $audience_result ] ) ) : ?><div class="notice notice-<?php echo esc_attr( $audience_messages[ $audience_result ][0] ); ?> inline"><p><?php echo esc_html( $audience_messages[ $audience_result ][1] ); ?></p></div><?php endif; ?>

		<dialog id="nck-list-create" class="nck-admin-dialog"<?php echo $list_editing ? ' data-nck-dialog-auto-open' : ''; ?>>
			<header class="nck-admin-dialog__header"><div><h2><?php echo esc_html( $list_editing ? __( 'Edit list', 'newsletter-campaign-kit' ) : __( 'Create list', 'newsletter-campaign-kit' ) ); ?></h2><p><?php esc_html_e( 'Create a stable audience that can receive campaigns.', 'newsletter-campaign-kit' ); ?></p></div><button class="nck-admin-dialog__close" type="button" data-nck-dialog-close aria-label="<?php esc_attr_e( 'Close', 'newsletter-campaign-kit' ); ?>">&times;</button></header>
			<section class="nck-admin-dialog__body">
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-form">
					<input type="hidden" name="action" value="newsletter_campaign_kit_save_list"><input type="hidden" name="list_id" value="<?php echo esc_attr( $list_edit_id ); ?>">
					<?php wp_nonce_field( 'newsletter_campaign_kit_save_list_' . $list_edit_id ); ?>
					<p><label for="nck-list-name"><?php esc_html_e( 'List name', 'newsletter-campaign-kit' ); ?><input id="nck-list-name" class="regular-text" name="list_name" required maxlength="120" value="<?php echo esc_attr( $list_editing['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Collectors, clients, public journal...', 'newsletter-campaign-kit' ); ?>"></label></p>
					<p><label for="nck-list-description"><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?><textarea id="nck-list-description" class="large-text" name="list_description" rows="3" placeholder="<?php esc_attr_e( 'Audience intent and editorial use.', 'newsletter-campaign-kit' ); ?>"><?php echo esc_textarea( $list_editing['description'] ?? '' ); ?></textarea></label></p>
					<?php submit_button( $list_editing ? __( 'Save list', 'newsletter-campaign-kit' ) : __( 'Create list', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>
		</dialog>

		<dialog id="nck-tag-create" class="nck-admin-dialog"<?php echo $tag_editing ? ' data-nck-dialog-auto-open' : ''; ?>>
			<header class="nck-admin-dialog__header"><div><h2><?php echo esc_html( $tag_editing ? __( 'Edit tag', 'newsletter-campaign-kit' ) : __( 'Create tag', 'newsletter-campaign-kit' ) ); ?></h2><p><?php esc_html_e( 'Add a reusable internal attribute for audience rules.', 'newsletter-campaign-kit' ); ?></p></div><button class="nck-admin-dialog__close" type="button" data-nck-dialog-close aria-label="<?php esc_attr_e( 'Close', 'newsletter-campaign-kit' ); ?>">&times;</button></header>
			<section class="nck-admin-dialog__body">
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-form">
					<input type="hidden" name="action" value="newsletter_campaign_kit_save_tag"><input type="hidden" name="tag_id" value="<?php echo esc_attr( $tag_edit_id ); ?>">
					<?php wp_nonce_field( 'newsletter_campaign_kit_save_tag_' . $tag_edit_id ); ?>
					<p><label for="nck-tag-name"><?php esc_html_e( 'Tag name', 'newsletter-campaign-kit' ); ?><input id="nck-tag-name" class="regular-text" name="tag_name" required maxlength="80" value="<?php echo esc_attr( $tag_editing['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Portrait, private access, collector...', 'newsletter-campaign-kit' ); ?>"></label></p>
					<p><label for="nck-tag-color"><?php esc_html_e( 'Tag color', 'newsletter-campaign-kit' ); ?><input id="nck-tag-color" type="color" name="tag_color" value="<?php echo esc_attr( $tag_editing['color'] ?? '#111827' ); ?>"></label></p>
					<?php submit_button( $tag_editing ? __( 'Save tag', 'newsletter-campaign-kit' ) : __( 'Create tag', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>
		</dialog>

		<dialog id="nck-audience-assignment" class="nck-admin-dialog">
			<header class="nck-admin-dialog__header"><div><h2><?php esc_html_e( 'Assign subscriber audiences', 'newsletter-campaign-kit' ); ?></h2><p><?php esc_html_e( 'Update several subscribers and audiences in one operation.', 'newsletter-campaign-kit' ); ?></p></div><button class="nck-admin-dialog__close" type="button" data-nck-dialog-close aria-label="<?php esc_attr_e( 'Close', 'newsletter-campaign-kit' ); ?>">&times;</button></header>
			<section class="nck-admin-dialog__body">
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-assignment-form">
				<input type="hidden" name="action" value="newsletter_campaign_kit_update_assignment">
				<?php wp_nonce_field( 'newsletter_campaign_kit_update_assignment' ); ?>
				<div class="nck-bulk-picker">
					<fieldset>
						<legend><?php esc_html_e( 'Subscribers', 'newsletter-campaign-kit' ); ?></legend>
						<input type="search" class="large-text" placeholder="<?php esc_attr_e( 'Filter by email', 'newsletter-campaign-kit' ); ?>" data-nck-check-filter="nck-subscriber-options">
						<div id="nck-subscriber-options" class="nck-check-list" data-nck-check-list>
							<?php foreach ( $subscribers as $subscriber ) : ?><label><input type="checkbox" name="subscriber_ids[]" value="<?php echo esc_attr( $subscriber['id'] ); ?>"> <span><?php echo esc_html( $subscriber['email'] ); ?></span></label><?php endforeach; ?>
						</div>
					</fieldset>
					<fieldset>
						<legend><?php esc_html_e( 'Lists and tags', 'newsletter-campaign-kit' ); ?></legend>
						<div class="nck-check-list">
							<?php foreach ( $lists as $list ) : ?><label><input type="checkbox" name="audiences[]" value="<?php echo esc_attr( 'list:' . $list['id'] ); ?>"> <span><?php echo esc_html( sprintf( __( 'List: %s', 'newsletter-campaign-kit' ), $list['name'] ) ); ?></span></label><?php endforeach; ?>
							<?php foreach ( $tags as $tag ) : ?><label><input type="checkbox" name="audiences[]" value="<?php echo esc_attr( 'tag:' . $tag['id'] ); ?>"> <span><?php echo esc_html( sprintf( __( 'Tag: %s', 'newsletter-campaign-kit' ), $tag['name'] ) ); ?></span></label><?php endforeach; ?>
						</div>
					</fieldset>
				</div>
				<label><?php esc_html_e( 'Operation', 'newsletter-campaign-kit' ); ?><select name="assignment_operation"><option value="add"><?php esc_html_e( 'Add selected assignments', 'newsletter-campaign-kit' ); ?></option><option value="remove"><?php esc_html_e( 'Remove selected assignments', 'newsletter-campaign-kit' ); ?></option></select></label>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Update assignment', 'newsletter-campaign-kit' ); ?></button>
			</form>
			</section>
		</dialog>

		<dialog id="nck-segment-create" class="nck-admin-dialog"<?php echo $editing ? ' data-nck-dialog-auto-open' : ''; ?>>
			<header class="nck-admin-dialog__header"><div><h2><?php echo esc_html( $editing ? __( 'Edit dynamic segment', 'newsletter-campaign-kit' ) : __( 'Create dynamic segment', 'newsletter-campaign-kit' ) ); ?></h2><p><?php esc_html_e( 'Combine lists, tags, sources and dates into a live audience.', 'newsletter-campaign-kit' ); ?></p></div><button class="nck-admin-dialog__close" type="button" data-nck-dialog-close aria-label="<?php esc_attr_e( 'Close', 'newsletter-campaign-kit' ); ?>">&times;</button></header>
			<section class="nck-admin-dialog__body">
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( $editing ? 'newsletter_campaign_kit_update_segment' : 'newsletter_campaign_kit_create_segment' ); ?>">
					<?php if ( $editing ) : ?><input type="hidden" name="segment_id" value="<?php echo esc_attr( $editing['id'] ); ?>"><?php endif; ?>
					<?php wp_nonce_field( $editing ? 'newsletter_campaign_kit_update_segment_' . absint( $editing['id'] ) : 'newsletter_campaign_kit_create_segment' ); ?>
					<p><input class="regular-text" name="segment_name" required maxlength="120" value="<?php echo esc_attr( $editing ? $editing['name'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Active portrait collectors', 'newsletter-campaign-kit' ); ?>"></p>
					<p><textarea class="large-text" name="segment_description" rows="2" placeholder="<?php esc_attr_e( 'Purpose of this dynamic audience.', 'newsletter-campaign-kit' ); ?>"><?php echo esc_textarea( $editing ? $editing['description'] : '' ); ?></textarea></p>
					<p>
						<label><?php esc_html_e( 'Match', 'newsletter-campaign-kit' ); ?>
						<select name="segment_match_type">
							<option value="all" <?php selected( $editing ? $editing['match_type'] : 'all', 'all' ); ?>><?php esc_html_e( 'All selected conditions', 'newsletter-campaign-kit' ); ?></option>
							<option value="any" <?php selected( $editing ? $editing['match_type'] : 'all', 'any' ); ?>><?php esc_html_e( 'Any selected condition', 'newsletter-campaign-kit' ); ?></option>
						</select></label>
					</p>
					<div class="nck-bulk-picker">
						<fieldset><legend><?php esc_html_e( 'Lists', 'newsletter-campaign-kit' ); ?></legend><div class="nck-check-list">
							<?php foreach ( $lists as $list ) : ?><label><input type="checkbox" name="segment_list_ids[]" value="<?php echo esc_attr( $list['id'] ); ?>" <?php checked( in_array( (int) $list['id'], array_map( 'absint', isset( $editing_rules['list_ids'] ) ? $editing_rules['list_ids'] : array() ), true ) ); ?>> <span><?php echo esc_html( $list['name'] ); ?></span></label><?php endforeach; ?>
						</div></fieldset>
						<fieldset><legend><?php esc_html_e( 'Tags', 'newsletter-campaign-kit' ); ?></legend><div class="nck-check-list">
							<?php foreach ( $tags as $tag ) : ?><label><input type="checkbox" name="segment_tag_ids[]" value="<?php echo esc_attr( $tag['id'] ); ?>" <?php checked( in_array( (int) $tag['id'], array_map( 'absint', isset( $editing_rules['tag_ids'] ) ? $editing_rules['tag_ids'] : array() ), true ) ); ?>> <span><?php echo esc_html( $tag['name'] ); ?></span></label><?php endforeach; ?>
						</div></fieldset>
					</div>
					<p><label><?php esc_html_e( 'Subscription sources', 'newsletter-campaign-kit' ); ?><br><input class="regular-text" name="segment_sources" value="<?php echo esc_attr( implode( ', ', isset( $editing_rules['sources'] ) ? $editing_rules['sources'] : array() ) ); ?>" placeholder="front_footer, checkout"></label></p>
					<p>
						<label><?php esc_html_e( 'Subscribed after', 'newsletter-campaign-kit' ); ?> <input type="date" name="segment_created_after" value="<?php echo esc_attr( ! empty( $editing_rules['created_after'] ) ? substr( $editing_rules['created_after'], 0, 10 ) : '' ); ?>"></label>
						<label><?php esc_html_e( 'Subscribed before', 'newsletter-campaign-kit' ); ?> <input type="date" name="segment_created_before" value="<?php echo esc_attr( ! empty( $editing_rules['created_before'] ) ? substr( $editing_rules['created_before'], 0, 10 ) : '' ); ?>"></label>
					</p>
					<?php submit_button( $editing ? __( 'Save segment', 'newsletter-campaign-kit' ) : __( 'Create segment', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
					<?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-segments' ) ); ?>"><?php esc_html_e( 'Cancel editing', 'newsletter-campaign-kit' ); ?></a><?php endif; ?>
				</form>
			</section>
		</dialog>

		<dialog id="nck-topic-create" class="nck-admin-dialog"<?php echo $topic_editing ? ' data-nck-dialog-auto-open' : ''; ?>>
			<header class="nck-admin-dialog__header"><div><h2><?php echo esc_html( $topic_editing ? __( 'Edit campaign topic', 'newsletter-campaign-kit' ) : __( 'Create campaign topic', 'newsletter-campaign-kit' ) ); ?></h2><p><?php esc_html_e( 'Expose one clear editorial preference to subscribers.', 'newsletter-campaign-kit' ); ?></p></div><button class="nck-admin-dialog__close" type="button" data-nck-dialog-close aria-label="<?php esc_attr_e( 'Close', 'newsletter-campaign-kit' ); ?>">&times;</button></header>
			<section class="nck-admin-dialog__body">
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-form">
					<input type="hidden" name="action" value="newsletter_campaign_kit_save_topic"><input type="hidden" name="topic_id" value="<?php echo esc_attr( $topic_edit_id ); ?>">
					<?php wp_nonce_field( 'newsletter_campaign_kit_save_topic_' . $topic_edit_id ); ?>
					<p><label for="nck-topic-name"><?php esc_html_e( 'Topic name', 'newsletter-campaign-kit' ); ?><input id="nck-topic-name" class="regular-text" name="topic_name" required maxlength="100" value="<?php echo esc_attr( $topic_editing['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Portraits, exhibitions, private archives...', 'newsletter-campaign-kit' ); ?>"></label></p>
					<p><label for="nck-topic-description"><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?><textarea id="nck-topic-description" class="large-text" name="topic_description" rows="3" placeholder="<?php esc_attr_e( 'Editorial scope of this topic.', 'newsletter-campaign-kit' ); ?>"><?php echo esc_textarea( $topic_editing['description'] ?? '' ); ?></textarea></label></p>
					<p><label for="nck-topic-color"><?php esc_html_e( 'Topic color', 'newsletter-campaign-kit' ); ?><input id="nck-topic-color" type="color" name="topic_color" value="<?php echo esc_attr( $topic_editing['color'] ?? '#111827' ); ?>"></label></p>
					<?php submit_button( $topic_editing ? __( 'Save topic', 'newsletter-campaign-kit' ) : __( 'Create topic', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>
		</dialog>

		<h2><?php esc_html_e( 'Lists', 'newsletter-campaign-kit' ); ?></h2>
		<div class="nck-table-wrap"><table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscribers', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $display_lists ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No list yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $display_lists as $list ) : ?><tr><td><strong><?php echo esc_html( $list['name'] ); ?></strong><br><code><?php echo esc_html( $list['slug'] ); ?></code></td><td><code><?php echo esc_html( $list['status'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) $list['subscribers_count'] ) ); ?></td><td><?php echo esc_html( $list['description'] ); ?></td><td><div class="nck-inline-actions">
			<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'newsletter-campaign-kit-segments', 'list_edit' => absint( $list['id'] ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_audience_entity_action"><input type="hidden" name="entity_kind" value="list"><input type="hidden" name="entity_id" value="<?php echo esc_attr( $list['id'] ); ?>"><input type="hidden" name="entity_operation" value="<?php echo esc_attr( 'active' === $list['status'] ? 'archive' : 'restore' ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_audience_entity_list_' . absint( $list['id'] ) ); ?><button class="button button-small" type="submit"><?php echo esc_html( 'active' === $list['status'] ? __( 'Archive', 'newsletter-campaign-kit' ) : __( 'Restore', 'newsletter-campaign-kit' ) ); ?></button></form>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nck-confirm="<?php esc_attr_e( 'Delete this unused list permanently?', 'newsletter-campaign-kit' ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_audience_entity_action"><input type="hidden" name="entity_kind" value="list"><input type="hidden" name="entity_id" value="<?php echo esc_attr( $list['id'] ); ?>"><input type="hidden" name="entity_operation" value="delete"><?php wp_nonce_field( 'newsletter_campaign_kit_audience_entity_list_' . absint( $list['id'] ) ); ?><button class="button button-small nck-danger-button" type="submit"><?php esc_html_e( 'Delete', 'newsletter-campaign-kit' ); ?></button></form>
		</div></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php newsletter_campaign_kit_render_pagination( $list_page, $list_total, $per_page, $pagination_args, 'list_page' ); ?>

		<h2><?php esc_html_e( 'Tags', 'newsletter-campaign-kit' ); ?></h2>
		<div class="nck-table-wrap"><table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Slug', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscribers', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Color', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $display_tags ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No tag yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $display_tags as $tag ) : ?><tr><td><strong><?php echo esc_html( $tag['name'] ); ?></strong></td><td><code><?php echo esc_html( $tag['slug'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) $tag['subscribers_count'] ) ); ?></td><td><span class="nck-color" style="background:<?php echo esc_attr( $tag['color'] ); ?>"></span><?php echo esc_html( $tag['color'] ); ?></td><td><div class="nck-inline-actions">
			<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'newsletter-campaign-kit-segments', 'tag_edit' => absint( $tag['id'] ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nck-confirm="<?php esc_attr_e( 'Delete this unused tag permanently?', 'newsletter-campaign-kit' ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_audience_entity_action"><input type="hidden" name="entity_kind" value="tag"><input type="hidden" name="entity_id" value="<?php echo esc_attr( $tag['id'] ); ?>"><input type="hidden" name="entity_operation" value="delete"><?php wp_nonce_field( 'newsletter_campaign_kit_audience_entity_tag_' . absint( $tag['id'] ) ); ?><button class="button button-small nck-danger-button" type="submit"><?php esc_html_e( 'Delete', 'newsletter-campaign-kit' ); ?></button></form>
		</div></td></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php newsletter_campaign_kit_render_pagination( $tag_page, $tag_total, $per_page, $pagination_args, 'tag_page' ); ?>

		<h2 id="nck-segments"><?php esc_html_e( 'Dynamic segments', 'newsletter-campaign-kit' ); ?></h2>
		<div class="nck-table-wrap"><table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Mode', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Rules', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Audience', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $segments ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No dynamic segment yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $segments as $segment ) : ?>
			<?php $rules = json_decode( $segment['rules'], true ); ?>
			<tr>
				<td><strong><?php echo esc_html( $segment['name'] ); ?></strong><br><code><?php echo esc_html( $segment['slug'] ); ?></code></td>
				<td><?php echo esc_html( 'any' === $segment['match_type'] ? __( 'Any', 'newsletter-campaign-kit' ) : __( 'All', 'newsletter-campaign-kit' ) ); ?></td>
				<td><?php echo esc_html( sprintf( __( '%1$d lists, %2$d tags, %3$d sources', 'newsletter-campaign-kit' ), count( isset( $rules['list_ids'] ) ? $rules['list_ids'] : array() ), count( isset( $rules['tag_ids'] ) ? $rules['tag_ids'] : array() ), count( isset( $rules['sources'] ) ? $rules['sources'] : array() ) ) ); ?></td>
				<td><strong><?php echo esc_html( number_format_i18n( 'active' === $segment['status'] ? newsletter_campaign_kit_get_segment_audience_count( $segment['id'] ) : 0 ) ); ?></strong><br><small><?php echo esc_html( $segment['description'] ); ?></small></td>
				<td><code><?php echo esc_html( 'active' === $segment['status'] ? __( 'Active', 'newsletter-campaign-kit' ) : __( 'Archived', 'newsletter-campaign-kit' ) ); ?></code></td>
				<td><div class="nck-inline-actions"><?php if ( 'active' === $segment['status'] ) : ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( 'segment_edit', absint( $segment['id'] ), admin_url( 'admin.php?page=newsletter-campaign-kit-segments' ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a><?php endif; ?><form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_duplicate_segment"><input type="hidden" name="segment_id" value="<?php echo esc_attr( $segment['id'] ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_duplicate_segment_' . absint( $segment['id'] ) ); ?><button class="button button-small" type="submit"><?php esc_html_e( 'Duplicate', 'newsletter-campaign-kit' ); ?></button></form><form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_segment_status"><input type="hidden" name="segment_id" value="<?php echo esc_attr( $segment['id'] ); ?>"><input type="hidden" name="segment_status" value="<?php echo esc_attr( 'active' === $segment['status'] ? 'archived' : 'active' ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_segment_status_' . absint( $segment['id'] ) ); ?><button class="button button-small" type="submit"><?php echo esc_html( 'active' === $segment['status'] ? __( 'Archive', 'newsletter-campaign-kit' ) : __( 'Restore', 'newsletter-campaign-kit' ) ); ?></button></form></div></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php newsletter_campaign_kit_render_pagination( $segment_page, $segment_total, $per_page, $pagination_args, 'segment_page' ); ?>

		<section class="nck-topic-guide">
			<div><span class="nck-eyebrow"><?php esc_html_e( 'Preference layer', 'newsletter-campaign-kit' ); ?></span><h2><?php esc_html_e( 'Campaign topics', 'newsletter-campaign-kit' ); ?></h2></div>
			<p><?php esc_html_e( 'Topics are editorial preferences chosen by subscribers on signup or in their preference centre. When a campaign has a topic, only eligible audience members subscribed to that topic receive it. A campaign without a topic targets every consented member of its selected audience.', 'newsletter-campaign-kit' ); ?></p>
		</section>
		<div class="nck-table-wrap"><table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Color', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Usage', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $topics ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No campaign topic yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $topics as $topic ) : ?>
			<?php $topic_subscribers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . newsletter_campaign_kit_get_subscriber_topics_table() . ' WHERE topic_id = %d AND status = %s', $topic['id'], 'subscribed' ) ); ?>
			<tr><td><strong><?php echo esc_html( $topic['name'] ); ?></strong><br><code><?php echo esc_html( $topic['slug'] ); ?></code></td><td><code><?php echo esc_html( $topic['status'] ); ?></code></td><td><span class="nck-color" style="background:<?php echo esc_attr( $topic['color'] ); ?>"></span><?php echo esc_html( $topic['color'] ); ?></td><td><?php echo esc_html( $topic['description'] ); ?></td><td><?php echo esc_html( sprintf( _n( '%d subscriber', '%d subscribers', $topic_subscribers, 'newsletter-campaign-kit' ), $topic_subscribers ) ); ?></td><td><div class="nck-inline-actions">
				<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'newsletter-campaign-kit-segments', 'topic_edit' => absint( $topic['id'] ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_audience_entity_action"><input type="hidden" name="entity_kind" value="topic"><input type="hidden" name="entity_id" value="<?php echo esc_attr( $topic['id'] ); ?>"><input type="hidden" name="entity_operation" value="<?php echo esc_attr( 'active' === $topic['status'] ? 'archive' : 'restore' ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_audience_entity_topic_' . absint( $topic['id'] ) ); ?><button class="button button-small" type="submit"><?php echo esc_html( 'active' === $topic['status'] ? __( 'Archive', 'newsletter-campaign-kit' ) : __( 'Restore', 'newsletter-campaign-kit' ) ); ?></button></form>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-nck-confirm="<?php esc_attr_e( 'Delete this unused topic permanently?', 'newsletter-campaign-kit' ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_audience_entity_action"><input type="hidden" name="entity_kind" value="topic"><input type="hidden" name="entity_id" value="<?php echo esc_attr( $topic['id'] ); ?>"><input type="hidden" name="entity_operation" value="delete"><?php wp_nonce_field( 'newsletter_campaign_kit_audience_entity_topic_' . absint( $topic['id'] ) ); ?><button class="button button-small nck-danger-button" type="submit"><?php esc_html_e( 'Delete', 'newsletter-campaign-kit' ); ?></button></form>
			</div></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php newsletter_campaign_kit_render_pagination( $topic_page, $topic_total, $per_page, $pagination_args, 'topic_page' ); ?>
	</div>
	<style>.newsletter-campaign-kit-admin .nck-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:18px 0}.newsletter-campaign-kit-admin .nck-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:18px 0;padding:16px}.newsletter-campaign-kit-admin .nck-assignment-form,.newsletter-campaign-kit-admin .nck-inline-actions{display:flex;gap:8px;flex-wrap:wrap}.newsletter-campaign-kit-admin .nck-color{display:inline-block;width:14px;height:14px;border-radius:999px;margin-right:8px;vertical-align:-2px}@media(max-width:900px){.newsletter-campaign-kit-admin .nck-layout{grid-template-columns:1fr}.newsletter-campaign-kit-admin .nck-assignment-form{align-items:stretch;flex-direction:column}}</style>
	<?php
}
