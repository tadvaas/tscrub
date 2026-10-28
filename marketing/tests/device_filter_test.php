<?php
declare(strict_types=1);

/**
 * Advanced device filters: the parser, the per-row facet values, matching and the
 * counts the modal shows.
 *
 * Why pure: these are the rules, not the plumbing. Every one of them has a silent
 * failure mode that a browser test would not catch — an "unknown" BIOS swept into
 * "unlocked", an unrecognised URL token rejecting the whole filter instead of the
 * one token, a count that disagrees with what the filter then selects.
 *
 * The vocabulary is checked against the values the rest of the server actually
 * produces (mdm_status_label(), the sort rank in load_devices(), the payload keys),
 * because a facet whose values nothing ever emits is a dead option in the modal.
 *
 *   php tests/device_filter_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/reports_lib.php';

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

/** A row as load_devices() sees it. */
function dev(array $over = []): array {
    return $over + [
        'attested' => false, 'online' => true, 'erasure' => null,
        'bioslock' => '', 'secure_boot' => '', 'tpm' => '', 'drive_count' => 0,
        'notes' => '', 'mdm_status' => '', 'mdm_verdict' => '',
    ];
}

echo "== 1. the parser is a whitelist, not a decoder ==\n";

$p = device_filters_parse('bios:locked');
t_check('a known token survives', $p === ['bios' => ['locked' => true]], $p);

$p = device_filters_parse('bios:locked,junk:whatever');
t_check('an unknown FACET is dropped, not fatal', $p === ['bios' => ['locked' => true]], $p);

$p = device_filters_parse('bios:sideways');
t_check('an unknown VALUE is dropped', $p === [], $p);

$p = device_filters_parse('  BIOS : LOCKED , presence:offline ');
t_check('case and spaces are tolerated', $p === ['bios' => ['locked' => true], 'presence' => ['offline' => true]], $p);

$p = device_filters_parse('bios:locked,presence:offline,mdm:locked');
t_check('several facets combine', count($p) === 3, $p);

$p = device_filters_parse(',,,');
t_check('empty input is an empty filter', $p === [], $p);
$p = device_filters_parse(null);
t_check('null input is an empty filter', $p === [], $p);

$enc = device_filters_encode(device_filters_parse('presence:offline,bios:locked'));
t_check('encoding is canonical (sorted)', $enc === 'bios:locked,presence:offline', $enc);
t_check('parse -> encode -> parse round-trips',
    device_filters_encode(device_filters_parse($enc)) === $enc);

echo "\n== 2. one value per row per facet ==\n";

// presence
t_check('online', device_facet_value(dev(['online' => true]), 'presence') === 'online');
t_check('offline', device_facet_value(dev(['online' => false]), 'presence') === 'offline');
t_check('erasing (phase=wiping)',
    device_facet_value(dev(['erasure' => ['phase' => 'wiping']]), 'presence') === 'erasing');
t_check('failed (phase=failed)',
    device_facet_value(dev(['erasure' => ['phase' => 'failed']]), 'presence') === 'failed');
t_check('an attested unit has NO presence (it never reported)',
    device_facet_value(dev(['attested' => true, 'online' => true]), 'presence') === '');

// bios — the whole point of the feature
t_check('bios Locked', device_facet_value(dev(['bioslock' => 'Locked']), 'bios') === 'locked');
t_check('bios Unlocked', device_facet_value(dev(['bioslock' => 'Unlocked']), 'bios') === 'unlocked');
t_check('bios Unknown', device_facet_value(dev(['bioslock' => 'Unknown']), 'bios') === 'unknown');
t_check('bios with no value is NOT unknown (an unknown must not be invented)',
    device_facet_value(dev(['bioslock' => '']), 'bios') === '');
t_check('bios "N/A" is empty, not a category',
    device_facet_value(dev(['bioslock' => 'N/A']), 'bios') === '');

// mdm — mirrors mdm_status_label()
$mdm = [
    ['unchecked', '', 'unchecked'],
    ['queued', '', 'queued'],
    ['checking', '', 'checking'],
    ['done', 'locked_other', 'locked'],
    ['done', 'locked_this', 'locked_this'],
    ['done', 'unlocked', 'unlocked'],
    ['done', 'paid_only', 'blocked'],
    ['done', 'insufficient_credits', 'blocked'],
    ['na', 'na', 'na'],
    ['', '', ''],
    ['done', 'ms_error', ''],
];
foreach ($mdm as $m) {
    $got = device_facet_value(dev(['mdm_status' => $m[0], 'mdm_verdict' => $m[1]]), 'mdm');
    t_check('mdm ' . ($m[0] ?: '-') . '/' . ($m[1] ?: '-') . ' -> ' . ($m[2] ?: '(none)'), $got === $m[2], $got);
}

