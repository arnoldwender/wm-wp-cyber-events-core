<?php
/**
 * Standalone test suite for wm-cyber-events-core.
 *
 * Runs without WordPress or MySQL: tests/bootstrap-harness.php provides wpdb (with the column lists
 * of the real schema), options, hooks, cron, HTTP and REST stand-ins. The groups A to G exercise the
 * registration flow and the privacy paths corrected on 2026-09-15; each assertion is meant to fail
 * when the corrected code is reverted (tests/run-mutations.sh checks that).
 *
 * @package CyberEvents
 */

require_once __DIR__ . '/bootstrap-harness.php';

// ------------------------------------------------------------
// Test framework
// ------------------------------------------------------------
$passed_count = 0;
$failed_count = 0;
$test_results = [];

function assert_test(string $name, bool $condition, string $failure_details = ''): void {
    global $passed_count, $failed_count, $test_results;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed_count++;
        $test_results[] = ['name' => $name, 'status' => 'passed'];
    } else {
        echo "[FAIL] {$name}: {$failure_details}\n";
        $failed_count++;
        $test_results[] = ['name' => $name, 'status' => 'failed', 'details' => $failure_details];
    }
}

function print_results(): void {
    global $passed_count, $failed_count, $test_results;
    echo "\n============================================================\n";
    echo "  TEST SUITE RESULTS: {$passed_count} PASSED | {$failed_count} FAILED\n";
    echo "============================================================\n\n";
    echo "--- JSON REPORT ---\n";
    echo json_encode(['passed' => $passed_count, 'failed' => $failed_count, 'results' => $test_results], JSON_PRETTY_PRINT) . "\n";
}

// A PHP warning, notice or deprecation is a failure too: an undefined property or a call PHP is about to
// change behaves differently in production than in this run.
set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $errno)) {
        return false; // silenced with @
    }
    assert_test('no PHP warning, notice or deprecation', false, "{$message} at " . basename($file) . ":{$line}");
    return true;
});

// An uncaught error counts as a failure and still prints the results line, so a fatal can never
// look like a shorter green run.
set_exception_handler(static function (Throwable $e): void {
    global $failed_count;
    echo '[FAIL] uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    $failed_count++;
    print_results();
    exit(1);
});

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function reset_state(): void {
    global $mock_db_tables, $wpdb, $mock_options, $mock_options_autoload, $mock_scheduled, $mock_cleared_hooks, $mock_http_requests, $mock_http_response, $mock_transients, $mock_filters, $mock_rest_routes, $mock_is_admin, $mock_cache, $mock_ext_cache, $mock_cache_evict_on_incr, $mock_registered_scripts, $mock_registered_styles, $mock_script_translations, $mock_registered_blocks, $mock_block_bindings, $mock_blog_stack, $mock_blog_switches;
    $wpdb->prefix = 'wp_';
    $wpdb->posts  = 'wp_posts';
    $default_tables = [
        'wp_cybevents_events',
        'wp_cybevents_sessions',
        'wp_cybevents_registrations',
        'wp_cybevents_registration_sessions',
        'wp_cybevents_invoices',
        'wp_cybevents_audit_log',
        'wp_cybevents_queue',
        'wp_cybevents_venues',
        'wp_posts',
    ];
    $mock_db_tables = [];
    foreach ($default_tables as $table) {
        $mock_db_tables[$table] = [];
    }
    $wpdb->table_auto_increment = [];
    $wpdb->before_update = null;
    $wpdb->on_get_results = null;
    $wpdb->queries = [];
    $wpdb->reads = [];
    $mock_cache = [];
    $mock_ext_cache = false;
    $mock_cache_evict_on_incr = false;
    $mock_options_autoload = [];
    unset($mock_options[CyberEvents_Database::AUDIT_TIP_OPTION]);
    unset($mock_options['wm_cybevents_delete_data_on_uninstall']);
    $mock_scheduled = [];
    $mock_cleared_hooks = [];
    $mock_http_requests = [];
    $mock_http_response = ['response' => ['code' => 201], 'body' => ''];
    $mock_transients = [];
    $mock_filters['cron_schedules'] = [];
    $mock_filters['wm_cybevents_public_rest_rate_limit'] = [];
    $mock_filters['wm_cybevents_metric_value'] = [];
    $mock_filters['cybevents_seatmap_transport'] = [];
    $mock_rest_routes = [];
    $mock_registered_scripts = [];
    $mock_registered_styles = [];
    $mock_script_translations = [];
    $mock_registered_blocks = [];
    $mock_block_bindings = [];
    $mock_blog_stack = [];
    $mock_blog_switches = [];
    $mock_is_admin = false;
    $GLOBALS['mock_user_can_manage_options'] = true;
    $GLOBALS['mock_now'] = null;
    $GLOBALS['mock_is_multisite'] = false;
    $GLOBALS['mock_network_active'] = false;
    $_GET = [];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
}

function set_settings(array $overrides): void {
    update_option('cybevents_settings', array_merge(get_option('cybevents_settings', []), $overrides));
}

function source_of(string $relative): string {
    return (string) file_get_contents(ABSPATH . $relative);
}

/** Body of a function or method, braces included, found by brace matching. */
function function_body(string $relative, string $function): string {
    $src = source_of($relative);
    $pos = strpos($src, "function {$function}(");
    if ($pos === false) {
        return '';
    }
    $open = strpos($src, '{', $pos);
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}' && --$depth === 0) {
            return substr($src, $open, $i - $open + 1);
        }
    }
    return '';
}

function seed_event(array $overrides = []): int {
    global $wpdb;
    $wpdb->insert('wp_cybevents_events', array_merge([
        'title'        => 'Testkonferenz',
        'slug'         => 'testkonferenz',
        'start_date'   => '2026-10-15 09:00:00',
        'end_date'     => '2026-10-15 18:00:00',
        'location'     => 'Saal 1',
        'max_capacity' => 100,
        'status'       => 'active',
    ], $overrides));
    return $wpdb->insert_id;
}

function seed_session(int $event_id, int $capacity, int $taken = 0): int {
    global $wpdb;
    $wpdb->insert('wp_cybevents_sessions', [
        'event_id'   => $event_id,
        'title'      => 'Session ' . ($wpdb->table_auto_increment['wp_cybevents_sessions'] ?? 0),
        'start_time' => '2026-10-15 10:00:00',
        'end_time'   => '2026-10-15 11:00:00',
        'kapazitaet' => $capacity,
        'belegt'     => $taken,
    ]);
    return $wpdb->insert_id;
}

/** A registration row with every column; returns its id and the raw tokens. */
function seed_registration(int $event_id, string $status, array $overrides = []): array {
    global $wpdb;
    $token = bin2hex(random_bytes(32));
    $doi   = bin2hex(random_bytes(32));
    $wpdb->insert('wp_cybevents_registrations', array_merge([
        'event_id'         => $event_id,
        'token_hash'       => hash('sha256', $token),
        'email'            => 'person' . random_int(1000, 9999) . '@example.org',
        'first_name'       => 'Erika',
        'last_name'        => 'Muster',
        'company'          => 'Muster AG',
        'avatar_url'       => '',
        'status'           => $status,
        'doi_token_hash'   => 'pending_doi' === $status ? hash('sha256', $doi) : null,
        'doi_requested_at' => current_time('mysql'),
        'doi_confirmed_at' => 'confirmed' === $status ? current_time('mysql') : null,
        'consent_privacy'  => 1,
        'consent_photo'    => 0,
        'payment_status'   => 'free',
        'invoice_number'   => null,
        'checked_in'       => 0,
        'checked_in_at'    => null,
        'created_at'       => current_time('mysql'),
    ], $overrides));
    return ['id' => $wpdb->insert_id, 'token' => $token, 'doi' => $doi];
}

function registration(int $id) {
    global $mock_db_tables;
    return $mock_db_tables['wp_cybevents_registrations'][$id] ?? null;
}

function session_taken(int $id): int {
    global $mock_db_tables;
    return (int) ($mock_db_tables['wp_cybevents_sessions'][$id]->belegt ?? -1);
}

function audit_details_containing(string $needle): array {
    global $mock_db_tables;
    return array_values(array_filter($mock_db_tables['wp_cybevents_audit_log'], static fn($row) => str_contains((string) $row->details, $needle)));
}

function rest_request(array $params, array $headers = []): WP_REST_Request {
    $req = new WP_REST_Request();
    foreach ($params as $key => $value) {
        $req->set_param($key, $value);
    }
    foreach ($headers as $key => $value) {
        $req->set_header($key, $value);
    }
    return $req;
}

function query_args(string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
    return $args;
}

echo "============================================================\n";
echo " WM CYBER EVENTS CORE — TEST SUITE\n";
echo "============================================================\n\n";

// ------------------------------------------------------------
// Test 1: book_session_atomic() admits exactly the capacity
// ------------------------------------------------------------
reset_state();
$event_id   = seed_event();
$session_id = seed_session($event_id, 5);
$granted = 0;
$refused = 0;
for ($i = 1; $i <= 25; $i++) {
    $res = CyberEvents_Database::book_session_atomic($session_id, $i);
    if (true === $res) {
        $granted++;
    } elseif (is_wp_error($res) && 'cybevents_session_full' === $res->get_error_code()) {
        $refused++;
    }
}
assert_test(
    'book_session_atomic grants 5 of 25 requests for 5 seats and refuses 20 as full',
    $granted === 5 && $refused === 20 && session_taken($session_id) === 5 && count($mock_db_tables['wp_cybevents_registration_sessions']) === 5,
    "granted {$granted}, refused {$refused}, taken " . session_taken($session_id)
);

// ------------------------------------------------------------
// Test 5: Theme Manager
// ------------------------------------------------------------
$themes = CyberEvents_Theme_Manager::get_theme_presets();
$has_themes = isset($themes['theme_default'], $themes['violet_mesh'], $themes['enterprise_dark']);
$active_preset = CyberEvents_Theme_Manager::get_active_preset();
$css_tokens = CyberEvents_Theme_Manager::get_generated_css();

assert_test(
    'Theme Manager loads its presets and generates CSS custom properties',
    $has_themes && !empty($active_preset) && is_string($css_tokens),
    "Active: {$active_preset}, CSS length: " . strlen($css_tokens)
);

// ------------------------------------------------------------
// Test 7: CSV formula injection (CWE-1236)
// ------------------------------------------------------------
$sanitized_all = true;
foreach (['=CMD|"/C calc"!A0', '+123456789', '-9999999', '@SUM(1+1)'] as $val) {
    $sanitized = CyberEvents_Admin::sanitize_csv_cell($val);
    if (in_array(substr($sanitized, 0, 1), ['=', '+', '-', '@'], true)) {
        $sanitized_all = false;
        break;
    }
}
assert_test(
    'Admin CSV exporter escapes leading formula characters (CWE-1236)',
    $sanitized_all,
    'One or more cells remained unescaped'
);

// ------------------------------------------------------------
// Test 9: Brevo webhook fails CLOSED without a configured secret (fleet audit 2026-09-13 §6.2 #5)
// ------------------------------------------------------------
set_settings(['brevo_webhook_secret' => '']);

$req_unconfigured = new WP_REST_Request('POST', '/cybevents/v1/brevo-webhook');
$req_unconfigured->set_body(json_encode(['event' => 'hard_bounce', 'email' => 'victim@example.org']));
$perm_unconfigured = CyberEvents_REST_API::validate_brevo_webhook_permission($req_unconfigured);
$perm_status = is_wp_error($perm_unconfigured) ? (int) (($perm_unconfigured->data['status'] ?? 0)) : 0;
assert_test(
    'Brevo webhook permission fails closed (401) while no shared secret is configured',
    is_wp_error($perm_unconfigured) && 401 === $perm_status,
    'permission_callback returned ' . var_export($perm_unconfigured, true)
);

$handler_unconfigured = CyberEvents_REST_API::handle_brevo_webhook($req_unconfigured);
assert_test(
    'Brevo webhook handler refuses to process a payload while no shared secret is configured',
    $handler_unconfigured instanceof WP_REST_Response && 401 === $handler_unconfigured->get_status(),
    'handler answered status ' . ($handler_unconfigured instanceof WP_REST_Response ? $handler_unconfigured->get_status() : 'n/a')
);

$webhook_secret = 'wh_' . bin2hex(random_bytes(8));
set_settings(['brevo_webhook_secret' => $webhook_secret]);
$perm_wrong = CyberEvents_REST_API::validate_brevo_webhook_permission(rest_request([], ['x-brevo-webhook-secret' => 'attacker-guess']));
$perm_right = CyberEvents_REST_API::validate_brevo_webhook_permission(rest_request([], ['x-brevo-webhook-secret' => $webhook_secret]));
assert_test(
    'Brevo webhook permission rejects a wrong secret (401) and accepts the configured one',
    is_wp_error($perm_wrong) && 401 === (int) ($perm_wrong->data['status'] ?? 0) && true === $perm_right,
    'wrong=' . var_export($perm_wrong, true) . ' right=' . var_export($perm_right, true)
);

// ============================================================
// Group A — cron events, activation, migration
// ============================================================
reset_state();
$main_source = source_of('wm-cyber-events-core.php');
$filter_line = "add_filter( 'cron_schedules', array( 'CyberEvents_Activator', 'register_cron_schedules' ) );";
// At column 0 of the main file, i.e. registered when the file loads (before activation and init).
$filter_at_load = (bool) preg_match('/^' . preg_quote($filter_line, '/') . '$/m', $main_source);
add_filter('cron_schedules', ['CyberEvents_Activator', 'register_cron_schedules']);
CyberEvents_Activator::ensure_cron_events();
assert_test(
    'A1 the every_minute recurrence is registered at file load, so the queue event is scheduled (until 2026-09-15 it never was)',
    $filter_at_load
        && ($mock_scheduled['cybevents_process_queue_cron']['recurrence'] ?? '') === 'every_minute'
        && ($mock_scheduled['cybevents_gdpr_retention_cron']['recurrence'] ?? '') === 'daily',
    'filter at load: ' . var_export($filter_at_load, true) . ', scheduled: ' . json_encode($mock_scheduled)
);

$schedules = CyberEvents_Activator::register_cron_schedules([]);
assert_test(
    'A2 every_minute is 60 seconds and its label calls no translation function (the filter can run before init)',
    (int) ($schedules['every_minute']['interval'] ?? 0) === 60 && !preg_match('/\b(?:__|esc_html__|_x)\(/', function_body('includes/class-activator.php', 'register_cron_schedules')),
    json_encode($schedules)
);

CyberEvents_Deactivator::deactivate();
assert_test(
    'A3 deactivation clears the queue and the retention events',
    in_array('cybevents_process_queue_cron', $mock_cleared_hooks, true) && in_array('cybevents_gdpr_retention_cron', $mock_cleared_hooks, true),
    json_encode($mock_cleared_hooks)
);

$init_body     = function_body('wm-cyber-events-core.php', 'cybevents_init');
$migrate_body  = function_body('includes/class-activator.php', 'migrate');
$activate_body = function_body('includes/class-activator.php', 'activate');
assert_test(
    'A4 a schema bump runs migrate() (schema and cron only), never activate(), and activate() itself is opt-in free of demo seeding',
    str_contains($init_body, 'CyberEvents_Activator::migrate();')
        && str_contains($init_body, 'CyberEvents_Activator::ensure_cron_events();')
        && !str_contains($init_body, 'CyberEvents_Activator::activate()')
        && str_contains($migrate_body, 'self::create_schema();')
        && !str_contains($migrate_body, 'seed_demo_data')
        && !str_contains($activate_body, 'seed_demo_data')
        && !str_contains(source_of('includes/class-admin.php'), 'CyberEvents_Activator::activate()'),
    'init/migrate/activate/admin call the wrong routine'
);

reset_state();
add_filter('cron_schedules', ['CyberEvents_Activator', 'register_cron_schedules']);
CyberEvents_Activator::activate();
assert_test(
    'A4b CyberEvents_Activator::activate() sets default options and schedules cron without seeding events, sessions, registrations or posts',
    is_array(get_option('cybevents_settings'))
        && wp_next_scheduled('cybevents_process_queue_cron') !== false
        && wp_next_scheduled('cybevents_gdpr_retention_cron') !== false
        && count($mock_db_tables['wp_cybevents_events']) === 0
        && count($mock_db_tables['wp_cybevents_sessions']) === 0
        && count($mock_db_tables['wp_cybevents_registrations']) === 0
        && count($mock_db_tables['wp_posts']) === 0,
    json_encode([
        'events'   => count($mock_db_tables['wp_cybevents_events']),
        'sessions' => count($mock_db_tables['wp_cybevents_sessions']),
        'regs'     => count($mock_db_tables['wp_cybevents_registrations']),
        'posts'    => count($mock_db_tables['wp_posts']),
    ])
);

$activator_source = source_of('includes/class-activator.php');
assert_test(
    'A5 the audit table has residual_mac and DB_VERSION is at least 3, so existing installs migrate',
    version_compare(CyberEvents_Activator::DB_VERSION, '3', '>=') && (bool) preg_match('/cybevents_audit_log \(.*?residual_mac varchar\(64\)/s', $activator_source),
    'DB_VERSION ' . CyberEvents_Activator::DB_VERSION
);

// ============================================================
// Group B — Brevo client without a key
// ============================================================
reset_state();
set_settings(['brevo_api_key' => '']);
$doi_result    = CyberEvents_Brevo_API::send_double_optin(['email' => 'erika@example.org', 'redirection_url' => home_url('/')]);
$update_result = CyberEvents_Brevo_API::update_contact_attributes('erika@example.org', ['X' => 1]);
assert_test(
    'B1 without an API key both Brevo calls return an error and send nothing (until 2026-09-15 a simulated success)',
    is_wp_error($doi_result) && 'cybevents_brevo_not_configured' === $doi_result->get_error_code()
        && is_wp_error($update_result) && 'cybevents_brevo_not_configured' === $update_result->get_error_code()
        && count($mock_http_requests) === 0,
    var_export($doi_result, true)
);

// ============================================================
// Group C1 — public registration
// ============================================================
reset_state();
set_settings([
    'brevo_api_key'            => '',
    'enable_friendly_captcha'  => 1,
    'friendly_captcha_api_key' => 'secret-key',
    'friendly_captcha_sitekey' => 'site-key',
]);
$event_id = seed_event();
$register_response = CyberEvents_REST_API::handle_register(rest_request([
    'event_id'        => $event_id,
    'email'           => 'erika.muster@example.org',
    'first_name'      => 'Erika',
    'last_name'       => 'Muster',
    'company'         => 'Muster AG',
    'consent_privacy' => 1,
    'avatar_url'      => 'https://tracker.example.net/pixel.png',
]));
$new_reg = array_values($mock_db_tables['wp_cybevents_registrations'])[0] ?? null;
$doi_job = array_values($mock_db_tables['wp_cybevents_queue'])[0] ?? null;
$doi_payload = $doi_job ? (array) json_decode((string) $doi_job->payload, true) : [];

assert_test(
    'C1a registration answers 200 with the captcha switched on (the plugin ships no widget) and stores no posted avatar URL',
    $register_response->get_status() === 200 && $new_reg && 'pending_doi' === $new_reg->status && '' === (string) $new_reg->avatar_url && count($mock_http_requests) === 0,
    'status ' . $register_response->get_status() . ' ' . json_encode($register_response->get_data()) . ' avatar ' . ($new_reg->avatar_url ?? 'n/a')
);

