<?php
/**
 * Reusable editorial templates and secure previews.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_templates_table_exists() {
	return newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_templates_table() );
}

/** Convert safe email HTML to a readable plain-text alternative. */
function newsletter_campaign_kit_html_to_text( $html ) {
	$html = preg_replace( '/<\s*br\s*\/?>/i', "\n", (string) $html );
	$html = preg_replace( '/<\/(p|div|h[1-6]|li|tr)>/i', "\n\n", (string) $html );
	$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
	$text = preg_replace( "/[ \t]+\n/", "\n", (string) $text );
	$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

	return trim( (string) $text );
}

function newsletter_campaign_kit_sanitize_text_body( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

	return trim( sanitize_textarea_field( $text ) );
}

/** Remove active document blocks before applying WordPress post HTML rules. */
function newsletter_campaign_kit_sanitize_html_body( $html ) {
	$html = preg_replace( '#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', (string) $html );

	return wp_kses_post( (string) $html );
}

function newsletter_campaign_kit_get_template( $template_id ) {
	global $wpdb;

	$template_id = absint( $template_id );
	if ( ! $template_id || ! newsletter_campaign_kit_templates_table_exists() ) {
		return null;
	}

	$table = newsletter_campaign_kit_get_templates_table();

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $template_id ), ARRAY_A );
}

function newsletter_campaign_kit_get_templates( $include_archived = false, $limit = 100, $offset = 0 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_templates_table_exists() ) {
		return array();
	}

	$table = newsletter_campaign_kit_get_templates_table();
	$limit = max( 1, min( 100, absint( $limit ) ) );
	$offset = absint( $offset );
	if ( $include_archived ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
	}

	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", 'active', $limit, $offset ), ARRAY_A );
}

function newsletter_campaign_kit_count_templates( $include_archived = false ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_templates_table_exists() ) {
		return 0;
	}

	$table = newsletter_campaign_kit_get_templates_table();
	if ( $include_archived ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'active' ) );
}

/** Return the immutable definitions used to initialize a useful template library. */
function newsletter_campaign_kit_get_default_template_definitions() {
	return array(
		'editorial-letter' => array(
			'name'         => __( 'Editorial letter', 'newsletter-campaign-kit' ),
			'subject'      => __( 'A new story from the archive', 'newsletter-campaign-kit' ),
			'preview_text' => __( 'A selection of images, notes and recent work.', 'newsletter-campaign-kit' ),
			'html_body'    => '<h1>' . esc_html__( 'A new story begins here', 'newsletter-campaign-kit' ) . '</h1><p>' . esc_html__( 'Share the opening note of this edition, then introduce the photographs, places or people at its centre.', 'newsletter-campaign-kit' ) . '</p><p><strong>' . esc_html__( 'Selected work', 'newsletter-campaign-kit' ) . '</strong></p><p>' . esc_html__( 'Add the story, link or invitation that should remain with the reader.', 'newsletter-campaign-kit' ) . '</p>',
		),
		'new-collection' => array(
			'name'         => __( 'New collection', 'newsletter-campaign-kit' ),
			'subject'      => __( 'Discover the latest collection', 'newsletter-campaign-kit' ),
			'preview_text' => __( 'A new visual series is now available.', 'newsletter-campaign-kit' ),
			'html_body'    => '<h1>' . esc_html__( 'A collection enters the archive', 'newsletter-campaign-kit' ) . '</h1><p>' . esc_html__( 'Introduce the intention, territory and period behind the series.', 'newsletter-campaign-kit' ) . '</p><p><strong>' . esc_html__( 'Collection details', 'newsletter-campaign-kit' ) . '</strong></p><p>' . esc_html__( 'Add the number of works, location, year and access conditions.', 'newsletter-campaign-kit' ) . '</p>',
		),
		'journal-dispatch' => array(
			'name'         => __( 'Journal dispatch', 'newsletter-campaign-kit' ),
			'subject'      => __( 'Notes from the visual journal', 'newsletter-campaign-kit' ),
			'preview_text' => __( 'A recent field note from the studio.', 'newsletter-campaign-kit' ),
			'html_body'    => '<h1>' . esc_html__( 'From the visual journal', 'newsletter-campaign-kit' ) . '</h1><p>' . esc_html__( 'Open with the observation, encounter or working note that shaped this entry.', 'newsletter-campaign-kit' ) . '</p><blockquote>' . esc_html__( 'Add one short passage that carries the voice of the journal.', 'newsletter-campaign-kit' ) . '</blockquote><p>' . esc_html__( 'Continue the story and direct readers to the complete article.', 'newsletter-campaign-kit' ) . '</p>',
		),
		'private-invitation' => array(
			'name'         => __( 'Private invitation', 'newsletter-campaign-kit' ),
			'subject'      => __( 'Your invitation to a private viewing', 'newsletter-campaign-kit' ),
			'preview_text' => __( 'A protected collection is ready for you.', 'newsletter-campaign-kit' ),
			'html_body'    => '<h1>' . esc_html__( 'A private viewing', 'newsletter-campaign-kit' ) . '</h1><p>' . esc_html__( 'Explain why this recipient is invited and what the protected selection contains.', 'newsletter-campaign-kit' ) . '</p><p><strong>' . esc_html__( 'Access details', 'newsletter-campaign-kit' ) . '</strong></p><p>' . esc_html__( 'Add the validity period and the secure destination supplied by your access workflow.', 'newsletter-campaign-kit' ) . '</p>',
		),
		'event-announcement' => array(
			'name'         => __( 'Exhibition or event', 'newsletter-campaign-kit' ),
			'subject'      => __( 'An upcoming exhibition and gathering', 'newsletter-campaign-kit' ),
			'preview_text' => __( 'Dates, place and practical information.', 'newsletter-campaign-kit' ),
			'html_body'    => '<h1>' . esc_html__( 'Meet the work in person', 'newsletter-campaign-kit' ) . '</h1><p>' . esc_html__( 'Present the exhibition, publication or collaboration in a few direct lines.', 'newsletter-campaign-kit' ) . '</p><p><strong>' . esc_html__( 'Date and location', 'newsletter-campaign-kit' ) . '</strong></p><p>' . esc_html__( 'Add the programme, address and reservation information.', 'newsletter-campaign-kit' ) . '</p>',
		),
	);
}

