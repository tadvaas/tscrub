<?php
declare(strict_types=1);

/**
 * Machine-readable certificate generation (JSON-LD + detached Ed25519 signature
 * + RFC 3161 timestamp).
 *
 * Design: research/machine-readable-certs/README.md. The signed document is a
 * JSON-LD object whose `proof.signatureValue` is a detached Ed25519 signature
 * over the JCS (RFC 8785) canonical bytes of the document *without* the `proof`
 * object. The signature uses the same Ed25519 `vendor.key` that signs licences
 * (issue_licence.py) via `openssl pkeyutl`. The proof optionally carries an
 * RFC 3161 TimeStampResp token for the same canonical bytes.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/certifier.php';
require_once __DIR__ . '/cert_terms.php';

/** Public vendor Ed25519 key + fingerprint (mirrors /api/signing-key). */
function jsonld_pub_key(): array {
    return [
        'pem' => "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAbBDdsD4wQh7aoBRe890V8LcTOZNe6n6Cvh0AkrBA4B4=\n-----END PUBLIC KEY-----",
        'fingerprint' => 'be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02',
    ];
}

function jsonld_vendor_key_path(): string {
    $p = (string)(db_config()['vendor_key'] ?? '');
    if ($p === '') { $p = __DIR__ . '/vendor.key'; }
    return $p;
}

function jsonld_tsa_url(): string {
    return (string)(db_config()['tsa_url'] ?? '');
}

function jsonld_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Run a command without a shell (array form) and return stdout, or null on failure. */
function jsonld_run(array $cmd): ?string {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) return null;
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        error_log('jsonld: command failed: ' . implode(' ', $cmd) . ' — ' . trim((string)$err));
        return null;
    }
    return $out;
}

/**
 * JCS (RFC 8785) canonicalization: recursively sort object keys (UTF-16 code
 * unit order — for ASCII keys, byte order) then JSON-encode. Exact only when
 * every number in the document is an integer (build_cert_jsonld enforces that;
 * floats would need ECMAScript number serialization). Strings use the JCS
 * escaping (short forms + \u00xx, raw UTF-8, unescaped '/'), which is exactly
 * json_encode with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
 */
function jsonld_jcs_sort(array &$v): void {
    foreach ($v as &$item) {
        if (is_array($item)) { jsonld_jcs_sort($item); }
    }
    unset($item);
    if (!array_is_list($v)) { ksort($v, SORT_STRING); }
}

function jsonld_jcs(array $doc): string {
    $v = $doc;
    jsonld_jcs_sort($v);
    $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('jsonld_jcs: ' . json_last_error_msg());
    }
    return $json;
}

/** Detached Ed25519 signature (base64url) over the raw canonical bytes. */
function jsonld_sign(string $canonical, ?string $key = null): ?string {
    $key = $key ?? jsonld_vendor_key_path();
    if ($key === '' || !is_file($key)) {
        error_log('jsonld: vendor key not found at ' . $key);
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'tscrub_sig_');
    if ($tmp === false || file_put_contents($tmp, $canonical) === false) { return null; }
    try {
        $sig = jsonld_run(['openssl', 'pkeyutl', '-sign', '-inkey', $key, '-rawin', '-in', $tmp]);
    } finally {
        @unlink($tmp);
    }
    if ($sig === null || $sig === '') return null;
    return jsonld_b64url($sig);
}

/**
 * RFC 3161 timestamp for the canonical bytes. Returns ['token' => base64url
 * TimeStampResp, 'state' => 'recorded'|'verified'] or null on any failure —
 * never fatal, so certificate issuance never blocks on a TSA outage.
 */
