<?php
declare(strict_types=1);

/**
 * Background MDM check worker (cron-driven).
 *
 * Claims queued mdm_jobs, probes Microsoft Graph with the device's staged
 * authoritative hash, and records the verdict. A MySQL named lock serialises
 * with the cron scheduler so overlapping runs are no-ops.
 *
 * Crontab (as the web user, every minute):
 *   * * * * * php /home/oxwet/webs/tscrub-form/mdm-worker.php >> /home/oxwet/webs/tscrub-form/mdm-worker.log 2>&1
 */

require __DIR__ . '/db.php';
require __DIR__ . '/http.php';
require __DIR__ . '/mdm.php';

const MAX_JOBS_PER_RUN = 10;
const MAX_ATTEMPTS = 3;

set_time_limit(0);
date_default_timezone_set('UTC');

// Serialise with the cron scheduler (no Redis — a MySQL named lock).
try {
    $got = (int)db()->query("SELECT GET_LOCK('mdm_worker', 0)")->fetchColumn();
    if ($got !== 1) {
        exit(0); // another run is already in progress
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'worker lock error: ' . $e->getMessage() . "\n");
    exit(1);
}

$processed = 0;
try {
    while ($processed < MAX_JOBS_PER_RUN) {
        $job = mdm_claim_job();
        if ($job === null) {
            break;
        }
        $processed++;

        $jobId   = (int)$job['id'];
        $userId  = (int)$job['user_id'];
        $serial  = (string)$job['serial'];
        $uuid    = (string)$job['uuid'];

        $hash = mdm_staged_hash($userId, $serial, $uuid);
        if ($hash === null) {
            mdm_abort_job($jobId, 'na', 'no staged hash for serial');
            echo 'job ' . $jobId . ' (' . $serial . '): no staged hash -> failed' . "\n";
            continue;
        }

        $res     = mdm_probe($serial, $hash, 600);
        $verdict = (string)$res['verdict'];
        $source  = (string)($res['source'] ?? 'live');
        $detail  = json_encode($res) ?: '';

        mdm_log_probe($userId, $serial, $uuid, $verdict, $source, 'worker');

        if (in_array($verdict, ['locked_other', 'locked_this', 'unlocked', 'hash_invalid'], true)) {
            mdm_complete_job($jobId, $verdict, $source, $detail);
            echo 'job ' . $jobId . ' (' . $serial . '): ' . $verdict . "\n";
        } elseif ((int)($job['attempts'] ?? 0) + 1 >= MAX_ATTEMPTS) {
            mdm_abort_job($jobId, $verdict, $detail);
            echo 'job ' . $jobId . ' (' . $serial . '): giving up after ' . MAX_ATTEMPTS . " attempts -> " . $verdict . "\n";
        } else {
            mdm_fail_job($jobId, $detail); // back to queued, attempts + 1
            echo 'job ' . $jobId . ' (' . $serial . '): transient (' . $verdict . ") requeued\n";
        }
    }
} finally {
    try {
        db()->query("SELECT RELEASE_LOCK('mdm_worker')")->fetchColumn();
    } catch (Throwable $e) {
        // best-effort
    }
}

echo 'worker run done (processed ' . $processed . ")\n";
