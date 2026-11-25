<?php
/**
 * Standalone Automated Test Runner & Unit Suite for wm-cyber-events-core.
 * Bootstrap File (Harness)
 *
 * Runs without external WordPress/MySQL dependency by providing a high-fidelity
 * mock environment for wpdb, options, hooks, sanitization, and REST API.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Setup mock WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('CYBEREVENTS_VERSION')) {
    define('CYBEREVENTS_VERSION', '1.2.3');
}
if (!defined('CYBEREVENTS_PLUGIN_DIR')) {
    define('CYBEREVENTS_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('CYBEREVENTS_PLUGIN_URL')) {
    define('CYBEREVENTS_PLUGIN_URL', 'https://demo.example.com/wp-content/plugins/wm-cyber-events-core/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Global Mocks & In-Memory Store
global $mock_options, $mock_options_autoload, $mock_filters, $mock_actions, $mock_db_tables, $wpdb;
$mock_options          = [];
$mock_options_autoload = [];
$mock_filters          = [];
$mock_actions          = [];
$mock_db_tables = [
    'wp_cybevents_events'                => [],
    'wp_cybevents_sessions'              => [],
    'wp_cybevents_registrations'         => [],
    'wp_cybevents_registration_sessions' => [],
    'wp_cybevents_invoices'              => [],
    'wp_cybevents_audit_log'             => [],
    'wp_cybevents_queue'                 => [],
    'wp_cybevents_venues'                => [],
    'wp_posts'                           => [],
];

class Mock_WPDB {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $table_auto_increment = [];
    public array $queries = [];

    /** Every read that reached the database, so a test can assert that a request touched none. */
    public array $reads = [];

    /** Test hook: runs before update() applies, e.g. to let a concurrent request change the row first. */
    public $before_update = null;

    /** Column names per table, read from the CREATE TABLE statements in CyberEvents_Activator. */
    private ?array $schema = null;

    /** Map Multisite blog-prefixed table names (`wp_2_cybevents_*`) back to canonical schema keys. */
    private static function canonical_table(string $table): string {
        return (string) preg_replace('/^wp_\d+_/', 'wp_', $table);
    }

    private function columns(string $table): ?array {
        if ($this->schema === null) {
            $this->schema = [];
            $source = (string) file_get_contents(dirname(__DIR__) . '/includes/class-activator.php');
            preg_match_all('/CREATE TABLE \{\$wpdb->prefix\}(\w+) \((.*?)\) \$charset_collate/s', $source, $tables, PREG_SET_ORDER);
            foreach ($tables as [, $name, $body]) {
                preg_match_all('/^\s*(\w+)\s+(?:bigint|int|tinyint|varchar|longtext|text|datetime|decimal)\b(.*)$/m', $body, $cols, PREG_SET_ORDER);
                foreach ($cols as [, $col, $rest]) {
                    // The default MySQL writes for a column the INSERT leaves out.
                    if (preg_match("/DEFAULT '([^']*)'/", $rest, $d)) {
                        $default = $d[1];
                    } elseif (preg_match('/DEFAULT (-?\d+(?:\.\d+)?)/', $rest, $d)) {
                        $default = str_contains($d[1], '.') ? (float) $d[1] : (int) $d[1];
                    } elseif (str_contains($rest, 'DEFAULT CURRENT_TIMESTAMP')) {
                        $default = 'CURRENT_TIMESTAMP';
                    } else {
                        $default = null;
                    }
                    $this->schema['wp_' . $name][$col] = $default;
                }
            }
        }
        $canonical = self::canonical_table($table);
        return isset($this->schema[$canonical]) ? array_keys($this->schema[$canonical]) : null;
    }

    /** Row as MySQL stores it: every column present, left-out columns at their default. */
    private function with_defaults(string $table, array $data): array {
        if ($this->columns($table) === null) {
            return $data;
        }
        $canonical = self::canonical_table($table);
        foreach ($this->schema[$canonical] as $col => $default) {
            if ($col !== 'id' && !array_key_exists($col, $data)) {
                $data[$col] = $default === 'CURRENT_TIMESTAMP' ? date('Y-m-d H:i:s') : $default;
            }
        }
        return $data;
    }

    /** MySQL refuses a statement that names a column the table does not have; so does the mock. */
    private function unknown_column(string $table, array $names): ?string {
        $columns = $this->columns($table);
        if ($columns === null) {
            return null;
        }
        foreach ($names as $name) {
            if (!in_array($name, $columns, true)) {
                $this->last_error = "Unknown column '{$name}' in '{$table}'";
                return $name;
            }
        }
        return null;
    }

    public function insert(string $table, array $data, array $format = []) {
        global $mock_db_tables;
        if ($this->unknown_column($table, array_keys($data)) !== null) {
            return false;
        }
        if (!isset($this->table_auto_increment[$table])) {
            $keys = array_keys($mock_db_tables[$table] ?? []);
            $this->table_auto_increment[$table] = $keys ? max($keys) : 0;
        }
        $this->table_auto_increment[$table]++;
        $id = $this->table_auto_increment[$table];
        $this->insert_id = $id;
        $row = array_merge(['id' => $id], $this->with_defaults($table, $data));
        $mock_db_tables[$table][$id] = (object) $row;
        return 1;
    }

    private static function row_matches(object $row, array $where): bool {
        foreach ($where as $w_key => $w_val) {
            if (!property_exists($row, $w_key) || (string) $row->$w_key !== (string) $w_val) {
                return false;
            }
        }
        return true;
    }

    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        global $mock_db_tables;
        if ($this->unknown_column($table, array_merge(array_keys($data), array_keys($where))) !== null) {
            return false;
        }
        if (is_callable($this->before_update)) {
            ($this->before_update)($table, $data, $where);
        }
        $updated = 0;
        foreach ($mock_db_tables[$table] ?? [] as $id => $row) {
            if (self::row_matches($row, $where)) {
                foreach ($data as $d_key => $d_val) {
                    $row->$d_key = $d_val;
                }
                $mock_db_tables[$table][$id] = $row;
                $updated++;
            }
        }
        return $updated;
    }

    public function delete(string $table, array $where, array $format = []) {
        global $mock_db_tables;
        if ($this->unknown_column($table, array_keys($where)) !== null) {
            return false;
        }
        $deleted = 0;
        foreach ($mock_db_tables[$table] ?? [] as $id => $row) {
            if (self::row_matches($row, $where)) {
                unset($mock_db_tables[$table][$id]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /** Test hook: runs after get_results(), e.g. to let a second runner claim a queue job in between. */
    public $on_get_results = null;

    /** A literal as prepare() writes it: 'text' or a number. */
    private static function literal(string $raw) {
        $raw = trim($raw);
        return (strlen($raw) >= 2 && $raw[0] === "'") ? stripslashes(substr($raw, 1, -1)) : $raw;
    }

    /**
     * Conditions of a WHERE clause joined by AND: =, <>, <, <=, >, >=, IS NULL, LIKE, IN, NOT IN.
     * A condition the parser does not recognise fails the query loudly instead of matching every
     * row, so a test cannot pass because a filter was silently ignored.
     */
    private static function where_conditions(string $query): array {
        if (!preg_match('/\bWHERE\b(.*?)(?:\bORDER\s+BY\b|\bLIMIT\b|\bGROUP\s+BY\b|$)/is', $query, $m)) {
            return [];
        }
        $conditions = [];
        foreach (self::split_outside_quotes_and_parens($m[1], 'AND') as $part) {
            $conditions[] = self::parse_condition($part);
        }
        return $conditions;
    }

    /** Split on a keyword that stands outside quotes and parentheses. */
    private static function split_outside_quotes_and_parens(string $sql, string $keyword): array {
        $parts = [];
        $depth = 0;
        $quoted = false;
        $start = 0;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quoted) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === "'") {
                    $quoted = false;
                }
                continue;
            }
            if ($ch === "'") {
                $quoted = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($depth === 0 && preg_match('/\G\b' . $keyword . '\b/Ai', $sql, $mm, 0, $i) && ($i === 0 || !ctype_alnum($sql[$i - 1]))) {
                $parts[] = substr($sql, $start, $i - $start);
                $i += strlen($keyword) - 1;
                $start = $i + 1;
            }
        }
        $parts[] = substr($sql, $start);
        return array_map('trim', $parts);
    }

    private static function parse_condition(string $part): array {
        $lit = "('(?:[^'\\\\]|\\\\.)*'|-?\d+(?:\.\d+)?)";
        $part = trim($part);
        if (str_starts_with($part, '(') && str_ends_with($part, ')') && self::balanced(substr($part, 1, -1))) {
            $inner = substr($part, 1, -1);
            $ors = self::split_outside_quotes_and_parens($inner, 'OR');
            return count($ors) > 1 ? ['or', '', array_map([self::class, 'parse_condition'], $ors)] : self::parse_condition($inner);
        }
        if (preg_match("/^(?:\w+\.)?(\w+)\s*(=|<>|!=|<=|>=|<|>)\s*{$lit}$/s", $part, $c)) {
            return [$c[2] === '!=' ? '<>' : $c[2], $c[1], self::literal($c[3])];
        }
        if (preg_match('/^(?:\w+\.)?(\w+)\s+IS\s+NULL$/i', $part, $c)) {
            return ['null', $c[1], null];
        }
        if (preg_match("/^(?:\w+\.)?(\w+)\s+LIKE\s+{$lit}$/is", $part, $c)) {
            return ['like', $c[1], self::literal($c[2])];
        }
        if (preg_match('/^(?:\w+\.)?(\w+)\s+(NOT\s+)?IN\s*\((.*)\)$/is', $part, $c)) {
            preg_match_all("/{$lit}/", $c[3], $vals);
            return [trim($c[2]) !== '' ? 'notin' : 'in', $c[1], array_map([self::class, 'literal'], $vals[1])];
        }
        throw new RuntimeException("Mock_WPDB cannot evaluate WHERE condition: {$part}");
    }

    /** Whether the parentheses outside quotes balance (so "(a) OR (b)" is not taken as one group). */
    private static function balanced(string $sql): bool {
        $depth = 0;
        $quoted = false;
        for ($i = 0, $len = strlen($sql); $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quoted) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === "'") {
                    $quoted = false;
                }
                continue;
            }
            if ($ch === "'") {
                $quoted = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && --$depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    private static function condition_holds(object $row, string $type, string $col, $val): bool {
        if ($type === 'or') {
            foreach ($val as [$t, $c, $v]) {
                if (self::condition_holds($row, $t, $c, $v)) {
                    return true;
                }
            }
            return false;
        }
        $has = property_exists($row, $col);
        $cur = $has ? $row->$col : null;
        switch ($type) {
            case 'null':
                return $cur === null;
            case 'in':
                return $has && in_array((string) $cur, array_map('strval', $val), true);
            case 'notin':
                return !in_array((string) $cur, array_map('strval', $val), true);
            case 'like':
                $regex = '/^' . strtr(preg_quote((string) $val, '/'), ['%' => '.*', '_' => '.', '\\\\%' => '%', '\\\\_' => '_']) . '$/s';
                return $has && preg_match($regex, (string) $cur) === 1;
        }
        if (!$has || $cur === null) {
            return false;
        }
        $a = is_numeric($cur) && is_numeric($val) ? (float) $cur : (string) $cur;
        $b = is_numeric($cur) && is_numeric($val) ? (float) $val : (string) $val;
        return match ($type) {
            '='  => $a == $b,
            '<>' => $a != $b,
            '<'  => $a < $b,
            '<=' => $a <= $b,
            '>'  => $a > $b,
            '>=' => $a >= $b,
        };
    }

    private function rows_for(string $query): array {
        global $mock_db_tables;
        if (!preg_match('/\bFROM\s+([^\s]+)/i', $query, $m)) {
            return [];
        }
        $rows = array_values($mock_db_tables[$m[1]] ?? []);
        foreach (self::where_conditions($query) as [$type, $col, $val]) {
            $rows = array_values(array_filter($rows, static fn($row) => self::condition_holds($row, $type, $col, $val)));
        }
        if (preg_match('/ORDER\s+BY\s+(?:\w+\.)?id\s+DESC/i', $query)) {
            $rows = array_reverse($rows);
        }
        if (preg_match('/\bLIMIT\s+(\d+)/i', $query, $l)) {
            $rows = array_slice($rows, 0, (int) $l[1]);
        }
        return $rows;
    }

    private static function selected_column(string $query): ?string {
        if (preg_match('/^\s*SELECT\s+(?:\w+\.)?(\w+)\s+FROM\b/i', $query, $m) && $m[1] !== '*') {
            return $m[1];
        }
        return null;
    }

    public function query(string $query) {
        global $mock_db_tables;
        $this->queries[] = $query;
        if (preg_match('/TRUNCATE\s+TABLE\s+([^\s]+)/i', $query, $m)) {
            $mock_db_tables[$m[1]] = [];
            return 1;
        }
        if (preg_match('/DROP\s+TABLE\s+IF\s+EXISTS\s+`?([^\s`]+)`?/i', $query, $m)) {
            unset($mock_db_tables[$m[1]]);
            return 1;
        }
        // Atomic capacity update simulation: UPDATE table SET belegt = belegt + 1 WHERE id = %d AND belegt < kapazitaet
        if (preg_match('/UPDATE\s+([^\s]+)\s+SET\s+belegt\s*=\s*belegt\s*\+\s*1\s+WHERE\s+id\s*=\s*(\d+)\s+AND\s+belegt\s*<\s*kapazitaet/i', $query, $m)) {
            $table = $m[1];
            $id = (int)$m[2];
            if (isset($mock_db_tables[$table][$id])) {
                $session = $mock_db_tables[$table][$id];
                if ($session->belegt < $session->kapazitaet) {
                    $session->belegt++;
                    $mock_db_tables[$table][$id] = $session;
                    return 1;
                }
            }
            return 0;
        }
        if (preg_match('/UPDATE\s+([^\s]+)\s+SET\s+belegt\s*=\s*GREATEST\(0,\s*CAST\(belegt AS SIGNED\)\s*-\s*1\)\s+WHERE\s+id\s*=\s*(\d+)/i', $query, $m)) {
            if (isset($mock_db_tables[$m[1]][(int) $m[2]])) {
                $mock_db_tables[$m[1]][(int) $m[2]]->belegt = max(0, (int) $mock_db_tables[$m[1]][(int) $m[2]]->belegt - 1);
                return 1;
            }
            return 0;
        }
        if (preg_match('/^\s*DELETE\s+FROM\s+([^\s]+)/i', $query, $m)) {
            $deleted = 0;
            foreach ($this->rows_for(preg_replace('/^\s*DELETE\s+/i', 'SELECT * ', $query)) as $row) {
                unset($mock_db_tables[$m[1]][$row->id]);
                $deleted++;
            }
            return $deleted;
        }
        // UPDATE table SET col = 'literal'[, ...] WHERE ...
        if (preg_match("/^\s*UPDATE\s+([^\s]+)\s+SET\s+((?:\w+\s*=\s*(?:'(?:[^'\\\\]|\\\\.)*'|-?\d+)\s*,?\s*)+)\s+WHERE\s+(.*)$/is", $query, $m)) {
            preg_match_all("/(\w+)\s*=\s*('(?:[^'\\\\]|\\\\.)*'|-?\d+)/", $m[2], $sets, PREG_SET_ORDER);
            $changed = 0;
            foreach ($this->rows_for("SELECT * FROM {$m[1]} WHERE {$m[3]}") as $row) {
                foreach ($sets as [, $col, $val]) {
                    $mock_db_tables[$m[1]][$row->id]->$col = self::literal($val);
                }
                $changed++;
            }
            return $changed;
        }
        throw new RuntimeException('Mock_WPDB cannot execute query: ' . preg_replace('/\s+/', ' ', $query));
    }

    public function prepare(string $query, ...$args): string {
        if (isset($args[0]) && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $arg) {
            $val = is_numeric($arg) && !is_string($arg) ? $arg : "'" . addslashes((string)$arg) . "'";
            $query = preg_replace('/%[sdfFi]/', str_replace(['\\', '$'], ['\\\\', '\\$'], (string)$val), $query, 1);
        }
        return $query;
    }

    public function get_row(string $query, string $output = 'OBJECT') {
        $this->reads[] = $query;
        $rows = $this->rows_for($query);
        if (!$rows) {
            return null;
        }
        return $output === ARRAY_A ? (array) $rows[0] : clone $rows[0];
    }

    public function get_results(string $query, string $output = 'OBJECT'): array {
        $this->reads[] = $query;
        // Copies: a caller holding a row must not see later writes, as with a real result set.
        $rows = array_map(static fn($r) => clone $r, $this->rows_for($query));
        if (is_callable($this->on_get_results)) {
            ($this->on_get_results)($query, $rows);
        }
        return $output === ARRAY_A ? array_map(fn($r) => (array) $r, $rows) : $rows;
    }

    public function get_col(string $query): array {
        $this->reads[] = $query;
        $col = self::selected_column($query) ?? 'id';
        return array_map(static fn($r) => $r->$col ?? null, $this->rows_for($query));
    }

    public function get_var(string $query) {
        global $mock_db_tables;
        $this->reads[] = $query;
        if (preg_match("/SHOW\s+TABLES\s+LIKE\s+'([^']+)'/i", $query, $m)) {
            return array_key_exists($m[1], $mock_db_tables) ? $m[1] : null;
        }
        if (preg_match('/GET_LOCK|RELEASE_LOCK/i', $query)) {
            return 1;
        }
        if (preg_match('/MAX\(id\)\s+FROM\s+([^\s]+)/i', $query, $m)) {
            $keys = array_keys($mock_db_tables[$m[1]] ?? []);
            return !empty($keys) ? max($keys) : 0;
        }
        if (preg_match('/COUNT\(\*\)\s+FROM\s+([^\s]+)/i', $query)) {
            return count($this->rows_for($query));
        }
        $col = self::selected_column($query);
        $rows = $this->rows_for($query);
        return ($col && $rows) ? ($rows[0]->$col ?? null) : null;
    }
}

