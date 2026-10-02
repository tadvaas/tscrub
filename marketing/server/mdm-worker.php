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
const PROBE_COOLDOWN_SECONDS = 30;

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

mdm_ensure_schema();
$processed = 0;
$graphUp = mdm_graph_reachable();
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

        // Billing gate (defensive): the endpoints refuse to enqueue for gated
        // accounts, but a user can lapse (paid -> free / zero credits) between
        // enqueue and the worker picking the job up. Record the gate as a
        // terminal verdict; the dashboard Re-check button is the re-probe path
        // after the account tops up or upgrades.
        $gate = mdm_gate($userId);
        if (!$gate['allowed']) {
            $verdict = $gate['reason'] === 'free_tier' ? 'paid_only' : 'insufficient_credits';
            mdm_log_probe($userId, $serial, $uuid, $verdict, 'gate', 'worker');
            mdm_complete_job($jobId, $verdict, 'gate', json_encode($gate) ?: '');
            echo 'job ' . $jobId . ' (' . $serial . '): gated (' . $gate['reason'] . ")\n";
            continue;
        }

        // Verdict cache: for a non-forced check, reuse the most recent real
        // verdict within TTL instead of re-importing the same hash (fewer
        // register→unregister cycles = less Microsoft abuse-flag exposure).
        if (!(int)($job['force'] ?? 0)) {
            $cached = mdm_cached_verdict($userId, $serial, $uuid);
            if ($cached !== null) {
                mdm_log_probe($userId, $serial, $uuid, $cached['verdict'], 'cache', 'worker');
                mdm_complete_job($jobId, $cached['verdict'], 'cache', json_encode($cached));
                echo 'job ' . $jobId . ' (' . $serial . '): ' . $cached['verdict'] . " (cached)\n";
                continue;
            }
        }

        // Graph unreachable — don't burn a 600 s probe + import on a dead link;
        // requeue quickly and retry next minute.
        if (!$graphUp) {
            mdm_log_probe($userId, $serial, $uuid, 'offline', 'error', 'worker');
            mdm_fail_job($jobId, 'graph unreachable (pre-flight)');
            echo 'job ' . $jobId . ' (' . $serial . '): offline (graph unreachable) requeued' . "\n";
            continue;
        }

        // Cooldown: space live probes so a queued batch doesn't fire rapid
        // import→delete cycles back-to-back (another abuse-flag trigger).
        $cooldown = mdm_probe_cooldown_remaining(PROBE_COOLDOWN_SECONDS);
        if ($cooldown > 0) {
            echo 'job ' . $jobId . ' (' . $serial . "): cooldown {$cooldown}s\n";
            sleep($cooldown);
        }

        $res     = mdm_probe($serial, $hash, 600);
        $verdict = (string)$res['verdict'];
        $source  = (string)($res['source'] ?? 'live');
        $detail  = json_encode($res) ?: '';

        mdm_log_probe($userId, $serial, $uuid, $verdict, $source, 'worker');

        if (in_array($verdict, ['locked_other', 'locked_this', 'unlocked', 'hash_invalid', 'unknown', 'ms_error'], true)) {
            // Charge one credit per LIVE Graph probe (an import was actually
            // made). Cached verdicts and transport-only failures (offline /
            // ms_error) never reach here with source 'live', so they are free.
            if ($source === 'live') {
                $debit = credit_debit($userId, 1, 'mdm:live:' . $jobId);
                if ($debit === -1) {
                    error_log('mdm credit shortfall for job ' . $jobId . ' (' . $serial . ')');
                }
            }
            mdm_complete_job($jobId, $verdict, $source, $detail);
            echo 'job ' . $jobId . ' (' . $serial . '): ' . $verdict . "\n";
        } elseif ($verdict !== 'offline' && (int)($job['attempts'] ?? 0) + 1 >= MAX_ATTEMPTS) {
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
