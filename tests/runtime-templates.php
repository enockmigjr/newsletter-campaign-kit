<?php
/**
 * WordPress runtime verification for templates and multipart delivery.
 *
 * Run with: wp eval-file tests/runtime-templates.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_templates_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$email             = 'templates-runtime@photovault.test';
$email_hash        = newsletter_campaign_kit_hash_email( $email );
$templates_table   = newsletter_campaign_kit_get_templates_table();
$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
$old_provider      = get_option( 'newsletter_campaign_kit_provider_settings', array() );
$template_ids      = array();
$subscriber_id     = 0;
$captured_alt_body = '';
$previous_user_id  = get_current_user_id();
$capture_alt_body  = static function ( $phpmailer ) use ( &$captured_alt_body ) {
	$captured_alt_body = (string) $phpmailer->AltBody;
};

try {
	newsletter_campaign_kit_activate();
	newsletter_templates_runtime_assert( newsletter_campaign_kit_templates_table_exists(), 'Template migration did not create its table.' );
	$default_template_id = newsletter_campaign_kit_get_default_template_id();
	newsletter_templates_runtime_assert( $default_template_id > 0, 'The default editorial template was not seeded.' );
	newsletter_templates_runtime_assert( 0 === newsletter_campaign_kit_seed_default_templates(), 'Default templates are not idempotent.' );
	$campaign_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . newsletter_campaign_kit_get_campaigns_table() );
	newsletter_templates_runtime_assert( in_array( 'template_id', $campaign_columns, true ) && in_array( 'text_body', $campaign_columns, true ), 'Campaign migration did not add multipart/template columns.' );

	$template_id = newsletter_campaign_kit_create_template(
		array(
			'name'         => 'Runtime editorial ' . wp_generate_password( 5, false, false ),
			'subject'      => 'Runtime suspended light',
			'preview_text' => 'A short editorial preview.',
			'html_body'    => '<h1>Suspended light</h1><p>A first paragraph.<br>A second line.</p><script>alert(1)</script>',
			'text_body'    => '',
		),
		1
	);
	newsletter_templates_runtime_assert( ! is_wp_error( $template_id ), 'Template creation failed.' );
	$template_ids[] = (int) $template_id;
	$template       = newsletter_campaign_kit_get_template( $template_id );
	newsletter_templates_runtime_assert( false === strpos( $template['html_body'], '<script' ) && false === strpos( $template['html_body'], 'alert(1)' ), 'Active template content was persisted.' );
	newsletter_templates_runtime_assert( false !== strpos( $template['text_body'], 'Suspended light' ) && false !== strpos( $template['text_body'], 'A second line.' ), 'Plain text was not generated from HTML.' );

	$updated = newsletter_campaign_kit_update_template(
		$template_id,
		array(
			'name'         => $template['name'],
			'subject'      => 'Updated runtime subject',
			'preview_text' => $template['preview_text'],
			'html_body'    => $template['html_body'],
			'text_body'    => "Explicit text version\nWith two lines.",
		),
		1
	);
	newsletter_templates_runtime_assert( true === $updated, 'Template update failed.' );
	$duplicate_id = newsletter_campaign_kit_duplicate_template( $template_id, 1 );
	newsletter_templates_runtime_assert( ! is_wp_error( $duplicate_id ), 'Template duplication failed.' );
	$template_ids[] = (int) $duplicate_id;
	newsletter_templates_runtime_assert( true === newsletter_campaign_kit_set_template_status( $duplicate_id, 'archived', 1 ), 'Template archive failed.' );
	$archived_content = newsletter_campaign_kit_resolve_campaign_content( array( 'template_id' => $duplicate_id ) );
	newsletter_templates_runtime_assert( is_wp_error( $archived_content ) && 'newsletter_template_unavailable' === $archived_content->get_error_code(), 'An archived template remained selectable.' );
	newsletter_templates_runtime_assert( true === newsletter_campaign_kit_set_template_status( $duplicate_id, 'active', 1 ), 'Template restore failed.' );
	$administrator = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	newsletter_templates_runtime_assert( ! empty( $administrator ), 'No administrator is available for the template UI test.' );
	wp_set_current_user( (int) $administrator[0] );
	ob_start();
	newsletter_campaign_kit_render_templates_page();
	$admin_markup = ob_get_clean();
	newsletter_templates_runtime_assert( false !== strpos( $admin_markup, 'newsletter_campaign_kit_save_template' ) && false !== strpos( $admin_markup, 'newsletter_campaign_kit_preview' ), 'Template admin UI did not expose save and preview actions.' );

	$content = newsletter_campaign_kit_resolve_campaign_content(
		array(
			'template_id' => $template_id,
			'subject'     => 'Campaign override subject',
		)
	);
	newsletter_templates_runtime_assert( ! is_wp_error( $content ) && 'Campaign override subject' === $content['subject'], 'Campaign subject override was not preserved.' );
	newsletter_templates_runtime_assert( 'Explicit text version' === strtok( $content['text_body'], "\n" ), 'Campaign did not inherit the template text body.' );

	$wpdb->delete( $subscribers_table, array( 'email_hash' => $email_hash ), array( '%s' ) );
	newsletter_templates_runtime_assert( true === newsletter_campaign_kit_subscribe_email( $email, 'runtime_templates', 'Runtime multipart consent' ), 'Runtime recipient could not subscribe.' );
	$subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$subscribers_table} WHERE email_hash = %s", $email_hash ), ARRAY_A );
	$subscriber_id = (int) $subscriber['id'];
	$campaign = array(
		'id'         => 0,
		'subject'    => $content['subject'],
		'body'       => $content['html_body'],
		'text_body'  => $content['text_body'],
		'topic_id'   => null,
	);
	$wrapped_body = newsletter_campaign_kit_render_campaign_body( $campaign, $subscriber );
	newsletter_templates_runtime_assert( false !== strpos( $wrapped_body, '<!doctype html>' ) && false !== strpos( $wrapped_body, '<table role="presentation"' ), 'Campaign content is not wrapped in the professional email document.' );
	newsletter_templates_runtime_assert( false !== strpos( $wrapped_body, 'Suspended light' ), 'The editorial content disappeared from the wrapped message.' );
	newsletter_templates_runtime_assert( false !== strpos( $wrapped_body, 'Manage preferences or unsubscribe' ), 'The HTML compliance link is missing.' );
	update_option( 'newsletter_campaign_kit_provider_settings', array( 'provider' => 'wp_mail', 'from_name' => 'PhotoVault', 'from_email' => 'wordpress@photovault.local' ), false );
	add_action( 'phpmailer_init', $capture_alt_body, 20 );
	$sent = newsletter_campaign_kit_send_with_wp_mail( false, $campaign, $subscriber, array() );
	remove_action( 'phpmailer_init', $capture_alt_body, 20 );
	newsletter_templates_runtime_assert( true === $sent, 'Multipart email was not handed to wp_mail.' );
	newsletter_templates_runtime_assert( false !== strpos( $captured_alt_body, 'Explicit text version' ), 'PHPMailer did not receive the campaign AltBody.' );
	newsletter_templates_runtime_assert( false !== strpos( $captured_alt_body, 'Manage preferences or unsubscribe:' ), 'Plain-text compliance link is missing.' );

	echo wp_json_encode(
		array(
			'migration'          => '0.6.0',
			'template_lifecycle' => 'create_update_duplicate_archive_restore',
			'html_sanitization'  => true,
			'text_fallback'      => true,
			'campaign_inheritance' => true,
			'admin_ui'           => true,
			'phpmailer_alt_body' => true,
			'wp_mail'            => true,
			'default_library'    => true,
			'editorial_shell'    => true,
		)
	);
} finally {
	wp_set_current_user( $previous_user_id );
	remove_action( 'phpmailer_init', $capture_alt_body, 20 );
	update_option( 'newsletter_campaign_kit_provider_settings', $old_provider, false );
	if ( $subscriber_id ) {
		$wpdb->delete( newsletter_campaign_kit_get_subscriber_topics_table(), array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( newsletter_campaign_kit_get_subscriber_lists_table(), array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( newsletter_campaign_kit_get_subscriber_tags_table(), array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( newsletter_campaign_kit_get_audit_table(), array( 'subscriber_id' => $subscriber_id ), array( '%d' ) );
		$wpdb->delete( $subscribers_table, array( 'id' => $subscriber_id ), array( '%d' ) );
	}
	foreach ( array_unique( array_filter( $template_ids ) ) as $template_id ) {
		$wpdb->delete( $templates_table, array( 'id' => $template_id ), array( '%d' ) );
	}
}
