<?php
declare(strict_types=1);

/**
 * Small HTTP helpers shared by the JSON API endpoints.
 */

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $code, string $msg): void {
    json_out(['ok' => false, 'error' => $msg], $code);
}

function json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    return is_array($data) ? $data : [];
}

// Convert a stored UTC timestamp to Europe/London (British Time) for display.
// Storage stays UTC; only the API display strings are localised, so a browser
// in any timezone still shows the customer's local time consistently.
function ts_local(?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/London'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $utc;
    }
}

// Convert a Unix timestamp to a compact relative "… ago" string using a
// single unit, e.g. "3m ago", "2h ago", "5d ago", "3w ago", "2mo ago",
// "1y ago". The value floors to the largest whole unit (1h59m -> "1h ago",
// 2h01m -> "2h ago"); ages under a minute report "just now".
function ts_rel(?int $unix): string {
    if ($unix === null || $unix <= 0) {
        return '';
    }
    $diff = time() - $unix;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60)       return 'just now';
    if ($diff < 3600)     return intdiv($diff, 60) . 'm ago';
    if ($diff < 86400)    return intdiv($diff, 3600) . 'h ago';
    if ($diff < 604800)   return intdiv($diff, 86400) . 'd ago';
    if ($diff < 2592000)  return intdiv($diff, 604800) . 'w ago';
    if ($diff < 31536000) return intdiv($diff, 2592000) . 'mo ago';
    return intdiv($diff, 31536000) . 'y ago';
}

// JSON endpoints must never leak a raw 500 HTML page or stack trace. Log the
// exception and return a JSON error body instead.
set_exception_handler(function (Throwable $e): void {
    error_log('tscrub uncaught exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        fail(500, 'Internal error.');
    }
    exit;
});
