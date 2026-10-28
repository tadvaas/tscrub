<?php
declare(strict_types=1);

/**
 * Shared report parsing (used by the /api/reports ingestion).
 * Parses an uploaded `reports[]` multipart (CSV + optional .json manifest +
 * .csv.sig) and consolidates drives by Chain of Custody ID.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/grading.php';

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
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT pub_key FROM licences WHERE user_id IN ($ph) AND tier <> 'free' AND pub_key <> ''");
    $stmt->execute($ids);
    return array_values(array_filter(array_map(fn($r) => (string)$r['pub_key'], $stmt->fetchAll())));
}

/**
 * Lazily add the `report_type`, `grade` and `notes` columns to an existing
 * `reports` table (idempotent — mirrors schema.sql). A fresh install gets
 * them from schema.sql; an existing server self-heals on first use.
 */
function reports_ensure_schema(): void {
    static $ensured = false;
    if ($ensured) return;   // column/index probes + ALTERs are ~ms each; run once per request
    $ensured = true;
    try {
        $wanted = [
            'report_type' => "ENUM('erasure','diagnostics') NOT NULL DEFAULT 'erasure' AFTER source",
            'grade'       => "VARCHAR(8) NOT NULL DEFAULT '' AFTER report_type",
            'notes'       => "TEXT NULL AFTER grade",
            'updated_at'  => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER uploaded_at",
            'device_key'    => "VARCHAR(255) NOT NULL DEFAULT '' AFTER payload",
            'summary_json'  => "JSON NULL AFTER device_key",
            'drive_serials' => "JSON NULL AFTER summary_json",
        ];
        foreach ($wanted as $col => $ddl) {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND COLUMN_NAME = ?"
            );
            $stmt->execute([$col]);
            if ((int)$stmt->fetchColumn() > 0) {
                continue;   // column already present — nothing to do
            }
            db()->exec("ALTER TABLE reports ADD COLUMN $col $ddl");
        }
        // Composite index for the dashboard's typed report queries
        // (load_devices / load_drives): filter by report_type AND order by
        // uploaded_at without scanning the user's whole report set. Added
        // idempotently for pre-existing tables.
        $idx = (int)db()->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports'
               AND INDEX_NAME = 'idx_reports_user_type_ts'"
        )->fetchColumn();
        if ($idx === 0) {
            db()->exec('ALTER TABLE reports ADD KEY idx_reports_user_type_ts (user_id, report_type, uploaded_at, id)');
        }
        // Index for the two-phase device/drive pagination: GROUP BY device_key
        // per report_type (MAX(id) wins).
        $keyIdx = (int)db()->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports'
               AND INDEX_NAME = 'idx_reports_user_type_key'"
        )->fetchColumn();
        if ($keyIdx === 0) {
            db()->exec('ALTER TABLE reports ADD KEY idx_reports_user_type_key (user_id, report_type, device_key, id)');
        }
    } catch (Throwable $e) {
        // Non-fatal: report_type is a typed-report optimisation; pdf_sha and
        // pdf_path are only needed for the diagnostics PDF download.
        error_log('reports ensure schema: ' . $e->getMessage());
    }
}

/**
 * Derive the denormalized columns written at ingest so the dashboard list
 * queries never decode the full payload for every report. `$withSummary`
 * controls whether the list-level `summary_json` is produced (diagnostics) or
 * left NULL (erasure — which only needs `device_key` + `drive_serials`).
 */
function reports_denormalize(array $payload, bool $withSummary = false): array {
    $summary = null;
    if ($withSummary) {
        $summary = [
            'system'         => (string)($payload['system'] ?? ''),
            'sysserial'      => (string)($payload['sysserial'] ?? $payload['sysSerial'] ?? ''),
            'bbserial'       => (string)($payload['bbserial'] ?? $payload['bbSerial'] ?? ''),
            'chassisserial'  => (string)($payload['chassisserial'] ?? ''),
            'chassistype'    => (string)($payload['chassistype'] ?? ''),
            'systemuuid'     => (string)($payload['systemuuid'] ?? ''),
            'sku'            => (string)($payload['sku'] ?? ''),
            'asset_tag'      => (string)($payload['asset_tag'] ?? ''),
            'bios_vendor'    => (string)($payload['bios_vendor'] ?? ''),
            'biosversion'    => (string)($payload['biosversion'] ?? ''),
            'biosdate'       => (string)($payload['biosdate'] ?? ''),
            'board'          => (string)($payload['board'] ?? ''),
            'bioslock'       => (string)($payload['bioslock'] ?? ''),
            'bioslockmethod' => (string)($payload['bioslockmethod'] ?? ''),
            'cpu'            => (string)($payload['cpu'] ?? ''),
            'cpu_spec'       => (string)($payload['cpu_spec'] ?? ''),
            'gpu'            => (string)($payload['gpu'] ?? ''),
            'ram'            => (string)($payload['ram'] ?? ''),
            'display'        => (string)($payload['display'] ?? ''),
            'wifi'           => (string)($payload['wifi'] ?? ''),
            'tpm'            => (string)($payload['tpm'] ?? ''),
            'macs'           => (string)($payload['macs'] ?? ''),
            'storage_controllers' => (string)($payload['storage_controllers'] ?? ''),
            'battery'        => (string)($payload['battery'] ?? ''),
            'secure_boot'    => (string)($payload['secure_boot'] ?? ''),
            'dimms'          => (string)($payload['dimms'] ?? ''),
            'product_key'    => (string)($payload['product_key'] ?? ''),
            'product_key_id' => product_key_id_from_key((string)($payload['product_key'] ?? '')),
            'tool_version'   => (string)($payload['tool_version'] ?? ''),
            'operator'       => (string)($payload['operator'] ?? ''),
            'validator'      => (string)($payload['validator'] ?? ''),
            'media_source'   => (string)($payload['media_source'] ?? ''),
            'media_destination' => (string)($payload['media_destination'] ?? ''),
            'drive_count'    => count(is_array($payload['drives'] ?? null) ? $payload['drives'] : []),
            'report_id'      => (string)($payload['report_id'] ?? ''),
            'digital_identifier' => (string)($payload['digital_identifier'] ?? ''),
            'selftest_cpu'   => (string)($payload['selftest_cpu'] ?? ''),
            'mdm'            => (string)($payload['enrollment'] ?? ''),
        ];
    }
    return [
        'device_key'    => device_key($payload),
        'summary'       => $summary,
        'drive_serials' => reports_drive_serials($payload),
    ];
}

/**
 * Lowercased, deduped drive serials for an erasure report (with the model for
 * the sort tiebreak) — the index the Drives tab pages over. The original
 * serials live in the payload.
 */
