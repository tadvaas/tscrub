<?php
declare(strict_types=1);

/**
 * Remote BIOS password clear — server side.
 *
 * The dashboard stages a clear command (operator pastes the plaintext BIOS
 * password); the booted appliance pulls it (API-token auth), clears the admin/
 * setup password on-device, and reports back. Passwords are encrypted at rest
 * with libsodium (sodium_crypto_secretbox); the key is config.json `secret_key`
 * (64 hex chars) with a deterministic fallback for un-configured installs.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';

/** Lazily create the bios_unlock table (idempotent — mirrors schema.sql). */
function unlock_ensure_schema(): void {
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS bios_unlock (
               id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id       BIGINT UNSIGNED NOT NULL,
               serial        VARCHAR(255)    NOT NULL DEFAULT "",
               uuid          VARCHAR(64)     NOT NULL DEFAULT "",
               password_enc  VARCHAR(512)    NOT NULL DEFAULT "",
               status        VARCHAR(16)     NOT NULL DEFAULT "pending",
               result        VARCHAR(16)     NOT NULL DEFAULT "",
               detail        VARCHAR(512)    NOT NULL DEFAULT "",
               verdict       VARCHAR(24)     NOT NULL DEFAULT "",
               created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               dispatched_at DATETIME        NULL,
               resolved_at   DATETIME        NULL,
               PRIMARY KEY (id),
               KEY idx_unlock_user_serial (user_id, serial, id),
               KEY idx_unlock_status (status, id)
            )'
        );
        // `verdict` landed after the first release, and `detail` had to be
        // widened to hold the appliance's honest explanation of a clear that
        // cannot work on this firmware (~253 chars). Both are idempotent
        // migrations; information_schema rather than SHOW COLUMNS LIKE ? which
        // rejects bound placeholders.
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bios_unlock' AND COLUMN_NAME = 'verdict'");
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE bios_unlock ADD COLUMN verdict VARCHAR(24) NOT NULL DEFAULT "" AFTER detail');
        }
        $stmt = db()->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bios_unlock' AND COLUMN_NAME = 'detail'");
        $len = $stmt->fetchColumn();
        if ($len !== false && $len !== null && (int)$len < 512) {
            db()->exec('ALTER TABLE bios_unlock MODIFY COLUMN detail VARCHAR(512) NOT NULL DEFAULT ""');
        }
    } catch (Throwable $e) {
        error_log('unlock ensure schema error: ' . $e->getMessage());
    }
}

/** 32-byte encryption key: config.json `secret_key` (64 hex) or a derived fallback. */
function unlock_key(): string {
    $s = db_config()['secret_key'] ?? '';
    if (is_string($s) && preg_match('/^[0-9a-f]{64}$/i', $s) === 1) {
        return hex2bin($s);
    }
    // Deterministic fallback — rotate by setting a real secret_key in config.json.
    $seed = db_config()['db']['password'] ?? 'tscrub';
    return hash('sha256', 'tscrub-bios-unlock:' . $seed, true);
}

function unlock_encrypt(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, unlock_key()));
}

function unlock_decrypt(string $blob): ?string {
    try {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $p = sodium_crypto_secretbox_open($ct, $nonce, unlock_key());
        return $p === false ? null : (string)$p;
    } catch (Throwable $e) {
        return null;
    }
}

/** Expire stale pending commands (never claimed) and purge their passwords. */
function unlock_expire_stale(): void {
    try {
        db()->prepare('UPDATE bios_unlock SET status = "cancelled", resolved_at = UTC_TIMESTAMP(), password_enc = "" WHERE status = "pending" AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY')
            ->execute();
    } catch (Throwable $e) {
        error_log('unlock expire stale error: ' . $e->getMessage());
    }
}

/** Enqueue a clear command; supersedes any still-pending one for the serial. */
function unlock_enqueue(int $userId, string $serial, string $uuid, string $password): int {
    unlock_ensure_schema();
    unlock_expire_stale();
    // Superseded rows must not retain their password at rest.
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    db()->prepare("UPDATE bios_unlock SET status = 'superseded', resolved_at = UTC_TIMESTAMP(), password_enc = '' WHERE user_id IN ($ph) AND serial = ? AND status = 'pending'")
        ->execute(array_merge($ids, [$serial]));
    db()->prepare('INSERT INTO bios_unlock (user_id, serial, uuid, password_enc, status) VALUES (?, ?, ?, ?, "pending")')
        ->execute([$userId, $serial, $uuid, unlock_encrypt($password)]);
    return (int)db()->lastInsertId();
}

