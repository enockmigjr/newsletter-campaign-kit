<?php
/**
 * Campaign reporting for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_get_campaign_reports( $limit = 50 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_campaigns_table_exists() || ! newsletter_campaign_kit_queue_table_exists() ) {
		return array();
	}

	$campaigns_table = newsletter_campaign_kit_get_campaigns_table();
	$queue_table     = newsletter_campaign_kit_get_queue_table();
	$snapshots_table = newsletter_campaign_kit_get_audience_snapshots_table();
	$events_table    = newsletter_campaign_kit_get_tracking_events_table();
	$has_snapshots   = newsletter_campaign_kit_audience_snapshot_tables_exist();
	$has_tracking    = newsletter_campaign_kit_table_exists( $events_table );
	$limit           = max( 1, min( 50000, absint( $limit ) ) );
	$snapshot_select = $has_snapshots
		? 'sn.id AS snapshot_id, sn.audience_type, sn.audience_label, sn.topic_label, sn.rules AS snapshot_rules, sn.recipient_count AS snapshot_recipient_count, sn.created_at AS snapshot_created_at'
		: "NULL AS snapshot_id, NULL AS audience_type, NULL AS audience_label, NULL AS topic_label, NULL AS snapshot_rules, 0 AS snapshot_recipient_count, NULL AS snapshot_created_at";
	$snapshot_join = $has_snapshots ? "LEFT JOIN {$snapshots_table} sn ON sn.campaign_id = c.id" : '';
	$tracking_select = $has_tracking
		? "COUNT(DISTINCT CASE WHEN e.event_type = 'open' AND e.is_bot = 0 THEN e.queue_id END) AS unique_open_total,
		COUNT(DISTINCT CASE WHEN e.event_type = 'click' AND e.is_bot = 0 THEN e.queue_id END) AS unique_click_total,
		COUNT(DISTINCT CASE WHEN e.event_type = 'conversion' AND e.is_bot = 0 THEN e.queue_id END) AS unique_conversion_total,
		SUM(CASE WHEN e.event_type = 'open' AND e.is_bot = 0 THEN 1 ELSE 0 END) AS open_event_total,
		SUM(CASE WHEN e.event_type = 'click' AND e.is_bot = 0 THEN 1 ELSE 0 END) AS click_event_total,
		SUM(CASE WHEN e.is_bot = 1 THEN 1 ELSE 0 END) AS automated_event_total"
		: '0 AS unique_open_total, 0 AS unique_click_total, 0 AS unique_conversion_total, 0 AS open_event_total, 0 AS click_event_total, 0 AS automated_event_total';
	$tracking_join = $has_tracking ? "LEFT JOIN {$events_table} e ON e.queue_id = q.id" : '';

	$sql = "SELECT c.id, c.title, c.subject, c.status, c.updated_at,
		{$snapshot_select},
		COUNT(DISTINCT q.id) AS queued_total,
		COUNT(DISTINCT CASE WHEN q.status = 'sent' THEN q.id END) AS sent_total,
		COUNT(DISTINCT CASE WHEN q.status = 'failed' THEN q.id END) AS failed_total,
		COUNT(DISTINCT CASE WHEN q.status = 'pending' THEN q.id END) AS pending_total,
		COUNT(DISTINCT CASE WHEN q.status = 'processing' THEN q.id END) AS processing_total,
		COUNT(DISTINCT CASE WHEN q.status = 'paused' THEN q.id END) AS paused_total,
		COUNT(DISTINCT CASE WHEN q.status = 'cancelled' THEN q.id END) AS cancelled_total,
		{$tracking_select},
		MAX(q.sent_at) AS last_sent_at
		FROM {$campaigns_table} c
		LEFT JOIN {$queue_table} q ON q.campaign_id = c.id
		{$tracking_join}
		{$snapshot_join}
		GROUP BY c.id
		ORDER BY c.updated_at DESC
		LIMIT %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );

	foreach ( $rows as &$row ) {
		$row['queued_total']     = absint( $row['queued_total'] );
		$row['sent_total']       = absint( $row['sent_total'] );
		$row['failed_total']     = absint( $row['failed_total'] );
		$row['pending_total']    = absint( $row['pending_total'] );
		$row['processing_total'] = absint( $row['processing_total'] );
		$row['paused_total']     = absint( $row['paused_total'] );
		$row['cancelled_total']  = absint( $row['cancelled_total'] );
		$row['snapshot_recipient_count'] = absint( $row['snapshot_recipient_count'] );
		$row['unique_open_total']         = absint( $row['unique_open_total'] );
		$row['unique_click_total']        = absint( $row['unique_click_total'] );
		$row['unique_conversion_total']   = absint( $row['unique_conversion_total'] );
		$row['open_event_total']          = absint( $row['open_event_total'] );
		$row['click_event_total']         = absint( $row['click_event_total'] );
		$row['automated_event_total']     = absint( $row['automated_event_total'] );
		$row['delivery_rate']    = $row['queued_total'] > 0 ? round( ( $row['sent_total'] / $row['queued_total'] ) * 100, 1 ) : 0;
		$row['open_rate']        = $row['sent_total'] > 0 ? round( ( $row['unique_open_total'] / $row['sent_total'] ) * 100, 1 ) : 0;
		$row['click_rate']       = $row['sent_total'] > 0 ? round( ( $row['unique_click_total'] / $row['sent_total'] ) * 100, 1 ) : 0;
		$row['click_to_open_rate'] = $row['unique_open_total'] > 0 ? round( ( $row['unique_click_total'] / $row['unique_open_total'] ) * 100, 1 ) : 0;
		$row['conversion_rate']  = $row['unique_click_total'] > 0 ? round( ( $row['unique_conversion_total'] / $row['unique_click_total'] ) * 100, 1 ) : 0;
	}
	unset( $row );

	return $rows;
}

function newsletter_campaign_kit_get_campaign_report_totals() {
	global $wpdb;

	$empty = array( 'campaigns' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'opened' => 0, 'clicked' => 0, 'converted' => 0 );
	if ( ! newsletter_campaign_kit_campaigns_table_exists() || ! newsletter_campaign_kit_queue_table_exists() ) {
		return $empty;
	}
	$campaigns = newsletter_campaign_kit_get_campaigns_table();
	$queue     = newsletter_campaign_kit_get_queue_table();
	$events    = newsletter_campaign_kit_get_tracking_events_table();
	$tracking  = newsletter_campaign_kit_table_exists( $events )
		? ", COUNT(DISTINCT CASE WHEN e.event_type = 'open' AND e.is_bot = 0 THEN e.queue_id END) AS opened,
		COUNT(DISTINCT CASE WHEN e.event_type = 'click' AND e.is_bot = 0 THEN e.queue_id END) AS clicked,
		COUNT(DISTINCT CASE WHEN e.event_type = 'conversion' AND e.is_bot = 0 THEN e.queue_id END) AS converted"
		: ', 0 AS opened, 0 AS clicked, 0 AS converted';
	$tracking_join = newsletter_campaign_kit_table_exists( $events ) ? "LEFT JOIN {$events} e ON e.queue_id = q.id" : '';
	$row       = $wpdb->get_row(
		"SELECT COUNT(DISTINCT c.id) AS campaigns, COUNT(DISTINCT q.id) AS queued,
		COUNT(DISTINCT CASE WHEN q.status = 'sent' THEN q.id END) AS sent,
		COUNT(DISTINCT CASE WHEN q.status = 'failed' THEN q.id END) AS failed,
		COUNT(DISTINCT CASE WHEN q.status = 'pending' THEN q.id END) AS pending
		{$tracking}
		FROM {$campaigns} c LEFT JOIN {$queue} q ON q.campaign_id = c.id {$tracking_join}",
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		return $empty;
	}

	return array_map( 'absint', wp_parse_args( $row, $empty ) );
}

/** Summarize subscription acquisition and consent state. */
function newsletter_campaign_kit_get_subscription_report_totals() {
	global $wpdb;

	$empty = array( 'total' => 0, 'subscribed' => 0, 'pending' => 0, 'unsubscribed' => 0, 'confirmed_30d' => 0, 'created_30d' => 0 );
	if ( ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return $empty;
	}
	$table  = newsletter_campaign_kit_get_subscribers_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
	$row    = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS total,
			SUM(CASE WHEN status = 'subscribed' THEN 1 ELSE 0 END) AS subscribed,
			SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
			SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) AS unsubscribed,
			SUM(CASE WHEN confirmed_at >= %s THEN 1 ELSE 0 END) AS confirmed_30d,
			SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS created_30d
			FROM {$table}",
			$cutoff,
			$cutoff
		),
		ARRAY_A
	);

	return array_map( 'absint', wp_parse_args( is_array( $row ) ? $row : array(), $empty ) );
}

