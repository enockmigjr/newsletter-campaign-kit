<?php
/**
 * Admin interface for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check whether the subscribers table exists.
 *
 * @return bool
 */
function newsletter_campaign_kit_subscribers_table_exists() {
	global $wpdb;

	$table_name = newsletter_campaign_kit_get_subscribers_table();
	$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $found === $table_name;
}

/**
 * Register the admin menu.
 */
function newsletter_campaign_kit_register_admin_menu() {
	add_menu_page(
		__( 'Newsletter Kit', 'newsletter-campaign-kit' ),
		__( 'Newsletter Kit', 'newsletter-campaign-kit' ),
		'newsletter_manage_subscribers',
		'newsletter-campaign-kit',
		'newsletter_campaign_kit_render_subscribers_page',
		'dashicons-email-alt2',
		58
	);

	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Subscribers', 'newsletter-campaign-kit' ),
		__( 'Subscribers', 'newsletter-campaign-kit' ),
		'newsletter_manage_subscribers',
		'newsletter-campaign-kit',
		'newsletter_campaign_kit_render_subscribers_page'
	);
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Lists & segments', 'newsletter-campaign-kit' ),
		__( 'Lists & segments', 'newsletter-campaign-kit' ),
		'newsletter_manage_lists',
		'newsletter-campaign-kit-segments',
		'newsletter_campaign_kit_render_segments_page'
	);
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Import subscribers', 'newsletter-campaign-kit' ),
		__( 'Import CSV', 'newsletter-campaign-kit' ),
		'newsletter_manage_subscribers',
		'newsletter-campaign-kit-import',
		'newsletter_campaign_kit_render_import_page'
	);
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_admin_menu' );

/** Load one consistent responsive stylesheet on Newsletter Kit screens. */
function newsletter_campaign_kit_enqueue_admin_assets( $hook_suffix ) {
	if ( false === strpos( (string) $hook_suffix, 'newsletter-campaign-kit' ) ) {
		return;
	}

	wp_enqueue_style(
		'newsletter-campaign-kit-admin',
		NEWSLETTER_CAMPAIGN_KIT_URL . 'assets/css/admin.css',
		array(),
		NEWSLETTER_CAMPAIGN_KIT_VERSION
	);
	wp_enqueue_script( 'newsletter-campaign-kit-admin', NEWSLETTER_CAMPAIGN_KIT_URL . 'assets/js/admin.js', array(), NEWSLETTER_CAMPAIGN_KIT_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'newsletter_campaign_kit_enqueue_admin_assets' );

/**
 * Count subscribers by status.
 *
 * @return array<string,int>
 */
function newsletter_campaign_kit_get_subscriber_counts() {
	global $wpdb;

	$empty = array(
		'total'        => 0,
		'subscribed'   => 0,
		'pending'      => 0,
		'unsubscribed' => 0,
		'suppressed'   => 0,
	);

	if ( ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return $empty;
	}

	$table_name = newsletter_campaign_kit_get_subscribers_table();
	$rows       = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table_name} GROUP BY status", ARRAY_A );

	foreach ( (array) $rows as $row ) {
		$status = sanitize_key( $row['status'] );
		if ( isset( $empty[ $status ] ) ) {
			$empty[ $status ] = (int) $row['total'];
		}
		$empty['total'] += (int) $row['total'];
	}

	return $empty;
}

/**
 * Fetch subscribers for the admin list.
 *
 * @param array<string,mixed> $args Query args.
 * @return array<int,array<string,mixed>>
 */
