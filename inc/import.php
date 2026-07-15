<?php
/**
 * Secure CSV subscriber imports.
 *
 * @package NewsletterCampaignKit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return the maximum accepted import size in bytes. */
function newsletter_campaign_kit_import_max_bytes() {
	return (int) apply_filters( 'newsletter_campaign_kit_import_max_bytes', 5 * MB_IN_BYTES );
}

/** Return the maximum number of data rows processed per import. */
function newsletter_campaign_kit_import_max_rows() {
	return (int) apply_filters( 'newsletter_campaign_kit_import_max_rows', 5000 );
}

/** Normalize a CSV header for reliable matching. */
function newsletter_campaign_kit_normalize_import_header( $header ) {
	$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );

	return sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( $header ) ) ) );
}

/** Detect a common CSV delimiter from the first non-empty line. */
function newsletter_campaign_kit_detect_csv_delimiter( $line ) {
	$delimiters = array( ',', ';', "\t" );
	$selected   = ',';
	$max_fields = 0;

	foreach ( $delimiters as $delimiter ) {
		$fields = str_getcsv( (string) $line, $delimiter );
		if ( count( $fields ) > $max_fields ) {
			$max_fields = count( $fields );
			$selected   = $delimiter;
		}
	}

	return $selected;
}

/** Build normalized lookup maps for existing active lists and tags. */
function newsletter_campaign_kit_get_import_audience_maps() {
	global $wpdb;

	$maps = array( 'lists' => array(), 'tags' => array() );
	if ( ! newsletter_campaign_kit_segments_tables_exist() ) {
		return $maps;
	}

	$lists = $wpdb->get_results( 'SELECT id, name, slug FROM ' . newsletter_campaign_kit_get_lists_table() . " WHERE status = 'active'", ARRAY_A );
	$tags  = $wpdb->get_results( 'SELECT id, name, slug FROM ' . newsletter_campaign_kit_get_tags_table(), ARRAY_A );
	foreach ( array( 'lists' => $lists, 'tags' => $tags ) as $type => $items ) {
		foreach ( $items as $item ) {
			$maps[ $type ][ sanitize_title( $item['slug'] ) ] = (int) $item['id'];
			$maps[ $type ][ sanitize_title( $item['name'] ) ] = (int) $item['id'];
		}
	}

	return $maps;
}

/** Resolve a pipe-separated audience cell to existing IDs. */
function newsletter_campaign_kit_resolve_import_audiences( $value, $map, $label ) {
	$ids     = array();
	$unknown = array();
	foreach ( array_filter( array_map( 'trim', explode( '|', (string) $value ) ) ) as $name ) {
		$key = sanitize_title( $name );
		if ( isset( $map[ $key ] ) ) {
			$ids[] = (int) $map[ $key ];
		} else {
			$unknown[] = sanitize_text_field( $name );
		}
	}

	if ( $unknown ) {
		return new WP_Error(
			'newsletter_import_unknown_audience',
			sprintf(
				/* translators: 1: audience type, 2: comma-separated audience names. */
				__( 'Unknown %1$s: %2$s.', 'newsletter-campaign-kit' ),
				$label,
				implode( ', ', $unknown )
			)
		);
	}

	return array_values( array_unique( $ids ) );
}

