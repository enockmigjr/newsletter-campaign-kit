<?php
/**
 * Plugin Name: Newsletter Campaign Kit
 * Description: Reusable newsletter subscription and campaign foundation for WordPress projects.
 * Version: 0.1.2
 * Author: PhotoVault
 * Text Domain: newsletter-campaign-kit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEWSLETTER_CAMPAIGN_KIT_VERSION', '0.1.2' );
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