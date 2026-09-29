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
 * Returns [ok => bool, status => int, body => string]. Transient transport
 * failures (DNS/connect/SSL/timeout) and Graph 5xx responses are retried up to
 * twice with a short backoff. POSTs are NOT re-issued on 5xx (a retried import
 * could create a duplicate queue entry); 2xx/4xx are returned immediately.
 * $GLOBALS['mdm_http_override'] (a callable) replaces the transport — used by
 * the offline test harness.
 */
function mdm_http(array $req): array {
    $method = strtoupper((string)($req['method'] ?? 'GET'));
    $last = ['ok' => false, 'status' => 0, 'body' => ''];
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $last = mdm_http_once($req);
        $transportFail = !$last['ok'] || $last['status'] === 0;
        $serverFail   = $last['ok'] && $last['status'] >= 500 && $method !== 'POST';
        if (!$transportFail && !$serverFail) {
            return $last;
        }
        if ($attempt < 2) {
            usleep(400000 * ($attempt + 1)); // 0.4 s, 0.8 s
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
        return ['ok' => false, 'status' => 0, 'body' => ''];
    }
    $ch = curl_init((string)($req['url'] ?? ''));
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => ''];
    }
    $method = strtoupper((string)($req['method'] ?? 'GET'));
    $opts = [
        CURLOPT_HTTPHEADER       => (array)($req['headers'] ?? []),
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_CONNECTTIMEOUT   => 10,
        CURLOPT_TIMEOUT          => (int)($req['timeout'] ?? 60),
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
        return ['ok' => false, 'status' => 0, 'body' => ''];
    }
    return ['ok' => true, 'status' => $status, 'body' => (string)$body];
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
    while (time() < $deadline) {
        $r = mdm_http([
            'method'  => 'GET',
            'url'     => $url,
            'headers' => ['Authorization: Bearer ' . $token],
            'timeout' => 30,
        ]);
        if ($r['ok'] && $r['status'] === 200) {
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

/** Record the BIOS-lock state the appliance detected (pre-wipe triage flag for
 *  the Devices tab). Updates the staged row; a no-op when the device has no
 *  staged hash yet (the erasure report still carries it post-wipe), or when the
 *  caller sent no signal (an older appliance that predates the bios_lock field
 *  must not wipe a value stored by a newer one). */
function mdm_set_device_bios(int $userId, string $serial, string $uuid, string $biosLock, string $biosLockMethod): void {
    if ($biosLock === '') {
        return;
    }
    try {
        db()->prepare('UPDATE mdm_staged_hash SET bios_lock = ?, bios_lock_method = ? WHERE user_id = ? AND serial = ? AND uuid = ?')
            ->execute([$biosLock, $biosLockMethod, $userId, $serial, $uuid]);
    } catch (Throwable $e) {
        error_log('mdm set device bios error: ' . $e->getMessage());
    }
}

// ---- MDM check queue (mdm_jobs) -------------------------------------------
// The check runs in the background (mdm-worker.php) so neither the WinPE
// upload nor the web request waits on Microsoft's async import queue. Jobs
// move queued -> checking -> done|failed.

/** Enqueue a fresh check for a device; returns the job id (0 on error).
 *  Skips a duplicate while one is already queued/checking for the same device. */
function mdm_enqueue_job(int $userId, string $serial, string $uuid): int {
    try {
        $stmt = db()->prepare('SELECT id FROM mdm_jobs WHERE user_id = ? AND serial = ? AND uuid = ? AND status IN ("queued","checking") ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $serial, $uuid]);
        $existing = $stmt->fetch();
        if ($existing !== false) {
            return (int)$existing['id'];
        }
        db()->prepare('INSERT INTO mdm_jobs (user_id, serial, uuid, status) VALUES (?, ?, ?, "queued")')
            ->execute([$userId, $serial, $uuid]);
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
        $stmt = db()->query('SELECT id FROM mdm_jobs WHERE status = "queued" ORDER BY id ASC LIMIT 1 FOR UPDATE');
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

/** Dashboard device list: staged serials + their latest job state. */
function mdm_devices(int $userId): array {
    try {
        $stmt = db()->prepare('SELECT serial, uuid, model, bios_lock, bios_lock_method, created_at AS captured_at FROM mdm_staged_hash WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $j = mdm_latest_job($userId, (string)$h['serial'], (string)$h['uuid']);
            $out[] = [
                'serial' => (string)$h['serial'],
                'uuid' => (string)$h['uuid'],
                'model' => (string)$h['model'],
                'bios_lock' => (string)($h['bios_lock'] ?? ''),
                'bios_lock_method' => (string)($h['bios_lock_method'] ?? ''),
                'captured_at' => (string)$h['captured_at'],
                'status' => $j['status'] ?? 'na',
                'verdict' => $j['verdict'] ?? '',
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
 * Probe Microsoft Graph with a WinPE-captured OAv3 hash: token → purge leaked
 * registrations → import → poll → verdict → delete. Returns
 * ['verdict' => ..., 'source' => 'live']. Never throws: failures degrade to
 * 'offline'. A generated base hash is deliberately NOT used — it cannot match
 * an externally-enrolled device (autopilot-report.md §26).
 */
function mdm_probe(string $serial, string $hash, int $pollTimeout = 300): array {
    if (!mdm_configured()) {
        error_log('mdm: autopilot not configured');
        return ['verdict' => 'offline', 'source' => 'error'];
    }
    if ($hash === '') {
        return ['verdict' => 'hash_invalid', 'source' => 'error'];
    }
    $s = mdm_settings();

    $token = mdm_token((string)$s['tenant_id'], (string)$s['client_id'], (string)$s['client_secret']);
    if ($token === null) {
        return ['verdict' => 'offline', 'source' => 'error'];
    }

    // Self-heal BEFORE probing: a registration for this serial in OUR tenant
    // can only be a leak from a previous probe (the tScrub tenant is probe-only),
    // and leaving it would make the import return a false "locked_this".
    mdm_purge_serial($token, $serial);

    $import = mdm_import($token, $serial, $hash);
    if ($import === null) {
        return ['verdict' => 'offline', 'source' => 'error'];
    }
    $identityId = (string)($import['id'] ?? '');
    if ($identityId === '') {
        return ['verdict' => 'offline', 'source' => 'error'];
    }

    $state = mdm_poll($token, $identityId, $pollTimeout, 5);
    mdm_delete($token, $identityId); // always clean up our own probe (import queue entry)

    $out = mdm_verdict_from_state($state);
    // A successful import CREATES a real device in the tenant's registered list,
    // a timed-out (unknown) import may still complete later, and locked_this
    // means the import matched a registration in OUR OWN tenant (a leak) —
    // remove the registration in all three cases so a probe never leaves one
    // behind. locked_other/hash_invalid/error create no registration here.
    if (in_array($out['verdict'], ['unlocked', 'unknown', 'locked_this'], true)) {
        mdm_delete_registration($token, (string)($state['deviceRegistrationId'] ?? ''), $serial);
    }
    $out['source'] = 'live';
    return $out;
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
        // Columns added after v1.6.0 — idempotently ALTER any pre-existing
        // table (CREATE TABLE IF NOT EXISTS will not add columns).
        try {
            $cols = db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mdm_staged_hash'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('bios_lock', $cols, true)) {
                db()->exec("ALTER TABLE mdm_staged_hash ADD COLUMN bios_lock VARCHAR(20) NOT NULL DEFAULT '' AFTER model");
            }
            if (!in_array('bios_lock_method', $cols, true)) {
                db()->exec("ALTER TABLE mdm_staged_hash ADD COLUMN bios_lock_method VARCHAR(255) NOT NULL DEFAULT '' AFTER bios_lock");
            }
        } catch (Throwable $e) {
            error_log('mdm schema alter error: ' . $e->getMessage());
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
