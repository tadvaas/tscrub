<?php
/**
 * Unit test for mdm_tenant_status() — the admin-only Microsoft probe-tenant
 * subscription reader behind the dashboard expiry banner.
 *
 * Pure function test: the Graph transport is replaced via
 * $GLOBALS['mdm_http_override'] and the settings via
 * $GLOBALS['mdm_settings_override'], so no database, config.json or network is
 * touched. Run anywhere PHP is available:
 *
 *   php mdm_tenant_status_test.php /path/to/server
 */

$serverDir = $argv[1] ?? dirname(__DIR__) . '/server';
require_once $serverDir . '/mdm.php';

$count = 0;
$fails = 0;

function t_check(string $label, bool $ok): void
{
    global $count, $fails;
    $count++;
    echo ($ok ? "ok   - " : "FAIL - ") . $label . "\n";
    if (!$ok) {
        $fails++;
    }
}

function json_resp(array $data, int $status = 200): array
{
    return ['ok' => true, 'status' => $status, 'body' => json_encode($data), 'headers' => []];
}

/** Install fake Graph responses for the next call to mdm_tenant_status(). */
function fake_graph(array $subs, array $skus, array $org = ['value' => [['displayName' => 'TFix']]], int $subsStatus = 200): void
{
    $GLOBALS['fake_subs'] = $subs;
    $GLOBALS['fake_skus'] = $skus;
    $GLOBALS['fake_org']  = $org;
    $GLOBALS['fake_subs_status'] = $subsStatus;
    $GLOBALS['mdm_http_override'] = function (array $req): array {
        $url = (string)($req['url'] ?? '');
        if (strpos($url, '/oauth2/v2.0/token') !== false) {
            return json_resp(['access_token' => 'fake-token']);
        }
        if (strpos($url, '/v1.0/organization') !== false) {
            return json_resp($GLOBALS['fake_org']);
        }
        if (strpos($url, '/v1.0/directory/subscriptions') !== false) {
            return json_resp($GLOBALS['fake_subs'], $GLOBALS['fake_subs_status']);
        }
        if (strpos($url, '/v1.0/subscribedSkus') !== false) {
            return json_resp($GLOBALS['fake_skus']);
        }
        return ['ok' => true, 'status' => 404, 'body' => '{}', 'headers' => []];
    };
}

function subscription(string $status, bool $trial, ?int $endOffsetDays, int $total = 250): array
{
    $s = [
        'skuPartNumber'       => 'Microsoft_Intune_Suite',
        'skuId'               => 'sku-intune-suite',
        'status'              => $status,
        'isTrial'             => $trial,
        'totalLicenses'       => $total,
        'createdDateTime'     => '2026-09-28T00:00:00Z',
        'nextLifecycleDateTime' => $endOffsetDays === null
            ? null
            : gmdate('c', time() + $endOffsetDays * 86400),
    ];
    return ['value' => [$s]];
}

function skus(int $consumed = 1, int $enabled = 250): array
{
    return ['value' => [[
        'skuId'         => 'sku-intune-suite',
        'skuPartNumber' => 'Microsoft_Intune_Suite',
        'consumedUnits' => $consumed,
        'prepaidUnits'  => ['enabled' => $enabled, 'suspended' => 0, 'warning' => 0],
    ]]];
}

$GLOBALS['mdm_settings_override'] = [
    'tenant_id'     => '00000000-0000-0000-0000-000000000001',
    'client_id'     => '00000000-0000-0000-0000-000000000002',
    'client_secret' => 'secret',
];

// 1. Trial ending in 10 days -> warning, not expired.
fake_graph(subscription('Enabled', true, 10), skus());
$s = mdm_tenant_status();
t_check('ok on a readable tenant', $s['ok'] === true);
t_check('tenant name surfaced', $s['tenant_name'] === 'TFix');
t_check('SKU surfaced', $s['sku'] === 'Microsoft_Intune_Suite');
t_check('is_trial surfaced', $s['is_trial'] === true);
t_check('total licences surfaced', $s['total_licenses'] === 250);
t_check('consumed licences surfaced', $s['consumed_licenses'] === 1);
t_check('days remaining is 10', $s['days_remaining'] === 10);
t_check('10 days out is a warning', $s['warning'] === true);
t_check('10 days out is not expired', $s['expired'] === false);
t_check('expiry date is known', $s['expiry_known'] === true);

// 2. Trial ending in 60 days -> no warning.
fake_graph(subscription('Enabled', true, 60), skus());
$s = mdm_tenant_status();
t_check('60 days out is not a warning', $s['warning'] === false);
t_check('60 days remaining', $s['days_remaining'] === 60);

// 3. Trial date in the past -> expired + warning.
fake_graph(subscription('Enabled', true, -1), skus());
$s = mdm_tenant_status();
t_check('past lifecycle date is expired', $s['expired'] === true);
t_check('past lifecycle date is a warning', $s['warning'] === true);
t_check('past lifecycle date has negative days', $s['days_remaining'] < 0);

// 4. Paid subscription -> never a warning even when the date is far away.
fake_graph(subscription('Enabled', false, 200), skus(3, 5));
$s = mdm_tenant_status();
t_check('paid subscription is not a trial', $s['is_trial'] === false);
t_check('paid subscription is not a warning', $s['warning'] === false);
t_check('paid consumed count surfaced', $s['consumed_licenses'] === 3);

// 5. Suspended status -> expired even with a future date.
fake_graph(subscription('Suspended', true, 30), skus());
$s = mdm_tenant_status();
t_check('Suspended status marks expired', $s['expired'] === true);
t_check('Suspended status is a warning', $s['warning'] === true);

// 6. directory/subscriptions forbidden -> seats-only fallback, expiry unknown.
fake_graph([], skus(2, 7), ['value' => [['displayName' => 'TFix']]], 403);
$s = mdm_tenant_status();
t_check('seats-only fallback still ok', $s['ok'] === true);
t_check('seats-only fallback knows the SKU', $s['sku'] === 'Microsoft_Intune_Suite');
t_check('seats-only fallback reports total licences', $s['total_licenses'] === 7);
t_check('seats-only fallback reports consumed licences', $s['consumed_licenses'] === 2);
t_check('seats-only fallback does not invent an expiry', $s['expiry_known'] === false);
t_check('seats-only fallback does not warn', $s['warning'] === false);

// 7. Nothing readable -> ok=false with an error, never a fabricated date.
fake_graph([], [], ['value' => []], 500);
$s = mdm_tenant_status();
t_check('unreadable tenant is not ok', $s['ok'] === false);
t_check('unreadable tenant carries an error', $s['error'] !== '');
t_check('unreadable tenant has no days remaining', $s['days_remaining'] === null);

// 8. Unconfigured autopilot block -> configured=false, ok=false.
$GLOBALS['mdm_settings_override'] = [];
$s = mdm_tenant_status();
t_check('unconfigured tenant is not ok', $s['ok'] === false);
t_check('unconfigured tenant reports configured=false', $s['configured'] === false);
t_check('unconfigured tenant names the cause', $s['error'] === 'autopilot not configured');

echo "\n{$count} checks, {$fails} failure(s)\n";
exit($fails === 0 ? 0 : 1);
