<?php
/**
 * Integration test for surfacing attested (manually recorded) destruction in the
 * Devices and Drives lists.
 *
 * Needs a real database, so run it on the server:
 *   php /tmp/manual_list_test.php /home/oxwet/webs/tscrub-form
 *
 * The point of this test is that an attested record is not merely stored but
 * *reachable*: it must appear in the same lists, search, sort and pagination as
 * scanned machines, carry the `attested` flag the UI badges on, and never pretend
 * to have scan data (no grade, no presence, empty BIOS/MDM).
 *
 * Creates its own certificate and removes everything afterwards — including when a
 * test fails.
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

$cocid = 'TEST-MANUAL-LIST-DELETEME';

// The lists are org-scoped, so the certificate must belong to a real user.
$userId = (int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId <= 0) {
    echo "FAIL - no user in the database to scope the test to\n";
    exit(1);
}

$cleanup = function () use ($cocid): void {
    $ids = db()->prepare('SELECT id FROM certificates WHERE cocid = ?');
    $ids->execute([$cocid]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        db()->prepare("DELETE FROM certificate_drives WHERE certificate_id = ? AND source = 'manual'")->execute([$cid]);
        db()->prepare('DELETE FROM certificate_devices WHERE certificate_id = ?')->execute([$cid]);
        db()->prepare('DELETE FROM certificates WHERE id = ?')->execute([$cid]);
    }
};
$cleanup();

register_shutdown_function(function () use ($cleanup) {
    $cleanup();
});

try {
    // --- record a device that held two media, plus one standalone drive --------
    $cert = manual_cert_row($userId, $cocid);
    t_check('a job row is opened for the COCID', $cert !== null);
    if ($cert === null) { throw new RuntimeException('no cert'); }
    $certId = (int)$cert['id'];

    $dev = md_validate_device([
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => 'LIST-IMEI-1', 'reason' => 'media_soldered', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '2026-10-13',
        'destroy_witness' => 'J. Smith',
    ])['clean'];

    $media = [
        md_validate_media(['serial' => 'LIST-IMEI-1', 'make' => 'Apple', 'model' => 'iPhone 6',
            'type' => 'eMMC/UFS (integrated)', 'size' => '16 GB'])['clean'],
        md_validate_media(['serial' => 'LIST-SD-1', 'make' => 'SanDisk', 'model' => 'Ultra',
            'type' => 'microSD', 'size' => '32 GB'])['clean'],
    ];
    // A medium inherits the unit's reason/method, as the API route arranges.
    foreach ($media as $i => $m) {
        $media[$i]['reason'] = $dev['reason'];
        $media[$i]['destroy_method'] = $dev['destroy_method'];
    }

    $r = manual_attested_save_device($certId, $cocid, $dev, $media, 'operator@example.com');
    t_check('the attested device saves', $r['ok'] === true);

    // A SECOND unit whose system line sorts before the phone's ("Laptop …" < "Mobile
    // phone …"). The sort direction can only be observed by comparing two rows, and the
    // test must not depend on whatever else the account happens to hold — it scopes to the
    // first user in the database, which on a clean install has almost nothing. It carries
    // its own COCID so every by-COCID lookup elsewhere in this test stays unambiguous.
    $sortCoc = $cocid . '-SORT';
    $dev2 = md_validate_device([
        'category' => 'laptop', 'make' => 'Acer', 'model' => 'Aspire 5',
        'serial' => 'LIST-AAA-1', 'reason' => 'would_not_boot', 'destroy_method' => 'shredded',
    ])['clean'];
    $r1b = manual_attested_save_device($certId, $sortCoc, $dev2, [], 'operator@example.com');
    t_check('a second attested unit saves, for the sort comparison', $r1b['ok'] === true);

    $standalone = md_validate_media(['serial' => 'LIST-LOOSE-1', 'make' => 'Samsung',
        'model' => '870 EVO', 'type' => 'SSD', 'size' => '500 GB', 'bus' => 'SATA',
        'reason' => 'physically_damaged', 'destroy_method' => 'crushed'])['clean'];
    $r2 = manual_attested_save_media($certId, $cocid, $standalone, 'operator@example.com');
    t_check('the standalone destroyed drive saves', $r2['ok'] === true);

    // --- Devices list ---------------------------------------------------------
    $page = load_devices($userId, ['page' => 1, 'per' => 10]);
    t_check('the Devices page still returns a devices array', isset($page['devices']) && is_array($page['devices']));
    t_check('the response separates the attested count', ($page['attested_total'] ?? -1) === 2);
    t_check('the attested total is included in the page total', (int)$page['total'] >= 1);

    $found = null;
    foreach ($page['devices'] as $d) {
        if (($d['cocid'] ?? '') === $cocid) { $found = $d; break; }
    }
    t_check('the attested device appears on the Devices page', $found !== null);
    if ($found !== null) {
        t_check('...flagged attested', ($found['attested'] ?? false) === true);
        t_check('...labelled as a device, not a medium', ($found['attested_kind'] ?? '') === 'device');
        t_check('...named from the attested vocabulary', strpos((string)$found['system'], 'Mobile phone') !== false);
        t_check('...carrying its serial', ($found['sysserial'] ?? '') === 'LIST-IMEI-1');
        t_check('...reporting the media it held', (int)$found['drive_count'] === 2);
        t_check('...carrying the attester recorded server-side', ($found['attested_by'] ?? '') === 'operator@example.com');
        t_check('...carrying the qualifier, so no surface re-derives it',
            strpos((string)($found['attested_qualifier'] ?? ''), 'attested') !== false);
        // Absence of scan data must be explicit, not merely blank-by-accident.
        t_check('...not claiming to be online', empty($found['online']));
        t_check('...not claiming a BIOS lock state', ($found['bioslock'] ?? '') === '');
        t_check('...not claiming MDM state', ($found['mdm'] ?? '') === '');
        t_check('...not claiming a refurb grade', ($found['refurb_grade'] ?? '') === '');
    }

    // --- Drives list (fast path: no search) -----------------------------------
    $drv = load_drives($userId, ['page' => 1, 'per' => 10]);
    t_check('the Drives page separates the attested count', ($drv['attested_total'] ?? -1) === 3);

    $bySerial = [];
    foreach ($drv['drives'] as $d) { $bySerial[(string)$d['serial']] = $d; }

    t_check('an attested medium from the device is listed', isset($bySerial['LIST-SD-1']));
    t_check('the standalone destroyed drive is listed', isset($bySerial['LIST-LOOSE-1']));

    if (isset($bySerial['LIST-LOOSE-1'])) {
        $loose = $bySerial['LIST-LOOSE-1'];
        t_check('a standalone drive is marked DESTROYED', ($loose['status'] ?? '') === 'DESTROYED');
        t_check('...as NOT SANITISED, never as wiped', ($loose['certification'] ?? '') === 'NOT SANITISED');
        t_check('...flagged attested', ($loose['attested'] ?? false) === true);
        // The whole point of the feature: no host may be invented for it.
        t_check('...given no host it never had', ($loose['system'] ?? '') === '');
        t_check('...not graded, because no SMART was ever read', ($loose['graded'] ?? true) === false);
        t_check('...with an empty grade rather than a misleading one', ($loose['grade'] ?? 'x') === '');
        t_check('...carrying the attester', ($loose['attested_by'] ?? '') === 'operator@example.com');
    }

    if (isset($bySerial['LIST-SD-1'])) {
        t_check('a media row knows the unit it came from',
            strpos((string)$bySerial['LIST-SD-1']['system'], 'iPhone 6') !== false);
        t_check('...and that unit\'s serial', ($bySerial['LIST-SD-1']['sysserial'] ?? '') === 'LIST-IMEI-1');
    }

    // Attested rows lead the list, so the first row of the first page is one.
    t_check('attested media lead the drives list', ($drv['drives'][0]['attested'] ?? false) === true);

    // --- search paths (a destroyed drive must be findable) --------------------
    $q1 = load_drives($userId, ['page' => 1, 'per' => 10, 'q' => 'LIST-LOOSE-1']);
    t_check('a destroyed drive is findable by serial in the Drives search',
        (int)$q1['total'] === 1 && (int)($q1['attested_total'] ?? 0) === 1);

    $q2 = load_devices($userId, ['page' => 1, 'per' => 10, 'q' => 'LIST-IMEI-1']);
    $hit2 = false;
    foreach (($q2['devices'] ?? []) as $d) { if (($d['cocid'] ?? '') === $cocid) { $hit2 = true; break; } }
    t_check('the attested device is findable by serial in the Devices search', $hit2);

    // The Devices search box advertises COCID search. A manually recorded unit has no
    // report to derive `_cocids` from, so this only works if the row sets it itself.
    $q3 = load_devices($userId, ['page' => 1, 'per' => 10, 'q' => $cocid]);
    $hit3 = false;
    foreach (($q3['devices'] ?? []) as $d) { if (($d['cocid'] ?? '') === $cocid) { $hit3 = true; break; } }
    t_check('the attested device is findable by COCID in the Devices search', $hit3);

    $q4 = load_devices($userId, ['page' => 1, 'per' => 10, 'q' => 'shredded']);
    $hit4 = false;
    foreach (($q4['devices'] ?? []) as $d) { if (($d['cocid'] ?? '') === $cocid) { $hit4 = true; break; } }
    t_check('the attested device is findable by its destruction method', $hit4);

    // --- sort direction -------------------------------------------------------
    // Nothing asserted the DIRECTION until now, which is how a total failure hid: the API
    // converts ?dir= to ±1 before calling this, while load_devices() tested for the literal
    // string 'asc' — so the comparison was never true, every column sorted descending
    // whatever the header said, and the arrow the dashboard drew was a lie.
    $systems = static function (array $r): array {
        return array_map(static fn($d) => strtolower((string)($d['system'] ?? '')), $r['devices'] ?? []);
    };
    $ascStr = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'system', 'dir' => 'asc']));
    $ascInt = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'system', 'dir' => 1]));
    $desStr = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'system', 'dir' => 'desc']));
    $desInt = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'system', 'dir' => -1]));
    t_check("'asc' and 1 are read as the same direction", $ascStr === $ascInt);
    t_check("'desc' and -1 are read as the same direction", $desStr === $desInt);

    $want = $ascStr;
    sort($want);
    t_check('ascending really is ascending', $ascStr === $want);
    $wantDesc = $desStr;
    rsort($wantDesc);
    t_check('descending really is descending', $desStr === $wantDesc);

    // The direction is only observable by comparing the two rows this test created: the
    // phone's system line sorts after the laptop's.
    $idxOf = static function (array $list, string $needle): int {
        foreach ($list as $i => $v) { if (strpos($v, $needle) !== false) { return $i; } }
        return -1;
    };
    $aLap = $idxOf($ascStr, 'list-aaa-1');
    $aPhn = $idxOf($ascStr, 'list-imei-1');
    $dLap = $idxOf($desStr, 'list-aaa-1');
    $dPhn = $idxOf($desStr, 'list-imei-1');
    t_check('both units are on the page, so the order can be compared',
        $aLap >= 0 && $aPhn >= 0 && $dLap >= 0 && $dPhn >= 0);
    t_check('ascending puts the Laptop before the phone', $aLap < $aPhn);
    t_check('descending puts the phone first — the direction actually reversed', $dPhn < $dLap);
    t_check('the two directions are different orderings', $ascStr !== $desStr);
    t_check('a missing direction means descending, the long-standing default',
        query_dir('') === -1 && query_dir(0) === -1);
    t_check('the direction is read case-insensitively', query_dir('ASC') === 1);

    // The comparison unit has done its job — remove it, so the assertions below (which
    // count attested rows) describe the original fixture alone.
    t_check('the comparison unit deletes', manual_attested_delete($certId, (int)$r1b['id'], true) === 1);
    $restored = load_devices($userId, ['page' => 1, 'per' => 10]);
    t_check('the attested count is back to the single fixture unit',
        (int)($restored['attested_total'] ?? -1) === 1);

    // --- the empty page that used to raise 1064 -------------------------------
    // A page past the end yields no keys; the query must be valid SQL, not IN ().
    $far = load_devices($userId, ['page' => 9999, 'per' => 10]);
    t_check('a page past the end returns an empty list instead of an SQL error',
        isset($far['devices']) && count($far['devices']) === 0);

    // --- deleting removes both the unit and its media -------------------------
    $devRowId = (int)$found['attested_id'];
    $removed = manual_attested_delete($certId, $devRowId, true);
    t_check('deleting an attested device removes it and its media', $removed === 3);

    $after = load_drives($userId, ['page' => 1, 'per' => 10]);
    t_check('its media leave the Drives list too', (int)($after['attested_total'] ?? -1) === 1);

    $afterDev = load_devices($userId, ['page' => 1, 'per' => 10]);
    t_check('the attested device leaves the Devices list', (int)($afterDev['attested_total'] ?? -1) === 0);
} catch (Throwable $e) {
    t_check('test ran without an unexpected exception (' . $e->getMessage() . ')', false);
}

$cleanup();

echo "\n$count assertions, $fails failed\n";
exit($fails === 0 ? 0 : 1);
