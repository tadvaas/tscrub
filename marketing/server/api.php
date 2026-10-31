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
 *   GET  /api/admin/mdm-tenant      (probe tenant subscription / trial expiry)
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
require_once __DIR__ . '/device_log.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/certifier.php';
require_once __DIR__ . '/jsonld.php';
// The attested-destruction vocabulary and validation. The routes call md_validate_*
// directly, so this must be loaded up front: requiring it lazily (inside a save) is
// too late and the route hits "Call to undefined function md_validate_device()" —
// a fatal, which the dashboard can only report as a generic failure.
require_once __DIR__ . '/manual_destruction.php';

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

function cert_row(array $c, ?array $att = null, ?array $assets = null): array {
    // Attested (operator-recorded) items live in their own tables and are deliberately NOT
    // part of `devices`/`methods`/`runs` — those count instrument-verified evidence, and the
    // two classes are never summed (the PDF, /verify and the JSON-LD twin all keep them
    // apart). They are carried alongside so a list can state them rather than showing "0
    // devices" for a job that certified 24 operator-attested units.
    //
    // $att is passed in by the list (one batched lookup for the whole page); a single-row
    // caller gets it looked up, so no caller can accidentally emit a row without it.
    if ($att === null) {
        $att = attested_counts([(int)($c['id'] ?? 0)])[(int)($c['id'] ?? 0)] ?? [];
    }
    if ($assets === null) {
        $assets = asset_exception_counts([(int)($c['id'] ?? 0)])[(int)($c['id'] ?? 0)] ?? [];
    }
    $attDevices = (int)($att['devices'] ?? 0);
    $attMedia   = (int)($att['media'] ?? 0);
    return [
        'id'         => (int)$c['id'],
        'cert'       => (string)$c['cert_id'],
        'cocid'      => (string)$c['cocid'],
        'devices'    => (int)$c['devices'],
        'methods'    => (int)$c['methods'],
        'runs'       => (int)$c['runs'],
        'attested_devices' => $attDevices,
        'attested_media'   => $attMedia,
        // The figure a list wants to show: every attested ITEM, units and media together, so
        // the definition lives in one place rather than being re-derived per surface.
        'attested_items'   => $attDevices + $attMedia,
        // Asset accounting: how many assets the certificate records, and how many
        // are not the ordinary in-place case. Zero on a certificate issued before
        // the feature, which is honest: it recorded no asset-level detail.
        'asset_total'      => (int)($assets['total'] ?? 0),
        'asset_exceptions' => (int)($assets['exceptions'] ?? 0),
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
function generate_certificate(array $g, int $userId, bool $canSign, array $destroyed = [], array $assets = [], array $originMap = []): array {
    require_once __DIR__ . '/render_cert.php';
    certs_ensure_schema();
    $certifier = certifier_for_user($userId);

    // Any ALTER TABLE this needs must happen BEFORE the transaction below: DDL
    // in MySQL causes an implicit COMMIT, which leaves the later commit() with
    // "There is no active transaction" and fails the whole request. That is not
    // hypothetical — it took out certificate generation the first time a new
    // drive column was added (2026-10-22).
    certificate_drives_ensure_schema();

    // Operator strategy: the report's own operator when present, otherwise the
    // account holder who generated the certificate (name, else email).
    $g['operator'] = operator_for_pdf($g, $userId);

    $pdfDir = __DIR__ . '/certs';
    if (!is_dir($pdfDir)) { @mkdir($pdfDir, 0775, true); }

    $cocid = (string)$g['cocid'];
    $issuedAt = gmdate('Y-m-d H:i:s');
    // The same instant in the format the printed code signs. Built before
    // rendering because the code is drawn INTO the PDF; it attests the
    // certificate id + issue time only (a hash of the PDF cannot be printed
    // inside the PDF it hashes — see cert_code_message()).
    $issuedAtCode = gmdate('Y-m-d\TH:i:s\Z');
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM certificates WHERE user_id IN ($ph) AND cocid = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute(array_merge($ids, [$cocid]));
    $existing = $stmt->fetch();

    // Build + sign + write the machine-readable JSON-LD beside the PDF, from
    // the same rendered data. Returns ['path', 'sha', 'ts'] — path is '' when
    // signing is unavailable, which must never block PDF issuance.
    $attested = [];
    $finalAssets = $assets;
    $writeJson = function (array $rendered, array $grp, string $certId) use ($certifier, $issuedAt, &$attested, $assets): array {
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
        ], $rendered['drives'], $grp['reports'], $certifier, $attested, $rendered['assets'] ?? $assets);
        $signed = jsonld_sign_document($doc);
        if ($signed === null) { return ['path' => '', 'sha' => '', 'ts' => 'none']; }
        return ['path' => jsonld_write($signed['json'], $certId), 'sha' => $signed['sha256'], 'ts' => $signed['ts_state']];
    };

    if ($existing !== false) {
        // One COCID = one consolidated cert: merge and re-render the same cert.
        $merged = merge_group($g, load_cert_drives((int)$existing['id']), load_cert_reports((int)$existing['id']), $existing);
        // Re-apply the freshly resolved origin to drives that were merged in from
        // the stored certificate (which may predate this feature or carry an older
        // link). A re-issue is an explicit act, so it reads the current links.
        foreach ($merged['drives'] as $i => $drv) {
            $ms = mp_key((string)($drv['serial'] ?? ''));
            if ($ms !== '' && isset($originMap[$ms])) {
                $merged['drives'][$i] = array_merge($drv, $originMap[$ms]);
            }
        }
        $certId = (string)$existing['cert_id'];
        // Manually recorded destruction lives with the certificate, not in the report
        // merge: load it and hand it to the renderer as its own (attested) section.
        $attested = manual_attested_load((int)$existing['id']);
        $rendered = render_certificate_pdf($merged, $certId, $canSign, $certifier, $destroyed, $attested,
            (string)cert_offline_marker((string)cert_offline_code($certId, $issuedAtCode)), $assets);
        $groupPath = $certId . '.pdf';
        $written = $pdfDir . '/' . $groupPath;
        @file_put_contents($written, $rendered['data']);
        $finalAssets = $rendered['assets'] ?? $assets;
        $j = $writeJson($rendered, $merged, $certId);

        try {
            db()->beginTransaction();
            $stmt = db()->prepare(
                'UPDATE certificates SET devices = ?, methods = ?, runs = ?, first_ts = ?, last_ts = ?, sha_state = ?, sig_state = ?, pdf_sha256 = ?, pdf_path = ?, json_path = ?, json_sha256 = ?, json_ts_state = ?, is_draft = 0, issued_at = ? WHERE id = ?'
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
        $rendered = render_certificate_pdf($g, $certId, $canSign, $certifier, $destroyed, [],
            (string)cert_offline_marker((string)cert_offline_code($certId, $issuedAtCode)), $assets);
        $groupPath = $certId . '.pdf';
        $written = $pdfDir . '/' . $groupPath;
        @file_put_contents($written, $rendered['data']);
        $finalAssets = $rendered['assets'] ?? $assets;
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
    // Freeze the asset accounting onto the certificate beside the drive rows. The
    // certificate is a snapshot: /verify and a re-download read this, never a fresh
    // resolution of reports that may have changed since the document was issued.
    if ($cert !== null) {
        try {
            certificate_assets_save((int)$cert['id'], $finalAssets);
        } catch (Throwable $e) {
            error_log('certificate_assets_save: ' . $e->getMessage());
        }
    }
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

    // Draft certificates (a job whose only content is manually recorded destruction,
    // not yet generated) are job records, not issued certificates, so they must not
    // appear as phantom entries in the list. Keyed on the explicit is_draft flag —
    // never on pdf_path, which is empty on 26 of the 45 production certificates.
    $where = 'user_id IN (' . implode(',', array_fill(0, count(org_member_ids((int)$u['id'])), '?')) . ")"
        . " AND is_draft = 0";
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
    $rows = $stmt->fetchAll();

    // Attested counts for this page, in one batched lookup rather than two queries per row.
    // Kept out of the WHERE/SELECT above so the verified figures stay exactly what they were:
    // an attested item is not instrument-verified evidence and must not inflate them.
    $att = attested_counts(array_map(static fn($r) => (int)$r['id'], $rows));
    $assetCounts = asset_exception_counts(array_map(static fn($r) => (int)$r['id'], $rows));
    $certs = array_map(
        static fn($r) => cert_row($r, $att[(int)$r['id']] ?? [], $assetCounts[(int)$r['id']] ?? []),
        $rows
    );
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
    $out['assets'] = certificate_assets_load((int)$c['id']);
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
    // visible-ok: fetching ONE owned row to render its PDF — a hidden upload is
    // still the owner's evidence and is reachable from "Show removed".
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
    // Operator notes (e.g. "damaged screen") — empty → omitted from the PDF.
    $raw['notes'] = (string)($r['notes'] ?? '');
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
    // visible-ok: same as the diagnostics PDF — one owned row, by id.
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
    if ($phase !== '' && !in_array($phase, ['idle', 'wiping', 'done', 'failed', 'no_media'], true)) {
        $phase = '';
    }
    $drivesTotal  = max(0, (int)($d['drives_total'] ?? 0));
    $drivesDone   = max(0, (int)($d['drives_done'] ?? 0));
    $drivesFailed = max(0, (int)($d['drives_failed'] ?? 0));

    // Live wipe progress (optional; only newer appliances send it). -1 means
    // "indeterminate / unknown" and is the safe default for older appliances.
    $progressPct = (int)($d['progress_pct'] ?? -1);
    if ($progressPct < -1 || $progressPct > 100) $progressPct = -1;
    $progressEtaSec = (int)($d['progress_eta_sec'] ?? -1);
    if ($progressEtaSec < -1) $progressEtaSec = -1;

    // WHICH drive failed and why (e.g. "S3Z8NB0K123 (sanitize stalled)").
    // Optional: older appliances never send it, and the column is bounded.
    $failedDevices = trim((string)($d['failed_devices'] ?? ''));
    if ($failedDevices !== '') {
        $failedDevices = preg_replace('/[\x00-\x1f]/', ' ', $failedDevices) ?? '';
        if (strlen($failedDevices) > 255) $failedDevices = substr($failedDevices, 0, 255);
    }

    presence_ensure_schema();
    presence_heartbeat((int)$owner['id'], $serial, $uuid, $lanIp, $phase, $drivesTotal, $drivesDone, $drivesFailed, $progressPct, $progressEtaSec, $touchPhase, $failedDevices);
    json_out(['ok' => true]);
}

// GET /api/mdm/devices — dashboard device list (session auth).
if ($method === 'GET' && $route === '/mdm/devices') {
    $u = auth_require();
    json_out(['ok' => true, 'devices' => mdm_devices((int)$u['id'])]);
}

// ---------------------------------------------------------------------------
// Legacy BIOS-unlock routes. A clear is now a `bios_unlock` command in the
// unified queue (see /api/devices/commands below) — but appliances already in
// the field poll these paths, so they stay as a thin compatibility layer that
// claims from and reports to the SAME queue, which keeps each command single-
// claim. New integrations should use /api/devices/commands.
// ---------------------------------------------------------------------------

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
    $id      = (int)($d['id'] ?? 0);
    $result  = trim((string)($d['result'] ?? ''));
    $detail  = trim((string)($d['detail'] ?? ''));
    $verdict = trim((string)($d['verdict'] ?? ''));
    // The session's CoC, stamped by the appliance at result time. Optional: a
    // session that never had one leaves the column empty.
    $cocid   = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($d['cocid'] ?? '')) ?? '', 0, 64);
    if ($id <= 0) {
        fail(400, 'Invalid command id.');
    }
    if (!in_array($result, ['cleared', 'failed', 'unsupported'], true)) {
        fail(400, 'Invalid result.');
    }

    // unlock_report() clips the detail to its column and derives the verdict
    // when the appliance did not send one.
    if (!unlock_report((int)$owner['id'], $id, $result, $detail, $verdict, $cocid)) {
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
    if (!in_array($command, ['shutdown', 'reboot', 'wipe', 'bios_unlock'], true)) {
        fail(400, 'Command must be "shutdown", "reboot", "wipe" or "bios_unlock".');
    }

    // A BIOS unlock is a command in this queue like any other. The password is
    // encrypted at rest (never stored in the clear, never echoed to the
    // dashboard) and rides in the command's options.
    if ($command === 'bios_unlock') {
        $password = (string)($d['password'] ?? '');
        if ($password === '' || strlen($password) > 255) {
            fail(400, 'Password required (max 255 chars).');
        }
        $id = unlock_enqueue((int)$u['id'], $serial, $uuid, $password);
        json_out(['ok' => true, 'id' => $id, 'staged' => true]);
    }

    $options = [];
    if ($command === 'wipe') {
        // Reject staging a wipe while the device is actively erasing. The
        // dashboard's own "wiping" gate is up to a heartbeat window stale, so
        // enforce it server-side too — fail fast with a clear message instead
        // of silently queueing behind an in-progress wipe.
        $presence = presence_map((int)$u['id']);
        if (presence_is_online($serial, $uuid, $presence)) {
            $erasure = presence_erasure($serial, $uuid, $presence);
            if (is_array($erasure) && ($erasure['phase'] ?? '') === 'wiping') {
                fail(409, 'An erasure is already in progress on this device.');
            }
        }

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
    $uuid   = trim((string)($_GET['uuid'] ?? ''));
    if ($serial === '' || $serial === 'N/A') {
        fail(400, 'Serial required.');
    }
    if (strlen($uuid) > 64) {
        $uuid = '';
    }

    // The appliance declares which command types it can execute. Anything absent
    // defaults to the three it has always known, so a build already in the field
    // is never handed a command it would refuse with "unknown command" — an
    // unlock staged for a machine on an older image waits for the legacy
    // /api/bios/unlock/pending route instead. A newer build adds bios_unlock.
    $want = trim((string)($_GET['commands'] ?? ''));
    $commands = ['shutdown', 'reboot', 'wipe'];
    if ($want !== '') {
        $commands = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $want)),
            static fn(string $c): bool => in_array($c, ['shutdown', 'reboot', 'wipe', 'bios_unlock'], true)
        )));
        if ($commands === []) {
            $commands = ['shutdown', 'reboot', 'wipe'];
        }
    }

    $cmd = remote_claim((int)$owner['id'], $serial, $uuid, $commands);
    if ($cmd === null) {
        json_out(['ok' => true, 'pending' => false]);
    }
    $out = ['ok' => true, 'pending' => true, 'id' => $cmd['id'], 'command' => $cmd['command'], 'options' => $cmd['options']];
    if ($cmd['command'] === 'bios_unlock') {
        // The claim is the single point where the password leaves the database,
        // and only the appliance ever sees it. A row whose password cannot be
        // decrypted is resolved as a failure rather than handed over blind —
        // the appliance could never act on it.
        $claimed = unlock_claimed_with_password($cmd);
        if ($claimed === null) {
            remote_report((int)$owner['id'], (int)$cmd['id'], 'failed', 'stored password could not be decrypted');
            json_out(['ok' => true, 'pending' => false]);
        }
        $out['password']     = $claimed['password'];
        $out['password_b64'] = $claimed['password_b64'];
    }
    json_out($out);
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
    if (!in_array($result, ['done', 'failed', 'deferred', 'cleared', 'unsupported'], true)) {
        fail(400, 'Invalid result.');
    }

    // A BIOS unlock reports its own vocabulary (cleared|failed|unsupported) with
    // an optional verdict; remote_report maps that onto the queue's statuses and
    // records which appliance build produced it, plus the session's CoC.
    $cocid = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($d['cocid'] ?? '')) ?? '', 0, 64);
    if (!remote_report((int)$owner['id'], $id, $result, mb_substr($detail, 0, 255), mb_substr(trim((string)($d['verdict'] ?? '')), 0, 24), $cocid)) {
        fail(409, 'Command is not dispatched.');
    }
    json_out(['ok' => true]);
}

