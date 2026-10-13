<?php
/**
 * Manually recorded destruction — shared vocabulary, identity and validation.
 *
 * Covers devices and media that never reach the appliance: phones, kit that will
 * not power on, soldered or unsupported media. They are recorded as an operator
 * ATTESTATION, which must stay unmistakably distinct from the instrument-verified
 * sanitisation path — every surface that prints one of these rows must also print
 * md_attested_qualifier().
 *
 * Deliberately free of TCPDF and of the database, so the API, the printable
 * certificate (render_cert.php) and the machine-readable twin (jsonld.php) can
 * share exactly one vocabulary and one definition of "the same asset".
 *
 * Plan: research/manual-destruction/README.md
 */

require_once __DIR__ . '/grading.php';   // device_key_placeholder(): the single placeholder list

/** What the destroyed unit was. */
function md_categories(): array
{
    return [
        'mobile_phone'  => 'Mobile phone',
        'tablet'        => 'Tablet',
        'laptop'        => 'Laptop',
        'desktop'       => 'Desktop',
        'server'        => 'Server',
        'ip_phone'      => 'IP phone',
        'switch'        => 'Network switch',
        'router'        => 'Router / firewall',
        'network_appliance' => 'Network appliance (other)',
        'sealed_unit'   => 'Sealed unit',
        'embedded'      => 'Embedded board',
        'other'         => 'Other',
    ];
}

/**
 * Why the item was dealt with this way rather than sanitised by the appliance. Required,
 * because that is the first question an auditor asks. The same list serves both outcomes:
 * a unit can be destroyed because it would not boot, or reset because the appliance
 * cannot boot it at all.
 */
function md_reasons(): array
{
    return [
        'not_supported_by_appliance' => 'Not supported by tScrub (cannot be booted)',
        'would_not_power_on'      => 'Would not power on',
        'would_not_boot'          => 'Would not boot / no supported interface',
        'media_soldered'          => 'Media soldered / integrated',
        'unsupported_media'       => 'Unsupported media type',
        'battery_unsafe'          => 'Battery unsafe to power',
        'physically_damaged'      => 'Physically damaged',
        'beyond_economic_repair'  => 'Beyond economic repair',
        'other'                   => 'Other',
    ];
}

/**
 * How the item was dealt with.
 *
 * This list once refused `factory reset` outright — "it is not a destroy method and it
 * cannot be verified". Both halves of that are still true, and the second half is still
 * enforced: nothing here is ever presented as verified. What changed (2026-10-13) is that
 * refusing to RECORD it left network kit and phones in a spreadsheet, so it is now
 * offered as a method of its own with the disposition derived from it
 * (md_method_disposition()). A reset item is recorded as sanitised and RETAINED and must
 * never be described as destroyed anywhere — the operator keeps the device, so a
 * "destroyed" claim would be false in their name.
 */
function md_methods(): array
{
    return [
        'shredded'                 => 'Shredded',
        'crushed'                  => 'Crushed',
        'degaussed'                => 'Degaussed',
        'incinerated'              => 'Incinerated',
        'disintegrated'            => 'Disintegrated',
        'dismantled_then_shredded' => 'Dismantled, then shredded',
        'factory_reset'            => 'Factory reset',
        'other'                    => 'Other',
    ];
}

/**
 * The outcome a method implies: 'retained' when the device survives, 'destroyed'
 * otherwise. Derived rather than stored, so the operator cannot pick a method and a
 * disposition that disagree.
 */
function md_method_disposition(string $method): string
{
    return $method === 'factory_reset' ? 'retained' : 'destroyed';
}

/** The marker that must appear wherever an attested row is printed. */
function md_attested_qualifier(): string
{
    return 'operator-attested — not instrument-verified';
}

/**
 * Counts for the attested half of a certificate. Devices are units; media are the
 * drive rows. They are counted separately from verified media and never merged into
 * a single figure.
 */
