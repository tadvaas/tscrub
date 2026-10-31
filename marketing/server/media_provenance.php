<?php
declare(strict_types=1);

/**
 * Asset → medium provenance — the missing link between what a customer hands
 * over and the evidence that its data was destroyed.
 *
 * A customer tracks ASSETS by their OEM system serial. The appliance records
 * MEDIA by drive serial, against the HOST that was running the erase. When the
 * drive is pulled and erased on a bench machine, host ≠ asset and every surface
 * keyed on the host is wrong.
 *
 * This module builds the three-way relation from evidence that already exists:
 *
 *   observed_intake  the medium's serial appears in the asset's boot snapshot
 *                    (the diagnostics report's drive inventory)
 *   observed_failed  the medium appears in a FAILED/BLOCKED/FROZEN erasure row
 *                    whose SystemSerial is the asset
 *   observed_host    the medium appears in an erasure row under that host
 *                    (covers a normal in-place erase with no boot snapshot)
 *   declared         an operator linked them in the dashboard; always labelled
 *
 * Pure: no DB, no TCPDF, no network. The caller supplies the report groups, the
 * diagnostics snapshots, the declared links and the set of processing hosts.
 * Plan: research/media-provenance/README.md
 *
 * GUARDRAILS (do not break):
 *  - provenance is never instrument-verified unless the appliance observed it;
 *  - an asset is never "sanitised" when only its media was;
 *  - no link, no host: an unattributable medium stays standalone;
 *  - ambiguous serials refuse the link rather than guess;
 *  - a processing host (bench) is evidence, never an asset.
 */

require_once __DIR__ . '/grading.php';   // device_key(), device_key_placeholder()

/** Matching key for a serial. Display always uses the original casing. */
function mp_key(string $v): string
{
    return strtolower(trim($v));
}

/** A serial we are willing to build a custody link on. */
function mp_serial_usable(string $v): bool
{
    $t = trim($v);
    return $t !== '' && !device_key_placeholder($t) && strcasecmp($t, 'N/A') !== 0;
}

/** Human wording for a provenance class. Never says "verified". */
function mp_provenance_label(string $p): string
{
    switch ($p) {
        case 'observed_intake': return 'observed in the boot snapshot';
        case 'observed_failed': return 'erase failed on this asset';
        case 'observed_host':   return 'recorded in a report on this asset';
        case 'declared':        return 'declared by operator';
        case 'conflict':        return 'origin ambiguous between assets';
    }
    return '';
}

/** Short class for a per-drive column / JSON-LD. */
function mp_provenance_short(string $p): string
{
    switch ($p) {
        case 'observed_intake': return 'intake';
        case 'observed_failed': return 'failed here';
        case 'observed_host':   return 'report';
        case 'declared':        return 'declared';
    }
    return '';
}

/** Every asset disposition the certificate may state. */
function mp_disposition_defs(): array
{
    return [
        'sanitised_in_place'      => 'sanitised in place',
        'media_removed_sanitised' => 'media removed; sanitised separately',
        'no_data_bearing_media'   => 'no data-bearing media at intake',
        'media_destroyed'         => 'media physically destroyed',
        'incomplete'              => 'not sanitised',
    ];
}

/** True when a medium's evidence was produced on a host other than its asset. */
function mp_is_removed(string $disposition): bool
{
    return $disposition === 'media_removed_sanitised';
}

/**
 * Preference order. A declaration is the weakest claim: any observation made on
 * a *non-processing* host outranks it, because that host really did have the
 * medium. An observation on a processing host is filtered out by the caller
 * before this module sees it, which is what lets a declaration supply the
 * origin for a bench erase.
 */
function mp_strength(string $provenance): int
{
    switch ($provenance) {
        case 'observed_intake': return 3;
        case 'observed_failed': return 2;
        case 'observed_host':   return 1;
        case 'declared':        return 0;
    }
    return 0;
}

