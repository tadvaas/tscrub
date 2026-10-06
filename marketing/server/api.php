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
require_once __DIR__ . '/mdm.php';
require_once __DIR__ . '/bios_unlock.php';
require_once __DIR__ . '/remote.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/certifier.php';
require_once __DIR__ . '/jsonld.php';

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
        'has_json'   => (($c['json_path'] ?? '') !== ''),
        'json_url'   => '/verify?cert=' . urlencode((string)$c['cert_id']) . '&format=jsonld',
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
                smart, tempc, poweronhours, powercycles, reallocsectors, pctused, availspare, tbw_tb, smartpost, tempcpost, poweronhourspost,
                firmware, sector_size, sectors, hpa, dco, sed_status, reallocsectorspost, selftest, start_time, end_time, duration_secs,
                tool_version, operator, validator, media_source, media_destination
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

/** Most recent licence tier for a user (free when none). Org-aware. */
function owner_tier(int $userId): string {
    return org_tier($userId);
}

/**
 * Upsert a consolidated certificate from a parsed report group (one COCID).
 * Merges into the existing certificate when one already exists for that COCID.
 * Returns the certificate row (cert_row shape).
 */
function generate_certificate(array $g, int $userId, bool $canSign, array $destroyed = []): array {
    require_once __DIR__ . '/render_cert.php';
    certs_ensure_schema();
    $certifier = certifier_for_user($userId);

    // Operator strategy: the report's own operator when present, otherwise the
    // account holder who generated the certificate (name, else email).
    $g['operator'] = operator_for_pdf($g, $userId);

    $pdfDir = __DIR__ . '/certs';
    if (!is_dir($pdfDir)) { @mkdir($pdfDir, 0775, true); }

    $cocid = (string)$g['cocid'];
    $issuedAt = gmdate('Y-m-d H:i:s');
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM certificates WHERE user_id IN ($ph) AND cocid = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(array_merge($ids, [$cocid]));
    $existing = $stmt->fetch();

    // Build + sign + write the machine-readable JSON-LD beside the PDF, from
    // the same rendered data. Returns ['path', 'sha', 'ts'] — path is '' when
    // signing is unavailable, which must never block PDF issuance.
    $writeJson = function (array $rendered, array $grp, string $certId) use ($certifier, $issuedAt): array {
        $doc = build_cert_jsonld([
            'cert'       => $certId,
            'cocid'      => (string)$grp['cocid'],
            'issued_at'  => $issuedAt,
            'devices'    => (int)$rendered['devices'],
            'methods'    => (int)$rendered['methods'],
            'runs'       => (int)$rendered['runs'],
            'first'      => $grp['first'] ?? null,
            'last'       => $grp['last'] ?? null,
            'sha_state'  => (string)$grp['shaState'],
            'sig_state'  => (string)$grp['sigState'],
            'pdf_sha256' => (string)$rendered['sha'],
        ], $rendered['drives'], $grp['reports'], $certifier);
        $signed = jsonld_sign_document($doc);
        if ($signed === null) { return ['path' => '', 'sha' => '', 'ts' => 'none']; }
        return ['path' => jsonld_write($signed['json'], $certId), 'sha' => $signed['sha256'], 'ts' => $signed['ts_state']];
    };

    if ($existing !== false) {
        // One COCID = one consolidated cert: merge and re-render the same cert.
        $merged = merge_group($g, load_cert_drives((int)$existing['id']), load_cert_reports((int)$existing['id']), $existing);
        $certId = (string)$existing['cert_id'];
        $rendered = render_certificate_pdf($merged, $certId, $canSign, $certifier, $destroyed);
        $groupPath = $certId . '.pdf';
        $written = $pdfDir . '/' . $groupPath;
        @file_put_contents($written, $rendered['data']);
        $j = $writeJson($rendered, $merged, $certId);

        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'UPDATE certificates SET devices = ?, methods = ?, runs = ?, first_ts = ?, last_ts = ?, sha_state = ?, sig_state = ?, pdf_sha256 = ?, pdf_path = ?, json_path = ?, json_sha256 = ?, json_ts_state = ?, issued_at = ? WHERE id = ?'
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
                (string)$j['path'],
                (string)$j['sha'],
                (string)$j['ts'],
                $issuedAt,
                (int)$existing['id'],
            ]);
            rewrite_certificate_details((int)$existing['id'], $merged['reports'], $rendered['drives']);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            @unlink($written);
            if ($j['path'] !== '') { @unlink($pdfDir . '/' . $j['path']); }
            error_log('generate_certificate db error: ' . $e->getMessage());
            fail(500, 'Could not generate the certificate.');
        }
    } else {
        $certId = gen_cert_id();
        $rendered = render_certificate_pdf($g, $certId, $canSign, $certifier, $destroyed);
        $groupPath = $certId . '.pdf';
        $written = $pdfDir . '/' . $groupPath;
        @file_put_contents($written, $rendered['data']);
        $j = $writeJson($rendered, $g, $certId);

        $entry = [
            'cert'       => $certId,
            'cocid'      => $cocid,
            'devices'    => $rendered['devices'],
            'methods'    => $rendered['methods'],
            'runs'       => $rendered['runs'],
            'first'      => $g['first'],
            'last'       => $g['last'],
            'sha_state'  => $g['shaState'],
            'sig_state'  => $g['sigState'],
            'reports'    => $g['reports'],
            'drives'     => $rendered['drives'],
            'pdf_sha'    => $rendered['sha'],
            'pdf_path'   => $groupPath,
            'json_path'  => (string)$j['path'],
            'json_sha256' => (string)$j['sha'],
            'json_ts_state' => (string)$j['ts'],
        ];
        try {
            db()->beginTransaction();
            insert_certificate_records([$entry], $userId, '', '', $issuedAt);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) { db()->rollBack(); }
            @unlink($written);
            if ($j['path'] !== '') { @unlink($pdfDir . '/' . $j['path']); }
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
    $org = null;
    if ($u !== null) {
        $m = org_for_user((int)$u['id']);
        if ($m !== null && $m['status'] === 'active') {
            $org = ['id' => (int)$m['organisation_id'], 'name' => (string)$m['name'], 'role' => (string)$m['role']];
        }
    }
    json_out(['ok' => true, 'user' => $u === null ? null : user_public($u), 'org' => $org]);
}

