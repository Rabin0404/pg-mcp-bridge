<?php
/**
 * PostgreSQL MCP HTTP tunnel (JSON API).
 * Upload this file + config.php to your PHP/Apache host.
 *
 * access_mode in config.php:
 *   - readonly  : SELECT / WITH...SELECT / EXPLAIN / SHOW
 *   - readwrite : + INSERT / UPDATE / DELETE (no DDL)
 *   - full      : + CREATE / ALTER / DROP / TRUNCATE / etc.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
error_reporting(0);
set_time_limit(30);

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_config(): array
{
    $candidates = [
        __DIR__ . '/config.php',
        dirname(__DIR__) . '/config.php',
        __DIR__ . '/../private/pg_mcp_config.php',
    ];
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            $cfg = require $path;
            if (is_array($cfg)) {
                return $cfg;
            }
        }
    }
    json_out(['ok' => false, 'error' => 'config.php not found or invalid'], 500);
}

function access_mode(array $cfg): string
{
    $mode = isset($cfg['access_mode']) ? strtolower(trim((string)$cfg['access_mode'])) : 'readonly';
    if (!in_array($mode, ['readonly', 'readwrite', 'full'], true)) {
        return 'readonly';
    }
    return $mode;
}

function get_bearer_token(): string
{
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = $v;
                break;
            }
        }
    }
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
        return $m[1];
    }
    return '';
}

function require_auth(array $cfg): void
{
    $expected = isset($cfg['token']) ? (string)$cfg['token'] : '';
    if ($expected === '' || $expected === 'change-me-to-a-long-random-secret') {
        json_out(['ok' => false, 'error' => 'Server token is not configured'], 500);
    }
    $got = get_bearer_token();
    if ($got === '' || !hash_equals($expected, $got)) {
        json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

function db_connect(array $cfg)
{
    if (!function_exists('pg_connect')) {
        json_out(['ok' => false, 'error' => 'PHP pgsql extension is not available'], 500);
    }
    $host = isset($cfg['host']) ? $cfg['host'] : '127.0.0.1';
    $port = isset($cfg['port']) ? $cfg['port'] : '5432';
    $db = isset($cfg['database']) ? $cfg['database'] : 'postgres';
    $user = isset($cfg['user']) ? $cfg['user'] : 'postgres';
    $pass = isset($cfg['password']) ? $cfg['password'] : '';

    $connstr = sprintf(
        "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
        $host,
        $port,
        $db,
        $user,
        $pass
    );
    $conn = @pg_connect($connstr);
    if (!$conn) {
        json_out(['ok' => false, 'error' => 'Could not connect to PostgreSQL'], 500);
    }
    return $conn;
}

function apply_session_guards($conn, array $cfg): void
{
    $timeout = isset($cfg['statement_timeout_ms']) ? (int)$cfg['statement_timeout_ms'] : 15000;
    if ($timeout < 1000) {
        $timeout = 1000;
    }
    $mode = access_mode($cfg);
    if ($mode === 'readonly') {
        @pg_query($conn, "SET default_transaction_read_only = on");
    } else {
        @pg_query($conn, "SET default_transaction_read_only = off");
    }
    @pg_query($conn, "SET statement_timeout = " . $timeout);
    @pg_query($conn, "SET idle_in_transaction_session_timeout = " . ($timeout + 5000));
}

function strip_sql_literals(string $sql): string
{
    $out = preg_replace('/\/\*.*?\*\//s', ' ', $sql);
    if ($out === null) {
        return $sql;
    }
    $out = preg_replace('/--[^\n\r]*/', ' ', $out);
    if ($out === null) {
        return $sql;
    }
    $out = preg_replace("/('([^']|'')*')|(\"([^\"]|\"\")*\")|(\\$\\$.*?\\$\\$)/s", "''", $out);
    return $out === null ? $sql : $out;
}