/** @internal Merge one observed/declared link into the media map. */
function mp_link_merge(array &$media, string $mserial, string $assetKey, string $provenance, array &$conflicts, string $failedStatus = ''): void
{
    $s = mp_strength($provenance);
    if (!isset($media[$mserial])) {
        $media[$mserial] = ['asset_key' => $assetKey, 'provenance' => $provenance, 'strength' => $s, 'conflicts' => [], 'failed_status' => $failedStatus];
        return;
    }
    $cur = &$media[$mserial];
    if ($cur['asset_key'] === $assetKey) {
        if ($s > $cur['strength']) {
            $cur['strength'] = $s;
        }
        // Keep the most INFORMATIVE provenance for display. A boot snapshot and a
        // failed attempt are both observations of the same asset, but only the
        // failure explains why the medium left it.
        $rank = ['declared' => 0, 'observed_host' => 1, 'observed_intake' => 2, 'observed_failed' => 3];
        if (($rank[$provenance] ?? 0) > ($rank[$cur['provenance']] ?? 0)) {
            $cur['provenance'] = $provenance;
        }
        if ($provenance === 'observed_failed' && $failedStatus !== '') {
            $cur['failed_status'] = $failedStatus;
        }
        return;
    }
    if ($s > $cur['strength']) {
        // A stronger observation replaces the weaker claim outright.
        $cur['asset_key'] = $assetKey;
        $cur['provenance'] = $provenance;
        $cur['strength'] = $s;
        $cur['failed_status'] = $failedStatus;
        return;
    }
    if ($s === $cur['strength']) {
        // Same class, two different assets: genuinely ambiguous. Keep the first
        // and record both so the certificate can say so instead of picking.
        $cur['conflicts'] = array_values(array_unique(array_merge($cur['conflicts'], [$cur['asset_key'], $assetKey])));
        $conflicts[$mserial] = $cur['conflicts'];
    }
    // Weaker claim against a stronger observation: ignored. The API refuses such
    // a declaration, so this is only reachable for legacy rows.
}

/**
 * Build the asset → medium relation.
 *
 * @param array $payloads        erasure report groups (each with `drives[]`, `sysserial`, `system`)
 * @param array $diagnostics     boot snapshots (each with `drives[]`, `sysserial`, `system`, `asset_tag`)
 * @param array $links           declared links (`asset_serial`, `media_serial`, `reason`, `note`)
 * @param array $processingHosts set of lowercased device keys that are OUR bench hosts
 * @return array{assets: array, media: array, conflicts: array}
 */
function mp_collect(array $payloads, array $diagnostics, array $links, array $processingHosts = []): array
{
    $assets = [];
    $media = [];
    $conflicts = [];

    $init = function (string $key, string $serial, string $origin = 'declared') use (&$assets): void {
        if (isset($assets[$key])) {
            $rank = ['declared' => 0, 'host' => 1, 'intake' => 2];
            if (($rank[$origin] ?? 0) > ($rank[$assets[$key]['origin'] ?? 'declared'] ?? 0)) {
                $assets[$key]['origin'] = $origin;
            }
            return;
        }
        $assets[$key] = [
            'asset_key'    => $key,
            'asset_serial' => trim($serial),
            'origin'       => $origin,
            'system_name'  => '',
            'model'        => '',
            'asset_tag'    => '',
            'serial_source' => 'dmi',
            'intake_ref'   => '',
            'intake_media' => 0,
        ];
    };

    // 1. Boot snapshots: the asset, its metadata, and every medium it could see.
    foreach ($diagnostics as $d) {
        $serial = (string)($d['sysserial'] ?? $d['serial'] ?? '');
        if (!mp_serial_usable($serial)) continue;
        $key = mp_key(device_key(is_array($d) ? $d : []));
        if ($key === '') continue;
        $init($key, $serial, 'intake');
        if ($assets[$key]['system_name'] === '') {
            $assets[$key]['system_name'] = (string)($d['system'] ?? '');
        }
        if ($assets[$key]['asset_tag'] === '') {
            $assets[$key]['asset_tag'] = (string)($d['asset_tag'] ?? '');
        }
        $src = (string)($d['serial_source'] ?? '');
        if ($src !== '') $assets[$key]['serial_source'] = $src;
        if ($assets[$key]['intake_ref'] === '') {
            $assets[$key]['intake_ref'] = (string)($d['uploaded_at'] ?? $d['first'] ?? '');
        }
        $n = 0;
        foreach (($d['drives'] ?? []) as $dv) {
            if (!is_array($dv)) continue;
            $ms = mp_key((string)($dv['serial'] ?? ''));
            if (!mp_serial_usable($ms)) continue;
            $n++;
            mp_link_merge($media, $ms, $key, 'observed_intake', $conflicts);
        }
        // Keep the largest inventory, not the last: an early snapshot may have
        // seen a drive that a later one did not (pulled before the second boot).
        $assets[$key]['intake_media'] = max($assets[$key]['intake_media'], $n);
    }

    // 2. Erasure rows. A non-processing host is an asset in its own right: it
    // really did contain these media, whether the erase succeeded or not.
    foreach ($payloads as $g) {
        if (!is_array($g)) continue;
        $hostSerial = (string)($g['sysserial'] ?? $g['sysSerial'] ?? '');
        $hostKey = mp_key(device_key($g));
        $isProcessing = $hostKey !== '' && isset($processingHosts[$hostKey]);
        if ($hostKey !== '' && mp_serial_usable($hostSerial) && !$isProcessing) {
            $init($hostKey, $hostSerial, 'host');
            if ($assets[$hostKey]['system_name'] === '') {
                $assets[$hostKey]['system_name'] = (string)($g['system'] ?? '');
            }
            if ($assets[$hostKey]['asset_tag'] === '') {
                $assets[$hostKey]['asset_tag'] = (string)($g['asset_tag'] ?? '');
            }
        }
        foreach (($g['drives'] ?? []) as $dv) {
            if (!is_array($dv)) continue;
            $ms = mp_key((string)($dv['serial'] ?? ''));
            if (!mp_serial_usable($ms)) continue;
            if ($isProcessing || $hostKey === '') continue;   // evidence only, no origin claim
            $status = strtoupper(trim((string)($dv['status'] ?? '')));
            $prov = ($status === 'COMPLETED' || $status === 'DESTROYED') ? 'observed_host' : 'observed_failed';
            mp_link_merge($media, $ms, $hostKey, $prov, $conflicts, $prov === 'observed_failed' ? $status : '');
        }
    }

    // 3. Declared links. They fill a missing origin or agree with an observed
    // one; they never override an observation on a non-processing host.
    foreach ($links as $l) {
        if (!is_array($l)) continue;
        $assetSerial = (string)($l['asset_serial'] ?? '');
        $mserial = (string)($l['media_serial'] ?? '');
        if (!mp_serial_usable($assetSerial) || !mp_serial_usable($mserial)) continue;
        $key = mp_key($assetSerial);
        $ms = mp_key($mserial);
        $init($key, $assetSerial, 'declared');
        mp_link_merge($media, $ms, $key, 'declared', $conflicts);
    }

    // A conflicted medium is attributed to nobody. Keeping the first claimant
    // would be a guess, which is exactly what this module exists to avoid. The
    // claimants keep a note of it so neither asset is later reported as having
    // had no media at all.
    foreach ($conflicts as $ms => $keys) {
        foreach ($keys as $ck) {
            if (isset($assets[$ck])) {
                $assets[$ck]['conflict_media'][] = $ms;
            }
        }
        unset($media[$ms]);
    }

    return ['assets' => $assets, 'media' => $media, 'conflicts' => $conflicts];
}