function md_attested_counts(array $attested): array
{
    $devices = 0;
    $media   = 0;
    foreach (($attested['devices'] ?? []) as $d) {
        $devices++;
        $media += count($d['media'] ?? []);
    }
    $media += count($attested['media'] ?? []);   // standalone destroyed media

    return ['devices' => $devices, 'media' => $media];
}

/**
 * The certificate's claim line for a mixed job.
 *
 * Verified and attested are stated as two separate figures. They are never summed:
 * one is instrument-verified, the other is an operator attestation, and a single
 * total would claim more than the evidence supports. Wording lives here so the PDF
 * and the JSON-LD twin cannot drift apart.
 */
function md_attested_banner(int $verifiedMedia, int $destroyedDevices, int $destroyedMedia, int $retainedDevices = 0, int $retainedMedia = 0): string
{
    $parts = [];

    if ($verifiedMedia > 0) {
        $parts[] = $verifiedMedia . ' MEDIA SANITISED IN PLACE (VERIFIED)';
    }

    if ($destroyedMedia > 0) {
        $devices = '';
        if ($destroyedDevices > 0) {
            $devices = ', ' . $destroyedDevices . ($destroyedDevices === 1 ? ' DEVICE' : ' DEVICES');
        }
        $parts[] = $destroyedMedia . ' MEDIA DESTROYED (ATTESTED' . $devices . ')';
    } elseif ($destroyedDevices > 0) {
        // A destroyed unit with no recorded medium is a real case: the operator may not
        // have recorded the disk, or the unit had none left to record. Leading with
        // '0 MEDIA DESTROYED' would contradict the attestation in the same sentence, so
        // the device figure leads and the missing media is stated rather than implied.
        $parts[] = $destroyedDevices . ($destroyedDevices === 1 ? ' DEVICE' : ' DEVICES')
            . ' DESTROYED (ATTESTED, NO MEDIA RECORDED)';
    }

    // The retained figure is separate from the destroyed one and is never added to it: a
    // factory reset destroys nothing, and the device stays in circulation.
    if ($retainedMedia > 0) {
        $devices = '';
        if ($retainedDevices > 0) {
            $devices = ', ' . $retainedDevices . ($retainedDevices === 1 ? ' DEVICE' : ' DEVICES');
        }
        $parts[] = $retainedMedia . ' MEDIA FACTORY RESET (ATTESTED, RETAINED' . $devices . ')';
    } elseif ($retainedDevices > 0) {
        $parts[] = $retainedDevices . ($retainedDevices === 1 ? ' DEVICE' : ' DEVICES')
            . ' FACTORY RESET (ATTESTED, RETAINED, NO MEDIA RECORDED)';
    }

    return $parts ? implode(' · ', $parts) : 'NO MEDIA RECORDED';
}

/** The sentence that must accompany the attested figures. */
function md_attested_footnote(bool $hasRetained = false): string
{
    $s = 'Attested items were not sanitised by this tool: they were recorded by the '
        . 'operator named above as physically destroyed';
    if ($hasRetained) {
        $s .= ', or — for the factory-reset items — as reset using the device\'s own '
            . 'facilities and RETAINED. A factory reset destroys no hardware and the '
            . 'device stays in circulation, so those items are a declaration of what the '
            . 'operator did, not a finding about the media';
    }
    return $s . '. Attested items are ' . md_attested_qualifier() . '.';
}

/**
 * Attested counts split by outcome, for the claim line and the section sub-line.
 *
 * A medium inherits its parent device's outcome unless it names its own method, which is
 * the same rule manual_media_insert() applies when it stores the row.
 */
