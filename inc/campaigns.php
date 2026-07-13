<?php
/**
 * Campaign drafts and server-side transitions for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_campaigns_table_exists() {
	return function_exists( 'newsletter_campaign_kit_table_exists' )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_campaigns_table() );
}

function newsletter_campaign_kit_get_campaign_statuses() {
	return array(
		'draft'     => __( 'Draft', 'newsletter-campaign-kit' ),
		'ready'     => __( 'Ready', 'newsletter-campaign-kit' ),
		'scheduled' => __( 'Scheduled', 'newsletter-campaign-kit' ),
		'sending'   => __( 'Sending', 'newsletter-campaign-kit' ),
		'sent'      => __( 'Sent', 'newsletter-campaign-kit' ),
		'paused'    => __( 'Paused', 'newsletter-campaign-kit' ),
		'cancelled' => __( 'Cancelled', 'newsletter-campaign-kit' ),
	);
}

function newsletter_campaign_kit_get_allowed_campaign_transitions() {
	return array(
		'draft'     => array( 'ready', 'cancelled' ),
		'ready'     => array( 'draft', 'scheduled', 'sending', 'cancelled' ),
		'scheduled' => array( 'ready', 'sending', 'cancelled' ),
		'sending'   => array( 'paused', 'sent' ),
		'paused'    => array( 'sending', 'cancelled' ),
		'sent'      => array(),
		'cancelled' => array(),
	);
}

function newsletter_campaign_kit_get_campaigns( $limit = 50 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_campaigns_table_exists() ) {
		return array();
	}

	$table = newsletter_campaign_kit_get_campaigns_table();
	$limit = max( 1, min( 100, absint( $limit ) ) );

	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", $limit ), ARRAY_A );
}

function newsletter_campaign_kit_get_campaign( $campaign_id ) {
	global $wpdb;

	$campaign_id = absint( $campaign_id );
	if ( ! $campaign_id || ! newsletter_campaign_kit_campaigns_table_exists() ) {
		return null;
	}

	$table = newsletter_campaign_kit_get_campaigns_table();

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $campaign_id ), ARRAY_A );
}

function newsletter_campaign_kit_user_can_transition_campaign( $from_status, $to_status ) {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		return false;
	}

	if ( in_array( $to_status, array( 'scheduled', 'sending', 'sent' ), true ) && ! current_user_can( 'newsletter_send_campaigns' ) ) {
		return false;
	}

	$transitions = newsletter_campaign_kit_get_allowed_campaign_transitions();

	return isset( $transitions[ $from_status ] ) && in_array( $to_status, $transitions[ $from_status ], true );
}

function newsletter_campaign_kit_resolve_campaign_audience( $value ) {
	$value = sanitize_text_field( $value );
	if ( 'all' === $value ) {
		return array( 'target_list_id' => null, 'target_segment_id' => null );
	}

	if ( ! preg_match( '/^(list|segment):(\d+)$/', $value, $matches ) ) {
		return new WP_Error( 'newsletter_invalid_audience', __( 'The campaign audience is invalid.', 'newsletter-campaign-kit' ) );
	}

	$record_id = absint( $matches[2] );
	$table     = 'list' === $matches[1] ? newsletter_campaign_kit_get_lists_table() : newsletter_campaign_kit_get_segments_table();
	if ( ! newsletter_campaign_kit_record_is_active( $table, $record_id ) ) {
		return new WP_Error( 'newsletter_invalid_audience', __( 'The selected campaign audience is unavailable.', 'newsletter-campaign-kit' ) );
	}

	return array(
		'target_list_id'    => 'list' === $matches[1] ? $record_id : null,
		'target_segment_id' => 'segment' === $matches[1] ? $record_id : null,
	);
}

/** Validate and normalize campaign input before persistence. */
function newsletter_campaign_kit_prepare_campaign_data( $input ) {
	$title    = isset( $input['title'] ) ? substr( sanitize_text_field( $input['title'] ), 0, 190 ) : '';
	$content  = newsletter_campaign_kit_resolve_campaign_content(
		array(
			'template_id'  => isset( $input['template_id'] ) ? absint( $input['template_id'] ) : 0,
			'subject'      => isset( $input['subject'] ) ? $input['subject'] : '',
			'preview_text' => isset( $input['preview_text'] ) ? $input['preview_text'] : '',
			'html_body'    => isset( $input['html_body'] ) ? $input['html_body'] : '',
			'text_body'    => isset( $input['text_body'] ) ? $input['text_body'] : '',
		)
	);
	$audience = newsletter_campaign_kit_resolve_campaign_audience( isset( $input['target_audience'] ) ? $input['target_audience'] : 'all' );
	$topic_id = isset( $input['topic_id'] ) ? absint( $input['topic_id'] ) : 0;

	if ( '' === $title || is_wp_error( $content ) || is_wp_error( $audience ) ) {
		return is_wp_error( $content ) ? $content : ( is_wp_error( $audience ) ? $audience : new WP_Error( 'newsletter_invalid_campaign', __( 'The campaign title is required.', 'newsletter-campaign-kit' ) ) );
	}
	if ( $topic_id && ! newsletter_campaign_kit_record_is_active( newsletter_campaign_kit_get_topics_table(), $topic_id ) ) {
		return new WP_Error( 'newsletter_invalid_campaign_topic', __( 'The selected campaign topic is unavailable.', 'newsletter-campaign-kit' ) );
	}

	return array(
		'title'             => $title,
		'subject'           => $content['subject'],
		'preview_text'      => $content['preview_text'],
		'body'              => $content['html_body'],
		'text_body'         => $content['text_body'],
		'template_id'       => $content['template_id'] ? $content['template_id'] : null,
		'target_list_id'    => $audience['target_list_id'],
		'target_segment_id' => $audience['target_segment_id'],
		'topic_id'          => $topic_id ? $topic_id : null,
	);
}

