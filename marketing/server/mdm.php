<?php
declare(strict_types=1);

/**
 * Windows Autopilot MDM check — server-side Graph probe.
 *
 * Implements BitRaser's "try to enrol → read the verdict → delete the probe"
 * flow against Microsoft Graph, with the Azure tenant/app credentials held
 * SERVER-SIDE (config.json → "autopilot" block) so the tScrub appliance never
 * carries them. The appliance posts {serial, uuid, manufacturer, product}; this
 * library builds the OAv3 "4K hardware hash" (base mode) and returns a verdict.
 *
 * See research/autopilot-report.md §25 and research/autopilot-build-plan.md.
 *
 * Verdicts:
 *   unlocked      import completed with code 0 — not enrolled anywhere
 *   locked_this   ZtdDeviceAlreadyAssigned     — enrolled in the caller's tenant
 *   locked_other  ZtdDeviceAssignedToOtherTenant — enrolled in another tenant
 *   hash_invalid  InvalidZtdHardwareHash       — hash rejected (shouldn't occur)
 *   unknown       import still queued/processing when the poll window closed
 *   offline       token/network/Graph failure
 *   error         any other terminal state
 *
 * The Graph import is ASYNCHRONOUS: POST returns 201 with state "unknown"; the
 * real verdict arrives later in state.deviceErrorCode / deviceErrorName.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/org.php';

// ---- configuration --------------------------------------------------------

function mdm_settings(): array {
    if (isset($GLOBALS['mdm_settings_override']) && is_array($GLOBALS['mdm_settings_override'])) {
        return $GLOBALS['mdm_settings_override'];
    }
    $s = db_config()['autopilot'] ?? [];
    return is_array($s) ? $s : [];
}

function mdm_configured(): bool {
    $s = mdm_settings();
    return (($s['tenant_id'] ?? '') !== '')
        && (($s['client_id'] ?? '') !== '')
        && (($s['client_secret'] ?? '') !== '');
}

/** Shared secret for the tenant-agnostic WinPE hash-report ingest. Not a user
 *  API token — it only authenticates the upload; the hash itself is staged
 *  unassigned and claimed by whichever tenant uploads the device's report. */
function mdm_ingest_key(): string {
    return (string)(db_config()['mdm_ingest_key'] ?? '');
}

function mdm_authority(): string {
    return rtrim((string)(mdm_settings()['authority'] ?? 'https://login.microsoftonline.com'), '/');
}

function mdm_graph(): string {
    return rtrim((string)(mdm_settings()['graph_url'] ?? 'https://graph.microsoft.com'), '/');
}

/**
 * Human-readable status word for a device, keyed off the machine state
 * (status + verdict). This is the SINGLE source of truth for MDM wording: the
 * appliance renders `label` verbatim in its Runtime panel instead of mapping
 * verdicts itself, so the wording can change server-side without an appliance
 * release. Keep the labels short enough for the runtime panel's value column.
 * Labels MUST be ASCII: the appliance's sed-based JSON parser does not decode
 * \uXXXX escapes (a Unicode "…" would render as the literal "Checking\u2026"
 * on the console).
 */
function mdm_status_label(string $status, string $verdict): string {
    $st = strtolower($status);
    $v  = strtolower($verdict);

    if ($st === 'checking') return 'Checking...';
    if ($st === 'queued')   return 'Queued';
    if ($st === 'failed')   return 'Failed';
    if ($st === 'unchecked') return 'Unchecked';
    if ($st === 'na')       return '---';

    switch ($v) {
        case 'unlocked':     return 'Unlocked';
        case 'locked_this':  return 'Locked (this)';
        case 'locked_other': return 'Locked';
        case 'hash_invalid': return 'Invalid hash';
        case 'ms_error':     return 'MS error';
        case 'offline':      return 'Offline';
        case 'error':        return 'Error';
        case 'unknown':      return 'Pending';
        case 'skipped':      return 'Skipped';
        case 'na':           return '---';
        case 'paid_only':    return 'Paid only';
        case 'insufficient_credits': return 'No credits';
    }
    return 'Unknown';
}

/** The user's most recent licence tier (free when none). Mirrors owner_tier()
 *  in api.php so the MDM worker (which does not load api.php) can gate too. */
function mdm_user_tier(int $userId): string {
    return org_tier($userId);
}

/**
 * MDM billing gate. MDM is a paid feature: the free tier cannot check, and paid
 * tiers spend one credit per live Graph probe. Returns the decision plus the
 * current balance so callers can render a precise status ("Paid only" /
 * "No credits").
 */
function mdm_gate(int $userId): array {
    $tier = mdm_user_tier($userId);
    if ($tier === 'free') {
        return ['allowed' => false, 'reason' => 'free_tier', 'balance' => credit_balance($userId)];
    }
    $balance = credit_balance($userId);
    if ($balance < 1) {
        return ['allowed' => false, 'reason' => 'insufficient_credits', 'balance' => 0];
    }
    return ['allowed' => true, 'reason' => 'ok', 'balance' => $balance];
}

// ---- OAv3 4K hardware hash (base mode) ------------------------------------
// Byte-for-byte port of research/oa3hash.py build_records()/encode() base set,
// validated 2026-09-28 against a real oa3tool.exe capture and live-tested
// against Microsoft Graph (accepted, code 0).

function mdm_u16le(int $n): string {
    return pack('v', $n & 0xffff);
}

/** Canonical RFC 4122 UUID → 16-byte SMBIOS wire order (type 12). */
function mdm_uuid_wire(string $uuid): string {
    $hex = preg_replace('/[^0-9a-fA-F]/', '', $uuid);
    if (strlen($hex) !== 32) {
        return '';
    }
    $b = hex2bin($hex);
    if ($b === false) {
        return '';
    }
    // bytes 0-3 reversed, 4-5 reversed, 6-7 reversed, 8-15 as-is.
    return strrev(substr($b, 0, 4)) . strrev(substr($b, 4, 2)) . strrev(substr($b, 6, 2)) . substr($b, 8, 8);
}

/** Append one TLV record: {u16 type, u16 length, data} where length = 4 + len(data). */
function mdm_add_record(string &$out, int $type, string $data): void {
    if ($data === '') {
        return;
    }
    $out .= mdm_u16le($type) . mdm_u16le(4 + strlen($data)) . $data;
}

/**
 * Build the base hardware hash: types 12 (UUID), 14 (serial), 16 (manufacturer),
 * 17 (product), then the CS checksum record, zero-padded to 3000 bytes,
 * base64-encoded. Returns '' if the payload overflows (invalid input).
 */
function mdm_base_hash(string $serial, string $uuid, string $manufacturer, string $product): string {
    $tlv = '';
    if ($uuid !== '') {
        $wire = mdm_uuid_wire($uuid);
        mdm_add_record($tlv, 12, $wire !== '' ? $wire : $uuid . "\0");
    }
    if ($serial !== '')       { mdm_add_record($tlv, 14, $serial . "\0"); }
    if ($manufacturer !== '') { mdm_add_record($tlv, 16, $manufacturer . "\0"); }
    if ($product !== '')      { mdm_add_record($tlv, 17, $product . "\0"); }

    $total  = 4 + strlen($tlv);
    $prefix = 'OA' . mdm_u16le($total) . $tlv;
    $payload = $prefix . 'CS' . mdm_u16le(36) . hash('sha256', $prefix, true);
    if (strlen($payload) > 3000) {
        return '';
    }
    $payload .= str_repeat("\0", 3000 - strlen($payload));
    return base64_encode($payload);
}