function jsonld_timestamp(string $canonical): ?array {
    $tsa = jsonld_tsa_url();
    if ($tsa === '' || !function_exists('curl_init')) return null;

    $tmp = tempnam(sys_get_temp_dir(), 'tscrub_ts_');
    $tsq = tempnam(sys_get_temp_dir(), 'tscrub_tsq_');
    $tsr = tempnam(sys_get_temp_dir(), 'tscrub_tsr_');
    if ($tmp === false || $tsq === false || $tsr === false) return null;
    if (file_put_contents($tmp, $canonical) === false) return null;

    try {
        if (jsonld_run(['openssl', 'ts', '-query', '-data', $tmp, '-sha256', '-cert', '-out', $tsq]) === null) {
            return null;
        }
        $req = @file_get_contents($tsq);
        if ($req === false || $req === '') return null;

        $ch = curl_init($tsa);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/timestamp-query'],
            CURLOPT_POSTFIELDS => $req,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $resp === '') {
            error_log('jsonld: TSA request failed: ' . $err);
            return null;
        }
        if (file_put_contents($tsr, $resp) === false) return null;

        $state = 'recorded';
        $ca = (string)(db_config()['tsa_ca'] ?? '');
        if ($ca !== '' && is_file($ca)) {
            if (jsonld_run(['openssl', 'ts', '-verify', '-data', $tmp, '-in', $tsr, '-CAfile', $ca]) !== null) {
                $state = 'verified';
            }
        }

        return ['token' => jsonld_b64url((string)$resp), 'state' => $state, 'algorithm' => 'sha256'];
    } finally {
        @unlink($tmp);
        @unlink($tsq);
        @unlink($tsr);
    }
}

/** Local "N/A / blank → ''" normalizer (norm_na lives in certifier.php). */
function jsonld_na(string $v): string {
    $t = trim($v);
    return ($t === '' || strcasecmp($t, 'N/A') === 0) ? '' : $t;
}

/** 'YYYY-MM-DD HH:MM:SS' (UTC) → 'YYYY-MM-DDTHH:MM:SSZ'; '' when unparsable. */
function jsonld_iso(?string $mysqlUtc): string {
    $t = trim((string)$mysqlUtc);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $t, $m)) {
        return $m[1] . 'T' . $m[2] . 'Z';
    }
    return '';
}

/**
 * Normalize a drive row to one canonical key set. Accepts both the rendered
 * shape (cls/cert/status/system/sysserial/bbserial) and the DB row shape
 * (class/certification/final_status/system/system_serial/baseboard_serial).
 */
function jsonld_normalize_drive(array $d): array {
    return [
        'ts'            => jsonld_na((string)($d['ts'] ?? '')),
        'device'        => jsonld_na((string)($d['device'] ?? '')),
        'type'          => jsonld_na((string)($d['type'] ?? '')),
        'model'         => jsonld_na((string)($d['model'] ?? '')),
        'serial'        => jsonld_na((string)($d['serial'] ?? '')),
        'size'          => jsonld_na((string)($d['size'] ?? '')),
        'bus'           => jsonld_na((string)($d['bus'] ?? '')),
        'class'         => jsonld_na((string)($d['cls'] ?? $d['class'] ?? '')),
        'method'        => jsonld_na((string)($d['method'] ?? '')),
        'certification' => jsonld_na((string)($d['cert'] ?? $d['certification'] ?? '')),
        'final_status'  => jsonld_na((string)($d['status'] ?? $d['final_status'] ?? '')),
        'system'        => jsonld_na((string)($d['system'] ?? $d['system_name'] ?? '')),
        'system_serial' => jsonld_na((string)($d['sysserial'] ?? $d['system_serial'] ?? '')),
        'baseboard_serial' => jsonld_na((string)($d['bbserial'] ?? $d['baseboard_serial'] ?? '')),
        'smart'         => jsonld_na((string)($d['smart'] ?? '')),
        'tempc'         => jsonld_na((string)($d['tempc'] ?? '')),
        'poweronhours'  => jsonld_na((string)($d['poweronhours'] ?? '')),
        'powercycles'   => jsonld_na((string)($d['powercycles'] ?? '')),
        'reallocsectors' => jsonld_na((string)($d['reallocsectors'] ?? '')),
        'pctused'       => jsonld_na((string)($d['pctused'] ?? '')),
        'availspare'    => jsonld_na((string)($d['availspare'] ?? '')),
        'tbw_tb'        => jsonld_na((string)($d['tbw_tb'] ?? '')),
        'smartpost'     => jsonld_na((string)($d['smartpost'] ?? '')),
        'tempcpost'     => jsonld_na((string)($d['tempcpost'] ?? '')),
        'poweronhourspost' => jsonld_na((string)($d['poweronhourspost'] ?? '')),
        'firmware'      => jsonld_na((string)($d['firmware'] ?? '')),
        'sector_size'   => jsonld_na((string)($d['sector_size'] ?? '')),
        'sectors'       => jsonld_na((string)($d['sectors'] ?? '')),
        'hpa'           => jsonld_na((string)($d['hpa'] ?? '')),
        'dco'           => jsonld_na((string)($d['dco'] ?? '')),
        'sed_status'    => jsonld_na((string)($d['sed_status'] ?? '')),
        'reallocsectorspost' => jsonld_na((string)($d['reallocsectorspost'] ?? '')),
        'selftest'      => jsonld_na((string)($d['selftest'] ?? '')),
        'start_time'    => jsonld_na((string)($d['start_time'] ?? '')),
        'end_time'      => jsonld_na((string)($d['end_time'] ?? '')),
        'duration_secs' => jsonld_na((string)($d['duration_secs'] ?? '')),
        'tool_version'  => jsonld_na((string)($d['tool_version'] ?? '')),
        'operator'      => jsonld_na((string)($d['operator'] ?? '')),
        'validator'     => jsonld_na((string)($d['validator'] ?? '')),
        'media_source'  => jsonld_na((string)($d['media_source'] ?? '')),
        'media_destination' => jsonld_na((string)($d['media_destination'] ?? '')),
    ];
}

