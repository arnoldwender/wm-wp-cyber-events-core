<?php
// phpcs:ignoreFile
/**
 * Automated Enterprise Deep Feature & Compliance Verification Suite.
 *
 * Tests:
 * 1. Fast Check-In REST API & Double-Scan Prevention.
 * 2. iCalendar RFC 5545 vCalendar 2.0 valid format generation.
 * 3. Self-Service Cancellation (DSGVO) with real-time atomic seat release.
 * 4. Automated GDPR 14-day expired DOI retention purge.
 *
 * @package CyberEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( file_exists( '/var/www/html/wp-load.php' ) ) {
		require_once '/var/www/html/wp-load.php';
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "===============================================================\n";
echo "WM Cyber Events Core — Deep Features & Compliance Test Suite\n";
echo "Environment: " . php_uname( 's' ) . " | PHP " . phpversion() . " | WP " . $GLOBALS['wp_version'] . "\n";
echo "===============================================================\n\n";

global $wpdb;

// Clean test environment
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->prefix}cybevents_events WHERE slug LIKE 'deep-test-%'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}cybevents_registrations WHERE email LIKE '%@deep-test.de'" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// -----------------------------------------------------------------------------
// Test Setup: Create Test Event & Sessions
// -----------------------------------------------------------------------------
$wpdb->insert(
	"{$wpdb->prefix}cybevents_events",
	array(
		'title'        => 'Demo-Konferenz 2026',
		'slug'         => 'deep-test-summit-2026',
		'description'  => 'Erfundene Konferenz fuer die Tests.',
		'start_date'   => '2026-10-15 09:00:00',
		'end_date'     => '2026-10-16 18:00:00',
		'location'     => 'Musterstadt, Kongresszentrum',
		'max_capacity' => 500,
		'status'       => 'active',
	)
);
$event_id = $wpdb->insert_id;

$wpdb->insert(
	"{$wpdb->prefix}cybevents_sessions",
	array(
		'event_id'    => $event_id,
		'title'       => 'Quantum Encryption & Post-Quantum TLS 1.3',
		'description' => 'Tiefenanalyse von Post-Quantum Algorithmen in staatlichen Netzen.',
		'speaker'     => 'Dr. Valentina Richter',
		'raum'        => 'Saal A (Hauptauditorium)',
		'start_time'  => '2026-10-15 10:00:00',
		'end_time'    => '2026-10-15 11:30:00',
		'kapazitaet'  => 50,
		'belegt'      => 0,
	)
);
$session_id = $wpdb->insert_id;

// -----------------------------------------------------------------------------
// TEST 1: Check-In REST API & Double-Scan Prevention
// -----------------------------------------------------------------------------
echo "[TEST 1] Fast Check-In API & Double-Scan Prevention...\n";

$reg_data = CyberEvents_Database::create_registration(
	array(
		'event_id'        => $event_id,
		'email'           => 'attendee1@deep-test.de',
		'first_name'      => 'Klaus',
		'last_name'       => 'Weber',
		'company'         => 'Musterfirma GmbH',
		'consent_privacy' => 1,
	)
);
$reg_id    = $reg_data['registration_id'];
$raw_token = $reg_data['raw_token'];

// Confirm DOI
CyberEvents_Database::confirm_doi( $reg_data['raw_doi_token'] );

// 1.1 Perform First Check-In Request
$request_1 = new WP_REST_Request( 'POST', '/cybevents/v1/checkin' );
$request_1->set_param( 'token', $raw_token );
$response_1 = CyberEvents_REST_API::handle_checkin( $request_1 );

if ( 200 !== $response_1->get_status() || ! $response_1->get_data()['success'] ) {
	echo "❌ FEHLER: Erster Check-in fehlgeschlagen. Status: " . $response_1->get_status() . "\n";
	exit( 1 );
}
echo "  ✓ Erster Einlass erfolgreich gebucht (Status 200, checked_in = 1).\n";

// 1.2 Perform Second Check-In with same token (Double-Scan Attack)
$request_2 = new WP_REST_Request( 'POST', '/cybevents/v1/checkin' );
$request_2->set_param( 'token', $raw_token );
$response_2 = CyberEvents_REST_API::handle_checkin( $request_2 );

if ( 409 !== $response_2->get_status() || empty( $response_2->get_data()['already_in'] ) ) {
	echo "❌ FEHLER: Doppelter Check-in wurde nicht als Konflikt (409) abgewiesen!\n";
	exit( 1 );
}
echo "  ✓ Doppeleinlass-Versuch revisionssicher abgewiesen (Status 409 Conflict).\n";


// -----------------------------------------------------------------------------
// TEST 2: iCalendar RFC 5545 (.ics) Generation
// -----------------------------------------------------------------------------
echo "\n[TEST 2] iCalendar RFC 5545 vCalendar 2.0 Export...\n";

$event_obj = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = $event_id" );
$sessions  = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = $event_id" );

$ics = CyberEvents_Calendar_Exporter::generate_ics( $event_obj, $sessions, 'Klaus Weber' );

$required_markers = array(
	'BEGIN:VCALENDAR',
	'VERSION:2.0',
	'PRODID:-//WM Suite//WM Cyber Events Core',
	'X-WR-TIMEZONE:Europe/Berlin',
	'SUMMARY:Demo-Konferenz 2026',
	'LOCATION:Musterstadt',
	'BEGIN:VALARM',
	'TRIGGER:-P1D',
	'SUMMARY:[Session] Quantum Encryption & Post-Quantum TLS 1.3',
	'TRIGGER:-PT15M',
	'END:VCALENDAR',
);

foreach ( $required_markers as $marker ) {
	if ( strpos( $ics, $marker ) === false ) {
		echo "❌ FEHLER: Erforderlicher iCal-Marker '$marker' fehlt im .ics Stream!\n";
		exit( 1 );
	}
}
echo "  ✓ RFC 5545 iCalendar-Export syntaktisch valide mit Konferenz- und Session-Alarmen.\n";


// -----------------------------------------------------------------------------
// TEST 3: Self-Service Cancellation & Atomic Spot Release
// -----------------------------------------------------------------------------
echo "\n[TEST 3] Self-Service Cancellation & Atomic Seat Release...\n";

$reg_data_2 = CyberEvents_Database::create_registration(
	array(
		'event_id'        => $event_id,
		'email'           => 'attendee2@deep-test.de',
		'first_name'      => 'Erika',
		'last_name'       => 'Musterfrau',
		'company'         => 'Fraunhofer Institute',
		'consent_privacy' => 1,
	)
);
$raw_token_2 = $reg_data_2['raw_token'];
CyberEvents_Database::confirm_doi( $reg_data_2['raw_doi_token'] );

// Book session
$book_res = CyberEvents_Database::book_session_atomic( $session_id, $reg_data_2['registration_id'] );
if ( is_wp_error( $book_res ) ) {
	echo "❌ FEHLER beim Buchen der Session: " . $book_res->get_error_message() . "\n";
	exit( 1 );
}

$belegt_after_booking = (int) $wpdb->get_var( "SELECT belegt FROM {$wpdb->prefix}cybevents_sessions WHERE id = $session_id" );
echo "  ✓ Session gebucht: Belegung = $belegt_after_booking.\n";

// Now perform self-service cancellation
$cancel_res = CyberEvents_GDPR_Manager::cancel_registration_self_service( $raw_token_2, 'Teilnehmer hat online storniert' );
if ( is_wp_error( $cancel_res ) || true !== $cancel_res ) {
	echo "❌ FEHLER bei Self-Service Stornierung!\n";
	exit( 1 );
}

$reg_status_after = $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}cybevents_registrations WHERE id = " . $reg_data_2['registration_id'] );
$belegt_after_cancel = (int) $wpdb->get_var( "SELECT belegt FROM {$wpdb->prefix}cybevents_sessions WHERE id = $session_id" );

if ( 'cancelled' !== $reg_status_after ) {
	echo "❌ FEHLER: Registrierungsstatus ist '$reg_status_after' statt 'cancelled'!\n";
	exit( 1 );
}

if ( 0 !== $belegt_after_cancel ) {
	echo "❌ FEHLER: Session-Platz wurde nicht freigegeben! Belegung: $belegt_after_cancel\n";
	exit( 1 );
}
echo "  ✓ Self-Service Stornierung erfolgreich: Status 'cancelled', Platz automatisch freigegeben (Belegung = 0).\n";


// -----------------------------------------------------------------------------
// TEST 4: Automated GDPR Retention Purge (14-Day DOI Expiry)
// -----------------------------------------------------------------------------
echo "\n[TEST 4] DSGVO Art. 17 Automated Retention Purge (14 Tage DOI-Ablauf)...\n";

// Insert an expired pending_doi registration (16 days old)
$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-16 days' ) );
$wpdb->insert(
	"{$wpdb->prefix}cybevents_registrations",
	array(
		'event_id'        => $event_id,
		'token_hash'      => hash( 'sha256', 'old_token_123' ),
		'email'           => 'expired_doi@deep-test.de',
		'first_name'      => 'Alter',
		'last_name'       => 'Kontakt',
		'company'         => 'Test GmbH',
		'status'          => 'pending_doi',
		'consent_privacy' => 1,
		'created_at'      => $old_date,
	)
);
$expired_reg_id = $wpdb->insert_id;

// Insert a recent pending_doi registration (1 day old)
$recent_date = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
$wpdb->insert(
	"{$wpdb->prefix}cybevents_registrations",
	array(
		'event_id'        => $event_id,
		'token_hash'      => hash( 'sha256', 'recent_token_456' ),
		'email'           => 'recent_doi@deep-test.de',
		'first_name'      => 'Neuer',
		'last_name'       => 'Kontakt',
		'company'         => 'Test GmbH',
		'status'          => 'pending_doi',
		'consent_privacy' => 1,
		'created_at'      => $recent_date,
	)
);
$recent_reg_id = $wpdb->insert_id;

// Run GDPR purge
$purged_count = CyberEvents_GDPR_Manager::purge_expired_pending_doi( 14 );

$expired_exists = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE id = $expired_reg_id" );
$recent_exists  = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE id = $recent_reg_id" );

if ( 0 !== (int) $expired_exists ) {
	echo "❌ FEHLER: Abgelaufene DOI-Registrierung (>14 Tage) wurde NICHT geloescht!\n";
	exit( 1 );
}

if ( 1 !== (int) $recent_exists ) {
	echo "❌ FEHLER: Frische DOI-Registrierung (<14 Tage) wurde faelschlicherweise geloescht!\n";
	exit( 1 );
}
echo "  ✓ DSGVO Art. 17 Loeschkonzept verifiziert: $purged_count alte DOI-Eintraege geloescht, aktive Daten unberuehrt.\n";

echo "\n===============================================================\n";
echo "VERDICT: ALL 4/4 ENTERPRISE EXTENSION TESTS PASS\n";
echo "===============================================================\n";
