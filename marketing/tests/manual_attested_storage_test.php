<?php
/**
 * Integration test for the attested-destruction storage layer.
 *
 * Needs a real database, so run it on the server:
 *   php /tmp/manual_attested_storage_test.php /home/oxwet/webs/tscrub-form
 *
 * Creates a synthetic certificate, exercises save/load/delete and the identity
 * constraints, proves a report rewrite cannot delete attested rows, and removes
 * everything afterwards — including when a test fails.
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/manual_destruction.php';
require_once $serverDir . '/db.php';
require_once $serverDir . '/jsonld.php';
require_once $serverDir . '/reports_lib.php';

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

certs_ensure_schema();

$cocid = 'TEST-MANUAL-STORAGE-DELETEME';
db()->prepare('DELETE FROM certificate_devices WHERE cocid = ?')->execute([$cocid]);
db()->prepare('DELETE FROM certificates WHERE cocid = ?')->execute([$cocid]);
db()->prepare('INSERT INTO certificates (cert_id, cocid, issued_at) VALUES (?, ?, UTC_TIMESTAMP())')
    ->execute(['TESTCERT-STORAGE-DELETEME', $cocid]);
$certId = (int)db()->lastInsertId();

try {
    // --- a device with two media -------------------------------------------
    $dev = md_validate_device([
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => 'IMEI-1', 'reason' => 'media_soldered', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '2026-10-13', 'destroy_witness' => 'J. Smith',
    ])['clean'];
    $m1 = md_validate_media(['serial' => 'IMEI-1', 'make' => 'Apple', 'model' => 'iPhone 6',
        'type' => 'eMMC/UFS (integrated)', 'size' => '16 GB', 'reason' => 'media_soldered',
        'destroy_method' => 'shredded'])['clean'];
    $m2 = md_validate_media(['serial' => 'SD-1', 'make' => 'SanDisk', 'model' => 'Ultra',
        'type' => 'microSD', 'size' => '32 GB', 'reason' => 'media_soldered',
        'destroy_method' => 'shredded'])['clean'];

    $r = manual_attested_save_device($certId, $cocid, $dev, [$m1, $m2], 'operator@example.com');
    t_check('device with two media saves', $r['ok'] === true);
    $deviceId = (int)$r['id'];

    $load = manual_attested_load($certId);
    t_check('load returns one device', count($load['devices']) === 1);
    t_check('load attaches both media to the device', count($load['devices'][0]['media'] ?? []) === 2);
    t_check('no standalone media yet', count($load['media']) === 0);
    $row = $load['devices'][0]['media'][0];
    t_check('media row is marked manual', strtolower((string)$row['source']) === 'manual');
    t_check('media row is DESTROYED', strtoupper((string)$row['final_status']) === 'DESTROYED');
    t_check('media row records the attesting operator', (string)$load['devices'][0]['attested_by'] === 'operator@example.com');
    t_check('media row carries the unit it came from',
        strpos((string)$row['system_name'], 'iPhone 6') !== false);

    // --- identity rules -----------------------------------------------------
    $dup = manual_attested_save_device($certId, $cocid, $dev, [], 'operator@example.com');
    t_check('duplicate device is rejected', $dup['ok'] === false);
    t_check('duplicate device message is actionable (mentions asset tag)',
        strpos($dup['error'], 'already recorded') !== false && strpos($dup['error'], 'asset tag') !== false);
    $afterDup = manual_attested_load($certId);
    t_check('a rejected duplicate writes nothing', count($afterDup['devices']) === 1
        && count($afterDup['devices'][0]['media']) === 2);

    $other = $dev;
    $other['make'] = 'Other';
    $other['model'] = 'Widget';
    $other['identity_key'] = md_identity_key('IMEI-1', 'Other', 'Widget');
    $ok = manual_attested_save_device($certId, $cocid, $other, [], 'operator@example.com');
    t_check('same serial with a different make is accepted', $ok['ok'] === true);

    // --- standalone destroyed media ----------------------------------------
    $solo = md_validate_media(['serial' => 'BTNH94823D6P', 'make' => 'Intel', 'model' => 'SSDPEKNW512G8H',
        'type' => 'SSD', 'size' => '512 GB', 'bus' => 'NVMe', 'reason' => 'unsupported_media',
        'destroy_method' => 'shredded'])['clean'];
    $r = manual_attested_save_media($certId, $cocid, $solo, 'operator@example.com');
    t_check('standalone destroyed media saves', $r['ok'] === true);
    // The caller needs this id to delete the record again; it is read inside the
    // transaction because lastInsertId() after commit() returns 0.
    t_check('standalone destroyed media returns its row id', (int)$r['id'] > 0);
    $load = manual_attested_load($certId);
    t_check('standalone media is not attached to a device', count($load['media']) === 1);
    t_check('standalone media has no device link',
        (int)($load['media'][0]['certificate_device_id'] ?? -1) === 0);
    t_check('standalone media has no host recorded', (string)$load['media'][0]['system_name'] === '');
    // The attester must survive on the media row itself: unlike device media, this
    // row has no unit to carry it, and "who attested this" is the first question an
    // auditor asks about an attestation.
    t_check('standalone media records the attesting operator',
        (string)$load['media'][0]['attested_by'] === 'operator@example.com');

    $dupSolo = manual_attested_save_media($certId, $cocid, $solo, 'operator@example.com');
    t_check('duplicate standalone media is rejected', $dupSolo['ok'] === false);

    // --- a report rewrite must not touch attested rows ----------------------
    $before = manual_attested_load($certId);
    rewrite_certificate_details($certId, [], [[
        'ts' => '2026-10-13T00:00:00Z', 'device' => 'sda', 'type' => 'SSD', 'model' => 'Reported Disk',
        'serial' => 'REPORT-1', 'size' => '256 GB', 'bus' => 'SATA', 'cls' => 'PURGE',
        'method' => 'NVMe Crypto Purge', 'cert' => 'PURGE', 'status' => 'COMPLETED',
    ]]);
    $after = manual_attested_load($certId);
    t_check('report rewrite keeps both attested devices', count($after['devices']) === count($before['devices']));
    t_check('report rewrite keeps attested media', count($after['devices'][0]['media'] ?? []) === 2);
    t_check('report rewrite keeps standalone attested media', count($after['media']) === 1);
    $reportRows = (int)db()->query("SELECT COUNT(*) FROM certificate_drives WHERE certificate_id = $certId AND source <> 'manual'")->fetchColumn();
    t_check('report rewrite still writes the reported drive', $reportRows === 1);

    // --- attested rows must never enter the VERIFIED drive list -------------
    // This is the guard for the worst bug this feature has had. load_cert_drives() feeds
    // both Annex A and rewrite_certificate_details(), so a manual row leaking in was
    // rendered as an instrument-verified finding AND written back as source='report' —
    // turning an operator's attestation into appliance evidence, then doubling the
    // certificate's device count. The JSON-LD twin has the same exposure.
    $verified = load_cert_drives($certId);
    t_check('the verified drive list holds only the reported drive', count($verified) === 1);
    $verifiedSerials = array_column($verified, 'serial');
    t_check('no attested media appear in the verified drive list',
        !in_array('SD-1', $verifiedSerials, true) && !in_array('IMEI-1', $verifiedSerials, true));

    $jsonD = jsonld_load_drives($certId);
    t_check('the JSON-LD verified list also excludes attested media', count($jsonD) === 1);
    t_check('the JSON-LD list is the same single reported drive',
        array_column($jsonD, 'serial') === $verifiedSerials);

    // --- delete -------------------------------------------------------------
    $removed = manual_attested_delete($certId, $deviceId, true);
    t_check('deleting a device removes it and its media', $removed === 3);
    $load = manual_attested_load($certId);
    t_check('device gone after delete', count($load['devices']) === 1);   // the "Other/Widget" one
    t_check('standalone media survives the device delete', count($load['media']) === 1);
    t_check('deleting the same device again is a no-op', manual_attested_delete($certId, $deviceId, true) === 0);
    t_check('deleting a non-existent id is a no-op', manual_attested_delete($certId, 999999999, false) === 0);
} finally {
    db()->prepare("DELETE FROM certificate_drives WHERE certificate_id = ?")->execute([$certId]);
    db()->prepare('DELETE FROM certificate_devices WHERE certificate_id = ?')->execute([$certId]);
    db()->prepare('DELETE FROM certificates WHERE cocid = ?')->execute([$cocid]);
    $left = (int)db()->query("SELECT COUNT(*) FROM certificate_drives WHERE certificate_id = $certId")->fetchColumn();
    $leftD = (int)db()->query("SELECT COUNT(*) FROM certificate_devices WHERE certificate_id = $certId")->fetchColumn();
    t_check('cleanup removed every synthetic row', $left === 0 && $leftD === 0);
}

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);
