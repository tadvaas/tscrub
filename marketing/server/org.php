<?php
declare(strict_types=1);

/**
 * Organisations & seats (§5) — a lightweight multi-user workspace layer.
 *
 * One Team/Enterprise licence covers several operators, who share the org's
 * reports, certificates, devices, MDM results, remote commands and BIOS-unlock
 * queue. Email-invite + role; no SSO, no separate portal.
 *
 * Scoping model:
 *   - WRITES keep `user_id = <acting user>` (attribution / audit trail).
 *   - READS resolve the actor to an org id-list via org_member_ids(): a solo
 *     user returns [self] (byte-identical to the pre-org behaviour), an active
 *     org member returns every active member's user_id.
 *
 * Roles: owner (everything) > admin (invite/remove/role) > member (operate).
 * Member removal is a SOFT remove (status -> inactive): the member's rows stay
 * in the org's history but the seat is freed and they lose access.
 *
 * Seats are a pure function of the org's effective tier (no stored column):
 * free=2, payg=1, team=10, enterprise=50. The effective tier for a member is
 * the most permissive tier among all active members' licences; a solo user
 * keeps the pre-org "latest licence" semantics.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

const ORG_SEATS = ['free' => 2, 'payg' => 10, 'team' => 50, 'enterprise' => 100];
const ORG_INVITE_TTL = 60 * 60 * 24 * 7; // 7 days

/** Per-request cache so repeated scope lookups are one indexed query. */
function org_cache(): array {
    if (!isset($GLOBALS['tscrub_org_cache'])) {
        $GLOBALS['tscrub_org_cache'] = ['for' => [], 'ids' => []];
    }
    return $GLOBALS['tscrub_org_cache'];
}

function org_reset_cache(): void {
    unset($GLOBALS['tscrub_org_cache']);
}

/**
 * Lazily create the organisations tables (idempotent — mirrors schema.sql).
 * A fresh install gets them from schema.sql; an existing server self-heals.
 */