// POST /api/account — update profile (name / company details)
if ($method === 'POST' && $route === '/account') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $name = trim((string)($d['name'] ?? ''));

    // Organisation members edit only their own name; company/address/phone
    // details are organisation-owned (the owner certifies the certificates),
    // so only owners/admins — and solo users — may change them. Whatever a
    // member submits for those fields is ignored and the stored values kept.
    $canEditCompany = org_role((int)$u['id']) !== 'member';
    if ($canEditCompany) {
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
        if (mb_strlen($companyName) > 255) {
            fail(400, 'Company name must be 255 characters or fewer.');
        }
    } else {
        $companyName = (string)$u['company_name'];
        $profile = [
            'company_reg' => (string)$u['company_reg'],
            'addr_line1'  => (string)$u['addr_line1'],
            'addr_line2'  => (string)$u['addr_line2'],
            'city'        => (string)$u['city'],
            'postcode'    => (string)$u['postcode'],
            'country'     => (string)$u['country'],
            'phone'       => (string)$u['phone'],
        ];
    }

    if ($name === '' || mb_strlen($name) > 200) {
        fail(400, 'Your name is required.');
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

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        fail(400, 'A valid email address is required.');
    }
    if (strlen($password) < 8 || strlen($password) > 72) {
        fail(400, 'Password must be between 8 and 72 characters.');
    }
    if ($name === '' || mb_strlen($name) > 200) {
        fail(400, 'Your name is required.');
    }

    if (fetch_user_by_email($email) !== null) {
        fail(409, 'An account with that email already exists.');
    }

    try {
        // Every account starts as a personal account; an organisation is
        // created (or joined) later from the dashboard.
        db()->prepare('INSERT INTO users (email, password_hash, name, account_type, company_name) VALUES (?, ?, ?, "personal", "")')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
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

// GET /api/certs — search (q) over certificate ID / CoC ID, server-side paging.
if ($method === 'GET' && $route === '/certs') {
    $u = auth_require();
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 20;
    $offset = ($page - 1) * $per;

    $where = 'user_id IN (' . implode(',', array_fill(0, count(org_member_ids((int)$u['id'])), '?')) . ')';
    $params = org_member_ids((int)$u['id']);
    $types = array_fill(0, count($params), PDO::PARAM_INT);
    if ($q !== '') {
        $where .= ' AND (LOWER(cert_id) LIKE ? OR LOWER(cocid) LIKE ?)';
        $like = '%' . strtolower($q) . '%';
        $params[] = $like; $types[] = PDO::PARAM_STR;
        $params[] = $like; $types[] = PDO::PARAM_STR;
    }

    $stmt = db()->prepare("SELECT COUNT(*) FROM certificates WHERE $where");
    foreach ($params as $i => $p) { $stmt->bindValue($i + 1, $p, $types[$i]); }
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();

    $stmt = db()->prepare("SELECT * FROM certificates WHERE $where ORDER BY issued_at DESC LIMIT ? OFFSET ?");
    foreach ($params as $i => $p) { $stmt->bindValue($i + 1, $p, $types[$i]); }
    $stmt->bindValue(count($params) + 1, $per, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $certs = array_map('cert_row', $stmt->fetchAll());
    json_out(['ok' => true, 'certs' => $certs, 'total' => $total, 'page' => $page, 'per' => $per]);
}

// GET /api/certs/{cert_id}
if ($method === 'GET' && count($seg) === 2 && $seg[0] === 'certs') {
    $u = auth_require();
    $c = fetch_cert_by_cert_id($seg[1]);
    if ($c === null || (!org_owns((int)$u['id'], (int)($c['user_id'] ?? 0)) && $u['role'] !== 'admin')) {
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
    if ($c === null || (!org_owns((int)$u['id'], (int)($c['user_id'] ?? 0)) && $u['role'] !== 'admin')) {
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
    $ids = org_member_ids((int)$u['id']);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id, tier, customer, expiry, created_at FROM licences WHERE user_id IN ($ph) ORDER BY created_at DESC");
    $stmt->execute($ids);
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
    $ids = org_member_ids((int)$u['id']);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM licences WHERE id = ? AND user_id IN ($ph)");
    $stmt->execute(array_merge([(int)$seg[1]], $ids));
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
    reports_ensure_schema();

    if (empty($_FILES['reports'])) {
        fail(400, 'Upload one or more tScrub report files (.csv).');
    }
    // Machine ingestion may tag the upload as 'diagnostics'; the signed
    // post-erasure CSV stays the default 'erasure' type.
    $reportType = (string)($_POST['report_type'] ?? 'erasure');
    if ($reportType !== 'diagnostics') {
        $reportType = 'erasure';
    }
    $ingested = user_ingested_shas((int)$owner['id']);
    $stats = ['uploaded' => 0, 'skipped' => 0];
    $groups = parse_reports($_FILES['reports'], licence_pub_keys((int)$owner['id']), $ingested, $stats);
    if (!$groups && $stats['skipped'] === 0) {
        fail(400, 'No valid report data found.');
    }

    $ids = $groups ? store_reports($groups, (int)$owner['id'], $source, $reportType) : [];

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

// POST /api/reports/diagnostics — boot-time diagnostics report (identity +
// hardware + attached drive inventory), the first of the two report kinds the
// appliance ingests (this one at boot, the signed erasure report at the end).
// API-token auth only, same token as the appliance. Records presence and keeps
// the legacy device_registrations upsert so the Devices tab's triage merge
// keeps working; the typed `reports` row is the new canonical store.
if ($method === 'POST' && $route === '/reports/diagnostics') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $d = json_body();
    $serial = trim((string)($d['serial'] ?? ''));
    $uuid   = trim((string)($d['uuid'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if (strlen($serial) > 255 || strlen($uuid) > 64) {
        fail(400, 'Field too long.');
    }
    // Control characters would corrupt the OAv3 hash records (length framing)
    // and log lines — reject up front, mirroring POST /api/mdm/hash.
    if (preg_match('/[\x00-\x1f\x7f]/', $serial) === 1 || preg_match('/[\x00-\x1f\x7f]/', $uuid) === 1) {
        fail(400, 'Invalid serial or uuid.');
    }

    // Online now + legacy registration upsert (backward-compatible Devices-tab
    // triage merge), then the typed diagnostics report row.
    presence_ensure_schema();
    presence_heartbeat((int)$owner['id'], $serial, $uuid);
    device_register((int)$owner['id'], $serial, $uuid, $d);
    $id = store_diagnostics_report((int)$owner['id'], $d, $serial, $uuid);

    // The diagnostics report is the device's ownership claim and, with the
    // tScrub-standalone strategy, also its MDM hash source. Two sources
    // coexist: (1) a WinPE oa3tool hash uploaded via /api/mdm/hash (held in
    // the global unassigned pool until this report claims it — authoritative,
    // preferred, never overwritten), and (2) an OAv3 hash generated HERE from
    // the report's own hardware fields (no WinPE needed). The hash is always
    // staged, but the check itself is manual only (dashboard Re-check): a
    // device with a staged hash but no job surfaces as "Unchecked".
    mdm_ensure_schema();
    $haveHash = mdm_staged_hash((int)$owner['id'], $serial, $uuid) !== null
             || mdm_claim_hash_for_user((int)$owner['id'], $serial, $uuid);
    if (!$haveHash) {
        $d['product_key_id'] = product_key_id_from_key((string)($d['product_key'] ?? ''));
        $generated = mdm_report_hash($d);
        if ($generated !== '') {
            mdm_stage_hash((int)$owner['id'], $serial, $uuid, (string)($d['product'] ?? ''), $generated);
        }
    }

    // Erasure pre-flight (the appliance reads this before wiping): a paid
    // account with a zero balance reports can_erase=false; the appliance decides
    // whether to block (fail-open offline — if this response can't be fetched,
    // erasure proceeds). Free accounts are always allowed.
    $diagTier = owner_tier((int)$owner['id']);
    $diagBalance = credit_balance((int)$owner['id']);

    json_out([
        'ok' => true,
        'report_type' => 'diagnostics',
        'id' => $id,
        'registered' => true,
        'credits' => ['balance' => $diagBalance],
        'can_erase' => $diagTier === 'free' || $diagBalance > 0,
    ]);
}

// GET /api/reports/{id}/download — renders the Device Diagnostics Report PDF
// on the fly from the stored payload (owner or admin). Nothing is persisted.
if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'reports' && $seg[2] === 'download') {
    $u = auth_require();
    $id = (int)$seg[1];
    if ($id <= 0) {
        fail(404, 'Report not found.');
    }
    $stmt = db()->prepare('SELECT * FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if ($r === false || (!org_owns((int)$u['id'], (int)($r['user_id'] ?? 0)) && $u['role'] !== 'admin')) {
        fail(404, 'Report not found.');
    }
    if (($r['report_type'] ?? 'erasure') !== 'diagnostics') {
        fail(404, 'No diagnostics report available.');
    }
    $d = json_decode((string)($r['payload'] ?? ''), true);
    if (!is_array($d)) {
        fail(404, 'Report data unavailable.');
    }

    require_once __DIR__ . '/render_diag.php';
    $raw = diag_stored_to_raw($d);
    // Operator strategy: fall back to the account holder (name, else email)
    // when the appliance supplied none; the validator row is omitted when empty.
    $raw['operator'] = operator_for_pdf($raw, (int)$u['id']);
    // The operator's manual I-A–I-F refurb grade (empty → no grade shown).
    $raw['refurb_grade'] = (string)($r['grade'] ?? '');
    $rendered = render_diagnostics_pdf(
        $raw,
        certifier_for_user((int)$r['user_id']),
        owner_tier((int)$r['user_id']) !== 'free',
        'none'
    );

    $sys = trim((string)($d['system'] ?? ''));
    $sys = $sys !== '' ? trim(preg_replace('/[^A-Za-z0-9]+/', '_', $sys), '_') : 'device';
    $sn = trim((string)($d['sysserial'] ?? $d['sysSerial'] ?? ''));
    $sn = $sn !== '' ? trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $sn), '_') : 'nosn';

    // "date and time in seconds": the report's UTC timestamp -> YYYYMMDD-HHMMSS.
    $digits = preg_replace('/\D/', '', (string)($r['uploaded_at'] ?? ''));
    $ts = strlen($digits) >= 14
        ? substr($digits, 0, 4) . substr($digits, 4, 2) . substr($digits, 6, 2) . '-' . substr($digits, 8, 2) . substr($digits, 10, 2) . substr($digits, 12, 2)
        : gmdate('Ymd-His');

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="tScrub-' . $sys . '-' . $sn . '-' . $ts . '.pdf"');
    header('Content-Length: ' . strlen($rendered['data']));
    echo $rendered['data'];
    exit;
}

// GET /api/drives/{id}/pdf — renders a comprehensive per-drive health report
// PDF on the fly from one stored report + drive serial (owner or admin).
if ($method === 'GET' && count($seg) === 3 && $seg[0] === 'drives' && $seg[2] === 'pdf') {
    $u = auth_require();
    $id = (int)$seg[1];
    if ($id <= 0) {
        fail(404, 'Drive report not found.');
    }
    $stmt = db()->prepare('SELECT * FROM reports WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if ($r === false || (!org_owns((int)$u['id'], (int)($r['user_id'] ?? 0)) && $u['role'] !== 'admin')) {
        fail(404, 'Drive report not found.');
    }
    if (($r['report_type'] ?? 'erasure') === 'diagnostics') {
        fail(404, 'No drive report available for this report.');
    }
    $g = json_decode((string)($r['payload'] ?? ''), true);
    if (!is_array($g)) {
        fail(404, 'Report data unavailable.');
    }
    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($serial === '') {
        fail(400, 'Missing drive serial.');
    }
    $drive = null;
    foreach (($g['drives'] ?? []) as $d) {
        if (is_array($d) && strcasecmp(trim((string)($d['serial'] ?? '')), $serial) === 0) {
            $drive = $d;
            break;
        }
    }
    if ($drive === null) {
        fail(404, 'Drive not found in this report.');
    }
    $drive['cocid']       = (string)$g['cocid'];
    $drive['uploaded_at'] = ts_local((string)$r['uploaded_at']);

    require_once __DIR__ . '/render_drive.php';
    $rendered = render_drive_pdf(
        $drive,
        owner_tier((int)$r['user_id']) !== 'free',
        certifier_for_user((int)$r['user_id'])
    );

    $sn = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $serial));
    $sn = $sn !== '' ? $sn : 'drive';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Drive-Health-' . $sn . '.pdf"');
    header('Content-Length: ' . strlen($rendered['data']));
    echo $rendered['data'];
    exit;
}

// POST /api/mdm/hash — stage a WinPE-captured authoritative 4K hash for the
// device, so the appliance's MDM check (POST /api/mdm/autopilot) can use it.
// Tenant-agnostic ingest: authenticated by a shared secret (X-Ingest-Key), not
// a per-user API token. The hash is staged unassigned and claimed later by
// whichever tenant uploads the device's diagnostics report.
if ($method === 'POST' && $route === '/mdm/hash') {
    $ingestKey = $_SERVER['HTTP_X_INGEST_KEY'] ?? '';
    $expected  = mdm_ingest_key();
    if ($expected === '' || !is_string($ingestKey) || !hash_equals($expected, $ingestKey)) {
        fail(401, 'Invalid ingest key.');
    }

    rate_limit('mdm', 30);

    $d = json_body();
    $serial = trim((string)($d['serial'] ?? ''));
    $uuid   = trim((string)($d['uuid'] ?? ''));
    $model  = trim((string)($d['model'] ?? ''));
    $hash   = trim((string)($d['hardwareIdentifier'] ?? ''));

    // Log the attempt BEFORE validation so a rejected upload is still visible
    // in mdm_ingest_log (WinPE cannot easily retry, and the screen is gone).
    mdm_ensure_schema();
    mdm_log_ingest(null, $serial, $uuid, strlen($hash), (string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial number required.');
    }
    if (strlen($serial) > 255 || preg_match('/[\x00-\x1f\x7f]/', $serial) === 1) {
        fail(400, 'Invalid serial.');
    }
    // A missing UUID is allowed (some machines report none) — the device is
    // still keyed by (serial, uuid) with uuid="" so it dedupes per serial.
    if (strlen($uuid) > 255 || preg_match('/[\x00-\x1f\x7f]/', $uuid) === 1) {
        fail(400, 'Invalid uuid.');
    }
    if (strlen($model) > 255 || preg_match('/[\x00-\x1f\x7f]/', $model) === 1) {
        fail(400, 'Invalid model.');
    }
    if (strlen($hash) !== 4000 || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $hash) !== 1) {
        fail(400, 'Invalid hardware hash.');
    }

    // Hash intake enters a GLOBAL unassigned pool; the device is attributed to
    // a user only once a diagnostics report for the same serial+uuid is uploaded
    // (that upload claims the hash and starts the check). If a report already
    // exists (hash captured after the report), claim + enqueue now.
    $reportOwner = mdm_report_owner($serial, $uuid);
    mdm_stage_hash($reportOwner, $serial, $uuid, $model, $hash);
    // The check is manual only (dashboard Re-check) — no auto-enqueue here.
    json_out(['ok' => true, 'staged' => true, 'queued' => false, 'job_id' => 0, 'assigned' => $reportOwner !== null]);
}

// POST /api/mdm/autopilot — machine MDM check (Autopilot enrolment).
// API-token auth only (no session). The appliance sends hardware identifiers;
// the Azure credentials live server-side in config.json (see mdm.php).
if ($method === 'POST' && $route === '/mdm/autopilot') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    rate_limit('mdm', 30);

    $d = json_body();
    $serial = trim((string)($d['serial'] ?? ''));
    $uuid   = trim((string)($d['uuid'] ?? ''));

    // Input guards: length caps + control characters (log-injection defence).
    foreach (['serial' => $serial, 'uuid' => $uuid] as $name => $field) {
        if (strlen($field) > 255) {
            fail(400, 'Field too long: ' . $name . '.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $field) === 1) {
            fail(400, 'Invalid characters in request.');
        }
    }

    mdm_ensure_schema();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // Nothing to check: the appliance marks missing identifiers as "N/A".
    if ($serial === '' || $uuid === '' || $serial === 'N/A' || $uuid === 'N/A') {
        mdm_log_probe((int)$owner['id'], $serial, $uuid, 'skipped', 'none', $ip);
        json_out(['ok' => true, 'verdict' => 'skipped', 'source' => 'none', 'label' => 'Skipped']);
    }

    // The check now runs in the background (mdm-worker.php): the appliance
    // enqueues or reads a job instead of blocking on Microsoft's async queue.
    $staged = mdm_staged_hash((int)$owner['id'], $serial, $uuid);
    if ($staged === null) {
        // A hash may exist but not yet be claimed by a diagnostics report (the
        // report upload moments later claims it and starts the check). Report
        // "queued" so the appliance polls instead of a misleading "---".
        if (mdm_has_unassigned_hash($serial, $uuid)) {
            json_out(['ok' => true, 'status' => 'queued', 'verdict' => '', 'source' => 'none', 'label' => 'Queued']);
        }
        mdm_log_probe((int)$owner['id'], $serial, $uuid, 'na', 'none', $ip);
        json_out(['ok' => true, 'verdict' => 'na', 'source' => 'none', 'status' => 'na', 'label' => '---']);
    }

    // MDM is a paid feature: free accounts can't check, and paid accounts spend
    // one credit per live probe. Gate here (before any enqueue) so a blocked
    // account never creates a job the worker would have to skip.
    $mdmGate = mdm_gate((int)$owner['id']);
    if (!$mdmGate['allowed']) {
        $verdict = $mdmGate['reason'] === 'free_tier' ? 'paid_only' : 'insufficient_credits';
        mdm_log_probe((int)$owner['id'], $serial, $uuid, $verdict, 'gate', $ip);
        json_out([
            'ok' => true,
            'status' => 'done',
            'verdict' => $verdict,
            'label' => mdm_status_label('done', $verdict),
            'source' => 'gate',
            'credits' => ['balance' => $mdmGate['balance']],
        ]);
    }

    // The check is manual only (dashboard Re-check). The appliance just reads
    // the latest job state; no job yet => "Unchecked".
    $job = mdm_latest_job((int)$owner['id'], $serial, $uuid);
    $status  = $job !== null ? (string)($job['status'] ?? '') : 'unchecked';
    $verdict = (string)($job['verdict'] ?? '');

    json_out(['ok' => true, 'status' => $status, 'verdict' => $verdict,
        'label' => mdm_status_label($status, $verdict),
        'source' => $status === 'done' ? (string)($job['source'] ?? 'live') : 'none']);
}

// GET /api/mdm/status?serial=… — poll a device's check status (appliance).
// API-token auth only.
if ($method === 'GET' && $route === '/mdm/status') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $serial = trim((string)($_GET['serial'] ?? ''));
    $uuid   = trim((string)($_GET['uuid'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }

    // Mirror the paid-only gate so a poll never reports a checkable state for a
    // gated account (defensive — /mdm/autopilot returns a terminal verdict).
    $mdmGate = mdm_gate((int)$owner['id']);
    if (!$mdmGate['allowed']) {
        $verdict = $mdmGate['reason'] === 'free_tier' ? 'paid_only' : 'insufficient_credits';
        json_out([
            'ok' => true,
            'serial' => $serial,
            'uuid' => $uuid,
            'status' => 'done',
            'verdict' => $verdict,
            'label' => mdm_status_label('done', $verdict),
            'source' => 'gate',
            'credits' => ['balance' => $mdmGate['balance']],
        ]);
    }

    $job = mdm_latest_job((int)$owner['id'], $serial, $uuid !== '' ? $uuid : null);
    $status  = (string)($job['status'] ?? 'na');
    $verdict = (string)($job['verdict'] ?? '');
    // No job yet => "Unchecked" (the dashboard Re-check is the manual trigger).
    if ($status === 'na') {
        $status = 'unchecked';
        $verdict = '';
    }
    json_out([
        'ok' => true,
        'serial' => $serial,
        'uuid' => $uuid,
        'status' => $status,
        'verdict' => $verdict,
        'label' => mdm_status_label($status, $verdict),
        'source' => (string)($job['source'] ?? 'none'),
        'last_checked_at' => $job['updated_at'] ?? null,
    ]);
}

// POST /api/heartbeat — appliance presence ping (device is online now).
// API-token auth only (same token as the appliance's report/MDM calls).
// Deliberately NOT rate-limited: heartbeats are frequent by design.
if ($method === 'POST' && $route === '/heartbeat') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $d = json_body();
    $serial = trim((string)($d['serial'] ?? ''));
    $uuid   = trim((string)($d['uuid'] ?? ''));
    $lanIp  = trim((string)($d['ip'] ?? $d['lan_ip'] ?? ''));

    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if (strlen($serial) > 255 || strlen($uuid) > 64) {
        fail(400, 'Field too long.');
    }
    if (strlen($lanIp) > 45) {
        $lanIp = '';
    }

    // Erasure indicator (optional; only newer appliances send it). When the
    // key is absent (older appliance) we leave any previously stored phase
    // untouched; when present (even empty) we apply it — empty clears it.
    $touchPhase = array_key_exists('phase', $d);
    $phase = (string)($d['phase'] ?? '');
    if ($phase !== '' && !in_array($phase, ['idle', 'wiping', 'done', 'failed'], true)) {
        $phase = '';
    }
    $drivesTotal  = max(0, (int)($d['drives_total'] ?? 0));
    $drivesDone   = max(0, (int)($d['drives_done'] ?? 0));
    $drivesFailed = max(0, (int)($d['drives_failed'] ?? 0));

    presence_ensure_schema();
    presence_heartbeat((int)$owner['id'], $serial, $uuid, $lanIp, $phase, $drivesTotal, $drivesDone, $drivesFailed, $touchPhase);
    json_out(['ok' => true]);
}

// GET /api/mdm/devices — dashboard device list (session auth).
if ($method === 'GET' && $route === '/mdm/devices') {
    $u = auth_require();
    json_out(['ok' => true, 'devices' => mdm_devices((int)$u['id'])]);
}

// POST /api/bios/unlock — stage a BIOS password clear (dashboard). Session+CSRF.
if ($method === 'POST' && $route === '/bios/unlock') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $serial   = trim((string)($d['serial'] ?? ''));
    $uuid     = trim((string)($d['uuid'] ?? ''));
    $password = (string)($d['password'] ?? '');

    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if (strlen($serial) > 255 || strlen($uuid) > 64) {
        fail(400, 'Field too long.');
    }
    if ($password === '' || strlen($password) > 255) {
        fail(400, 'Password required (max 255 chars).');
    }

    $id = unlock_enqueue((int)$u['id'], $serial, $uuid, $password);
    json_out(['ok' => true, 'id' => $id, 'staged' => true]);
}

// GET /api/bios/unlock/pending?serial=&uuid= — appliance pulls a staged command.
// API-token auth only.
if ($method === 'GET' && $route === '/bios/unlock/pending') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    $uuid = trim((string)($_GET['uuid'] ?? ''));

    $cmd = unlock_claim((int)$owner['id'], $serial, $uuid);
    if ($cmd === null) {
        json_out(['ok' => true, 'pending' => false]);
    }
    json_out(['ok' => true, 'pending' => true, 'id' => $cmd['id'],
        'serial' => $cmd['serial'], 'uuid' => $cmd['uuid'], 'password' => $cmd['password'],
        'password_b64' => base64_encode($cmd['password'])]);
}