function newsletter_campaign_kit_create_campaign( $input, $actor_user_id = 0 ) {
	global $wpdb;

	$data = newsletter_campaign_kit_prepare_campaign_data( $input );
	if ( is_wp_error( $data ) || ! newsletter_campaign_kit_campaigns_table_exists() ) {
		return is_wp_error( $data ) ? $data : new WP_Error( 'newsletter_campaign_storage_unavailable', __( 'Campaign storage is unavailable.', 'newsletter-campaign-kit' ) );
	}

	$table = newsletter_campaign_kit_get_campaigns_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array_merge(
			$data,
			array(
				'slug'       => newsletter_campaign_kit_generate_unique_slug( $table, $data['title'] ),
				'status'     => 'draft',
				'created_by' => absint( $actor_user_id ),
				'updated_by' => absint( $actor_user_id ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		)
	);
	$result = false === $ok ? new WP_Error( 'newsletter_campaign_create_failed', __( 'The campaign could not be created.', 'newsletter-campaign-kit' ) ) : (int) $wpdb->insert_id;
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( is_wp_error( $result ) ? 'newsletter_campaign_create_failed' : 'newsletter_campaign_created', is_wp_error( $result ) ? 'failure' : 'success', 0, array( 'title' => $data['title'] ) );
	}

	return $result;
}

function newsletter_campaign_kit_update_campaign( $campaign_id, $input, $actor_user_id = 0 ) {
	global $wpdb;

	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	if ( ! $campaign ) {
		return new WP_Error( 'newsletter_campaign_not_found', __( 'Campaign not found.', 'newsletter-campaign-kit' ) );
	}
	if ( 'draft' !== $campaign['status'] ) {
		return new WP_Error( 'newsletter_campaign_locked', __( 'Only draft campaigns can be edited.', 'newsletter-campaign-kit' ) );
	}

	$data = newsletter_campaign_kit_prepare_campaign_data( $input );
	if ( is_wp_error( $data ) ) {
		return $data;
	}
	$data['updated_by'] = absint( $actor_user_id );
	$data['updated_at'] = current_time( 'mysql', true );
	$updated            = $wpdb->update( newsletter_campaign_kit_get_campaigns_table(), $data, array( 'id' => absint( $campaign_id ), 'status' => 'draft' ) );
	$result             = false === $updated ? new WP_Error( 'newsletter_campaign_update_failed', __( 'The campaign could not be updated.', 'newsletter-campaign-kit' ) ) : true;
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( is_wp_error( $result ) ? 'newsletter_campaign_update_failed' : 'newsletter_campaign_updated', is_wp_error( $result ) ? 'failure' : 'success', 0, array( 'campaign_id' => absint( $campaign_id ) ) );
	}

	return $result;
}