// GET /api/devices/commands?serial= — latest command state (dashboard). Session auth.
if ($method === 'GET' && $route === '/devices/commands') {
    $u = auth_require();
    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($serial === '') {
        fail(400, 'Serial required.');
    }
    // One queue, two views: `command` drives the power actions and `unlock` the
    // BIOS clear, both read from the same rows, so the card can never show two
    // different pictures of what is staged. The full history for a machine lives
    // in the Log expansion (GET /api/devices/events).
    json_out([
        'ok'      => true,
        'command' => remote_latest((int)$u['id'], $serial),
        'unlock'  => unlock_latest((int)$u['id'], $serial),
    ]);
}

// GET /api/devices/events?serial=&uuid= — every event recorded for one device:
// remote commands and their results, Autopilot checks and hash captures,
// appliance registrations and the drives that ended up in a certificate. The
// dashboard merges this with the report submissions it already holds for the
// device, and renders the lot as that row's Log expansion. Session auth.
if ($method === 'GET' && $route === '/devices/events') {
    $u = auth_require();
    $serial = trim((string)($_GET['serial'] ?? ''));
    $uuid   = trim((string)($_GET['uuid'] ?? ''));
    if ($serial === '' && $uuid === '') {
        fail(400, 'Serial required.');
    }
    if (strlen($serial) > 255 || strlen($uuid) > 64) {
        fail(400, 'Field too long.');
    }
    json_out(['ok' => true, 'events' => device_events((int)$u['id'], $serial, $uuid)]);
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
    if (mdm_device_is_apple((int)$u['id'], $serial, $uuid)) {
        fail(400, 'Apple devices are not enrolled in Windows Autopilot.');
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

// POST /api/mdm/batch — queue an MDM (Autopilot) check for every device matching
// the dashboard's current view (q + source + ff). The view IS the selection:
// `ff=…,mdm:unchecked` can only match a device that has a staged hash and no job
// at all, so there is nothing to filter out afterwards. Session auth.
//
// The WHOLE batch is refused when the balance cannot cover it. The worker debits
// one credit per live probe AFTER the probe, so a batch larger than the balance
// would run checks it cannot pay for and only log the shortfall. `force` is
// always 0 here: a cached verdict within 24h is free and never touches Graph.
if ($method === 'POST' && $route === '/mdm/batch') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $q = trim((string)($d['q'] ?? ''));
    $source = list_source($d['source'] ?? '');
    $ff = (string)($d['ff'] ?? '');
    $dry = !empty($d['dry_run']);
    // Its OWN bucket, and a 2-second gap rather than the 30 used for device probes.
    // rate_limit() is a minimum INTERVAL per (scope, IP), not a per-minute quota, so
    // sharing the 'mdm' scope meant the dry run blocked its own confirmation for 30
    // seconds — and it also shared a budget with the appliance's /mdm/autopilot, so a
    // machine checking in could lock the operator out of the dashboard.
    rate_limit('mdm_batch', 2);

    $gate = mdm_gate((int)$u['id']);
    if (!$gate['allowed']) {
        fail(402, $gate['reason'] === 'free_tier'
            ? 'MDM check is a paid feature — upgrade your licence on the Billing page.'
            : 'Insufficient credits — top up on the Billing page.');
    }

    // Derive the set exactly as the list does, so the batch can never drift from
    // what was on screen (or from what the pager said was there).
    $res = load_devices((int)$u['id'], ['q' => $q, 'page' => 1, 'per' => 10,
        'sort' => 'status', 'dir' => -1, 'source' => $source, 'ff' => $ff, 'all' => true]);

    $eligible = [];
    $skipped = [];
    foreach (($res['devices'] ?? []) as $dev) {
        // The only gate is the captured hardware hash. A device that has already
        // been checked is still scheduled — the caller forces the re-probe — and so
        // is one whose last verdict was Locked. A check already in flight is the one
        // exception: queueing it again would buy the same probe twice.
        $state = device_mdm_schedule_state($dev);
        if ($state !== 'ready') {
            $serial = trim((string)($dev['sysserial'] ?? ''));
            $skipped[] = [
                'serial' => $serial !== '' ? $serial : '(no serial)',
                'reason' => $state === 'in_flight' ? 'already queued' : 'no captured hash',
            ];
            continue;
        }
        $serial = trim((string)($dev['sysserial'] ?? ''));
        $uuid = trim((string)($dev['systemuuid'] ?? ''));
        if ($serial === '' || $serial === 'N/A' || $uuid === '' || $uuid === 'N/A') {
            $skipped[] = ['serial' => $serial !== '' ? $serial : '(no serial)', 'reason' => 'no serial/UUID'];
            continue;
        }
        if (mdm_staged_hash((int)$u['id'], $serial, $uuid) === null) {
            $skipped[] = ['serial' => $serial, 'reason' => 'no captured hash'];
            continue;
        }
        $eligible[] = ['serial' => $serial, 'uuid' => $uuid];
    }

    $need = count($eligible);
    $balance = (int)$gate['balance'];
    if ($need > $balance) {
        fail(402, 'This batch needs ' . $need . ' credit' . ($need === 1 ? '' : 's')
            . ' but the balance is ' . $balance . '. Nothing has been queued — top up on the Billing page.');
    }

    if ($dry) {
        json_out(['ok' => true, 'dry_run' => true, 'count' => $need, 'credits' => $need,
            'total' => (int)($res['total'] ?? 0),
            'balance' => $balance, 'balance_after' => $balance - $need, 'skipped' => $skipped]);
    }

    mdm_ensure_schema();
    $queued = 0;
    foreach ($eligible as $e) {
        // force=true: an already-checked device must actually be re-probed. With
        // force=false mdm_enqueue_job() returns the EXISTING job id and inserts
        // nothing, so "schedule everything in the view" would silently do nothing
        // for every device that had been checked before.
        if (mdm_enqueue_job((int)$u['id'], $e['serial'], $e['uuid'], true) > 0) { $queued++; }
    }
    json_out(['ok' => true, 'queued' => $queued, 'credits' => $queued,
        'balance' => $balance, 'balance_after' => $balance - $queued, 'skipped' => $skipped]);
}

// GET /api/reports — the current user's uploaded reports (raw evidence).
// Optional `q` searches drive/system serials and other stored data.
if ($method === 'GET' && $route === '/reports') {
    $u = auth_require();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $cocid = (string)($_GET['cocid'] ?? '');
    $q = trim((string)($_GET['q'] ?? ''));
    // "Show removed" asks for the hidden (voided) rows back; every other view is
    // served the visible set only.
    $showVoided = (string)($_GET['voided'] ?? '') === '1';
    $res = load_user_reports((int)$u['id'], $cocid !== '' ? $cocid : null, $q !== '' ? $q : null, $page, 10, $showVoided);
    json_out(['ok' => true, 'reports' => $res['reports'], 'total' => $res['total'], 'page' => $res['page'], 'per' => $res['per'], 'q' => $q,
        'show_voided' => $showVoided]);
}

// POST /api/reports/cocid — correct a mistyped Chain of Custody ID. One report
// (`report_id`), or a whole job (`from` + `to`). Session + CSRF auth, org-scoped
// by report ownership, and only for the owner and org admins: a read-only member
// may upload evidence but not re-label it.
//
// This is a RENAME, never a delete: the payload, the file names and the audit
// trail all move together (research/cocid-correction). Refused while an issued
// certificate cites the job.
if ($method === 'POST' && $route === '/reports/cocid') {
    auth_csrf_verify();
    $u = auth_require();
    if (!reports_may_correct((int)$u['id'])) {
        fail(403, 'Only the account owner or an organisation admin can correct a Chain of Custody ID.');
    }
    $d = json_body();
    $newCocid = trim((string)($d['cocid'] ?? ''));
    $reason = trim((string)($d['reason'] ?? ''));
    if ($reason === '') {
        fail(400, 'Say why the COCID is being corrected.');
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $from = trim((string)($d['from'] ?? ''));
    if ($from !== '') {
        $res = job_cocid_rename((int)$u['id'], $from, $newCocid, $reason, $ip, (int)$u['id']);
    } else {
        $reportId = (int)($d['report_id'] ?? 0);
        if ($reportId <= 0) {
            fail(400, 'Provide the report id, or the job COCID to rename.');
        }
        $res = report_cocid_set((int)$u['id'], $reportId, $newCocid, $reason, $ip, (int)$u['id']);
    }
    if (empty($res['ok'])) {
        fail((int)($res['status'] ?? 400), (string)($res['error'] ?? 'Could not correct the COCID.'));
    }
    json_out(['ok' => true, 'from' => $res['from'] ?? '', 'to' => $res['to'] ?? '',
        'changed' => (int)($res['changed'] ?? 0), 'attested' => (int)($res['attested'] ?? 0)]);
}

// POST /api/reports/void — hide a junk upload from every list, picker and
// certificate (reversible, audited, and it refunds no credit).
if ($method === 'POST' && $route === '/reports/void') {
    auth_csrf_verify();
    $u = auth_require();
    if (!reports_may_correct((int)$u['id'])) {
        fail(403, 'Only the account owner or an organisation admin can remove an upload.');
    }
    $d = json_body();
    $reportId = (int)($d['report_id'] ?? 0);
    $reason = trim((string)($d['reason'] ?? ''));
    if ($reportId <= 0) {
        fail(400, 'Provide the report id.');
    }
    if ($reason === '') {
        fail(400, 'Say why the upload is being removed.');
    }
    $res = report_void_set((int)$u['id'], $reportId, true, $reason, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (int)$u['id']);
    if (empty($res['ok'])) {
        fail((int)($res['status'] ?? 400), (string)($res['error'] ?? 'Could not remove the upload.'));
    }
    json_out(['ok' => true, 'voided' => true, 'report_id' => $reportId]);
}

// POST /api/reports/unvoid — put a removed upload back in the lists.
if ($method === 'POST' && $route === '/reports/unvoid') {
    auth_csrf_verify();
    $u = auth_require();
    if (!reports_may_correct((int)$u['id'])) {
        fail(403, 'Only the account owner or an organisation admin can restore an upload.');
    }
    $d = json_body();
    $reportId = (int)($d['report_id'] ?? 0);
    if ($reportId <= 0) {
        fail(400, 'Provide the report id.');
    }
    $res = report_void_set((int)$u['id'], $reportId, false, '', (string)($_SERVER['REMOTE_ADDR'] ?? ''), (int)$u['id']);
    if (empty($res['ok'])) {
        fail((int)($res['status'] ?? 400), (string)($res['error'] ?? 'Could not restore the upload.'));
    }
    json_out(['ok' => true, 'voided' => false, 'report_id' => $reportId]);
}

// GET /api/reports/cocids — distinct COCIDs for the cert generator's search/select.
if ($method === 'GET' && count($seg) === 2 && $seg[0] === 'reports' && $seg[1] === 'cocids') {
    $u = auth_require();
    json_out(['ok' => true, 'cocids' => distinct_cocids((int)$u['id'])]);
}

// GET /api/devices/version — cheap change fingerprint for the Devices tab.
// The dashboard polls this every 10s and only re-fetches /api/devices when it
// changes, so an idle page doesn't re-decode every report payload each tick.
if ($method === 'GET' && $route === '/devices/version') {
    $u = auth_require();
    json_out(['ok' => true, 'version' => devices_list_version((int)$u['id'])]);
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
    // Normalised (and passed through load_devices()'s own normaliser) so 'asc', ±1 and a
    // missing value cannot be read three different ways.
    $dir = query_dir($_GET['dir'] ?? '');
    // all | verified | attested — the three-way switch on the tab.
    $source = list_source($_GET['source'] ?? '');
    // Advanced filters from the Filters modal: `facet:value,facet:value`. Parsed
    // and whitelisted inside load_devices() (device_filters_parse), which also
    // returns the canonical form so the page can rewrite a hand-edited URL.
    $ff = (string)($_GET['ff'] ?? '');

    $res = load_devices((int)$u['id'], ['q' => $q, 'page' => $page, 'per' => $per,
        'sort' => $sort, 'dir' => $dir, 'source' => $source, 'ff' => $ff,
        // Processing hosts are hidden by default; this is the explicit opt-in.
        'bench' => !empty($_GET['bench'])]);

    json_out([
        'ok'      => true,
        'devices' => $res['devices'],
        'total'   => $res['total'],
        // Forwarded explicitly: this response is rebuilt here, so a key added to
        // load_devices() is NOT automatically visible to the dashboard. Attested
        // units are included in `total`, so without this the page cannot say how
        // many of them are attestations.
        'attested_total' => (int)($res['attested_total'] ?? 0),
        // Fleet counts for the Devices summary strip. Forwarded explicitly for
        // the same reason as attested_total: this array is rebuilt here.
        'summary' => is_array($res['summary'] ?? null) ? $res['summary'] : null,
        // Per-option counts for the Filters modal, over the source+search set.
        'facets'  => is_array($res['facets'] ?? null) ? $res['facets'] : null,
        // Canonical echo of what the server actually applied — anything it did
        // not recognise is absent from this string, and the page adopts it so a
        // stale or hand-edited URL is corrected rather than silently ignored.
        'ff'      => (string)($res['ff'] ?? ''),
        'page'    => $page,
        'per'     => $per,
        'source'  => $source,
        'version' => devices_list_version((int)$u['id']),
    ]);
}

// GET /api/devices/export — download the device inventory as CSV, JSON or XLS,
// restricted to the requested `fields`. It is the VIEW, unpaginated: the same
// search, source switch, facet filters and order as GET /api/devices, because
// an export that ignores the filters on screen is a different answer to the
// question the operator asked. Session auth.
if ($method === 'GET' && $route === '/devices/export') {
    $u = auth_require();
    $q = trim((string)($_GET['q'] ?? ''));
    $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'json', 'xls'], true)) $format = 'csv';

    $cols = [
        'system' => 'System', 'sysserial' => 'Serial', 'bbserial' => 'Baseboard serial',
        'chassisserial' => 'Chassis serial', 'systemuuid' => 'System UUID',
        'cpu' => 'CPU', 'cpu_spec' => 'CPU spec', 'gpu' => 'GPU', 'ram' => 'RAM',
        'display' => 'Display', 'wifi' => 'Wi-Fi', 'sku' => 'SKU', 'asset_tag' => 'Asset tag',
        'bios_vendor' => 'BIOS vendor', 'biosversion' => 'BIOS version', 'biosdate' => 'BIOS date',
        'board' => 'Motherboard', 'bioslock' => 'BIOS lock', 'bioslockmethod' => 'BIOS lock method',
        'tpm' => 'TPM', 'macs' => 'MAC address', 'storage_controllers' => 'Storage controllers',
        'battery' => 'Battery', 'secure_boot' => 'Secure boot', 'dimms' => 'DIMMs',
        'product_key' => 'Windows product key', 'product_key_id' => 'Product key ID',
        'mdm_status' => 'MDM status', 'mdm_verdict' => 'MDM verdict', 'mdm_label' => 'MDM label',
        'refurb_grade' => 'Refurb grade', 'notes' => 'Notes',
        'status' => 'Status', 'lan_ip' => 'LAN IP', 'last_seen_at' => 'Last seen',
        'activity_at' => 'Latest event', 'bench' => 'Processing host',
        'drive_count' => 'Drives', 'first' => 'First report', 'last' => 'Last report',
        'report_id' => 'Report ID', 'digital_identifier' => 'Digital identifier',
    ];
    $selected = export_field_keys($cols, $_GET['fields'] ?? null);

    // One loader, one answer: the same query parameters the list uses, with
    // all:true. The hand-rolled search that used to live here was a second
    // implementation of `q` (with its own field list) and knew nothing about
    // `ff` or the source switch.
    $exportQuery = [
        'q'      => $q,
        'ff'     => (string)($_GET['ff'] ?? ''),
        'source' => list_source($_GET['source'] ?? ''),
        'bench'  => !empty($_GET['bench']),
        'all'    => true,
        'page'   => 1,
        'per'    => 100,
    ];
    $sort = trim((string)($_GET['sort'] ?? ''));
    if ($sort !== '') {
        $exportQuery['sort'] = $sort;
        $exportQuery['dir']  = query_dir($_GET['dir'] ?? '');
    }
    $devices = load_devices((int)$u['id'], $exportQuery)['devices'];

    $labels = [];
    foreach ($selected as $key) $labels[] = $cols[$key];

    $value = static function (string $key, array $d) {
        if ($key === 'status') return !empty($d['online']) ? 'Online' : 'Offline';
        if ($key === 'drive_count') return (int)($d['drive_count'] ?? 0);
        if ($key === 'bench') return !empty($d['bench']) ? 'Yes' : 'No';
        return (string)($d[$key] ?? '');
    };

    $rows = [];
    foreach ($devices as $d) {
        $row = [];
        foreach ($selected as $key) $row[] = $value($key, $d);
        $rows[] = $row;
    }

    export_send($format, 'tscrub-devices', $labels, $rows);
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

