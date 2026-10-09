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

// Stream a generic file download (sets Content-Type + Content-Disposition).
function download_out(string $filename, string $contentType, string $content): void {
    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    echo $content;
    exit;
}

// CSV download (RFC 4180 via fputcsv) with a UTF-8 BOM so Excel opens
// non-ASCII values (e.g. £, °, en-dashes) correctly.
function csv_out(string $filename, array $rows): void {
    $fh = fopen('php://temp', 'w+');
    foreach ($rows as $row) {
        // Explicit $escape='' is RFC 4180-correct (quotes doubled, not
        // backslash-escaped) and avoids the PHP 8.4 fputcsv deprecation.
        fputcsv($fh, array_map(static fn($v) => $v === null ? '' : (string)$v, $row), ',', '"', '');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    download_out($filename, 'text/csv; charset=UTF-8', "\xEF\xBB\xBF" . $csv);
}

// Pretty-printed JSON download.
function json_download(string $filename, array $data): void {
    download_out($filename, 'application/json; charset=UTF-8', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// Resolve which of a page's columns were requested. Accepts a comma-separated
// string or a repeated `fields[]=` array; unknown keys are dropped and an empty
// selection means "export everything" (the modal pre-checks all fields).
function export_field_keys(array $cols, $input): array {
    $keys = is_array($input) ? $input : explode(',', (string)$input);
    $keys = array_values(array_unique(array_filter(array_map('trim', $keys), static fn($k) => $k !== '')));
    $allowed = array_keys($cols);
    $selected = array_values(array_filter($keys, static fn($k) => in_array($k, $allowed, true)));
    return $selected !== [] ? $selected : $allowed;
}

// Dispatch a tabular export to JSON, Excel XML or CSV.
function export_send(string $format, string $basename, array $labels, array $rows): void {
    if ($format === 'json') {
        $out = [];
        foreach ($rows as $r) {
            $obj = [];
            foreach ($labels as $i => $label) {
                $obj[$label] = $r[$i] ?? '';
            }
            $out[] = $obj;
        }
        json_download($basename . '.json', $out);
        return;
    }
    if ($format === 'xls') {
        xls_out($basename . '.xls', $labels, $rows);
        return;
    }
    csv_out($basename . '.csv', array_merge([$labels], $rows));
}

// Excel 2003 XML (SpreadsheetML) — a library-free "XLS" that Excel, Numbers
// and LibreOffice open natively. All cells are emitted as strings so serials,
// MACs and IDs with leading zeros keep their exact text.
function xls_out(string $filename, array $header, array $rows): void {
    $esc = static function ($v): string {
        $s = (string)$v;
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
         . "<?mso-application progid=\"Excel.Sheet\"?>\n"
         . "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\">\n"
         . " <Styles><Style ss:ID=\"hdr\"><Font ss:Bold=\"1\"/></Style></Styles>\n"
         . " <Worksheet ss:Name=\"Export\"><Table>\n";
    $cells = '';
    foreach ($header as $h) {
        $cells .= "<Cell ss:StyleID=\"hdr\"><Data ss:Type=\"String\">" . $esc($h) . "</Data></Cell>";
    }
    $xml .= "  <Row>" . $cells . "</Row>\n";
    foreach ($rows as $row) {
        $cells = '';
        foreach ($row as $v) {
            $s = (string)($v ?? '');
            if (strlen($s) > 32767) $s = substr($s, 0, 32767);
            $cells .= "<Cell><Data ss:Type=\"String\">" . $esc($s) . "</Data></Cell>";
        }
        $xml .= "  <Row>" . $cells . "</Row>\n";
    }
    $xml .= " </Table></Worksheet>\n</Workbook>";
    download_out($filename, 'application/vnd.ms-excel', $xml);
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
