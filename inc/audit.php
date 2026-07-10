<?php
/**
 * Audit logging for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_sanitize_audit_context( $context ) {
	$safe = array();
	if ( ! is_array( $context ) ) {
		return $safe;
	}

	foreach ( $context as $key => $value ) {
		$key = sanitize_key( $key );
		if ( '' === $key || in_array( $key, array( 'email', 'token', 'unsubscribe_token' ), true ) ) {
			continue;
		}

		if ( is_scalar( $value ) ) {
			$safe[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 180 );
		}
	}

	return $safe;
}

function newsletter_campaign_kit_log_event( $event, $status = 'info', $subscriber_id = 0, $context = array() ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_audit_table();
	if ( function_exists( 'newsletter_campaign_kit_table_exists' ) && ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	$event  = substr( sanitize_key( $event ), 0, 80 );
	$status = substr( sanitize_key( $status ), 0, 24 );
	if ( '' === $event ) {
		return false;
	}

	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

	return false !== $wpdb->insert(
		$table,
		array(
			'event'          => $event,
			'status'         => $status ? $status : 'info',
			'subscriber_id'  => absint( $subscriber_id ),
			'actor_user_id'  => get_current_user_id() ? get_current_user_id() : null,
			'ip_hash'        => function_exists( 'newsletter_campaign_kit_get_request_ip_hash' ) ? newsletter_campaign_kit_get_request_ip_hash() : '',
			'user_agent'     => $user_agent,
			'context'        => wp_json_encode( newsletter_campaign_kit_sanitize_audit_context( $context ) ),
			'created_at'     => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
	);
}

function newsletter_campaign_kit_get_recent_audit_events( $limit = 50 ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_audit_table();
	if ( function_exists( 'newsletter_campaign_kit_table_exists' ) && ! newsletter_campaign_kit_table_exists( $table ) ) {
		return array();
	}

	$limit = max( 1, min( 100, absint( $limit ) ) );

	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ), ARRAY_A );
}

function newsletter_campaign_kit_count_audit_events() {
	global $wpdb;

	$table = newsletter_campaign_kit_get_audit_table();
	if ( function_exists( 'newsletter_campaign_kit_table_exists' ) && ! newsletter_campaign_kit_table_exists( $table ) ) {
		return 0;
	}

	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

function newsletter_campaign_kit_register_audit_menu() {
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Audit', 'newsletter-campaign-kit' ),
		__( 'Audit', 'newsletter-campaign-kit' ),
		'newsletter_view_reports',
		'newsletter-campaign-kit-audit',
		'newsletter_campaign_kit_render_audit_page'
	);
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_audit_menu', 20 );

function newsletter_campaign_kit_render_audit_page() {
	if ( ! current_user_can( 'newsletter_view_reports' ) ) {
		wp_die( esc_html__( 'You are not allowed to view newsletter audit events.', 'newsletter-campaign-kit' ) );
	}

	$events = newsletter_campaign_kit_get_recent_audit_events( 80 );
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Newsletter audit', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Recent subscription, segmentation, export and status events without raw IP addresses or unsubscribe tokens.', 'newsletter-campaign-kit' ); ?></p>
		<table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Event', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscriber', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actor', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Context', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Date', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $events ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No audit event recorded yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $events as $event ) : ?>
				<tr>
					<td><code><?php echo esc_html( $event['event'] ); ?></code></td>
					<td><?php echo esc_html( $event['status'] ); ?></td>
					<td><?php echo ! empty( $event['subscriber_id'] ) ? esc_html( '#' . absint( $event['subscriber_id'] ) ) : esc_html__( 'None', 'newsletter-campaign-kit' ); ?></td>
					<td><?php echo ! empty( $event['actor_user_id'] ) ? esc_html( '#' . absint( $event['actor_user_id'] ) ) : esc_html__( 'Public', 'newsletter-campaign-kit' ); ?></td>
					<td><?php echo esc_html( ! empty( $event['context'] ) ? $event['context'] : '-' ); ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $event['created_at'], 'Y-m-d H:i' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}