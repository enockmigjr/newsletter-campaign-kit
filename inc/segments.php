<?php
/**
 * Lists and tags for Newsletter Campaign Kit.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_campaign_kit_table_exists( $table_name ) {
	global $wpdb;

	$table_name = sanitize_text_field( $table_name );
	$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	return $found === $table_name;
}

function newsletter_campaign_kit_segments_tables_exist() {
	return newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_lists_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_tags_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscriber_lists_table() )
		&& newsletter_campaign_kit_table_exists( newsletter_campaign_kit_get_subscriber_tags_table() );
}

function newsletter_campaign_kit_generate_unique_slug( $table_name, $name ) {
	global $wpdb;

	$base = sanitize_title( $name );
	$base = '' !== $base ? $base : 'segment';
	$slug = $base;
	$i    = 2;

	while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE slug = %s LIMIT 1", $slug ) ) ) {
		$slug = $base . '-' . $i;
		$i++;
	}

	return $slug;
}

function newsletter_campaign_kit_get_default_list_id() {
	global $wpdb;

	$table = newsletter_campaign_kit_get_lists_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return 0;
	}

	$list_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", 'editorial-updates' ) );
	if ( $list_id ) {
		return $list_id;
	}

	$now = current_time( 'mysql', true );
	$wpdb->insert(
		$table,
		array(
			'name'        => __( 'Editorial updates', 'newsletter-campaign-kit' ),
			'slug'        => 'editorial-updates',
			'description' => __( 'Default list for public newsletter subscriptions.', 'newsletter-campaign-kit' ),
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

function newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_id, $list_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$list_id       = absint( $list_id );
	if ( ! $subscriber_id || ! $list_id ) {
		return false;
	}

	$table = newsletter_campaign_kit_get_subscriber_lists_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT subscriber_id FROM {$table} WHERE subscriber_id = %d AND list_id = %d LIMIT 1",
			$subscriber_id,
			$list_id
		)
	);
	if ( $existing ) {
		return true;
	}

	return false !== $wpdb->insert(
		$table,
		array(
			'subscriber_id' => $subscriber_id,
			'list_id'       => $list_id,
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%d', '%d', '%s' )
	);
}

function newsletter_campaign_kit_assign_subscriber_to_tag( $subscriber_id, $tag_id ) {
	global $wpdb;

	$subscriber_id = absint( $subscriber_id );
	$tag_id        = absint( $tag_id );
	if ( ! $subscriber_id || ! $tag_id ) {
		return false;
	}

	$table = newsletter_campaign_kit_get_subscriber_tags_table();
	if ( ! newsletter_campaign_kit_table_exists( $table ) ) {
		return false;
	}

	return false !== $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$table} (subscriber_id, tag_id, created_at) VALUES (%d, %d, %s)",
			$subscriber_id,
			$tag_id,
			current_time( 'mysql', true )
		)
	);
}

function newsletter_campaign_kit_get_lists() {
	global $wpdb;

	$lists_table = newsletter_campaign_kit_get_lists_table();
	$map_table   = newsletter_campaign_kit_get_subscriber_lists_table();
	if ( ! newsletter_campaign_kit_segments_tables_exist() ) {
		return array();
	}

	$sql = "SELECT l.*, COUNT(sl.subscriber_id) AS subscribers_count FROM {$lists_table} l LEFT JOIN {$map_table} sl ON sl.list_id = l.id GROUP BY l.id ORDER BY l.updated_at DESC";

	return $wpdb->get_results( $sql, ARRAY_A );
}

function newsletter_campaign_kit_get_tags() {
	global $wpdb;

	$tags_table = newsletter_campaign_kit_get_tags_table();
	$map_table  = newsletter_campaign_kit_get_subscriber_tags_table();
	if ( ! newsletter_campaign_kit_segments_tables_exist() ) {
		return array();
	}

	$sql = "SELECT t.*, COUNT(st.subscriber_id) AS subscribers_count FROM {$tags_table} t LEFT JOIN {$map_table} st ON st.tag_id = t.id GROUP BY t.id ORDER BY t.updated_at DESC";

	return $wpdb->get_results( $sql, ARRAY_A );
}

function newsletter_campaign_kit_handle_create_list() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter lists.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_list' );

	$name        = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
	$description = isset( $_POST['list_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_description'] ) ) : '';
	if ( '' === $name || ! newsletter_campaign_kit_segments_tables_exist() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_lists_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'name'        => $name,
			'slug'        => newsletter_campaign_kit_generate_unique_slug( $table, $name ),
			'description' => $description,
			'status'      => 'active',
			'created_at'  => $now,
			'updated_at'  => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_list_create_failed' : 'newsletter_list_created', false === $ok ? 'failure' : 'success', 0, array( 'name' => $name ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=' . ( false === $ok ? 'failed' : 'list' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_list', 'newsletter_campaign_kit_handle_create_list' );

function newsletter_campaign_kit_handle_create_tag() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter tags.', 'newsletter-campaign-kit' ) );
	}

	check_admin_referer( 'newsletter_campaign_kit_create_tag' );

	$name  = isset( $_POST['tag_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_name'] ) ) : '';
	$color = isset( $_POST['tag_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['tag_color'] ) ) : '';
	if ( '' === $name || ! newsletter_campaign_kit_segments_tables_exist() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=invalid' ) );
		exit;
	}

	global $wpdb;
	$table = newsletter_campaign_kit_get_tags_table();
	$now   = current_time( 'mysql', true );
	$ok    = $wpdb->insert(
		$table,
		array(
			'name'       => $name,
			'slug'       => newsletter_campaign_kit_generate_unique_slug( $table, $name ),
			'color'      => $color ? $color : '#111827',
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);

	if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
		newsletter_campaign_kit_log_event( false === $ok ? 'newsletter_tag_create_failed' : 'newsletter_tag_created', false === $ok ? 'failure' : 'success', 0, array( 'name' => $name ) );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=newsletter-campaign-kit-segments&created=' . ( false === $ok ? 'failed' : 'tag' ) ) );
	exit;
}
add_action( 'admin_post_newsletter_campaign_kit_create_tag', 'newsletter_campaign_kit_handle_create_tag' );

function newsletter_campaign_kit_render_segments_page() {
	if ( ! current_user_can( 'newsletter_manage_lists' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage newsletter segments.', 'newsletter-campaign-kit' ) );
	}

	$lists = newsletter_campaign_kit_get_lists();
	$tags  = newsletter_campaign_kit_get_tags();
	$segments = function_exists( 'newsletter_campaign_kit_get_segments' ) ? newsletter_campaign_kit_get_segments() : array();
	$topics   = function_exists( 'newsletter_campaign_kit_get_topics' ) ? newsletter_campaign_kit_get_topics() : array();
	$subscribers = function_exists( 'newsletter_campaign_kit_get_subscribers' ) ? newsletter_campaign_kit_get_subscribers( array( 'limit' => 100 ) ) : array();
	?>
	<div class="wrap newsletter-campaign-kit-admin">
		<h1><?php esc_html_e( 'Lists & segments', 'newsletter-campaign-kit' ); ?></h1>
		<p><?php esc_html_e( 'Prepare editorial audiences with reusable lists and subscriber tags.', 'newsletter-campaign-kit' ); ?></p>

		<?php if ( ! newsletter_campaign_kit_segments_tables_exist() ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Segment tables are not installed yet. Reactivate or upgrade the plugin with the database available.', 'newsletter-campaign-kit' ); ?></p></div>
		<?php endif; ?>

		<div class="nck-layout">
			<section class="nck-panel">
				<h2><?php esc_html_e( 'Create list', 'newsletter-campaign-kit' ); ?></h2>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="newsletter_campaign_kit_create_list">
					<?php wp_nonce_field( 'newsletter_campaign_kit_create_list' ); ?>
					<p><input class="regular-text" name="list_name" required maxlength="120" placeholder="<?php esc_attr_e( 'Collectors, clients, public journal...', 'newsletter-campaign-kit' ); ?>"></p>
					<p><textarea class="large-text" name="list_description" rows="3" placeholder="<?php esc_attr_e( 'Audience intent and editorial use.', 'newsletter-campaign-kit' ); ?>"></textarea></p>
					<?php submit_button( __( 'Create list', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="nck-panel">
				<h2><?php esc_html_e( 'Create tag', 'newsletter-campaign-kit' ); ?></h2>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="newsletter_campaign_kit_create_tag">
					<?php wp_nonce_field( 'newsletter_campaign_kit_create_tag' ); ?>
					<p><input class="regular-text" name="tag_name" required maxlength="80" placeholder="<?php esc_attr_e( 'Portrait, private access, collector...', 'newsletter-campaign-kit' ); ?>"></p>
					<p><input type="color" name="tag_color" value="#111827"></p>
					<?php submit_button( __( 'Create tag', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<section class="nck-panel">
			<h2><?php esc_html_e( 'Assign subscriber audiences', 'newsletter-campaign-kit' ); ?></h2>
			<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nck-assignment-form">
				<input type="hidden" name="action" value="newsletter_campaign_kit_update_assignment">
				<?php wp_nonce_field( 'newsletter_campaign_kit_update_assignment' ); ?>
				<select name="subscriber_id" required>
					<option value=""><?php esc_html_e( 'Select subscriber', 'newsletter-campaign-kit' ); ?></option>
					<?php foreach ( $subscribers as $subscriber ) : ?><option value="<?php echo esc_attr( $subscriber['id'] ); ?>"><?php echo esc_html( $subscriber['email'] ); ?></option><?php endforeach; ?>
				</select>
				<select name="audience" required>
					<option value=""><?php esc_html_e( 'Select list or tag', 'newsletter-campaign-kit' ); ?></option>
					<?php foreach ( $lists as $list ) : ?><option value="<?php echo esc_attr( 'list:' . $list['id'] ); ?>"><?php echo esc_html( sprintf( __( 'List: %s', 'newsletter-campaign-kit' ), $list['name'] ) ); ?></option><?php endforeach; ?>
					<?php foreach ( $tags as $tag ) : ?><option value="<?php echo esc_attr( 'tag:' . $tag['id'] ); ?>"><?php echo esc_html( sprintf( __( 'Tag: %s', 'newsletter-campaign-kit' ), $tag['name'] ) ); ?></option><?php endforeach; ?>
				</select>
				<select name="assignment_operation">
					<option value="add"><?php esc_html_e( 'Add', 'newsletter-campaign-kit' ); ?></option>
					<option value="remove"><?php esc_html_e( 'Remove', 'newsletter-campaign-kit' ); ?></option>
				</select>
				<button class="button button-primary" type="submit"><?php esc_html_e( 'Update assignment', 'newsletter-campaign-kit' ); ?></button>
			</form>
		</section>

		<div class="nck-layout">
			<section class="nck-panel">
				<h2><?php esc_html_e( 'Create dynamic segment', 'newsletter-campaign-kit' ); ?></h2>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="newsletter_campaign_kit_create_segment">
					<?php wp_nonce_field( 'newsletter_campaign_kit_create_segment' ); ?>
					<p><input class="regular-text" name="segment_name" required maxlength="120" placeholder="<?php esc_attr_e( 'Active portrait collectors', 'newsletter-campaign-kit' ); ?>"></p>
					<p><textarea class="large-text" name="segment_description" rows="2" placeholder="<?php esc_attr_e( 'Purpose of this dynamic audience.', 'newsletter-campaign-kit' ); ?>"></textarea></p>
					<p>
						<label><?php esc_html_e( 'Match', 'newsletter-campaign-kit' ); ?>
						<select name="segment_match_type">
							<option value="all"><?php esc_html_e( 'All selected conditions', 'newsletter-campaign-kit' ); ?></option>
							<option value="any"><?php esc_html_e( 'Any selected condition', 'newsletter-campaign-kit' ); ?></option>
						</select></label>
					</p>
					<p>
						<label><?php esc_html_e( 'Lists', 'newsletter-campaign-kit' ); ?><br>
						<select name="segment_list_ids[]" multiple size="4">
							<?php foreach ( $lists as $list ) : ?><option value="<?php echo esc_attr( $list['id'] ); ?>"><?php echo esc_html( $list['name'] ); ?></option><?php endforeach; ?>
						</select></label>
					</p>
					<p>
						<label><?php esc_html_e( 'Tags', 'newsletter-campaign-kit' ); ?><br>
						<select name="segment_tag_ids[]" multiple size="4">
							<?php foreach ( $tags as $tag ) : ?><option value="<?php echo esc_attr( $tag['id'] ); ?>"><?php echo esc_html( $tag['name'] ); ?></option><?php endforeach; ?>
						</select></label>
					</p>
					<p><label><?php esc_html_e( 'Subscription sources', 'newsletter-campaign-kit' ); ?><br><input class="regular-text" name="segment_sources" placeholder="front_footer, checkout"></label></p>
					<p>
						<label><?php esc_html_e( 'Subscribed after', 'newsletter-campaign-kit' ); ?> <input type="date" name="segment_created_after"></label>
						<label><?php esc_html_e( 'Subscribed before', 'newsletter-campaign-kit' ); ?> <input type="date" name="segment_created_before"></label>
					</p>
					<?php submit_button( __( 'Create segment', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>

			<section class="nck-panel">
				<h2><?php esc_html_e( 'Create campaign topic', 'newsletter-campaign-kit' ); ?></h2>
				<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="newsletter_campaign_kit_create_topic">
					<?php wp_nonce_field( 'newsletter_campaign_kit_create_topic' ); ?>
					<p><input class="regular-text" name="topic_name" required maxlength="100" placeholder="<?php esc_attr_e( 'Portraits, exhibitions, private archives...', 'newsletter-campaign-kit' ); ?>"></p>
					<p><textarea class="large-text" name="topic_description" rows="3" placeholder="<?php esc_attr_e( 'Editorial scope of this topic.', 'newsletter-campaign-kit' ); ?>"></textarea></p>
					<p><input type="color" name="topic_color" value="#111827"></p>
					<?php submit_button( __( 'Create topic', 'newsletter-campaign-kit' ), 'primary', 'submit', false ); ?>
				</form>
			</section>
		</div>

		<h2><?php esc_html_e( 'Lists', 'newsletter-campaign-kit' ); ?></h2>
		<table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Slug', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscribers', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $lists ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No list yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $lists as $list ) : ?><tr><td><?php echo esc_html( $list['name'] ); ?></td><td><code><?php echo esc_html( $list['slug'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) $list['subscribers_count'] ) ); ?></td><td><?php echo esc_html( $list['description'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Tags', 'newsletter-campaign-kit' ); ?></h2>
		<table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Slug', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Subscribers', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Color', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $tags ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No tag yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $tags as $tag ) : ?><tr><td><?php echo esc_html( $tag['name'] ); ?></td><td><code><?php echo esc_html( $tag['slug'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( (int) $tag['subscribers_count'] ) ); ?></td><td><span class="nck-color" style="background:<?php echo esc_attr( $tag['color'] ); ?>"></span><?php echo esc_html( $tag['color'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Dynamic segments', 'newsletter-campaign-kit' ); ?></h2>
		<table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Mode', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Rules', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $segments ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No dynamic segment yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $segments as $segment ) : ?>
			<?php $rules = json_decode( $segment['rules'], true ); ?>
			<tr>
				<td><strong><?php echo esc_html( $segment['name'] ); ?></strong><br><code><?php echo esc_html( $segment['slug'] ); ?></code></td>
				<td><?php echo esc_html( 'any' === $segment['match_type'] ? __( 'Any', 'newsletter-campaign-kit' ) : __( 'All', 'newsletter-campaign-kit' ) ); ?></td>
				<td><?php echo esc_html( sprintf( __( '%1$d lists, %2$d tags, %3$d sources', 'newsletter-campaign-kit' ), count( isset( $rules['list_ids'] ) ? $rules['list_ids'] : array() ), count( isset( $rules['tag_ids'] ) ? $rules['tag_ids'] : array() ), count( isset( $rules['sources'] ) ? $rules['sources'] : array() ) ) ); ?></td>
				<td><?php echo esc_html( $segment['description'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Campaign topics', 'newsletter-campaign-kit' ); ?></h2>
		<table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Name', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Slug', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Color', 'newsletter-campaign-kit' ); ?></th><th><?php esc_html_e( 'Description', 'newsletter-campaign-kit' ); ?></th></tr></thead><tbody>
		<?php if ( empty( $topics ) ) : ?><tr><td colspan="4"><?php esc_html_e( 'No campaign topic yet.', 'newsletter-campaign-kit' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $topics as $topic ) : ?><tr><td><?php echo esc_html( $topic['name'] ); ?></td><td><code><?php echo esc_html( $topic['slug'] ); ?></code></td><td><span class="nck-color" style="background:<?php echo esc_attr( $topic['color'] ); ?>"></span><?php echo esc_html( $topic['color'] ); ?></td><td><?php echo esc_html( $topic['description'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table>
	</div>
	<style>.newsletter-campaign-kit-admin .nck-layout{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:18px 0}.newsletter-campaign-kit-admin .nck-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:18px 0;padding:16px}.newsletter-campaign-kit-admin .nck-assignment-form{display:flex;gap:8px;flex-wrap:wrap}.newsletter-campaign-kit-admin .nck-color{display:inline-block;width:14px;height:14px;border-radius:999px;margin-right:8px;vertical-align:-2px}@media(max-width:900px){.newsletter-campaign-kit-admin .nck-layout{grid-template-columns:1fr}.newsletter-campaign-kit-admin .nck-assignment-form{align-items:stretch;flex-direction:column}}</style>
	<?php
}
