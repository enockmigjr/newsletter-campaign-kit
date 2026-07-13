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

function newsletter_campaign_kit_handle_create_campaign() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to create newsletter campaigns.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_campaign' );

	$title        = isset( $_POST['campaign_title'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign_title'] ) ) : '';
	$content      = newsletter_campaign_kit_resolve_campaign_content(
		array(
			'template_id' => isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
			'subject' => isset( $_POST['campaign_subject'] ) ? wp_unslash( $_POST['campaign_subject'] ) : '',
			'preview_text' => isset( $_POST['campaign_preview_text'] ) ? wp_unslash( $_POST['campaign_preview_text'] ) : '',
			'html_body' => isset( $_POST['campaign_body'] ) ? wp_unslash( $_POST['campaign_body'] ) : '',
			'text_body' => isset( $_POST['campaign_text_body'] ) ? wp_unslash( $_POST['campaign_text_body'] ) : '',
		)
	);
	$audience     = newsletter_campaign_kit_resolve_campaign_audience( isset( $_POST['target_audience'] ) ? wp_unslash( $_POST['target_audience'] ) : 'all' );
	$topic_id     = isset( $_POST['topic_id'] ) ? absint( $_POST['topic_id'] ) : 0;

	if ( '' === $title || is_wp_error( $content ) || is_wp_error( $audience ) || ! newsletter_campaign_kit_campaigns_table_exists() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&created=invalid' ) );
		exit;
	}
	if ( $topic_id && ! newsletter_campaign_kit_record_is_active( newsletter_campaign_kit_get_topics_table(), $topic_id ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&created=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_campaigns_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'title'          => $title,
			'slug'           => newsletter_campaign_kit_generate_unique_slug( $table, $title ),
			'subject'        => $content['subject'],
			'preview_text'   => $content['preview_text'],
			'body'           => $content['html_body'],
			'text_body'      => $content['text_body'],
			'template_id'    => $content['template_id'] ? $content['template_id'] : null,
			'status'         => 'draft',
			'target_list_id'    => $audience['target_list_id'],
			'target_segment_id' => $audience['target_segment_id'],
			'topic_id'          => $topic_id ? $topic_id : null,
			'created_by'     => get_current_user_id(),
			'updated_by'     => get_current_user_id(),
			'created_at'     => $now,
			'updated_at'     => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_campaign_create_failed' : 'newsletter_campaign_created', false === $ok ? 'failure' : 'success', 0, array( 'title' => $title ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-campaigns&created=' . ( false === $ok ? 'failed' : 'campaign' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_campaign', 'newsletter_campaign_kit_handle_create_campaign' );

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
			<h2><?php esc_html_e( 'Create campaign draft', 'newsletter-campaign-kit' ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="newsletter_campaign_kit_create_campaign">
				<?php wp_nonce_field( 'newsletter_campaign_kit_create_campaign' ); ?>
				<p><input class="regular-text" name="campaign_title" required maxlength="190" placeholder="<?php esc_attr_e( 'July visual letter', 'newsletter-campaign-kit' ); ?>"></p>
				<p><label for="nck-template-id"><?php esc_html_e( 'Start from a template', 'newsletter-campaign-kit' ); ?></label><br><select id="nck-template-id" name="template_id"><option value="0"><?php esc_html_e( 'No template', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $templates as $template ) : ?><option value="<?php echo esc_attr( $template['id'] ); ?>"><?php echo esc_html( $template['name'] ); ?></option><?php endforeach; ?></select></p>
				<p><input class="regular-text" name="campaign_subject" maxlength="190" placeholder="<?php esc_attr_e( 'Subject override (optional with a template)', 'newsletter-campaign-kit' ); ?>"></p>
				<p><input class="large-text" name="campaign_preview_text" maxlength="255" placeholder="<?php esc_attr_e( 'Short inbox preview text.', 'newsletter-campaign-kit' ); ?>"></p>
				<p>
					<label class="screen-reader-text" for="nck-target-audience"><?php esc_html_e( 'Campaign audience', 'newsletter-campaign-kit' ); ?></label>
					<select id="nck-target-audience" name="target_audience">
						<option value="all"><?php esc_html_e( 'All subscribed contacts', 'newsletter-campaign-kit' ); ?></option>
						<?php foreach ( $lists as $list ) : ?>
							<option value="<?php echo esc_attr( 'list:' . $list['id'] ); ?>"><?php echo esc_html( sprintf( __( 'List: %s', 'newsletter-campaign-kit' ), $list['name'] ) ); ?></option>
						<?php endforeach; ?>
						<?php foreach ( $segments as $segment ) : ?>
							<option value="<?php echo esc_attr( 'segment:' . $segment['id'] ); ?>"><?php echo esc_html( sprintf( __( 'Dynamic segment: %s', 'newsletter-campaign-kit' ), $segment['name'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label class="screen-reader-text" for="nck-topic-id"><?php esc_html_e( 'Campaign topic', 'newsletter-campaign-kit' ); ?></label>
					<select id="nck-topic-id" name="topic_id">
						<option value="0"><?php esc_html_e( 'No campaign topic', 'newsletter-campaign-kit' ); ?></option>
						<?php foreach ( $topics as $topic ) : ?>
							<option value="<?php echo esc_attr( $topic['id'] ); ?>"><?php echo esc_html( $topic['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><textarea class="large-text" name="campaign_body" rows="8" placeholder="<?php esc_attr_e( 'Editorial body. Basic safe HTML is allowed.', 'newsletter-campaign-kit' ); ?>"></textarea></p>
				<p><textarea class="large-text code" name="campaign_text_body" rows="6" placeholder="<?php esc_attr_e( 'Plain-text version. Generated from HTML when left empty.', 'newsletter-campaign-kit' ); ?>"></textarea></p>
				<?php submit_button( __( 'Create draft', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
			</form>
		</section>

		<h2><?php esc_html_e( 'Campaign pipeline', 'newsletter-campaign-kit' ); ?></h2>
		<table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Title', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Topic', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Audience', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Scheduled', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Updated', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Transitions', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $campaigns ) ) : ?><tr><td colspan="7"><?php esc_html_e( 'No campaign draft yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
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
					<td><code><?php echo esc_html( isset( $statuses[ $campaign['status'] ] ) ? $statuses[ $campaign['status'] ] : $campaign['status'] ); ?></code></td>
					<td><?php echo ! empty( $campaign['scheduled_at'] ) ? esc_html( get_date_from_gmt( $campaign['scheduled_at'], 'Y-m-d H:i' ) ) : esc_html__( 'Not scheduled', 'newsletter-campaign-kit' ); ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $campaign['updated_at'], 'Y-m-d H:i' ) ); ?></td>
					<td><div class="nck-inline-actions"><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_preview&kind=campaign&id=' . absint( $campaign['id'] ) ), 'newsletter_campaign_kit_preview_campaign_' . absint( $campaign['id'] ) ) ); ?>"><?php esc_html_e( 'Preview', 'newsletter-campaign-kit' ); ?></a><?php newsletter_campaign_kit_render_campaign_transition_actions( $campaign ); ?></div></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<style>.newsletter-campaign-kit-admin .nck-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:18px 0;padding:16px}.newsletter-campaign-kit-admin .nck-inline-actions{display:flex;gap:6px;flex-wrap:wrap}</style>
	<?php
}
