<?php
declare(strict_types=1);

/**
 * The sanitisaton-standard claim: the rule, and the surfaces that must use it.
 *
 * Two things are asserted, and the second is the one that matters.
 *
 *   1. the rule itself — a job is IEEE only when EVERY scored drive earned it,
 *      a refused drive takes the job back to NIST with its shortfall, and a
 *      pre-claim record states nothing;
 *   2. that the PDF and the /verify page CALL this rule instead of re-deriving
 *      it. This repo has shipped a helper with passing tests that nothing
 *      called (md_attested_banner), and three claim surfaces disagreeing at
 *      once. Unit-testing the rule alone would not have caught either.
 *
 * Note what is deliberately NOT here: no rendered-PDF assertion. That lives in
 * cert_claim_line_test.php, which builds real documents. This test is pure and
 * runs anywhere.
 *
 *   php tests/claim_surfaces_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/claim_lib.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok): void
{
    global $count, $fails;
    $count++;
    if ($ok) { echo "ok   - $label\n"; } else { $fails++; echo "FAIL - $label\n"; }
}

/** A drive row as the certificate builder sees it. */
function d(string $std = '', string $level = '', string $short = ''): array
{
    return ['sanitization_standard' => $std, 'sanitization_level' => $level, 'sanitization_shortfall' => $short];
}

echo "== 1. the rule ==\n";

// Every drive earned it, same level.
$c = cert_claim([d('IEEE 2883-2022', 'Purge'), d('IEEE 2883-2022', 'Purge')]);
t_check('uniform Purge job claims IEEE', $c['ieee'] === true);
t_check('uniform job reports one level', $c['level'] === 'Purge');
t_check('label carries the level', cert_claim_label($c) === 'IEEE 2883-2022 (Purge)');
t_check('awarded job carries no shortfall', $c['shortfall'] === '');

// Mixed levels: both were earned, so the job says both.
$c = cert_claim([d('IEEE 2883-2022', 'Purge'), d('IEEE 2883-2022', 'Clear')]);
t_check('mixed levels still claim IEEE', $c['ieee'] === true);
t_check('mixed levels read "Clear, Purge"', $c['level'] === 'Clear, Purge');

// One refusal takes the whole job back to NIST, and says why.
$c = cert_claim([d('IEEE 2883-2022', 'Purge'), d('NIST 800-88', '', 'G2 coverage 2% < 5%')]);
t_check('one refused drive denies the job', $c['ieee'] === false);
t_check('refused job states NIST', $c['standard'] === 'NIST SP 800-88 Rev 1');
t_check('refused job carries the failing gate', $c['shortfall'] === 'G2 coverage 2% < 5%');
t_check('refused job reports no level', $c['level'] === '');
t_check('refused label is the plain standard', cert_claim_label($c) === 'NIST SP 800-88 Rev 1');

// A pre-claim record (appliance older than the claim) makes no statement.
$c = cert_claim([d(), d()]);
t_check('pre-claim job claims nothing', $c['ieee'] === false);
t_check('pre-claim job states NIST as the recorded basis', $c['standard'] === 'NIST SP 800-88 Rev 1');
t_check('pre-claim job has no shortfall', $c['shortfall'] === '');

// No drives at all (an attested-only certificate).
$c = cert_claim([]);
t_check('no drives claims nothing', $c['ieee'] === false);

// An awarded job whose level was never recorded must not invent one.
$c = cert_claim([d('IEEE 2883-2022', ''), d('IEEE 2883-2022', '')]);
t_check('missing level still claims IEEE', $c['ieee'] === true);
t_check('missing level is not guessed', $c['level'] === '');
t_check('missing level prints without brackets', cert_claim_label($c) === 'IEEE 2883-2022');

echo "\n== 2. what a surface shows ==\n";

$awarded = cert_claim_display(cert_claim([d('IEEE 2883-2022', 'Clear')]));
t_check('awarded job produces a row', is_array($awarded));
t_check('row value is the label', ($awarded['value'] ?? '') === 'IEEE 2883-2022 (Clear)');
t_check('row note is the caveat', ($awarded['note'] ?? '') === cert_claim_caveat());
t_check('caveat never says certified as a claim',
    stripos(cert_claim_caveat(), 'not ieee certified') !== false);
t_check('refused job produces NO row', cert_claim_display(cert_claim([d('NIST 800-88')])) === null);
t_check('pre-claim job produces NO row', cert_claim_display(cert_claim([d()])) === null);

echo "\n== 3. the surfaces actually call the rule ==\n";

$cert = (string)file_get_contents($serverDir . '/render_cert.php');
$page = (string)file_get_contents($serverDir . '/verify.php');

t_check('PDF includes the shared library', strpos($cert, "claim_lib.php") !== false);
t_check('verify page includes the shared library', strpos($page, "claim_lib.php") !== false);

// The rule must exist in exactly one place.
t_check('PDF no longer defines the rule', preg_match('/function\s+cert_claim\s*\(/', $cert) !== 1);
t_check('verify page does not define the rule', preg_match('/function\s+cert_claim\s*\(/', $page) !== 1);

// Both surfaces must call it, not merely include it.
t_check('PDF calls cert_claim()', preg_match('/cert_claim\(\$drives\)/', $cert) === 1);
t_check('PDF prints the shared label', strpos($cert, 'cert_claim_label($claim)') !== false);
t_check('PDF prints the shared caveat', strpos($cert, 'cert_claim_caveat()') !== false);
t_check('verify page computes the claim with the shared rule',
    strpos($page, 'cert_claim_display(cert_claim($claimDrives))') !== false);
t_check('verify page renders the shared view', strpos($page, 'cert_claim_display(') !== false);
t_check('verify page renders the row only when there is a claim',
    strpos($page, '$claimView !== null') !== false);

// The caveat must not be hand-written anywhere else: one string, one source.
t_check('caveat text is not duplicated in the PDF',
    substr_count($cert, 'methods described, not IEEE certified') === 0);
t_check('caveat text is not duplicated in the page',
    substr_count($page, 'methods described, not IEEE certified') === 0);
t_check('caveat defined once in the library',
    substr_count((string)file_get_contents($serverDir . '/claim_lib.php'), 'methods described, not IEEE certified') === 1);

echo "\n" . ($fails === 0 ? "ALL PASS" : "$fails FAILED") . " — $count checks\n";
exit($fails === 0 ? 0 : 1);
