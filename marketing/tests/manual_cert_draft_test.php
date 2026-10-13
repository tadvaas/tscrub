<?php
/**
 * Integration test for the draft-certificate path: a job made only of manually
 * recorded destruction has no report, so it needs a certificate row created on
 * demand — and drafts must stay out of the certificate list.
 *
 * Run on the server:
 *   php /tmp/manual_cert_draft_test.php /home/oxwet/webs/tscrub-form
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

// The regression this test exists for is that nothing already issued may be hidden by
// the draft filter. Asserting "no certificate anywhere is a draft" asserted instead that
// the feature had never been used, and it failed as soon as it was (a manually recorded
// job legitimately leaves a draft behind). The real invariant is the COLUMN DEFAULT: a
// certificate created the old way is not a draft.
$defaults = db()->query("SHOW FULL COLUMNS FROM certificates LIKE 'is_draft'")->fetch(PDO::FETCH_ASSOC);
t_check('is_draft defaults to 0, so an existing insert path cannot create a draft',
    (string)($defaults['Default'] ?? '') === '0',
    (string)($defaults['Default'] ?? ''));
$issued = (int)db()->query('SELECT COUNT(*) FROM certificates WHERE is_draft = 1 AND pdf_path <> \'\'')->fetchColumn();
t_check('no issued certificate (one with a rendered PDF) is flagged as a draft', $issued === 0);

$userId = (int)db()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId <= 0) {
    echo "FAIL - no user in the database to test with\n";
    exit(1);
}

$cocid = 'TEST-DRAFT-DELETEME';
db()->prepare('DELETE FROM certificate_devices WHERE cocid = ?')->execute([$cocid]);
db()->prepare('DELETE FROM certificates WHERE cocid = ?')->execute([$cocid]);

try {
    $a = manual_cert_row($userId, $cocid);
    t_check('a draft certificate is created for a job with no report', is_array($a) && (int)($a['id'] ?? 0) > 0);
    $firstId = (int)$a['id'];
    t_check('the draft has a certificate id', (string)$a['cert_id'] !== '');
    t_check('the draft is flagged is_draft = 1', (int)$a['is_draft'] === 1);

    $b = manual_cert_row($userId, $cocid);
    t_check('a second call finds the same row instead of creating another', (int)($b['id'] ?? 0) === $firstId);
    $n = (int)db()->query('SELECT COUNT(*) FROM certificates WHERE cocid = ' . db()->quote($cocid))->fetchColumn();
    t_check('exactly one certificate row exists for the COCID', $n === 1);

    // The list filter: drafts must not appear as certificates.
    $stmt = db()->prepare('SELECT COUNT(*) FROM certificates WHERE cocid = ? AND is_draft = 0');
    $stmt->execute([$cocid]);
    t_check('draft is excluded from the certificate list filter', (int)$stmt->fetchColumn() === 0);

    // Generation clears the flag (generate_certificate sets is_draft = 0), which is
    // what makes the certificate appear in the list.
    db()->prepare('UPDATE certificates SET is_draft = 0 WHERE id = ?')->execute([$firstId]);
    $stmt->execute([$cocid]);
    t_check('a generated certificate passes the list filter', (int)$stmt->fetchColumn() === 1);

    // An empty COCID is refused rather than creating a junk row.
    t_check('an empty COCID returns null', manual_cert_row($userId, '') === null);

    // A device recorded against a draft job is found by the generate lookup.
    $dev = md_validate_device(['category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => 'IMEI-DRAFT-1', 'reason' => 'would_not_boot', 'destroy_method' => 'shredded'])['clean'];
    $med = md_validate_media(['serial' => 'IMEI-DRAFT-1', 'make' => 'Apple', 'model' => 'iPhone 6',
        'type' => 'eMMC/UFS (integrated)', 'size' => '16 GB', 'reason' => 'would_not_boot',
        'destroy_method' => 'shredded'])['clean'];
    $res = manual_attested_save_device($firstId, $cocid, $dev, [$med], 'tester@example.com');
    t_check('a device saves against a draft certificate', $res['ok'] === true);

    $load = manual_attested_load($firstId);
    t_check('the draft now carries the attested device and its medium',
        count($load['devices']) === 1 && count($load['devices'][0]['media']) === 1);
} finally {
    db()->prepare('DELETE FROM certificate_devices WHERE cocid = ?')->execute([$cocid]);
    db()->prepare('DELETE FROM certificates WHERE cocid = ?')->execute([$cocid]);
    $left = (int)db()->query('SELECT COUNT(*) FROM certificates WHERE cocid = ' . db()->quote($cocid))->fetchColumn();
    t_check('cleanup removed the draft', $left === 0);
}

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);
