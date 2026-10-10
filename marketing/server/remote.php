<?php
declare(strict_types=1);

/**
 * Remote device commands — server side.
 *
 * The dashboard stages a command (shutdown, reboot, wipe, or bios_unlock) for a
 * device; the booted appliance polls GET /api/devices/commands/pending
 * (API-token auth), claims it, and acts once it is safe. Power commands are
 * executed by the polling worker; a wipe command is handed to the console (via
 * a marker file) which reports the outcome.
 *
 * ONE QUEUE: the BIOS unlock used to be a parallel table (bios_unlock) with its
 * own routes and poll loop, which is why it never showed up beside a queued
 * erase. It is now a command type here — its password rides in `options`
 * (encrypted at rest, decrypted only at claim time, purged the moment the
 * command resolves) and its richer result vocabulary (cleared|failed|
 * unsupported + verdict) is recorded on the same row. bios_unlock.php is the
 * vocabulary/crypto/compat layer around this queue; its table is history only.
 *
 * status: pending -> dispatched -> done|failed|unsupported (or superseded/
 * cancelled/expired). The appliance may report "deferred" (a wipe started after
 * the claim); that flips the command straight back to "pending" for the next
 * poll.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';

/** Lazily create the device_commands table (idempotent — mirrors schema.sql). */
function remote_ensure_schema(): void {
    static $ensured = false;
    if ($ensured) return;   // CREATE TABLE IF NOT EXISTS + column probe are ~10ms; run once per request
    $ensured = true;
    try {
        if (!db_table_exists('device_commands')) db()->exec(
            'CREATE TABLE IF NOT EXISTS device_commands (
               id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id       BIGINT UNSIGNED NOT NULL,
               serial        VARCHAR(255)    NOT NULL DEFAULT "",
               uuid          VARCHAR(64)     NOT NULL DEFAULT "",
               command       VARCHAR(16)     NOT NULL DEFAULT "",
               options       JSON            NULL,
               status        VARCHAR(16)     NOT NULL DEFAULT "pending",
               result        VARCHAR(16)     NOT NULL DEFAULT "",
               detail        VARCHAR(255)    NOT NULL DEFAULT "",
               created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               dispatched_at DATETIME        NULL,
               resolved_at   DATETIME        NULL,
               PRIMARY KEY (id),
               KEY idx_cmd_user_serial (user_id, serial, id),
               KEY idx_cmd_status (status, id)
            )'
        );
        // The `options` column landed after the first release — add it to a
        // pre-existing table (idempotent; information_schema, not SHOW COLUMNS
        // LIKE ? which rejects bound placeholders).
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_commands' AND COLUMN_NAME = 'options'");
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE device_commands ADD COLUMN options JSON NULL AFTER command');
        }
        // `verdict` + `tool_version` came with the unified BIOS unlock: the
        // verdict is the operator-facing classification of an unlock outcome,
        // and tool_version records which appliance build produced it (builds
        // change what the appliance can do, so an old verdict is history, not
        // current state). Both are idempotent, information_schema-based
        // migrations — SHOW COLUMNS LIKE ? rejects bound placeholders.
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_commands' AND COLUMN_NAME = 'verdict'");
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE device_commands ADD COLUMN verdict VARCHAR(24) NOT NULL DEFAULT "" AFTER detail');
        }
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_commands' AND COLUMN_NAME = 'tool_version'");
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE device_commands ADD COLUMN tool_version VARCHAR(32) NOT NULL DEFAULT "" AFTER verdict');
        }
        // `cocid` is the Chain of Custody of the SESSION that executed the
        // command, as stamped by the appliance when it reported the result — not
        // at staging time, because a command staged for an offline machine can
        // run days later under a different CoC. The recorded value must be the
        // one that did the work.
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'device_commands' AND COLUMN_NAME = 'cocid'");
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE device_commands ADD COLUMN cocid VARCHAR(64) NOT NULL DEFAULT "" AFTER tool_version');
        }
    } catch (Throwable $e) {
        error_log('remote ensure schema error: ' . $e->getMessage());
    }
}

/** Expire stale commands with command-type-aware TTLs.
 *
 *  Wipe jobs are DURABLE: a pending wipe lives 7 days (survives a re-image +
 *  boot), and a dispatched wipe whose result POST was lost is requeued after
 *  10 minutes — never hard-expired. Power commands (shutdown/reboot) are
 *  online-only: a stale one must never fire long after the fact, so pending
 *  power commands expire in 5 minutes and dispatched ones in 2. */