function newsletter_campaign_kit_duplicate_campaign( $campaign_id, $actor_user_id = 0 ) {
	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	if ( ! $campaign ) {
		return new WP_Error( 'newsletter_campaign_not_found', __( 'Campaign not found.', 'newsletter-campaign-kit' ) );
	}

	$audience = 'all';
	if ( ! empty( $campaign['target_list_id'] ) ) {
		$audience = 'list:' . absint( $campaign['target_list_id'] );
	} elseif ( ! empty( $campaign['target_segment_id'] ) ) {
		$audience = 'segment:' . absint( $campaign['target_segment_id'] );
	}

	return newsletter_campaign_kit_create_campaign(
		array(
			'title'           => sprintf( __( '%s copy', 'newsletter-campaign-kit' ), $campaign['title'] ),
			'subject'         => $campaign['subject'],
			'preview_text'    => $campaign['preview_text'],
			'html_body'       => $campaign['body'],
			'text_body'       => $campaign['text_body'],
			'target_audience' => $audience,
			'topic_id'        => ! empty( $campaign['topic_id'] ) && newsletter_campaign_kit_record_is_active( newsletter_campaign_kit_get_topics_table(), $campaign['topic_id'] ) ? $campaign['topic_id'] : 0,
		),
		$actor_user_id
	);
}

function newsletter_campaign_kit_get_campaign_audience_count( $campaign ) {
	static $counts = array();

	if ( ! function_exists( 'newsletter_campaign_kit_get_campaign_recipients' ) ) {
		return 0;
	}
	$key = implode(
		':',
		array(
			absint( isset( $campaign['target_list_id'] ) ? $campaign['target_list_id'] : 0 ),
			absint( isset( $campaign['target_segment_id'] ) ? $campaign['target_segment_id'] : 0 ),
			absint( isset( $campaign['topic_id'] ) ? $campaign['topic_id'] : 0 ),
		)
	);
	if ( ! isset( $counts[ $key ] ) ) {
		$counts[ $key ] = count( newsletter_campaign_kit_get_campaign_recipients( $campaign ) );
	}

	return $counts[ $key ];
}

function newsletter_campaign_kit_get_campaign_input_from_request() {
	return array(
		'title'           => isset( $_POST['campaign_title'] ) ? wp_unslash( $_POST['campaign_title'] ) : '',
		'template_id'     => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
		'subject'         => isset( $_POST['campaign_subject'] ) ? wp_unslash( $_POST['campaign_subject'] ) : '',
		'preview_text'    => isset( $_POST['campaign_preview_text'] ) ? wp_unslash( $_POST['campaign_preview_text'] ) : '',
		'html_body'       => isset( $_POST['campaign_body'] ) ? wp_unslash( $_POST['campaign_body'] ) : '',
		'text_body'       => isset( $_POST['campaign_text_body'] ) ? wp_unslash( $_POST['campaign_text_body'] ) : '',
		'target_audience' => isset( $_POST['target_audience'] ) ? wp_unslash( $_POST['target_audience'] ) : 'all',
		'topic_id'        => isset( $_POST['topic_id'] ) ? absint( $_POST['topic_id'] ) : 0,
	);
}

function newsletter_campaign_kit_handle_create_campaign() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to create newsletter campaigns.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_campaign' );

	$result = newsletter_campaign_kit_create_campaign( newsletter_campaign_kit_get_campaign_input_from_request(), get_current_user_id() );
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&created=' . ( is_wp_error( $result ) ? 'invalid' : 'campaign' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_campaign', 'newsletter_campaign_kit_handle_create_campaign' );

function newsletter_campaign_kit_handle_update_campaign() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to edit newsletter campaigns.', 'newsletter-campaign-kit' ) );
	}
	$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_update_campaign_' . $campaign_id );
	$result = newsletter_campaign_kit_update_campaign( $campaign_id, newsletter_campaign_kit_get_campaign_input_from_request(), get_current_user_id() );
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&updated=' . ( is_wp_error( $result ) ? 'invalid' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_update_campaign', 'newsletter_campaign_kit_handle_update_campaign' );