function newsletter_campaign_kit_get_subscribers( $args = array() ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return array();
	}

	$defaults = array(
		'status' => '',
		'search' => '',
		'limit'  => 50,
		'offset' => 0,
	);
	$args     = wp_parse_args( $args, $defaults );

	$table_name = newsletter_campaign_kit_get_subscribers_table();
	$where      = array( '1=1' );
	$params     = array();

	$status = sanitize_key( $args['status'] );
	if ( in_array( $status, array( 'subscribed', 'pending', 'unsubscribed', 'suppressed' ), true ) ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}

	$search = sanitize_text_field( $args['search'] );
	if ( '' !== $search ) {
		$where[]  = 'email LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}

	$params[] = max( 1, min( 100, absint( $args['limit'] ) ) );
	$params[] = max( 0, absint( $args['offset'] ) );

	$sql = "SELECT id, email, status, source, created_at, updated_at FROM {$table_name} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

/** Count subscribers matching the same safe filters as the admin query. */
function newsletter_campaign_kit_count_subscribers( $args = array() ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return 0;
	}
	$args   = wp_parse_args( $args, array( 'status' => '', 'search' => '' ) );
	$where  = array( '1=1' );
	$params = array();
	$status = sanitize_key( $args['status'] );
	if ( in_array( $status, array( 'subscribed', 'pending', 'unsubscribed', 'suppressed' ), true ) ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}
	$search = sanitize_text_field( $args['search'] );
	if ( '' !== $search ) {
		$where[]  = 'email LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $search ) . '%';
	}
	$sql = 'SELECT COUNT(*) FROM ' . newsletter_campaign_kit_get_subscribers_table() . ' WHERE ' . implode( ' AND ', $where );

	return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
}

/** Render accessible WordPress pagination while preserving active filters. */
function newsletter_campaign_kit_render_pagination( $current_page, $total_items, $per_page, $base_args = array(), $page_key = 'paged' ) {
	$total_pages = max( 1, (int) ceil( absint( $total_items ) / max( 1, absint( $per_page ) ) ) );
	if ( $total_pages <= 1 ) {
		return;
	}
	$base_args              = array_filter( $base_args, static function ( $value ) { return '' !== $value && null !== $value; } );
	$base_args[ $page_key ] = '%#%';
	$links                  = paginate_links(
		array(
			'base'      => add_query_arg( $base_args, admin_url( 'admin.php' ) ),
			'format'    => '',
			'current'   => max( 1, absint( $current_page ) ),
			'total'     => $total_pages,
			'type'      => 'list',
			'prev_text' => __( 'Previous', 'newsletter-campaign-kit' ),
			'next_text' => __( 'Next', 'newsletter-campaign-kit' ),
		)
	);
	if ( $links ) {
		echo '<nav class="nck-pagination" aria-label="' . esc_attr__( 'List pagination', 'newsletter-campaign-kit' ) . '">' . wp_kses_post( $links ) . '</nav>';
	}
}

/**
 * Render a compact status card.
 *
 * @param string $label Card label.
 * @param int    $value Card value.
 */
function newsletter_campaign_kit_render_count_card( $label, $value ) {
	?>
	<div class="nck-card">
		<span><?php echo esc_html( $label ); ?></span>
		<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
	</div>
	<?php
}

/**
 * Render the subscribers admin page.
 */
