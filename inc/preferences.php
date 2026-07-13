<?php
/**
 * Subscriber topic preferences and public preference center.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Build a public preference-center URL from an opaque subscriber token. */
function newsletter_campaign_kit_get_preferences_url( $token ) {
	return add_query_arg(
		array(
			'action' => 'newsletter_campaign_kit_preferences',
			'token'  => sanitize_text_field( $token ),
		),
		admin_url( 'admin-post.php' )
	);
}

/** Find the minimal subscriber record authorized by a valid opaque token. */
function newsletter_campaign_kit_get_subscriber_by_token( $token ) {
	global $wpdb;

	$token = sanitize_text_field( $token );
	if ( ! newsletter_campaign_kit_is_valid_unsubscribe_token( $token ) || ! newsletter_campaign_kit_subscribers_table_exists() ) {
		return null;
	}

	$table = newsletter_campaign_kit_get_subscribers_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT id, email, email_hash, unsubscribe_token, status FROM {$table} WHERE unsubscribe_token = %s LIMIT 1", $token ), ARRAY_A );
}

/** Return active topics and the subscriber's effective choice for each one. */
function newsletter_campaign_kit_get_subscriber_topic_preferences( $subscriber_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$topics         = newsletter_campaign_kit_get_topics_table();
	$preferences    = newsletter_campaign_kit_get_subscriber_topics_table();
	if ( ! $subscriber_id || ! newsletter_campaign_kit_table_exists( $topics ) || ! newsletter_campaign_kit_table_exists( $preferences ) ) {
		return array();
	}

	$sql = "SELECT t.id, t.name, t.slug, t.description, t.color, COALESCE(st.status, 'subscribed') AS preference_status
		FROM {$topics} t LEFT JOIN {$preferences} st ON st.topic_id = t.id AND st.subscriber_id = %d
		WHERE t.status = 'active' ORDER BY t.name ASC";

	return $wpdb->get_results( $wpdb->prepare( $sql, $subscriber_id ), ARRAY_A );
}

/** Persist a complete set of topic choices for one subscriber. */
function newsletter_campaign_kit_set_topic_preferences( $subscriber_id, $selected_topic_ids ) {
	global $wpdb;

	$subscriber_id     = absint( $subscriber_id );
	$selected_topic_ids = is_array( $selected_topic_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $selected_topic_ids ) ) ) ) : array();
	$topics_table      = newsletter_campaign_kit_get_topics_table();
	$preferences_table = newsletter_campaign_kit_get_subscriber_topics_table();
	if ( ! $subscriber_id || ! newsletter_campaign_kit_table_exists( $preferences_table ) ) {
		return new WP_Error( 'newsletter_preferences_unavailable', __( 'Preferences could not be saved.', 'newsletter-campaign-kit' ) );
	}
	$subscriber_status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . newsletter_campaign_kit_get_subscribers_table() . ' WHERE id = %d LIMIT 1', $subscriber_id ) );
	if ( 'subscribed' !== $subscriber_status ) {
		return new WP_Error( 'newsletter_preferences_not_subscribed', __( 'Preferences are unavailable for this subscription.', 'newsletter-campaign-kit' ) );
	}

	$active_topic_ids = array_map( 'absint', $wpdb->get_col( "SELECT id FROM {$topics_table} WHERE status = 'active' ORDER BY id ASC" ) );
	$selected_topic_ids = array_values( array_intersect( $active_topic_ids, $selected_topic_ids ) );
	$now = current_time( 'mysql', true );
	$wpdb->query( 'START TRANSACTION' );
	foreach ( $active_topic_ids as $topic_id ) {
		$status = in_array( $topic_id, $selected_topic_ids, true ) ? 'subscribed' : 'unsubscribed';
		$sql    = "INSERT INTO {$preferences_table} (subscriber_id, topic_id, status, updated_at) VALUES (%d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)";
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $subscriber_id, $topic_id, $status, $now ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'newsletter_preferences_db_error', __( 'Preferences could not be saved.', 'newsletter-campaign-kit' ) );
		}
	}
	$wpdb->query( 'COMMIT' );

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_preferences_updated', 'success', $subscriber_id, array( 'topics_selected' => count( $selected_topic_ids ), 'topics_total' => count( $active_topic_ids ) ) );
	}

	return true;
}

