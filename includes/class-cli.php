<?php
/**
 * WP-CLI Commands for WM Cyber Events Core
 *
 * @package WM_Cyber_Events_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage CyberEvents directly from WP-CLI.
 */
class CyberEvents_CLI_Command {

	/**
	 * List all events with registration and attendee statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybevents list
	 *
	 * @subcommand list
	 */
	public function list( $args, $assoc_args ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*,
				 (SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations r WHERE r.event_id = e.id AND r.status = %s) as confirmed_count
				 FROM {$wpdb->prefix}cybevents_events e
				 ORDER BY e.id DESC LIMIT %d",
				'confirmed',
				50
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $events ) ) {
			WP_CLI::warning( 'Keine Veranstaltungen gefunden.' );
			return;
		}

		$items = [];
		foreach ( $events as $event ) {
			$items[] = [
				'ID'          => $event->id,
				'Titel'       => $event->title,
				'Datum'       => $event->start_date ?: 'TBD',
				'Status'      => $event->status ?: 'draft',
				'Kapazität'   => $event->max_capacity,
				'Teilnehmer'  => $event->confirmed_count ?? 0,
				'Preis'       => number_format_i18n( (float) $event->ticket_price, 2 ) . ' €',
			];
		}

		WP_CLI\Utils\format_items( 'table', $items, [ 'ID', 'Titel', 'Datum', 'Status', 'Kapazität', 'Teilnehmer', 'Preis' ] );
	}

	/**
	 * Run background queue batch worker.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybevents queue-run
	 *
	 * @subcommand queue-run
	 */
	public function queue_run( $args, $assoc_args ) {
		WP_CLI::line( 'Starte Hintergrund-Queue-Verarbeitung...' );
		$processed = CyberEvents_Queue_Runner::process_batch();
		WP_CLI::success( "Queue-Batch erfolgreich ausgeführt. {$processed} Jobs verarbeitet." );
	}

	/**
	 * Verify the audit log hash chain and report the first entry that does not check out.
	 *
	 * Exits non-zero when the chain is broken, so it can be used as a cron or monitoring gate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybevents verify-audit
	 *
	 * @subcommand verify-audit
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function verify_audit( $args, $assoc_args ) {
		$result = CyberEvents_Database::verify_audit_chain();

		if ( 'wp-config' !== $result['key_source'] ) {
			WP_CLI::warning(
				'AUTH_KEY ist nicht definiert. Der Kettenschlüssel stammt aus der Datenbank — also von dort, ' .
				'wo ein Angreifer ohnehin schreiben würde. Setzen Sie AUTH_KEY in der wp-config.php.'
			);
		}

		if ( ! $result['valid'] ) {
			$where = null === $result['broken_at_id'] ? 'am Ende des Protokolls' : 'bei Eintrag #' . $result['broken_at_id'];
			WP_CLI::error( "Audit-Kette NICHT integer {$where}: " . (string) $result['error'] );
		}

		WP_CLI::success(
			"Audit-Kette integer: {$result['checked']} Einträge inhaltlich geprüft, " .
			"{$result['pseudonymized']} pseudonymisiert (nur Kettenposition prüfbar)."
		);

		if ( $result['unchained_legacy'] > 0 ) {
			WP_CLI::warning(
				"{$result['unchained_legacy']} Eintrag/Einträge stammen aus der Zeit vor der Verkettung und tragen " .
				'keine prüfbare Signatur. Sie gelten als ungeprüft, nicht als integer.'
			);
		}
	}

	/**
	 * Manage Demo Data and Factory Reset.
	 *
	 * ## OPTIONS
	 *
	 * [--import]
	 * : Import realistic enterprise demo events, sessions, and bookings.
	 *
	 * [--delete]
	 * : Delete only demo data, preserving real events and invoices.
	 *
	 * [--reset]
	 * : Hard factory reset - delete all events, registrations, and invoices.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybevents demodaten --import
	 *     wp cybevents demodaten --delete
	 *     wp cybevents demodaten --reset
	 *
	 * @subcommand demodaten
	 */
	public function demodaten( $args, $assoc_args ) {
		if ( isset( $assoc_args['import'] ) ) {
			WP_CLI::line( 'Importiere CyberEvents Demodaten...' );
			$result = CyberEvents_Seeder::seed_demo_data( true );
			$sess_count = is_array( $result['sessions'] ?? null ) ? count( $result['sessions'] ) : 0;
			$reg_count  = is_array( $result['registrations'] ?? null ) ? count( $result['registrations'] ) : 0;
			WP_CLI::success( sprintf( 'Demodaten erfolgreich importiert: Event "%s" (#%d) mit %d Sessions und %d Anmeldungen.', $result['event_title'], $result['event_id'], $sess_count, $reg_count ) );
		} elseif ( isset( $assoc_args['delete'] ) ) {
			WP_CLI::line( 'Lösche CyberEvents Demodaten...' );
			$result = CyberEvents_Seeder::delete_demo_data();
			WP_CLI::success( "Demodaten entfernt: {$result['events']} Events, {$result['sessions']} Sessions, {$result['registrations']} Anmeldungen gelöscht." );
		} elseif ( isset( $assoc_args['reset'] ) ) {
			WP_CLI::confirm( 'WARNUNG: Möchten Sie wirklich alle Events, Registrierungen und Rechnungen löschen und das Plugin auf Werkseinstellungen zurücksetzen?' );
			$result = CyberEvents_Seeder::reset_to_factory_defaults();
			WP_CLI::success( 'CyberEvents wurde vollständig auf Werkseinstellungen zurückgesetzt.' );
		} else {
			WP_CLI::error( 'Bitte geben Sie eine Option an: --import, --delete oder --reset.' );
		}
	}
	/**
	 * Report the admission waiting room, and optionally clear the current window.
	 *
	 * ## OPTIONS
	 *
	 * [--event=<id>]
	 * : Event the counters are read for; 0 (default) is the bucket shared by requests naming no event.
	 *
	 * [--reset]
	 * : Clear the counters of the current window for both gates.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybevents admission --event=12
	 *
	 * @subcommand admission
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function admission( $args, $assoc_args ) {
		$event_id = isset( $assoc_args['event'] ) ? absint( $assoc_args['event'] ) : 0;
		$status   = CyberEvents_Waiting_Room::status();

		if ( isset( $assoc_args['reset'] ) ) {
			CyberEvents_Waiting_Room::reset( CyberEvents_Waiting_Room::GATE_BOOK, $event_id );
			CyberEvents_Waiting_Room::reset( CyberEvents_Waiting_Room::GATE_REGISTER, $event_id );
			WP_CLI::success( sprintf( 'Warteraum-Zähler des laufenden Fensters für Event %d zurückgesetzt.', $event_id ) );
			return;
		}

		WP_CLI::line( '=== Warteraum (Zulassungen) ===' );
		WP_CLI::line( 'Zustand:           ' . ( $status['enabled'] ? 'an' : 'aus' ) );
		WP_CLI::line( 'Limit pro Fenster: ' . $status['limit'] . ' Anfragen je ' . $status['window_seconds'] . ' Sekunden' );
		WP_CLI::line( 'Zähler-Backend:    ' . $status['backend'] );
		WP_CLI::line( 'Event:             ' . $event_id );
		WP_CLI::line( 'Buchung, Fenster:  ' . CyberEvents_Waiting_Room::depth( CyberEvents_Waiting_Room::GATE_BOOK, $event_id ) );
		WP_CLI::line( 'Anmeldung, Fenster:' . CyberEvents_Waiting_Room::depth( CyberEvents_Waiting_Room::GATE_REGISTER, $event_id ) );

		if ( '' !== $status['database_backend_warning'] ) {
			WP_CLI::warning( $status['database_backend_warning'] );
		}
	}
}

WP_CLI::add_command( 'cybevents', 'CyberEvents_CLI_Command' );
