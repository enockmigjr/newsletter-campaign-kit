<?php
/**
 * Campaign reporting for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Normalize report filters shared by data and count queries. */
function newsletter_campaign_kit_normalize_report_filters( $args = array() ) {
	if ( is_numeric( $args ) ) {
		$args = array( 'limit' => absint( $args ) );
	}

	return wp_parse_args(
		is_array( $args ) ? $args : array(),
		array(
			'limit'      => 25,
			'offset'     => 0,
			'campaign_id' => 0,
			'list_id'    => 0,
			'segment_id' => 0,
			'topic_id'   => 0,
			'status'     => '',
			'date_from'  => '',
			'date_to'    => '',
		)
	);
}

/** Build a prepared WHERE fragment for campaign report filters. */
function newsletter_campaign_kit_get_report_where( $args ) {
	$clauses = array( '1=1' );
	$values  = array();
	foreach ( array( 'campaign_id' => 'id', 'list_id' => 'target_list_id', 'segment_id' => 'target_segment_id', 'topic_id' => 'topic_id' ) as $key => $column ) {
		if ( absint( $args[ $key ] ) ) {
			$clauses[] = "c.{$column} = %d";
			$values[]  = absint( $args[ $key ] );
		}
	}
	$statuses = array_keys( newsletter_campaign_kit_get_campaign_statuses() );
	if ( in_array( sanitize_key( $args['status'] ), $statuses, true ) ) {
		$clauses[] = 'c.status = %s';
		$values[]  = sanitize_key( $args['status'] );
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_from'] ) ) {
		$clauses[] = 'c.updated_at >= %s';
		$values[]  = $args['date_from'] . ' 00:00:00';
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['date_to'] ) ) {
		$clauses[] = 'c.updated_at <= %s';
		$values[]  = $args['date_to'] . ' 23:59:59';
	}

	return array( implode( ' AND ', $clauses ), $values );
}

/** Calculate rates from campaign counters without database side effects. */
function newsletter_campaign_kit_calculate_report_rates( $row ) {
	$queued      = absint( $row['queued_total'] ?? 0 );
	$sent        = absint( $row['sent_total'] ?? 0 );
	$opens       = absint( $row['unique_open_total'] ?? 0 );
	$clicks      = absint( $row['unique_click_total'] ?? 0 );
	$conversions = absint( $row['unique_conversion_total'] ?? 0 );

	return array(
		'delivery_rate'      => $queued > 0 ? round( ( $sent / $queued ) * 100, 1 ) : 0,
		'open_rate'          => $sent > 0 ? round( ( $opens / $sent ) * 100, 1 ) : 0,
		'click_rate'         => $sent > 0 ? round( ( $clicks / $sent ) * 100, 1 ) : 0,
		'click_to_open_rate' => $opens > 0 ? round( ( $clicks / $opens ) * 100, 1 ) : 0,
		'conversion_rate'    => $clicks > 0 ? round( ( $conversions / $clicks ) * 100, 1 ) : 0,
	);
}

function newsletter_campaign_kit_get_campaign_reports( $args = array() ) {
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
	$args            = newsletter_campaign_kit_normalize_report_filters( $args );
	$limit           = max( 1, min( 500, absint( $args['limit'] ) ) );
	$offset          = absint( $args['offset'] );
	list( $where, $where_values ) = newsletter_campaign_kit_get_report_where( $args );
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
		COUNT(DISTINCT CASE WHEN q.attempts > 0 AND q.status IN ('pending', 'processing') THEN q.id END) AS retry_total,
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
		WHERE {$where}
		GROUP BY c.id
		ORDER BY c.updated_at DESC
		LIMIT %d OFFSET %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where_values, array( $limit, $offset ) ) ), ARRAY_A );

	foreach ( $rows as &$row ) {
		$row['queued_total']     = absint( $row['queued_total'] );
		$row['sent_total']       = absint( $row['sent_total'] );
		$row['failed_total']     = absint( $row['failed_total'] );
		$row['retry_total']      = absint( $row['retry_total'] );
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
		$row = array_merge( $row, newsletter_campaign_kit_calculate_report_rates( $row ) );
	}
	unset( $row );

	return $rows;
}

