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
 */
function mdm_status_label(string $status, string $verdict): string {
    $st = strtolower($status);
    $v  = strtolower($verdict);

    if ($st === 'checking') return 'Checking…';
    if ($st === 'queued')   return 'Queued';
    if ($st === 'failed')   return 'Failed';
    if ($st === 'na')       return 'No hash';

    switch ($v) {
        case 'unlocked':     return 'Unlocked';
        case 'locked_this':  return 'Locked (this)';
        case 'locked_other': return 'Locked (other)';
        case 'hash_invalid': return 'Invalid hash';
        case 'ms_error':     return 'MS error';
        case 'offline':      return 'Offline';
        case 'error':        return 'Error';
        case 'unknown':      return 'Pending';
        case 'skipped':      return 'Skipped';
        case 'na':           return 'No hash';
    }
    return 'Unknown';
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
 * Store a WinPE-captured authoritative hash, keyed by serial. One hash per
 * (user, serial, uuid); a new capture of the SAME device (serial + uuid)
 * overwrites the previous one, while the same serial with a different uuid is
 * a distinct device. The hash is kept after the check so the device can be
 * re-probed later (e.g. "was it removed from MDM?"). The hash MUST be the
 * oa3tool output — a generated base hash can't match an enrolled device
 * (autopilot-report.md §26).
 */
function mdm_stage_hash(int $userId, string $serial, string $uuid, string $model, string $hash): void {
    try {
        db()->prepare(
            'INSERT INTO mdm_staged_hash (user_id, serial, uuid, model, hardware_identifier)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE model = VALUES(model), hardware_identifier = VALUES(hardware_identifier), created_at = UTC_TIMESTAMP()'
        )->execute([$userId, $serial, $uuid, $model, $hash]);
    } catch (Throwable $e) {
        error_log('mdm stage hash error: ' . $e->getMessage());
    }
}

/** Fetch the pending staged hash for a device (serial + uuid), or null if none. */
function mdm_staged_hash(int $userId, string $serial, string $uuid): ?string {
    try {
        $stmt = db()->prepare('SELECT hardware_identifier FROM mdm_staged_hash WHERE user_id = ? AND serial = ? AND uuid = ?');
        $stmt->execute([$userId, $serial, $uuid]);
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

// ---- MDM check queue (mdm_jobs) -------------------------------------------
// The check runs in the background (mdm-worker.php) so neither the WinPE
// upload nor the web request waits on Microsoft's async import queue. Jobs
// move queued -> checking -> done|failed.

/** Enqueue a fresh check for a device; returns the job id (0 on error).
 *  Skips a duplicate while one is already queued/checking for the same device,
 *  UNLESS $force is set (the dashboard Re-check button) — a forced check always
 *  creates a new job and bypasses the verdict cache in the worker. */
function mdm_enqueue_job(int $userId, string $serial, string $uuid, bool $force = false): int {
    try {
        if (!$force) {
            $stmt = db()->prepare('SELECT id FROM mdm_jobs WHERE user_id = ? AND serial = ? AND uuid = ? AND status IN ("queued","checking") ORDER BY id DESC LIMIT 1');
            $stmt->execute([$userId, $serial, $uuid]);
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
        if ($uuid !== null && $uuid !== '') {
            $stmt = db()->prepare('SELECT * FROM mdm_jobs WHERE user_id = ? AND serial = ? AND uuid = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$userId, $serial, $uuid]);
        } else {
            $stmt = db()->prepare('SELECT * FROM mdm_jobs WHERE user_id = ? AND serial = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$userId, $serial]);
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
        $stmt = db()->prepare(
            "SELECT verdict, updated_at FROM mdm_jobs
             WHERE user_id = ? AND serial = ? AND uuid = ?
               AND status = 'done'
               AND verdict IN ('unlocked','locked_this','locked_other')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $serial, $uuid]);
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
        $stmt = db()->prepare('SELECT serial, uuid, model, created_at AS captured_at FROM mdm_staged_hash WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $j = mdm_latest_job($userId, (string)$h['serial'], (string)$h['uuid']);
            $out[] = [
                'serial' => (string)$h['serial'],
                'uuid' => (string)$h['uuid'],
                'model' => (string)$h['model'],
                'captured_at' => (string)$h['captured_at'],
                'status' => $j['status'] ?? 'na',
                'verdict' => $j['verdict'] ?? '',
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
               user_id             BIGINT UNSIGNED NOT NULL,
               serial              VARCHAR(255)    NOT NULL DEFAULT "",
               uuid                VARCHAR(64)     NOT NULL DEFAULT "",
               model               VARCHAR(255)    NOT NULL DEFAULT "",
               hardware_identifier TEXT            NOT NULL,
               created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_mdm_staged_device (user_id, serial, uuid),
               CONSTRAINT fk_mdm_staged_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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
               user_id    BIGINT UNSIGNED NOT NULL,
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
function mdm_log_ingest(int $userId, string $serial, string $uuid, int $hashLen, string $ip): void {
    try {
        db()->prepare('INSERT INTO mdm_ingest_log (user_id, serial, uuid, hash_len, ip) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $serial, $uuid, $hashLen, $ip]);
    } catch (Throwable $e) {
        error_log('mdm ingest log error: ' . $e->getMessage());
    }
}