/** Claim the pending command for a serial+uuid (appliance) — returns decrypted data. */
function unlock_claim(int $userId, string $serial, string $uuid): ?array {
    unlock_ensure_schema();
    unlock_expire_stale();
    db()->beginTransaction();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Requeue a dispatched command whose result POST never arrived (the
        // appliance's report was lost), so it doesn't stay dispatched forever.
        db()->prepare("UPDATE bios_unlock SET status = 'pending', dispatched_at = NULL WHERE user_id IN ($ph) AND serial = ? AND status = 'dispatched' AND dispatched_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE")
            ->execute(array_merge($ids, [$serial]));

        // Match serial, and the staged uuid when present (serial-only fallback).
        $stmt = db()->prepare("SELECT * FROM bios_unlock WHERE user_id IN ($ph) AND serial = ? AND (uuid = '' OR uuid = ?) AND status = 'pending' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $stmt->execute(array_merge($ids, [$serial, $uuid]));
        $row = $stmt->fetch();
        if ($row === false) {
            db()->commit();
            return null;
        }
        db()->prepare('UPDATE bios_unlock SET status = "dispatched", dispatched_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([(int)$row['id']]);
        db()->commit();
        $pwd = unlock_decrypt((string)$row['password_enc']);
        if ($pwd === null) {
            return null;
        }
        return [
            'id'       => (int)$row['id'],
            'serial'   => (string)$row['serial'],
            'uuid'     => (string)$row['uuid'],
            'password' => $pwd,
        ];
    } catch (Throwable $e) {
        try { db()->rollBack(); } catch (Throwable $ignored) {}
        error_log('unlock claim error: ' . $e->getMessage());
        return null;
    }
}

/** Trim a value to a column's character length (multibyte-safe). */
function unlock_clip(string $s, int $max): string {
    $s = trim($s);
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * Reduce an appliance result + its free-text detail to a single machine-readable
 * verdict, so the dashboard can say "wrong password" or "this firmware has no
 * clear path from Linux" instead of a generic "failed". A clear returns
 * `failed` for both of those cases, and they need opposite operator action —
 * one is retried, the other can never succeed from Linux.
 *
 * The wording matched here is emitted by product/src/38_bios_unlock.sh
 * (bios_unlock::_write_error_reason + bios_unlock::clear). Anything that stops
 * matching degrades to 'failed'; the detail is shown verbatim either way, so no
 * information is lost. The wording lives server-side on purpose — one place
 * owns the operator-facing vocabulary.
 *
 * ORDER MATTERS: the v1.11.44 wording for "writes accepted but the password is
 * unchanged" also contained the words "wrong password, or this firmware exposes
 * no reset path from Linux", so the no-clear-path family is tested first —
 * removing exactly that ambiguity is why this function exists.
 */
function unlock_verdict(string $result, string $detail, string $stored = ''): string {
    $stored = trim($stored);
    if ($stored !== '') {
        return $stored;   // an explicit verdict from the appliance wins
    }
    $d = strtolower($detail);
    $has = static fn(string $needle): bool => str_contains($d, $needle);

    if ($result === 'cleared') {
        return 'cleared';
    }
    if ($has('no clear path from linux') || $has('no reset path from linux') || $has('the writes were accepted')) {
        return 'no_clear_path';
    }
    if ($has('wrong password')) {
        return 'wrong_password';
    }
    if ($has('password policy')) {
        return 'policy';
    }
    if ($has('does not support setting or clearing')) {
        return 'not_supported';
    }
    if ($has('cap_sys_admin')) {
        return 'needs_privilege';
    }
    if ($has('read-only') || $has('is not writable')) {
        return 'read_only';
    }
    if ($has('no firmware-attributes device published') || $has('no writable bios password interface')) {
        return 'no_interface';
    }
    return $result === 'unsupported' ? 'unsupported' : 'failed';
}

/** Record the appliance's result for a dispatched command; purge the password.
 *  Returns false (and writes nothing) when the row is not dispatched. */
function unlock_report(int $userId, int $id, string $result, string $detail, string $verdict = ''): bool {
    $status = $result === 'cleared' ? 'done' : $result;
    // Classify from the FULL detail (the phrases matched sit at the start, but
    // never risk a truncation hiding them), then clip only what we store.
    $verdict = unlock_verdict($result, $detail, $verdict);
    $detail  = unlock_clip($detail, 512);
    $verdict = unlock_clip($verdict, 24);
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE bios_unlock SET status = ?, result = ?, detail = ?, verdict = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND user_id IN ($ph) AND status = 'dispatched'");
        $stmt->execute(array_merge([$status, $result, $detail, $verdict, $id], $ids));
        if ($stmt->rowCount() === 0) {
            return false;
        }
        // Never retain the password once the command has run.
        db()->prepare('UPDATE bios_unlock SET password_enc = "" WHERE id = ?')->execute([$id]);
        return true;
    } catch (Throwable $e) {
        error_log('unlock report error: ' . $e->getMessage());
        return false;
    }
}

/** Cancel a still-pending staged command (dashboard). Purges the password. */
function unlock_cancel(int $userId, int $id): bool {
    unlock_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("UPDATE bios_unlock SET status = 'cancelled', resolved_at = UTC_TIMESTAMP(), password_enc = '' WHERE id = ? AND user_id IN ($ph) AND status = 'pending'");
        $stmt->execute(array_merge([$id], $ids));
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('unlock cancel error: ' . $e->getMessage());
        return false;
    }
}

/** Latest unlock command state for a serial (dashboard). */
function unlock_latest(int $userId, string $serial): array {
    unlock_ensure_schema();
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, status, result, detail, verdict, created_at, resolved_at FROM bios_unlock WHERE user_id IN ($ph) AND serial = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(array_merge($ids, [$serial]));
        $r = $stmt->fetch();
        if ($r === false) {
            return ['status' => 'none'];
        }
        return [
            'id'          => (int)$r['id'],
            'status'      => (string)$r['status'],
            'result'      => (string)$r['result'],
            /* Rows written before the verdict column existed are classified on
               read, so already-resolved commands render correctly too. */
            'verdict'     => unlock_verdict((string)$r['result'], (string)$r['detail'], (string)($r['verdict'] ?? '')),
            'detail'      => (string)$r['detail'],
            'created_at'  => (string)$r['created_at'],
            'resolved_at' => $r['resolved_at'] === null ? null : (string)$r['resolved_at'],
        ];
    } catch (Throwable $e) {
        error_log('unlock latest error: ' . $e->getMessage());
        return ['status' => 'none'];
    }
}