// POST /api/bios/unlock/result — appliance reports the clear outcome. API token.
if ($method === 'POST' && $route === '/bios/unlock/result') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $d = json_body();
    $id     = (int)($d['id'] ?? 0);
    $result = trim((string)($d['result'] ?? ''));
    $detail = trim((string)($d['detail'] ?? ''));
    if ($id <= 0) {
        fail(400, 'Invalid command id.');
    }
    if (!in_array($result, ['cleared', 'failed', 'unsupported'], true)) {
        fail(400, 'Invalid result.');
    }

    if (!unlock_report((int)$owner['id'], $id, $result, mb_substr($detail, 0, 255))) {
        fail(409, 'Command is not dispatched.');
    }
    json_out(['ok' => true]);
}

// GET /api/bios/unlock?serial= — latest command state (dashboard). Session auth.
if ($method === 'GET' && $route === '/bios/unlock') {
    $u = auth_require();
    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($serial === '') {
        fail(400, 'Serial required.');
    }
    json_out(['ok' => true, 'unlock' => unlock_latest((int)$u['id'], $serial)]);
}

// POST /api/bios/unlock/cancel — cancel a still-pending staged command. Session+CSRF.
if ($method === 'POST' && $route === '/bios/unlock/cancel') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) {
        fail(400, 'Invalid command id.');
    }
    json_out(['ok' => true, 'cancelled' => unlock_cancel((int)$u['id'], $id)]);
}