function remote_expire_stale(int $userId, string $serial): void {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $args = array_merge($ids, [$serial]);

        db()->prepare(
            "UPDATE device_commands SET status = 'expired', resolved_at = UTC_TIMESTAMP()
             WHERE user_id IN ($ph) AND serial = ? AND status = 'pending'
               AND ((command IN ('wipe','bios_unlock') AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)
                 OR (command IN ('shutdown','reboot') AND created_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE))"
        )->execute($args);

        db()->prepare(
            "UPDATE device_commands SET status = 'pending', dispatched_at = NULL, detail = 'result lost — requeued'
             WHERE user_id IN ($ph) AND serial = ? AND status = 'dispatched' AND command IN ('wipe','bios_unlock')
               AND dispatched_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE"
        )->execute($args);

        db()->prepare(
            "UPDATE device_commands SET status = 'expired', detail = 'no result received', resolved_at = UTC_TIMESTAMP()
             WHERE user_id IN ($ph) AND serial = ? AND status = 'dispatched' AND command IN ('shutdown','reboot')
               AND dispatched_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE"
        )->execute($args);
    } catch (Throwable $e) {
        error_log('remote expire error: ' . $e->getMessage());
    }
}

/** Expire every stale command for a user in one pass (dashboard read path) with
 *  the same command-type TTLs as remote_expire_stale. */
function remote_expire_stale_user(int $userId): void {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));

        db()->prepare(
            "UPDATE device_commands SET status = 'expired', resolved_at = UTC_TIMESTAMP()
             WHERE user_id IN ($ph) AND status = 'pending'
               AND ((command IN ('wipe','bios_unlock') AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)
                 OR (command IN ('shutdown','reboot') AND created_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE))"
        )->execute($ids);

        db()->prepare(
            "UPDATE device_commands SET status = 'pending', dispatched_at = NULL, detail = 'result lost — requeued'
             WHERE user_id IN ($ph) AND status = 'dispatched' AND command IN ('wipe','bios_unlock')
               AND dispatched_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE"
        )->execute($ids);

        db()->prepare(
            "UPDATE device_commands SET status = 'expired', detail = 'no result received', resolved_at = UTC_TIMESTAMP()
             WHERE user_id IN ($ph) AND status = 'dispatched' AND command IN ('shutdown','reboot')
               AND dispatched_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE"
        )->execute($ids);
    } catch (Throwable $e) {
        error_log('remote expire user error: ' . $e->getMessage());
    }
}

