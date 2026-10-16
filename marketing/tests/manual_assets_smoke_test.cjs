/**
 * Runtime smoke test for manual-assets.js.
 *
 * `node --check` only proves the file parses. Every defect this file has actually
 * shipped — the undefined `isDevice`, and the one-argument call to a two-argument
 * show() that threw under 'use strict' — was a RUNTIME error, invisible to a syntax
 * check. So this runs the real save() against stubbed fetch/DOM responses and
 * asserts what the operator actually ends up seeing.
 *
 * Run: node marketing/tests/manual_assets_smoke_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

// MANUAL_ASSETS_SRC lets the suite be pointed at a mutated copy, which is how the
// "would this test have caught the bug?" question gets answered.
const SRC = process.env.MANUAL_ASSETS_SRC
  || path.join(__dirname, '..', 'site', 'public', 'assets', 'manual-assets.js');
const src = fs.readFileSync(SRC, 'utf8');

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

// ---------------------------------------------------------------- DOM stub ---
function makeNode(tag) {
  const n = {
    tagName: String(tag || '').toUpperCase(),
    children: [],
    attributes: {},
    _text: '',
    _classes: new Set(),
    disabled: false,
    style: {},
    get textContent() { return this._text; },
    set textContent(v) { this._text = String(v); },
    // innerHTML = '' must actually remove the children. Without this the detail view's
    // rebuild left the previous render in place, so a test asserting that a hostless
    // drive has no media table passed against the PREVIOUS row's table.
    get innerHTML() { return this._html || ''; },
    set innerHTML(v) { this._html = String(v); if (this._html === '') { this.children = []; } },
    setAttribute(k, v) {
      this.attributes[k] = String(v);
      if (k === 'class') String(v).split(/\s+/).forEach(c => { if (c) this._classes.add(c); });
    },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; },
    appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
    removeChild(c) { const i = this.children.indexOf(c); if (i >= 0) this.children.splice(i, 1); return c; },
    classList: {
      _o: null,
      add() { [].forEach.call(arguments, c => this._o._classes.add(c)); },
      remove() { [].forEach.call(arguments, c => this._o._classes.delete(c)); },
      contains(c) { return this._o._classes.has(c); },
    },
    addEventListener(ev, fn) { (this._on || (this._on = {}))[ev] = fn; },
    // Real elements have focus(); detail() moves focus to the Close button so keyboard
    // users land inside the dialog rather than behind it.
    focus() { global.__focused = this; },
    closest(sel) {
      let n = this;
      while (n) {
        if (sel.charAt(0) === '#' && n.id === sel.slice(1)) return n;
        n = n.parentNode;
      }
      return null;
    },
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
  n.classList._o = n;
  return n;
}

// Depth-first, document order — the order a real querySelector matches in. The previous
// stub reached only two levels (b.children[i].children[j]), so it could not see the media
// row's fields at all: they sit at body → #manual-modal-media → row → select. That is
// exactly why the suite passed while Add on the Devices page was broken — the stub never
// reproduced the situation the bug came from.
function walkAll(node) {
  const out = [];
  (node.children || []).forEach(c => { out.push(c); walkAll(c).forEach(g => out.push(g)); });
  return out;
}

// The selectors the module actually uses, against a whole subtree.
function matches(root, sel) {
  const all = walkAll(root);
  if (sel === '#manual-modal-media') { return all.filter(n => n.id === 'manual-modal-media'); }
  // 'input[name=serial]' and '[name=serial]' are both used, so the tag is optional.
  const m = /^(?:([a-zA-Z]+))?\[name=([^\]]+)\]$/.exec(sel);
  if (m) {
    return all.filter(n => n.getAttribute('name') === m[2]
      && (!m[1] || n.tagName === m[1].toUpperCase()));
  }
  if (sel === 'input,select') {
    return all.filter(n => n.tagName === 'INPUT' || n.tagName === 'SELECT');
  }
  return [];
}

// The stub's textContent only holds an element's OWN text, whereas the browser
// aggregates descendants — so the tests need this to read a composed subtree.
function textOf(node) {
  if (!node) return '';
  let s = node._text || '';
  (node.children || []).forEach(c => { s += ' ' + textOf(c); });
  return s.replace(/\s+/g, ' ').trim();
}

const byId = {};
['manual-modal', 'manual-modal-title', 'manual-modal-sub', 'manual-modal-save',
 'manual-modal-body', 'manual-modal-close', 'manual-modal-backdrop',
 'manual-modal-error',
 // the read-only detail shell
 'manual-detail-modal', 'manual-detail-title', 'manual-detail-sub',
 'manual-detail-body', 'manual-detail-close', 'manual-detail-backdrop'
].forEach(id => { const n = makeNode('div'); n.id = id; byId[id] = n; });

global.document = {
  readyState: 'complete',
  body: makeNode('body'),
  getElementById(id) { return byId[id] || null; },
  querySelector() { return null; },
  addEventListener() {},
  createElement: makeNode,
  // The icons are inline SVG, built with createElementNS.
  createElementNS(ns, tag) { return makeNode(tag); },
};

function installHarness(fetchImpl) {
  global.fetch = fetchImpl;
  global.window = global.window || {};
  global.window.location = { reload() { global.__reloaded = true; }, href: '' };
  // confirm/alert are the operator-facing surface of remove(), so they are stubbed
  // rather than ignored: the wording and the decision both matter here.
  global.__confirmAnswer = true;
  global.__lastConfirm = '';
  global.__alerts = [];
  global.__reqs = [];
  global.window.confirm = (msg) => { global.__lastConfirm = String(msg); return global.__confirmAnswer; };
  global.window.alert = (msg) => { global.__alerts.push(String(msg)); };
  global.AbortController = class {
    constructor() { this.signal = { aborted: false }; }
    abort() { this.signal.aborted = true; }
  };
}

installHarness(() => Promise.reject(new Error('no fetch installed')));
eval(src + '\n//# sourceURL=manual-assets.js');

// The module resolves the form from #manual-modal-body, so that is the node to
// populate — building a detached one would exercise a different path from the page.
//
// The layout mirrors what open() actually builds: the COCID, then the DEVICE fields,
// then the media box, then the device-level reason/method/evidence fields. Getting this
// wrong is not cosmetic: save() reads the device fields from the body and the media
// fields from the media box, so a stub that puts the device fields only inside the media
// row makes save() post an empty device and the after-save landing lose its serial.
function fieldset(names, values) {
  const wrap = makeNode('div');
  names.forEach(k => {
    const f = makeNode('input');
    f.setAttribute('name', k);
    // A real input reflects `name`/`value` as properties, which is how collect() and
    // save() read them; setting only the attribute would silently produce empty rows.
    f.name = k;
    f.value = (values && Object.prototype.hasOwnProperty.call(values, k)) ? String(values[k])
      : (k === 'serial' ? 'SN-1'
      : (k === 'reason' ? 'media_soldered'
      : (k === 'destroy_method' ? 'shredded' : 'x')));
    wrap.appendChild(f);
  });
  wrap.querySelectorAll = (sel) => matches(wrap, sel);
  return wrap;
}

function fillValidForm() {
  const b = byId['manual-modal-body'];
  const cocid = makeNode('input');
  cocid.setAttribute('name', 'cocid');
  cocid.name = 'cocid';
  cocid.value = 'COC-1';

  const deviceFields = fieldset(['category', 'make', 'model', 'serial'],
    { category: 'ip_phone', make: 'Cisco', model: '7942G', serial: 'SN-1' });

  const media = makeNode('div');
  media.id = 'manual-modal-media';
  const row = fieldset(['type', 'size', 'make', 'model', 'serial', 'bus'],
    { type: 'Flash (integrated)', size: '64 MB', serial: 'SN-1' });
  // A media row really does carry its own Reason/Method pair — the "different outcome for
  // this medium" override, hidden inside a nested div. The stub used to leave them out,
  // which is why it never saw the collision that broke save(). They are POPULATED here
  // deliberately: with the old document-order lookup the device would have been posted
  // with the MEDIUM's reason and method — valid values, so no error, just the wrong record
  // silently filed. A blank pair would instead make the save fail loudly with "A reason is
  // required", which is the symptom the operator reported.
  row.appendChild(fieldset(['reason', 'destroy_method'],
    { reason: 'physically_damaged', destroy_method: 'crushed' }));
  media.appendChild(row);

  const deviceEvidence = fieldset(
    ['reason', 'destroy_method', 'destroy_vendor', 'destroy_date', 'destroy_witness', 'evidence_ref'],
    { reason: 'not_supported_by_appliance', destroy_method: 'factory_reset',
      destroy_vendor: '', destroy_date: '2026-10-16', destroy_witness: '', evidence_ref: '' });

  b.children = [];
  b.appendChild(cocid);
  b.appendChild(deviceFields);
  b.appendChild(media);
  b.appendChild(deviceEvidence);

  b.querySelector = (sel) => matches(b, sel)[0] || null;
  b.querySelectorAll = (sel) => matches(b, sel);
  return b;
}

function installResponse(response) {
  installHarness((url, opts) => {
    // The POST body is kept: the modal's payload building is where the real defect was, and
    // asserting on what reached the wire is the only way to pin it.
    global.__reqs.push({ url: String(url), method: (opts && opts.method) || 'GET', body: (opts && opts.body) || '' });
    if (String(url).indexOf('/api/csrf') !== -1) {
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ csrf: 'tok' }) });
    }
    if (response === 'abort') {
      // Simulates the module's own AbortController firing. What matters is that the
      // abort path reports a timeout rather than a vague connection error, and
      // re-enables the button instead of leaving it on "Saving…".
      return new Promise((resolve, reject) => {
        void resolve;
        setTimeout(() => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })), 30);
      });
    }
    return Promise.resolve({
      ok: response.status >= 200 && response.status < 300,
      status: response.status,
      text: () => Promise.resolve(response.body),
    });
  });
}

function clickSave() {
  const btn = byId['manual-modal-save'];
  btn.disabled = false;
  btn.textContent = 'Record device';
  fillValidForm();
  byId['manual-modal-error']._classes.add('hidden');
  byId['manual-modal-error'].textContent = '';
  byId['manual-modal']._classes.delete('hidden');
  global.__reloaded = false;
  global.window.location.href = '';
  // .call(btn): the module's handler passes `this` as the button, so invoking it bare
  // would bind `this` to the handler map and the module would toggle a property on
  // the wrong object — the assertions below would then pass without testing anything.
  btn._on.click.call(btn);
  return btn;
}

function runFailureCase(name, response, expectFragment, done) {
  installResponse(response);
  const btn = clickSave();
  setTimeout(() => {
    const shown = byId['manual-modal-error'].textContent || '';
    check(name + ' — button re-enabled, not left on "Saving…"', btn.disabled === false);
    check(name + ' — an error message is shown', shown.length > 0);
    check(name + ' — shows "' + expectFragment + '"'
      + (shown.indexOf(expectFragment) === -1 ? ' (got: ' + shown.slice(0, 110) + ')' : ''),
      shown.indexOf(expectFragment) !== -1);
    done();
  }, 110);
}

if (!byId['manual-modal-save']._on || !byId['manual-modal-save']._on.click) {
  check('the module registers a save handler on load', false);
  console.log('\n' + count + ' checks, ' + fails + ' failed');
  process.exit(1);
}
check('the module registers a save handler on load', true);

installResponse({ status: 201, body: JSON.stringify({ ok: true, id: 7 }) });
clickSave();
setTimeout(() => {
  // The landing is the fix for "I saved it and nothing appeared": attested items sort
  // to the LAST page, so a bare reload looked like a failed save.
  check('a 201 success — lands on the Devices page',
    global.window.location.href.indexOf('/dashboard/devices') === 0,
    global.window.location.href);
  check('a 201 success — the landing searches for the serial just recorded',
    global.window.location.href === '/dashboard/devices?q=SN-1', global.window.location.href);
  check('a 201 success — it does not merely reload', global.__reloaded === false);
  check('a 201 success — the modal closes', byId['manual-modal']._classes.has('hidden'));
  check('a 201 success — no error is shown', (byId['manual-modal-error'].textContent || '') === '');
  // Deliberately not re-enabled: the page is about to navigate, and re-enabling the
  // button would invite a second, duplicate record during that window.
  check('a 201 success — the button is not re-enabled (the page is navigating)',
    byId['manual-modal-save'].disabled === true);

  // ---- what the modal actually posted -----------------------------------------
  // The device's Reason and Method live in a block appended AFTER the media rows, and each
  // media row carries its own hidden pair. save() resolved them by document order, so it
  // read the MEDIUM's selects for the device: blank there meant the server refused every
  // save ("A reason is required"), and filled there meant the device was filed with the
  // medium's reason and method. Neither could be seen from here until the stub above could
  // reach the media row at all.
  const posted = JSON.parse(
    ((global.__reqs.filter(r => r.url.indexOf('/api/certificates/devices') !== -1)[0] || {}).body) || '{}');
  check('the posted device carries the DEVICE reason, not the medium\'s',
    posted.device && posted.device.reason === 'not_supported_by_appliance',
    posted.device && JSON.stringify(posted.device.reason));
  check('the posted device carries the DEVICE method',
    posted.device && posted.device.destroy_method === 'factory_reset',
    posted.device && JSON.stringify(posted.device.destroy_method));
  check('the posted device keeps the device serial and category',
    posted.device && posted.device.serial === 'SN-1' && posted.device.category === 'ip_phone',
    posted.device && JSON.stringify(posted.device));
  check('the medium posts its OWN reason and method, untouched',
    posted.media && posted.media.length === 1
      && posted.media[0].reason === 'physically_damaged'
      && posted.media[0].destroy_method === 'crushed',
    JSON.stringify(posted.media));

  runFailureCase('a 400 with a JSON message',
    { status: 400, body: JSON.stringify({ ok: false, error: 'Media 1: A serial is required.' }) },
    'A serial is required.', () => {

      runFailureCase('a 500 returning HTML, not JSON',
        { status: 500, body: '<br /><b>Fatal error</b>: Uncaught Error: boom' },
        'Save failed (HTTP 500)', () => {

          runFailureCase('an aborted request', 'abort',
            'did not answer within 30 seconds', () => {

              // ---- remove(): the only correction path for an attested record ----
              const mkBtn = () => {
                const b = makeNode('button');
                b.textContent = 'Remove';
                b.disabled = false;
                return b;
              };

              // Declining the confirm must send nothing at all.
              installResponse({ status: 200, body: JSON.stringify({ ok: true, removed: 1 }) });
              global.__reloaded = false;
              global.__confirmAnswer = false;
              window.ManualAssets.remove({ attested_kind: 'media', attested_id: 42, cocid: 'COC-9' }, mkBtn());
              setTimeout(() => {
                check('remove — declining the confirm sends no request', global.__reqs.length === 0,
                  JSON.stringify(global.__reqs));
                check('remove — the confirm names what will go', /this media item/.test(global.__lastConfirm),
                  global.__lastConfirm);

                // Accepting must DELETE the right record, with the job it belongs to.
                installResponse({ status: 200, body: JSON.stringify({ ok: true, removed: 1 }) });
                global.__reloaded = false;
                global.__confirmAnswer = true;
                const btn = mkBtn();
                window.ManualAssets.remove({ attested_kind: 'media', attested_id: 42, cocid: 'COC-9' }, btn);
                setTimeout(() => {
                  const del = global.__reqs.filter(r => r.method === 'DELETE');
                  check('remove — a media record DELETE goes to /api/certificates/media/{id}',
                    del.length === 1 && del[0].url.indexOf('/api/certificates/media/42?cocid=COC-9') !== -1,
                    JSON.stringify(del));
                  check('remove — the page reloads on success', global.__reloaded === true);

                  // A device removal must target the device route, which also takes its media.
                  installResponse({ status: 200, body: JSON.stringify({ ok: true, removed: 3 }) });
                  global.__reloaded = false;
                  global.__confirmAnswer = true;
                  window.ManualAssets.remove({ attested_kind: 'device', attested_id: 7, cocid: 'COC-1' }, mkBtn());
                  setTimeout(() => {
                    const del2 = global.__reqs.filter(r => r.method === 'DELETE');
                    check('remove — a device DELETE goes to /api/certificates/devices/{id}',
                      del2.length === 1 && del2[0].url.indexOf('/api/certificates/devices/7?cocid=COC-1') !== -1,
                      JSON.stringify(del2));
                    check('remove — a device confirm warns that its media go too',
                      /every medium recorded with it/.test(global.__lastConfirm), global.__lastConfirm);

                    // Failure: the operator must be told, and the button must come back.
                    installResponse({ status: 409, body: JSON.stringify({ ok: false, error: 'Not found.' }) });
                    global.__reloaded = false;
                    global.__alerts = [];
                    const fbtn = mkBtn();
                    window.ManualAssets.remove({ attested_kind: 'media', attested_id: 42, cocid: 'COC-9' }, fbtn);
                    setTimeout(() => {
                      check('remove — a failure alerts the server message',
                        global.__alerts.length === 1 && global.__alerts[0] === 'Not found.',
                        JSON.stringify(global.__alerts));
                      check('remove — the button is restored after a failure', fbtn.disabled === false);
                      check('remove — the button text is restored after a failure', fbtn.textContent === 'Remove');
                      check('remove — it does not reload on failure', global.__reloaded === false);

                      // A non-JSON (HTML) failure must not be reported as connectivity.
                      installResponse({ status: 500, body: '<b>Fatal error</b>: boom' });
                      global.__alerts = [];
                      window.ManualAssets.remove({ attested_kind: 'media', attested_id: 42, cocid: 'COC-9' }, mkBtn());
                      setTimeout(() => {
                        check('remove — an HTML failure is reported with its status',
                          /Could not remove it \(HTTP 500\)/.test(global.__alerts[0] || ''),
                          JSON.stringify(global.__alerts));

                        // An id-less row must not become a request to /media/undefined.
                        installResponse({ status: 200, body: JSON.stringify({ ok: true, removed: 1 }) });
                        global.__reqs = [];
                        window.ManualAssets.remove({ attested_kind: 'media', cocid: 'COC-9' }, mkBtn());
                        setTimeout(() => {
                          check('remove — a row with no id sends no request', global.__reqs.length === 0,
                            JSON.stringify(global.__reqs));

                          // ---- detail(): the read-only record view ----
                          // Until this existed, everything recorded at Add time (witness,
                          // evidence reference, attester, per-medium detail) was stored and
                          // printed on the certificate but invisible in the dashboard.
                          const devRow = {
                            attested_kind: 'device',
                            system: 'Mobile phone — Apple iPhone 8 (DEMO-IMEI-0001)',
                            sysserial: 'DEMO-IMEI-0001', category: 'mobile_phone',
                            make: 'Apple', model: 'iPhone 8',
                            reason: 'media_soldered', destroy_method: 'shredded',
                            destroy_vendor: 'Acme Shredding Ltd', destroy_date: '2026-10-13',
                            destroy_witness: 'J. Smith', evidence_ref: 'ACME-2026-1013-01',
                            attested_by: 'James Offen', cocid: 'COC-DEMO-2026-10-13',
                            attested_qualifier: 'operator-attested — not instrument-verified',
                            drives: [
                              // The API copies the unit's reason/method into a medium that
                              // arrived without them, so a stored media row ALWAYS carries them.
                              // Omitting them here would test a shape production never produces.
                              { type: 'eMMC/UFS (integrated)', size: '64 GB', make: 'Apple', model: 'iPhone 8', serial: 'DEMO-IMEI-0001', bus: '', reason: 'media_soldered', destroy_method: 'shredded' },
                            ],
                          };
                          window.ManualAssets.detail(devRow);
                          const dBody = textOf(byId['manual-detail-body']);
                          check('detail — the modal opens', byId['manual-detail-modal']._classes.has('hidden') === false);
                          check('detail — the title names the item',
                            byId['manual-detail-title'].textContent.indexOf('Apple iPhone 8') !== -1,
                            byId['manual-detail-title'].textContent);
                          check('detail — the qualifier is shown',
                            byId['manual-detail-sub'].textContent.indexOf('not instrument-verified') !== -1);
                          // Human labels, not the raw machine values.
                          check('detail — the reason is shown as a readable label', /Media soldered/.test(dBody), dBody.slice(0, 120));
                          check('detail — the method is shown as a readable label', /Shredded/.test(dBody));
                          check('detail — the witness is shown', /J\. Smith/.test(dBody));
                          check('detail — the evidence reference is shown', /ACME-2026-1013-01/.test(dBody));
                          check('detail — the attester is shown', /James Offen/.test(dBody));
                          check('detail — the Chain of Custody is shown', /COC-DEMO-2026-10-13/.test(dBody));
                          check('detail — the media table lists the medium',
                            /eMMC\/UFS \(integrated\)/.test(dBody) && /64 GB/.test(dBody));
                          check('detail — a medium matching the device says so, rather than implying the operator chose it',
                            /Same as the device/.test(dBody), dBody.slice(-180));
                          // A medium whose outcome genuinely differs must show ITS values, not the
                          // device's — that distinction is the whole reason the per-medium override
                          // exists (e.g. one drive pulled and wiped while the rest were shredded).
                          window.ManualAssets.detail(Object.assign({}, devRow, {
                            drives: [Object.assign({}, devRow.drives[0], { reason: 'physically_damaged', destroy_method: 'crushed' })],
                          }));
                          const dDiff = textOf(byId['manual-detail-body']);
                          check('detail — a medium with a different outcome shows its own, not the device\'s',
                            /Physically damaged/.test(dDiff) && /Crushed/.test(dDiff), dDiff.slice(-200));
                          check('detail — that medium is not mislabelled as matching the device',
                            !/Same as the device/.test(dDiff));
                          window.ManualAssets.detail(devRow);
                          check('detail — the scroll lock is applied',
                            global.document.body.style.overflow === 'hidden');

                          // The close paths are wired in wire(), so drive the real button
                          // rather than calling a helper the page never uses.
                          const dCloseBtn = byId['manual-detail-close'];
                          check('detail — the Close button is wired', !!(dCloseBtn._on && dCloseBtn._on.click));
                          // The guard only releases the page scroll lock when no OTHER modal
                          // needs it. Both branches are worth pinning down, because getting
                          // it wrong either traps the page scrolled-locked or unhides a
                          // still-open Add modal's backdrop scroll.
                          byId['manual-modal']._classes.add('hidden');
                          if (dCloseBtn._on && dCloseBtn._on.click) { dCloseBtn._on.click(); }
                          check('detail — Close hides the modal', byId['manual-detail-modal']._classes.has('hidden'));
                          check('detail — Close releases the scroll lock when nothing else is open',
                            global.document.body.style.overflow === '',
                            JSON.stringify(global.document.body.style.overflow));
                          window.ManualAssets.detail(devRow);
                          byId['manual-modal']._classes.delete('hidden');   // pretend Add is open
                          if (dCloseBtn._on && dCloseBtn._on.click) { dCloseBtn._on.click(); }
                          check('detail — Close keeps the scroll lock while the Add modal is open',
                            global.document.body.style.overflow === 'hidden',
                            JSON.stringify(global.document.body.style.overflow));
                          document.body.style.overflow = '';
                          byId['manual-modal']._classes.add('hidden');

                          // A standalone destroyed drive has no host, and the detail view must
                          // not invent one — the certificate prints "—" for the same reason.
                          window.ManualAssets.detail({
                            attested_kind: 'media', type: 'SSD', size: '500 GB', make: 'Samsung',
                            model: '870 EVO', serial: 'LOOSE-SN-9', bus: 'SATA',
                            reason: 'physically_damaged', destroy_method: 'crushed',
                            destroy_vendor: '', destroy_date: '2026-10-12', destroy_witness: '',
                            evidence_ref: '', attested_by: 'James Offen', cocid: 'COC-DEMO-2026-10-13',
                            system: '', sysserial: '',
                          });
                          const dBot = textOf(byId['manual-detail-body']);
                          check('detail — a hostless drive says so rather than showing a blank',
                            /No host recorded/.test(dBot), dBot.slice(0, 140));
                          check('detail — a hostless drive gets no media table',
                            !/MEDIA destroyed with this device/.test(dBot));
                          check('detail — unknown values render as a dash, not "undefined"',
                            !/undefined/.test(dBot));

                          // ---- actions(): the icon-button cell shared by both tables ----
                          installResponse({ status: 200, body: JSON.stringify({ ok: true, removed: 1 }) });
                          const cell = window.ManualAssets.actions(Object.assign({}, devRow, { attested_id: 5 }));
                          const abtns = cell.children.filter(c => c.tagName === 'BUTTON');
                          check('actions — two buttons are built', abtns.length === 2, abtns.length);
                          // Icon-only: the accessible name cannot come from the content, so an
                          // aria-label is mandatory — a glyph with only a tooltip is unusable
                          // with a screen reader and invisible on touch.
                          check('actions — each icon button has an accessible name',
                            abtns.every(b => String(b.getAttribute('aria-label') || '').length > 0),
                            abtns.map(b => b.getAttribute('aria-label')).join(', '));
                          check('actions — each icon button has a tooltip', abtns.every(b => !!b.title));
                          check('actions — each button holds an svg with one path',
                            abtns.every(b => b.children.length === 1 && b.children[0].children.length === 1));
                          check('actions — the icons differ (details vs remove)',
                            abtns[0].children[0].children[0].getAttribute('d') !== abtns[1].children[0].children[0].getAttribute('d'));
                          check('actions — the buttons are not labelled with words',
                            abtns.every(b => !/Details|Remove/.test(b.textContent || '')));

                          byId['manual-detail-modal']._classes.add('hidden');
                          abtns[0]._on.click();
                          check('actions — the Details button opens the record view',
                            byId['manual-detail-modal']._classes.has('hidden') === false);

                          global.__confirmAnswer = true;
                          global.__reqs = [];
                          abtns[1]._on.click();
                          setTimeout(() => {
                            const del = global.__reqs.filter(r => r.method === 'DELETE');
                            // attested_kind drives the route, so pin both: a device removal must
                            // go to /devices (which also takes its media) and a medium to /media.
                            check('actions — a device row removes via /api/certificates/devices',
                              del.length === 1 && del[0].url.indexOf('/api/certificates/devices/5?cocid=') !== -1,
                              JSON.stringify(global.__reqs));

                            global.__reqs = [];
                            global.__reloaded = false;
                            global.__confirmAnswer = true;
                            const mCell = window.ManualAssets.actions({ attested_kind: 'media', attested_id: 9, cocid: 'COC-1' });
                            const mBtns = mCell.children.filter(c => c.tagName === 'BUTTON');
                            mBtns[1]._on.click();
                            setTimeout(() => {
                              const mdl = global.__reqs.filter(r => r.method === 'DELETE');
                              check('actions — a media row removes via /api/certificates/media',
                                mdl.length === 1 && mdl[0].url.indexOf('/api/certificates/media/9?cocid=') !== -1,
                                JSON.stringify(global.__reqs));

                              console.log('\n' + count + ' checks, ' + fails + ' failed');
                              process.exit(fails === 0 ? 0 : 1);
                            }, 80);
                          }, 80);
                        }, 60);
                      }, 60);
                    }, 60);
                  }, 60);
                }, 60);
              }, 60);
            });
        });
    });
}, 110);
