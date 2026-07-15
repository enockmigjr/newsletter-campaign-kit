<?php
/**
 * WordPress runtime verification for reusable editorial blocks.
 *
 * Run with: wp eval-file tests/runtime-editorial-blocks.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function newsletter_blocks_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix        = strtolower( wp_generate_password( 8, false, false ) );
$block_ids     = array();
$campaign_ids  = array();
$user_id       = 0;
$original_user = get_current_user_id();
$blocks_table  = newsletter_campaign_kit_get_blocks_table();
$campaigns     = newsletter_campaign_kit_get_campaigns_table();
$queue         = newsletter_campaign_kit_get_queue_table();
$audit         = newsletter_campaign_kit_get_audit_table();

try {
	newsletter_blocks_runtime_assert( newsletter_campaign_kit_blocks_table_exists(), 'The editorial blocks table was not migrated.' );
	$administrators = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
	newsletter_blocks_runtime_assert( ! empty( $administrators ), 'An administrator is required for the editor test.' );
	wp_set_current_user( (int) $administrators[0] );
	newsletter_blocks_runtime_assert( current_user_can( 'newsletter_create_campaigns' ), 'The administrator does not have the campaign capability.' );

	$invalid = newsletter_campaign_kit_create_block( array( 'name' => 'Invalid', 'category' => 'not-allowed', 'html_body' => '<p>Invalid</p>' ), get_current_user_id() );
	newsletter_blocks_runtime_assert( is_wp_error( $invalid ) && 'newsletter_invalid_block_category' === $invalid->get_error_code(), 'An unknown block category was accepted.' );

	$block_id = newsletter_campaign_kit_create_block(
		array(
			'name'      => 'Runtime quote ' . $suffix,
			'category'  => 'quote',
			'html_body' => '<blockquote><strong>Trace</strong><script>alert(1)</script></blockquote>',
			'text_body' => '',
		),
		get_current_user_id()
	);
	newsletter_blocks_runtime_assert( is_int( $block_id ), 'The editorial block could not be created.' );
	$block_ids[] = $block_id;
	$block       = newsletter_campaign_kit_get_block( $block_id );
	newsletter_blocks_runtime_assert( false === strpos( $block['html_body'], '<script' ) && false !== strpos( $block['html_body'], '<blockquote>' ), 'Unsafe block HTML was not sanitized.' );
	newsletter_blocks_runtime_assert( 'Trace' === $block['text_body'], 'Plain text was not generated from the sanitized HTML.' );

	$updated = newsletter_campaign_kit_update_block(
		$block_id,
		array( 'name' => 'Runtime CTA ' . $suffix, 'category' => 'cta', 'html_body' => '<p><a href="https://example.test/work">View work</a></p>', 'text_body' => 'View work: https://example.test/work' ),
		get_current_user_id()
	);
	newsletter_blocks_runtime_assert( true === $updated && 'cta' === newsletter_campaign_kit_get_block( $block_id )['category'], 'The block update failed.' );
	$duplicate_id = newsletter_campaign_kit_duplicate_block( $block_id, get_current_user_id() );
	newsletter_blocks_runtime_assert( is_int( $duplicate_id ) && $duplicate_id !== $block_id, 'The block could not be duplicated.' );
	$block_ids[] = $duplicate_id;

	newsletter_blocks_runtime_assert( true === newsletter_campaign_kit_set_block_status( $block_id, 'archived', get_current_user_id() ), 'The block could not be archived.' );
	$active_ids = array_map( 'absint', wp_list_pluck( newsletter_campaign_kit_get_blocks(), 'id' ) );
	newsletter_blocks_runtime_assert( ! in_array( $block_id, $active_ids, true ) && in_array( $duplicate_id, $active_ids, true ), 'Archived blocks were not excluded from the active library.' );
	newsletter_blocks_runtime_assert( true === newsletter_campaign_kit_set_block_status( $block_id, 'active', get_current_user_id() ), 'The block could not be restored.' );

	$campaign_id = newsletter_campaign_kit_create_campaign(
		array(
			'title'           => 'Block campaign ' . $suffix,
			'subject'         => 'Editorial block runtime',
			'html_body'       => newsletter_campaign_kit_get_block( $block_id )['html_body'],
			'text_body'       => newsletter_campaign_kit_get_block( $block_id )['text_body'],
			'target_audience' => 'all',
		),
		get_current_user_id()
	);
	newsletter_blocks_runtime_assert( is_int( $campaign_id ), 'A campaign could not persist inserted block content.' );
	$campaign_ids[] = $campaign_id;
	$campaign       = newsletter_campaign_kit_get_campaign( $campaign_id );
	newsletter_blocks_runtime_assert( false !== strpos( $campaign['body'], 'View work' ) && false !== strpos( $campaign['text_body'], 'example.test/work' ), 'The campaign lost inserted block content.' );

	ob_start();
	newsletter_campaign_kit_render_campaigns_page();
	$editor_html = ob_get_clean();
	newsletter_blocks_runtime_assert( false !== strpos( $editor_html, 'nck-editorial-block' ) && false !== strpos( $editor_html, 'data-newsletter-insert-block' ), 'The campaign editor does not render the block inserter.' );
	newsletter_blocks_runtime_assert( false !== strpos( $editor_html, 'Runtime CTA ' . $suffix ), 'The active block is missing from the campaign editor.' );
	newsletter_blocks_runtime_assert( wp_script_is( 'newsletter-campaign-kit-blocks', 'enqueued' ), 'The local block insertion script was not enqueued.' );
	$script = wp_scripts()->registered['newsletter-campaign-kit-blocks'] ?? null;
	newsletter_blocks_runtime_assert( $script && 0 === strpos( (string) $script->src, NEWSLETTER_CAMPAIGN_KIT_URL ), 'The block insertion script is not plugin-local.' );
	newsletter_blocks_runtime_assert( false !== strpos( (string) ( $script->extra['data'] ?? '' ), 'View work' ), 'The active block payload was not bound to the editor.' );

	$user_id = wp_create_user( 'block-reader-' . $suffix, wp_generate_password( 24, true, true ), 'block-reader-' . $suffix . '@photovault.test' );
	newsletter_blocks_runtime_assert( is_int( $user_id ), 'The capability fixture account could not be created.' );
	wp_set_current_user( $user_id );
	newsletter_blocks_runtime_assert( ! current_user_can( 'newsletter_create_campaigns' ), 'A subscriber unexpectedly has campaign authoring access.' );

	WP_CLI::success(
		wp_json_encode(
			array(
				'storage'      => 'migrated',
				'sanitization' => 'html_and_text',
				'lifecycle'    => 'create_update_duplicate_archive_restore',
				'editor'       => 'local_payload_and_campaign_persistence',
				'capability'   => 'newsletter_create_campaigns',
			)
		)
	);
} finally {
	wp_set_current_user( $original_user );
	foreach ( $campaign_ids as $campaign_id ) {
		$wpdb->delete( $queue, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		$wpdb->delete( $campaigns, array( 'id' => $campaign_id ), array( '%d' ) );
	}
	foreach ( $block_ids as $block_id ) {
		$wpdb->delete( $blocks_table, array( 'id' => $block_id ), array( '%d' ) );
	}
	if ( $user_id ) {
		$wpdb->delete( $audit, array( 'actor_user_id' => $user_id ), array( '%d' ) );
		wp_delete_user( $user_id );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE context LIKE %s", '%' . $wpdb->esc_like( $suffix ) . '%' ) );
}
