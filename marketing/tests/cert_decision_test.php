<?php
declare(strict_types=1);

/**
 * The physical-destruction decision on a certificate: what the operator's ticks
 * are allowed to claim.
 *
 * Why pure: this is the one rule standing between "the certificate records what
 * was destroyed" and "the certificate records what somebody said was destroyed".
 * It also used to fail the whole generation when a drive finished between the
 * decision prompt and the follow-up request — a 187-item job receives reports all
 * day, so on a live job that was not a rare race, it was the normal case.
 *
 *   php tests/cert_decision_test.php
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

/** A drive row as the route hands it over. */
function drv(string $serial, string $status): array {
    return ['serial' => $serial, 'status' => $status, 'model' => 'X', 'device' => 'HP'];
}

echo "== 1. a waiting drive accepts the decision ==\n";
$drives = [drv('AAA111', 'COMPLETED'), drv('BBB222', 'FAILED'), drv('CCC333', 'BLOCKED')];
[$ok, $unknown] = cert_decide_destroyed($drives, ['BBB222']);
t_check('the ticked drive is recorded as destroyed', $ok === ['BBB222'], $ok);
t_check('no unknown serial is reported', $unknown === null, $unknown);

echo "\n== 2. a drive that finished meanwhile is skipped, not refused ==\n";
// The dashboard offered the decision when BBB222 was FAILED; by the time the
// operator confirms, a later report has completed it.
$drivesMoved = [drv('AAA111', 'COMPLETED'), drv('BBB222', 'COMPLETED'), drv('CCC333', 'BLOCKED')];
[$ok, $unknown] = cert_decide_destroyed($drivesMoved, ['BBB222']);
t_check('a now-completed drive is not claimed as physically destroyed', $ok === [], $ok);
t_check('and it does not fail the request', $unknown === null, $unknown);

[$ok, $unknown] = cert_decide_destroyed($drivesMoved, ['BBB222', 'CCC333']);
t_check('the still-waiting drive in the same request is applied', $ok === ['CCC333'], $ok);
t_check('mixing settled and waiting drives is not an error', $unknown === null, $unknown);

echo "\n== 3. a settled outcome is honoured for every settled status ==\n";
foreach (['COMPLETED', 'DESTROYED', 'RETAINED'] as $st) {
    [$ok, $unknown] = cert_decide_destroyed([drv('S1', $st)], ['S1']);
    t_check($st . ' needs no decision and is skipped', $ok === [] && $unknown === null, [$ok, $unknown]);
}

echo "\n== 4. only a non-completed status is decision-worthy ==\n";
foreach (['FAILED', 'BLOCKED', 'FROZEN', 'STALLED', 'UNKNOWN', 'DRY-RUN', ''] as $st) {
    [$ok, $unknown] = cert_decide_destroyed([drv('S1', $st)], ['S1']);
    t_check(($st === '' ? '(empty)' : $st) . ' accepts the decision', $ok === ['S1'] && $unknown === null, [$ok, $unknown]);
}

echo "\n== 5. a serial from another job is refused ==\n";
[$ok, $unknown] = cert_decide_destroyed($drives, ['ZZZ999']);
t_check('an unknown serial is reported', $unknown === 'ZZZ999', $unknown);
t_check('nothing is accepted when the request is refused', $ok === [], $ok);
[$ok, $unknown] = cert_decide_destroyed($drives, ['BBB222', 'ZZZ999']);
t_check('one bad serial refuses the request as a whole', $ok === [] && $unknown === 'ZZZ999', [$ok, $unknown]);

echo "\n== 6. matching is case- and space-insensitive, output keeps the operator's form ==\n";
[$ok, $unknown] = cert_decide_destroyed([drv('bbb222', 'FAILED')], ['  BBB222 ']);
t_check('a lower-case row matches an upper-case tick', $unknown === null, $unknown);
t_check('the serial is passed on trimmed as the operator wrote it', $ok === ['BBB222'], $ok);
[$ok, $unknown] = cert_decide_destroyed([drv('BBB222', 'FAILED')], ['bbb222']);
t_check('a lower-case tick matches an upper-case row', $ok === ['bbb222'], $ok);

echo "\n== 7. empty input is ignored, not treated as an unknown drive ==\n";
[$ok, $unknown] = cert_decide_destroyed($drives, ['', '   ', 'BBB222']);
t_check('blank serials are dropped', $ok === ['BBB222'], $ok);
t_check('and do not fail the request', $unknown === null, $unknown);
[$ok, $unknown] = cert_decide_destroyed($drives, []);
t_check('an empty decision list accepts nothing and fails nothing', $ok === [] && $unknown === null, [$ok, $unknown]);

echo "\n== 8. a drive with no serial cannot be decided on ==\n";
[$ok, $unknown] = cert_decide_destroyed([drv('', 'FAILED')], ['']);
t_check('an unidentifiable drive yields no claim', $ok === [] && $unknown === null, [$ok, $unknown]);

echo "\n" . ($fails === 0 ? 'ALL PASS' : $fails . ' FAILED') . " — $count checks\n";
exit($fails === 0 ? 0 : 1);