/** Seed missing defaults once without replacing administrator customizations. */
function newsletter_campaign_kit_seed_default_templates() {
	global $wpdb;

	if ( ! newsletter_campaign_kit_templates_table_exists() ) {
		return 0;
	}

	$table   = newsletter_campaign_kit_get_templates_table();
	$created = 0;
	$now     = current_time( 'mysql', true );
	foreach ( newsletter_campaign_kit_get_default_template_definitions() as $slug => $definition ) {
		$slug = sanitize_title( $slug );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ) ) ) {
			continue;
		}
		$data = newsletter_campaign_kit_prepare_template_data( $definition );
		if ( is_wp_error( $data ) ) {
			continue;
		}
		$inserted = $wpdb->insert(
			$table,
			array_merge(
				$data,
				array(
					'slug'       => $slug,
					'status'     => 'active',
					'created_by' => 0,
					'updated_by' => 0,
					'created_at' => $now,
					'updated_at' => $now,
				)
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( false !== $inserted ) {
			++$created;
		}
	}

	return $created;
}

/** Return the preferred starting template for a new campaign. */
function newsletter_campaign_kit_get_default_template_id() {
	global $wpdb;

	if ( ! newsletter_campaign_kit_templates_table_exists() ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT id FROM ' . newsletter_campaign_kit_get_templates_table() . ' WHERE slug = %s AND status = %s LIMIT 1',
			'editorial-letter',
			'active'
		)
	);
}

/** Validate and normalize template input before persistence. */
function newsletter_campaign_kit_prepare_template_data( $input ) {
	$name         = isset( $input['name'] ) ? substr( sanitize_text_field( $input['name'] ), 0, 190 ) : '';
	$subject      = isset( $input['subject'] ) ? substr( sanitize_text_field( $input['subject'] ), 0, 190 ) : '';
	$preview_text = isset( $input['preview_text'] ) ? substr( sanitize_text_field( $input['preview_text'] ), 0, 255 ) : '';
	$html_body    = isset( $input['html_body'] ) ? newsletter_campaign_kit_sanitize_html_body( $input['html_body'] ) : '';
	$text_body    = isset( $input['text_body'] ) ? newsletter_campaign_kit_sanitize_text_body( $input['text_body'] ) : '';

	if ( '' === $name || '' === $subject || '' === trim( $html_body ) ) {
		return new WP_Error( 'newsletter_invalid_template', __( 'Name, subject and HTML content are required.', 'newsletter-campaign-kit' ) );
	}
	if ( '' === $text_body ) {
		$text_body = newsletter_campaign_kit_html_to_text( $html_body );
	}

	return array(
		'name'         => $name,
		'subject'      => $subject,
		'preview_text' => $preview_text,
		'html_body'    => $html_body,
		'text_body'    => $text_body,
	);
}

