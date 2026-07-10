<?php
/**
 * Plugin Name: Newsletter Campaign Kit
 * Description: Reusable newsletter subscription and campaign foundation for WordPress projects.
 * Version: 0.1.3
 * Author: PhotoVault
 * Text Domain: newsletter-campaign-kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEWSLETTER_CAMPAIGN_KIT_VERSION', '0.1.3' );
define( 'NEWSLETTER_CAMPAIGN_KIT_DIR', plugin_dir_path( __FILE__ ) );

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

function newsletter_campaign_kit_get_lists_table() {
	global $wpdb;

	return $wpdb->prefix . 'newsletter_campaign_lists';
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
		KEY updated_at (updated_at)
	) {$charset_collate};";

	dbDelta( $sql );

	$lists_table            = newsletter_campaign_kit_get_lists_table();
	$tags_table             = newsletter_campaign_kit_get_tags_table();
	$subscriber_lists_table = newsletter_campaign_kit_get_subscriber_lists_table();
	$subscriber_tags_table  = newsletter_campaign_kit_get_subscriber_tags_table();

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

	dbDelta( $lists_sql );
	dbDelta( $tags_sql );
	dbDelta( $subscriber_lists_sql );
	dbDelta( $subscriber_tags_sql );

	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( newsletter_campaign_kit_get_capabilities() as $capability ) {
			$admin->add_cap( $capability );
		}
	}

	update_option( 'newsletter_campaign_kit_version', NEWSLETTER_CAMPAIGN_KIT_VERSION, false );
}
register_activation_hook( __FILE__, 'newsletter_campaign_kit_activate' );

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

require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/subscribers.php';
require_once NEWSLETTER_CAMPAIGN_KIT_DIR . 'inc/admin.php';