function jsonld_drive(array $norm): array {
    $props = [];
    foreach ([
        'ts', 'size', 'bus', 'class', 'system', 'system_serial', 'baseboard_serial',
        'smart', 'tempc', 'poweronhours', 'powercycles', 'reallocsectors',
        'pctused', 'availspare', 'tbw_tb', 'smartpost', 'tempcpost',
        'poweronhourspost', 'firmware', 'sector_size', 'sectors', 'hpa', 'dco',
        'sed_status', 'reallocsectorspost', 'selftest', 'tool_version', 'operator',
        'validator', 'media_source', 'media_destination',
    ] as $k) {
        if (($norm[$k] ?? '') !== '') { $props[$k] = $norm[$k]; }
    }

    $duration = ($norm['duration_secs'] !== '' && ctype_digit($norm['duration_secs']))
        ? (int)$norm['duration_secs']
        : null;

    $d = [
        '@type' => 'Product',
        'name'  => $norm['model'],
        'serialNumber' => $norm['serial'],
        'productID'    => $norm['device'],
        'category'     => $norm['type'],
        'sanitizationMethod' => $norm['method'],
        'sanitizationTechnique' => cert_technique_label($norm['method'], $norm['class'], $norm['final_status']),
        'nistLevel'    => cert_nist_class($norm['class'], $norm['final_status']),
        'certificationLevel' => $norm['certification'],
        'finalStatus'  => $norm['final_status'],
        'startTime'    => jsonld_iso($norm['start_time']),
        'endTime'      => jsonld_iso($norm['end_time']),
        'duration'     => $duration,
    ];
    if ($props !== []) { $d['properties'] = $props; }
    return $d;
}

/**
 * Build the unsigned JSON-LD document from the certificate row + its drive and
 * report rows. $summary keys: cert, cocid, issued_at, devices, methods, runs,
 * first, last, sha_state, sig_state, pdf_sha256.
 */
