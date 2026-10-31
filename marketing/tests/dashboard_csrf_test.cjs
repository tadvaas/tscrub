/**
 * A POST from a dashboard page needs the token that getCsrf() fetches.
 * Neither devices.html nor drives.html has a global `csrf` variable, so a bare
 * `'X-CSRF-Token': csrf` throws a ReferenceError while building the fetch
 * options: no request is made, the button stays disabled, and the operator sees
 * nothing happen. That is exactly how "Mark as our bench machine" shipped
 * broken, which also hid the Bench hosts control (it only showed once a host
 * existed).
 *
 * Invariants asserted here:
 *
 *   - both pages define getCsrf() and neither declares a global csrf variable;
 *   - the two provenance POSTs (benchToggle, openOriginForm) call getCsrf()
 *     and never reference a bare `csrf`;
 *   - the origin action lives in the media record view beside Correct COCID,
 *     labelled Reassign origin, and the report history no longer carries it;
 *   - the bench-host control lives in the Filters modal as a checkbox, is not a
 *     separate toolbar button, and is seeded, applied and cleared with the rest
 *     of the filter state;
 *   - the bench choice is counted and chipped like a filter, so the Filters
 *     button never under-states the view.
 *
 * Run: node marketing/tests/dashboard_csrf_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.CSRF_ROOT || path.join(__dirname, '..');
const PAGE_DIR = path.join(root, 'site', 'dashboard');

let count = 0;
let fails = 0;
function check(label, ok, detail) {
  count++;
  if (ok) console.log('ok   - ' + label);
  else {
    fails++;
    console.log('FAIL - ' + label + (detail !== undefined ? ' (got: ' + detail + ')' : ''));
  }
}

function inlineJs(html) {
  const m = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/);
  return m ? m[1] : '';
}

/** Body of `function <name>(...) { ... }`, brace-matched. */
function bodyOf(js, name) {
  const start = js.indexOf('function ' + name);
  if (start < 0) return '';
  const open = js.indexOf('{', start);
  if (open < 0) return '';
  let depth = 0;
  for (let i = open; i < js.length; i++) {
    if (js[i] === '{') depth++;
    else if (js[i] === '}') {
      depth--;
      if (depth === 0) return js.slice(open, i + 1);
    }
  }
  return '';
}

const pages = {
  devices: fs.readFileSync(path.join(PAGE_DIR, 'devices.html'), 'utf8'),
  drives: fs.readFileSync(path.join(PAGE_DIR, 'drives.html'), 'utf8'),
};
const js = {
  devices: inlineJs(pages.devices),
  drives: inlineJs(pages.drives),
};

