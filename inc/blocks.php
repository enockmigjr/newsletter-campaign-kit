<?php
/**
 * Reusable editorial content blocks for newsletter campaigns.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_blocks_table_exists() {
	return newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_blocks_table() );
}

/** Return the bounded block categories exposed to integrations. */
function newsletter_campaign_kit_get_block_categories() {
	$categories = array(
		'header'  => __( 'Header', 'newsletter-campaign-kit' ),
		'content' => __( 'Content', 'newsletter-campaign-kit' ),
		'image'   => __( 'Image', 'newsletter-campaign-kit' ),
		'cta'     => __( 'Call to action', 'newsletter-campaign-kit' ),
		'quote'   => __( 'Quote', 'newsletter-campaign-kit' ),
		'divider' => __( 'Divider', 'newsletter-campaign-kit' ),
		'footer'  => __( 'Footer', 'newsletter-campaign-kit' ),
	);
	$filtered = apply_filters( 'newsletter_campaign_kit_block_categories', $categories );
	if ( ! is_array( $filtered ) ) {
		return $categories;
	}

	$result = array();
	foreach ( $filtered as $key => $label ) {
		$key   = substr( sanitize_key( $key ), 0, 80 );
		$label = substr( sanitize_text_field( $label ), 0, 80 );
		if ( '' !== $key && '' !== $label ) {
			$result[ $key ] = $label;
		}
	}

	return $result ?: $categories;
}

function newsletter_campaign_kit_get_block( $block_id ) {
	global $wpdb;

	$block_id = absint( $block_id );
	if ( ! $block_id || ! newsletter_campaign_kit_blocks_table_exists() ) {
		return null;
	}

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . newsletter_campaign_kit_get_blocks_table() . ' WHERE id = %d LIMIT 1', $block_id ), ARRAY_A );
}

function newsletter_campaign_kit_get_blocks( $include_archived = false, $limit = 200, $offset = 0 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_blocks_table_exists() ) {
		return array();
	}
	$table = newsletter_campaign_kit_get_blocks_table();
	$limit = max( 1, min( 200, absint( $limit ) ) );
	$offset = absint( $offset );
	if ( $include_archived ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY category ASC, updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
	}

	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY category ASC, name ASC LIMIT %d OFFSET %d", 'active', $limit, $offset ), ARRAY_A );
}

function newsletter_campaign_kit_count_blocks( $include_archived = false ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_blocks_table_exists() ) {
		return 0;
	}

	$table = newsletter_campaign_kit_get_blocks_table();
	if ( $include_archived ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'active' ) );
}

/** Validate and normalize one reusable block. */
function newsletter_campaign_kit_prepare_block_data( $input ) {
	$categories = newsletter_campaign_kit_get_block_categories();
	$name       = substr( sanitize_text_field( $input['name'] ?? '' ), 0, 190 );
	$category   = sanitize_key( $input['category'] ?? 'content' );
	$html_body  = newsletter_campaign_kit_sanitize_html_body( $input['html_body'] ?? '' );
	$text_body  = newsletter_campaign_kit_sanitize_text_body( $input['text_body'] ?? '' );

	if ( '' === $name || '' === trim( $html_body ) ) {
		return new WP_Error( 'newsletter_invalid_block', __( 'Block name and HTML content are required.', 'newsletter-campaign-kit' ) );
	}
	if ( ! isset( $categories[ $category ] ) ) {
		return new WP_Error( 'newsletter_invalid_block_category', __( 'The block category is invalid.', 'newsletter-campaign-kit' ) );
	}
	if ( '' === $text_body ) {
		$text_body = newsletter_campaign_kit_html_to_text( $html_body );
	}

	return compact( 'name', 'category', 'html_body', 'text_body' );
}

function newsletter_campaign_kit_create_block( $input, $actor_user_id = 0 ) {
	global $wpdb;

	$data = newsletter_campaign_kit_prepare_block_data( $input );
	if ( is_wp_error( $data ) || ! newsletter_campaign_kit_blocks_table_exists() ) {
		return is_wp_error( $data ) ? $data : new WP_Error( 'newsletter_block_storage_unavailable', __( 'Block storage is unavailable.', 'newsletter-campaign-kit' ) );
	}
	$table = newsletter_campaign_kit_get_blocks_table();
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
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
	);

	return false === $ok ? new WP_Error( 'newsletter_block_create_failed', __( 'The block could not be created.', 'newsletter-campaign-kit' ) ) : (int) $wpdb->insert_id;
}

