<?php
/**
 * Shared certificate vocabulary — "how the data was destroyed".
 *
 * Used by BOTH the printable certificate (render_cert.php) and the
 * machine-readable twin (jsonld.php), so the two can never drift apart. Kept
 * deliberately free of TCPDF: jsonld.php must not drag the PDF library into
 * /verify?format=jsonld.
 *
 * Vocabulary is NIST SP 800-88 Rev 1 (Clear / Purge / Destruction). We do NOT
 * claim IEEE 2883 here — see research/ieee-2883-2022/decisions.md (D1).
 * Plan: research/cert-destruction-evidence/README.md
 */

/** NIST SP 800-88 level reached by one drive: Clear | Purge | Destruction | ''. */
function cert_nist_class(string $cls, string $status): string
{
    $s = strtoupper(trim($status));
    if ($s === 'DESTROYED') return 'Destruction';
    if ($s !== 'COMPLETED') return '';              // not destroyed — no level claim
    $c = strtoupper(trim($cls));
    if (strpos($c, 'PURGE') !== false)       return 'Purge';
    if (strpos($c, 'DESTRUCTION') !== false) return 'Destruction';
    if (strpos($c, 'CLEAR') !== false)       return 'Clear';
    if (strpos($c, 'SANITISATION') !== false) return 'Purge';
    return 'Clear';
}

/**
 * Plain-English "how" for one drive — the technique family, stated alongside
 * the method so the certificate carries a technique as well as a standard
 * (the field NIST 800-88 §4.6 requires and most certificates omit).
 */
function cert_technique_label(string $method, string $cls, string $status = ''): string
{
    $s = strtoupper(trim($status));
    if ($s === 'DESTROYED') return 'Operator-confirmed physical destruction';
    if ($s === 'FAILED')    return 'No destruction — erasure failed';
    if ($s === 'BLOCKED')   return 'No destruction — blocked by firmware (Block SID)';
    if ($s === 'FROZEN')    return 'No destruction — frozen media requires physical destruction';
    if ($s === 'UNKNOWN')   return 'No destruction — outcome unknown';
    if ($s === 'DRY-RUN')   return 'No destruction — dry run';

    $m = strtoupper(trim($method));
    $c = strtoupper(trim($cls));
    if ($m !== '') {
        if (strpos($m, 'CRYPTO') !== false)        return 'Cryptographic erase (media encryption key destroyed)';
        if (strpos($m, 'BLOCK PURGE') !== false)   return 'Block erase (internal media blocks erased)';
        if (strpos($m, 'OVERWRITE') !== false)     return 'Overwrite (every addressable location rewritten)';
        if (strpos($m, 'ENHANCED ERASE') !== false) return 'ATA Enhanced Secure Erase (internal block erase)';
        if (strpos($m, 'SECURE ERASE') !== false || strpos($m, 'SECURITY ERASE') !== false) {
            return 'ATA Secure Erase (internal block erase)';
        }
        if (strpos($m, 'FORMAT') !== false)        return 'NVMe controller-level format (Clear)';
        if (strpos($m, 'NWIPE') !== false)         return 'SCSI software overwrite (Clear)';
        if (strpos($m, 'FACTORY RESET') !== false) return 'Manufacturer factory reset (Clear)';
        if (strpos($m, 'SANITIZE') !== false)      return 'SCSI sanitise overwrite (Purge)';
        if (strpos($m, 'PHYSICAL DESTR') !== false) return 'Operator-confirmed physical destruction';
    }
    $clsMap = [
        'PURGE'        => 'Controller-level purge (addressable + non-addressable locations)',
        'CLEAR'        => 'Write / erase in place (Clear)',
        'SANITISATION' => 'Sanitisation (Purge)',
        'DESTRUCTION'  => 'Operator-confirmed physical destruction',
    ];
    if ($c !== '' && isset($clsMap[$c])) return $clsMap[$c];
    return $m !== '' ? $method : '—';
}

/**
 * Group DESTROYED/COMPLETED drives by their raw method so the certificate can
 * print one line per method used. Keyed on the RAW method/cls/status (never on
 * the pretty label — this file cannot call method_label(), which lives in the
 * TCPDF renderer); the caller maps each row through method_label().
 *
 * Returns rows sorted by device count desc:
 *   [ ['method'=>raw, 'cls'=>raw, 'status'=>raw, 'level'=>NIST, 'technique'=>…, 'n'=>int], … ]
 * Non-completed drives are excluded: this is a "how we destroyed" table, and
 * failures are carried by the outcome banner + Annex A status column.
 */
function cert_method_breakdown(array $drives): array
{
    $groups = [];
    foreach ($drives as $d) {
        $status = strtoupper(trim((string)($d['status'] ?? '')));
        if ($status !== 'COMPLETED' && $status !== 'DESTROYED') continue;
        $method = (string)($d['method'] ?? '');
        $cls    = (string)($d['cls'] ?? '');
        $key    = strtoupper($method) . '|' . strtoupper($cls) . '|' . $status;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'method'    => $method,
                'cls'       => $cls,
                'status'    => $status,
                'level'     => cert_nist_class($cls, $status),
                'technique' => cert_technique_label($method, $cls, $status),
                'n'         => 0,
            ];
        }
        $groups[$key]['n']++;
    }
    $rows = array_values($groups);
    usort($rows, function ($a, $b) {
        if ($a['n'] !== $b['n']) return $b['n'] - $a['n'];
        return strcmp((string)$a['method'], (string)$b['method']);
    });
    return $rows;
}

/**
 * One-line media disposition — the honest counterweight to the "Destruction"
 * title: it says whether the hardware still exists.
 */
function cert_media_disposition(array $drives): string
{
    $sanitised = 0;   // COMPLETED — data destroyed in place, hardware reusable
    $destroyed = 0;   // DESTROYED — media physically destroyed
    foreach ($drives as $d) {
        $s = strtoupper(trim((string)($d['status'] ?? '')));
        if ($s === 'COMPLETED') $sanitised++;
        elseif ($s === 'DESTROYED') $destroyed++;
    }
    $parts = [];
    if ($sanitised > 0) {
        $parts[] = $sanitised . ' media sanitised in place (data destroyed; hardware reusable)';
    }
    if ($destroyed > 0) {
        $parts[] = $destroyed . ' media physically destroyed';
    }
    return $parts !== [] ? implode('; ', $parts) : '';
}
