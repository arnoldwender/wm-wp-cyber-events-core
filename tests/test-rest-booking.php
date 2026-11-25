<?php
/**
 * REST-layer booking test.
 *
 * test-concurrency.php exercises CyberEvents_Database::book_session_atomic() directly and
 * therefore cannot see the wiring between that function and the REST handler. This test
 * drives /cybevents/v1/book-sessions through rest_do_request() instead, which is where a
 * refused booking was previously reported to the participant as HTTP 200 "erfolgreich".
 *
 * Run: wp eval-file wp-content/plugins/wm-cyber-events-core/tests/test-rest-booking.php
 *
 * @package CyberEvents
 */

global $wpdb;

$T_EVENTS   = $wpdb->prefix . 'cybevents_events';
$T_SESSIONS = $wpdb->prefix . 'cybevents_sessions';
$T_REGS     = $wpdb->prefix . 'cybevents_registrations';
$T_BOOKINGS = $wpdb->prefix . 'cybevents_registration_sessions';

// wp eval-file executes this file inside a function scope, so `global` does not bind to
// these. Counters live in $GLOBALS explicitly — matching tests/test-i18n.php.
$GLOBALS['cybevents_passed'] = 0;
$GLOBALS['cybevents_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition under test.
 * @param string $label     Human readable description.
 * @return void
 */
function cybevents_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['cybevents_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['cybevents_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

echo "\n=== REST BOOKING TEST ===\n\n";

// --- Fixture -------------------------------------------------------------
$wpdb->insert( $T_EVENTS, array( 'title' => 'REST-TEST Event', 'status' => 'active' ) );
$event_id = (int) $wpdb->insert_id;

// Session A: capacity 1, already saturated.
$wpdb->insert( $T_SESSIONS, array( 'event_id' => $event_id, 'title' => 'Ausgebuchte Session', 'kapazitaet' => 1, 'belegt' => 1 ) );
$full_session = (int) $wpdb->insert_id;

// Session B: capacity 5, empty.
$wpdb->insert( $T_SESSIONS, array( 'event_id' => $event_id, 'title' => 'Freie Session', 'kapazitaet' => 5, 'belegt' => 0 ) );
$free_session = (int) $wpdb->insert_id;

$raw_token = bin2hex( random_bytes( 32 ) );
$wpdb->insert(
	$T_REGS,
	array(
		'event_id'   => $event_id,
		'email'      => 'rest-test@example.test',
		'first_name' => 'Rest',
		'last_name'  => 'Test',
		'token_hash' => hash( 'sha256', $raw_token ),
		'status'     => 'confirmed',
	)
);
$reg_id = (int) $wpdb->insert_id;

/**
 * Drive the REST route.
 *
 * @param string $token       Raw registration token.
 * @param array  $session_ids Session IDs to book.
 * @return WP_REST_Response
 */
function cybevents_book( $token, $session_ids ) {
	$request = new WP_REST_Request( 'POST', '/cybevents/v1/book-sessions' );
	$request->set_param( 'token', $token );
	$request->set_param( 'session_ids', $session_ids );
	return rest_do_request( $request );
}

// --- Case 1: full session must be refused, loudly ------------------------
echo "Case 1 — Buchung einer ausgebuchten Session\n";
$res  = cybevents_book( $raw_token, array( $full_session ) );
$body = $res->get_data();
$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $T_BOOKINGS WHERE session_id = %d AND registration_id = %d", $full_session, $reg_id ) );

cybevents_assert( 409 === $res->get_status(), 'HTTP 409 statt 200 (Buchung wurde abgelehnt)' );
cybevents_assert( empty( $body['success'] ), 'success = false' );
cybevents_assert( 0 === $rows, 'Keine Buchungszeile angelegt' );
cybevents_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT belegt FROM $T_SESSIONS WHERE id = %d", $full_session ) ), 'belegt unverändert bei 1 (keine Überbuchung)' );
cybevents_assert( false !== strpos( (string) ( $body['message'] ?? '' ), 'Ausgebuchte Session' ), 'Fehlermeldung nennt den Session-Titel' );

// --- Case 2: available session must succeed ------------------------------
echo "\nCase 2 — Buchung einer freien Session\n";
$res  = cybevents_book( $raw_token, array( $free_session ) );
$body = $res->get_data();
$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $T_BOOKINGS WHERE session_id = %d AND registration_id = %d", $free_session, $reg_id ) );

cybevents_assert( 200 === $res->get_status(), 'HTTP 200' );
cybevents_assert( ! empty( $body['success'] ), 'success = true' );
cybevents_assert( 1 === $rows, 'Genau eine Buchungszeile angelegt' );
cybevents_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT belegt FROM $T_SESSIONS WHERE id = %d", $free_session ) ), 'belegt auf 1 erhöht' );

// --- Case 3: mixed batch must not report blanket success -----------------
echo "\nCase 3 — Gemischter Batch (frei + ausgebucht)\n";
$res  = cybevents_book( $raw_token, array( $free_session, $full_session ) );
$body = $res->get_data();

cybevents_assert( 409 === $res->get_status(), 'HTTP 409 — Teilfehler wird nicht als Erfolg gemeldet' );
cybevents_assert( ! empty( $body['errors'] ) && is_array( $body['errors'] ), 'Antwort enthält errors[]' );

// --- Case 4: deselecting releases the seat -------------------------------
echo "\nCase 4 — Abwahl gibt den Platz frei\n";
$res  = cybevents_book( $raw_token, array() );
$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $T_BOOKINGS WHERE registration_id = %d", $reg_id ) );

cybevents_assert( 200 === $res->get_status(), 'HTTP 200' );
cybevents_assert( 0 === $rows, 'Alle Buchungszeilen entfernt' );
cybevents_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT belegt FROM $T_SESSIONS WHERE id = %d", $free_session ) ), 'belegt wieder auf 0' );

// --- Teardown ------------------------------------------------------------
$wpdb->delete( $T_BOOKINGS, array( 'registration_id' => $reg_id ) );
$wpdb->delete( $T_REGS, array( 'id' => $reg_id ) );
$wpdb->delete( $T_SESSIONS, array( 'id' => $full_session ) );
$wpdb->delete( $T_SESSIONS, array( 'id' => $free_session ) );
$wpdb->delete( $T_EVENTS, array( 'id' => $event_id ) );

echo "\n----------------------------------------------------\n";
$passed = (int) $GLOBALS['cybevents_passed'];
$failed = (int) $GLOBALS['cybevents_failed'];
echo "Tests bestanden: {$passed} / " . ( $passed + $failed ) . "\n";
echo ( 0 === $failed && $passed > 0 ) ? "VERDICT: PASS\n" : "VERDICT: FAIL\n";
echo "----------------------------------------------------\n";
