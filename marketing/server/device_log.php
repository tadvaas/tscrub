<?php
declare(strict_types=1);

/**
 * Per-device event log — everything recorded about one machine, newest first.
 *
 * The Devices tab's "Reports" expansion already lists report SUBMISSIONS
 * (diagnostics / erasure / completed MDM checks), because load_devices() is the
 * code that knows how to map a report onto a machine. This file covers the other
 * half of the story: what was ASKED of the machine and what came back — remote
 * commands and their results, MDM checks and hash captures, appliance
 * registrations, and the drives that ended up in a certificate. The dashboard
 * merges the two into one timeline, which keeps the report→device mapping in the
 * one place that already gets it right and leaves this a plain serial lookup.
 *
 * Timestamps go out through ts_local(), the same helper load_devices() uses, so
 * a merged list sorts correctly instead of interleaving two time zones.
 *
 * Read-only, org-scoped through org_member_ids() like every other device read.
 * Never returns a password: a BIOS clear reports its outcome, never its input.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/http.php';       // ts_local()
require_once __DIR__ . '/reports_lib.php';

/** Outcome wording + severity for a finished remote command. */
function device_event_command_outcome(string $command, string $status, string $verdict): array {
    if ($command === 'bios_unlock') {
        if ($status === 'done') return ['Cleared', 'ok'];
        if ($verdict === 'wrong_password') return ['Wrong password', 'fail'];
        if ($verdict === 'no_clear_path') return ['No clear path from Linux on this firmware', 'warn'];
        if ($verdict === 'not_supported') return ['Firmware cannot set or clear passwords', 'warn'];
        if ($status === 'unsupported') return ['Not supported by this firmware', 'warn'];
        return ['Failed', 'fail'];
    }
    return $status === 'done' ? ['Done', 'ok'] : ['Failed', 'fail'];
}

/** Verdict wording + severity for an Autopilot (MDM) check. */
function device_event_mdm_outcome(string $verdict, string $status): array {
    if ($status === 'failed' || $status === 'aborted') return ['Check failed', 'fail'];
    switch (strtolower($verdict)) {
        case 'locked_other': return ['Locked — enrolled in another tenant', 'warn'];
        case 'locked_this':  return ['Locked — enrolled in this tenant', 'warn'];
        case 'unlocked':     return ['Unlocked — not enrolled in Autopilot', 'ok'];
        case 'hash_invalid': return ['Hardware hash rejected by Microsoft', 'warn'];
        case 'ms_error':
        case 'error':        return ['Microsoft check failed', 'fail'];
        case 'offline':      return ['Could not reach Microsoft', 'info'];
        case 'unknown':      return ['Still processing at Microsoft', 'info'];
        default:             return [$verdict !== '' ? $verdict : 'No verdict', 'info'];
    }
}

/**
 * Every event recorded for a device, newest first.
 *
 * @return list<array{at:string,kind:string,label:string,state:string,detail:string,ref:string,cocid:string}>
 *         `at` is a localised 'Y-m-d H:i:s' (comparable with the report rows the
 *         dashboard already has), `kind` groups the row (wipe|bios_unlock|
 *         shutdown|reboot|mdm|boot|drive|certificate), `state` is
 *         ok|warn|fail|pending|info, and `cocid` is the Chain of Custody the
 *         event belongs to — EMPTY for the events that are not part of one
 *         (a staged remote command, an Autopilot probe, a registration): those
 *         have no CoC to record, so none is invented for them.
 */