// POST /api/devices/notes — set (or clear, notes='') the operator's free-form
// notes on a diagnostics report (e.g. "damaged screen"). Session + CSRF auth;
// org-scoped by report ownership.
if ($method === 'POST' && $route === '/devices/notes') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $notes = trim((string)($d['notes'] ?? ''));
    $reportId = (int)($d['report_id'] ?? 0);
    if ($reportId <= 0) {
        fail(400, 'Provide the diagnostics report id.');
    }
    if (!report_notes_set((int)$u['id'], $reportId, $notes)) {
        fail(404, 'Diagnostics report not found.');
    }
    json_out(['ok' => true, 'notes' => $notes, 'report_id' => $reportId]);
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
    // Same contract as /devices: `sort` names a column, `dir` is asc/desc (or ±1), and an
    // empty `sort` means the default order — attested rows first, then newest report — so a
    // page that has never been sorted keeps the order it has always had.
    $sort = trim((string)($_GET['sort'] ?? ''));
    $dir = query_dir($_GET['dir'] ?? '');
    // all | verified | attested — the three-way switch on the tab.
    $source = list_source($_GET['source'] ?? '');

    $res = load_drives((int)$u['id'], ['q' => $q, 'page' => $page, 'per' => $per,
        'sort' => $sort, 'dir' => $dir, 'source' => $source]);

    json_out([
        'ok'     => true,
        'drives' => $res['drives'],
        'total'  => $res['total'],
        // As on /devices: rebuilt here, so the attested count must be forwarded.
        'attested_total' => (int)($res['attested_total'] ?? 0),
        'page'   => $page,
        'per'    => $per,
        'sort'   => $sort,
        'dir'    => $dir > 0 ? 'asc' : 'desc',
        'source' => $source,
    ]);
}

