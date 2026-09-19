<?php
declare(strict_types=1);

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
            ]
        );
    }
    return $pdo;
}
