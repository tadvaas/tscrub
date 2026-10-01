<?php
declare(strict_types=1);

/**
 * Shared report parsing (used by the /api/reports ingestion).
 * Parses an uploaded `reports[]` multipart (CSV + optional .json manifest +
 * .csv.sig) and consolidates drives by Chain of Custody ID.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';

function run_cmd(array $cmd) {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { return null; }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}

function tmpfile_path($data) {
    $p = tempnam(sys_get_temp_dir(), 'tscrub-cert-');
    file_put_contents($p, $data);
    return $p;
}

function rand_letters(int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= chr(65 + random_int(0, 25));
    }
    return $s;
}

function gen_cert_id() {
    // All segments are CSPRNG-derived so certificate IDs (exposed on the public
    // /verify page) can't be predicted and enumerated.
    $p1 = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $p2 = rand_letters(2);
    $p3 = rand_letters(3);
    $p4 = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $p5 = rand_letters(4);
    return 'COD-' . $p1 . '-' . $p2 . '-' . $p3 . '-' . $p4 . '-' . $p5;
}

/** Truncate a UTF-8 string to a schema-width-safe maximum length. */
function clip_str(string $s, int $max): string {
    return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
}

/**
 * Base64-encoded DER public keys (SPKI) of every paid licence belonging to a
 * user. Reports signed by one of these keys are attributable to that licence.
 */
function licence_pub_keys(int $userId): array {
    $stmt = db()->prepare("SELECT pub_key FROM licences WHERE user_id = ? AND tier <> 'free' AND pub_key <> ''");
    $stmt->execute([$userId]);
    return array_values(array_filter(array_map(fn($r) => (string)$r['pub_key'], $stmt->fetchAll())));
}

/**
 * Lazily add the `report_type` column to an existing `reports` table
 * (idempotent — mirrors schema.sql). A fresh install gets the column from
 * schema.sql; an existing server self-heals on first use.
 */
function reports_ensure_schema(): void {
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'report_type'"
        );
        $stmt->execute();
        if ((int)$stmt->fetchColumn() > 0) {
            return;   // column already present — nothing to do
        }
        db()->exec(
            "ALTER TABLE reports
             ADD COLUMN report_type ENUM('erasure','diagnostics') NOT NULL DEFAULT 'erasure' AFTER source"
        );
    } catch (Throwable $e) {
        // Any real failure is non-fatal: the column is only a typed-report
        // optimisation and ingestion otherwise falls back to erasure.
        error_log('reports ensure schema: ' . $e->getMessage());
    }
}

/**
 * Parse an uploaded reports multipart and group drives by Chain of Custody ID.
 *
 * @param array $files The `$_FILES['reports']` array.
 * @param array $licencePubKeys Base64-encoded DER public keys of the owner's
 *                              paid licences (see licence_pub_keys).
 * @return array $groups[COCID] = [cocid, drives[], reports[], shaState, sigState,
 *                                 system, sysserial, bbserial, first, last]
 */