/** Return whether a subscriber has not opted out of a campaign topic. */
function newsletter_campaign_kit_subscriber_accepts_topic( $subscriber_id, $topic_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$topic_id      = absint( $topic_id );
	if ( ! $topic_id ) {
		return true;
	}
	$table = newsletter_campaign_kit_get_subscriber_topics_table();
	if ( ! $subscriber_id || ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE subscriber_id = %d AND topic_id = %d LIMIT 1", $subscriber_id, $topic_id ) );
	return 'unsubscribed' !== $status;
}

/** Remove topic opt-outs and active suppressions from a resolved audience. */
function newsletter_campaign_kit_filter_campaign_recipients( $recipients, $topic_id ) {
	global $wpdb;

	$topic_id  = absint( $topic_id );
	$recipients = is_array( $recipients ) ? $recipients : array();
	if ( empty( $recipients ) ) {
		return $recipients;
	}
	if ( ! newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscriber_topics_table() ) || ! newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_suppressions_table() ) ) {
		return array();
	}

	$subscriber_ids = array_values( array_unique( array_filter( array_map( static function ( $recipient ) {
		return absint( $recipient['id'] ?? 0 );
	}, $recipients ) ) ) );
	if ( empty( $subscriber_ids ) ) {
		return array();
	}
	$table        = newsletter_campaign_kit_get_subscriber_topics_table();
	$placeholders = implode( ',', array_fill( 0, count( $subscriber_ids ), '%d' ) );
	$params       = array_merge( array( $topic_id ), $subscriber_ids );
	$opted_out    = $topic_id ? array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT subscriber_id FROM {$table} WHERE topic_id = %d AND status = 'unsubscribed' AND subscriber_id IN ({$placeholders})", $params ) ) ) : array();
	$suppressed_hashes = array();
	$email_hashes      = array_values( array_unique( array_filter( array_map( static function ( $recipient ) {
		return sanitize_text_field( $recipient['email_hash'] ?? '' );
	}, $recipients ) ) ) );
	if ( ! empty( $email_hashes ) ) {
		$suppressions       = newsletter_campaign_kit_get_suppressions_table();
		$hash_placeholders  = implode( ',', array_fill( 0, count( $email_hashes ), '%s' ) );
		$suppressed_hashes  = $wpdb->get_col( $wpdb->prepare( "SELECT email_hash FROM {$suppressions} WHERE status = 'active' AND email_hash IN ({$hash_placeholders})", $email_hashes ) );
	}

	return array_values( array_filter( $recipients, static function ( $recipient ) use ( $opted_out, $suppressed_hashes ) {
		return ! in_array( absint( $recipient['id'] ?? 0 ), $opted_out, true )
			&& ! in_array( sanitize_text_field( $recipient['email_hash'] ?? '' ), $suppressed_hashes, true );
	} ) );
}

/** Return a stable cancellation reason, or an empty string when delivery is allowed. */
function newsletter_campaign_kit_get_recipient_ineligibility_reason( $subscriber, $campaign ) {
	if ( ! newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_suppressions_table() ) ) {
		return 'compliance_storage_unavailable';
	}
	if ( ! empty( $campaign['topic_id'] ) && ! newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscriber_topics_table() ) ) {
		return 'compliance_storage_unavailable';
	}
	if ( empty( $subscriber['id'] ) || 'subscribed' !== ( $subscriber['status'] ?? '' ) ) {
		return 'not_subscribed';
	}
	if ( newsletter_campaign_kit_is_email_hash_suppressed( $subscriber['email_hash'] ?? '' ) ) {
		return 'address_suppressed';
	}
	if ( ! newsletter_campaign_kit_subscriber_accepts_topic( $subscriber['id'], $campaign['topic_id'] ?? 0 ) ) {
		return 'topic_opt_out';
	}

	return '';
}

