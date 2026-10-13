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

// This assertion used to be the opposite: 'factory reset' was deliberately NOT a method,
// on the grounds that it is not a destruction and cannot be verified. Both halves of that
// are still true, but refusing to RECORD it only pushed network kit and phones into a
// spreadsheet, so it is now a method whose disposition is 'retained'. What matters is that
// the label is still refused: it is not the machine value.
$r = md_validate_device(['serial' => 'X1', 'make' => 'A', 'model' => 'B', 'category' => 'other',
    'reason' => 'other', 'destroy_method' => 'factory reset']);
t_check('a method label is not accepted in place of the machine value',
    strpos(t_errors($r), 'A method is required') !== false);

$r = md_validate_device(['serial' => 'X1', 'make' => 'A', 'model' => 'B', 'category' => 'other',
    'reason' => 'other', 'destroy_method' => 'FACTORY_RESET']);
t_check('a reset is accepted case-insensitively and normalised to the machine value',
    t_errors($r) === '' && $r['clean']['destroy_method'] === 'factory_reset');

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
t_eq('method line', 'Shredded (Acme Shredding, 2026-10-13)',
    md_attested_method_line(['destroy_method' => 'shredded', 'destroy_vendor' => 'Acme Shredding',
        'destroy_date' => '2026-10-13']));
t_eq('method line without evidence extras', 'Crushed',
    md_attested_method_line(['destroy_method' => 'crushed']));
// A reset has no destruction vendor: the mechanism IS the method, and a shredding vendor
// against a device the operator kept would invent a contractor who was never there.
t_eq('method line drops the vendor on a reset', 'Factory reset (2026-10-13)',
    md_attested_method_line(['destroy_method' => 'factory_reset', 'destroy_vendor' => 'Acme Shredding',
        'destroy_date' => '2026-10-13']));

// --- the item verb is chosen from the method, never supplied by the caller ---
t_eq('item line: destroyed', 'Destroyed — Shredded (Acme, 2026-10-13)',
    md_attested_line(['destroy_method' => 'shredded', 'destroy_vendor' => 'Acme',
        'destroy_date' => '2026-10-13']));
$resetLine = md_attested_line(['destroy_method' => 'factory_reset', 'destroy_date' => '2026-10-13']);
t_eq('item line: a reset states retention', 'Factory reset (2026-10-13) — device retained', $resetLine);
t_check('item line: a reset is never called destroyed', stripos($resetLine, 'destroy') === false);

// --- disposition is derived from the method, so the two cannot disagree ------
t_eq('factory reset is retained', 'retained', md_method_disposition('factory_reset'));
t_eq('shredding is destroyed', 'destroyed', md_method_disposition('shredded'));
t_eq('an unknown method is not silently retained', 'destroyed', md_method_disposition('melted'));
t_eq('an empty method is not silently retained', 'destroyed', md_method_disposition(''));

// --- counts split by outcome -------------------------------------------------
$mixed = [
    'devices' => [
        ['destroy_method' => 'shredded', 'media' => [['serial' => 'A'], ['serial' => 'B']]],
        ['destroy_method' => 'factory_reset', 'media' => [['serial' => 'C']]],
    ],
    'media' => [['destroy_method' => 'factory_reset', 'serial' => 'D']],
];
$split = md_attested_split($mixed);
t_eq('split: destroyed devices', 1, $split['destroyed']['devices']);
t_eq('split: destroyed media', 2, $split['destroyed']['media']);
t_eq('split: retained devices', 1, $split['retained']['devices']);
t_eq('split: retained media', 2, $split['retained']['media']);
t_eq('split of nothing', ['devices' => 0, 'media' => 0], md_attested_split([])['destroyed']);
$override = md_attested_split([
    'devices' => [['destroy_method' => 'shredded', 'media' => [['destroy_method' => 'factory_reset']]]],
]);
t_eq('split: a medium may differ from its host', 1, $override['retained']['media']);
t_eq('split: ...and the host is still destroyed', 1, $override['destroyed']['devices']);

// --- the summary line --------------------------------------------------------
t_eq('summary: the destroyed-only wording is unchanged',
    '2 media destroyed with 1 device. These items are operator-attested — not instrument-verified.',
    md_attested_summary(md_attested_split(['devices' => [['destroy_method' => 'shredded', 'media' => [
        ['destroy_method' => 'shredded'], ['destroy_method' => 'shredded']]]]])));
$mixedSummary = md_attested_summary($split);
t_check('summary: names the retained items',
    strpos($mixedSummary, 'reset to factory settings and retained') !== false);