function md_attested_split(array $attested): array
{
    $out = [
        'destroyed' => ['devices' => 0, 'media' => 0],
        'retained'  => ['devices' => 0, 'media' => 0],
    ];
    $bump = function (string $method, bool $isDevice) use (&$out): void {
        $d = md_method_disposition($method);
        if ($isDevice) { $out[$d]['devices']++; } else { $out[$d]['media']++; }
    };

    foreach (($attested['devices'] ?? []) as $dev) {
        $bump((string)($dev['destroy_method'] ?? ''), true);
        foreach (($dev['media'] ?? []) as $m) {
            $own = (string)($m['destroy_method'] ?? '');
            $bump($own !== '' ? $own : (string)($dev['destroy_method'] ?? ''), false);
        }
    }
    foreach (($attested['media'] ?? []) as $m) {
        $bump((string)($m['destroy_method'] ?? ''), false);
    }

    return $out;
}

/**
 * The sub-line under the attestation heading: what was recorded, split by outcome.
 *
 * Shared so the certificate, the verification page and the dashboard cannot describe the
 * same records in different words. The wording for a destroyed-only record is unchanged,
 * so nothing about an existing certificate reads differently.
 */
function md_attested_summary(array $split): string
{
    $pair = function (int $n, string $one, string $many): string {
        return $n . ' ' . ($n === 1 ? $one : $many);
    };

    $d = $split['destroyed'] ?? ['devices' => 0, 'media' => 0];
    $r = $split['retained']  ?? ['devices' => 0, 'media' => 0];
    $phrases = [];

    if ((int)$d['media'] > 0) {
        $phrases[] = $pair((int)$d['media'], 'medium', 'media') . ' destroyed'
            . ((int)$d['devices'] > 0 ? ' with ' . $pair((int)$d['devices'], 'device', 'devices') : '');
    } elseif ((int)$d['devices'] > 0) {
        $phrases[] = $pair((int)$d['devices'], 'device', 'devices') . ' destroyed, no medium recorded';
    }

    if ((int)$r['media'] > 0) {
        $phrases[] = $pair((int)$r['media'], 'medium', 'media') . ' reset to factory settings and retained'
            . ((int)$r['devices'] > 0 ? ' with ' . $pair((int)$r['devices'], 'device', 'devices') : '');
    } elseif ((int)$r['devices'] > 0) {
        $phrases[] = $pair((int)$r['devices'], 'device', 'devices') . ' reset to factory settings and retained, no medium recorded';
    }

    $body = $phrases ? ucfirst(implode('; ', $phrases)) . '.' : 'No items recorded.';
    $body .= ' These items are ' . md_attested_qualifier() . '.';
    if ((int)$r['devices'] + (int)$r['media'] > 0) {
        $body .= ' A factory reset destroys no hardware, so the reset devices remain in circulation.';
    }
    return $body;
}

/**
 * Who attested, taken from the SESSION user — never from a client-supplied name.
 * An attestation is a statement of record, so who made it is part of the record.
 */
function manual_attester(array $user): string
{
    $name = trim((string)($user['name'] ?? ''));
    $mail = trim((string)($user['email'] ?? ''));
    if ($name !== '' && $mail !== '') { return $name . ' <' . $mail . '>'; }
    return $name !== '' ? $name : $mail;
}

/** Machine value -> label, falling back to the raw value (never a blank cell). */
function md_label(array $map, string $key): string
{
    $k = strtolower(trim($key));
    return $map[$k] ?? $key;
}

/**
 * Trim, drop control/zero-width characters, collapse internal whitespace and
 * casefold. The collapse matters: this data is pasted from spreadsheets, and
 * `Apple ` / `apple` / `iPhone  6` must not create a second identity for one item.
 * NBSP and friends are listed explicitly — PCRE's \s does not match them in
 * UTF-8 mode, and pasted cells are full of them.
 */
function md_normalise(string $v): string
{
    $v = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $v);
    $v = preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $v);
    $v = trim($v, " \t\n\r\0\x0B\"'");
    return mb_strtolower($v, 'UTF-8');
}

/**
 * The uniqueness key for one asset: normalised serial + make + model, joined with
 * a unit separator so the fields cannot bleed into each other. Compared byte-exact
 * (`utf8mb4_bin`) so the database agrees with md_normalise() — under the default
 * _unicode_ci collation "Café" and "Cafe" would compare equal in the index while
 * this function keeps them distinct.
 */
