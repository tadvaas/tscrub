<?php
declare(strict_types=1);

/**
 * Stripe integration — prepaid device-credit packs (Phase 1).
 *
 * No Composer dependency: Stripe's API is plain JSON over HTTPS, so we call it
 * with the built-in cURL extension and verify webhook signatures with
 * hash_hmac (Stripe's HMAC signing scheme). Keys + price IDs live in config.json
 * under a "stripe" block and are never committed. When not configured, payment
 * endpoints fail with a clear 503 and everything else is unaffected.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/org.php';

function stripe_settings(): array {
    $s = db_config()['stripe'] ?? [];
    return is_array($s) ? $s : [];
}

function stripe_configured(): bool {
    // Checkout only needs the secret key + prices; webhook delivery is gated
    // separately on the webhook signing secret (stripe_verify_webhook).
    $s = stripe_settings();
    return ($s['secret_key'] ?? '') !== '';
}

/**
 * Call a Stripe v1 API path (GET or POST). Returns the decoded JSON body.
 */
function stripe_request(string $method, string $path, array $params = []): array {
    if (!stripe_configured()) {
        fail(503, 'Payments are not configured yet.');
    }
    $key = (string)stripe_settings()['secret_key'];

    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $key];

    if ($method === 'GET') {
        if ($params !== []) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        }
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        error_log('stripe request failed: ' . $curlErr);
        fail(502, 'Payment provider unavailable.');
    }
    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
        error_log('stripe invalid response: ' . substr((string)$resp, 0, 300));
        fail(502, 'Payment provider returned an invalid response.');
    }
    if (isset($data['error'])) {
        error_log('stripe error: ' . json_encode($data['error']));
        fail(502, 'Payment provider error.');
    }
    return $data;
}

/**
 * Verify a Stripe-Signature header (v1 scheme: HMAC-SHA256 over "t.payload").
 * Rejects stale events (older than 5 minutes) to limit replay surface.
 */
function stripe_verify_webhook(string $payload, string $sigHeader): bool {
    $secret = (string)(stripe_settings()['webhook_secret'] ?? '');
    if ($secret === '' || $sigHeader === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 2) {
            $parts[trim($kv[0])] = trim($kv[1]);
        }
    }
    $t = $parts['t'] ?? '';
    $v1 = $parts['v1'] ?? '';
    if ($t === '' || $v1 === '') {
        return false;
    }
    if (abs(time() - (int)$t) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    return hash_equals($expected, $v1);
}

/**
 * Mark a webhook event as seen (idempotency). Returns false when the event was
 * already handled, so duplicate deliveries are acknowledged without re-crediting.
 */
function stripe_event_seen(string $eventId): bool {
    $stmt = db()->prepare('INSERT IGNORE INTO stripe_events (id, type) VALUES (?, ?)');
    $stmt->execute([$eventId, '']);
    return $stmt->rowCount() > 0;
}

/**
 * Lazily add the organisation_id column + org index to an existing
 * credit_events table (idempotent — mirrors schema.sql). Existing rows keep
 * organisation_id = 0 (personal), which is the pre-org semantics.
 */
function credit_ensure_schema(): void {
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_events' AND COLUMN_NAME = ?"
        );
        $stmt->execute(['organisation_id']);
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE credit_events ADD COLUMN organisation_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id');
        }
        $stmt->execute(['idx_credit_org']);
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_events' AND INDEX_NAME = 'idx_credit_org'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            db()->exec('ALTER TABLE credit_events ADD KEY idx_credit_org (organisation_id, id)');
        }
    } catch (Throwable $e) {
        error_log('credit ensure schema error: ' . $e->getMessage());
    }
}

/** The organisation_id a user's credits pool under (0 = personal/solo). */
function credit_scope_id(int $userId): int {
    $m = org_for_user($userId);
    return ($m !== null && $m['status'] === 'active') ? (int)$m['organisation_id'] : 0;
}

/**
 * Current credit balance. Org members read the organisation pool (every active
 * member's events under that org); solo users read their personal wallet
 * (organisation_id = 0). Membership changes never move the pool — it is keyed
 * by organisation_id, not by who is currently a member.
 */
function credit_balance(int $userId): int {
    credit_ensure_schema();
    $orgId = credit_scope_id($userId);
    if ($orgId > 0) {
        $stmt = db()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN type = "credit" THEN units ELSE -units END), 0)
               FROM credit_events WHERE organisation_id = ?'
        );
        $stmt->execute([$orgId]);
    } else {
        $stmt = db()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN type = "credit" THEN units ELSE -units END), 0)
               FROM credit_events WHERE organisation_id = 0 AND user_id = ?'
        );
        $stmt->execute([$userId]);
    }
    return (int)$stmt->fetchColumn();
}

/**
 * Apply one credit/debit event, idempotently keyed by (user_id, ref). The caller
 * MUST pass a non-empty, stable ref (e.g. "stripe:cs_...", "report:<sha>").
 * Returns true when a new event was recorded, false when the ref already existed.
 * The event lands in the actor's organisation pool (organisation_id resolved from
 * membership; 0 when solo), with user_id recording who did it.
 */
function credit_apply(int $userId, string $type, int $units, string $ref): bool {
    credit_ensure_schema();
    $orgId = credit_scope_id($userId);
    $stmt = db()->prepare('INSERT IGNORE INTO credit_events (user_id, organisation_id, type, units, ref) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $orgId, $type, $units, $ref]);
    return $stmt->rowCount() > 0;
}

/**
 * Atomically debit credits from the actor's pool (org or personal), never going
 * negative. Returns 1 = debited, 0 = ref already applied (no-op), -1 = insufficient
 * balance. Locks the pool's rows so concurrent members can't overspend the org.
 */
function credit_debit(int $userId, int $units, string $ref): int {
    if ($units <= 0) {
        return 0;
    }
    credit_ensure_schema();
    db()->beginTransaction();
    try {
        $orgId = credit_scope_id($userId);
        if ($orgId > 0) {
            $stmt = db()->prepare(
                'SELECT COALESCE(SUM(CASE WHEN type = "credit" THEN units ELSE -units END), 0)
                   FROM credit_events WHERE organisation_id = ? FOR UPDATE'
            );
            $stmt->execute([$orgId]);
        } else {
            $stmt = db()->prepare(
                'SELECT COALESCE(SUM(CASE WHEN type = "credit" THEN units ELSE -units END), 0)
                   FROM credit_events WHERE organisation_id = 0 AND user_id = ? FOR UPDATE'
            );
            $stmt->execute([$userId]);
        }
        if ((int)$stmt->fetchColumn() < $units) {
            db()->rollBack();
            return -1;
        }
        $applied = credit_apply($userId, 'debit', $units, $ref);
        db()->commit();
        return $applied ? 1 : 0;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('credit_debit error: ' . $e->getMessage());
        return -1;
    }
}