$confirm_link = (string) ($doi_payload['redirection_url'] ?? '');
$link_args    = query_args($confirm_link);
assert_test(
    'C1b the queued job is brevo_doi and carries a link to /confirm whose tokens belong to the registration',
    $doi_job && 'brevo_doi' === $doi_job->job_type
        && str_starts_with($confirm_link, rest_url('cybevents/v1/confirm'))
        && $new_reg && hash('sha256', (string) ($link_args['doi'] ?? '')) === $new_reg->doi_token_hash
        && hash('sha256', (string) ($link_args['token'] ?? '')) === $new_reg->token_hash
        && (int) ($doi_payload['registration_id'] ?? 0) === (int) $new_reg->id,
    'job ' . json_encode($doi_job)
);

assert_test(
    'C1c no audit entry of the registration contains the e-mail address',
    count(audit_details_containing('erika.muster@example.org')) === 0 && count($mock_db_tables['wp_cybevents_audit_log']) >= 1,
    json_encode(audit_details_containing('erika.muster@example.org'))
);

// ============================================================
// Group B2 — queue processing of that job
// ============================================================
$processed = CyberEvents_Queue_Runner::process_batch();
$job_now   = $mock_db_tables['wp_cybevents_queue'][$doi_job->id];
assert_test(
    'B2 without a key the job is retried (pending, attempt 1, error kept) and keeps its payload for the retry',
    $processed === 0 && 'pending' === $job_now->status && (int) $job_now->attempts === 1
        && str_contains((string) $job_now->error_message, 'Brevo') && str_contains((string) $job_now->payload, 'redirection_url'),
    json_encode($job_now)
);

set_settings(['brevo_api_key' => 'xkeysib-test', 'brevo_doi_template_id' => 7, 'brevo_list_id' => 3]);
$mock_db_tables['wp_cybevents_queue'][$doi_job->id]->scheduled_at = gmdate('Y-m-d H:i:s', time() - 60);
$processed  = CyberEvents_Queue_Runner::process_batch();
$job_now    = $mock_db_tables['wp_cybevents_queue'][$doi_job->id];
$brevo_call = $mock_http_requests[0] ?? null;
$brevo_body = $brevo_call ? (array) json_decode((string) $brevo_call['args']['body'], true) : [];
assert_test(
    'B3 with a key the job calls doubleOptinConfirmation with the confirmation link, completes, and keeps only the registration id',
    $processed === 1 && 'completed' === $job_now->status
        && $job_now->payload === json_encode(['registration_id' => (int) $new_reg->id])
        && ($brevo_call['url'] ?? '') === 'https://api.brevo.com/v3/contacts/doubleOptinConfirmation'
        && ($brevo_body['redirectionUrl'] ?? '') === $confirm_link
        && ($brevo_body['templateId'] ?? 0) === 7 && ($brevo_body['includeListIds'] ?? []) === [3]
        && ($brevo_body['email'] ?? '') === 'erika.muster@example.org',
    json_encode(['job' => $job_now, 'body' => $brevo_body])
);

$final_job_id = CyberEvents_Queue_Runner::push('brevo_doi', ['registration_id' => 99, 'email' => 'x@example.org', 'redirection_url' => rest_url('cybevents/v1/confirm') . '?doi=secret&token=secret']);
$mock_db_tables['wp_cybevents_queue'][$final_job_id]->attempts = 4;
$mock_http_response = ['response' => ['code' => 400], 'body' => json_encode(['message' => 'invalid template'])];
CyberEvents_Queue_Runner::process_batch();
$final_job = $mock_db_tables['wp_cybevents_queue'][$final_job_id];
assert_test(
    'B4 a job that fails for the last time is marked failed and its tokens are dropped from the payload',
    'failed' === $final_job->status && $final_job->payload === json_encode(['registration_id' => 99]) && str_contains((string) $final_job->error_message, 'invalid template'),
    json_encode($final_job)
);

$mock_http_response = ['response' => ['code' => 201], 'body' => ''];
$legacy_job_id = CyberEvents_Queue_Runner::push('brevo_send_doi', ['registration_id' => 5, 'email' => 'legacy@example.org']);
CyberEvents_Queue_Runner::process_batch();
assert_test(
    'B5 jobs queued under the old type brevo_send_doi are processed too',
    'completed' === $mock_db_tables['wp_cybevents_queue'][$legacy_job_id]->status,
    json_encode($mock_db_tables['wp_cybevents_queue'][$legacy_job_id])
);

$mock_http_requests = [];
$claimed_job_id = CyberEvents_Queue_Runner::push('brevo_doi', ['registration_id' => 6, 'email' => 'claimed@example.org']);
// A second runner claims the job between this runner's SELECT and its UPDATE.
$wpdb->on_get_results = static function (string $query) use ($claimed_job_id): void {
    global $mock_db_tables;
    if (str_contains($query, 'cybevents_queue')) {
        $mock_db_tables['wp_cybevents_queue'][$claimed_job_id]->status = 'processing';
        $mock_db_tables['wp_cybevents_queue'][$claimed_job_id]->processed_at = gmdate('Y-m-d H:i:s');
    }
};
$processed = CyberEvents_Queue_Runner::process_batch();
$wpdb->on_get_results = null;
assert_test(
    'B6 a job another runner claimed in between is skipped: no second mail',
    $processed === 0 && count($mock_http_requests) === 0 && (int) $mock_db_tables['wp_cybevents_queue'][$claimed_job_id]->attempts === 0,
    'requests ' . count($mock_http_requests) . ', job ' . json_encode($mock_db_tables['wp_cybevents_queue'][$claimed_job_id])
);

$stale_job_id = CyberEvents_Queue_Runner::push('brevo_doi', ['registration_id' => 7, 'email' => 'stale@example.org']);
$mock_db_tables['wp_cybevents_queue'][$stale_job_id]->status = 'processing';
$mock_db_tables['wp_cybevents_queue'][$stale_job_id]->processed_at = gmdate('Y-m-d H:i:s', time() - 1200);
$mock_db_tables['wp_cybevents_queue'][$claimed_job_id]->processed_at = gmdate('Y-m-d H:i:s', time() - 60);
CyberEvents_Queue_Runner::process_batch();
assert_test(
    'B7 a job left in processing for 20 minutes is handed back and sent; one claimed a minute ago is left alone',
    'completed' === $mock_db_tables['wp_cybevents_queue'][$stale_job_id]->status && 'processing' === $mock_db_tables['wp_cybevents_queue'][$claimed_job_id]->status,
    json_encode([$mock_db_tables['wp_cybevents_queue'][$stale_job_id], $mock_db_tables['wp_cybevents_queue'][$claimed_job_id]])
);

// ============================================================
// Group C2 — confirmation link
// ============================================================
$wpdb->insert('wp_posts', ['ID' => 42, 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '<!-- wp:shortcode -->[cybevents_session_selector]<!-- /wp:shortcode -->']);
$target      = CyberEvents_REST_API::confirmation_target((string) $link_args['doi'], (string) $link_args['token']);
$target_args = query_args($target);
$confirmed   = registration((int) $new_reg->id);
assert_test(
    'C2a the link confirms the registration, spends the DOI token and leads to the session selector page with the participant token',
    str_starts_with($target, get_permalink(42)) && ($target_args['cybevents_doi'] ?? '') === 'confirmed' && ($target_args['token'] ?? '') === $link_args['token']
        && 'confirmed' === $confirmed->status && null === $confirmed->doi_token_hash && !empty($confirmed->doi_confirmed_at),
    $target . ' ' . json_encode($confirmed)
);

$second_target = CyberEvents_REST_API::confirmation_target((string) $link_args['doi'], (string) $link_args['token']);
assert_test(
    'C2b the same link a second time answers invalid and carries no token',
    (query_args($second_target)['cybevents_doi'] ?? '') === 'invalid' && !isset(query_args($second_target)['token']),
    $second_target
);

$other_pending = seed_registration($event_id, 'pending_doi');
$foreign_target = CyberEvents_REST_API::confirmation_target($other_pending['doi'], $link_args['token']);
$cancelled = seed_registration($event_id, 'pending_doi');
$mock_db_tables['wp_cybevents_registrations'][$cancelled['id']]->status = 'cancelled';
$cancelled_target = CyberEvents_REST_API::confirmation_target($cancelled['doi'], $cancelled['token']);
assert_test(
    'C2c a participant token of another registration is not passed on, and a cancelled registration is not confirmed',
    (query_args($foreign_target)['cybevents_doi'] ?? '') === 'confirmed' && !isset(query_args($foreign_target)['token'])
        && (query_args($cancelled_target)['cybevents_doi'] ?? '') === 'invalid' && 'cancelled' === registration($cancelled['id'])->status,
    $foreign_target . ' | ' . $cancelled_target
);

CyberEvents_REST_API::register_routes();
$confirm_route = $mock_rest_routes['cybevents/v1/confirm'] ?? [];
assert_test(
    'C2d GET /cybevents/v1/confirm is registered and redirects without caching',
    ($confirm_route['methods'] ?? '') === 'GET' && ($confirm_route['callback'] ?? null) === ['CyberEvents_REST_API', 'handle_confirm']
        && str_contains(function_body('includes/class-rest-api.php', 'handle_confirm'), 'nocache_headers()')
        && str_contains(function_body('includes/class-rest-api.php', 'handle_confirm'), 'wp_safe_redirect('),
    json_encode($confirm_route)
);

// ============================================================
// Group C3 — contract between the front-end script and the REST routes
// ============================================================
$js_source = source_of('public/js/cybevents-public.js');
preg_match_all('/config\.root\s*\}?\s*\+?\s*[\'"`]?\/([a-z-]+)/', $js_source, $js_routes);
$missing_routes = array_values(array_filter(array_unique($js_routes[1]), static fn($route) => !isset($mock_rest_routes['cybevents/v1/' . $route])));
assert_test(
    'C3a the script root is rest_url(cybevents/v1) and every route the script calls is registered under it',
    str_contains($main_source, "'root'  => esc_url_raw( rest_url( 'cybevents/v1' ) )") && count($js_routes[1]) >= 3 && $missing_routes === [],
    'script routes ' . json_encode($js_routes[1]) . ', missing ' . json_encode($missing_routes)
);

$selection_session = seed_session($event_id, 10);
CyberEvents_Database::book_session_atomic($selection_session, (int) $new_reg->id);
$selection = CyberEvents_REST_API::handle_get_session_selection(rest_request(['token' => $link_args['token']]));
$selection_data = $selection->get_data();
$still_pending = seed_registration($event_id, 'pending_doi');
$pending_selection = CyberEvents_REST_API::handle_get_session_selection(rest_request(['token' => $still_pending['token']]));
$selection_sql = function_body('includes/class-rest-api.php', 'handle_get_session_selection');
assert_test(
    'C3b session selection returns the fields the script reads (success, participant names, booked_sessions, verfuegbar) and refuses unconfirmed registrations',
    $selection->get_status() === 200 && true === ($selection_data['success'] ?? null)
        && ($selection_data['participant']['first_name'] ?? '') === 'Erika' && ($selection_data['participant']['last_name'] ?? '') === 'Muster'
        && ($selection_data['booked_sessions'] ?? null) === [$selection_session]
        && !isset($selection_data['participant']['email'])
        && str_contains($selection_sql, 'AS verfuegbar')
        && str_contains($js_source, 'data.booked_sessions') && str_contains($js_source, 'participant.first_name') && str_contains($js_source, 's.verfuegbar')
        && $pending_selection->get_status() === 403,
    json_encode($selection_data) . ' pending ' . $pending_selection->get_status()
);

// ============================================================
// Group C4 — check-in
// ============================================================
$checkin_reg = seed_registration($event_id, 'confirmed', ['email' => 'checkin.person@example.org']);
$first_scan  = CyberEvents_REST_API::handle_checkin(rest_request(['token' => $checkin_reg['token']]));
$second_scan = CyberEvents_REST_API::handle_checkin(rest_request(['token' => $checkin_reg['token']]));
assert_test(
    'C4a the first scan checks in (200), the second answers 409, and the audit entry has no address',
    $first_scan->get_status() === 200 && $second_scan->get_status() === 409 && 1 === (int) registration($checkin_reg['id'])->checked_in
        && count(audit_details_containing('checkin.person@example.org')) === 0,
    $first_scan->get_status() . '/' . $second_scan->get_status()
);

$pending_scan = CyberEvents_REST_API::handle_checkin(rest_request(['token' => $still_pending['token']]));
assert_test(
    'C4b an unconfirmed registration is refused at the door (403) and not checked in',
    $pending_scan->get_status() === 403 && 0 === (int) registration($still_pending['id'])->checked_in,
    'status ' . $pending_scan->get_status()
);

$race_reg = seed_registration($event_id, 'confirmed');
// A second scanner checks the person in after this request read the row and before it writes.
$wpdb->before_update = static function (string $table, array $data) use ($race_reg): void {
    global $mock_db_tables;
    if ('wp_cybevents_registrations' === $table && isset($data['checked_in'])) {
        $mock_db_tables[$table][$race_reg['id']]->checked_in = 1;
    }
};
$race_scan = CyberEvents_REST_API::handle_checkin(rest_request(['token' => $race_reg['token']]));
$wpdb->before_update = null;
assert_test(
    'C4c of two simultaneous scans only one succeeds: the check-in is decided in the UPDATE (409 for the loser)',
    $race_scan->get_status() === 409,
    'status ' . $race_scan->get_status()
);

// ============================================================
// Group C5 — booking sessions
// ============================================================
reset_state();
$event_id  = seed_event();
$s1        = seed_session($event_id, 10);
$s2        = seed_session($event_id, 10);
$s3        = seed_session($event_id, 10);
$full      = seed_session($event_id, 1, 1);
$foreign   = seed_session(seed_event(['title' => 'Andere', 'slug' => 'andere']), 10);
$pending   = seed_registration($event_id, 'pending_doi');
$attendee  = seed_registration($event_id, 'confirmed');

$pending_booking = CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $pending['token'], 'session_ids' => [$s1]]));
assert_test(
    'C5a an unconfirmed registration cannot book (403) and takes no seat',
    $pending_booking->get_status() === 403 && session_taken($s1) === 0,
    'status ' . $pending_booking->get_status() . ', taken ' . session_taken($s1)
);

$booking = CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $attendee['token'], 'session_ids' => [$s1, $s2]]));
assert_test(
    'C5b a confirmed registration books two sessions',
    $booking->get_status() === 200 && session_taken($s1) === 1 && session_taken($s2) === 1,
    'status ' . $booking->get_status()
);

$swap = CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $attendee['token'], 'session_ids' => [$s3, $full]]));
$mapping = array_map(static fn($row) => (int) $row->session_id, array_values($mock_db_tables['wp_cybevents_registration_sessions']));
sort($mapping);
assert_test(
    'C5c a swap that fails on a full session changes nothing: the seat taken in the request is released and the old bookings stay',
    $swap->get_status() === 409 && session_taken($s1) === 1 && session_taken($s2) === 1 && session_taken($s3) === 0 && session_taken($full) === 1 && $mapping === [$s1, $s2],
    'status ' . $swap->get_status() . ', mapping ' . json_encode($mapping) . ', taken s3 ' . session_taken($s3)
);

$foreign_booking = CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $attendee['token'], 'session_ids' => [$s1, $foreign]]));
assert_test(
    'C5d a session of another event is refused (400)',
    $foreign_booking->get_status() === 400 && session_taken($foreign) === 0,
    'status ' . $foreign_booking->get_status()
);

// ============================================================
// Group C6 — ticket and webhook
// ============================================================
$ticket = CyberEvents_REST_API::handle_get_ticket(rest_request(['token' => $attendee['token']]));
$no_location_event = seed_event(['location' => '', 'slug' => 'ohne-ort']);
$no_location_reg   = seed_registration($no_location_event, 'confirmed');
$ticket_no_location = CyberEvents_REST_API::handle_get_ticket(rest_request(['token' => $no_location_reg['token']]));
$pending_ticket = CyberEvents_REST_API::handle_get_ticket(rest_request(['token' => $pending['token']]));
assert_test(
    'C6a the ticket is never stored by a shared cache, shows the event location (empty when there is none) and is refused for unconfirmed registrations',
    $ticket instanceof WP_REST_Response && ($ticket->headers['Cache-Control'] ?? '') === 'private, no-store, max-age=0'
        && ($ticket->get_data()['event_location'] ?? null) === 'Saal 1'
        && $ticket_no_location instanceof WP_REST_Response && ($ticket_no_location->get_data()['event_location'] ?? null) === ''
        && is_wp_error($pending_ticket) && 404 === (int) ($pending_ticket->data['status'] ?? 0),
    json_encode($ticket instanceof WP_REST_Response ? $ticket->headers : $ticket)
);

$bounce_reg = seed_registration($event_id, 'confirmed', ['email' => 'bounce.person@example.org']);
CyberEvents_Database::book_session_atomic($s3, $bounce_reg['id']);
set_settings(['brevo_webhook_secret' => 'wh_secret']);
$bounce_req = rest_request([], ['X-Brevo-Webhook-Secret' => 'wh_secret']);
$bounce_req->set_body(json_encode(['event' => 'hard_bounce', 'email' => 'bounce.person@example.org']));
$bounce = CyberEvents_REST_API::handle_brevo_webhook($bounce_req);
assert_test(
    'C6b a hard bounce marks the registration bounced, releases its seat, and the audit entry has no address',
    $bounce->get_status() === 200 && 'bounced' === registration($bounce_reg['id'])->status && session_taken($s3) === 0
        && count(audit_details_containing('bounce.person@example.org')) === 0,
    json_encode(registration($bounce_reg['id']))
);

// ============================================================
// Group D — privacy: eraser, exporter, retention, audit pseudonymisation
// ============================================================
reset_state();
$event_id = seed_event();
$seat     = seed_session($event_id, 5);
$erase    = seed_registration($event_id, 'confirmed', ['email' => 'lena.beispiel@example.org']);
CyberEvents_Database::book_session_atomic($seat, $erase['id']);
// Entries written before 2026-09-15 copied the address into the details.
CyberEvents_Database::log_audit('registration_created', $erase['id'], 0, wp_json_encode(['email' => 'lena.beispiel@example.org']));
CyberEvents_Database::log_audit('registration_created', 99, 0, wp_json_encode(['email' => 'someone.else@example.org']));
$erase_job = CyberEvents_Queue_Runner::push('brevo_update_attributes', ['email' => 'lena.beispiel@example.org', 'attributes' => ['EVENT_STATUS' => 'X']]);
$erased = CyberEvents_GDPR_Manager::erase_personal_data('lena.beispiel@example.org');
$chain  = CyberEvents_Database::verify_audit_chain();
assert_test(
    'D1 the eraser deletes the registration, releases its seat, pseudonymises audit entries naming the address, drops queued mail and keeps the chain valid',
    null === registration($erase['id']) && session_taken($seat) === 0 && count($mock_db_tables['wp_cybevents_registration_sessions']) === 0
        && count(audit_details_containing('lena.beispiel@example.org')) === 0 && count(audit_details_containing('someone.else@example.org')) === 1
        && !str_contains((string) $mock_db_tables['wp_cybevents_queue'][$erase_job]->payload, 'lena.beispiel')
        && true === $erased['items_removed'] && false === $erased['items_retained'] && !empty($chain['valid']) && $chain['pseudonymized'] === 1,
    json_encode(['erased' => $erased, 'chain' => $chain])
);