$wpdb = new Mock_WPDB();

// Mock WP core functions
if (!function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): void {
        global $mock_actions;
        $mock_actions[$tag][] = $callback;
    }
}
if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void {
        global $mock_actions;
        if (isset($mock_actions[$tag])) {
            foreach ($mock_actions[$tag] as $callback) {
                call_user_func($callback, ...$args);
            }
        }
    }
}
if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): void {
        global $mock_filters;
        $mock_filters[$tag][] = $callback;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) {
        global $mock_filters;
        if (isset($mock_filters[$tag])) {
            foreach ($mock_filters[$tag] as $callback) {
                $value = call_user_func($callback, $value, ...$args);
            }
        }
        return $value;
    }
}
if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool {
        global $mock_options, $mock_options_autoload;
        $mock_options[$option] = $value;
        if ($autoload !== null) {
            $mock_options_autoload[$option] = $autoload;
        }
        return true;
    }
}
if (!function_exists('add_option')) {
    function add_option(string $option, $value = '', string $deprecated = '', $autoload = null): bool {
        global $mock_options, $mock_options_autoload;
        if (array_key_exists($option, $mock_options)) {
            return false;
        }
        $mock_options[$option] = $value;
        if ($autoload !== null) {
            $mock_options_autoload[$option] = $autoload;
        }
        return true;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key));
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}
if (!function_exists('absint')) {
    function absint($maybeint): int {
        return abs((int)$maybeint);
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email): string {
        return filter_var((string)$email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data): string {
        return (string)$data;
    }
}
if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = 'post'): array {
        return [];
    }
}
if (!function_exists('wp_kses')) {
    function wp_kses($data, $allowed_html = [], $allowed_protocols = []): string {
        return (string)$data;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        global $mock_options;
        unset($mock_options[$option]);
        return true;
    }
}
if (!function_exists('delete_site_option')) {
    function delete_site_option(string $option): bool {
        global $mock_options;
        unset($mock_options[$option]);
        return true;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return 1;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool {
        if (array_key_exists('mock_user_can_manage_options', $GLOBALS)) {
            return (bool) $GLOBALS['mock_user_can_manage_options'];
        }
        return true;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1): string {
        return substr(hash('sha256', (string)$action . 'salt'), 0, 10);
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1): bool {
        return $nonce === substr(hash('sha256', (string)$action . 'salt'), 0, 10);
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false): string {
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string)$string);
        $string = strip_tags($string);
        return $remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $string) : $string;
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array {
        return [
            'path'    => sys_get_temp_dir() . '/wm-tests-uploads',
            'url'     => 'https://demo.example.com/wp-content/uploads',
            'subdir'  => '',
            'basedir' => sys_get_temp_dir() . '/wm-tests-uploads',
            'baseurl' => 'https://demo.example.com/wp-content/uploads',
            'error'   => false,
        ];
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string {
        return rtrim($string, '/\\') . '/';
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $string): string {
        return rtrim($string, '/\\');
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) {
        return parse_url($url, $component);
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string {
        return 'https://demo.example.com' . ($path ? '/' . ltrim($path, '/') : '');
    }
}
if (!function_exists('site_url')) {
    function site_url(string $path = ''): string {
        return 'https://demo.example.com' . ($path ? '/' . ltrim($path, '/') : '');
    }
}
if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://demo.example.com/wp-admin/' . ltrim($path, '/');
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = 'name'): string {
        return 'Cyber Events Enterprise Demo';
    }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}
