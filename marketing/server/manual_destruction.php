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
        'mobile_phone' => 'Mobile phone',
        'tablet'       => 'Tablet',
        'laptop'       => 'Laptop',
        'desktop'      => 'Desktop',
        'server'       => 'Server',
        'sealed_unit'  => 'Sealed unit',
        'embedded'     => 'Embedded board',
        'other'        => 'Other',
    ];
}

/**
 * Why it was destroyed rather than sanitised. Required, because "why Destroy and
 * not sanitise" is the first question an auditor asks about an attested outcome.
 */
function md_reasons(): array
{
    return [
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
 * How it was destroyed. Note there is no "factory reset" here and there never
 * should be: it is not a destroy method and it cannot be verified.
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
        'other'                    => 'Other',
    ];
}

/** The marker that must appear wherever an attested row is printed. */
function md_attested_qualifier(): string
{
    return 'operator-attested — not instrument-verified';
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
        $errors[] = 'A reason is required — why was this destroyed rather than sanitised?';
    }

    $method = strtolower(md_field($row['destroy_method'] ?? '', 32));
    if (!array_key_exists($method, md_methods())) {
        $errors[] = 'A destruction method is required.';
    }

    $date = md_field($row['destroy_date'] ?? '', 10);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'The destruction date must be YYYY-MM-DD.';
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

/** How the destruction reads on the certificate, e.g. "Shredded (vendor, 2026-10-13)". */
function md_destruction_line(array $dev): string
{
    $line = md_label(md_methods(), (string)($dev['destroy_method'] ?? ''));
    $bits = [];
    if (!empty($dev['destroy_vendor']))  { $bits[] = (string)$dev['destroy_vendor']; }
    if (!empty($dev['destroy_date']))    { $bits[] = (string)$dev['destroy_date']; }
    if ($bits) {
        $line .= ' (' . implode(', ', $bits) . ')';
    }
    return $line;
}