/**
 * Attach the recorded evidence to each asset and decide its disposition.
 *
 * @param array $collected output of mp_collect()
 * @param array $drives    the CONSOLIDATED drive rows (best status per serial) that
 *                         the certificate will print — already deduped, so one
 *                         medium yields one evidence row
 * @return array{assets: array, drives: array, summary: array, conflicts: array}
 */
function mp_apply_evidence(array $collected, array $drives): array
{
    $bySerial = [];
    foreach ($drives as $d) {
        if (!is_array($d)) continue;
        $ms = mp_key((string)($d['serial'] ?? ''));
        if ($ms !== '') $bySerial[$ms] = $d;
    }

    $assets = $collected['assets'];
    $media  = $collected['media'];
    $conflicts = $collected['conflicts'] ?? [];
    $annotations = [];
    $summary = [
        'assets'    => 0,
        'in_place'  => 0,
        'removed'   => 0,
        'destroyed' => 0,
        'no_media'  => 0,
        'incomplete'=> 0,
        'media_removed' => 0,
        'media_in_place' => 0,
    ];

    // Group media under their resolved asset.
    foreach ($media as $ms => $link) {
        $key = (string)$link['asset_key'];
        if (!isset($assets[$key])) continue;
        $assets[$key]['media'][$ms] = [
            'serial'      => $ms,
            'provenance'  => (string)$link['provenance'],
            'strength'    => (int)$link['strength'],
            'failed_status' => (string)($link['failed_status'] ?? ''),
        ];
    }
    // An ambiguous medium is listed under each claimant as an unresolved origin.
    foreach ($assets as $key => $a) {
        foreach (($a['conflict_media'] ?? []) as $ms) {
            if (!isset($assets[$key]['media'][$ms])) {
                $assets[$key]['media'][$ms] = [
                    'serial'     => $ms,
                    'provenance' => 'conflict',
                    'strength'   => 0,
                    'conflict'   => true,
                ];
            }
        }
    }

    // A declaration-only asset whose only medium was reassigned to a stronger
    // observation is not an asset with "no data-bearing media" — it is an
    // orphan claim. Drop it rather than print a false absence.
    foreach ($assets as $key => $a) {
        if (($a['media'] ?? []) === []
            && ($a['origin'] ?? '') === 'declared'
            && (string)($a['intake_ref'] ?? '') === '') {
            unset($assets[$key]);
        }
    }

    foreach ($assets as $key => &$a) {
        $a['media'] = $a['media'] ?? [];
        $inPlace = 0;
        $removed = 0;
        $destroyed = 0;
        $incomplete = 0;

        foreach ($a['media'] as $ms => &$line) {
            $dv = $bySerial[$ms] ?? null;
            $status = $dv !== null ? strtoupper(trim((string)($dv['status'] ?? ''))) : '';
            $hostSerial = $dv !== null ? (string)($dv['sysserial'] ?? '') : '';
            $hostKey = mp_key($hostSerial);

            $line['model'] = $dv !== null ? (string)($dv['model'] ?? '') : '';
            $line['bus'] = $dv !== null ? (string)($dv['bus'] ?? '') : '';
            $line['type'] = $dv !== null ? (string)($dv['type'] ?? '') : '';
            $line['size'] = $dv !== null ? (string)($dv['size'] ?? '') : '';
            $line['status'] = $status;
            $line['method'] = $dv !== null ? (string)($dv['method'] ?? '') : '';
            $line['cls'] = $dv !== null ? (string)($dv['cls'] ?? '') : '';
            $line['host_serial'] = $hostSerial;
            $line['host_name'] = $dv !== null ? (string)($dv['system'] ?? '') : '';
            // Keep the failure observed on the asset; do not overwrite it with the
            // merged row's (successful) status from the bench.
            $line['failed_status'] = (string)($line['failed_status'] ?? '');

            if (!empty($line['conflict'])) {
                // Two non-processing assets both claim this medium. Do not pick
                // one, and do not annotate the drive: the origin is unresolved.
                $line['disposition'] = 'incomplete';
                ++$incomplete;
                continue;
            }

            if ($dv === null) {
                $line['disposition'] = 'incomplete';
                ++$incomplete;
            } elseif ($status === 'COMPLETED' && $hostKey === $key) {
                $line['disposition'] = 'sanitised_in_place';
                // The successful row supersedes the earlier failure as the
                // provenance statement for this medium; both remain in the
                // custody trail via failed_status.
                if ($line['provenance'] === 'observed_failed') {
                    $line['provenance'] = 'observed_host';
                }
                ++$inPlace;
            } elseif ($status === 'COMPLETED') {
                $line['disposition'] = 'media_removed_sanitised';
                ++$removed;
            } elseif ($status === 'DESTROYED') {
                $line['disposition'] = 'media_destroyed';
                ++$destroyed;
            } else {
                $line['disposition'] = 'incomplete';
                $line['failed_status'] = $status;
                ++$incomplete;
            }

            // The failed attempt on the asset is part of the custody trail, even
            // when a different row carries the successful erase.
            if ($line['provenance'] === 'observed_failed' && $line['failed_status'] === ''
                && $status !== '' && $status !== 'COMPLETED' && $status !== 'DESTROYED') {
                $line['failed_status'] = $status;
            }

            // Annotate the drive row only when the origin is NOT its host: an
            // in-place row needs no second identity, and leaving it empty keeps
            // every ordinary certificate byte-identical.
            if ($hostKey !== '' && $hostKey !== $key) {
                $annotations[$ms] = [
                    'asset_serial'      => (string)$a['asset_serial'],
                    'origin_provenance' => (string)$line['provenance'],
                    'provenance_json'   => json_encode([
                        'asset_serial'  => (string)$a['asset_serial'],
                        'provenance'    => (string)$line['provenance'],
                        'host_serial'   => $hostSerial,
                        'status'        => $status,
                        'failed_status' => (string)$line['failed_status'],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }
        unset($line);

        $count = count($a['media']);
        if ($count === 0) {
            $a['disposition'] = 'no_data_bearing_media';
            ++$summary['no_media'];
        } else {
            $a['disposition'] = mp_asset_rollup($a);
            switch ($a['disposition']) {
                case 'incomplete':                 ++$summary['incomplete']; break;
                case 'media_removed_sanitised':    ++$summary['removed']; break;
                case 'media_destroyed':            ++$summary['destroyed']; break;
                default:                           ++$summary['in_place'];
            }
        }
        $summary['media_in_place'] += $inPlace;
        $summary['media_removed']  += $removed;
        ++$summary['assets'];
    }
    unset($a);

    return [
        'assets'      => array_values($assets),
        'drives'      => $annotations,
        'summary'     => $summary,
        'conflicts'   => $conflicts,
    ];
}

/**
 * Should the certificate print an asset-accounting section at all?
 *
 * Only when it says something the per-drive table does not: media that left the
 * asset, an asset with no data-bearing media, an unresolved origin, or an asset
 * that did not complete. An ordinary in-place job renders exactly as before,
 * which is the compatibility rule for every certificate shape already issued.
 */
function mp_asset_accounting_visible(array $assets, array $conflicts = []): bool
{
    if ($conflicts !== []) return true;
    foreach ($assets as $a) {
        if (($a['disposition'] ?? '') !== 'sanitised_in_place') return true;
    }
    return false;
}

/** The asset rows the certificate prints: everything except the ordinary case. */
function mp_asset_block_rows(array $assets): array
{
    $out = [];
    foreach ($assets as $a) {
        if (($a['disposition'] ?? '') === 'sanitised_in_place') continue;
        $out[] = $a;
    }
    return $out;
}

/** Roll up an asset's media into its single disposition. */
function mp_asset_rollup(array $a): string
{
    $media = $a['media'] ?? [];
    if ($media === []) return 'no_data_bearing_media';
    $inPlace = $removed = $destroyed = $incomplete = 0;
    foreach ($media as $m) {
        switch ((string)($m['disposition'] ?? '')) {
            case 'sanitised_in_place':      ++$inPlace; break;
            case 'media_removed_sanitised': ++$removed; break;
            case 'media_destroyed':         ++$destroyed; break;
            default:                        ++$incomplete;
        }
    }
    if ($incomplete > 0) return 'incomplete';
    if ($removed > 0) return 'media_removed_sanitised';
    if ($destroyed > 0 && $destroyed === count($media)) return 'media_destroyed';
    return 'sanitised_in_place';
}

/**
 * Reconcile an asset block with the FINAL drive statuses.
 *
 * The operator's physical-destruction decision is applied inside the renderer,
 * after provenance has been resolved. Without this the drive row could read
 * DESTROYED while the asset block still said "no completed erasure evidence".
 * Completing a failed drive also upgrades the asset from incomplete.
 */
function mp_reconcile_assets(array $assets, array $drives): array
{
    $bySerial = [];
    foreach ($drives as $d) {
        if (!is_array($d)) continue;
        $ms = mp_key((string)($d['serial'] ?? ''));
        if ($ms !== '') $bySerial[$ms] = $d;
    }
    foreach ($assets as &$a) {
        if (!isset($a['media']) || !is_array($a['media'])) {
            $a['media'] = [];
        }
        // NOTE: iterate the property directly. `foreach (($a['media'] ?? []) as &$m)`
        // iterates a TEMPORARY expression, so every write is lost — the disposition
        // then silently stays as it was resolved before the decision.
        foreach ($a['media'] as &$m) {
            $ms = mp_key((string)($m['serial'] ?? ''));
            $dv = $bySerial[$ms] ?? null;
            if ($dv === null || !empty($m['conflict'])) continue;
            $status = strtoupper(trim((string)($dv['status'] ?? '')));
            if ($status === 'DESTROYED') {
                $m['disposition'] = 'media_destroyed';
                $m['failed_status'] = '';
            } elseif ($status === 'COMPLETED' && (string)($m['disposition'] ?? '') === 'incomplete') {
                $hostKey = mp_key((string)($dv['sysserial'] ?? ''));
                $m['host_serial'] = (string)($dv['sysserial'] ?? '');
                $m['disposition'] = ($hostKey !== '' && $hostKey === (string)($a['asset_key'] ?? ''))
                    ? 'sanitised_in_place'
                    : 'media_removed_sanitised';
                $m['failed_status'] = '';
            }
        }
        unset($m);
        $a['disposition'] = mp_asset_rollup($a);
    }
    unset($a);
    return $assets;
}
function mp_asset_line(array $a): string
{
    $bits = [trim((string)($a['asset_serial'] ?? ''))];
    $name = trim((string)($a['system_name'] ?? ''));
    if ($name !== '') $bits[] = $name;
    $defs = mp_disposition_defs();
    $bits[] = $defs[(string)($a['disposition'] ?? '')] ?? (string)($a['disposition'] ?? '');
    return implode(' — ', array_values(array_filter($bits, static fn($v) => trim((string)$v) !== '')));
}

/** The provenance qualifier printed once per asset block. */
function mp_provenance_qualifier(): string
{
    return 'Asset-to-medium provenance is observed by the appliance or declared by the operator; '
        . 'the erasure evidence for each medium is instrument-verified and listed in Annex A.';
}
