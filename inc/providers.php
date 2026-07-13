<?php
/**
 * Delivery provider settings for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_get_provider_defaults() {
	return array(
		'provider'          => 'wp_mail',
		'from_name'         => get_bloginfo( 'name' ),
		'from_email'        => get_option( 'admin_email' ),
		'one_click_enabled' => false,
		'dkim_confirmed'    => false,
	);
}

function newsletter_campaign_kit_get_provider_settings() {
	$settings = get_option( 'newsletter_campaign_kit_provider_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$settings = wp_parse_args( $settings, newsletter_campaign_kit_get_provider_defaults() );
	$settings['provider']   = in_array( $settings['provider'], array( 'wp_mail', 'external_filter' ), true ) ? $settings['provider'] : 'wp_mail';
	$settings['from_name']  = substr( sanitize_text_field( $settings['from_name'] ), 0, 120 );
	$settings['from_email'] = sanitize_email( $settings['from_email'] );
	$settings['one_click_enabled'] = ! empty( $settings['one_click_enabled'] );
	$settings['dkim_confirmed']    = ! empty( $settings['dkim_confirmed'] );

	return $settings;
}

function newsletter_campaign_kit_save_provider_settings() {
	if ( ! current_user_can( 'newsletter_manage_settings' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter settings.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_save_provider_settings' );

	$provider   = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'wp_mail';
	$from_name  = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '';
	$from_email = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
	$settings   = array(
		'provider'          => in_array( $provider, array( 'wp_mail', 'external_filter' ), true ) ? $provider : 'wp_mail',
		'from_name'         => substr( $from_name, 0, 120 ),
		'from_email'        => is_email( $from_email ) ? $from_email : get_option( 'admin_email' ),
		'one_click_enabled' => ! empty( $_POST['one_click_enabled'] ),
		'dkim_confirmed'    => ! empty( $_POST['dkim_confirmed'] ),
	);

	update_option( 'newsletter_campaign_kit_provider_settings', $settings, false );

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( 'newsletter_provider_settings_saved', 'success', 0, array( 'provider' => $settings['provider'] ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-settings&saved=1' ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_save_provider_settings', 'newsletter_campaign_kit_save_provider_settings' );

function newsletter_campaign_kit_register_settings_menu() {
	add_submenu_page(
		'newsletter-campaign-kit',
		__( 'Settings', 'newsletter-campaign-kit' ),
		__( 'Settings', 'newsletter-campaign-kit' ),
		'newsletter_manage_settings',
		'newsletter-campaign-kit-settings',
		'newsletter_campaign_kit_render_settings_page'
	);
}
add_action( 'admin_menu', 'newsletter_campaign_kit_register_settings_menu', 30 );

function newsletter_campaign_kit_render_settings_page() {
	if ( ! current_user_can( 'newsletter_manage_settings' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter settings.', 'newsletter-campaign-kit' ) );
	}

	$settings = newsletter_campaign_kit_get_provider_settings();
	$unsubscribe_url = newsletter_campaign_kit_get_unsubscribe_url( str_repeat( 'a', 64 ) );
	$is_https        = 'https' === wp_parse_url( $unsubscribe_url, PHP_URL_SCHEME );
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Newsletter settings', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Configure the delivery adapter used by the queue. API providers should be connected through the provider filter without storing secrets in this plugin.', 'newsletter-campaign-kit' ); ?></p>
		<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-panel">
			<input type="hidden" name="action" value="newsletter_campaign_kit_save_provider_settings">
			<?php wp_nonce_field( 'newsletter_campaign_kit_save_provider_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="provider"><?php esc_html_e( 'Provider', 'newsletter-campaign-kit' ); ?></label></th>
					<td>
						<select id="provider" name="provider">
							<option value="wp_mail" <?php selected( $settings['provider'], 'wp_mail' ); ?>><?php esc_html_e( 'WordPress wp_mail', 'newsletter-campaign-kit' ); ?></option>
							<option value="external_filter" <?php selected( $settings['provider'], 'external_filter' ); ?>><?php esc_html_e( 'External filter/API adapter', 'newsletter-campaign-kit' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'wp_mail uses the WordPress mail stack. External filter lets another plugin return the delivery result.', 'newsletter-campaign-kit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'One-click unsubscribe', 'newsletter-campaign-kit' ); ?></th>
					<td>
						<label><input type="checkbox" name="one_click_enabled" value="1" <?php checked( $settings['one_click_enabled'] ); ?>> <?php esc_html_e( 'Add RFC 8058 unsubscribe headers to campaign emails', 'newsletter-campaign-kit' ); ?></label><br>
						<label><input type="checkbox" name="dkim_confirmed" value="1" <?php checked( $settings['dkim_confirmed'] ); ?>> <?php esc_html_e( 'I confirm that DKIM signs both unsubscribe headers', 'newsletter-campaign-kit' ); ?></label>
						<p class="description"><?php echo esc_html( $is_https ? __( 'HTTPS endpoint detected. Enable only after verifying the DKIM signature at the delivery provider.', 'newsletter-campaign-kit' ) : __( 'HTTPS is not detected. One-click headers remain disabled until the public WordPress admin URL uses HTTPS.', 'newsletter-campaign-kit' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="from_name"><?php esc_html_e( 'From name', 'newsletter-campaign-kit' ); ?></label></th>
					<td><input class="regular-text" id="from_name" name="from_name" maxlength="120" value="<?php echo esc_attr( $settings['from_name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="from_email"><?php esc_html_e( 'From email', 'newsletter-campaign-kit' ); ?></label></th>
					<td><input class="regular-text" id="from_email" name="from_email" type="email" value="<?php echo esc_attr( $settings['from_email'] ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

function newsletter_campaign_kit_render_campaign_body( $campaign, $subscriber ) {
	$body = isset( $campaign['body'] ) ? (string) $campaign['body'] : '';
	$body = '' !== trim( $body ) ? $body : '<p>' . esc_html( $campaign['subject'] ) . '</p>';
	$url  = function_exists( 'newsletter_campaign_kit_get_preferences_url' ) ? newsletter_campaign_kit_get_preferences_url( $subscriber['unsubscribe_token'] ) : '';
	if ( $url ) {
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage preferences or unsubscribe', 'newsletter-campaign-kit' ) . '</a></p>';
	}

	return wp_kses_post( $body );
}

function newsletter_campaign_kit_render_campaign_text( $campaign, $subscriber ) {
	$text = isset( $campaign['text_body'] ) ? newsletter_campaign_kit_sanitize_text_body( $campaign['text_body'] ) : '';
	if ( '' === $text ) {
		$html = isset( $campaign['body'] ) ? $campaign['body'] : '';
		$text = newsletter_campaign_kit_html_to_text( $html );
	}
	$url = function_exists( 'newsletter_campaign_kit_get_preferences_url' ) && ! empty( $subscriber['unsubscribe_token'] ) ? newsletter_campaign_kit_get_preferences_url( $subscriber['unsubscribe_token'] ) : '';
	if ( $url ) {
		$text .= "\n\n" . __( 'Manage preferences or unsubscribe:', 'newsletter-campaign-kit' ) . "\n" . esc_url_raw( $url );
	}

	return trim( $text );
}

/**
 * Build RFC 8058 headers when the secure endpoint and DKIM are confirmed.
 *
 * @param array $subscriber Subscriber record.
 * @param array $settings   Provider settings.
 * @return string[]
 */