function normalize_single_statement(string $sql): ?string
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return null;
    }
    $without_literals = strip_sql_literals($trimmed);
    $parts = array_values(array_filter(array_map('trim', explode(';', $without_literals)), function ($p) {
        return $p !== '';
    }));
    if (count($parts) !== 1) {
        return null;
    }
    $normalized = preg_replace('/\s+/', ' ', strtolower($parts[0]));
    if ($normalized === null || $normalized === '') {
        return null;
    }
    return $normalized;
}

function is_ddl_sql(string $normalized): bool
{
    $ddlStarts = '/^(create|alter|drop|truncate|grant|revoke|reindex|vacuum|analyze|cluster|comment|security|refresh|reassign|discard|lock|listen|notify|unlisten|copy|call|do|execute|prepare|deallocate|reset|checkpoint|load)\b/';
    if (preg_match($ddlStarts, $normalized)) {
        return true;
    }
    // SELECT INTO creates a table
    if (preg_match('/^(with|select)\b/', $normalized) && preg_match('/\binto\b/', $normalized)) {
        return true;
    }
    return false;
}

function is_dml_sql(string $normalized): bool
{
    if (preg_match('/^(insert|update|delete|merge)\b/', $normalized)) {
        return true;
    }
    // WITH ... INSERT/UPDATE/DELETE
    if (preg_match('/^with\b/', $normalized) && preg_match('/\b(insert|update|delete|merge)\b/', $normalized)) {
        return true;
    }
    return false;
}

function is_read_sql(string $normalized): bool
{
    if (!preg_match('/^(with|select|explain|show|values|table)\b/', $normalized)) {
        return false;
    }
    if (preg_match('/^with\b/', $normalized)) {
        if (!preg_match('/\bselect\b/', $normalized)) {
            return false;
        }
        if (preg_match('/\b(insert|update|delete|merge)\b/', $normalized)) {
            return false;
        }
    }
    if (!preg_match('/^explain\b/', $normalized) && preg_match('/\binto\b/', $normalized)) {
        return false;
    }
    return true;
}

/**
 * @return array{ok:bool,error?:string}
 */
function validate_sql(string $sql, string $mode): array
{
    $normalized = normalize_single_statement($sql);
    if ($normalized === null) {
        return ['ok' => false, 'error' => 'Only a single SQL statement is allowed'];
    }

    if ($mode === 'full') {
        // Single statement: reads, DML, DDL. Block session/txn control that could bypass guards.
        if (preg_match('/^(begin|commit|rollback|savepoint|release|set|reset|discard)\b/', $normalized)) {
            return ['ok' => false, 'error' => 'Transaction/session control statements are not allowed'];
        }
        if (!preg_match('/^(with|select|insert|update|delete|merge|explain|show|values|table|create|alter|drop|truncate|grant|revoke|reindex|vacuum|analyze|cluster|comment|security|refresh|reassign|lock|listen|notify|unlisten|copy|call|do|execute|prepare|deallocate|checkpoint|load)\b/', $normalized)) {
            return ['ok' => false, 'error' => 'Unsupported SQL statement type'];
        }
        return ['ok' => true];
    }

    if ($mode === 'readwrite') {
        if (is_ddl_sql($normalized)) {
            return ['ok' => false, 'error' => 'DDL is not allowed in readwrite mode (CREATE/ALTER/DROP/TRUNCATE/...)'];
        }
        if (is_dml_sql($normalized) || is_read_sql($normalized)) {
            // In readwrite, allow FOR UPDATE locking clauses on SELECT
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'Only SELECT/INSERT/UPDATE/DELETE (and EXPLAIN/SHOW) are allowed in readwrite mode'];
    }

    // readonly
    if (!is_read_sql($normalized)) {
        return ['ok' => false, 'error' => 'Only a single read-only SELECT/WITH/EXPLAIN/SHOW statement is allowed'];
    }
    if (preg_match('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/', $normalized)) {
        return ['ok' => false, 'error' => 'Row-locking clauses are not allowed in readonly mode'];
    }
    return ['ok' => true];
}