function device_events(int $userId, string $serial, string $uuid = '', int $limit = 120): array {
    $serial = trim($serial);
    $uuid   = trim($uuid);
    if ($serial === '' && $uuid === '') {
        return [];
    }
    $limit = max(1, min(400, $limit));
    $ids = org_member_ids($userId);
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    // One device, however it identifies itself: the serial is authoritative, and
    // the uuid catches rows an appliance sent with an empty serial.
    $match = '';
    $margs = [];
    if ($serial !== '') { $match .= 'serial = ?'; $margs[] = $serial; }
    if ($uuid !== '')   { $match .= ($match !== '' ? ' OR ' : '') . 'LOWER(uuid) = LOWER(?)'; $margs[] = $uuid; }

    $events = [];
    // $cocid is the Chain of Custody this event belongs to, when it has one:
    // the reports and the certificates carry a CoC, the command queue and the
    // MDM tables do not. An em dash in the UI is honest; a made-up CoC is not.
    $add = static function (string $ts, string $kind, string $label, string $state, string $detail = '', string $ref = '', string $cocid = '') use (&$events): void {
        $ts = trim($ts);
        if ($ts === '' || str_starts_with($ts, '0000-00-00')) {
            return;
        }
        $events[] = [
            'at'     => ts_local($ts),
            'kind'   => $kind,
            'label'  => $label,
            'state'  => $state,
            'detail' => $detail,
            'ref'    => $ref,
            'cocid'  => $cocid,
        ];
    };
    $noun = static fn(string $command): string => [
        'wipe'        => 'Erase',
        'bios_unlock' => 'BIOS clear',
        'shutdown'    => 'Shut down',
        'reboot'      => 'Restart',
    ][$command] ?? $command;

    // --- remote commands: staged by the dashboard, run by the appliance -------
    if (db_table_exists('device_commands')) {
        try {
            $stmt = db()->prepare("SELECT id, command, status, result, detail, verdict, tool_version, cocid, created_at, dispatched_at, resolved_at
                                     FROM device_commands WHERE user_id IN ($ph) AND ($match) ORDER BY id DESC LIMIT 60");
            $stmt->execute(array_merge($ids, $margs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cmd = (string)$c['command'];
                // Labelled, not a bare "#229": the Log shows ids from several
                // tables at once, and device_commands.id, mdm_jobs.id and a
                // certificate id are independent sequences that can collide on
                // the same number without being the same record.
                $id  = 'command ' . (int)$c['id'];
                $add((string)$c['created_at'], $cmd, $noun($cmd) . ' requested', 'pending', '', $id);
                if (!empty($c['dispatched_at'])) {
                    $add((string)$c['dispatched_at'], $cmd, $noun($cmd) . ' picked up by the appliance', 'pending', '', $id);
                }
                if (empty($c['resolved_at'])) {
                    continue;
                }
                // A command that never ran is still news: say which way it went.
                $st = (string)$c['status'];
                if (in_array($st, ['cancelled', 'superseded', 'expired'], true)) {
                    $label = ['cancelled' => 'withdrawn', 'superseded' => 'replaced by a newer command', 'expired' => 'expired before the appliance picked it up'][$st];
                    $add((string)$c['resolved_at'], $cmd, $noun($cmd) . ' ' . $label, $st === 'expired' ? 'warn' : 'info', '', $id);
                    continue;
                }
                [$outcome, $state] = device_event_command_outcome($cmd, $st, (string)$c['verdict']);
                $detail = trim((string)$c['detail']);
                // The operator-facing instruction for the one verdict that needs
                // one: the appliance's own detail explains what happened but not
                // what to do about it. (This is the guidance the ops card used to
                // carry, kept where a clear's history is actually read.)
                if ((string)$c['verdict'] === 'no_clear_path') {
                    $detail = trim($detail . ($detail !== '' ? ' — ' : '')
                        . 'HP setup passwords can be cleared from Linux by an appliance running v1.11.46 or newer; on an older image, clear it pre-boot (BIOS setup, vendor SMC, or SPI reflash)');
                }
                if ((string)$c['tool_version'] !== '') {
                    $detail = trim($detail . ($detail !== '' ? ' · ' : '') . 'appliance ' . (string)$c['tool_version']);
                }
                // The outcome carries the CoC the appliance stamped when it
                // EXECUTED the command. The 'requested' and 'picked up' events
                // above deliberately do not: at those moments no CoC had been
                // recorded for it yet.
                $add((string)$c['resolved_at'], $cmd, $noun($cmd) . ': ' . $outcome, $state, $detail, $id, (string)($c['cocid'] ?? ''));
            }
        } catch (Throwable $e) {
            error_log('device log commands: ' . $e->getMessage());
        }
    }

    // --- Autopilot / MDM -----------------------------------------------------
    if (db_table_exists('mdm_jobs')) {
        try {
            $stmt = db()->prepare("SELECT id, status, verdict, source, detail, created_at, updated_at
                                     FROM mdm_jobs WHERE user_id IN ($ph) AND ($match) ORDER BY id DESC LIMIT 40");
            $stmt->execute(array_merge($ids, $margs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $j) {
                $st  = (string)$j['status'];
                $ref = 'job ' . (int)$j['id'];
                $add((string)$j['created_at'], 'mdm', 'Autopilot check requested', 'pending', '', $ref);
                if (!in_array($st, ['done', 'failed', 'aborted'], true)) {
                    continue;
                }
                [$outcome, $state] = device_event_mdm_outcome((string)$j['verdict'], $st);
                $detail = trim((string)$j['detail']);
                if ((string)$j['source'] !== '') {
                    $detail = trim($detail . ($detail !== '' ? ' · ' : '') . 'via ' . (string)$j['source']);
                }
                $ts = (string)$j['updated_at'];
                if ($ts === '' || str_starts_with($ts, '0000-00-00')) $ts = (string)$j['created_at'];
                $add($ts, 'mdm', 'Autopilot check: ' . $outcome, $state, $detail, $ref);
            }
        } catch (Throwable $e) {
            error_log('device log mdm jobs: ' . $e->getMessage());
        }
    }
    if (db_table_exists('mdm_ingest_log')) {
        try {
            $stmt = db()->prepare("SELECT hash_len, ip, created_at FROM mdm_ingest_log WHERE user_id IN ($ph) AND ($match) ORDER BY id DESC LIMIT 20");
            $stmt->execute(array_merge($ids, $margs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
                $detail = (int)$g['hash_len'] . '-char hash';
                if ((string)$g['ip'] !== '') $detail .= ' from ' . (string)$g['ip'];
                $add((string)$g['created_at'], 'mdm', 'Autopilot hash captured', 'info', $detail, '');
            }
        } catch (Throwable $e) {
            error_log('device log mdm ingest: ' . $e->getMessage());
        }
    }
    if (db_table_exists('mdm_log')) {
        try {
            $stmt = db()->prepare("SELECT verdict, source, ip, created_at FROM mdm_log WHERE user_id IN ($ph) AND ($match) ORDER BY id DESC LIMIT 20");
            $stmt->execute(array_merge($ids, $margs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                [$outcome, $state] = device_event_mdm_outcome((string)$p['verdict'], (string)$p['verdict'] === 'offline' ? 'failed' : 'done');
                $src = (string)$p['source'] !== '' ? (string)$p['source'] : 'live';
                $add((string)$p['created_at'], 'mdm', 'Autopilot probe (' . $src . '): ' . $outcome, $state, '', '');
            }
        } catch (Throwable $e) {
            error_log('device log mdm probes: ' . $e->getMessage());
        }
    }

    // --- registrations (the appliance announcing itself) ---------------------
    if (db_table_exists('device_registrations')) {
        try {
            $stmt = db()->prepare("SELECT payload, registered_at, last_seen_ts FROM device_registrations WHERE user_id IN ($ph) AND ($match) ORDER BY id DESC LIMIT 10");
            $stmt->execute(array_merge($ids, $margs));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $p = json_decode((string)$r['payload'], true);
                $bits = [];
                if (is_array($p)) {
                    $name = (string)($p['system'] ?? $p['system_name'] ?? $p['model'] ?? '');
                    if ($name !== '') $bits[] = $name;
                    if (!empty($p['drives']) && is_array($p['drives'])) $bits[] = count($p['drives']) . ' drive(s) inventoried';
                    $ver = (string)($p['tool_version'] ?? '');
                    if ($ver !== '') $bits[] = 'appliance ' . $ver;
                }
                $add((string)$r['registered_at'], 'boot', 'Registered by the appliance', 'info', implode(' · ', $bits), '',
                    is_array($p) ? (string)($p['cocid'] ?? '') : '');
                $seen = (int)$r['last_seen_ts'];
                if ($seen > 0) {
                    $add(gmdate('Y-m-d H:i:s', $seen), 'boot', 'Last heartbeat from the appliance', 'info', '', '');
                }
            }
        } catch (Throwable $e) {
            error_log('device log registrations: ' . $e->getMessage());
        }
    }

    // --- what the drives ended up in (certificates) --------------------------
    if (db_table_exists('certificate_drives') && db_table_exists('certificates')) {
        try {
            $stmt = db()->prepare("SELECT cd.serial AS drive_serial, cd.device, cd.model, cd.size, cd.method, cd.final_status,
                                          cd.end_time, cd.ts, c.cert_id, c.cocid, c.issued_at
                                     FROM certificate_drives cd
                                     JOIN certificates c ON c.id = cd.certificate_id
                                    WHERE c.user_id IN ($ph) AND cd.system_serial = ?
                                    ORDER BY cd.id DESC LIMIT 40");
            $stmt->execute(array_merge($ids, [$serial !== '' ? $serial : "\0"]));
            $certs = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $ts = trim((string)$d['end_time']);
                if ($ts === '') $ts = (string)$d['ts'];
                $detail = implode(' · ', array_filter([
                    (string)$d['model'] !== '' ? (string)$d['model'] : (string)$d['device'],
                    (string)$d['size'],
                    (string)$d['method'],
                ]));
                $add($ts, 'drive', 'Drive ' . (string)$d['drive_serial'] . ': ' . ((string)$d['final_status'] !== '' ? (string)$d['final_status'] : 'no final status'),
                    stripos((string)$d['final_status'], 'COMPLET') !== false ? 'ok' : (stripos((string)$d['final_status'], 'FAIL') !== false ? 'fail' : 'info'),
                    $detail, (string)$d['cert_id'], (string)$d['cocid']);
                $cert = (string)$d['cert_id'];
                if ($cert !== '' && !isset($certs[$cert])) {
                    $certs[$cert] = true;
                    // The id is the ref, not the label: every ref in the Log is
                    // the identifier of one record you can look up. The CoC has
                    // its own column now, so it is no longer repeated as detail.
                    $add((string)$d['issued_at'], 'certificate', 'Certificate issued',
                        'ok', '', $cert, (string)$d['cocid']);
                }
            }
        } catch (Throwable $e) {
            error_log('device log certificate drives: ' . $e->getMessage());
        }
    }

    usort($events, static fn(array $a, array $b): int => strcmp((string)$b['at'], (string)$a['at']));
    return array_slice($events, 0, $limit);
}