function md_identity_key(string $serial, string $make, string $model): string
{
    return md_normalise($serial) . "\x1F" . md_normalise($make) . "\x1F" . md_normalise($model);
}

/** Collapse a submitted field to a storable string (control chars out, capped). */
function md_field($v, int $max = 255): string
{
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)($v ?? ''));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    $s = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max);
    }
    return $s;
}

/**
 * Validate the parts common to a device and to a standalone medium.
 * Returns ['errors' => [...], 'clean' => [...]] — errors are operator-facing.
 */
function md_check_common(array $row): array
{
    $errors = [];
    $clean  = [];

    $serial = md_field($row['serial'] ?? '', 128);
    $make   = md_field($row['make'] ?? '', 128);
    $model  = md_field($row['model'] ?? '', 128);

    if ($serial === '') {
        $errors[] = 'A serial is required — use the device serial, the IMEI, or an assigned asset tag.';
    } elseif (device_key_placeholder($serial)) {
        $errors[] = 'The serial "' . $serial . '" is a placeholder, not an identifier — assign an asset tag.';
    }

    if ($make === '') {
        $errors[] = 'A make/manufacturer is required (it is part of how the asset is identified).';
    }
    if ($model === '') {
        $errors[] = 'A model is required (it is part of how the asset is identified).';
    }

    $reason = strtolower(md_field($row['reason'] ?? '', 32));
    if (!array_key_exists($reason, md_reasons())) {
        $errors[] = 'A reason is required — why was this not sanitised by the appliance?';
    }

    $method = strtolower(md_field($row['destroy_method'] ?? '', 32));
    if (!array_key_exists($method, md_methods())) {
        $errors[] = 'A method is required — how was the unit dealt with?';
    }

    $date = md_field($row['destroy_date'] ?? '', 10);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'The date must be YYYY-MM-DD.';
    }

    $clean['serial']           = $serial;
    $clean['make']             = $make;
    $clean['model']            = $model;
    $clean['reason']           = $reason;
    $clean['destroy_method']   = $method;
    $clean['destroy_date']     = $date;
    $clean['destroy_vendor']   = md_field($row['destroy_vendor'] ?? '');
    $clean['destroy_witness']  = md_field($row['destroy_witness'] ?? '');
    $clean['evidence_ref']     = md_field($row['evidence_ref'] ?? '');
    $clean['evidence_sha256']  = preg_match('/^[0-9a-f]{64}$/i', md_field($row['evidence_sha256'] ?? '', 64))
        ? strtolower(md_field($row['evidence_sha256'], 64))
        : '';
    $clean['identity_key']     = md_identity_key($serial, $make, $model);

    return ['errors' => $errors, 'clean' => $clean];
}

/** Validate a manually recorded device (unit). */
function md_validate_device(array $row): array
{
    $r = md_check_common($row);

    $cat = strtolower(md_field($row['category'] ?? '', 32));
    if (!array_key_exists($cat, md_categories())) {
        $r['errors'][] = 'A category is required.';
        $cat = '';
    }
    $r['clean']['category'] = $cat;

    return $r;
}

/**
 * Validate a manually recorded medium. `type` is what the drive annex shows in its
 * Type column (e.g. "eMMC/UFS (integrated)" for a phone's soldered storage).
 */
function md_validate_media(array $row): array
{
    $r = md_check_common($row);

    $type = md_field($row['type'] ?? '', 64);
    if ($type === '') {
        $r['errors'][] = 'A media type is required (e.g. SSD, HDD, eMMC/UFS (integrated)).';
    }
    $r['clean']['type'] = $type;
    $r['clean']['size'] = md_field($row['size'] ?? '', 32);
    $r['clean']['bus']  = md_field($row['bus'] ?? '', 32);

    return $r;
}

