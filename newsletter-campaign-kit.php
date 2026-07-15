<?php
/**
 * Plugin Name: Newsletter Campaign Kit
 * Description: Reusable newsletter subscription and campaign foundation for WordPress projects.
 * Version: 0.16.0
 * Author: PhotoVault
 * Text Domain: newsletter-campaign-kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEWSLETTER_CAMPAIGN_KIT_VERSION', '0.16.0' );
define( 'NEWSLETTER_CAMPAIGN_KIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEWSLETTER_CAMPAIGN_KIT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Return the capabilities managed by Newsletter Campaign Kit.
 *
 * @return string[]
 */
function newsletter_campaign_kit_get_capabilities() {
	return array(
		'newsletter_manage_subscribers',
		'newsletter_manage_lists',
		'newsletter_create_campaigns',
		'newsletter_send_campaigns',
		'newsletter_view_reports',
		'newsletter_manage_settings',
	);
}

/**
 * Return the subscribers table name.
 *
 * @return string
 */
function newsletter_campaign_kit_get_subscribers_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_subscribers';
}

function newsletter_campaign_kit_get_audit_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_audit';
}

function newsletter_campaign_kit_get_campaigns_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_campaigns';
}

function newsletter_campaign_kit_get_queue_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_queue';
}

function newsletter_campaign_kit_get_lists_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_lists';
}

function newsletter_campaign_kit_get_audience_snapshots_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_audience_snapshots';
}

function newsletter_campaign_kit_get_audience_snapshot_members_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_audience_snapshot_members';
}

function newsletter_campaign_kit_get_tags_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_tags';
}

function newsletter_campaign_kit_get_subscriber_lists_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_subscriber_lists';
}

function newsletter_campaign_kit_get_subscriber_tags_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_subscriber_tags';
}

function newsletter_campaign_kit_get_segments_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_segments';
}

function newsletter_campaign_kit_get_topics_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_topics';
}

function newsletter_campaign_kit_get_subscriber_topics_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_subscriber_topics';
}

function newsletter_campaign_kit_get_suppressions_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_suppressions';
}

function newsletter_campaign_kit_get_templates_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_templates';
}

function newsletter_campaign_kit_get_blocks_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_blocks';
}

function newsletter_campaign_kit_get_provider_events_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_provider_events';
}

/** Return whether one plugin table exists in the current site. */
function newsletter_campaign_kit_table_exists( $table_name ) {
	global $wpdb;

	$table_name = sanitize_text_field( $table_name );
	$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $found === $table_name;
}
/**
 * Install or upgrade plugin storage and capabilities.
 */
