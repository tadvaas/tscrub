<?php
declare(strict_types=1);

/**
 * Remote device commands — server side.
 *
 * The dashboard stages a command (shutdown, reboot, or wipe) for a device; the
 * booted appliance polls GET /api/devices/commands/pending (API-token auth),
 * claims it, and acts once it is safe (no wipe in progress). Power commands are
 * executed by the polling worker; a wipe command is handed to the console (via
 * a marker file) which reports the outcome. Mirrors the remote BIOS-unlock
 * queue in bios_unlock.php.
 *
 * status: pending -> dispatched -> done|failed (or superseded/cancelled/
 * expired). The appliance may report "deferred" (a wipe started after the
 * claim); that flips the command straight back to "pending" for the next poll.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';

/** Lazily create the device_commands table (idempotent — mirrors schema.sql). */
function remote_ensure_schema(): void {
    try {
        db()->exec(
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
    } catch (Throwable $e) {
        error_log('remote ensure schema error: ' . $e->getMessage());
    }
}

/** Expire stale commands: pending ones the device never claimed, and dispatched
 *  ones it claimed but never reported on (e.g. the console died first). */
function remote_expire_stale(int $userId, string $serial, int $minutes = 5): void {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pendingBefore = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        db()->prepare("UPDATE device_commands SET status = 'expired', resolved_at = UTC_TIMESTAMP() WHERE user_id IN ($ph) AND serial = ? AND status = 'pending' AND created_at < ?")
            ->execute(array_merge($ids, [$serial, $pendingBefore]));
        $dispatchedBefore = gmdate('Y-m-d H:i:s', time() - 120);
        db()->prepare("UPDATE device_commands SET status = 'expired', detail = 'no result received', resolved_at = UTC_TIMESTAMP() WHERE user_id IN ($ph) AND serial = ? AND status = 'dispatched' AND dispatched_at < ?")
            ->execute(array_merge($ids, [$serial, $dispatchedBefore]));
    } catch (Throwable $e) {
        error_log('remote expire error: ' . $e->getMessage());
    }
}

/** Expire every stale command for a user in one pass (dashboard read path).
 *  A shutdown/reboot the appliance claimed but never reported on — e.g. the
 *  result POST was lost as the machine powered off — must not linger as
 *  "dispatched" forever, or the status column would read "Power off…"
 *  indefinitely. */
function remote_expire_stale_user(int $userId): void {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pendingBefore = gmdate('Y-m-d H:i:s', time() - 5 * 60);
        db()->prepare("UPDATE device_commands SET status = 'expired', resolved_at = UTC_TIMESTAMP() WHERE user_id IN ($ph) AND status = 'pending' AND created_at < ?")
            ->execute(array_merge($ids, [$pendingBefore]));
        $dispatchedBefore = gmdate('Y-m-d H:i:s', time() - 120);
        db()->prepare("UPDATE device_commands SET status = 'expired', detail = 'no result received', resolved_at = UTC_TIMESTAMP() WHERE user_id IN ($ph) AND status = 'dispatched' AND dispatched_at < ?")
            ->execute(array_merge($ids, [$dispatchedBefore]));
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
    db()->prepare("UPDATE device_commands SET status = 'superseded' WHERE user_id IN ($ph) AND serial = ? AND status = 'pending'")
        ->execute(array_merge($ids, [$serial]));
    $optionsJson = $options === [] ? null : json_encode($options, JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO device_commands (user_id, serial, uuid, command, options, status) VALUES (?, ?, ?, ?, ?, "pending")')
        ->execute([$userId, $serial, $uuid, $command, $optionsJson]);
    return (int)db()->lastInsertId();
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

/** Claim the pending command for a serial (appliance). Returns null when none. */
function remote_claim(int $userId, string $serial): ?array {
    remote_ensure_schema();
    remote_expire_stale($userId, $serial);
    db()->beginTransaction();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT * FROM device_commands WHERE user_id IN ($ph) AND serial = ? AND status = 'pending' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $stmt->execute(array_merge($ids, [$serial]));
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

/** Record the appliance's result for a dispatched command. */
function remote_report(int $userId, int $id, string $result, string $detail): void {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        if ($result === 'deferred') {
            // Not safe to act right now — return to pending for the next poll.
            db()->prepare("UPDATE device_commands SET status = 'pending', dispatched_at = NULL, result = 'deferred', detail = ? WHERE id = ? AND user_id IN ($ph)")
                ->execute(array_merge([$detail, $id], $ids));
            return;
        }
        $status = $result === 'done' ? 'done' : 'failed';
        db()->prepare("UPDATE device_commands SET status = ?, result = ?, detail = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND user_id IN ($ph)")
            ->execute(array_merge([$status, $result, $detail, $id], $ids));
    } catch (Throwable $e) {
        error_log('remote report error: ' . $e->getMessage());
    }
}

/** Cancel a still-pending command (dashboard). */
function remote_cancel(int $userId, int $id): bool {
    remote_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE device_commands SET status = 'cancelled', resolved_at = UTC_TIMESTAMP() WHERE id = ? AND user_id IN ($ph) AND status = 'pending'");
        $stmt->execute(array_merge([$id], $ids));
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('remote cancel error: ' . $e->getMessage());
        return false;
    }
}

/** Latest command state for a serial (dashboard). */
function remote_latest(int $userId, string $serial): array {
    remote_ensure_schema();
    remote_expire_stale($userId, $serial);
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, command, status, result, detail, created_at, resolved_at FROM device_commands WHERE user_id IN ($ph) AND serial = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge($ids, [$serial]));
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