function parse_reports(array $files, array $licencePubKeys = [], array $ingestedShas = [], ?array &$stats = null): array {
    // 1) Bucket uploads by stem.
    $csvs = [];
    $manifests = [];
    $sigs = [];

    // $stats (by-ref, optional): [uploaded, skipped] report-FILE counts plus a
    // per-file debit list (sha => new drive count) for per-device billing.
    if ($stats === null) {
        $stats = ['uploaded' => 0, 'skipped' => 0, 'debits' => []];
    }

    if (empty($files['name']) || !is_array($files['name'])) {
        fail(400, 'Upload one or more tScrub report files (.csv).');
    }

    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = $files['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) continue;
        if (strlen($name) > 200 || filesize($tmp) > 2_000_000) continue;

        $l = strtolower($name);
        if (substr($l, -4) === '.csv') {
            $csvs[substr($l, 0, -4)] = ['name' => $name, 'tmp' => $tmp];
        } elseif (substr($l, -8) === '.csv.sig') {
            $sigs[substr($l, 0, -8)] = $tmp;
        } elseif (substr($l, -4) === '.sig') {
            $sigs[substr($l, 0, -4)] = $tmp;
        } elseif (substr($l, -5) === '.json') {
            $manifests[substr($l, 0, -5)] = $tmp;
        }
    }

    if (!$csvs) {
        fail(400, 'Upload one or more tScrub report files (.csv).');
    }
    if (count($csvs) > 20) {
        fail(400, 'Too many report files. Upload at most 20 .csv files at once.');
    }

    // 2) Parse each CSV and consolidate drives by Chain of Custody ID.
    $groups = [];
    $seenShas = [];
    $seenSerials = [];

    foreach ($csvs as $stem => $csv) {
        $sha = strtolower(hash_file('sha256', $csv['tmp']));

        // Skip report files already ingested for this account (cross-request
        // dedup — e.g. re-uploading the whole fleet from USBs). Counted skipped.
        if (isset($ingestedShas[$sha])) {
            $stats['skipped']++;
            continue;
        }
        // Skip an identical report file uploaded twice in this same request.
        if (isset($seenShas[$sha])) {
            $stats['skipped']++;
            continue;
        }
        $seenShas[$sha] = true;

        $fh = fopen($csv['tmp'], 'r');
        if (!$fh) continue;
        $header = fgetcsv($fh);
        if ($header === false) { fclose($fh); continue; }

        // Map columns by name so the 12/15/23-column formats are all accepted.
        $map = [];
        foreach ($header as $idx => $col) { $map[strtolower(trim((string)$col))] = $idx; }
        $get = function ($row, $key) use (&$map) {
            $idx = $map[$key] ?? null;
            return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
        };

        $cocid = '';
        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            if (count($rows) >= 10000) break;
            if (!is_array($row) || count($row) < 2) continue;
            if ($get($row, 'model') === '' && $get($row, 'serial') === '') continue;
            if ($cocid === '') $cocid = preg_replace('/[^A-Za-z0-9_-]/', '', $get($row, 'cocid'));
            $rows[] = $row;
        }
        fclose($fh);

        if (!$rows) continue;

        // This file contributes at least one drive row — count it as uploaded
        // and record its drive count for per-device billing (idempotent by SHA).
        $stats['uploaded']++;
        $stats['debits'][] = ['sha' => $sha, 'drives' => count($rows)];

        if ($cocid === '') {
            // No COCID column — recover one from the report filename
            // (tScrub names files tScrub_<cocid>_<ts>.csv; accept any 4-8
            // digit run, not just 5, for legacy/third-party files).
            if (preg_match('/_([0-9]{4,8})_/', $csv['name'], $mm)) {
                $cocid = $mm[1];
            }
        }
        if ($cocid === '') {
            // Still nothing: never collapse unrelated files into one shared
            // bucket. Key off the file's own SHA-256 so each distinct upload
            // stays separate instead of merging into a single "UNKNOWN" job.
            $cocid = 'UNKNOWN-' . substr($sha, 0, 12);
        }
        if (mb_strlen($cocid, 'UTF-8') > 64) $cocid = mb_substr($cocid, 0, 64, 'UTF-8');

        // SHA-256 (computed above) + optional Ed25519 signature verification.
        $manifestData = null;
        if (isset($manifests[$stem])) {
            $decoded = json_decode((string)file_get_contents($manifests[$stem]), true);
            if (is_array($decoded)) $manifestData = $decoded;
        }
        $recorded = $manifestData ? strtolower((string)($manifestData['sha256'] ?? '')) : '';
        $shaState = 'unverified';
        if ($recorded !== '') {
            $shaState = hash_equals($recorded, $sha) ? 'verified' : 'mismatch';
        }

        $sigState = 'none';
        if ($manifestData && !empty($manifestData['signed']) && !empty($manifestData['public_key']) && isset($sigs[$stem])) {
            $pubPath = tmpfile_path(base64_decode((string)$manifestData['public_key']));
            $sigPath = tmpfile_path(base64_decode((string)file_get_contents($sigs[$stem])));
            $r = run_cmd(['openssl', 'pkeyutl', '-verify', '-pubin', '-inkey', $pubPath,
                          '-rawin', '-in', $csv['tmp'], '-sigfile', $sigPath]);
            if ($r && $r[0] === 0) {
                $sigState = 'valid';
                // Attribution: a signature is only 'attributed' when the report
                // key matches the public half of the owner's paid licence.
                if ($licencePubKeys !== []) {
                    $der = run_cmd(['openssl', 'pkey', '-pubin', '-in', $pubPath, '-outform', 'DER']);
                    if ($der && $der[0] === 0 && in_array(base64_encode((string)$der[1]), $licencePubKeys, true)) {
                        $sigState = 'attributed';
                    }
                }
            } else {
                $sigState = 'invalid';
            }
            @unlink($pubPath); @unlink($sigPath);
        }

        if (!isset($groups[$cocid])) {
            $groups[$cocid] = [
                'cocid' => $cocid, 'drives' => [], 'reports' => [],
                'shaState' => 'unverified', 'sigState' => 'none',
                'system' => '', 'sysserial' => '', 'bbserial' => '',
                'cpu' => '', 'gpu' => '', 'ram' => '', 'enrollment' => '',
                'chassisserial' => '', 'chassistype' => '', 'biosversion' => '',
                'biosdate' => '', 'systemuuid' => '', 'bioslock' => '', 'bioslockmethod' => '',
                'sku' => '', 'asset_tag' => '', 'bios_vendor' => '', 'board' => '', 'tpm' => '',
                'macs' => '', 'storage_controllers' => '', 'tool_version' => '',
                'operator' => '', 'validator' => '', 'media_source' => '', 'media_destination' => '',
                'first' => null, 'last' => null,
            ];
        }
        $g = &$groups[$cocid];
        $g['reports'][] = ['name' => $csv['name'], 'sha' => $sha, 'state' => $shaState];

        if ($shaState === 'mismatch') $g['shaState'] = 'mismatch';
        elseif ($shaState === 'verified' && $g['shaState'] !== 'mismatch') $g['shaState'] = 'verified';

        // Merge per-report signature state into the group, strongest wins:
        // invalid > attributed > valid > none.
        if ($sigState === 'invalid') {
            $g['sigState'] = 'invalid';
        } elseif ($sigState === 'attributed') {
            if ($g['sigState'] !== 'invalid') $g['sigState'] = 'attributed';
        } elseif ($sigState === 'valid') {
            if ($g['sigState'] !== 'invalid' && $g['sigState'] !== 'attributed') $g['sigState'] = 'valid';
        }

        $seenSerials[$cocid] = $seenSerials[$cocid] ?? [];
        foreach ($rows as $row) {
            $serial = $get($row, 'serial');
            $ts = $get($row, 'timestamp');
            // MDM (Autopilot) verdict: prefer the CSV column, fall back to the
            // JSON manifest's "mdm" field (older/third-party files have neither).
            $enrollment = $get($row, 'enrollment');
            if ($enrollment === '' && $manifestData && !empty($manifestData['mdm'])) {
                $enrollment = (string)$manifestData['mdm'];
            }
            $drive = [
                'ts' => clip_str($ts, 32),
                'model' => clip_str($get($row, 'model'), 255),
                'serial' => clip_str($get($row, 'serial'), 255),
                'device' => clip_str($get($row, 'device'), 64),
                'type' => clip_str($get($row, 'type'), 64),
                'size' => clip_str($get($row, 'size'), 32),
                'bus' => clip_str($get($row, 'bus'), 32),
                'method' => clip_str($get($row, 'method'), 255),
                'cls' => clip_str($get($row, 'class'), 64),
                'cert' => clip_str($get($row, 'certification'), 64),
                'status' => clip_str($get($row, 'finalstatus'), 64),
                'system' => clip_str($get($row, 'system'), 255),
                'sysserial' => clip_str($get($row, 'systemserial'), 255),
                'bbserial' => clip_str($get($row, 'baseboardserial'), 255),
                'cpu' => clip_str($get($row, 'cpu'), 255),
                'gpu' => clip_str($get($row, 'gpu'), 255),
                'ram' => clip_str($get($row, 'ram'), 64),
                'enrollment' => clip_str($enrollment, 32),
                'smart' => clip_str($get($row, 'smart'), 16),
                'tempc' => clip_str($get($row, 'tempc'), 16),
                'poweronhours' => clip_str($get($row, 'poweronhours'), 32),
                'powercycles' => clip_str($get($row, 'powercycles'), 32),
                'reallocsectors' => clip_str($get($row, 'reallocsectors'), 32),
                'pctused' => clip_str($get($row, 'pctused'), 32),
                'availspare' => clip_str($get($row, 'availspare'), 32),
                'tbw_tb' => clip_str($get($row, 'tbw_tb'), 32),
                'smartpost' => clip_str($get($row, 'smartpost'), 16),
                'tempcpost' => clip_str($get($row, 'tempcpost'), 16),
                'poweronhourspost' => clip_str($get($row, 'poweronhourspost'), 32),
                'start_time' => clip_str($get($row, 'starttime'), 32),
                'end_time' => clip_str($get($row, 'endtime'), 32),
                'duration_secs' => clip_str($get($row, 'durationsecs'), 32),
                'firmware' => clip_str($get($row, 'firmware'), 64),
                'sector_size' => clip_str($get($row, 'sectorsize'), 16),
                'sectors' => clip_str($get($row, 'sectors'), 32),
                'hpa' => clip_str($get($row, 'hpa'), 20),
                'dco' => clip_str($get($row, 'dco'), 20),
                'sed_status' => clip_str($get($row, 'sedstatus'), 20),
                'reallocsectorspost' => clip_str($get($row, 'reallocsectorspost'), 32),
                'selftest' => clip_str($get($row, 'selftest'), 128),
                'sku' => clip_str($get($row, 'sku'), 128),
                'asset_tag' => clip_str($get($row, 'assettag'), 128),
                'bios_vendor' => clip_str($get($row, 'biosvendor'), 64),
                'board' => clip_str($get($row, 'boardmodel'), 128),
                'tpm' => clip_str($get($row, 'tpm'), 32),
                'macs' => clip_str($get($row, 'macaddress'), 255),
                'storage_controllers' => clip_str($get($row, 'storagecontrollers'), 255),
                'tool_version' => clip_str($get($row, 'toolversion'), 32),
                'operator' => clip_str($get($row, 'operator'), 128),
                'validator' => clip_str($get($row, 'validator'), 128),
                'media_source' => clip_str($get($row, 'mediasource'), 128),
                'media_destination' => clip_str($get($row, 'mediadestination'), 128),
            ];
            $dkey = $serial !== '' ? strtolower($serial) : '';
            if ($dkey !== '' && isset($seenSerials[$cocid][$dkey])) {
                // Same physical drive seen again in this upload — keep the best outcome.
                $idx = $seenSerials[$cocid][$dkey];
                if (drive_status_rank($drive['status']) > drive_status_rank($g['drives'][$idx]['status'] ?? '')) {
                    $g['drives'][$idx] = $drive;
                }
            } else {
                if ($dkey !== '') $seenSerials[$cocid][$dkey] = count($g['drives']);
                $g['drives'][] = $drive;
            }
            if ($g['system'] === '' && isset($map['system'])) {
                $g['system'] = clip_str($get($row, 'system'), 255);
                $g['sysserial'] = clip_str($get($row, 'systemserial'), 255);
                $g['bbserial'] = clip_str($get($row, 'baseboardserial'), 255);
            }
            if ($g['enrollment'] === '' && $enrollment !== '') {
                $g['enrollment'] = clip_str($enrollment, 32);
            }
            if ($g['cpu'] === '' && isset($map['cpu'])) {
                $g['cpu'] = clip_str($get($row, 'cpu'), 255);
                $g['gpu'] = clip_str($get($row, 'gpu'), 255);
                $g['ram'] = clip_str($get($row, 'ram'), 64);
            }
            if ($g['systemuuid'] === '' && isset($map['systemuuid'])) {
                $g['systemuuid'] = clip_str($get($row, 'systemuuid'), 64);
                $g['chassisserial'] = clip_str($get($row, 'chassisserial'), 255);
                $g['chassistype'] = clip_str($get($row, 'chassistype'), 64);
                $g['biosversion'] = clip_str($get($row, 'biosversion'), 64);
                $g['biosdate'] = clip_str($get($row, 'biosdate'), 32);
                $g['bioslock'] = clip_str($get($row, 'bioslock'), 20);
                $g['bioslockmethod'] = clip_str($get($row, 'bioslockmethod'), 255);
            }
            if ($g['sku'] === '' && isset($map['sku'])) {
                $g['sku'] = clip_str($get($row, 'sku'), 128);
                $g['asset_tag'] = clip_str($get($row, 'assettag'), 128);
                $g['bios_vendor'] = clip_str($get($row, 'biosvendor'), 64);
                $g['board'] = clip_str($get($row, 'boardmodel'), 128);
                $g['tpm'] = clip_str($get($row, 'tpm'), 32);
                $g['macs'] = clip_str($get($row, 'macaddress'), 255);
                $g['storage_controllers'] = clip_str($get($row, 'storagecontrollers'), 255);
                $g['tool_version'] = clip_str($get($row, 'toolversion'), 32);
                $g['operator'] = clip_str($get($row, 'operator'), 128);
                $g['validator'] = clip_str($get($row, 'validator'), 128);
                $g['media_source'] = clip_str($get($row, 'mediasource'), 128);
                $g['media_destination'] = clip_str($get($row, 'mediadestination'), 128);
            }
            if ($ts !== '') {
                $tsEpoch = strtotime($ts) ?: null;
                if ($tsEpoch !== null) {
                    $firstEpoch = $g['first'] !== null ? strtotime((string)$g['first']) : false;
                    $lastEpoch = $g['last'] !== null ? strtotime((string)$g['last']) : false;
                    if ($firstEpoch === false || $tsEpoch < $firstEpoch) $g['first'] = $ts;
                    if ($lastEpoch === false || $tsEpoch > $lastEpoch) $g['last'] = $ts;
                }
            }
        }

        // Fall back to the JSON manifest for machine attributes the CSV lacks
        // (older/third-party files may carry them only in the manifest).
        if ($manifestData) {
            if ($g['systemuuid'] === '' && !empty($manifestData['system_uuid'])) {
                $g['systemuuid'] = clip_str((string)$manifestData['system_uuid'], 64);
            }
            if ($g['chassisserial'] === '' && !empty($manifestData['chassis_serial'])) {
                $g['chassisserial'] = clip_str((string)$manifestData['chassis_serial'], 255);
            }
            if ($g['chassistype'] === '' && !empty($manifestData['chassis_type'])) {
                $g['chassistype'] = clip_str((string)$manifestData['chassis_type'], 64);
            }
            if ($g['biosversion'] === '' && !empty($manifestData['bios_version'])) {
                $g['biosversion'] = clip_str((string)$manifestData['bios_version'], 64);
            }
            if ($g['biosdate'] === '' && !empty($manifestData['bios_date'])) {
                $g['biosdate'] = clip_str((string)$manifestData['bios_date'], 32);
            }
            if ($g['bioslock'] === '' && !empty($manifestData['bios_lock'])) {
                $g['bioslock'] = clip_str((string)$manifestData['bios_lock'], 20);
            }
            if ($g['bioslockmethod'] === '' && !empty($manifestData['bios_lock_method'])) {
                $g['bioslockmethod'] = clip_str((string)$manifestData['bios_lock_method'], 255);
            }
            if ($g['sku'] === '' && !empty($manifestData['sku'])) $g['sku'] = clip_str((string)$manifestData['sku'], 128);
            if ($g['asset_tag'] === '' && !empty($manifestData['asset_tag'])) $g['asset_tag'] = clip_str((string)$manifestData['asset_tag'], 128);
            if ($g['bios_vendor'] === '' && !empty($manifestData['bios_vendor'])) $g['bios_vendor'] = clip_str((string)$manifestData['bios_vendor'], 64);
            if ($g['board'] === '' && !empty($manifestData['board'])) $g['board'] = clip_str((string)$manifestData['board'], 128);
            if ($g['tpm'] === '' && !empty($manifestData['tpm'])) $g['tpm'] = clip_str((string)$manifestData['tpm'], 32);
            if ($g['macs'] === '' && !empty($manifestData['macs'])) $g['macs'] = clip_str((string)$manifestData['macs'], 255);
            if ($g['storage_controllers'] === '' && !empty($manifestData['storage_controllers'])) $g['storage_controllers'] = clip_str((string)$manifestData['storage_controllers'], 255);
            if ($g['tool_version'] === '' && !empty($manifestData['version'])) $g['tool_version'] = clip_str((string)$manifestData['version'], 32);
            if ($g['operator'] === '' && !empty($manifestData['operator'])) $g['operator'] = clip_str((string)$manifestData['operator'], 128);
            if ($g['validator'] === '' && !empty($manifestData['validator'])) $g['validator'] = clip_str((string)$manifestData['validator'], 128);
            if ($g['media_source'] === '' && !empty($manifestData['media_source'])) $g['media_source'] = clip_str((string)$manifestData['media_source'], 128);
            if ($g['media_destination'] === '' && !empty($manifestData['media_destination'])) $g['media_destination'] = clip_str((string)$manifestData['media_destination'], 128);
        }
        unset($g);
    }

    return $groups;
}