function newsletter_campaign_kit_activate() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = newsletter_campaign_kit_get_subscribers_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email_hash char(64) NOT NULL,
		email varchar(190) NOT NULL,
		unsubscribe_token char(64) NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'subscribed',
		confirmation_token_hash char(64) NULL,
		confirmation_expires_at datetime NULL,
		confirmation_sent_at datetime NULL,
		confirmed_at datetime NULL,
		source varchar(100) NOT NULL DEFAULT 'front_footer',
		consent_text text NULL,
		ip_hash char(64) NULL,
		user_agent varchar(255) NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email_hash (email_hash),
		KEY unsubscribe_token (unsubscribe_token),
		KEY status (status),
		UNIQUE KEY confirmation_token_hash (confirmation_token_hash),
		KEY confirmation_expires_at (confirmation_expires_at),
		KEY updated_at (updated_at)
	) {$charset_collate};";

	dbDelta( $sql );

	$audit_table = newsletter_campaign_kit_get_audit_table();
	$audit_sql   = "CREATE TABLE {$audit_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event varchar(80) NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'info',
		subscriber_id bigint(20) unsigned NULL,
		actor_user_id bigint(20) unsigned NULL,
		ip_hash char(64) NULL,
		user_agent varchar(255) NULL,
		context longtext NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY event (event),
		KEY status (status),
		KEY subscriber_id (subscriber_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $audit_sql );
	$campaigns_table = newsletter_campaign_kit_get_campaigns_table();
	$campaigns_sql   = "CREATE TABLE {$campaigns_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		title varchar(190) NOT NULL,
		slug varchar(220) NOT NULL,
		subject varchar(190) NOT NULL,
		preview_text varchar(255) NULL,
		body longtext NULL,
		text_body longtext NULL,
		template_id bigint(20) unsigned NULL,
		status varchar(32) NOT NULL DEFAULT 'draft',
		target_list_id bigint(20) unsigned NULL,
		target_segment_id bigint(20) unsigned NULL,
		topic_id bigint(20) unsigned NULL,
		scheduled_at datetime NULL,
		sent_at datetime NULL,
		created_by bigint(20) unsigned NULL,
		updated_by bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug),
		KEY status (status),
		KEY template_id (template_id),
		KEY target_list_id (target_list_id),
		KEY target_segment_id (target_segment_id),
		KEY topic_id (topic_id),
		KEY scheduled_at (scheduled_at)
	) {$charset_collate};";

	dbDelta( $campaigns_sql );
	$templates_table = newsletter_campaign_kit_get_templates_table();
	$templates_sql   = "CREATE TABLE {$templates_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(190) NOT NULL,
		slug varchar(220) NOT NULL,
		subject varchar(190) NOT NULL,
		preview_text varchar(255) NULL,
		html_body longtext NOT NULL,
		text_body longtext NULL,
		status varchar(24) NOT NULL DEFAULT 'active',
		created_by bigint(20) unsigned NULL,
		updated_by bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug),
		KEY status (status),
		KEY updated_at (updated_at)
	) {$charset_collate};";

	dbDelta( $templates_sql );
	$blocks_table = newsletter_campaign_kit_get_blocks_table();
	$blocks_sql   = "CREATE TABLE {$blocks_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(190) NOT NULL,
		slug varchar(220) NOT NULL,
		category varchar(80) NOT NULL DEFAULT 'content',
		html_body longtext NOT NULL,
		text_body longtext NULL,
		status varchar(24) NOT NULL DEFAULT 'active',
		created_by bigint(20) unsigned NULL,
		updated_by bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug),
		KEY category_status (category, status),
		KEY updated_at (updated_at)
	) {$charset_collate};";

	dbDelta( $blocks_sql );
	$queue_table = newsletter_campaign_kit_get_queue_table();
	$queue_sql   = "CREATE TABLE {$queue_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		campaign_id bigint(20) unsigned NOT NULL,
		subscriber_id bigint(20) unsigned NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'pending',
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		next_attempt_at datetime NOT NULL,
		locked_at datetime NULL,
		sent_at datetime NULL,
		last_error varchar(255) NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY campaign_subscriber (campaign_id, subscriber_id),
		KEY status_next_attempt (status, next_attempt_at),
		KEY campaign_id (campaign_id),
		KEY subscriber_id (subscriber_id)
	) {$charset_collate};";

	dbDelta( $queue_sql );
	$snapshots_table = newsletter_campaign_kit_get_audience_snapshots_table();
	$snapshots_sql   = "CREATE TABLE {$snapshots_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		campaign_id bigint(20) unsigned NOT NULL,
		audience_type varchar(16) NOT NULL DEFAULT 'all',
		audience_id bigint(20) unsigned NULL,
		audience_label varchar(190) NOT NULL,
		topic_id bigint(20) unsigned NULL,
		topic_label varchar(190) NULL,
		rules longtext NULL,
		recipient_count bigint(20) unsigned NOT NULL DEFAULT 0,
		created_by bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY campaign_id (campaign_id),
		KEY audience (audience_type, audience_id),
		KEY topic_id (topic_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $snapshots_sql );
	$snapshot_members_table = newsletter_campaign_kit_get_audience_snapshot_members_table();
	$snapshot_members_sql   = "CREATE TABLE {$snapshot_members_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		snapshot_id bigint(20) unsigned NOT NULL,
		subscriber_id bigint(20) unsigned NULL,
		member_key char(64) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY snapshot_member (snapshot_id, member_key),
		KEY snapshot_id (snapshot_id),
		KEY subscriber_id (subscriber_id)
	) {$charset_collate};";

	dbDelta( $snapshot_members_sql );

	$lists_table            = newsletter_campaign_kit_get_lists_table();
	$tags_table             = newsletter_campaign_kit_get_tags_table();
	$subscriber_lists_table = newsletter_campaign_kit_get_subscriber_lists_table();
	$subscriber_tags_table  = newsletter_campaign_kit_get_subscriber_tags_table();
	$segments_table         = newsletter_campaign_kit_get_segments_table();
	$topics_table           = newsletter_campaign_kit_get_topics_table();
	$subscriber_topics_table = newsletter_campaign_kit_get_subscriber_topics_table();
	$suppressions_table      = newsletter_campaign_kit_get_suppressions_table();

	$lists_sql = "CREATE TABLE {$lists_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(120) NOT NULL,
		slug varchar(140) NOT NULL,
		description text NULL,
		status varchar(24) NOT NULL DEFAULT 'active',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug),
		KEY status (status)
	) {$charset_collate};";

	$tags_sql = "CREATE TABLE {$tags_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(80) NOT NULL,
		slug varchar(100) NOT NULL,
		color varchar(16) NOT NULL DEFAULT '#111827',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug)
	) {$charset_collate};";

	$subscriber_lists_sql = "CREATE TABLE {$subscriber_lists_table} (
		subscriber_id bigint(20) unsigned NOT NULL,
		list_id bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (subscriber_id, list_id),
		KEY list_id (list_id)
	) {$charset_collate};";

	$subscriber_tags_sql = "CREATE TABLE {$subscriber_tags_table} (
		subscriber_id bigint(20) unsigned NOT NULL,
		tag_id bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (subscriber_id, tag_id),
		KEY tag_id (tag_id)
	) {$charset_collate};";

	$segments_sql = "CREATE TABLE {$segments_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(120) NOT NULL,
		slug varchar(140) NOT NULL,
		description text NULL,
		match_type varchar(8) NOT NULL DEFAULT 'all',
		rules longtext NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'active',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug),
		KEY status (status)
	) {$charset_collate};";

	$topics_sql = "CREATE TABLE {$topics_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(100) NOT NULL,
		slug varchar(120) NOT NULL,
		description text NULL,
		color varchar(16) NOT NULL DEFAULT '#111827',
		status varchar(24) NOT NULL DEFAULT 'active',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY slug (slug),
		KEY status (status)
	) {$charset_collate};";

	$subscriber_topics_sql = "CREATE TABLE {$subscriber_topics_table} (
		subscriber_id bigint(20) unsigned NOT NULL,
		topic_id bigint(20) unsigned NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'subscribed',
		updated_at datetime NOT NULL,
		PRIMARY KEY  (subscriber_id, topic_id),
		KEY topic_status (topic_id, status)
	) {$charset_collate};";

	$suppressions_sql = "CREATE TABLE {$suppressions_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email_hash char(64) NOT NULL,
		subscriber_id bigint(20) unsigned NULL,
		status varchar(24) NOT NULL DEFAULT 'active',
		reason varchar(40) NOT NULL DEFAULT 'manual',
		source varchar(100) NOT NULL DEFAULT 'admin',
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		released_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email_hash (email_hash),
		KEY status_reason (status, reason),
		KEY subscriber_id (subscriber_id)
	) {$charset_collate};";

	dbDelta( $lists_sql );
	dbDelta( $tags_sql );
	dbDelta( $subscriber_lists_sql );
	dbDelta( $subscriber_tags_sql );
	dbDelta( $segments_sql );
	dbDelta( $topics_sql );
	dbDelta( $subscriber_topics_sql );
	dbDelta( $suppressions_sql );

	$provider_events_table = newsletter_campaign_kit_get_provider_events_table();
	$provider_events_sql   = "CREATE TABLE {$provider_events_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event_key char(64) NOT NULL,
		event_type varchar(24) NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'received',
		subscriber_id bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		processed_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY event_key (event_key),
		KEY event_type_status (event_type, status),
		KEY subscriber_id (subscriber_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $provider_events_sql );

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( newsletter_campaign_kit_get_capabilities() as $capability ) {
			$admin->add_cap( $capability );
		}
	}
	if ( function_exists( 'newsletter_campaign_kit_seed_default_templates' ) ) {
		newsletter_campaign_kit_seed_default_templates();
	}

	update_option( 'newsletter_campaign_kit_version', NEWSLETTER_CAMPAIGN_KIT_VERSION, false );

	if ( function_exists( 'newsletter_campaign_kit_schedule_runner' ) ) {
		newsletter_campaign_kit_schedule_runner();
	}
}
register_activation_hook( __FILE__, 'newsletter_campaign_kit_activate' );

function newsletter_campaign_kit_deactivate() {
	wp_clear_scheduled_hook( 'newsletter_campaign_kit_run_scheduled' );
}
register_deactivation_hook( __FILE__, 'newsletter_campaign_kit_deactivate' );

/**
 * Apply versioned upgrades for already active installations.
 */
function newsletter_campaign_kit_maybe_upgrade() {
	$installed_version = get_option( 'newsletter_campaign_kit_version' );
	if ( NEWSLETTER_CAMPAIGN_KIT_VERSION === $installed_version ) {
		return;
	}

	newsletter_campaign_kit_activate();
}
add_action( 'init', 'newsletter_campaign_kit_maybe_upgrade' );

require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/suppressions.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/subscribers.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/segments.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/import.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/import-admin.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/segment-engine.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/preferences.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/audit.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/audience-snapshots.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/templates.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/blocks.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/campaigns.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/http-provider.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/providers.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/double-opt-in.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/queue.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/scheduler.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/reports.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/exports.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/admin.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/privacy.php';