$invoiced = seed_registration($event_id, 'confirmed', ['email' => 'rechnung.person@example.org', 'invoice_number' => 'CE-2026-01001']);
$erased_invoiced = CyberEvents_GDPR_Manager::erase_personal_data('rechnung.person@example.org');
$invoiced_row    = registration($invoiced['id']);
preg_match('/cybevents_registrations \((.*?)\) \$charset_collate/s', $activator_source, $reg_schema);
preg_match_all('/^\s*(\w+)\s+(?:bigint|int|tinyint|varchar|longtext|text|datetime|decimal)\b/m', $reg_schema[1] ?? '', $reg_columns);
preg_match("/cybevents_registrations\",\s*array\((.*?)\),\s*array\( 'id'/s", function_body('includes/class-gdpr-manager.php', 'erase_personal_data'), $anon_update);
preg_match_all("/'(\w+)'\s*=>/", $anon_update[1] ?? '', $anon_columns);
assert_test(
    'D2 a registration with an invoice is anonymised through columns that exist (until 2026-09-15 a missing column made it fail silently)',
    $invoiced_row && 'anonymized-' . $invoiced['id'] . '@privacy.local' === $invoiced_row->email && 'cancelled' === $invoiced_row->status && '' === $invoiced_row->token_hash
        && true === $erased_invoiced['items_removed'] && true === $erased_invoiced['items_retained'] && count($erased_invoiced['messages']) === 1
        && count($anon_columns[1]) >= 5 && array_diff($anon_columns[1], $reg_columns[1]) === [],
    json_encode(['row' => $invoiced_row, 'unknown' => array_values(array_diff($anon_columns[1], $reg_columns[1]))])
);

$export_reg = seed_registration($event_id, 'confirmed', ['email' => 'export.person@example.org']);
$wpdb->insert('wp_cybevents_invoices', ['registration_id' => $export_reg['id'], 'event_id' => $event_id, 'invoice_number' => 'CE-2026-02000', 'recipient_name' => 'Export Person', 'recipient_email' => 'export.person@example.org', 'gross_amount' => 119.0, 'currency' => 'EUR', 'issued_at' => '2026-09-01 10:00:00', 'due_date' => '2026-09-15 10:00:00']);
$export = CyberEvents_GDPR_Manager::export_personal_data('export.person@example.org');
$export_groups = array_column($export['data'], 'group_id');
$export_fields = array_column($export['data'][0]['data'] ?? [], 'name');
$exporter_body = function_body('includes/class-gdpr-manager.php', 'export_personal_data');
assert_test(
    'D3 the exporter joins the plugin events table (not wp_posts) and exports consents, sessions and invoices',
    str_contains($exporter_body, 'LEFT JOIN {$prefix}cybevents_events e ON r.event_id = e.id') && !str_contains($exporter_body, '->posts')
        && in_array('cybevents_registrations', $export_groups, true) && in_array('cybevents_invoices', $export_groups, true)
        && in_array('Einwilligung Datenschutz', $export_fields, true) && in_array('Gebuchte Sessions', $export_fields, true),
    json_encode(['groups' => $export_groups, 'fields' => $export_fields])
);

reset_state();
$event_id  = seed_event();
$old_seat  = seed_session($event_id, 5);
$GLOBALS['mock_now'] = time() - 20 * DAY_IN_SECONDS;
$expired   = seed_registration($event_id, 'pending_doi');
$old_conf  = seed_registration($event_id, 'confirmed');
$GLOBALS['mock_now'] = null;
CyberEvents_Database::book_session_atomic($old_seat, $expired['id']);
$recent    = seed_registration($event_id, 'pending_doi');
$purged    = CyberEvents_GDPR_Manager::purge_expired_pending_doi(14);
assert_test(
    'D4 unconfirmed registrations older than 14 days are deleted with their seats; recent and confirmed ones stay',
    $purged === 1 && null === registration($expired['id']) && session_taken($old_seat) === 0 && null !== registration($recent['id']) && null !== registration($old_conf['id']),
    "purged {$purged}, seat " . session_taken($old_seat)
);

reset_state();
$GLOBALS['mock_now'] = time() - 100 * DAY_IN_SECONDS;
CyberEvents_Database::log_audit('session_booked', 7, 1, '{"session_id":3}');
CyberEvents_Database::log_audit('invoice_created', 8, 1, '{"invoice":"CE-2026-01001"}');
$GLOBALS['mock_now'] = null;
CyberEvents_Database::log_audit('checkin_completed', 9, 1, '{"time":"now"}');
$rotated = CyberEvents_GDPR_Manager::rotate_audit_logs(90);
$audit_rows = array_values($mock_db_tables['wp_cybevents_audit_log']);
$chain = CyberEvents_Database::verify_audit_chain();
assert_test(
    'D5a entries older than 90 days are pseudonymised with a residual MAC, invoice entries are kept, the chain verifies',
    $rotated === 1 && '' === $audit_rows[0]->details && 0 === (int) $audit_rows[0]->user_id && 1 === preg_match('/^[0-9a-f]{64}$/', (string) $audit_rows[0]->residual_mac)
        && '' !== $audit_rows[1]->details && !empty($chain['valid']) && $chain['pseudonymized'] === 1 && $chain['checked'] === 2,
    json_encode(['rotated' => $rotated, 'chain' => $chain])
);

$pseudo_id = (int) $audit_rows[0]->id;
$mock_db_tables['wp_cybevents_audit_log'][$pseudo_id]->object_id = 777;
$edited_pseudonymised = CyberEvents_Database::verify_audit_chain();
$mock_db_tables['wp_cybevents_audit_log'][$pseudo_id]->object_id = 7;
$saved_mac = $mock_db_tables['wp_cybevents_audit_log'][$pseudo_id]->residual_mac;
$mock_db_tables['wp_cybevents_audit_log'][$pseudo_id]->residual_mac = '';
$missing_mac = CyberEvents_Database::verify_audit_chain();
$mock_db_tables['wp_cybevents_audit_log'][$pseudo_id]->residual_mac = $saved_mac;
$recent_id = (int) $audit_rows[2]->id;
// The bypass closed on 2026-09-15: edit an entry and mark it pseudonymised to skip the content check.
$mock_db_tables['wp_cybevents_audit_log'][$recent_id]->details = '{"time":"forged"}';
$mock_db_tables['wp_cybevents_audit_log'][$recent_id]->pseudonymized_at = date('Y-m-d H:i:s');
$forged = CyberEvents_Database::verify_audit_chain();
assert_test(
    'D5b verification reports an edited pseudonymised entry, a missing residual MAC, and an edited entry marked as pseudonymised',
    empty($edited_pseudonymised['valid']) && $edited_pseudonymised['broken_at_id'] === $pseudo_id
        && empty($missing_mac['valid']) && $missing_mac['broken_at_id'] === $pseudo_id
        && empty($forged['valid']) && $forged['broken_at_id'] === $recent_id,
    json_encode([$edited_pseudonymised, $missing_mac, $forged])
);

reset_state();
$event_id = seed_event();
$seat     = seed_session($event_id, 5);
$canceller = seed_registration($event_id, 'confirmed', ['email' => 'storno.person@example.org']);
CyberEvents_Database::book_session_atomic($seat, $canceller['id']);
$cancel_result = CyberEvents_GDPR_Manager::cancel_registration_self_service($canceller['token']);
assert_test(
    'D6 self-service cancellation releases the seat, cancels the registration, and the audit entry has no address',
    true === $cancel_result && 'cancelled' === registration($canceller['id'])->status && session_taken($seat) === 0
        && count(audit_details_containing('storno.person@example.org')) === 0,
    json_encode(registration($canceller['id']))
);

$uploads = wp_upload_dir();
@mkdir($uploads['basedir'] . '/cybevents-avatars', 0777, true);
$inside  = $uploads['basedir'] . '/cybevents-avatars/avatar_Abc123.jpg';
$guard   = $uploads['basedir'] . '/cybevents-avatars/index.php';
$outside = $uploads['basedir'] . '/keep-me.jpg';
file_put_contents($inside, 'x');
file_put_contents($guard, '<?php // Silence is golden');
file_put_contents($outside, 'x');
CyberEvents_GDPR_Manager::delete_avatar_file($uploads['baseurl'] . '/cybevents-avatars/../keep-me.jpg');
CyberEvents_GDPR_Manager::delete_avatar_file($uploads['baseurl'] . '/cybevents-avatars/index.php');
CyberEvents_GDPR_Manager::delete_avatar_file('https://elsewhere.example/wp-content/uploads/cybevents-avatars/avatar_Abc123.jpg');
$others_kept = is_file($outside) && is_file($inside) && is_file($guard);
CyberEvents_GDPR_Manager::delete_avatar_file($uploads['baseurl'] . '/cybevents-avatars/avatar_Abc123.jpg');
assert_test(
    'D7 avatar deletion removes only avatar_*.jpg|png|webp files inside uploads/cybevents-avatars',
    $others_kept && !is_file($inside) && is_file($outside) && is_file($guard),
    'others kept before: ' . var_export($others_kept, true)
);
@unlink($outside);
@unlink($guard);

// ============================================================
// Group E — health reported to the Suite Hub
// ============================================================
reset_state();
set_settings(['brevo_api_key' => '', 'brevo_webhook_secret' => '']);
$adapter = new CyberEvents_Spoke_Adapter();
$health_unconfigured = $adapter->get_health_status();
set_settings(['brevo_api_key' => 'xkeysib-test', 'brevo_webhook_secret' => 'wh_secret']);
$health_unscheduled = $adapter->get_health_status();
add_filter('cron_schedules', ['CyberEvents_Activator', 'register_cron_schedules']);
CyberEvents_Activator::ensure_cron_events();
$health_ok = $adapter->get_health_status();
$failed_job = CyberEvents_Queue_Runner::push('brevo_doi', ['registration_id' => 1]);
$mock_db_tables['wp_cybevents_queue'][$failed_job]->status = 'failed';
$health_failed_job = $adapter->get_health_status();
unset($mock_db_tables['wp_cybevents_queue'][$failed_job]);
CyberEvents_Database::log_audit('test_entry', 1, 1, '{"a":1}');
$first_audit = array_key_first($mock_db_tables['wp_cybevents_audit_log']);
$mock_db_tables['wp_cybevents_audit_log'][$first_audit]->details = '{"a":2}';
$health_tampered = $adapter->get_health_status();
$metrics = $adapter->get_telemetry_metrics();
assert_test(
    'E1 health is NEEDS_CONFIG without Brevo, DEGRADED without the queue event or with failed jobs, HEALTHY when all is set, ERROR with a broken audit chain',
    'NEEDS_CONFIG' === $health_unconfigured && 'DEGRADED' === $health_unscheduled && 'HEALTHY' === $health_ok && 'DEGRADED' === $health_failed_job && 'ERROR' === $health_tampered,
    json_encode([$health_unconfigured, $health_unscheduled, $health_ok, $health_failed_job, $health_tampered])
);
assert_test(
    'E2 telemetry reports measured values only (no fixed status literals) and the SBOM names no standards the code does not establish',
    true === $metrics['queue_event_scheduled'] && true === $metrics['brevo_configured'] && !in_array('INNODB_ROW_LOCKS_ACTIVE', $metrics, true) && !in_array('ZUGFeRD_2.3_READY', $metrics, true)
        && !str_contains(json_encode($adapter->get_sbom_data()), 'WCAG 2.2 AA') && !str_contains(json_encode($adapter->get_sbom_data()), 'ZUGFeRD 2.3'),
    json_encode($metrics)
);
// The header and readme.txt went to GPLv2 or later on 2026-09-16 (95b48ee) and the adapter did not follow:
// it kept the scaffold's `Proprietary`, with an id and a name, which CycloneDX 1.6 does not accept.
$sbom_licenses = $adapter->get_sbom_data()['licenses'] ?? null;
assert_test(
    'E3 the SBOM declares the licence the plugin ships under, as an SPDX id alone (Proprietary, with an id and a name, until 2026-11-05)',
    [['license' => ['id' => 'GPL-2.0-or-later']]] === $sbom_licenses,
    (string) json_encode($sbom_licenses)
);

// ============================================================
// Group F — CSV import
// ============================================================
reset_state();
$event_id = seed_event();
$import = CyberEvents_CSV_Importer::import_csv($event_id, "email;vorname;nachname;firma\nimport.person@example.org;Max;Beispiel;Beispiel GmbH");
$imported = array_values($mock_db_tables['wp_cybevents_registrations'])[0] ?? null;
assert_test(
    'F1 an imported participant is admitted but records no privacy consent and no double opt-in',
    $import['imported'] === 1 && $imported && 'confirmed' === $imported->status && 0 === (int) $imported->consent_privacy && empty($imported->doi_confirmed_at),
    json_encode($imported)
);

// RFC 4180: a backslash before the closing quote is an ordinary character. With PHP's implicit
// backslash escape the field swallows the rest of the line and the last name and company are lost.
CyberEvents_CSV_Importer::import_csv($event_id, "email;vorname;nachname;firma\n" . 'backslash@example.org;"Max\\";Beispiel;Firma');
$backslash_row = array_values(array_filter($mock_db_tables['wp_cybevents_registrations'], static fn($r) => 'backslash@example.org' === $r->email))[0] ?? null;
assert_test(
    'F2 a quoted field ending in a backslash does not swallow the following fields',
    $backslash_row && 'Beispiel' === $backslash_row->last_name && 'Firma' === $backslash_row->company,
    json_encode($backslash_row)
);

// ============================================================
// Group G — admin screens, ticket block, captcha
// ============================================================
$all_php = '';
foreach (glob(ABSPATH . 'includes/*.php') as $file) {
    $all_php .= file_get_contents($file);
}
$all_php .= $main_source;
preg_match_all("/log_audit\(\s*'[a-z_]+',\s*get_current_user_id\(\)/", $all_php, $swapped_audit_args);
assert_test(
    'G1 no log_audit() call passes the user id in the object id position',
    count($swapped_audit_args[0]) === 0,
    json_encode($swapped_audit_args[0])
);

preg_match_all("/'(cybevents-[a-z0-9-]+)'/", function_body('includes/class-admin.php', 'register_admin_menus'), $menu_slugs);
preg_match_all('/admin\.php\?page=([a-z0-9-]+)/', $all_php, $linked_slugs);
$dead_links = array_values(array_diff(array_unique($linked_slugs[1]), $menu_slugs[1]));
assert_test(
    'G2 every admin.php?page= link names a registered admin page (the DATEV links pointed to cybevents-datev)',
    count($menu_slugs[1]) >= 5 && $dead_links === [],
    json_encode($dead_links)
);

$settings_body = function_body('includes/class-admin.php', 'render_settings_page');
assert_test(
    'G3 the settings save is skipped for the demo and reset buttons and keeps the invoice counter and account length it has no field for',
    str_contains($settings_body, '! $is_demo_or_reset && isset( $_POST[\'cybevents_settings_nonce\'] )')
        && str_contains($settings_body, "absint( \$previous['invoice_counter'] ?? 1000 )")
        && str_contains($settings_body, "\$previous['datev_sachkontenlaenge'] ?? '4'"),
    'settings save guard or preserved values missing'
);

$registrations_body = function_body('includes/class-admin.php', 'render_registrations_page');
$unset_pos = strpos($registrations_body, 'unset( $r->token_hash, $r->doi_token_hash );');
$json_pos  = strpos($registrations_body, 'wp_json_encode( $r )');
$confirm_block = substr(source_of('includes/class-admin.php'), (int) strpos(source_of('includes/class-admin.php'), "'confirm_doi' === \$_GET['action']"), 1500);
assert_test(
    'G4 the registrations list strips token hashes before embedding a row as JSON, and the manual DOI confirmation only confirms pending registrations and spends the token',
    false !== $unset_pos && false !== $json_pos && $unset_pos < $json_pos
        && str_contains($confirm_block, "'doi_token_hash'   => null") && (bool) preg_match("/'status'\s*=>\s*'pending_doi'/", $confirm_block),
    "unset at {$unset_pos}, json at {$json_pos}"
);

reset_state();
$event_id = seed_event(['title' => 'Sommerforum', 'location' => 'Saal 1']);
$pass_pending = seed_registration($event_id, 'pending_doi');
$pass_ok      = seed_registration($event_id, 'confirmed', ['first_name' => 'Jana', 'last_name' => 'Beispiel']);
$_GET['token'] = $pass_pending['token'];
$pending_pass_html = CyberEvents_Blocks_Manager::render_ticket_pass_verifier_block([]);
$_GET['token'] = $pass_ok['token'];
$pass_html = CyberEvents_Blocks_Manager::render_ticket_pass_verifier_block([]);
$_GET = [];
$mock_is_admin = true;
$preview_html = CyberEvents_Blocks_Manager::render_ticket_pass_verifier_block([]);
$mock_is_admin = false;
assert_test(
    'G5 the ticket pass is shown for confirmed registrations only, with the event title, location and ticket number, and no client names or a QR image that encodes nothing',
    str_contains($pending_pass_html, 'keinen Teilnehmerpass') && !str_contains($pending_pass_html, 'cybevents-digital-pass')
        && str_contains($pass_html, 'Jana Beispiel') && str_contains($pass_html, 'Sommerforum') && str_contains($pass_html, 'Saal 1') && str_contains($pass_html, '#' . $pass_ok['id'])
        && str_contains($preview_html, 'Max Mustermann')
        && !str_contains($pass_html . $preview_html, 'Maizière') && !str_contains($pass_html . $preview_html, '<svg class="cybevents-qr')
        && !str_contains(function_body('includes/class-blocks-manager.php', 'render_ticket_pass_verifier_block'), 'EPC_QR'),
    substr($pass_html, 0, 400)
);

$save_reg_block = substr(source_of('includes/class-admin.php'), (int) strpos(source_of('includes/class-admin.php'), '// 5. Handle Save Registration'), 4000);
$status_select  = substr($registrations_body, (int) strpos($registrations_body, 'id="edit_reg_status"'), 900);
assert_test(
    'G7 saving the attendee modal keeps a status or payment status it does not recognise (a bounced registration was saved as confirmed, an unknown payment status as free) and the modal offers "bounced"',
    (bool) preg_match("/in_array\(\s*\\\$posted_status,\s*array\([^)]*'bounced'[^)]*\),\s*true\s*\)\s*\?\s*\\\$posted_status\s*:\s*\(string\)\s*\\\$old_reg->status/", $save_reg_block)
        && (bool) preg_match("/\?\s*\\\$posted_payment\s*:\s*\(string\)\s*\\\$old_reg->payment_status/", $save_reg_block)
        && !preg_match("/\?\s*\\\$_POST\['status'\]\s*:\s*'confirmed'/", $save_reg_block)
        && str_contains($status_select, '<option value="bounced">'),
    'status handling of the attendee modal'
);

assert_test(
    'G6 the captcha script is only enqueued when a widget exists',
    str_contains(function_body('wm-cyber-events-core.php', 'cybevents_enqueue_frontend_assets'), "! empty( \$settings['enable_friendly_captcha'] ) && CyberEvents_Accessibility::captcha_widget_available()"),
    'enqueue condition changed'
);

// ============================================================
// Group H — invoices, credit notes, numbering, DATEV, iCalendar
// ============================================================
function cii_dom(string $xml): ?DOMXPath {
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return null;
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
    $xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    $xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
    $xp->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
    return $xp;
}

function cii_value(DOMXPath $xp, string $path): string {
    $nodes = $xp->query($path);
    return $nodes && $nodes->length ? trim($nodes->item(0)->textContent) : '';
}

$test_seller = [
    'seller_name'       => 'Beispiel Veranstaltungen GmbH',
    'seller_street'     => 'Hauptstraße 5',
    'seller_postcode'   => '10115',
    'seller_city'       => 'Berlin',
    'seller_country'    => 'DE',
    'seller_vat_id'     => 'DE123456789',
    'seller_tax_number' => '',
    'seller_email'      => 'rechnung@example.org',
    'seller_iban'       => 'DE89370400440532013000',
];