function build_cert_jsonld(array $summary, array $drives, array $reports, array $issuer): array {
    $context = [
        'https://schema.org',
        [
            'tscrub' => 'https://tscrub.com/ns#',
            'DataDestructionCertificate' => 'tscrub:DataDestructionCertificate',
            'chainOfCustodyId' => 'tscrub:chainOfCustodyId',
            'sanitizationMethod' => 'tscrub:sanitizationMethod',
            'sanitizationTechnique' => 'tscrub:sanitizationTechnique',
            'nistLevel' => 'tscrub:nistLevel',
            'certificationLevel' => 'tscrub:certificationLevel',
            'finalStatus' => 'tscrub:finalStatus',
            'pdfSha256' => 'tscrub:pdfSha256',
            'report' => 'tscrub:report',
            'reportSha256' => 'tscrub:reportSha256',
            'shaState' => 'tscrub:shaState',
            'signatureState' => 'tscrub:signatureState',
            'properties' => 'tscrub:properties',
        ],
    ];

    $doc = [
        '@context' => $context,
        '@type' => 'DataDestructionCertificate',
        'identifier' => (string)$summary['cert'],
        'chainOfCustodyId' => (string)$summary['cocid'],
        'dateIssued' => jsonld_iso((string)$summary['issued_at']),
        'issuedBy' => [
            '@type' => 'Organization',
            'name' => (string)($issuer['name'] ?? ''),
            'identifier' => (string)($issuer['reg'] ?? ''),
            'address' => (string)($issuer['addr'] ?? ''),
            'telephone' => (string)($issuer['phone'] ?? ''),
        ],
        'devices' => (int)$summary['devices'],
        'methods' => (int)$summary['methods'],
        'runs' => (int)$summary['runs'],
        'pdfSha256' => (string)$summary['pdf_sha256'],
        'shaState' => (string)$summary['sha_state'],
        'signatureState' => (string)$summary['sig_state'],
        'about' => [],
        'report' => [],
    ];

    // Media disposition + how the data was destroyed (mirrors the PDF certificate
    // — see research/cert-destruction-evidence/README.md).
    $mediaDisposition = cert_media_disposition($drives);
    if ($mediaDisposition !== '') { $doc['mediaDisposition'] = $mediaDisposition; }

    if (($summary['first'] ?? null) !== null && (string)$summary['first'] !== '') {
        $doc['dateCreated'] = jsonld_iso((string)$summary['first']);
    }
    if (($summary['last'] ?? null) !== null && (string)$summary['last'] !== '') {
        $doc['dateModified'] = jsonld_iso((string)$summary['last']);
    }

    foreach ($drives as $d) {
        $doc['about'][] = jsonld_drive(jsonld_normalize_drive($d));
    }
    foreach ($reports as $r) {
        $doc['report'][] = [
            'name' => (string)($r['name'] ?? ''),
            'reportSha256' => strtolower((string)($r['sha'] ?? $r['sha256'] ?? '')),
            'state' => (string)($r['state'] ?? ''),
        ];
    }

    return $doc;
}

/**
 * Canonicalize, sign and timestamp a built document. Returns
 * ['json' => final JSON string, 'sha256' => hex, 'ts_state' => string]
 * or null when signing is unavailable. Signing failure is non-fatal at the
 * caller's discretion — the PDF is still issued.
 */
function jsonld_sign_document(array $doc): ?array {
    $canonical = jsonld_jcs($doc);
    $sig = jsonld_sign($canonical);
    if ($sig === null) return null;

    $proof = [
        'type' => 'Ed25519Signature2020',
        'created' => gmdate('Y-m-d\TH:i:s\Z'),
        'verificationMethod' => db_base_url() . '/.well-known/tscrub-cert-key.json',
        'signatureValue' => $sig,
    ];

    $tsState = 'none';
    $ts = jsonld_timestamp($canonical);
    if ($ts !== null) {
        $tsState = (string)$ts['state'];
        $proof['timestamp'] = [
            'type' => 'RFC3161',
            'digestAlgorithm' => (string)$ts['algorithm'],
            'timestampToken' => (string)$ts['token'],
            'state' => $tsState,
        ];
    }

    $doc['proof'] = $proof;
    $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return null;

    return [
        'json' => $json,
        'sha256' => strtolower(hash('sha256', $json)),
        'ts_state' => $tsState,
    ];
}

/** Write the JSON-LD file and return its relative path (cert_id.jsonld). */
function jsonld_write(string $json, string $certId): string {
    $dir = __DIR__ . '/certs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $rel = $certId . '.jsonld';
    @file_put_contents($dir . '/' . $rel, $json);
    return $rel;
}

