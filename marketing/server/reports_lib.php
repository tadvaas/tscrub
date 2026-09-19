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

function gen_cert_id() {
    $p1 = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $p2 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2));
    $p3 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 3));
    $p4 = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $p5 = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 4));
    return 'COD-' . $p1 . '-' . $p2 . '-' . $p3 . '-' . $p4 . '-' . $p5;
}

/**
 * Parse an uploaded reports multipart and group drives by Chain of Custody ID.
 *
 * @param array $files The `$_FILES['reports']` array.
 * @return array $groups[COCID] = [cocid, drives[], reports[], shaState, sigState,
 *                                 system, sysSerial, bbSerial, first, last]
 */
function parse_reports(array $files): array {
    // 1) Bucket uploads by stem.
    $csvs = [];
    $manifests = [];
    $sigs = [];

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
            $csvs[substr($name, 0, -4)] = ['name' => $name, 'tmp' => $tmp];
        } elseif (substr($l, -8) === '.csv.sig') {
            $sigs[substr($name, 0, -8)] = $tmp;
        } elseif (substr($l, -4) === '.sig') {
            $sigs[substr($name, 0, -4)] = $tmp;
        } elseif (substr($l, -5) === '.json') {
            $manifests[substr($name, 0, -5)] = $tmp;
        }
    }

    if (!$csvs) {
        fail(400, 'Upload one or more tScrub report files (.csv).');
    }

    // 2) Parse each CSV and consolidate drives by Chain of Custody ID.
    $groups = [];
    $seenShas = [];
    $seenSerials = [];

    foreach ($csvs as $stem => $csv) {
        // Skip an identical report file already uploaded under another name.
        $sha = strtolower(hash_file('sha256', $csv['tmp']));
        if (isset($seenShas[$sha])) continue;
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
            if (!is_array($row) || count($row) < 2) continue;
            if ($get($row, 'model') === '' && $get($row, 'serial') === '') continue;
            if ($cocid === '') $cocid = preg_replace('/[^A-Za-z0-9_-]/', '', $get($row, 'cocid'));
            $rows[] = $row;
        }
        fclose($fh);

        if (!$rows) continue;

        if ($cocid === '' && preg_match('/_(\d{5})_/', $csv['name'], $mm)) $cocid = $mm[1];
        if ($cocid === '') $cocid = 'UNKNOWN';

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
            $sigState = ($r && $r[0] === 0) ? 'valid' : 'invalid';
            @unlink($pubPath); @unlink($sigPath);
        }

        if (!isset($groups[$cocid])) {
            $groups[$cocid] = [
                'cocid' => $cocid, 'drives' => [], 'reports' => [],
                'shaState' => 'unverified', 'sigState' => 'none',
                'system' => '', 'sysSerial' => '', 'bbSerial' => '',
                'first' => null, 'last' => null,
            ];
        }
        $g = &$groups[$cocid];
        $g['reports'][] = ['name' => $csv['name'], 'sha' => $sha, 'state' => $shaState];

        if ($shaState === 'mismatch') $g['shaState'] = 'mismatch';
        elseif ($shaState === 'verified' && $g['shaState'] !== 'mismatch') $g['shaState'] = 'verified';

        if ($sigState === 'invalid') $g['sigState'] = 'invalid';
        elseif ($sigState === 'valid' && $g['sigState'] !== 'invalid') $g['sigState'] = 'valid';

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
                'ts' => $ts,
                'model' => $get($row, 'model'),
                'serial' => $get($row, 'serial'),
                'device' => $get($row, 'device'),
                'type' => $get($row, 'type'),
                'size' => $get($row, 'size'),
                'bus' => $get($row, 'bus'),
                'method' => $get($row, 'method'),
                'cls' => $get($row, 'class'),
                'cert' => $get($row, 'certification'),
                'status' => $get($row, 'finalstatus'),
                'system' => $get($row, 'system'),
                'sysserial' => $get($row, 'systemserial'),
                'bbserial' => $get($row, 'baseboardserial'),
                'smart' => $get($row, 'smart'),
                'tempc' => $get($row, 'tempc'),
                'poweronhours' => $get($row, 'poweronhours'),
                'powercycles' => $get($row, 'powercycles'),
                'reallocsectors' => $get($row, 'reallocsectors'),
                'pctused' => $get($row, 'pctused'),
                'availspare' => $get($row, 'availspare'),
                'tbw_tb' => $get($row, 'tbw_tb'),
                'smartpost' => $get($row, 'smartpost'),
                'tempcpost' => $get($row, 'tempcpost'),
                'poweronhourspost' => $get($row, 'poweronhourspost'),
            ];
            if ($g['system'] === '' && isset($map['system'])) {
                $g['system'] = $get($row, 'system');
                $g['sysSerial'] = $get($row, 'systemserial');
                $g['bbSerial'] = $get($row, 'baseboardserial');
            }
            if ($ts !== '') {
                if ($g['first'] === null || $ts < $g['first']) $g['first'] = $ts;
                if ($g['last'] === null || $ts > $g['last']) $g['last'] = $ts;
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
            $pdfSha,
            $pdfPath,
            $issuedAt,
        ]);

        $dbCertId = (int)db()->lastInsertId();
        $rq = db()->prepare('INSERT INTO certificate_reports (certificate_id, report_name, sha256, state) VALUES (?, ?, ?, ?)');
        foreach ($entry['reports'] as $r) {
            $rq->execute([$dbCertId, (string)$r['name'], (string)$r['sha'], (string)$r['state']]);
        }

        $dq = db()->prepare(
            'INSERT INTO certificate_drives
             (certificate_id, ts, device, type, model, serial, size, bus, class, method, certification, final_status, system_name, system_serial, baseboard_serial,
              smart, tempc, poweronhours, powercycles, reallocsectors, pctused, availspare, tbw_tb, smartpost, tempcpost, poweronhourspost)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($entry['drives'] as $d) {
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
}
