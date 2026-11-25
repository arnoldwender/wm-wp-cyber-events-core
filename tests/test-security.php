<?php
/**
 * Automated Cybersecurity & Penetration Verification Suite.
 *
 * Tests:
 * 1. Webhook Signature & Secret Authentication (Spoofing Abwehr)
 * 2. Cross-Event Session Booking IDOR Prevention
 * 3. CSV Formula Injection / CWE-1236 Neutralization
 * 4. ZUGFeRD 2.3 XML Injection / Entity Escaping
 * 5. Open Redirect Defense in DOI Callback Handlers
 * 6. IP-based REST API Rate Limiting & DoS Mitigation
 *
 * Run via WP-CLI:
 * wp --allow-root eval-file wp-content/plugins/wm-cyber-events-core/tests/test-security.php
 *
 * @package CyberEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Direct script execution without WordPress context is forbidden.\n" );
}

echo "=== CYBERSECURITY AUDIT & HARDENING VERIFICATION SUITE ===\n\n";

global $wpdb;
$passed = 0;
$total  = 6;

// -------------------------------------------------------------
// TEST 1: Brevo Webhook Authentication & Spoofing Defense
// -------------------------------------------------------------
echo "[TEST 1/6] Brevo Webhook Secret / Signature Verification...\n";

// Set secret in options
$secret = 'sec_token_test_' . bin2hex( random_bytes( 8 ) );
$settings = get_option( 'cybevents_settings', array() );
$settings['brevo_webhook_secret'] = $secret;
update_option( 'cybevents_settings', $settings );

// 1a. Request without secret
$req_no_auth = new WP_REST_Request( 'POST', '/cybevents/v1/brevo-webhook' );
$res_no_auth = CyberEvents_REST_API::validate_brevo_webhook_permission( $req_no_auth );
$blocked_no_auth = is_wp_error( $res_no_auth ) && 401 === $res_no_auth->get_error_data()['status'];

// 1b. Request with invalid secret
$req_invalid = new WP_REST_Request( 'POST', '/cybevents/v1/brevo-webhook' );
$req_invalid->set_header( 'x-brevo-webhook-secret', 'attacker_wrong_token' );
$res_invalid = CyberEvents_REST_API::validate_brevo_webhook_permission( $req_invalid );
$blocked_invalid = is_wp_error( $res_invalid ) && 401 === $res_invalid->get_error_data()['status'];

// 1c. Request with valid secret
$req_valid = new WP_REST_Request( 'POST', '/cybevents/v1/brevo-webhook' );
$req_valid->set_header( 'x-brevo-webhook-secret', $secret );
$res_valid = CyberEvents_REST_API::validate_brevo_webhook_permission( $req_valid );
$allowed_valid = true === $res_valid;

// 1d. No secret configured at all: the route must fail CLOSED, not open (fleet audit 2026-09-13 §6.2 #5).
$settings['brevo_webhook_secret'] = '';
update_option( 'cybevents_settings', $settings );
$req_unconfigured = new WP_REST_Request( 'POST', '/cybevents/v1/brevo-webhook' );
$res_unconfigured = CyberEvents_REST_API::validate_brevo_webhook_permission( $req_unconfigured );
$blocked_unconfigured = is_wp_error( $res_unconfigured ) && 401 === $res_unconfigured->get_error_data()['status'];
$settings['brevo_webhook_secret'] = $secret;
update_option( 'cybevents_settings', $settings );

if ( $blocked_no_auth && $blocked_invalid && $allowed_valid && $blocked_unconfigured ) {
	echo "  -> PASS: Webhook spoofing blocked (401), valid secret authenticated.\n";
	$passed++;
} else {
	echo "  -> FAIL: Webhook authentication failed.\n";
}

// -------------------------------------------------------------
// TEST 2: Cross-Event IDOR Prevention in Session Booking
// -------------------------------------------------------------
echo "[TEST 2/6] Cross-Event Session IDOR Prevention...\n";

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// Setup Event A & Session A
$wpdb->insert( "{$wpdb->prefix}cybevents_events", array( 'title' => 'Event Alpha', 'status' => 'active' ) );
$event_a_id = $wpdb->insert_id;
$wpdb->insert( "{$wpdb->prefix}cybevents_sessions", array( 'event_id' => $event_a_id, 'title' => 'Session A', 'kapazitaet' => 10, 'belegt' => 0, 'start_time' => '2026-10-01 10:00:00', 'end_time' => '2026-10-01 11:00:00' ) );
$session_a_id = $wpdb->insert_id;

// Setup Event B & Session B
$wpdb->insert( "{$wpdb->prefix}cybevents_events", array( 'title' => 'Event Beta', 'status' => 'active' ) );
$event_b_id = $wpdb->insert_id;
$wpdb->insert( "{$wpdb->prefix}cybevents_sessions", array( 'event_id' => $event_b_id, 'title' => 'Session B', 'kapazitaet' => 10, 'belegt' => 0, 'start_time' => '2026-10-01 10:00:00', 'end_time' => '2026-10-01 11:00:00' ) );
$session_b_id = $wpdb->insert_id;

// Setup Registration for Event A
$raw_token = bin2hex( random_bytes( 16 ) );
$wpdb->insert(
	"{$wpdb->prefix}cybevents_registrations",
	array(
		'event_id'   => $event_a_id,
		'email'      => 'idor_test_' . time() . '@example.com',
		'first_name' => 'IDOR',
		'last_name'  => 'Tester',
		'status'     => 'confirmed',
		'token_hash' => hash( 'sha256', $raw_token ),
		'created_at' => current_time( 'mysql' ),
	)
);
$reg_id = $wpdb->insert_id;
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// Attempt 1: Try booking Session B (belongs to Event B) with Event A's token
$req_idor = new WP_REST_Request( 'POST', '/cybevents/v1/book-sessions' );
$req_idor->set_param( 'token', $raw_token );
$req_idor->set_param( 'session_ids', array( $session_b_id ) );

$res_idor = CyberEvents_REST_API::handle_book_sessions( $req_idor );
$idor_blocked = ( 400 === $res_idor->get_status() );

// Attempt 2: Book valid Session A
$req_legit = new WP_REST_Request( 'POST', '/cybevents/v1/book-sessions' );
$req_legit->set_param( 'token', $raw_token );
$req_legit->set_param( 'session_ids', array( $session_a_id ) );

$res_legit = CyberEvents_REST_API::handle_book_sessions( $req_legit );
$legit_ok = ( 200 === $res_legit->get_status() );

if ( $idor_blocked && $legit_ok ) {
	echo "  -> PASS: Cross-event session injection rejected (400), legitimate session booked.\n";
	$passed++;
} else {
	echo "  -> FAIL: IDOR vulnerability detected.\n";
}

// Remove exactly the rows this test inserted, by id. Until 2026-09-17 every runtime pass left them
// behind: 28 "Event Alpha"/"Event Beta" events and 8 idor_test_ registrations were measured on
// local WordPress install, which is also the demo site. Only ids created above are touched.
// wm-runtime: removes only the rows it inserts
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->delete( "{$wpdb->prefix}cybevents_registration_sessions", array( 'registration_id' => $reg_id ) );
$wpdb->delete( "{$wpdb->prefix}cybevents_registrations", array( 'id' => $reg_id ) );
foreach ( array( $session_a_id, $session_b_id ) as $probe_session_id ) {
	$wpdb->delete( "{$wpdb->prefix}cybevents_sessions", array( 'id' => $probe_session_id ) );
}
foreach ( array( $event_a_id, $event_b_id ) as $probe_event_id ) {
	$wpdb->delete( "{$wpdb->prefix}cybevents_events", array( 'id' => $probe_event_id ) );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

// -------------------------------------------------------------
// TEST 3: CSV Formula Injection (CWE-1236) Neutralization
// -------------------------------------------------------------
echo "[TEST 3/6] CSV Formula Injection (CWE-1236) Neutralization...\n";

$payloads = array(
	'=CMD|\' /C calc\'!A0'               => '\'=CMD|\' /C calc\'!A0',
	'+SUM(1+1)*cmd|\' /C calc\'!A0'     => '\'+SUM(1+1)*cmd|\' /C calc\'!A0',
	'-2+3+cmd|\' /C calc\'!A0'          => '\'-2+3+cmd|\' /C calc\'!A0',
	'@HYPERLINK("http://evil.com","X")' => '\'@HYPERLINK("http://evil.com","X")',
	"\tMALICIOUS_TAB"                   => "'\tMALICIOUS_TAB",
	"Normal text without prefix"        => "Normal text without prefix",
);

$csv_ok = true;
foreach ( $payloads as $input => $expected ) {
	$sanitized = CyberEvents_Admin::sanitize_csv_cell( $input );
	if ( $sanitized !== $expected ) {
		$csv_ok = false;
		echo esc_html( "  -> Mismatch for '$input': got '$sanitized', expected '$expected'\n" );
	}
}

if ( $csv_ok ) {
	echo "  -> PASS: All formula injection prefixes neutralized with single quote prefix.\n";
	$passed++;
} else {
	echo "  -> FAIL: CSV Formula sanitization failed.\n";
}

// -------------------------------------------------------------
// TEST 4: ZUGFeRD 2.3 XML Injection / Entity Escaping
// -------------------------------------------------------------
echo "[TEST 4/6] ZUGFeRD 2.3 XML Injection / Entity Escaping...\n";

// The escaping lives in generate_zugferd_xml(), a pure function. Until 2026-09-17 this test went through
// create_invoice(), which since 2026-09-15 refuses (WP_Error) while the seller settings are incomplete —
// the test then read a property of null and failed without ever looking at the XML. Going through
// create_invoice() on a configured site would also consume a real invoice number from the gapless
// sequence and leave an invoice row behind. The document is generated here and nothing is stored.
$xml = CyberEvents_Invoicing::generate_zugferd_xml(
	array(
		'type_code'         => CyberEvents_Invoicing::TYPE_INVOICE,
		'invoice_number'    => 'PROBE-XML-ESCAPING',
		'issued_at'         => '2026-09-17 10:00:00',
		'due_date'          => '2026-10-01 10:00:00',
		'delivery_date'     => '2026-09-17 10:00:00',
		'seller'            => array(
			'name'       => 'Probe Seller & <Co>',
			'street'     => 'Musterweg 1',
			'postcode'   => '06108',
			'city'       => 'Halle (Saale)',
			'country'    => 'DE',
			'vat_id'     => 'DE123456789',
			'tax_number' => '',
			'email'      => '',
			'iban'       => '',
		),
		'recipient_name'    => 'Max <Admin> & "Co"',
		'recipient_company' => 'Cyber <Sec> GmbH & Partner',
		'recipient_email'   => '',
		'recipient_address' => 'Teststr. 1 <script>alert(1)</script>',
		'net_cents'         => 10000,
		'tax_cents'         => 1900,
		'gross_cents'       => 11900,
		'tax_rate'          => 19.0,
		'currency'          => 'EUR',
		'item_description'  => 'Ticket & Workshop <A1>',
	)
);

$contains_dangerous_tags = ( strpos( $xml, '<script>' ) !== false || strpos( $xml, '<Admin>' ) !== false || strpos( $xml, '<Sec>' ) !== false );
$contains_escaped_xml    = ( strpos( $xml, '&lt;script&gt;' ) !== false && strpos( $xml, '&amp;' ) !== false && strpos( $xml, '&lt;Admin&gt;' ) !== false );

if ( ! $contains_dangerous_tags && $contains_escaped_xml ) {
	echo "  -> PASS: Dangerous raw XML elements sanitized with ENT_XML1 / ENT_QUOTES.\n";
	$passed++;
} else {
	echo "  -> FAIL: XML Injection escaping failed.\n";
}

// -------------------------------------------------------------
// TEST 5: Open Redirect Defense in Brevo DOI
// -------------------------------------------------------------
echo "[TEST 5/6] Open Redirect Defense in DOI Handlers...\n";

$evil_url   = 'https://evil-phishing-site.com/steal-creds';
$safe_check = CyberEvents_Brevo_API::validate_safe_redirect_url( $evil_url );
$local_url  = home_url( '/session-auswahl/?token=test12345' );
$local_check = CyberEvents_Brevo_API::validate_safe_redirect_url( $local_url );

// A foreign host falls back to the front page. The /session-auswahl/ slug this test expected was removed on
// 2026-09-15 because it exists on no install unless someone creates it by hand (class-brevo-api.php).
$redirect_safe = ( $safe_check === home_url( '/' ) ) && ( $local_check === $local_url );

if ( $redirect_safe ) {
	echo "  -> PASS: External phishing redirect target neutralized to local home URL.\n";
	$passed++;
} else {
	echo "  -> FAIL: Open redirect validation failed.\n";
}

// -------------------------------------------------------------
// TEST 6: IP Rate Limiting
// -------------------------------------------------------------
echo "[TEST 6/6] IP-based REST API Rate Limiting...\n";

$test_action = 'rate_test_' . time();
$limit = 3;
delete_transient( 'cybevents_rl_' . md5( $test_action . '_127.0.0.1' ) );

$r1 = CyberEvents_REST_API::check_rate_limit( $test_action, $limit, 60 );
$r2 = CyberEvents_REST_API::check_rate_limit( $test_action, $limit, 60 );
$r3 = CyberEvents_REST_API::check_rate_limit( $test_action, $limit, 60 );
$r4 = CyberEvents_REST_API::check_rate_limit( $test_action, $limit, 60 ); // Should exceed

if ( $r1 && $r2 && $r3 && ! $r4 ) {
	echo "  -> PASS: Rate limit strictly enforced (threshold: 3 reqs, 4th request blocked).\n";
	$passed++;
} else {
	echo "  -> FAIL: Rate limiter did not block excessive requests.\n";
}

// -------------------------------------------------------------
// Final Verdict
// -------------------------------------------------------------
echo "\n-------------------------------------------------------------\n";
if ( $passed === $total ) {
	echo "VERDICT: PASS (6/6 Cybersecurity Attack Vectors Mitigated & Verified)\n";
} else {
	echo esc_html( "VERDICT: FAIL ($passed/$total Passed)\n" );
}
echo "-------------------------------------------------------------\n";