// POST /api/devices/commands — stage a remote power command (dashboard). Session+CSRF.
if ($method === 'POST' && $route === '/devices/commands') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $serial  = trim((string)($d['serial'] ?? ''));
    $uuid    = trim((string)($d['uuid'] ?? ''));
    $command = trim((string)($d['command'] ?? ''));

    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if (strlen($serial) > 255 || strlen($uuid) > 64) {
        fail(400, 'Field too long.');
    }
    if (!in_array($command, ['shutdown', 'reboot', 'wipe'], true)) {
        fail(400, 'Command must be "shutdown", "reboot" or "wipe".');
    }

    $options = [];
    if ($command === 'wipe') {
        $raw = $d['options'] ?? null;
        if ($raw !== null && !is_array($raw)) {
            fail(400, 'Options must be an object.');
        }
        $options['dry_run'] = (bool)($raw['dry_run'] ?? false);
        $drives = $raw['drives'] ?? 'all';
        if ($drives === 'all') {
            $options['drives'] = 'all';
        } elseif (is_array($drives)) {
            $clean = [];
            foreach ($drives as $s) {
                if (!is_string($s)) continue;
                $s = trim($s);
                if ($s === '' || strlen($s) > 255 || preg_match('/[\x00-\x1f\x7f]/', $s) === 1) continue;
                $clean[] = $s;
            }
            if (count($clean) === 0) {
                fail(400, 'No valid drives selected.');
            }
            $options['drives'] = $clean;
        } else {
            fail(400, 'Drives must be "all" or an array of serials.');
        }
    }

    if (($command === 'shutdown' || $command === 'reboot') && remote_has_power_pending((int)$u['id'], $serial)) {
        fail(409, 'A shutdown or restart is already pending for this device.');
    }

    $id = remote_enqueue((int)$u['id'], $serial, $uuid, $command, $options);
    json_out(['ok' => true, 'id' => $id, 'staged' => true]);
}

