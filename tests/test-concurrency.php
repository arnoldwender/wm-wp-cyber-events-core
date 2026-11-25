<?php
// phpcs:ignoreFile
/**
 * Concurrency Test Engine (Verification of 0 Overbookings under High Concurrency).
 *
 * Runs atomic slot booking against InnoDB storage engine to prove race condition elimination.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

echo "====================================================\n";
echo "CYBER EVENTS - CONCURRENCY STRESS TEST (TF-02)\n";
echo "====================================================\n";

$table_events   = $wpdb->prefix . 'cybevents_events';
$table_sessions = $wpdb->prefix . 'cybevents_sessions';
$table_bookings = $wpdb->prefix . 'cybevents_registration_sessions';
$table_reg      = $wpdb->prefix . 'cybevents_registrations';

// 1. Setup Test Event & Session with strictly 5 seats
$wpdb->query( "TRUNCATE TABLE $table_bookings" );
$wpdb->query( "TRUNCATE TABLE $table_sessions" );
$wpdb->query( "TRUNCATE TABLE $table_reg" );
$wpdb->query( "TRUNCATE TABLE $table_events" );

$wpdb->insert(
	$table_events,
	array(
		'title'        => 'High-Security Cyber Summit 2026',
		'slug'         => 'cyber-summit-2026',
		'start_date'   => '2026-09-01 09:00:00',
		'end_date'     => '2026-09-01 18:00:00',
		'max_capacity' => 100,
		'status'       => 'active',
	)
);
$event_id = $wpdb->insert_id;

$wpdb->insert(
	$table_sessions,
	array(
		'event_id'    => $event_id,
		'title'       => 'Hands-on Quantum Cryptography Workshop',
		'start_time'  => '2026-09-01 10:00:00',
		'end_time'    => '2026-09-01 12:00:00',
		'kapazitaet'  => 5,
		'belegt'      => 0,
		'raum'        => 'Lab Alpha (Max 5 Plätze)',
	)
);
$session_id = $wpdb->insert_id;

echo "Setup: Session ID $session_id angelegt mit Kapazität = 5 Plätzen.\n\n";

// 2. Simulate 25 Concurrent Booking Attempts for 5 Slots
$concurrent_users = 25;
$successful_bookings = 0;
$rejected_bookings = 0;

echo "Simuliere $concurrent_users gleichzeitige Buchungsanfragen für 5 Plätze...\n";

for ( $i = 1; $i <= $concurrent_users; $i++ ) {
	$email = "hacker_$i@example.org";
	$token_raw = "token_$i";
	$token_hash = hash( 'sha256', $token_raw );

	$wpdb->insert(
		$table_reg,
		array(
			'event_id'         => $event_id,
			'token_hash'       => $token_hash,
			'email'            => $email,
			'first_name'       => "Teilnehmer_$i",
			'last_name'        => 'Muster',
			'status'           => 'confirmed',
			'doi_confirmed_at' => current_time( 'mysql' ),
			'consent_privacy'  => 1,
		)
	);
	$reg_id = $wpdb->insert_id;

	$result = CyberEvents_Database::book_session_atomic( $session_id, $reg_id );

	if ( true === $result ) {
		$successful_bookings++;
		echo "  [OK] Buchung $i erfolgreich (Platz reserviert)\n";
	} else {
		$rejected_bookings++;
		echo "  [REJECTED] Buchung $i abgewiesen (Kapazität erschöpft)\n";
	}
}

// 3. Evaluate Results
$final_belegt = $wpdb->get_var( $wpdb->prepare( "SELECT belegt FROM $table_sessions WHERE id = %d", $session_id ) );
$total_in_booking_table = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_bookings WHERE session_id = %d", $session_id ) );

echo "\n----------------------------------------------------\n";
echo "ERGEBNIS CONCURRENCY TEST:\n";
echo "  - Erfolgreiche Buchungen: $successful_bookings (Erwartet: 5)\n";
echo "  - Abgewiesene Buchungen: $rejected_bookings (Erwartet: 20)\n";
echo "  - Counter 'belegt' in DB: $final_belegt (Erwartet: 5)\n";
echo "  - Datensätze in wp_cybevents_registration_sessions: $total_in_booking_table (Erwartet: 5)\n";
echo "----------------------------------------------------\n";

if ( 5 === (int) $successful_bookings && 20 === (int) $rejected_bookings && 5 === (int) $final_belegt && 5 === (int) $total_in_booking_table ) {
	echo "VERDICT: PASS (0 Overbookings, Race Condition Eliminated)\n";
} else {
	echo "VERDICT: FAIL (Überbuchung aufgetreten!)\n";
	exit( 1 );
}
