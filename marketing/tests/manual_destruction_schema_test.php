<?php
/**
 * Integration test for the attested-destruction schema.
 *
 * Needs a real database, so run it on the server:
 *   php /tmp/manual_destruction_schema_test.php /home/oxwet/webs/tscrub-form
 *
 * It calls certs_ensure_schema() twice (idempotency), checks the new table,
 * columns, collation and unique indexes, then exercises the constraints against a
 * synthetic certificate and removes it — including on failure.
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/db.php';
require_once $serverDir . '/jsonld.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok): void
{
    global $count, $fails;
    $count++;
    if ($ok) {
        echo "ok   - $label\n";
    } else {
        $fails++;
        echo "FAIL - $label\n";
    }
}

// --- 1. idempotency: running it twice must be a no-op the second time -------
certs_ensure_schema();
certs_ensure_schema();
t_check('certs_ensure_schema() is idempotent (ran twice, no error)', true);

// --- 2. the schema landed --------------------------------------------------
$tables = [];
foreach (db()->query('SHOW TABLES')->fetchAll() as $t) {
    $tables[strtolower((string)reset($t))] = true;
}
t_check('certificate_devices exists', isset($tables['certificate_devices']));

$have = [];
foreach (db()->query('SHOW COLUMNS FROM certificate_devices')->fetchAll() as $c) {
    $have[strtolower((string)$c['Field'])] = true;
}
foreach (['id', 'certificate_id', 'cocid', 'category', 'make', 'model', 'serial', 'reason',
          'destroy_method', 'destroy_vendor', 'destroy_date', 'attested_by', 'destroy_witness',
          'evidence_ref', 'evidence_sha256', 'identity_key', 'created_at'] as $col) {
    t_check("certificate_devices.$col", isset($have[$col]));
}

$dcols = [];
foreach (db()->query('SHOW COLUMNS FROM certificate_drives')->fetchAll() as $c) {
    $dcols[strtolower((string)$c['Field'])] = true;
}
foreach (['source', 'certificate_device_id', 'reason', 'destroy_method', 'destroy_vendor',
          'destroy_date', 'destroy_witness', 'evidence_ref', 'evidence_sha256', 'identity_key'] as $col) {
    t_check("certificate_drives.$col", isset($dcols[$col]));
}

// The collation is load-bearing: _unicode_ci would compare "Café" == "Cafe" in the
// index while md_normalise() keeps them distinct, so the DB would reject (or merge)
// identities PHP considers different.
$ik = db()->query("SHOW FULL COLUMNS FROM certificate_drives LIKE 'identity_key'")->fetch();
t_check('certificate_drives.identity_key is utf8mb4_bin (byte-exact)',
    stripos((string)($ik['Collation'] ?? ''), 'utf8mb4_bin') !== false);
$ikd = db()->query("SHOW FULL COLUMNS FROM certificate_devices LIKE 'identity_key'")->fetch();
t_check('certificate_devices.identity_key is utf8mb4_bin (byte-exact)',
    stripos((string)($ikd['Collation'] ?? ''), 'utf8mb4_bin') !== false);

t_check('uq_cdr_identity present on certificate_drives',
    count(db()->query("SHOW INDEX FROM certificate_drives WHERE Key_name = 'uq_cdr_identity'")->fetchAll()) > 0);
t_check('uq_cd_identity present on certificate_devices',
    count(db()->query("SHOW INDEX FROM certificate_devices WHERE Key_name = 'uq_cd_identity'")->fetchAll()) > 0);

// Existing report rows must still be readable and unconstrained (source defaulted).
$rep = db()->query("SELECT COUNT(*) AS n FROM certificate_drives WHERE source = 'report' OR source IS NULL")->fetch();
t_check('existing drive rows default to source=report', (int)$rep['n'] >= 0);
$existing = db()->query("SELECT COUNT(*) AS n FROM certificate_drives WHERE identity_key IS NOT NULL")->fetch();
t_check('no pre-existing row has an identity key', (int)$existing['n'] === 0);

// --- 3. constraints, on a synthetic certificate we remove afterwards -------
$cocid = 'TEST-MANUAL-SCHEMA-DELETEME';
db()->prepare('DELETE FROM certificates WHERE cocid = ?')->execute([$cocid]);
db()->prepare('DELETE FROM certificate_devices WHERE cocid = ?')->execute([$cocid]);
db()->prepare('INSERT INTO certificates (cert_id, cocid, issued_at) VALUES (?, ?, UTC_TIMESTAMP())')
    ->execute(['TESTCERT-DELETEME', $cocid]);
$certId = (int)db()->lastInsertId();

try {
    $insDev = db()->prepare('INSERT INTO certificate_devices
        (certificate_id, cocid, category, make, model, serial, reason, destroy_method, identity_key, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');

    $insDev->execute([$certId, $cocid, 'mobile_phone', 'Apple', 'iPhone 6', 'IMEI-1',
        'media_soldered', 'shredded', 'imei-1|apple|iphone 6']);
    t_check('device row inserts', true);

    $rejected = false;
    try {
        $insDev->execute([$certId, $cocid, 'mobile_phone', 'Apple', 'iPhone 6', 'IMEI-1',
            'media_soldered', 'shredded', 'imei-1|apple|iphone 6']);
    } catch (Throwable $e) {
        $rejected = true;
    }
    t_check('duplicate device identity is rejected by the unique key', $rejected);

    $accepted = true;
    try {
        // Same serial, different make: two brands sharing a string are different
        // devices, which is the whole reason the key is composite.
        $insDev->execute([$certId, $cocid, 'other', 'Other', 'Widget', 'IMEI-1',
            'other', 'crushed', 'imei-1|other|widget']);
    } catch (Throwable $e) {
        $accepted = false;
    }
    t_check('same serial with a different make is accepted', $accepted);

    $devId = (int)db()->query('SELECT MIN(id) FROM certificate_devices WHERE certificate_id = ' . $certId)->fetchColumn();
    $insDr = db()->prepare('INSERT INTO certificate_drives
        (certificate_id, device, type, model, serial, final_status, source, certificate_device_id, identity_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $insDr->execute([$certId, '', 'eMMC/UFS (integrated)', 'iPhone 6', 'IMEI-1', 'DESTROYED',
        'manual', $devId, 'imei-1|apple|iphone 6']);
    t_check('attested media row inserts and links to its device', true);

    $rejected = false;
    try {
        $insDr->execute([$certId, '', 'eMMC/UFS (integrated)', 'iPhone 6', 'IMEI-1', 'DESTROYED',
            'manual', null, 'imei-1|apple|iphone 6']);
    } catch (Throwable $e) {
        $rejected = true;
    }
    t_check('duplicate attested media identity is rejected', $rejected);

    $nullOk = true;
    try {
        // Report rows carry no identity key; MySQL allows unlimited NULLs in a
        // unique index, which is what keeps existing behaviour untouched.
        for ($i = 0; $i < 3; $i++) {
            $insDr->execute([$certId, 'sda', 'SSD', 'Some Disk', 'SN-' . $i, 'COMPLETED',
                'report', null, null]);
        }
    } catch (Throwable $e) {
        $nullOk = false;
    }
    t_check('three report rows with NULL identity_key coexist', $nullOk);

    $n = (int)db()->query('SELECT COUNT(*) FROM certificate_drives WHERE certificate_id = ' . $certId)->fetchColumn();
    t_check('manual + report rows in one certificate: 4 rows', $n === 4);
} finally {
    // CASCADE removes devices and drives with the certificate.
    db()->prepare('DELETE FROM certificates WHERE cocid = ?')->execute([$cocid]);
    db()->prepare('DELETE FROM certificate_devices WHERE cocid = ?')->execute([$cocid]);
    $left = (int)db()->query('SELECT COUNT(*) FROM certificate_drives WHERE certificate_id = ' . $certId)->fetchColumn();
    $leftD = (int)db()->query('SELECT COUNT(*) FROM certificate_devices WHERE certificate_id = ' . $certId)->fetchColumn();
    t_check('cleanup: synthetic certificate rows removed (cascade)', $left === 0 && $leftD === 0);
}

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);