/**
 * Persist parsed certificate entries (certificates + reports + per-drive rows)
 * inside an existing transaction. $pdfSha is the SHA-256 of the rendered PDF
 * (empty for machine ingestion, which produces no PDF).
 */
function insert_certificate_records(array $entries, ?int $userId, string $pdfSha, string $pdfPath, string $issuedAt): void {
    foreach ($entries as $entry) {
        $stmt = db()->prepare(
            'INSERT INTO certificates
             (cert_id, cocid, user_id, devices, methods, runs, first_ts, last_ts, sha_state, sig_state, pdf_sha256, pdf_path, issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entry['cert'],
            (string)$entry['cocid'],
            $userId,
            (int)$entry['devices'],
            (int)$entry['methods'],
            (int)$entry['runs'],
            $entry['first'] !== null ? (string)$entry['first'] : null,
            $entry['last'] !== null ? (string)$entry['last'] : null,
            (string)$entry['sha_state'],
            (string)$entry['sig_state'],
            (string)($entry['pdf_sha'] ?? $pdfSha),
            (string)($entry['pdf_path'] ?? $pdfPath),
            $issuedAt,
        ]);

        $dbCertId = (int)db()->lastInsertId();
        rewrite_certificate_details($dbCertId, $entry['reports'], $entry['drives']);
    }
}

/**
 * (Re)write the report-file + per-drive rows for a certificate. Used both by
 * insert_certificate_records (fresh cert) and by the machine-ingestion merge
 * path, which rewrites an existing certificate when more reports arrive for the
 * same Chain of Custody ID.
 */
