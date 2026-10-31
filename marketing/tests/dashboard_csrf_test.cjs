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
 *   - the Bench hosts button is in the markup from the start (not `hidden`),
 *     disabled with an explanation, so the feature is discoverable before
 *     anything is marked;
 *   - paintBench() removes `hidden` unconditionally — the control must remain
 *     visible in the empty state.
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

// The control itself: present, not hidden, disabled with an explanation.
const benchBtn = (pages.devices.match(/<button[^>]*id="bench-btn"[^>]*>/) || [''])[0];
check('devices: Bench hosts button is in the markup', benchBtn.length > 0);
check('devices: Bench hosts button is not hidden', !/\bclass="[^"]*\bhidden\b/.test(benchBtn));
check('devices: Bench hosts button starts disabled', /\bdisabled\b/.test(benchBtn));
check(
  'devices: Bench hosts button explains the empty state',
  /No machines are marked as processing hosts yet/.test(benchBtn)
);
const paint = bodyOf(js.devices, 'paintBench');
check('devices: paintBench() exists', paint.length > 0);
check(
  'devices: paintBench() always un-hides the control',
  /classList\.remove\(\s*'hidden'\s*\)/.test(paint) && !/classList\.toggle\(\s*'hidden'/.test(paint)
);

console.log('\n' + (fails ? fails + ' of ' + count + ' checks FAILED' : count + ' checks passed'));
process.exit(fails ? 1 : 0);