reset_state();
unset($mock_options[CyberEvents_Database::INVOICE_SEQUENCE_OPTION]);
set_settings(array_fill_keys(array_keys($test_seller), '') + ['invoice_prefix' => 'CE-2026-', 'invoice_counter' => 1000]);
$event_id = seed_event(['title' => 'Sommerforum', 'start_date' => '2026-10-15 09:00:00']);
$no_seller = CyberEvents_Invoicing::create_invoice(['event_id' => $event_id, 'recipient_name' => 'Erika Muster', 'net_amount' => 45.50, 'tax_rate' => 19]);
assert_test(
    'H1 without seller data no invoice is created and no number is drawn (until 2026-09-15 a client was the seller of every invoice)',
    is_wp_error($no_seller) && 'cybevents_seller_incomplete' === $no_seller->get_error_code() && count($mock_db_tables['wp_cybevents_invoices']) === 0
        && !isset($mock_options[CyberEvents_Database::INVOICE_SEQUENCE_OPTION]),
    var_export($no_seller, true)
);

set_settings($test_seller);
$invoice_id  = CyberEvents_Invoicing::create_invoice(['registration_id' => 7, 'event_id' => $event_id, 'recipient_name' => 'Erika Muster', 'recipient_company' => 'Muster & <Söhne> AG', 'recipient_email' => 'erika@example.org', 'recipient_address' => '', 'net_amount' => 45.50, 'tax_rate' => 19, 'tax_amount' => 8.645, 'gross_amount' => 54.145]);
$invoice_row = $mock_db_tables['wp_cybevents_invoices'][$invoice_id] ?? null;
$xp = $invoice_row ? cii_dom((string) $invoice_row->zugferd_xml) : null;
$sum = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
assert_test(
    'H2 invoice amounts are computed in cents: net 45.50 at 19 % gives tax 8.65 and total 54.15 in row and XML (BR-CO-15; the float version wrote 54.14)',
    $xp && (float) $invoice_row->net_amount === 45.50 && (float) $invoice_row->tax_amount === 8.65 && (float) $invoice_row->gross_amount === 54.15
        && cii_value($xp, $sum . 'ram:TaxBasisTotalAmount') === '45.50' && cii_value($xp, $sum . 'ram:TaxTotalAmount') === '8.65'
        && cii_value($xp, $sum . 'ram:GrandTotalAmount') === '54.15' && cii_value($xp, $sum . 'ram:DuePayableAmount') === '54.15',
    json_encode($invoice_row)
);

