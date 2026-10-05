<?php
declare(strict_types=1);

/**
 * Remote product-key injection — server side.
 *
 * The dashboard stages an injection (operator pastes a Windows product key);
 * the booted appliance pulls it (API-token auth), writes the key into the MSDM
 * ACPI table in firmware, and reports back. The key is encrypted at rest with
 * libsodium using the same master key as the BIOS-unlock passwords (reused from
 * bios_unlock.php — see unlock_key()/unlock_encrypt()/unlock_decrypt()).
 *
 * status: pending -> dispatched -> done|failed|unsupported (or superseded/
 * cancelled/expired). The appliance may report "deferred" (a wipe started after
 * the claim); that flips the row straight back to "pending" for the next poll.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bios_unlock.php';   // reuse unlock_key/encrypt/decrypt

/** Lazily create the key_inject table (idempotent — mirrors schema.sql). */
function inject_ensure_schema(): void {
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS key_inject (
               id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id       BIGINT UNSIGNED NOT NULL,
               serial        VARCHAR(255)    NOT NULL DEFAULT "",
               uuid          VARCHAR(64)     NOT NULL DEFAULT "",
               key_enc       VARCHAR(512)    NOT NULL DEFAULT "",
               status        VARCHAR(16)     NOT NULL DEFAULT "pending",
               result        VARCHAR(16)     NOT NULL DEFAULT "",
               detail        VARCHAR(255)    NOT NULL DEFAULT "",
               created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               dispatched_at DATETIME        NULL,
               resolved_at   DATETIME        NULL,
               PRIMARY KEY (id),
               KEY idx_kinj_user_serial (user_id, serial, id),
               KEY idx_kinj_status (status, id)
            )'
        );
    } catch (Throwable $e) {
        error_log('inject ensure schema error: ' . $e->getMessage());
    }
}

/** Expire stale commands: pending ones the device never claimed, and dispatched
 *  ones it claimed but never reported on. Dispatched rows get a generous window
 *  (a firmware write can take minutes), so a slow flashrom write is not marked
 *  expired mid-flight. */
function inject_expire_stale(int $userId, string $serial, int $pendingMinutes = 5, int $dispatchedSeconds = 600): void {
    inject_ensure_schema();
    try {
        $pendingBefore = gmdate('Y-m-d H:i:s', time() - $pendingMinutes * 60);
        db()->prepare('UPDATE key_inject SET status = "expired", resolved_at = UTC_TIMESTAMP() WHERE user_id = ? AND serial = ? AND status = "pending" AND created_at < ?')
            ->execute([$userId, $serial, $pendingBefore]);
        $dispatchedBefore = gmdate('Y-m-d H:i:s', time() - $dispatchedSeconds);
        db()->prepare('UPDATE key_inject SET status = "expired", detail = "no result received", resolved_at = UTC_TIMESTAMP() WHERE user_id = ? AND serial = ? AND status = "dispatched" AND dispatched_at < ?')
            ->execute([$userId, $serial, $dispatchedBefore]);
    } catch (Throwable $e) {
        error_log('inject expire error: ' . $e->getMessage());
    }
}

/** Expire every stale command for a user in one pass (dashboard read path). */
function inject_expire_stale_user(int $userId): void {
    inject_ensure_schema();
    try {
        $pendingBefore = gmdate('Y-m-d H:i:s', time() - 5 * 60);
        db()->prepare('UPDATE key_inject SET status = "expired", resolved_at = UTC_TIMESTAMP() WHERE user_id = ? AND status = "pending" AND created_at < ?')
            ->execute([$userId, $pendingBefore]);
        $dispatchedBefore = gmdate('Y-m-d H:i:s', time() - 600);
        db()->prepare('UPDATE key_inject SET status = "expired", detail = "no result received", resolved_at = UTC_TIMESTAMP() WHERE user_id = ? AND status = "dispatched" AND dispatched_at < ?')
            ->execute([$userId, $dispatchedBefore]);
    } catch (Throwable $e) {
        error_log('inject expire user error: ' . $e->getMessage());
    }
}