/** Output the isolated preference-center document without exposing the token to referrers. */
function newsletter_campaign_kit_render_preferences_document( $subscriber, $status = '' ) {
	$status = sanitize_key( $status );
	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	}
	$topics = $subscriber ? newsletter_campaign_kit_get_subscriber_topic_preferences( $subscriber['id'] ) : array();
	$token  = $subscriber['unsubscribe_token'] ?? '';
	?><!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php esc_html_e( 'Newsletter preferences', 'newsletter-campaign-kit' ); ?></title>
		<style>body{margin:0;background:#f4f5f7;color:#17191c;font:16px/1.55 system-ui,sans-serif}.screen-reader-text{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.nck-preferences{box-sizing:border-box;max-width:720px;margin:8vh auto;padding:32px;background:#fff;border:1px solid #d9dde3;border-radius:8px}.nck-preferences h1{font:600 32px/1.2 Georgia,serif;margin:0 0 12px}.nck-preferences fieldset{border:0;margin:28px 0;padding:0}.nck-topic{display:grid;grid-template-columns:24px 1fr;gap:8px 12px;padding:16px 0;border-top:1px solid #e5e7eb}.nck-topic input{margin-top:5px}.nck-topic small{grid-column:2;color:#626871}.nck-actions{display:flex;gap:12px;flex-wrap:wrap}.nck-button{border:1px solid #17191c;border-radius:4px;background:#17191c;color:#fff;cursor:pointer;padding:10px 16px;font:inherit}.nck-button-secondary{background:#fff;color:#17191c}.nck-notice{border-left:4px solid #238636;background:#f0faf3;padding:12px 16px}@media(max-width:760px){.nck-preferences{margin:0;min-height:100vh;border:0;border-radius:0;padding:24px}}</style>
	</head>
	<body><main class="nck-preferences">
		<h1><?php esc_html_e( 'Newsletter preferences', 'newsletter-campaign-kit' ); ?></h1>
		<?php if ( ! $subscriber ) : ?>
			<p><?php esc_html_e( 'This preference link is invalid or has expired.', 'newsletter-campaign-kit' ); ?></p>
		<?php elseif ( 'subscribed' !== $subscriber['status'] ) : ?>
			<p class="nck-notice"><?php esc_html_e( 'This address is not subscribed to newsletter delivery. A new explicit subscription may be required before messages can be received again.', 'newsletter-campaign-kit' ); ?></p>
		<?php else : ?>
			<?php if ( 'updated' === $status ) : ?><p class="nck-notice"><?php esc_html_e( 'Your preferences were saved.', 'newsletter-campaign-kit' ); ?></p><?php endif; ?>
			<?php if ( 'failed' === $status ) : ?><p class="nck-notice"><?php esc_html_e( 'Your preferences could not be saved. Please try again.', 'newsletter-campaign-kit' ); ?></p><?php endif; ?>
			<p><?php esc_html_e( 'Choose the editorial themes you want to receive. Campaigns without a theme may still be delivered while the global subscription remains active.', 'newsletter-campaign-kit' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="newsletter_campaign_kit_update_preferences">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'newsletter_campaign_kit_preferences_' . $token ); ?>
				<fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Editorial themes', 'newsletter-campaign-kit' ); ?></legend>
				<?php foreach ( $topics as $topic ) : ?><label class="nck-topic"><input type="checkbox" name="topic_ids[]" value="<?php echo esc_attr( $topic['id'] ); ?>" <?php checked( 'unsubscribed' !== $topic['preference_status'] ); ?>><strong><?php echo esc_html( $topic['name'] ); ?></strong><small><?php echo esc_html( $topic['description'] ); ?></small></label><?php endforeach; ?>
				<?php if ( empty( $topics ) ) : ?><p><?php esc_html_e( 'No thematic preferences are available yet.', 'newsletter-campaign-kit' ); ?></p><?php endif; ?></fieldset>
				<button class="nck-button" type="submit"><?php esc_html_e( 'Save preferences', 'newsletter-campaign-kit' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:32px">
				<input type="hidden" name="action" value="newsletter_campaign_kit_confirm_unsubscribe">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<?php wp_nonce_field( 'newsletter_campaign_kit_preferences_' . $token ); ?>
				<button class="nck-button nck-button-secondary" type="submit"><?php esc_html_e( 'Unsubscribe from all newsletters', 'newsletter-campaign-kit' ); ?></button>
			</form>
		<?php endif; ?>
	</main></body></html><?php
}

/** Display the preference center on GET without mutating subscription state. */
function newsletter_campaign_kit_handle_preferences() {
	$token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$subscriber = newsletter_campaign_kit_get_subscriber_by_token( $token );
	$status     = isset( $_GET['preferences'] ) ? sanitize_key( wp_unslash( $_GET['preferences'] ) ) : '';
	if ( ! $subscriber ) {
		status_header( 404 );
	}
	newsletter_campaign_kit_render_preferences_document( $subscriber, $status );
	exit;
}
add_action( 'admin_post_nopriv_newsletter_campaign_kit_preferences', 'newsletter_campaign_kit_handle_preferences' );
add_action( 'admin_post_newsletter_campaign_kit_preferences', 'newsletter_campaign_kit_handle_preferences' );

/** Save topic choices after token authorization and CSRF validation. */
function newsletter_campaign_kit_handle_update_preferences() {
	$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$subscriber = newsletter_campaign_kit_get_subscriber_by_token( $token );
	$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! $subscriber || 'subscribed' !== $subscriber['status'] || ! wp_verify_nonce( $nonce, 'newsletter_campaign_kit_preferences_' . $token ) ) {
		status_header( 403 );
		newsletter_campaign_kit_render_preferences_document( null );
		exit;
	}
	$topic_ids = isset( $_POST['topic_ids'] ) && is_array( $_POST['topic_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['topic_ids'] ) ) : array();
	$result    = newsletter_campaign_kit_set_topic_preferences( $subscriber['id'], $topic_ids );
	wp_safe_redirect( add_query_arg( 'preferences', is_wp_error( $result ) ? 'failed' : 'updated', newsletter_campaign_kit_get_preferences_url( $token ) ) );
	exit;
}
add_action( 'admin_post_nopriv_newsletter_campaign_kit_update_preferences', 'newsletter_campaign_kit_handle_update_preferences' );
add_action( 'admin_post_newsletter_campaign_kit_update_preferences', 'newsletter_campaign_kit_handle_update_preferences' );

/** Confirm a browser-based global unsubscribe; RFC 8058 remains a separate POST path. */
function newsletter_campaign_kit_handle_confirm_unsubscribe() {
	$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$subscriber = newsletter_campaign_kit_get_subscriber_by_token( $token );
	$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! $subscriber || ! wp_verify_nonce( $nonce, 'newsletter_campaign_kit_preferences_' . $token ) ) {
		status_header( 403 );
		newsletter_campaign_kit_render_preferences_document( null );
		exit;
	}
	newsletter_campaign_kit_unsubscribe_by_token( $token );
	wp_safe_redirect( newsletter_campaign_kit_get_preferences_url( $token ) );
	exit;
}
add_action( 'admin_post_nopriv_newsletter_campaign_kit_confirm_unsubscribe', 'newsletter_campaign_kit_handle_confirm_unsubscribe' );
add_action( 'admin_post_newsletter_campaign_kit_confirm_unsubscribe', 'newsletter_campaign_kit_handle_confirm_unsubscribe' );