$transaction_children = [];
if ($xp) {
    foreach ($xp->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/*') as $node) {
        $transaction_children[] = $node->localName;
    }
}
$xml_text = (string) ($invoice_row->zugferd_xml ?? '');
assert_test(
    'H3 invoice XML: EN 16931 guideline ID, type 380, delivery block in schema order with the event date, seller from the settings, escaped buyer, SEPA account; none of the old hardcoded values',
    $xp && cii_value($xp, '//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID') === 'urn:cen.eu:en16931:2017'
        && cii_value($xp, '//rsm:ExchangedDocument/ram:TypeCode') === '380'
        && $transaction_children === ['IncludedSupplyChainTradeLineItem', 'ApplicableHeaderTradeAgreement', 'ApplicableHeaderTradeDelivery', 'ApplicableHeaderTradeSettlement']
        && cii_value($xp, '//ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString') === '20261015'
        && cii_value($xp, '//ram:SellerTradeParty/ram:Name') === 'Beispiel Veranstaltungen GmbH'
        && cii_value($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID') === 'DE123456789'
        && cii_value($xp, '//ram:BuyerTradeParty/ram:Name') === 'Muster & <Söhne> AG'
        && cii_value($xp, '//ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID') === 'DE89370400440532013000'
        && cii_value($xp, '//ram:SpecifiedTradeProduct/ram:Name') === 'Teilnahme: Sommerforum'
        && !str_contains($xml_text, 'Agentur für Innovation') && !str_contains($xml_text, 'DE334123456') && !str_contains($xml_text, 'Musterstraße 1') && !str_contains($xml_text, 'factur-x.eu:1p0:comfort'),
    json_encode($transaction_children) . ' ' . substr($xml_text, 0, 300)
);

$storno_id  = CyberEvents_Invoicing::cancel_invoice($invoice_id, 'Absage');
$storno_row = $mock_db_tables['wp_cybevents_invoices'][$storno_id] ?? null;
$sxp = $storno_row ? cii_dom((string) $storno_row->zugferd_xml) : null;
assert_test(
    'H4 credit note: own number from the sequence (no "-STORNO" suffix), type 381 with positive amounts and a reference to the original, negative amounts in the row, original cancelled',
    $sxp && 'storno' === $storno_row->invoice_type && (float) $storno_row->gross_amount === -54.15 && 'cancelled' === $mock_db_tables['wp_cybevents_invoices'][$invoice_id]->status
        && !str_contains((string) $storno_row->invoice_number, 'STORNO') && $storno_row->invoice_number !== $invoice_row->invoice_number
        && cii_value($sxp, '//rsm:ExchangedDocument/ram:TypeCode') === '381'
        && cii_value($sxp, $sum . 'ram:GrandTotalAmount') === '54.15'
        && cii_value($sxp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID') === $invoice_row->invoice_number,
    json_encode($storno_row)
);

$number_before_delete = (string) $storno_row->invoice_number;
$mock_db_tables['wp_cybevents_invoices'] = [];
$after_delete = CyberEvents_Database::get_next_invoice_number();
unset($mock_options[CyberEvents_Database::INVOICE_SEQUENCE_OPTION]);
$wpdb->insert('wp_cybevents_invoices', ['invoice_number' => 'CE-2026-01234-STORNO', 'gross_amount' => -1.0]);
$after_legacy = CyberEvents_Database::get_next_invoice_number();
assert_test(
    'H5 numbers are never reused: after deleting every invoice the sequence continues, and a fresh sequence continues after the highest number already issued',
    'CE-2026-01001' === $invoice_row->invoice_number && 'CE-2026-01002' === $number_before_delete && 'CE-2026-01003' === $after_delete && 'CE-2026-01235' === $after_legacy,
    json_encode([$invoice_row->invoice_number, $number_before_delete, $after_delete, $after_legacy])
);

$zero_rate = CyberEvents_Invoicing::create_invoice(['event_id' => $event_id, 'recipient_name' => 'X', 'net_amount' => 10, 'tax_rate' => 0]);
set_settings(['seller_iban' => 'DE89370400440532013001']);
$bad_iban = CyberEvents_Invoicing::seller_problems(CyberEvents_Invoicing::seller());
set_settings(['seller_iban' => $test_seller['seller_iban']]);
assert_test(
    'H6 a zero rate and an IBAN with a wrong check digit are refused',
    is_wp_error($zero_rate) && 'cybevents_tax_rate_unsupported' === $zero_rate->get_error_code() && in_array('IBAN (Prüfziffer)', $bad_iban, true)
        && CyberEvents_Invoicing::iban_is_valid('DE89370400440532013000'),
    json_encode($bad_iban)
);

// DATEV
reset_state();
set_settings($test_seller);
$event_id = seed_event();
$datev_invoice = CyberEvents_Invoicing::create_invoice(['event_id' => $event_id, 'recipient_name' => '=HYPERLINK("http://example.invalid")', 'recipient_company' => 'Müller GmbH', 'net_amount' => 100, 'tax_rate' => 19]);
CyberEvents_Invoicing::cancel_invoice($datev_invoice, 'Absage');
$today = date('Y-m-d');
$datev_csv = CyberEvents_DATEV_Exporter::generate_export($today, $today);
$datev_lines = is_string($datev_csv) ? explode("\r\n", $datev_csv) : [];
$h1 = str_getcsv($datev_lines[0] ?? '', ';', '"', '');
$past_export = CyberEvents_DATEV_Exporter::generate_export('2025-03-01', '2025-03-31');
$h1_past = str_getcsv(is_string($past_export) ? explode("\r\n", $past_export)[0] : '', ';', '"', '');
$booking = str_getcsv($datev_lines[2] ?? '', ';', '"', '');
$credit  = str_getcsv($datev_lines[3] ?? '', ';', '"', '');
assert_test(
    'H7 DATEV header: "Erzeugt am" with 17 digits, fiscal year start from the export period, label within 30 characters and named after the export period (it named a client)',
    'EXTF' === ($h1[0] ?? '') && 1 === preg_match('/^\d{17}$/', (string) ($h1[5] ?? '')) && ($h1[12] ?? '') === substr($today, 0, 4) . '0101'
        && strlen((string) ($h1[16] ?? '')) <= 30 && str_starts_with((string) ($h1[16] ?? ''), 'Rechnungen ') && count($h1) === 31
        && ($h1_past[12] ?? '') === '20250101' && ($h1_past[14] ?? '') === '20250301' && ($h1_past[15] ?? '') === '20250331',
    $datev_lines[0] ?? 'no header'
);
assert_test(
    'H8 DATEV rows: invoice S and credit note H with the same amount, booking text with a neutralised formula character',
    ($booking[0] ?? '') === '119,00' && ($booking[1] ?? '') === 'S' && ($credit[0] ?? '') === '119,00' && ($credit[1] ?? '') === 'H'
        && str_starts_with((string) ($booking[13] ?? ''), "'=HYPERLINK") && mb_strlen((string) $booking[13]) <= 60 && count($booking) === 125,
    json_encode([$booking[0] ?? null, $booking[1] ?? null, $booking[13] ?? null, $credit[1] ?? null])
);
$encoded = CyberEvents_DATEV_Exporter::to_windows_1252("Müller € ß");
$multi_year = CyberEvents_DATEV_Exporter::generate_export('2025-12-01', '2026-01-31');
$bad_date   = CyberEvents_DATEV_Exporter::generate_export('2026-02-30', '2026-03-01');
assert_test(
    'H9 the export is converted to Windows-1252 (the euro sign is byte 0x80; utf8_decode wrote "?") and a period across two years or an invalid date is refused',
    $encoded === "M\xFCller \x80 \xDF" && is_wp_error($multi_year) && is_wp_error($bad_date)
        && str_contains(source_of('includes/class-admin.php'), 'CyberEvents_DATEV_Exporter::to_windows_1252( $csv )') && !str_contains(source_of('includes/class-admin.php'), 'utf8_decode'),
    bin2hex($encoded)
);

// iCalendar
reset_state();
update_option('timezone_string', 'Europe/Berlin');
$ics_event = (object) ['id' => 3, 'title' => 'Sommerforum', 'slug' => 'sommerforum', 'description' => 'Konferenz', 'start_date' => '2026-10-15 09:00:00', 'end_date' => '2026-10-15 18:00:00', 'location' => 'Saal 1, Halle', 'created_at' => '2026-09-01 10:00:00'];
$ics_session = (object) ['id' => 4, 'title' => 'Workshop', 'description' => 'Praxis', 'speaker' => 'Jana Beispiel', 'raum' => 'Raum 2', 'start_time' => '2026-10-15 10:00:00', 'end_time' => '2026-10-15 11:00:00', 'created_at' => '2026-09-01 10:00:00'];
$ics = str_replace("\r\n ", '', CyberEvents_Calendar_Exporter::generate_ics($ics_event, [$ics_session]));
$ics_no_location = CyberEvents_Calendar_Exporter::generate_ics((object) (['location' => ''] + (array) $ics_event), [(object) (['speaker' => '', 'raum' => ''] + (array) $ics_session)]);
assert_test(
    'H10 iCalendar converts site time to UTC (09:00 Berlin in October = 07:00Z; it wrote 09:00Z), escapes the session location once, writes a real line break after the speaker, and invents no location or speaker',
    str_contains($ics, "DTSTART:20261015T070000Z") && str_contains($ics, "DTSTART:20261015T080000Z") && str_contains($ics, 'X-WR-TIMEZONE:Europe/Berlin')
        && str_contains($ics, 'LOCATION:Raum 2 · Saal 1\, Halle') && !str_contains($ics, '\\\\,')
        && str_contains($ics, 'DESCRIPTION:Referent: Jana Beispiel\n\nPraxis') && !str_contains($ics, '\\\\n')
        && !str_contains($ics_no_location, 'LOCATION:') && !str_contains($ics_no_location, 'Referent') && !str_contains($ics_no_location, 'Halle (Saale)'),
    $ics
);

$cal_event_id = seed_event(['title' => 'Sommerforum', 'start_date' => '2026-10-15 09:00:00', 'end_date' => '2026-10-15 18:00:00', 'location' => '']);
$cal_html = CyberEvents_Blocks_Manager::render_calendar_widget_block(['eventId' => $cal_event_id]);
update_option('timezone_string', '');
assert_test(
    'H11 the calendar block links carry UTC times and no invented location (they named a client venue)',
    str_contains($cal_html, 'dates=20261015T070000Z/20261015T160000Z') && str_contains($cal_html, rawurlencode('2026-10-15T07:00:00+00:00')) && !str_contains($cal_html, 'Halle (Saale)'),
    substr($cal_html, 0, 600)
);

$dashboard = function_body('includes/class-admin.php', 'render_dashboard_page');
assert_test(
    'H12 dashboard counts and sums only standard invoices (credit notes were counted as invoices and subtracted a second time), and the fake GiroCode class is gone',
    3 === substr_count($dashboard, "invoice_type = 'standard'") && !is_file(ABSPATH . 'includes/class-epc-qr.php') && !str_contains($main_source, 'class-epc-qr.php'),
    'dashboard queries or EPC class'
);

$settings_body = function_body('includes/class-admin.php', 'render_settings_page');
$seller_keys_ok = true;
foreach (array_keys($test_seller) as $seller_key) {
    // Form field (the loop over $seller_fields), saved value ($_POST) and activation default.
    $seller_keys_ok = $seller_keys_ok && str_contains($settings_body, "'{$seller_key}'") && str_contains($settings_body, "\$_POST['{$seller_key}']") && str_contains(source_of('includes/class-activator.php'), "'{$seller_key}'");
}
assert_test(
    'H13 every seller field has a form field, a saved value and an activation default',
    $seller_keys_ok,
    'missing seller key'
);

// ============================================================
// Group I — honest texts: claims, client content, emoji, form fields, structured data
// ============================================================
function emoji_in(string $text): array {
    preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{23F0}-\x{23FF}]/u', $text, $m);
    return $m[0];
}
$claim_pattern = '/BFSG|BITV|WCAG|BSI IT-Grundschutz|Grundschutz|konform|KONFORM|ZUGFeRD 2\.3|Factur-X|GiroCode|PDF\/A|Leitweg|revisionssicher|transaktionssicher|Row-Lock|100 ?% ?[a-zäöüß]|Halle \(Saale\)|Warteliste|Catering/i';

reset_state();
set_settings(array_fill_keys(array_keys($test_seller), ''));
$event_id = seed_event(['title' => 'Sommerforum', 'location' => '', 'ticket_price' => 49.0, 'start_date' => '2030-10-15 09:00:00', 'end_date' => '2030-10-15 17:00:00']);
$hp_response = CyberEvents_REST_API::handle_register(rest_request(['event_id' => $event_id, 'email' => 'bot@example.org', 'first_name' => 'B', 'last_name' => 'Ot', 'consent_privacy' => 1, 'cybevents_hp' => 'http://spam.example']));
assert_test(
    'I1 a request with the hidden honeypot field filled is refused (400) and stores nothing (the field was never read)',
    $hp_response->get_status() === 400 && count($mock_db_tables['wp_cybevents_registrations']) === 0,
    'status ' . $hp_response->get_status()
);

set_settings(['enable_friendly_captcha' => 1, 'friendly_captcha_sitekey' => 'site', 'friendly_captcha_api_key' => 'key']);
$form_html = CyberEvents_Accessibility::render_registration_form($event_id, ['showJobTitle' => true, 'showPhoneField' => true, 'showDietary' => true]);
assert_test(
    'I2 the registration form asks only for what the route stores (no position, phone or dietary notes), states no conformity, invents no location, shows no captcha container without a widget, and has no emoji',
    !str_contains($form_html, 'name="job_title"') && !str_contains($form_html, 'name="phone"') && !str_contains($form_html, 'name="dietary_notes"')
        && !preg_match($claim_pattern, $form_html) && !str_contains($form_html, 'Veranstaltungsort') && !str_contains($form_html, 'frc-captcha')
        && str_contains($form_html, 'Verbindlich anmelden') && emoji_in($form_html) === [] && str_contains($form_html, 'name="first_name"'),
    json_encode(['claims' => preg_match_all($claim_pattern, $form_html, $cm) ? $cm[0] : [], 'emoji' => emoji_in($form_html)])
);

$trust_html = CyberEvents_Blocks_Manager::render_security_trust_badge_block([]);
assert_test(
    'I3 the trust block lists what the plugin does: no conformity statements, no client, no captcha card without a widget, no emoji',
    !preg_match($claim_pattern, $trust_html) && !str_contains($trust_html, 'Friendly Captcha') && str_contains($trust_html, 'Double Opt-in') && emoji_in($trust_html) === [],
    json_encode(['claims' => preg_match_all($claim_pattern, $trust_html, $cm) ? $cm[0] : [], 'emoji' => emoji_in($trust_html)])
);

update_option('timezone_string', 'Europe/Berlin');
$hero_html = CyberEvents_Blocks_Manager::render_event_hero_block(['eventId' => $event_id]);
assert_test(
    'I4 the hero block names no client, promises no waiting list or catering, offers no free sign-up for a paid event, counts down to the real UTC instant, and has no emoji',
    !preg_match($claim_pattern, $hero_html) && !str_contains($hero_html, 'kostenfrei') && str_contains($hero_html, 'Jetzt anmelden')
        && str_contains($hero_html, 'data-target-date="2030-10-15T07:00:00+00:00"') && !str_contains($hero_html, 'Veranstaltungsort') && emoji_in($hero_html) === [],
    json_encode(['claims' => preg_match_all($claim_pattern, $hero_html, $cm) ? $cm[0] : [], 'emoji' => emoji_in($hero_html)])
);

$session_for_ld = seed_session($event_id, 10);
$mock_db_tables['wp_cybevents_sessions'][$session_for_ld]->raum = 'Raum 2';
$ld_without = CyberEvents_Events_Manager::render_schema_json_ld($event_id);
set_settings(['seller_name' => 'Beispiel Veranstaltungen GmbH']);
$ld_with = CyberEvents_Events_Manager::render_schema_json_ld($event_id);
$ld = json_decode(strip_tags($ld_with), true) ?: [];
$ld0 = json_decode(strip_tags($ld_without), true) ?: [];
update_option('timezone_string', '');
assert_test(
    'I5 event JSON-LD uses stored data only: organizer from the seller settings (it named a client for every event), no invented address or location, times with the site offset',
    !isset($ld0['organizer']) && !isset($ld0['location']) && ($ld['organizer']['name'] ?? '') === 'Beispiel Veranstaltungen GmbH'
        && ($ld['startDate'] ?? '') === '2030-10-15T09:00:00+02:00' && ($ld['subEvent'][0]['location']['name'] ?? '') === 'Raum 2'
        && !preg_match('/Halle|addressLocality|validFrom/', $ld_with) && ($ld['offers']['availability'] ?? '') === 'https://schema.org/InStock',
    $ld_with
);

$venues_source = source_of('includes/class-venues-manager.php');
assert_test(
    'I6 a venue without the wheelchair flag counts as not accessible, the add form does not pre-check it, and no BFSG label is attached',
    !str_contains($venues_source, "'' === \$accessible") && !preg_match('/id="cyb_venue_accessible" value="1" checked/', $venues_source) && !preg_match('/BFSG|Konform/', $venues_source),
    'venues manager still treats a missing flag as accessible or carries a legal label'
);

$admin_source = source_of('includes/class-admin.php');
$dashboard_body = function_body('includes/class-admin.php', 'render_dashboard_page');
preg_match_all('/href="#(wm-sec-[a-z-]+)"/', $dashboard_body, $jump_targets);
$missing_anchors = array_values(array_filter($jump_targets[1], static fn($id) => !str_contains($dashboard_body, 'id="' . $id . '"')));
assert_test(
    'I7 the admin carries no Spanish strings, no invented status or retention claims, and every dashboard jump link has a target',
    !preg_match("/esc_(?:html|attr)_?e?_*\(\s*'[^']*(?:ción|¿|Guía|Ajustes|Saltar|Métricas|Registro Forense|Asistentes|Facturas|Pasarelas|Navegación)/u", $admin_source)
        && !preg_match('/System Nominal|10 Jahre Aufbewahrung|Salted SHA-256|ZUGFeRD 2\.3|GoBD|GiroCode|PDF\/A/', $admin_source)
        && str_contains($admin_source, "filename=teilnehmer-' . gmdate( 'Y-m-d' )")
        && count($jump_targets[1]) >= 4 && $missing_anchors === [],
    json_encode(['missing anchors' => $missing_anchors])
);

$emoji_hits = [];
// No file is excepted since 1.2.0: Arnold ordered the emoji of Calendar Views replaced by SVG icons (20.11.2026),
// and the demo seeder carries none since its data became fictional (19.11.2026).
foreach (array_merge(glob(ABSPATH . 'includes/*.php'), [ABSPATH . 'public/js/cybevents-public.js', ABSPATH . 'wm-cyber-events-core.php']) as $file) {
    if ($found = emoji_in((string) file_get_contents($file))) {
        $emoji_hits[basename($file)] = count($found);
    }
}
assert_test(
    'I8 no emoji in the plugin PHP and the public script, Calendar Views and the demo seeder included',
    $emoji_hits === [],
    json_encode($emoji_hits)
);

$block_descriptions = '';
foreach (glob(ABSPATH . 'blocks/*/block.json') as $block_file) {
    if (!str_contains($block_file, 'calendar-views')) {
        $block_descriptions .= (json_decode((string) file_get_contents($block_file), true)['description'] ?? '') . "\n";
    }
}
$header = [];
preg_match('/^ \* Description:\s*(.+)$/m', $main_source, $header);
assert_test(
    'I9 block descriptions and the plugin header state no conformity and no lock mechanism',
    !preg_match($claim_pattern, $block_descriptions) && !preg_match($claim_pattern . 'u', $header[1] ?? 'missing') && !str_contains($block_descriptions . ($header[1] ?? ''), 'InnoDB'),
    $block_descriptions . ($header[1] ?? '')
);

$js_source = source_of('public/js/cybevents-public.js');
assert_test(
    'I10 the public script promises no mail that was not sent yet and uses the singular for one free seat',
    !str_contains($js_source, 'Wir haben Ihnen eine E-Mail') && str_contains($js_source, "'1 freier Platz'") && !str_contains($js_source, 'transaktionssicher'),
    'script texts'
);

// The block editor (blocks/editor.js) against the PHP that renders each block.
$editor_js = source_of('blocks/editor.js');
$block_renderers = [
    'event-hero'           => [['includes/class-blocks-manager.php', 'render_event_hero_block']],
    'registration-form'    => [['includes/class-blocks-manager.php', 'render_registration_form_block'], ['includes/class-accessibility.php', 'render_registration_form']],
    'agenda-matrix'        => [['includes/class-events-manager.php', 'render_agenda']],
    'speaker-grid'         => [['includes/class-blocks-manager.php', 'render_speaker_grid_block']],
    'calendar-widget'      => [['includes/class-blocks-manager.php', 'render_calendar_widget_block']],
    'session-selector'     => [['includes/class-blocks-manager.php', 'render_session_selector_block'], ['includes/class-sessions-manager.php', 'render_session_selection_ui']],
    'ticket-pass-verifier' => [['includes/class-blocks-manager.php', 'render_ticket_pass_verifier_block']],
    'security-trust-badge' => [['includes/class-blocks-manager.php', 'render_security_trust_badge_block']],
    'events-grid'          => [['includes/class-blocks-manager.php', 'render_events_grid_block']],
];

/** The registerBlockType() call of one block in editor.js. */
function editor_block_segment(string $js, string $slug): string {
    $start = strpos($js, "registerBlockType( 'wm-cyber-events/{$slug}'");
    if ($start === false) {
        return '';
    }
    $next = strpos($js, 'registerBlockType(', $start + 1);
    return substr($js, $start, ($next === false ? strlen($js) : $next) - $start);
}

/** Renderer source of a block, without the defaults listed in shortcode_atts() (a default is not a use). */
function block_renderer_source(array $parts): string {
    $body = '';
    foreach ($parts as [$file, $function]) {
        $body .= function_body($file, $function);
    }
    return (string) preg_replace('/shortcode_atts\(.*?\),\s*\$atts/s', '', $body);
}

$dead_controls = [];
$missing_controls = [];
$missing_segments = [];
foreach ($block_renderers as $slug => $parts) {
    $segment = editor_block_segment($editor_js, $slug);
    $renderer = block_renderer_source($parts);
    if ($segment === '' || $renderer === '') {
        $missing_segments[] = $slug;
        continue;
    }
    preg_match_all('/set\( \{ (\w+):/', $segment, $controls);
    foreach (array_unique($controls[1]) as $name) {
        if (!str_contains($renderer, "['{$name}']")) {
            $dead_controls[] = "{$slug}.{$name}";
        }
    }
    $block_attributes = array_keys(json_decode(source_of("blocks/{$slug}/block.json"), true)['attributes'] ?? []);
    foreach ($block_attributes as $name) {
        // showFriendlyCaptcha: the card needs a captcha widget, which the plugin does not ship (captcha_widget_available()).
        if (str_starts_with($name, 'show') && $name !== 'showFriendlyCaptcha' && str_contains($renderer, "['{$name}']") && !in_array($name, $controls[1], true)) {
            $missing_controls[] = "{$slug}.{$name}";
        }
    }
}
assert_test(
    'I11 every editor control changes something the renderer reads (position, phone, dietary notes, track filters, session overlap warnings did not), and every show-option the renderer reads has a control (Microsoft 365 had none)',
    $dead_controls === [] && $missing_controls === [] && $missing_segments === [],
    json_encode(['dead' => $dead_controls, 'missing' => $missing_controls, 'unparsed blocks' => $missing_segments])
);

// Style selects: the renderer turns the value into a class, so a value without a rule in the frontend
// stylesheet looks exactly like the default. The block.json default is the base look and needs no rule.
$style_classes = [
    'event-hero'           => ['presetStyle' => 'cybevents-hero--preset-', 'borderRadius' => 'cyb-radius-'],
    'registration-form'    => ['formStyle' => 'cybevents-style-', 'borderRadius' => 'cyb-radius-'],
    'agenda-matrix'        => ['layoutStyle' => 'cybevents-agenda--layout-'],
    'speaker-grid'         => ['cardStyle' => 'cybevents-speaker--', 'avatarStyle' => 'cybevents-avatar--'],
    'calendar-widget'      => ['cardStyle' => 'cybevents-cal--'],
    'ticket-pass-verifier' => ['cardStyle' => 'cybevents-pass--'],
    'security-trust-badge' => ['layout' => 'cybevents-trust-layout--', 'theme' => 'cybevents-trust-theme--'],
    'events-grid'          => ['cardStyle' => 'cybevents-grid-style--'],
];
$public_css = source_of('public/css/cybevents-public.css');
$dead_options = [];
$select_count = 0;
foreach ($block_renderers as $slug => $parts) {
    $segment = editor_block_segment($editor_js, $slug);
    $renderer = block_renderer_source($parts);
    $defaults = array_map(static fn($a) => $a['default'] ?? null, json_decode(source_of("blocks/{$slug}/block.json"), true)['attributes'] ?? []);
    preg_match_all('/value: attr\.(\w+),\s*options: \[(.*?)\]/s', $segment, $selects, PREG_SET_ORDER);
    foreach ($selects as [, $name, $options_src]) {
        if ($name === 'filterStatus') {
            continue; // A query filter, not a class (I13).
        }
        $select_count++;
        $prefix = $style_classes[$slug][$name] ?? null;
        if ($prefix === null || !str_contains($renderer, $prefix)) {
            $dead_options[] = "{$slug}.{$name} (no class the renderer builds)";
            continue;
        }
        preg_match_all("/value: '([a-z0-9-]+)'/", $options_src, $values);
        $choices = array_values(array_filter($values[1], static fn($v) => $v === ($defaults[$name] ?? null) || preg_match('/\.' . preg_quote($prefix . $v, '/') . '(?![a-z0-9_-])/', $public_css)));
        foreach (array_diff($values[1], $choices) as $value) {
            $dead_options[] = "{$slug}.{$name}={$value}";
        }
        if (count($choices) < 2) {
            $dead_options[] = "{$slug}.{$name} (fewer than two working choices)";
        }
    }
}
assert_test(
    'I12 every style option in the editor has a rule in the frontend stylesheet (about half of them had none, the agenda layouts none at all)',
    $dead_options === [] && $select_count >= 5,
    json_encode(['dead' => $dead_options, 'selects' => $select_count])
);

reset_state();
seed_event(['title' => 'Laufende Tagung', 'status' => 'active']);
seed_event(['title' => 'Tagung 2024', 'status' => 'archived']);
seed_event(['title' => 'Entwurf Tagung', 'status' => 'draft']);
$grid_titles = static function (string $filter): array {
    preg_match_all('/<h3 class="cybevents-card-title">([^<]+)<\/h3>/', CyberEvents_Blocks_Manager::render_events_grid_block(['filterStatus' => $filter]), $m);
    sort($m[1]);
    return $m[1];
};
$grid_result = ['active' => $grid_titles('active'), 'all' => $grid_titles('all'), 'archived' => $grid_titles('archived')];
assert_test(
    'I13 the events grid filter shows archived events for "all" and "archived" (it asked for a status "completed" that no event has, and "archived" fell back to the active events); drafts never',
    $grid_result === ['active' => ['Laufende Tagung'], 'all' => ['Laufende Tagung', 'Tagung 2024'], 'archived' => ['Tagung 2024']],
    json_encode($grid_result)
);

$mock_is_admin = true;
$pass_preview = CyberEvents_Blocks_Manager::render_ticket_pass_verifier_block([]);
$mock_is_admin = false;
// Only the string literals: attribute names such as showBitv or showGrundschutz stay, saved blocks carry them.
preg_match_all("/'((?:[^'\\\\\\n]|\\\\.)*)'/u", $editor_js, $editor_literals);
$editor_texts = implode("\n", $editor_literals[1]);
$editor_claims = preg_match_all($claim_pattern, $editor_texts, $cm) ? $cm[0] : [];
$editor_extra = preg_match_all('/InnoDB|Row-Level|QR|OFFIZIELL|Offiziell|Siegel|Made in Germany|24h\/2h|Enterprise Vertrauen/u', $editor_texts, $xm) ? $xm[0] : [];
assert_test(
    'I14 the block editor has no emoji and names no conformity, lock mechanism, QR code, alarm schedule or client; the pass calls itself neither official in the editor nor on the page',
    emoji_in($editor_js) === [] && $editor_claims === [] && $editor_extra === [] && !str_contains(mb_strtoupper($pass_preview), 'OFFIZIELL') && str_contains($pass_preview, 'TEILNEHMERPASS'),
    json_encode(['emoji' => count(emoji_in($editor_js)), 'claims' => $editor_claims, 'other' => $editor_extra])
);

$event_for_privacy = seed_event(['title' => 'Datenschutztag']);
$GLOBALS['mock_privacy_policy_url'] = 'https://example.org/datenschutz/';
$form_default_privacy = CyberEvents_Accessibility::render_registration_form($event_for_privacy, []);
$form_block_privacy = CyberEvents_Accessibility::render_registration_form($event_for_privacy, ['privacyPolicyUrl' => 'https://example.org/eigene-seite/']);
$GLOBALS['mock_privacy_policy_url'] = '';
$form_no_privacy = CyberEvents_Accessibility::render_registration_form($event_for_privacy, []);
assert_test(
    'I15 the consent links to the WordPress privacy page when the block sets no URL (it had no link at all), the block URL wins, and no link is invented when neither exists',
    str_contains($form_default_privacy, 'href="https://example.org/datenschutz/"') && str_contains($form_block_privacy, 'href="https://example.org/eigene-seite/"')
        && !str_contains($form_block_privacy, 'example.org/datenschutz/') && !str_contains($form_no_privacy, 'Datenschutzerklärung lesen'),
    'links: ' . json_encode([str_contains($form_default_privacy, 'datenschutz/'), str_contains($form_block_privacy, 'eigene-seite'), str_contains($form_no_privacy, 'Datenschutzerklärung lesen')])
);

// ============================================================
// Group J — what WordPress actually renders: block.json defaults, CPT editors, demo data keys
// ============================================================

/**
 * Render a dynamic block the way WordPress does: attributes missing from the block comment take the
 * block.json default before the render callback sees them. I3, I4 and I14 called the callbacks
 * without those defaults, so a client name and "kostenfrei" in block.json went unnoticed.
 */
function render_with_block_defaults(string $slug, array $attrs, callable $renderer): string {
    $defaults = [];
    foreach (json_decode(source_of("blocks/{$slug}/block.json"), true)['attributes'] ?? [] as $name => $spec) {
        if (array_key_exists('default', $spec)) {
            $defaults[$name] = $spec['default'];
        }
    }
    return (string) $renderer(array_merge($defaults, $attrs));
}

$text_defaults = [];
foreach (glob(ABSPATH . 'blocks/*/block.json') as $block_file) {
    foreach (json_decode((string) file_get_contents($block_file), true)['attributes'] ?? [] as $name => $spec) {
        if (is_string($spec['default'] ?? null) && !preg_match('/^[#a-z0-9_-]*$/', $spec['default'])) {
            $text_defaults[] = basename(dirname($block_file)) . ".{$name} = {$spec['default']}";
        }
    }
}
assert_test(
    'J1 block.json carries no wording as a default (only keys such as dark-glow or #anchor): the renderers compute headings, buttons and badges from the event and translate them',
    $text_defaults === [],
    json_encode($text_defaults, JSON_UNESCAPED_UNICODE)
);

reset_state();
set_settings(array_fill_keys(array_keys($test_seller), ''));
update_option('timezone_string', 'Europe/Berlin');
$paid_event = seed_event(['title' => 'Herbstkonferenz', 'location' => 'Saal 3', 'ticket_price' => 120.0, 'start_date' => '2030-11-05 09:00:00', 'end_date' => '2030-11-05 17:00:00']);
$confirmed = seed_registration($paid_event, 'confirmed');
$html_by_block = [
    'security-trust-badge' => render_with_block_defaults('security-trust-badge', [], [CyberEvents_Blocks_Manager::class, 'render_security_trust_badge_block']),
    'event-hero'           => render_with_block_defaults('event-hero', ['eventId' => $paid_event], [CyberEvents_Blocks_Manager::class, 'render_event_hero_block']),
    'registration-form'    => render_with_block_defaults('registration-form', ['eventId' => $paid_event], [CyberEvents_Blocks_Manager::class, 'render_registration_form_block']),
    'speaker-grid'         => render_with_block_defaults('speaker-grid', ['eventId' => $paid_event], [CyberEvents_Blocks_Manager::class, 'render_speaker_grid_block']),
    'agenda-matrix'        => render_with_block_defaults('agenda-matrix', ['eventId' => $paid_event], [CyberEvents_Events_Manager::class, 'render_agenda']),
    'events-grid'          => render_with_block_defaults('events-grid', [], [CyberEvents_Blocks_Manager::class, 'render_events_grid_block']),
];
$_GET['token'] = $confirmed['token'];
$html_by_block['ticket-pass-verifier'] = render_with_block_defaults('ticket-pass-verifier', [], [CyberEvents_Blocks_Manager::class, 'render_ticket_pass_verifier_block']);
$_GET = [];
update_option('timezone_string', '');
$rendered_problems = [];
foreach ($html_by_block as $slug => $html) {
    if (preg_match_all($claim_pattern, $html, $cm)) {
        $rendered_problems[] = "{$slug}: " . implode(', ', array_unique($cm[0]));
    }
    if (emoji_in($html) !== []) {
        $rendered_problems[] = "{$slug}: emoji";
    }
    if (preg_match('/Souveräne|OFFIZIELL|strengen Standards|Führende Köpfe|parallelen Tracks|Keynote & Panelists|kostenfrei/iu', $html, $xm)) {
        $rendered_problems[] = "{$slug}: {$xm[0]}";
    }
}
$pass_html = $html_by_block['ticket-pass-verifier'];
assert_test(
    'J2 blocks rendered with their block.json defaults (as WordPress renders them) name no client, state no conformity, offer no free sign-up for a paid event, and the pass carries the event title instead of a demo summit',
    $rendered_problems === []
        && str_contains($html_by_block['event-hero'], 'Jetzt anmelden') && str_contains($html_by_block['registration-form'], 'Verbindlich anmelden')
        && str_contains($pass_html, '<div class="cybevents-pass-logo">Herbstkonferenz</div>') && str_contains($pass_html, 'TEILNEHMERPASS')
        && str_contains($html_by_block['agenda-matrix'], '<h2 class="cybevents-agenda-title">Herbstkonferenz</h2>'),
    json_encode($rendered_problems, JSON_UNESCAPED_UNICODE)
);

// The hero's second button points to #cybevents-agenda; no element carried that id.
$hero_targets = [];
preg_match_all('/href="#([a-z0-9-]+)"/', $html_by_block['event-hero'], $hero_targets);
$page_html = $html_by_block['agenda-matrix'] . $html_by_block['registration-form'];
$missing_targets = array_values(array_filter(array_unique($hero_targets[1]), static fn($id) => !str_contains($page_html, 'id="' . $id . '"')));
assert_test(
    'J3 every jump link of the hero lands on the agenda or the form rendered on the same page',
    count($hero_targets[1]) >= 2 && $missing_targets === [],
    json_encode(['targets' => $hero_targets[1], 'missing' => $missing_targets])
);

/** Code of a PHP file without comments, so a comment that records a removed default does not count. */
function php_code_without_comments(string $relative): string {
    $code = '';
    foreach (token_get_all(source_of($relative)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

$client_hits = [];
foreach (glob(ABSPATH . 'includes/*.php') as $file) {
    // Since 2026-11-19 the demo seeder, Calendar Views and the theme presets are checked too: they named a client.
    if (preg_match_all('/Halle \(Saale\)|Hauptauditorium|ZUGFeRD E-Invoice/u', php_code_without_comments('includes/' . basename($file)), $hm)) {
        $client_hits[basename($file)] = array_values(array_unique($hm[0]));
    }
}
assert_test(
    'J4 no fixed venue, city or standard in any file under includes/ (the event editor pre-filled a client venue and saved it into new events; demo data and presets named the client)',
    $client_hits === [],
    json_encode($client_hits, JSON_UNESCAPED_UNICODE)
);

// Demo seeder, Calendar Views and the frontend script ship example data to every site that activates the
// plugin. Until 2026-11-19 they carried registrations at real authority domains and a client's address; every
// e-mail domain and URL host there must now be one of the reserved example domains (RFC 2606).
$demo_domain_hits = [];
foreach (['includes/class-seeder.php', 'includes/class-calendar-views.php', 'public/js/cybevents-public.js'] as $demo_file) {
    $demo_source = source_of($demo_file);
    preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', $demo_source, $mail_hosts);
    preg_match_all('#https?://([A-Za-z0-9.-]+)#', $demo_source, $url_hosts);
    foreach (array_merge($mail_hosts[1], $url_hosts[1]) as $host) {
        if (!preg_match('/(^|\.)example\.(com|org|net)$/', strtolower($host))) {
            $demo_domain_hits[$demo_file][] = $host;
        }
    }
}
// The event post's save_post hook inserts its own row before the seeder links its row to the post.
$seed_event_body = substr($seeder_code_j7 = php_code_without_comments('includes/class-seeder.php'), (int) strpos($seeder_code_j7, "'post_type'    => 'cybevent'"), 1600);
$phantom_cleanup = (bool) preg_match('/DELETE FROM \{\$wpdb->prefix\}cybevents_events WHERE wp_post_id = %d AND id <> %d", \$post_id, \$event_id/', $seed_event_body);
assert_test(
    'J7 seeding removes the event row the save_post hook creates for the demo post (it left a second event with 0 seats and no price)',
    $phantom_cleanup,
    'no cleanup of the hook row after wp_insert_post of the demo event'
);
// The seeder creates a landing page and a workshop page; until 2026-11-19 the purge left both and every
// re-seed added a copy (demo-fachkonferenz-2, -3). The IDs are recorded and only recorded pages are deleted.
$seed_body_j8   = function_body('includes/class-seeder.php', 'seed_demo_data');
$purge_pages_j8 = function_body('includes/class-seeder.php', 'delete_demo_pages');
$j8 = [
    'seed records both page IDs' => (bool) preg_match('/array\( \$landing_page_id, \$workshop_page_id \)/', $seed_body_j8)
        && str_contains($seed_body_j8, 'update_option( self::DEMO_PAGES_OPTION'),
    'purge deletes them'         => str_contains(function_body('includes/class-seeder.php', 'delete_demo_data'), 'self::delete_demo_pages()'),
    'factory reset deletes them' => str_contains(function_body('includes/class-seeder.php', 'reset_to_factory_defaults'), 'self::delete_demo_pages()'),
    'only pages are deleted'     => str_contains($purge_pages_j8, "'page' === get_post_type( \$page_id )"),
    'the record is cleared'      => str_contains($purge_pages_j8, 'delete_option( self::DEMO_PAGES_OPTION )'),
];
assert_test(
    'J8 the demo purge deletes the pages the seeder created, and only those (re-seeding piled up copies of the landing page)',
    !in_array(false, $j8, true),
    json_encode($j8)
);
$seeded_mail_count = preg_match_all("/'email'\s*=>\s*'[^']+@example\.(com|org|net)'/", source_of('includes/class-seeder.php'));
assert_test(
    'J6 demo data uses only reserved example domains for e-mail addresses and links (registrations used real authority domains)',
    $demo_domain_hits === [] && $seeded_mail_count === 5,
    json_encode(['hosts' => $demo_domain_hits, 'seeded example addresses' => $seeded_mail_count])
);

$seeder_code = php_code_without_comments('includes/class-seeder.php');
preg_match_all('/update_post_meta\(\s*\$sess_post_id,\s*\'(\w+)\'/', $seeder_code, $seed_session_keys);
preg_match_all('/update_post_meta\(\s*\$post_id,\s*\'(\w+)\'/', $seeder_code, $seed_event_keys);
preg_match_all('/get_post_meta\(\s*\$post_id,\s*\'(\w+)\'/', function_body('includes/class-sessions-manager.php', 'sync_cpt_to_table'), $sync_session_keys);
preg_match_all('/get_post_meta\(\s*\$post_id,\s*\'(\w+)\'/', function_body('includes/class-events-manager.php', 'sync_cpt_to_table'), $sync_event_keys);
$meta_mismatch = [
    'session keys the sync reads but the seeder never writes' => array_values(array_diff($sync_session_keys[1], $seed_session_keys[1])),
    'session keys the seeder writes that nothing reads'        => array_values(array_diff($seed_session_keys[1], $sync_session_keys[1])),
    'event keys the sync reads but the seeder never writes'   => array_values(array_diff($sync_event_keys[1], $seed_event_keys[1])),
];
assert_test(
    'J5 demo sessions and events carry the post meta the editor saves back (a demo session saved in the editor lost its event, room and capacity; a demo event became free)',
    count($sync_session_keys[1]) >= 5 && count($sync_event_keys[1]) >= 5 && array_filter($meta_mismatch) === [],
    json_encode($meta_mismatch)
);

// ------------------------------------------------------------
// Group K: admission waiting room (2026-09-16)
//
// A ticket drop is the moment the plugin is least able to defend itself: the seat counter cannot be
// oversold, but every request still walks the session table and takes a row lock. These assertions pin
// that the requests over the limit are turned away BEFORE any of that runs, and that the ones under it
// are not touched.
// ------------------------------------------------------------

/** Reads that reached the database during $callback. */
function reads_during(callable $callback): array {
    global $wpdb;
    $wpdb->reads = [];
    $wpdb->queries = [];
    $callback();
    return array_merge($wpdb->reads, $wpdb->queries);
}

reset_state();
set_settings(['admission_per_minute' => 0]);
$off_verdict = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 7);
assert_test(
    'K1 the waiting room is off by default and writes no counter at all',
    $off_verdict['admitted'] === true && $off_verdict['limit'] === 0 && $mock_transients === [] && $mock_cache === [],
    json_encode(['verdict' => $off_verdict, 'transients' => array_keys($mock_transients), 'cache' => array_keys($mock_cache)])
);

reset_state();
set_settings(['admission_per_minute' => 3]);
$verdicts = [];
for ($i = 0; $i < 5; $i++) {
    $verdicts[] = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 7);
}
assert_test(
    'K2 a limit of three admits three requests per window and refuses the rest with a Retry-After inside the window',
    array_column($verdicts, 'admitted') === [true, true, true, false, false]
        && $verdicts[3]['retry_after'] >= 1 && $verdicts[3]['retry_after'] <= CyberEvents_Waiting_Room::WINDOW_SECONDS
        && $verdicts[4]['count'] === 5,
    json_encode($verdicts)
);

// The whole point: a refused booking must not reach the database.
reset_state();
set_settings(['admission_per_minute' => 1]);
$event_id   = seed_event();
$session_id = seed_session($event_id, 50);
$reg        = seed_registration($event_id, 'confirmed');

$first_reads = reads_during(static function () use ($reg, $session_id, $event_id) {
    CyberEvents_REST_API::handle_book_sessions(rest_request([
        'token'       => $reg['token'],
        'event_id'    => $event_id,
        'session_ids' => [$session_id],
    ]));
});

$refused = null;
$refused_reads = reads_during(static function () use ($reg, $session_id, $event_id, &$refused) {
    $refused = CyberEvents_REST_API::handle_book_sessions(rest_request([
        'token'       => $reg['token'],
        'event_id'    => $event_id,
        'session_ids' => [$session_id],
    ]));
});

// The harness keeps transients in memory, so "no reads" here means "none of the booking queries".
// Measured on a local WordPress install on 2026-09-16 with the database backend: an admitted booking costs 16 queries,
// a refused one 2 — the transient the counter itself is. With a persistent object cache it is 0.
assert_test(
    'K3 a booking over the limit answers 503 with Retry-After and runs none of the booking queries (the admitted one before it made ' . count($first_reads) . ')',
    $refused instanceof WP_REST_Response
        && $refused->get_status() === 503
        && isset($refused->headers['Retry-After'])
        && (int) $refused->headers['Retry-After'] >= 1
        && $refused_reads === []
        && count($first_reads) > 0,
    json_encode(['status' => $refused ? $refused->get_status() : null, 'headers' => $refused->headers ?? [], 'refused_reads' => $refused_reads, 'first' => count($first_reads)])
);

assert_test(
    'K3b the refusal promises no seat and says so in words, and the seat count did not move',
    $refused instanceof WP_REST_Response
        && true === ($refused->get_data()['waiting'] ?? null)
        && str_contains((string) ($refused->get_data()['message'] ?? ''), 'nicht reserviert')
        && session_taken($session_id) === 1,
    json_encode($refused ? $refused->get_data() : null)
);

// A new window admits again: the counter is per window, not a permanent ban.
$GLOBALS['mock_now'] = (int) current_time('timestamp', true) + CyberEvents_Waiting_Room::WINDOW_SECONDS;
$after_window = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, $event_id);
$GLOBALS['mock_now'] = null;
assert_test(
    'K4 the next window admits again',
    $after_window['admitted'] === true && $after_window['count'] === 1,
    json_encode($after_window)
);

// One event filling up must not close another, and registration must not close booking.
reset_state();
set_settings(['admission_per_minute' => 1]);
CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 11);
$other_event = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 12);
$other_gate  = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_REGISTER, 11);
$same_again  = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 11);
assert_test(
    'K5 the counters are per gate and per event: a full bucket on one event or gate does not close the others',
    $other_event['admitted'] === true && $other_gate['admitted'] === true && $same_again['admitted'] === false,
    json_encode(['other_event' => $other_event['admitted'], 'other_gate' => $other_gate['admitted'], 'same' => $same_again['admitted']])
);

