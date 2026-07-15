<?php
/**
 * Campaign delivery queue for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_queue_table_exists() {
	return function_exists( 'newsletter_campaign_kit_table_exists' )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_queue_table() );
}

function newsletter_campaign_kit_get_campaign_recipients( $campaign ) {
	global $wpdb;

	if ( empty( $campaign['id'] ) || ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return array();
	}

	$subscribers_table = newsletter_campaign_kit_get_subscribers_table();
	$list_id           = ! empty( $campaign['target_list_id'] ) ? absint( $campaign['target_list_id'] ) : 0;
	$segment_id        = ! empty( $campaign['target_segment_id'] ) ? absint( $campaign['target_segment_id'] ) : 0;
	$topic_id          = ! empty( $campaign['topic_id'] ) ? absint( $campaign['topic_id'] ) : 0;

	if ( $segment_id && function_exists( 'newsletter_campaign_kit_get_segment_recipients' ) ) {
		return newsletter_campaign_kit_filter_campaign_recipients( newsletter_campaign_kit_get_segment_recipients( $segment_id ), $topic_id );
	}

	if ( $list_id && function_exists( 'newsletter_campaign_kit_get_subscriber_lists_table' ) ) {
		$map_table = newsletter_campaign_kit_get_subscriber_lists_table();
		if ( function_exists( 'newsletter_campaign_kit_table_exists' ) && newsletter_campaign_kit_table_exists( $map_table ) ) {
			$sql = "SELECT s.id, s.email, s.email_hash, s.unsubscribe_token FROM {$subscribers_table} s INNER JOIN {$map_table} sl ON sl.subscriber_id = s.id WHERE s.status = %s AND sl.list_id = %d ORDER BY s.id ASC";
			$recipients = $wpdb->get_results( $wpdb->prepare( $sql, 'subscribed', $list_id ), ARRAY_A );
			return newsletter_campaign_kit_filter_campaign_recipients( $recipients, $topic_id );
		}
	}

	$recipients = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, email_hash, unsubscribe_token FROM {$subscribers_table} WHERE status = %s ORDER BY id ASC", 'subscribed' ), ARRAY_A );
	return newsletter_campaign_kit_filter_campaign_recipients( $recipients, $topic_id );
}

function newsletter_campaign_kit_enqueue_campaign( $campaign_id, $manage_transaction = true, $expected_fingerprint = '' ) {
	global $wpdb;

	$campaign = newsletter_campaign_kit_get_campaign( $campaign_id );
	if ( ! $campaign || ! newsletter_campaign_kit_queue_table_exists() || ! newsletter_campaign_kit_audience_snapshot_tables_exist() ) {
		return new WP_Error( 'newsletter_delivery_storage_unavailable', __( 'Campaign delivery storage is unavailable.', 'newsletter-campaign-kit' ) );
	}

	$queue_table = newsletter_campaign_kit_get_queue_table();
	$created     = 0;
	$now         = current_time( 'mysql', true );
	if ( $manage_transaction ) {
		$wpdb->query( 'START TRANSACTION' );
	}
	$locked_campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . newsletter_campaign_kit_get_campaigns_table() . ' WHERE id = %d FOR UPDATE', absint( $campaign_id ) ), ARRAY_A );
	if ( ! $locked_campaign ) {
		if ( $manage_transaction ) {
			$wpdb->query( 'ROLLBACK' );
		}
		return new WP_Error( 'newsletter_campaign_not_found', __( 'Campaign not found.', 'newsletter-campaign-kit' ) );
	}
	if ( '' !== $expected_fingerprint ) {
		$review = newsletter_campaign_kit_prepare_campaign_delivery_review( $locked_campaign );
		if ( is_wp_error( $review ) || ! hash_equals( $review['fingerprint'], sanitize_text_field( $expected_fingerprint ) ) ) {
			if ( $manage_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new WP_Error( 'newsletter_campaign_review_stale', __( 'The campaign or its audience changed. Review the delivery again.', 'newsletter-campaign-kit' ) );
		}
	}
	$snapshot = newsletter_campaign_kit_get_campaign_audience_snapshot( $campaign_id );
	if ( ! $snapshot ) {
		$recipients = newsletter_campaign_kit_get_campaign_recipients( $locked_campaign );
		$snapshot   = newsletter_campaign_kit_create_audience_snapshot( $locked_campaign, $recipients, get_current_user_id() );
		if ( is_wp_error( $snapshot ) ) {
			if ( $manage_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return $snapshot;
		}
	}
	$recipient_ids = newsletter_campaign_kit_get_audience_snapshot_member_ids( $snapshot['id'] );
	if ( 'paused' === $locked_campaign['status'] ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue_table} SET status = 'pending', next_attempt_at = %s, updated_at = %s WHERE campaign_id = %d AND status = 'paused'",
				$now,
				$now,
				$campaign_id
			)
		);
	}

	foreach ( $recipient_ids as $subscriber_id ) {
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$queue_table} (campaign_id, subscriber_id, status, attempts, next_attempt_at, created_at, updated_at) VALUES (%d, %d, %s, %d, %s, %s, %s)",
				absint( $campaign['id'] ),
				absint( $subscriber_id ),
				'pending',
				0,
				$now,
				$now,
				$now
			)
		);
		if ( false === $ok ) {
			if ( $manage_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new WP_Error( 'newsletter_queue_create_failed', __( 'The campaign queue could not be created.', 'newsletter-campaign-kit' ) );
		}
		if ( $ok ) {
			++$created;
		}
	}
	if ( $manage_transaction ) {
		$wpdb->query( 'COMMIT' );
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_campaign_queue_enqueued', 'success', 0, array( 'campaign_id' => absint( $campaign['id'] ), 'snapshot_id' => absint( $snapshot['id'] ), 'created' => $created, 'recipients' => count( $recipient_ids ) ) );
	}

	return $created;
}

function newsletter_campaign_kit_sync_queue_for_campaign_transition( $campaign_id, $next_status ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_queue_table_exists() ) {
		return;
	}

	$campaign_id = absint( $campaign_id );
	$next_status = sanitize_key( $next_status );
	$table       = newsletter_campaign_kit_get_queue_table();
	$now         = current_time( 'mysql', true );

	if ( 'sending' === $next_status ) {
		return newsletter_campaign_kit_enqueue_campaign( $campaign_id );
	}

	if ( in_array( $next_status, array( 'paused', 'cancelled' ), true ) ) {
		$queue_status = 'paused' === $next_status ? 'paused' : 'cancelled';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s WHERE campaign_id = %d AND status IN ('pending','processing','paused')",
				$queue_status,
				$now,
				$campaign_id
			)
		);
	}

	return true;
}

function newsletter_campaign_kit_get_queue_counts( $campaign_id = 0 ) {
	global $wpdb;

	$empty = array(
		'total'      => 0,
		'pending'    => 0,
		'processing' => 0,
		'sent'       => 0,
		'failed'     => 0,
		'paused'     => 0,
		'cancelled'  => 0,
	);

	if ( ! newsletter_campaign_kit_queue_table_exists() ) {
		return $empty;
	}

	$table       = newsletter_campaign_kit_get_queue_table();
	$campaign_id = absint( $campaign_id );
	$where       = $campaign_id ? $wpdb->prepare( 'WHERE campaign_id = %d', $campaign_id ) : '';
	$rows        = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} {$where} GROUP BY status", ARRAY_A );

	foreach ( (array) $rows as $row ) {
		$status = sanitize_key( $row['status'] );
		if ( isset( $empty[ $status ] ) ) {
			$empty[ $status ] = (int) $row['total'];
		}
		$empty['total'] += (int) $row['total'];
	}

	return $empty;
}

function newsletter_campaign_kit_get_recent_queue_items( $limit = 80 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_queue_table_exists() ) {
		return array();
	}

	$queue_table     = newsletter_campaign_kit_get_queue_table();
	$campaigns_table = newsletter_campaign_kit_get_campaigns_table();
	$limit           = max( 1, min( 150, absint( $limit ) ) );

	$sql = "SELECT q.*, c.title AS campaign_title FROM {$queue_table} q LEFT JOIN {$campaigns_table} c ON c.id = q.campaign_id ORDER BY q.updated_at DESC LIMIT %d";

	return $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
}

function newsletter_campaign_kit_process_queue_batch( $limit = 20 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_queue_table_exists() ) {
		return array( 'processed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0 );
	}

	$queue_table     = newsletter_campaign_kit_get_queue_table();
	$campaigns_table = newsletter_campaign_kit_get_campaigns_table();
	$limit           = max( 1, min( 100, absint( $limit ) ) );
	$now             = current_time( 'mysql', true );
	$items           = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT q.* FROM {$queue_table} q INNER JOIN {$campaigns_table} c ON c.id = q.campaign_id WHERE q.status = %s AND c.status = 'sending' AND q.attempts < %d AND q.next_attempt_at <= %s ORDER BY q.next_attempt_at ASC, q.id ASC LIMIT %d",
			'pending',
			5,
			$now,
			$limit
		),
		ARRAY_A
	);
	$result          = array( 'processed' => 0, 'sent' => 0, 'failed' => 0, 'retried' => 0 );

	foreach ( $items as $item ) {
		$item_id = absint( $item['id'] );
		$locked  = $wpdb->update(
			$queue_table,
			array( 'status' => 'processing', 'locked_at' => $now, 'updated_at' => $now ),
			array( 'id' => $item_id, 'status' => 'pending' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( ! $locked ) {
			continue;
		}

		++$result['processed'];
		$campaign   = newsletter_campaign_kit_get_campaign( (int) $item['campaign_id'] );
		$subscriber = newsletter_campaign_kit_get_queue_subscriber( (int) $item['subscriber_id'] );
		$send       = new WP_Error( 'newsletter_no_provider', __( 'No newsletter provider is configured yet.', 'newsletter-campaign-kit' ) );

		if ( $campaign && $subscriber ) {
			$ineligibility_reason = newsletter_campaign_kit_get_recipient_ineligibility_reason( $subscriber, $campaign );
			if ( '' === $ineligibility_reason ) {
				try {
					$send = apply_filters( 'newsletter_campaign_kit_send_email', $send, $campaign, $subscriber, $item );
				} catch ( Throwable $error ) {
					$send = new WP_Error( 'newsletter_provider_exception', __( 'The delivery provider failed unexpectedly.', 'newsletter-campaign-kit' ) );
					if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
						newsletter_campaign_kit_log_event( 'newsletter_provider_exception', 'failure', absint( $subscriber['id'] ), array( 'campaign_id' => absint( $campaign['id'] ) ) );
					}
				}
			} else {
				$wpdb->update(
					$queue_table,
					array( 'status' => 'cancelled', 'locked_at' => null, 'last_error' => $ineligibility_reason, 'updated_at' => $now ),
					array( 'id' => $item_id ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				continue;
			}
		}

		if ( true === $send ) {
			$wpdb->update(
				$queue_table,
				array( 'status' => 'sent', 'sent_at' => $now, 'last_error' => '', 'updated_at' => $now ),
				array( 'id' => $item_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			++$result['sent'];
			continue;
		}

		$attempts = absint( $item['attempts'] ) + 1;
		$error    = is_wp_error( $send ) ? $send->get_error_message() : __( 'Provider returned a non-success response.', 'newsletter-campaign-kit' );
		$status   = $attempts >= 5 ? 'failed' : 'pending';
		$delay    = min( 3600, 300 * pow( 2, max( 0, $attempts - 1 ) ) );
		$next     = gmdate( 'Y-m-d H:i:s', time() + $delay );

		$wpdb->update(
			$queue_table,
			array(
				'status'          => $status,
				'attempts'        => $attempts,
				'next_attempt_at' => $next,
				'locked_at'       => null,
				'last_error'      => substr( sanitize_text_field( $error ), 0, 255 ),
				'updated_at'      => $now,
			),
			array( 'id' => $item_id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( 'failed' === $status ) {
			++$result['failed'];
		} else {
			++$result['retried'];
		}
	}

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_queue_processed', 'info', 0, $result );
	}

	return $result;
}

function newsletter_campaign_kit_recover_stale_queue_locks( $timeout = 900 ) {
	global $wpdb;

	if ( ! newsletter_campaign_kit_queue_table_exists() ) {
		return 0;
	}

	$timeout = max( 300, min( 3600, absint( $timeout ) ) );
	$table   = newsletter_campaign_kit_get_queue_table();
	$now     = current_time( 'mysql', true );
	$stale   = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	return (int) $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', locked_at = NULL, next_attempt_at = %s, updated_at = %s WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at <= %s",
			$now,
			$now,
			$stale
		)
	);
}

function newsletter_campaign_kit_get_queue_subscriber( $subscriber_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	if ( ! $subscriber_id || ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return null;
	}

	$table = newsletter_campaign_kit_get_subscribers_table();

	return $wpdb->get_row( $wpdb->prepare( "SELECT id, email, email_hash, unsubscribe_token, status FROM {$table} WHERE id = %d LIMIT 1", $subscriber_id ), ARRAY_A );
}

function newsletter_campaign_kit_handle_process_queue() {
	if ( ! current_user_can( 'newsletter_send_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to process newsletter deliveries.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_process_queue' );
	$settings = newsletter_campaign_kit_get_provider_settings();
	newsletter_campaign_kit_process_queue_batch( $settings['queue_batch_size'] );

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-queue&processed=1' ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_process_queue', 'newsletter_campaign_kit_handle_process_queue' );

function newsletter_campaign_kit_register_queue_menu() {
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Queue', 'newsletter-campaign-kit' ),
		__( 'Queue', 'newsletter-campaign-kit' ),
		'newsletter_send_campaigns',
		'newsletter-campaign-kit-queue',
		'newsletter_campaign_kit_render_queue_page'
	);
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_queue_menu', 16 );

function newsletter_campaign_kit_render_queue_page() {
	if ( ! current_user_can( 'newsletter_send_campaigns' ) ) {
		wp_die( esc_html__( 'You are not allowed to view newsletter deliveries.', 'newsletter-campaign-kit' ) );
	}

	$counts = newsletter_campaign_kit_get_queue_counts();
	$items  = newsletter_campaign_kit_get_recent_queue_items();
	$health = function_exists( 'newsletter_campaign_kit_get_scheduler_health' ) ? newsletter_campaign_kit_get_scheduler_health() : array( 'status' => 'unknown', 'message' => __( 'Scheduler health is unavailable.', 'newsletter-campaign-kit' ) );
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Delivery queue', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Batch delivery queue with attempts, retry backoff, wp_mail delivery, and optional external provider handoff.', 'newsletter-campaign-kit' ); ?></p>
		<div class="notice <?php echo esc_attr( 'healthy' === $health['status'] ? 'notice-success' : ( 'pending' === $health['status'] ? 'notice-info' : 'notice-warning' ) ); ?> inline"><p><strong><?php echo esc_html( sprintf( __( 'Scheduler: %s', 'newsletter-campaign-kit' ), ucfirst( $health['status'] ) ) ); ?></strong> <?php echo esc_html( $health['message'] ); ?></p></div>

		<div class="nck-grid">
			<?php foreach ( array( 'total', 'pending', 'sent', 'failed', 'paused', 'cancelled' ) as $status ) : ?>
				<div class="nck-card"><span><?php echo esc_html( ucfirst( $status ) ); ?></span><strong><?php echo esc_html( number_format_i18n( $counts[ $status ] ) ); ?></strong></div>
			<?php endforeach; ?>
		</div>

		<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="newsletter_campaign_kit_process_queue">
			<?php wp_nonce_field( 'newsletter_campaign_kit_process_queue' ); ?>
			<?php submit_button( __( 'Process next batch', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
		</form>

		<table class="widefat fixed striped">
			<thead><tr><th><?php esc_html_e( 'Campaign', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscriber', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Status', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Attempts', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Next attempt', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Last error', 'newsletter-campaign-kit' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $items ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No queued delivery yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $items as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['campaign_title'] ? $item['campaign_title'] : '#' . absint( $item['campaign_id'] ) ); ?></td>
					<td><?php echo esc_html( '#' . absint( $item['subscriber_id'] ) ); ?></td>
					<td><code><?php echo esc_html( $item['status'] ); ?></code></td>
					<td><?php echo esc_html( absint( $item['attempts'] ) ); ?></td>
					<td><?php echo esc_html( get_date_from_gmt( $item['next_attempt_at'], 'Y-m-d H:i' ) ); ?></td>
					<td><?php echo esc_html( $item['last_error'] ? $item['last_error'] : '-' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
