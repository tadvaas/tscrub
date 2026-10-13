<?php
/**
 * Unit tests for manual_destruction.php — the identity and validation rules for
 * manually recorded destruction. No database and no TCPDF: this covers the rules
 * that the API, the certificate and the JSON-LD twin all depend on.
 *
 * Run: php marketing/tests/manual_destruction_test.php
 */

require_once __DIR__ . '/../server/manual_destruction.php';

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

function t_eq(string $label, $expected, $actual): void
{
    $ok = $expected === $actual;
    global $count, $fails;
    $count++;
    if ($ok) {
        echo "ok   - $label\n";
    } else {
        $fails++;
        echo "FAIL - $label\n";
        echo "       expected: " . var_export($expected, true) . "\n";
        echo "       actual:   " . var_export($actual, true) . "\n";
    }
}

function t_errors(array $r): string
{
    return implode(' | ', $r['errors']);
}

// --- a good device validates and yields a clean row -------------------------
$good = [
    'category'       => 'MOBILE_PHONE',
    'make'           => '  Apple ',
    'model'          => "iPhone  6",
    'serial'         => ' 356789012345678 ',
    'reason'         => 'MEDIA_SOLDERED',
    'destroy_method' => 'SHREDDED',
    'destroy_vendor' => 'Acme Shredding',
    'destroy_date'   => '2026-10-13',
    'destroy_witness' => 'J. Smith',
    'evidence_ref'   => 'ACME-2026-0417',
];
$r = md_validate_device($good);
t_eq('good device: no errors', '', t_errors($r));
t_eq('good device: serial trimmed', '356789012345678', $r['clean']['serial']);
t_eq('good device: make trimmed', 'Apple', $r['clean']['make']);
t_eq('good device: model whitespace collapsed', 'iPhone 6', $r['clean']['model']);
t_eq('good device: category normalised', 'mobile_phone', $r['clean']['category']);
t_eq('good device: reason normalised', 'media_soldered', $r['clean']['reason']);
t_eq('good device: method normalised', 'shredded', $r['clean']['destroy_method']);
t_eq('good device: witness kept', 'J. Smith', $r['clean']['destroy_witness']);

// --- identity: mandatory, non-placeholder ----------------------------------
$r = md_validate_device(['make' => 'Apple', 'model' => 'iPhone 6', 'serial' => '',
    'category' => 'mobile_phone', 'reason' => 'media_soldered', 'destroy_method' => 'shredded']);
t_check('blank serial rejected', strpos(t_errors($r), 'A serial is required') !== false);

foreach (['N/A', 'n/a', 'unknown', 'none', 'To Be Filled By O.E.M.', '0'] as $placeholder) {
    $r = md_validate_device(['make' => 'Apple', 'model' => 'iPhone 6', 'serial' => $placeholder,
        'category' => 'mobile_phone', 'reason' => 'media_soldered', 'destroy_method' => 'shredded']);
    t_check('placeholder serial rejected: ' . $placeholder,
        strpos(t_errors($r), 'is a placeholder') !== false);
}

foreach (['make', 'model'] as $missing) {
    $row = ['serial' => 'X1', 'make' => 'Apple', 'model' => 'iPhone 6',
        'category' => 'mobile_phone', 'reason' => 'media_soldered', 'destroy_method' => 'shredded'];
    $row[$missing] = '';
    $r = md_validate_device($row);
    t_check($missing . ' is required', strpos(t_errors($r), 'is required') !== false);
}

// --- enums, dates, lengths --------------------------------------------------
$r = md_validate_device(['serial' => 'X1', 'make' => 'A', 'model' => 'B', 'category' => 'nope',
    'reason' => 'because', 'destroy_method' => 'shredded']);
t_check('unknown category rejected', strpos(t_errors($r), 'category is required') !== false);
t_check('unknown reason rejected', strpos(t_errors($r), 'A reason is required') !== false);

$r = md_validate_device(['serial' => 'X1', 'make' => 'A', 'model' => 'B', 'category' => 'other',
    'reason' => 'other', 'destroy_method' => 'factory reset']);
t_check('an invented method (factory reset) is rejected',
    strpos(t_errors($r), 'destruction method is required') !== false);

$r = md_validate_device(['serial' => 'X1', 'make' => 'A', 'model' => 'B', 'category' => 'other',
    'reason' => 'other', 'destroy_method' => 'shredded', 'destroy_date' => '13/10/2026']);
t_check('non-ISO date rejected', strpos(t_errors($r), 'YYYY-MM-DD') !== false);

$r = md_validate_device(['serial' => str_repeat('X', 300), 'make' => 'A', 'model' => 'B',
    'category' => 'other', 'reason' => 'other', 'destroy_method' => 'shredded']);
t_eq('over-long serial is capped, not rejected', 128, strlen($r['clean']['serial']));
t_eq('over-long serial: no errors', '', t_errors($r));

// --- identity key semantics (the whole point of the rule) -------------------
t_eq('same triple, different case -> one identity',
    md_identity_key('ABC123', 'Apple', 'iPhone 6'),
    md_identity_key('abc123', 'apple', 'iphone 6'));
t_check('case-insensitive identity',
    md_identity_key('ABC123', 'Apple', 'iPhone 6') === md_identity_key(' abc123 ', 'apple', 'iPhone  6'));