/** Insert an unsubscribed administrative record without inventing consent. */
function newsletter_campaign_kit_import_unsubscribed_email( $email ) {
	global $wpdb;

	$email_hash = newsletter_campaign_kit_hash_email( $email );
	$now        = current_time( 'mysql', true );
	$inserted   = $wpdb->insert(
		newsletter_campaign_kit_get_subscribers_table(),
		array(
			'email_hash'        => $email_hash,
			'email'             => $email,
			'unsubscribe_token' => newsletter_campaign_kit_create_unsubscribe_token( $email_hash ),
			'status'            => 'unsubscribed',
			'source'            => 'admin_csv_import',
			'consent_text'      => '',
			'created_at'        => $now,
			'updated_at'        => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return false === $inserted
		? new WP_Error( 'newsletter_import_db_error', __( 'The subscriber could not be created.', 'newsletter-campaign-kit' ) )
		: (int) $wpdb->insert_id;
}

/** Apply one validated import row in a transaction. */
function newsletter_campaign_kit_apply_import_row( $row ) {
	global $wpdb;

	$wpdb->query( 'START TRANSACTION' );
	try {
		$subscriber_id = ! empty( $row['subscriber_id'] ) ? (int) $row['subscriber_id'] : 0;
		if ( in_array( $row['action'], array( 'create', 'reactivate', 'refresh' ), true ) ) {
			$result = newsletter_campaign_kit_subscribe_email( $row['email'], 'admin_csv_import', $row['consent'] );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			$subscriber = newsletter_campaign_kit_get_subscriber_by_email( $row['email'] );
			$subscriber_id = $subscriber ? (int) $subscriber['id'] : 0;
		} elseif ( 'create_unsubscribed' === $row['action'] ) {
			$result = newsletter_campaign_kit_import_unsubscribed_email( $row['email'] );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			$subscriber_id = (int) $result;
		} elseif ( 'unsubscribe' === $row['action'] ) {
			$result = newsletter_campaign_kit_set_subscriber_status( $subscriber_id, 'unsubscribed', 'admin_csv_import' );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
		}

		if ( ! $subscriber_id ) {
			throw new RuntimeException( __( 'The subscriber could not be resolved after import.', 'newsletter-campaign-kit' ) );
		}
		foreach ( $row['list_ids'] as $list_id ) {
			if ( ! newsletter_campaign_kit_assign_subscriber_to_list( $subscriber_id, $list_id ) ) {
				throw new RuntimeException( __( 'A list assignment could not be saved.', 'newsletter-campaign-kit' ) );
			}
		}
		foreach ( $row['tag_ids'] as $tag_id ) {
			if ( ! newsletter_campaign_kit_assign_subscriber_to_tag( $subscriber_id, $tag_id ) ) {
				throw new RuntimeException( __( 'A tag assignment could not be saved.', 'newsletter-campaign-kit' ) );
			}
		}

		$wpdb->query( 'COMMIT' );
		if ( function_exists( 'newsletter_campaign_kit_log_event' ) ) {
			newsletter_campaign_kit_log_event( 'newsletter_subscriber_imported', 'success', $subscriber_id, array( 'action' => $row['action'] ) );
		}
		return true;
	} catch ( Throwable $exception ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'newsletter_import_row_failed', $exception->getMessage() );
	}
}

/**
 * Inspect or apply a CSV import.
 *
 * @param string              $path    Readable CSV path.
 * @param array<string,mixed> $options Mapping and import options.
 * @return array<string,mixed>|WP_Error
 */
function newsletter_campaign_kit_process_csv_import( $path, $options = array() ) {
	global $wpdb;

	$defaults = array(
		'apply'            => false,
		'allow_reactivate' => false,
		'default_status'   => 'subscribed',
		'default_consent'  => '',
		'mapping'          => array( 'email' => 'email', 'status' => 'status', 'lists' => 'lists', 'tags' => 'tags', 'consent' => 'consent_text' ),
	);
	$options  = wp_parse_args( $options, $defaults );
	if ( ! is_readable( $path ) || filesize( $path ) > newsletter_campaign_kit_import_max_bytes() ) {
		return new WP_Error( 'newsletter_import_unreadable', __( 'The CSV file is missing, unreadable, or too large.', 'newsletter-campaign-kit' ) );
	}

	$handle = fopen( $path, 'rb' );
	if ( false === $handle ) {
		return new WP_Error( 'newsletter_import_open_failed', __( 'The CSV file could not be opened.', 'newsletter-campaign-kit' ) );
	}
	$first_line = fgets( $handle );
	$delimiter  = newsletter_campaign_kit_detect_csv_delimiter( $first_line );
	rewind( $handle );
	$headers = fgetcsv( $handle, 0, $delimiter );
	if ( ! is_array( $headers ) ) {
		fclose( $handle );
		return new WP_Error( 'newsletter_import_empty', __( 'The CSV file does not contain a header row.', 'newsletter-campaign-kit' ) );
	}
	$headers = array_map( 'newsletter_campaign_kit_normalize_import_header', $headers );
	$indexes = array();
	foreach ( $options['mapping'] as $field => $header ) {
		$normalized = newsletter_campaign_kit_normalize_import_header( $header );
		$index      = '' !== $normalized ? array_search( $normalized, $headers, true ) : false;
		$indexes[ sanitize_key( $field ) ] = false === $index ? null : (int) $index;
	}
	if ( ! isset( $indexes['email'] ) || null === $indexes['email'] ) {
		fclose( $handle );
		return new WP_Error( 'newsletter_import_email_header', __( 'The mapped email header was not found.', 'newsletter-campaign-kit' ) );
	}

	$audiences = newsletter_campaign_kit_get_import_audience_maps();
	$seen      = array();
	$report    = array( 'mode' => $options['apply'] ? 'apply' : 'preview', 'total' => 0, 'valid' => 0, 'applied' => 0, 'errors' => 0, 'rows' => array() );
	$line      = 1;
	while ( ( $columns = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
		$line++;
		if ( $line - 1 > newsletter_campaign_kit_import_max_rows() ) {
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'limit', 'message' => __( 'The maximum row count was exceeded.', 'newsletter-campaign-kit' ) );
			break;
		}
		if ( 1 === count( $columns ) && '' === trim( (string) $columns[0] ) ) {
			continue;
		}
		$report['total']++;
		$get = static function ( $field ) use ( $columns, $indexes ) {
			return isset( $indexes[ $field ] ) && null !== $indexes[ $field ] && isset( $columns[ $indexes[ $field ] ] ) ? trim( (string) $columns[ $indexes[ $field ] ] ) : '';
		};
		$email = sanitize_email( $get( 'email' ) );
		if ( ! is_email( $email ) ) {
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'reject', 'message' => __( 'Invalid email address.', 'newsletter-campaign-kit' ) );
			continue;
		}
		$email_hash = newsletter_campaign_kit_hash_email( $email );
		if ( isset( $seen[ $email_hash ] ) ) {
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'duplicate', 'message' => sprintf( __( 'Duplicate of CSV line %d.', 'newsletter-campaign-kit' ), $seen[ $email_hash ] ) );
			continue;
		}
		$seen[ $email_hash ] = $line;
		$status = sanitize_key( $get( 'status' ) ? $get( 'status' ) : $options['default_status'] );
		if ( ! in_array( $status, array( 'subscribed', 'unsubscribed' ), true ) ) {
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'reject', 'message' => __( 'Status must be subscribed or unsubscribed.', 'newsletter-campaign-kit' ) );
			continue;
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . newsletter_campaign_kit_get_subscribers_table() . ' WHERE email_hash = %s LIMIT 1', $email_hash ), ARRAY_A );
		if ( newsletter_campaign_kit_is_email_hash_suppressed( $email_hash ) || ( $existing && 'suppressed' === $existing['status'] ) ) {
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'suppressed', 'message' => __( 'The address is protected by an active suppression.', 'newsletter-campaign-kit' ) );
			continue;
		}
		$consent = sanitize_textarea_field( $get( 'consent' ) ? $get( 'consent' ) : $options['default_consent'] );
		$action  = $existing ? 'assign' : ( 'subscribed' === $status ? 'create' : 'create_unsubscribed' );
		if ( $existing && 'subscribed' !== $existing['status'] && 'subscribed' === $status ) {
			$action = 'reactivate';
			if ( empty( $options['allow_reactivate'] ) ) {
				$report['errors']++;
				$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'reactivate', 'message' => __( 'Reactivation requires the explicit import option.', 'newsletter-campaign-kit' ) );
				continue;
			}
		} elseif ( $existing && 'subscribed' === $existing['status'] && 'unsubscribed' === $status ) {
			$action = 'unsubscribe';
		} elseif ( $existing && 'subscribed' === $status && $consent ) {
			$action = 'refresh';
		}
		if ( in_array( $action, array( 'create', 'reactivate' ), true ) && '' === $consent ) {
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => $action, 'message' => __( 'Active subscriptions require recorded consent.', 'newsletter-campaign-kit' ) );
			continue;
		}
		$list_ids = newsletter_campaign_kit_resolve_import_audiences( $get( 'lists' ), $audiences['lists'], __( 'lists', 'newsletter-campaign-kit' ) );
		$tag_ids  = newsletter_campaign_kit_resolve_import_audiences( $get( 'tags' ), $audiences['tags'], __( 'tags', 'newsletter-campaign-kit' ) );
		if ( is_wp_error( $list_ids ) || is_wp_error( $tag_ids ) ) {
			$error = is_wp_error( $list_ids ) ? $list_ids : $tag_ids;
			$report['errors']++;
			$report['rows'][] = array( 'line' => $line, 'status' => 'error', 'action' => 'audience', 'message' => $error->get_error_message() );
			continue;
		}

		$row = array( 'email' => $email, 'subscriber_id' => $existing ? (int) $existing['id'] : 0, 'action' => $action, 'consent' => $consent, 'list_ids' => $list_ids, 'tag_ids' => $tag_ids );
		$report['valid']++;
		$row_status  = 'valid';
		$row_message = __( 'Ready to import.', 'newsletter-campaign-kit' );
		if ( $options['apply'] ) {
			$applied = newsletter_campaign_kit_apply_import_row( $row );
			if ( is_wp_error( $applied ) ) {
				$report['errors']++;
				$row_status  = 'error';
				$row_message = $applied->get_error_message();
			} else {
				$report['applied']++;
				$row_status  = 'applied';
				$row_message = __( 'Imported successfully.', 'newsletter-campaign-kit' );
			}
		}
		$report['rows'][] = array( 'line' => $line, 'status' => $row_status, 'action' => $action, 'message' => $row_message );
	}
	fclose( $handle );

	return $report;
}