function fetch_result($res, int $maxRows): array
{
    $numfields = pg_num_fields($res);
    $columns = [];
    for ($i = 0; $i < $numfields; $i++) {
        $columns[] = [
            'name' => pg_field_name($res, $i),
            'type' => pg_field_type($res, $i),
        ];
    }

    $rows = [];
    $count = 0;
    $truncated = false;
    while ($row = pg_fetch_assoc($res)) {
        if ($count >= $maxRows) {
            $truncated = true;
            break;
        }
        $rows[] = $row;
        $count++;
    }

    return [
        'columns' => $columns,
        'data' => $rows,
        'rowCount' => count($rows),
        'truncated' => $truncated,
        'maxRows' => $maxRows,
    ];
}

function max_rows(array $cfg): int
{
    $maxRows = isset($cfg['max_rows']) ? (int)$cfg['max_rows'] : 500;
    if ($maxRows < 1) {
        $maxRows = 1;
    }
    if ($maxRows > 5000) {
        $maxRows = 5000;
    }
    return $maxRows;
}

function action_ping($conn, array $cfg): array
{
    $res = pg_query($conn, 'SELECT version() AS version, current_database() AS database, current_user AS "user"');
    if (!$res) {
        return ['ok' => false, 'error' => pg_last_error($conn)];
    }
    $row = pg_fetch_assoc($res);
    pg_free_result($res);
    $row['access_mode'] = access_mode($cfg);
    return ['ok' => true, 'data' => $row];
}

function action_list_schemas($conn): array
{
    $sql = "SELECT nspname AS schema_name
            FROM pg_catalog.pg_namespace
            WHERE nspname NOT LIKE 'pg_%'
              AND nspname <> 'information_schema'
            ORDER BY nspname";
    $res = pg_query($conn, $sql);
    if (!$res) {
        return ['ok' => false, 'error' => pg_last_error($conn)];
    }
    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = $row['schema_name'];
    }
    pg_free_result($res);
    return ['ok' => true, 'data' => $rows, 'rowCount' => count($rows)];
}

function action_list_tables($conn, string $schema): array
{
    if ($schema === '') {
        return ['ok' => false, 'error' => 'schema is required'];
    }
    $sql = "SELECT c.relname AS name,
                   CASE c.relkind
                     WHEN 'r' THEN 'table'
                     WHEN 'v' THEN 'view'
                     WHEN 'm' THEN 'materialized_view'
                     WHEN 'f' THEN 'foreign_table'
                     WHEN 'p' THEN 'partitioned_table'
                     ELSE c.relkind::text
                   END AS type
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = $1
              AND c.relkind IN ('r', 'v', 'm', 'f', 'p')
            ORDER BY c.relname";
    $res = pg_query_params($conn, $sql, [$schema]);
    if (!$res) {
        return ['ok' => false, 'error' => pg_last_error($conn)];
    }
    $rows = [];
    while ($row = pg_fetch_assoc($res)) {
        $rows[] = $row;
    }
    pg_free_result($res);
    return ['ok' => true, 'data' => $rows, 'rowCount' => count($rows)];
}

