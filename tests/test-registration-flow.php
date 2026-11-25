<?php
/**
 * Integration test of the public registration flow inside WordPress (real MySQL, real REST routing).
 *
 * Run: wp eval-file wp-content/plugins/wm-cyber-events-core/tests/test-registration-flow.php --allow-root
 *
 * Creates a temporary event with two sessions, registers through the REST route, confirms the double
 * opt-in, lists and books sessions, reads the ticket, checks in as an administrator, exports and then
 * erases the participant through the privacy tools the plugin registers, and deletes the temporary
 * event, sessions and queue jobs. Audit entries written by the run stay in the log (a chain cannot
 * lose rows); one of them is pseudonymised to check the residual MAC against MySQL. Settings are not
 * changed. Exits 1 when a check fails.
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
$counts = static function () use ( $wpdb, $p ): array {
	$out = array();
	foreach ( array( 'events', 'sessions', 'registrations', 'registration_sessions', 'queue', 'invoices' ) as $table ) {
		$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cybevents_{$table}" ); // phpcs:ignore WordPress.DB
	}
	return $out;
};
$rest = static function ( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};

$before        = $counts();
$health_before = ( new CyberEvents_Spoke_Adapter() )->get_health_status();
$queue_max_id  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$p}cybevents_queue" ); // phpcs:ignore WordPress.DB
$email         = 'integration-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.org';

// ------------------------------------------------------------
// Fixtures
// ------------------------------------------------------------
$wpdb->insert( "{$p}cybevents_events", array( 'title' => 'Integrationstest ' . gmdate( 'Y-m-d H:i:s' ), 'slug' => 'integrationstest-' . time(), 'start_date' => '2030-01-15 09:00:00', 'end_date' => '2030-01-15 17:00:00', 'location' => 'Testraum 1', 'max_capacity' => 10, 'status' => 'active' ) );
$event_id = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}cybevents_sessions", array( 'event_id' => $event_id, 'title' => 'Integrationstest Session A', 'start_time' => '2030-01-15 10:00:00', 'end_time' => '2030-01-15 11:00:00', 'kapazitaet' => 5, 'belegt' => 0 ) );
$session_a = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}cybevents_sessions", array( 'event_id' => $event_id, 'title' => 'Integrationstest Session B', 'start_time' => '2030-01-15 11:00:00', 'end_time' => '2030-01-15 12:00:00', 'kapazitaet' => 1, 'belegt' => 0 ) );
$session_b = (int) $wpdb->insert_id;
$taken = static fn( int $id ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT belegt FROM {$p}cybevents_sessions WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB

try {
	// ------------------------------------------------------------
	// Cron wiring
	// ------------------------------------------------------------
	$schedules = wp_get_schedules();
	$check( 'every_minute is a registered recurrence and the queue event is scheduled on it', isset( $schedules['every_minute'] ) && 'every_minute' === wp_get_schedule( 'cybevents_process_queue_cron' ), (string) wp_get_schedule( 'cybevents_process_queue_cron' ) );
	$check( 'the queue event has a handler', false !== has_action( 'cybevents_process_queue_cron' ) );

	// ------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------
	$register = $rest( 'POST', '/cybevents/v1/register', array( 'event_id' => $event_id, 'email' => $email, 'first_name' => 'Integration', 'last_name' => 'Test', 'company' => 'Test GmbH', 'consent_privacy' => 1 ) );
	$reg      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cybevents_registrations WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB
	$check( 'POST /cybevents/v1/register answers 200 and stores a pending registration', 200 === $register->get_status() && $reg && 'pending_doi' === $reg->status, $register->get_status() . ' ' . wp_json_encode( $register->get_data() ) );

	$job     = $reg ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cybevents_queue WHERE id > %d AND payload LIKE %s ORDER BY id ASC LIMIT 1", $queue_max_id, '%' . $wpdb->esc_like( $email ) . '%' ) ) : null; // phpcs:ignore WordPress.DB
	$payload = $job ? (array) json_decode( (string) $job->payload, true ) : array();
	// The cron may already have run the job; without an API key it stays pending with its payload.
	wp_parse_str( (string) wp_parse_url( (string) ( $payload['redirection_url'] ?? '' ), PHP_URL_QUERY ), $link );
	$check(
		'the queued brevo_doi job links to /confirm with the tokens of this registration',
		$job && 'brevo_doi' === $job->job_type && 0 === strpos( (string) $payload['redirection_url'], rest_url( 'cybevents/v1/confirm' ) )
			&& hash_equals( (string) $reg->doi_token_hash, hash( 'sha256', (string) ( $link['doi'] ?? '' ) ) ) && hash_equals( (string) $reg->token_hash, hash( 'sha256', (string) ( $link['token'] ?? '' ) ) ),
		wp_json_encode( $job )
	);
	$audit_with_email = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cybevents_audit_log WHERE details LIKE %s", '%' . $wpdb->esc_like( $email ) . '%' ) ); // phpcs:ignore WordPress.DB
	$check( 'no audit entry contains the address', 0 === $audit_with_email, (string) $audit_with_email );

	// ------------------------------------------------------------
	// Before confirmation
	// ------------------------------------------------------------
	$early = $rest( 'GET', '/cybevents/v1/session-selection', array( 'token' => (string) ( $link['token'] ?? '' ) ) );
	$check( 'session selection is refused before confirmation (403)', 403 === $early->get_status(), (string) $early->get_status() );

	// ------------------------------------------------------------
	// Confirmation
	// ------------------------------------------------------------
	$target = CyberEvents_REST_API::confirmation_target( (string) ( $link['doi'] ?? '' ), (string) ( $link['token'] ?? '' ) );
	wp_parse_str( (string) wp_parse_url( $target, PHP_URL_QUERY ), $target_args );
	$reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cybevents_registrations WHERE id = %d", $reg->id ) ); // phpcs:ignore WordPress.DB
	$check( 'the confirmation link confirms, clears the DOI hash and passes the participant token on', 'confirmed' === ( $target_args['cybevents_doi'] ?? '' ) && ( $target_args['token'] ?? '' ) === $link['token'] && 'confirmed' === $reg->status && null === $reg->doi_token_hash, $target );
	$again = CyberEvents_REST_API::confirmation_target( (string) $link['doi'], (string) $link['token'] );
	$check( 'the same link a second time is invalid', false !== strpos( $again, 'cybevents_doi=invalid' ), $again );

	// ------------------------------------------------------------
	// Session selection and booking
	// ------------------------------------------------------------
	$selection = $rest( 'GET', '/cybevents/v1/session-selection', array( 'token' => $link['token'] ) );
	$data      = $selection->get_data();
	$sessions  = array_column( array_map( static fn( $s ) => (array) $s, (array) ( $data['sessions'] ?? array() ) ), 'verfuegbar', 'id' );
	$check( 'session selection returns participant names, booked_sessions and verfuegbar per session', 200 === $selection->get_status() && true === ( $data['success'] ?? null ) && 'Integration' === ( $data['participant']['first_name'] ?? '' ) && array() === ( $data['booked_sessions'] ?? null ) && 5 === (int) ( $sessions[ $session_a ] ?? -1 ), wp_json_encode( $data ) );

	$book = $rest( 'POST', '/cybevents/v1/book-sessions', array( 'token' => $link['token'], 'session_ids' => array( $session_a, $session_b ) ) );
	$check( 'booking two sessions takes one seat in each', 200 === $book->get_status() && 1 === $taken( $session_a ) && 1 === $taken( $session_b ), $book->get_status() . ' ' . wp_json_encode( $book->get_data() ) );

	$swap = $rest( 'POST', '/cybevents/v1/book-sessions', array( 'token' => $link['token'], 'session_ids' => array( $session_a ) ) );
	$check( 'deselecting a session releases its seat', 200 === $swap->get_status() && 1 === $taken( $session_a ) && 0 === $taken( $session_b ), $swap->get_status() );

	// ------------------------------------------------------------
	// Ticket and check-in
	// ------------------------------------------------------------
	$ticket  = $rest( 'GET', '/cybevents/v1/ticket', array( 'token' => $link['token'] ) );
	$headers = $ticket->get_headers();
	$check( 'the ticket is private, no-store and shows the event location', 200 === $ticket->get_status() && 'private, no-store, max-age=0' === ( $headers['Cache-Control'] ?? '' ) && 'Testraum 1' === ( $ticket->get_data()['event_location'] ?? '' ), wp_json_encode( $headers ) );

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
	$first  = $rest( 'POST', '/cybevents/v1/checkin', array( 'token' => $link['token'] ) );
	$second = $rest( 'POST', '/cybevents/v1/checkin', array( 'token' => $link['token'] ) );
	wp_set_current_user( 0 );
	$check( 'check-in answers 200 once and 409 on the second scan', 200 === $first->get_status() && 409 === $second->get_status(), $first->get_status() . '/' . $second->get_status() );

	// ------------------------------------------------------------
	// Audit pseudonymisation against MySQL
	// ------------------------------------------------------------
	$audit_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}cybevents_audit_log WHERE event_type = 'registration_created' AND object_id = %d ORDER BY id DESC LIMIT 1", $reg->id ) ); // phpcs:ignore WordPress.DB
	$pseudo   = CyberEvents_Database::pseudonymize_audit_entry( $audit_id, current_time( 'mysql' ) );
	$chain    = CyberEvents_Database::verify_audit_chain();
	$check( 'a pseudonymised entry written to MySQL verifies through its residual MAC', $pseudo && ! empty( $chain['valid'] ) && $chain['pseudonymized'] >= 1, wp_json_encode( $chain ) );

	// ------------------------------------------------------------
	// Privacy exporter and eraser, as WordPress calls them
	// ------------------------------------------------------------
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$export    = call_user_func( $exporters['cybevents-registrations']['callback'], $email, 1 );
	$fields    = array_column( $export['data'][0]['data'] ?? array(), 'value', 'name' );
	$check( 'the exporter names the plugin event and the booked session', 0 === strpos( (string) ( $fields['Veranstaltung'] ?? '' ), 'Integrationstest ' ) && 'Integrationstest Session A' === ( $fields['Gebuchte Sessions'] ?? '' ), wp_json_encode( $fields ) );

	$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$erased  = call_user_func( $erasers['cybevents-registrations']['callback'], $email, 1 );
	$left    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cybevents_registrations WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB
	$queued  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cybevents_queue WHERE payload LIKE %s", '%' . $wpdb->esc_like( $email ) . '%' ) ); // phpcs:ignore WordPress.DB
	$check( 'the eraser deletes the registration, releases its seat and drops queued mail for the address', true === $erased['items_removed'] && 0 === $left && 0 === $taken( $session_a ) && 0 === $queued, wp_json_encode( $erased ) );
	$chain = CyberEvents_Database::verify_audit_chain();
	$check( 'the audit chain verifies after the run', ! empty( $chain['valid'] ), wp_json_encode( $chain ) );
} finally {
	// ------------------------------------------------------------
	// Cleanup: temporary event, sessions, any registration and queue job of the run
	// ------------------------------------------------------------
	$leftover = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cybevents_registrations WHERE email = %s OR event_id = %d", $email, $event_id ) ); // phpcs:ignore WordPress.DB
	foreach ( (array) $leftover as $row ) {
		CyberEvents_GDPR_Manager::delete_registration( $row );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cybevents_queue WHERE id > %d AND ( payload LIKE %s OR payload = '{}' OR payload LIKE %s )", $queue_max_id, '%' . $wpdb->esc_like( $email ) . '%', '%"registration_id":' . (int) ( $reg->id ?? 0 ) . '%' ) ); // phpcs:ignore WordPress.DB
	$wpdb->delete( "{$p}cybevents_sessions", array( 'event_id' => $event_id ) );
	$wpdb->delete( "{$p}cybevents_events", array( 'id' => $event_id ) );
}

$after = $counts();
$check( 'row counts are back to what they were before the run', $before === $after, wp_json_encode( array( 'before' => $before, 'after' => $after ) ) );
$check( 'the hub health is what it was before the run', ( new CyberEvents_Spoke_Adapter() )->get_health_status() === $health_before, $health_before );

WP_CLI::log( sprintf( 'INTEGRATION RESULTS: %d PASSED | %d FAILED', $results['passed'], $results['failed'] ) );
if ( $results['failed'] > 0 ) {
	WP_CLI::error( 'Registration flow integration test failed.' );
}
WP_CLI::success( 'Registration flow integration test passed.' );