function rewrite_certificate_details(int $dbCertId, array $reports, array $drives): void {
    db()->prepare('DELETE FROM certificate_reports WHERE certificate_id = ?')->execute([$dbCertId]);
    db()->prepare('DELETE FROM certificate_drives WHERE certificate_id = ?')->execute([$dbCertId]);

    $rq = db()->prepare('INSERT INTO certificate_reports (certificate_id, report_name, sha256, state) VALUES (?, ?, ?, ?)');
    foreach ($reports as $r) {
        $rq->execute([$dbCertId, (string)$r['name'], (string)$r['sha'], (string)$r['state']]);
    }

    $dq = db()->prepare(
        'INSERT INTO certificate_drives
         (certificate_id, ts, device, type, model, serial, size, bus, class, method, certification, final_status, system_name, system_serial, baseboard_serial,
          smart, tempc, poweronhours, powercycles, reallocsectors, pctused, availspare, tbw_tb, smartpost, tempcpost, poweronhourspost)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($drives as $d) {
        $dq->execute([
            $dbCertId,
            (string)($d['ts'] ?? ''),
            (string)($d['device'] ?? ''),
            (string)($d['type'] ?? ''),
            (string)($d['model'] ?? ''),
            (string)($d['serial'] ?? ''),
            (string)($d['size'] ?? ''),
            (string)($d['bus'] ?? ''),
            (string)($d['cls'] ?? ''),
            (string)($d['method'] ?? ''),
            (string)($d['cert'] ?? ''),
            (string)($d['status'] ?? ''),
            (string)($d['system'] ?? ''),
            (string)($d['sysserial'] ?? ''),
            (string)($d['bbserial'] ?? ''),
            (string)($d['smart'] ?? ''),
            (string)($d['tempc'] ?? ''),
            (string)($d['poweronhours'] ?? ''),
            (string)($d['powercycles'] ?? ''),
            (string)($d['reallocsectors'] ?? ''),
            (string)($d['pctused'] ?? ''),
            (string)($d['availspare'] ?? ''),
            (string)($d['tbw_tb'] ?? ''),
            (string)($d['smartpost'] ?? ''),
            (string)($d['tempcpost'] ?? ''),
            (string)($d['poweronhourspost'] ?? ''),
        ]);
    }
}

/**
 * Map a certificate_drives row (DB column names) to the group-drive shape that
 * render_certificate_pdf expects (cls/cert/status/sysserial/bbserial).
 */
function db_drive_to_group(array $d): array {
    return [
        'ts' => (string)($d['ts'] ?? ''),
        'model' => (string)($d['model'] ?? ''),
        'serial' => (string)($d['serial'] ?? ''),
        'device' => (string)($d['device'] ?? ''),
        'type' => (string)($d['type'] ?? ''),
        'size' => (string)($d['size'] ?? ''),
        'bus' => (string)($d['bus'] ?? ''),
        'method' => (string)($d['method'] ?? ''),
        // Accept either shape: group-format arrays (parse_reports payloads,
        // used when consolidating stored reports in POST /api/certs) or raw
        // certificate_drives DB rows (used when regenerating an existing
        // certificate). The group keys win when present, so the conversion is
        // idempotent for already-grouped drives.
        'cls' => (string)($d['cls'] ?? $d['class'] ?? ''),
        'cert' => (string)($d['cert'] ?? $d['certification'] ?? ''),
        'status' => (string)($d['status'] ?? $d['final_status'] ?? ''),
        'system' => (string)($d['system'] ?? ''),
        'sysserial' => (string)($d['sysserial'] ?? $d['system_serial'] ?? ''),
        'bbserial' => (string)($d['bbserial'] ?? $d['baseboard_serial'] ?? ''),
        'smart' => (string)($d['smart'] ?? ''),
        'tempc' => (string)($d['tempc'] ?? ''),
        'poweronhours' => (string)($d['poweronhours'] ?? ''),
        'powercycles' => (string)($d['powercycles'] ?? ''),
        'reallocsectors' => (string)($d['reallocsectors'] ?? ''),
        'pctused' => (string)($d['pctused'] ?? ''),
        'availspare' => (string)($d['availspare'] ?? ''),
        'tbw_tb' => (string)($d['tbw_tb'] ?? ''),
        'smartpost' => (string)($d['smartpost'] ?? ''),
        'tempcpost' => (string)($d['tempcpost'] ?? ''),
        'poweronhourspost' => (string)($d['poweronhourspost'] ?? ''),
    ];
}

/**
 * Rank a drive's final status so that, when the same physical drive (serial)
 * appears in multiple reports, the *best* outcome wins. A drive blocked or
 * failed on one machine and then successfully erased on another must resolve
 * to COMPLETED, not the earlier failure.
 *
 * Tiers: COMPLETED > operator-confirmed DESTROYED > DRY-RUN > any not-sanitised
 * outcome. Within the not-sanitised outcomes the rank is an ESCALATION order,
 * so the label shown never depends on upload order: FROZEN (destruction
 * required) > BLOCKED (firmware blocked) > FAILED (erase failed) > UNKNOWN
 * (no terminal status).
 */
function drive_status_rank(?string $status): int {
    $s = strtoupper(trim((string)$status));
    if ($s === 'COMPLETED') return 100;
    if ($s === 'DESTROYED') return 50;
    if ($s === 'DRY-RUN')   return 10;
    if ($s === 'FROZEN')    return 4;
    if ($s === 'BLOCKED')   return 3;
    if ($s === 'FAILED')    return 2;
    if ($s === 'UNKNOWN')   return 1;
    return 0;
}

/**
 * Merge a freshly parsed report group into an existing certificate (same COCID),
 * deduping drives by serial and reports by SHA, and keeping the strongest
 * sha/sig states and the widest first/last window. When the same serial appears
 * more than once, the drive row with the best outcome wins.
 */
function merge_group(array $g, array $dbDrives, array $dbReports, array $existingCert): array {
    $drives = [];
    $bySerial = [];
    foreach ($dbDrives as $d) {
        $gd = db_drive_to_group($d);
        $s = strtolower(trim((string)($gd['serial'] ?? '')));
        if ($s !== '' && isset($bySerial[$s])) {
            if (drive_status_rank($gd['status'] ?? '') > drive_status_rank($drives[$bySerial[$s]]['status'] ?? '')) {
                $drives[$bySerial[$s]] = $gd;
            }
            continue;
        }
        if ($s !== '') $bySerial[$s] = count($drives);
        $drives[] = $gd;
    }
    foreach (($g['drives'] ?? []) as $nd) {
        $s = strtolower(trim((string)($nd['serial'] ?? '')));
        if ($s !== '' && isset($bySerial[$s])) {
            if (drive_status_rank($nd['status'] ?? '') > drive_status_rank($drives[$bySerial[$s]]['status'] ?? '')) {
                $drives[$bySerial[$s]] = $nd;
            }
            continue;
        }
        if ($s !== '') $bySerial[$s] = count($drives);
        $drives[] = $nd;
    }

    $reports = $dbReports;
    $seenShas = [];
    foreach ($dbReports as $r) { $seenShas[strtolower((string)$r['sha'])] = true; }
    foreach (($g['reports'] ?? []) as $nr) {
        $sha = strtolower((string)($nr['sha'] ?? ''));
        if ($sha !== '' && isset($seenShas[$sha])) continue;
        $seenShas[$sha] = true;
        $reports[] = $nr;
    }

    $first = $existingCert['first_ts'] ?? null;
    $last = $existingCert['last_ts'] ?? null;
    if (($g['first'] ?? null) !== null) {
        $first = ($first === null || strtotime((string)$g['first']) < strtotime((string)$first)) ? $g['first'] : $first;
    }
    if (($g['last'] ?? null) !== null) {
        $last = ($last === null || strtotime((string)$g['last']) > strtotime((string)$last)) ? $g['last'] : $last;
    }

    $shaRank = ['unverified' => 0, 'verified' => 1, 'mismatch' => 2];
    $sigRank = ['none' => 0, 'valid' => 1, 'attributed' => 2, 'invalid' => 3];
    $shaState = (($shaRank[$g['shaState'] ?? 'unverified'] ?? 0) >= ($shaRank[$existingCert['sha_state'] ?? 'unverified'] ?? 0))
        ? ($g['shaState'] ?? 'unverified') : ($existingCert['sha_state'] ?? 'unverified');
    $sigState = (($sigRank[$g['sigState'] ?? 'none'] ?? 0) >= ($sigRank[$existingCert['sig_state'] ?? 'none'] ?? 0))
        ? ($g['sigState'] ?? 'none') : ($existingCert['sig_state'] ?? 'none');

    return [
        'cocid' => (string)$g['cocid'],
        'drives' => $drives,
        'reports' => $reports,
        'first' => $first,
        'last' => $last,
        'shaState' => $shaState,
        'sigState' => $sigState,
    ];
}

/**
 * Collect every report-file SHA-256 this user has already ingested, decoded
 * from the JSON payloads of their `reports` rows. Used to skip duplicates when
 * reports are re-uploaded (e.g. an entire fleet uploaded again from USBs).
 */
function user_ingested_shas(int $userId): array {
    $set = [];
    $stmt = db()->prepare('SELECT payload FROM reports WHERE user_id = ?');
    $stmt->execute([$userId]);
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $p = json_decode((string)($row['payload'] ?? ''), true);
        if (!is_array($p)) continue;
        foreach (($p['reports'] ?? []) as $r) {
            $sha = strtolower((string)($r['sha'] ?? ''));
            if ($sha !== '') $set[$sha] = true;
        }
    }
    return $set;
}

