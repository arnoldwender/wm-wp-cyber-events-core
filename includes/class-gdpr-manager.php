<?php
/**
 * GDPR (DSGVO Art. 17 / Löschkonzept) & Compliance Engine.
 *
 * Implements automated retention purges for unconfirmed registrations (14 days),
 * audit log rotation (90 days), and self-service cancellation with atomic session release.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_GDPR_Manager {

	/**
	 * Initialize GDPR Privacy Hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_privacy_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_privacy_erasers' ) );
	}

	/**
	 * Register WordPress Core Personal Data Exporter.
	 *
	 * @param array $exporters Array of registered exporters.
	 * @return array
	 */
	public static function register_privacy_exporters( $exporters ) {
		$exporters['cybevents-registrations'] = array(
			'exporter_friendly_name' => __( 'CyberEvents Teilnehmerdaten', 'wm-cyber-events-core' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register WordPress Core Personal Data Eraser (Right to be Forgotten).
	 *
	 * @param array $erasers Array of registered erasers.
	 * @return array
	 */
	public static function register_privacy_erasers( $erasers ) {
		$erasers['cybevents-registrations'] = array(
			'eraser_friendly_name' => __( 'CyberEvents Teilnehmerdaten', 'wm-cyber-events-core' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Run daily GDPR retention cleanup cron job.
	 */
	public static function run_retention_cleanup() {
		self::purge_expired_pending_doi( 14 );
		self::rotate_audit_logs( 90 );
	}

	/**
	 * Purge unconfirmed registrations older than X days (DSGVO Art. 17).
	 *
	 * @param int $days Retention threshold in days.
	 * @return int Number of deleted records.
	 */
	public static function purge_expired_pending_doi( $days = 14 ) {
		global $wpdb;

		$cutoff = current_datetime()->modify( '-' . absint( $days ) . ' days' )->format( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE status = 'pending_doi' AND created_at < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// One registration at a time, so booked seats and the avatar file go with it. Until
		// 2026-09-15 a single DELETE removed the rows and left their seats counted as taken.
		$deleted = 0;
		foreach ( (array) $expired as $reg ) {
			if ( self::delete_registration( $reg ) ) {
				++$deleted;
			}
		}

		if ( $deleted > 0 ) {
			CyberEvents_Database::log_audit(
				'gdpr_retention_purge',
				0,
				0,
				wp_json_encode( array( 'purged_count' => (int) $deleted, 'cutoff' => $cutoff ) )
			);
		}

		return (int) $deleted;
	}

	/**
	 * Pseudonymise personal fields in audit entries older than the retention window.
	 *
	 * This used to be a DELETE. Two things were wrong with that.
	 *
	 * First, README.md and ARCHITECTURE.md both state that personal traits in the audit log are
	 * *pseudonymised* after 90 days — the code removed the rows instead, so the documented
	 * Löschkonzept and the implemented one were different concepts.
	 *
	 * Second, deleting rows from the middle of a hash chain breaks it, and a retention job that
	 * breaks the audit chain on every run would leave only two options: report a permanent
	 * failure, or re-sign the chain afterwards — and re-signing is precisely the laundering
	 * primitive that makes tamper evidence worthless.
	 *
	 * The chain binds a content digest rather than the fields themselves, so the plaintext can be
	 * dropped while the commitment and both links stay intact. Verification of such a row proves
	 * its place in the chain and that something was recorded; it can no longer prove what, which
	 * is what erasure means.
	 *
	 * Invoice events are exempt: sec. 147 AO requires ten years, which Art. 17(3)(b) DSGVO
	 * preserves.
	 *
	 * @param int $days Retention window in days.
	 * @return int Number of entries pseudonymised.
	 */
	public static function rotate_audit_logs( $days = 90 ) {
		global $wpdb;

		// created_at is written in site time (current_time( 'mysql' )); until 2026-09-15 the cutoff
		// was computed in UTC, which moved the window by the site's offset.
		$cutoff = wp_date( 'Y-m-d H:i:s', current_datetime()->getTimestamp() - ( absint( $days ) * DAY_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'cybevents_audit_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE event_type NOT IN ( 'invoice_created', 'invoice_cancelled' )
				   AND pseudonymized_at IS NULL
				   AND entry_hash <> ''
				   AND created_at < %s",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Row by row: each pseudonymised entry gets a residual MAC, without which verification now
		// reports the row (see CyberEvents_Database::audit_residual_mac()).
		$now     = current_time( 'mysql' );
		$rotated = 0;
		foreach ( (array) $ids as $id ) {
			if ( CyberEvents_Database::pseudonymize_audit_entry( (int) $id, $now ) ) {
				++$rotated;
			}
		}

		return $rotated;
	}

	/**
	 * Delete one registration with its session bookings (releasing the seats) and its avatar file.
	 *
	 * Takes the row or its id. Passing an id used to read a property off an integer: two PHP warnings,
	 * no seats released, nothing deleted and `false` returned — measured 2026-09-16 while probing the
	 * waiting room on a local WordPress install. A signature that silently does nothing is worse than one that refuses.
	 *
	 * @param object|int $reg Registration row, or its id.
	 * @return bool True when the registration row was deleted.
	 */
	public static function delete_registration( $reg ) {
		global $wpdb;

		$reg = self::registration_row( $reg );
		if ( ! $reg ) {
			return false;
		}

		self::release_sessions( $reg );
		self::delete_avatar_file( (string) ( $reg->avatar_url ?? '' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( "{$wpdb->prefix}cybevents_registrations", array( 'id' => (int) $reg->id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted && (int) $deleted > 0;
	}

	/**
	 * The registration row behind whatever the caller passed: the row itself, or the row an id names.
	 *
	 * @param object|int $reg Registration row or id.
	 * @return object|null
	 */
	private static function registration_row( $reg ) {
		global $wpdb;

		if ( is_object( $reg ) ) {
			return $reg;
		}

		$id = absint( $reg );
		if ( ! $id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE id = %d", $id ) );
	}

	/**
	 * Cancel every session booking of a registration through the atomic path, so each seat is
	 * released.
	 *
	 * Takes the row or its id, for the same reason as delete_registration().
	 *
	 * @param object|int $reg Registration row, or its id.
	 */
	public static function release_sessions( $reg ) {
		global $wpdb;

		$reg = self::registration_row( $reg );
		if ( ! $reg ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$session_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT session_id FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id = %d", (int) $reg->id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( (array) $session_ids as $session_id ) {
			CyberEvents_Database::cancel_session_atomic( (int) $session_id, (int) $reg->id );
		}

		if ( ! empty( $session_ids ) && class_exists( 'CyberEvents_Seat_Map' ) ) {
			CyberEvents_Seat_Map::invalidate_cache( (int) $reg->event_id );
		}
	}

	/**
	 * Delete an avatar file, but only one inside uploads/cybevents-avatars.
	 *
	 * @param string $avatar_url Stored avatar URL.
	 */
	public static function delete_avatar_file( $avatar_url ) {
		if ( '' === $avatar_url || ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$uploads  = wp_upload_dir();
		$base_url = trailingslashit( (string) $uploads['baseurl'] ) . 'cybevents-avatars/';
		if ( 0 !== strpos( $avatar_url, $base_url ) ) {
			return;
		}
		$name = basename( (string) wp_parse_url( $avatar_url, PHP_URL_PATH ) );
		if ( ! preg_match( '/^avatar_[A-Za-z0-9]+\.(jpg|png|webp)$/', $name ) ) {
			return;
		}
		$path = trailingslashit( (string) $uploads['basedir'] ) . 'cybevents-avatars/' . $name;
		if ( is_file( $path ) && function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Self-service cancel participant registration & release session seats atomically.
	 *
	 * @param string $token_or_raw_token Raw participant token.
	 * @param string $reason Cancellation reason.
	 * @return bool|WP_Error True on success.
	 */
	public static function cancel_registration_self_service( $token_or_raw_token, $reason = 'Self-service cancellation' ) {
		global $wpdb;

		$reg = CyberEvents_Database::get_registration_by_token( $token_or_raw_token );
		if ( ! $reg ) {
			return new WP_Error( 'not_found', 'Ungültiger oder abgelaufener Teilnehmer-Link.' );
		}

		if ( 'cancelled' === $reg->status ) {
			return true; // Already cancelled
		}

		// 1. Release all booked sessions atomically
		self::release_sessions( $reg );

		// 2. Mark registration as cancelled
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			"{$wpdb->prefix}cybevents_registrations",
			array( 'status' => 'cancelled' ),
			array( 'id' => $reg->id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// 3. Storno invoice if issued
		if ( ! empty( $reg->invoice_number ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$inv_id = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_invoices WHERE invoice_number = %s", $reg->invoice_number )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $inv_id ) {
				$storno = CyberEvents_Invoicing::cancel_invoice( (int) $inv_id, $reason );
				if ( is_wp_error( $storno ) && 'already_cancelled' !== $storno->get_error_code() ) {
					CyberEvents_Database::log_audit( 'invoice_cancel_failed', (int) $inv_id, 0, wp_json_encode( array( 'error' => $storno->get_error_code() ) ) );
				}
			}
		}

		// 4. Update Brevo CRM attributes
		CyberEvents_Queue_Runner::push(
			'brevo_update_attributes',
			array(
				'email'      => $reg->email,
				'attributes' => array(
					'EVENT_STATUS' => 'CANCELLED',
					'LAST_UPDATED' => current_time( 'mysql' ),
				),
			)
		);

		CyberEvents_Database::log_audit(
			'self_service_cancelled',
			$reg->id,
			0,
			wp_json_encode( array( 'reason' => $reason ) )
		);

		return true;
	}

	/**
	 * WordPress Core Exporter Callback for CyberEvents Personal Data.
	 *
	 * Until 2026-09-15 the event title came from a join of the registration's event id against
	 * WordPress post ids, so the title of an unrelated post (a private one included) was exported
	 * as the event, and a column that does not exist was exported as a dietary preference. Consent
	 * flags, DOI and check-in times, the avatar, booked sessions and invoices were missing.
	 *
	 * @param string $email_address User email to export.
	 * @param int    $page          Pagination page number.
	 * @return array
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$export_items = array();
		$prefix       = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$registrations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, e.title AS event_title
				 FROM {$prefix}cybevents_registrations r
				 LEFT JOIN {$prefix}cybevents_events e ON r.event_id = e.id
				 WHERE r.email = %s",
				$email_address
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( (array) $registrations as $reg ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$session_titles = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT s.title FROM {$prefix}cybevents_sessions s
					 INNER JOIN {$prefix}cybevents_registration_sessions rs ON rs.session_id = s.id
					 WHERE rs.registration_id = %d ORDER BY s.start_time ASC",
					(int) $reg->id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$fields = array(
				__( 'Veranstaltung', 'wm-cyber-events-core' )                => ! empty( $reg->event_title ) ? $reg->event_title : sprintf( 'Event #%d', (int) $reg->event_id ),
				__( 'Status', 'wm-cyber-events-core' )                       => $reg->status,
				__( 'Vorname', 'wm-cyber-events-core' )                      => $reg->first_name,
				__( 'Nachname', 'wm-cyber-events-core' )                     => $reg->last_name,
				__( 'E-Mail', 'wm-cyber-events-core' )                       => $reg->email,
				__( 'Unternehmen / Organisation', 'wm-cyber-events-core' )   => $reg->company ?? '',
				__( 'Profilbild', 'wm-cyber-events-core' )                   => $reg->avatar_url ?? '',
				__( 'Einwilligung Datenschutz', 'wm-cyber-events-core' )     => (int) ( $reg->consent_privacy ?? 0 ) ? __( 'ja', 'wm-cyber-events-core' ) : __( 'nein', 'wm-cyber-events-core' ),
				__( 'Einwilligung Fotos', 'wm-cyber-events-core' )           => (int) ( $reg->consent_photo ?? 0 ) ? __( 'ja', 'wm-cyber-events-core' ) : __( 'nein', 'wm-cyber-events-core' ),
				__( 'Bestätigung angefordert', 'wm-cyber-events-core' )      => $reg->doi_requested_at ?? '',
				__( 'Bestätigt am', 'wm-cyber-events-core' )                 => $reg->doi_confirmed_at ?? '',
				__( 'Eingecheckt am', 'wm-cyber-events-core' )               => $reg->checked_in_at ?? '',
				__( 'Gebuchte Sessions', 'wm-cyber-events-core' )            => implode( ', ', array_map( 'strval', (array) $session_titles ) ),
				__( 'Rechnungsnummer', 'wm-cyber-events-core' )              => $reg->invoice_number ?? '',
				__( 'Anmeldedatum', 'wm-cyber-events-core' )                 => $reg->created_at,
			);

			$item_data = array();
			foreach ( $fields as $name => $value ) {
				$item_data[] = array(
					'name'  => $name,
					'value' => esc_html( (string) $value ),
				);
			}

			$export_items[] = array(
				'group_id'    => 'cybevents_registrations',
				'group_label' => __( 'CyberEvents Registrierungen', 'wm-cyber-events-core' ),
				'item_id'     => "cybevents-reg-{$reg->id}",
				'data'        => $item_data,
			);
		}

		// Invoices addressed to the person.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$invoices = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$prefix}cybevents_invoices WHERE recipient_email = %s ORDER BY id ASC", $email_address )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( (array) $invoices as $inv ) {
			$export_items[] = array(
				'group_id'    => 'cybevents_invoices',
				'group_label' => __( 'CyberEvents Rechnungen', 'wm-cyber-events-core' ),
				'item_id'     => "cybevents-invoice-{$inv->id}",
				'data'        => array(
					array( 'name' => __( 'Rechnungsnummer', 'wm-cyber-events-core' ), 'value' => esc_html( (string) $inv->invoice_number ) ),
					array( 'name' => __( 'Empfänger', 'wm-cyber-events-core' ), 'value' => esc_html( trim( $inv->recipient_name . ' ' . $inv->recipient_company ) ) ),
					array( 'name' => __( 'Adresse', 'wm-cyber-events-core' ), 'value' => esc_html( (string) $inv->recipient_address ) ),
					array( 'name' => __( 'Bruttobetrag', 'wm-cyber-events-core' ), 'value' => esc_html( $inv->gross_amount . ' ' . $inv->currency ) ),
					array( 'name' => __( 'Ausgestellt am', 'wm-cyber-events-core' ), 'value' => esc_html( (string) $inv->issued_at ) ),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * WordPress Core Eraser Callback for CyberEvents Personal Data (Right to be Forgotten).
	 *
	 * Until 2026-09-15 the anonymising UPDATE for invoiced registrations named a column that does
	 * not exist (dietary_requirements), so it failed for every such registration while the eraser
	 * reported the data as anonymised; seats stayed taken, and the erased address was written into
	 * the audit log. Now: seats released, avatar deleted, the registration deleted or (with an
	 * invoice) anonymised with a checked result, audit entries that name the address pseudonymised,
	 * queued mails for the address dropped. Invoices themselves are not changed.
	 *
	 * @param string $email_address User email to erase.
	 * @param int    $page          Pagination page number.
	 * @return array
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();
		$prefix         = $wpdb->prefix;
		$email_address  = (string) sanitize_email( $email_address );

		if ( '' === $email_address ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$registrations = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$prefix}cybevents_registrations WHERE email = %s", $email_address )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( (array) $registrations as $reg ) {
			if ( empty( $reg->invoice_number ) ) {
				if ( self::delete_registration( $reg ) ) {
					++$items_removed;
				} else {
					++$items_retained;
					/* translators: %d: registration id */
					$messages[] = sprintf( __( 'Registrierung #%d konnte nicht gelöscht werden.', 'wm-cyber-events-core' ), (int) $reg->id );
				}
				continue;
			}

			// A registration with an invoice keeps its row (the invoice number points to it); the
			// person's data in it is replaced.
			self::release_sessions( $reg );
			self::delete_avatar_file( (string) ( $reg->avatar_url ?? '' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				"{$prefix}cybevents_registrations",
				array(
					'first_name'     => '[DSGVO Art. 17]',
					'last_name'      => '[Gelöscht]',
					'email'          => sprintf( 'anonymized-%d@privacy.local', (int) $reg->id ),
					'company'        => '',
					'avatar_url'     => '',
					'token_hash'     => '',
					'doi_token_hash' => null,
					'status'         => 'cancelled',
				),
				array( 'id' => (int) $reg->id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( false === $updated || 0 === (int) $updated ) {
				++$items_retained;
				/* translators: %d: registration id */
				$messages[] = sprintf( __( 'Registrierung #%d konnte nicht anonymisiert werden; die Daten sind unverändert.', 'wm-cyber-events-core' ), (int) $reg->id );
				continue;
			}

			++$items_removed;
			++$items_retained;
			$messages[] = sprintf(
				/* translators: 1: registration id, 2: invoice number */
				__( 'Registrierung #%1$d wurde anonymisiert. Die Rechnung %2$s mit Name, Adresse und E-Mail bleibt unverändert gespeichert; Rechnungen löscht das Plugin nicht.', 'wm-cyber-events-core' ),
				(int) $reg->id,
				$reg->invoice_number
			);
		}

		// Audit entries written before 2026-09-15 copied the address into their details.
		$like = '%' . $wpdb->esc_like( $email_address ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$audit_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$prefix}cybevents_audit_log WHERE details LIKE %s AND pseudonymized_at IS NULL AND entry_hash <> '' AND event_type NOT IN ( 'invoice_created', 'invoice_cancelled' )",
				$like
			)
		);
		$queue_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$prefix}cybevents_queue WHERE payload LIKE %s", $like )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$now          = current_time( 'mysql' );
		$pseudonymous = 0;
		foreach ( (array) $audit_ids as $audit_id ) {
			if ( CyberEvents_Database::pseudonymize_audit_entry( (int) $audit_id, $now ) ) {
				++$pseudonymous;
			}
		}

		foreach ( (array) $queue_ids as $queue_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				"{$prefix}cybevents_queue",
				array( 'payload' => '{}', 'status' => 'failed', 'error_message' => 'Gelöscht auf Anfrage (Art. 17 DSGVO)' ),
				array( 'id' => (int) $queue_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$removed_any = $items_removed > 0 || $pseudonymous > 0 || ! empty( $queue_ids );

		if ( ! empty( $registrations ) || $removed_any ) {
			CyberEvents_Database::log_audit(
				'gdpr_data_erased',
				0,
				get_current_user_id(),
				wp_json_encode(
					array(
						'registrations'       => count( (array) $registrations ),
						'audit_pseudonymized' => $pseudonymous,
						'queue_jobs_dropped'  => count( (array) $queue_ids ),
					)
				)
			);
		}

		return array(
			'items_removed'  => $removed_any,
			'items_retained' => $items_retained > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