// GET /api/drives/export — download the drive inventory as CSV, JSON or XLS,
// restricted to the requested `fields`. It is the VIEW, unpaginated: the same
// search, source switch and order as GET /api/drives. Session auth.
if ($method === 'GET' && $route === '/drives/export') {
    $u = auth_require();
    $q = trim((string)($_GET['q'] ?? ''));
    $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'json', 'xls'], true)) $format = 'csv';

    $cols = [
        'serial' => 'Serial', 'model' => 'Model', 'size' => 'Size', 'bus' => 'Bus', 'type' => 'Type',
        'status' => 'Status', 'cls' => 'Class', 'method' => 'Method', 'cert' => 'Certification',
        'ts' => 'Erased', 'cocid' => 'CoC', 'system' => 'System', 'sysserial' => 'System serial',
        'grade' => 'Grade', 'grade_reason' => 'Grade reason',
        'smart' => 'SMART (pre)', 'tempc' => 'Temp C (pre)', 'poweronhours' => 'Power-on hours (pre)',
        'powercycles' => 'Power cycles', 'reallocsectors' => 'Reallocated sectors',
        'pctused' => 'Used %', 'availspare' => 'Available spare', 'tbw_tb' => 'TBW (TB)',
        'smartpost' => 'SMART (post)', 'tempcpost' => 'Temp C (post)', 'poweronhourspost' => 'Power-on hours (post)',
        'firmware' => 'Firmware', 'sector_size' => 'Sector size', 'sectors' => 'Sectors',
        'hpa' => 'HPA', 'dco' => 'DCO', 'hpa_result' => 'HPA result', 'dco_result' => 'DCO result',
        'sed_status' => 'SED status', 'selftest' => 'Self-test', 'reallocsectorspost' => 'Reallocated (post)',
        'verify_result' => 'Verification', 'verify_sectors' => 'Verified sectors',
        'standard' => 'Overwrite standard', 'passes' => 'Overwrite passes', 'erase_engine' => 'Erase engine',
        'verify_technique' => 'Verification technique', 'verify_coverage_pct' => 'Verified coverage %',
        'sanitization_standard' => 'Sanitisation standard', 'sanitization_level' => 'Sanitisation level',
        'sanitization_shortfall' => 'Claim shortfall',
        'format_result' => 'Format result', 'format_fs' => 'Formatted', 'format_scheme' => 'Partition scheme', 'format_label' => 'Volume label',
        'psid_revert' => 'PSID revert', 'lock_kind' => 'TCG lock', 'ppi_requested' => 'PPI requested', 'ppi_cleared' => 'PPI cleared',
        'start_time' => 'Start time', 'end_time' => 'End time', 'duration_secs' => 'Duration (s)',
        'sku' => 'SKU', 'asset_tag' => 'Asset tag', 'bios_vendor' => 'BIOS vendor', 'board' => 'Motherboard',
        'tpm' => 'TPM', 'macs' => 'MAC address', 'storage_controllers' => 'Storage controllers',
        'tool_version' => 'Tool version', 'operator' => 'Operator', 'validator' => 'Validator',
        'media_source' => 'Media source', 'media_destination' => 'Media destination',
        'reports' => 'Reports', 'report_id' => 'Report ID', 'uploaded_at' => 'Uploaded',
    ];
    $selected = export_field_keys($cols, $_GET['fields'] ?? null);

    // One loader, one answer: the same query parameters the list uses, with
    // all:true. The hand-rolled search that used to live here was a second
    // implementation of `q` and knew nothing about the source switch.
    $exportQuery = [
        'q'      => $q,
        'source' => list_source($_GET['source'] ?? ''),
        'all'    => true,
        'page'   => 1,
        'per'    => 100,
    ];
    $sort = trim((string)($_GET['sort'] ?? ''));
    if ($sort !== '') {
        $exportQuery['sort'] = $sort;
        $exportQuery['dir']  = query_dir($_GET['dir'] ?? '');
    }
    $drives = load_drives((int)$u['id'], $exportQuery)['drives'];

    $labels = [];
    foreach ($selected as $key) $labels[] = $cols[$key];

    $value = static function (string $key, array $d) {
        if ($key === 'reports') return (int)($d['reports'] ?? 0);
        return (string)($d[$key] ?? '');
    };

    $rows = [];
    foreach ($drives as $d) {
        $row = [];
        foreach ($selected as $key) $row[] = $value($key, $d);
        $rows[] = $row;
    }

    export_send($format, 'tscrub-drives', $labels, $rows);
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