function newsletter_campaign_kit_get_one_click_headers( $subscriber, $settings ) {
	$token = isset( $subscriber['unsubscribe_token'] ) ? sanitize_text_field( $subscriber['unsubscribe_token'] ) : '';
	if ( empty( $settings['one_click_enabled'] ) || empty( $settings['dkim_confirmed'] ) || ! newsletter_campaign_kit_is_valid_unsubscribe_token( $token ) ) {
		return array();
	}

	$url = newsletter_campaign_kit_get_unsubscribe_url( $token );
	if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
		return array();
	}

	return array(
		'List-Unsubscribe: <' . esc_url_raw( $url ) . '>',
		'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
	);
}

function newsletter_campaign_kit_send_with_wp_mail( $current_result, $campaign, $subscriber, $queue_item ) {
	if ( true === $current_result ) {
		return true;
	}

	$settings = newsletter_campaign_kit_get_provider_settings();
	if ( 'wp_mail' !== $settings['provider'] ) {
		return $current_result;
	}

	if ( empty( $subscriber['email'] ) || ! is_email( $subscriber['email'] ) ) {
		return new WP_Error( 'newsletter_invalid_recipient', __( 'Recipient email is invalid.', 'newsletter-campaign-kit' ) );
	}
	$ineligibility_reason = function_exists( 'newsletter_campaign_kit_get_recipient_ineligibility_reason' ) ? newsletter_campaign_kit_get_recipient_ineligibility_reason( $subscriber, $campaign ) : '';
	if ( '' !== $ineligibility_reason ) {
		return new WP_Error( 'newsletter_recipient_ineligible', __( 'The recipient is no longer eligible for this campaign.', 'newsletter-campaign-kit' ), array( 'reason' => $ineligibility_reason ) );
	}

	$subject = isset( $campaign['subject'] ) ? sanitize_text_field( $campaign['subject'] ) : '';
	if ( '' === $subject ) {
		return new WP_Error( 'newsletter_missing_subject', __( 'Campaign subject is missing.', 'newsletter-campaign-kit' ) );
	}

	$from_email = is_email( $settings['from_email'] ) ? $settings['from_email'] : get_option( 'admin_email' );
	$from_name  = '' !== $settings['from_name'] ? $settings['from_name'] : get_bloginfo( 'name' );
	$headers    = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: ' . $from_name . ' <' . $from_email . '>',
	);
	$headers    = array_merge( $headers, newsletter_campaign_kit_get_one_click_headers( $subscriber, $settings ) );
	$message    = newsletter_campaign_kit_render_campaign_body( $campaign, $subscriber );
	$alt_body   = newsletter_campaign_kit_render_campaign_text( $campaign, $subscriber );
	$set_alt_body = static function ( $phpmailer ) use ( $alt_body ) {
		$phpmailer->AltBody = $alt_body;
	};
	add_action( 'phpmailer_init', $set_alt_body );
	try {
		$sent = wp_mail( $subscriber['email'], $subject, $message, $headers );
	} finally {
		remove_action( 'phpmailer_init', $set_alt_body );
	}

	return $sent ? true : new WP_Error( 'newsletter_wp_mail_failed', __( 'wp_mail could not deliver the message.', 'newsletter-campaign-kit' ) );
}
add_filter( 'newsletter_campaign_kit_send_email', 'newsletter_campaign_kit_send_with_wp_mail', 10, 4 );
