<?php
declare(strict_types=1);

// One-off: regenerate every certificate from its stored report payloads.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reports_lib.php';
require_once __DIR__ . '/render_cert.php';

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

function regen_user(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u === false ? null : $u;
}

function regen_tier(int $userId): string {
    $stmt = db()->prepare('SELECT tier FROM licences WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $t = $stmt->fetch();
    return ($t !== false && isset($t['tier']) && $t['tier'] !== null) ? (string)$t['tier'] : 'free';
}

function regen_certifier(array $u): array {
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

$certs = db()->query('SELECT * FROM certificates ORDER BY id')->fetchAll();
$pdfDir = __DIR__ . '/certs';
if (!is_dir($pdfDir)) { @mkdir($pdfDir, 0775, true); }

foreach ($certs as $c) {
    $userId = (int)($c['user_id'] ?? 0);
    $cocid  = (string)($c['cocid'] ?? '');
    if ($userId <= 0 || $cocid === '') { continue; }

    $payloads = load_report_payloads($userId, $cocid);
    if (!$payloads) { continue; }

    $g = null;
    foreach ($payloads as $grp) {
        if ($g === null) { $g = $grp; continue; }
        $g = merge_group($grp, $g['drives'], $g['reports'], [
            'first_ts'  => $g['first'] ?? null,
            'last_ts'   => $g['last'] ?? null,
            'sha_state' => $g['shaState'] ?? 'unverified',
            'sig_state' => $g['sigState'] ?? 'none',
        ]);
    }

    $merged = merge_group($g, load_cert_drives((int)$c['id']), load_cert_reports((int)$c['id']), $c);
    $user = regen_user($userId);
    $canSign = regen_tier($userId) !== 'free';
    $rendered = render_certificate_pdf($merged, (string)$c['cert_id'], $canSign, regen_certifier($user ?? []), []);

    $groupPath = $c['cert_id'] . '.pdf';
    $pdfPath = $pdfDir . '/' . $groupPath;
    @unlink($pdfPath);
    if (@file_put_contents($pdfPath, $rendered['data']) === false) { echo "FAIL {$c['cert_id']}\n"; continue; }
    @chgrp($pdfPath, 'www-data');
    @chmod($pdfPath, 0664);

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
            (int)$c['id'],
        ]);
        rewrite_certificate_details((int)$c['id'], $merged['reports'], $rendered['drives']);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        echo "FAIL {$c['cert_id']}: " . $e->getMessage() . "\n";
        continue;
    }
    echo "OK {$c['cert_id']} devices=" . $rendered['devices'] . "\n";
}
echo "done\n";
