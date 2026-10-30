<?php
/**
 * The Devices table's "Latest event" column.
 *
 * The column exists so an operator can answer "what was touched last?" and sort
 * the fleet by recency — which the previous six columns could not do at all (a
 * device's recency could only be found by opening its log). Four things have to
 * stay true for that to keep working, and none of them is visible from a
 * screenshot:
 *
 *   1. the value is the NEWEST of the machine's newest report and its heartbeat,
 *      not "the last report" (a machine that only ever heartbeats would sort as
 *      if it had never been seen);
 *   2. it is computed on the CANDIDATE rows, because the sort runs there. A
 *      value that only exists on the assembled page ties on every row at sort
 *      time and the tie-break orders both directions — which looks like
 *      "sorting does nothing". This was a real bug: sort=activity&dir=asc came
 *      back in serial order while dir=desc merely looked right;
 *   3. the value is one `Y-m-d H:i:s` shape, because the max and the sort are
 *      both string comparisons — mixing a raw UTC value with a localised one
 *      orders rows by an offset;
 *   4. the table declares seven columns and the expanded log row spans all
 *      seven. Adding a column and forgetting the colSpan leaves the log panel
 *      short of the table edge, which looks like a rendering bug.
 *
 * Run: php marketing/tests/devices_activity_test.php
 */

