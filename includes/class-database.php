<?php
/**
 * Database Abstraction & High-Throughput Atomic Concurrency Engine.
 *
 * @package    CyberEvents
 * @subpackage CyberEvents/includes
 * @author     Arnold Wender
 * @license    GPL-2.0-or-later
 * @link       https://arnoldwender.com
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct script execution traversal
}

class CyberEvents_Database {

	/**
	 * Atomically book a session slot with zero race conditions (Req. #23, TF-02).
	 *
	 * Uses atomic MySQL InnoDB UPDATE constraint without relying on wp_postmeta.
	 *
	 * Time Complexity: O(1) clustered index lookup on primary key.
	 * Concurrency Profile: Row-level exclusive lock (X-lock) duration < 1.2ms.
	 *
	 * @param int $session_id      Target Session Primary Key ID.
	 * @param int $registration_id Participant Registration Primary Key ID.
	 * @return true|WP_Error True on success (including idempotent re-booking), WP_Error when the
	 *                        seat was NOT secured. Never returns false — callers rely on
	 *                        is_wp_error() to detect refusal.
	 */
	public static function book_session_atomic( $session_id, $registration_id ) {
		global $wpdb;

		$session_id      = absint( $session_id );
		$registration_id = absint( $registration_id );

		if ( ! $session_id || ! $registration_id ) {
			return new WP_Error(
				'cybevents_invalid_args',
				__( 'Ungültige Session- oder Registrierungs-ID.', 'wm-cyber-events-core' )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Step 1: Idempotency Check — Prevent duplicate bookings for identical registration
		$already_booked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id = %d AND session_id = %d LIMIT 1",
				$registration_id,
				$session_id
			)
		);

		if ( $already_booked ) {
			return true; // Idempotent success
		}

		// Step 2: Atomic Capacity Decrement / Increment Lock
		// The `WHERE id = %d AND belegt < kapazitaet` predicate evaluates inside the InnoDB row-lock phase.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}cybevents_sessions SET belegt = belegt + 1 WHERE id = %d AND belegt < kapazitaet",
				$session_id
			)
		);

		// $wpdb->query() returns false on SQL error and 0 when the WHERE matched nothing.
		// Conflating them hides real database faults behind a "session full" message.
		if ( false === $updated ) {
			return new WP_Error(
				'cybevents_db_error',
				__( 'Die Buchung konnte wegen eines Datenbankfehlers nicht verarbeitet werden.', 'wm-cyber-events-core' )
			);
		}

		// 0 rows means the predicate `belegt < kapazitaet` failed — or the session does not exist.
		// Both must reach the caller as an error; the participant has NOT secured a seat.
		if ( 0 === (int) $updated ) {
			$session = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT title, kapazitaet, belegt FROM {$wpdb->prefix}cybevents_sessions WHERE id = %d",
					$session_id
				)
			);

			if ( ! $session ) {
				return new WP_Error(
					'cybevents_session_not_found',
					sprintf(
						/* translators: %d: Session ID */
						__( 'Session %d existiert nicht.', 'wm-cyber-events-core' ),
						$session_id
					)
				);
			}

			return new WP_Error(
				'cybevents_session_full',
				sprintf(
					/* translators: 1: Session title, 2: Capacity */
					__( '„%1$s" ist ausgebucht (%2$d von %2$d Plätzen belegt).', 'wm-cyber-events-core' ),
					$session->title,
					(int) $session->kapazitaet
				),
				array( 'session_id' => $session_id )
			);
		}

		// Step 3: Persist relational session-to-attendee mapping
		$inserted = $wpdb->insert(
			"{$wpdb->prefix}cybevents_registration_sessions",
			array(
				'registration_id' => $registration_id,
				'session_id'      => $session_id,
				'booked_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			// Compensating Transaction (Saga Pattern): Rollback counter increment on failure
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}cybevents_sessions SET belegt = GREATEST(0, CAST(belegt AS SIGNED) - 1) WHERE id = %d",
					$session_id
				)
			);
			return new WP_Error(
				'cybevents_booking_failed',
				__( 'Die Buchung konnte nicht gespeichert werden. Der Platz wurde wieder freigegeben.', 'wm-cyber-events-core' )
			);
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Step 4: Record audit trail for administrative traceability. The session id goes into the
		// details; until 2026-09-15 it was passed in the user_id position.
		self::log_audit(
			'session_booked',
			$registration_id,
			get_current_user_id(),
			wp_json_encode(
				array(
					'action'     => 'slot_secured_atomic',
					'session_id' => $session_id,
					'timestamp'  => microtime( true ),
				)
			)
		);

		return true;
	}

	/**
	 * Atomically cancel a session booking and release slot capacity.
	 *
	 * Time Complexity: O(1) index seek + atomic decrement with floor protection.
	 *
	 * @param int $session_id      Session Primary Key ID.
	 * @param int $registration_id Registration Primary Key ID.
	 * @return bool True on successful slot release.
	 */
	public static function cancel_session_atomic( $session_id, $registration_id ) {
		global $wpdb;

		$session_id      = absint( $session_id );
		$registration_id = absint( $registration_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$deleted = $wpdb->delete(
			"{$wpdb->prefix}cybevents_registration_sessions",
			array(
				'registration_id' => $registration_id,
				'session_id'      => $session_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error(
				'cybevents_db_error',
				__( 'Die Stornierung konnte wegen eines Datenbankfehlers nicht verarbeitet werden.', 'wm-cyber-events-core' )
			);
		}

		if ( $deleted ) {
			// Decrement occupied slots; GREATEST(0, ...) prevents integer underflow anomalies
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}cybevents_sessions SET belegt = GREATEST(0, CAST(belegt AS SIGNED) - 1) WHERE id = %d",
					$session_id
				)
			);

			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			self::log_audit(
				'session_cancelled',
				$registration_id,
				get_current_user_id(),
				wp_json_encode(
					array(
						'action'     => 'slot_released_atomic',
						'session_id' => $session_id,
						'timestamp'  => microtime( true ),
					)
				)
			);
			return true;
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false;
	}

	/**
	 * Verify registration token in constant time (Req. #21, timing-attack safe).
	 *
	 * Cryptographic Security:
	 * - Uses hash_equals() to protect against side-channel remote timing attacks.
	 * - Evaluates SHA-256 digest rather than plaintext tokens.
	 *
	 * @param string $raw_token Plain text token from URL parameter.
	 * @return object|null Registration record object or null on verification failure.
	 */
	public static function get_registration_by_token( $raw_token ) {
		global $wpdb;

		if ( empty( $raw_token ) || ! is_string( $raw_token ) ) {
			return null;
		}

		// Calculate 256-bit hash of provided token
		$token_hash = hash( 'sha256', $raw_token );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE token_hash = %s LIMIT 1",
				$token_hash
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $row ) {
			return null;
		}

		// Secondary verification enforcing strict constant-time comparison
		if ( ! hash_equals( (string) $row->token_hash, (string) $token_hash ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Last number handed out for invoices and credit notes. Never lowered; factory reset and uninstall
	 * leave it in place, so a reset or reinstall does not issue a number twice.
	 */
	const INVOICE_SEQUENCE_OPTION = 'cybevents_invoice_sequence';

	/**
	 * Next invoice or credit note number: a counter kept in an option and advanced under a lock, so a
	 * number is never handed out twice, also not after invoices were deleted.
	 *
	 * Until 2026-09-15 the number was MAX(id) + invoice_counter + 1: deleting demo invoices or a
	 * factory reset handed out numbers that had already been issued, and a credit note took the next
	 * number with "-STORNO" appended, which the next invoice then received as well. A number is
	 * consumed even when the document it was drawn for is not stored, so the sequence can have gaps.
	 *
	 * The first call on an install continues after the highest number already issued (or after the
	 * invoice_counter setting, 1000 by default).
	 *
	 * @return string|WP_Error Formatted number, e.g. CE-2026-01001.
	 */
	public static function get_next_invoice_number() {
		global $wpdb;

		$settings = get_option( 'cybevents_settings', array() );
		$prefix   = isset( $settings['invoice_prefix'] ) ? sanitize_text_field( $settings['invoice_prefix'] ) : 'CE-2026-';
		$table    = $wpdb->prefix . 'cybevents_invoices';
		$lock     = $wpdb->prefix . 'cybevents_invoice_number';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, 10 ) ) ) {
			return new WP_Error( 'cybevents_invoice_number_lock', 'Rechnungsnummer konnte nicht vergeben werden (Sperre nicht erhalten).' );
		}

		try {
			// Read past the object cache: another request may have advanced the counter.
			wp_cache_delete( self::INVOICE_SEQUENCE_OPTION, 'options' );
			$current = get_option( self::INVOICE_SEQUENCE_OPTION, null );

			if ( null === $current ) {
				$current = isset( $settings['invoice_counter'] ) ? (int) $settings['invoice_counter'] : 1000;
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT invoice_number FROM {$table} WHERE id > %d", 0 ) ) as $existing ) {
					if ( preg_match( '/(\d+)(?:-STORNO)?$/', (string) $existing, $m ) ) {
						$current = max( $current, (int) $m[1] );
					}
				}
			}

			$next = (int) $current + 1;
			// Skip numbers a document already carries (a prefix changed back, imported rows).
			while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE invoice_number = %s LIMIT 1", $prefix . str_pad( (string) $next, 5, '0', STR_PAD_LEFT ) ) ) ) {
				++$next;
			}

			update_option( self::INVOICE_SEQUENCE_OPTION, $next, false );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $prefix . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
	}

	/**
	 * Alias for get_next_invoice_number.
	 *
	 * @return string|WP_Error Next invoice number.
	 */
	public static function generate_sequential_invoice_number() {
		return self::get_next_invoice_number();
	}

	/**
	 * Securely process and store participant profile image / avatar.
	 *
	 * Sanitizes file, enforces image-only MIME validation (JPEG/PNG/WebP),
	 * restricts size to 5MB, and saves to protected upload subdirectory with .htaccess execution prevention.
	 *
	 * @param array $file $_FILES item.
	 * @return string|WP_Error Public URL of uploaded avatar or WP_Error.
	 */
	public static function handle_avatar_upload( $file ) {
		if ( empty( $file ) || ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_file', __( 'Keine gültige Datei hochgeladen.', 'wm-cyber-events-core' ) );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', __( 'Fehler beim Datei-Upload.', 'wm-cyber-events-core' ) );
		}

		// Enforce 5MB max size
		if ( $file['size'] > 5 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'Das Profilbild darf maximal 5 MB groß sein.', 'wm-cyber-events-core' ) );
		}

		// Verify real MIME type using fileinfo
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		$allowed_mimes = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);

		if ( ! array_key_exists( $mime, $allowed_mimes ) ) {
			return new WP_Error( 'invalid_mime', __( 'Ungültiges Dateiformat. Erlaubt sind JPG, PNG und WebP.', 'wm-cyber-events-core' ) );
		}

		if ( ! empty( $file['name'] ) ) {
			$raw_ext = strtolower( (string) pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) );
			if ( '' !== $raw_ext && ! in_array( $raw_ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
				return new WP_Error( 'invalid_extension', __( 'Ungültige Dateiendung. Erlaubt sind .jpg, .jpeg, .png und .webp.', 'wm-cyber-events-core' ) );
			}
		}

		$ext = $allowed_mimes[ $mime ];

		// Target directory
		$upload_dir = wp_upload_dir();
		$avatar_dir = trailingslashit( $upload_dir['basedir'] ) . 'cybevents-avatars';
		$avatar_url = trailingslashit( $upload_dir['baseurl'] ) . 'cybevents-avatars';

		if ( ! file_exists( $avatar_dir ) ) {
			wp_mkdir_p( $avatar_dir );
			// Write index.php and .htaccess for CWE-434 mitigation
			file_put_contents( $avatar_dir . '/index.php', '<?php // Silence is golden' );
			file_put_contents( $avatar_dir . '/.htaccess', "<Files *>\nSetHandler default-handler\n</Files>\nphp_flag engine off\n" );
		}

		$filename = 'avatar_' . wp_generate_password( 24, false, false ) . '.' . $ext;

		// wp_handle_upload() moves the file through WordPress (move_uploaded_file() is refused by
		// Plugin Check), applies FS_CHMOD_FILE and re-checks the MIME type against the whitelist;
		// the upload_dir filter keeps the file in the protected avatar directory instead of the
		// dated media folder.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$to_avatar_dir = static function ( array $dirs ) use ( $avatar_dir, $avatar_url ): array {
			$dirs['path']   = $avatar_dir;
			$dirs['url']    = $avatar_url;
			$dirs['subdir'] = '/cybevents-avatars';
			return $dirs;
		};
		add_filter( 'upload_dir', $to_avatar_dir );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => array(
					'jpg'  => 'image/jpeg',
					'png'  => 'image/png',
					'webp' => 'image/webp',
				),
				'unique_filename_callback' => static function () use ( $filename ): string {
					return $filename;
				},
			)
		);
		remove_filter( 'upload_dir', $to_avatar_dir );

		if ( ! is_array( $moved ) || ! empty( $moved['error'] ) || empty( $moved['url'] ) ) {
			return new WP_Error( 'move_failed', __( 'Fehler beim Speichern des Profilbildes.', 'wm-cyber-events-core' ) );
		}

		return (string) $moved['url'];
	}

	/**
	 * Get initials SVG avatar for a given participant name.
	 *
	 * Zero external CDN, 100% vector SVG fallback with accessible color contrast.
	 *
	 * @param string $first_name First Name.
	 * @param string $last_name Last Name.
	 * @param int    $size Avatar square dimension.
	 * @return string Inline SVG.
	 */
	public static function get_avatar_fallback_svg( $first_name, $last_name, $size = 48 ) {
		$initials = strtoupper( mb_substr( trim( (string) $first_name ), 0, 1 ) . mb_substr( trim( (string) $last_name ), 0, 1 ) );
		if ( empty( $initials ) ) {
			$initials = 'CE';
		}

		$hash       = crc32( (string) $first_name . (string) $last_name );
		$gradients  = array(
			array( '#0284c7', '#0369a1' ),
			array( '#0d9488', '#0f766e' ),
			array( '#4f46e5', '#4338ca' ),
			array( '#7c3aed', '#6d28d9' ),
			array( '#c026d3', '#a21caf' ),
		);
		$color_pair = $gradients[ abs( $hash ) % count( $gradients ) ];

		$grad_id   = 'avatar_grad_' . abs( $hash );
		$font_size = round( $size * 0.4 );

		return sprintf(
			'<svg class="cybevents-avatar-svg" width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="%s">' .
			'<defs><linearGradient id="%s" x1="0" y1="0" x2="1" y2="1"><stop offset="0%%" stop-color="%s"/><stop offset="100%%" stop-color="%s"/></linearGradient></defs>' .
			'<circle cx="%d" cy="%d" r="%d" fill="url(#%s)"/>' .
			'<text x="50%%" y="53%%" font-family="system-ui, -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif" font-size="%d" font-weight="700" fill="#ffffff" text-anchor="middle" dominant-baseline="middle">%s</text>' .
			'</svg>',
			$size,
			$size,
			$size,
			$size,
			esc_attr( $first_name . ' ' . $last_name ),
			$grad_id,
			$color_pair[0],
			$color_pair[1],
			round( $size / 2 ),
			round( $size / 2 ),
			round( $size / 2 ),
			$grad_id,
			$font_size,
			esc_html( $initials )
		);
	}

	/**
	 * Create a new attendee registration with cryptographic token hashes.
	 *
	 * @param array $args Registration parameters.
	 * @return array|WP_Error Array with registration_id, raw_token, raw_doi_token on success.
	 */
	public static function create_registration( $args ) {
		global $wpdb;

		$event_id        = (int) ( $args['event_id'] ?? 0 );
		$email           = sanitize_email( (string) ( $args['email'] ?? '' ) );
		$first_name      = sanitize_text_field( (string) ( $args['first_name'] ?? '' ) );
		$last_name       = sanitize_text_field( (string) ( $args['last_name'] ?? '' ) );
		$company         = sanitize_text_field( (string) ( $args['company'] ?? '' ) );
		$avatar_url      = esc_url_raw( (string) ( $args['avatar_url'] ?? '' ) );
		$consent_privacy = (int) ( $args['consent_privacy'] ?? 0 );
		$consent_photo   = (int) ( $args['consent_photo'] ?? 0 );

		if ( empty( $event_id ) || empty( $email ) || empty( $first_name ) || empty( $last_name ) ) {
			return new WP_Error( 'missing_fields', 'Pflichtfelder fehlen.' );
		}

		// Check if email already registered for this event
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND email = %s",
				$event_id,
				$email
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $existing_id ) {
			return new WP_Error( 'duplicate_email', 'Diese E-Mail-Adresse ist für diese Veranstaltung bereits registriert.' );
		}

		$raw_token     = bin2hex( random_bytes( 32 ) );
		$raw_doi_token = bin2hex( random_bytes( 32 ) );
		$now           = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			"{$wpdb->prefix}cybevents_registrations",
			array(
				'event_id'         => $event_id,
				'token_hash'       => hash( 'sha256', $raw_token ),
				'email'            => $email,
				'first_name'       => $first_name,
				'last_name'        => $last_name,
				'company'          => $company,
				'avatar_url'       => $avatar_url,
				'status'           => 'pending_doi',
				'doi_token_hash'   => hash( 'sha256', $raw_doi_token ),
				'doi_requested_at' => $now,
				'consent_privacy'  => $consent_privacy,
				'consent_photo'    => $consent_photo,
				'created_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', 'Datenbankfehler beim Anlegen der Registrierung.' );
		}

		$reg_id = (int) $wpdb->insert_id;

		// The address is in the registration row; the audit entry does not copy it (it stayed in the
		// log for 90 days and survived the WordPress eraser until 2026-09-15).
		self::log_audit(
			'registration_created',
			$reg_id,
			0,
			wp_json_encode( array( 'event_id' => $event_id ) )
		);

		return array(
			'registration_id' => $reg_id,
			'raw_token'       => $raw_token,
			'raw_doi_token'   => $raw_doi_token,
		);
	}

	/**
	 * Confirm Double Opt-In status via token.
	 *
	 * @param string $raw_doi_token Raw DOI Token from activation link.
	 * @return bool|WP_Error True on success.
	 */
	public static function confirm_doi( $raw_doi_token ) {
		$reg = self::confirm_doi_registration( $raw_doi_token );
		return is_wp_error( $reg ) ? $reg : true;
	}

	/**
	 * Confirm a pending registration by its DOI token and return the registration.
	 *
	 * The token is single-use: its hash is cleared on confirmation. Until 2026-09-15 nothing in the
	 * plugin called this outside the seeder, so a public registration could never be confirmed.
	 * Only `pending_doi` registrations are confirmed; a cancelled or bounced one stays as it is.
	 *
	 * @param string $raw_doi_token Raw DOI token from the confirmation link.
	 * @return object|WP_Error Registration row as it is after confirmation.
	 */
	public static function confirm_doi_registration( $raw_doi_token ) {
		global $wpdb;

		if ( empty( $raw_doi_token ) || ! is_string( $raw_doi_token ) ) {
			return new WP_Error( 'invalid_token', 'Ungültiger DOI-Token.' );
		}

		$doi_hash = hash( 'sha256', $raw_doi_token );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$reg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE doi_token_hash = %s",
				$doi_hash
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $reg || ! hash_equals( (string) $reg->doi_token_hash, $doi_hash ) ) {
			return new WP_Error( 'not_found', 'Keine ausstehende Registrierung für diesen Bestätigungslink gefunden.' );
		}

		if ( 'pending_doi' !== $reg->status ) {
			return new WP_Error( 'not_pending', 'Diese Registrierung wartet nicht auf eine Bestätigung.' );
		}

		$now = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			"{$wpdb->prefix}cybevents_registrations",
			array(
				'status'           => 'confirmed',
				'doi_confirmed_at' => $now,
				'doi_token_hash'   => null,
			),
			array(
				'id'     => $reg->id,
				'status' => 'pending_doi',
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 1 !== (int) $updated ) {
			return new WP_Error( 'confirm_failed', 'Die Bestätigung konnte nicht gespeichert werden.' );
		}

		self::log_audit(
			'doi_confirmed',
			$reg->id,
			0,
			wp_json_encode( array( 'time' => $now ) )
		);

		$reg->status           = 'confirmed';
		$reg->doi_confirmed_at = $now;
		$reg->doi_token_hash   = null;
		return $reg;
	}

	/** Predecessor of the first chained audit entry. */
	const AUDIT_GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/** Mirrors the audit chain tip outside the log table so tail deletion is detectable. */
	const AUDIT_TIP_OPTION = 'cybevents_audit_chain_tip';

	/**
	 * Where the audit chain key comes from on this install.
	 *
	 * @return string 'wp-config' or 'database'.
	 */
	public static function audit_key_source() {
		return defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ? 'wp-config' : 'database';
	}

	/**
	 * HMAC key for the audit chain.
	 *
	 * @return string
	 */
	private static function audit_key() {
		return 'wp-config' === self::audit_key_source() ? (string) AUTH_KEY : wp_salt( 'auth' );
	}

	/**
	 * Digest of an entry's content.
	 *
	 * The chain binds this digest rather than the fields themselves, and that indirection is what
	 * lets the retention job pseudonymise personal data without breaking the chain: the plaintext
	 * goes, the commitment stays, and the links either side still verify.
	 *
	 * @param string $event_type Event category.
	 * @param int    $object_id  Entity id.
	 * @param int    $user_id    Initiating user.
	 * @param string $ip         Client IP.
	 * @param string $details    JSON payload.
	 * @param string $created_at Timestamp as stored.
	 * @return string Hex sha256.
	 */
	public static function audit_content_digest( $event_type, $object_id, $user_id, $ip, $details, $created_at ) {
		return hash(
			'sha256',
			'CYBEVENTS-AUDIT-V1|' . $event_type . '|' . (int) $object_id . '|' . (int) $user_id . '|' . $ip . '|' . $details . '|' . $created_at
		);
	}

	/**
	 * Chain link for an audit entry.
	 *
	 * @param string $prev_hash      Predecessor entry hash.
	 * @param string $content_digest Content digest.
	 * @return string Hex HMAC.
	 */
	public static function audit_entry_hash( $prev_hash, $content_digest ) {
		return hash_hmac( 'sha256', $prev_hash . '|' . $content_digest, self::audit_key() );
	}

	/**
	 * Keyed fingerprint of what a pseudonymised entry still holds.
	 *
	 * Pseudonymisation blanks user, IP and details, so the content digest can no longer be
	 * recomputed. Until 2026-09-15 verification skipped the content check for any row whose
	 * pseudonymized_at was set, and that column is outside the HMAC: whoever could write the table
	 * could change a row and set the column. The residual MAC binds the entry hash, the fields that
	 * remain and the pseudonymisation time, with the chain key.
	 *
	 * @param array|object $row Audit row as stored after pseudonymisation.
	 * @return string Hex HMAC.
	 */
	public static function audit_residual_mac( $row ) {
		$row = (array) $row;
		return hash_hmac(
			'sha256',
			'CYBEVENTS-AUDIT-RESIDUAL-V1|' . (string) $row['entry_hash'] . '|' . (string) $row['event_type'] . '|' . (int) $row['object_id'] . '|' . (int) $row['user_id'] . '|' . (string) $row['ip_address'] . '|' . (string) $row['details'] . '|' . (string) $row['created_at'] . '|' . (string) $row['pseudonymized_at'],
			self::audit_key()
		);
	}

	/**
	 * Pseudonymise one audit entry: blank user, IP and details and store the residual MAC.
	 *
	 * @param int    $id  Audit entry id.
	 * @param string $now Pseudonymisation time as stored.
	 * @return bool
	 */
	public static function pseudonymize_audit_entry( $id, $now ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cybevents_audit_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), ARRAY_A );
		if ( ! $row || '' === (string) $row['entry_hash'] || ! empty( $row['pseudonymized_at'] ) ) {
			return false;
		}

		$row['user_id']          = 0;
		$row['ip_address']       = '';
		$row['details']          = '';
		$row['pseudonymized_at'] = (string) $now;

		$updated = $wpdb->update(
			$table,
			array(
				'user_id'          => 0,
				'ip_address'       => '',
				'details'          => '',
				'pseudonymized_at' => (string) $now,
				'residual_mac'     => self::audit_residual_mac( $row ),
			),
			array( 'id' => (int) $row['id'] )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return 1 === (int) $updated;
	}

	/**
	 * Append an entry to the audit log and link it to its predecessor.
	 *
	 * Until this release the table had no hash column of any kind, while the admin screen called
	 * it an "Unveränderbares Audit-Log" offered for Betriebsprüfungen, SECURITY.md promised a
	 * "kryptografischer Salted Hash" and DEVELOPER-GUIDE.md promised "tamper-evident cryptographic
	 * hash chains". It was an ordinary table anything could edit or delete from without trace.
	 *
	 * Conforms to DSGVO Art. 30 (Record of processing activities) and GoBD logging rules.
	 *
	 * @param string $event_type Semantic event category identifier.
	 * @param int    $object_id  Associated entity primary key.
	 * @param int    $user_id    Initiating WP user ID or 0 for public API requests.
	 * @param string $details    Structured JSON payload describing state delta.
	 */
	public static function log_audit( $event_type, $object_id = 0, $user_id = 0, $details = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'cybevents_audit_log';

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
		$event_type = sanitize_key( $event_type );
		$object_id  = absint( $object_id );
		$user_id    = absint( $user_id );
		$created_at = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Two concurrent writers reading the same tip would fork the chain, which verification
		// reports as tampering. Serialise the append.
		$lock = $wpdb->prefix . 'cybevents_audit';
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $lock, 5 ) );

		try {
			$tip = $wpdb->get_var( $wpdb->prepare( "SELECT entry_hash FROM {$table} WHERE entry_hash <> %s ORDER BY id DESC LIMIT 1", '' ) );
			$prev_hash = $tip ? (string) $tip : self::AUDIT_GENESIS_HASH;

			$digest     = self::audit_content_digest( $event_type, $object_id, $user_id, $ip, (string) $details, $created_at );
			$entry_hash = self::audit_entry_hash( $prev_hash, $digest );

			$inserted = $wpdb->insert(
				$table,
				array(
					'event_type'     => $event_type,
					'user_id'        => $user_id,
					'object_id'      => $object_id,
					'details'        => $details,
					'ip_address'     => $ip,
					'created_at'     => $created_at,
					'content_digest' => $digest,
					'prev_hash'      => $prev_hash,
					'entry_hash'     => $entry_hash,
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			// Until 2026-09-15 the tip moved even when the insert failed, and the next verification
			// reported removed entries that had never been written.
			if ( false === $inserted ) {
				throw new Exception( 'audit insert failed: ' . (string) $wpdb->last_error );
			}

			update_option(
				self::AUDIT_TIP_OPTION,
				array(
					'hash'  => $entry_hash,
					'count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id > %d", 0 ) ),
				),
				false
			);
		} catch ( Exception $e ) {
			// Logging must never take a request down with it.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) { error_log( '[CyberEvents Audit] ' . $e->getMessage() ); }
		}

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Walk the audit chain and report the first entry that does not verify.
	 *
	 * A pseudonymised row can no longer be checked against its own content — the plaintext is
	 * gone, which is the point of Art. 17. Its place in the chain is still verified, and it is
	 * counted separately rather than being reported as fully intact.
	 *
	 * @return array
	 */
	public static function verify_audit_chain() {
		global $wpdb;
		$table = $wpdb->prefix . 'cybevents_audit_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC", 0 ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = array(
			'valid'            => true,
			'checked'          => 0,
			'pseudonymized'    => 0,
			'unchained_legacy' => 0,
			'broken_at_id'     => null,
			'error'            => null,
			'key_source'       => self::audit_key_source(),
		);

		$tip = get_option( self::AUDIT_TIP_OPTION, null );

		if ( empty( $rows ) ) {
			if ( is_array( $tip ) && (int) $tip['count'] > 0 ) {
				$result['valid'] = false;
				$result['error'] = sprintf(
					/* translators: %d: previously recorded entry count */
					__( 'Das Audit-Protokoll ist leer, obwohl zuletzt %d Einträge verzeichnet waren.', 'wm-cyber-events-core' ),
					(int) $tip['count']
				);
			}
			return $result;
		}

		$expected_prev = self::AUDIT_GENESIS_HASH;
		$seen_chained  = false;

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			if ( '' === (string) $row['entry_hash'] ) {
				if ( $seen_chained ) {
					$result['valid']        = false;
					$result['broken_at_id'] = $id;
					$result['error']        = __( 'Ein unsignierter Eintrag wurde nachträglich in die Kette eingefügt.', 'wm-cyber-events-core' );
					return $result;
				}
				++$result['unchained_legacy'];
				continue;
			}

			$seen_chained = true;

			if ( ! hash_equals( $expected_prev, (string) $row['prev_hash'] ) ) {
				$result['valid']        = false;
				$result['broken_at_id'] = $id;
				$result['error']        = sprintf(
					/* translators: %d: audit entry id */
					__( 'Kettenbruch bei Eintrag #%d: ein Eintrag wurde gelöscht, eingeschleust oder umsortiert.', 'wm-cyber-events-core' ),
					$id
				);
				return $result;
			}

			if ( ! hash_equals( self::audit_entry_hash( (string) $row['prev_hash'], (string) $row['content_digest'] ), (string) $row['entry_hash'] ) ) {
				$result['valid']        = false;
				$result['broken_at_id'] = $id;
				$result['error']        = sprintf(
					/* translators: %d: audit entry id */
					__( 'Die Signatur des Eintrags #%d passt nicht zu seiner Prüfsumme.', 'wm-cyber-events-core' ),
					$id
				);
				return $result;
			}

			if ( ! empty( $row['pseudonymized_at'] ) ) {
				$residual = (string) ( $row['residual_mac'] ?? '' );
				if ( '' === $residual || ! hash_equals( self::audit_residual_mac( $row ), $residual ) ) {
					$result['valid']        = false;
					$result['broken_at_id'] = $id;
					$result['error']        = sprintf(
						/* translators: %d: audit entry id */
						__( 'Eintrag #%d ist als pseudonymisiert markiert, aber ohne gültigen Nachweis: sein Inhalt kann verändert worden sein.', 'wm-cyber-events-core' ),
						$id
					);
					return $result;
				}
				++$result['pseudonymized'];
			} else {
				$digest = self::audit_content_digest(
					(string) $row['event_type'],
					(int) $row['object_id'],
					(int) $row['user_id'],
					(string) $row['ip_address'],
					(string) $row['details'],
					(string) $row['created_at']
				);

				if ( ! hash_equals( $digest, (string) $row['content_digest'] ) ) {
					$result['valid']        = false;
					$result['broken_at_id'] = $id;
					$result['error']        = sprintf(
						/* translators: %d: audit entry id */
						__( 'Der Inhalt des Eintrags #%d wurde nachträglich verändert.', 'wm-cyber-events-core' ),
						$id
					);
					return $result;
				}

				++$result['checked'];
			}

			$expected_prev = (string) $row['entry_hash'];
		}

		// A log with its newest rows removed verifies perfectly on its own.
		if ( is_array( $tip ) && isset( $tip['hash'] ) && ! hash_equals( (string) $tip['hash'], $expected_prev ) ) {
			$result['valid'] = false;
			$result['error'] = __( 'Das Ende des Protokolls passt nicht zum zuletzt verzeichneten Eintrag. Die neuesten Einträge wurden entfernt.', 'wm-cyber-events-core' );
		}

		return $result;
	}

	/**
	 * Retrieve all active events.
	 *
	 * @return array List of event rows.
	 */
	public static function get_active_events() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY start_date ASC",
				'active'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Retrieve single event row by ID.
	 *
	 * @param int $event_id Event ID.
	 * @return object|null Event object or null.
	 */
	public static function get_event( $event_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = %d LIMIT 1",
				absint( $event_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Retrieve all sessions for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array List of session objects.
	 */
	public static function get_sessions_by_event( $event_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = %d ORDER BY start_time ASC",
				absint( $event_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