t_check('NBSP and zero-width chars do not split an identity',
    md_identity_key("ABC\u{00A0}123", "Ap\u{200B}ple", 'iPhone 6')
        === md_identity_key('ABC 123', 'Apple', 'iPhone 6'));
t_check('same serial, different make -> different identity (accepted)',
    md_identity_key('1000000000', 'Kingston', 'DataTraveler')
        !== md_identity_key('1000000000', 'SanDisk', 'Cruzer'));
t_check('same serial, same make, different model -> different identity',
    md_identity_key('S1', 'Apple', 'iPhone 6') !== md_identity_key('S1', 'Apple', 'iPhone 7'));
t_check('field bleed impossible (separator)',
    md_identity_key('A', 'B', 'C') !== md_identity_key('A B', 'C', ''));

// --- media validation -------------------------------------------------------
$r = md_validate_media(['serial' => 'BTNH94823D6P', 'make' => 'Intel', 'model' => 'SSDPEKNW512G8H',
    'type' => 'SSD', 'size' => '512 GB', 'bus' => 'NVMe', 'reason' => 'unsupported_media',
    'destroy_method' => 'shredded']);
t_eq('good media: no errors', '', t_errors($r));
t_eq('good media: type kept', 'SSD', $r['clean']['type']);
t_eq('good media: size kept', '512 GB', $r['clean']['size']);

$r = md_validate_media(['serial' => 'X1', 'make' => 'A', 'model' => 'B', 'type' => '',
    'reason' => 'other', 'destroy_method' => 'shredded']);
t_check('media type is required', strpos(t_errors($r), 'media type is required') !== false);

// An integrated-storage medium is the phone case: the media line is the unit's
// soldered storage, so it carries the unit's identity and says so.
$r = md_validate_media(['serial' => '356789012345678', 'make' => 'Apple', 'model' => 'iPhone 6',
    'type' => 'eMMC/UFS (integrated)', 'size' => '16 GB', 'reason' => 'media_soldered',
    'destroy_method' => 'shredded']);
t_eq('integrated-storage medium accepted for a phone', '', t_errors($r));

// --- label helpers never return a blank cell --------------------------------
t_eq('method label', 'Shredded', md_label(md_methods(), 'shredded'));
t_eq('unknown method prints raw', 'melted', md_label(md_methods(), 'melted'));
t_eq('category label', 'Mobile phone', md_label(md_categories(), 'mobile_phone'));
t_eq('reason label', 'Media soldered / integrated', md_label(md_reasons(), 'media_soldered'));
t_check('every method has a label', count(array_filter(md_methods())) === count(md_methods()));
t_check('every reason has a label', count(array_filter(md_reasons())) === count(md_reasons()));

t_eq('device line', 'Mobile phone — Apple iPhone 6 (356789012345678)',
    md_device_line(['category' => 'mobile_phone', 'make' => 'Apple', 'model' => 'iPhone 6',
        'serial' => '356789012345678']));
t_eq('destruction line', 'Shredded (Acme Shredding, 2026-10-13)',
    md_destruction_line(['destroy_method' => 'shredded', 'destroy_vendor' => 'Acme Shredding',
        'destroy_date' => '2026-10-13']));
t_eq('destruction line without evidence extras', 'Crushed',
    md_destruction_line(['destroy_method' => 'crushed']));

// --- the attested qualifier exists and says the honest thing ----------------
t_check('qualifier names both roles',
    strpos(md_attested_qualifier(), 'attested') !== false
    && strpos(md_attested_qualifier(), 'not instrument-verified') !== false);

// --- claim wording (PDF + JSON-LD read these, so they cannot drift) ---------
$attested = [
    'devices' => [['media' => [['serial' => 'A'], ['serial' => 'B']]]],
    'media'   => [['serial' => 'C']],
];
t_eq('counts: a device and all its media', ['devices' => 1, 'media' => 3], md_attested_counts($attested));
t_eq('counts: nothing recorded', ['devices' => 0, 'media' => 0], md_attested_counts([]));

t_eq('banner: mixed job states both figures separately',
    '12 MEDIA SANITISED IN PLACE (VERIFIED) · 3 MEDIA DESTROYED (ATTESTED, 1 DEVICE)',
    md_attested_banner(12, 1, 3));
t_eq('banner: plural devices',
    '5 MEDIA DESTROYED (ATTESTED, 2 DEVICES)',
    md_attested_banner(0, 2, 5));

$onlyAttested = md_attested_banner(0, 1, 1);
t_check('banner: an attested-only job makes no verified claim',
    strpos($onlyAttested, 'VERIFIED') === false);
t_check('banner: an attested-only job still says attested',
    strpos($onlyAttested, 'ATTESTED') !== false);

$onlyVerified = md_attested_banner(7, 0, 0);
t_check('banner: a verified-only job makes no attested claim',
    strpos($onlyVerified, 'ATTESTED') === false);

t_eq('banner: nothing recorded is stated, not left blank',
    'NO MEDIA RECORDED', md_attested_banner(0, 0, 0));
t_check('footnote explains that attested items were not sanitised by us',
    strpos(md_attested_footnote(), 'not sanitised by this tool') !== false
    && strpos(md_attested_footnote(), 'not instrument-verified') !== false);

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);