// ---- Asset → medium provenance (research/media-provenance) -------------------
// A drive pulled from an asset and erased on a bench machine is evidenced by a
// report whose SystemSerial is the bench. These routes record the operator's
// declaration of where the medium came from; observations are derived from the
// reports themselves. Both are surfaced by the certificate, with provenance
// printed separately from the erasure evidence.

// GET /api/media/links?cocid= — declared links for one job.
if ($method === 'GET' && $route === '/media/links') {
    $u = auth_require();
    $cocid = trim((string)($_GET['cocid'] ?? ''));
    if ($cocid === '' || mb_strlen($cocid, 'UTF-8') > 64) {
        fail(400, 'Provide a valid Chain of Custody ID.');
    }
    json_out(['ok' => true, 'links' => media_links_for_cocid((int)$u['id'], $cocid)]);
}

// POST /api/media/link — declare that a medium came out of an asset.
// Refused when it contradicts an observation made on a different host.
if ($method === 'POST' && $route === '/media/link') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $res = media_link_declare(
        (int)$u['id'],
        (string)($d['cocid'] ?? ''),
        (string)($d['asset_serial'] ?? ''),
        (string)($d['media_serial'] ?? ''),
        (string)($d['reason'] ?? ''),
        (string)($d['note'] ?? ''),
        (int)$u['id']
    );
    if (!$res['ok']) {
        fail(409, (string)$res['error']);
    }
    json_out(['ok' => true, 'id' => (int)$res['id']], 201);
}