function org_ensure_schema(): void {
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS organisations (
               id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               name          VARCHAR(255)    NOT NULL,
               owner_user_id BIGINT UNSIGNED NOT NULL,
               created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_org_owner (owner_user_id),
               CONSTRAINT fk_org_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS organisation_members (
               id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               organisation_id BIGINT UNSIGNED NOT NULL,
               user_id         BIGINT UNSIGNED NOT NULL,
               role            ENUM("owner","admin","member") NOT NULL DEFAULT "member",
               status          ENUM("active","inactive") NOT NULL DEFAULT "active",
               joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_org_member_user (user_id),
               KEY idx_org_member_org (organisation_id),
               CONSTRAINT fk_org_member_org  FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE CASCADE,
               CONSTRAINT fk_org_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS organisation_invites (
               token           CHAR(64)        NOT NULL,
               organisation_id BIGINT UNSIGNED NOT NULL,
               email           VARCHAR(255)    NOT NULL,
               role            ENUM("admin","member") NOT NULL DEFAULT "member",
               created_by      BIGINT UNSIGNED NOT NULL,
               expires_at      DATETIME        NOT NULL,
               used            TINYINT(1)      NOT NULL DEFAULT 0,
               created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (token),
               KEY idx_org_invites_org (organisation_id),
               KEY idx_org_invites_email (email)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        error_log('org ensure schema error: ' . $e->getMessage());
    }
}

/** The user's membership row (organisation_id, role, status, org name/owner), or null. */
function org_for_user(int $userId): ?array {
    $cache = org_cache();
    if (array_key_exists($userId, $cache['for'])) {
        return $cache['for'][$userId];
    }
    $row = null;
    try {
        $stmt = db()->prepare(
            'SELECT m.organisation_id, m.role, m.status, o.name, o.owner_user_id
             FROM organisation_members m JOIN organisations o ON o.id = m.organisation_id
             WHERE m.user_id = ?'
        );
        $stmt->execute([$userId]);
        $r = $stmt->fetch();
        if ($r !== false) {
            $row = [
                'organisation_id' => (int)$r['organisation_id'],
                'role'            => (string)$r['role'],
                'status'          => (string)$r['status'],
                'name'            => (string)$r['name'],
                'owner_user_id'   => (int)$r['owner_user_id'],
            ];
        }
    } catch (Throwable $e) {
        // tables missing — behave as solo.
    }
    $cache['for'][$userId] = $row;
    $GLOBALS['tscrub_org_cache'] = $cache;
    return $row;
}

/**
 * The actor's read scope: every active member user_id in their organisation,
 * or just [self] when solo (or soft-removed). The core of the scoping model.
 */
function org_member_ids(int $userId): array {
    $cache = org_cache();
    if (array_key_exists($userId, $cache['ids'])) {
        return $cache['ids'][$userId];
    }
    $ids = [$userId];
    try {
        $stmt = db()->prepare(
            'SELECT m2.user_id
             FROM organisation_members m1
             JOIN organisation_members m2 ON m2.organisation_id = m1.organisation_id AND m2.status = "active"
             WHERE m1.user_id = ? AND m1.status = "active"'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($rows) {
            $ids = array_map('intval', $rows);
            sort($ids);
        }
    } catch (Throwable $e) {
        // tables missing — behave as solo.
    }
    $cache['ids'][$userId] = $ids;
    $GLOBALS['tscrub_org_cache'] = $cache;
    return $ids;
}

/** 'owner' | 'admin' | 'member' | 'none' (inactive/removed counts as none). */
function org_role(int $userId): string {
    $m = org_for_user($userId);
    if ($m === null || $m['status'] !== 'active') {
        return 'none';
    }
    return (string)$m['role'];
}

/** True when $actorId may act on a row owned by $rowUserId (same user or same org). */
function org_owns(int $actorId, int $rowUserId): bool {
    if ($rowUserId <= 0) {
        return false;
    }
    if ($rowUserId === $actorId) {
        return true;
    }
    return in_array($rowUserId, org_member_ids($actorId), true);
}

/** Seat allowance for a tier. */
function org_seats_for_tier(string $tier): int {
    return ORG_SEATS[$tier] ?? 1;
}

/**
 * Effective licence tier for a user. Org members: the most permissive tier
 * among all active members' licences. Solo: the pre-org "latest licence"
 * semantics, preserved exactly so existing accounts don't change behaviour.
 */
function org_tier(int $userId): string {
    try {
        $m = org_for_user($userId);
        if ($m === null || $m['status'] !== 'active') {
            $stmt = db()->prepare('SELECT tier FROM licences WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
            $stmt->execute([$userId]);
            $t = $stmt->fetch();
            return ($t !== false && isset($t['tier']) && $t['tier'] !== null) ? (string)$t['tier'] : 'free';
        }
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT tier FROM licences WHERE user_id IN ($ph) ORDER BY FIELD(tier, 'enterprise', 'team', 'payg', 'free'), id DESC LIMIT 1");
        $stmt->execute($ids);
        $t = $stmt->fetch();
        return ($t !== false && isset($t['tier']) && $t['tier'] !== null) ? (string)$t['tier'] : 'free';
    } catch (Throwable $e) {
        return 'free';
    }
}

/** Effective licence summary (tier + seats) for a user. */
function org_effective_licence(int $userId): array {
    $tier = org_tier($userId);
    return ['tier' => $tier, 'seats' => org_seats_for_tier($tier)];
}

function org_active_member_count(int $orgId): int {
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM organisation_members WHERE organisation_id = ? AND status = "active"');
        $stmt->execute([$orgId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Re-tag a user's pre-org personal credit rows to their organisation so the
 * pooled wallet picks them up. A user who held device credits BEFORE creating
 * or joining an org must not lose them — their historical rows sit at
 * organisation_id = 0 and the pooled balance reads organisation_id = <org>.
 * Best-effort: the credit table (or its organisation_id column) may not exist
 * yet on a fresh install.
 */
function org_adopt_personal_credits(int $userId, int $orgId): void {
    try {
        db()->prepare('UPDATE credit_events SET organisation_id = ? WHERE user_id = ? AND organisation_id = 0')
            ->execute([$orgId, $userId]);
    } catch (Throwable $e) {
        error_log('org adopt credits error: ' . $e->getMessage());
    }
}

function org_user(int $userId): ?array {
    try {
        $stmt = db()->prepare('SELECT id, email, name, status FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        return $u === false ? null : $u;
    } catch (Throwable $e) {
        return null;
    }
}

function org_user_by_email(string $email): ?array {
    try {
        $stmt = db()->prepare('SELECT id, email, name, status FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $u = $stmt->fetch();
        return $u === false ? null : $u;
    } catch (Throwable $e) {
        return null;
    }
}

/** Members of the acting user's organisation (email + role + status). */
function org_members(int $userId): array {
    $m = org_for_user($userId);
    if ($m === null) {
        return [];
    }
    try {
        $stmt = db()->prepare(
            'SELECT m.user_id, m.role, m.status, m.joined_at, u.email, u.name
             FROM organisation_members m JOIN users u ON u.id = m.user_id
             WHERE m.organisation_id = ?
             ORDER BY FIELD(m.role, "owner", "admin", "member"), u.email'
        );
        $stmt->execute([(int)$m['organisation_id']]);
        return array_map(fn($r) => [
            'user_id'   => (int)$r['user_id'],
            'email'     => (string)$r['email'],
            'name'      => (string)$r['name'],
            'role'      => (string)$r['role'],
            'status'    => (string)$r['status'],
            'joined_at' => (string)$r['joined_at'],
        ], $stmt->fetchAll());
    } catch (Throwable $e) {
        error_log('org members error: ' . $e->getMessage());
        return [];
    }
}

/** The org object for the current user, or null when solo. */
function org_summary(int $userId): array {
    org_ensure_schema();
    $m = org_for_user($userId);
    $lic = org_effective_licence($userId);
    if ($m === null) {
        return [
            'org'        => null,
            'role'       => 'none',
            'tier'       => $lic['tier'],
            'seats'      => $lic['seats'],
            'seats_used' => 0,
            'members'    => [],
        ];
    }
    return [
        'org' => [
            'id'            => (int)$m['organisation_id'],
            'name'          => (string)$m['name'],
            'owner_user_id' => (int)$m['owner_user_id'],
        ],
        'role'       => (string)$m['role'],
        'status'     => (string)$m['status'],
        'tier'       => $lic['tier'],
        'seats'      => $lic['seats'],
        'seats_used' => org_active_member_count((int)$m['organisation_id']),
        'members'    => org_members($userId),
    ];
}

/** Create an organisation; the caller becomes its owner. */
function org_create(int $userId, string $name): array {
    org_ensure_schema();
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
        fail(400, 'Organisation name must be between 1 and 255 characters.');
    }
    if (org_for_user($userId) !== null) {
        fail(409, 'You are already a member of an organisation.');
    }
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO organisations (name, owner_user_id) VALUES (?, ?)')
            ->execute([$name, $userId]);
        $orgId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO organisation_members (organisation_id, user_id, role, status) VALUES (?, ?, "owner", "active")')
            ->execute([$orgId, $userId]);
        org_adopt_personal_credits($userId, $orgId);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('org create error: ' . $e->getMessage());
        fail(500, 'Could not create the organisation.');
    }
    org_reset_cache();
    return org_summary($userId);
}

/** Rename the organisation (owner only). */
function org_rename(int $userId, string $name): array {
    org_ensure_schema();
    $m = org_for_user($userId);
    if ($m === null || $m['status'] !== 'active') {
        fail(403, 'You are not in an organisation.');
    }
    if ((string)$m['role'] !== 'owner') {
        fail(403, 'Only the owner can rename the organisation.');
    }
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
        fail(400, 'Organisation name must be between 1 and 255 characters.');
    }
    db()->prepare('UPDATE organisations SET name = ? WHERE id = ?')
        ->execute([$name, (int)$m['organisation_id']]);
    org_reset_cache();
    return org_summary($userId);
}

/** Invite an existing account by email; returns the token (caller sends the email). */
function org_invite(int $actorId, string $email, string $role): array {
    org_ensure_schema();
    $m = org_for_user($actorId);
    if ($m === null || $m['status'] !== 'active') {
        fail(403, 'You are not in an organisation.');
    }
    if (!in_array((string)$m['role'], ['owner', 'admin'], true)) {
        fail(403, 'Only owners and admins can invite members.');
    }
    if (!in_array($role, ['admin', 'member'], true)) {
        fail(400, 'Invalid role.');
    }
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        fail(400, 'A valid email address is required.');
    }
    // An ACTIVE member can't be re-invited; an inactive (soft-removed) one can.
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM organisation_members m JOIN users u ON u.id = m.user_id
             WHERE m.organisation_id = ? AND LOWER(u.email) = ? AND m.status = "active"'
        );
        $stmt->execute([(int)$m['organisation_id'], $email]);
        if ((int)$stmt->fetchColumn() > 0) {
            fail(409, 'That person is already a member of the organisation.');
        }
    } catch (Throwable $e) {
        error_log('org invite member check error: ' . $e->getMessage());
    }

    $seats = org_seats_for_tier(org_tier($actorId));
    if (org_active_member_count((int)$m['organisation_id']) >= $seats) {
        fail(409, 'Seat limit reached — upgrade your licence to add more operators.');
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO organisation_invites (token, organisation_id, email, role, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $token,
            (int)$m['organisation_id'],
            $email,
            $role,
            $actorId,
            gmdate('Y-m-d H:i:s', time() + ORG_INVITE_TTL),
        ]);
    return ['token' => $token, 'email' => $email, 'role' => $role];
}

/** Accept an invite (the logged-in user's email must match the invite). */
function org_accept(int $userId, string $token): array {
    org_ensure_schema();
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        fail(400, 'Invalid invitation.');
    }
    $stmt = db()->prepare('SELECT * FROM organisation_invites WHERE token = ? AND used = 0');
    $stmt->execute([$token]);
    $inv = $stmt->fetch();
    if ($inv === false || strtotime((string)$inv['expires_at']) <= time()) {
        fail(400, 'This invitation is invalid or has expired.');
    }
    $u = org_user($userId);
    if ($u === null) {
        fail(404, 'User not found.');
    }
    if (strtolower(trim((string)$u['email'])) !== strtolower(trim((string)$inv['email']))) {
        fail(403, 'This invitation was sent to a different email address.');
    }
    $existing = org_for_user($userId);
    if ($existing !== null) {
        if ((string)$existing['status'] === 'active') {
            fail(409, 'You are already a member of an organisation.');
        }
        if ((int)$existing['organisation_id'] !== (int)$inv['organisation_id']) {
            fail(409, 'You are already a member of a different organisation.');
        }
        // Inactive in THIS org → allowed; the accept below reactivates the seat.
    }

    $orgStmt = db()->prepare('SELECT id, owner_user_id FROM organisations WHERE id = ?');
    $orgStmt->execute([(int)$inv['organisation_id']]);
    $org = $orgStmt->fetch();
    if ($org === false) {
        fail(404, 'Organisation not found.');
    }

    db()->beginTransaction();
    try {
        // Lock the membership rows to serialise concurrent accepts (seat race).
        $stmt = db()->prepare('SELECT COUNT(*) FROM organisation_members WHERE organisation_id = ? AND status = "active" FOR UPDATE');
        $stmt->execute([(int)$org['id']]);
        $active = (int)$stmt->fetchColumn();
        $seats = org_seats_for_tier(org_tier((int)$org['owner_user_id']));
        if ($active >= $seats) {
            db()->rollBack();
            fail(409, 'Seat limit reached.');
        }
        db()->prepare('UPDATE organisation_invites SET used = 1 WHERE token = ? AND used = 0')->execute([$token]);
        db()->prepare('INSERT INTO organisation_members (organisation_id, user_id, role, status) VALUES (?, ?, ?, "active")
                       ON DUPLICATE KEY UPDATE role = VALUES(role), status = "active", joined_at = UTC_TIMESTAMP()')
            ->execute([(int)$org['id'], $userId, (string)$inv['role']]);
        org_adopt_personal_credits($userId, (int)$org['id']);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('org accept error: ' . $e->getMessage());
        fail(500, 'Could not join the organisation.');
    }
    org_reset_cache();
    return org_summary($userId);
}

/** Soft-remove a member (frees the seat; their rows stay in org history). */
function org_remove(int $actorId, int $targetId): void {
    $m = org_for_user($actorId);
    if ($m === null || $m['status'] !== 'active') {
        fail(403, 'You are not in an organisation.');
    }
    if (!in_array((string)$m['role'], ['owner', 'admin'], true)) {
        fail(403, 'Only owners and admins can remove members.');
    }
    $target = org_for_user($targetId);
    if ($target === null || (int)$target['organisation_id'] !== (int)$m['organisation_id']) {
        fail(404, 'Member not found.');
    }
    if ((string)$target['role'] === 'owner') {
        fail(403, 'The owner cannot be removed. Transfer ownership first.');
    }
    if ($actorId === $targetId) {
        fail(400, 'You cannot remove yourself — use "Leave organisation" instead.');
    }
    if ((string)$m['role'] === 'admin' && (string)$target['role'] === 'admin') {
        fail(403, 'Admins cannot remove other admins.');
    }
    db()->prepare('UPDATE organisation_members SET status = "inactive" WHERE organisation_id = ? AND user_id = ? AND status = "active"')
        ->execute([(int)$m['organisation_id'], $targetId]);
    org_reset_cache();
}

/** Change a member's role (owner or admin). */
function org_change_role(int $actorId, int $targetId, string $role): void {
    $m = org_for_user($actorId);
    if ($m === null || $m['status'] !== 'active') {
        fail(403, 'You are not in an organisation.');
    }
    if (!in_array((string)$m['role'], ['owner', 'admin'], true)) {
        fail(403, 'Only owners and admins can change roles.');
    }
    if (!in_array($role, ['admin', 'member'], true)) {
        fail(400, 'Invalid role.');
    }
    $target = org_for_user($targetId);
    if ($target === null || (int)$target['organisation_id'] !== (int)$m['organisation_id'] || (string)$target['status'] !== 'active') {
        fail(404, 'Member not found.');
    }
    if ((string)$target['role'] === 'owner') {
        fail(403, 'The owner\'s role cannot be changed.');
    }
    db()->prepare('UPDATE organisation_members SET role = ? WHERE organisation_id = ? AND user_id = ? AND status = "active"')
        ->execute([$role, (int)$m['organisation_id'], $targetId]);
    org_reset_cache();
}

/** Leave an organisation (owner must transfer first). */
function org_leave(int $userId): void {
    $m = org_for_user($userId);
    if ($m === null) {
        fail(400, 'You are not in an organisation.');
    }
    if ((string)$m['role'] === 'owner') {
        fail(403, 'The owner must transfer ownership before leaving.');
    }
    db()->prepare('UPDATE organisation_members SET status = "inactive" WHERE organisation_id = ? AND user_id = ? AND status = "active"')
        ->execute([(int)$m['organisation_id'], $userId]);
    org_reset_cache();
}

/** Transfer ownership to another active member. */
function org_transfer(int $actorId, int $targetId): void {
    $m = org_for_user($actorId);
    if ($m === null || (string)$m['role'] !== 'owner') {
        fail(403, 'Only the owner can transfer ownership.');
    }
    $target = org_for_user($targetId);
    if ($target === null || (int)$target['organisation_id'] !== (int)$m['organisation_id'] || (string)$target['status'] !== 'active') {
        fail(404, 'Member not found.');
    }
    if ($targetId === $actorId) {
        fail(400, 'You already own this organisation.');
    }
    db()->beginTransaction();
    try {
        db()->prepare('UPDATE organisation_members SET role = "admin" WHERE organisation_id = ? AND user_id = ?')
            ->execute([(int)$m['organisation_id'], $actorId]);
        db()->prepare('UPDATE organisation_members SET role = "owner" WHERE organisation_id = ? AND user_id = ?')
            ->execute([(int)$m['organisation_id'], $targetId]);
        db()->prepare('UPDATE organisations SET owner_user_id = ? WHERE id = ?')
            ->execute([$targetId, (int)$m['organisation_id']]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('org transfer error: ' . $e->getMessage());
        fail(500, 'Could not transfer ownership.');
    }
    org_reset_cache();
}
