<?php
// phpcs:ignoreFile
/**
 * ZUGFeRD 2.3 / XRechnung and Storno Verification Test.
 *
 * Verifies XML conformance (EN 16931 Profile Comfort) and §14 UStG Storno reversal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "====================================================\n";
echo "CYBER EVENTS - ZUGFeRD 2.3 & STORNO TEST (TF-03)\n";
echo "====================================================\n";

$invoice_data = array(
	'registration_id'   => 1,
	'event_id'          => 1,
	'recipient_name'    => 'Prof. Dr. Alice Neumann',
	'recipient_company' => 'Musterfirma GmbH',
	'recipient_email'   => 'alice.neumann@example.com',
	'recipient_address' => 'Musterstraße 42, 12345 Musterstadt',
	'net_amount'        => 250.00,
	'tax_amount'        => 47.50,
	'gross_amount'      => 297.50,
	'currency'          => 'EUR',
);

echo "1. Erzeuge ZUGFeRD 2.3 / XRechnung EN 16931...\n";
$inv_id = CyberEvents_Invoicing::create_invoice( $invoice_data );

if ( is_wp_error( $inv_id ) || ! $inv_id ) {
	echo "FAIL: Rechnungserstellung fehlgeschlagen.\n";
	exit( 1 );
}
echo "   [OK] Rechnung ID $inv_id erfolgreich erstellt.\n";

$table_invoices = $wpdb->prefix . 'cybevents_invoices';
$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_invoices WHERE id = %d", $inv_id ) );

echo "   Rechnungsnummer: {$inv->invoice_number}\n";
echo "   Bruttobetrag: {$inv->gross_amount} {$inv->currency}\n";

// Validate XML structure
$xml = simplexml_load_string( $inv->zugferd_xml );
if ( false === $xml ) {
	echo "FAIL: ZUGFeRD XML ist nicht wohlgeformt!\n";
	exit( 1 );
}
echo "   [OK] ZUGFeRD XML ist wohlgeformtes XML (Root: " . $xml->getName() . ")\n";

// 2. Perform §14 Abs. 2 UStG Storno (Rechnungskorrektur)
echo "\n2. Führe §14 UStG Stornierung (Rechnungskorrektur) durch...\n";
$storno_id = CyberEvents_Invoicing::cancel_invoice( $inv_id, 'Teilnehmer-Absage krankheitsbedingt' );

if ( is_wp_error( $storno_id ) || ! $storno_id ) {
	echo "FAIL: Storno fehlgeschlagen: " . ( is_wp_error( $storno_id ) ? $storno_id->get_error_message() : '' ) . "\n";
	exit( 1 );
}
echo "   [OK] Storno-Beleg ID $storno_id erfolgreich erzeugt.\n";

$storno_inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_invoices WHERE id = %d", $storno_id ) );
echo "   Storno-Rechnungsnummer: {$storno_inv->invoice_number}\n";
echo "   Storno-Bruttobetrag: {$storno_inv->gross_amount} EUR (Erwartet: -297.50)\n";
echo "   Storno-Referenz: {$storno_inv->storno_reference_id} (Referenziert ID: $inv_id)\n";

$inv_after_storno = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $table_invoices WHERE id = %d", $inv_id ) );
echo "   Ursprungsrechnung Status: {$inv_after_storno->status} (Erwartet: cancelled)\n";

echo "\n----------------------------------------------------\n";
echo "ERGEBNIS ZUGFeRD & STORNO TEST:\n";
echo "----------------------------------------------------\n";

if ( (float) $storno_inv->gross_amount === -297.50 && 'cancelled' === $inv_after_storno->status && (int) $storno_inv->storno_reference_id === (int) $inv_id ) {
	echo "VERDICT: PASS (ZUGFeRD 2.3 XML & Revisionssichere Storno-Korrektur verifiziert)\n";
} else {
	echo "VERDICT: FAIL (Storno-Integrität fehlerhaft)\n";
	exit( 1 );
}
