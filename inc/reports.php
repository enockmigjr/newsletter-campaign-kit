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
	$limit           = max( 1, min( 100, absint( $limit ) ) );

	$sql = "SELECT c.id, c.title, c.subject, c.status, c.updated_at,
		COUNT(q.id) AS queued_total,
		SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END) AS sent_total,
		SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END) AS failed_total,
		SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END) AS pending_total,
		SUM(CASE WHEN q.status = 'processing' THEN 1 ELSE 0 END) AS processing_total,
		SUM(CASE WHEN q.status = 'paused' THEN 1 ELSE 0 END) AS paused_total,
		SUM(CASE WHEN q.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_total,
		MAX(q.sent_at) AS last_sent_at
		FROM {$campaigns_table} c
		LEFT JOIN {$queue_table} q ON q.campaign_id = c.id
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
		$row['delivery_rate']    = $row['queued_total'] > 0 ? round( ( $row['sent_total'] / $row['queued_total'] ) * 100, 1 ) : 0;
	}
	unset( $row );

	return $rows;
}

function newsletter_campaign_kit_get_campaign_report_totals() {
	$reports = newsletter_campaign_kit_get_campaign_reports( 100 );
	$totals  = array(
		'campaigns' => count( $reports ),
		'queued'    => 0,
		'sent'      => 0,
		'failed'    => 0,
		'pending'   => 0,
	);

	foreach ( $reports as $report ) {
		$totals['queued']  += absint( $report['queued_total'] );
		$totals['sent']    += absint( $report['sent_total'] );
		$totals['failed']  += absint( $report['failed_total'] );
		$totals['pending'] += absint( $report['pending_total'] );
	}

	return $totals;
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
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Campaign reports', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Delivery totals built from the queue. Open and click tracking are intentionally not reported until tracking endpoints exist.', 'newsletter-campaign-kit' ); ?></p>

		<div class="nck-grid">
			<div class="nck-card"><span><?php esc_html_e( 'Campaigns', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['campaigns'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Queued', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['queued'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Sent', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['sent'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Failed', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['failed'] ) ); ?></strong></div>
		</div>

		<table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Campaign', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Queued', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Sent', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Failed', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Pending', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Delivery', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Last sent', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $reports ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No campaign report yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $reports as $report ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $report['title'] ); ?></strong><br><span><?php echo esc_html( $report['subject'] ); ?></span></td>
					<td><code><?php echo esc_html( $report['status'] ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( $report['queued_total'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $report['sent_total'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $report['failed_total'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $report['pending_total'] ) ); ?></td>
					<td><?php echo esc_html( $report['delivery_rate'] . '%' ); ?></td>
					<td><?php echo ! empty( $report['last_sent_at'] ) ? esc_html( get_date_from_gmt( $report['last_sent_at'], 'Y-m-d H:i' ) ) : esc_html__( 'None', 'newsletter-campaign-kit' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}