function jsonld_load_drives(int $certId): array {
    $stmt = db()->prepare(
        'SELECT ts, device, type, model, serial, size, bus, class, method, certification, final_status,
                system_name, system_serial, baseboard_serial,
                smart, tempc, poweronhours, powercycles, reallocsectors, pctused, availspare, tbw_tb,
                smartpost, tempcpost, poweronhourspost,
                firmware, sector_size, sectors, hpa, dco, sed_status, reallocsectorspost, selftest,
                start_time, end_time, duration_secs, tool_version, operator, validator, media_source, media_destination
         FROM certificate_drives WHERE certificate_id = ? ORDER BY id'
    );
    $stmt->execute([$certId]);
    return $stmt->fetchAll();
}

function jsonld_load_reports(int $certId): array {
    $stmt = db()->prepare('SELECT report_name, sha256, state FROM certificate_reports WHERE certificate_id = ? ORDER BY id');
    $stmt->execute([$certId]);
    return array_map(
        fn($r) => ['name' => (string)$r['report_name'], 'sha' => (string)$r['sha256'], 'state' => (string)$r['state']],
        $stmt->fetchAll()
    );
}

/**
 * Return the JSON-LD for a certificate row, generating + persisting it lazily
 * when it doesn't exist yet (e.g. certs issued before this feature shipped).
 */
function jsonld_ensure_cert(array $certRow): ?array {
    $certId = (string)$certRow['cert_id'];
    $dir = __DIR__ . '/certs';
    $path = (string)($certRow['json_path'] ?? '');
    if ($path !== '' && is_file($dir . '/' . $path)) {
        $json = (string)file_get_contents($dir . '/' . $path);
        if ($json !== '') {
            return [
                'json' => $json,
                'sha256' => strtolower(hash('sha256', $json)),
                'ts_state' => (string)($certRow['json_ts_state'] ?? 'none'),
            ];
        }
    }

    $summary = [
        'cert'       => $certId,
        'cocid'      => (string)$certRow['cocid'],
        'issued_at'  => (string)$certRow['issued_at'],
        'devices'    => (int)$certRow['devices'],
        'methods'    => (int)$certRow['methods'],
        'runs'       => (int)$certRow['runs'],
        'first'      => $certRow['first_ts'] ?? null,
        'last'       => $certRow['last_ts'] ?? null,
        'sha_state'  => (string)$certRow['sha_state'],
        'sig_state'  => (string)$certRow['sig_state'],
        'pdf_sha256' => (string)$certRow['pdf_sha256'],
    ];
    $issuer = certifier_for_user((int)($certRow['user_id'] ?? 0));
    $doc = build_cert_jsonld($summary, jsonld_load_drives((int)$certRow['id']), jsonld_load_reports((int)$certRow['id']), $issuer);
    $signed = jsonld_sign_document($doc);
    if ($signed === null) return null;

    $rel = jsonld_write($signed['json'], $certId);
    db()->prepare('UPDATE certificates SET json_path = ?, json_sha256 = ?, json_ts_state = ? WHERE id = ?')
        ->execute([$rel, $signed['sha256'], $signed['ts_state'], (int)$certRow['id']]);
    return $signed;
}

/**
 * Lazily add the machine-readable columns to `certificates` (idempotent —
 * mirrors schema.sql; an existing server self-heals on first use).
 */
function certs_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [];
    foreach (db()->query('SHOW COLUMNS FROM certificates')->fetchAll() as $c) {
        $cols[strtolower((string)$c['Field'])] = true;
    }
    if (!isset($cols['json_path'])) {
        db()->exec("ALTER TABLE certificates ADD COLUMN json_path VARCHAR(255) NOT NULL DEFAULT ''");
    }
    if (!isset($cols['json_sha256'])) {
        db()->exec("ALTER TABLE certificates ADD COLUMN json_sha256 CHAR(64) NOT NULL DEFAULT ''");
    }
    if (!isset($cols['json_ts_state'])) {
        db()->exec("ALTER TABLE certificates ADD COLUMN json_ts_state VARCHAR(16) NOT NULL DEFAULT 'none'");
    }
}
