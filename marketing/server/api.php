<?php
declare(strict_types=1);

/**
 * tScrub JSON API (front controller).
 *
 * Served by nginx `location /api/` → this file. Routes:
 *   GET  /api/csrf
 *   GET  /api/me
 *   POST /api/register
 *   POST /api/login
 *   POST /api/logout
 *   POST /api/verify-email
 *   POST /api/password/reset          (request reset email)
 *   POST /api/password/reset/confirm  (set new password)
 *   GET  /api/certs                   (current user's certificates)
 *   GET  /api/certs/{cert_id}         (one certificate, owner or admin)
 *   POST /api/licence                 (issue a licence for the current user)
 *   GET  /api/licences                (current user's licences)
 *   GET  /api/licences/{id}/download  (download a .lic file)
 *   GET  /api/admin/stats
 *   GET  /api/admin/users
 *   GET  /api/admin/users/{id}
 *   POST /api/admin/users/{id}/role
 *   POST /api/admin/users/{id}/status
 *   POST /api/admin/users/{id}/sessions/revoke
 *   POST /api/admin/users/{id}/licence
 *   GET  /api/admin/certificates
 *   GET  /api/admin/licences
 *   GET  /api/admin/audit
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/reports_lib.php';

auth_start();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';
$route = preg_replace('#^/api#', '', $uri);
$route = '/' . trim($route === null ? '' : $route, '/');

const TIERS = ['free', 'payg', 'team', 'enterprise'];

function route_segments(string $route): array {
    return array_values(array_filter(explode('/', $route), fn($s) => $s !== ''));
}

// ---- helpers ---------------------------------------------------------------

function fetch_user_by_email(string $email): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    return $u === false ? null : $u;
}

function fetch_user_by_id(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u === false ? null : $u;
}

function create_token(int $userId, string $type, int $ttlSeconds): string {
    $token = auth_token();
    db()->prepare('INSERT INTO tokens (token, user_id, type, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([$token, $userId, $type, gmdate('Y-m-d H:i:s', time() + $ttlSeconds)]);
    return $token;
}

function send_verify_email(array $u): void {
    $token = create_token((int)$u['id'], 'verify', 60 * 60 * 24);
    $link = db_base_url() . '/login?verify=' . $token;
    $name = ($u['name'] ?? '') !== '' ? $u['name'] : $u['email'];
    mail_send(
        'Verify your tScrub account',
        "Hi {$name},\n\nPlease verify your tScrub account by opening this link:\n\n{$link}\n\n"
        . "This link expires in 24 hours. If you didn't create a tScrub account, you can ignore this email.\n\n— tScrub",
        'contact',
        (string)$u['email']
    );
}

function send_reset_email(array $u): void {
    $token = create_token((int)$u['id'], 'reset', 60 * 60);
    $link = db_base_url() . '/login?reset=' . $token;
    $name = ($u['name'] ?? '') !== '' ? $u['name'] : $u['email'];
    mail_send(
        'Reset your tScrub password',
        "Hi {$name},\n\nWe received a request to reset your tScrub password. Open this link to choose a new one:\n\n{$link}\n\n"
        . "This link expires in 1 hour. If you didn't request this, you can ignore this email.\n\n— tScrub",
        'contact',
        (string)$u['email']
    );
}

function cert_row(array $c): array {
    return [
        'id'         => (int)$c['id'],
        'cert'       => (string)$c['cert_id'],
        'cocid'      => (string)$c['cocid'],
        'devices'    => (int)$c['devices'],
        'methods'    => (int)$c['methods'],
        'runs'       => (int)$c['runs'],
        'first'      => (string)($c['first_ts'] ?? ''),
        'last'       => (string)($c['last_ts'] ?? ''),
        'sha_state'  => (string)$c['sha_state'],
        'sig_state'  => (string)$c['sig_state'],
        'pdf_sha256' => (string)$c['pdf_sha256'],
        'pdf_path'   => (string)($c['pdf_path'] ?? ''),
        'has_pdf'    => (($c['pdf_path'] ?? '') !== ''),
        'issued'     => (string)$c['issued_at'],
        'user_id'    => $c['user_id'] === null ? null : (int)$c['user_id'],
    ];
}

function load_cert_reports(int $certId): array {
    $stmt = db()->prepare('SELECT report_name, sha256, state FROM certificate_reports WHERE certificate_id = ? ORDER BY id');
    $stmt->execute([$certId]);
    return array_map(
        fn($r) => ['name' => (string)$r['report_name'], 'sha' => (string)$r['sha256'], 'state' => (string)$r['state']],
        $stmt->fetchAll()
    );
}

function load_cert_drives(int $certId): array {
    $stmt = db()->prepare(
        'SELECT id, certificate_id, ts, device, type, model, serial, size, bus, class, method, certification, final_status,
                system_name AS `system`, system_serial, baseboard_serial,
                smart, tempc, poweronhours, powercycles, reallocsectors, pctused, availspare, tbw_tb, smartpost, tempcpost, poweronhourspost
         FROM certificate_drives WHERE certificate_id = ? ORDER BY id'
    );
    $stmt->execute([$certId]);
    return $stmt->fetchAll();
}

function fetch_cert_by_cert_id(string $certId): ?array {
    $stmt = db()->prepare('SELECT * FROM certificates WHERE cert_id = ?');
    $stmt->execute([$certId]);
    $c = $stmt->fetch();
    return $c === false ? null : $c;
}

function issue_licence(array $user, string $tier): array {
    $py = '/usr/bin/python3';
    $script = __DIR__ . '/issue_licence.py';

    $customer = ($user['account_type'] === 'company' && ($user['company_name'] ?? '') !== '')
        ? (string)$user['company_name']
        : ((($user['name'] ?? '') !== '') ? (string)$user['name'] : (string)$user['email']);
    $expiry = gmdate('Y-m-d', time() + 365 * 24 * 3600);

    $cmd = [$py, $script, '--tier', $tier, '--json', $customer, $expiry];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        fail(500, 'Licence issuer unavailable.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        error_log('licence issue failed: ' . trim($stderr));
        fail(500, 'Could not issue a licence.');
    }

    $lic = json_decode(trim((string)$stdout), true);
    if (!is_array($lic) || empty($lic['signature']) || empty($lic['customer']) || empty($lic['expiry'])) {
        error_log('licence issue output invalid: ' . trim((string)$stdout));
        fail(500, 'Could not issue a licence.');
    }

    db()->prepare('INSERT INTO licences (user_id, tier, customer, expiry, licence_json) VALUES (?, ?, ?, ?, ?)')
        ->execute([
            (int)$user['id'],
            $tier,
            $customer,
            $expiry,
            json_encode($lic, JSON_UNESCAPED_SLASHES),
        ]);

    return [
        'id'         => (int)db()->lastInsertId(),
        'tier'       => $tier,
        'customer'   => $customer,
        'expiry'     => $expiry,
        'licence'    => $lic,
    ];
}

// ---- dispatch --------------------------------------------------------------

$seg = route_segments($route);

// GET /api/csrf
if ($method === 'GET' && $route === '/csrf') {
    json_out(['ok' => true, 'csrf' => auth_csrf()]);
}

// GET /api/me
if ($method === 'GET' && $route === '/me') {
    $u = auth_user();
    json_out(['ok' => true, 'user' => $u === null ? null : user_public($u)]);
}

// POST /api/account — update profile (name / company name)
if ($method === 'POST' && $route === '/account') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $name = trim((string)($d['name'] ?? ''));
    $companyName = trim((string)($d['company_name'] ?? ''));

    if ($u['account_type'] === 'company') {
        if ($companyName === '' || mb_strlen($companyName) > 255) {
            fail(400, 'Company name is required for a company account.');
        }
        if ($name === '') { $name = $companyName; }
    } else {
        if ($name === '' || mb_strlen($name) > 200) {
            fail(400, 'Your name is required.');
        }
        $companyName = '';
    }

    db()->prepare('UPDATE users SET name = ?, company_name = ? WHERE id = ?')
        ->execute([$name, $companyName, $u['id']]);
    $fresh = fetch_user_by_id((int)$u['id']);
    json_out(['ok' => true, 'user' => user_public($fresh)]);
}

// POST /api/password — change own password (requires current password)
if ($method === 'POST' && $route === '/password') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $current = (string)($d['current_password'] ?? '');
    $new = (string)($d['new_password'] ?? '');

    $full = fetch_user_by_id((int)$u['id']);
    if ($full === null || !password_verify($current, (string)$full['password_hash'])) {
        fail(400, 'Current password is incorrect.');
    }
    if (strlen($new) < 8 || strlen($new) > 72) {
        fail(400, 'New password must be between 8 and 72 characters.');
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id = ? AND id <> ?')
        ->execute([$u['id'], $GLOBALS['tscrub_session']['id']]);
    json_out(['ok' => true, 'message' => 'Password updated.']);
}

// POST /api/register
if ($method === 'POST' && $route === '/register') {
    auth_csrf_verify();
    rate_limit('register', 60);
    $d = json_body();

    $email = strtolower(trim((string)($d['email'] ?? '')));
    $password = (string)($d['password'] ?? '');
    $name = trim((string)($d['name'] ?? ''));
    $accountType = (string)($d['account_type'] ?? 'personal');
    $companyName = trim((string)($d['company_name'] ?? ''));

    if (!in_array($accountType, ['personal', 'company'], true)) {
        fail(400, 'Invalid account type.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        fail(400, 'A valid email address is required.');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        fail(400, 'Password must be between 8 and 72 characters.');
    }
    if ($accountType === 'company') {
        if ($companyName === '' || mb_strlen($companyName) > 255) {
            fail(400, 'Company name is required for a company account.');
        }
        if ($name === '') {
            $name = $companyName;
        }
    } else {
        if ($name === '' || mb_strlen($name) > 200) {
            fail(400, 'Your name is required.');
        }
    }

    if (fetch_user_by_email($email) !== null) {
        fail(409, 'An account with that email already exists.');
    }

    db()->prepare('INSERT INTO users (email, password_hash, name, account_type, company_name) VALUES (?, ?, ?, ?, ?)')
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $accountType, $companyName]);

    $u = fetch_user_by_email($email);
    send_verify_email($u);

    json_out(['ok' => true, 'message' => 'Account created. Check your email to verify your address.'], 201);
}

// POST /api/login
if ($method === 'POST' && $route === '/login') {
    auth_csrf_verify();
    rate_limit('login', 5);
    $d = json_body();

    $email = strtolower(trim((string)($d['email'] ?? '')));
    $password = (string)($d['password'] ?? '');
    if ($email === '' || $password === '') {
        fail(400, 'Email and password are required.');
    }

    $u = fetch_user_by_email($email);

    if ($u !== null && $u['locked_until'] !== null && strtotime((string)$u['locked_until']) > time()) {
        fail(423, 'Too many failed attempts. Please try again later.');
    }

    $ok = $u !== null && password_verify($password, (string)$u['password_hash']);
    if (!$ok) {
        if ($u !== null) {
            $attempts = (int)$u['failed_attempts'] + 1;
            if ($attempts >= 5) {
                db()->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                    ->execute([$attempts, gmdate('Y-m-d H:i:s', time() + 15 * 60), $u['id']]);
            } else {
                db()->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, $u['id']]);
            }
        }
        fail(401, 'Invalid email or password.');
    }

    if ($u['status'] !== 'active') {
        fail(403, 'This account is suspended.');
    }

    auth_login((int)$u['id']);
    $fresh = fetch_user_by_id((int)$u['id']);
    json_out(['ok' => true, 'user' => user_public($fresh), 'email_verified' => (int)$fresh['email_verified'] === 1]);
}

// POST /api/logout
if ($method === 'POST' && $route === '/logout') {
    auth_csrf_verify();
    auth_logout();
    json_out(['ok' => true]);
}

// POST /api/verify-email
if ($method === 'POST' && $route === '/verify-email') {
    $d = json_body();
    $token = trim((string)($d['token'] ?? ''));
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        fail(400, 'Invalid verification link.');
    }
    $stmt = db()->prepare('SELECT * FROM tokens WHERE token = ? AND type = ? AND used = 0');
    $stmt->execute([$token, 'verify']);
    $t = $stmt->fetch();
    if ($t === false || strtotime((string)$t['expires_at']) <= time()) {
        fail(400, 'This verification link is invalid or has expired.');
    }
    db()->prepare('UPDATE tokens SET used = 1 WHERE token = ?')->execute([$token]);
    db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$t['user_id']]);
    json_out(['ok' => true, 'message' => 'Email verified. You can now sign in.']);
}

// POST /api/password/reset (request)
if ($method === 'POST' && $route === '/password/reset') {
    auth_csrf_verify();
    rate_limit('password-reset', 60);
    $d = json_body();
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $u = $email !== '' ? fetch_user_by_email($email) : null;
    // Always respond ok to avoid leaking which emails are registered.
    if ($u !== null && $u['status'] === 'active') {
        send_reset_email($u);
    }
    json_out(['ok' => true, 'message' => 'If that address is registered, a reset link has been sent.']);
}

// POST /api/password/reset/confirm
if ($method === 'POST' && $route === '/password/reset/confirm') {
    $d = json_body();
    $token = trim((string)($d['token'] ?? ''));
    $password = (string)($d['password'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        fail(400, 'Invalid reset link.');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        fail(400, 'Password must be between 8 and 72 characters.');
    }
    $stmt = db()->prepare('SELECT * FROM tokens WHERE token = ? AND type = ? AND used = 0');
    $stmt->execute([$token, 'reset']);
    $t = $stmt->fetch();
    if ($t === false || strtotime((string)$t['expires_at']) <= time()) {
        fail(400, 'This reset link is invalid or has expired.');
    }
    db()->prepare('UPDATE tokens SET used = 1 WHERE token = ?')->execute([$token]);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $t['user_id']]);
    db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$t['user_id']]);
    json_out(['ok' => true, 'message' => 'Password updated. Please sign in.']);
}

// GET /api/certs
if ($method === 'GET' && $route === '/certs') {
    $u = auth_require();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20;
    $offset = ($page - 1) * $per;
    $stmt = db()->prepare('SELECT COUNT(*) FROM certificates WHERE user_id = ?');
    $stmt->execute([$u['id']]);
    $total = (int)$stmt->fetchColumn();
    $stmt = db()->prepare('SELECT * FROM certificates WHERE user_id = ? ORDER BY issued_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $u['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $per, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $certs = array_map('cert_row', $stmt->fetchAll());
    json_out(['ok' => true, 'certs' => $certs, 'total' => $total, 'page' => $page, 'per' => $per]);
}

// GET /api/certs/{cert_id}
if ($method === 'GET' && count($seg) === 2 && $seg[0] === 'certs') {
    $u = auth_require();
    $c = fetch_cert_by_cert_id($seg[1]);
    if ($c === null || ((int)$c['user_id'] !== (int)$u['id'] && $u['role'] !== 'admin')) {
        fail(404, 'Certificate not found.');
    }
    $out = cert_row($c);
    $out['reports'] = load_cert_reports((int)$c['id']);
    $out['drives'] = load_cert_drives((int)$c['id']);
    json_out(['ok' => true, 'cert' => $out]);
}

// GET /api/certs/{cert_id}/download
if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'certs' && $seg[2] === 'download') {
    $u = auth_require();
    $c = fetch_cert_by_cert_id($seg[1]);
    if ($c === null || ((int)$c['user_id'] !== (int)$u['id'] && $u['role'] !== 'admin')) {
        fail(404, 'Certificate not found.');
    }
    $pdfRel = (string)($c['pdf_path'] ?? '');
    $file = __DIR__ . '/certs/' . $pdfRel;
    if ($pdfRel === '' || !is_file($file)) {
        fail(404, 'No PDF available for this certificate.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Certificate-of-Destruction-' . $c['cert_id'] . '.pdf"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

// POST /api/licence
if ($method === 'POST' && $route === '/licence') {
    auth_csrf_verify();
    $u = auth_require();
    rate_limit('licence', 30);
    $d = json_body();
    $tier = (string)($d['tier'] ?? 'free');
    if (!in_array($tier, TIERS, true)) {
        fail(400, 'Invalid tier.');
    }
    // Paid tiers are issued by admins only; the free tier stays self-serve.
    if ($tier !== 'free' && ($u['role'] ?? '') !== 'admin') {
        fail(403, 'Paid licences are issued by tScrub — please contact us.');
    }
    $lic = issue_licence($u, $tier);
    if ($tier !== 'free') {
        audit_log($u, 'issue_licence:' . $tier, (string)$u['email']);
    }
    json_out(['ok' => true, 'licence' => $lic], 201);
}

// GET /api/licences
if ($method === 'GET' && $route === '/licences') {
    $u = auth_require();
    $stmt = db()->prepare('SELECT id, tier, customer, expiry, created_at FROM licences WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $rows = array_map(fn($l) => [
        'id'         => (int)$l['id'],
        'tier'       => (string)$l['tier'],
        'customer'   => (string)$l['customer'],
        'expiry'     => (string)$l['expiry'],
        'created_at' => (string)$l['created_at'],
    ], $stmt->fetchAll());
    json_out(['ok' => true, 'licences' => $rows]);
}

// GET /api/licences/{id}/download
if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'licences' && $seg[2] === 'download') {
    $u = auth_require();
    $stmt = db()->prepare('SELECT * FROM licences WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$seg[1], (int)$u['id']]);
    $lic = $stmt->fetch();
    if ($lic === false) {
        fail(404, 'Licence not found.');
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$lic['customer']));
    $slug = trim($slug === null ? '' : $slug, '-') ?: 'tscrub';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $slug . '-' . $lic['expiry'] . '.lic"');
    echo $lic['licence_json'];
    exit;
}

// POST /api/reports (machine ingestion — API-token authenticated, no PDF)
if ($method === 'POST' && $route === '/reports') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'A valid API token is required.');
    }
    $stmt = db()->prepare('SELECT * FROM api_tokens WHERE token = ?');
    $stmt->execute([$token]);
    $tok = $stmt->fetch();
    if ($tok === false) {
        fail(401, 'Invalid API token.');
    }
    $owner = fetch_user_by_id((int)$tok['user_id']);
    if ($owner === null || $owner['status'] !== 'active') {
        fail(403, 'Account inactive.');
    }
    db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$tok['id']]);

    if (empty($_FILES['reports'])) {
        fail(400, 'Upload one or more tScrub report files (.csv).');
    }
    $groups = parse_reports($_FILES['reports']);
    if (!$groups) {
        fail(400, 'No valid report data found.');
    }

    $entries = [];
    foreach ($groups as $g) {
        $methods = [];
        foreach ($g['drives'] as $d) { $methods[trim((string)$d['method'])] = true; }
        $entries[] = [
            'cert'      => gen_cert_id(),
            'cocid'     => (string)$g['cocid'],
            'devices'   => count($g['drives']),
            'methods'   => count($methods),
            'runs'      => count($g['reports']),
            'first'     => $g['first'],
            'last'      => $g['last'],
            'sha_state' => $g['shaState'],
            'sig_state' => $g['sigState'],
            'reports'   => $g['reports'],
            'drives'    => $g['drives'],
        ];
    }

    $issuedAt = gmdate('Y-m-d H:i:s');
    try {
        db()->beginTransaction();
        insert_certificate_records($entries, (int)$owner['id'], '', '', $issuedAt);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('api reports db error: ' . $e->getMessage());
        fail(500, 'Could not store the report.');
    }

    json_out(['ok' => true, 'certificates' => array_column($entries, 'cert'), 'count' => count($entries)], 201);
}

// GET /api/tokens
if ($method === 'GET' && $route === '/tokens') {
    $u = auth_require();
    $stmt = db()->prepare('SELECT id, label, created_at, last_used_at, token FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $rows = array_map(function ($t) {
        return [
            'id'          => (int)$t['id'],
            'label'       => (string)$t['label'],
            'created_at'  => (string)$t['created_at'],
            'last_used_at' => $t['last_used_at'],
            'token'       => substr((string)$t['token'], -8),
        ];
    }, $stmt->fetchAll());
    json_out(['ok' => true, 'tokens' => $rows]);
}

// POST /api/tokens
if ($method === 'POST' && $route === '/tokens') {
    auth_csrf_verify();
    $u = auth_require();
    $label = trim((string)(json_body()['label'] ?? ''));
    if (mb_strlen($label) > 100) { $label = substr($label, 0, 100); }
    $token = auth_token();
    db()->prepare('INSERT INTO api_tokens (user_id, token, label) VALUES (?, ?, ?)')->execute([$u['id'], $token, $label]);
    json_out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'token' => $token], 201);
}

// DELETE /api/tokens/{id}
if ($method === 'DELETE' && count($seg) === 2 && $seg[0] === 'tokens') {
    auth_csrf_verify();
    $u = auth_require();
    db()->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')->execute([(int)$seg[1], $u['id']]);
    json_out(['ok' => true]);
}

// ---- admin ----------------------------------------------------------------

// GET /api/admin/stats
if ($method === 'GET' && $route === '/admin/stats') {
    auth_require_admin();
    $stats = [
        'users'         => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'companies'     => (int)db()->query("SELECT COUNT(*) FROM users WHERE account_type = 'company'")->fetchColumn(),
        'certificates'  => (int)db()->query('SELECT COUNT(*) FROM certificates')->fetchColumn(),
        'licences'      => (int)db()->query('SELECT COUNT(*) FROM licences')->fetchColumn(),
        'unassigned'    => (int)db()->query('SELECT COUNT(*) FROM certificates WHERE user_id IS NULL')->fetchColumn(),
    ];
    json_out(['ok' => true, 'stats' => $stats]);
}

// GET /api/admin/users
if ($method === 'GET' && $route === '/admin/users') {
    auth_require_admin();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 25;
    $offset = ($page - 1) * $per;
    $total = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stmt = db()->prepare('SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $users = array_map('user_public', $stmt->fetchAll());
    // fetch counts in bulk to avoid N+1
    $cnt = db()->query('SELECT user_id, COUNT(*) n FROM certificates WHERE user_id IS NOT NULL GROUP BY user_id')->fetchAll();
    $map = [];
    foreach ($cnt as $r) { $map[(int)$r['user_id']] = (int)$r['n']; }
    foreach ($users as &$row) { $row['certificates'] = $map[$row['id']] ?? 0; }
    unset($row);
    $lcnt = db()->query('SELECT user_id, COUNT(*) n FROM licences GROUP BY user_id')->fetchAll();
    $lmap = [];
    foreach ($lcnt as $r) { $lmap[(int)$r['user_id']] = (int)$r['n']; }
    foreach ($users as &$row) { $row['licences'] = $lmap[$row['id']] ?? 0; }
    unset($row);
    json_out(['ok' => true, 'users' => $users, 'total' => $total, 'page' => $page, 'per' => $per]);
}

// GET /api/admin/users/{id}
if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'admin' && $seg[1] === 'users') {
    auth_require_admin();
    $u = fetch_user_by_id((int)$seg[2]);
    if ($u === null) {
        fail(404, 'User not found.');
    }
    $row = user_public($u);
    $stmt = db()->prepare('SELECT * FROM certificates WHERE user_id = ? ORDER BY issued_at DESC');
    $stmt->execute([$u['id']]);
    $row['certificates'] = array_map('cert_row', $stmt->fetchAll());
    $stmt = db()->prepare('SELECT id, tier, customer, expiry, created_at FROM licences WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $row['licences'] = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT id, ip, user_agent, created_at, expires_at FROM sessions WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $row['sessions'] = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT id, label, created_at, last_used_at, token FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $row['tokens'] = array_map(fn($t) => [
        'id'          => (int)$t['id'],
        'label'       => (string)$t['label'],
        'created_at'  => (string)$t['created_at'],
        'last_used_at' => $t['last_used_at'],
        'token'       => substr((string)$t['token'], -8),
    ], $stmt->fetchAll());
    json_out(['ok' => true, 'user' => $row]);
}

// POST /api/admin/users/{id}/role
if ($method === 'POST' && count($seg) === 4 && $seg[0] === 'admin' && $seg[1] === 'users' && $seg[3] === 'role') {
    $admin = auth_require_admin();
    $target = fetch_user_by_id((int)$seg[2]);
    if ($target === null) {
        fail(404, 'User not found.');
    }
    $role = (string)(json_body()['role'] ?? '');
    if (!in_array($role, ['user', 'admin'], true)) {
        fail(400, 'Invalid role.');
    }
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $target['id']]);
    audit_log($admin, 'set_role:' . $role, (string)$target['email']);
    json_out(['ok' => true]);
}

// POST /api/admin/users/{id}/status
if ($method === 'POST' && count($seg) === 4 && $seg[0] === 'admin' && $seg[1] === 'users' && $seg[3] === 'status') {
    $admin = auth_require_admin();
    $target = fetch_user_by_id((int)$seg[2]);
    if ($target === null) {
        fail(404, 'User not found.');
    }
    $status = (string)(json_body()['status'] ?? '');
    if (!in_array($status, ['active', 'suspended'], true)) {
        fail(400, 'Invalid status.');
    }
    db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $target['id']]);
    if ($status === 'suspended') {
        db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$target['id']]);
    }
    audit_log($admin, 'set_status:' . $status, (string)$target['email']);
    json_out(['ok' => true]);
}

// POST /api/admin/users/{id}/sessions/revoke
if ($method === 'POST' && count($seg) === 5 && $seg[0] === 'admin' && $seg[1] === 'users' && $seg[3] === 'sessions' && $seg[4] === 'revoke') {
    $admin = auth_require_admin();
    $target = fetch_user_by_id((int)$seg[2]);
    if ($target === null) {
        fail(404, 'User not found.');
    }
    db()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$target['id']]);
    audit_log($admin, 'revoke_sessions', (string)$target['email']);
    json_out(['ok' => true]);
}

// GET /api/admin/certificates
if ($method === 'GET' && $route === '/admin/certificates') {
    auth_require_admin();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 25;
    $offset = ($page - 1) * $per;
    $total = (int)db()->query('SELECT COUNT(*) FROM certificates')->fetchColumn();
    $stmt = db()->prepare(
        'SELECT c.*, u.email AS owner_email FROM certificates c
         LEFT JOIN users u ON u.id = c.user_id
         ORDER BY c.issued_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $certs = [];
    foreach ($stmt->fetchAll() as $c) {
        $row = cert_row($c);
        $row['owner_email'] = $c['owner_email'] ?? null;
        $certs[] = $row;
    }
    json_out(['ok' => true, 'certs' => $certs, 'total' => $total, 'page' => $page, 'per' => $per]);
}

// GET /api/admin/licences — all licences with their owner
if ($method === 'GET' && $route === '/admin/licences') {
    auth_require_admin();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 25;
    $offset = ($page - 1) * $per;
    $total = (int)db()->query('SELECT COUNT(*) FROM licences')->fetchColumn();
    $stmt = db()->prepare(
        'SELECT l.id, l.tier, l.customer, l.expiry, l.created_at, u.email AS owner_email
         FROM licences l JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $licences = array_map(fn($l) => [
        'id'          => (int)$l['id'],
        'tier'        => (string)$l['tier'],
        'customer'    => (string)$l['customer'],
        'expiry'      => (string)$l['expiry'],
        'created_at'  => (string)$l['created_at'],
        'owner_email' => (string)($l['owner_email'] ?? ''),
    ], $stmt->fetchAll());
    json_out(['ok' => true, 'licences' => $licences, 'total' => $total, 'page' => $page, 'per' => $per]);
}

// POST /api/admin/users/{id}/licence — issue a licence to a specific user
if ($method === 'POST' && count($seg) === 4 && $seg[0] === 'admin' && $seg[1] === 'users' && $seg[3] === 'licence') {
    $admin = auth_require_admin();
    $target = fetch_user_by_id((int)$seg[2]);
    if ($target === null) {
        fail(404, 'User not found.');
    }
    $tier = (string)(json_body()['tier'] ?? '');
    if (!in_array($tier, TIERS, true)) {
        fail(400, 'Invalid tier.');
    }
    $lic = issue_licence($target, $tier);
    audit_log($admin, 'issue_licence:' . $tier, (string)$target['email']);
    json_out(['ok' => true, 'licence' => $lic], 201);
}

// GET /api/admin/audit — admin actions log
if ($method === 'GET' && $route === '/admin/audit') {
    auth_require_admin();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;
    $offset = ($page - 1) * $per;
    $total = (int)db()->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
    $stmt = db()->prepare(
        'SELECT a.action, a.target, a.ip, a.created_at, u.email AS admin_email
         FROM admin_audit_log a JOIN users u ON u.id = a.admin_id
         ORDER BY a.id DESC LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_map(fn($a) => [
        'admin_email' => (string)($a['admin_email'] ?? ''),
        'action'      => (string)$a['action'],
        'target'      => (string)$a['target'],
        'ip'          => (string)$a['ip'],
        'created_at'  => (string)$a['created_at'],
    ], $stmt->fetchAll());
    json_out(['ok' => true, 'audit' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
}

fail(404, 'Not found.');