/**
 * Persist parsed report groups (raw evidence) to the `reports` table. Returns
 * the list of inserted report row IDs. $reportType is 'erasure' (default) or
 * 'diagnostics'.
 */
function store_reports(array $groups, int $userId, string $source, string $reportType = 'erasure'): array {
    $ids = [];
    $q = db()->prepare(
        'INSERT INTO reports (user_id, cocid, filename, sha_state, sig_state, source, report_type, devices, runs, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($groups as $g) {
        $names = array_map(fn($r) => (string)($r['name'] ?? ''), $g['reports'] ?? []);
        $filename = implode(', ', array_slice($names, 0, 5));
        $q->execute([
            $userId,
            (string)($g['cocid'] ?? ''),
            clip_str($filename, 500),
            (string)($g['shaState'] ?? 'unverified'),
            (string)($g['sigState'] ?? 'none'),
            $source,
            $reportType === 'diagnostics' ? 'diagnostics' : 'erasure',
            count($g['drives'] ?? []),
            count($g['reports'] ?? []),
            json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $ids[] = (int)db()->lastInsertId();
    }
    return $ids;
}

/**
 * Ingest a boot-time diagnostics report (identity + hardware + attached drive
 * inventory) into the `reports` table with report_type = 'diagnostics'. The
 * payload is stored as a JSON envelope in the same shape as an erasure group
 * (system / sysserial / drives / reports) so the generic report readers stay
 * tolerant of it. Returns the inserted row id, or 0 on failure.
 */
function store_diagnostics_report(int $userId, array $d, string $serial, string $uuid): int {
    reports_ensure_schema();

    $system = trim((string)($d['manufacturer'] ?? '') . ' ' . (string)($d['product'] ?? ''));
    $now    = gmdate('Y-m-d\TH:i:s\Z');

    $drives = [];
    foreach (($d['drives'] ?? []) as $dv) {
        if (!is_array($dv)) continue;
        $drives[] = [
            'device'     => (string)($dv['device'] ?? ''),
            'type'       => (string)($dv['type'] ?? ''),
            'model'      => (string)($dv['model'] ?? ''),
            'serial'     => (string)($dv['serial'] ?? ''),
            'size'       => (string)($dv['size'] ?? ''),
            'bus'        => (string)($dv['bus'] ?? ''),
            'class'      => (string)($dv['class'] ?? ''),
            'capability' => (string)($dv['capability'] ?? ''),
            'firmware'    => (string)($dv['firmware'] ?? ''),
            'sector_size' => (string)($dv['sector_size'] ?? ''),
            'sectors'     => (string)($dv['sectors'] ?? ''),
            'smart'       => (string)($dv['smart'] ?? ''),
            'selftest'    => (string)($dv['selftest'] ?? ''),
            'realloc'     => (string)($dv['realloc'] ?? ''),
            'opal_locked'=> isset($dv['opal_locked']) ? (bool)$dv['opal_locked'] : false,
        ];
    }

    $payload = [
        'report_type'    => 'diagnostics',
        'system'         => $system,
        'sysserial'      => $serial,
        'systemuuid'     => $uuid,
        'report_id'      => (string)($d['report_id'] ?? ''),
        'digital_identifier' => (string)($d['digital_identifier'] ?? ''),
        'bbserial'       => (string)($d['board_serial'] ?? ''),
        'chassisserial'  => (string)($d['chassis_serial'] ?? ''),
        'chassistype'    => (string)($d['chassis_type'] ?? ''),
        'biosversion'    => (string)($d['bios_version'] ?? ''),
        'biosdate'       => (string)($d['bios_date'] ?? ''),
        'bioslock'       => (string)($d['bios_lock'] ?? ''),
        'bioslockmethod' => (string)($d['bios_lock_method'] ?? ''),
        'cpu'            => (string)($d['cpu'] ?? ''),
        'gpu'            => (string)($d['gpu'] ?? ''),
        'ram'            => (string)($d['ram'] ?? ''),
        'sku'            => (string)($d['sku'] ?? ''),
        'asset_tag'      => (string)($d['asset_tag'] ?? ''),
        'bios_vendor'    => (string)($d['bios_vendor'] ?? ''),
        'board'          => (string)($d['board'] ?? ''),
        'tpm'            => (string)($d['tpm'] ?? ''),
        'macs'           => (string)($d['macs'] ?? ''),
        'storage_controllers' => (string)($d['storage_controllers'] ?? ''),
        'tool_version'   => (string)($d['tool_version'] ?? ''),
        'operator'       => (string)($d['operator'] ?? ''),
        'validator'      => (string)($d['validator'] ?? ''),
        'media_source'   => (string)($d['media_source'] ?? ''),
        'media_destination' => (string)($d['media_destination'] ?? ''),
        'battery'        => (string)($d['battery'] ?? ''),
        'secure_boot'    => (string)($d['secure_boot'] ?? ''),
        'dimms'          => (string)($d['dimms'] ?? ''),
        'first'          => $now,
        'last'           => $now,
        'reports'        => [['name' => 'diagnostics', 'sha' => '']],
        'drives'         => $drives,
    ];

    try {
        db()->prepare(
            'INSERT INTO reports (user_id, cocid, filename, sha_state, sig_state, source, report_type, devices, runs, payload)
             VALUES (?, ?, ?, "unverified", "none", "api", "diagnostics", ?, 1, ?)'
        )->execute([
            $userId,
            '',
            'diagnostics',
            count($drives),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('store diagnostics report error: ' . $e->getMessage());
        return 0;
    }
}

/** Shape one reports-table row (with decoded payload) for the JSON API. */
function report_row(array $r, ?array $payload): array {
    return [
        'id'          => (int)$r['id'],
        'cocid'       => (string)$r['cocid'],
        'filename'    => (string)$r['filename'],
        'sha_state'   => (string)$r['sha_state'],
        'sig_state'   => (string)$r['sig_state'],
        'source'      => (string)$r['source'],
        'report_type' => (string)($r['report_type'] ?? 'erasure'),
        'devices'     => (int)$r['devices'],
        'runs'        => (int)$r['runs'],
        'uploaded_at' => ts_local((string)$r['uploaded_at']),
        'first'       => is_array($payload) ? ts_local((string)($payload['first'] ?? '')) : '',
        'last'        => is_array($payload) ? ts_local((string)($payload['last'] ?? '')) : '',
        'system'      => is_array($payload) ? (string)($payload['system'] ?? '') : '',
        'sysserial'   => is_array($payload) ? (string)($payload['sysserial'] ?? $payload['sysSerial'] ?? '') : '',
        'bbserial'    => is_array($payload) ? (string)($payload['bbserial'] ?? $payload['bbSerial'] ?? '') : '',
        'cpu'         => is_array($payload) ? (string)($payload['cpu'] ?? '') : '',
        'gpu'         => is_array($payload) ? (string)($payload['gpu'] ?? '') : '',
        'ram'         => is_array($payload) ? (string)($payload['ram'] ?? '') : '',
        'enrollment'  => is_array($payload) ? (string)($payload['enrollment'] ?? '') : '',
        'chassisserial' => is_array($payload) ? (string)($payload['chassisserial'] ?? '') : '',
        'chassistype'   => is_array($payload) ? (string)($payload['chassistype'] ?? '') : '',
        'biosversion'   => is_array($payload) ? (string)($payload['biosversion'] ?? '') : '',
        'biosdate'      => is_array($payload) ? (string)($payload['biosdate'] ?? '') : '',
        'systemuuid'    => is_array($payload) ? (string)($payload['systemuuid'] ?? '') : '',
        'report_id'     => is_array($payload) ? (string)($payload['report_id'] ?? '') : '',
        'digital_identifier' => is_array($payload) ? (string)($payload['digital_identifier'] ?? '') : '',
        'bioslock'      => is_array($payload) ? (string)($payload['bioslock'] ?? '') : '',
        'bioslockmethod'=> is_array($payload) ? (string)($payload['bioslockmethod'] ?? '') : '',
        'sku'            => is_array($payload) ? (string)($payload['sku'] ?? '') : '',
        'asset_tag'      => is_array($payload) ? (string)($payload['asset_tag'] ?? '') : '',
        'bios_vendor'    => is_array($payload) ? (string)($payload['bios_vendor'] ?? '') : '',
        'board'          => is_array($payload) ? (string)($payload['board'] ?? '') : '',
        'tpm'            => is_array($payload) ? (string)($payload['tpm'] ?? '') : '',
        'macs'           => is_array($payload) ? (string)($payload['macs'] ?? '') : '',
        'storage_controllers' => is_array($payload) ? (string)($payload['storage_controllers'] ?? '') : '',
        'tool_version'   => is_array($payload) ? (string)($payload['tool_version'] ?? '') : '',
        'operator'       => is_array($payload) ? (string)($payload['operator'] ?? '') : '',
        'validator'      => is_array($payload) ? (string)($payload['validator'] ?? '') : '',
        'media_source'   => is_array($payload) ? (string)($payload['media_source'] ?? '') : '',
        'media_destination' => is_array($payload) ? (string)($payload['media_destination'] ?? '') : '',
        'battery'        => is_array($payload) ? (string)($payload['battery'] ?? '') : '',
        'secure_boot'    => is_array($payload) ? (string)($payload['secure_boot'] ?? '') : '',
        'dimms'          => is_array($payload) ? (string)($payload['dimms'] ?? '') : '',
        'drives'      => is_array($payload) ? ($payload['drives'] ?? []) : [],
        'reports'     => is_array($payload) ? ($payload['reports'] ?? []) : [],
    ];
}

/**
 * List a user's uploaded reports (most recent first), optionally filtered by
 * Chain of Custody ID and/or a free-text query (matches the CoC ID, filename,
 * or anything in the stored payload — including drive serials, system serial,
 * model, CPU, GPU, RAM).
 */
function load_user_reports(int $userId, ?string $cocid, ?string $q, int $page, int $per): array {
    $per = max(1, min(100, $per));
    $page = max(1, $page);
    $offset = ($page - 1) * $per;

    reports_ensure_schema();
    $where = "user_id = ? AND report_type <> 'diagnostics'";
    $params = [$userId];
    $types = [PDO::PARAM_INT];

    if ($cocid !== null && $cocid !== '') {
        $where .= ' AND cocid = ?';
        $params[] = $cocid;
        $types[] = PDO::PARAM_STR;
    }
    if ($q !== null && $q !== '') {
        $where .= ' AND (LOWER(cocid) LIKE ? OR LOWER(filename) LIKE ? OR LOWER(CAST(payload AS CHAR)) LIKE ?)';
        $like = '%' . strtolower($q) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types[] = PDO::PARAM_STR;
        $types[] = PDO::PARAM_STR;
        $types[] = PDO::PARAM_STR;
    }

    $count = db()->prepare("SELECT COUNT(*) FROM reports WHERE $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = db()->prepare("SELECT * FROM reports WHERE $where ORDER BY uploaded_at DESC, id DESC LIMIT ? OFFSET ?");
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 1, $p, $types[$i]);
    }
    $stmt->bindValue(count($params) + 1, $per, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $payload = json_decode((string)$r['payload'], true);
        $rows[] = report_row($r, is_array($payload) ? $payload : null);
    }
    return ['reports' => $rows, 'total' => $total, 'page' => $page, 'per' => $per];
}

/**
 * Distinct Chain of Custody IDs for a user's uploaded reports, with the number
 * of report uploads and the existing certificate (if any) for each COCID.
 */
function distinct_cocids(int $userId): array {
    reports_ensure_schema();
    $stmt = db()->prepare(
        'SELECT r.cocid, COUNT(*) AS report_count, MAX(r.uploaded_at) AS latest,
                (SELECT c.cert_id FROM certificates c WHERE c.user_id = r.user_id AND c.cocid = r.cocid ORDER BY c.id DESC LIMIT 1) AS cert_id
         FROM reports r
         WHERE r.user_id = ? AND r.report_type <> \'diagnostics\'
         GROUP BY r.cocid
         ORDER BY latest DESC'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'cocid'        => (string)$row['cocid'],
            'report_count' => (int)$row['report_count'],
            'latest'       => (string)$row['latest'],
            'cert_id'      => $row['cert_id'] === null ? null : (string)$row['cert_id'],
        ];
    }
    return $out;
}

/**
 * Load every stored report group (decoded payloads) for a COCID, oldest first —
 * the input set for generating a consolidated certificate.
 */
function load_report_payloads(int $userId, string $cocid): array {
    reports_ensure_schema();
    $stmt = db()->prepare("SELECT payload FROM reports WHERE user_id = ? AND cocid = ? AND report_type <> 'diagnostics' ORDER BY id");
    $stmt->execute([$userId, $cocid]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $g = json_decode((string)$p, true);
        if (is_array($g)) $groups[] = $g;
    }
    return $groups;
}

// ---- device presence (heartbeat) ------------------------------------------

/** Lazily create the device_presence table (idempotent — mirrors schema.sql). */
function presence_ensure_schema(): void {
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS device_presence (
               id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id      BIGINT UNSIGNED NOT NULL,
               serial       VARCHAR(255)    NOT NULL DEFAULT "",
               uuid         VARCHAR(64)     NOT NULL DEFAULT "",
               last_seen_ts INT UNSIGNED    NOT NULL,
               PRIMARY KEY (id),
               UNIQUE KEY uq_presence_device (user_id, serial, uuid),
               KEY idx_presence_seen (last_seen_ts)
            )'
        );
    } catch (Throwable $e) {
        error_log('presence ensure schema error: ' . $e->getMessage());
    }
}