/** Enqueue a command; supersedes any still-pending one for the serial. */
function remote_enqueue(int $userId, string $serial, string $uuid, string $command, array $options = []): int {
    remote_ensure_schema();
    remote_expire_stale($userId, $serial);
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    // Supersede only what genuinely conflicts. With one queue the old rule (a
    // new command cancels *any* pending one) would let clearing a BIOS password
    // silently drop a staged erase — a data-loss surprise, and the queue runs
    // one command per poll anyway, so queuing behind is safe. A newer power
    // command still replaces another power command, and a newer unlock replaces
    // the older unlock (whose password is dropped in the same statement).
    if (in_array($command, ['shutdown', 'reboot'], true)) {
        db()->prepare("UPDATE device_commands SET status = 'superseded' WHERE user_id IN ($ph) AND serial = ? AND status = 'pending' AND command IN ('shutdown','reboot')")
            ->execute(array_merge($ids, [$serial]));
    } else {
        db()->prepare("UPDATE device_commands SET status = 'superseded', options = IF(command = 'bios_unlock', NULL, options) WHERE user_id IN ($ph) AND serial = ? AND status = 'pending' AND command = ?")
            ->execute(array_merge($ids, [$serial, $command]));
    }
    $optionsJson = $options === [] ? null : json_encode($options, JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO device_commands (user_id, serial, uuid, command, options, status) VALUES (?, ?, ?, ?, ?, "pending")')
        ->execute([$userId, $serial, $uuid, $command, $optionsJson]);
    return (int)db()->lastInsertId();
}

/** Enqueue a BIOS unlock command. The caller passes the password already
 *  encrypted (unlock_encrypt); it travels in `options` so an unlock needs no
 *  column of its own, and it is purged as soon as the command resolves. */
function remote_enqueue_unlock(int $userId, string $serial, string $uuid, string $passwordEnc): int {
    return remote_enqueue($userId, $serial, $uuid, 'bios_unlock', ['password_enc' => $passwordEnc]);
}

/** The appliance build that produced a command's result, read from the device's
 *  latest report (the appliance reports its tool version with every run). Empty
 *  when it cannot be determined — no report yet — in which case the dashboard
 *  simply shows the result as before. */
function remote_row_tool_version(int $id): string {
    try {
        $stmt = db()->prepare('SELECT serial FROM device_commands WHERE id = ?');
        $stmt->execute([$id]);
        $serial = (string)($stmt->fetchColumn() ?: '');
        if ($serial === '') {
            return '';
        }
        $stmt = db()->prepare('SELECT tool_version FROM certificate_drives WHERE serial = ? AND tool_version <> "" ORDER BY id DESC LIMIT 1');
        $stmt->execute([$serial]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/** True when a shutdown/reboot is already queued or running for this serial
 *  (expired commands are cleaned first, so a stale row never blocks a new
 *  one). */
function remote_has_power_pending(int $userId, string $serial): bool {
    remote_ensure_schema();
    remote_expire_stale($userId, $serial);
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT COUNT(*) FROM device_commands WHERE user_id IN ($ph) AND serial = ? AND command IN ('shutdown', 'reboot') AND status IN ('pending', 'dispatched')");
        $stmt->execute(array_merge($ids, [$serial]));
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('remote power pending check error: ' . $e->getMessage());
        return false;
    }
}

/** Claim the pending command for a serial+uuid (appliance). Returns null when
 *  none. The uuid check is lenient — it only applies when BOTH the staged
 *  command and the appliance carry a uuid — so an empty uuid on either side
 *  never blocks a serial match (cloned serials with distinct uuids still
 *  claim only their own job). */
function remote_claim(int $userId, string $serial, string $uuid = '', array $commands = []): ?array {
    remote_ensure_schema();
    remote_expire_stale($userId, $serial);
    db()->beginTransaction();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $filter = '';
        $extra  = [];
        if ($commands !== []) {
            $allowed = array_values(array_intersect($commands, ['shutdown', 'reboot', 'wipe', 'bios_unlock']));
            if ($allowed === []) {
                db()->commit();
                return null;
            }
            $filter = ' AND command IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $extra  = $allowed;
        }
        $stmt = db()->prepare("SELECT * FROM device_commands WHERE user_id IN ($ph) AND serial = ? AND (uuid = '' OR ? = '' OR LOWER(uuid) = LOWER(?)) AND status = 'pending'$filter ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $stmt->execute(array_merge($ids, [$serial, $uuid, $uuid], $extra));
        $row = $stmt->fetch();
        if ($row === false) {
            db()->commit();
            return null;
        }
        db()->prepare('UPDATE device_commands SET status = "dispatched", dispatched_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([(int)$row['id']]);
        db()->commit();
        $opts = [];
        if (!empty($row['options'])) {
            $decoded = json_decode((string)$row['options'], true);
            if (is_array($decoded)) $opts = $decoded;
        }
        return [
            'id'      => (int)$row['id'],
            'serial'  => (string)$row['serial'],
            'uuid'    => (string)$row['uuid'],
            'command' => (string)$row['command'],
            'options' => $opts,
        ];
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $ignored) {}
        error_log('remote claim error: ' . $e->getMessage());
        return null;
    }
}

/** Record the appliance's result for a dispatched command.
 *
 *  BIOS unlock keeps its own vocabulary (cleared|failed|unsupported) plus an
 *  optional verdict; `cleared` maps to status `done` and the two failure results
 *  stay as the row status, which is exactly what the unlock card has always
 *  rendered. The password is dropped from the row the moment the command
 *  resolves — a finished clear must never leave a credential behind. */
function remote_report(int $userId, int $id, string $result, string $detail, string $verdict = '', string $cocid = ''): bool {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($result === 'deferred') {
            // Not safe to act right now — return to pending for the next poll.
            db()->prepare("UPDATE device_commands SET status = 'pending', dispatched_at = NULL, result = 'deferred', detail = ? WHERE id = ? AND user_id IN ($ph) AND status = 'dispatched'")
                ->execute(array_merge([$detail, $id], $ids));
            return true;
        }
        // The ROW decides whether this is an unlock result: only a bios_unlock
        // row carries a password to drop and a verdict worth recording — and a
        // *failed* unlock, which says neither "cleared" nor "unsupported", must
        // still lose its password.
        $chk = db()->prepare("SELECT command FROM device_commands WHERE id = ? AND user_id IN ($ph)");
        $chk->execute(array_merge([$id], $ids));
        $isUnlock = (string)($chk->fetchColumn() ?: '') === 'bios_unlock';
        $status = match ($result) {
            'done', 'cleared' => 'done',
            'unsupported'     => 'unsupported',
            default           => 'failed',
        };
        // Only a genuinely dispatched command can be resolved. A duplicate or
        // late report (the appliance retries its POST up to three times) must
        // never overwrite a resolved outcome, and a caller told "false" can
        // surface the mismatch instead of silently accepting it.
        $stmt = db()->prepare("UPDATE device_commands SET status = ?, result = ?, detail = ?, verdict = ?, tool_version = ?, cocid = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND user_id IN ($ph) AND status = 'dispatched'");
        $stmt->execute(array_merge([
            $status, $result, $detail,
            mb_substr($verdict, 0, 24),
            $isUnlock ? remote_row_tool_version($id) : '',
            // CoC as the appliance stamped it; sanitised because it becomes part
            // of a filename in the report/certificate path.
            substr(preg_replace('/[^A-Za-z0-9_-]/', '', $cocid) ?? '', 0, 64),
            $id,
        ], $ids));
        $ok = $stmt->rowCount() > 0;
        if ($ok && $isUnlock) {
            db()->prepare("UPDATE device_commands SET options = NULL WHERE id = ? AND user_id IN ($ph) AND command = 'bios_unlock'")
                ->execute(array_merge([$id], $ids));
        }
        return $ok;
    } catch (Throwable $e) {
        error_log('remote report error: ' . $e->getMessage());
        return false;
    }
}

/** Cancel a still-pending command (dashboard). $command restricts the type, so
 *  the unlock card cannot cancel a queued erase by passing its id. A cancelled
 *  unlock drops its password along with the command. */
function remote_cancel(int $userId, int $id, string $command = ''): bool {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE device_commands SET status = 'cancelled', resolved_at = UTC_TIMESTAMP()"
             . ", options = IF(command = 'bios_unlock', NULL, options)"
             . " WHERE id = ? AND user_id IN ($ph) AND status = 'pending'";
        $args = array_merge([$id], $ids);
        if ($command !== '') {
            $sql .= ' AND command = ?';
            $args[] = $command;
        }
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('remote cancel error: ' . $e->getMessage());
        return false;
    }
}

/** Latest command state for a serial (dashboard). $commands restricts the types
 *  considered, because the ops modal renders power commands and BIOS unlocks in
 *  separate cards from this one queue. */
function remote_latest(int $userId, string $serial, array $commands = ['shutdown', 'reboot', 'wipe']): array {
    remote_ensure_schema();
    remote_expire_stale($userId, $serial);
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $filter = '';
        $extra  = [];
        if ($commands !== []) {
            $allowed = array_values(array_intersect($commands, ['shutdown', 'reboot', 'wipe', 'bios_unlock']));
            if ($allowed === []) {
                return ['status' => 'none'];
            }
            $filter = ' AND command IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
            $extra  = $allowed;
        }
        $stmt = db()->prepare("SELECT id, command, status, result, detail, verdict, tool_version, created_at, resolved_at FROM device_commands WHERE user_id IN ($ph) AND serial = ?$filter ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge($ids, [$serial], $extra));
        $r = $stmt->fetch();
        if ($r === false) {
            return ['status' => 'none'];
        }
        return [
            'id'          => (int)$r['id'],
            'command'     => (string)$r['command'],
            'status'      => (string)$r['status'],
            'result'      => (string)$r['result'],
            'detail'      => (string)$r['detail'],
            'verdict'     => (string)($r['verdict'] ?? ''),
            'tool_version' => (string)($r['tool_version'] ?? ''),
            'created_at'  => (string)$r['created_at'],
            'resolved_at' => $r['resolved_at'] === null ? null : (string)$r['resolved_at'],
        ];
    } catch (Throwable $e) {
        error_log('remote latest error: ' . $e->getMessage());
        return ['status' => 'none'];
    }
}

/** Attach the latest command (any status) to each device (dashboard). The
 *  queued/running dot uses status pending|dispatched; the status column uses a
 *  recent shutdown/reboot (dispatched, or done within the heartbeat window) to
 *  show "Shutting down…"/"Restarting…" while the machine powers off/reboots. */
function remote_fold_devices(int $userId, array $devices): array {
    remote_ensure_schema();
    remote_expire_stale_user($userId);
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT serial, uuid, command, status, result, dispatched_at, resolved_at FROM device_commands WHERE user_id IN ($ph) ORDER BY id DESC");
        $stmt->execute($ids);
        $bySerial = [];
        $byUuid   = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = strtolower(trim((string)$r['serial']));
            $u = strtolower(trim((string)$r['uuid']));
            $item = [
                'command'       => (string)$r['command'],
                'status'        => (string)$r['status'],
                'result'        => (string)$r['result'],
                'dispatched_at' => $r['dispatched_at'] === null ? null : (string)$r['dispatched_at'],
                'resolved_at'   => $r['resolved_at'] === null ? null : (string)$r['resolved_at'],
            ];
            if ($s !== '' && !isset($bySerial[$s])) $bySerial[$s] = $item;
            if ($u !== '' && !isset($byUuid[$u]))   $byUuid[$u]   = $item;
        }
    } catch (Throwable $e) {
        error_log('remote fold error: ' . $e->getMessage());
        return $devices;
    }

    foreach ($devices as $k => $dv) {
        $s = strtolower(trim((string)($dv['sysserial'] ?? '')));
        $u = strtolower(trim((string)($dv['systemuuid'] ?? '')));
        $devices[$k]['remote'] = ($s !== '' && isset($bySerial[$s])) ? $bySerial[$s]
            : (($u !== '' && isset($byUuid[$u])) ? $byUuid[$u] : null);
    }
    return $devices;
}