for (const name of Object.keys(pages)) {
  check(name + ': page has a getCsrf() helper', /function\s+getCsrf\s*\(/.test(js[name]));
  check(
    name + ': no global csrf variable (token must be fetched)',
    !/^\s*(?:var|let|const)\s+csrf\s*=/m.test(js[name])
  );
}

const posts = [
  { page: 'devices', fn: 'benchToggle', endpoint: '/api/devices/processing-host' },
  { page: 'drives', fn: 'openOriginForm', endpoint: '/api/media/link' },
];
for (const p of posts) {
  const body = bodyOf(js[p.page], p.fn);
  check(p.page + ': ' + p.fn + ' exists', body.length > 0);
  check(p.page + ': ' + p.fn + ' posts to ' + p.endpoint, body.indexOf(p.endpoint) >= 0);
  check(p.page + ': ' + p.fn + ' gets the token with getCsrf()', /getCsrf\s*\(\s*\)/.test(body));
  check(
    p.page + ': ' + p.fn + ' never uses a bare csrf',
    !/'X-CSRF-Token'\s*:\s*csrf\b/.test(body)
  );
}

// The origin action belongs to the record, beside Correct COCID, not to the
// report-history table. "Assign" was the wrong verb: the medium already has an
// origin (the host that reported it), so the operator is re-attributing it.
const driveView = bodyOf(js.drives, 'openDrive');
const originState = bodyOf(js.drives, 'originState');
check('drives: the record view shows the origin state', driveView.indexOf('originState(d, originBody)') >= 0);
check('drives: the record view offers Reassign origin', /Reassign origin/.test(driveView));
check('drives: it opens the origin form in the record', /openOriginForm\(d, originBody\)/.test(driveView));
check(
  'drives: it sits beside Correct COCID in the footer',
  driveView.indexOf('Correct COCID') >= 0
    && driveView.indexOf('Correct COCID') < driveView.indexOf('Reassign origin')
);
check('drives: the origin state itself is read-only', originState.length > 0 && !/Reassign origin/.test(originState));
check(
  'drives: an ordinary host is assumed to be the origin asset',
  /sanitised in the machine that reported it/.test(originState) && !/not separately recorded/.test(originState)
);
check(
  'drives: only a bench host gets the reassign caveat',
  /host_processing/.test(originState) && /Processed on bench machine/.test(originState)
);
check(
  'drives: the in-place state is green',
  /else if \(host\)[\s\S]*?text-emerald-700/.test(originState) && /In place/.test(originState)
);
check(
  'drives: the bench state is amber',
  /host_processing[\s\S]*?text-amber-700/.test(originState) && /Bench processed/.test(originState)
);
check('drives: the report history no longer carries the origin action', !/function originBox/.test(js.drives) && !/inner\.appendChild\(originBox/.test(js.drives));
check('drives: the old labels are gone', !/Assign origin/.test(pages.drives) && !/Change origin/.test(pages.drives));
check('drives: action labels do not carry a dialog ellipsis', !/Correct COCID\u2026/.test(pages.drives) && !/Reassign origin\u2026/.test(pages.drives));

// The control itself: a checkbox in the Filters modal, not a toolbar button.
const benchInput = (pages.devices.match(/<input[^>]*id="filter-bench"[^>]*>/) || [''])[0];
check('devices: Filters modal has the bench checkbox', benchInput.length > 0);
check('devices: the bench checkbox is a checkbox', /type="checkbox"/.test(benchInput));
check('devices: no separate Bench hosts toolbar button', !/id="bench-btn"/.test(pages.devices));
check('devices: the bench checkbox names the choice', /Show our bench hosts/.test(pages.devices));

const hint = bodyOf(js.devices, 'paintBenchHint');
check('devices: paintBenchHint() exists', hint.length > 0);
check(
  'devices: paintBenchHint() says how to mark one when none exist',
  /No machines are marked yet/.test(hint)
);

// Marking a bench machine belongs with the machine's operations, not in its log
// history. The Log panel is rebuilt constantly; the Ops panel is the action
// surface and stays open across the reload.
const logPanel = bodyOf(js.devices, 'logPanel');
const openOps = bodyOf(js.devices, 'openOperations');
check('devices: the Log no longer carries the bench action', !/benchToggle/.test(logPanel));
check('devices: the Operations panel carries the bench action', /benchToggle\(dev\)/.test(openOps));
check('devices: the empty-bench hint points at the Ops panel', /Ops panel/.test(hint));

const open = bodyOf(js.devices, 'openFilterModal');
check('devices: opening Filters seeds the bench choice', /pendingBench\s*=\s*benchView/.test(open));
const apply = bodyOf(js.devices, 'applyFilterModal');
check('devices: applying Filters writes the bench choice', /benchView\s*=\s*!!?\s*pendingBench/.test(apply));
check(
  'devices: Clear all in the modal clears the bench choice',
  /pendingBench\s*=\s*false/.test(js.devices)
);
const paintFilters = bodyOf(js.devices, 'paintFilters');
check(
  'devices: the filter count includes the bench choice',
  /countFilters\(filters\)\s*\+\s*\(benchView\s*\?\s*1\s*:\s*0\)/.test(paintFilters)
);
check(
  'devices: the chip row can turn the bench choice off',
  /benchView\s*=\s*false/.test(paintFilters) && /Bench hosts/.test(paintFilters)
);
const hintCount = bodyOf(js.devices, 'paintFilterHint');
check(
  'devices: Apply (N) counts the bench choice',
  /pendingBench\s*\?\s*1\s*:\s*0/.test(hintCount)
);

console.log('\n' + (fails ? fails + ' of ' + count + ' checks FAILED' : count + ' checks passed'));
process.exit(fails ? 1 : 0);