// secure boot / tpm / media / notes
t_check('secureboot On', device_facet_value(dev(['secure_boot' => 'On']), 'secureboot') === 'on');
t_check('secureboot Enabled counts as on', device_facet_value(dev(['secure_boot' => 'Enabled']), 'secureboot') === 'on');
t_check('secureboot Off', device_facet_value(dev(['secure_boot' => 'Off']), 'secureboot') === 'off');
t_check('secureboot Disabled counts as off', device_facet_value(dev(['secure_boot' => 'Disabled']), 'secureboot') === 'off');
t_check('secureboot blank is empty', device_facet_value(dev(['secure_boot' => '']), 'secureboot') === '');
t_check('tpm 2.0 present', device_facet_value(dev(['tpm' => '2.0']), 'tpm') === 'present');
t_check('tpm blank absent', device_facet_value(dev(['tpm' => '']), 'tpm') === 'absent');
t_check('tpm "Not present" absent', device_facet_value(dev(['tpm' => 'Not Present']), 'tpm') === 'absent');
t_check('media reported', device_facet_value(dev(['drive_count' => 2]), 'media') === 'any');
t_check('media none', device_facet_value(dev(['drive_count' => 0]), 'media') === 'none');
t_check('notes any', device_facet_value(dev(['notes' => 'x']), 'notes') === 'any');
t_check('notes none', device_facet_value(dev(['notes' => '  ']), 'notes') === 'none');

echo "\n== 3. matching ==\n";

$rows = [
    dev(['_k' => 'a', 'bioslock' => 'Locked',   'online' => false]),
    dev(['_k' => 'b', 'bioslock' => 'Unlocked', 'online' => true]),
    dev(['_k' => 'c', 'bioslock' => 'Unknown',  'online' => false]),
    dev(['_k' => 'd', 'bioslock' => '',         'online' => false]),
    dev(['_k' => 'e', 'attested' => true,       'notes' => 'shredded']),
];
$keys = static function (array $f) use ($rows): array {
    return array_values(array_map(
        static fn($r) => $r['_k'],
        array_filter($rows, static fn($r) => device_filters_match($r, $f))
    ));
};

t_check('an empty filter matches everything', $keys(device_filters_parse('')) === ['a', 'b', 'c', 'd', 'e']);
t_check('bios:locked selects only Locked', $keys(device_filters_parse('bios:locked')) === ['a']);
t_check('two values in one facet are OR',
    $keys(device_filters_parse('bios:locked,bios:unlocked')) === ['a', 'b']);
t_check('two facets are AND',
    $keys(device_filters_parse('bios:locked,presence:offline')) === ['a']);
t_check('contradictory facets select nothing',
    $keys(device_filters_parse('bios:locked,presence:online')) === []);
t_check('presence excludes attested rows (they have no presence)',
    $keys(device_filters_parse('presence:offline')) === ['a', 'c', 'd']);
t_check('a row with no BIOS value matches no BIOS option',
    $keys(device_filters_parse('bios:unknown')) === ['c']);

echo "\n== 4. counts ==\n";

$counts = device_facet_counts($rows);
t_check('bios counts', $counts['bios'] == ['locked' => 1, 'unlocked' => 1, 'unknown' => 1], $counts['bios']);
// `==` (not `===`) for these maps: key ORDER is an artefact of which row was
// counted first, and asserting it would only make the test brittle.
t_check('presence counts (attested excluded)',
    $counts['presence'] == ['online' => 1, 'offline' => 3], $counts['presence']);
t_check('a facet nothing populates is counted from what rows do have', $counts['tpm'] == ['absent' => 5], $counts['tpm']);
t_check('notes counts', $counts['notes'] == ['any' => 1, 'none' => 4], $counts['notes']);
// The counts must describe the same rows the filter would select, or the modal
// promises numbers it cannot deliver.
foreach (['bios' => ['locked', 'unlocked', 'unknown'], 'presence' => ['online', 'offline']] as $facet => $vals) {
    foreach ($vals as $v) {
        $matched = count(array_filter($rows, static fn($r) => device_facet_value($r, $facet) === $v));
        t_check("count for $facet:$v equals what the filter selects", ($counts[$facet][$v] ?? 0) === $matched);
    }
}

echo "\n== 5. the vocabulary is closed ==\n";

$defs = device_facet_defs();
t_check('seven dimensions', count($defs) === 7, array_keys($defs));
foreach ($defs as $facet => $vals) {
    t_check("$facet offers at least two options", count($vals) >= 2, $vals);
    t_check("$facet values are lowercase wire tokens", $vals === array_map('strtolower', $vals), $vals);
}

echo "\n" . $count . " checks, " . $fails . " failed\n";
exit($fails === 0 ? 0 : 1);