/** Count campaigns matching report filters. */
function newsletter_campaign_kit_count_campaign_reports( $args = array() ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_campaigns_table_exists() ) {
		return 0;
	}
	$args = newsletter_campaign_kit_normalize_report_filters( $args );
	list( $where, $values ) = newsletter_campaign_kit_get_report_where( $args );
	$sql = 'SELECT COUNT(*) FROM ' . newsletter_campaign_kit_get_campaigns_table() . " c WHERE {$where}";

	return absint( $values ? $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) : $wpdb->get_var( $sql ) );
}

/** Return authenticated provider outcome totals not attributable to one campaign. */
function newsletter_campaign_kit_get_provider_event_totals() {
	global $wpdb;

	$empty = array( 'bounce' => 0, 'complaint' => 0 );
	$table = newsletter_campaign_kit_get_provider_events_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return $empty;
	}
	$rows = $wpdb->get_results( "SELECT event_type, COUNT(*) AS total FROM {$table} WHERE status = 'processed' GROUP BY event_type", ARRAY_A );
	foreach ( (array) $rows as $row ) {
		if ( isset( $empty[ $row['event_type'] ] ) ) {
			$empty[ $row['event_type'] ] = absint( $row['total'] );
		}
	}

	return $empty;
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

/** Return current acquisition cohorts and sources from first-party subscription data. */
function newsletter_campaign_kit_get_subscription_breakdowns() {
	global $wpdb;

	$empty = array( 'monthly' => array(), 'sources' => array() );
	if ( ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return $empty;
	}
	$table  = newsletter_campaign_kit_get_subscribers_table();
	$cutoff = gmdate( 'Y-m-01 00:00:00', strtotime( '-11 months' ) );
	$monthly = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS cohort,
				COUNT(*) AS acquired,
				SUM(CASE WHEN status = 'subscribed' THEN 1 ELSE 0 END) AS active_now,
				SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) AS unsubscribed_now
			FROM {$table} WHERE created_at >= %s
			GROUP BY DATE_FORMAT(created_at, '%%Y-%%m') ORDER BY cohort ASC",
			$cutoff
		),
		ARRAY_A
	);
	$sources = $wpdb->get_results(
		"SELECT COALESCE(NULLIF(source, ''), 'unknown') AS acquisition_source,
			COUNT(*) AS acquired,
			SUM(CASE WHEN status = 'subscribed' THEN 1 ELSE 0 END) AS active_now,
			SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) AS unsubscribed_now
		FROM {$table}
		GROUP BY COALESCE(NULLIF(source, ''), 'unknown')
		ORDER BY acquired DESC LIMIT 12",
		ARRAY_A
	);

	return array(
		'monthly' => is_array( $monthly ) ? $monthly : array(),
		'sources' => is_array( $sources ) ? $sources : array(),
	);
}