function newsletter_campaign_kit_create_template( $input, $actor_user_id = 0 ) {
	global $wpdb;

	$data = newsletter_campaign_kit_prepare_template_data( $input );
	if ( is_wp_error( $data ) || ! newsletter_campaign_kit_templates_table_exists() ) {
		return is_wp_error( $data ) ? $data : new WP_Error( 'newsletter_template_storage_unavailable', __( 'Template storage is unavailable.', 'newsletter-campaign-kit' ) );
	}

	$table = newsletter_campaign_kit_get_templates_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array_merge(
			$data,
			array(
				'slug'       => newsletter_campaign_kit_generate_unique_slug( $table, $data['name'] ),
				'status'     => 'active',
				'created_by' => absint( $actor_user_id ),
				'updated_by' => absint( $actor_user_id ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
	);

	return false === $ok ? new WP_Error( 'newsletter_template_create_failed', __( 'The template could not be created.', 'newsletter-campaign-kit' ) ) : (int) $wpdb->insert_id;
}

function newsletter_campaign_kit_update_template( $template_id, $input, $actor_user_id = 0 ) {
	global $wpdb;

	$template = newsletter_campaign_kit_get_template( $template_id );
	$data     = newsletter_campaign_kit_prepare_template_data( $input );
	if ( ! $template || is_wp_error( $data ) ) {
		return is_wp_error( $data ) ? $data : new WP_Error( 'newsletter_template_not_found', __( 'Template not found.', 'newsletter-campaign-kit' ) );
	}

	$data['updated_by'] = absint( $actor_user_id );
	$data['updated_at'] = current_time( 'mysql', true );
	$updated            = $wpdb->update( newsletter_campaign_kit_get_templates_table(), $data, array( 'id' => absint( $template_id ) ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ), array( '%d' ) );

	return false === $updated ? new WP_Error( 'newsletter_template_update_failed', __( 'The template could not be updated.', 'newsletter-campaign-kit' ) ) : true;
}

function newsletter_campaign_kit_duplicate_template( $template_id, $actor_user_id = 0 ) {
	$template = newsletter_campaign_kit_get_template( $template_id );
	if ( ! $template ) {
		return new WP_Error( 'newsletter_template_not_found', __( 'Template not found.', 'newsletter-campaign-kit' ) );
	}

	return newsletter_campaign_kit_create_template(
		array(
			'name'         => sprintf( __( '%s copy', 'newsletter-campaign-kit' ), $template['name'] ),
			'subject'      => $template['subject'],
			'preview_text' => $template['preview_text'],
			'html_body'    => $template['html_body'],
			'text_body'    => $template['text_body'],
		),
		$actor_user_id
	);
}

function newsletter_campaign_kit_set_template_status( $template_id, $status, $actor_user_id = 0 ) {
	global $wpdb;

	if ( ! in_array( $status, array( 'active', 'archived' ), true ) || ! newsletter_campaign_kit_get_template( $template_id ) ) {
		return new WP_Error( 'newsletter_invalid_template_status', __( 'The template status is invalid.', 'newsletter-campaign-kit' ) );
	}

	$updated = $wpdb->update(
		newsletter_campaign_kit_get_templates_table(),
		array( 'status' => $status, 'updated_by' => absint( $actor_user_id ), 'updated_at' => current_time( 'mysql', true ) ),
		array( 'id' => absint( $template_id ) ),
		array( '%s', '%d', '%s' ),
		array( '%d' )
	);

	return false === $updated ? new WP_Error( 'newsletter_template_status_failed', __( 'The template status could not be changed.', 'newsletter-campaign-kit' ) ) : true;
}

/** Resolve template defaults while preserving explicit campaign overrides. */
function newsletter_campaign_kit_resolve_campaign_content( $input ) {
	$template_id = isset( $input['template_id'] ) ? absint( $input['template_id'] ) : 0;
	$template    = $template_id ? newsletter_campaign_kit_get_template( $template_id ) : null;
	if ( $template_id && ( ! $template || 'active' !== $template['status'] ) ) {
		return new WP_Error( 'newsletter_template_unavailable', __( 'The selected template is unavailable.', 'newsletter-campaign-kit' ) );
	}

	$subject      = isset( $input['subject'] ) ? substr( sanitize_text_field( $input['subject'] ), 0, 190 ) : '';
	$preview_text = isset( $input['preview_text'] ) ? substr( sanitize_text_field( $input['preview_text'] ), 0, 255 ) : '';
	$html_body    = isset( $input['html_body'] ) ? newsletter_campaign_kit_sanitize_html_body( $input['html_body'] ) : '';
	$text_body    = isset( $input['text_body'] ) ? newsletter_campaign_kit_sanitize_text_body( $input['text_body'] ) : '';

	$subject      = '' !== $subject ? $subject : ( $template['subject'] ?? '' );
	$preview_text = '' !== $preview_text ? $preview_text : ( $template['preview_text'] ?? '' );
	$html_body    = '' !== trim( $html_body ) ? $html_body : ( $template['html_body'] ?? '' );
	$text_body    = '' !== $text_body ? $text_body : ( $template['text_body'] ?? '' );
	$text_body    = '' !== $text_body ? $text_body : newsletter_campaign_kit_html_to_text( $html_body );

	if ( '' === $subject || '' === trim( $html_body ) ) {
		return new WP_Error( 'newsletter_missing_campaign_content', __( 'A subject and HTML content are required.', 'newsletter-campaign-kit' ) );
	}

	return compact( 'template_id', 'subject', 'preview_text', 'html_body', 'text_body' );
}

function newsletter_campaign_kit_handle_save_template() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter templates.', 'newsletter-campaign-kit' ) );
	}

	$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_save_template_' . $template_id );
	$input = array(
		'name'         => isset( $_POST['template_name'] ) ? wp_unslash( $_POST['template_name'] ) : '',
		'subject'      => isset( $_POST['template_subject'] ) ? wp_unslash( $_POST['template_subject'] ) : '',
		'preview_text' => isset( $_POST['template_preview_text'] ) ? wp_unslash( $_POST['template_preview_text'] ) : '',
		'html_body'    => isset( $_POST['template_html_body'] ) ? wp_unslash( $_POST['template_html_body'] ) : '',
		'text_body'    => isset( $_POST['template_text_body'] ) ? wp_unslash( $_POST['template_text_body'] ) : '',
	);
	$result = $template_id ? newsletter_campaign_kit_update_template( $template_id, $input, get_current_user_id() ) : newsletter_campaign_kit_create_template( $input, get_current_user_id() );
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event(
			is_wp_error( $result ) ? 'newsletter_template_save_failed' : ( $template_id ? 'newsletter_template_updated' : 'newsletter_template_created' ),
			is_wp_error( $result ) ? 'failure' : 'success',
			0,
			array( 'template_id' => $template_id ? $template_id : absint( $result ) )
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-templates&saved=' . ( is_wp_error( $result ) ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_save_template', 'newsletter_campaign_kit_handle_save_template' );

function newsletter_campaign_kit_handle_template_action() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter templates.', 'newsletter-campaign-kit' ) );
	}

	$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
	$operation   = isset( $_POST['template_operation'] ) ? sanitize_key( wp_unslash( $_POST['template_operation'] ) ) : '';
	check_admin_referer( 'newsletter_campaign_kit_template_action_' . $template_id );
	if ( ! in_array( $operation, array( 'duplicate', 'archive', 'restore' ), true ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-templates&action_result=invalid' ) );
		exit;
	}
	if ( 'duplicate' === $operation ) {
		$result = newsletter_campaign_kit_duplicate_template( $template_id, get_current_user_id() );
	} else {
		$result = newsletter_campaign_kit_set_template_status( $template_id, 'restore' === $operation ? 'active' : 'archived', get_current_user_id() );
	}
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event(
			is_wp_error( $result ) ? 'newsletter_template_action_failed' : 'newsletter_template_action_completed',
			is_wp_error( $result ) ? 'failure' : 'success',
			0,
			array( 'template_id' => $template_id, 'operation' => $operation )
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-templates&action_result=' . ( is_wp_error( $result ) ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_template_action', 'newsletter_campaign_kit_handle_template_action' );

function newsletter_campaign_kit_register_templates_menu() {
	add_submenu_page( 'newsletter-campaign-kit', __( 'Email templates', 'newsletter-campaign-kit' ), __( 'Templates', 'newsletter-campaign-kit' ), 'newsletter_create_campaigns', 'newsletter-campaign-kit-templates', 'newsletter_campaign_kit_render_templates_page' );
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_templates_menu', 14 );

function newsletter_campaign_kit_render_templates_page() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter templates.', 'newsletter-campaign-kit' ) );
	}

	$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing  = $edit_id ? newsletter_campaign_kit_get_template( $edit_id ) : null;
	$defaults = array( 'id' => 0, 'name' => '', 'subject' => '', 'preview_text' => '', 'html_body' => '', 'text_body' => '' );
	$form     = wp_parse_args( $editing ?: array(), $defaults );
	$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page     = 25;
	$templates    = newsletter_campaign_kit_get_templates( true, $per_page, ( $current_page - 1 ) * $per_page );
	$total        = newsletter_campaign_kit_count_templates( true );
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Email templates', 'newsletter-campaign-kit' ); ?></h1>
		<section class="nck-panel">
			<h2><?php echo esc_html( $editing ? __( 'Edit template', 'newsletter-campaign-kit' ) : __( 'Create a reusable template', 'newsletter-campaign-kit' ) ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-form">
				<input type="hidden" name="action" value="newsletter_campaign_kit_save_template">
				<input type="hidden" name="template_id" value="<?php echo esc_attr( $form['id'] ); ?>">
				<?php wp_nonce_field( 'newsletter_campaign_kit_save_template_' . absint( $form['id'] ) ); ?>
				<p><label><?php esc_html_e( 'Template name', 'newsletter-campaign-kit' ); ?><br><input class="regular-text" name="template_name" maxlength="190" required value="<?php echo esc_attr( $form['name'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Default subject', 'newsletter-campaign-kit' ); ?><br><input class="large-text" name="template_subject" maxlength="190" required value="<?php echo esc_attr( $form['subject'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Inbox preview', 'newsletter-campaign-kit' ); ?><br><input class="large-text" name="template_preview_text" maxlength="255" value="<?php echo esc_attr( $form['preview_text'] ); ?>"></label></p>
				<h3><?php esc_html_e( 'HTML version', 'newsletter-campaign-kit' ); ?></h3>
				<?php wp_editor( $form['html_body'], 'ncktemplatehtml', array( 'textarea_name' => 'template_html_body', 'textarea_rows' => 12, 'media_buttons' => false ) ); ?>
				<p><label><?php esc_html_e( 'Plain-text version', 'newsletter-campaign-kit' ); ?><br><textarea class="large-text code" name="template_text_body" rows="9"><?php echo esc_textarea( $form['text_body'] ); ?></textarea></label></p>
				<p class="description"><?php esc_html_e( 'Leave plain text empty to generate it from the HTML content.', 'newsletter-campaign-kit' ); ?></p>
				<?php submit_button( $editing ? __( 'Update template', 'newsletter-campaign-kit' ) : __( 'Create template', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
			</form>
		</section>
		<h2><?php esc_html_e( 'Template library', 'newsletter-campaign-kit' ); ?></h2>
		<div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subject', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $templates ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No reusable template yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $templates as $template ) : ?>
			<tr><td><strong><?php echo esc_html( $template['name'] ); ?></strong></td><td><?php echo esc_html( $template['subject'] ); ?></td><td><?php echo esc_html( $template['status'] ); ?></td><td><div class="nck-inline-actions">
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-templates&edit=' . absint( $template['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a>
				<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_preview&kind=template&id=' . absint( $template['id'] ) ), 'newsletter_campaign_kit_preview_template_' . absint( $template['id'] ) ) ); ?>"><?php esc_html_e( 'Preview', 'newsletter-campaign-kit' ); ?></a>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_template_action"><input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>"><input type="hidden" name="template_operation" value="duplicate"><?php wp_nonce_field( 'newsletter_campaign_kit_template_action_' . absint( $template['id'] ) ); ?><button class="button button-small"><?php esc_html_e( 'Duplicate', 'newsletter-campaign-kit' ); ?></button></form>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_template_action"><input type="hidden" name="template_id" value="<?php echo esc_attr( $template['id'] ); ?>"><input type="hidden" name="template_operation" value="<?php echo esc_attr( 'active' === $template['status'] ? 'archive' : 'restore' ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_template_action_' . absint( $template['id'] ) ); ?><button class="button button-small"><?php echo esc_html( 'active' === $template['status'] ? __( 'Archive', 'newsletter-campaign-kit' ) : __( 'Restore', 'newsletter-campaign-kit' ) ); ?></button></form>
			</div></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php newsletter_campaign_kit_render_pagination( $current_page, $total, $per_page, array( 'page' => 'newsletter-campaign-kit-templates' ) ); ?>
	</div>
	<style>.newsletter-campaign-kit-admin .nck-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:18px 0;padding:16px}.newsletter-campaign-kit-admin .nck-inline-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.newsletter-campaign-kit-admin .nck-inline-actions form{margin:0}</style>
	<?php
}

function newsletter_campaign_kit_handle_preview() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to preview newsletter content.', 'newsletter-campaign-kit' ), '', array( 'response' => 403 ) );
	}

	$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
	$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	if ( ! in_array( $kind, array( 'template', 'campaign', 'block' ), true ) || ! $id ) {
		wp_die( esc_html__( 'Preview request is invalid.', 'newsletter-campaign-kit' ), '', array( 'response' => 400 ) );
	}
	check_admin_referer( 'newsletter_campaign_kit_preview_' . $kind . '_' . $id );
	if ( 'template' === $kind ) {
		$record = newsletter_campaign_kit_get_template( $id );
		$content = $record ? array( 'subject' => $record['subject'], 'preview_text' => $record['preview_text'], 'html_body' => $record['html_body'], 'text_body' => $record['text_body'] ) : null;
	} elseif ( 'block' === $kind ) {
		$record = function_exists( 'newsletter_campaign_kit_get_block' ) ? newsletter_campaign_kit_get_block( $id ) : null;
		$content = $record ? array( 'subject' => $record['name'], 'preview_text' => $record['category'], 'html_body' => $record['html_body'], 'text_body' => $record['text_body'] ) : null;
	} else {
		$record = function_exists( 'newsletter_campaign_kit_get_campaign' ) ? newsletter_campaign_kit_get_campaign( $id ) : null;
		$content = $record ? array( 'subject' => $record['subject'], 'preview_text' => $record['preview_text'], 'html_body' => $record['body'], 'text_body' => $record['text_body'] ?? '' ) : null;
	}
	if ( ! $content ) {
		wp_die( esc_html__( 'Preview content not found.', 'newsletter-campaign-kit' ), '', array( 'response' => 404 ) );
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	header( 'Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'; frame-ancestors \'none\'' );
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: DENY' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	$text_body = '' !== trim( $content['text_body'] ) ? $content['text_body'] : newsletter_campaign_kit_html_to_text( $content['html_body'] );
	?><!doctype html><html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>"><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $content['subject'] ); ?></title><style>body{margin:0;background:#f0f0f1;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{max-width:760px;margin:32px auto;background:#fff;border:1px solid #dcdcde}.meta{padding:20px 24px;border-bottom:1px solid #dcdcde}.meta p{margin:4px 0}.email{padding:32px}.plain{margin:24px;padding:20px;background:#f6f7f7;white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:800px){.shell{margin:0;border:0}.email{padding:20px}.plain{margin:16px}}</style></head><body><main class="shell"><header class="meta"><strong><?php echo esc_html( $content['subject'] ); ?></strong><p><?php echo esc_html( $content['preview_text'] ); ?></p></header><article class="email"><?php echo wp_kses_post( $content['html_body'] ); ?></article><section class="plain" aria-label="<?php esc_attr_e( 'Plain-text version', 'newsletter-campaign-kit' ); ?>"><?php echo esc_html( $text_body ); ?></section></main></body></html><?php
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_preview', 'newsletter_campaign_kit_handle_preview' );