function newsletter_campaign_kit_update_block( $block_id, $input, $actor_user_id = 0 ) {
	global $wpdb;

	$block = newsletter_campaign_kit_get_block( $block_id );
	$data  = newsletter_campaign_kit_prepare_block_data( $input );
	if ( ! $block || is_wp_error( $data ) ) {
		return is_wp_error( $data ) ? $data : new WP_Error( 'newsletter_block_not_found', __( 'Block not found.', 'newsletter-campaign-kit' ) );
	}
	$data['updated_by'] = absint( $actor_user_id );
	$data['updated_at'] = current_time( 'mysql', true );
	$updated            = $wpdb->update( newsletter_campaign_kit_get_blocks_table(), $data, array( 'id' => absint( $block_id ) ), array( '%s', '%s', '%s', '%s', '%d', '%s' ), array( '%d' ) );

	return false === $updated ? new WP_Error( 'newsletter_block_update_failed', __( 'The block could not be updated.', 'newsletter-campaign-kit' ) ) : true;
}

function newsletter_campaign_kit_duplicate_block( $block_id, $actor_user_id = 0 ) {
	$block = newsletter_campaign_kit_get_block( $block_id );
	if ( ! $block ) {
		return new WP_Error( 'newsletter_block_not_found', __( 'Block not found.', 'newsletter-campaign-kit' ) );
	}

	return newsletter_campaign_kit_create_block(
		array(
			'name'      => sprintf( __( '%s copy', 'newsletter-campaign-kit' ), $block['name'] ),
			'category'  => $block['category'],
			'html_body' => $block['html_body'],
			'text_body' => $block['text_body'],
		),
		$actor_user_id
	);
}

function newsletter_campaign_kit_set_block_status( $block_id, $status, $actor_user_id = 0 ) {
	global $wpdb;

	if ( ! in_array( $status, array( 'active', 'archived' ), true ) || ! newsletter_campaign_kit_get_block( $block_id ) ) {
		return new WP_Error( 'newsletter_invalid_block_status', __( 'The block status is invalid.', 'newsletter-campaign-kit' ) );
	}
	$updated = $wpdb->update(
		newsletter_campaign_kit_get_blocks_table(),
		array( 'status' => $status, 'updated_by' => absint( $actor_user_id ), 'updated_at' => current_time( 'mysql', true ) ),
		array( 'id' => absint( $block_id ) ),
		array( '%s', '%d', '%s' ),
		array( '%d' )
	);

	return false === $updated ? new WP_Error( 'newsletter_block_status_failed', __( 'The block status could not be changed.', 'newsletter-campaign-kit' ) ) : true;
}

