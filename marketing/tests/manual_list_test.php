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

    // The certificates list needs the same figures per certificate, and it must get them
    // WITHOUT them leaking into the stored verified counts — a job of 24 attested units
    // reported "0 devices" in that tab.
    $att = attested_counts([$certId])[$certId] ?? [];
    t_check('attested counts: the units are counted', ($att['devices'] ?? -1) === 2);
    t_check('attested counts: the media are counted, including the standalone one',
        ($att['media'] ?? -1) === 3);
    t_check('attested counts: a certificate with none returns nothing to state',
        attested_counts([999999999]) === []);
    t_check('attested counts: the stored verified figures are untouched',
        (int)db()->query("SELECT devices FROM certificates WHERE id = $certId")->fetchColumn() === 0);
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

    // Status has TWO values, and an account is normally all-offline (only a booted machine
    // is online), so the column value alone ties for every row. Comparing just that left asc
    // and desc byte-identical — clicking Status twice did nothing, which is exactly what it
    // looked like. The tie-break has to follow the direction for the toggle to mean anything.
    $stAsc = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'status', 'dir' => 'asc']));
    $stDes = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'status', 'dir' => 'desc']));
    t_check('both units are on the page for the status sort',
        $idxOf($stAsc, 'list-aaa-1') >= 0 && $idxOf($stAsc, 'list-imei-1') >= 0
        && $idxOf($stDes, 'list-aaa-1') >= 0 && $idxOf($stDes, 'list-imei-1') >= 0);
    t_check('status ascending orders the tied rows one way',
        $idxOf($stAsc, 'list-aaa-1') < $idxOf($stAsc, 'list-imei-1'));
    t_check('status descending reverses them — a tied column still toggles',
        $idxOf($stDes, 'list-imei-1') < $idxOf($stDes, 'list-aaa-1'));
    t_check('the two status directions are different orderings', $stAsc !== $stDes);

    // MDM ties just as easily (almost every machine is "unchecked" or unranked).
    $mdmA = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'mdm', 'dir' => 'asc']));
    $mdmD = $systems(load_devices($userId, ['page' => 1, 'per' => 100, 'sort' => 'mdm', 'dir' => 'desc']));
    t_check('the MDM column toggles too, even where its ranks tie', $mdmA !== $mdmD);
    t_check('a missing direction means descending, the long-standing default',
        query_dir('') === -1 && query_dir(0) === -1);
    t_check('the direction is read case-insensitively', query_dir('ASC') === 1);

    // --- the sort key must not contain anything a heartbeat rewrites ----------
    // The tie-break used to be last_seen_at, which every appliance heartbeat rewrites — so
    // a group tied on the chosen column reshuffled itself as heartbeats arrived. Status is
    // the worst case: a working fleet is all online or all offline, so every row ties and the
    // order inside the group WAS the heartbeat order. Reproduced live before the fix —
    // PC1Q4QWH moved from position 4 to position 1 between two loads six seconds apart, with
    // nothing touching the page.
    $keyA = device_sort_keys(['sysserial' => 'ABC123', 'last_seen_at' => '2026-10-18 09:00:00'], 1);
    $keyB = device_sort_keys(['sysserial' => 'ABC123', 'last_seen_at' => '2026-10-18 10:30:00'], 1);
    t_check('the sort key is the column value and identity, and nothing else', count($keyA) === 2);
    t_check('a heartbeat cannot change a row\'s sort key', $keyA === $keyB);
    t_check('identity is the machine serial, lower-cased', $keyA[1] === 'abc123');
    t_check('an attested unit falls back to its internal key, never to an empty string',
        device_sort_keys(['_key' => 'attested:device:123'], 1)[1] === 'attested:device:123');

    // And the same thing through the real query: a group tied on Status must come back in
    // identity order, which is the only order a heartbeat cannot disturb.
    $tiedGroup = static function (array $query) use ($userId): array {
        $out = [];
        foreach (load_devices($userId, $query)['devices'] ?? [] as $d) {
            if (empty($d['online'])) { $out[] = strtolower((string)($d['sysserial'] ?? $d['_key'] ?? '')); }
        }
        return $out;
    };
    $offDes = $tiedGroup(['page' => 1, 'per' => 100, 'sort' => 'status', 'dir' => 'desc']);
    $offAsc = $tiedGroup(['page' => 1, 'per' => 100, 'sort' => 'status', 'dir' => 'asc']);
    $wantOff = $offDes;
    rsort($wantOff);
    $wantOffAsc = $offAsc;
    sort($wantOffAsc);
    t_check('rows tied on Status come back in identity order (descending)', count($offDes) > 0 && $offDes === $wantOff);
    t_check('rows tied on Status come back in identity order (ascending)', count($offAsc) > 0 && $offAsc === $wantOffAsc);

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

    // --- the media list: sorting and the Submitted column --------------------
    // The Media tab's headers are server-side, exactly like the Devices tab: the page holds
    // one page of rows, so the order has to be decided before the slice, or a page boundary
    // would cut the list in arrival order rather than in the chosen one.
    $mt = static fn(array $rows): array => array_map(
        static fn($d) => strtolower((string)($d['serial'] ?? '')), $rows);

    // Sizes arrive as text ("4GB", "1000 GB", "500 GB", and often nothing at all), so a
    // string compare would order 1000 GB before 4GB. The number is what the column means.
    t_check('a capacity is compared as a number, not as text',
        media_size_gb('4GB') === 4 && media_size_gb('1000 GB') === 1000
        && media_size_gb('500 GB') === 500 && media_size_gb('1 TB') === 1024);
    t_check('an unrecorded capacity sorts below a real one, not as the smallest',
        media_size_gb('') === -1);

    // Every column the table offers must actually order the list, both ways.
    foreach (['model', 'serial', 'size', 'type', 'status', 'method', 'grade', 'date'] as $col) {
        $ascCol = $mt(load_drives($userId, ['page' => 1, 'per' => 50, 'sort' => $col, 'dir' => 'asc'])['drives'] ?? []);
        $desCol = $mt(load_drives($userId, ['page' => 1, 'per' => 50, 'sort' => $col, 'dir' => 'desc'])['drives'] ?? []);
        t_check("the media list sorts by $col, and the direction reverses it", $ascCol !== $desCol);
    }

    // The Submitted column states when the latest report arrived — and nothing at all for an
    // operator attestation, which never had a report to submit. Asserted on the function
    // itself as well as on live rows, because this test account normally holds only the
    // attested fixtures and would otherwise never exercise the scanned branch.
    $synthetic = media_with_datetime([['serial' => 'SCAN-1', 'uploaded_at' => '2026-10-18 14:37:06']]);
    t_check('a scanned medium exposes its latest report time under the documented key',
        ($synthetic[0]['last_report_datetime_submitted'] ?? null) === '2026-10-18 14:37:06');
    $syntheticAtt = media_with_datetime([['serial' => 'ATT-1', 'attested' => true]]);
    t_check('an attested medium has no submission time to state',
        ($syntheticAtt[0]['last_report_datetime_submitted'] ?? null) === '');

    $mediaRows = load_drives($userId, ['page' => 1, 'per' => 100])['drives'] ?? [];
    t_check('every live media row carries the Submitted value, blank when there is none',
        $mediaRows !== []
        && count(array_filter($mediaRows, static fn($d) => array_key_exists('last_report_datetime_submitted', $d))) === count($mediaRows));

    // No sort must keep the order this table has always had: attested rows lead, so a
    // destroyed unit does not move when the operator pages or searches.
    $firstScanned = null;
    $lastAttested = null;
    foreach ($mediaRows as $i => $mr) {
        if (!empty($mr['attested'])) { $lastAttested = $i; }
        elseif ($firstScanned === null) { $firstScanned = $i; }
    }
    t_check('the default order still leads with the attested rows',
        $lastAttested === null || $firstScanned === null || $lastAttested < $firstScanned);
    t_check('an unknown column falls back to the default order instead of erroring',
        count(load_drives($userId, ['page' => 1, 'per' => 5, 'sort' => 'nonsense'])['drives'] ?? []) > 0);

    // --- the source switch ---------------------------------------------------
    // The Devices and Media tabs share a three-way switch: everything, only what the
    // appliance erased, or only what an operator attested. It has to narrow the total as
    // well as the rows, or the pager and the fleet cards contradict what is on screen — and
    // it must never mix the two classes, because they are never mixed on the certificate.
    t_check('the source value is normalised, so a typo cannot hide rows',
        list_source('verified') === 'verified' && list_source('ATTESTED') === 'attested'
        && list_source('') === 'all' && list_source('nope') === 'all');

    $mAll = load_drives($userId, ['page' => 1, 'per' => 100, 'source' => 'all']);
    $mVer = load_drives($userId, ['page' => 1, 'per' => 100, 'source' => 'verified']);
    $mAtt = load_drives($userId, ['page' => 1, 'per' => 100, 'source' => 'attested']);
    t_check('the media switch splits the list without losing a row',
        $mVer['total'] + $mAtt['total'] === $mAll['total']);
    t_check('media source=verified returns no attestations',
        count(array_filter($mVer['drives'], static fn($d) => !empty($d['attested']))) === 0);
    t_check('media source=attested returns only attestations',
        count($mAtt['drives']) > 0
        && count(array_filter($mAtt['drives'], static fn($d) => !empty($d['attested']))) === count($mAtt['drives']));
    t_check('an unknown media source falls back to everything',
        load_drives($userId, ['page' => 1, 'per' => 100, 'source' => 'bogus'])['total'] === $mAll['total']);
    // The two media paths must agree: the fast key-first path (no search, no sort) and the
    // full-decode path (sorted) are separate code, and only one of them was easy to forget.
    t_check('the fast and sorted media paths agree on the filtered total',
        load_drives($userId, ['page' => 1, 'per' => 100, 'source' => 'attested'])['total']
        === load_drives($userId, ['page' => 1, 'per' => 100, 'sort' => 'date', 'dir' => 'desc', 'source' => 'attested'])['total']);

    $dAll = load_devices($userId, ['page' => 1, 'per' => 100, 'source' => 'all']);
    $dVer = load_devices($userId, ['page' => 1, 'per' => 100, 'source' => 'verified']);
    $dAtt = load_devices($userId, ['page' => 1, 'per' => 100, 'source' => 'attested']);
    t_check('the devices switch splits the list without losing a row',
        $dVer['total'] + $dAtt['total'] === $dAll['total']);
    t_check('devices source=verified returns no attestations',
        count(array_filter($dVer['devices'], static fn($d) => !empty($d['attested']))) === 0);
    t_check('devices source=attested returns only attestations',
        count($dAtt['devices']) > 0
        && count(array_filter($dAtt['devices'], static fn($d) => !empty($d['attested']))) === count($dAtt['devices']));
    t_check('the devices summary narrows with the switch, so the cards match the rows',
        (int)($dAtt['summary']['total'] ?? -1) === (int)$dAtt['total']
        && (int)($dAtt['summary']['attested'] ?? -1) === (int)$dAtt['attested_total']);

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
