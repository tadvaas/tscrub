<?php
declare(strict_types=1);

/**
 * Processing (bench) hosts are SHOWN by default; hiding them is the choice.
 *
 * Why this needs its own test: the visibility rule is a single empty()/negation
 * test in two places in load_devices(), and getting it backwards is silent. The
 * page still renders, the pager still pages, and the only symptom is a machine
 * missing from a list — the exact failure that reads as "my bench was deleted".
 *
 * Two things are pinned:
 *
 *   1. the normaliser's direction: an ABSENT, empty or unrecognised value must
 *      mean SHOW, because a typo in a shared URL must never hide rows; only an
 *      explicit negative spelling may hide them, including the `bench=hide`
 *      the dashboard writes;
 *   2. both loader call sites and both API entry points actually use it —
 *      a helper nobody calls protects nothing.
 *
 *   php tests/bench_visibility_test.php
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

echo "== 1. absent, empty and unrecognised mean SHOW ==\n";
t_check('no bench parameter shows the machines', query_hide_bench('') === false);
t_check('a null parameter shows the machines', query_hide_bench(null) === false);
t_check('the old opt-in (bench=1) still shows them', query_hide_bench('1') === false);
t_check('a typo shows them rather than hiding a machine', query_hide_bench('hdie') === false);
t_check('an unrelated word shows them', query_hide_bench('yes') === false);

echo "\n== 2. only an explicit negative spelling hides them ==\n";
foreach (['hide', 'HIDE', ' hide ', '0', 'no', 'false'] as $neg) {
    t_check('bench=' . json_encode($neg) . ' hides them', query_hide_bench($neg) === true);
}

echo "\n== 3. the loader honours it in both passes ==\n";
$lib = (string)file_get_contents($serverDir . '/reports_lib.php');
t_check(
    'the helper is called in the candidate pass and the folded pass',
    preg_match_all('/query_hide_bench\(/', $lib) >= 3   // 1 definition + 2 call sites
);
t_check(
    'no truthiness test on the bench parameter survives',
    !preg_match('/empty\(\$query\[\'bench\'\]\)/', $lib)
);

echo "\n== 4. both API entry points forward the raw spelling ==\n";
$api = (string)file_get_contents($serverDir . '/api.php');
t_check(
    'list and export pass the parameter through as a string',
    preg_match_all("/'bench'\\s*=>\\s*\\(string\\)\\(\\\$_GET\\['bench'\\] \\?\\? ''\\)/", $api) >= 2
);
t_check(
    'neither API call site coerces it to a bool',
    !preg_match("/'bench'\\s*=>\\s*!empty\\(\\\$_GET\\['bench'\\]\\)/", $api)
);

echo "\n" . ($fails ? "$fails of $count checks FAILED" : "$count checks passed") . "\n";
exit($fails ? 1 : 0);