// Registration is the second route a drop lands on.
reset_state();
set_settings(['admission_per_minute' => 1]);
$reg_event = seed_event();
CyberEvents_REST_API::handle_register(rest_request([
    'event_id'        => $reg_event,
    'email'           => 'erste@example.org',
    'first_name'      => 'Erika',
    'last_name'       => 'Muster',
    'consent_privacy' => 1,
]));
$second_registration = null;
$registration_reads = reads_during(static function () use ($reg_event, &$second_registration) {
    $second_registration = CyberEvents_REST_API::handle_register(rest_request([
        'event_id'        => $reg_event,
        'email'           => 'zweite@example.org',
        'first_name'      => 'Max',
        'last_name'       => 'Muster',
        'consent_privacy' => 1,
    ]));
});
assert_test(
    'K6 registration over the limit is refused the same way, before the row is written',
    $second_registration instanceof WP_REST_Response
        && $second_registration->get_status() === 503
        && $registration_reads === []
        && count($mock_db_tables['wp_cybevents_registrations']) === 1,
    json_encode(['status' => $second_registration ? $second_registration->get_status() : null, 'reads' => count($registration_reads), 'rows' => count($mock_db_tables['wp_cybevents_registrations'])])
);

// Where the counter lives, and what that costs.
reset_state();
set_settings(['admission_per_minute' => 5]);
$mock_ext_cache = false;
CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 3);
$database_backend = CyberEvents_Waiting_Room::backend();
$counted_in_transients = count($mock_transients);

reset_state();
set_settings(['admission_per_minute' => 5]);
$mock_ext_cache = true;
CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 3);
$cache_backend = CyberEvents_Waiting_Room::backend();
$counted_in_cache = count($mock_cache[CyberEvents_Waiting_Room::CACHE_GROUP] ?? []);

assert_test(
    'K7 without a persistent object cache the counter is a transient (a database row), with one it is an atomic increment in the cache',
    $database_backend === 'database' && $counted_in_transients === 1
        && $cache_backend === 'object-cache' && $counted_in_cache === 1 && $mock_transients === [],
    json_encode(compact('database_backend', 'counted_in_transients', 'cache_backend', 'counted_in_cache'))
);

assert_test(
    'K7b the settings screen tells the operator which backend counts, and warns when it is the database',
    CyberEvents_Waiting_Room::status()['database_backend_warning'] === ''
        && str_contains(source_of('includes/class-admin.php'), 'database_backend_warning'),
    json_encode(CyberEvents_Waiting_Room::status())
);

// A cache that dropped the key between add() and incr() must not read as "nobody has arrived".
reset_state();
set_settings(['admission_per_minute' => 1]);
$mock_ext_cache = true;
$mock_cache = [];
$evicting = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 4);
$mock_cache = []; // the backend evicted the window key
$after_eviction = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 4);
assert_test(
    'K8 an evicted counter starts this request at one, never at zero: a lost key must not read as an empty window',
    $evicting['count'] === 1 && $after_eviction['count'] === 1 && $after_eviction['admitted'] === true,
    json_encode(['first' => $evicting['count'], 'after' => $after_eviction['count']])
);

reset_state();
set_settings(['admission_per_minute' => 2]);
CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 9);
$depth_before = CyberEvents_Waiting_Room::depth(CyberEvents_Waiting_Room::GATE_BOOK, 9);
CyberEvents_Waiting_Room::reset(CyberEvents_Waiting_Room::GATE_BOOK, 9);
assert_test(
    'K9 depth() reports the window without counting itself, and reset() clears it',
    $depth_before === 1 && CyberEvents_Waiting_Room::depth(CyberEvents_Waiting_Room::GATE_BOOK, 9) === 0,
    json_encode(['before' => $depth_before, 'after' => CyberEvents_Waiting_Room::depth(CyberEvents_Waiting_Room::GATE_BOOK, 9)])
);

reset_state();
set_settings(['admission_per_minute' => -5]);
$negative = CyberEvents_Waiting_Room::limit();
set_settings(['admission_per_minute' => CyberEvents_Waiting_Room::MAX_LIMIT * 10]);
$too_large = CyberEvents_Waiting_Room::limit();
assert_test(
    'K10 a negative limit reads as off and a huge one is capped, so the stored value cannot open or close the gate by accident',
    $negative === 0 && $too_large === CyberEvents_Waiting_Room::MAX_LIMIT,
    json_encode(['negative' => $negative, 'too_large' => $too_large])
);

// The counting path of a real persistent cache, end to end: the mutation that replaces add() with
// set() resets the window on every request and never refuses anything.
reset_state();
set_settings(['admission_per_minute' => 2]);
$mock_ext_cache = true;
$cache_verdicts = [];
for ($i = 0; $i < 4; $i++) {
    $cache_verdicts[] = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 21);
}
assert_test(
    'K12 with a persistent object cache the counter accumulates across requests and refuses over the limit (a counter reset on each request never refuses anything)',
    array_column($cache_verdicts, 'admitted') === [true, true, false, false]
        && array_column($cache_verdicts, 'count') === [1, 2, 3, 4],
    json_encode(array_map(static fn($v) => [$v['admitted'], $v['count']], $cache_verdicts))
);

// A key the backend dropped between add() and incr(): the request still counts as one, never as none.
reset_state();
set_settings(['admission_per_minute' => 2]);
$mock_ext_cache = true;
$mock_cache_evict_on_incr = true;
$evicted_verdict = CyberEvents_Waiting_Room::admit(CyberEvents_Waiting_Room::GATE_BOOK, 22);
$mock_cache_evict_on_incr = false;
assert_test(
    'K12b a request whose counter key was evicted mid-flight reports one, not zero: a lost key must never read as an empty window',
    $evicted_verdict['count'] === 1,
    json_encode($evicted_verdict)
);

// delete_registration() and release_sessions() take a row or an id. An id used to read a property off
// an integer: two warnings, no seats released, nothing deleted, false returned (measured on a local WordPress install
// 2026-09-16 while probing the waiting room).
reset_state();
$del_event   = seed_event();
$del_session = seed_session($del_event, 10);
$del_reg     = seed_registration($del_event, 'confirmed');
CyberEvents_Database::book_session_atomic($del_session, $del_reg['id']);
$taken_before = session_taken($del_session);
$deleted_by_id = CyberEvents_GDPR_Manager::delete_registration($del_reg['id']);
assert_test(
    'K14 delete_registration() takes an id as well as a row: the seat is released and the row is gone (an id used to warn twice and delete nothing)',
    $deleted_by_id === true && $taken_before === 1 && session_taken($del_session) === 0 && registration($del_reg['id']) === null,
    json_encode(['deleted' => $deleted_by_id, 'before' => $taken_before, 'after' => session_taken($del_session)])
);

// release_sessions() is public and callers reach it on its own (the eraser, the webhook); its own
// guard has to hold, not only the one delete_registration() runs before it.
reset_state();
$rel_event   = seed_event();
$rel_session = seed_session($rel_event, 10);
$rel_reg     = seed_registration($rel_event, 'confirmed');
CyberEvents_Database::book_session_atomic($rel_session, $rel_reg['id']);
CyberEvents_GDPR_Manager::release_sessions($rel_reg['id']);
assert_test(
    'K14c release_sessions() called on its own with an id releases the seat (it used to read a property off the integer and release nothing)',
    session_taken($rel_session) === 0,
    'seat still taken: ' . session_taken($rel_session)
);

assert_test(
    'K14b an id that names no registration is refused instead of half-running',
    CyberEvents_GDPR_Manager::delete_registration(999999) === false && CyberEvents_GDPR_Manager::delete_registration(0) === false,
    'deleting a missing registration did not return false'
);

$settings_body = function_body('includes/class-admin.php', 'render_settings_page');
assert_test(
    'K13 the settings save keeps the stored admission limit when the form did not post one, like the invoice counter',
    str_contains($settings_body, "absint( \$previous['admission_per_minute'] ?? 0 )")
        && str_contains($settings_body, 'CyberEvents_Waiting_Room::MAX_LIMIT'),
    'admission limit not preserved on save'
);

reset_state();
set_settings(['admission_per_minute' => 4]);
$telemetry = (new CyberEvents_Spoke_Adapter())->get_telemetry_metrics();
assert_test(
    'K11 the hub reports the limit and the backend, so an operator sees a waiting room that counts in the database',
    ($telemetry['admission_limit'] ?? null) === 4 && ($telemetry['admission_backend'] ?? '') === 'database',
    json_encode(['limit' => $telemetry['admission_limit'] ?? null, 'backend' => $telemetry['admission_backend'] ?? null])
);

// ------------------------------------------------------------
// Group L: seat map and its live stream (2026-09-16)
//
// The plugin stores capacity, not furniture. The map draws what exists — a cell per seat, a row per
// session — and the stream carries occupancy numbers only. These assertions pin both halves: what is
// drawn, and what is never in it.
// ------------------------------------------------------------

reset_state();
$map_event = seed_event();
$map_full  = seed_session($map_event, 4, 4);
$map_part  = seed_session($map_event, 7, 3);
$snapshot  = CyberEvents_Seat_Map::snapshot($map_event);

assert_test(
    'L1 the snapshot carries capacity, taken and free per session',
    count($snapshot['sessions']) === 2
        && $snapshot['sessions'][0]['capacity'] === 4 && $snapshot['sessions'][0]['taken'] === 4 && $snapshot['sessions'][0]['free'] === 0
        && $snapshot['sessions'][1]['capacity'] === 7 && $snapshot['sessions'][1]['taken'] === 3 && $snapshot['sessions'][1]['free'] === 4,
    json_encode($snapshot['sessions'])
);

$digest_before = $snapshot['digest'];
$mock_db_tables['wp_cybevents_sessions'][$map_part]->title = 'Umbenannte Session';
$digest_after_rename = CyberEvents_Seat_Map::snapshot($map_event)['digest'];
CyberEvents_Database::book_session_atomic($map_part, 4242);
$digest_after_booking = CyberEvents_Seat_Map::snapshot($map_event)['digest'];

assert_test(
    'L2 the digest follows the numbers: renaming a session does not change it, booking a seat does',
    $digest_after_rename === $digest_before && $digest_after_booking !== $digest_before,
    json_encode(['before' => substr($digest_before, 0, 12), 'renamed' => substr($digest_after_rename, 0, 12), 'booked' => substr($digest_after_booking, 0, 12)])
);

reset_state();
$svg_event = seed_event();
$svg_sess  = seed_session($svg_event, 7, 3);
$svg       = CyberEvents_Seat_Map::render_svg($svg_event);
preg_match_all('/class="cybevents-seatmap-seat ([a-z-]+)"/', $svg, $seat_classes);

assert_test(
    'L3 a session of seven seats with three taken is drawn as seven cells, three of them taken',
    count($seat_classes[1]) === 7
        && count(array_filter($seat_classes[1], static fn($c) => $c === 'is-taken')) === 3
        && count(array_filter($seat_classes[1], static fn($c) => $c === 'is-free')) === 4,
    json_encode($seat_classes[1])
);