// DELETE /api/media/link/{id} — remove a declared link.
if ($method === 'DELETE' && count($seg) === 3 && $seg[0] === 'media' && $seg[1] === 'link') {
    auth_csrf_verify();
    $u = auth_require();
    if (!media_link_delete((int)$u['id'], (int)$seg[2])) {
        fail(404, 'Link not found.');
    }
    json_out(['ok' => true]);
}

// GET /api/devices/processing-hosts — our bench machines.
if ($method === 'GET' && $route === '/devices/processing-hosts') {
    $u = auth_require();
    $ids = org_member_ids((int)$u['id']);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $q = db()->prepare("SELECT device_key, label, created_at FROM processing_hosts WHERE user_id IN ($ph) ORDER BY device_key");
        $q->execute($ids);
        json_out(['ok' => true, 'hosts' => $q->fetchAll()]);
    } catch (Throwable $e) {
        json_out(['ok' => true, 'hosts' => []]);
    }
}

// POST /api/devices/processing-host — mark/unmark a host as a bench machine.
if ($method === 'POST' && $route === '/devices/processing-host') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();
    $on = !empty($d['on']);
    // The list's _key is the normalised device key; when a caller does not have
    // it, derive it from the identity fields rather than trusting a bare serial
    // (a placeholder system serial may key the row on the baseboard or UUID).
    $key = (string)($d['device_key'] ?? '');
    if ($key === '') {
        $key = device_key([
            'sysserial'  => (string)($d['serial'] ?? ''),
            'bbserial'   => (string)($d['bbserial'] ?? ''),
            'systemuuid' => (string)($d['uuid'] ?? ''),
        ]);
    }
    if (!processing_host_set((int)$u['id'], $key, $on, (string)($d['label'] ?? ''), (int)$u['id'])) {
        fail(400, 'Could not update that host.');
    }
    json_out(['ok' => true, 'on' => $on]);
}