/** Normalise a report string field for hash building: '' and 'N/A' both mean
 *  "no value" (the appliance fills missing SMBIOS fields with 'N/A'). */
function mdm_report_str(string $v): string {
    $v = trim($v);
    return ($v === '' || strcasecmp($v, 'N/A') === 0) ? '' : $v;
}

/** Disk serial/model normaliser: same ''/'N/A' rule but NO whitespace trim —
 *  the fixed-width ATA/NVMe serial keeps its spaces verbatim (Windows does the
 *  same), unlike the SMBIOS strings handled by mdm_report_str(). */
function mdm_report_disk_str(string $v): string {
    return ($v === '' || strcasecmp(trim($v), 'N/A') === 0) ? '' : $v;
}

/** Type-8 network record (22 bytes): u16 medium=14 + u16 0 + 6-byte MAC +
 *  u32 length + "PCI\0" UTF-16LE. Returns '' for an invalid MAC. */
function mdm_mac_record(string $mac): string {
    $m = str_replace([':', '-', ' '], '', $mac);
    if (preg_match('/^[0-9a-fA-F]{12}$/', $m) !== 1) {
        return '';
    }
    $bin = hex2bin($m);
    if ($bin === false) {
        return '';
    }
    $utf16 = '';
    foreach (str_split("PCI\0") as $c) {
        $utf16 .= $c . "\0";
    }
    return mdm_u16le(14) . "\0\0" . $bin . pack('V', strlen($utf16)) . $utf16;
}

/** TPM 2.0 type-13 descriptor from raw `tpm2_getcap properties-fixed` output
 *  (the appliance collapses newlines to ';'). Port of oa3hash_min.py.
 *
 *  Handles BOTH tpm2-tools output styles: the current Buildroot build emits
 *  'TPM2_PT_X:;  raw: 0x..;  value: ...;' (value may be quoted or bare), and
 *  older builds emit 'TPM2_PT_X: 0x..' / 'TPM2_PT_LEVEL: 0'. */
function mdm_tpm_descriptor_20(string $getcap): string {
    if ($getcap === '') return '';

    $rawHex = function (string $prop) use ($getcap): ?string {
        // Current 'raw: 0x..' style first (non-greedy -> the prop's OWN raw
        // value, never crossing into the next property), then the old '0x..'.
        if (preg_match('/' . $prop . ':.*?raw:\s*0x([0-9a-fA-F]+)/s', $getcap, $m) === 1) return $m[1];
        if (preg_match('/' . $prop . ':\s*0x([0-9a-fA-F]+)/s', $getcap, $m) === 1) return $m[1];
        return null;
    };
    $valueStr = function (string $prop, ?string $default = null) use ($getcap): ?string {
        // value may be quoted ("2.0") or bare (1.16); non-greedy so a bare
        // REVISION value can't swallow a later quoted MANUFACTURER value.
        if (preg_match('/' . $prop . ':.*?value:\s*"?([^";]+)"?/s', $getcap, $m) === 1) return $m[1];
        return $default;
    };

    $family = $valueStr('TPM2_PT_FAMILY_INDICATOR', '2.0') ?? '2.0';
    $rev    = $valueStr('TPM2_PT_REVISION');
    $mfrHex = $rawHex('TPM2_PT_MANUFACTURER');
    $fw1Hex = $rawHex('TPM2_PT_FIRMWARE_VERSION_1');
    $fw2Hex = $rawHex('TPM2_PT_FIRMWARE_VERSION_2');

    // LEVEL's raw is DECIMAL in both styles ('raw: 0' / '0').
    $level = 0;
    if (preg_match('/TPM2_PT_LEVEL:.*?raw:\s*(\d+)/s', $getcap, $m) === 1) {
        $level = (int)$m[1];
    } elseif (preg_match('/TPM2_PT_LEVEL:\s*(\d+)/s', $getcap, $m) === 1) {
        $level = (int)$m[1];
    }

    $vendor = '';
    if ($mfrHex !== null) {
        $bin = hex2bin(str_pad($mfrHex, 8, '0', STR_PAD_LEFT));
        if ($bin !== false) $vendor = str_replace("\0", ' ', $bin);
    }
    $s = 'TPM-Version:' . $family . ' -Level:' . $level;
    if ($rev !== null && $rev !== '') $s .= '-Revision:' . $rev;
    if ($vendor !== '') $s .= "-VendorID:'" . $vendor . "'";
    if ($fw1Hex !== null && $fw2Hex !== null) $s .= '-Firmware:' . hexdec($fw1Hex) . '.' . hexdec($fw2Hex);
    return $s;
}

/** TPM 1.2 type-13 descriptor from the sysfs caps file (collapsed to ';').
 *  SpecLevel/Errata are hardcoded 2/3 (constant across the TPM 1.2 spec). */
function mdm_tpm_descriptor_12(string $caps): string {
    if ($caps === '') return '';
    $vendor = '';
    if (preg_match('/Manufacturer:\s*0x([0-9a-fA-F]+)/', $caps, $m) === 1) {
        $bin = hex2bin(str_pad($m[1], 8, '0', STR_PAD_LEFT));
        if ($bin !== false) $vendor = str_replace("\0", ' ', $bin);
    }
    $fw1 = null; $fw2 = null;
    if (preg_match('/Firmware version:\s*([\d.]+)/', $caps, $m) === 1) {
        $parts = explode('.', $m[1]);
        if (isset($parts[0]) && ctype_digit($parts[0])) $fw1 = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        if (isset($parts[1]) && ctype_digit($parts[1])) $fw2 = $parts[1];
    }
    $s = 'TPM-Version:01.02-SpecLevel:2-Errata:3';
    if ($vendor !== '') $s .= "-VendorID:'" . $vendor . "'";
    if ($fw1 !== null && $fw2 !== null) $s .= '-Firmware:' . $fw1 . '.' . $fw2;
    return $s;
}

/**
 * Build the full OAv3 "4K" hardware hash from a diagnostics-report payload —
 * the tScrub-standalone strategy (no WinPE oa3tool capture needed). Port of
 * research/oa3hash_min.py hw_from_tscrub_report() + build_records() + encode():
 * identity types 12 (UUID), 14 (serial), 15 (bios vendor), 16 (manufacturer),
 * 17 (product), 18 (sku), 19 (family), 21 (board product), 22 (board version),
 * 23 (system version), 24 (ProductKeyId), 13 (TPM descriptor), 25 (EkPub),
 * 11 (OfflineDeviceId = SHA-256 of the EK), 7 (disk serial), 8 (MAC).
 * Live-verified 2026-10-03: yields the same Microsoft verdict
 * (ZtdDeviceAssignedToOtherTenant) as the WinPE oa3tool hash.
 *
 * $d may carry a pre-derived 'product_key_id'; callers with reports_lib loaded
 * should set it (product_key_id_from_key()). Returns '' when the serial is
 * absent (no hash can be built).
 */
