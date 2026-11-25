<?php
/**
 * Participant CSV Import Engine with Deduplication.
 *
 * Implements strict deduplication per email (Req. #41, TF-01), field mapping,
 * and detailed import audit logging.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_CSV_Importer {

	/**
	 * Import participants from CSV content into an event with deduplication.
	 *
	 * @param int    $event_id Target Event ID.
	 * @param string $csv_content Raw CSV string or file path.
	 * @param bool   $dry_run If true, only validates without inserting.
	 * @return array Report containing imported, skipped, errors, and log entries.
	 */
	public static function import_csv( $event_id, $csv_content, $dry_run = false ) {
		global $wpdb;

		$event_id = absint( $event_id );
		$report   = array(
			'total'    => 0,
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => 0,
			'log'      => array(),
		);

		if ( empty( $csv_content ) || ! $event_id ) {
			$report['log'][] = 'Keine CSV-Daten oder ungültige Event-ID bereitgestellt.';
			return $report;
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $csv_content ) );
		if ( empty( $lines ) ) {
			return $report;
		}

		// Detect delimiter (, or ;)
		$first_line = $lines[0];
		$delimiter  = ( substr_count( $first_line, ';' ) >= substr_count( $first_line, ',' ) ) ? ';' : ',';

		// Enclosure and escape are passed explicitly: PHP 8.4 deprecates the implicit backslash escape,
		// and an empty escape parses fields the RFC 4180 way (a quote is escaped by doubling it).
		$header = str_getcsv( array_shift( $lines ), $delimiter, '"', '' );
		$header = array_map( 'trim', array_map( 'strtolower', $header ) );

		$email_idx = array_search( 'email', $header, true );
		if ( false === $email_idx ) {
			$email_idx = array_search( 'e-mail', $header, true );
		}

		$first_name_idx = array_search( 'vorname', $header, true );
		if ( false === $first_name_idx ) {
			$first_name_idx = array_search( 'first_name', $header, true );
		}

		$last_name_idx = array_search( 'nachname', $header, true );
		if ( false === $last_name_idx ) {
			$last_name_idx = array_search( 'last_name', $header, true );
		}

		$company_idx = array_search( 'firma', $header, true );
		if ( false === $company_idx ) {
			$company_idx = array_search( 'company', $header, true );
		}

		if ( false === $email_idx ) {
			$report['log'][] = 'Kritischer Fehler: Keine Spalte "email" oder "E-Mail" in der Kopfzeile gefunden.';
			$report['errors']++;
			return $report;
		}

		foreach ( $lines as $line_num => $line_str ) {
			$trimmed = trim( $line_str );
			if ( empty( $trimmed ) ) {
				continue;
			}

			$report['total']++;
			$row = str_getcsv( $trimmed, $delimiter, '"', '' );

			$email      = isset( $row[ $email_idx ] ) ? sanitize_email( trim( $row[ $email_idx ] ) ) : '';
			$first_name = ( false !== $first_name_idx && isset( $row[ $first_name_idx ] ) ) ? sanitize_text_field( trim( $row[ $first_name_idx ] ) ) : '';
			$last_name  = ( false !== $last_name_idx && isset( $row[ $last_name_idx ] ) ) ? sanitize_text_field( trim( $row[ $last_name_idx ] ) ) : '';
			$company    = ( false !== $company_idx && isset( $row[ $company_idx ] ) ) ? sanitize_text_field( trim( $row[ $company_idx ] ) ) : '';

			if ( ! is_email( $email ) ) {
				$report['errors']++;
				$report['log'][] = sprintf( 'Zeile %d: Ungültige E-Mail-Adresse "%s" übersprungen.', $line_num + 2, esc_html( $email ) );
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// Strict Deduplication Check (TF-01)
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND email = %s LIMIT 1",
					$event_id,
					$email
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $exists ) {
				$report['skipped']++;
				$report['log'][] = sprintf( 'Zeile %d: Dublette abgewiesen für E-Mail "%s".', $line_num + 2, esc_html( $email ) );
				continue;
			}

			if ( ! $dry_run ) {
				// Generate secure token
				$raw_token  = bin2hex( random_bytes( 32 ) );
				$token_hash = hash( 'sha256', $raw_token );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
				$inserted = $wpdb->insert(
					"{$wpdb->prefix}cybevents_registrations",
					array(
						'event_id'         => $event_id,
						'token_hash'       => $token_hash,
						'email'            => $email,
						'first_name'       => $first_name,
						'last_name'        => $last_name,
						'company'          => $company,
						// An administrator's import admits the person ('confirmed') but records no
						// consent and no double opt-in: until 2026-09-15 both were stored as given for
						// people who never saw the form.
						'status'           => 'confirmed',
						'consent_privacy'  => 0,
						'created_at'       => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

				if ( $inserted ) {
					$report['imported']++;
					$report['log'][] = sprintf( 'Zeile %d: Teilnehmer "%s %s" (%s) erfolgreich importiert.', $line_num + 2, esc_html( $first_name ), esc_html( $last_name ), esc_html( $email ) );
				} else {
					$report['errors']++;
					$report['log'][] = sprintf( 'Zeile %d: Datenbankfehler beim Speichern von "%s".', $line_num + 2, esc_html( $email ) );
				}
			} else {
				$report['imported']++;
			}
		}

		CyberEvents_Database::log_audit(
			'csv_import',
			$event_id,
			get_current_user_id(),
			wp_json_encode(
				array(
					'total'    => $report['total'],
					'imported' => $report['imported'],
					'skipped'  => $report['skipped'],
					'errors'   => $report['errors'],
				)
			)
		);

		return $report;
	}
}