function newsletter_campaign_kit_register_reports_menu() {
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Reports', 'newsletter-campaign-kit' ),
		__( 'Reports', 'newsletter-campaign-kit' ),
		'newsletter_view_reports',
		'newsletter-campaign-kit-reports',
		'newsletter_campaign_kit_render_reports_page'
	);
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_reports_menu', 18 );

function newsletter_campaign_kit_render_reports_page() {
	if ( ! current_user_can( 'newsletter_view_reports' ) ) {
		wp_die( esc_html__( 'You are not allowed to view newsletter reports.', 'newsletter-campaign-kit' ) );
	}

	$reports = newsletter_campaign_kit_get_campaign_reports();
	$totals  = newsletter_campaign_kit_get_campaign_report_totals();
	$subscriptions = newsletter_campaign_kit_get_subscription_report_totals();
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Campaign reports', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Delivery, engagement and attributed conversion metrics from first-party campaign events. Open rates remain estimates when mail clients block or proxy images.', 'newsletter-campaign-kit' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( newsletter_campaign_kit_get_export_url( 'campaigns' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export campaign reports', 'newsletter-campaign-kit' ); ?></a></p>

		<div class="nck-grid">
			<div class="nck-card"><span><?php esc_html_e( 'Campaigns', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['campaigns'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Queued', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['queued'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Sent', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['sent'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Failed', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['failed'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Unique opens', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['opened'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Unique clicks', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['clicked'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Attributed conversions', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['converted'] ) ); ?></strong></div>
		</div>

		<section class="nck-panel"><h2><?php esc_html_e( 'Subscription health', 'newsletter-campaign-kit' ); ?></h2><div class="nck-grid">
			<div class="nck-card"><span><?php esc_html_e( 'Active subscribers', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['subscribed'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Pending confirmation', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['pending'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'New confirmations (30 days)', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['confirmed_30d'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Unsubscribed', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['unsubscribed'] ) ); ?></strong></div>
		</div></section>

		<table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Campaign', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Audience snapshot', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Sent', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Delivery', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Open', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Click', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Click / open', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Conversion', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $reports ) ) : ?><tr><td colspan="9"><?php esc_html_e( 'No campaign report yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $reports as $report ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $report['title'] ); ?></strong><br><span><?php echo esc_html( $report['subject'] ); ?></span></td>
					<td><code><?php echo esc_html( $report['status'] ); ?></code></td>
					<td>
						<?php if ( ! empty( $report['snapshot_id'] ) ) : ?>
							<strong><?php echo esc_html( newsletter_campaign_kit_describe_audience_snapshot( $report ) ); ?></strong><br>
							<span><?php echo esc_html( sprintf( _n( '%d recipient', '%d recipients', $report['snapshot_recipient_count'], 'newsletter-campaign-kit' ), $report['snapshot_recipient_count'] ) ); ?></span><br>
							<small><?php echo esc_html( get_date_from_gmt( $report['snapshot_created_at'], 'Y-m-d H:i' ) ); ?></small>
							<?php if ( ! empty( $report['snapshot_rules'] ) ) : ?><details><summary><?php esc_html_e( 'Stored rules', 'newsletter-campaign-kit' ); ?></summary><code><?php echo esc_html( $report['snapshot_rules'] ); ?></code></details><?php endif; ?>
						<?php else : ?><?php esc_html_e( 'Not captured', 'newsletter-campaign-kit' ); ?><?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $report['sent_total'] ) ); ?></td>
					<td><?php echo esc_html( $report['delivery_rate'] . '%' ); ?></td>
					<td><?php echo esc_html( $report['open_rate'] . '%' ); ?><br><small><?php echo esc_html( number_format_i18n( $report['unique_open_total'] ) ); ?> <?php esc_html_e( 'unique', 'newsletter-campaign-kit' ); ?></small></td>
					<td><?php echo esc_html( $report['click_rate'] . '%' ); ?><br><small><?php echo esc_html( number_format_i18n( $report['unique_click_total'] ) ); ?> <?php esc_html_e( 'unique', 'newsletter-campaign-kit' ); ?></small></td>
					<td><?php echo esc_html( $report['click_to_open_rate'] . '%' ); ?></td>
					<td><?php echo esc_html( $report['conversion_rate'] . '%' ); ?><br><small><?php echo esc_html( number_format_i18n( $report['unique_conversion_total'] ) ); ?> <?php esc_html_e( 'attributed', 'newsletter-campaign-kit' ); ?></small></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
