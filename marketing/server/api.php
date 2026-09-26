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
 *   POST /api/certs                   (generate a consolidated certificate from stored reports)
 *   POST /api/reports                 (upload reports — session or API-token auth; stores only)
 *   GET  /api/reports                 (current user's uploaded reports)
 *   GET  /api/reports/cocids          (distinct COCIDs for the cert generator)
 *   POST /api/licence                 (issue a licence for the current user)
 *   GET  /api/licences                (current user's licences)
 *   GET  /api/licences/{id}/download  (download a .lic file)
 *   POST /api/checkout                (create a Stripe Checkout session for a device pack)
 *   GET  /api/credits                 (device-credit balance + history)
 *   POST /api/stripe/webhook          (Stripe events — signature-verified)
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
require_once __DIR__ . '/stripe.php';

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

/** Most recent licence tier for a user (free when none). */
function owner_tier(int $userId): string {
    $stmt = db()->prepare('SELECT tier FROM licences WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $t = $stmt->fetch();
    return ($t !== false && isset($t['tier']) && $t['tier'] !== null) ? (string)$t['tier'] : 'free';
}

/**
 * Build the certificate's certifier block from the user's profile. The company
 * name (when set) is the certifying party, falling back to the individual's
 * name; registration/address/phone appear whenever entered. tScrub is named
 * separately on the certificate as the tool provider, not the certifier.
 */
function certifier_details(array $u): array {
    $name = (string)($u['company_name'] ?? '');
    if ($name === '') { $name = (string)($u['name'] ?? ''); }
    if ($name === '') { $name = (string)($u['email'] ?? ''); }

    $reg = (($u['company_reg'] ?? '') !== '') ? 'Company No. ' . $u['company_reg'] : '';

    $addr = [];
    if (($u['addr_line1'] ?? '') !== '') { $addr[] = $u['addr_line1']; }
    if (($u['addr_line2'] ?? '') !== '') { $addr[] = $u['addr_line2']; }
    $cityLine = trim(implode(' ', array_filter([($u['city'] ?? ''), ($u['postcode'] ?? '')])));
    if ($cityLine !== '') { $addr[] = $cityLine; }
    if (($u['country'] ?? '') !== '') { $addr[] = $u['country']; }

    return [
        'name'  => $name,
        'reg'   => $reg,
        'addr'  => implode(', ', $addr),
        'phone' => (string)($u['phone'] ?? ''),
    ];
}

/**
 * Upsert a consolidated certificate from a parsed report group (one COCID).
 * Merges into the existing certificate when one already exists for that COCID.
 * Returns the certificate row (cert_row shape).
 */
function generate_certificate(array $g, int $userId, bool $canSign, array $destroyed = []): array {
    require_once __DIR__ . '/render_cert.php';
    $certifier = certifier_details(fetch_user_by_id($userId) ?? []);
    $pdfDir = __DIR__ . '/certs';
    if (!is_dir($pdfDir)) { @mkdir($pdfDir, 0775, true); }

    $cocid = (string)$g['cocid'];
    $stmt = db()->prepare('SELECT * FROM certificates WHERE user_id = ? AND cocid = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId, $cocid]);
    $existing = $stmt->fetch();

    if ($existing !== false) {
        // One COCID = one consolidated cert: merge and re-render the same cert.
        $merged = merge_group($g, load_cert_drives((int)$existing['id']), load_cert_reports((int)$existing['id']), $existing);
        $certId = (string)$existing['cert_id'];
        $rendered = render_certificate_pdf($merged, $certId, $canSign, $certifier, $destroyed);
        $groupPath = $certId . '.pdf';
        $written = $pdfDir . '/' . $groupPath;
        @file_put_contents($written, $rendered['data']);

        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'UPDATE certificates SET devices = ?, methods = ?, runs = ?, first_ts = ?, last_ts = ?, sha_state = ?, sig_state = ?, pdf_sha256 = ?, pdf_path = ?, issued_at = ? WHERE id = ?'
            );
            $stmt->execute([
                (int)$rendered['devices'],
                (int)$rendered['methods'],
                (int)$rendered['runs'],
                $merged['first'] !== null ? (string)$merged['first'] : null,
                $merged['last'] !== null ? (string)$merged['last'] : null,
                (string)$merged['shaState'],
                (string)$merged['sigState'],
                (string)$rendered['sha'],
                $groupPath,
                gmdate('Y-m-d H:i:s'),
                (int)$existing['id'],
            ]);
            rewrite_certificate_details((int)$existing['id'], $merged['reports'], $rendered['drives']);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            @unlink($written);
            error_log('generate_certificate db error: ' . $e->getMessage());
            fail(500, 'Could not generate the certificate.');
        }
    } else {
        $certId = gen_cert_id();
        $rendered = render_certificate_pdf($g, $certId, $canSign, $certifier, $destroyed);
        $groupPath = $certId . '.pdf';
        $written = $pdfDir . '/' . $groupPath;
        @file_put_contents($written, $rendered['data']);

        $entry = [
            'cert'      => $certId,
            'cocid'     => $cocid,
            'devices'   => $rendered['devices'],
            'methods'   => $rendered['methods'],
            'runs'      => $rendered['runs'],
            'first'     => $g['first'],
            'last'      => $g['last'],
            'sha_state' => $g['shaState'],
            'sig_state' => $g['sigState'],
            'reports'   => $g['reports'],
            'drives'    => $rendered['drives'],
            'pdf_sha'   => $rendered['sha'],
            'pdf_path'  => $groupPath,
        ];
        try {
            db()->beginTransaction();
            insert_certificate_records([$entry], $userId, '', '', gmdate('Y-m-d H:i:s'));
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            @unlink($written);
            error_log('generate_certificate db error: ' . $e->getMessage());
            fail(500, 'Could not generate the certificate.');
        }
    }

    $cert = fetch_cert_by_cert_id($certId);
    return cert_row($cert);
}

function issue_licence(array $user, string $tier): array {
    $py = '/usr/bin/python3';
    $script = __DIR__ . '/issue_licence.py';

    $customer = (($user['company_name'] ?? '') !== '')
        ? (string)$user['company_name']
        : ((($user['name'] ?? '') !== '') ? (string)$user['name'] : (string)$user['email']);
    $expiry = gmdate('Y-m-d', time() + 365 * 24 * 3600);

    // `--` stops argparse treating a customer name beginning with `-` as an option.
    $cmd = [$py, $script, '--tier', $tier, '--json', '--', $customer, $expiry];
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

    // Derive the report key's public half so uploaded reports can be bound to
    // this licence (attribution). Stored as base64 DER SPKI; NULL-able for free.
    $pubKey = '';
    if (!empty($lic['key'])) {
        $tmp = tempnam(sys_get_temp_dir(), 'tscrub-lic-');
        file_put_contents($tmp, base64_decode((string)$lic['key']));
        $r = run_cmd(['openssl', 'pkey', '-in', $tmp, '-pubout', '-outform', 'DER']);
        @unlink($tmp);
        if ($r !== null && $r[0] === 0) {
            $pubKey = base64_encode((string)$r[1]);
        }
    }

    db()->prepare('INSERT INTO licences (user_id, tier, customer, expiry, licence_json, pub_key) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            (int)$user['id'],
            $tier,
            $customer,
            $expiry,
            json_encode($lic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $pubKey,
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

// POST /api/account — update profile (name / company details)
if ($method === 'POST' && $route === '/account') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $name = trim((string)($d['name'] ?? ''));
    $companyName = trim((string)($d['company_name'] ?? ''));

    $profile = [
        'company_reg' => trim((string)($d['company_reg'] ?? '')),
        'addr_line1'  => trim((string)($d['addr_line1'] ?? '')),
        'addr_line2'  => trim((string)($d['addr_line2'] ?? '')),
        'city'        => trim((string)($d['city'] ?? '')),
        'postcode'    => trim((string)($d['postcode'] ?? '')),
        'country'     => trim((string)($d['country'] ?? '')),
        'phone'       => trim((string)($d['phone'] ?? '')),
    ];
    $max = ['company_reg' => 64, 'addr_line1' => 255, 'addr_line2' => 255, 'city' => 100, 'postcode' => 20, 'country' => 100, 'phone' => 50];
    foreach ($profile as $k => $v) {
        if (mb_strlen($v) > $max[$k]) {
            fail(400, 'Company details too long.');
        }
    }

    if ($name === '' || mb_strlen($name) > 200) {
        fail(400, 'Your name is required.');
    }
    if (mb_strlen($companyName) > 255) {
        fail(400, 'Company name must be 255 characters or fewer.');
    }

    db()->prepare('UPDATE users SET name = ?, company_name = ?, company_reg = ?, addr_line1 = ?, addr_line2 = ?, city = ?, postcode = ?, country = ?, phone = ? WHERE id = ?')
        ->execute([$name, $companyName, $profile['company_reg'], $profile['addr_line1'], $profile['addr_line2'], $profile['city'], $profile['postcode'], $profile['country'], $profile['phone'], $u['id']]);
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
        if (mb_strlen($name) > 200) {
            fail(400, 'Name must be 200 characters or fewer.');
        }
    } else {
        if ($name === '' || mb_strlen($name) > 200) {
            fail(400, 'Your name is required.');
        }
    }

    if (fetch_user_by_email($email) !== null) {
        fail(409, 'An account with that email already exists.');
    }

    try {
        db()->prepare('INSERT INTO users (email, password_hash, name, account_type, company_name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $accountType, $companyName]);
    } catch (Throwable $e) {
        // Concurrent registration race on the unique email key -> 409, not 500.
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            fail(409, 'An account with that email already exists.');
        }
        throw $e;
    }

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

    // Always run bcrypt so the response time does not reveal whether the
    // email address exists (account-enumeration timing).
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $ok = false;
    if ($u !== null) {
        $ok = password_verify($password, (string)$u['password_hash']);
    } else {
        password_verify($password, $dummyHash);
    }
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

    // Email verification is enforced before issuing a session.
    if ((int)$u['email_verified'] !== 1) {
        json_out(['ok' => false, 'error' => 'Please verify your email address before signing in.', 'verify_required' => true], 403);
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
    db()->prepare('UPDATE tokens SET used = 1 WHERE token = ? AND used = 0')->execute([$token]);
    db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$t['user_id']]);
    json_out(['ok' => true, 'message' => 'Email verified. You can now sign in.']);
}

// POST /api/verify-email/resend — resend the verification link for an
// unverified account (shown after a blocked login).
if ($method === 'POST' && $route === '/verify-email/resend') {
    auth_csrf_verify();
    rate_limit('verify-resend', 60);
    $d = json_body();
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $u = $email !== '' ? fetch_user_by_email($email) : null;
    if ($u !== null && (int)$u['email_verified'] !== 1 && $u['status'] === 'active') {
        send_verify_email($u);
    } else {
        // Fixed delay on the no-op path so timing does not reveal whether the
        // address exists-and-is-unverified (mirrors /api/password/reset).
        usleep(300000);
    }
    json_out(['ok' => true, 'message' => 'If that address is registered and unverified, a new link has been sent.']);
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
    } else {
        // Fixed delay on the no-op path so response timing does not reveal
        // whether the address is registered.
        usleep(300000);
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
    db()->prepare('UPDATE tokens SET used = 1 WHERE token = ? AND used = 0')->execute([$token]);
    db()->prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $t['user_id']]);
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
    $pdfRel = basename((string)($c['pdf_path'] ?? ''));
    $file = __DIR__ . '/certs/' . $pdfRel;
    if ($pdfRel === '' || $pdfRel === '.' || $pdfRel === '..'
        || !preg_match('/^[A-Za-z0-9._-]+\.pdf$/', $pdfRel) || !is_file($file)) {
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

// POST /api/reports — upload report files. Session-authenticated for manual
// dashboard uploads, API-token-authenticated for machine ingestion. Stores the
// parsed reports as raw evidence only; certificates are generated separately
// via POST /api/certs.
if ($method === 'POST' && $route === '/reports') {
    $source = 'manual';
    $owner = null;

    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1) {
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
        $source = 'api';
    } else {
        auth_csrf_verify();
        $owner = auth_require();
    }
    rate_limit('reports', 10);

    if (empty($_FILES['reports'])) {
        fail(400, 'Upload one or more tScrub report files (.csv).');
    }
    $ingested = user_ingested_shas((int)$owner['id']);
    $stats = ['uploaded' => 0, 'skipped' => 0];
    $groups = parse_reports($_FILES['reports'], licence_pub_keys((int)$owner['id']), $ingested, $stats);
    if (!$groups && $stats['skipped'] === 0) {
        fail(400, 'No valid report data found.');
    }

    $ids = $groups ? store_reports($groups, (int)$owner['id'], $source) : [];

    // Per-device billing: every paid tier spends one credit per newly ingested
    // drive (free is exempt). Best-effort and idempotent — a shortfall is
    // reported in the response, never a block on evidence collection.
    $billed = 0;
    $short = 0;
    if (owner_tier((int)$owner['id']) !== 'free') {
        foreach (($stats['debits'] ?? []) as $deb) {
            $r = credit_debit((int)$owner['id'], (int)$deb['drives'], 'report:' . $deb['sha']);
            if ($r === -1) {
                $short += (int)$deb['drives'];
            } else {
                $billed += (int)$deb['drives'];
            }
        }
    }

    json_out([
        'ok' => true,
        'reports' => $ids,
        'count' => count($ids),
        'uploaded' => $stats['uploaded'],
        'skipped' => $stats['skipped'],
        'credits' => ['billed' => $billed, 'short' => $short],
    ], 201);
}

// GET /api/reports — the current user's uploaded reports (raw evidence).
// Optional `q` searches drive/system serials and other stored data.
if ($method === 'GET' && $route === '/reports') {
    $u = auth_require();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $cocid = (string)($_GET['cocid'] ?? '');
    $q = trim((string)($_GET['q'] ?? ''));
    $res = load_user_reports((int)$u['id'], $cocid !== '' ? $cocid : null, $q !== '' ? $q : null, $page, 10);
    json_out(['ok' => true, 'reports' => $res['reports'], 'total' => $res['total'], 'page' => $res['page'], 'per' => $res['per'], 'q' => $q]);
}

// GET /api/reports/cocids — distinct COCIDs for the cert generator's search/select.
if ($method === 'GET' && count($seg) === 2 && $seg[0] === 'reports' && $seg[1] === 'cocids') {
    $u = auth_require();
    json_out(['ok' => true, 'cocids' => distinct_cocids((int)$u['id'])]);
}

// GET /api/signing-key — vendor public key + fingerprint (authenticated only,
// never published in the static HTML).
if ($method === 'GET' && $route === '/signing-key') {
    auth_require();
    json_out([
        'ok' => true,
        'pem' => "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAbBDdsD4wQh7aoBRe890V8LcTOZNe6n6Cvh0AkrBA4B4=\n-----END PUBLIC KEY-----",
        'fingerprint' => 'be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02',
    ]);
}

// POST /api/checkout — create a Stripe PaymentIntent for the on-page Payment
// Element. Returns the client secret + publishable key so the browser can collect
// payment without leaving the dashboard. Amount/currency live on the Stripe Price
// (single source of truth). automatic_payment_methods surfaces every method
// enabled on the account (rendered as compact tabs, so the modal stays short),
// while Apple Pay / Google Pay still render as express-wallet buttons.
if ($method === 'POST' && $route === '/checkout') {
    auth_csrf_verify();
    $u = auth_require();
    if (!stripe_configured()) {
        fail(503, 'Payments are not configured yet.');
    }
    $pack = (string)(json_body()['pack'] ?? '');
    $price = stripe_settings()['prices'][$pack] ?? null;
    if (!is_array($price) || empty($price['price_id'])) {
        fail(400, 'Unknown device pack.');
    }
    $p = stripe_request('GET', 'prices/' . (string)$price['price_id']);
    $amount = (int)($p['unit_amount'] ?? 0);
    $currency = (string)($p['currency'] ?? 'gbp');
    if ($amount <= 0) {
        fail(502, 'Could not determine the pack price.');
    }
    $pi = stripe_request('POST', 'payment_intents', [
        'amount'                             => $amount,
        'currency'                           => $currency,
        'automatic_payment_methods[enabled]' => 'true',
        'metadata[user_id]'                  => (string)$u['id'],
        'metadata[units]'                    => (string)($price['units'] ?? 0),
        'metadata[pack]'                     => $pack,
    ]);
    if (empty($pi['client_secret'])) {
        fail(502, 'Could not start payment.');
    }
    json_out([
        'ok' => true,
        'client_secret'  => $pi['client_secret'],
        'publishable_key' => (string)(stripe_settings()['publishable_key'] ?? ''),
    ]);
}

// GET /api/stripe/publishable-key — public key for the browser SDK (safe to expose).
if ($method === 'GET' && $route === '/stripe/publishable-key') {
    json_out(['ok' => true, 'publishable_key' => (string)(stripe_settings()['publishable_key'] ?? '')]);
}

// GET /api/credits — device-credit balance + recent events.
if ($method === 'GET' && $route === '/credits') {
    $u = auth_require();
    $stmt = db()->prepare('SELECT type, units, ref, created_at FROM credit_events WHERE user_id = ? ORDER BY id DESC LIMIT 20');
    $stmt->execute([(int)$u['id']]);
    $events = array_map(static function (array $ev): array {
        $ev['created_at'] = ts_local((string)($ev['created_at'] ?? ''));
        return $ev;
    }, $stmt->fetchAll());
    json_out(['ok' => true, 'balance' => credit_balance((int)$u['id']), 'events' => $events]);
}

// POST /api/stripe/webhook — Stripe event delivery (signature-verified, idempotent).
if ($method === 'POST' && $route === '/stripe/webhook') {
    $payload = file_get_contents('php://input');
    $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!stripe_configured() || !stripe_verify_webhook((string)$payload, (string)$sig)) {
        fail(400, 'Invalid signature.');
    }
    $event = json_decode((string)$payload, true);
    if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
        fail(400, 'Invalid event.');
    }
    if (!stripe_event_seen((string)$event['id'])) {
        json_out(['ok' => true, 'handled' => false]); // duplicate delivery — acknowledge
    }

    $obj = $event['data']['object'] ?? [];
    // Embedded (checkout.session.completed, payment mode) and legacy
    // (payment_intent.succeeded) purchases both credit the wallet and auto-issue a
    // payg licence on the customer's first purchase. A checkout session can fire
    // before payment succeeds, so gate on payment_status === 'paid'.
    $isPaid = ($event['type'] === 'payment_intent.succeeded')
        || ($event['type'] === 'checkout.session.completed' && ($obj['payment_status'] ?? '') === 'paid');
    if ($isPaid) {
        $userId = (int)($obj['metadata']['user_id'] ?? 0);
        if ($userId > 0) {
            $user = fetch_user_by_id($userId);
            if ($user !== null && $user['status'] === 'active') {
                $units = (int)($obj['metadata']['units'] ?? 0);
                if ($units > 0) {
                    credit_apply($userId, 'credit', $units, 'stripe:' . (string)$obj['id']);
                }
                // A paid (payg) licence is required for attributable reports.
                if (owner_tier($userId) === 'free') {
                    issue_licence($user, 'payg');
                }
            }
        }
    }

    json_out(['ok' => true, 'handled' => true]);
}