// POST /api/certificates/devices — record a manually destroyed device (unit) with
// the media it contained. An operator ATTESTATION, never instrument-verified: see
// research/manual-destruction/README.md.
if ($method === 'POST' && $route === '/certificates/devices') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();

    $cocid = trim((string)($d['cocid'] ?? ''));
    if ($cocid === '' || mb_strlen($cocid, 'UTF-8') > 64) {
        fail(400, 'Provide a valid Chain of Custody ID.');
    }

    $rawMedia = (is_array($d['media'] ?? null)) ? $d['media'] : [];
    if (count($rawMedia) < 1) {
        fail(400, 'Record at least one medium — a device that held no storage has nothing to certify here.');
    }
    if (count($rawMedia) > 64) {
        fail(400, 'Too many media on one device (64 maximum).');
    }

    $dev = md_validate_device(is_array($d['device'] ?? null) ? $d['device'] : []);
    if ($dev['errors']) { fail(400, $dev['errors'][0]); }

    $media = [];
    foreach ($rawMedia as $i => $m) {
        $row = is_array($m) ? $m : [];
        // A medium belongs to the device, so it inherits the device's reason and
        // destruction method unless the operator set a different outcome for it. The
        // modal hides those two per-medium selects behind a toggle, so they arrive
        // empty in the normal case — without this, every device save was rejected with
        // "A reason is required".
        if (($row['reason'] ?? '') === '')           { $row['reason'] = $dev['clean']['reason']; }
        if (($row['destroy_method'] ?? '') === '')   { $row['destroy_method'] = $dev['clean']['destroy_method']; }
        $v = md_validate_media($row);
        if ($v['errors']) { fail(400, 'Media ' . ($i + 1) . ': ' . $v['errors'][0]); }
        $media[] = $v['clean'];
    }

    $cert = manual_cert_row((int)$u['id'], $cocid);
    if ($cert === null) { fail(500, 'Could not open a job record for that Chain of Custody ID.'); }

    $res = manual_attested_save_device((int)$cert['id'], $cocid, $dev['clean'], $media, manual_attester($u));
    if (!$res['ok']) { fail(409, $res['error']); }

    json_out(['ok' => true, 'id' => $res['id'], 'cert' => (string)$cert['cert_id']], 201);
}

// POST /api/certificates/media — record one standalone destroyed medium (a drive
// with no device recorded: stripped from a dead host, or never attached at all).
if ($method === 'POST' && $route === '/certificates/media') {
    auth_csrf_verify();
    $u = auth_require();
    $d = json_body();

    $cocid = trim((string)($d['cocid'] ?? ''));
    if ($cocid === '' || mb_strlen($cocid, 'UTF-8') > 64) {
        fail(400, 'Provide a valid Chain of Custody ID.');
    }

    $v = md_validate_media(is_array($d['media'] ?? null) ? $d['media'] : []);
    if ($v['errors']) { fail(400, $v['errors'][0]); }

    $cert = manual_cert_row((int)$u['id'], $cocid);
    if ($cert === null) { fail(500, 'Could not open a job record for that Chain of Custody ID.'); }

    $res = manual_attested_save_media((int)$cert['id'], $cocid, $v['clean'], manual_attester($u));
    if (!$res['ok']) { fail(409, $res['error']); }

    json_out(['ok' => true, 'id' => $res['id'], 'cert' => (string)$cert['cert_id']], 201);
}

// DELETE /api/certificates/devices/{id}?cocid=... — remove an attested device and
// the media recorded with it.
if ($method === 'DELETE' && count($seg) === 3 && $seg[0] === 'certificates' && $seg[1] === 'devices') {
    auth_csrf_verify();
    $u = auth_require();
    $cocid = trim((string)($_GET['cocid'] ?? ''));
    if ($cocid === '') { fail(400, 'Provide the Chain of Custody ID.'); }
    $cert = manual_cert_row((int)$u['id'], $cocid);
    if ($cert === null) { fail(404, 'No job found for that Chain of Custody ID.'); }
    $n = manual_attested_delete((int)$cert['id'], (int)$seg[2], true);
    json_out(['ok' => true, 'removed' => $n]);
}

