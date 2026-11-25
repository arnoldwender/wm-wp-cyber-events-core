<?php
/**
 * Falsifiers for the audit-log claims.
 *
 * Measured on 2026-08-27, before this release: wp_cybevents_audit_log had no hash column of any
 * kind. Meanwhile the admin screen called it an "Unveränderbares Audit-Log für lückenlose
 * Nachvollziehbarkeit bei Betriebsprüfungen", SECURITY.md promised "kryptografischer Salted Hash"
 * and docs/DEVELOPER-GUIDE.md promised "tamper-evident cryptographic hash chains". It was an
 * ordinary table that anything could edit or delete from without leaving a trace.
 *
 * The retention job made this harder than in the sibling plugins: it removed rows from the middle
 * of the log every night, which a naive chain would report as tampering on every legitimate run.
 * The chain therefore binds a content DIGEST rather than the fields, so the retention job can drop
 * the plaintext — which is what README and ARCHITECTURE said it did all along, while the code
 * deleted — and both links either side still verify.
 *
 * Mutation checks:
 *   - remove the entry_hash comparison in verify_audit_chain() → case 5 fails.
 *   - remove the prev_hash comparison → case 4 fails.
 *   - remove the content_digest comparison → case 3 fails.
 *   - make rotate_audit_logs() DELETE again → case 6 fails.
 *
 * WARNING: truncates the audit log. Scratch installs only.
 *
 * Run: wp eval-file wp-content/plugins/wm-cyber-events-core/tests/test-audit-chain.php --allow-root
 *
 * @package CyberEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_a_passed'] = 0;
$GLOBALS['wm_a_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_a_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_a_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_a_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
$table = $wpdb->prefix . 'cybevents_audit_log';

echo "\n=== CYBEVENTS AUDIT CHAIN TEST ===\n\n";

/**
 * Fresh audit log with $n chained entries.
 *
 * @param int $n Entries.
 * @return array Ids.
 */
function wm_a_fixture( $n = 4 ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cybevents_audit_log';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "TRUNCATE TABLE {$table}" );
	delete_option( CyberEvents_Database::AUDIT_TIP_OPTION );
	for ( $i = 1; $i <= $n; $i++ ) {
		CyberEvents_Database::log_audit( "probe_event_{$i}", $i, 7, wp_json_encode( array( 'n' => $i ) ) );
	}
	return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC" ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// --- Case 1: the log carries a chain at all --------------------------------
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
wm_a_assert( in_array( 'entry_hash', $cols, true ), 'the audit log has an entry_hash column' );
wm_a_assert( in_array( 'prev_hash', $cols, true ), 'the audit log has a prev_hash column' );
wm_a_assert( in_array( 'content_digest', $cols, true ), 'the audit log has a content_digest column' );

// --- Case 2: an untouched log verifies -------------------------------------
$ids = wm_a_fixture();
$r   = CyberEvents_Database::verify_audit_chain();
wm_a_assert( true === $r['valid'], 'an untouched audit log verifies' );
wm_a_assert( 4 === $r['checked'], 'all four entries were checked by content (got ' . $r['checked'] . ')' );

// --- Case 3: editing an entry's content is detected ------------------------
$ids = wm_a_fixture();
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = 999 WHERE id = %d", $ids[1] ) );
$r = CyberEvents_Database::verify_audit_chain();
wm_a_assert( false === $r['valid'], 'editing an audit entry is detected' );
wm_a_assert( (int) $ids[1] === (int) $r['broken_at_id'], 'the edited entry is the one reported' );

// --- Case 4: deleting an entry from the middle is detected -----------------
$ids = wm_a_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $ids[1] ) );
$r = CyberEvents_Database::verify_audit_chain();
wm_a_assert( false === $r['valid'], 'deleting an audit entry from the middle is detected' );

// --- Case 5: forging a link without the key does not work ------------------
// Rewrite an entry AND its digest, the way someone with database access would.
$ids = wm_a_fixture();
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ids[1] ), ARRAY_A );
$forged = CyberEvents_Database::audit_content_digest( $row['event_type'], 4242, 4242, '9.9.9.9', '{}', $row['created_at'] );
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$table} SET user_id = 4242, object_id = 4242, ip_address = '9.9.9.9', details = '{}', content_digest = %s WHERE id = %d",
		$forged,
		$ids[1]
	)
);
$r = CyberEvents_Database::verify_audit_chain();
wm_a_assert( false === $r['valid'], 'recomputing the digest without the key does not forge a valid chain' );

// --- Case 6: the retention job pseudonymises and keeps the chain -----------
// A DELETE here would break the chain on every nightly run, which is why the previous
// implementation and a hash chain could not have coexisted.
$ids = wm_a_fixture( 3 );
$old = gmdate( 'Y-m-d H:i:s', time() - ( 200 * DAY_IN_SECONDS ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id = %d", $old, $ids[0] ) );

$rotated = CyberEvents_GDPR_Manager::rotate_audit_logs( 90 );
$after   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ids[0] ), ARRAY_A );
$r       = CyberEvents_Database::verify_audit_chain();

wm_a_assert( 1 === (int) $rotated, 'the retention job touched exactly the one expired entry' );
wm_a_assert( null !== $after, 'the expired entry still exists — it was pseudonymised, not deleted' );
wm_a_assert( '' === (string) $after['ip_address'] && 0 === (int) $after['user_id'], 'its personal fields are gone' );
wm_a_assert( ! empty( $after['pseudonymized_at'] ), 'it is marked as pseudonymised' );
wm_a_assert( true === $r['valid'], 'the chain still verifies after the retention run' );
wm_a_assert( 1 === (int) $r['pseudonymized'], 'the pseudonymised entry is counted separately, not as content-verified' );
wm_a_assert( 2 === (int) $r['checked'], 'only the two intact entries count as content-verified' );

// --- Case 7: invoice entries are exempt from the retention window ----------
$ids = wm_a_fixture( 1 );
CyberEvents_Database::log_audit( 'invoice_created', 1, 7, '{"invoice":1}' );
$inv = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE event_type = 'invoice_created' ORDER BY id DESC LIMIT 1" );
$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id = %d", $old, $inv ) );
CyberEvents_GDPR_Manager::rotate_audit_logs( 90 );
$inv_after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $inv ), ARRAY_A );
wm_a_assert( empty( $inv_after['pseudonymized_at'] ), 'an invoice entry is left alone (sec. 147 AO, ten years)' );

// --- Case 8: deleting the newest entries is detected -----------------------
$ids = wm_a_fixture();
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id > %d", $ids[1] ) );
$r = CyberEvents_Database::verify_audit_chain();
wm_a_assert( false === $r['valid'], 'deleting the newest entries is caught by the recorded tip' );

// --- Case 9: the key does not come from a literal in the source ------------
wm_a_assert( 'wp-config' === CyberEvents_Database::audit_key_source(), 'the chain key comes from wp-config.php on this install' );

// --- Teardown -------------------------------------------------------------
$wpdb->query( "TRUNCATE TABLE {$table}" );
delete_option( CyberEvents_Database::AUDIT_TIP_OPTION );

echo "\n=== RESULT: {$GLOBALS['wm_a_passed']} passed, {$GLOBALS['wm_a_failed']} failed ===\n\n";
