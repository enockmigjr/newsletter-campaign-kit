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

function newsletter_campaign_kit_get_recent_audit_events( $args = array() ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_audit_table();
	if ( function_exists( 'newsletter_campaign_kit_table_exists' ) && ! newsletter_campaign_kit_table_exists( $table ) ) {
		return array();
	}

	if ( is_numeric( $args ) ) {
		$args = array( 'limit' => absint( $args ) );
	}
	$args   = wp_parse_args( $args, array( 'event' => '', 'status' => '', 'limit' => 50, 'offset' => 0 ) );
	$where  = array( '1=1' );
	$params = array();
	$event  = sanitize_key( $args['event'] );
	$status = sanitize_key( $args['status'] );
	if ( '' !== $event ) {
		$where[]  = 'event = %s';
		$params[] = $event;
	}
	if ( '' !== $status ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}
	$params[] = max( 1, min( 100, absint( $args['limit'] ) ) );
	$params[] = max( 0, absint( $args['offset'] ) );
	$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

function newsletter_campaign_kit_count_audit_events( $args = array() ) {
	global $wpdb;

	$table = newsletter_campaign_kit_get_audit_table();
	if ( function_exists( 'newsletter_campaign_kit_table_exists' ) && ! newsletter_campaign_kit_table_exists( $table ) ) {
		return 0;
	}

	$args   = wp_parse_args( $args, array( 'event' => '', 'status' => '' ) );
	$where  = array( '1=1' );
	$params = array();
	$event  = sanitize_key( $args['event'] );
	$status = sanitize_key( $args['status'] );
	if ( '' !== $event ) {
		$where[]  = 'event = %s';
		$params[] = $event;
	}
	if ( '' !== $status ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}
	$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

	return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
}

/** Return distinct safe filters currently present in the audit table. */
function newsletter_campaign_kit_get_audit_filter_options() {
	global $wpdb;

	$table = newsletter_campaign_kit_get_audit_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return array( 'events' => array(), 'statuses' => array() );
	}

	return array(
		'events'   => array_map( 'sanitize_key', $wpdb->get_col( "SELECT DISTINCT event FROM {$table} ORDER BY event ASC LIMIT 200" ) ),
		'statuses' => array_map( 'sanitize_key', $wpdb->get_col( "SELECT DISTINCT status FROM {$table} ORDER BY status ASC LIMIT 50" ) ),
	);
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

	$event_filter = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
	$status       = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page     = 30;
	$events       = newsletter_campaign_kit_get_recent_audit_events( array( 'event' => $event_filter, 'status' => $status, 'limit' => $per_page, 'offset' => ( $current_page - 1 ) * $per_page ) );
	$total        = newsletter_campaign_kit_count_audit_events( array( 'event' => $event_filter, 'status' => $status ) );
	$options      = newsletter_campaign_kit_get_audit_filter_options();
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Newsletter audit', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Recent subscription, segmentation, export and status events without raw IP addresses or unsubscribe tokens.', 'newsletter-campaign-kit' ); ?></p>
		<form class="nck-filters" method="get"><input type="hidden" name="page" value="newsletter-campaign-kit-audit"><select name="event"><option value=""><?php esc_html_e( 'All events', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $options['events'] as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $event_filter, $option ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select><select name="status"><option value=""><?php esc_html_e( 'All statuses', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $options['statuses'] as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select><button class="button button-primary" type="submit"><?php esc_html_e( 'Filter', 'newsletter-campaign-kit' ); ?></button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-audit' ) ); ?>"><?php esc_html_e( 'Reset', 'newsletter-campaign-kit' ); ?></a></form>
		<div class="nck-table-wrap"><table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Event', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscriber', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Actor', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Context', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Date', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $events ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No audit event recorded yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $events as $audit_event ) : ?>
				<tr>
					<td><code><?php echo esc_html( $audit_event['event'] ); ?></code></td>
					<td><?php echo esc_html( $audit_event['status'] ); ?></td>
					<td><?php echo ! empty( $audit_event['subscriber_id'] ) ? esc_html( '#' . absint( $audit_event['subscriber_id'] ) ) : esc_html__( 'None', 'newsletter-campaign-kit' ); ?></td>
					<td><?php echo ! empty( $audit_event['actor_user_id'] ) ? esc_html( '#' . absint( $audit_event['actor_user_id'] ) ) : esc_html__( 'Public', 'newsletter-campaign-kit' ); ?></td>
					<td><?php $context = json_decode( (string) $audit_event['context'], true ); $context = is_array( $context ) ? $context : array(); ?><details class="nck-audit-details"><summary><?php esc_html_e( 'View details', 'newsletter-campaign-kit' ); ?></summary><dl><dt><?php esc_html_e( 'Event ID', 'newsletter-campaign-kit' ); ?></dt><dd>#<?php echo esc_html( absint( $audit_event['id'] ) ); ?></dd><?php foreach ( $context as $key => $value ) : ?><dt><?php echo esc_html( $key ); ?></dt><dd><?php echo esc_html( $value ); ?></dd><?php endforeach; ?><?php if ( ! empty( $audit_event['ip_hash'] ) ) : ?><dt><?php esc_html_e( 'Network fingerprint', 'newsletter-campaign-kit' ); ?></dt><dd><code><?php echo esc_html( substr( $audit_event['ip_hash'], 0, 12 ) . '...' ); ?></code></dd><?php endif; ?><?php if ( ! empty( $audit_event['user_agent'] ) ) : ?><dt><?php esc_html_e( 'User agent', 'newsletter-campaign-kit' ); ?></dt><dd><?php echo esc_html( $audit_event['user_agent'] ); ?></dd><?php endif; ?></dl></details></td>
					<td><?php echo esc_html( get_date_from_gmt( $audit_event['created_at'], 'Y-m-d H:i' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php if ( function_exists( 'newsletter_campaign_kit_render_pagination' ) ) { newsletter_campaign_kit_render_pagination( $current_page, $total, $per_page, array( 'page' => 'newsletter-campaign-kit-audit', 'event' => $event_filter, 'status' => $status ) ); } ?>
	</div>
	<?php
}
