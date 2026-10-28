/**
 * The bulk MDM check: the guards that keep it from spending money wrongly.
 *
 * Nothing here tests Graph — that needs the network, credits and a real tenant.
 * What IS assertable is the set of decisions that would each be expensive to get
 * wrong, none of which fail loudly at runtime:
 *
 *   - the batch derives its set from the SAME loader as the list (`all: true`), so
 *     it cannot drift from what the pager said was there;
 *   - it refuses the WHOLE batch when the balance cannot cover it — the worker
 *     debits per live probe AFTER the probe, so a short balance would run checks it
 *     cannot pay for and only log the shortfall;
 *   - it enqueues with force=false, so a cached verdict inside 24h is free;
 *   - it uses its OWN rate-limit bucket: rate_limit() is a minimum INTERVAL per
 *     (scope, IP), so sharing 'mdm' meant the dry run blocked its own confirmation
 *     for 30 seconds, and shared a budget with the appliance's /mdm/autopilot;
 *   - the button's count is the CHECKABLE subset of the filtered view, not the
 *     filtered total (which would read "check 207" where only 9 are unchecked).
 *
 * Run: node marketing/tests/mdm_batch_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.MDM_ROOT || path.join(__dirname, '..');
const api = fs.readFileSync(path.join(root, 'server', 'api.php'), 'utf8');
const lib = fs.readFileSync(path.join(root, 'server', 'reports_lib.php'), 'utf8');
const page = fs.readFileSync(path.join(root, 'site', 'dashboard', 'devices.html'), 'utf8');

let count = 0;
let fails = 0;
function check(label, ok, detail) {
  count++;
  if (ok) console.log('ok   - ' + label);
  else { fails++; console.log('FAIL - ' + label + (detail !== undefined ? ' (got: ' + detail + ')' : '')); }
}

// ---- the endpoint ---------------------------------------------------------
const start = api.indexOf("$route === '/mdm/batch'");
const end = api.indexOf("$route === '/reports'", start);
const ep = start >= 0 ? api.slice(start, end > start ? end : start + 6000) : '';
check('POST /api/mdm/batch exists', start >= 0);
check('it verifies CSRF before anything else', /auth_csrf_verify\(\);/.test(ep) && /auth_require\(\);/.test(ep));
check('it takes the view, not a list of serials',
  /load_devices\(/.test(ep) && /'all' => true/.test(ep) && !/\['serials'\]|\['ids'\]/.test(ep));
check('it has its own rate-limit bucket', /rate_limit\('mdm_batch'/.test(ep) && !/rate_limit\('mdm'/.test(ep));
check('it refuses the whole batch when short of credits',
  /\$need > \$balance/.test(ep) && /fail\(402/.test(ep) && /Nothing has been queued/.test(ep));
check('eligibility is the unchecked facet, not a separate rule',
  /device_facet_value\(\$dev, 'mdm'\) !== 'unchecked'/.test(ep));
check('it re-checks the staged hash before enqueueing', /mdm_staged_hash\(/.test(ep));
check('it reports skips with a reason', /'no captured hash'/.test(ep) && /'reason' =>/.test(ep));
check('dry_run returns before any write', ep.indexOf('$dry') < ep.indexOf('mdm_ensure_schema()'));
// (Match on the tail of the argument list: the first argument is `(int)$u['id']`,
// so a [^)]* pattern stops at its parenthesis and silently never matches.)
check('it enqueues with force=false', /\$e\['uuid'\], false\)/.test(ep));
check('it never forces a re-probe', !/\$e\['uuid'\], true\)/.test(ep));

// ---- the filter that defines the set ------------------------------------
check('unchecked can only be true when a hash is staged (facet rule)',
  /\$st === 'unchecked'\) \{ return 'unchecked'; \}/.test(lib));
check('checkable is counted over the FILTERED list', /\$summary\['checkable'\] = count\(array_filter\(/.test(lib));
check('checkable counts mdm === unchecked', /device_facet_value\(\$d, 'mdm'\) === 'unchecked'/.test(lib));

// ---- the button ---------------------------------------------------------
check('the button exists and starts disabled', /id="mdm-batch-btn"[^>]*disabled/.test(page));
check('its count comes from summary.checkable', /fleetSummary\.checkable/.test(page));
// The subset must be attributable to the view, or "9" under a 207-device list
// reads as nine devices from somewhere else.
check('the label names the view total when the two differ',
  /'Check MDM \(' \+ n \+ ' of ' \+ t \+ '\)'/.test(page));
check('the dialog states the view total', /' of the ' \+ d\.total/.test(page));
check('the server returns that total', /'total' => \(int\)\(\$res\['total'\]/.test(
  fs.readFileSync(path.join(root, 'server', 'api.php'), 'utf8')));
check('the count refreshes even when the rows do not',
  /paintSortArrows\(\);\s*\n\s*paintMdmBatch\(\);/.test(page));
check('it disables itself at zero', /btn\.disabled = n === 0/.test(page));
check('it posts the current view', /q: currentQ, source: source, ff: encodeFilters\(filters\)/.test(page));
check('the dialog dry-runs before offering the action', /mdmBatchPost\(true\)/.test(page));
check('only the confirm does the real call', /mdmBatchPost\(false\)/.test(page));
check('the dialog says a check does not change the device',
  /does not change it/.test(page));

console.log('\n' + count + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