if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void {
        echo $text;
    }
}
if (!function_exists('current_time')) {
    function current_time(string $type, $gmt = 0) {
        // $mock_now lets a test write entries "in the past" (retention windows).
        $now = $GLOBALS['mock_now'] ?? time();
        return $type === 'mysql' ? date('Y-m-d H:i:s', $now) : $now;
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string {
        return bin2hex(random_bytes((int)($length / 2)));
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'mock-salt-wm-cyber-events-sec-key-2026';
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return $thing instanceof WP_Error;
    }
}
class WP_Error {
    public string $code;
    public string $message;
    public $data;
    public function __construct(string $code = '', string $message = '', $data = '') {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_message(): string {
        return $this->message;
    }
    public function get_error_code(): string {
        return $this->code;
    }
}

if (!interface_exists('ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface')) {
    eval('namespace ArnoldWender\SuiteHub\Contracts {
        interface WM_Plugin_Module_Interface {
            public function get_module_slug(): string;
            public function get_name(): string;
            public function get_version(): string;
            public function get_description(): string;
            public function get_health_status(): string;
            public function get_sbom_data(): array;
            public function get_dependencies(): array;
            public function get_settings_url(): ?string;
            public function get_telemetry_metrics(): array;
            public function execute_action(string $action, array $params = []): array;
        }
    }');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Cron, redirects and REST helpers used by the queue, the activator and the REST controller.
global $mock_scheduled, $mock_cleared_hooks, $mock_redirect;
$mock_scheduled     = [];
$mock_cleared_hooks = [];
$mock_redirect      = null;
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook) {
        global $mock_scheduled;
        return $mock_scheduled[$hook]['time'] ?? false;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool {
        global $mock_scheduled;
        $schedules = apply_filters('cron_schedules', ['hourly' => ['interval' => 3600], 'daily' => ['interval' => 86400]]);
        if (!isset($schedules[$recurrence])) {
            return false; // WordPress refuses an unknown recurrence.
        }
        $mock_scheduled[$hook] = ['time' => $timestamp, 'recurrence' => $recurrence];
        return true;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): int {
        global $mock_scheduled, $mock_cleared_hooks;
        $mock_cleared_hooks[] = $hook;
        unset($mock_scheduled[$hook]);
        return 1;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string {
        return 'https://demo.example.com/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('add_query_arg')) {
    // Both forms WordPress accepts: add_query_arg( array, url ) and add_query_arg( key, value, url ).
    function add_query_arg(...$args): string {
        if (is_array($args[0])) {
            [$params, $url] = [$args[0], (string) ($args[1] ?? '')];
        } else {
            [$params, $url] = [[$args[0] => $args[1]], (string) ($args[2] ?? '')];
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post) {
        return 'https://demo.example.com/?page_id=' . (int) $post;
    }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers(): void {}
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302): bool {
        global $mock_redirect;
        $mock_redirect = $location;
        return true;
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n(string $format, $timestamp = false): string {
        return gmdate($format, $timestamp ?: time());
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url): string {
        return filter_var((string) $url, FILTER_SANITIZE_URL) ?: '';
    }
}
if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var((string) $email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
}
if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void {
        @unlink($file);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title): string {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// Site time zone: option timezone_string, UTC when unset (as WordPress falls back to gmt_offset 0).
if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string(): string {
        return (string) (get_option('timezone_string') ?: 'UTC');
    }
}
if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone {
        return new DateTimeZone(wp_timezone_string());
    }
}
if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $timestamp = null): string {
        return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(wp_timezone())->format($format);
    }
}
if (!function_exists('current_datetime')) {
    function current_datetime(): DateTimeImmutable {
        $now = $GLOBALS['mock_now'] ?? time();
        return (new DateTimeImmutable('@' . (int) $now))->setTimezone(wp_timezone());
    }
}
global $mock_registered_scripts, $mock_registered_styles, $mock_script_translations, $mock_registered_blocks, $mock_block_bindings;
$mock_registered_scripts  = [];
$mock_registered_styles   = [];
$mock_script_translations = [];
$mock_registered_blocks   = [];
$mock_block_bindings      = [];
if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, $src = '', array $deps = [], $ver = false, $in_footer = false): bool {
        global $mock_registered_scripts;
        $mock_registered_scripts[$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver];
        return true;
    }
}
if (!function_exists('wp_register_style')) {
    function wp_register_style(string $handle, $src = '', array $deps = [], $ver = false, string $media = 'all'): bool {
        global $mock_registered_styles;
        $mock_registered_styles[$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver];
        return true;
    }
}
global $mock_enqueued_styles, $mock_inline_styles;
$mock_enqueued_styles = [];
$mock_inline_styles   = [];
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, $src = '', array $deps = [], $ver = false, string $media = 'all'): void {
        global $mock_enqueued_styles;
        $mock_enqueued_styles[$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver];
    }
}
if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style(string $handle, string $data): bool {
        global $mock_inline_styles;
        $mock_inline_styles[$handle][] = $data;
        return true;
    }
}
if (!function_exists('wp_set_script_translations')) {
    function wp_set_script_translations(string $handle, string $domain = 'default', string $path = ''): bool {
        global $mock_script_translations;
        $mock_script_translations[$handle] = ['domain' => $domain, 'path' => $path];
        return true;
    }
}
if (!function_exists('register_block_type')) {
    function register_block_type($block_type, array $args = []) {
        global $mock_registered_blocks;
        $mock_registered_blocks[(string) $block_type] = $args;
        return (object) ['name' => (string) $block_type, 'args' => $args];
    }
}
if (!function_exists('register_block_bindings_source')) {
    function register_block_bindings_source(string $source_name, array $source_properties) {
        global $mock_block_bindings;
        $mock_block_bindings[$source_name] = $source_properties;
        return (object) $source_properties;
    }
}
if (!function_exists('get_all_registered_block_bindings_sources')) {
    function get_all_registered_block_bindings_sources(): array {
        global $mock_block_bindings;
        return $mock_block_bindings;
    }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return rtrim(dirname($file), '/\\') . '/';
    }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string {
        return 'https://demo.example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string {
        return basename(dirname($file)) . '/' . basename($file);
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): void {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable $callback): void {}
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, $deprecated = false, $plugin_rel_path = false): bool {
        return true;
    }
}
if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string {
        return $text;
    }
}
if (!function_exists('register_post_type')) {
    function register_post_type(string $post_type, array $args = []) {
        return (object) ['name' => $post_type, 'args' => $args];
    }
}
if (!function_exists('register_taxonomy')) {
    function register_taxonomy(string $taxonomy, $object_type, array $args = []) {
        return (object) ['name' => $taxonomy, 'args' => $args];
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void {
        $GLOBALS['mock_shortcodes'][$tag] = $callback;
    }
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool {
        return true;
    }
}
// Object cache: an in-memory store plus the switch that says whether it is persistent. The default
// WordPress cache is per-request, and code that counts in it would reset every request; $mock_ext_cache
// lets a test run both worlds.
global $mock_cache, $mock_ext_cache, $mock_cache_evict_on_incr;
$mock_cache     = [];
$mock_ext_cache = false;
// A persistent cache may evict a key between the add() that created it and the incr() that counts:
// with this on, incr() answers false, as the real backends do for a missing key.
$mock_cache_evict_on_incr = false;
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool {
        global $mock_ext_cache;
        return (bool) $mock_ext_cache;
    }
}
if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, string $group = '', bool $force = false, &$found = null) {
        global $mock_cache;
        $found = isset($mock_cache[$group][$key]);
        return $found ? $mock_cache[$group][$key] : false;
    }
}
if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, string $group = '', int $expire = 0): bool {
        global $mock_cache;
        $mock_cache[$group][$key] = $data;
        return true;
    }
}
if (!function_exists('wp_cache_add')) {
    // Writes only when the key is absent, as the real one does.
    function wp_cache_add($key, $data, string $group = '', int $expire = 0): bool {
        global $mock_cache;
        if (isset($mock_cache[$group][$key])) {
            return false;
        }
        $mock_cache[$group][$key] = $data;
        return true;
    }
}
if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, int $offset = 1, string $group = '') {
        global $mock_cache, $mock_cache_evict_on_incr;
        if ($mock_cache_evict_on_incr) {
            unset($mock_cache[$group][$key]);
        }
        if (!isset($mock_cache[$group][$key])) {
            return false; // As in WordPress: no key, no increment.
        }
        $mock_cache[$group][$key] = (int) $mock_cache[$group][$key] + $offset;
        return $mock_cache[$group][$key];
    }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, string $group = ''): bool {
        global $mock_cache;
        unset($mock_cache[$group][$key]);
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        global $mock_transients;
        unset($mock_transients[$key]);
        return true;
    }
}