function newsletter_campaign_kit_handle_duplicate_campaign() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to duplicate newsletter campaigns.', 'newsletter-campaign-kit' ) );
	}
	$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_duplicate_campaign_' . $campaign_id );
	$result = newsletter_campaign_kit_duplicate_campaign( $campaign_id, get_current_user_id() );
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&duplicated=' . ( is_wp_error( $result ) ? 'invalid' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_duplicate_campaign', 'newsletter_campaign_kit_handle_duplicate_campaign' );

function newsletter_campaign_kit_handle_transition_campaign() {
	$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
	$next_status = isset( $_POST['next_status'] ) ? sanitize_key( wp_unslash( $_POST['next_status'] ) ) : '';

	check_admin_referer( 'newsletter_campaign_kit_transition_campaign_' . $campaign_id );

	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	if ( ! $campaign || ! newsletter_campaign_kit_user_can_transition_campaign( $campaign['status'], $next_status ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&transition=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_campaigns_table();
	$now   = current_time( 'mysql', true );
	$data  = array(
		'status'     => $next_status,
		'updated_by' => get_current_user_id(),
		'updated_at' => $now,
	);
	$types = array( '%s', '%d', '%s' );

	if ( 'ready' === $next_status ) {
		$data['scheduled_at'] = null;
		$types[]              = '%s';
	}

	if ( 'sent' === $next_status ) {
		$data['sent_at'] = $now;
		$types[]         = '%s';
	}

	$updated = $wpdb->update( $table, $data, array( 'id' => $campaign_id ), $types, array( '%d' ) );

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event(
			false === $updated ? 'newsletter_campaign_transition_failed' : 'newsletter_campaign_transitioned',
			false === $updated ? 'failure' : 'success',
			0,
			array(
				'campaign_id' => $campaign_id,
				'from_status' => $campaign['status'],
				'to_status'   => $next_status,
			)
		);
	}

	if ( false !== $updated && function_exists( 'newsletter_campaign_kit_sync_queue_for_campaign_transition' ) ) {
		newsletter_campaign_kit_sync_queue_for_campaign_transition( $campaign_id, $next_status );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&transition=' . ( false === $updated ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_transition_campaign', 'newsletter_campaign_kit_handle_transition_campaign' );

function newsletter_campaign_kit_parse_schedule_datetime( $value ) {
	$value = sanitize_text_field( $value );
	if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/', $value ) ) {
		return new WP_Error( 'newsletter_invalid_schedule', __( 'The scheduled date is invalid.', 'newsletter-campaign-kit' ) );
	}

	$date   = DateTimeImmutable::createFromFormat( '!Y-m-d\\TH:i', $value, wp_timezone() );
	$errors = DateTimeImmutable::getLastErrors();
	if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ) {
		return new WP_Error( 'newsletter_invalid_schedule', __( 'The scheduled date is invalid.', 'newsletter-campaign-kit' ) );
	}

	$minimum = new DateTimeImmutable( '+1 minute', wp_timezone() );
	if ( $date < $minimum ) {
		return new WP_Error( 'newsletter_schedule_in_past', __( 'The scheduled date must be in the future.', 'newsletter-campaign-kit' ) );
	}

	return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

function newsletter_campaign_kit_handle_schedule_campaign() {
	if ( ! current_user_can( 'newsletter_send_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to schedule newsletter campaigns.', 'newsletter-campaign-kit' ) );
	}

	$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_schedule_campaign_' . $campaign_id );

	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	$value    = isset( $_POST['scheduled_at'] ) ? wp_unslash( $_POST['scheduled_at'] ) : '';
	$date     = newsletter_campaign_kit_parse_schedule_datetime( $value );
	if ( ! $campaign || ! in_array( $campaign['status'], array( 'ready', 'scheduled' ), true ) || is_wp_error( $date ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&scheduled=invalid' ) );
		exit;
	}

	global $wpdb;
	$table   = newsletter_campaign_kit_get_campaigns_table();
	$updated = $wpdb->update(
		$table,
		array(
			'status'       => 'scheduled',
			'scheduled_at' => $date,
			'updated_by'   => get_current_user_id(),
			'updated_at'   => current_time( 'mysql', true ),
		),
		array( 'id' => $campaign_id, 'status' => $campaign['status'] ),
		array( '%s', '%s', '%d', '%s' ),
		array( '%d', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event(
			false === $updated ? 'newsletter_campaign_schedule_failed' : 'newsletter_campaign_scheduled',
			false === $updated ? 'failure' : 'success',
			0,
			array( 'campaign_id' => $campaign_id, 'scheduled_at' => $date )
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&scheduled=' . ( false === $updated ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_schedule_campaign', 'newsletter_campaign_kit_handle_schedule_campaign' );

function newsletter_campaign_kit_register_campaigns_menu() {
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Campaigns', 'newsletter-campaign-kit' ),
		__( 'Campaigns', 'newsletter-campaign-kit' ),
		'newsletter_create_campaigns',
		'newsletter-campaign-kit-campaigns',
		'newsletter_campaign_kit_render_campaigns_page'
	);
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_campaigns_menu', 15 );

function newsletter_campaign_kit_render_campaign_transition_actions( $campaign ) {
	$transitions = newsletter_campaign_kit_get_allowed_campaign_transitions();
	$next_steps  = isset( $transitions[ $campaign['status'] ] ) ? $transitions[ $campaign['status'] ] : array();

	foreach ( $next_steps as $next_status ) {
		if ( 'scheduled' === $next_status ) {
			continue;
		}
		if ( ! newsletter_campaign_kit_user_can_transition_campaign( $campaign['status'], $next_status ) ) {
			continue;
		}
		?>
		<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="newsletter_campaign_kit_transition_campaign">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign['id'] ); ?>">
			<input type="hidden" name="next_status" value="<?php echo esc_attr( $next_status ); ?>">
			<?php wp_nonce_field( 'newsletter_campaign_kit_transition_campaign_' . absint( $campaign['id'] ) ); ?>
			<button class="button button-small" type="submit"><?php echo esc_html( ucfirst( $next_status ) ); ?></button>
		</form>
		<?php
	}

	if ( in_array( $campaign['status'], array( 'ready', 'scheduled' ), true ) && current_user_can( 'newsletter_send_campaigns' ) ) {
		$scheduled_value = '';
		if ( ! empty( $campaign['scheduled_at'] ) ) {
			$scheduled_value = get_date_from_gmt( $campaign['scheduled_at'], 'Y-m-d\\TH:i' );
		}
		?>
		<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="newsletter_campaign_kit_schedule_campaign">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign['id'] ); ?>">
			<?php wp_nonce_field( 'newsletter_campaign_kit_schedule_campaign_' . absint( $campaign['id'] ) ); ?>
			<input type="datetime-local" name="scheduled_at" value="<?php echo esc_attr( $scheduled_value ); ?>" required>
			<button class="button button-small" type="submit"><?php esc_html_e( 'Schedule', 'newsletter-campaign-kit' ); ?></button>
		</form>
		<?php
	}
}

function newsletter_campaign_kit_render_campaigns_page() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter campaigns.', 'newsletter-campaign-kit' ) );
	}

	$campaigns = newsletter_campaign_kit_get_campaigns();
	$lists     = function_exists( 'newsletter_campaign_kit_get_lists' ) ? newsletter_campaign_kit_get_lists() : array();
	$segments  = function_exists( 'newsletter_campaign_kit_get_segments' ) ? newsletter_campaign_kit_get_segments() : array();
	$topics    = function_exists( 'newsletter_campaign_kit_get_topics' ) ? newsletter_campaign_kit_get_topics() : array();
	$templates = function_exists( 'newsletter_campaign_kit_get_templates' ) ? newsletter_campaign_kit_get_templates() : array();
	$statuses  = newsletter_campaign_kit_get_campaign_statuses();
	$edit_id   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing   = $edit_id ? newsletter_campaign_kit_get_campaign( $edit_id ) : null;
	$editing   = $editing && 'draft' === $editing['status'] ? $editing : null;
	$selected_audience = 'all';
	if ( $editing && ! empty( $editing['target_list_id'] ) ) {
		$selected_audience = 'list:' . absint( $editing['target_list_id'] );
	} elseif ( $editing && ! empty( $editing['target_segment_id'] ) ) {
		$selected_audience = 'segment:' . absint( $editing['target_segment_id'] );
	}
	$list_labels    = array();
	$segment_labels = array();
	$topic_labels   = array();
	foreach ( $lists as $list ) {
		$list_labels[ (int) $list['id'] ] = $list['name'];
	}
	foreach ( $segments as $segment ) {
		$segment_labels[ (int) $segment['id'] ] = $segment['name'];
	}
	foreach ( $topics as $topic ) {
		$topic_labels[ (int) $topic['id'] ] = $topic['name'];
	}
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Campaigns', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Prepare editorial newsletters with controlled server-side states before delivery is enabled.', 'newsletter-campaign-kit' ); ?></p>

		<?php if ( ! newsletter_campaign_kit_campaigns_table_exists() ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Campaign tables are not installed yet. Reactivate or upgrade the plugin with the database available.', 'newsletter-campaign-kit' ); ?></p></div>
		<?php endif; ?>

		<section class="nck-panel">
			<h2><?php echo esc_html( $editing ? __( 'Edit campaign draft', 'newsletter-campaign-kit' ) : __( 'Create campaign draft', 'newsletter-campaign-kit' ) ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $editing ? 'newsletter_campaign_kit_update_campaign' : 'newsletter_campaign_kit_create_campaign' ); ?>">
				<?php if ( $editing ) : ?><input type="hidden" name="campaign_id" value="<?php echo esc_attr( $editing['id'] ); ?>"><?php endif; ?>
				<?php wp_nonce_field( $editing ? 'newsletter_campaign_kit_update_campaign_' . absint( $editing['id'] ) : 'newsletter_campaign_kit_create_campaign' ); ?>
				<p><input class="regular-text" name="campaign_title" required maxlength="190" value="<?php echo esc_attr( $editing ? $editing['title'] : '' ); ?>" placeholder="<?php esc_attr_e( 'July visual letter', 'newsletter-campaign-kit' ); ?>"></p>
				<p><label for="nck-template-id"><?php esc_html_e( 'Start from a template', 'newsletter-campaign-kit' ); ?></label><br><select id="nck-template-id" name="template_id"><option value="0"><?php esc_html_e( 'No template', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $templates as $template ) : ?><option value="<?php echo esc_attr( $template['id'] ); ?>" <?php selected( $editing ? (int) $editing['template_id'] : 0, (int) $template['id'] ); ?>><?php echo esc_html( $template['name'] ); ?></option><?php endforeach; ?></select></p>
				<p><input class="regular-text" name="campaign_subject" maxlength="190" value="<?php echo esc_attr( $editing ? $editing['subject'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Subject override (optional with a template)', 'newsletter-campaign-kit' ); ?>"></p>
				<p><input class="large-text" name="campaign_preview_text" maxlength="255" value="<?php echo esc_attr( $editing ? $editing['preview_text'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Short inbox preview text.', 'newsletter-campaign-kit' ); ?>"></p>
				<p>
					<label class="screen-reader-text" for="nck-target-audience"><?php esc_html_e( 'Campaign audience', 'newsletter-campaign-kit' ); ?></label>
					<select id="nck-target-audience" name="target_audience">
						<option value="all" <?php selected( $selected_audience, 'all' ); ?>><?php esc_html_e( 'All subscribed contacts', 'newsletter-campaign-kit' ); ?></option>
						<?php foreach ( $lists as $list ) : ?>
							<option value="<?php echo esc_attr( 'list:' . $list['id'] ); ?>" <?php selected( $selected_audience, 'list:' . $list['id'] ); ?>><?php echo esc_html( sprintf( __( 'List: %s', 'newsletter-campaign-kit' ), $list['name'] ) ); ?></option>
						<?php endforeach; ?>
						<?php foreach ( $segments as $segment ) : ?>
							<option value="<?php echo esc_attr( 'segment:' . $segment['id'] ); ?>" <?php selected( $selected_audience, 'segment:' . $segment['id'] ); ?>><?php echo esc_html( sprintf( __( 'Dynamic segment: %s', 'newsletter-campaign-kit' ), $segment['name'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label class="screen-reader-text" for="nck-topic-id"><?php esc_html_e( 'Campaign topic', 'newsletter-campaign-kit' ); ?></label>
					<select id="nck-topic-id" name="topic_id">
						<option value="0" <?php selected( $editing ? (int) $editing['topic_id'] : 0, 0 ); ?>><?php esc_html_e( 'No campaign topic', 'newsletter-campaign-kit' ); ?></option>
						<?php foreach ( $topics as $topic ) : ?>
							<option value="<?php echo esc_attr( $topic['id'] ); ?>" <?php selected( $editing ? (int) $editing['topic_id'] : 0, (int) $topic['id'] ); ?>><?php echo esc_html( $topic['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><textarea class="large-text" name="campaign_body" rows="8" placeholder="<?php esc_attr_e( 'Editorial body. Basic safe HTML is allowed.', 'newsletter-campaign-kit' ); ?>"><?php echo esc_textarea( $editing ? $editing['body'] : '' ); ?></textarea></p>
				<p><textarea class="large-text code" name="campaign_text_body" rows="6" placeholder="<?php esc_attr_e( 'Plain-text version. Generated from HTML when left empty.', 'newsletter-campaign-kit' ); ?>"><?php echo esc_textarea( $editing ? $editing['text_body'] : '' ); ?></textarea></p>
				<?php submit_button( $editing ? __( 'Save draft', 'newsletter-campaign-kit' ) : __( 'Create draft', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				<?php if ( $editing ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns' ) ); ?>"><?php esc_html_e( 'Cancel editing', 'newsletter-campaign-kit' ); ?></a><?php endif; ?>
			</form>
		</section>

		<h2><?php esc_html_e( 'Campaign pipeline', 'newsletter-campaign-kit' ); ?></h2>
		<table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Title', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Topic', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Audience', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Recipients', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Scheduled', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Updated', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $campaigns ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No campaign draft yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $campaigns as $campaign ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $campaign['title'] ); ?></strong><br><code><?php echo esc_html( $campaign['slug'] ); ?></code></td>
					<td><?php echo ! empty( $campaign['topic_id'] ) && isset( $topic_labels[ (int) $campaign['topic_id'] ] ) ? esc_html( $topic_labels[ (int) $campaign['topic_id'] ] ) : esc_html__( 'Uncategorized', 'newsletter-campaign-kit' ); ?><br><small><?php echo esc_html( $campaign['subject'] ); ?></small></td>
					<td>
						<?php
						if ( ! empty( $campaign['target_segment_id'] ) && isset( $segment_labels[ (int) $campaign['target_segment_id'] ] ) ) {
							echo esc_html( sprintf( __( 'Segment: %s', 'newsletter-campaign-kit' ), $segment_labels[ (int) $campaign['target_segment_id'] ] ) );
						} elseif ( ! empty( $campaign['target_list_id'] ) && isset( $list_labels[ (int) $campaign['target_list_id'] ] ) ) {
							echo esc_html( sprintf( __( 'List: %s', 'newsletter-campaign-kit' ), $list_labels[ (int) $campaign['target_list_id'] ] ) );
						} else {
							esc_html_e( 'All subscribers', 'newsletter-campaign-kit' );
						}
						?>
					</td>
					<td><?php echo esc_html( number_format_i18n( newsletter_campaign_kit_get_campaign_audience_count( $campaign ) ) ); ?></td>
					<td><code><?php echo esc_html( isset( $statuses[ $campaign['status'] ] ) ? $statuses[ $campaign['status'] ] : $campaign['status'] ); ?></code></td>
					<td><?php echo ! empty( $campaign['scheduled_at'] ) ? esc_html( get_date_from_gmt( $campaign['scheduled_at'], 'Y-m-d H:i' ) ) : esc_html__( 'Not scheduled', 'newsletter-campaign-kit' ); ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $campaign['updated_at'], 'Y-m-d H:i' ) ); ?></td>
					<td><div class="nck-inline-actions"><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_preview&kind=campaign&id=' . absint( $campaign['id'] ) ), 'newsletter_campaign_kit_preview_campaign_' . absint( $campaign['id'] ) ) ); ?>"><?php esc_html_e( 'Preview', 'newsletter-campaign-kit' ); ?></a><?php if ( 'draft' === $campaign['status'] ) : ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', absint( $campaign['id'] ), admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns' ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a><?php endif; ?><form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_duplicate_campaign"><input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign['id'] ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_duplicate_campaign_' . absint( $campaign['id'] ) ); ?><button class="button button-small" type="submit"><?php esc_html_e( 'Duplicate', 'newsletter-campaign-kit' ); ?></button></form><?php newsletter_campaign_kit_render_campaign_transition_actions( $campaign ); ?></div></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<style>.newsletter-campaign-kit-admin .nck-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:18px 0;padding:16px}.newsletter-campaign-kit-admin .nck-inline-actions{display:flex;gap:6px;flex-wrap:wrap}</style>
	<?php
}