// GET /api/devices/commands/pending?serial=&uuid= — appliance pulls a staged command.
// API-token auth only.
if ($method === 'GET' && $route === '/devices/commands/pending') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }

    $cmd = remote_claim((int)$owner['id'], $serial);
    if ($cmd === null) {
        json_out(['ok' => true, 'pending' => false]);
    }
    json_out(['ok' => true, 'pending' => true, 'id' => $cmd['id'], 'command' => $cmd['command'], 'options' => $cmd['options']]);
}

// POST /api/devices/commands/result — appliance reports the outcome. API token.
if ($method === 'POST' && $route === '/devices/commands/result') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $d = json_body();
    $id     = (int)($d['id'] ?? 0);
    $result = trim((string)($d['result'] ?? ''));
    $detail = trim((string)($d['detail'] ?? ''));
    if ($id <= 0) {
        fail(400, 'Invalid command id.');
    }
    if (!in_array($result, ['done', 'failed', 'deferred'], true)) {
        fail(400, 'Invalid result.');
    }

    remote_report((int)$owner['id'], $id, $result, mb_substr($detail, 0, 255));
    json_out(['ok' => true]);
}

// GET /api/devices/commands?serial= — latest command state (dashboard). Session auth.
if ($method === 'GET' && $route === '/devices/commands') {
    $u = auth_require();
    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($serial === '') {
        fail(400, 'Serial required.');
    }
    json_out(['ok' => true, 'command' => remote_latest((int)$u['id'], $serial)]);
}