/** Record a heartbeat from a booted appliance (keyed by serial + uuid). */
function presence_heartbeat(int $userId, string $serial, string $uuid): void {
    try {
        db()->prepare(
            'INSERT INTO device_presence (user_id, serial, uuid, last_seen_ts)
             VALUES (?, ?, ?, UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_seen_ts = UNIX_TIMESTAMP()'
        )->execute([$userId, $serial, $uuid]);
    } catch (Throwable $e) {
        error_log('presence heartbeat error: ' . $e->getMessage());
    }
}

/** Map a user's presence rows to ['serial' => ts, 'uuid' => ts] (lowercased). */
function presence_map(int $userId): array {
    $map = ['serial' => [], 'uuid' => []];
    try {
        $stmt = db()->prepare('SELECT serial, uuid, last_seen_ts FROM device_presence WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = (int)$r['last_seen_ts'];
            $s  = strtolower(trim((string)$r['serial']));
            $u  = strtolower(trim((string)$r['uuid']));
            if ($s !== '') $map['serial'][$s] = $ts;
            if ($u !== '') $map['uuid'][$u]  = $ts;
        }
    } catch (Throwable $e) {
        error_log('presence map error: ' . $e->getMessage());
    }
    return $map;
}

/** True when the device has a heartbeat within the online window (seconds). */
function presence_is_online(string $serial, string $uuid, array $map, int $window = 90): bool {
    $s = strtolower(trim($serial));
    $u = strtolower(trim($uuid));
    if ($s !== '' && isset($map['serial'][$s])) {
        return (time() - $map['serial'][$s]) <= $window;
    }
    if ($u !== '' && isset($map['uuid'][$u])) {
        return (time() - $map['uuid'][$u]) <= $window;
    }
    return false;
}

/** Latest heartbeat timestamp (unix seconds) for a device, or null if none. */
function presence_last_seen(string $serial, string $uuid, array $map): ?int {
    $s = strtolower(trim($serial));
    $u = strtolower(trim($uuid));
    $ts = null;
    if ($s !== '' && isset($map['serial'][$s])) {
        $ts = (int)$map['serial'][$s];
    }
    if ($u !== '' && isset($map['uuid'][$u])) {
        $ut = (int)$map['uuid'][$u];
        if ($ts === null || $ut > $ts) $ts = $ut;
    }
    return $ts;
}

/** Lazily create the device_registrations table (idempotent — mirrors schema.sql). */
function register_ensure_schema(): void {
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS device_registrations (
               id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id       BIGINT UNSIGNED NOT NULL,
               serial        VARCHAR(255)    NOT NULL DEFAULT "",
               uuid          VARCHAR(64)     NOT NULL DEFAULT "",
               payload       JSON            NOT NULL,
               registered_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
               last_seen_ts  INT UNSIGNED    NOT NULL DEFAULT 0,
               PRIMARY KEY (id),
               UNIQUE KEY uq_reg_device (user_id, serial, uuid),
               KEY idx_reg_user (user_id, id),
               CONSTRAINT fk_reg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );
    } catch (Throwable $e) {
        error_log('register ensure schema error: ' . $e->getMessage());
    }
}