t_check('summary: says a reset destroys no hardware',
    strpos($mixedSummary, 'destroys no hardware') !== false);

// --- the vocabulary the operator can actually choose -------------------------
t_check('the network and phone categories exist',
    array_key_exists('ip_phone', md_categories())
    && array_key_exists('switch', md_categories())
    && array_key_exists('router', md_categories())
    && array_key_exists('mobile_phone', md_categories()));
t_check('there is a reason for kit the appliance cannot boot',
    array_key_exists('not_supported_by_appliance', md_reasons()));
t_check('factory reset is selectable', array_key_exists('factory_reset', md_methods()));
t_eq('a factory reset validates with no vendor',
    '',
    t_errors(md_validate_device(['category' => 'ip_phone', 'make' => 'Cisco', 'model' => '7942',
        'serial' => 'FCW1234ABCD', 'reason' => 'not_supported_by_appliance',
        'destroy_method' => 'factory_reset', 'destroy_date' => '2026-10-13'])));
t_check('the reason question is not worded as a destruction',
    strpos(t_errors(md_validate_device(['category' => 'switch', 'make' => 'Cisco',
        'model' => 'C2960', 'serial' => 'FOC1', 'destroy_method' => 'shredded'])),
        'why was this not sanitised') !== false);

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
// A unit can be destroyed with no medium recorded (the operator did not record the disk,
// or there was none left). Leading with '0 MEDIA DESTROYED' contradicts the attestation
// in the same sentence, which is precisely the class of claim this helper exists to stop.
t_eq('banner: a destroyed unit with no recorded medium is not reported as zero media',
    '1 DEVICE DESTROYED (ATTESTED, NO MEDIA RECORDED)',
    md_attested_banner(0, 1, 0));
t_eq('banner: plural devices with no media',
    '2 DEVICES DESTROYED (ATTESTED, NO MEDIA RECORDED)',
    md_attested_banner(0, 2, 0));
t_eq('banner: verified media plus a unit with no media states both',
    '4 MEDIA SANITISED IN PLACE (VERIFIED) · 1 DEVICE DESTROYED (ATTESTED, NO MEDIA RECORDED)',
    md_attested_banner(4, 1, 0));

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

// A retained item is counted separately and is NEVER added to the destroyed figure: a
// factory reset destroys nothing, and the device stays with the operator.
t_eq('banner: a factory reset is stated as retained, not destroyed',
    '3 MEDIA FACTORY RESET (ATTESTED, RETAINED, 1 DEVICE)',
    md_attested_banner(0, 0, 0, 1, 3));
t_eq('banner: a reset unit with no recorded medium',
    '1 DEVICE FACTORY RESET (ATTESTED, RETAINED, NO MEDIA RECORDED)',
    md_attested_banner(0, 0, 0, 1, 0));
$bothBanner = md_attested_banner(2, 2, 4, 1, 3);
t_check('banner: destroyed and retained are never summed',
    strpos($bothBanner, '4 MEDIA DESTROYED (ATTESTED, 2 DEVICES)') !== false
    && strpos($bothBanner, '3 MEDIA FACTORY RESET (ATTESTED, RETAINED, 1 DEVICE)') !== false
    && strpos($bothBanner, '7 MEDIA') === false);
$retainedOnly = md_attested_banner(0, 0, 0, 1, 1);
t_check('banner: a retained-only job makes no destruction or verification claim',
    stripos($retainedOnly, 'DESTROYED') === false && stripos($retainedOnly, 'VERIFIED') === false);
// The destroyed-only banner must be byte-identical to what it was before the reset
// vocabulary existed, so no already-issued certificate reads differently.
t_eq('banner: the destroyed-only wording is unchanged',
    '12 MEDIA SANITISED IN PLACE (VERIFIED) · 3 MEDIA DESTROYED (ATTESTED, 1 DEVICE)',
    md_attested_banner(12, 1, 3, 0, 0));

t_check('footnote explains that attested items were not sanitised by us',
    strpos(md_attested_footnote(), 'not sanitised by this tool') !== false
    && strpos(md_attested_footnote(), 'not instrument-verified') !== false);
$retainedFootnote = md_attested_footnote(true);
t_check('footnote: a reset item is described as retained',
    strpos($retainedFootnote, 'RETAINED') !== false
    && strpos($retainedFootnote, 'not instrument-verified') !== false);
t_check('footnote: the destroyed-only wording is unchanged',
    strpos(md_attested_footnote(), 'RETAINED') === false);

echo "\n$count tests, $fails failures\n";
exit($fails === 0 ? 0 : 1);