function newsletter_campaign_kit_render_subscribers_page() {
	if ( ! current_user_can( 'newsletter_manage_subscribers' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter subscribers.', 'newsletter-campaign-kit' ) );
	}

	$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
	$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page     = 25;
	$subscribers = newsletter_campaign_kit_get_subscribers(
		array(
			'status' => $status,
			'search' => $search,
			'limit'  => $per_page,
			'offset' => ( $current_page - 1 ) * $per_page,
		)
	);
	$filtered_total = newsletter_campaign_kit_count_subscribers( array( 'status' => $status, 'search' => $search ) );
	$counts      = newsletter_campaign_kit_get_subscriber_counts();
	$suppression_page     = isset( $_GET['suppression_page'] ) ? max( 1, absint( $_GET['suppression_page'] ) ) : 1;
	$suppression_per_page = 20;
	$suppressions         = newsletter_campaign_kit_get_suppressions( $suppression_per_page, ( $suppression_page - 1 ) * $suppression_per_page );
	$suppression_total    = newsletter_campaign_kit_count_suppressions();
	$export_url  = wp_nonce_url( admin_url( 'admin-post.php?action=newsletter_campaign_kit_export_subscribers' ), 'newsletter_campaign_kit_export_subscribers' );
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Newsletter Campaign Kit', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Manage consented subscribers, status changes, and operational exports.', 'newsletter-campaign-kit' ); ?></p>

		<?php if ( ! newsletter_campaign_kit_subscribers_table_exists() ) : ?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'The subscribers table is not installed yet. Visit this page after activating the plugin with the database available.', 'newsletter-campaign-kit' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="nck-grid">
			<?php newsletter_campaign_kit_render_count_card( __( 'Total', 'newsletter-campaign-kit' ), $counts['total'] ); ?>
			<?php newsletter_campaign_kit_render_count_card( __( 'Subscribed', 'newsletter-campaign-kit' ), $counts['subscribed'] ); ?>
			<?php newsletter_campaign_kit_render_count_card( __( 'Pending confirmation', 'newsletter-campaign-kit' ), $counts['pending'] ); ?>
			<?php newsletter_campaign_kit_render_count_card( __( 'Unsubscribed', 'newsletter-campaign-kit' ), $counts['unsubscribed'] ); ?>
			<?php newsletter_campaign_kit_render_count_card( __( 'Suppressed', 'newsletter-campaign-kit' ), $counts['suppressed'] ); ?>
		</div>

		<form method="GET" class="nck-filters">
			<input type="hidden" name="page" value="newsletter-campaign-kit">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by email', 'newsletter-campaign-kit' ); ?>">
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'newsletter-campaign-kit' ); ?></option>
				<?php foreach ( array( 'subscribed', 'pending', 'unsubscribed', 'suppressed' ) as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $status, $option ); ?>><?php echo esc_html( ucfirst( $option ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button button-primary" type="submit"><?php esc_html_e( 'Filter', 'newsletter-campaign-kit' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=newsletter-campaign-kit' ) ); ?>"><?php esc_html_e( 'Reset', 'newsletter-campaign-kit' ); ?></a>
			<?php if ( current_user_can( 'newsletter_view_reports' ) ) : ?>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'newsletter-campaign-kit' ); ?></a>
			<?php endif; ?>
		</form>

		<div class="nck-table-wrap"><table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Email', 'newsletter-campaign-kit' ); ?></th>
					<th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th>
					<th><?php esc_html_e( 'Source', 'newsletter-campaign-kit' ); ?></th>
					<th><?php esc_html_e( 'Created', 'newsletter-campaign-kit' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'newsletter-campaign-kit' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'newsletter-campaign-kit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $subscribers ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No subscribers found.', 'newsletter-campaign-kit' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $subscribers as $subscriber ) : ?>
						<tr>
							<td><?php echo esc_html( $subscriber['email'] ); ?></td>
							<td><code><?php echo esc_html( $subscriber['status'] ); ?></code></td>
							<td><?php echo esc_html( $subscriber['source'] ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $subscriber['created_at'], 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $subscriber['updated_at'], 'Y-m-d H:i' ) ); ?></td>
							<td><?php newsletter_campaign_kit_render_subscriber_actions( (int) $subscriber['id'], $subscriber['status'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table></div>
		<?php newsletter_campaign_kit_render_pagination( $current_page, $filtered_total, $per_page, array( 'page' => 'newsletter-campaign-kit', 'status' => $status, 's' => $search ) ); ?>

		<h2><?php esc_html_e( 'Durable suppression registry', 'newsletter-campaign-kit' ); ?></h2>
		<p><?php esc_html_e( 'Active entries block re-import and delivery even when the subscriber record is removed. Released entries never resubscribe a contact automatically.', 'newsletter-campaign-kit' ); ?></p>
		<div class="nck-table-wrap"><table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Contact', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Reason', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Source', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Updated', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Action', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $suppressions ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No suppression has been recorded.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $suppressions as $suppression ) : ?><tr>
				<td><?php echo esc_html( $suppression['email'] ? $suppression['email'] : substr( $suppression['email_hash'], 0, 12 ) . '...' ); ?></td>
				<td><code><?php echo esc_html( $suppression['status'] ); ?></code></td><td><?php echo esc_html( $suppression['reason'] ); ?></td><td><?php echo esc_html( $suppression['source'] ); ?></td><td><?php echo esc_html( get_date_from_gmt( $suppression['updated_at'], 'Y-m-d H:i' ) ); ?></td>
				<td><?php if ( 'active' === $suppression['status'] ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="newsletter_campaign_kit_release_suppression"><input type="hidden" name="suppression_id" value="<?php echo esc_attr( $suppression['id'] ); ?>"><?php wp_nonce_field( 'newsletter_campaign_kit_release_suppression_' . absint( $suppression['id'] ) ); ?><button class="button button-small" type="submit"><?php esc_html_e( 'Release', 'newsletter-campaign-kit' ); ?></button></form><?php else : ?>-<?php endif; ?></td>
			</tr><?php endforeach; ?>
			</tbody>
		</table></div>
		<?php newsletter_campaign_kit_render_pagination( $suppression_page, $suppression_total, $suppression_per_page, array( 'page' => 'newsletter-campaign-kit', 'status' => $status, 's' => $search, 'paged' => $current_page ), 'suppression_page' ); ?>
	</div>
	<?php
}

/**
 * Render subscriber status actions.
 *
 * @param int    $subscriber_id Subscriber ID.
 * @param string $status        Current status.
 */
function newsletter_campaign_kit_render_subscriber_actions( $subscriber_id, $status ) {
	if ( 'suppressed' === $status ) {
		echo esc_html__( 'Release the suppression below before reactivation.', 'newsletter-campaign-kit' );
		return;
	}
	$actions = array(
		'subscribed'   => __( 'Subscribe', 'newsletter-campaign-kit' ),
		'unsubscribed' => __( 'Unsubscribe', 'newsletter-campaign-kit' ),
		'suppressed'   => __( 'Suppress', 'newsletter-campaign-kit' ),
	);
	?>
	<div class="nck-inline-actions">
		<?php foreach ( $actions as $next_status => $label ) : ?>
			<?php if ( $next_status === $status ) { continue; } ?>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="newsletter_campaign_kit_update_subscriber_status">
				<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( $subscriber_id ); ?>">
				<input type="hidden" name="subscriber_status" value="<?php echo esc_attr( $next_status ); ?>">
				<?php wp_nonce_field( 'newsletter_campaign_kit_update_subscriber_' . $subscriber_id ); ?>
				<button class="button button-small" type="submit"><?php echo esc_html( $label ); ?></button>
			</form>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Handle subscriber status updates.
 */
function newsletter_campaign_kit_handle_update_subscriber_status() {
	if ( ! current_user_can( 'newsletter_manage_subscribers' ) ) {
		wp_die( esc_html__( 'You are not allowed to update subscribers.', 'newsletter-campaign-kit' ) );
	}

	$subscriber_id = isset( $_POST['subscriber_id'] ) ? absint( $_POST['subscriber_id'] ) : 0;
	$status        = isset( $_POST['subscriber_status'] ) ? sanitize_key( wp_unslash( $_POST['subscriber_status'] ) ) : '';

	check_admin_referer( 'newsletter_campaign_kit_update_subscriber_' . $subscriber_id );

	if ( ! $subscriber_id || ! in_array( $status, array( 'subscribed', 'unsubscribed', 'suppressed' ), true ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit&updated=invalid' ) );
		exit;
	}

	$result = newsletter_campaign_kit_set_subscriber_status( $subscriber_id, $status, 'admin' );

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( is_wp_error( $result ) ? 'newsletter_status_update_failed' : 'newsletter_status_updated', is_wp_error( $result ) ? 'failure' : 'success', $subscriber_id, array( 'status' => $status, 'reason' => is_wp_error( $result ) ? $result->get_error_code() : '' ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit&updated=' . ( is_wp_error( $result ) ? 'failed' : 'success' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_update_subscriber_status', 'newsletter_campaign_kit_handle_update_subscriber_status' );

/**
 * Export subscriber data as a minimal CSV.
 */
function newsletter_campaign_kit_handle_export_subscribers() {
	if ( ! current_user_can( 'newsletter_view_reports' ) ) {
		wp_die( esc_html__( 'You are not allowed to export subscribers.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_export_subscribers' );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="newsletter-subscribers.csv"' );
	header( 'X-Content-Type-Options: nosniff' );

	$output = fopen( 'php://output', 'w' );
	if ( false === $output ) {
		exit;
	}

	$count = newsletter_campaign_kit_stream_subscribers_csv( $output );
	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_export_subscribers', is_wp_error( $count ) ? 'failure' : 'success', 0, array( 'count' => is_wp_error( $count ) ? 0 : $count ) );
	}
	fclose( $output );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_export_subscribers', 'newsletter_campaign_kit_handle_export_subscribers' );