function mdm_report_hash(array $d): string {
    $serial = mdm_report_str((string)($d['serial'] ?? ''));
    if ($serial === '') return '';

    $tlv = '';
    $add = function (int $t, string $data) use (&$tlv): void {
        mdm_add_record($tlv, $t, $data);
    };
    $addStr = function (int $t, string $v) use (&$tlv): void {
        if ($v !== '') mdm_add_record($tlv, $t, $v . "\0");
    };

    $uuid = mdm_report_str((string)($d['uuid'] ?? ''));
    if ($uuid !== '') {
        $add(12, mdm_uuid_wire($uuid)); // '' -> omitted (invalid GUID)
    }
    $addStr(14, $serial);
    $addStr(15, mdm_report_str((string)($d['bios_vendor'] ?? '')));
    $addStr(16, mdm_report_str((string)($d['manufacturer'] ?? '')));
    $addStr(17, mdm_report_str((string)($d['product'] ?? '')));
    $addStr(18, mdm_report_str((string)($d['sku'] ?? '')));
    $addStr(19, mdm_report_str((string)($d['family'] ?? '')));
    $addStr(21, mdm_report_str((string)($d['board_product'] ?? '')));
    $addStr(22, mdm_report_str((string)($d['board_version'] ?? '')));
    $addStr(23, mdm_report_str((string)($d['system_version'] ?? '')));
    $addStr(24, mdm_report_str((string)($d['product_key_id'] ?? '')));

    // TPM descriptor: supplied directly, else derived from tpm_getcap (2.0) or
    // tpm_caps (1.2). EkPub (25) + OfflineDeviceId (11) are TPM 2.0 only — a
    // TPM 1.2/no-TPM type 11 is a Windows-persisted random seed.
    $tpmDesc = mdm_report_str((string)($d['tpm_descriptor'] ?? ''));
    if ($tpmDesc === '') $tpmDesc = mdm_tpm_descriptor_20((string)($d['tpm_getcap'] ?? ''));
    if ($tpmDesc === '') $tpmDesc = mdm_tpm_descriptor_12((string)($d['tpm_caps'] ?? ''));
    $addStr(13, $tpmDesc);

    // The EK is an RSA-2048 modulus: exactly 256 bytes (512 hex chars). Drop a
    // truncated/malformed value rather than emit a corrupt type 25/11.
    $ekHex = strtolower((string)($d['tpm_ekpub'] ?? ''));
    if (strlen($ekHex) === 512 && preg_match('/^[0-9a-f]+$/', $ekHex) === 1) {
        $ekBytes = hex2bin($ekHex);
        if ($ekBytes !== false) {
            $add(25, $ekBytes);
            $add(11, "\x00\x00\x00\x00\x01\x00\x20\x00\x00\x00" . hash('sha256', $ekBytes, true));
        }
    }

    // disk serial — '<serial>|<model>|' (NO trailing NUL), first drive wins.
    // Spaces are significant (fixed-width ATA/NVMe), so no trimming here.
    $disk = '';
    foreach (($d['drives'] ?? []) as $dv) {
        if (!is_array($dv)) continue;
        $dsn = mdm_report_disk_str((string)($dv['serial'] ?? ''));
        if ($dsn !== '') {
            $disk = $dsn . '|' . mdm_report_disk_str((string)($dv['model'] ?? '')) . '|';
            break;
        }
    }
    $add(7, $disk);

    // first physical MAC (skip the all-zero placeholder).
    $firstMac = '';
    foreach (explode(';', str_replace("\n", ';', (string)($d['macs'] ?? ''))) as $part) {
        $part = trim($part);
        if ($part !== '' && strtolower($part) !== '00:00:00:00:00:00') {
            $firstMac = $part;
            break;
        }
    }
    $add(8, mdm_mac_record($firstMac));

    $total  = 4 + strlen($tlv);
    $prefix = 'OA' . mdm_u16le($total) . $tlv;
    $payload = $prefix . 'CS' . mdm_u16le(36) . hash('sha256', $prefix, true);
    if (strlen($payload) > 3000) return '';
    $payload .= str_repeat("\0", 3000 - strlen($payload));
    return base64_encode($payload);
}

// ---- HTTP (curl, with a test hook) ----------------------------------------

/**
 * One HTTP call with retry. $req = [method, url, headers[], body, timeout].
 * Returns [ok => bool, status => int, body => string, headers => string[]].
 * Transient transport failures (DNS/connect/SSL/timeout) and Graph 5xx
 * responses are retried up to twice with a short backoff. POSTs are NOT
 * re-issued on 5xx (a retried import could create a duplicate queue entry).
 * HTTP 429 responses are retried honouring the Retry-After header (a 429'd
 * request was never processed, so retrying is safe even for POST); the sleep
 * is capped at 60s. Other 2xx/4xx are returned immediately.
 * $GLOBALS['mdm_http_override'] (a callable) replaces the transport — used by
 * the offline test harness.
 */
function mdm_http(array $req): array {
    $method = strtoupper((string)($req['method'] ?? 'GET'));
    $last = ['ok' => false, 'status' => 0, 'body' => '', 'headers' => []];
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $last = mdm_http_once($req);
        $transportFail = !$last['ok'] || $last['status'] === 0;
        $serverFail   = $last['ok'] && $last['status'] >= 500 && $method !== 'POST';
        $rateLimited  = $last['ok'] && $last['status'] === 429;
        if (!$transportFail && !$serverFail && !$rateLimited) {
            return $last;
        }
        if ($attempt < 2) {
            if ($rateLimited) {
                $retryAfter = (int)($last['headers']['retry-after'] ?? 5);
                if ($retryAfter < 1) { $retryAfter = 5; }
                if ($retryAfter > 60) { $retryAfter = 60; }
                sleep($retryAfter);
            } else {
                usleep(400000 * ($attempt + 1)); // 0.4 s, 0.8 s
            }
        }
    }
    return $last;
}

/** Single-shot transport (no retry) — split out so mdm_http() can retry. */
function mdm_http_once(array $req): array {
    if (isset($GLOBALS['mdm_http_override']) && is_callable($GLOBALS['mdm_http_override'])) {
        return call_user_func($GLOBALS['mdm_http_override'], $req);
    }
    if (!function_exists('curl_init')) {
        error_log('mdm: curl extension missing');
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => []];
    }
    $ch = curl_init((string)($req['url'] ?? ''));
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => []];
    }
    $method = strtoupper((string)($req['method'] ?? 'GET'));
    // Collect response headers (lowercased name → value) so callers can honour
    // Retry-After on a 429.
    $respHeaders = [];
    $opts = [
        CURLOPT_HTTPHEADER       => (array)($req['headers'] ?? []),
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_CONNECTTIMEOUT   => 10,
        CURLOPT_TIMEOUT          => (int)($req['timeout'] ?? 60),
        // This host has a broken IPv6 default route (curl -6 to Graph hangs);
        // the intermittent SSL_ERROR_SYSCALL failures were IPv6 connection
        // attempts. Force IPv4 so probes never traverse the dead path.
        CURLOPT_IPRESOLVE        => CURL_IPRESOLVE_V4,
        CURLOPT_HEADERFUNCTION   => function ($ch, string $line) use (&$respHeaders): int {
            $len = strlen($line);
            $trimmed = trim($line);
            $pos = strpos($trimmed, ':');
            if ($pos !== false) {
                $name  = strtolower(trim(substr($trimmed, 0, $pos)));
                $value = trim(substr($trimmed, $pos + 1));
                if ($name !== '') {
                    $respHeaders[$name] = $value;
                }
            }
            return $len;
        },
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = (string)($req['body'] ?? '');
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($ch, $opts);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('mdm http error: ' . $err);
        return ['ok' => false, 'status' => 0, 'body' => '', 'headers' => []];
    }
    return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'headers' => $respHeaders];
}