/** Upsert a boot-time registration snapshot (identity + hardware + drives). */
function device_register(int $userId, string $serial, string $uuid, array $payload): void {
    register_ensure_schema();
    try {
        db()->prepare(
            'INSERT INTO device_registrations (user_id, serial, uuid, payload, registered_at, last_seen_ts)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_seen_ts = UNIX_TIMESTAMP()'
        )->execute([$userId, $serial, $uuid, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        error_log('device register error: ' . $e->getMessage());
    }
}

/** Fetch a user's registration snapshots (newest first). */
function load_registered_devices(int $userId): array {
    $out = [];
    try {
        $stmt = db()->prepare('SELECT serial, uuid, payload, registered_at, last_seen_ts FROM device_registrations WHERE user_id = ? ORDER BY registered_at DESC, id DESC');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = json_decode((string)$r['payload'], true);
            if (!is_array($p)) $p = [];
            $out[] = [
                'serial'        => (string)$r['serial'],
                'uuid'          => (string)$r['uuid'],
                'payload'       => $p,
                'registered_at' => (string)$r['registered_at'],
                'last_seen_ts'  => (int)$r['last_seen_ts'],
            ];
        }
    } catch (Throwable $e) {
        error_log('register load error: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Aggregated machine (hardware/firmware) inventory across a user's boot-time
 * diagnostics reports — one row per physical machine, keyed by system serial
 * (fallback: baseboard serial, then system UUID). The serial is the stable
 * ITAD inventory key; UUIDs are frequently absent or vendor-garbage, and
 * serial-first keeps the same machine from appearing twice when one report
 * carried a UUID and another did not. The most recent report's profile and
 * attached-drive inventory win; every diagnostics snapshot is kept in
 * `history` (newest first) and first/last seen are accumulated. This feeds
 * the dashboard "Devices" tab.
 */
function load_devices(int $userId): array {
    reports_ensure_schema();
    $stmt = db()->prepare("SELECT uploaded_at, payload FROM reports WHERE user_id = ? AND report_type = 'diagnostics' ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute([$userId]);

    $devices = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = json_decode((string)$r['payload'], true);
        if (!is_array($g)) continue;

        $key = '';
        foreach (['sysserial', 'sysSerial', 'bbserial', 'bbSerial', 'systemuuid'] as $k) {
            if (!empty($g[$k])) { $key = strtolower((string)$g[$k]); break; }
        }
        if ($key === '') continue;   // diagnostics without identity can't be listed

        $drives = is_array($g['drives'] ?? null) ? $g['drives'] : [];
        $seen   = ts_local((string)$r['uploaded_at']);

        if (!isset($devices[$key])) {
            $devices[$key] = [
                'system'        => (string)($g['system'] ?? ''),
                'sysserial'     => (string)($g['sysserial'] ?? $g['sysSerial'] ?? ''),
                'bbserial'      => (string)($g['bbserial'] ?? $g['bbSerial'] ?? ''),
                'report_id'     => (string)($g['report_id'] ?? ''),
                'digital_identifier' => (string)($g['digital_identifier'] ?? ''),
                'chassisserial' => (string)($g['chassisserial'] ?? ''),
                'chassistype'   => (string)($g['chassistype'] ?? ''),
                'biosversion'   => (string)($g['biosversion'] ?? ''),
                'biosdate'      => (string)($g['biosdate'] ?? ''),
                'systemuuid'    => (string)($g['systemuuid'] ?? ''),
                'bioslock'      => (string)($g['bioslock'] ?? ''),
                'bioslockmethod'=> (string)($g['bioslockmethod'] ?? ''),
                'cpu'           => (string)($g['cpu'] ?? ''),
                'gpu'           => (string)($g['gpu'] ?? ''),
                'ram'           => (string)($g['ram'] ?? ''),
                'sku'           => (string)($g['sku'] ?? ''),
                'asset_tag'     => (string)($g['asset_tag'] ?? ''),
                'bios_vendor'   => (string)($g['bios_vendor'] ?? ''),
                'board'         => (string)($g['board'] ?? ''),
                'tpm'           => (string)($g['tpm'] ?? ''),
                'macs'          => (string)($g['macs'] ?? ''),
                'storage_controllers' => (string)($g['storage_controllers'] ?? ''),
                'tool_version'  => (string)($g['tool_version'] ?? ''),
                'operator'      => (string)($g['operator'] ?? ''),
                'validator'     => (string)($g['validator'] ?? ''),
                'media_source'  => (string)($g['media_source'] ?? ''),
                'media_destination' => (string)($g['media_destination'] ?? ''),
                'battery'       => (string)($g['battery'] ?? ''),
                'secure_boot'   => (string)($g['secure_boot'] ?? ''),
                'dimms'         => (string)($g['dimms'] ?? ''),
                'mdm'           => (string)($g['enrollment'] ?? ''),
                'first'         => (string)$r['uploaded_at'],
                'last'          => (string)$r['uploaded_at'],
                'drive_count'   => count($drives),
                'drives'        => $drives,
                'history'       => [],
            ];
        }

        $d = &$devices[$key];
        $d['history'][] = [
            'uploaded_at'    => $seen,
            'first'          => $seen,
            'last'           => $seen,
            'system'         => (string)($g['system'] ?? ''),
            'sysserial'      => (string)($g['sysserial'] ?? $g['sysSerial'] ?? ''),
            'bbserial'       => (string)($g['bbserial'] ?? $g['bbSerial'] ?? ''),
            'report_id'      => (string)($g['report_id'] ?? ''),
            'digital_identifier' => (string)($g['digital_identifier'] ?? ''),
            'chassisserial'  => (string)($g['chassisserial'] ?? ''),
            'chassistype'    => (string)($g['chassistype'] ?? ''),
            'biosversion'    => (string)($g['biosversion'] ?? ''),
            'biosdate'       => (string)($g['biosdate'] ?? ''),
            'systemuuid'     => (string)($g['systemuuid'] ?? ''),
            'bioslock'       => (string)($g['bioslock'] ?? ''),
            'bioslockmethod' => (string)($g['bioslockmethod'] ?? ''),
            'cpu'            => (string)($g['cpu'] ?? ''),
            'gpu'            => (string)($g['gpu'] ?? ''),
            'ram'            => (string)($g['ram'] ?? ''),
            'sku'            => (string)($g['sku'] ?? ''),
            'asset_tag'      => (string)($g['asset_tag'] ?? ''),
            'bios_vendor'    => (string)($g['bios_vendor'] ?? ''),
            'board'          => (string)($g['board'] ?? ''),
            'tpm'            => (string)($g['tpm'] ?? ''),
            'macs'           => (string)($g['macs'] ?? ''),
            'storage_controllers' => (string)($g['storage_controllers'] ?? ''),
            'tool_version'   => (string)($g['tool_version'] ?? ''),
            'operator'       => (string)($g['operator'] ?? ''),
            'validator'      => (string)($g['validator'] ?? ''),
            'media_source'   => (string)($g['media_source'] ?? ''),
            'media_destination' => (string)($g['media_destination'] ?? ''),
            'battery'        => (string)($g['battery'] ?? ''),
            'secure_boot'    => (string)($g['secure_boot'] ?? ''),
            'dimms'          => (string)($g['dimms'] ?? ''),
            'drive_count'    => count($drives),
            'drives'         => $drives,
        ];
        if ((string)$r['uploaded_at'] < $d['first']) $d['first'] = (string)$r['uploaded_at'];
        if ((string)$r['uploaded_at'] > $d['last'])  $d['last']  = (string)$r['uploaded_at'];
        unset($d);
    }

    $out = array_values($devices);
    $presence = presence_map($userId);
    foreach ($out as $k => $dv) {
        $lastSeen = presence_last_seen((string)$dv['sysserial'], (string)$dv['systemuuid'], $presence);
        $out[$k]['online']    = presence_is_online((string)$dv['sysserial'], (string)$dv['systemuuid'], $presence);
        $out[$k]['last_seen'] = $lastSeen !== null ? ts_rel($lastSeen) : null;
        $out[$k]['last_seen_at'] = $lastSeen !== null ? ts_local(gmdate('Y-m-d H:i:s', $lastSeen)) : null;
        $out[$k]['first']     = ts_local((string)$dv['first']);
        $out[$k]['last']      = ts_local((string)$dv['last']);
    }

    // Merge legacy boot-time registrations (pre-v1.8.6 appliances POST to
    // /api/devices/register): the same boot-time diagnostics snapshot, stored
    // in the older table. A registration whose serial or UUID already has a
    // typed diagnostics report is skipped; otherwise it appears as a device
    // with a single synthesized history entry.
    foreach (load_registered_devices($userId) as $reg) {
        $p = $reg['payload'];
        $serial = strtolower(trim((string)($p['serial'] ?? $reg['serial'])));
        $uuid   = strtolower(trim((string)($p['uuid'] ?? $reg['uuid'])));

        $matched = false;
        foreach ($out as $dv) {
            $mSerial = strtolower(trim((string)$dv['sysserial']));
            $mUuid   = strtolower(trim((string)$dv['systemuuid']));
            if (($serial !== '' && $serial === $mSerial) || ($uuid !== '' && $uuid === $mUuid)) {
                $matched = true;
                break;
            }
        }
        if ($matched) continue;

        $drives = is_array($p['drives'] ?? null) ? $p['drives'] : [];
        $seen   = ts_local((string)$reg['registered_at']);
        $profile = [
            'system'         => trim((string)($p['manufacturer'] ?? '') . ' ' . (string)($p['product'] ?? '')),
            'sysserial'      => (string)($p['serial'] ?? $reg['serial']),
            'bbserial'       => '',
            'report_id'      => (string)($p['report_id'] ?? ''),
            'digital_identifier' => (string)($p['digital_identifier'] ?? ''),
            'chassisserial'  => (string)($p['chassis_serial'] ?? ''),
            'chassistype'    => (string)($p['chassis_type'] ?? ''),
            'biosversion'    => (string)($p['bios_version'] ?? ''),
            'biosdate'       => (string)($p['bios_date'] ?? ''),
            'systemuuid'     => (string)($p['uuid'] ?? $reg['uuid']),
            'bioslock'       => (string)($p['bios_lock'] ?? ''),
            'bioslockmethod' => (string)($p['bios_lock_method'] ?? ''),
            'cpu'            => (string)($p['cpu'] ?? ''),
            'gpu'            => (string)($p['gpu'] ?? ''),
            'ram'            => (string)($p['ram'] ?? ''),
            'sku'            => (string)($p['sku'] ?? ''),
            'asset_tag'      => (string)($p['asset_tag'] ?? ''),
            'bios_vendor'    => (string)($p['bios_vendor'] ?? ''),
            'board'          => (string)($p['board'] ?? ''),
            'tpm'            => (string)($p['tpm'] ?? ''),
            'macs'           => (string)($p['macs'] ?? ''),
            'storage_controllers' => (string)($p['storage_controllers'] ?? ''),
            'tool_version'   => (string)($p['tool_version'] ?? ''),
            'operator'       => (string)($p['operator'] ?? ''),
            'validator'      => (string)($p['validator'] ?? ''),
            'media_source'   => (string)($p['media_source'] ?? ''),
            'media_destination' => (string)($p['media_destination'] ?? ''),
            'battery'        => (string)($p['battery'] ?? ''),
            'secure_boot'    => (string)($p['secure_boot'] ?? ''),
            'dimms'          => (string)($p['dimms'] ?? ''),
        ];
        $regSeen = presence_last_seen((string)($p['serial'] ?? $reg['serial']), (string)($p['uuid'] ?? $reg['uuid']), $presence);
        $out[] = $profile + [
            'mdm'         => '',
            'first'       => $seen,
            'last'        => $seen,
            'online'      => presence_is_online((string)($p['serial'] ?? $reg['serial']), (string)($p['uuid'] ?? $reg['uuid']), $presence),
            'last_seen'   => $regSeen !== null ? ts_rel($regSeen) : null,
            'last_seen_at' => $regSeen !== null ? ts_local(gmdate('Y-m-d H:i:s', $regSeen)) : null,
            'drive_count' => count($drives),
            'drives'      => $drives,
            'history'     => [
                $profile + [
                    'uploaded_at' => $seen,
                    'first'       => $seen,
                    'last'        => $seen,
                    'drive_count' => count($drives),
                    'drives'      => $drives,
                ],
            ],
        ];
    }

    return $out;
}

/**
 * Aggregated storage-device inventory across a user's reports — one row per
 * physical drive (keyed by serial). The best erasure outcome wins for the
 * summary columns; every report that touched the drive is kept in `history`
 * (the Drives tab expands it when a drive has multiple reports). Sorted by
 * model, then serial.
 */
function load_drives(int $userId): array {
    reports_ensure_schema();
    $stmt = db()->prepare("SELECT cocid, uploaded_at, devices, payload FROM reports WHERE user_id = ? AND report_type <> 'diagnostics' ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute([$userId]);

    $drives = [];
    $lastUpload = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = json_decode((string)$r['payload'], true);
        if (!is_array($g) || empty($g['drives'])) continue;
        foreach ($g['drives'] as $d) {
            if (!is_array($d)) continue;
            $serial = trim((string)($d['serial'] ?? ''));
            $key = $serial !== '' ? strtolower($serial) : '';
            if ($key === '') continue;

            $entry = $d;
            $entry['cocid']          = (string)$r['cocid'];
            $entry['uploaded_at']    = ts_local((string)$r['uploaded_at']);
            $entry['ts']             = ts_local((string)($d['ts'] ?? ''));
            $entry['report_devices'] = (int)$r['devices'];

            if (!isset($drives[$key])) {
                $drives[$key] = $entry;
                $drives[$key]['history'] = [$entry];
                $lastUpload[$key] = (string)$r['uploaded_at'];
            } else {
                // Reports are read newest-first, so the first entry for a drive
                // is its most recent report — the drives table shows that row
                // verbatim; older reports stay in `history` for the expander.
                $drives[$key]['history'][] = $entry;
            }
        }
    }

    $out = array_values($drives);
    foreach ($out as &$dv) {
        $dv['reports']  = count($dv['history']);
        $dv['multiple'] = $dv['reports'] > 1;
        unset($dv);
    }
    // Sort by most recent report upload, newest first (model/serial as a
    // stable tiebreaker). Reports are read newest-first, so first-seen wins.
    usort($out, function ($a, $b) use ($lastUpload) {
        $ka = strtolower((string)($a['serial'] ?? ''));
        $kb = strtolower((string)($b['serial'] ?? ''));
        $c = strcmp((string)($lastUpload[$kb] ?? ''), (string)($lastUpload[$ka] ?? ''));
        if ($c !== 0) return $c;
        $m = strcasecmp((string)($a['model'] ?? ''), (string)($b['model'] ?? ''));
        if ($m !== 0) return $m;
        return strcasecmp((string)($a['serial'] ?? ''), (string)($b['serial'] ?? ''));
    });
    return $out;
}
