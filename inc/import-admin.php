<?php
/**
 * Administration screen and upload handler for CSV imports.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Handle the protected admin CSV upload. */
function newsletter_campaign_kit_handle_csv_import() {
	if ( ! current_user_can( 'newsletter_manage_subscribers' ) || ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to import newsletter audiences.', 'newsletter-campaign-kit' ) );
	}
	check_admin_referer( 'newsletter_campaign_kit_import_csv' );
	$file = isset( $_FILES['newsletter_csv'] ) && is_array( $_FILES['newsletter_csv'] ) ? $_FILES['newsletter_csv'] : array();
	if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > newsletter_campaign_kit_import_max_bytes() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-import&import_error=upload' ) );
		exit;
	}
	$filetype = wp_check_filetype( sanitize_file_name( $file['name'] ), array( 'csv' => 'text/csv' ) );
	if ( 'csv' !== $filetype['ext'] ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-import&import_error=type' ) );
		exit;
	}
	$mapping = array();
	foreach ( array( 'email', 'status', 'lists', 'tags', 'consent' ) as $field ) {
		$key               = 'map_' . $field;
		$mapping[ $field ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}
	$apply  = isset( $_POST['import_mode'] ) && 'apply' === sanitize_key( wp_unslash( $_POST['import_mode'] ) );
	$apply  = $apply && ! empty( $_POST['confirm_apply'] );
	$result = newsletter_campaign_kit_process_csv_import(
		$file['tmp_name'],
		array(
			'apply'            => $apply,
			'allow_reactivate' => ! empty( $_POST['allow_reactivate'] ),
			'default_status'   => isset( $_POST['default_status'] ) ? sanitize_key( wp_unslash( $_POST['default_status'] ) ) : 'subscribed',
			'default_consent'  => isset( $_POST['default_consent'] ) ? sanitize_textarea_field( wp_unslash( $_POST['default_consent'] ) ) : '',
			'mapping'          => $mapping,
		)
	);
	$token = wp_generate_password( 20, false, false );
	set_transient( 'newsletter_import_' . get_current_user_id() . '_' . $token, $result, 15 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'import_report', rawurlencode( $token ), admin_url( 'admin.php?page=newsletter-campaign-kit-import' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_import_csv', 'newsletter_campaign_kit_handle_csv_import' );

/** Render the CSV import screen and its short-lived report. */
function newsletter_campaign_kit_render_import_page() {
	if ( ! current_user_can( 'newsletter_manage_subscribers' ) || ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to import newsletter audiences.', 'newsletter-campaign-kit' ) );
	}
	$report = null;
	if ( isset( $_GET['import_report'] ) ) {
		$token  = sanitize_key( wp_unslash( $_GET['import_report'] ) );
		$report = get_transient( 'newsletter_import_' . get_current_user_id() . '_' . $token );
		delete_transient( 'newsletter_import_' . get_current_user_id() . '_' . $token );
	}
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Import subscribers', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Preview a mapped CSV before applying it. Lists and tags use existing names or slugs separated with a pipe (|).', 'newsletter-campaign-kit' ); ?></p>
		<?php if ( isset( $_GET['import_error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'The CSV upload was rejected. Check its extension, size, and upload status.', 'newsletter-campaign-kit' ); ?></p></div><?php endif; ?>
		<?php if ( is_wp_error( $report ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div><?php endif; ?>
		<?php if ( is_array( $report ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $report['errors'] ? 'warning' : 'success' ); ?>"><p><strong><?php echo esc_html( 'apply' === $report['mode'] ? __( 'Import report', 'newsletter-campaign-kit' ) : __( 'Preview report', 'newsletter-campaign-kit' ) ); ?></strong> <?php echo esc_html( sprintf( __( '%1$d rows, %2$d valid, %3$d applied, %4$d errors.', 'newsletter-campaign-kit' ), $report['total'], $report['valid'], $report['applied'], $report['errors'] ) ); ?></p></div>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Line', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Action', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Result', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
			<?php foreach ( array_slice( $report['rows'], 0, 100 ) as $row ) : ?><tr><td><?php echo esc_html( $row['line'] ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['action'] ); ?></td><td><?php echo esc_html( $row['message'] ); ?></td></tr><?php endforeach; ?>
			</tbody></table>
		<?php endif; ?>
		<form method="POST" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-panel" style="max-width:900px;margin-top:20px">
			<input type="hidden" name="action" value="newsletter_campaign_kit_import_csv">
			<?php wp_nonce_field( 'newsletter_campaign_kit_import_csv' ); ?>
			<table class="form-table"><tbody>
			<tr><th><label for="newsletter_csv"><?php esc_html_e( 'CSV file', 'newsletter-campaign-kit' ); ?></label></th><td><input id="newsletter_csv" type="file" name="newsletter_csv" accept=".csv,text/csv" required><p class="description"><?php echo esc_html( sprintf( __( 'Maximum %1$d MB and %2$d data rows.', 'newsletter-campaign-kit' ), newsletter_campaign_kit_import_max_bytes() / MB_IN_BYTES, newsletter_campaign_kit_import_max_rows() ) ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Header mapping', 'newsletter-campaign-kit' ); ?></th><td>
				<label><?php esc_html_e( 'Email', 'newsletter-campaign-kit' ); ?> <input name="map_email" value="email" required></label><br>
				<label><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?> <input name="map_status" value="status"></label><br>
				<label><?php esc_html_e( 'Lists', 'newsletter-campaign-kit' ); ?> <input name="map_lists" value="lists"></label><br>
				<label><?php esc_html_e( 'Tags', 'newsletter-campaign-kit' ); ?> <input name="map_tags" value="tags"></label><br>
				<label><?php esc_html_e( 'Consent', 'newsletter-campaign-kit' ); ?> <input name="map_consent" value="consent_text"></label>
			</td></tr>
			<tr><th><label for="default_status"><?php esc_html_e( 'Default status', 'newsletter-campaign-kit' ); ?></label></th><td><select id="default_status" name="default_status"><option value="subscribed"><?php esc_html_e( 'Subscribed', 'newsletter-campaign-kit' ); ?></option><option value="unsubscribed"><?php esc_html_e( 'Unsubscribed', 'newsletter-campaign-kit' ); ?></option></select></td></tr>
			<tr><th><label for="default_consent"><?php esc_html_e( 'Default consent evidence', 'newsletter-campaign-kit' ); ?></label></th><td><textarea id="default_consent" name="default_consent" class="large-text" rows="3"></textarea><p class="description"><?php esc_html_e( 'Required for new or reactivated active subscriptions when the mapped cell is empty.', 'newsletter-campaign-kit' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Mode', 'newsletter-campaign-kit' ); ?></th><td><label><input type="radio" name="import_mode" value="preview" checked> <?php esc_html_e( 'Preview only', 'newsletter-campaign-kit' ); ?></label><br><label><input type="radio" name="import_mode" value="apply"> <?php esc_html_e( 'Apply valid rows', 'newsletter-campaign-kit' ); ?></label></td></tr>
			<tr><th><?php esc_html_e( 'Sensitive actions', 'newsletter-campaign-kit' ); ?></th><td><label><input type="checkbox" name="allow_reactivate" value="1"> <?php esc_html_e( 'Allow reactivation when fresh consent is present', 'newsletter-campaign-kit' ); ?></label><br><label><input type="checkbox" name="confirm_apply" value="1"> <?php esc_html_e( 'I confirm the applied import', 'newsletter-campaign-kit' ); ?></label></td></tr>
			</tbody></table>
			<?php submit_button( __( 'Process CSV', 'newsletter-campaign-kit' ) ); ?>
		</form>
	</div>
	<?php
}