/** Quick reachability pre-flight for the Graph endpoint (IPv4, short timeout).
 *  Any HTTP response — even 401 unauth — proves DNS + TLS + route are up. */
function mdm_graph_reachable(): bool {
    $r = mdm_http_once([
        'method'  => 'GET',
        'url'     => mdm_graph() . '/v1.0/organization',
        'headers' => [],
        'timeout' => 8,
    ]);
    return $r['ok'];
}

/**
 * Ordered list of probe tenants. The primary tenant (config.json "autopilot")
 * comes first, then any extra tenants from "autopilot_tenants". The Autopilot
 * import verdicts are tenant-independent — "ZtdDeviceAssignedToOtherTenant"
 * means the hash matches a device in SOME other tenant, and a clean import
 * completes code 0 regardless of which tenant runs it — so a healthy alternate
 * tenant answers the same question while another tenant's Autopilot service is
 * degraded (the BitRaser shared-probe-tenant model).
 *
 * "autopilot_tenants" is an array of {tenant_id, client_id, client_secret}.
 * All tenants share the primary's graph_url/authority (Microsoft endpoints).
 */
function mdm_tenant_pool(): array {
    $pool = [];
    if (mdm_configured()) {
        $pool[] = mdm_settings();
    }
    $extras = db_config()['autopilot_tenants'] ?? [];
    if (is_array($extras)) {
        foreach ($extras as $t) {
            if (!is_array($t)) {
                continue;
            }
            if ((($t['tenant_id'] ?? '') !== '') && (($t['client_id'] ?? '') !== '') && (($t['client_secret'] ?? '') !== '')) {
                $pool[] = $t;
            }
        }
    }
    $seen = [];
    $out  = [];
    foreach ($pool as $t) {
        $tid = (string)($t['tenant_id'] ?? '');
        if ($tid === '' || isset($seen[$tid])) {
            continue;
        }
        $seen[$tid] = true;
        $out[] = $t;
    }
    return $out;
}

/** Health probe of Microsoft's Autopilot backend for one tenant.
 *
 *  The Autopilot registrations collection (`windowsAutopilotDeviceIdentities`)
 *  returns HTTP 500 on ANY `$select`, so it can't be used for a cheap health
 *  check. Read the tenant's Windows Autopilot sync status instead: Microsoft
 *  sets `syncStatus: "failed"` when the Autopilot ingestion backend is down for
 *  the tenant (the exact state behind the 2026-09-29/30 import stall). Only a
 *  transport failure, an HTTP 5xx, or an explicit `syncStatus: "failed"` is
 *  treated as a Microsoft-side error; 4xx (can't read the setting) is assumed
 *  up so the real probe can decide. When $token/$tenant are omitted the
 *  primary tenant is used. */
function mdm_autopilot_reachable(?string $token = null, ?array $tenant = null): bool {
    $s = $tenant ?? mdm_settings();
    if ((($s['tenant_id'] ?? '') === '') || (($s['client_id'] ?? '') === '') || (($s['client_secret'] ?? '') === '')) {
        return true; // unconfigured — let mdm_probe report its own verdict
    }
    if ($token === null || $token === '') {
        $token = mdm_token((string)$s['tenant_id'], (string)$s['client_id'], (string)$s['client_secret']);
        if ($token === null) {
            return false;
        }
    }
    $r = mdm_http_once([
        'method'  => 'GET',
        'url'     => mdm_graph() . '/beta/deviceManagement/windowsAutopilotSettings',
        'headers' => ['Authorization: Bearer ' . $token],
        'timeout' => 8,
    ]);
    if (!$r['ok']) {
        return false;
    }
    if ($r['status'] >= 500) {
        return false;
    }
    if ($r['status'] !== 200) {
        return true; // 4xx — can't read the setting, let the real probe decide
    }
    $d = json_decode($r['body'], true);
    if (!is_array($d)) {
        return true;
    }
    return strtolower((string)($d['syncStatus'] ?? '')) !== 'failed';
}

// ---- Graph client ---------------------------------------------------------

function mdm_token(string $tenant, string $client, string $secret): ?string {
    $url = mdm_authority() . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token';
    $r = mdm_http([
        'method'  => 'POST',
        'url'     => $url,
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        'body'    => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $client,
            'client_secret' => $secret,
            'scope'         => 'https://graph.microsoft.com/.default',
        ]),
        'timeout' => 30,
    ]);
    if (!$r['ok'] || $r['status'] !== 200) {
        return null;
    }
    $d = json_decode($r['body'], true);
    return (is_array($d) && !empty($d['access_token'])) ? (string)$d['access_token'] : null;
}

/** POST the probe import. Returns the decoded entity (with id) or null. */
function mdm_import(string $token, string $serial, string $hash): ?array {
    $url = mdm_graph() . '/beta/deviceManagement/importedWindowsAutopilotDeviceIdentities';
    $r = mdm_http([
        'method'  => 'POST',
        'url'     => $url,
        'headers' => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        'body'    => json_encode([
            '@odata.type'                   => '#microsoft.graph.importedWindowsAutopilotDeviceIdentity',
            'serialNumber'                  => $serial,
            'hardwareIdentifier'            => $hash,
            'productKey'                    => '',
            'groupTag'                      => '',
            'assignedUserPrincipalName'     => '',
        ]),
        'timeout' => 30,
    ]);
    if (!$r['ok'] || $r['status'] < 200 || $r['status'] >= 300) {
        return null;
    }
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : null;
}

/** Poll the import state until it reaches complete/error (async import). */
function mdm_poll(string $token, string $identityId, int $timeout = 120, int $interval = 5): array {
    $url = mdm_graph() . '/beta/deviceManagement/importedWindowsAutopilotDeviceIdentities/'
         . rawurlencode($identityId) . '?$select=id,state';
    $deadline = time() + $timeout;
    $last = [];
    $sawOk = false;
    while (time() < $deadline) {
        $r = mdm_http([
            'method'  => 'GET',
            'url'     => $url,
            'headers' => ['Authorization: Bearer ' . $token],
            'timeout' => 30,
        ]);
        if ($r['ok'] && $r['status'] === 200) {
            $sawOk = true;
            $d = json_decode($r['body'], true);
            if (is_array($d)) {
                $last = is_array($d['state'] ?? null) ? $d['state'] : [];
                $st = strtolower((string)($last['deviceImportStatus'] ?? ''));
                if ($st === 'complete' || $st === 'error') {
                    return $last;
                }
            }
        }
        sleep($interval);
    }
    // Never got a single successful poll GET → the transport to Graph is down,
    // NOT a pending import. Signal it distinctly so it maps to 'offline'.
    if (!$sawOk) {
        return ['deviceImportStatus' => '__transport__'];
    }
    return $last; // timed out — caller reports last-known state
}