/** One-line description of an attested device, as it will read on the certificate. */
function md_device_line(array $dev): string
{
    $cat   = md_label(md_categories(), (string)($dev['category'] ?? ''));
    $make  = trim((string)($dev['make'] ?? ''));
    $model = trim((string)($dev['model'] ?? ''));
    $what  = trim($make . ' ' . $model);

    $line = $cat;
    if ($what !== '') {
        $line .= ($line !== '' ? ' — ' : '') . $what;
    }
    $serial = trim((string)($dev['serial'] ?? ''));
    if ($serial !== '') {
        $line .= ' (' . $serial . ')';
    }
    return $line;
}

/**
 * How the outcome reads on the certificate, e.g. "Shredded (Acme Shredding,
 * 2026-10-13)" or, for a factory reset, "Factory reset (2026-10-13)".
 *
 * A reset carries no vendor: the mechanism IS the method, and printing a shredding
 * vendor against a device the operator kept would invent a contractor who was never
 * there. The date is still shown, because "when" is the auditable part.
 */
function md_attested_method_line(array $row): string
{
    $method = (string)($row['destroy_method'] ?? '');
    $line   = md_label(md_methods(), $method);
    $bits   = [];
    if (md_method_disposition($method) === 'destroyed' && !empty($row['destroy_vendor'])) {
        $bits[] = (string)$row['destroy_vendor'];
    }
    if (!empty($row['destroy_date'])) { $bits[] = (string)$row['destroy_date']; }
    if ($bits) {
        $line .= ' (' . implode(', ', $bits) . ')';
    }
    return $line;
}

/**
 * The item line on the certificate, e.g. "Destroyed — Shredded (Acme Shredding,
 * 2026-10-13)" or "Factory reset — device retained (2026-10-13)".
 *
 * The verb is chosen from the method, never passed in by the caller: that is the only
 * way a retained device can be sure of never being described as destroyed.
 */
function md_attested_line(array $row): string
{
    if (md_method_disposition((string)($row['destroy_method'] ?? '')) === 'retained') {
        return md_attested_method_line($row) . ' — device retained';
    }
    return 'Destroyed — ' . md_attested_method_line($row);
}

/**
 * One medium as the certificate lists it beneath its host unit, e.g.
 * "• SSD · 870 EVO · 500 GB — S1234".
 *
 * The outcome is appended ONLY when it differs from the host's, so a unit dealt with as a
 * whole reads exactly as it always did and only the exception is called out — the same rule
 * the dashboard's detail view already applies. Without it the detail contradicted the
 * summary: the banner counted "1 MEDIA FACTORY RESET (ATTESTED, RETAINED)" while the bullet
 * sat silently under a line reading "Destroyed", which is the class of false claim this
 * module exists to stop.
 *
 * The test is against the HOST's method, not "is a method set at all": the storage layer
 * fills a blank medium method from its host, so an inherited value is indistinguishable
 * from a deliberately repeated one. Only a genuine difference is worth printing.
 */
function md_attested_media_line(array $m, array $dev = []): string
{
    $bits = array_filter([
        trim((string)($m['type'] ?? '')),
        trim((string)($m['model'] ?? '')),
        trim((string)($m['size'] ?? '')),
        trim((string)($m['bus'] ?? '')),
    ], static fn($v) => $v !== '');

    $line   = '• ' . implode(' · ', $bits);
    $serial = trim((string)($m['serial'] ?? ''));
    if ($serial !== '') { $line .= ' — ' . $serial; }

    $own  = trim((string)($m['destroy_method'] ?? ''));
    $host = trim((string)($dev['destroy_method'] ?? ''));
    // A host is required for anything to differ from: with none, the plain bullet is the
    // honest answer, and a standalone medium is printed through md_attested_line() anyway.
    if ($host !== '' && $own !== '' && $own !== $host) {
        $line .= md_method_disposition($own) === 'retained'
            ? ' · retained — ' . md_attested_method_line($m)
            : ' · destroyed — ' . md_attested_method_line($m);
    }

    return $line;
}