/** Enqueue an injection; supersedes any still-pending one for the serial. */
function inject_enqueue(int $userId, string $serial, string $uuid, string $key): int {
    inject_ensure_schema();
    inject_expire_stale($userId, $serial);
    db()->prepare('UPDATE key_inject SET status = "superseded" WHERE user_id = ? AND serial = ? AND status = "pending"')
        ->execute([$userId, $serial]);
    db()->prepare('INSERT INTO key_inject (user_id, serial, uuid, key_enc, status) VALUES (?, ?, ?, ?, "pending")')
        ->execute([$userId, $serial, $uuid, unlock_encrypt($key)]);
    return (int)db()->lastInsertId();
}

/** Claim the pending command for a serial (appliance) — returns decrypted data. */
function inject_claim(int $userId, string $serial): ?array {
    inject_ensure_schema();
    inject_expire_stale($userId, $serial);
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('SELECT * FROM key_inject WHERE user_id = ? AND serial = ? AND status = "pending" ORDER BY id ASC LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId, $serial]);
        $row = $stmt->fetch();
        if ($row === false) {
            db()->commit();
            return null;
        }
        db()->prepare('UPDATE key_inject SET status = "dispatched", dispatched_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([(int)$row['id']]);
        db()->commit();
        $key = unlock_decrypt((string)$row['key_enc']);
        if ($key === null) {
            return null;
        }
        return [
            'id'     => (int)$row['id'],
            'serial' => (string)$row['serial'],
            'uuid'   => (string)$row['uuid'],
            'key'    => $key,
        ];
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $ignored) {}
        error_log('inject claim error: ' . $e->getMessage());
        return null;
    }
}

/** Record the appliance's result for a dispatched command; purge the key. */
function inject_report(int $userId, int $id, string $result, string $detail): void {
    inject_ensure_schema();
    try {
        if ($result === 'deferred') {
            // Not safe to act right now — return to pending for the next poll.
            db()->prepare('UPDATE key_inject SET status = "pending", dispatched_at = NULL, result = "deferred", detail = ? WHERE id = ? AND user_id = ?')
                ->execute([$detail, $id, $userId]);
            return;
        }
        $status = $result === 'injected' ? 'done' : $result;   // failed|unsupported
        db()->prepare('UPDATE key_inject SET status = ?, result = ?, detail = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')
            ->execute([$status, $result, $detail, $id, $userId]);
        // Never retain the key once the command has run.
        db()->prepare('UPDATE key_inject SET key_enc = "" WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        error_log('inject report error: ' . $e->getMessage());
    }
}

/** Cancel a still-pending staged command (dashboard). Purges the key. */
function inject_cancel(int $userId, int $id): bool {
    inject_ensure_schema();
    try {
        $stmt = db()->prepare('UPDATE key_inject SET status = "cancelled", resolved_at = UTC_TIMESTAMP(), key_enc = "" WHERE id = ? AND user_id = ? AND status = "pending"');
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('inject cancel error: ' . $e->getMessage());
        return false;
    }
}

/** Latest injection state for a serial (dashboard). Never returns the key. */
function inject_latest(int $userId, string $serial): array {
    inject_ensure_schema();
    inject_expire_stale($userId, $serial);
    try {
        $stmt = db()->prepare('SELECT id, status, result, detail, created_at, resolved_at FROM key_inject WHERE user_id = ? AND serial = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, $serial]);
        $r = $stmt->fetch();
        if ($r === false) {
            return ['status' => 'none'];
        }
        return [
            'id'          => (int)$r['id'],
            'status'      => (string)$r['status'],
            'result'      => (string)$r['result'],
            'detail'      => (string)$r['detail'],
            'created_at'  => (string)$r['created_at'],
            'resolved_at' => $r['resolved_at'] === null ? null : (string)$r['resolved_at'],
        ];
    } catch (Throwable $e) {
        error_log('inject latest error: ' . $e->getMessage());
        return ['status' => 'none'];
    }
}