if (!function_exists('determine_locale')) {
    function determine_locale(): string {
        return 'de_DE';
    }
}
if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, int $decimals = 0): string {
        return number_format((float) $number, $decimals, ',', '.');
    }
}
if (!function_exists('shortcode_atts')) {
    // As in WordPress: only the known keys, the passed value when present, the default otherwise.
    function shortcode_atts(array $pairs, $atts, string $shortcode = ''): array {
        $atts = (array) $atts;
        $out  = [];
        foreach ($pairs as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }
        return $out;
    }
}
if (!function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 0): int {
        return random_int($min, $max);
    }
}
if (!function_exists('wp_trim_words')) {
    function wp_trim_words($text, int $num_words = 55, $more = null): string {
        $words = preg_split('/\s+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        return count($words) > $num_words ? implode(' ', array_slice($words, 0, $num_words)) . ($more ?? '…') : implode(' ', $words);
    }
}
if (!function_exists('get_privacy_policy_url')) {
    // WordPress returns '' when no privacy policy page is set or it is not published.
    function get_privacy_policy_url(): string {
        return (string) ($GLOBALS['mock_privacy_policy_url'] ?? '');
    }
}
if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
        return 1 === $number ? $single : $plural;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
global $mock_transients, $mock_http_requests, $mock_http_response, $mock_rest_routes, $mock_is_admin;
$mock_transients    = [];
$mock_http_requests = [];
$mock_http_response = ['response' => ['code' => 201], 'body' => ''];
$mock_rest_routes   = [];
$mock_is_admin      = false;
if (!function_exists('get_transient')) {
    function get_transient(string $key) {
        global $mock_transients;
        return $mock_transients[$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool {
        global $mock_transients;
        $mock_transients[$key] = $value;
        return true;
    }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool {
        global $mock_is_admin;
        return (bool) $mock_is_admin;
    }
}
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class(string $class): string {
        return preg_replace('/[^A-Za-z0-9_-]/', '', $class);
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = []): bool {
        global $mock_rest_routes;
        $mock_rest_routes[$namespace . $route] = $args;
        return true;
    }
}
// Outbound HTTP: every request is recorded; the answer is whatever the test put in $mock_http_response.
if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []) {
        global $mock_http_requests, $mock_http_response;
        $mock_http_requests[] = ['method' => 'POST', 'url' => $url, 'args' => $args];
        return $mock_http_response;
    }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []) {
        global $mock_http_requests, $mock_http_response;
        $mock_http_requests[] = ['method' => $args['method'] ?? 'GET', 'url' => $url, 'args' => $args];
        return $mock_http_response;
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = [];
        private array $headers = [];
        private string $body = '';
        public function __construct(string $method = 'GET', string $route = '') {}
        public function set_param(string $key, $value): void { $this->params[$key] = $value; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
        private static function canonicalize_header(string $key): string {
            return str_replace('-', '_', strtolower($key));
        }
        public function set_header(string $key, string $value): void { $this->headers[self::canonicalize_header($key)] = $value; }
        public function get_header(string $key): ?string { return $this->headers[self::canonicalize_header($key)] ?? null; }
        public function set_body(string $body): void { $this->body = $body; }
        public function get_body(): string { return $this->body; }
        public function get_json_params(): array { return $this->body !== '' ? (json_decode($this->body, true) ?? []) : $this->params; }
        public function get_file_params(): array { return []; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public int $status;
        public array $headers = [];
        public function __construct($data = null, int $status = 200, array $headers = []) {
            $this->data = $data;
            $this->status = $status;
            foreach ($headers as $key => $value) {
                $this->header((string) $key, (string) $value);
            }
        }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function get_headers(): array { return $this->headers; }
        public function header(string $key, string $value): void { $this->headers[$key] = $value; }
    }
}

// Multisite & Activator mocks
global $mock_blog_stack, $mock_blog_switches;
$mock_blog_stack    = [];
$mock_blog_switches = [];
if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', bool $execute = true): array {
        global $mock_db_tables;
        $list = is_array($queries) ? $queries : [$queries];
        $results = [];
        foreach ($list as $sql) {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^\s`(]+)`?/i', (string) $sql, $m)) {
                $table = $m[1];
                if (!isset($mock_db_tables[$table])) {
                    $mock_db_tables[$table] = [];
                }
                $results[$table] = 'Created table ' . $table;
            }
        }
        return $results;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return (bool) ($GLOBALS['mock_is_multisite'] ?? false);
    }
}
if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network(string $plugin): bool {
        return (bool) ($GLOBALS['mock_network_active'] ?? false);
    }
}
if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $new_blog_id, bool $deprecated = false): bool {
        global $wpdb, $mock_db_tables, $mock_blog_stack, $mock_blog_switches;
        $mock_blog_stack[] = ['prefix' => $wpdb->prefix, 'posts' => $wpdb->posts];
        $wpdb->prefix = $new_blog_id <= 1 ? 'wp_' : 'wp_' . $new_blog_id . '_';
        $wpdb->posts  = $wpdb->prefix . 'posts';
        if (!isset($mock_db_tables[$wpdb->posts])) {
            $mock_db_tables[$wpdb->posts] = [];
        }
        $mock_blog_switches[] = $new_blog_id;
        return true;
    }
}
if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool {
        global $wpdb, $mock_blog_stack;
        if (empty($mock_blog_stack)) {
            return false;
        }
        $prev = array_pop($mock_blog_stack);
        $wpdb->prefix = $prev['prefix'];
        $wpdb->posts  = $prev['posts'];
        return true;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $postarr, bool $wp_error = false, bool $fire_after_hooks = true) {
        global $wpdb, $mock_db_tables;
        $table = $wpdb->posts;
        if (!isset($mock_db_tables[$table])) {
            $mock_db_tables[$table] = [];
        }
        $keys = array_keys($mock_db_tables[$table]);
        $id = $keys ? max($keys) + 1 : 1;
        $mock_db_tables[$table][$id] = (object) array_merge(['ID' => $id, 'id' => $id], $postarr);
        return $id;
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $meta_key, $meta_value, $prev_value = ''): bool {
        return true;
    }
}
if (!function_exists('term_exists')) {
    function term_exists($term, string $taxonomy = '', ?int $parent = null) {
        return 0;
    }
}
if (!function_exists('wp_insert_term')) {
    function wp_insert_term(string $term, string $taxonomy, array $args = []): array {
        return ['term_id' => 1, 'term_taxonomy_id' => 1];
    }
}
if (!function_exists('update_term_meta')) {
    function update_term_meta(int $term_id, string $meta_key, $meta_value, $prev_value = ''): bool {
        return true;
    }
}
if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms(int $object_id, $terms, string $taxonomy, bool $append = false): array {
        return [1];
    }
}