function newsletter_campaign_kit_handle_save_block() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter blocks.', 'newsletter-campaign-kit' ) );
	}
	$block_id = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
	check_admin_referer( 'newsletter_campaign_kit_save_block_' . $block_id );
	$input = array(
		'name'      => isset( $_POST['block_name'] ) ? wp_unslash( $_POST['block_name'] ) : '',
		'category'  => isset( $_POST['block_category'] ) ? wp_unslash( $_POST['block_category'] ) : 'content',
		'html_body' => isset( $_POST['block_html_body'] ) ? wp_unslash( $_POST['block_html_body'] ) : '',
		'text_body' => isset( $_POST['block_text_body'] ) ? wp_unslash( $_POST['block_text_body'] ) : '',
	);
	$result = $block_id ? newsletter_campaign_kit_update_block( $block_id, $input, get_current_user_id() ) : newsletter_campaign_kit_create_block( $input, get_current_user_id() );
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( is_wp_error( $result ) ? 'newsletter_block_save_failed' : ( $block_id ? 'newsletter_block_updated' : 'newsletter_block_created' ), is_wp_error( $result ) ? 'failure' : 'success', 0, array( 'block_id' => $block_id ?: absint( $result ) ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-blocks&saved=' . ( is_wp_error( $result ) ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_save_block', 'newsletter_campaign_kit_handle_save_block' );

function newsletter_campaign_kit_handle_block_action() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter blocks.', 'newsletter-campaign-kit' ) );
	}
	$block_id  = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
	$operation = isset( $_POST['block_operation'] ) ? sanitize_key( wp_unslash( $_POST['block_operation'] ) ) : '';
	check_admin_referer( 'newsletter_campaign_kit_block_action_' . $block_id );
	if ( ! in_array( $operation, array( 'duplicate', 'archive', 'restore' ), true ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-blocks&action_result=invalid' ) );
		exit;
	}
	$result = 'duplicate' === $operation
		? newsletter_campaign_kit_duplicate_block( $block_id, get_current_user_id() )
		: newsletter_campaign_kit_set_block_status( $block_id, 'restore' === $operation ? 'active' : 'archived', get_current_user_id() );
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( is_wp_error( $result ) ? 'newsletter_block_action_failed' : 'newsletter_block_action_completed', is_wp_error( $result ) ? 'failure' : 'success', 0, array( 'block_id' => $block_id, 'operation' => $operation ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-blocks&action_result=' . ( is_wp_error( $result ) ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_block_action', 'newsletter_campaign_kit_handle_block_action' );

function newsletter_campaign_kit_register_blocks_menu() {
	add_submenu_page( 'newsletter-campaign-kit', __( 'Editorial blocks', 'newsletter-campaign-kit' ), __( 'Blocks', 'newsletter-campaign-kit' ), 'newsletter_create_campaigns', 'newsletter-campaign-kit-blocks', 'newsletter_campaign_kit_render_blocks_page' );
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_blocks_menu', 14 );

function newsletter_campaign_kit_render_blocks_page() {
	if ( ! current_user_can( 'newsletter_create_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter blocks.', 'newsletter-campaign-kit' ) );
	}
	$edit_id    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$editing    = $edit_id ? newsletter_campaign_kit_get_block( $edit_id ) : null;
	$form       = wp_parse_args( $editing ?: array(), array( 'id' => 0, 'name' => '', 'category' => 'content', 'html_body' => '', 'text_body' => '' ) );
	$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page     = 25;
	$blocks       = newsletter_campaign_kit_get_blocks( true, $per_page, ( $current_page - 1 ) * $per_page );
	$total        = newsletter_campaign_kit_count_blocks( true );
	$categories = newsletter_campaign_kit_get_block_categories();
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Editorial blocks', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Build reusable fragments that can be inserted into any campaign without replacing its full template.', 'newsletter-campaign-kit' ); ?></p>
		<section class="nck-panel">
			<h2><?php echo esc_html( $editing ? __( 'Edit block', 'newsletter-campaign-kit' ) : __( 'Create a reusable block', 'newsletter-campaign-kit' ) ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="newsletter_campaign_kit_save_block"><input type="hidden" name="block_id" value="<?php echo esc_attr( $form['id'] ); ?>">
				<?php wp_nonce_field( 'newsletter_campaign_kit_save_block_' . absint( $form['id'] ) ); ?>
				<p><label><?php esc_html_e( 'Block name', 'newsletter-campaign-kit' ); ?><br><input class="regular-text" name="block_name" maxlength="190" required value="<?php echo esc_attr( $form['name'] ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Category', 'newsletter-campaign-kit' ); ?><br><select name="block_category"><?php foreach ( $categories as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $form['category'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
				<h3><?php esc_html_e( 'HTML fragment', 'newsletter-campaign-kit' ); ?></h3>
				<?php wp_editor( $form['html_body'], 'nckblockhtml', array( 'textarea_name' => 'block_html_body', 'textarea_rows' => 10, 'media_buttons' => false ) ); ?>
				<p><label><?php esc_html_e( 'Plain-text fragment', 'newsletter-campaign-kit' ); ?><br><textarea class="large-text code" name="block_text_body" rows="7"><?php echo esc_textarea( $form['text_body'] ); ?></textarea></label></p>
				<p class="description"><?php esc_html_e( 'Leave plain text empty to generate it safely from the HTML fragment.', 'newsletter-campaign-kit' ); ?></p>
				<?php submit_button( $editing ? __( 'Update block', 'newsletter-campaign-kit' ) : __( 'Create block', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
			</form>
		</section>
		<h2><?php esc_html_e( 'Block library', 'newsletter-campaign-kit' ); ?></h2>
		<div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Category', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $blocks ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No reusable block yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $blocks as $block ) : ?>
			<tr><td><strong><?php echo esc_html( $block['name'] ); ?></strong></td><td><?php echo esc_html( $categories[ $block['category'] ] ?? $block['category'] ); ?></td><td><?php echo esc_html( $block['status'] ); ?></td><td><div class="nck-inline-actions">
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-blocks&edit=' . absint( $block['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'newsletter-campaign-kit' ); ?></a>
				<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_preview&kind=block&id=' . absint( $block['id'] ) ), 'newsletter_campaign_kit_preview_block_' . absint( $block['id'] ) ) ); ?>"><?php esc_html_e( 'Preview', 'newsletter-campaign-kit' ); ?></a>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_block_action"><input type="hidden" name="block_id" value="<?php echo esc_attr( $block['id'] ); ?>"><input type="hidden" name="block_operation" value="duplicate"><?php wp_nonce_field( 'newsletter_campaign_kit_block_action_' . absint( $block['id'] ) ); ?><button class="button button-small"><?php esc_html_e( 'Duplicate', 'newsletter-campaign-kit' ); ?></button></form>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_block_action"><input type="hidden" name="block_id" value="<?php echo esc_attr( $block['id'] ); ?>"><input type="hidden" name="block_operation" value="<?php echo esc_attr( 'active' === $block['status'] ? 'archive' : 'restore' ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_block_action_' . absint( $block['id'] ) ); ?><button class="button button-small"><?php echo esc_html( 'active' === $block['status'] ? __( 'Archive', 'newsletter-campaign-kit' ) : __( 'Restore', 'newsletter-campaign-kit' ) ); ?></button></form>
			</div></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php newsletter_campaign_kit_render_pagination( $current_page, $total, $per_page, array( 'page' => 'newsletter-campaign-kit-blocks' ) ); ?>
	</div>
	<style>.newsletter-campaign-kit-admin .nck-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:18px 0;padding:16px}.newsletter-campaign-kit-admin .nck-inline-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.newsletter-campaign-kit-admin .nck-inline-actions form{margin:0}</style>
	<?php
}
