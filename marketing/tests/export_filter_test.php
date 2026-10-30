<?php
declare(strict_types=1);

/**
 * Exports must be the VIEW, not the whole account.
 *
 * Both export routes used to load everything and then re-implement `q` by hand —
 * a second copy of the search, with its own field list, that knew nothing about
 * the source switch or the facet filters. So "export" answered a different
 * question from the list on screen: filter to 173 locked machines, export, get
 * 207.
 *
 * These checks pin the shape rather than the rows (the rows need a database):
 * one loader call carrying the same parameters the list uses, `all: true` for the
 * unpaginated set, and no hand-rolled filtering left behind.
 *
 *   php tests/export_filter_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
$api = (string)file_get_contents($serverDir . '/api.php');
$lib = (string)file_get_contents($serverDir . '/reports_lib.php');
$siteDir = dirname($serverDir) . '/site';
$devicesPage = (string)@file_get_contents($siteDir . '/dashboard/devices.html');
$drivesPage  = (string)@file_get_contents($siteDir . '/dashboard/drives.html');

$count = 0;
$fails = 0;
function t_check(string $label, bool $ok, $detail = null): void {
    global $count, $fails;
    $count++;
    if ($ok) { echo "ok   - $label\n"; }
    else {
        $fails++;
        echo "FAIL - $label" . ($detail !== null ? ' (got: ' . json_encode($detail) . ')' : '') . "\n";
    }
}

/** The body of one route, from its marker to the next top-level `if (` route. */
function route_block(string $src, string $marker): string {
    $start = strpos($src, $marker);
    if ($start === false) return '';
    $end = strpos($src, "\nif (\$method", $start + strlen($marker));
    return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
}

$devExport = route_block($api, "if (\$method === 'GET' && \$route === '/devices/export')");
$drvExport = route_block($api, "if (\$method === 'GET' && \$route === '/drives/export')");

echo "== the routes exist at all ==\n";
t_check('the devices export route was found', $devExport !== '');
t_check('the drives export route was found', $drvExport !== '');

echo "\n== one loader, carrying the view's parameters ==\n";
t_check('devices export calls load_devices with the export query',
    strpos($devExport, "load_devices((int)\$u['id'], \$exportQuery)") !== false);
t_check('drives export calls load_drives with the export query',
    strpos($drvExport, "load_drives((int)\$u['id'], \$exportQuery)") !== false);
t_check('the devices export asks for everything (unpaginated)',
    preg_match("/'all'\\s*=>\\s*true/", $devExport) === 1);
t_check('the drives export asks for everything (unpaginated)',
    preg_match("/'all'\\s*=>\\s*true/", $drvExport) === 1);
t_check('the devices export forwards the facet filters',
    strpos($devExport, "['ff']") !== false);
t_check('the devices export forwards the source switch',
    strpos($devExport, "list_source(\$_GET['source'] ?? '')") !== false);
t_check('the drives export forwards the source switch',
    strpos($drvExport, "list_source(\$_GET['source'] ?? '')") !== false);
t_check('both forward the chosen order, so the file matches the list',
    strpos($devExport, "\$exportQuery['sort']") !== false
    && strpos($drvExport, "\$exportQuery['sort']") !== false);

echo "\n== the hand-rolled second copy of the search is gone ==\n";
t_check('no needle filter left in the devices export',
    strpos($devExport, '$needle') === false);
t_check('no needle filter left in the drives export',
    strpos($drvExport, '$needle') === false);
t_check('the devices export no longer reloads the whole account',
    strpos($devExport, 'load_devices((int)$u' . "['id']);") === false);
t_check('the drives export no longer reloads the whole account',
    strpos($drvExport, 'load_drives((int)$u' . "['id']);") === false);

echo "\n== load_drives can return the whole filtered set ==\n";
t_check('the drive_serials fast path is skipped for an all:true request',
    strpos($lib, "if (\$query !== null && \$q === '' && \$sort === '' && empty(\$query['all'])) {") !== false);
t_check('and the slice honours all:true',
    strpos($lib, "!empty(\$query['all']) ? \$out : array_slice(\$out,") !== false);

echo "\n== the pages send what the list is showing ==\n";
t_check('the Devices page sends ff, source and the order',
    strpos($devicesPage, 'ff: encodeFilters(filters),') !== false
    && strpos($devicesPage, 'source: source,') !== false
    && strpos($devicesPage, 'sort: sortKey,') !== false);
t_check('the Media page sends source and the order',
    strpos($drivesPage, 'source: source,') !== false
    && strpos($drivesPage, 'sort: sortKey,') !== false);
t_check('neither page builds the URL by string concatenation any more',
    strpos($devicesPage, "'/api/devices/export?format=' + format") === false
    && strpos($drivesPage, "'/api/drives/export?format=' + format") === false);

echo "\n";
echo "\n== every download gets its own name ==\n";
// A fixed name means the browser silently becomes tscrub-devices(1).csv, and an
// export is a snapshot of a moment, so the moment belongs in the name.
$http = (string)file_get_contents($serverDir . '/http.php');
t_check('the server stamps the filename',
    strpos($http, "\$stamp = gmdate('Ymd-His');") !== false
    && strpos($http, "\$basename .= '-' . \$stamp;") !== false);
t_check('the Devices page stamps the download name',
    strpos($devicesPage, "'tscrub-devices-' + exportStamp() + '.' + ext") !== false);
t_check('the Media page stamps the download name',
    strpos($drivesPage, "'tscrub-drives-' + exportStamp() + '.' + ext") !== false);
t_check('neither page falls back to a fixed name',
    strpos($devicesPage, "'tscrub-devices.' + ext") === false
    && strpos($drivesPage, "'tscrub-drives.' + ext") === false);

if ($fails === 0) {
    echo "ALL TESTS PASSED ($count checks)\n";
    exit(0);
}
echo "$fails of $count checks FAILED\n";
exit(1);
