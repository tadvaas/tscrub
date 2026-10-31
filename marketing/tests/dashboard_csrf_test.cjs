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