function reports_drive_serials(array $payload): array {
    $out = [];
    $seen = [];
    foreach (($payload['drives'] ?? []) as $d) {
        if (!is_array($d)) continue;
        $s = strtolower(trim((string)($d['serial'] ?? '')));
        if ($s === '' || isset($seen[$s])) continue;
        $seen[$s] = true;
        $out[] = ['s' => $s, 'm' => (string)($d['model'] ?? '')];
    }
    return $out;
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
/**
 * CSV header → [normalised column name => column index].
 *
 * Normalisation is `strtolower(trim(...))` and nothing else, so `EraseEngine`
 * becomes `eraseengine` and `OverwriteStandard` becomes `overwritestandard`.
 * A duplicated header keeps the LAST occurrence, as it always has.
 *
 * Pulled out of parse_reports() so the column contract can be tested: that
 * function reads uploads via is_uploaded_file(), so it cannot be driven from
 * the CLI at all, which left the whole server side of the CSV format (and the
 * v1.11.73 Standard -> OverwriteStandard rename) covered only by ad-hoc probes.
 */
function reports_header_map(array $header): array {
    $map = [];
    foreach ($header as $idx => $col) { $map[strtolower(trim((string)$col))] = $idx; }
    return $map;
}

/** One cell by normalised column name; '' when the column or the cell is absent. */
function reports_row_value(array $row, array $map, string $key): string {
    $idx = $map[$key] ?? null;
    return ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
}

/**
 * Software-overwrite provenance + verification technique for one CSV row.
 *
 * Two things here are deliberately more than a straight column read:
 *
 *  - `OverwriteStandard` (v1.11.73+) is preferred, with the pre-rename
 *    `Standard` column accepted as a fallback, because reports written by
 *    v1.11.70-v1.11.72 must still import. The rename freed the bare word
 *    "standard" for the sanitisation CLAIM a later release may assert; the
 *    VALUE means the same thing under both names.
 *  - a row with an empty `OverwriteStandard` but a populated `Standard` (a
 *    41-column-era file, or one where the new column was added empty) must not
 *    lose the standard, which is exactly what a plain
 *    `$row['overwritestandard'] ?: ...` on a normalised map gets wrong.
 */
function reports_drive_provenance(array $row, array $map): array {
    $std = reports_row_value($row, $map, 'overwritestandard');
    if ($std === '') $std = reports_row_value($row, $map, 'standard');
    return [
        'standard' => clip_str($std, 32),
        'passes' => clip_str(reports_row_value($row, $map, 'passes'), 16),
        'erase_engine' => clip_str(reports_row_value($row, $map, 'eraseengine'), 32),
        // What KIND of evidence the read-back carries: sentinel |
        // overwrite-sample | ce-exempt. The percentage only exists for
        // overwrite-sample (see product/src/43_verify.sh).
        'verify_technique' => clip_str(reports_row_value($row, $map, 'verifytechnique'), 32),
        'verify_coverage_pct' => clip_str(reports_row_value($row, $map, 'verifycoveragepct'), 16),
        // Opt-in IEEE claim (v1.11.76+). Empty on every older report, which is
        // exactly right: those runs made no such claim.
        'sanitization_standard' => clip_str(reports_row_value($row, $map, 'sanitizationstandard'), 32),
        'sanitization_level' => clip_str(reports_row_value($row, $map, 'sanitizationlevel'), 16),
        'sanitization_shortfall' => clip_str(reports_row_value($row, $map, 'sanitizationshortfall'), 64),
    ];
}

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
        $map = reports_header_map($header);
        $get = function ($row, $key) use (&$map) {
            return reports_row_value($row, $map, $key);
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
                'cpu' => '', 'gpu' => '', 'ram' => '', 'enrollment' => '', 'verify' => '', 'format' => '',
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
                'hpa_result' => clip_str($get($row, 'hparesult'), 20),
                'dco_result' => clip_str($get($row, 'dcoresult'), 20),
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
                'verify_result' => clip_str($get($row, 'verifyresult'), 32),
                'verify_sectors' => clip_str($get($row, 'verifysectors'), 16),
                'raid' => clip_str($get($row, 'raid'), 16),
                'format' => clip_str($get($row, 'format'), 16),
                'format_fs' => clip_str($get($row, 'formatfs'), 16),
                'format_scheme' => clip_str($get($row, 'formatscheme'), 16),
                'format_label' => clip_str($get($row, 'formatlabel'), 32),
                'format_result' => clip_str($get($row, 'formatresult'), 16),
                'format_confirm' => clip_str($get($row, 'formatconfirm'), 32),
                'freeze_lock' => clip_str($get($row, 'freezelock'), 20),
                'block_sid' => clip_str($get($row, 'blocksid'), 20),
                'flr_attempts' => clip_str($get($row, 'flrattempts'), 16),
                'psid_revert' => clip_str($get($row, 'psidrevert'), 16),
                'lock_kind' => clip_str($get($row, 'lockkind'), 16),
                'ppi_requested' => clip_str($get($row, 'ppirequested'), 16),
                // Derivable only across reports (prior BLOCKED -> now COMPLETED);
                // computed in merge_group(). A single report is always 'no'.
                'ppi_cleared' => 'no',
            ];
            // Overwrite standard / passes / engine and the verification
            // technique: see reports_drive_provenance().
            $drive = array_merge($drive, reports_drive_provenance($row, $map));
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
            if ($g['verify'] === '' && isset($map['verify'])) {
                $g['verify'] = clip_str($get($row, 'verify'), 16);
            }
            if ($g['format'] === '' && isset($map['format'])) {
                $g['format'] = clip_str($get($row, 'format'), 16);
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
            if ($g['verify'] === '' && !empty($manifestData['verify'])) $g['verify'] = clip_str((string)$manifestData['verify'], 16);
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
             (cert_id, cocid, user_id, devices, methods, runs, first_ts, last_ts, sha_state, sig_state, pdf_sha256, pdf_path, json_path, json_sha256, json_ts_state, issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            (string)($entry['json_path'] ?? ''),
            (string)($entry['json_sha256'] ?? ''),
            (string)($entry['json_ts_state'] ?? 'none'),
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
/**
 * The certificate row for a COCID, created as a DRAFT when none exists yet.
 *
 * A job made only of manually recorded destruction — a pallet of phones, none of
 * which ever ran the appliance — has no report and therefore no certificate. Rather
 * than keep a second parallel store, the certificate row is created here, and
 * generate_certificate() finds it later by COCID and renders it.
 *
 * Drafts carry no pdf_path; the certificate list filters them out so they cannot
 * appear as phantom certificates in the dashboard.
 */
function manual_cert_row(int $userId, string $cocid): ?array
{
    if ($cocid === '') { return null; }

    $ids = org_member_ids($userId);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $q = db()->prepare("SELECT * FROM certificates WHERE user_id IN ($ph) AND cocid = ? ORDER BY id DESC LIMIT 1");
    $q->execute(array_merge($ids, [$cocid]));
    $row = $q->fetch();
    if ($row !== false) { return $row; }

    $certId   = gen_cert_id();
    $issuedAt = gmdate('Y-m-d H:i:s');
    insert_certificate_records([[
        'cert' => $certId, 'cocid' => $cocid, 'devices' => 0, 'methods' => 0, 'runs' => 0,
        'first' => null, 'last' => null, 'sha_state' => 'unverified', 'sig_state' => 'none',
        'reports' => [], 'drives' => [], 'pdf_sha' => '', 'pdf_path' => '',
        'json_path' => '', 'json_sha256' => '', 'json_ts_state' => 'none',
    ]], $userId, '', '', $issuedAt);
    // Mark it a draft: it is a job record, not an issued certificate. The flag is
    // cleared when generate_certificate() renders this COCID.
    db()->prepare('UPDATE certificates SET is_draft = 1 WHERE cert_id = ?')->execute([$certId]);

    $q = db()->prepare('SELECT * FROM certificates WHERE cert_id = ?');
    $q->execute([$certId]);
    $row = $q->fetch();
    return $row === false ? null : $row;
}

/**
 * Load the manually recorded (attested) destruction for a certificate: the units
 * and their media, plus any standalone destroyed media with no host recorded.
 * Media are attached to their device in memory via certificate_device_id.
 * Plan: research/manual-destruction/README.md
 */
function manual_attested_load(int $certId): array
{
    $q = db()->prepare('SELECT * FROM certificate_devices WHERE certificate_id = ? ORDER BY id');
    $q->execute([$certId]);
    $devices = $q->fetchAll();

    $q = db()->prepare("SELECT * FROM certificate_drives WHERE certificate_id = ? AND source = 'manual' ORDER BY id");
    $q->execute([$certId]);
    $media = $q->fetchAll();

    $byDevice = [];
    $loose    = [];
    foreach ($media as $m) {
        $link = (int)($m['certificate_device_id'] ?? 0);
        if ($link > 0) {
            $byDevice[$link][] = $m;
        } else {
            $loose[] = $m;
        }
    }
    foreach ($devices as $i => $d) {
        $devices[$i]['media'] = $byDevice[(int)$d['id']] ?? [];
    }

    return ['devices' => $devices, 'media' => $loose];
}

/** True when a PDO failure was an integrity-constraint violation (23000). */
function manual_is_duplicate(Throwable $e): bool
{
    return (string)$e->getCode() === '23000';
}

/** The one-row INSERT for a media record belonging to an attested device. */
function manual_media_insert(int $certId, int $deviceId, string $cocid, array $m, array $dev, string $attestedBy = ''): void
{
    // The attested-destruction vocabulary and identity rules live in one module so
    // the API, the PDF and the JSON-LD twin cannot drift apart.
    require_once __DIR__ . '/manual_destruction.php';

    $q = db()->prepare(
        "INSERT INTO certificate_drives
         (certificate_id, device, type, model, serial, size, bus, class, method, certification,
          final_status, system_name, system_serial, source, certificate_device_id,
          reason, destroy_method, destroy_vendor, destroy_date, destroy_witness,
          evidence_ref, evidence_sha256, identity_key, attested_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    // Resolve the outcome ONCE, so the label, the class and the stored method cannot
    // disagree. The medium's own method wins when it names one (a drive pulled and wiped
    // while the rest of the unit was destroyed); otherwise it inherits its host's.
    //
    // A blank value must fall through to the host, and `??` alone does not do that: the
    // validator always SETS the key (to '' when the method is missing), so a medium
    // validated without one stored an empty method, and the row was then read back as
    // destroyed while the class column on the same row said 'factory reset'.
    $own  = trim((string)($m['destroy_method'] ?? ''));
    $host = trim((string)($dev['destroy_method'] ?? ''));
    $eff  = $own !== '' ? $own : $host;
    $live = ['destroy_method' => $eff] + ($dev ?: $m);
    $retained = md_method_disposition($eff) === 'retained';

    $q->execute([
        $certId,
        '',
        (string)$m['type'],
        (string)$m['model'],
        (string)$m['serial'],
        (string)($m['size'] ?? ''),
        (string)($m['bus'] ?? ''),
        $retained ? 'FACTORY RESET' : 'DESTRUCTION',
        md_attested_method_line($live),
        'NOT SANITISED',
        $retained ? 'RETAINED' : 'DESTROYED',
        // The unit each medium came from, as the annex shows it. A standalone
        // destroyed drive has NO host: it must stay empty so the certificate
        // prints "—" rather than implying it was attached to something.
        $dev ? md_device_line($dev) : '',
        (string)($dev['serial'] ?? ''),
        'manual',
        $deviceId,
        (string)($m['reason'] ?? $dev['reason'] ?? ''),
        $eff,
        (string)($m['destroy_vendor'] ?? $dev['destroy_vendor'] ?? ''),
        (string)($m['destroy_date'] ?? $dev['destroy_date'] ?? ''),
        (string)($m['destroy_witness'] ?? $dev['destroy_witness'] ?? ''),
        (string)($m['evidence_ref'] ?? $dev['evidence_ref'] ?? ''),
        (string)($m['evidence_sha256'] ?? $dev['evidence_sha256'] ?? ''),
        md_identity_key((string)$m['serial'], (string)$m['make'], (string)$m['model']),
        // The attester, recorded from the session by the route. Stored here too
        // because a standalone destroyed drive has no unit row to carry it.
        $attestedBy,
    ]);
}

/**
 * Save a validated attested device together with its media rows.
 * $dev/$media are the 'clean' arrays from md_validate_device()/md_validate_media().
 * Returns ['ok' => bool, 'id' => int, 'error' => string].
 */
function manual_attested_save_device(int $certId, string $cocid, array $dev, array $media, string $attestedBy): array
{
    manual_list_cache([]);   // the lists are about to change
    try {
        db()->beginTransaction();

        $q = db()->prepare(
            'INSERT INTO certificate_devices
             (certificate_id, cocid, category, make, model, serial, reason, destroy_method,
              destroy_vendor, destroy_date, attested_by, destroy_witness, evidence_ref,
              evidence_sha256, identity_key, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $q->execute([
            $certId, $cocid,
            (string)$dev['category'], (string)$dev['make'], (string)$dev['model'], (string)$dev['serial'],
            (string)$dev['reason'], (string)$dev['destroy_method'],
            (string)$dev['destroy_vendor'], (string)$dev['destroy_date'],
            $attestedBy, (string)$dev['destroy_witness'],
            (string)$dev['evidence_ref'], (string)$dev['evidence_sha256'],
            (string)$dev['identity_key'],
        ]);
        $deviceId = (int)db()->lastInsertId();

        foreach ($media as $m) {
            manual_media_insert($certId, $deviceId, $cocid, $m, $dev, $attestedBy);
        }

        db()->commit();
        return ['ok' => true, 'id' => $deviceId, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('manual_attested_save_device: ' . $e->getMessage());
        return [
            'ok'    => false,
            'id'    => 0,
            'error' => manual_is_duplicate($e)
                ? 'That asset is already recorded on this job (same serial, make and model). If two units really are identical, give one an asset tag so the two can be told apart.'
                : 'Could not save the record.',
        ];
    }
}

/** Save one standalone destroyed medium (a drive with no device recorded). */
function manual_attested_save_media(int $certId, string $cocid, array $m, string $attestedBy): array
{
    manual_list_cache([]);   // the lists are about to change
    try {
        db()->beginTransaction();
        // No device: this medium has no host recorded (see manual_media_insert).
        manual_media_insert($certId, 0, $cocid, $m, [], $attestedBy);
        // Read the id BEFORE committing: after commit() lastInsertId() returns 0 on
        // this connection, so the route was answering {"ok":true,"id":0} and the
        // caller had no id with which to delete the record it had just created.
        // The device path already captured it inside the transaction, which is why
        // only standalone media were affected.
        $mediaId = (int)db()->lastInsertId();
        db()->commit();
        return ['ok' => true, 'id' => $mediaId, 'error' => ''];
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('manual_attested_save_media: ' . $e->getMessage());
        return [
            'ok'    => false,
            'id'    => 0,
            'error' => manual_is_duplicate($e)
                ? 'That medium is already recorded on this job (same serial, make and model).'
                : 'Could not save the record.',
        ];
    }
}

/**
 * Remove an attested record. Deleting a device also removes its media — there is
 * deliberately no FK from the media to the device, so this is explicit rather
 * than a cascade that could outlive a half-understood schema change.
 * Returns the number of rows removed.
 */
function manual_attested_delete(int $certId, int $id, bool $isDevice): int
{
    if ($id <= 0) { return 0; }

    manual_list_cache([]);   // the lists are about to change
    $n = 0;
    db()->beginTransaction();
    try {
        if ($isDevice) {
            $q = db()->prepare("DELETE FROM certificate_drives WHERE certificate_id = ? AND certificate_device_id = ? AND source = 'manual'");
            $q->execute([$certId, $id]);
            $n += $q->rowCount();
            $q = db()->prepare('DELETE FROM certificate_devices WHERE certificate_id = ? AND id = ?');
            $q->execute([$certId, $id]);
            $n += $q->rowCount();
        } else {
            $q = db()->prepare("DELETE FROM certificate_drives WHERE certificate_id = ? AND id = ? AND source = 'manual'");
            $q->execute([$certId, $id]);
            $n += $q->rowCount();
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('manual_attested_delete: ' . $e->getMessage());
        return 0;
    }
    return $n;
}

/**
 * The REPORT-DERIVED drives of a certificate, in the group-drive shape.
 *
 * source <> 'manual' is essential, not tidiness. Attested media live in the same table,
 * and they are not appliance findings. Without the filter they are merged into the
 * verified drive list, rendered in Annex A as instrument-verified items, counted into
 * the certificate's device total, and then written straight back by
 * rewrite_certificate_details() as source='report' rows — laundering an operator's
 * claim into the appliance's evidence, permanently. Lives here rather than in api.php
 * so the storage test can guard it.
 */
/**
 * Lazily add the software-overwrite provenance columns to an existing
 * certificate_drives table (idempotent — mirrors schema.sql). The report
 * payload carries these per drive; the certificate must persist them or a
 * JSON-LD rebuild / re-issue loses them.
 *
 * ⚠️ Call this OUTSIDE a transaction. Each ADD COLUMN is DDL, and MySQL
 * implicitly commits before DDL, so a caller holding a transaction loses it and
 * its own commit() then fails with "There is no active transaction".
 */
function certificate_drives_ensure_schema(): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    try {
        $wanted = [
            'standard'     => "VARCHAR(32) NOT NULL DEFAULT '' AFTER media_destination",
            'passes'       => "VARCHAR(16) NOT NULL DEFAULT '' AFTER standard",
            'erase_engine' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER passes",
            'verify_technique' => "VARCHAR(32) NOT NULL DEFAULT '' AFTER erase_engine",
            'verify_coverage_pct' => "VARCHAR(16) NOT NULL DEFAULT '' AFTER verify_technique",
            'sanitization_standard' => "VARCHAR(32) NOT NULL DEFAULT '' AFTER verify_coverage_pct",
            'sanitization_level' => "VARCHAR(16) NOT NULL DEFAULT '' AFTER sanitization_standard",
            'sanitization_shortfall' => "VARCHAR(64) NOT NULL DEFAULT '' AFTER sanitization_level",
        ];
        foreach ($wanted as $col => $ddl) {
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificate_drives' AND COLUMN_NAME = ?"
            );
            $stmt->execute([$col]);
            if ((int)$stmt->fetchColumn() > 0) continue;
            db()->exec("ALTER TABLE certificate_drives ADD COLUMN $col $ddl");
        }
    } catch (Throwable $e) {
        error_log('certificate_drives_ensure_schema: ' . $e->getMessage());
    }
}

function load_cert_drives(int $certId): array {
    certificate_drives_ensure_schema();
    $stmt = db()->prepare(
        'SELECT id, certificate_id, ts, device, type, model, serial, size, bus, class, method, certification, final_status,
                system_name AS `system`, system_serial, baseboard_serial,
                smart, tempc, poweronhours, powercycles, reallocsectors, pctused, availspare, tbw_tb, smartpost, tempcpost, poweronhourspost,
                firmware, sector_size, sectors, hpa, dco, sed_status, reallocsectorspost, selftest, start_time, end_time, duration_secs,
                tool_version, operator, validator, media_source, media_destination,
                standard, passes, erase_engine, verify_technique, verify_coverage_pct,
                sanitization_standard, sanitization_level, sanitization_shortfall
         FROM certificate_drives WHERE certificate_id = ? AND source <> \'manual\' ORDER BY id'
    );
    $stmt->execute([$certId]);
    return $stmt->fetchAll();
}

/**
 * Column -> group-drive key for the certificate_drives insert. The placeholder
 * list is generated from this map so a new column can never drift out of step
 * with the values (the failure mode is an unbound placeholder / wrong order).
 */
function certificate_drives_column_map(): array {
    return [
        'ts' => 'ts', 'device' => 'device', 'type' => 'type', 'model' => 'model',
        'serial' => 'serial', 'size' => 'size', 'bus' => 'bus',
        'class' => 'cls', 'method' => 'method', 'certification' => 'cert',
        'final_status' => 'status', 'system_name' => 'system',
        'system_serial' => 'sysserial', 'baseboard_serial' => 'bbserial',
        'smart' => 'smart', 'tempc' => 'tempc', 'poweronhours' => 'poweronhours',
        'powercycles' => 'powercycles', 'reallocsectors' => 'reallocsectors',
        'pctused' => 'pctused', 'availspare' => 'availspare', 'tbw_tb' => 'tbw_tb',
        'smartpost' => 'smartpost', 'tempcpost' => 'tempcpost',
        'poweronhourspost' => 'poweronhourspost', 'firmware' => 'firmware',
        'sector_size' => 'sector_size', 'sectors' => 'sectors', 'hpa' => 'hpa',
        'dco' => 'dco', 'sed_status' => 'sed_status',
        'reallocsectorspost' => 'reallocsectorspost', 'selftest' => 'selftest',
        'start_time' => 'start_time', 'end_time' => 'end_time',
        'duration_secs' => 'duration_secs', 'tool_version' => 'tool_version',
        'operator' => 'operator', 'validator' => 'validator',
        'media_source' => 'media_source', 'media_destination' => 'media_destination',
        'standard' => 'standard', 'passes' => 'passes', 'erase_engine' => 'erase_engine',
        'verify_technique' => 'verify_technique',
        'verify_coverage_pct' => 'verify_coverage_pct',
        'sanitization_standard' => 'sanitization_standard',
        'sanitization_level' => 'sanitization_level',
        'sanitization_shortfall' => 'sanitization_shortfall',
    ];
}

function certificate_drives_insert_sql(): string {
    $cols = array_keys(certificate_drives_column_map());
    return 'INSERT INTO certificate_drives (certificate_id, ' . implode(', ', $cols) . ')'
        . ' VALUES (' . rtrim(str_repeat('?,', count($cols) + 1), ',') . ')';
}

function rewrite_certificate_details(int $dbCertId, array $reports, array $drives): void {
    db()->prepare('DELETE FROM certificate_reports WHERE certificate_id = ?')->execute([$dbCertId]);
    // Manually recorded media (source = 'manual') are NOT part of a report rewrite:
    // they were entered by an operator, not derived from an appliance run, so a
    // re-issue must leave them exactly as entered. certificate_devices is untouched
    // here for the same reason.
    db()->prepare("DELETE FROM certificate_drives WHERE certificate_id = ? AND source <> 'manual'")->execute([$dbCertId]);
    certificate_drives_ensure_schema();

    $rq = db()->prepare('INSERT INTO certificate_reports (certificate_id, report_name, sha256, state) VALUES (?, ?, ?, ?)');
    foreach ($reports as $r) {
        $rq->execute([$dbCertId, (string)$r['name'], (string)$r['sha'], (string)$r['state']]);
    }

    $map = certificate_drives_column_map();
    $dq = db()->prepare(certificate_drives_insert_sql());
    foreach ($drives as $d) {
        $vals = [$dbCertId];
        foreach ($map as $groupKey) {
            $vals[] = (string)($d[$groupKey] ?? '');
        }
        $dq->execute($vals);
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
        'firmware' => (string)($d['firmware'] ?? ''),
        'sector_size' => (string)($d['sector_size'] ?? ''),
        'sectors' => (string)($d['sectors'] ?? ''),
        'hpa' => (string)($d['hpa'] ?? ''),
        'dco' => (string)($d['dco'] ?? ''),
        'hpa_result' => (string)($d['hpa_result'] ?? ''),
        'dco_result' => (string)($d['dco_result'] ?? ''),
        'sed_status' => (string)($d['sed_status'] ?? ''),
        'reallocsectorspost' => (string)($d['reallocsectorspost'] ?? ''),
        'selftest' => (string)($d['selftest'] ?? ''),
        'start_time' => (string)($d['start_time'] ?? ''),
        'end_time' => (string)($d['end_time'] ?? ''),
        'duration_secs' => (string)($d['duration_secs'] ?? ''),
        'sku' => (string)($d['sku'] ?? ''),
        'asset_tag' => (string)($d['asset_tag'] ?? ''),
        'bios_vendor' => (string)($d['bios_vendor'] ?? ''),
        'board' => (string)($d['board'] ?? ''),
        'tpm' => (string)($d['tpm'] ?? ''),
        'macs' => (string)($d['macs'] ?? ''),
        'storage_controllers' => (string)($d['storage_controllers'] ?? ''),
        'tool_version' => (string)($d['tool_version'] ?? ''),
        'operator' => (string)($d['operator'] ?? ''),
        'validator' => (string)($d['validator'] ?? ''),
        'media_source' => (string)($d['media_source'] ?? ''),
        'media_destination' => (string)($d['media_destination'] ?? ''),
        'cpu' => (string)($d['cpu'] ?? ''),
        'gpu' => (string)($d['gpu'] ?? ''),
        'ram' => (string)($d['ram'] ?? ''),
        'enrollment' => (string)($d['enrollment'] ?? ''),
        'verify_result' => (string)($d['verify_result'] ?? ''),
        'verify_sectors' => (string)($d['verify_sectors'] ?? ''),
        'format' => (string)($d['format'] ?? ''),
        'format_fs' => (string)($d['format_fs'] ?? ''),
        'format_scheme' => (string)($d['format_scheme'] ?? ''),
        'format_label' => (string)($d['format_label'] ?? ''),
        'format_result' => (string)($d['format_result'] ?? ''),
        'format_confirm' => (string)($d['format_confirm'] ?? ''),
        'freeze_lock' => (string)($d['freeze_lock'] ?? ''),
        'block_sid' => (string)($d['block_sid'] ?? ''),
        'flr_attempts' => (string)($d['flr_attempts'] ?? ''),
        'psid_revert' => (string)($d['psid_revert'] ?? ''),
        'lock_kind' => (string)($d['lock_kind'] ?? ''),
        'ppi_requested' => (string)($d['ppi_requested'] ?? ''),
        'ppi_cleared'   => (string)($d['ppi_cleared'] ?? ''),
        'standard'      => (string)($d['standard'] ?? ''),
        'passes'        => (string)($d['passes'] ?? ''),
        'erase_engine'  => (string)($d['erase_engine'] ?? ''),
        'verify_technique' => (string)($d['verify_technique'] ?? ''),
        'verify_coverage_pct' => (string)($d['verify_coverage_pct'] ?? ''),
        'sanitization_standard' => (string)($d['sanitization_standard'] ?? ''),
        'sanitization_level' => (string)($d['sanitization_level'] ?? ''),
        'sanitization_shortfall' => (string)($d['sanitization_shortfall'] ?? ''),
    ];
}

/**
 * Rank a drive's final status so that, when the same physical drive (serial)
 * appears in multiple reports, the *best* outcome wins. A drive blocked or
 * failed on one machine and then successfully erased on another must resolve
 * to COMPLETED, not the earlier failure.
 *
 * Tiers: COMPLETED > operator-confirmed DESTROYED / RETAINED (a factory reset
 * leaves the device in service, so it ranks with a destruction: neither is a
 * sanitisation, and neither is a failure) > DRY-RUN > any not-sanitised
 * outcome. Within the not-sanitised outcomes the rank is an ESCALATION order,
 * so the label shown never depends on upload order: FROZEN (destruction
 * required) > STALLED (drive wedged mid-operation, needs a power cycle) >
 * BLOCKED (firmware blocked) > FAILED (erase failed) > UNKNOWN (no terminal
 * status).
 */
function drive_status_rank(?string $status): int {
    $s = strtoupper(trim((string)$status));
    if ($s === 'COMPLETED') return 100;
    if ($s === 'DESTROYED') return 50;
    if ($s === 'RETAINED')  return 50;
    if ($s === 'DRY-RUN')   return 10;
    if ($s === 'FROZEN')    return 6;
    if ($s === 'STALLED')   return 5;
    if ($s === 'BLOCKED')   return 4;
    if ($s === 'FAILED')    return 3;
    if ($s === 'UNKNOWN')   return 2;
    return 0;
}

/**
 * Derive whether a drive's Block SID gate was cleared by the TCG PPI request.
 * The appliance can no longer claim this itself (it has no cross-reboot
 * memory), so the server infers it from the drive's report history: the drive
 * was BLOCKED (status BLOCKED, or block_sid=yes) on an earlier pass and is
 * COMPLETED now. Returns 'yes' | 'no'.
 */
function drive_ppi_cleared(array $dv): string {
    if (strtoupper(trim((string)($dv['status'] ?? ''))) !== 'COMPLETED') {
        return 'no';
    }
    foreach (($dv['history'] ?? []) as $h) {
        if (!is_array($h)) continue;
        if (strtoupper(trim((string)($h['status'] ?? ''))) === 'BLOCKED'
            || strtolower(trim((string)($h['block_sid'] ?? ''))) === 'yes') {
            return 'yes';
        }
    }
    return 'no';
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
    $everBlocked = [];
    foreach ($dbDrives as $d) {
        $gd = db_drive_to_group($d);
        $s = strtolower(trim((string)($gd['serial'] ?? '')));
        if ($s !== '' && (strtoupper(trim((string)($gd['status'] ?? ''))) === 'BLOCKED'
                || strtolower(trim((string)($gd['block_sid'] ?? ''))) === 'yes')) {
            $everBlocked[$s] = true;
        }
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
        if ($s !== '' && (strtoupper(trim((string)($nd['status'] ?? ''))) === 'BLOCKED'
                || strtolower(trim((string)($nd['block_sid'] ?? ''))) === 'yes')) {
            $everBlocked[$s] = true;
        }
        if ($s !== '' && isset($bySerial[$s])) {
            if (drive_status_rank($nd['status'] ?? '') >= drive_status_rank($drives[$bySerial[$s]]['status'] ?? '')) {
                $drives[$bySerial[$s]] = $nd;
            }
            continue;
        }
        if ($s !== '') $bySerial[$s] = count($drives);
        $drives[] = $nd;
    }

    // PPI cleared: a drive that was Block-SID-blocked on an earlier pass
    // (status BLOCKED, or block_sid=yes) and is now COMPLETED was cleared by the
    // PPI request + reboot. The appliance can no longer claim this itself (it
    // has no cross-reboot memory), so the consolidation derives it here.
    foreach ($drives as $i => $dv) {
        $s = strtolower(trim((string)($dv['serial'] ?? '')));
        $drives[$i]['ppi_cleared'] = (strtoupper(trim((string)($dv['status'] ?? ''))) === 'COMPLETED'
            && $s !== '' && !empty($everBlocked[$s])) ? 'yes' : 'no';
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
        // Carry the machine profile through so the certificate summary survives
        // a multi-payload merge (merge_group's output replaces $g in POST /api/certs).
        'system' => (string)($g['system'] ?? ''),
        'sysserial' => (string)($g['sysserial'] ?? ''),
        'bbserial' => (string)($g['bbserial'] ?? ''),
        'sku' => (string)($g['sku'] ?? ''),
        'asset_tag' => (string)($g['asset_tag'] ?? ''),
        'bios_vendor' => (string)($g['bios_vendor'] ?? ''),
        'board' => (string)($g['board'] ?? ''),
        'tpm' => (string)($g['tpm'] ?? ''),
        'macs' => (string)($g['macs'] ?? ''),
        'storage_controllers' => (string)($g['storage_controllers'] ?? ''),
        'tool_version' => (string)($g['tool_version'] ?? ''),
        'operator' => (string)($g['operator'] ?? ''),
        'validator' => (string)($g['validator'] ?? ''),
        'media_source' => (string)($g['media_source'] ?? ''),
        'media_destination' => (string)($g['media_destination'] ?? ''),
        'cpu' => (string)($g['cpu'] ?? ''),
        'gpu' => (string)($g['gpu'] ?? ''),
        'ram' => (string)($g['ram'] ?? ''),
        'enrollment' => (string)($g['enrollment'] ?? ''),
        'verify' => (string)($g['verify'] ?? ''),
        'format' => (string)($g['format'] ?? ''),
        'chassisserial' => (string)($g['chassisserial'] ?? ''),
        'chassistype' => (string)($g['chassistype'] ?? ''),
        'biosversion' => (string)($g['biosversion'] ?? ''),
        'biosdate' => (string)($g['biosdate'] ?? ''),
        'systemuuid' => (string)($g['systemuuid'] ?? ''),
        'bioslock' => (string)($g['bioslock'] ?? ''),
        'bioslockmethod' => (string)($g['bioslockmethod'] ?? ''),
    ];
}

/**
 * Collect every report-file SHA-256 this user has already ingested, decoded
 * from the JSON payloads of their `reports` rows. Used to skip duplicates when
 * reports are re-uploaded (e.g. an entire fleet uploaded again from USBs).
 */
function user_ingested_shas(int $userId): array {
    $set = [];
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT payload FROM reports WHERE user_id IN ($ph)");
    $stmt->execute($ids);
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
        'INSERT INTO reports (user_id, cocid, filename, sha_state, sig_state, source, report_type, devices, runs, payload, device_key, summary_json, drive_serials)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($groups as $g) {
        $names = array_map(fn($r) => (string)($r['name'] ?? ''), $g['reports'] ?? []);
        $filename = implode(', ', array_slice($names, 0, 5));
        $den = reports_denormalize($g, $reportType === 'diagnostics');
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
            $den['device_key'],
            $den['summary'] === null ? null : json_encode($den['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($den['drive_serials'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $ids[] = (int)db()->lastInsertId();
    }
    return $ids;
}

/** Derive the 13-digit OA3 ProductKeyId (Autopilot hash type 24) from a
 *  25-char Windows 8/10/11 (PKey2009) product key.
 *
 *  PKey2009 keys contain exactly one 'N' — a positional marker, not a base-24
 *  char. Its 0-based position is the first base-24 digit; the other 24 chars
 *  decode with 'BCDFGHJKMPQRTVWXY2346789'. Fields: Group = bits 0-19
 *  (RefGroupID), Serial = bits 20-49. ProductKeyId = group + 9-digit serial.
 *
 *  Verified against 3 real MSDM keys and their oa3tool.exe captures:
 *    NGHXG-H9FV3-9MX36-PB823-T6PKC -> 3305168038923
 *    JMM6B-TTNC3-WJJ9K-FQPDJ-PKCKC -> 3305031854723
 *    NCM8M-K26D6-J9XW9-2RTFM-K766P -> 3305012776346
 */
function product_key_id_from_key(string $key): string {
    $key = strtoupper(str_replace('-', '', trim($key)));
    if (strlen($key) !== 25 || strpos($key, 'N') === false) {
        return '';
    }
    $alpha = 'BCDFGHJKMPQRTVWXY2346789';
    $digits = [strpos($key, 'N')];
    for ($i = 0; $i < 25; $i++) {
        $c = $key[$i];
        if ($c === 'N') continue;
        $p = strpos($alpha, $c);
        if ($p === false) return '';
        $digits[] = $p;
    }
    if (count($digits) !== 25) return '';
    // Big integer in 24-bit limbs (little-endian): num = num * 24 + digit.
    $limbs = [0];
    foreach ($digits as $d) {
        $carry = $d;
        $n = count($limbs);
        for ($i = 0; $i < $n; $i++) {
            $v = $limbs[$i] * 24 + $carry;
            $limbs[$i] = $v & 0xFFFFFF;
            $carry = $v >> 24;
        }
        while ($carry > 0) {
            $limbs[] = $carry & 0xFFFFFF;
            $carry >>= 24;
        }
    }
    $l0 = $limbs[0];
    $l1 = $limbs[1] ?? 0;
    $l2 = $limbs[2] ?? 0;
    $group  = $l0 & 0xFFFFF;                                   // bits 0-19
    $serial = ((($l0 >> 20) & 0xF) | ($l1 << 4) | ($l2 << 28)) & 0x3FFFFFFF; // bits 20-49
    return (string)$group . str_pad((string)$serial, 9, '0', STR_PAD_LEFT);
}

/**
 * Seed a freshly stored diagnostics report with the previous report's manual
 * annotations (refurb grade + operator notes) for the same machine, keyed by
 * device_key(). Copy-forward only — each report still owns its own values, so
 * editing or clearing them on one report never mutates another and every PDF
 * stays a self-contained snapshot. Non-fatal: a lookup hiccup must never block
 * report storage.
 */
function diagnostics_carry_annotations(int $reportId, int $userId, array $payload): void {
    try {
        $key = device_key($payload);
        if ($key === '') return;
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Walk recent diagnostics reports newest-first (excluding the one just
        // inserted); the previous report for this machine is the first whose
        // device_key matches. A bounded window keeps this cheap — carry-forward
        // is a convenience, not a guarantee, so a machine absent from the last
        // 200 reports simply starts blank again.
        $stmt = db()->prepare(
            "SELECT grade, notes, payload FROM reports
             WHERE id <> ? AND user_id IN ($ph) AND report_type = 'diagnostics'
             ORDER BY uploaded_at DESC, id DESC LIMIT 200"
        );
        $stmt->execute(array_merge([$reportId], $ids));
        $grade = '';
        $notes = '';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $g = json_decode((string)$r['payload'], true);
            if (!is_array($g)) continue;
            if (device_key($g) === $key) {
                $grade = (string)($r['grade'] ?? '');
                $notes = (string)($r['notes'] ?? '');
                break;
            }
        }
        if ($grade === '' && $notes === '') return;
        db()->prepare('UPDATE reports SET grade = ?, notes = ? WHERE id = ?')
            ->execute([$grade, $notes, $reportId]);
    } catch (Throwable $e) {
        error_log('diagnostics carry annotations error: ' . $e->getMessage());
    }
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
            'hpa'         => (string)($dv['hpa'] ?? ''),
            'dco'         => (string)($dv['dco'] ?? ''),
            'smart'       => (string)($dv['smart'] ?? ''),
            'selftest'    => (string)($dv['selftest'] ?? ''),
            'realloc'     => (string)($dv['realloc'] ?? ''),
            'temp'        => (string)($dv['temp'] ?? ''),
            'poh'         => (string)($dv['poh'] ?? ''),
            'cycles'      => (string)($dv['cycles'] ?? ''),
            'pct_used'    => (string)($dv['pct_used'] ?? ''),
            'spare'       => (string)($dv['spare'] ?? ''),
            'tbw'         => (string)($dv['tbw'] ?? ''),
            'selftest_run' => (string)($dv['selftest_run'] ?? ''),
            'opal_locked'=> isset($dv['opal_locked']) ? (bool)$dv['opal_locked'] : false,
        ];
    }

    $payload = [
        'report_type'    => 'diagnostics',
        'system'         => $system,
        'manufacturer'   => (string)($d['manufacturer'] ?? ''),
        'product'        => (string)($d['product'] ?? ''),
        'sysserial'      => $serial,
        'systemuuid'     => $uuid,
        'family'         => (string)($d['family'] ?? ''),
        'board_product'  => (string)($d['board_product'] ?? ''),
        'board_version'  => (string)($d['board_version'] ?? ''),
        'system_version' => (string)($d['system_version'] ?? ''),
        'tpm_ekpub'      => (string)($d['tpm_ekpub'] ?? ''),
        'tpm_getcap'     => (string)($d['tpm_getcap'] ?? ''),
        'tpm_caps'       => (string)($d['tpm_caps'] ?? ''),
        'product_key'    => (string)($d['product_key'] ?? ''),
        'product_key_id' => product_key_id_from_key((string)($d['product_key'] ?? '')),
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
        // UEFI-variable evidence sent by the appliance (v1.11.49+): a one-line
        // fingerprint summary plus the raw firmware state variables. NOTE this
        // list is an explicit whitelist, so ANY new appliance field must be
        // added here or it is dropped on ingest without an error — which is
        // exactly what happened to these two on the first v1.11.49 reports.
        'efivars'        => (string)($d['efivars'] ?? ''),
        'efivars_b64'    => (string)($d['efivars_b64'] ?? ''),
        'dimms'          => (string)($d['dimms'] ?? ''),
        'cpu_spec'       => (string)($d['cpu_spec'] ?? ''),
        'display'        => (string)($d['display'] ?? ''),
        'wifi'           => (string)($d['wifi'] ?? ''),
        'selftest_cpu'   => (string)($d['selftest_cpu'] ?? ''),
        'diagnostics'    => is_array($d['diagnostics'] ?? null) ? $d['diagnostics'] : [],
        'diagnostics_summary' => (string)($d['diagnostics_summary'] ?? ''),
        'usb_devices'    => (string)($d['usb_devices'] ?? ''),
        'pci_devices'    => (string)($d['pci_devices'] ?? ''),
        'smbios'         => (string)($d['smbios'] ?? ''),
        'interfaces'     => (string)($d['interfaces'] ?? ''),
        'uefi_boot_entries' => (string)($d['uefi_boot_entries'] ?? ''),
        'peripherals'    => (string)($d['peripherals'] ?? ''),
        'bios_lockdown'  => isset($d['bios_lockdown']) ? (int)$d['bios_lockdown'] : 0,
        'cpu_socket'     => (string)($d['cpu_socket'] ?? ''),
        'cpu_family'     => (string)($d['cpu_family'] ?? ''),
        'cpu_id'         => (string)($d['cpu_id'] ?? ''),
        'cpu_voltage'    => (string)($d['cpu_voltage'] ?? ''),
        'bios_revision'  => (string)($d['bios_revision'] ?? ''),
        'bios_firmware_revision' => (string)($d['bios_firmware_revision'] ?? ''),
        'chassis_lock'   => (string)($d['chassis_lock'] ?? ''),
        'chassis_state'  => (string)($d['chassis_state'] ?? ''),
        'onboard_devices' => (string)($d['onboard_devices'] ?? ''),
        'oem_strings'    => (string)($d['oem_strings'] ?? ''),
        'battery_model'  => (string)($d['battery_model'] ?? ''),
        'battery_chemistry' => (string)($d['battery_chemistry'] ?? ''),
        'first'          => $now,
        'last'           => $now,
        'reports'        => [['name' => 'diagnostics', 'sha' => '']],
        'drives'         => $drives,
    ];

    try {
        $den = reports_denormalize($payload, true);
        db()->prepare(
            'INSERT INTO reports (user_id, cocid, filename, sha_state, sig_state, source, report_type, devices, runs, payload, device_key, summary_json)
             VALUES (?, ?, ?, "unverified", "none", "api", "diagnostics", ?, 1, ?, ?, ?)'
        )->execute([
            $userId,
            // A diagnostics snapshot has no Chain of Custody of its own: it
            // belongs to the run the appliance is in, so it takes whatever CoC
            // the appliance sent. This used to be a hard-coded empty string,
            // which left every diagnostics report — and therefore every row in
            // the dashboard's device Log for one — with no CoC at all.
            (string)($d['cocid'] ?? ''),
            'diagnostics',
            count($drives),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $den['device_key'],
            json_encode($den['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $reportId = (int)db()->lastInsertId();

        // Carry the previous report's operator grade + notes forward so a new
        // snapshot doesn't arrive blank — still fully editable per report.
        diagnostics_carry_annotations($reportId, $userId, $payload);

        return $reportId;
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
        'verify'      => is_array($payload) ? (string)($payload['verify'] ?? '') : '',
        'chassisserial' => is_array($payload) ? (string)($payload['chassisserial'] ?? '') : '',
        'chassistype'   => is_array($payload) ? (string)($payload['chassistype'] ?? '') : '',
        'biosversion'   => is_array($payload) ? (string)($payload['biosversion'] ?? '') : '',
        'biosdate'      => is_array($payload) ? (string)($payload['biosdate'] ?? '') : '',
        'systemuuid'    => is_array($payload) ? (string)($payload['systemuuid'] ?? '') : '',
        'report_id'     => is_array($payload) ? (string)($payload['report_id'] ?? '') : '',
        'digital_identifier' => is_array($payload) ? (string)($payload['digital_identifier'] ?? '') : '',
        'selftest_cpu'  => is_array($payload) ? (string)($payload['selftest_cpu'] ?? '') : '',
        'diagnostics'   => is_array($payload) ? ($payload['diagnostics'] ?? []) : [],
        'diagnostics_summary' => is_array($payload) ? (string)($payload['diagnostics_summary'] ?? '') : '',
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
        'cpu_spec'       => is_array($payload) ? (string)($payload['cpu_spec'] ?? '') : '',
        'display'        => is_array($payload) ? (string)($payload['display'] ?? '') : '',
        'wifi'           => is_array($payload) ? (string)($payload['wifi'] ?? '') : '',
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
    $ids = org_member_ids($userId);
    $where = 'user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND report_type <> 'diagnostics'";
    $params = $ids;
    $types = array_fill(0, count($ids), PDO::PARAM_INT);

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
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT r.cocid, COUNT(*) AS report_count, MAX(r.uploaded_at) AS latest,
                (SELECT c.cert_id FROM certificates c WHERE c.user_id = r.user_id AND c.cocid = r.cocid ORDER BY c.id DESC LIMIT 1) AS cert_id
         FROM reports r
         WHERE r.user_id IN (' . $ph . ') AND r.report_type <> \'diagnostics\'
         GROUP BY r.cocid
         ORDER BY latest DESC'
    );
    $stmt->execute($ids);
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
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT payload FROM reports WHERE user_id IN ($ph) AND cocid = ? AND report_type <> 'diagnostics' ORDER BY id");
    $stmt->execute(array_merge($ids, [$cocid]));
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
               lan_ip       VARCHAR(45)     NOT NULL DEFAULT "",
               phase        VARCHAR(16)     NOT NULL DEFAULT "",
               drives_total INT UNSIGNED    NOT NULL DEFAULT 0,
               drives_done  INT UNSIGNED    NOT NULL DEFAULT 0,
               drives_failed INT UNSIGNED   NOT NULL DEFAULT 0,
               progress_pct TINYINT         NOT NULL DEFAULT -1,
               progress_eta_sec INT         NOT NULL DEFAULT -1,
               failed_devices VARCHAR(255)  NOT NULL DEFAULT "",
               last_seen_ts INT UNSIGNED    NOT NULL,
               PRIMARY KEY (id),
               UNIQUE KEY uq_presence_device (user_id, serial, uuid),
               KEY idx_presence_seen (last_seen_ts)
            )'
        );
        // Idempotent migrations for tables created before these columns existed.
        $cols = [
            'lan_ip'        => 'ADD COLUMN lan_ip VARCHAR(45) NOT NULL DEFAULT "" AFTER uuid',
            'phase'         => 'ADD COLUMN phase VARCHAR(16) NOT NULL DEFAULT "" AFTER lan_ip',
            'drives_total'  => 'ADD COLUMN drives_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER phase',
            'drives_done'   => 'ADD COLUMN drives_done INT UNSIGNED NOT NULL DEFAULT 0 AFTER drives_total',
            'drives_failed' => 'ADD COLUMN drives_failed INT UNSIGNED NOT NULL DEFAULT 0 AFTER drives_done',
            'progress_pct'     => 'ADD COLUMN progress_pct TINYINT NOT NULL DEFAULT -1 AFTER drives_failed',
            'progress_eta_sec' => 'ADD COLUMN progress_eta_sec INT NOT NULL DEFAULT -1 AFTER progress_pct',
            'failed_devices'   => 'ADD COLUMN failed_devices VARCHAR(255) NOT NULL DEFAULT "" AFTER progress_eta_sec',
        ];
        foreach ($cols as $col => $ddl) {
            $stmt = db()->query("SHOW COLUMNS FROM device_presence LIKE '$col'");
            if ($stmt->fetch() === false) {
                db()->exec("ALTER TABLE device_presence $ddl");
            }
        }
    } catch (Throwable $e) {
        error_log('presence ensure schema error: ' . $e->getMessage());
    }
}

/** Record a heartbeat from a booted appliance (keyed by serial + uuid). */
function presence_heartbeat(int $userId, string $serial, string $uuid, string $lanIp = '', string $phase = '', int $drivesTotal = 0, int $drivesDone = 0, int $drivesFailed = 0, int $progressPct = -1, int $progressEtaSec = -1, bool $touchPhase = false, string $failedDevices = ''): void {
    try {
        if ($touchPhase) {
            db()->prepare(
                'INSERT INTO device_presence (user_id, serial, uuid, lan_ip, phase, drives_total, drives_done, drives_failed, progress_pct, progress_eta_sec, failed_devices, last_seen_ts)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   lan_ip = IF(VALUES(lan_ip) = \'\', lan_ip, VALUES(lan_ip)),
                   -- Once a wipe finishes (done/failed), ignore a stale in-flight
                   -- "wiping" heartbeat (which still reports drives already
                   -- finished, so drives_done + drives_failed > 0). But a NEW
                   -- erasure cycle starts with drives_done = 0 AND
                   -- drives_failed = 0, so let that legitimately reset the phase
                   -- back to "wiping" (repeatable erasure: Shift+T / remote wipe).
                   phase = IF((phase IN (\'done\',\'failed\')) AND VALUES(phase) = \'wiping\' AND (VALUES(drives_done) + VALUES(drives_failed)) > 0, phase, VALUES(phase)),
                   drives_total  = IF(VALUES(phase) = \'\', 0, VALUES(drives_total)),
                   drives_done   = IF(VALUES(phase) = \'\', 0, VALUES(drives_done)),
                   drives_failed = IF(VALUES(phase) = \'\', 0, VALUES(drives_failed)),
                   progress_pct     = IF(VALUES(phase) = \'wiping\', VALUES(progress_pct), -1),
                   progress_eta_sec = IF(VALUES(phase) = \'wiping\', VALUES(progress_eta_sec), -1),
                   failed_devices   = IF(VALUES(phase) = \'\', \'\', VALUES(failed_devices)),
                   last_seen_ts = UNIX_TIMESTAMP()'
            )->execute([$userId, $serial, $uuid, $lanIp, $phase, $drivesTotal, $drivesDone, $drivesFailed, $progressPct, $progressEtaSec, $failedDevices]);
        } else {
            db()->prepare(
                'INSERT INTO device_presence (user_id, serial, uuid, lan_ip, last_seen_ts)
                 VALUES (?, ?, ?, ?, UNIX_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   lan_ip = IF(VALUES(lan_ip) = \'\', lan_ip, VALUES(lan_ip)),
                   last_seen_ts = UNIX_TIMESTAMP()'
            )->execute([$userId, $serial, $uuid, $lanIp]);
        }
    } catch (Throwable $e) {
        error_log('presence heartbeat error: ' . $e->getMessage());
    }
}

/** Map a user's presence rows to ['serial' => ts, 'uuid' => ts, 'ip' => ip, 'erasure' => state] (lowercased). */
function presence_map(int $userId): array {
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];   // read twice per devices load
    $map = ['serial' => [], 'uuid' => [], 'ip' => [], 'erasure' => []];
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT serial, uuid, lan_ip, phase, drives_total, drives_done, drives_failed, progress_pct, progress_eta_sec, failed_devices, last_seen_ts FROM device_presence WHERE user_id IN ($ph)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = (int)$r['last_seen_ts'];
            $ip = trim((string)($r['lan_ip'] ?? ''));
            $erasure = [
                'phase'         => (string)($r['phase'] ?? ''),
                'drives_total'  => (int)($r['drives_total'] ?? 0),
                'drives_done'   => (int)($r['drives_done'] ?? 0),
                'drives_failed' => (int)($r['drives_failed'] ?? 0),
                'progress_pct'  => (int)($r['progress_pct'] ?? -1),
                'progress_eta_sec' => (int)($r['progress_eta_sec'] ?? -1),
                // WHICH drive failed and why, e.g. "S3Z8NB0K123 (sanitize stalled)".
                'failed_devices'   => (string)($r['failed_devices'] ?? ''),
            ];
            $s  = strtolower(trim((string)$r['serial']));
            $u  = strtolower(trim((string)$r['uuid']));
            if ($s !== '') { $map['serial'][$s] = $ts; if ($ip !== '') $map['ip'][$s] = $ip; $map['erasure'][$s] = $erasure; }
            if ($u !== '') { $map['uuid'][$u]  = $ts; if ($ip !== '') $map['ip'][$u] = $ip; $map['erasure'][$u] = $erasure; }
        }
    } catch (Throwable $e) {
        error_log('presence map error: ' . $e->getMessage());
    }
    $cache[$userId] = $map;
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

/** Latest heartbeat LAN IP for a device, or '' if none recorded. */
function presence_lan_ip(string $serial, string $uuid, array $map): string {
    $s = strtolower(trim($serial));
    $u = strtolower(trim($uuid));
    if ($s !== '' && isset($map['ip'][$s])) return (string)$map['ip'][$s];
    if ($u !== '' && isset($map['ip'][$u])) return (string)$map['ip'][$u];
    return '';
}

/** Erasure state (phase/total/done/failed) for a device, or null if none. */
function presence_erasure(string $serial, string $uuid, array $map): ?array {
    $s = strtolower(trim($serial));
    $u = strtolower(trim($uuid));
    if ($s !== '' && isset($map['erasure'][$s])) return $map['erasure'][$s];
    if ($u !== '' && isset($map['erasure'][$u])) return $map['erasure'][$u];
    return null;
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
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];   // read twice per devices load
    $out = [];
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT serial, uuid, payload, registered_at, last_seen_ts FROM device_registrations WHERE user_id IN ($ph) ORDER BY registered_at DESC, id DESC");
        $stmt->execute($ids);
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
    $cache[$userId] = $out;
    return $out;
}

/**
 * Cheap change fingerprint for the Devices tab. Returns a stable value that
 * only changes when the account's reports (including in-place grade/notes via
 * updated_at), registrations, MDM jobs, presence heartbeats or remote commands
 * change. The dashboard polls it every 10s and re-fetches the full /api/devices
 * aggregation only when it differs — so an idle page stops re-decoding every
 * report payload on each tick.
 */
function devices_list_version(int $userId): string {
    reports_ensure_schema();
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sig = [];

    $agg = static function (string $sql) use ($ph, $ids): array {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($ids);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r === false ? [] : array_map(static fn($v) => (string)$v, $r);
        } catch (Throwable $e) {
            return [];
        }
    };

    $sig['reports']       = $agg("SELECT MAX(id) AS max_id, COUNT(*) AS cnt, MAX(updated_at) AS max_upd FROM reports WHERE user_id IN ($ph)");
    $sig['registrations'] = $agg("SELECT MAX(id) AS max_id, COUNT(*) AS cnt FROM device_registrations WHERE user_id IN ($ph)");
    $sig['mdm_jobs']      = $agg("SELECT MAX(id) AS max_id, COUNT(*) AS cnt FROM mdm_jobs WHERE user_id IN ($ph)");
    $sig['presence']      = $agg("SELECT MAX(last_seen_ts) AS max_seen, COUNT(*) AS cnt FROM device_presence WHERE user_id IN ($ph)");
    $sig['commands']      = $agg("SELECT MAX(id) AS max_id, COUNT(*) AS cnt FROM device_commands WHERE user_id IN ($ph)");

    return sha1(json_encode($sig));
}

/**
 * Set (or clear, with grade='') the manual I-A–I-F refurb grade on a
 * diagnostics report. The grade lives on the report itself so each report row
 * carries its own grade; the Devices tab shows the latest report's grade.
 * Writes are org-scoped via ownership of the report.
 */
function report_grade_set(int $userId, int $reportId, string $grade): bool {
    reports_ensure_schema();
    if (!valid_device_grade($grade)) return false;
    $norm = strtoupper(trim($grade));
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Verify ownership first — rowCount() on an UPDATE is 0 when the value
        // is unchanged (re-picking the same grade), which must still succeed.
        $chk = db()->prepare(
            "SELECT COUNT(*) FROM reports WHERE id = ? AND report_type = 'diagnostics' AND user_id IN ($ph)"
        );
        $chk->execute(array_merge([$reportId], $ids));
        if ((int)$chk->fetchColumn() === 0) {
            return false;
        }
        $stmt = db()->prepare(
            "UPDATE reports SET grade = ? WHERE id = ? AND report_type = 'diagnostics' AND user_id IN ($ph)"
        );
        $stmt->execute(array_merge([$norm, $reportId], $ids));
        return true;
    } catch (Throwable $e) {
        error_log('report grade set error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Set (or clear, with notes='') the operator's free-form notes on a
 * diagnostics report — e.g. "damaged screen, missing battery". Mirrors
 * report_grade_set: org-scoped by report ownership, empty clears the notes,
 * and the text is trimmed and capped so a stray paste can't bloat a row.
 */
function report_notes_set(int $userId, int $reportId, string $notes): bool {
    reports_ensure_schema();
    $norm = trim($notes);
    // Cap at a generous 4000 chars (UTF-8) — long enough for any realistic
    // condition note, short enough to stay a single-row annotation.
    if (function_exists('mb_substr')) {
        $norm = mb_substr($norm, 0, 4000);
    } else {
        $norm = substr($norm, 0, 4000);
    }
    try {
        $ids = org_member_ids($userId);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        // Verify ownership first — UPDATE rowCount() is 0 when the value is
        // unchanged (re-saving identical notes), which must still succeed.
        $chk = db()->prepare(
            "SELECT COUNT(*) FROM reports WHERE id = ? AND report_type = 'diagnostics' AND user_id IN ($ph)"
        );
        $chk->execute(array_merge([$reportId], $ids));
        if ((int)$chk->fetchColumn() === 0) {
            return false;
        }
        $stmt = db()->prepare(
            "UPDATE reports SET notes = ? WHERE id = ? AND report_type = 'diagnostics' AND user_id IN ($ph)"
        );
        $stmt->execute(array_merge([$norm, $reportId], $ids));
        return true;
    } catch (Throwable $e) {
        error_log('report notes set error: ' . $e->getMessage());
        return false;
    }
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
/**
 * One attested medium, shaped as a Drives-tab row.
 *
 * Shared by the device annex and the standalone list so a destroyed drive reads
 * the same wherever it appears. `status`/`cert` are set here rather than derived:
 * an attested medium is DESTROYED by attestation, and the row must never imply it
 * was wiped (there is no scan data, so `grade` stays empty and `graded` false).
 */
function manual_drive_row(array $m, string $cocid = ''): array
{
    require_once __DIR__ . '/manual_destruction.php';

    // A factory-reset medium is retained, not destroyed: the operator still has the
    // device, so the row must not read DESTROYED.
    $method = trim((string)($m['destroy_method'] ?? ''));
    if ($method !== '') {
        $retained = md_method_disposition($method) === 'retained';
    } else {
        // A row written before the method fallback existed can carry a blank method beside
        // a 'FACTORY RESET' class. Reading the class keeps that row honest instead of
        // showing a reset device as destroyed.
        $retained = strtoupper(trim((string)($m['class'] ?? ''))) === 'FACTORY RESET';
    }

    $row = [
        '_key'      => 'attested:media:' . (int)$m['id'],
        '_attested' => true,
        'attested'  => true,
        'attested_id'   => (int)$m['id'],
        'attested_kind' => 'media',
        'attested_qualifier' => md_attested_qualifier(),
        'id'        => (int)$m['id'],
        'cocid'     => $cocid,
        'serial'    => (string)$m['serial'],
        'model'     => (string)$m['model'],
        'type'      => (string)$m['type'],
        'size'      => (string)$m['size'],
        'bus'       => (string)$m['bus'],
        'method'    => (string)$m['method'],
        'status'    => $retained ? 'RETAINED' : 'DESTROYED',
        'final_status' => $retained ? 'RETAINED' : 'DESTROYED',
        'cert'      => $retained ? 'FACTORY RESET' : 'DESTRUCTION',
        'certification' => 'NOT SANITISED',
        // The unit this medium came from, when one was recorded. A standalone
        // destroyed drive has no host and must not be given one.
        'system'    => (string)$m['system_name'],
        'sysserial' => (string)$m['system_serial'],
        'reason'    => (string)$m['reason'],
        'destroy_method'  => (string)$m['destroy_method'],
        'destroy_vendor'  => (string)$m['destroy_vendor'],
        'destroy_date'    => (string)$m['destroy_date'],
        'destroy_witness' => (string)$m['destroy_witness'],
        'evidence_ref'    => (string)$m['evidence_ref'],
        'attested_by'     => (string)($m['attested_by'] ?? ''),
        // No SMART was ever read, so no resale grade exists. Leaving the keys out
        // would let the table fall back to "ungraded", which reads as "not yet
        // checked" rather than "never read because it no longer exists".
        'grade'     => '',
        'grade_reason' => '',
        'graded'    => false,
        'reports'   => 1,
        'multiple'  => false,
        'report_id' => 0,
    ];
    $row['history'] = [$row];
    return $row;
}

/**
 * Per-request memo for manual_list_devices(), keyed by user.
 *
 * It lives in its own function so it can be *cleared*: load_devices() reads the list
 * twice, but a request that deletes an attested record and then lists devices must
 * not be served the pre-delete list. Every mutator clears it before it writes.
 */
function manual_list_cache(?array $set = null): array
{
    static $cache = [];
    if ($set !== null) { $cache = $set; }
    return $cache;
}

/**
 * Manually recorded (attested) destroyed units, shaped as Devices-tab rows so they
 * take part in the same search, sort and pagination as scanned machines.
 *
 * Every row is flagged `attested`. The page uses that to badge it and to hide the
 * scan-only controls: the appliance never saw these units, so Log/Ops/Rep have
 * nothing to show. The qualifier travels with the row so no surface re-derives it.
 *
 * Cached per user for the request (see manual_list_cache).
 *
 * Plan: research/manual-destruction/README.md
 */
function manual_list_devices(int $userId): array
{
    $cache = manual_list_cache();
    if (array_key_exists($userId, $cache)) return $cache[$userId];

    require_once __DIR__ . '/manual_destruction.php';
    $out = [];
    try {
        $ids = org_member_ids($userId);
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $q = db()->prepare(
            "SELECT d.*, c.cert_id
               FROM certificate_devices d
               JOIN certificates c ON c.id = d.certificate_id
              WHERE c.user_id IN ($ph)
              ORDER BY d.id DESC"
        );
        $q->execute($ids);
        $devices = $q->fetchAll(PDO::FETCH_ASSOC);

        $media = [];
        if ($devices !== []) {
            $dids = array_map(static fn($d) => (int)$d['id'], $devices);
            $mph  = implode(',', array_fill(0, count($dids), '?'));
            // Scoped by certificate_device_id, so this cannot pick up another
            // account's media even though the ids are global.
            $mq = db()->prepare("SELECT * FROM certificate_drives WHERE source = 'manual' AND certificate_device_id IN ($mph) ORDER BY id");
            $mq->execute($dids);
            foreach ($mq->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $media[(int)$m['certificate_device_id']][] = $m;
            }
        }

        foreach ($devices as $d) {
            $rows   = $media[(int)$d['id']] ?? [];
            $drives = [];
            foreach ($rows as $m) { $drives[] = manual_drive_row($m, (string)$d['cocid']); }
            $seen = ts_local((string)$d['created_at']);

            $out[] = [
                // Namespaced so it can never collide with a device_key (which is a
                // lowercased serial).
                '_key'      => 'attested:device:' . (int)$d['id'],
                '_attested' => true,
                'attested'  => true,
                'attested_id'   => (int)$d['id'],
                'attested_kind' => 'device',
                'attested_qualifier' => md_attested_qualifier(),
                // The search filter tests `_cocids` for the COCID, and the Devices
                // search box advertises COCID search. Report-derived rows get this
                // from their reports; a manually recorded unit has no report, so
                // without it a destroyed phone could not be found by its job.
                '_cocids'   => (string)$d['cocid'],
                'cocid'     => (string)$d['cocid'],
                'cert_id'   => (string)$d['cert_id'],
                'system'    => md_device_line($d),
                'sysserial' => (string)$d['serial'],
                'bbserial'  => '',
                'systemuuid' => '',
                'category'  => (string)$d['category'],
                'make'      => (string)$d['make'],
                'model'     => (string)$d['model'],
                'reason'    => (string)$d['reason'],
                'destroy_method'  => (string)$d['destroy_method'],
                'destroy_vendor'  => (string)$d['destroy_vendor'],
                'destroy_date'    => (string)$d['destroy_date'],
                'destroy_witness' => (string)$d['destroy_witness'],
                'evidence_ref'    => (string)$d['evidence_ref'],
                'attested_by'     => (string)$d['attested_by'],
                // Scan-only fields stay empty rather than being faked: nothing
                // observed this unit, so it has no BIOS state, no MDM state and no
                // presence. `online` false is what stops it being mistaken for a
                // machine that is merely switched off.
                'bioslock'  => '',
                'bioslockmethod' => '',
                'mdm'       => '',
                'refurb_grade' => '',
                'notes'     => '',
                'online'    => false,
                'lan_ip'    => '',
                'erasure'   => null,
                'last_seen' => null,
                'last_seen_at' => null,
                'first'     => $seen,
                'last'      => $seen,
                'drive_count' => count($drives),
                'drives'    => $drives,
                'history'   => [[
                    'report_type' => 'attested',
                    'uploaded_at' => $seen,
                    'cocid'       => (string)$d['cocid'],
                    'drive_count' => count($drives),
                    'drives'      => $drives,
                ]],
            ];
        }
    } catch (Throwable $e) {
        // A schema that predates the attested tables (or a failed migration) must
        // not take the whole Devices page down with it.
        error_log('manual_list_devices: ' . $e->getMessage());
        $out = [];
    }

    $cache[$userId] = $out;
    manual_list_cache($cache);
    return $out;
}

/**
 * Manually recorded (attested) destroyed media, shaped as Drives-tab rows.
 *
 * Includes media that belonged to an attested unit as well as standalone destroyed
 * drives, so a serial search finds a destroyed drive wherever it was recorded. The
 * host, when there was one, is carried in `system`/`sysserial`.
 */
function manual_list_drives(int $userId): array
{
    require_once __DIR__ . '/manual_destruction.php';
    try {
        $ids = org_member_ids($userId);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $q = db()->prepare(
            "SELECT m.*, c.cert_id, c.cocid AS cert_cocid
               FROM certificate_drives m
               JOIN certificates c ON c.id = m.certificate_id
              WHERE c.user_id IN ($ph) AND m.source = 'manual'
              ORDER BY m.id DESC"
        );
        $q->execute($ids);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('manual_list_drives: ' . $e->getMessage());
        return [];
    }

    $out = [];
    foreach ($rows as $m) {
        // certificate_drives carries no COCID of its own — the job it belongs to is
        // on the certificate, which is also what proves ownership above.
        $row = manual_drive_row($m, (string)($m['cert_cocid'] ?? ''));
        $row['cert_id'] = (string)($m['cert_id'] ?? '');
        $out[] = $row;
    }
    return $out;
}

/**
 * Phase A of the Devices-tab pagination: one candidate per distinct machine,
 * built from the small `summary_json` (no full-payload decode). Returns
 * [device_key => summary + '_key'/'_latest_id'/'_cocids'/refurb_grade/notes/first/last].
 * Legacy boot-time registrations without a diagnostics report are folded in.
 */
function devices_candidates(array $ids, string $ph, int $userId): array {
    $devices = [];

    // Newest diagnostics report per device (MAX(id) wins).
    $stmt = db()->prepare("SELECT device_key, MAX(id) AS latest_id FROM reports WHERE user_id IN ($ph) AND report_type = 'diagnostics' AND device_key <> '' GROUP BY device_key");
    $stmt->execute($ids);
    $latest = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $latest[(string)$r['device_key']] = (int)$r['latest_id'];
    }

    if ($latest !== []) {
        $idPh = implode(',', array_fill(0, count($latest), '?'));
        // Don't pull the full `payload` blob here — summary_json covers the
        // list/search/sort fields. Payloads are fetched lazily below only for
        // the rare rows that predate summary_json (NULL cache).
        $stmt = db()->prepare("SELECT id, device_key, uploaded_at, summary_json, grade, notes FROM reports WHERE id IN ($idPh) ORDER BY uploaded_at DESC, id DESC");
        $stmt->execute(array_values($latest));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $missingIds = [];
        foreach ($rows as $r) {
            if (!is_array(json_decode((string)$r['summary_json'], true))) $missingIds[] = (int)$r['id'];
        }
        $payloads = [];
        if ($missingIds !== []) {
            $mp = implode(',', array_fill(0, count($missingIds), '?'));
            $ps = db()->prepare("SELECT id, payload FROM reports WHERE id IN ($mp)");
            $ps->execute($missingIds);
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $pr) $payloads[(int)$pr['id']] = (string)$pr['payload'];
        }

        foreach ($rows as $r) {
            $key = (string)$r['device_key'];
            $s = json_decode((string)$r['summary_json'], true);
            if (!is_array($s)) {
                $g = json_decode($payloads[(int)$r['id']] ?? '', true);
                $s = is_array($g) ? (reports_denormalize($g, true)['summary'] ?? []) : [];
            }
            if (!is_array($s)) $s = [];
            $devices[$key] = $s + [
                '_key'         => $key,
                '_latest_id'   => (int)$r['id'],
                '_cocids'      => '',
                'refurb_grade' => (string)($r['grade'] ?? ''),
                'notes'        => (string)($r['notes'] ?? ''),
                'first'        => ts_local((string)($r['uploaded_at'] ?? '')),
                'last'         => ts_local((string)($r['uploaded_at'] ?? '')),
            ];
        }

        // first/last across ALL reports (diagnostics + erasure) for the device.
        $stmt = db()->prepare("SELECT device_key, MIN(uploaded_at) AS first_up, MAX(uploaded_at) AS last_up FROM reports WHERE user_id IN ($ph) AND device_key <> '' GROUP BY device_key");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string)$r['device_key'];
            if (isset($devices[$key])) {
                $devices[$key]['first'] = ts_local((string)$r['first_up']);
                $devices[$key]['last']  = ts_local((string)$r['last_up']);
            }
        }

        // Distinct COCIDs across all reports (search by CoC surfaces the machine).
        $stmt = db()->prepare("SELECT device_key, GROUP_CONCAT(DISTINCT cocid SEPARATOR '\n') AS cocids FROM reports WHERE user_id IN ($ph) AND device_key <> '' AND cocid <> '' GROUP BY device_key");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = (string)$r['device_key'];
            if (isset($devices[$key])) $devices[$key]['_cocids'] = (string)$r['cocids'];
        }
    }

    // Legacy registrations for devices with no diagnostics report.
    foreach (load_registered_devices($userId) as $reg) {
        $p = $reg['payload'];
        $serial = strtolower(trim((string)($p['serial'] ?? $reg['serial'])));
        $uuid   = strtolower(trim((string)($p['uuid'] ?? $reg['uuid'])));
        $matched = false;
        foreach ($devices as $dv) {
            $mSerial = strtolower(trim((string)($dv['sysserial'] ?? '')));
            $mUuid   = strtolower(trim((string)($dv['systemuuid'] ?? '')));
            if (($serial !== '' && $serial === $mSerial) || ($uuid !== '' && $uuid === $mUuid)) { $matched = true; break; }
        }
        if ($matched) continue;

        $key = $serial !== '' ? $serial : $uuid;
        if ($key === '') continue;

        $seen = ts_local((string)$reg['registered_at']);
        $devices[$key] = [
            '_key'         => $key,
            '_latest_id'   => 0,
            '_cocids'      => '',
            'system'       => trim((string)($p['manufacturer'] ?? '') . ' ' . (string)($p['product'] ?? '')),
            'sysserial'    => (string)($p['serial'] ?? $reg['serial']),
            'bbserial'     => '',
            'chassisserial'=> (string)($p['chassis_serial'] ?? ''),
            'chassistype'  => (string)($p['chassis_type'] ?? ''),
            'systemuuid'   => (string)($p['uuid'] ?? $reg['uuid']),
            'sku'          => (string)($p['sku'] ?? ''),
            'asset_tag'    => (string)($p['asset_tag'] ?? ''),
            'bios_vendor'  => (string)($p['bios_vendor'] ?? ''),
            'biosversion'  => (string)($p['bios_version'] ?? ''),
            'biosdate'     => (string)($p['bios_date'] ?? ''),
            'board'        => (string)($p['board'] ?? ''),
            'bioslock'     => (string)($p['bios_lock'] ?? ''),
            'bioslockmethod' => (string)($p['bios_lock_method'] ?? ''),
            'cpu'          => (string)($p['cpu'] ?? ''),
            'cpu_spec'     => (string)($p['cpu_spec'] ?? ''),
            'gpu'          => (string)($p['gpu'] ?? ''),
            'ram'          => (string)($p['ram'] ?? ''),
            'display'      => (string)($p['display'] ?? ''),
            'wifi'         => (string)($p['wifi'] ?? ''),
            'tpm'          => (string)($p['tpm'] ?? ''),
            'macs'         => (string)($p['macs'] ?? ''),
            'storage_controllers' => (string)($p['storage_controllers'] ?? ''),
            'battery'      => (string)($p['battery'] ?? ''),
            'secure_boot'  => (string)($p['secure_boot'] ?? ''),
            'dimms'        => (string)($p['dimms'] ?? ''),
            'product_key'  => (string)($p['product_key'] ?? ''),
            'product_key_id' => product_key_id_from_key((string)($p['product_key'] ?? '')),
            'tool_version' => (string)($p['tool_version'] ?? ''),
            'operator'     => (string)($p['operator'] ?? ''),
            'validator'    => (string)($p['validator'] ?? ''),
            'media_source' => (string)($p['media_source'] ?? ''),
            'media_destination' => (string)($p['media_destination'] ?? ''),
            'drive_count'  => count(is_array($p['drives'] ?? null) ? $p['drives'] : []),
            'report_id'    => (string)($p['report_id'] ?? ''),
            'digital_identifier' => (string)($p['digital_identifier'] ?? ''),
            'selftest_cpu' => (string)($p['selftest_cpu'] ?? ''),
            'mdm'          => '',
            'refurb_grade' => '',
            'notes'        => '',
            'first'        => $seen,
            'last'         => $seen,
        ];
    }

    // Manually recorded (attested) destruction. Folded in here, not just appended to
    // the final page, so the units take part in the same search, sort and pagination
    // as scanned machines and are counted in `total`. Their `_key` is namespaced
    // ("attested:device:N") so it cannot collide with a device_key.
    foreach (manual_list_devices($userId) as $row) {
        $key = (string)$row['_key'];
        if ($key !== '' && !isset($devices[$key])) { $devices[$key] = $row; }
    }

    return $devices;
}

/**
 * Normalise a sort direction.
 *
 * Accepts what the HTTP layer has ('asc', 'desc', anything) AND what an internal caller
 * passes (±1), because the two used to disagree: api.php converted the query value to ±1
 * while load_devices() tested it against the literal string 'asc'. That comparison could
 * never be true, so EVERY column sorted descending however the header was clicked, and the
 * ▲ arrow the dashboard drew described a sort that was not being applied. One normaliser,
 * used by both, means a caller cannot get this wrong again.
 *
 * Anything unrecognised means descending, which is the long-standing default (newest or
 * highest first).
 */
function query_dir($value): int
{
    // ±1 from an internal caller ('> 0' so 0 falls through to descending, matching the
    // empty-string case below rather than being a third meaning for "nothing given").
    if (is_int($value)) { return $value > 0 ? 1 : -1; }
    $v = strtolower(trim((string)$value));
    return ($v === 'asc' || $v === '1') ? 1 : -1;
}

/**
 * The sort keys for one device row, most significant first: the chosen column's value, then
 * IDENTITY — and nothing else.
 *
 * Identity is the machine serial, falling back to the internal key. `_key` alone is not
 * enough: an attested unit's key is 'attested:device:123', a row id, which would order those
 * rows by insertion rather than by what they are.
 *
 * 🔴 NO `last_seen_at` HERE. It was the tie-break, and it is rewritten by every appliance
 * heartbeat — so a whole group tied on the chosen column silently reshuffled itself as the
 * heartbeats arrived. Status is the worst case, because a working fleet is normally all
 * online or all offline (four machines online on this account, all tied), and the order
 * inside that group WAS the heartbeat order: reproduced live, `PC1Q4QWH` moved from position
 * 4 to position 1 between two loads six seconds apart, with nobody touching the page.
 *
 * The tie-break must therefore be stable, and it must still be compared with the direction
 * applied (see the caller) so that ascending and descending remain genuinely different — the
 * bug that made clicking Status twice do nothing at all.
 */
function device_sort_keys(array $d, $columnValue): array
{
    $serial = strtolower((string)($d['sysserial'] ?? ''));
    return [
        $columnValue,
        $serial !== '' ? $serial : strtolower((string)($d['_key'] ?? '')),
    ];
}

/**
 * The submission time of the most recent report for each medium: what the Media table's
 * Submitted column shows, and what its date sort is keyed on.
 *
 * `uploaded_at` is already the NEWEST report's timestamp — both list paths read reports
 * newest-first and keep the first entry per serial — so this only exposes it under the name
 * the API and the table use.
 *
 * An attested row has no report at all, and says so with an empty string rather than
 * borrowing its destruction date: the column means "report submitted", and a factory reset
 * never had one. The table renders that as an em dash with a tooltip explaining why, and in
 * a date sort those rows group at the end rather than pretending to a date.
 */
function media_with_datetime(array $rows): array
{
    foreach ($rows as &$r) {
        $r['last_report_datetime_submitted'] = (string)($r['uploaded_at'] ?? '');
        unset($r);
    }
    return $rows;
}

/**
 * A capacity written as text ("4GB", "1000 GB", "500 GB", "") as a comparable number of
 * GB. A plain string compare would order "1000 GB" before "4GB" before "500 GB", which is
 * not a sort of anything. -1 for a size the report never recorded, so those group below the
 * real ones on an ascending sort instead of claiming to be the smallest.
 */
function media_size_gb(string $size): int
{
    $size = trim($size);
    if ($size === '') { return -1; }
    if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?/i', $size, $m)) { return -1; }
    $n = (float)$m[1];
    switch (strtoupper($m[2])) {
        case 'T': $n *= 1024; break;
        case 'M': $n /= 1024; break;
        case 'K': $n /= 1048576; break;
    }
    return (int)round($n);
}

/**
 * The sort value for one media row. Status uses drive_status_rank() so that COMPLETED sorts
 * above DESTROYED/RETAINED and FAILED above BLOCKED, rather than alphabetically; size is
 * numeric; the three text columns are case-insensitive.
 */
function media_sort_value(array $d, string $sort)
{
    switch ($sort) {
        case 'model':  return strtolower((string)($d['model'] ?? ''));
        case 'serial': return strtolower((string)($d['serial'] ?? ''));
        case 'size':   return media_size_gb((string)($d['size'] ?? ''));
        case 'type':   return strtolower((string)($d['type'] ?? ''));
        case 'status': return drive_status_rank((string)($d['status'] ?? ($d['final_status'] ?? '')));
        case 'method': return strtolower((string)($d['method'] ?? ''));
        case 'grade':  return strtolower((string)($d['grade'] ?? ''));
        case 'date':   return (string)($d['last_report_datetime_submitted'] ?? ($d['uploaded_at'] ?? ''));
    }
    return '';
}

/**
 * Normalise the record-source filter: which class of row a list should show.
 *
 *   all      both kinds (the default — nothing is hidden unless asked for)
 *   verified only what the appliance erased and reported on itself
 *   attested only what an operator recorded by hand, which is never instrument-verified
 *
 * The two are always kept apart on the certificate, on /verify and in the JSON-LD twin, so a
 * single switch that narrows either list to one of them is the same distinction in the UI.
 * Anything unrecognised means `all`, so a typo or a stale bookmark cannot hide rows.
 */
function list_source($value): string
{
    $v = strtolower(trim((string)$value));
    return ($v === 'verified' || $v === 'attested') ? $v : 'all';
}

/**
 * Sort media rows by a chosen column, then by identity — never by anything a heartbeat or
 * another writer can change. Same rule and same reason as device_sort_keys(): a volatile
 * tie-break reorders the table underneath the reader.
 *
 * Unlike the Devices table this also covers a date column, because for media the date IS a
 * real fact about the row (when its latest report arrived) rather than liveness.
 */
function media_sort_rows(array $rows, string $sort, int $dir): array
{
    usort($rows, function ($a, $b) use ($sort, $dir) {
        $ka = device_sort_keys(
            ['sysserial' => (string)($a['serial'] ?? ''), '_key' => (string)($a['_key'] ?? '')],
            media_sort_value($a, $sort)
        );
        $kb = device_sort_keys(
            ['sysserial' => (string)($b['serial'] ?? ''), '_key' => (string)($b['_key'] ?? '')],
            media_sort_value($b, $sort)
        );
        foreach ($ka as $i => $va) {
            $vb = $kb[$i];
            $cmp = (is_int($va) && is_int($vb)) ? ($va <=> $vb) : strcmp((string)$va, (string)$vb);
            if ($cmp !== 0) { return $dir * $cmp; }
        }
        return 0;
    });
    return $rows;
}

/**
 * Attested (operator-recorded) item counts per certificate id: units and media.
 *
 * Kept deliberately OUT of the stored verified figures — `certificates.devices`/`runs` count
 * instrument-verified evidence, and the PDF, /verify and the JSON-LD twin all state the two
 * classes separately and never sum them. But a surface that says "N devices" needs these too,
 * or it reports 0 for a job that certified 24 operator-attested units: that is exactly what
 * the certificates tab did.
 *
 * Batched because the list calls it for a whole page.
 */
function attested_counts(array $certIds): array
{
    $out = [];
    $certIds = array_values(array_unique(array_map('intval', $certIds)));
    if ($certIds === []) { return $out; }

    $ph = implode(',', array_fill(0, count($certIds), '?'));
    try {
        $q = db()->prepare("SELECT certificate_id, COUNT(*) AS n FROM certificate_devices WHERE certificate_id IN ($ph) GROUP BY certificate_id");
        $q->execute($certIds);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['certificate_id']]['devices'] = (int)$r['n'];
        }

        $q = db()->prepare("SELECT certificate_id, COUNT(*) AS n FROM certificate_drives WHERE certificate_id IN ($ph) AND source = 'manual' GROUP BY certificate_id");
        $q->execute($certIds);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['certificate_id']]['media'] = (int)$r['n'];
        }
    } catch (Throwable $e) {
        // An install predating the attested tables simply has no attested records.
        $out = [];
    }
    return $out;
}

/**
 * Fleet counters for the Devices tiles, over the CURRENT filtered list.
 *
 * Pure so it can be unit tested: the tiles are the first thing an operator
 * reads, and a wrong "erasing" or "attested" count is invisible in the rows
 * below it. An attested record never carries live telemetry, so it is counted
 * only as attested — never also as offline/online — otherwise a job of 24
 * attested units reads as 24 offline machines.
 *
 * @param array<int,array<string,mixed>> $list
 * @return array{total:int,online:int,offline:int,erasing:int,failed:int,attested:int}
 */
function devices_summary(array $list): array {
    $summary = ['total' => count($list), 'online' => 0, 'offline' => 0, 'erasing' => 0, 'failed' => 0, 'attested' => 0];
    foreach ($list as $d) {
        if (!empty($d['attested'])) { $summary['attested']++; continue; }
        if (!empty($d['online'])) {
            $summary['online']++;
            $phase = is_array($d['erasure'] ?? null) ? (string)($d['erasure']['phase'] ?? '') : '';
            if ($phase === 'wiping') { $summary['erasing']++; }
            if ($phase === 'failed') { $summary['failed']++; }
        } else {
            $summary['offline']++;
        }
    }
    return $summary;
}

/**
 * Advanced device filters (the Devices "Filters" modal).
 *
 * These MUST run on the server. The list is paged server-side, so filtering the
 * ten visible rows would narrow the wrong set and leave `total`, the fleet strip
 * and the pager describing the unfiltered account — the same class of bug the
 * source switch had. They are applied in load_devices() in exactly the same
 * place as the source switch: after the search, before the sort.
 *
 * Wire format is a flat list of `facet:value` tokens:
 *   ff=presence:offline,bios:locked,mdm:locked
 * Chosen over JSON because it survives a hand-edit, reads in a URL bar and needs
 * no decoder. Anything unrecognised is DROPPED rather than rejected: a stale
 * bookmark must show MORE records, never an error page.
 */
function device_facet_defs(): array {
    // facet => allowed wire values. Labels live in the dashboard, so wording
    // changes stay a client concern and cannot alter what the server accepts.
    return [
        'presence'   => ['online', 'offline', 'erasing', 'failed'],
        'bios'       => ['locked', 'unlocked', 'unknown'],
        'mdm'        => ['unchecked', 'queued', 'checking', 'locked', 'locked_this', 'unlocked', 'blocked', 'na'],
        'secureboot' => ['on', 'off', 'unknown'],
        'tpm'        => ['present', 'absent'],
        'media'      => ['any', 'none'],
        'notes'      => ['any', 'none'],
    ];
}

/** `facet:value,facet:value` -> [facet => [value => true]]. Whitelisted. */
function device_filters_parse($raw): array {
    $defs = device_facet_defs();
    $out = [];
    foreach (explode(',', (string)$raw) as $token) {
        $token = trim($token);
        if ($token === '' || strpos($token, ':') === false) { continue; }
        $parts = explode(':', $token, 2);
        $facet = strtolower(trim($parts[0]));
        $value = strtolower(trim($parts[1]));
        if (!isset($defs[$facet]) || !in_array($value, $defs[$facet], true)) { continue; }
        $out[$facet][$value] = true;
    }
    ksort($out);
    return $out;
}

/** Canonical wire form of a parsed filter set (sorted, so a URL is stable). */
function device_filters_encode(array $filters): string {
    $parts = [];
    ksort($filters);
    foreach ($filters as $facet => $values) {
        $vals = array_keys($values);
        sort($vals);
        foreach ($vals as $v) { $parts[] = $facet . ':' . $v; }
    }
    return implode(',', $parts);
}

/**
 * One row's value for a facet — the single source of truth for both matching and
 * the counts shown beside each option, so the two can never disagree.
 *
 * '' means "this facet does not apply to this row" (an attested unit has no
 * presence; a scanner-reported BIOS that never said whether it was locked).
 * An empty value matches nothing, which is deliberate: an unknown must not be
 * swept into "Unlocked".
 */
function device_facet_value(array $d, string $facet): string {
    switch ($facet) {
        case 'presence':
            if (!empty($d['attested'])) { return ''; }
            if (empty($d['online'])) { return 'offline'; }
            $phase = is_array($d['erasure'] ?? null) ? (string)($d['erasure']['phase'] ?? '') : '';
            if ($phase === 'failed') { return 'failed'; }
            if ($phase === 'wiping') { return 'erasing'; }
            return 'online';
        case 'bios':
            $v = strtolower(trim((string)($d['bioslock'] ?? '')));
            return in_array($v, ['locked', 'unlocked', 'unknown'], true) ? $v : '';
        case 'mdm':
            // Same precedence as the MDM sort rank in load_devices(), so "the
            // column says Locked" and "the filter finds Locked" cannot drift.
            $st = strtolower((string)($d['mdm_status'] ?? ''));
            $v  = strtolower((string)($d['mdm_verdict'] ?? ''));
            if ($st === 'checking') { return 'checking'; }
            if ($st === 'queued')   { return 'queued'; }
            if ($st === 'unchecked') { return 'unchecked'; }
            if ($v === 'locked_other') { return 'locked'; }
            if ($v === 'locked_this')  { return 'locked_this'; }
            if ($v === 'unlocked')     { return 'unlocked'; }
            if ($v === 'paid_only' || $v === 'insufficient_credits') { return 'blocked'; }
            if ($v === 'na' || $st === 'na') { return 'na'; }
            return '';
        case 'secureboot':
            $v = strtolower(trim((string)($d['secure_boot'] ?? '')));
            if ($v === '') { return ''; }
            if (strpos($v, 'off') !== false || strpos($v, 'disabled') !== false) { return 'off'; }
            if (strpos($v, 'on') !== false || strpos($v, 'enabled') !== false)   { return 'on'; }
            return 'unknown';
        case 'tpm':
            $v = strtolower(trim((string)($d['tpm'] ?? '')));
            if ($v === '' || $v === 'none' || $v === 'no' || strpos($v, 'not present') !== false) {
                return 'absent';
            }
            return 'present';
        case 'media':
            return ((int)($d['drive_count'] ?? 0) > 0) ? 'any' : 'none';
        case 'notes':
            return trim((string)($d['notes'] ?? '')) !== '' ? 'any' : 'none';
    }
    return '';
}

/** True when the row satisfies EVERY selected facet (a row must match one value
 *  within a facet, but all facets that are set). */
function device_filters_match(array $d, array $filters): bool {
    foreach ($filters as $facet => $values) {
        if (!isset($values[device_facet_value($d, $facet)])) { return false; }
    }
    return true;
}

/**
 * Counts per facet option for the modal's "Locked (12)" hints.
 *
 * Computed over the source+search set BEFORE the filters narrow it. If they were
 * counted after, selecting "Locked" would show 0 beside every other BIOS option
 * and the modal would look broken (the classic faceted-search trap). The honest
 * limitation: the numbers ignore the operator's other selections, so a
 * combination can yield fewer rows than any single count suggests — which is why
 * the Apply button shows the true filtered total it got back from the server.
 */
function device_facet_counts(array $list): array {
    $counts = [];
    foreach (array_keys(device_facet_defs()) as $facet) { $counts[$facet] = []; }
    foreach ($list as $d) {
        foreach (array_keys($counts) as $facet) {
            $v = device_facet_value($d, $facet);
            if ($v === '') { continue; }
            $counts[$facet][$v] = ($counts[$facet][$v] ?? 0) + 1;
        }
    }
    return $counts;
}

function load_devices(int $userId, ?array $query = null): array {
    reports_ensure_schema();
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // ---- Phase A (paginated only): pick the page's device keys from the small
    // summary cache, then restrict the full-payload loops below to those keys. ----
    $onlyKeys = null;
    $total = 0;
    $attestedTotal = 0;
    // Fleet-wide counts for the dashboard's summary strip. Computed from the
    // (optionally search-filtered) candidate list, because only that list has
    // presence folded in; the page slice below is far too small to count from.
    $summary = null;
    if ($query !== null) {
        $q = trim((string)($query['q'] ?? ''));
        $page = max(1, (int)($query['page'] ?? 1));
        $per = min(100, max(1, (int)($query['per'] ?? 10)));
        $sort = (string)($query['sort'] ?? 'status');
        $dir = query_dir($query['dir'] ?? '');
        $source = list_source($query['source'] ?? '');

        $cand = devices_candidates($ids, $ph, $userId);
        $presence = presence_map($userId);
        foreach ($cand as $key => &$c) {
            $lastSeen = presence_last_seen((string)($c['sysserial'] ?? ''), (string)($c['systemuuid'] ?? ''), $presence);
            $c['online']       = presence_is_online((string)($c['sysserial'] ?? ''), (string)($c['systemuuid'] ?? ''), $presence);
            $c['lan_ip']       = presence_lan_ip((string)($c['sysserial'] ?? ''), (string)($c['systemuuid'] ?? ''), $presence);
            $c['erasure']      = presence_erasure((string)($c['sysserial'] ?? ''), (string)($c['systemuuid'] ?? ''), $presence);
            $c['last_seen']    = $lastSeen !== null ? ts_rel($lastSeen) : null;
            $c['last_seen_at'] = $lastSeen !== null ? ts_local(gmdate('Y-m-d H:i:s', $lastSeen)) : null;
            unset($c);
        }
        if (function_exists('mdm_fold_devices')) $cand = mdm_fold_devices($userId, $cand);
        // remote_fold_devices is deliberately NOT applied here: remote command
        // state isn't used by the search filter or sort key, and it would re-run
        // a schema-ensure + stale-expiry write on every list load. It's applied
        // once to the final page below.

        $list = array_values($cand);
        if ($q !== '') {
            $needle = strtolower($q);
            $fields = ['system', 'sysserial', 'bbserial', 'chassisserial', 'systemuuid', 'lan_ip', 'bioslock', 'mdm', 'mdm_status', 'mdm_verdict', 'mdm_label', 'sku', 'asset_tag', 'cpu', 'cpu_spec', 'gpu', 'display', 'wifi', 'ram', 'tpm', 'macs', 'storage_controllers', 'battery', 'dimms', 'product_key', 'product_key_id',
                // Attested-only fields, so an operator can find a destroyed item by
                // how and why it was destroyed, or by who attested it. Report-derived
                // rows simply have none of these keys, so their search is unchanged.
                'make', 'model', 'category', 'reason', 'destroy_method', 'destroy_vendor', 'destroy_date', 'destroy_witness', 'attested_by', 'evidence_ref'];
            $list = array_values(array_filter($list, function ($d) use ($needle, $fields) {
                foreach ($fields as $f) {
                    if (strpos(strtolower((string)($d[$f] ?? '')), $needle) !== false) return true;
                }
                if (strpos(strtolower((string)($d['_cocids'] ?? '')), $needle) !== false) return true;
                return false;
            }));
        }

        // The source switch: 'all' shows both classes, 'verified' only what the appliance
        // erased, 'attested' only what an operator recorded. Applied before the sort and the
        // page slice, so `total`, `attested_total` and the fleet summary all describe what is
        // actually being shown rather than the whole account.
        if ($source !== 'all') {
            $wantAttested = ($source === 'attested');
            $list = array_values(array_filter(
                $list,
                static fn($d) => !empty($d['attested']) === $wantAttested
            ));
        }

        // Facet counts describe the source+search set the operator is looking at,
        // BEFORE the advanced filters narrow it — otherwise picking "Locked" would
        // report 0 beside every other BIOS option. See device_facet_counts().
        $facets = device_facet_counts($list);
        $ff = device_filters_parse($query['ff'] ?? '');
        if ($ff) {
            $list = array_values(array_filter(
                $list,
                static fn($d) => device_filters_match($d, $ff)
            ));
        }

        $mdmRank = function (array $d): int {
            $st = strtolower((string)($d['mdm_status'] ?? ''));
            $v = strtolower((string)($d['mdm_verdict'] ?? ''));
            $rep = strtolower((string)($d['mdm'] ?? ''));
            $REAL = ['locked_other', 'locked_this', 'unlocked', 'hash_invalid', 'ms_error', 'offline'];
            if ($st === 'checking') return 90;
            if ($st === 'queued') return 80;
            if ($st === 'unchecked') return 10;
            $verdict = in_array($v, $REAL, true) ? $v : (in_array($rep, $REAL, true) ? $rep : ($st === 'done' && $v === 'unknown' ? 'unknown' : ''));
            $r = ['locked_other' => 70, 'locked_this' => 70, 'ms_error' => 60, 'hash_invalid' => 50, 'offline' => 40, 'unlocked' => 30, 'unknown' => 20];
            return $r[$verdict] ?? 0;
        };
        $sortVal = function (array $d) use ($sort, $mdmRank) {
            switch ($sort) {
                case 'system': return strtolower((string)($d['system'] ?? $d['sysserial'] ?? ''));
                case 'serial': return strtolower((string)($d['sysserial'] ?? ''));
                case 'bios':   return strtolower((string)($d['bioslock'] ?? ''));
                case 'mdm':    return $mdmRank($d);
                case 'status': return !empty($d['online']) ? 1 : 0;
            }
            return '';
        };

        // The chosen column decides the order on its own; the tie-break is identity, which
        // no heartbeat can change. See device_sort_keys() for why last_seen_at is not in it.
        //
        // The tie-break is still multiplied by the direction, which is what keeps ascending
        // and descending genuinely different: Status has TWO distinct values and MDM a
        // handful, so on a fleet that is all-offline every row ties on the column, and if the
        // tie-break were direction-blind both directions would come back byte-identical —
        // clicking Status twice did nothing at all, which reads as a broken header.
        $keysOf = function (array $d) use ($sortVal): array {
            return device_sort_keys($d, $sortVal($d));
        };
        usort($list, function ($a, $b) use ($keysOf, $dir) {
            $ka = $keysOf($a);
            $kb = $keysOf($b);
            foreach ($ka as $i => $va) {
                $vb = $kb[$i];
                $cmp = (is_int($va) && is_int($vb)) ? ($va <=> $vb) : strcmp((string)$va, (string)$vb);
                if ($cmp !== 0) { return $dir * $cmp; }
            }
            return 0;
        });

        $total = count($list);
        // Attested rows in the *filtered* result, so the figure describes what the
        // search box is showing rather than the account-wide total.
        $attestedTotal = count(array_filter($list, static fn($d) => !empty($d['attested'])));
        $summary = devices_summary($list);
        $onlyKeys = [];
        foreach (array_slice($list, ($page - 1) * $per, $per) as $d) {
            $onlyKeys[] = $d['_key'];
        }
    }

    // An empty page slice produced "AND device_key IN ()", which MySQL rejects with a
    // syntax error (1064), so the dashboard's poll of this endpoint failed repeatedly.
    // Matching nothing is the correct meaning of an empty page.
    $keySql = '';
    if ($onlyKeys !== null) {
        $keySql = $onlyKeys === []
            ? ' AND 1 = 0'
            : ' AND device_key IN (' . implode(',', array_fill(0, count($onlyKeys), '?')) . ')';
    }
    $stmt = db()->prepare("SELECT id, uploaded_at, grade, notes, cocid, payload FROM reports WHERE user_id IN ($ph) AND report_type = 'diagnostics'" . $keySql . " ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute($onlyKeys !== null ? array_merge($ids, $onlyKeys) : $ids);

    $devices = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = json_decode((string)$r['payload'], true);
        if (!is_array($g)) continue;

        $key = device_key($g);
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
                'pdf_id'        => (int)$r['id'],
                'selftest_cpu'  => (string)($g['selftest_cpu'] ?? ''),
                'diagnostics'   => is_array($g['diagnostics'] ?? null) ? $g['diagnostics'] : [],
                'diagnostics_summary' => (string)($g['diagnostics_summary'] ?? ''),
                'chassisserial' => (string)($g['chassisserial'] ?? ''),
                'chassistype'   => (string)($g['chassistype'] ?? ''),
                'biosversion'   => (string)($g['biosversion'] ?? ''),
                'biosdate'      => (string)($g['biosdate'] ?? ''),
                'systemuuid'    => (string)($g['systemuuid'] ?? ''),
                'product_key'   => (string)($g['product_key'] ?? ''),
                'product_key_id'=> product_key_id_from_key((string)($g['product_key'] ?? '')),
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
                'refurb_grade'  => (string)($r['grade'] ?? ''),
                'notes'         => (string)($r['notes'] ?? ''),
                'secure_boot'   => (string)($g['secure_boot'] ?? ''),
                'dimms'         => (string)($g['dimms'] ?? ''),
                'cpu_spec'      => (string)($g['cpu_spec'] ?? ''),
                'display'       => (string)($g['display'] ?? ''),
                'wifi'          => (string)($g['wifi'] ?? ''),
                'family'        => (string)($g['family'] ?? ''),
                'system_version' => (string)($g['system_version'] ?? ''),
                'board_version' => (string)($g['board_version'] ?? ''),
                'cpu_socket'    => (string)($g['cpu_socket'] ?? ''),
                'cpu_family'    => (string)($g['cpu_family'] ?? ''),
                'cpu_id'        => (string)($g['cpu_id'] ?? ''),
                'cpu_voltage'   => (string)($g['cpu_voltage'] ?? ''),
                'bios_revision' => (string)($g['bios_revision'] ?? ''),
                'bios_firmware_revision' => (string)($g['bios_firmware_revision'] ?? ''),
                'chassis_lock'  => (string)($g['chassis_lock'] ?? ''),
                'chassis_state' => (string)($g['chassis_state'] ?? ''),
                'onboard_devices' => (string)($g['onboard_devices'] ?? ''),
                'oem_strings'   => (string)($g['oem_strings'] ?? ''),
                'battery_model' => (string)($g['battery_model'] ?? ''),
                'battery_chemistry' => (string)($g['battery_chemistry'] ?? ''),
                'usb_devices'   => (string)($g['usb_devices'] ?? ''),
                'pci_devices'   => (string)($g['pci_devices'] ?? ''),
                'smbios'        => (string)($g['smbios'] ?? ''),
                'interfaces'    => (string)($g['interfaces'] ?? ''),
                'uefi_boot_entries' => (string)($g['uefi_boot_entries'] ?? ''),
                'peripherals'   => (string)($g['peripherals'] ?? ''),
                'bios_lockdown' => isset($g['bios_lockdown']) ? (int)$g['bios_lockdown'] : 0,
                'mdm'           => (string)($g['enrollment'] ?? ''),
                'first'         => (string)$r['uploaded_at'],
                'last'          => (string)$r['uploaded_at'],
                'drive_count'   => count($drives),
                'drives'        => $drives,
                'history'       => [],
            ];
        }

        $d = &$devices[$key];
        // A device first registered before key capture existed can gain the key
        // on a later report; fill it forward so the dashboard can show it.
        if (($g['product_key'] ?? '') !== '' && $d['product_key'] === '') {
            $d['product_key'] = (string)$g['product_key'];
            $d['product_key_id'] = product_key_id_from_key((string)$g['product_key']);
        }
        $d['history'][] = [
            'report_type'    => 'diagnostics',
            'uploaded_at'    => $seen,
            'first'          => $seen,
            'last'           => $seen,
            'system'         => (string)($g['system'] ?? ''),
            'sysserial'      => (string)($g['sysserial'] ?? $g['sysSerial'] ?? ''),
            'bbserial'       => (string)($g['bbserial'] ?? $g['bbSerial'] ?? ''),
            'report_id'      => (string)($g['report_id'] ?? ''),
            'digital_identifier' => (string)($g['digital_identifier'] ?? ''),
            'pdf_id'         => (int)$r['id'],
            'grade'          => (string)($r['grade'] ?? ''),
            'notes'          => (string)($r['notes'] ?? ''),
            'cocid'          => (string)($r['cocid'] ?? ''),
            'selftest_cpu'   => (string)($g['selftest_cpu'] ?? ''),
            'diagnostics'    => is_array($g['diagnostics'] ?? null) ? $g['diagnostics'] : [],
            'diagnostics_summary' => (string)($g['diagnostics_summary'] ?? ''),
            'chassisserial'  => (string)($g['chassisserial'] ?? ''),
            'chassistype'    => (string)($g['chassistype'] ?? ''),
            'biosversion'    => (string)($g['biosversion'] ?? ''),
            'biosdate'       => (string)($g['biosdate'] ?? ''),
            'systemuuid'     => (string)($g['systemuuid'] ?? ''),
            'product_key'    => (string)($g['product_key'] ?? ''),
            'product_key_id' => product_key_id_from_key((string)($g['product_key'] ?? '')),
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
            'cpu_spec'       => (string)($g['cpu_spec'] ?? ''),
            'display'        => (string)($g['display'] ?? ''),
            'wifi'           => (string)($g['wifi'] ?? ''),
            'family'         => (string)($g['family'] ?? ''),
            'system_version' => (string)($g['system_version'] ?? ''),
            'board_version'  => (string)($g['board_version'] ?? ''),
            'cpu_socket'     => (string)($g['cpu_socket'] ?? ''),
            'cpu_family'     => (string)($g['cpu_family'] ?? ''),
            'cpu_id'         => (string)($g['cpu_id'] ?? ''),
            'cpu_voltage'    => (string)($g['cpu_voltage'] ?? ''),
            'bios_revision'  => (string)($g['bios_revision'] ?? ''),
            'bios_firmware_revision' => (string)($g['bios_firmware_revision'] ?? ''),
            'chassis_lock'   => (string)($g['chassis_lock'] ?? ''),
            'chassis_state'  => (string)($g['chassis_state'] ?? ''),
            'onboard_devices' => (string)($g['onboard_devices'] ?? ''),
            'oem_strings'    => (string)($g['oem_strings'] ?? ''),
            'battery_model'  => (string)($g['battery_model'] ?? ''),
            'battery_chemistry' => (string)($g['battery_chemistry'] ?? ''),
            'usb_devices'    => (string)($g['usb_devices'] ?? ''),
            'pci_devices'    => (string)($g['pci_devices'] ?? ''),
            'smbios'         => (string)($g['smbios'] ?? ''),
            'interfaces'     => (string)($g['interfaces'] ?? ''),
            'uefi_boot_entries' => (string)($g['uefi_boot_entries'] ?? ''),
            'peripherals'    => (string)($g['peripherals'] ?? ''),
            'bios_lockdown'  => isset($g['bios_lockdown']) ? (int)$g['bios_lockdown'] : 0,
            'drive_count'    => count($drives),
            'drives'         => $drives,
        ];
        if ((string)$r['uploaded_at'] < $d['first']) $d['first'] = (string)$r['uploaded_at'];
        if ((string)$r['uploaded_at'] > $d['last'])  $d['last']  = (string)$r['uploaded_at'];
        unset($d);
    }

    // Fold erasure reports into the same machine history so the Devices tab
    // shows when drives were wiped alongside the boot-time snapshots.
    // Same empty-page bug as the diagnostics listing above: an empty key list built
    // "AND device_key IN ()", which MySQL rejects (1064).
    $keySql = '';
    if ($onlyKeys !== null) {
        $keySql = $onlyKeys === []
            ? ' AND 1 = 0'
            : ' AND device_key IN (' . implode(',', array_fill(0, count($onlyKeys), '?')) . ')';
    }
    $stmt = db()->prepare("SELECT id, cocid, uploaded_at, payload FROM reports WHERE user_id IN ($ph) AND report_type = 'erasure'" . $keySql . " ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute($onlyKeys !== null ? array_merge($ids, $onlyKeys) : $ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $g = json_decode((string)$r['payload'], true);
        if (!is_array($g) || empty($g['drives'])) continue;

        $key = device_key($g);
        if ($key === '' || !isset($devices[$key])) continue;   // only fold into a known machine

        $drives = is_array($g['drives']) ? $g['drives'] : [];
        $devices[$key]['history'][] = [
            'report_type'    => 'erasure',
            'id'             => (int)$r['id'],
            'cocid'          => (string)$r['cocid'],
            'uploaded_at'    => ts_local((string)$r['uploaded_at']),
            'first'          => ts_local((string)($g['first'] ?? $r['uploaded_at'])),
            'last'           => ts_local((string)($g['last'] ?? $r['uploaded_at'])),
            'system'         => (string)($g['system'] ?? ''),
            'sysserial'      => (string)($g['sysserial'] ?? $g['sysSerial'] ?? ''),
            'bbserial'       => (string)($g['bbserial'] ?? $g['bbSerial'] ?? ''),
            'biosversion'    => (string)($g['biosversion'] ?? ''),
            'biosdate'       => (string)($g['biosdate'] ?? ''),
            'bioslock'       => (string)($g['bioslock'] ?? ''),
            'bioslockmethod' => (string)($g['bioslockmethod'] ?? ''),
            'systemuuid'     => (string)($g['systemuuid'] ?? ''),
            'drive_count'    => count($drives),
            'drives'         => $drives,
        ];
        if ((string)$r['uploaded_at'] < $devices[$key]['first']) $devices[$key]['first'] = (string)$r['uploaded_at'];
        if ((string)$r['uploaded_at'] > $devices[$key]['last'])  $devices[$key]['last']  = (string)$r['uploaded_at'];
    }

    // Fold completed MDM (Autopilot) checks into the same machine history so
    // the Devices tab shows when each check ran and what it concluded,
    // alongside the diagnostics snapshots and erasure reports. queued/running
    // jobs are still live (shown in the MDM column), not history.
    if (function_exists('mdm_ensure_schema')) mdm_ensure_schema();
    try {
        $stmt = db()->prepare("SELECT id, serial, uuid, status, verdict, source, detail, updated_at, created_at FROM mdm_jobs WHERE user_id IN ($ph) AND status IN ('done','failed','aborted') ORDER BY updated_at DESC, id DESC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $j) {
            $s = strtolower(trim((string)$j['serial']));
            $u = strtolower(trim((string)$j['uuid']));
            $key = ($s !== '' && isset($devices[$s])) ? $s : (($u !== '' && isset($devices[$u])) ? $u : '');
            if ($key === '') continue;   // only fold into a known machine
            $ts = (string)($j['updated_at'] ?? '');
            if ($ts === '' || $ts === '0000-00-00 00:00:00') $ts = (string)$j['created_at'];
            $devices[$key]['history'][] = [
                'report_type' => 'mdm',
                'id'          => (int)$j['id'],
                'uploaded_at' => ts_local($ts),
                'mdm_status'  => (string)$j['status'],
                'mdm_verdict' => (string)$j['verdict'],
                'mdm_source'  => (string)$j['source'],
                'mdm_detail'  => (string)$j['detail'],
            ];
        }
    } catch (Throwable $e) {
        error_log('mdm fold history error: ' . $e->getMessage());
    }

    // Chronological, newest first (diagnostics + erasure + MDM interleaved).
    foreach ($devices as &$d) {
        usort($d['history'], function ($a, $b) {
            $c = strcmp((string)($b['uploaded_at'] ?? ''), (string)($a['uploaded_at'] ?? ''));
            if ($c !== 0) return $c;
            $ia = (int)($a['id'] ?? $a['pdf_id'] ?? 0);
            $ib = (int)($b['id'] ?? $b['pdf_id'] ?? 0);
            return $ib - $ia;
        });
        unset($d);
    }

    $out = array_values($devices);
    $presence = presence_map($userId);
    foreach ($out as $k => $dv) {
        $lastSeen = presence_last_seen((string)$dv['sysserial'], (string)$dv['systemuuid'], $presence);
        $out[$k]['online']    = presence_is_online((string)$dv['sysserial'], (string)$dv['systemuuid'], $presence);
        $out[$k]['lan_ip']    = presence_lan_ip((string)$dv['sysserial'], (string)$dv['systemuuid'], $presence);
        $out[$k]['erasure']   = presence_erasure((string)$dv['sysserial'], (string)$dv['systemuuid'], $presence);
        $out[$k]['last_seen'] = $lastSeen !== null ? ts_rel($lastSeen) : null;
        $out[$k]['last_seen_at'] = $lastSeen !== null ? ts_local(gmdate('Y-m-d H:i:s', $lastSeen)) : null;
        $out[$k]['first']     = ts_local((string)$dv['first']);
        $out[$k]['last']      = ts_local((string)$dv['last']);

        // Refurb grading: the I-A–I-F grade lives on the newest diagnostics
        // report (set in the loop above), plus an automatic per-drive SMART
        // grade computed on demand.
        foreach ($out[$k]['drives'] as $di => $drv) {
            $g = drive_grade(is_array($drv) ? $drv : []);
            $out[$k]['drives'][$di]['grade'] = $g['grade'];
            $out[$k]['drives'][$di]['grade_reason'] = implode('; ', $g['reasons']);
            $out[$k]['drives'][$di]['graded'] = $g['graded'];
        }
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

        // Paginated path: only materialise legacy-only devices that are on this page.
        if ($onlyKeys !== null) {
            $rk = $serial !== '' ? $serial : $uuid;
            if (!in_array($rk, $onlyKeys, true)) continue;
        }

        $drives = is_array($p['drives'] ?? null) ? $p['drives'] : [];
        $seen   = ts_local((string)$reg['registered_at']);
        $profile = [
            'system'         => trim((string)($p['manufacturer'] ?? '') . ' ' . (string)($p['product'] ?? '')),
            'sysserial'      => (string)($p['serial'] ?? $reg['serial']),
            'bbserial'       => '',
            'report_id'      => (string)($p['report_id'] ?? ''),
            'digital_identifier' => (string)($p['digital_identifier'] ?? ''),
            'selftest_cpu'   => (string)($p['selftest_cpu'] ?? ''),
            'chassisserial'  => (string)($p['chassis_serial'] ?? ''),
            'chassistype'    => (string)($p['chassis_type'] ?? ''),
            'biosversion'    => (string)($p['bios_version'] ?? ''),
            'biosdate'       => (string)($p['bios_date'] ?? ''),
            'systemuuid'     => (string)($p['uuid'] ?? $reg['uuid']),
            'family'         => (string)($p['family'] ?? ''),
            'board_product'  => (string)($p['board_product'] ?? ''),
            'board_version'  => (string)($p['board_version'] ?? ''),
            'system_version' => (string)($p['system_version'] ?? ''),
            'tpm_ekpub'      => (string)($p['tpm_ekpub'] ?? ''),
            'tpm_getcap'     => (string)($p['tpm_getcap'] ?? ''),
            'tpm_caps'       => (string)($p['tpm_caps'] ?? ''),
            'product_key'    => (string)($p['product_key'] ?? ''),
            'product_key_id' => product_key_id_from_key((string)($p['product_key'] ?? '')),
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
            'cpu_spec'       => (string)($p['cpu_spec'] ?? ''),
            'display'        => (string)($p['display'] ?? ''),
            'wifi'           => (string)($p['wifi'] ?? ''),
        ];
        $regSeen = presence_last_seen((string)($p['serial'] ?? $reg['serial']), (string)($p['uuid'] ?? $reg['uuid']), $presence);
        $out[] = $profile + [
            'mdm'         => '',
            'first'       => $seen,
            'last'        => $seen,
            'online'      => presence_is_online((string)($p['serial'] ?? $reg['serial']), (string)($p['uuid'] ?? $reg['uuid']), $presence),
            'lan_ip'      => presence_lan_ip((string)($p['serial'] ?? $reg['serial']), (string)($p['uuid'] ?? $reg['uuid']), $presence),
            'erasure'     => presence_erasure((string)($p['serial'] ?? $reg['serial']), (string)($p['uuid'] ?? $reg['uuid']), $presence),
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

    // Attested (manually recorded) destruction, appended after the scanned machines.
    // They are already in $onlyKeys (devices_candidates folded them in), so the
    // ordering step below places them on their page like any other row.
    $out = array_merge($out, manual_list_devices($userId));

    // Fold MDM (Autopilot) + remote command state (previously done by the
    // caller). Re-applied here so sort/output stay consistent; cheap.
    if (function_exists('mdm_fold_devices')) $out = mdm_fold_devices($userId, $out);
    if (function_exists('remote_fold_devices')) $out = remote_fold_devices($userId, $out);

    if ($query === null) {
        return $out;
    }

    // Order the page to match the candidate order computed in Phase A. An attested
    // row's `_key` is a namespaced id that device_key() cannot re-derive, so prefer
    // it when the row carries one.
    $ordered = [];
    foreach ($onlyKeys as $key) {
        foreach ($out as $d) {
            $dk = (string)($d['_key'] ?? '');
            if ($dk === '') { $dk = device_key($d); }
            if ($dk === $key) { $ordered[] = $d; break; }
        }
    }
    // `total` already counts attested units (they are candidates). The separate
    // figure lets the page say how many of the rows are attestations rather than
    // letting a destroyed phone quietly inflate the scanned-fleet count.
    return ['devices' => $ordered, 'total' => $total, 'attested_total' => $attestedTotal,
            'summary' => $summary, 'facets' => $facets, 'ff' => device_filters_encode($ff)];
}

/**
 * Aggregated storage-device inventory across a user's reports — one row per
 * physical drive (keyed by serial). The best erasure outcome wins for the
 * summary columns; every report that touched the drive is kept in `history`
 * (the Drives tab expands it when a drive has multiple reports). Sorted by
 * model, then serial.
 */
function load_drives(int $userId, ?array $query = null): array {
    reports_ensure_schema();
    $ids = org_member_ids($userId);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $q = trim((string)($query['q'] ?? ''));
    $page = max(1, (int)($query['page'] ?? 1));
    $per = min(100, max(1, (int)($query['per'] ?? 10)));
    $sort = (string)($query['sort'] ?? '');
    $dir  = query_dir($query['dir'] ?? '');
    $source = list_source($query['source'] ?? '');

    // Fast path: no search and no chosen column → paginate over the small
    // `drive_serials` cache without decoding every erasure payload. A chosen sort cannot
    // take it, because the column it sorts on (type, status, method, grade) lives in the
    // payload that cache does not carry — so a sorted request uses the full path and sorts
    // before it slices, which is what makes the page boundaries follow the sort.
    if ($query !== null && $q === '' && $sort === '') {
        return drives_paged($ids, $ph, $page, $per, manual_list_drives($userId), $source);
    }

    $stmt = db()->prepare("SELECT id, cocid, uploaded_at, devices, payload FROM reports WHERE user_id IN ($ph) AND report_type = 'erasure' ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute($ids);

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
            $entry['report_id']      = (int)$r['id'];
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
        $dv['ppi_cleared'] = drive_ppi_cleared($dv);

        // Automatic per-drive resale grade from the captured SMART.
        $g = drive_grade($dv);
        $dv['grade'] = $g['grade'];
        $dv['grade_reason'] = implode('; ', $g['reasons']);
        $dv['graded'] = $g['graded'];
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

    // Attested (manually recorded) destroyed media lead the list, matching the
    // no-search path — a drive must not change position when the operator starts
    // typing in the search box. Merged after the grade loop so an attested row keeps
    // its empty grade: no SMART was ever read from it.
    $attested = manual_list_drives($userId);
    $out = array_merge($attested, $out);

    // The source switch, before anything counts or slices: see load_devices().
    if ($source !== 'all') {
        $wantAttested = ($source === 'attested');
        $out = array_values(array_filter(
            $out,
            static fn($d) => !empty($d['attested']) === $wantAttested
        ));
    }

    // The Submitted column's value, for every row, before anything sorts on it.
    $out = media_with_datetime($out);

    if ($query === null) {
        return $out;
    }

    if ($q !== '') {
        $needle = strtolower($q);
        $fields = ['serial', 'model', 'size', 'type', 'status', 'method', 'bus', 'cocid', 'system', 'sysserial'];
        $out = array_values(array_filter($out, function ($d) use ($needle, $fields) {
            foreach ($fields as $f) {
                if (strpos(strtolower((string)($d[$f] ?? '')), $needle) !== false) return true;
            }
            return false;
        }));
    }
    $total = count($out);
    // Counted after the filter: the figure must describe the rows being shown.
    $attestedTotal = count(array_filter($out, static fn($d) => !empty($d['attested'])));

    // Sorted AFTER the search filter and BEFORE the slice, so a page boundary follows the
    // chosen order rather than whichever order the rows happened to arrive in.
    if ($sort !== '') { $out = media_sort_rows($out, $sort, $dir); }

    return [
        'drives'         => array_slice($out, ($page - 1) * $per, $per),
        'total'          => $total,
        'attested_total' => $attestedTotal,
    ];
}

/**
 * Drives-tab pagination without search: pick the page's drive serials from the
 * `drive_serials` cache (no full-payload decode), then fetch payloads only for
 * the reports those drives appear in.
 */
function drives_paged(array $ids, string $ph, int $page, int $per, array $attested = [], string $source = 'all'): array {
    $stmt = db()->prepare("SELECT id, uploaded_at, drive_serials FROM reports WHERE user_id IN ($ph) AND report_type = 'erasure' ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute($ids);
    $newest = [];
    $reportSerials = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $serials = json_decode((string)$r['drive_serials'], true);
        if (!is_array($serials)) continue;
        $rid = (int)$r['id'];
        $up = (string)$r['uploaded_at'];
        foreach ($serials as $idx) {
            $s = (string)($idx['s'] ?? '');
            if ($s === '') continue;
            if (!isset($newest[$s])) $newest[$s] = ['id' => $rid, 'uploaded_at' => $up, 'model' => (string)($idx['m'] ?? '')];
            $reportSerials[$s][] = $rid;
        }
    }

    $serials = array_keys($newest);
    usort($serials, function ($a, $b) use ($newest) {
        $c = strcmp((string)$newest[$b]['uploaded_at'], (string)$newest[$a]['uploaded_at']);
        if ($c !== 0) return $c;
        $m = strcasecmp((string)$newest[$a]['model'], (string)$newest[$b]['model']);
        if ($m !== 0) return $m;
        return strcasecmp((string)$a, (string)$b);
    });

    // Attested (manually recorded) destroyed media are paginated together with the
    // scanned ones rather than bolted onto the last page, which is why they are
    // merged into the key list before slicing. They lead the list: a destroyed drive
    // is a different class of row, and keeping it in a fixed position means it does
    // not jump when the operator pages or searches.
    $attByKey = [];
    foreach ($attested as $a) {
        $k = (string)($a['_key'] ?? '');
        if ($k !== '' && !isset($attByKey[$k])) { $attByKey[$k] = $a; }
    }
    // The source switch. 'attested' needs no payload fetch at all — an attested row has no
    // report — and 'verified' simply leaves the attested keys out of the merge.
    if ($source === 'attested') {
        $serials = array_keys($attByKey);
        $reportSerials = [];
    } elseif ($source === 'verified') {
        $attByKey = [];
    } else {
        $serials = array_merge(array_keys($attByKey), $serials);
    }

    $total = count($serials);
    $pageSerials = array_slice($serials, ($page - 1) * $per, $per);
    if ($pageSerials === []) {
        return ['drives' => [], 'total' => $total, 'attested_total' => count($attByKey)];
    }

    // Only the scanned keys need a payload fetch — an attested key has no report.
    $needIds = [];
    foreach ($pageSerials as $s) {
        if (isset($attByKey[$s])) continue;
        foreach ($reportSerials[$s] ?? [] as $rid) $needIds[$rid] = true;
    }

    $drives = [];
    if ($needIds !== []) {
        $idPh = implode(',', array_fill(0, count($needIds), '?'));
        $stmt = db()->prepare("SELECT id, cocid, uploaded_at, devices, payload FROM reports WHERE id IN ($idPh) ORDER BY uploaded_at DESC, id DESC");
        $stmt->execute(array_keys($needIds));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $g = json_decode((string)$r['payload'], true);
            if (!is_array($g) || empty($g['drives'])) continue;
            foreach ($g['drives'] as $d) {
                if (!is_array($d)) continue;
                $serial = trim((string)($d['serial'] ?? ''));
                $key = $serial !== '' ? strtolower($serial) : '';
                if ($key === '' || !isset($newest[$key])) continue;

                $entry = $d;
                $entry['report_id']      = (int)$r['id'];
                $entry['cocid']          = (string)$r['cocid'];
                $entry['uploaded_at']    = ts_local((string)$r['uploaded_at']);
                $entry['ts']             = ts_local((string)($d['ts'] ?? ''));
                $entry['report_devices'] = (int)$r['devices'];

                if (!isset($drives[$key])) {
                    $drives[$key] = $entry;
                    $drives[$key]['history'] = [$entry];
                } else {
                    $drives[$key]['history'][] = $entry;
                }
            }
        }
    }

    $out = array_values($drives);
    foreach ($out as &$dv) {
        $dv['reports']  = count($dv['history']);
        $dv['multiple'] = $dv['reports'] > 1;
        $dv['ppi_cleared'] = drive_ppi_cleared($dv);
        $g = drive_grade($dv);
        $dv['grade'] = $g['grade'];
        $dv['grade_reason'] = implode('; ', $g['reasons']);
        $dv['graded'] = $g['graded'];
        unset($dv);
    }

    $byKey = [];
    foreach ($out as $dv) $byKey[strtolower((string)($dv['serial'] ?? ''))] = $dv;
    $ordered = [];
    foreach ($pageSerials as $s) {
        if (isset($attByKey[$s]))      { $ordered[] = $attByKey[$s]; }
        elseif (isset($byKey[$s]))     { $ordered[] = $byKey[$s]; }
    }

    return ['drives' => media_with_datetime($ordered), 'total' => $total, 'attested_total' => count($attByKey)];
}
