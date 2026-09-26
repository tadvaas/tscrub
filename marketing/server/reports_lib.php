<?php
declare(strict_types=1);

/**
 * Shared report parsing (used by certify.php and the /api/reports ingestion).
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
 * Parse an uploaded reports multipart and group drives by Chain of Custody ID.
 *
 * @param array $files The `$_FILES['reports']` array.
 * @param array $licencePubKeys Base64-encoded DER public keys of the owner's
 *                              paid licences (see licence_pub_keys).
 * @return array $groups[COCID] = [cocid, drives[], reports[], shaState, sigState,
 *                                 system, sysSerial, bbSerial, first, last]
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

        if ($cocid === '' && preg_match('/_(\d{5})_/', $csv['name'], $mm)) $cocid = $mm[1];
        if ($cocid === '') $cocid = 'UNKNOWN';
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
                'system' => '', 'sysSerial' => '', 'bbSerial' => '',
                'cpu' => '', 'gpu' => '', 'ram' => '',
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
            if ($serial !== '') {
                $dkey = strtolower($serial);
                if (isset($seenSerials[$cocid][$dkey])) continue;
                $seenSerials[$cocid][$dkey] = true;
            }
            $ts = $get($row, 'timestamp');
            $g['drives'][] = [
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
            ];
            if ($g['system'] === '' && isset($map['system'])) {
                $g['system'] = clip_str($get($row, 'system'), 255);
                $g['sysSerial'] = clip_str($get($row, 'systemserial'), 255);
                $g['bbSerial'] = clip_str($get($row, 'baseboardserial'), 255);
            }
            if ($g['cpu'] === '' && isset($map['cpu'])) {
                $g['cpu'] = clip_str($get($row, 'cpu'), 255);
                $g['gpu'] = clip_str($get($row, 'gpu'), 255);
                $g['ram'] = clip_str($get($row, 'ram'), 64);
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
        'cls' => (string)($d['class'] ?? ''),
        'cert' => (string)($d['certification'] ?? ''),
        'status' => (string)($d['final_status'] ?? ''),
        'system' => (string)($d['system'] ?? ''),
        'sysserial' => (string)($d['system_serial'] ?? ''),
        'bbserial' => (string)($d['baseboard_serial'] ?? ''),
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
 * to COMPLETED, not the earlier failure. COMPLETED > operator-confirmed
 * DESTROYED > DRY-RUN > anything else (FAILED/BLOCKED/FROZEN/UNKNOWN).
 */
function drive_status_rank(?string $status): int {
    $s = strtoupper(trim((string)$status));
    if ($s === 'COMPLETED') return 3;
    if ($s === 'DESTROYED') return 2;
    if ($s === 'DRY-RUN')   return 1;
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
 * the list of inserted report row IDs.
 */
function store_reports(array $groups, int $userId, string $source): array {
    $ids = [];
    $q = db()->prepare(
        'INSERT INTO reports (user_id, cocid, filename, sha_state, sig_state, source, devices, runs, payload)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            count($g['drives'] ?? []),
            count($g['reports'] ?? []),
            json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $ids[] = (int)db()->lastInsertId();
    }
    return $ids;
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
        'devices'     => (int)$r['devices'],
        'runs'        => (int)$r['runs'],
        'uploaded_at' => ts_local((string)$r['uploaded_at']),
        'first'       => is_array($payload) ? (string)($payload['first'] ?? '') : '',
        'last'        => is_array($payload) ? (string)($payload['last'] ?? '') : '',
        'system'      => is_array($payload) ? (string)($payload['system'] ?? '') : '',
        'sysserial'   => is_array($payload) ? (string)($payload['sysSerial'] ?? '') : '',
        'bbserial'    => is_array($payload) ? (string)($payload['bbSerial'] ?? '') : '',
        'cpu'         => is_array($payload) ? (string)($payload['cpu'] ?? '') : '',
        'gpu'         => is_array($payload) ? (string)($payload['gpu'] ?? '') : '',
        'ram'         => is_array($payload) ? (string)($payload['ram'] ?? '') : '',
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

    $where = 'user_id = ?';
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
    $stmt = db()->prepare(
        'SELECT r.cocid, COUNT(*) AS report_count, MAX(r.uploaded_at) AS latest,
                (SELECT c.cert_id FROM certificates c WHERE c.user_id = r.user_id AND c.cocid = r.cocid ORDER BY c.id DESC LIMIT 1) AS cert_id
         FROM reports r
         WHERE r.user_id = ?
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
    $stmt = db()->prepare('SELECT payload FROM reports WHERE user_id = ? AND cocid = ? ORDER BY id');
    $stmt->execute([$userId, $cocid]);
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $g = json_decode((string)$p, true);
        if (is_array($g)) $groups[] = $g;
    }
    return $groups;
}