reset_state();
$big_event = seed_event();
seed_session($big_event, CyberEvents_Seat_Map::MAX_CELLS + 50, 100);
$big_svg = CyberEvents_Seat_Map::render_svg($big_event);
assert_test(
    'L4 a session larger than MAX_CELLS is drawn as one bar, not as hundreds of rectangles nobody reads',
    substr_count($big_svg, 'cybevents-seatmap-seat') === 0
        && str_contains($big_svg, 'cybevents-seatmap-bar-fill')
        && str_contains($big_svg, 'cybevents-seatmap-bar-track'),
    'cells: ' . substr_count($big_svg, 'cybevents-seatmap-seat')
);

reset_state();
$zero_event = seed_event();
seed_session($zero_event, 0, 0);
$zero_svg = CyberEvents_Seat_Map::render_svg($zero_event);
assert_test(
    'L5 a session with no capacity draws an empty bar instead of dividing by zero',
    str_contains($zero_svg, 'cybevents-seatmap-bar-track') && str_contains($zero_svg, 'width="0"'),
    substr($zero_svg, 0, 200)
);

// Whose seats are marked: only the holder of a confirmed token sees their own.
reset_state();
$own_event   = seed_event();
$own_session = seed_session($own_event, 10, 0);
$own_reg     = seed_registration($own_event, 'confirmed');
$pending_reg = seed_registration($own_event, 'pending_doi');
CyberEvents_Database::book_session_atomic($own_session, $own_reg['id']);

// A registration that is not confirmed but does hold a row in registration_sessions: without the
// status check it would see its bookings and be allowed to change them.
$unconfirmed_session = seed_session($own_event, 10, 0);
CyberEvents_Database::book_session_atomic($unconfirmed_session, $pending_reg['id']);

assert_test(
    'L6b an unconfirmed registration that holds a booking row is shown none of it and may not book',
    CyberEvents_Seat_Map::booked_session_ids($pending_reg['token']) === []
        && CyberEvents_Seat_Map::may_book($pending_reg['token']) === false
        && CyberEvents_Seat_Map::may_book($own_reg['token']) === true
        && CyberEvents_Seat_Map::may_book('kein-token') === false
        && CyberEvents_Seat_Map::may_book('') === false,
    json_encode([
        'pending_sees' => CyberEvents_Seat_Map::booked_session_ids($pending_reg['token']),
        'pending_may'  => CyberEvents_Seat_Map::may_book($pending_reg['token']),
        'confirmed_may' => CyberEvents_Seat_Map::may_book($own_reg['token']),
    ])
);

assert_test(
    'L6 booked_session_ids() answers for a confirmed token, and with nothing for an unknown or unconfirmed one',
    CyberEvents_Seat_Map::booked_session_ids($own_reg['token']) === [$own_session]
        && CyberEvents_Seat_Map::booked_session_ids($pending_reg['token']) === []
        && CyberEvents_Seat_Map::booked_session_ids('token-das-nicht-existiert') === []
        && CyberEvents_Seat_Map::booked_session_ids('') === [],
    json_encode(CyberEvents_Seat_Map::booked_session_ids($own_reg['token']))
);

$own_html = CyberEvents_Seat_Map::render($own_event, $own_reg['token']);
$foreign_html = CyberEvents_Seat_Map::render($own_event, '');
assert_test(
    'L7 the row of a session the viewer holds carries is-booked, and an anonymous view carries none',
    str_contains($own_html, 'cybevents-seatmap-row is-booked') && ! str_contains($foreign_html, 'cybevents-seatmap-row is-booked'),
    'own: ' . (str_contains($own_html, 'is-booked') ? 'yes' : 'no') . ', anonymous: ' . (str_contains($foreign_html, 'cybevents-seatmap-row is-booked') ? 'yes' : 'no')
);

// The stream and the drawing carry occupancy, never a person.
assert_test(
    'L8 neither the drawing nor the snapshot carries a name, an address or the participant token (the token travels in the URL; a copy in the markup would sit in every cache)',
    ! str_contains($own_html, $own_reg['token'])
        && ! str_contains($own_html, 'Erika')
        && ! str_contains($own_html, '@example.org')
        && ! str_contains((string) wp_json_encode(CyberEvents_Seat_Map::snapshot($own_event)), '@example.org'),
    'personal data found in the seat map output'
);

assert_test(
    'L9 the numbers are also there as text, because a screen reader gets nothing from a rectangle',
    str_contains($own_html, 'cybevents-seatmap-table')
        && str_contains($own_html, 'data-cell="taken"')
        && preg_match('/data-session-row="' . $own_session . '"[^>]*>.*?<td data-cell="taken">1<\/td><td data-cell="free">9<\/td>/s', $own_html) === 1,
    'accessible table missing or out of step with the drawing'
);

reset_state();
assert_test(
    'L10 rendering without an event, or for an event that does not exist, says so instead of drawing an empty frame',
    str_contains(CyberEvents_Seat_Map::render(0), 'cybevents-notice')
        && str_contains(CyberEvents_Seat_Map::render(999999), 'cybevents-notice'),
    'missing event not reported'
);

// The stream: registered, rate-limited public access, and bounded.
reset_state();
CyberEvents_REST_API::register_routes();
assert_test(
    'L11 /seat-stream is registered as a public GET route with rate-limited permission_callback and required event_id',
    isset($mock_rest_routes['cybevents/v1/seat-stream'])
        && $mock_rest_routes['cybevents/v1/seat-stream']['methods'] === 'GET'
        && $mock_rest_routes['cybevents/v1/seat-stream']['permission_callback'] === ['CyberEvents_REST_API', 'check_public_events_access']
        && true === ($mock_rest_routes['cybevents/v1/seat-stream']['args']['event_id']['required'] ?? null),
    json_encode(array_keys($mock_rest_routes))
);

$stream_body = function_body('includes/class-seat-map.php', 'stream');
assert_test(
    'L12 the stream sets the SSE headers, disables proxy buffering, closes after its own limit and tells the browser when to come back',
    str_contains($stream_body, "text/event-stream")
        && str_contains($stream_body, "X-Accel-Buffering")
        && str_contains($stream_body, 'time() - $started < self::SSE_MAX_SECONDS')
        && str_contains($stream_body, 'connection_aborted()')
        && str_contains($stream_body, "'retry: '"),
    'stream body missing a bound or a header'
);

assert_test(
    'L13 the stream writes an event only when the digest moved, with a heartbeat so a proxy does not close an idle connection',
    str_contains($stream_body, "\$snapshot['digest'] !== \$last_digest")
        && str_contains($stream_body, 'SSE_HEARTBEAT_SECONDS')
        && str_contains($stream_body, ': keep-alive'),
    'stream writes on every tick or has no heartbeat'
);

// The browser paints what the server sends, with the same geometry.
$seatmap_js = source_of('public/js/cybevents-seatmap.js');
assert_test(
    'L14 the script repaints from the snapshot and never computes occupancy itself',
    str_contains($seatmap_js, 'i < session.taken')
        && ! preg_match('/taken\s*[+-]{2}|taken\s*[+-]=/', $seatmap_js),
    'the script changes occupancy on its own'
);

assert_test(
    'L15 the script reads the geometry from the markup instead of hardcoding a second copy of it',
    str_contains($seatmap_js, 'root.dataset.eventId')
        && str_contains($seatmap_js, 'root.dataset.stream')
        && str_contains($seatmap_js, 'root.dataset.digest')
        && ! preg_match('/var\s+(CELL|PER_LINE)\s*=/', $seatmap_js),
    'the script carries its own geometry'
);

assert_test(
    'L16 the script falls back to polling where EventSource is missing, instead of leaving the map frozen',
    str_contains($seatmap_js, 'if (window.EventSource)') && str_contains($seatmap_js, 'window.setInterval'),
    'no fallback for browsers without EventSource'
);

assert_test(
    'L16b the script takes the token from the query string, never from a data attribute of the markup',
    str_contains($seatmap_js, 'function tokenFromUrl()')
        && str_contains($seatmap_js, "URLSearchParams(window.location.search).get('token')")
        && ! str_contains($seatmap_js, 'dataset.token'),
    'the script reads the token from the markup'
);

assert_test(
    'L17 clicking a row books through /book-sessions, so the conditional UPDATE still decides the last seat',
    str_contains($seatmap_js, "settings.root + '/book-sessions'") && ! str_contains($seatmap_js, 'belegt'),
    'the script books outside the atomic route'
);

// The block and the shortcode reach the same renderer.
reset_state();
$block_event = seed_event();
seed_session($block_event, 5, 1);
$mock_is_admin = false;
$block_html = CyberEvents_Blocks_Manager::render_seat_map_block(['eventId' => $block_event]);
$mock_is_admin = true;
$editor_html = CyberEvents_Blocks_Manager::render_seat_map_block(['eventId' => $block_event]);
$mock_is_admin = false;
assert_test(
    'L18 the block renders the map in the frontend and a preview card in the editor',
    str_contains($block_html, 'cybevents-seatmap-svg')
        && str_contains($editor_html, 'cybevents-gutenberg-preview-box')
        && ! str_contains($editor_html, 'cybevents-seatmap-svg'),
    'block output wrong for one of the two contexts'
);

$main_source = source_of('wm-cyber-events-core.php');
assert_test(
    'L19 the shortcode [cybevents_seat_map] exists and hands its attributes to the same renderer',
    str_contains($main_source, "add_shortcode( 'cybevents_seat_map'")
        && str_contains($main_source, 'CyberEvents_Seat_Map::render('),
    'shortcode missing'
);

// ============================================================
// Group M — v1.1.0: 11/0 Gutenberg blocks, REST rate-limit gate, D-UNINSTALL, Ecosystem hooks
// ============================================================
require_once ABSPATH . 'wm-cyber-events-core.php';

reset_state();
$adapter = new CyberEvents_Spoke_Adapter();
// Read from the header, not a literal: a literal here had to be edited by hand on every release.
preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$/m', $main_source, $m1_header);
$m1_version = $m1_header[1] ?? 'missing';
assert_test(
    'M1 the plugin header version is synced with CYBEREVENTS_VERSION (main file and test harness) and the Suite Hub spoke adapter',
    CYBEREVENTS_VERSION === $m1_version
        && preg_match("/define\(\s*'CYBEREVENTS_VERSION',\s*'" . preg_quote($m1_version, '/') . "'\s*\)/", $main_source) === 1
        && $adapter->get_version() === $m1_version,
    'version mismatch: header=' . $m1_version . ' const=' . CYBEREVENTS_VERSION . ' adapter=' . $adapter->get_version()
);

reset_state();
$block_dirs = glob(ABSPATH . 'blocks/*/block.json') ?: [];
$blocks_with_editor = 0;
foreach ($block_dirs as $bj_path) {
    $bj = json_decode((string) file_get_contents($bj_path), true);
    if (is_array($bj) && ($bj['editorScript'] ?? '') === 'cybevents-blocks-editor-js' && ($bj['editorStyle'] ?? '') === 'cybevents-blocks-editor-css') {
        $blocks_with_editor++;
    }
}
CyberEvents_Blocks_Manager::register_blocks();
$editor_js_src = source_of('blocks/editor.js');
assert_test(
    'M2 all 11 Gutenberg blocks declare editorScript, register editor translations, and appear in blocks/editor.js',
    count($block_dirs) === 11
        && $blocks_with_editor === 11
        && isset($mock_registered_scripts['cybevents-blocks-editor-js'])
        && ($mock_script_translations['cybevents-blocks-editor-js']['domain'] ?? '') === 'wm-cyber-events-core'
        && count($mock_registered_blocks) === 11
        && str_contains($editor_js_src, "'wm-cyber-events/seat-map'")
        && str_contains($editor_js_src, "'wm-cyber-events/calendar-views'"),
    "block_dirs=" . count($block_dirs) . " with_editor={$blocks_with_editor} registered=" . count($mock_registered_blocks)
);

reset_state();
CyberEvents_REST_API::register_routes();
$public_routes = [
    'cybevents/v1/register',
    'cybevents/v1/confirm',
    'cybevents/v1/session-selection',
    'cybevents/v1/book-sessions',
    'cybevents/v1/calendar',
    'cybevents/v1/cancel-registration',
    'cybevents/v1/upload-avatar',
    'cybevents/v1/ticket',
    'cybevents/v1/seat-stream',
    'cybevents/v1/seat-snapshot',
    'cybevents/v1/venues',
];
$all_callbacks_hardened = true;
foreach ($public_routes as $route_key) {
    if (($mock_rest_routes[$route_key]['permission_callback'] ?? null) !== ['CyberEvents_REST_API', 'check_public_events_access']) {
        $all_callbacks_hardened = false;
    }
}
$GLOBALS['mock_user_can_manage_options'] = false;
$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
add_filter('wm_cybevents_public_rest_rate_limit', static fn(): int => 2);
$pub_1 = CyberEvents_REST_API::check_public_events_access(new WP_REST_Request('GET', '/cybevents/v1/venues'));
$pub_2 = CyberEvents_REST_API::check_public_events_access(new WP_REST_Request('GET', '/cybevents/v1/venues'));
$pub_3 = CyberEvents_REST_API::check_public_events_access(new WP_REST_Request('GET', '/cybevents/v1/venues'));
$GLOBALS['mock_user_can_manage_options'] = true;
$pub_admin = CyberEvents_REST_API::check_public_events_access(new WP_REST_Request('GET', '/cybevents/v1/venues'));
$raw_ip_leaked = false;
foreach (array_keys($mock_transients) as $t_key) {
    if (str_contains((string) $t_key, '198.51.100.42')) {
        $raw_ip_leaked = true;
    }
}
assert_test(
    'M3 all 11 public REST routes enforce blinded-IP rate limiting (HTTP 429 over limit, admin bypass, zero raw IP stored)',
    $all_callbacks_hardened
        && $pub_1 === true
        && $pub_2 === true
        && is_wp_error($pub_3)
        && $pub_3->get_error_code() === 'wm_cybevents_rate_limited'
        && ($pub_3->data['status'] ?? 0) === 429
        && $pub_admin === true
        && !$raw_ip_leaked,
    'callbacks=' . var_export($all_callbacks_hardened, true) . ' pub3=' . var_export($pub_3, true) . ' leaked=' . var_export($raw_ip_leaked, true)
);

reset_state();
$ev_m = seed_event();
$reg_cancelled = seed_registration($ev_m, 'cancelled');
$GLOBALS['mock_user_can_manage_options'] = false;
$unauth_no_token = CyberEvents_REST_API::handle_upload_avatar(rest_request([]));
$unauth_cancelled = CyberEvents_REST_API::handle_upload_avatar(rest_request(['token' => $reg_cancelled['token']]));
$unauth_bad_nonce = CyberEvents_REST_API::handle_upload_avatar(rest_request(['token' => seed_registration($ev_m, 'confirmed')['token']], ['X-WP-Nonce' => 'forged']));
$GLOBALS['mock_user_can_manage_options'] = true;
$unauth_admin_no_nonce = CyberEvents_REST_API::handle_upload_avatar(rest_request(['registration_id' => $reg_cancelled['id']]));
assert_test(
    'M3b /upload-avatar rejects requests without a valid active participant token or admin REST nonce (HTTP 403)',
    $unauth_no_token->get_status() === 403
        && $unauth_cancelled->get_status() === 403
        && $unauth_bad_nonce->get_status() === 403
        && $unauth_admin_no_nonce->get_status() === 403,
    'statuses: ' . implode(',', [$unauth_no_token->get_status(), $unauth_cancelled->get_status(), $unauth_bad_nonce->get_status(), $unauth_admin_no_nonce->get_status()])
);

reset_state();
if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', true);
}
$ev_u = seed_event(['title' => 'Persistenzkonferenz']);
update_option('cybevents_settings', ['invoice_prefix' => 'KEEP-2026-']);
update_option('wm_cybevents_delete_data_on_uninstall', 0);
include ABSPATH . 'uninstall.php';
$preserved_default = isset($mock_db_tables['wp_cybevents_events'][$ev_u])
    && (get_option('cybevents_settings')['invoice_prefix'] ?? '') === 'KEEP-2026-';

update_option('wm_cybevents_delete_data_on_uninstall', 1);
include ABSPATH . 'uninstall.php';
$purged_opt_in = !isset($mock_db_tables['wp_cybevents_events'])
    && !isset($mock_db_tables['wp_cybevents_registrations'])
    && !isset($mock_db_tables['wp_cybevents_invoices'])
    && !isset($mock_db_tables['wp_cybevents_audit_log'])
    && get_option('cybevents_settings', null) === null;
assert_test(
    'M4 uninstall.php preserves all tables and settings by default and drops all 7 tables only when opted in (D-UNINSTALL)',
    $preserved_default && $purged_opt_in,
    'preserved=' . var_export($preserved_default, true) . ' purged=' . var_export($purged_opt_in, true)
);

reset_state();
update_option('cybevents_db_version', CyberEvents_Activator::DB_VERSION);
$ev_e = seed_event(['title' => 'Kongress Digital', 'max_capacity' => 50]);
seed_registration($ev_e, 'confirmed');
seed_registration($ev_e, 'confirmed', ['checked_in' => 1]);
$ready_ver = '';
add_action('wm_cybevents_ready', static function (string $ver) use (&$ready_ver): void {
    $ready_ver = $ver;
});
add_filter('cron_schedules', ['CyberEvents_Activator', 'register_cron_schedules']);
cybevents_init();
$bb_cb = $mock_block_bindings['wm-cybevents/metrics']['get_value_callback'] ?? null;
$bb_active = is_callable($bb_cb) ? $bb_cb(['key' => 'active_events']) : '';
$bb_free   = is_callable($bb_cb) ? $bb_cb(['key' => 'event_free_seats', 'event_id' => $ev_e]) : '';
$gb_out    = apply_filters('generateblocks_dynamic_tag_replacement', '', ['tag' => 'wm_cybevents_metric', 'options' => ['key' => 'confirmed_registrations', 'event_id' => $ev_e]]);
$gb_native = apply_filters('generateblocks_dynamic_tag_output', 'Post Title', ['source' => 'post'], 'Post Title');
add_filter('wm_cybevents_metric_value', static fn(string $val, string $metric): string => $metric === 'active_events' ? $val . ' aktiv' : $val, 10, 2);
$filtered_val = cybevents_get_metric_value('active_events');
assert_test(
    'M5 ecosystem bridges register wm-cybevents/metrics Block Bindings, wm_cybevents_metric GB tag, and wm_cybevents_ready / wm_cybevents_metric_value hooks',
    $ready_ver === CYBEREVENTS_VERSION
        && $bb_active === '1'
        && $bb_free === '48'
        && $gb_out === '2'
        && $gb_native === 'Post Title'
        && $filtered_val === '1 aktiv',
    "ready={$ready_ver} active={$bb_active} free={$bb_free} gb={$gb_out} filtered={$filtered_val}"
);

// ============================================================
// Group N — R2-B1 (ETag + 304 cached polling + Page Visibility) & R2-B8 (Multisite + autoload=false)
// ============================================================
reset_state();
CyberEvents_REST_API::register_routes();
$snap_route = $mock_rest_routes['cybevents/v1/seat-snapshot'] ?? [];
assert_test(
    'N1 /seat-snapshot is registered as a public GET route with rate-limited permission_callback and required integer event_id',
    ($snap_route['methods'] ?? '') === 'GET'
        && ($snap_route['callback'] ?? null) === ['CyberEvents_REST_API', 'handle_seat_snapshot']
        && ($snap_route['permission_callback'] ?? null) === ['CyberEvents_REST_API', 'check_public_events_access']
        && true === ($snap_route['args']['event_id']['required'] ?? null)
        && 'integer' === ($snap_route['args']['event_id']['type'] ?? ''),
    json_encode($snap_route)
);

