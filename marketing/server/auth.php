<?php
declare(strict_types=1);

/**
 * Session + authentication layer.
 *
 * Sessions are stored in the `sessions` table (opaque token in an HttpOnly
 * cookie) so an admin can revoke them. Each session carries its own CSRF token
 * which must be sent as the `X-CSRF-Token` header on state-changing requests.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';

const SESSION_COOKIE = 'tscrub_session';
const SESSION_LIFETIME = 60 * 60 * 24 * 30; // 30 days

function auth_token(): string {
    return bin2hex(random_bytes(32));
}

function auth_cookie_opts(int $expires): array {
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Load (or create) the current session and hydrate the current user. */
function auth_start(): void {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    $row = null;

    if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
        $stmt = db()->prepare('SELECT id, user_id, csrf, expires_at FROM sessions WHERE id = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch() ?: null;
        if ($row !== null && strtotime((string)$row['expires_at']) <= time()) {
            $row = null;
        }
    }

    if ($row === null) {
        $token = auth_token();
        $csrf  = auth_token();
        db()->prepare(
            'INSERT INTO sessions (id, user_id, csrf, ip, user_agent, expires_at) VALUES (?, NULL, ?, ?, ?, ?)'
        )->execute([
            $token,
            $csrf,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            gmdate('Y-m-d H:i:s', time() + SESSION_LIFETIME),
        ]);
        setcookie(SESSION_COOKIE, $token, auth_cookie_opts(time() + SESSION_LIFETIME));
        $row = ['id' => $token, 'user_id' => null, 'csrf' => $csrf];
    }

    // Opportunistically reclaim expired sessions (~1% of requests) so the table
    // can't grow without bound from anonymous (cookie-less) requests.
    if (random_int(1, 100) === 1) {
        db()->exec('DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP()');
        db()->exec('DELETE FROM tokens WHERE expires_at <= UTC_TIMESTAMP()');
    }

    $GLOBALS['tscrub_session'] = $row;
    $GLOBALS['tscrub_user'] = null;

    if ($row['user_id'] !== null) {
        $stmt = db()->prepare(
            'SELECT id, email, name, account_type, company_name, company_reg, addr_line1, addr_line2, city, postcode, country, phone, role, email_verified, status, created_at, last_login_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$row['user_id']]);
        $u = $stmt->fetch();
        if ($u !== false && $u['status'] === 'active') {
            $GLOBALS['tscrub_user'] = $u;
        }
    }
}

/** The currently authenticated user, or null. */
function auth_user(): ?array {
    return $GLOBALS['tscrub_user'] ?? null;
}

/** The CSRF token for the current session. */
function auth_csrf(): string {
    return (string)($GLOBALS['tscrub_session']['csrf'] ?? '');
}

/** Reject the request unless the CSRF token matches the session. */
function auth_csrf_verify(): void {
    $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($given) || $given === '' || !hash_equals(auth_csrf(), $given)) {
        fail(403, 'Invalid CSRF token.');
    }
}

/** Require an authenticated user; exit 401 otherwise. */
function auth_require(): array {
    $u = auth_user();
    if ($u === null) {
        fail(401, 'Authentication required.');
    }
    return $u;
}

/** Require an admin; exit 403 otherwise. */
function auth_require_admin(): array {
    $u = auth_require();
    if (($u['role'] ?? '') !== 'admin') {
        fail(403, 'Admin access required.');
    }
    return $u;
}

/** Promote the current anonymous session to an authenticated one. */
function auth_login(int $userId): void {
    $old = (string)($GLOBALS['tscrub_session']['id'] ?? '');
    $new = auth_token();
    $newCsrf = auth_token();
    db()->prepare('UPDATE sessions SET id = ?, user_id = ?, csrf = ?, expires_at = ? WHERE id = ?')
        ->execute([$new, $userId, $newCsrf, gmdate('Y-m-d H:i:s', time() + SESSION_LIFETIME), $old]);
    setcookie(SESSION_COOKIE, $new, auth_cookie_opts(time() + SESSION_LIFETIME));
    $GLOBALS['tscrub_session']['id'] = $new;
    $GLOBALS['tscrub_session']['user_id'] = $userId;
    $GLOBALS['tscrub_session']['csrf'] = $newCsrf;

    db()->prepare('UPDATE users SET last_login_at = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([$userId]);
}

function auth_logout(): void {
    $token = $GLOBALS['tscrub_session']['id'] ?? null;
    if (is_string($token) && $token !== '') {
        db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
    }
    setcookie(SESSION_COOKIE, '', auth_cookie_opts(time() - 3600));
}

/** Sanitised fields safe to expose to the client. */
function user_public(array $u): array {
    return [
        'id'             => (int)$u['id'],
        'email'          => (string)$u['email'],
        'name'           => (string)$u['name'],
        'account_type'   => (string)$u['account_type'],
        'company_name'   => (string)$u['company_name'],
        'company_reg'    => (string)($u['company_reg'] ?? ''),
        'addr_line1'     => (string)($u['addr_line1'] ?? ''),
        'addr_line2'     => (string)($u['addr_line2'] ?? ''),
        'city'           => (string)($u['city'] ?? ''),
        'postcode'       => (string)($u['postcode'] ?? ''),
        'country'        => (string)($u['country'] ?? ''),
        'phone'          => (string)($u['phone'] ?? ''),
        'role'           => (string)$u['role'],
        'email_verified' => (int)$u['email_verified'] === 1,
        'status'         => (string)$u['status'],
        'created_at'     => (string)($u['created_at'] ?? ''),
        'last_login_at'  => (string)($u['last_login_at'] ?? ''),
    ];
}

/** Simple per-IP rate limiter (file based, mirrors submit.php). */
function rate_limit(string $scope, int $seconds): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = __DIR__ . '/rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $file = $dir . '/' . md5($scope . ':' . $ip);
    $fp = @fopen($file, 'c');
    if ($fp === false) {
        fail(429, 'Please wait a moment before trying again.');
    }
    flock($fp, LOCK_EX);
    $last = 0;
    if (filesize($file) > 0) {
        $last = (int)file_get_contents($file);
    }
    if ($last > 0 && (time() - $last) < $seconds) {
        flock($fp, LOCK_UN);
        fclose($fp);
        fail(429, 'Please wait a moment before trying again.');
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)time());
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function audit_log(array $admin, string $action, string $target = ''): void {
    db()->prepare('INSERT INTO admin_audit_log (admin_id, action, target, ip) VALUES (?, ?, ?, ?)')
        ->execute([
            (int)$admin['id'],
            $action,
            $target,
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
}