// POST /api/certs — generate a consolidated certificate for a COCID from stored reports.
if ($method === 'POST' && $route === '/certs') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $cocid = trim((string)($d['cocid'] ?? ''));
    if ($cocid === '' || mb_strlen($cocid, 'UTF-8') > 64) {
        fail(400, 'Provide a valid Chain of Custody ID.');
    }

    $payloads = load_report_payloads((int)$u['id'], $cocid);
    if (!$payloads) {
        fail(404, 'No reports found for that Chain of Custody ID. Upload reports first.');
    }

    // Fold every stored report group for this COCID into one consolidated group.
    $g = null;
    foreach ($payloads as $grp) {
        if ($g === null) {
            $g = $grp;
            continue;
        }
        $g = merge_group($grp, $g['drives'], $g['reports'], [
            'first_ts'  => $g['first'] ?? null,
            'last_ts'   => $g['last'] ?? null,
            'sha_state' => $g['shaState'] ?? 'unverified',
            'sig_state' => $g['sigState'] ?? 'none',
        ]);
    }

    // Physical-destruction decision. Drives that didn't complete (FAILED /
    // BLOCKED / FROZEN) can't be claimed as wiped. When the operator hasn't
    // supplied a decision, return the uncompleted drives so the dashboard can
    // ask; otherwise validate the choices and bake them into the certificate.
    $nonCompleted = [];
    foreach ($g['drives'] as $drv) {
        $st = strtoupper(trim((string)($drv['status'] ?? '')));
        if ($st === 'COMPLETED' || $st === 'DRY-RUN' || $st === 'DESTROYED') continue;
        $nonCompleted[] = [
            'serial' => (string)($drv['serial'] ?? ''),
            'model'  => (string)($drv['model'] ?? ''),
            'device' => (string)($drv['device'] ?? ''),
            'status' => (string)($drv['status'] ?? ''),
        ];
    }

    $destroyed = [];
    if (!array_key_exists('destroyed', $d)) {
        if ($nonCompleted !== []) {
            json_out(['ok' => true, 'decision_required' => true, 'drives' => $nonCompleted], 200);
        }
    } else {
        if (!is_array($d['destroyed'])) {
            fail(400, '"destroyed" must be an array of drive serials.');
        }
        $allowed = [];
        foreach ($nonCompleted as $n) { $allowed[strtolower(trim($n['serial']))] = true; }
        foreach ($d['destroyed'] as $s) {
            $s = trim((string)$s);
            if ($s === '') continue;
            if (!isset($allowed[strtolower($s)])) {
                fail(400, 'Drive "' . $s . '" is not an uncompleted drive in this Chain of Custody ID.');
            }
            $destroyed[] = $s;
        }
    }

    // Only the actual PDF generation is rate-limited; the decision check above
    // is cheap and must not consume the slot (it would otherwise block the
    // follow-up POST that carries the confirmed destruction choices).
    rate_limit('certs', 30);
    $out = generate_certificate($g, (int)$u['id'], owner_tier((int)$u['id']) !== 'free', $destroyed);
    json_out(['ok' => true, 'cert' => $out], 201);
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
    if (mb_strlen($label) > 100) { $label = mb_substr($label, 0, 100, 'UTF-8'); }
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
    foreach ($users as &$row) { $row['credits'] = credit_balance((int)$row['id']); }
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
    $row['credits'] = credit_balance((int)$u['id']);
    $stmt = db()->prepare('SELECT * FROM certificates WHERE user_id = ? ORDER BY issued_at DESC');
    $stmt->execute([$u['id']]);
    $row['certificates'] = array_map('cert_row', $stmt->fetchAll());
    $stmt = db()->prepare('SELECT id, tier, customer, expiry, created_at FROM licences WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $row['licences'] = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT id, ip, user_agent, created_at, expires_at FROM sessions WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$u['id']]);
    $row['sessions'] = array_map(fn($s) => [
        'id'          => substr((string)$s['id'], 0, 8) . '…',
        'ip'          => (string)$s['ip'],
        'user_agent'  => (string)$s['user_agent'],
        'created_at'  => (string)$s['created_at'],
        'expires_at'  => (string)$s['expires_at'],
    ], $stmt->fetchAll());
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
    auth_csrf_verify();
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
    auth_csrf_verify();
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
    auth_csrf_verify();
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
    auth_csrf_verify();
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

// POST /api/admin/users/{id}/credits — grant device credits to a user
if ($method === 'POST' && count($seg) === 4 && $seg[0] === 'admin' && $seg[1] === 'users' && $seg[3] === 'credits') {
    auth_csrf_verify();
    $admin = auth_require_admin();
    $target = fetch_user_by_id((int)$seg[2]);
    if ($target === null) {
        fail(404, 'User not found.');
    }
    $units = (int)(json_body()['units'] ?? 0);
    if ($units <= 0 || $units > 100000) {
        fail(400, 'Provide a credit amount between 1 and 100,000.');
    }
    credit_apply((int)$target['id'], 'credit', $units, 'admin:' . $admin['id'] . ':' . bin2hex(random_bytes(6)));
    // Credits only debit on paid tiers, so a free user who receives credits is
    // upgraded to payg — otherwise the grant would sit unused forever.
    $upgraded = false;
    if (owner_tier((int)$target['id']) === 'free') {
        issue_licence($target, 'payg');
        $upgraded = true;
    }
    audit_log($admin, 'grant_credits:' . $units . ($upgraded ? '+payg' : ''), (string)$target['email']);
    json_out(['ok' => true, 'balance' => credit_balance((int)$target['id']), 'tier' => owner_tier((int)$target['id']), 'upgraded' => $upgraded]);
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
