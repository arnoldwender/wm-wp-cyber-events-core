<?php
/**
 * Integration test of invoices, credit notes, the invoice number sequence, the DATEV export and the
 * iCalendar times inside WordPress (real MySQL decimals, GET_LOCK, options and time zone).
 *
 * Run: wp eval-file wp-content/plugins/wm-cyber-events-core/tests/test-invoicing-flow.php --allow-root
 *
 * Seller settings and the site time zone are set for the run and restored afterwards, together with
 * the number sequence; the temporary event and the invoice rows of the run are deleted. The audit
 * entries the run writes (invoice_created, invoice_cancelled) stay in the log. Exits 1 when a check
 * fails.
 *
 * wp eval-file runs this file inside a method: file-level variables are not globals.
 *
 * @package CyberEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via WP-CLI.\n" );
	exit( 1 );
}

global $wpdb;
$p       = $wpdb->prefix;
$results = array( 'passed' => 0, 'failed' => 0 );
$check   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$results ): void {
	if ( $ok ) {
		++$results['passed'];
		WP_CLI::log( "[PASS] {$name}" );
	} else {
		++$results['failed'];
		WP_CLI::log( "[FAIL] {$name}: {$detail}" );
	}
};

$settings_before = get_option( 'cybevents_settings', array() );
$sequence_before = get_option( CyberEvents_Database::INVOICE_SEQUENCE_OPTION, null );
$timezone_before = get_option( 'timezone_string' );
$invoices_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cybevents_invoices" ); // phpcs:ignore WordPress.DB
$events_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cybevents_events" ); // phpcs:ignore WordPress.DB
$chain_before    = CyberEvents_Database::verify_audit_chain();
$created_ids     = array();

// Highest numeric suffix already issued, as the sequence sees it.
$highest = isset( $settings_before['invoice_counter'] ) ? (int) $settings_before['invoice_counter'] : 1000;
foreach ( (array) $wpdb->get_col( "SELECT invoice_number FROM {$p}cybevents_invoices" ) as $existing ) { // phpcs:ignore WordPress.DB
	if ( preg_match( '/(\d+)(?:-STORNO)?$/', (string) $existing, $m ) ) {
		$highest = max( $highest, (int) $m[1] );
	}
}
if ( null !== $sequence_before ) {
	$highest = max( $highest, (int) $sequence_before );
}

$wpdb->insert( "{$p}cybevents_events", array( 'title' => 'Integrationstest Rechnung', 'slug' => 'integrationstest-rechnung-' . time(), 'start_date' => '2030-07-01 09:00:00', 'end_date' => '2030-07-01 17:00:00', 'location' => 'Testraum 3', 'max_capacity' => 10, 'status' => 'active' ) );
$event_id = (int) $wpdb->insert_id;

try {
	update_option(
		'cybevents_settings',
		array_merge(
			$settings_before,
			array(
				'seller_name'       => 'Integrationstest Veranstalter GmbH',
				'seller_street'     => 'Teststraße 1',
				'seller_postcode'   => '10115',
				'seller_city'       => 'Berlin',
				'seller_country'    => 'DE',
				'seller_vat_id'     => 'DE123456789',
				'seller_tax_number' => '',
				'seller_email'      => 'rechnung@example.org',
				'seller_iban'       => 'DE89370400440532013000',
			)
		)
	);

	// ------------------------------------------------------------
	// Invoice and credit note against MySQL
	// ------------------------------------------------------------
	$invoice_id = CyberEvents_Invoicing::create_invoice( array( 'event_id' => $event_id, 'recipient_name' => 'Erika Muster', 'recipient_company' => 'Müller & Söhne AG', 'recipient_email' => 'erika@example.org', 'net_amount' => 45.50, 'tax_rate' => 19 ) );
	if ( ! is_wp_error( $invoice_id ) ) {
		$created_ids[] = (int) $invoice_id;
	}
	$invoice = is_wp_error( $invoice_id ) ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cybevents_invoices WHERE id = %d", $invoice_id ) ); // phpcs:ignore WordPress.DB
	$check( 'an invoice is stored with net 45.50, tax 8.65 and gross 54.15 in the decimal columns', $invoice && '45.50' === $invoice->net_amount && '8.65' === $invoice->tax_amount && '54.15' === $invoice->gross_amount, is_wp_error( $invoice_id ) ? $invoice_id->get_error_message() : wp_json_encode( $invoice ) );

	$expected_number = ( $settings_before['invoice_prefix'] ?? 'CE-2026-' ) . str_pad( (string) ( $highest + 1 ), 5, '0', STR_PAD_LEFT );
	$check( 'its number continues after the highest number already issued', $invoice && $expected_number === $invoice->invoice_number, ( $invoice->invoice_number ?? '' ) . ' expected ' . $expected_number );

	$dom = new DOMDocument();
	$check( 'its XML parses and names the seller from the settings and the event date as date of supply', $invoice && $dom->loadXML( (string) $invoice->zugferd_xml ) && false !== strpos( $invoice->zugferd_xml, 'Integrationstest Veranstalter GmbH' ) && false !== strpos( $invoice->zugferd_xml, '<udt:DateTimeString format="102">20300701</udt:DateTimeString>' ), substr( (string) ( $invoice->zugferd_xml ?? '' ), 0, 200 ) );

	$credit_id = $invoice ? CyberEvents_Invoicing::cancel_invoice( (int) $invoice_id, 'Integrationstest' ) : new WP_Error( 'skipped', 'no invoice' );
	if ( ! is_wp_error( $credit_id ) ) {
		$created_ids[] = (int) $credit_id;
	}
	$credit   = is_wp_error( $credit_id ) ? null : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cybevents_invoices WHERE id = %d", $credit_id ) ); // phpcs:ignore WordPress.DB
	$original = $invoice ? $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}cybevents_invoices WHERE id = %d", $invoice_id ) ) : ''; // phpcs:ignore WordPress.DB
	$check( 'the credit note takes the next number, stores -54.15, carries type 381 and the original number, and the original is cancelled', $credit && '-54.15' === $credit->gross_amount && 'cancelled' === $original && false !== strpos( $credit->zugferd_xml, '<ram:TypeCode>381</ram:TypeCode>' ) && false !== strpos( $credit->zugferd_xml, '<ram:IssuerAssignedID>' . $invoice->invoice_number . '</ram:IssuerAssignedID>' ) && ( $settings_before['invoice_prefix'] ?? 'CE-2026-' ) . str_pad( (string) ( $highest + 2 ), 5, '0', STR_PAD_LEFT ) === $credit->invoice_number, is_wp_error( $credit_id ) ? $credit_id->get_error_message() : wp_json_encode( $credit ) );

	// ------------------------------------------------------------
	// DATEV
	// ------------------------------------------------------------
	$day   = substr( (string) ( $invoice->issued_at ?? current_time( 'mysql' ) ), 0, 10 );
	$csv   = CyberEvents_DATEV_Exporter::generate_export( $day, $day );
	$lines = is_string( $csv ) ? explode( "\r\n", $csv ) : array();
	$ours  = array_values( array_filter( $lines, static fn( $line ) => $invoice && ( false !== strpos( $line, '"' . $invoice->invoice_number . '"' ) || ( $credit && false !== strpos( $line, '"' . $credit->invoice_number . '"' ) ) ) ) );
	$bytes = is_string( $csv ) ? CyberEvents_DATEV_Exporter::to_windows_1252( $csv ) : '';
	$check( 'the DATEV export lists invoice (S) and credit note (H) with 54,15 and converts to Windows-1252 in this PHP build', 2 === count( $ours ) && false !== strpos( $ours[0], '"54,15";"S"' ) && false !== strpos( $ours[1], '"54,15";"H"' ) && false !== strpos( $bytes, "M\xFCller" ) && 1 === preg_match( '/^"EXTF";700;21;"Buchungsstapel";13;\d{17};/', $lines[0] ?? '' ), wp_json_encode( $ours ) );

	// ------------------------------------------------------------
	// iCalendar in a site time zone with an offset (restored below)
	// ------------------------------------------------------------
	update_option( 'timezone_string', 'Europe/Berlin' );
	$event    = CyberEvents_Database::get_event( $event_id );
	$ics      = CyberEvents_Calendar_Exporter::generate_ics( $event );
	$expected = ( new DateTimeImmutable( '2030-07-01 09:00:00', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	$check( 'iCalendar writes 09:00 site time (' . wp_timezone_string() . ') as ' . $expected, '20300701T070000Z' === $expected && false !== strpos( $ics, 'DTSTART:' . $expected ), $ics );
} finally {
	// ------------------------------------------------------------
	// Cleanup and restore
	// ------------------------------------------------------------
	foreach ( $created_ids as $id ) {
		$wpdb->delete( "{$p}cybevents_invoices", array( 'id' => $id ) );
	}
	$wpdb->delete( "{$p}cybevents_events", array( 'id' => $event_id ) );
	update_option( 'cybevents_settings', $settings_before );
	update_option( 'timezone_string', $timezone_before );
	if ( null === $sequence_before ) {
		delete_option( CyberEvents_Database::INVOICE_SEQUENCE_OPTION );
	} else {
		update_option( CyberEvents_Database::INVOICE_SEQUENCE_OPTION, $sequence_before, false );
	}
}

$check( 'invoice and event counts, settings, number sequence and time zone are what they were before the run', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cybevents_invoices" ) === $invoices_before && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cybevents_events" ) === $events_before && get_option( 'cybevents_settings', array() ) === $settings_before && get_option( CyberEvents_Database::INVOICE_SEQUENCE_OPTION, null ) === $sequence_before && get_option( 'timezone_string' ) === $timezone_before ); // phpcs:ignore WordPress.DB
$chain = CyberEvents_Database::verify_audit_chain();
$check( 'the audit chain verifies after the run', ! empty( $chain['valid'] ) || empty( $chain_before['valid'] ), wp_json_encode( $chain ) );

WP_CLI::log( sprintf( 'INTEGRATION RESULTS: %d PASSED | %d FAILED', $results['passed'], $results['failed'] ) );
if ( $results['failed'] > 0 ) {
	WP_CLI::error( 'Invoicing integration test failed.' );
}
WP_CLI::success( 'Invoicing integration test passed.' );