// DELETE /api/certificates/media/{id}?cocid=... — remove one standalone medium.
if ($method === 'DELETE' && count($seg) === 3 && $seg[0] === 'certificates' && $seg[1] === 'media') {
    auth_csrf_verify();
    $u = auth_require();
    $cocid = trim((string)($_GET['cocid'] ?? ''));
    if ($cocid === '') { fail(400, 'Provide the Chain of Custody ID.'); }
    $cert = manual_cert_row((int)$u['id'], $cocid);
    if ($cert === null) { fail(404, 'No job found for that Chain of Custody ID.'); }
    $n = manual_attested_delete((int)$cert['id'], (int)$seg[2], false);
    json_out(['ok' => true, 'removed' => $n]);
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
    $attestedOnly = false;
    if (!$payloads) {
        // A job can consist ONLY of manually recorded (attested) destruction: a pallet of
        // phones that never ran the appliance, which is the main reason this feature
        // exists. This route used to refuse exactly that case, so a pure-destruction job
        // could never be certified — the draft certificate created with the first attested
        // record IS the job record, and generation has to start from it rather than demand
        // a report. generate_certificate() finds that row and loads the attested records
        // itself; all we need to supply is an empty report group.
        // Read the job row WITHOUT creating one. manual_cert_row() would happily insert an
        // empty draft, so a generate request for a COCID with nothing in it would leave a
        // phantom certificate behind — which is exactly what happened while developing this.
        $ids = org_member_ids((int)$u['id']);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $q   = db()->prepare("SELECT id FROM certificates WHERE user_id IN ($ph) AND cocid = ? ORDER BY id DESC LIMIT 1");
        $q->execute(array_merge($ids, [$cocid]));
        $jobId = (int)$q->fetchColumn();

        $have   = $jobId > 0 ? manual_attested_load($jobId) : ['devices' => [], 'media' => []];
        $counts = md_attested_counts($have);
        if ($counts['devices'] === 0 && $counts['media'] === 0) {
            fail(404, 'No reports found for that Chain of Custody ID. Upload reports first.');
        }
        $attestedOnly = true;
        $payloads = [];
    }

    // Fold every stored report group for this COCID into one consolidated group.
    $g = $attestedOnly ? [
        'cocid'    => $cocid,
        'drives'   => [],
        'reports'  => [],
        'devices'  => 0,
        'methods'  => 0,
        'runs'     => 0,
        'first'    => null,
        'last'     => null,
        'shaState' => 'unverified',
        'sigState' => 'none',
    ] : null;
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

    // Asset → medium provenance. Resolved BEFORE merge_group() dedupes drive
    // rows: a COMPLETED bench row can otherwise replace the FAILED row from the
    // asset, erasing the only link between them (merge_group keeps the higher
    // status per serial). The annotations travel with the drive rows into the
    // per-drive table so the certificate states the origin beside the host.
    $prov = media_provenance_for_cocid((int)$u['id'], $cocid, $g['drives']);
    foreach ($g['drives'] as $i => $drv) {
        $ms = mp_key((string)($drv['serial'] ?? ''));
        if ($ms !== '' && isset($prov['drives'][$ms])) {
            $g['drives'][$i] = array_merge($drv, $prov['drives'][$ms]);
        }
    }
    $assets = $prov['assets'];

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
        // A retained device needs no destruction decision either: its outcome is settled.
        if ($st === 'COMPLETED' || $st === 'DESTROYED' || $st === 'RETAINED') continue;
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
    $out = generate_certificate($g, (int)$u['id'], owner_tier((int)$u['id']) !== 'free', $destroyed, $assets, $prov['drives']);
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

// GET /api/admin/users — supports ?q= (email/name/ID), ?role= and ?status=.
if ($method === 'GET' && $route === '/admin/users') {
    auth_require_admin();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 25;
    $offset = ($page - 1) * $per;
    $q = trim((string)($_GET['q'] ?? ''));
    $role = (string)($_GET['role'] ?? '');
    $status = (string)($_GET['status'] ?? '');
    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = '(email LIKE ? OR name LIKE ? OR id = ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
        $args[] = ctype_digit($q) ? (int)$q : -1;
    }
    if (in_array($role, ['user', 'admin'], true)) { $where[] = 'role = ?'; $args[] = $role; }
    if (in_array($status, ['active', 'suspended'], true)) { $where[] = 'status = ?'; $args[] = $status; }
    $w = $where !== [] ? (' WHERE ' . implode(' AND ', $where)) : '';
    $cnt = db()->prepare("SELECT COUNT(*) FROM users$w");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
    // $per/$offset are validated ints — safe to interpolate (no bind-order juggling).
    $stmt = db()->prepare("SELECT * FROM users$w ORDER BY created_at DESC LIMIT $per OFFSET $offset");
    $stmt->execute($args);
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

// GET /api/admin/audit — admin actions log. Supports ?q= (action/target/admin/IP).
if ($method === 'GET' && $route === '/admin/audit') {
    auth_require_admin();
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;
    $offset = ($page - 1) * $per;
    $q = trim((string)($_GET['q'] ?? ''));
    $w = '';
    $args = [];
    if ($q !== '') {
        $w = ' WHERE (a.action LIKE ? OR a.target LIKE ? OR u.email LIKE ? OR a.ip LIKE ?)';
        $args = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    }
    $cnt = db()->prepare("SELECT COUNT(*) FROM admin_audit_log a JOIN users u ON u.id = a.admin_id$w");
    $cnt->execute($args);
    $total = (int)$cnt->fetchColumn();
    $stmt = db()->prepare(
        "SELECT a.action, a.target, a.ip, a.created_at, u.email AS admin_email
         FROM admin_audit_log a JOIN users u ON u.id = a.admin_id$w
         ORDER BY a.id DESC LIMIT $per OFFSET $offset"
    );
    $stmt->execute($args);
    $rows = array_map(fn($a) => [
        'admin_email' => (string)($a['admin_email'] ?? ''),
        'action'      => (string)$a['action'],
        'target'      => (string)$a['target'],
        'ip'          => (string)$a['ip'],
        'created_at'  => (string)$a['created_at'],
    ], $stmt->fetchAll());
    json_out(['ok' => true, 'audit' => $rows, 'total' => $total, 'page' => $page, 'per' => $per]);
}

// GET /api/admin/mdm-tenant — the Microsoft probe tenant's subscription state.
// Read-only; answers "is this trial, and when does it end" for the dashboard
// banner. Called once per admin session (the client caches it for an hour).
if ($method === 'GET' && $route === '/admin/mdm-tenant') {
    auth_require_admin();
    json_out(['ok' => true, 'tenant' => mdm_tenant_status()]);
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
