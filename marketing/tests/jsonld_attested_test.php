<?php
/**
 * Unit tests for the machine-readable twin's attested-destruction block.
 *
 * build_cert_jsonld() is a pure function of arrays — no TCPDF, no database — so the
 * JSON-LD can be asserted directly. That matters here: the twin is what an automated
 * auditor reads, and its whole point is that attested destruction must never be
 * indistinguishable from instrument-verified sanitisation.
 *
 * Run: php marketing/tests/jsonld_attested_test.php
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/cert_terms.php';
require_once $serverDir . '/manual_destruction.php';
require_once $serverDir . '/jsonld.php';

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

$summary = [
    'cert' => 'CERT-TEST-1', 'cocid' => 'COC-1', 'issued_at' => '2026-10-13 09:00:00',
    'devices' => 0, 'methods' => 0, 'runs' => 0, 'first' => null, 'last' => null,
    'sha_state' => 'unverified', 'sig_state' => 'none', 'pdf_sha256' => '',
];
$issuer = ['name' => 'Test Certifier', 'email' => 'certifier@example.com'];

$verifiedDrive = [
    'serial' => 'SN-REPORT-1', 'model' => 'Reported Disk', 'type' => 'SSD', 'size' => '256 GB',
    'bus' => 'SATA', 'method' => 'NVMe Crypto Purge', 'cls' => 'PURGE', 'status' => 'COMPLETED',
];

$attested = [
    'devices' => [[
        'category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => '356789012345678', 'destroy_method' => 'shredded',
        'destroy_vendor' => 'Acme Shredding', 'destroy_date' => '2026-10-13',
        'attested_by' => 'Jane Operator <jane@example.com>', 'destroy_witness' => 'J. Smith',
        'evidence_ref' => 'ACME-2026-0417',
        'media' => [['type' => 'eMMC/UFS (integrated)', 'model' => 'iPhone 6',
            'serial' => '356789012345678', 'size' => '16 GB', 'bus' => '']],
    ]],
    'media' => [['type' => 'SSD', 'model' => 'SSDPEKNW512G8H', 'serial' => 'BTNH94823D6P',
        'size' => '512 GB', 'bus' => 'NVMe', 'destroy_method' => 'shredded',
        'destroy_date' => '2026-10-13']],
];

// --- a certificate with no attested records is unchanged ---------------------
$plain = build_cert_jsonld($summary, [$verifiedDrive], [], $issuer);
t_check('no attested block on a plain certificate', !isset($plain['attestedDestruction']));
t_check('verified drives are still listed', isset($plain['about']) && count($plain['about']) === 1);

// --- the attested block ------------------------------------------------------
$doc = build_cert_jsonld($summary, [$verifiedDrive], [], $issuer, $attested);
t_check('attested block present when records exist', isset($doc['attestedDestruction']));
if (isset($doc['attestedDestruction'])) {
    $block = $doc['attestedDestruction'];
    t_check('counts: 1 device, 2 media', $block['devices'] === 1 && $block['media'] === 2);
    t_check('the qualifier travels with the block',
        strpos((string)$block['qualifier'], 'not instrument-verified') !== false);
    t_check('an item and a standalone medium are both listed', count($block['items']) === 2);

    $dev = $block['items'][0];
    t_check('device item names the unit', strpos((string)$dev['name'], 'iPhone 6') !== false);
    t_check('device item is marked destroyed + attested',
        $dev['disposition'] === 'destroyed' && $dev['attestation'] === 'operator-attested');
    t_check('device item records the destruction method and vendor',
        strpos((string)$dev['destroyMethod'], 'Shredded') !== false
        && strpos((string)$dev['destroyMethod'], 'Acme Shredding') !== false);
    t_check('device item records the witness and evidence reference',
        $dev['witness'] === 'J. Smith' && $dev['evidenceRef'] === 'ACME-2026-0417');
    t_check('the device carries its own media', count($dev['media']) === 1);
    t_check('the medium is marked destroyed + attested',
        $dev['media'][0]['disposition'] === 'destroyed'
        && $dev['media'][0]['attestation'] === 'operator-attested');

    $solo = $block['items'][1];
    t_check('a standalone medium is typed as a Medium, not a Device', $solo['type'] === 'Medium');
    t_check('a standalone medium carries its model and serial',
        $solo['model'] === 'SSDPEKNW512G8H' && $solo['serial'] === 'BTNH94823D6P');
}

// --- the honesty guarantee: attested media are NOT verified drives ------------
// The key is `serialNumber` (jsonld_drive()), not `serial`. Getting that wrong made
// the negative assertion below pass vacuously the first time — it compared against a
// key that never exists — so the list itself is asserted to be populated first.
$serials = [];
foreach (($doc['about'] ?? []) as $d) {
    $serials[] = strtolower((string)($d['serialNumber'] ?? ''));
}
t_check('the verified drive list is populated (guards against a vacuous check)',
    count($serials) === 1 && $serials[0] === 'sn-report-1');
t_check('attested media do not appear in the verified drive list',
    !in_array('btnh94823d6p', $serials, true) && !in_array('356789012345678', $serials, true));
t_check('the verified drive is still there', in_array('sn-report-1', $serials, true));
t_check('attested items report no NIST level (that would be a sanitisation claim)',
    !isset($doc['attestedDestruction']['items'][0]['nistLevel']));

// mediaDisposition describes verified sanitisation, so it must not claim the
// attested media were sanitised in place.
$disp = (string)($doc['mediaDisposition'] ?? '');
t_check('mediaDisposition does not absorb the attested media',
    strpos($disp, '3') === false && strpos($disp, 'destroyed with') === false);

// --- a factory reset is retained, and the twin must say so -------------------
// An automated auditor reads this document, so a retained device must not be filed as
// destroyed property — and it must not carry a destroyMethod, because there was no
// destruction to name.
$resetDoc = build_cert_jsonld($summary, [$verifiedDrive], [], $issuer, [
    'devices' => [[
        'category' => 'ip_phone', 'make' => 'Cisco', 'model' => '7942G',
        'serial' => 'FCW1234ABCD', 'destroy_method' => 'factory_reset',
        'destroy_vendor' => '', 'destroy_date' => '2026-10-16',
        'attested_by' => 'Jane Operator',
        'media' => [['type' => 'Flash (integrated)', 'serial' => 'FCW1234ABCD']],
    ]],
    'media' => [],
]);
$resetBlock = $resetDoc['attestedDestruction'] ?? [];
t_check('reset: the block is still emitted', $resetBlock !== []);
t_check('reset: totals still count the item', ($resetBlock['devices'] ?? 0) === 1);
t_check('reset: the split keeps it out of the destroyed figure',
    ($resetBlock['destroyed'] ?? []) === ['devices' => 0, 'media' => 0]
    && ($resetBlock['retained'] ?? []) === ['devices' => 1, 'media' => 1]);

$resetItem = $resetBlock['items'][0] ?? [];
t_check('reset: the item is marked retained, not destroyed',
    ($resetItem['disposition'] ?? '') === 'retained');
t_check('reset: it is still labelled as an attestation',
    ($resetItem['attestation'] ?? '') === 'operator-attested');
t_check('reset: it carries no destroyMethod',
    !isset($resetItem['destroyMethod']));
t_check('reset: the method is still named',
    strpos((string)($resetItem['method'] ?? ''), 'Factory reset') !== false);
t_check('reset: the medium inherits the outcome',
    ($resetItem['media'][0]['disposition'] ?? '') === 'retained'
    && !isset($resetItem['media'][0]['destroyMethod']));
// The destroyed case is unchanged, so existing consumers of this document keep working.
t_check('destroyed items still carry destroyMethod alongside method',
    ($doc['attestedDestruction']['items'][0]['disposition'] ?? '') === 'destroyed'
    && ($doc['attestedDestruction']['items'][0]['destroyMethod'] ?? '')
        === ($doc['attestedDestruction']['items'][0]['method'] ?? null));

// --- an empty attested set keeps the document byte-identical -----------------
$again = build_cert_jsonld($summary, [$verifiedDrive], [], $issuer);
t_check('an empty attested set produces the same document as omitting it',
    json_encode($again) === json_encode($plain));

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);