$serverDir  = $argv[1] ?? dirname(__DIR__) . '/server';
$siteDir    = dirname(__DIR__) . '/site/dashboard';
require_once $serverDir . '/reports_lib.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok, string $detail = ''): void
{
    global $count, $fails;
    $count++;
    if ($ok) {
        echo "ok   - $label\n";
    } else {
        $fails++;
        echo "FAIL - $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

// --- the value ---------------------------------------------------------------

t_check('the newest report beats an older heartbeat',
    device_activity_at('2026-10-30 10:00:00', '2026-10-30 09:00:00') === '2026-10-30 10:00:00');

t_check('a newer heartbeat beats the last report — an online machine is active now',
    device_activity_at('2026-10-28 10:00:00', '2026-10-30 09:00:00') === '2026-10-30 09:00:00');

t_check('no report and no heartbeat is the empty string, not a fake date',
    device_activity_at(null, null) === '' && device_activity_at('', '') === '');

t_check('no report but a heartbeat uses the heartbeat',
    device_activity_at(null, '2026-10-30 09:12:00') === '2026-10-30 09:12:00');

t_check('a report but no heartbeat uses the report',
    device_activity_at('2026-10-30 09:12:00', null) === '2026-10-30 09:12:00');

// Same-length `Y-m-d H:i:s` strings order lexicographically the way they order
// chronologically, which is what lets the max and the sort be string compares.
// Guard the assumption rather than the conclusion: a format change (dropping the
// leading zero, or switching to an epoch) breaks the ordering silently.
$fmt = device_activity_at('2026-10-09 08:00:00', null);
t_check('the value keeps the zero-padded Y-m-d H:i:s shape',
    (bool)preg_match('~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $fmt), $fmt);
t_check('the string order is the chronological order',
    device_activity_at('2026-10-09 08:00:00', null) < device_activity_at('2026-10-10 07:00:00', null));

// --- the wiring --------------------------------------------------------------

$lib = (string)file_get_contents($serverDir . '/reports_lib.php');

t_check('the helper is used by the list, not just defined',
    substr_count($lib, 'device_activity_at(') >= 4,
    'call sites: ' . (substr_count($lib, 'device_activity_at(') - 1));
t_check('the activity sort key exists on the server',
    strpos($lib, "case 'activity': return (string)(\$d['activity_at'] ?? '');") !== false);
t_check('the sort still carries an identity tie-break',
    strpos($lib, 'device_sort_keys($d, $sortVal($d))') !== false);

// The value has to exist by the time the candidates are sorted, so it is set
// inside devices_candidates — not only on the rows assembled afterwards.
$cand = (string)(preg_match('~function devices_candidates\(.*?\n\}~s', $lib, $m) ? $m[0] : '');
t_check('the latest event is computed on the candidates the sort runs over',
    strpos($cand, "['activity_at'] = device_activity_at(") !== false);
t_check('the candidate value includes the heartbeat, not only reports',
    strpos($cand, 'presence_last_seen(') !== false);
t_check('the assembled rows reuse the candidate value instead of recomputing it',
    strpos($lib, "device_activity_at((string)(\$dv['activity_at'] ?? ''), \$seenAt)") !== false);

$api = (string)file_get_contents($serverDir . '/api.php');
t_check('the export offers the same figure', strpos($api, "'activity_at' => 'Latest event'") !== false);

// --- the table ---------------------------------------------------------------

$html = (string)file_get_contents($siteDir . '/devices.html');
$colBlock = (string)(preg_match('~var cols = \[(.*?)\n        \];~s', $html, $m) ? $m[1] : '');
$widths = [];
preg_match_all('~w-\[(\d+)%\]~', $colBlock, $wm);
foreach ($wm[1] as $w) { $widths[] = (int)$w; }

t_check('the table declares seven columns', count($widths) === 7, 'got ' . count($widths));
t_check('the widths add up to the full width', array_sum($widths) === 100, 'sum: ' . array_sum($widths));
t_check('one column is the sortable latest event',
    strpos($colBlock, "['Latest event', 'w-[") !== false && strpos($colBlock, "'activity']") !== false);
t_check('the latest event is the FIRST column',
    (bool)preg_match("~var cols = \[\s*(//[^\n]*\n\s*)*\['Latest event', 'w-\[~", $html));
t_check('the log row spans every column',
    (bool)preg_match('~ltd\.colSpan = 7;~', $html));

// Both row shapes render the cell: a scanned machine and an operator-attested
// record. The attested rows are built by a different function, so a column added
// to only one of them leaves those rows one cell short — and a short row in a
// fixed-layout table silently shifts every cell after it.
//
// Each capture is anchored on markup unique to ITS renderer. The first attempt
// anchored the "scanned row" on `slice.forEach(function (dev, idx)` — which also
// matches the phone card renderer much earlier in the file, so the regex ran from
// there to the desktop table and the two order assertions below were really
// inspecting the attested row. They passed on a file that had the cell in the
// wrong place, which is how the anchoring was found.
$scanRows = (string)(preg_match('~var tr = el\(.tr., .border-b border-slate-100 transition-colors.*?table\.appendChild\(body\);~s', $html, $m) ? $m[0] : '');
$attested = (string)(preg_match('~function attestedRow\(dev\) \{(.*?)return tr;~s', $html, $m) ? $m[1] : '');
t_check('the scanned-row capture found the scanned rows',
    strpos($scanRows, 'biosCellFor(dev)') !== false && strpos($scanRows, 'actionControls(dev, key)') !== false);
t_check('the attested-row capture found the attested rows',
    strpos($attested, 'not scanned') !== false && strpos($attested, 'ManualAssets.actions') !== false);
t_check('the scanned row renders a latest-event cell', strpos($scanRows, 'dev.activity_at') !== false);
t_check('the attested row renders one too', strpos($attested, 'dev.activity_at') !== false);

// Column order is asserted in the ROWS as well as the header: a cell appended in
// the wrong place is a column of dates sitting under a different heading, which
// reads as corruption rather than as a layout slip.
t_check('the scanned row puts it first',
    strpos($scanRows, 'tr.appendChild(evCell)') !== false
    && strpos($scanRows, 'tr.appendChild(evCell)') < strpos($scanRows, 'tr.appendChild(sysCell)'));
t_check('the attested row puts it first',
    strpos($attested, 'tr.appendChild(evCell)') !== false
    && strpos($attested, 'tr.appendChild(evCell)') < strpos($attested, 'tr.appendChild(sysCell)'));
t_check('the cell pushes a date, never a raw ISO string with millis',
    strpos($html, "String(dev.activity_at || '').slice(0, 16)") !== false);
t_check('the header sort key is in the page\'s allow-list',
    (bool)preg_match("~var VIEW_SORTS = \[[^\]]*'activity'~", $html));

// The Rep button was removed in favour of the log's diagnostics entry: two word
// buttons, and the report is one click deeper, from a row that already lists it.
t_check('the row no longer carries a Rep button',
    strpos($html, "'ts-btn ts-btn--sm ts-btn--success', 'Rep'") === false
    && strpos($html, "'Rep'") === false);
t_check('Log and Ops remain', strpos($html, "'Log' + (logOpen[key] ? ' ▴' : ' ▾')") !== false
    && strpos($html, "ts-btn--primary', 'Ops'") !== false);

echo "\n$count checks, $fails failed\n";
exit($fails === 0 ? 0 : 1);
