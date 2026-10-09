<?php
declare(strict_types=1);

// PHP's strtotime()/date() parse timezone-less strings in the server's local
// timezone. We store all timestamps as UTC (gmdate / SET time_zone='+00:00'),
// so pin PHP to UTC to keep lockout, session-expiry and token-expiry checks
// consistent everywhere.
date_default_timezone_set('UTC');

/**
 * Shared PDO factory. Reads MySQL credentials from config.json (never committed
 * to the repo; see config.example.json for the expected shape).
 */

function db_config(): array {
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/config.json';
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        $config = is_array($decoded) ? $decoded : [];
    }
    return $config;
}

function db_base_url(): string {
    $url = db_config()['base_url'] ?? 'https://tscrub.com';
    return rtrim((string)$url, '/');
}

/**
 * Cheap idempotent table-existence probe, cached per request. Used by the
 * *_ensure_schema() helpers to skip a ~10ms `CREATE TABLE IF NOT EXISTS`
 * parse/check when the table already exists (the common case on read paths).
 * Returns false on any error so callers fall back to an idempotent CREATE.
 */
function db_table_exists(string $table): bool {
    static $known = [];
    if (array_key_exists($table, $known)) return $known[$table];
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $known[$table] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $known[$table] = false;
    }
    return $known[$table];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = db_config()['db'] ?? [];
        $host = (string)($c['host'] ?? '127.0.0.1');
        $port = isset($c['port']) ? (int)$c['port'] : 3306;
        $name = (string)($c['name'] ?? 'tScrub');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO(
            $dsn,
            (string)($c['user'] ?? ''),
            (string)($c['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Keep NOW()/CURRENT_TIMESTAMP in UTC so they agree with the
                // gmdate() timestamps used elsewhere (sessions, cert issued_at).
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]
        );
    }
    return $pdo;
}
