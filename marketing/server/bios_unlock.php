<?php
declare(strict_types=1);

/**
 * Remote BIOS password clear — vocabulary, crypto and compatibility layer.
 *
 * The clear itself is a `bios_unlock` command in the unified command queue
 * (remote.php): the dashboard stages it, the booted appliance pulls it, clears
 * the setup password on-device and reports back. Keeping it in that queue is
 * what lets an operator see a queued erase and a staged unlock in one place.
 *
 * This file owns what is specific to an unlock — the operator-facing verdict
 * vocabulary (`unlock_verdict`), the password crypto, and the behaviour of the
 * legacy /api/bios/unlock* routes, which still serve appliances already in the
 * field. Passwords are encrypted at rest with libsodium
 * (sodium_crypto_secretbox); the key is config.json `secret_key` (64 hex chars)
 * with a deterministic fallback for un-configured installs. They live in the
 * queue row's `options` and are purged as soon as the command resolves.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/remote.php';

/** Lazily create the bios_unlock table (idempotent — mirrors schema.sql). */
function unlock_ensure_schema(): void {
    static $ensured = false;
    if ($ensured) return;   // runs on the modal's 4 s poll; probe once per request
    $ensured = true;
    try {
        // HISTORY ONLY — nothing writes this table any more (the unified queue
        // does). It is kept so pre-merge unlocks stay auditable.
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
        // `tool_version` records which appliance build produced a verdict. The
        // appliance's own behaviour changes between builds — HP setup passwords
        // became clearable in v1.11.46 — so without it a stored failure looks
        // exactly like a current one and operators keep acting on advice that a
        // newer build has made obsolete.
        $stmt = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bios_unlock' AND COLUMN_NAME = 'tool_version'");
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE bios_unlock ADD COLUMN tool_version VARCHAR(32) NOT NULL DEFAULT "" AFTER verdict');
        }
        // Pre-merge rows are the audit trail the dashboard's command log reads
        // — carry them into the queue once (see unlock_backfill_legacy).
        unlock_backfill_legacy();
    } catch (Throwable $e) {
        error_log('unlock ensure schema error: ' . $e->getMessage());
    }
}

/** Move the legacy bios_unlock history into the unified command queue (once).
 *
 *  Rows written before the queue merge are the audit trail, and the dashboard's
 *  command log reads the queue — without this, pre-merge unlocks vanish from the
 *  operator's view (including the verdicts they were told to act on). The
 *  password is deliberately NOT carried over, and a legacy command that never
 *  resolved is closed as cancelled rather than re-queued: a pending row there
 *  would be claimed by the queue with no password to send.
 *
 *  Guarded by a marker row, because the queue has no way to name-match an old
 *  row and a second run would duplicate the whole history. */
function unlock_backfill_legacy(): void {
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name       VARCHAR(64) NOT NULL,
            applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $stmt = db()->prepare('SELECT COUNT(*) FROM schema_migrations WHERE name = ?');
        $stmt->execute(['unlock_to_device_commands']);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        db()->exec("INSERT INTO device_commands
              (user_id, serial, uuid, command, options, status, result, detail, verdict, tool_version, created_at, dispatched_at, resolved_at)
            SELECT user_id, serial, uuid, 'bios_unlock', NULL,
                   CASE WHEN status IN ('pending','dispatched') THEN 'cancelled' ELSE status END,
                   result, detail, verdict, tool_version, created_at, dispatched_at, resolved_at
              FROM bios_unlock");
        db()->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute(['unlock_to_device_commands']);
    } catch (Throwable $e) {
        error_log('unlock backfill error: ' . $e->getMessage());
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

/** Unlock a freshly claimed row: add the decrypted password (appliance-facing).
 *  Returns null when the password cannot be decrypted — the appliance must never
 *  run a clear blind, and the caller reports that as a failure. */
function unlock_claimed_with_password(array $claimed): ?array {
    $enc = (string)($claimed['options']['password_enc'] ?? '');
    $pwd = unlock_decrypt($enc);
    if ($pwd === null) {
        return null;
    }
    unset($claimed['options']['password_enc']);
    $claimed['password']     = $pwd;
    $claimed['password_b64'] = base64_encode($pwd);
    return $claimed;
}

/** Enqueue a clear command; supersedes any still-pending unlock for the serial
 *  (that supersede drops the older password — see remote_enqueue). */
function unlock_enqueue(int $userId, string $serial, string $uuid, string $password): int {
    unlock_ensure_schema();
    return remote_enqueue_unlock($userId, $serial, $uuid, unlock_encrypt($password));
}

/** Claim the pending unlock for a serial+uuid (appliance). Also serves
 *  appliances in the field that still poll the legacy /api/bios/unlock/pending
 *  route — it claims from the SAME unified queue, so either route sees each
 *  command exactly once. */
function unlock_claim(int $userId, string $serial, string $uuid): ?array {
    unlock_ensure_schema();
    $cmd = remote_claim($userId, $serial, $uuid, ['bios_unlock']);
    return $cmd === null ? null : unlock_claimed_with_password($cmd);
}

/** Cancel a still-pending staged unlock (dashboard). */
function unlock_cancel(int $userId, int $id): bool {
    return remote_cancel($userId, $id, 'bios_unlock');
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
function unlock_report(int $userId, int $id, string $result, string $detail, string $verdict = '', string $cocid = ''): bool {
    // Classify from the FULL detail (the phrases matched sit at the start, but
    // never risk a truncation hiding them), then clip only what we store — the
    // queue's detail column holds 255 characters.
    $verdict = unlock_verdict($result, $detail, $verdict);
    return remote_report($userId, $id, $result, unlock_clip($detail, 255), unlock_clip($verdict, 24), $cocid);
}

/** Latest unlock state for a serial — the queue's newest bios_unlock row
 *  (dashboard). */
function unlock_latest(int $userId, string $serial): array {
    unlock_ensure_schema();   // also runs the one-time legacy history backfill
    $r = remote_latest($userId, $serial, ['bios_unlock']);
    if (($r['status'] ?? 'none') === 'none') {
        return ['status' => 'none'];
    }
    // Rows written before the verdict column existed are classified on read, so
    // already-resolved commands render correctly too.
    $r['verdict'] = unlock_verdict((string)$r['result'], (string)$r['detail'], (string)$r['verdict']);
    return $r;
}
