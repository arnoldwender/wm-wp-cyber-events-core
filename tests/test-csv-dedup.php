<?php
// phpcs:ignoreFile
/**
 * CSV Deduplication Test Engine (Verification of TF-01).
 *
 * Verifies that participant CSV import rejects duplicate emails and validates integrity.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "====================================================\n";
echo "CYBER EVENTS - CSV DEDUPLICATION TEST (TF-01)\n";
echo "====================================================\n";

$table_events = $wpdb->prefix . 'cybevents_events';
$table_reg    = $wpdb->prefix . 'cybevents_registrations';

$wpdb->query( "TRUNCATE TABLE $table_reg" );
$wpdb->query( "TRUNCATE TABLE $table_events" );

$wpdb->insert(
	$table_events,
	array(
		'title'      => 'Demo-Symposium 2026',
		'slug'       => 'agentur-symposium-2026',
		'start_date' => '2026-10-15 10:00:00',
		'end_date'   => '2026-10-15 17:00:00',
		'status'     => 'active',
	)
);
$event_id = $wpdb->insert_id;

// 1. Initial Batch
$csv_batch_1 = "Vorname;Nachname;E-Mail;Firma\n" .
	"Max;Mustermann;max@example.com;Musterfirma GmbH\n" .
	"Erika;Musterfrau;erika@example.com;Beispiel AG\n";

echo "1. Importiere Batch 1 (2 Teilnehmer)...\n";
$report1 = CyberEvents_CSV_Importer::import_csv( $event_id, $csv_batch_1 );
echo "   Importiert: {$report1['imported']}, Dubletten: {$report1['skipped']}, Fehler: {$report1['errors']}\n";

// 2. Batch with 2 Duplicates and 3 New Participants
$csv_batch_2 = "Vorname;Nachname;E-Mail;Firma\n" .
	"Max;Mustermann;max@example.com;Musterfirma Doppelgänger\n" . // DUPLICATE
	"Erika;Musterfrau;erika@example.com;Beispiel Doppelgänger\n" .    // DUPLICATE
	"Klaus;Schmidt;klaus@example.org;Musterverwaltung\n" .             // NEW
	"Anna;Meyer;anna@example.net;Beispiel-Institut\n" .               // NEW
	"David;Weber;david@example.org;Muster Labor\n";                    // NEW

echo "2. Importiere Batch 2 (2 Dubletten + 3 neue Teilnehmer)...\n";
$report2 = CyberEvents_CSV_Importer::import_csv( $event_id, $csv_batch_2 );
echo "   Importiert: {$report2['imported']}, Dubletten: {$report2['skipped']}, Fehler: {$report2['errors']}\n";

$total_in_db = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_reg WHERE event_id = %d", $event_id ) );

echo "\n----------------------------------------------------\n";
echo "ERGEBNIS DEDUP TEST:\n";
echo "  - Gesamtanzahl in DB: $total_in_db (Erwartet: 5 = 2 initial + 3 neu)\n";
echo "  - Abgewiesene Dubletten in Batch 2: {$report2['skipped']} (Erwartet: 2)\n";
echo "----------------------------------------------------\n";

if ( 5 === (int) $total_in_db && 2 === (int) $report2['skipped'] && 3 === (int) $report2['imported'] ) {
	echo "VERDICT: PASS (Strikte Dublettenabwehr TF-01 verifiziert)\n";
} else {
	echo "VERDICT: FAIL (Dublettenprüfung fehlgeschlagen)\n";
	exit( 1 );
}