// POST /api/devices/commands/cancel — cancel a still-pending command. Session+CSRF.
if ($method === 'POST' && $route === '/devices/commands/cancel') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $id = (int)($d['id'] ?? 0);
    if ($id <= 0) {
        fail(400, 'Invalid command id.');
    }
    json_out(['ok' => true, 'cancelled' => remote_cancel((int)$u['id'], $id)]);
}

// POST /api/mdm/recheck — enqueue a fresh check for a captured device.
// Session + CSRF (dashboard button).
if ($method === 'POST' && $route === '/mdm/recheck') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $serial = trim((string)($d['serial'] ?? ''));
    $uuid   = trim((string)($d['uuid'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if ($uuid === '' || $uuid === 'N/A') {
        fail(400, 'UUID required.');
    }
    if (mdm_staged_hash((int)$u['id'], $serial, $uuid) === null) {
        fail(404, 'No captured hash for that device.');
    }
    $mdmGate = mdm_gate((int)$u['id']);
    if (!$mdmGate['allowed']) {
        fail(402, $mdmGate['reason'] === 'free_tier'
            ? 'MDM check is a paid feature — upgrade your licence on the Billing page.'
            : 'Insufficient credits — top up on the Billing page.');
    }
    mdm_ensure_schema();
    $jobId = mdm_enqueue_job((int)$u['id'], $serial, $uuid, true);
    json_out(['ok' => true, 'queued' => $jobId > 0, 'job_id' => $jobId]);
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

// GET /api/devices — aggregated machine (hardware/firmware) inventory, with
// the MDM (Autopilot) registry folded in per device. Search (q), sort (sort,
// dir) and pagination (page, per) run server-side so the dashboard only ever
// downloads the current page.
if ($method === 'GET' && $route === '/devices') {
    $u = auth_require();
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per'] ?? 10)));
    $sort = (string)($_GET['sort'] ?? 'status');
    $dir = (($_GET['dir'] ?? '') === 'asc') ? 1 : -1;

    $devices = remote_fold_devices((int)$u['id'], mdm_fold_devices((int)$u['id'], load_devices((int)$u['id'])));

    // Free-text filter over the same fields the dashboard searches.
    if ($q !== '') {
        $needle = strtolower($q);
        $fields = ['system', 'sysserial', 'bbserial', 'chassisserial', 'systemuuid', 'lan_ip', 'bioslock', 'mdm', 'mdm_status', 'mdm_verdict', 'mdm_label', 'sku', 'asset_tag', 'cpu', 'cpu_spec', 'gpu', 'display', 'wifi', 'ram', 'tpm', 'macs', 'storage_controllers', 'battery', 'dimms', 'product_key', 'product_key_id'];
        $devices = array_values(array_filter($devices, function ($d) use ($needle, $fields) {
            foreach ($fields as $f) {
                if (strpos(strtolower((string)($d[$f] ?? '')), $needle) !== false) return true;
            }
            return false;
        }));
    }

    // Sort mirrors the dashboard: system/serial/bios are text, mdm/status are ranks.
    $mdmRank = function (array $d): int {
        $st = strtolower((string)($d['mdm_status'] ?? ''));
        $v = strtolower((string)($d['mdm_verdict'] ?? ''));
        $rep = strtolower((string)($d['mdm'] ?? ''));
        $REAL = ['locked_other', 'locked_this', 'unlocked', 'hash_invalid', 'ms_error', 'offline'];
        if ($st === 'checking') return 90;
        if ($st === 'queued') return 80;
        if ($st === 'unchecked') return 10;
        $verdict = in_array($v, $REAL, true) ? $v : (in_array($rep, $REAL, true) ? $rep : ($st === 'done' && $v === 'unknown' ? 'unknown' : ''));
        $r = ['locked_other' => 70, 'locked_this' => 70, 'ms_error' => 60, 'hash_invalid' => 50, 'offline' => 40, 'unlocked' => 30, 'unknown' => 20];
        return $r[$verdict] ?? 0;
    };
    $sortVal = function (array $d) use ($sort, $mdmRank) {
        switch ($sort) {
            case 'system': return strtolower((string)($d['system'] ?? $d['sysserial'] ?? ''));
            case 'serial': return strtolower((string)($d['sysserial'] ?? ''));
            case 'bios':   return strtolower((string)($d['bioslock'] ?? ''));
            case 'mdm':    return $mdmRank($d);
            case 'status': return !empty($d['online']) ? 1 : 0;
        }
        return '';
    };
    usort($devices, function ($a, $b) use ($sortVal, $dir) {
        $va = $sortVal($a);
        $vb = $sortVal($b);
        $cmp = (is_int($va) && is_int($vb)) ? ($va <=> $vb) : strcmp((string)$va, (string)$vb);
        return $dir * $cmp;
    });

    $total = count($devices);
    json_out([
        'ok'      => true,
        'devices' => array_values(array_slice($devices, ($page - 1) * $per, $per)),
        'total'   => $total,
        'page'    => $page,
        'per'     => $per,
    ]);
}

// POST /api/devices/grade — set (or clear, grade='') the operator's manual
// I-A–I-F refurb grade on a diagnostics report. Session + CSRF auth;
// org-scoped by report ownership.
if ($method === 'POST' && $route === '/devices/grade') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $grade = strtoupper(trim((string)($d['grade'] ?? '')));
    if (!valid_device_grade($grade)) {
        fail(400, 'grade must be one of I-A, I-B, I-C, I-D, I-F (or empty to clear).');
    }
    $reportId = (int)($d['report_id'] ?? 0);
    if ($reportId <= 0) {
        fail(400, 'Provide the diagnostics report id.');
    }
    if (!report_grade_set((int)$u['id'], $reportId, $grade)) {
        fail(404, 'Diagnostics report not found.');
    }
    json_out(['ok' => true, 'grade' => $grade, 'report_id' => $reportId]);
}

// POST /api/devices/register — the appliance posts its identity + hardware +
// drive inventory on boot (ITAD triage), before any wipe. API-token auth.
if ($method === 'POST' && $route === '/devices/register') {
    $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
        fail(401, 'Invalid API token.');
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

    $d = json_body();
    $serial = trim((string)($d['serial'] ?? ''));
    $uuid   = trim((string)($d['uuid'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if (strlen($serial) > 255 || strlen($uuid) > 64) {
        fail(400, 'Field too long.');
    }

    device_register((int)$owner['id'], $serial, $uuid, $d);
    presence_heartbeat((int)$owner['id'], $serial, $uuid);
    json_out(['ok' => true, 'registered' => true]);
}

// GET /api/drives — aggregated storage-device inventory (erasure + SMART).
// Search (q) and pagination (page, per) run server-side.
if ($method === 'GET' && $route === '/drives') {
    $u = auth_require();
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per'] ?? 10)));

    $drives = load_drives((int)$u['id']);
    if ($q !== '') {
        $needle = strtolower($q);
        $fields = ['serial', 'model', 'size', 'type', 'status', 'method', 'bus', 'cocid', 'system', 'sysserial'];
        $drives = array_values(array_filter($drives, function ($d) use ($needle, $fields) {
            foreach ($fields as $f) {
                if (strpos(strtolower((string)($d[$f] ?? '')), $needle) !== false) return true;
            }
            return false;
        }));
    }
    $total = count($drives);
    json_out([
        'ok'     => true,
        'drives' => array_values(array_slice($drives, ($page - 1) * $per, $per)),
        'total'  => $total,
        'page'   => $page,
        'per'    => $per,
    ]);
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

// GET /api/credits — device-credit balance + paged event history (org-pooled).
if ($method === 'GET' && $route === '/credits') {
    $u = auth_require();
    credit_ensure_schema();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per'] ?? 20)));
    $offset = ($page - 1) * $per;

    $orgId = credit_scope_id((int)$u['id']);
    if ($orgId > 0) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM credit_events WHERE organisation_id = ?');
        $stmt->execute([$orgId]);
        $total = (int)$stmt->fetchColumn();

        $stmt = db()->prepare('SELECT type, units, ref, created_at FROM credit_events WHERE organisation_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $orgId, PDO::PARAM_INT);
        $stmt->bindValue(2, $per, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = db()->prepare('SELECT COUNT(*) FROM credit_events WHERE organisation_id = 0 AND user_id = ?');
        $stmt->execute([(int)$u['id']]);
        $total = (int)$stmt->fetchColumn();

        $stmt = db()->prepare('SELECT type, units, ref, created_at FROM credit_events WHERE organisation_id = 0 AND user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, (int)$u['id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $per, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
    }
    $events = array_map(static function (array $ev): array {
        $ev['created_at'] = ts_local((string)($ev['created_at'] ?? ''));
        return $ev;
    }, $stmt->fetchAll());
    json_out(['ok' => true, 'balance' => credit_balance((int)$u['id']), 'events' => $events, 'total' => $total, 'page' => $page, 'per' => $per]);
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
    // BLOCKED / FROZEN / UNKNOWN / DRY-RUN) can't be claimed as wiped. When the
    // operator hasn't supplied a decision, return the uncompleted drives so the
    // dashboard can ask; otherwise validate the choices and bake them into the
    // certificate. An existing certificate's drives are folded in first so a
    // drive already recorded as DESTROYED (or COMPLETED) doesn't reappear here.
    $decisionDrives = $g['drives'];
    $ids = org_member_ids((int)$u['id']);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM certificates WHERE user_id IN ($ph) AND cocid = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(array_merge($ids, [$cocid]));
    $existing = $stmt->fetch();
    if ($existing !== false) {
        $decisionDrives = merge_group($g, load_cert_drives((int)$existing['id']), load_cert_reports((int)$existing['id']), $existing)['drives'];
    }
    $nonCompleted = [];
    foreach ($decisionDrives as $drv) {
        $st = strtoupper(trim((string)($drv['status'] ?? '')));
        if ($st === 'COMPLETED' || $st === 'DESTROYED') continue;
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

// GET /api/tscrub-conf — download a preconfigured tscrub.conf for the
// appliance: the account's appliance upload token. The dashboard URL is built
// into the appliance, so the token alone is all it needs (tscrub_upload= is
// only an optional override). Uses the most recent token, creating one if none
// exists yet.
if ($method === 'GET' && $route === '/tscrub-conf') {
    $u = auth_require();
    $stmt = db()->prepare('SELECT token FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->execute([$u['id']]);
    $token = $stmt->fetchColumn();
    if ($token === false || !is_string($token) || $token === '') {
        $token = auth_token();
        db()->prepare('INSERT INTO api_tokens (user_id, token, label) VALUES (?, ?, ?)')
            ->execute([$u['id'], $token, 'Appliance']);
    }
    $conf = "# tScrub appliance configuration\n"
        . "# Drop this file on the boot USB, next to your .lic, and tScrub will push\n"
        . "# every report to your tScrub dashboard automatically. The dashboard URL\n"
        . "# is built into the appliance, so only the token is needed. Optional keys:\n"
        . "#   tscrub_cocid=12345                run unattended with this Chain of Custody ID\n"
        . "#   tscrub_output=/path/to/reports    local report path (or ftp:/sftp:…)\n"
        . "tscrub_api_token={$token}\n";
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="tscrub.conf"');
    header('Content-Length: ' . strlen($conf));
    echo $conf;
    exit;
}

// ---- organisations & seats ------------------------------------------------

// GET /api/org — current org + members + seats (solo => org:null).
if ($method === 'GET' && $route === '/org') {
    $u = auth_require();
    json_out(['ok' => true] + org_summary((int)$u['id']));
}

// POST /api/org — create an organisation (the user becomes owner).
if ($method === 'POST' && $route === '/org') {
    auth_csrf_verify();
    $u = auth_require();
    rate_limit('org', 30);
    $d = json_body();
    json_out(['ok' => true] + org_create((int)$u['id'], trim((string)($d['name'] ?? ''))), 201);
}

// POST /api/org/invites — invite an existing account by email.
if ($method === 'POST' && $route === '/org/invites') {
    auth_csrf_verify();
    $u = auth_require();
    rate_limit('org-invite', 30);
    $d = json_body();
    $inv = org_invite((int)$u['id'], (string)($d['email'] ?? ''), (string)($d['role'] ?? 'member'));

    $org = org_for_user((int)$u['id']);
    $orgName = $org !== null ? (string)$org['name'] : 'an organisation';
    $link = db_base_url() . '/dashboard/account?org_invite=' . $inv['token'];
    $sender = (string)($u['name'] ?? '');
    if ($sender === '') { $sender = (string)($u['email'] ?? ''); }
    mail_send_async(
        'Join ' . $orgName . ' on tScrub',
        "Hi,\n\n{$sender} has invited you to join the {$orgName} organisation on tScrub as a {$inv['role']}.\n\n"
        . "Open this link to accept (it expires in 7 days):\n\n{$link}\n\n"
        . "If you don't have a tScrub account yet, register with this email address first, then open the link again.\n\n— tScrub",
        'contact',
        $inv['email']
    );
    json_out(['ok' => true, 'invite' => $inv], 201);
}

// POST /api/org/invites/{token}/accept — accept an invite (email must match).
if ($method === 'POST' && count($seg) === 4 && $seg[0] === 'org' && $seg[1] === 'invites' && $seg[3] === 'accept') {
    $u = auth_require();
    json_out(['ok' => true] + org_accept((int)$u['id'], $seg[2]));
}

// POST /api/org/members/{id}/role — change a member's role (owner/admin).
if ($method === 'POST' && count($seg) === 4 && $seg[0] === 'org' && $seg[1] === 'members' && $seg[3] === 'role') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    org_change_role((int)$u['id'], (int)$seg[2], (string)($d['role'] ?? ''));
    json_out(['ok' => true] + org_summary((int)$u['id']));
}

// DELETE /api/org/members/{id} — soft-remove a member (owner/admin).
if ($method === 'DELETE' && count($seg) === 3 && $seg[0] === 'org' && $seg[1] === 'members') {
    auth_csrf_verify();
    $u = auth_require();
    org_remove((int)$u['id'], (int)$seg[2]);
    json_out(['ok' => true] + org_summary((int)$u['id']));
}

// POST /api/org/leave — leave the organisation (member/admin).
if ($method === 'POST' && $route === '/org/leave') {
    auth_csrf_verify();
    $u = auth_require();
    org_leave((int)$u['id']);
    json_out(['ok' => true]);
}

// POST /api/org/rename — rename the organisation (owner only).
if ($method === 'POST' && $route === '/org/rename') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    json_out(['ok' => true] + org_rename((int)$u['id'], trim((string)($d['name'] ?? ''))));
}

// POST /api/org/transfer — transfer ownership to another active member (owner only).
if ($method === 'POST' && $route === '/org/transfer') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    org_transfer((int)$u['id'], (int)($d['user_id'] ?? 0));
    json_out(['ok' => true] + org_summary((int)$u['id']));
}

// ---- admin ----------------------------------------------------------------

// GET /api/admin/stats
if ($method === 'GET' && $route === '/admin/stats') {
    auth_require_admin();
    org_ensure_schema();
    $stats = [
        'users'         => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'organisations' => (int)db()->query('SELECT COUNT(*) FROM organisations')->fetchColumn(),
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

// GET /api/admin/mdm-log — Autopilot MDM probe log (all accounts)
if ($method === 'GET' && $route === '/admin/mdm-log') {
    auth_require_admin();
    mdm_ensure_schema();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;
    $offset = ($page - 1) * $per;
    $total = (int)db()->query('SELECT COUNT(*) FROM mdm_log')->fetchColumn();
    $stmt = db()->prepare(
        'SELECT m.serial, m.uuid, m.verdict, m.source, m.ip, m.created_at, u.email
         FROM mdm_log m JOIN users u ON u.id = m.user_id
         ORDER BY m.id DESC LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = array_map(fn($r) => [
        'email'      => (string)($r['email'] ?? ''),
        'serial'     => (string)$r['serial'],
        'uuid'       => (string)$r['uuid'],
        'verdict'    => (string)$r['verdict'],
        'source'     => (string)$r['source'],
        'ip'         => (string)$r['ip'],
        'created_at' => (string)$r['created_at'],
    ], $stmt->fetchAll());
    json_out(['ok' => true, 'log' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
}

fail(404, 'Not found.');