/** Delete the probe identity we just imported (cleanup; never a real enrolment). */
function mdm_delete(string $token, string $identityId): void {
    $url = mdm_graph() . '/beta/deviceManagement/importedWindowsAutopilotDeviceIdentities/'
         . rawurlencode($identityId);
    mdm_http([
        'method'  => 'DELETE',
        'url'     => $url,
        'headers' => ['Authorization: Bearer ' . $token],
        'timeout' => 30,
    ]);
}

/** Map a polled import state to a verdict (port of autopilot_status.py::verdict_from_state).
 *
 * The import is async and queue-based: on a busy tenant a new import can sit at
 * deviceImportStatus "unknown" for minutes before reaching "complete"/"error".
 * "unknown" therefore maps to its own verdict (still pending), NOT "error".
 */
function mdm_verdict_from_state(array $state): array {
    $st   = strtolower((string)($state['deviceImportStatus'] ?? ''));
    $code = (int)($state['deviceErrorCode'] ?? 0);
    $name = strtolower((string)($state['deviceErrorName'] ?? ''));

    if ($st === '__transport__') {
        return ['verdict' => 'offline'];
    }
    if (strpos($name, 'ztddeviceassignedtoothertenant') !== false || strpos($name, 'assigned to other') !== false) {
        return ['verdict' => 'locked_other'];
    }
    if (strpos($name, 'ztddevicealreadyassigned') !== false || strpos($name, 'assigned to my') !== false) {
        return ['verdict' => 'locked_this'];
    }
    if (strpos($name, 'invalidztdhardwarehash') !== false || strpos($name, 'invalid hardware hash') !== false) {
        return ['verdict' => 'hash_invalid'];
    }
    if ($st === 'complete' && $code === 0) {
        return ['verdict' => 'unlocked'];
    }
    if ($st === 'error') {
        return ['verdict' => 'error'];
    }
    return ['verdict' => 'unknown']; // still queued/processing after the poll window
}

/**
 * Delete the registered Windows Autopilot device created by a successful probe
 * import. The import queue entry and the resulting registration are DIFFERENT
 * entities: deleting the import job leaves the registration behind, so an
 * "unlocked" probe must remove both.
 *
 * Prefers the registration id that the import job reports in
 * state.deviceRegistrationId (populated on "complete"); falls back to matching
 * the serial in the registered-devices list ($filter is unsupported on that
 * collection). Best-effort.
 */
function mdm_delete_registration(string $token, string $regId, string $serial): void {
    $url = mdm_graph() . '/beta/deviceManagement/windowsAutopilotDeviceIdentities';
    $headers = ['Authorization: Bearer ' . $token];

    // Graph's registration delete is eventually-consistent: it can return 400
    // while the registration is still materialising after a "complete" import.
    // Retry a few times; 200/204/404 all mean "gone".
    $delete = function (string $id) use ($url, $headers): bool {
        for ($i = 0; $i < 3; $i++) {
            $r = mdm_http(['method' => 'DELETE', 'url' => $url . '/' . rawurlencode($id), 'headers' => $headers, 'timeout' => 30]);
            if ($r['ok'] && in_array($r['status'], [200, 204, 404], true)) {
                return true;
            }
            sleep(3);
        }
        return false;
    };

    if ($regId !== '' && $delete($regId)) {
        return;
    }

    // Fallback (also used when the regId delete failed): match by serial.
    // Re-fetch a few times — the registration can appear in the list seconds
    // after the import completes (eventual consistency). $top=999 is one page.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $r = mdm_http(['method' => 'GET', 'url' => $url . '?$top=999', 'headers' => $headers, 'timeout' => 30]);
        if (!$r['ok'] || $r['status'] !== 200) {
            return;
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d)) {
            return;
        }
        foreach (($d['value'] ?? []) as $dev) {
            if (!is_array($dev) || (string)($dev['serialNumber'] ?? '') !== $serial) {
                continue;
            }
            $id = (string)($dev['id'] ?? '');
            if ($id !== '') {
                $delete($id);
            }
            return;
        }
        sleep(3);
    }
}

/**
 * Pre-probe self-heal: remove every registration whose serialNumber matches
 * $serial from OUR tenant's windowsAutopilotDeviceIdentities list.
 *
 * The tScrub tenant is used EXCLUSIVELY for probing, so any registration here
 * for a device we are about to check can only be a leak left behind by a
 * previous probe (Graph's registration DELETE is eventually-consistent and can
 * leave the device behind for ~30 min). If left in place, the import would
 * match it and return "locked_this" — a false "Locked". Best-effort: a failed
 * purge just means the next probe self-heals again.
 */
function mdm_purge_serial(string $token, string $serial): void {
    $url = mdm_graph() . '/beta/deviceManagement/windowsAutopilotDeviceIdentities';
    $headers = ['Authorization: Bearer ' . $token];

    $delete = function (string $id) use ($url, $headers): void {
        for ($i = 0; $i < 3; $i++) {
            $r = mdm_http(['method' => 'DELETE', 'url' => $url . '/' . rawurlencode($id), 'headers' => $headers, 'timeout' => 30]);
            if ($r['ok'] && in_array($r['status'], [200, 204, 404], true)) {
                return;
            }
            sleep(3);
        }
    };

    $r = mdm_http(['method' => 'GET', 'url' => $url . '?$top=999', 'headers' => $headers, 'timeout' => 30]);
    if (!$r['ok'] || $r['status'] !== 200) {
        return;
    }
    $d = json_decode($r['body'], true);
    if (!is_array($d)) {
        return;
    }
    foreach (($d['value'] ?? []) as $dev) {
        if (!is_array($dev) || (string)($dev['serialNumber'] ?? '') !== $serial) {
            continue;
        }
        $id = (string)($dev['id'] ?? '');
        if ($id !== '') {
            $delete($id);
        }
    }
}

/**
 * Store a hardware hash for a device. Ownership is per (user, serial, uuid):
 * a null $userId stages into the GLOBAL unassigned pool (one row per device,
 * claimed later by a diagnostics report), while a non-null $userId stages an
 * owned row for that account — so a second account can hold its own hash for a
 * device another account already owns. A new capture of the SAME (user, serial,
 * uuid) overwrites the previous row (the unique key is owner_key/serial/uuid,
 * owner_key = COALESCE(user_id, 0)); the same serial with a different uuid is
 * a distinct device. The hash is kept after the check so the device can be
 * re-probed later (e.g. "was it removed from MDM?"). The hash MUST be the
 * oa3tool output — a generated base hash can't match an enrolled device
 * (autopilot-report.md §26).
 */
function mdm_stage_hash(?int $userId, string $serial, string $uuid, string $model, string $hash): void {
    try {
        db()->prepare(
            'INSERT INTO mdm_staged_hash (user_id, serial, uuid, model, hardware_identifier)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               model = VALUES(model),
               hardware_identifier = VALUES(hardware_identifier),
               created_at = UTC_TIMESTAMP()'
        )->execute([$userId, $serial, $uuid, $model, $hash]);
    } catch (Throwable $e) {
        error_log('mdm stage hash error: ' . $e->getMessage());
    }
}