require_once ABSPATH . 'includes/class-database.php';
require_once ABSPATH . 'includes/class-brevo-api.php';
require_once ABSPATH . 'includes/class-queue-runner.php';
require_once ABSPATH . 'includes/class-invoicing.php';
require_once ABSPATH . 'includes/class-datev-exporter.php';
require_once ABSPATH . 'includes/class-csv-importer.php';
require_once ABSPATH . 'includes/class-icons.php';
require_once ABSPATH . 'includes/class-accessibility.php';
require_once ABSPATH . 'includes/class-gdpr-manager.php';
require_once ABSPATH . 'includes/class-activator.php';
require_once ABSPATH . 'includes/class-deactivator.php';
require_once ABSPATH . 'includes/class-theme-manager.php';
require_once ABSPATH . 'includes/class-cyber-events-spoke-adapter.php';
require_once ABSPATH . 'includes/class-calendar-exporter.php';
require_once ABSPATH . 'includes/class-waiting-room.php';
require_once ABSPATH . 'includes/class-seat-map.php';
require_once ABSPATH . 'includes/class-rest-api.php';
require_once ABSPATH . 'includes/class-events-manager.php';
require_once ABSPATH . 'includes/class-sessions-manager.php';
require_once ABSPATH . 'includes/class-venues-manager.php';
require_once ABSPATH . 'includes/class-blocks-manager.php';
require_once ABSPATH . 'includes/class-admin.php';