reset_state();
$n_ev   = seed_event();
$n_sess = seed_session($n_ev, 8, 2);
$snap_200 = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $n_ev]));
$snap_data = $snap_200->get_data();
$etag_hdr  = $snap_200->headers['ETag'] ?? '';
$cc_hdr    = $snap_200->headers['Cache-Control'] ?? '';
$snap_304_strong = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $n_ev], ['If-None-Match' => $etag_hdr]));
$snap_304_weak   = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $n_ev], ['If-None-Match' => 'W/' . $etag_hdr]));
$snap_mismatch   = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $n_ev], ['If-None-Match' => '"stale-etag"']));
assert_test(
    'N2 /seat-snapshot emits ETag and private, no-cache headers and answers 304 with null body on matching strong or weak If-None-Match',
    $snap_200->get_status() === 200
        && is_array($snap_data) && isset($snap_data['digest']) && ($snap_data['sessions'][0]['taken'] ?? -1) === 2
        && $etag_hdr === '"' . $snap_data['digest'] . '"'
        && $cc_hdr === 'private, no-cache'
        && $snap_304_strong->get_status() === 304 && $snap_304_strong->get_data() === null
        && ($snap_304_strong->headers['ETag'] ?? '') === $etag_hdr
        && ($snap_304_strong->headers['Cache-Control'] ?? '') === 'private, no-cache'
        && $snap_304_weak->get_status() === 304 && $snap_304_weak->get_data() === null
        && $snap_mismatch->get_status() === 200 && is_array($snap_mismatch->get_data()),
    json_encode(['etag' => $etag_hdr, 'cc' => $cc_hdr, 's304' => $snap_304_strong->get_status(), 'w304' => $snap_304_weak->get_status(), 'mis' => $snap_mismatch->get_status()])
);

reset_state();
$c_ev   = seed_event();
$c_sess = seed_session($c_ev, 10, 0);
$reads_cold = reads_during(static function () use ($c_ev) {
    CyberEvents_Seat_Map::cached_snapshot($c_ev);
});
$reads_warm = reads_during(static function () use ($c_ev) {
    CyberEvents_Seat_Map::cached_snapshot($c_ev);
});
$mock_cache = []; // simulate next PHP request without persistent object cache; transient must serve
$reads_transient = reads_during(static function () use ($c_ev) {
    CyberEvents_Seat_Map::cached_snapshot($c_ev);
});
CyberEvents_Seat_Map::invalidate_cache($c_ev);
$cache_after_inv     = $mock_cache['cybevents_seats']['cybevents_seat_snap_' . $c_ev] ?? null;
$transient_after_inv = $mock_transients['cybevents_seat_snap_' . $c_ev] ?? null;
$mock_ext_cache = true;
CyberEvents_Seat_Map::cached_snapshot($c_ev);
$transients_with_ext_cache = $mock_transients;
CyberEvents_Seat_Map::invalidate_cache($c_ev);
$reads_rest_cold = reads_during(static function () use ($c_ev) {
    CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $c_ev]));
});
$reads_rest_warm = reads_during(static function () use ($c_ev) {
    CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $c_ev]));
});
assert_test(
    'N3 cached_snapshot() and /seat-snapshot cache for 3s in cybevents_seats and transients (zero DB reads on hit), skip transients with external cache, and invalidate_cache() deletes both',
    count($reads_cold) > 0 && $reads_warm === [] && $reads_transient === []
        && count($reads_rest_cold) > 0 && $reads_rest_warm === []
        && $cache_after_inv === null && $transient_after_inv === null
        && $transients_with_ext_cache === [],
    json_encode(['cold' => count($reads_cold), 'warm' => count($reads_warm), 'trans' => count($reads_transient), 'rest_cold' => count($reads_rest_cold), 'rest_warm' => count($reads_rest_warm), 'ext_trans' => count($transients_with_ext_cache)])
);

reset_state();
$inv_ev   = seed_event();
$inv_sess = seed_session($inv_ev, 10, 0);
$inv_reg1 = seed_registration($inv_ev, 'confirmed', ['email' => 'cache1@example.org']);
$inv_reg2 = seed_registration($inv_ev, 'confirmed', ['email' => 'cache2@example.org']);
$snap_before_book = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev]));
$etag_before_book = $snap_before_book->headers['ETag'] ?? '';

CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $inv_reg1['token'], 'event_id' => $inv_ev, 'session_ids' => [$inv_sess]]));
$snap_after_book = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev], ['If-None-Match' => $etag_before_book]));
$etag_after_book = $snap_after_book->headers['ETag'] ?? '';

CyberEvents_REST_API::handle_cancel_registration(rest_request(['token' => $inv_reg1['token']]));
$snap_after_cancel = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev], ['If-None-Match' => $etag_after_book]));

CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $inv_reg2['token'], 'event_id' => $inv_ev, 'session_ids' => [$inv_sess]]));
$snap_before_bounce = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev]));
$etag_before_bounce = $snap_before_bounce->headers['ETag'] ?? '';
set_settings(['brevo_webhook_secret' => 'wh_cache_secret']);
$bounce_cache_req = rest_request([], ['X-Brevo-Webhook-Secret' => 'wh_cache_secret']);
$bounce_cache_req->set_body((string) json_encode(['event' => 'hard_bounce', 'email' => 'cache2@example.org']));
CyberEvents_REST_API::handle_brevo_webhook($bounce_cache_req);
$snap_after_bounce = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev], ['If-None-Match' => $etag_before_bounce]));

$inv_reg3 = seed_registration($inv_ev, 'confirmed', ['email' => 'cache3@example.org']);
CyberEvents_REST_API::handle_book_sessions(rest_request(['token' => $inv_reg3['token'], 'event_id' => $inv_ev, 'session_ids' => [$inv_sess]]));
$snap_before_delete = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev]));
$etag_before_delete = $snap_before_delete->headers['ETag'] ?? '';
CyberEvents_GDPR_Manager::delete_registration($inv_reg3['id']);
$snap_after_delete = CyberEvents_REST_API::handle_seat_snapshot(rest_request(['event_id' => $inv_ev], ['If-None-Match' => $etag_before_delete]));

assert_test(
    'N4 booking, self-cancellation, Brevo bounce webhook and GDPR deletion invalidate the cached snapshot so /seat-snapshot returns 200 with updated occupancy',
    $snap_after_book->get_status() === 200 && ($snap_after_book->get_data()['sessions'][0]['taken'] ?? -1) === 1
        && $etag_after_book !== $etag_before_book
        && $snap_after_cancel->get_status() === 200 && ($snap_after_cancel->get_data()['sessions'][0]['taken'] ?? -1) === 0
        && $snap_after_bounce->get_status() === 200 && ($snap_after_bounce->get_data()['sessions'][0]['taken'] ?? -1) === 0
        && $snap_after_delete->get_status() === 200 && ($snap_after_delete->get_data()['sessions'][0]['taken'] ?? -1) === 0,
    json_encode([
        'book'   => [$snap_after_book->get_status(), $snap_after_book->get_data()['sessions'][0]['taken'] ?? null],
        'cancel' => [$snap_after_cancel->get_status(), $snap_after_cancel->get_data()['sessions'][0]['taken'] ?? null],
        'bounce' => [$snap_after_bounce->get_status(), $snap_after_bounce->get_data()['sessions'][0]['taken'] ?? null],
        'delete' => [$snap_after_delete->get_status(), $snap_after_delete->get_data()['sessions'][0]['taken'] ?? null],
    ])
);

reset_state();
$r_ev = seed_event();
seed_session($r_ev, 5, 1);
$default_map_html = CyberEvents_Seat_Map::render($r_ev);
add_filter('cybevents_seatmap_transport', static fn(string $t, int $eid): string => $eid === $r_ev ? 'sse' : $t, 10, 2);
$sse_map_html = CyberEvents_Seat_Map::render($r_ev);
assert_test(
    'N5 render() emits data-snapshot and defaults data-transport to etag-poll while keeping data-stream and respecting cybevents_seatmap_transport',
    str_contains($default_map_html, 'data-stream="' . esc_attr(rest_url('cybevents/v1/seat-stream')) . '"')
        && str_contains($default_map_html, 'data-snapshot="' . esc_attr(rest_url('cybevents/v1/seat-snapshot')) . '"')
        && str_contains($default_map_html, 'data-transport="etag-poll"')
        && str_contains($sse_map_html, 'data-transport="sse"'),
    substr($default_map_html, 0, 260)
);

$seatmap_js = source_of('public/js/cybevents-seatmap.js');
assert_test(
    'N6 seatmap script polls /seat-snapshot every 3000ms with If-None-Match, ignores 304, pauses on visibilityState hidden and polls immediately on return to visible',
    str_contains($seatmap_js, 'root.dataset.snapshot')
        && str_contains($seatmap_js, 'root.dataset.transport')
        && str_contains($seatmap_js, "'sse' !== transport")
        && str_contains($seatmap_js, "headers['If-None-Match']")
        && str_contains($seatmap_js, '304 === response.status')
        && str_contains($seatmap_js, '3000')
        && str_contains($seatmap_js, "document.addEventListener('visibilitychange'")
        && str_contains($seatmap_js, "document.visibilityState === 'hidden'")
        && str_contains($seatmap_js, "document.visibilityState === 'visible'")
        && str_contains($seatmap_js, 'clearInterval'),
    'seatmap script missing ETag polling or Page Visibility pause'
);

reset_state();
$GLOBALS['mock_is_multisite']   = true;
$GLOBALS['mock_network_active'] = false;
do_action('wp_initialize_site', (object) ['blog_id' => 2]);
$not_network_switched = $mock_blog_switches;
$not_network_tables   = isset($mock_db_tables['wp_2_cybevents_events']);

$GLOBALS['mock_network_active'] = true;
do_action('wp_initialize_site', (object) ['blog_id' => 2]);
$network_switched = $mock_blog_switches;
$ms_tables_ok = isset(
    $mock_db_tables['wp_2_cybevents_events'],
    $mock_db_tables['wp_2_cybevents_sessions'],
    $mock_db_tables['wp_2_cybevents_registrations'],
    $mock_db_tables['wp_2_cybevents_registration_sessions'],
    $mock_db_tables['wp_2_cybevents_invoices'],
    $mock_db_tables['wp_2_cybevents_queue'],
    $mock_db_tables['wp_2_cybevents_audit_log']
) && count($mock_db_tables['wp_2_cybevents_events']) === 0;
$prefix_restored = ($wpdb->prefix === 'wp_') && ($wpdb->posts === 'wp_posts');
assert_test(
    'N7 wp_initialize_site provisions all 7 tables on new Multisite blogs when network-activated and restores current blog',
    $not_network_switched === [] && !$not_network_tables
        && $network_switched === [2] && $ms_tables_ok && $prefix_restored,
    json_encode(['not_net' => $not_network_switched, 'net' => $network_switched, 'tables' => $ms_tables_ok, 'prefix' => $wpdb->prefix])
);

$admin_src = source_of('includes/class-admin.php');
$db_src    = source_of('includes/class-database.php');
assert_test(
    'N8 internal state options not needed on every frontend request pass autoload=false',
    str_contains($admin_src, "update_option( 'wm_cybevents_delete_data_on_uninstall', isset( \$_POST['wm_cybevents_delete_data_on_uninstall'] ) ? 1 : 0, false )")
        && str_contains($db_src, 'update_option( self::INVOICE_SEQUENCE_OPTION, $next, false )')
        && (bool) preg_match('/update_option\(\s*self::AUDIT_TIP_OPTION,.*?,\s*false\s*\)/s', $db_src),
    'missing autoload=false on internal state option'
);

// ============================================================
// Group O — 1.2.0 / 1.2.4: Calendar Views icons & dynamic parameterization, editor canvas CSS, print rule
// ============================================================
reset_state();
$o1_event = seed_event(['title' => 'Herbstforum', 'location' => 'Saal 3', 'start_date' => '2030-10-15 09:00:00', 'end_date' => '2030-10-15 17:00:00']);
$wpdb->insert('wp_cybevents_sessions', [
    'event_id'   => $o1_event,
    'title'      => 'Auftakt-Session',
    'speaker'    => 'Kim Beispiel',
    'raum'       => 'Saal 3',
    'start_time' => '2030-10-15 09:30:00',
    'end_time'   => '2030-10-15 11:00:00',
    'kapazitaet' => 50,
    'belegt'     => 10,
]);
$views_html = CyberEvents_Calendar_Views::render([]);
$views_src  = php_code_without_comments('includes/class-calendar-views.php');
preg_match_all("/CyberEvents_Icons::svg\(\s*'([a-z-]+)'/", $views_src, $views_icons);
$unknown_icons = array_values(array_filter(array_unique($views_icons[1]), static fn(string $name): bool => CyberEvents_Icons::svg($name) === ''));
$views_svgs    = substr_count($views_html, '<svg class="cybevents-icon"');
$total_svg_calls = substr_count($views_src, 'CyberEvents_Icons::svg(');
assert_test(
    'O1 Calendar Views carries no emoji and no diamond glyph: every icon comes from CyberEvents_Icons, is wrapped in wp_kses and is known by name',
    emoji_in($views_html) === [] && !str_contains($views_html, '◆')
        && $total_svg_calls >= 12
        && substr_count($views_src, 'wp_kses( CyberEvents_Icons::svg(') === $total_svg_calls
        && $unknown_icons === []
        && $views_svgs >= 12,
    json_encode(['emoji' => emoji_in($views_html), 'calls' => $total_svg_calls, 'kses' => substr_count($views_src, 'wp_kses( CyberEvents_Icons::svg('), 'svgs' => $views_svgs, 'unknown' => $unknown_icons], JSON_UNESCAPED_UNICODE)
);

assert_test(
    'O2 Calendar Views adds no second main landmark to the page and sets no fixed colour in its markup',
    !str_contains($views_html, '<main') && !preg_match('/style="[^"]*#[0-9a-fA-F]{3,6}/', $views_html),
    'main element or inline hex colour in the rendered view'
);

reset_state();
$mock_enqueued_styles = [];
$mock_inline_styles   = [];
set_settings(['theme_preset' => 'enterprise_dark']);
cybevents_enqueue_editor_canvas_assets();
$canvas_frontend = [$mock_enqueued_styles, $mock_inline_styles];
$mock_is_admin = true;
cybevents_enqueue_editor_canvas_assets();
$mock_is_admin = false;
assert_test(
    'O3 the block editor canvas, where Calendar Views and the seat map render with ServerSideRender, gets the frontend stylesheet and the theme preset through enqueue_block_assets; on the frontend it leaves both to wp_enqueue_scripts',
    in_array('cybevents_enqueue_editor_canvas_assets', $mock_actions['enqueue_block_assets'] ?? [], true)
        && $canvas_frontend === [[], []]
        && str_ends_with((string) ($mock_enqueued_styles['cybevents-public']['src'] ?? ''), 'public/css/cybevents-public.css')
        && str_contains(implode("\n", $mock_inline_styles['cybevents-public'] ?? []), '--cyb-primary: #0052cc;'),
    json_encode(['frontend' => $canvas_frontend, 'styles' => array_keys($mock_enqueued_styles), 'inline' => array_map('count', $mock_inline_styles)])
);

$public_css = source_of('public/css/cybevents-public.css');
assert_test(
    'O4 the print rule of the ticket pass hides the rest of a page only on a page that shows a pass, without outranking the rules that show the pass',
    str_contains($public_css, 'body:where(:has(#cybevents-printable-badge)) * { visibility: hidden; }')
        && !preg_match('/^\s*body \* \{ visibility: hidden; \}/m', $public_css),
    'print rule not scoped to a page with a ticket pass'
);

// A 1fr track is minmax(auto, 1fr): measured in the block editor on 2026-11-20, the day cells grew to their
// longest chip title and no longer lined up with the weekday header.
$o5_rule = static function (string $selector) use ($public_css): string {
    return preg_match('/^' . preg_quote($selector, '/') . ' \{([^}]*)\}/m', $public_css, $m) ? $m[1] : '';
};
$o5_header = $o5_rule('.cybevents-month-header-row');
$o5_cells  = $o5_rule('.cybevents-month-cells');
$o5_cell   = $o5_rule('.cybevents-day-cell');
assert_test(
    'O5 the month grid keeps seven equal columns in a narrow container such as the block editor: both rows use minmax(0, 1fr) and a day cell may shrink below its longest chip title',
    str_contains($o5_header, 'grid-template-columns: repeat(7, minmax(0, 1fr));')
        && str_contains($o5_cells, 'grid-template-columns: repeat(7, minmax(0, 1fr));')
        && 1 === preg_match('/^\s*min-width: 0;$/m', $o5_cell),
    (string) json_encode(['header' => trim($o5_header), 'cells' => trim($o5_cells), 'cell' => trim($o5_cell)])
);

reset_state();
$empty_views = CyberEvents_Calendar_Views::render(['month' => '2028-02']);
$public_js   = source_of('public/js/cybevents-public.js');
$forbidden_demo = ['Oktober 2026', 'November 2026', 'Dr. Johannes Weber', 'Prof. Dr. M. Richter', 'Kongresszentrum Musterstadt', 'ZUGFeRD Konform', '100% Barrierefrei'];
$found_forbidden = [];
foreach ($forbidden_demo as $needle) {
    if (str_contains($empty_views, $needle) || str_contains($public_js, $needle)) {
        $found_forbidden[] = $needle;
    }
}
assert_test(
    'O6 empty Calendar Views renders exact leap-year month grid for requested month without hardcoded October 2026 or invented demo strings (in PHP or JS)',
    str_contains($empty_views, 'data-year="2028"')
        && str_contains($empty_views, 'data-month="2"')
        && str_contains($empty_views, 'data-day="29"')
        && !str_contains($empty_views, 'data-day="30"')
        && str_contains($empty_views, 'Keine Veranstaltungen gefunden.')
        && $found_forbidden === [],
    json_encode(['forbidden' => $found_forbidden, 'year2028' => str_contains($empty_views, 'data-year="2028"'), 'day29' => str_contains($empty_views, 'data-day="29"')], JSON_UNESCAPED_UNICODE)
);

reset_state();
$dyn_ev = seed_event(['title' => 'Frühjahrs-Tagung 2027', 'location' => 'Stadthalle Nord', 'start_date' => '2027-05-20 09:00:00', 'end_date' => '2027-05-20 17:00:00']);
$wpdb->insert('wp_cybevents_sessions', [
    'event_id'   => $dyn_ev,
    'title'      => 'Kryptografie-Praxis',
    'speaker'    => 'Alex Beispiel',
    'raum'       => 'Saal Nord',
    'start_time' => '2027-05-20 10:00:00',
    'end_time'   => '2027-05-20 11:30:00',
    'kapazitaet' => 30,
    'belegt'     => 5,
]);
$dyn_views = CyberEvents_Calendar_Views::render([]);
$param_views = CyberEvents_Calendar_Views::render_shortcode(['month' => '2029-11']);
assert_test(
    'O7 Calendar Views renders stored active events and sessions dynamically across views and accepts parameterized month shortcode attribute',
    str_contains($dyn_views, 'data-year="2027"')
        && str_contains($dyn_views, 'data-month="5"')
        && str_contains($dyn_views, 'Frühjahrs-Tagung 2027')
        && str_contains($dyn_views, 'Stadthalle Nord')
        && str_contains($dyn_views, 'Kryptografie-Praxis')
        && str_contains($dyn_views, 'Alex Beispiel')
        && str_contains($dyn_views, 'Saal Nord')
        && str_contains($dyn_views, '25 freie Plätze')
        && str_contains($param_views, 'data-year="2029"')
        && str_contains($param_views, 'data-month="11"'),
    json_encode(['dyn_year' => str_contains($dyn_views, 'data-year="2027"'), 'param_year' => str_contains($param_views, 'data-year="2029"')])
);

print_results();
exit($failed_count === 0 ? 0 : 1);