/** Fetch the pending staged hash for a device (serial + uuid), or null if none. */
function mdm_staged_hash(int $userId, string $serial, string $uuid): ?string {
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT hardware_identifier FROM mdm_staged_hash WHERE user_id IN ($ph) AND serial = ? AND uuid = ?");
        $stmt->execute(array_merge($ids, [$serial, $uuid]));
        $row = $stmt->fetch();
        return ($row !== false && !empty($row['hardware_identifier'])) ? (string)$row['hardware_identifier'] : null;
    } catch (Throwable $e) {
        error_log('mdm staged hash get error: ' . $e->getMessage());
        return null;
    }
}

/** Delete a stored hash (manual/admin cleanup — not called automatically). */
function mdm_unstage_hash(int $userId, string $serial, string $uuid): void {
    try {
        db()->prepare('DELETE FROM mdm_staged_hash WHERE user_id = ? AND serial = ? AND uuid = ?')
            ->execute([$userId, $serial, $uuid]);
    } catch (Throwable $e) {
        error_log('mdm unstage hash error: ' . $e->getMessage());
    }
}

/** Owner (user_id) of the diagnostics report for a device, or null if none.
 *  A diagnostics report is the device's ownership claim; a staged hash stays
 *  unassigned until one arrives. Matches the payload's sysserial/systemuuid. */
function mdm_report_owner(string $serial, string $uuid): ?int {
    try {
        $stmt = db()->prepare(
            'SELECT user_id FROM reports
             WHERE report_type = "diagnostics"
               AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.sysserial"))) = LOWER(?)
               AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, "$.systemuuid"))) = LOWER(?)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$serial, $uuid]);
        $row = $stmt->fetch();
        return ($row !== false) ? (int)$row['user_id'] : null;
    } catch (Throwable $e) {
        error_log('mdm report owner error: ' . $e->getMessage());
        return null;
    }
}

