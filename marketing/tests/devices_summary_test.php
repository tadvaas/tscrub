<?php
/**
 * Unit test for devices_summary() — the fleet counters behind the Devices tiles.
 *
 * Pure function test: no database, no config, no network. Run anywhere PHP is
 * available:
 *
 *   php tests/devices_summary_test.php [server-dir]
 *
 * What it protects:
 *  - an attested record is counted ONCE, as attested — never also as offline.
 *    A job of 24 operator-attested units used to read as 24 offline machines,
 *    which makes a completed job look like a broken fleet.
 *  - `erasing` and `failed` come from the live erasure phase, so a drive in
 *    'wiping' is not counted as failed and vice versa.
 *  - an attested row with no telemetry at all still counts toward `total`.
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/reports_lib.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok, $got = null): void
{
    global $count, $fails;
    $count++;
    if ($ok) {
        echo "ok   - {$label}\n";
        return;
    }
    $fails++;
    echo "FAIL - {$label}";
    if ($got !== null) {
        echo ' (got ' . json_encode($got) . ')';
    }
    echo "\n";
}

function t_same(string $label, array $expected, array $got): void
{
    t_check($label, $expected === $got, $got);
}

$zero = ['total' => 0, 'online' => 0, 'offline' => 0, 'erasing' => 0, 'failed' => 0, 'attested' => 0];

// ---- empty fleet ----------------------------------------------------------
t_same('empty list is all zeroes', $zero, devices_summary([]));

// ---- a bench fleet: everything is offline ---------------------------------
$bench = [
    ['_key' => 'a', 'online' => 0],
    ['_key' => 'b', 'online' => 0],
    ['_key' => 'c', 'online' => false],
];
t_same('offline-only fleet', ['total' => 3, 'online' => 0, 'offline' => 3, 'erasing' => 0, 'failed' => 0, 'attested' => 0], devices_summary($bench));

// ---- live telemetry phases -------------------------------------------------
$live = [
    ['online' => 1, 'erasure' => ['phase' => 'wiping']],
    ['online' => 1, 'erasure' => ['phase' => 'failed']],
    ['online' => 1, 'erasure' => ['phase' => 'done']],
    ['online' => true],                       // online, no erasure block yet
    ['online' => 1, 'erasure' => 'wiping'],   // malformed: not an array
];
t_same(
    'live phases split online / erasing / failed',
    ['total' => 5, 'online' => 5, 'offline' => 0, 'erasing' => 1, 'failed' => 1, 'attested' => 0],
    devices_summary($live)
);

// ---- attested records ------------------------------------------------------
// The regression: attested rows carry no `online` flag, so a naive "not online
// means offline" count reports the whole attested job as an offline fleet.
$mixed = [
    ['online' => 0],
    ['attested' => true, 'attested_kind' => 'device'],
    ['attested' => true, 'attested_kind' => 'media'],
    ['attested' => 0],                         // falsy: a real, scanned row
];
t_same(
    'attested rows are counted once, never as offline',
    ['total' => 4, 'online' => 0, 'offline' => 2, 'erasing' => 0, 'failed' => 0, 'attested' => 2],
    devices_summary($mixed)
);

// An attested row must not be pulled into the erasing/failed counters even if
// it somehow carries a stale online flag.
t_same(
    'attested row with stale telemetry stays attested only',
    ['total' => 1, 'online' => 0, 'offline' => 0, 'erasing' => 0, 'failed' => 0, 'attested' => 1],
    devices_summary([['attested' => true, 'online' => 1, 'erasure' => ['phase' => 'wiping']]])
);

echo "\n{$count} checks, {$fails} failure(s)\n";
exit($fails === 0 ? 0 : 1);