/** Return privacy-bounded cross-campaign engagement breakdowns. */
function newsletter_campaign_kit_get_engagement_breakdowns() {
	global $wpdb;

	$empty  = array( 'timeline' => array(), 'links' => array(), 'devices' => array(), 'topics' => array() );
	$events = newsletter_campaign_kit_get_tracking_events_table();
	if ( ! newsletter_campaign_kit_table_exists( $events ) ) {
		return $empty;
	}
	$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
	$timeline = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE(created_at) AS event_day,
				SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) AS opens,
				SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS clicks,
				SUM(CASE WHEN event_type = 'conversion' THEN 1 ELSE 0 END) AS conversions
			FROM {$events} WHERE is_bot = 0 AND created_at >= %s
			GROUP BY DATE(created_at) ORDER BY event_day ASC",
			$cutoff
		),
		ARRAY_A
	);
	$links = $wpdb->get_results(
		"SELECT destination_url, destination_hash, event_label, COUNT(DISTINCT queue_id) AS unique_clicks, COUNT(*) AS total_clicks
		FROM {$events} WHERE event_type = 'click' AND is_bot = 0
		GROUP BY destination_hash, destination_url, event_label
		ORDER BY unique_clicks DESC, total_clicks DESC LIMIT 10",
		ARRAY_A
	);
	$devices = array( 'mobile' => 0, 'tablet' => 0, 'desktop' => 0, 'unknown' => 0 );
	foreach ( $wpdb->get_col( "SELECT user_agent FROM {$events} WHERE is_bot = 0 AND user_agent IS NOT NULL AND user_agent <> ''" ) as $user_agent ) {
		$user_agent = strtolower( (string) $user_agent );
		if ( preg_match( '/ipad|tablet|kindle/', $user_agent ) ) {
			++$devices['tablet'];
		} elseif ( preg_match( '/mobile|iphone|android/', $user_agent ) ) {
			++$devices['mobile'];
		} elseif ( preg_match( '/windows|macintosh|linux|x11/', $user_agent ) ) {
			++$devices['desktop'];
		} else {
			++$devices['unknown'];
		}
	}
	$topics = array();
	if ( newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
		$snapshots = newsletter_campaign_kit_get_audience_snapshots_table();
		$topics    = $wpdb->get_results(
			"SELECT COALESCE(NULLIF(sn.topic_label, ''), 'General') AS topic_label,
				COUNT(DISTINCT CASE WHEN e.event_type = 'open' AND e.is_bot = 0 THEN e.queue_id END) AS unique_opens,
				COUNT(DISTINCT CASE WHEN e.event_type = 'click' AND e.is_bot = 0 THEN e.queue_id END) AS unique_clicks,
				COUNT(DISTINCT CASE WHEN e.event_type = 'conversion' AND e.is_bot = 0 THEN e.queue_id END) AS conversions
			FROM {$events} e LEFT JOIN {$snapshots} sn ON sn.campaign_id = e.campaign_id
			GROUP BY COALESCE(NULLIF(sn.topic_label, ''), 'General')
			ORDER BY unique_clicks DESC, unique_opens DESC LIMIT 12",
			ARRAY_A
		);
	}

	return array(
		'timeline' => is_array( $timeline ) ? $timeline : array(),
		'links'    => is_array( $links ) ? $links : array(),
		'devices'  => $devices,
		'topics'   => is_array( $topics ) ? $topics : array(),
	);
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

	$current_page = isset( $_GET['report_page'] ) ? max( 1, absint( $_GET['report_page'] ) ) : 1;
	$per_page     = 25;
	$filters      = array(
		'campaign_id' => isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0,
		'list_id'     => isset( $_GET['list_id'] ) ? absint( $_GET['list_id'] ) : 0,
		'segment_id'  => isset( $_GET['segment_id'] ) ? absint( $_GET['segment_id'] ) : 0,
		'topic_id'    => isset( $_GET['topic_id'] ) ? absint( $_GET['topic_id'] ) : 0,
		'status'      => isset( $_GET['campaign_status'] ) ? sanitize_key( wp_unslash( $_GET['campaign_status'] ) ) : '',
		'date_from'   => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
		'date_to'     => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
	);
	$reports = newsletter_campaign_kit_get_campaign_reports( array_merge( $filters, array( 'limit' => $per_page, 'offset' => ( $current_page - 1 ) * $per_page ) ) );
	$report_total = newsletter_campaign_kit_count_campaign_reports( $filters );
	$totals  = newsletter_campaign_kit_get_campaign_report_totals();
	$subscriptions = newsletter_campaign_kit_get_subscription_report_totals();
	$subscription_breakdowns = newsletter_campaign_kit_get_subscription_breakdowns();
	$breakdowns    = newsletter_campaign_kit_get_engagement_breakdowns();
	$provider_events = newsletter_campaign_kit_get_provider_event_totals();
	$campaigns = newsletter_campaign_kit_get_campaigns( 100, 0 );
	$lists = newsletter_campaign_kit_get_lists( 100, 0 );
	$segments = newsletter_campaign_kit_get_segments( true, 100, 0 );
	$topics = newsletter_campaign_kit_get_topics( 100, 0 );
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<header class="nck-report-hero">
			<div><span class="nck-eyebrow"><?php esc_html_e( 'Performance intelligence', 'newsletter-campaign-kit' ); ?></span><h1><?php esc_html_e( 'Campaign reports', 'newsletter-campaign-kit' ); ?></h1><p><?php esc_html_e( 'Read delivery, audience growth, engagement and attributed conversions from first-party events. Open rates remain estimates when mail clients block or proxy images.', 'newsletter-campaign-kit' ); ?></p></div>
			<a class="button button-primary" href="<?php echo esc_url( newsletter_campaign_kit_get_export_url( 'campaigns' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Export CSV', 'newsletter-campaign-kit' ); ?></a>
		</header>
		<nav class="nck-report-nav" aria-label="<?php esc_attr_e( 'Report sections', 'newsletter-campaign-kit' ); ?>"><a href="#nck-report-overview"><?php esc_html_e( 'Overview', 'newsletter-campaign-kit' ); ?></a><a href="#nck-report-acquisition"><?php esc_html_e( 'Acquisition', 'newsletter-campaign-kit' ); ?></a><a href="#nck-report-engagement"><?php esc_html_e( 'Engagement', 'newsletter-campaign-kit' ); ?></a><a href="#nck-report-campaigns"><?php esc_html_e( 'Campaign detail', 'newsletter-campaign-kit' ); ?></a></nav>
		<section class="nck-report-filter-panel"><div><span class="nck-eyebrow"><?php esc_html_e( 'Focused analysis', 'newsletter-campaign-kit' ); ?></span><h2><?php esc_html_e( 'Campaign table filters', 'newsletter-campaign-kit' ); ?></h2><p><?php esc_html_e( 'Filters affect the detailed campaign table only. Portfolio, acquisition and engagement summaries remain global so totals stay comparable over time.', 'newsletter-campaign-kit' ); ?></p></div>
		<form class="nck-report-filters" method="GET" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="newsletter-campaign-kit-reports">
			<select name="campaign_id"><option value="0"><?php esc_html_e( 'All campaigns', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $campaigns as $campaign ) : ?><option value="<?php echo esc_attr( $campaign['id'] ); ?>" <?php selected( $filters['campaign_id'], $campaign['id'] ); ?>><?php echo esc_html( $campaign['title'] ); ?></option><?php endforeach; ?></select>
			<select name="list_id"><option value="0"><?php esc_html_e( 'All lists', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $lists as $list ) : ?><option value="<?php echo esc_attr( $list['id'] ); ?>" <?php selected( $filters['list_id'], $list['id'] ); ?>><?php echo esc_html( $list['name'] ); ?></option><?php endforeach; ?></select>
			<select name="segment_id"><option value="0"><?php esc_html_e( 'All segments', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $segments as $segment ) : ?><option value="<?php echo esc_attr( $segment['id'] ); ?>" <?php selected( $filters['segment_id'], $segment['id'] ); ?>><?php echo esc_html( $segment['name'] ); ?></option><?php endforeach; ?></select>
			<select name="topic_id"><option value="0"><?php esc_html_e( 'All topics', 'newsletter-campaign-kit' ); ?></option><?php foreach ( $topics as $topic ) : ?><option value="<?php echo esc_attr( $topic['id'] ); ?>" <?php selected( $filters['topic_id'], $topic['id'] ); ?>><?php echo esc_html( $topic['name'] ); ?></option><?php endforeach; ?></select>
			<select name="campaign_status"><option value=""><?php esc_html_e( 'All statuses', 'newsletter-campaign-kit' ); ?></option><?php foreach ( newsletter_campaign_kit_get_campaign_statuses() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
			<label><?php esc_html_e( 'From', 'newsletter-campaign-kit' ); ?><input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"></label>
			<label><?php esc_html_e( 'To', 'newsletter-campaign-kit' ); ?><input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"></label>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply filters', 'newsletter-campaign-kit' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit-reports' ) ); ?>"><?php esc_html_e( 'Reset', 'newsletter-campaign-kit' ); ?></a>
		</form>
		</section>

		<section id="nck-report-overview" class="nck-report-section"><span class="nck-eyebrow"><?php esc_html_e( 'Overview', 'newsletter-campaign-kit' ); ?></span><h2><?php esc_html_e( 'Global portfolio indicators', 'newsletter-campaign-kit' ); ?></h2>
		<div class="nck-grid nck-metric-grid">
			<div class="nck-card"><span><?php esc_html_e( 'Campaigns', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['campaigns'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Queued', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['queued'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Sent', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['sent'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Failed', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['failed'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Unique opens', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['opened'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Unique clicks', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['clicked'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Attributed conversions', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $totals['converted'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Provider bounces', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $provider_events['bounce'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Provider complaints', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $provider_events['complaint'] ) ); ?></strong></div>
		</div>
		<details class="nck-panel"><summary><strong><?php esc_html_e( 'KPI definitions and attribution', 'newsletter-campaign-kit' ); ?></strong></summary><p><?php esc_html_e( 'Sent means the configured transport accepted the message; it is not a mailbox delivery receipt. Delivery rate is sent divided by queued. Open and click rates use unique, non-automated recipients divided by sent messages. Click-to-open is unique clickers divided by unique openers.', 'newsletter-campaign-kit' ); ?></p><p><?php esc_html_e( 'A conversion is attributed to the most recent signed campaign click recorded by the first-party cookie during the 30-day attribution window. Provider bounces and complaints are authenticated globally but cannot be assigned to a campaign unless the provider supplies a campaign-safe identifier.', 'newsletter-campaign-kit' ); ?></p></details>
		</section>

		<section id="nck-report-acquisition" class="nck-panel nck-report-section"><span class="nck-eyebrow"><?php esc_html_e( 'Acquisition', 'newsletter-campaign-kit' ); ?></span><h2><?php esc_html_e( 'Subscription health', 'newsletter-campaign-kit' ); ?></h2><div class="nck-grid">
			<div class="nck-card"><span><?php esc_html_e( 'Active subscribers', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['subscribed'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Pending confirmation', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['pending'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'New confirmations (30 days)', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['confirmed_30d'] ) ); ?></strong></div>
			<div class="nck-card"><span><?php esc_html_e( 'Unsubscribed', 'newsletter-campaign-kit' ); ?></span><strong><?php echo esc_html( number_format_i18n( $subscriptions['unsubscribed'] ) ); ?></strong></div>
		</div></section>

		<div id="nck-report-engagement" class="nck-layout nck-report-section">
			<section class="nck-panel"><h2><?php esc_html_e( 'Acquisition cohorts', 'newsletter-campaign-kit' ); ?></h2><p><?php esc_html_e( 'Subscribers grouped by their registration month; status columns reflect their current state.', 'newsletter-campaign-kit' ); ?></p><div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Month', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Acquired', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Active now', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Unsubscribed now', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody><?php if ( empty( $subscription_breakdowns['monthly'] ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No acquisition data yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?><?php foreach ( $subscription_breakdowns['monthly'] as $cohort ) : ?><tr><td><?php echo esc_html( $cohort['cohort'] ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $cohort['acquired'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $cohort['active_now'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $cohort['unsubscribed_now'] ) ) ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
			<section class="nck-panel"><h2><?php esc_html_e( 'Acquisition sources', 'newsletter-campaign-kit' ); ?></h2><div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Source', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Acquired', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Active now', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Unsubscribed now', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody><?php if ( empty( $subscription_breakdowns['sources'] ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No source data yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?><?php foreach ( $subscription_breakdowns['sources'] as $source ) : ?><tr><td><code><?php echo esc_html( $source['acquisition_source'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( absint( $source['acquired'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $source['active_now'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $source['unsubscribed_now'] ) ) ); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
		</div>

		<div class="nck-layout">
			<section class="nck-panel">
				<h2><?php esc_html_e( 'Engagement over 30 days', 'newsletter-campaign-kit' ); ?></h2>
				<div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Day', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Opens', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Clicks', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Conversions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
				<?php if ( empty( $breakdowns['timeline'] ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No observed engagement in this period.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $breakdowns['timeline'] as $day ) : ?><tr><td><?php echo esc_html( $day['event_day'] ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $day['opens'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $day['clicks'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $day['conversions'] ) ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
			<section class="nck-panel">
				<h2><?php esc_html_e( 'Approximate devices', 'newsletter-campaign-kit' ); ?></h2>
				<p><?php esc_html_e( 'Derived from privacy-limited user-agent data and therefore approximate.', 'newsletter-campaign-kit' ); ?></p>
				<div class="nck-grid"><?php foreach ( $breakdowns['devices'] as $device => $count ) : ?><div class="nck-card"><span><?php echo esc_html( ucfirst( $device ) ); ?></span><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong></div><?php endforeach; ?></div>
			</section>
		</div>

		<div class="nck-layout">
			<section class="nck-panel">
				<h2><?php esc_html_e( 'Most clicked links', 'newsletter-campaign-kit' ); ?></h2>
				<div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Destination', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Unique clicks', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Total', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
				<?php if ( empty( $breakdowns['links'] ) ) : ?><tr><td colspan="3"><?php esc_html_e( 'No clicked link yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $breakdowns['links'] as $link ) : ?><tr><td><strong><?php echo esc_html( $link['event_label'] ?: __( 'Link', 'newsletter-campaign-kit' ) ); ?></strong><br><small><?php echo esc_html( $link['destination_url'] ?: substr( (string) $link['destination_hash'], 0, 16 ) . '...' ); ?></small></td><td><?php echo esc_html( number_format_i18n( absint( $link['unique_clicks'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $link['total_clicks'] ) ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
			<section class="nck-panel">
				<h2><?php esc_html_e( 'Engagement by topic', 'newsletter-campaign-kit' ); ?></h2>
				<div class="nck-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Topic', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Opens', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Clicks', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Conversions', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
				<?php if ( empty( $breakdowns['topics'] ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No topic engagement yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
				<?php foreach ( $breakdowns['topics'] as $topic ) : ?><tr><td><?php echo esc_html( $topic['topic_label'] ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $topic['unique_opens'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $topic['unique_clicks'] ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $topic['conversions'] ) ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div>
			</section>
		</div>

		<section id="nck-report-campaigns" class="nck-panel nck-report-section">
		<h2><?php esc_html_e( 'Campaign details', 'newsletter-campaign-kit' ); ?></h2>
		<p><?php echo esc_html( sprintf( _n( '%d campaign matches the current filters.', '%d campaigns match the current filters.', $report_total, 'newsletter-campaign-kit' ), $report_total ) ); ?></p>
		<div class="nck-table-wrap"><table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Campaign', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Audience snapshot', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Queue', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Sent', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Delivery', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Open', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Click', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Click / open', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Conversion', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $reports ) ) : ?><tr><td colspan="10"><?php esc_html_e( 'No campaign matches these filters.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
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
					<td><strong><?php echo esc_html( number_format_i18n( $report['queued_total'] ) ); ?></strong><br><small><?php echo esc_html( sprintf( __( '%1$d retrying, %2$d permanently failed', 'newsletter-campaign-kit' ), $report['retry_total'], $report['failed_total'] ) ); ?></small></td>
					<td><?php echo esc_html( number_format_i18n( $report['sent_total'] ) ); ?></td>
					<td><?php echo esc_html( $report['delivery_rate'] . '%' ); ?></td>
					<td><?php echo esc_html( $report['open_rate'] . '%' ); ?><br><small><?php echo esc_html( number_format_i18n( $report['unique_open_total'] ) ); ?> <?php esc_html_e( 'unique', 'newsletter-campaign-kit' ); ?></small></td>
					<td><?php echo esc_html( $report['click_rate'] . '%' ); ?><br><small><?php echo esc_html( number_format_i18n( $report['unique_click_total'] ) ); ?> <?php esc_html_e( 'unique', 'newsletter-campaign-kit' ); ?></small></td>
					<td><?php echo esc_html( $report['click_to_open_rate'] . '%' ); ?></td>
					<td><?php echo esc_html( $report['conversion_rate'] . '%' ); ?><br><small><?php echo esc_html( number_format_i18n( $report['unique_conversion_total'] ) ); ?> <?php esc_html_e( 'attributed', 'newsletter-campaign-kit' ); ?></small></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php newsletter_campaign_kit_render_pagination( $current_page, $report_total, $per_page, array_merge( array( 'page' => 'newsletter-campaign-kit-reports' ), $filters ), 'report_page' ); ?>
		</section>
	</div>
	<?php
}