/** Claim an unassigned staged hash for a user; true if a hash was claimed. */
function mdm_claim_hash_for_user(int $userId, string $serial, string $uuid): bool {
    try {
        $stmt = db()->prepare('UPDATE mdm_staged_hash SET user_id = ? WHERE serial = ? AND uuid = ? AND user_id IS NULL');
        $stmt->execute([$userId, $serial, $uuid]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('mdm claim hash error: ' . $e->getMessage());
        return false;
    }
}

/** True if a hash is staged for the device but not yet claimed by a user. */
function mdm_has_unassigned_hash(string $serial, string $uuid): bool {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM mdm_staged_hash WHERE serial = ? AND uuid = ? AND user_id IS NULL');
        $stmt->execute([$serial, $uuid]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// ---- MDM check queue (mdm_jobs) -------------------------------------------
// The check runs in the background (mdm-worker.php) so neither the WinPE
// upload nor the web request waits on Microsoft's async import queue. Jobs
// move queued -> checking -> done|failed.

/** Enqueue a fresh check for a device; returns the job id (0 on error).
 *  Idempotent per device: a job is only ever created the FIRST time. If any
 *  job already exists for the device (queued/checking/done/failed) its id is
 *  returned and no new job is created — so re-booting the appliance never
 *  re-triggers a Graph probe. $force (the dashboard Re-check button) bypasses
 *  this and always creates a new job (which also skips the verdict cache). */
function mdm_enqueue_job(int $userId, string $serial, string $uuid, bool $force = false): int {
    try {
        if (!$force) {
            $ids = org_member_ids($userId);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id FROM mdm_jobs WHERE user_id IN ($ph) AND serial = ? AND uuid = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(array_merge($ids, [$serial, $uuid]));
            $existing = $stmt->fetch();
            if ($existing !== false) {
                return (int)$existing['id'];
            }
        }
        db()->prepare('INSERT INTO mdm_jobs (user_id, serial, uuid, status, `force`) VALUES (?, ?, ?, "queued", ?)')
            ->execute([$userId, $serial, $uuid, $force ? 1 : 0]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('mdm enqueue job error: ' . $e->getMessage());
        return 0;
    }
}

/** Atomically claim the oldest queued job, marking it checking; null if none. */
function mdm_claim_job(): ?array {
    try {
        db()->beginTransaction();
        // Claim queued jobs, or "checking" jobs whose worker died mid-probe
        // (a live probe never legitimately runs longer than ~10 minutes).
        $stmt = db()->query('SELECT id FROM mdm_jobs
            WHERE status = "queued"
               OR (status = "checking" AND updated_at < UTC_TIMESTAMP() - INTERVAL 20 MINUTE)
            ORDER BY id ASC LIMIT 1 FOR UPDATE');
        $row = $stmt->fetch();
        if ($row === false) {
            db()->commit();
            return null;
        }
        $id = (int)$row['id'];
        db()->prepare('UPDATE mdm_jobs SET status = "checking", updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
        db()->commit();
        $stmt = db()->prepare('SELECT * FROM mdm_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch();
        return $job !== false ? $job : null;
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $ignored) {}
        error_log('mdm claim job error: ' . $e->getMessage());
        return null;
    }
}

/** Mark a job done with its verdict. */
function mdm_complete_job(int $jobId, string $verdict, string $source, string $detail): void {
    try {
        db()->prepare('UPDATE mdm_jobs SET status = "done", verdict = ?, source = ?, detail = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$verdict, $source, $detail, $jobId]);
    } catch (Throwable $e) {
        error_log('mdm complete job error: ' . $e->getMessage());
    }
}

/** Requeue a job after a transient failure (attempts incremented). */
function mdm_fail_job(int $jobId, string $detail): void {
    try {
        db()->prepare('UPDATE mdm_jobs SET status = "queued", attempts = attempts + 1, detail = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$detail, $jobId]);
    } catch (Throwable $e) {
        error_log('mdm fail job error: ' . $e->getMessage());
    }
}

/** Mark a job permanently failed after too many attempts. */
function mdm_abort_job(int $jobId, string $verdict, string $detail): void {
    try {
        db()->prepare('UPDATE mdm_jobs SET status = "failed", verdict = ?, source = "error", detail = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$verdict, $detail, $jobId]);
    } catch (Throwable $e) {
        error_log('mdm abort job error: ' . $e->getMessage());
    }
}

/** Latest job (any status) for a device, or null. When $uuid is given the
 *  match is serial + uuid (no cross-device ambiguity); without it the latest
 *  job for the serial is returned (legacy serial-only callers). */
function mdm_latest_job(int $userId, string $serial, ?string $uuid = null): ?array {
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($uuid !== null && $uuid !== '') {
            $stmt = db()->prepare("SELECT * FROM mdm_jobs WHERE user_id IN ($ph) AND serial = ? AND uuid = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(array_merge($ids, [$serial, $uuid]));
        } else {
            $stmt = db()->prepare("SELECT * FROM mdm_jobs WHERE user_id IN ($ph) AND serial = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(array_merge($ids, [$serial]));
        }
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    } catch (Throwable $e) {
        error_log('mdm latest job error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Most recent CACHED verdict for a device, or null. Reuses the last completed
 * job's real verdict (unlocked/locked_this/locked_other) if it is still within
 * $ttlSeconds, so a device re-checked within the window doesn't re-import its
 * hash (fewer register→unregister cycles = less abuse-flag exposure).
 * unknown/ms_error/offline/hash_invalid are never cached — a failed or
 * inconclusive check must be retried, and a bad hash must be re-checked after
 * the operator re-captures it. The dashboard Re-check button forces a bypass.
 */
function mdm_cached_verdict(int $userId, string $serial, string $uuid, int $ttlSeconds = 86400): ?array {
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT verdict, updated_at FROM mdm_jobs
             WHERE user_id IN ($ph) AND serial = ? AND uuid = ?
               AND status = 'done'
               AND verdict IN ('unlocked','locked_this','locked_other')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(array_merge($ids, [$serial, $uuid]));
        $j = $stmt->fetch();
        if ($j === false) {
            return null;
        }
        $updated = strtotime((string)($j['updated_at'] ?? ''));
        if ($updated === false || (time() - $updated) > $ttlSeconds) {
            return null;
        }
        return [
            'verdict'     => (string)$j['verdict'],
            'source'      => 'cache',
            'cached_at'   => (string)($j['updated_at'] ?? ''),
            'age_seconds' => time() - $updated,
        ];
    } catch (Throwable $e) {
        error_log('mdm cached verdict error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Seconds until the next live Graph probe is allowed (0 = allowed now). Live
 * probes are import→delete cycles; spacing them out avoids the rapid-fire
 * register/unregister pattern Microsoft's abuse heuristics watch for. Reads
 * the last live probe's timestamp from mdm_log (source = 'live').
 */
function mdm_probe_cooldown_remaining(int $cooldownSeconds = 30): int {
    try {
        $last = db()->query("SELECT MAX(created_at) FROM mdm_log WHERE source = 'live'")->fetchColumn();
        if ($last === false || $last === null || $last === '') {
            return 0;
        }
        $lastTs = strtotime((string)$last);
        if ($lastTs === false) {
            return 0;
        }
        $elapsed = time() - $lastTs;
        return $elapsed < $cooldownSeconds ? ($cooldownSeconds - $elapsed) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Dashboard device list: staged serials + their latest job state. */
function mdm_devices(int $userId): array {
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT serial, uuid, model, created_at AS captured_at FROM mdm_staged_hash WHERE user_id IN ($ph) ORDER BY created_at DESC");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $j = mdm_latest_job($userId, (string)$h['serial'], (string)$h['uuid']);
            // A staged hash with no job yet = info acquired but the check was
            // never initiated — surfaced as 'unchecked' (Re-check runs it).
            $status  = $j !== null ? (string)($j['status'] ?? '') : 'unchecked';
            $verdict = $j !== null ? (string)($j['verdict'] ?? '') : '';
            $out[] = [
                'serial' => (string)$h['serial'],
                'uuid' => (string)$h['uuid'],
                'model' => (string)$h['model'],
                'captured_at' => (string)$h['captured_at'],
                'status' => $status,
                'verdict' => $verdict,
                'label' => mdm_status_label($status, $verdict),
                'started_at' => $j['created_at'] ?? null,
                'last_checked_at' => $j['updated_at'] ?? null,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('mdm devices error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Attach each device's latest MDM (Autopilot) state from the WinPE registry to
 * a Devices-tab list. Matches by system serial first, then system UUID (both
 * lowercased). Adds mdm_status / mdm_verdict / mdm_last_checked_at when a
 * captured hash exists; devices never captured keep their report's
 * point-in-time 'mdm' verdict and gain no mdm_status. Never throws.
 */
function mdm_fold_devices(int $userId, array $devices): array {
    try {
        $bySerial = [];
        $byUuid = [];
        foreach (mdm_devices($userId) as $m) {
            $s = strtolower(trim((string)$m['serial']));
            $u = strtolower(trim((string)$m['uuid']));
            if ($s !== '') $bySerial[$s] = $m;
            if ($u !== '') $byUuid[$u] = $m;
        }
        foreach ($devices as $k => $dv) {
            $s = strtolower(trim((string)($dv['sysserial'] ?? '')));
            $u = strtolower(trim((string)($dv['systemuuid'] ?? '')));
            $m = null;
            if ($s !== '' && isset($bySerial[$s])) $m = $bySerial[$s];
            elseif ($u !== '' && isset($byUuid[$u])) $m = $byUuid[$u];
            if ($m !== null) {
                $devices[$k]['mdm_status'] = (string)$m['status'];
                $devices[$k]['mdm_verdict'] = (string)$m['verdict'];
                $devices[$k]['mdm_label'] = mdm_status_label((string)$m['status'], (string)$m['verdict']);
                $devices[$k]['mdm_last_checked_at'] = $m['last_checked_at'] ?? null;
            }
        }
    } catch (Throwable $e) {
        error_log('mdm fold devices error: ' . $e->getMessage());
    }
    return $devices;
}

/**
 * Probe Microsoft Graph with a WinPE-captured OAv3 hash against each probe
 * tenant in turn: token → health gate → purge leaked registrations → import →
 * poll → verdict → delete. Returns ['verdict' => ..., 'source' => 'live'].
 * Never throws: failures degrade to 'offline', and when every configured
 * tenant's Autopilot service is degraded it returns 'ms_error'. A generated
 * base hash is deliberately NOT used — it cannot match an externally-enrolled
 * device (autopilot-report.md §26).
 */
function mdm_probe(string $serial, string $hash, int $pollTimeout = 300): array {
    if ($hash === '') {
        return ['verdict' => 'hash_invalid', 'source' => 'error'];
    }
    $pool = mdm_tenant_pool();
    if (empty($pool)) {
        error_log('mdm: autopilot not configured');
        return ['verdict' => 'offline', 'source' => 'error'];
    }

    // Try each probe tenant in order. The import verdicts are tenant-independent
    // (ZtdDeviceAssignedToOtherTenant / complete-code-0), so the first tenant
    // whose Autopilot service is up answers the question. A tenant whose service
    // is degraded (syncStatus=failed) is skipped; if ALL are degraded we report
    // ms_error so the dashboard surfaces the Microsoft-side outage honestly.
    $sawMsError = false;
    foreach ($pool as $tenant) {
        $token = mdm_token((string)$tenant['tenant_id'], (string)$tenant['client_id'], (string)$tenant['client_secret']);
        if ($token === null) {
            continue; // offline tenant (bad creds/transport) — try the next
        }
        if (!mdm_autopilot_reachable($token, $tenant)) {
            $sawMsError = true; // Microsoft's Autopilot service is down for this tenant
            continue;
        }

        // Self-heal BEFORE probing: a registration for this serial in a probe
        // tenant can only be a leak from a previous probe (probe tenants are
        // probe-only), and leaving it would make the import return a false
        // "locked_this".
        mdm_purge_serial($token, $serial);

        $import = mdm_import($token, $serial, $hash);
        if ($import === null) {
            continue;
        }
        $identityId = (string)($import['id'] ?? '');
        if ($identityId === '') {
            continue;
        }

        $state = mdm_poll($token, $identityId, $pollTimeout, 5);
        mdm_delete($token, $identityId); // always clean up our own probe (import queue entry)

        $out = mdm_verdict_from_state($state);
        // A successful import CREATES a real device in the tenant's registered
        // list, a timed-out (unknown) import may still complete later, and
        // locked_this means the import matched a registration in the SAME
        // (probe-only) tenant (a leak) — remove the registration in all three
        // cases so a probe never leaves one behind. locked_other/hash_invalid/
        // error create no registration here.
        if (in_array($out['verdict'], ['unlocked', 'unknown', 'locked_this'], true)) {
            mdm_delete_registration($token, (string)($state['deviceRegistrationId'] ?? ''), $serial);
        }
        $out['source'] = 'live';
        return $out;
    }

    if ($sawMsError) {
        return [
            'verdict' => 'ms_error',
            'source'  => 'error',
            'detail'  => 'Microsoft Autopilot service unavailable (syncStatus failed) for all probe tenants',
        ];
    }
    return ['verdict' => 'offline', 'source' => 'error'];
}

// ---- audit log (mdm_log) --------------------------------------------------

/** Lazily create the mdm_log table (idempotent — mirrors schema.sql). */
function mdm_ensure_schema(): void {
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS mdm_log (
               id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id    BIGINT UNSIGNED NOT NULL,
               serial     VARCHAR(255)    NOT NULL DEFAULT "",
               uuid       VARCHAR(64)     NOT NULL DEFAULT "",
               verdict    VARCHAR(20)     NOT NULL DEFAULT "",
               source     VARCHAR(10)     NOT NULL DEFAULT "live",
               ip         VARCHAR(45)     NOT NULL DEFAULT "",
               created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_mdm_log_user (user_id, created_at),
               CONSTRAINT fk_mdm_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS mdm_staged_hash (
               id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id             BIGINT UNSIGNED NULL,
               owner_key           BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(user_id, 0)) STORED,
               serial              VARCHAR(255)    NOT NULL DEFAULT "",
               uuid                VARCHAR(64)     NOT NULL DEFAULT "",
               model               VARCHAR(255)    NOT NULL DEFAULT "",
               hardware_identifier TEXT            NOT NULL,
               created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_mdm_staged_device (owner_key, serial, uuid),
               KEY idx_mdm_staged_user (user_id),
               CONSTRAINT fk_mdm_staged_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // Existing-table migration: hashes are owned per (user, serial, uuid),
        // with the unassigned WinPE pool (user_id NULL) sharing one row per
        // device via owner_key = COALESCE(user_id, 0). MySQL 8 has no
        // ALTER ... IF EXISTS, so probe first. The unique key must move from any
        // legacy per-user (user_id, …) or global (serial, uuid) form to
        // (owner_key, serial, uuid) — the global form is what caused the
        // cross-account "no hash" bug (a second account's stage folded into the
        // first account's row and clobbered its hash).
        $hashUserIdx = (int)db()->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_staged_hash'
               AND INDEX_NAME = 'idx_mdm_staged_user'"
        )->fetchColumn();
        if ($hashUserIdx === 0) {
            db()->exec('ALTER TABLE mdm_staged_hash ADD KEY idx_mdm_staged_user (user_id)');
        }
        $hashNullable = db()->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_staged_hash' AND COLUMN_NAME = 'user_id'"
        )->fetchColumn();
        if ($hashNullable !== 'YES') {
            db()->exec('ALTER TABLE mdm_staged_hash MODIFY user_id BIGINT UNSIGNED NULL');
        }
        $hasOwnerKey = (int)db()->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_staged_hash' AND COLUMN_NAME = 'owner_key'"
        )->fetchColumn();
        if ($hasOwnerKey === 0) {
            db()->exec('ALTER TABLE mdm_staged_hash ADD COLUMN owner_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(user_id, 0)) STORED');
        }
        // Drop any uq_mdm_staged_device that is not the per-user key
        // (legacy (user_id, serial, uuid) or global (serial, uuid)).
        $uqCols = (string)db()->query(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_staged_hash'
               AND INDEX_NAME = 'uq_mdm_staged_device'"
        )->fetchColumn();
        if ($uqCols !== '' && $uqCols !== 'owner_key,serial,uuid') {
            db()->exec('ALTER TABLE mdm_staged_hash DROP INDEX uq_mdm_staged_device');
        }
        $hashUq = (int)db()->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_staged_hash'
               AND INDEX_NAME = 'uq_mdm_staged_device' AND COLUMN_NAME = 'owner_key'"
        )->fetchColumn();
        if ($hashUq === 0) {
            db()->exec('ALTER TABLE mdm_staged_hash ADD UNIQUE KEY uq_mdm_staged_device (owner_key, serial, uuid)');
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS mdm_jobs (
               id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id    BIGINT UNSIGNED NOT NULL,
               serial     VARCHAR(255)    NOT NULL DEFAULT "",
               uuid       VARCHAR(64)     NOT NULL DEFAULT "",
               status     VARCHAR(16)     NOT NULL DEFAULT "queued",
               verdict    VARCHAR(20)     NOT NULL DEFAULT "",
               source     VARCHAR(10)     NOT NULL DEFAULT "",
               detail     TEXT            NULL,
               attempts   INT UNSIGNED    NOT NULL DEFAULT 0,
               created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_mdm_jobs_status (status, created_at),
               KEY idx_mdm_jobs_device (user_id, serial, uuid, id),
               CONSTRAINT fk_mdm_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // mdm_jobs.force — a forced check (dashboard Re-check) bypasses the
        // verdict cache. Added idempotently for pre-existing tables (MySQL 8
        // has no ADD COLUMN IF NOT EXISTS).
        $hasForce = db()->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_jobs' AND COLUMN_NAME = 'force'"
        )->fetchColumn();
        if ((int)$hasForce === 0) {
            db()->exec('ALTER TABLE mdm_jobs ADD COLUMN `force` TINYINT(1) NOT NULL DEFAULT 0');
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS mdm_ingest_log (
               id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id    BIGINT UNSIGNED NULL,
               serial     VARCHAR(255)    NOT NULL DEFAULT "",
               uuid       VARCHAR(64)     NOT NULL DEFAULT "",
               hash_len   INT UNSIGNED    NOT NULL DEFAULT 0,
               ip         VARCHAR(45)     NOT NULL DEFAULT "",
               created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_mdm_ingest_user (user_id, created_at),
               CONSTRAINT fk_mdm_ingest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        // Ingest is now tenant-agnostic (shared ingest key, not a per-user
        // token) — make the audit column nullable for pre-existing tables.
        $ingestNullable = db()->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_ingest_log' AND COLUMN_NAME = 'user_id'"
        )->fetchColumn();
        if ($ingestNullable !== 'YES') {
            db()->exec('ALTER TABLE mdm_ingest_log MODIFY user_id BIGINT UNSIGNED NULL');
        }
    } catch (Throwable $e) {
        error_log('mdm schema ensure error: ' . $e->getMessage());
    }
}

/** Append one probe to the admin-visible MDM audit log (best-effort). */
function mdm_log_probe(int $userId, string $serial, string $uuid, string $verdict, string $source, string $ip): void {
    try {
        db()->prepare('INSERT INTO mdm_log (user_id, serial, uuid, verdict, source, ip) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $serial, $uuid, $verdict, $source, $ip]);
    } catch (Throwable $e) {
        error_log('mdm log probe error: ' . $e->getMessage());
    }
}

/** Record every WinPE hash-upload attempt (before validation) so a failed
 *  upload is diagnosable server-side even when the device's screen is gone. */
function mdm_log_ingest(?int $userId, string $serial, string $uuid, int $hashLen, string $ip): void {
    try {
        db()->prepare('INSERT INTO mdm_ingest_log (user_id, serial, uuid, hash_len, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $serial, $uuid, $hashLen, $ip]);
    } catch (Throwable $e) {
        error_log('mdm ingest log error: ' . $e->getMessage());
    }
}