function action_describe_table($conn, string $schema, string $table): array
{
    if ($schema === '' || $table === '') {
        return ['ok' => false, 'error' => 'schema and table are required'];
    }

    $colsSql = "SELECT
                    a.attname AS column_name,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type,
                    NOT a.attnotnull AS is_nullable,
                    pg_get_expr(ad.adbin, ad.adrelid) AS column_default,
                    a.attnum AS ordinal_position
                FROM pg_catalog.pg_attribute a
                JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                LEFT JOIN pg_catalog.pg_attrdef ad
                       ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
                WHERE n.nspname = $1
                  AND c.relname = $2
                  AND a.attnum > 0
                  AND NOT a.attisdropped
                ORDER BY a.attnum";
    $res = pg_query_params($conn, $colsSql, [$schema, $table]);
    if (!$res) {
        return ['ok' => false, 'error' => pg_last_error($conn)];
    }
    $columns = [];
    while ($row = pg_fetch_assoc($res)) {
        $columns[] = [
            'name' => $row['column_name'],
            'type' => $row['data_type'],
            'nullable' => ($row['is_nullable'] === 't' || $row['is_nullable'] === true || $row['is_nullable'] === '1'),
            'default' => $row['column_default'],
            'position' => (int)$row['ordinal_position'],
        ];
    }
    pg_free_result($res);

    if (count($columns) === 0) {
        return ['ok' => false, 'error' => 'Table not found or has no columns'];
    }

    $pkSql = "SELECT a.attname AS column_name
              FROM pg_index i
              JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
              JOIN pg_class c ON c.oid = i.indrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE i.indisprimary
                AND n.nspname = $1
                AND c.relname = $2
              ORDER BY array_position(i.indkey, a.attnum)";
    $pkRes = pg_query_params($conn, $pkSql, [$schema, $table]);
    $pk = [];
    if ($pkRes) {
        while ($row = pg_fetch_assoc($pkRes)) {
            $pk[] = $row['column_name'];
        }
        pg_free_result($pkRes);
    }

    return [
        'ok' => true,
        'data' => [
            'schema' => $schema,
            'table' => $table,
            'columns' => $columns,
            'primaryKey' => $pk,
        ],
    ];
}

function action_query($conn, string $sql, array $cfg): array
{
    $mode = access_mode($cfg);
    $check = validate_sql($sql, $mode);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => $check['error']];
    }

    $maxRows = max_rows($cfg);
    $normalized = normalize_single_statement($sql);
    $useTxn = true;
    if ($mode === 'full' && $normalized !== null && is_ddl_sql($normalized)) {
        // Some DDL/admin commands cannot run inside a transaction block
        $useTxn = false;
    }

    if ($useTxn) {
        if ($mode === 'readonly') {
            @pg_query($conn, 'BEGIN READ ONLY');
        } else {
            @pg_query($conn, 'BEGIN');
        }
    }

    $res = @pg_query($conn, $sql);
    if (!$res) {
        $err = pg_last_error($conn);
        if ($useTxn) {
            @pg_query($conn, 'ROLLBACK');
        }
        return ['ok' => false, 'error' => $err ?: 'Query failed'];
    }

    $numfields = pg_num_fields($res);
    $affected = pg_affected_rows($res);

    if ($numfields > 0) {
        $payload = fetch_result($res, $maxRows);
        pg_free_result($res);
        if ($useTxn) {
            @pg_query($conn, 'COMMIT');
        }
        return array_merge(['ok' => true, 'access_mode' => $mode, 'affectedRows' => $affected], $payload);
    }

    pg_free_result($res);
    if ($useTxn) {
        @pg_query($conn, 'COMMIT');
    }
    return [
        'ok' => true,
        'access_mode' => $mode,
        'affectedRows' => $affected,
        'columns' => [],
        'data' => [],
        'rowCount' => 0,
        'truncated' => false,
        'maxRows' => $maxRows,
    ];
}

// --- main ---

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST required'], 405);
}

$cfg = load_config();
require_auth($cfg);

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}

$action = isset($body['action']) ? (string)$body['action'] : '';
if ($action === '') {
    json_out(['ok' => false, 'error' => 'action is required'], 400);
}

$conn = db_connect($cfg);
apply_session_guards($conn, $cfg);

switch ($action) {
    case 'ping':
        json_out(action_ping($conn, $cfg));
    case 'list_schemas':
        json_out(action_list_schemas($conn));
    case 'list_tables':
        json_out(action_list_tables($conn, isset($body['schema']) ? (string)$body['schema'] : 'public'));
    case 'describe_table':
        json_out(action_describe_table(
            $conn,
            isset($body['schema']) ? (string)$body['schema'] : 'public',
            isset($body['table']) ? (string)$body['table'] : ''
        ));
    case 'query':
        json_out(action_query($conn, isset($body['sql']) ? (string)$body['sql'] : '', $cfg));
    default:
        json_out(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
}
