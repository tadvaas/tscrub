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
    querySelector(sel) { return matches(this, sel)[0] || null; },
    querySelectorAll(sel) { return matches(this, sel); },
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
  // #id: match the node property OR the attribute — el() sets ids via setAttribute.
  if (sel.charAt(0) === '#') {
    const idv = sel.slice(1);
    return all.filter(n => n.id === idv || n.getAttribute('id') === idv);
  }
  // A bare tag selector ('select'), which open() uses to reach the method field
  // inside its label wrapper.
  if (/^[a-zA-Z]+$/.test(sel)) { return all.filter(n => n.tagName === sel.toUpperCase()); }
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

  // The unified device form: identity fields always save, and the attestation
  // section decides the record type. The stub mirrors the real layout — the
  // device modal has no media box at all.
  const identity = fieldset(['serial', 'model', 'note'],
    { serial: 'SN-1', model: '7942G', note: 'pulled media' });

  const att = makeNode('div');
  att.setAttribute('id', 'manual-attest-section');
  att.classList.add('hidden');   // the default: a register entry
  att.appendChild(fieldset(['category', 'make'],
    { category: 'ip_phone', make: 'Cisco' }));
  att.appendChild(fieldset(
    ['reason', 'destroy_method', 'destroy_vendor', 'destroy_date', 'destroy_witness', 'evidence_ref'],
    { reason: 'not_supported_by_appliance', destroy_method: 'factory_reset',
      destroy_vendor: '', destroy_date: '2026-10-16', destroy_witness: '', evidence_ref: '' }));

  b.children = [];
  b.appendChild(cocid);
  b.appendChild(identity);
  b.appendChild(att);

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
  // The closed attestation section means a REGISTER entry: identity + job, with no
  // destruction claim anywhere. That is the default the operator gets.
  const posted = JSON.parse(
    ((global.__reqs.filter(r => r.url.indexOf('/api/devices/declared') !== -1)[0] || {}).body) || '{}');
  check('the posted record is a register entry, not an attestation',
    posted.cocid === 'COC-1' && posted.serial === 'SN-1' && posted.model === '7942G'
      && posted.note === 'pulled media',
    JSON.stringify(posted));
  check('the device payload carries no media key — media live on the Media tab',
    !('media' in posted) && !('device' in posted),
    JSON.stringify(posted));

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
                      /every media item recorded with it/.test(global.__lastConfirm), global.__lastConfirm);

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
                          check('actions — three buttons are built (details, edit, remove)', abtns.length === 3, abtns.length);
                          // Icon-only: the accessible name cannot come from the content, so an
                          // aria-label is mandatory — a glyph with only a tooltip is unusable
                          // with a screen reader and invisible on touch.
                          check('actions — each icon button has an accessible name',
                            abtns.every(b => String(b.getAttribute('aria-label') || '').length > 0),
                            abtns.map(b => b.getAttribute('aria-label')).join(', '));
                          check('actions — each icon button has a tooltip', abtns.every(b => !!b.title));
                          check('actions — each button holds an svg with one path',
                            abtns.every(b => b.children.length === 1 && b.children[0].children.length === 1));
                          check('actions — the icons differ',
                            new Set(abtns.map(b => b.children[0].children[0].getAttribute('d'))).size === 3);
                          check('actions — the buttons are not labelled with words',
                            abtns.every(b => !/Details|Edit|Remove/.test(b.textContent || '')));

                          byId['manual-detail-modal']._classes.add('hidden');
                          abtns[0]._on.click();
                          check('actions — the Details button opens the record view',
                            byId['manual-detail-modal']._classes.has('hidden') === false);

                          // ---- edit mode: the same modal, pre-filled, saving with PUT ----
                          abtns[1]._on.click();
                          check('actions — Edit opens the modal in edit mode',
                            /Edit attested/.test(byId['manual-modal-title'].textContent), byId['manual-modal-title'].textContent);
                          check('actions — Edit relabels the save button',
                            byId['manual-modal-save'].textContent === 'Save changes', byId['manual-modal-save'].textContent);
                          const edBody = byId['manual-modal-body'];
                          const edCocid = edBody.querySelector('[name=cocid]');
                          check('actions — Edit locks the COCID to the record\'s job',
                            !!edCocid && edCocid.disabled === true && edCocid.value === 'COC-DEMO-2026-10-13',
                            edCocid && edCocid.value);
                          const edSerial = edBody.querySelector('[name=serial]');
                          check('actions — Edit pre-fills the serial',
                            !!edSerial && edSerial.value === 'DEMO-IMEI-0001', edSerial && edSerial.value);
                          const edMethod = edBody.querySelector('select[name=destroy_method]');
                          check('actions — Edit pre-selects the destruction method',
                            !!edMethod && edMethod.value === 'shredded', edMethod && edMethod.value);
                          check('actions — Edit shows no media block — media live on the Media tab',
                            (edBody.querySelectorAll('[name=type]') || []).length === 0);
                          // Editing must reach the update route for THIS record, and with PUT:
                          // the module is evaluated as source here, so the request shape is
                          // asserted on the code rather than by firing the saved form.
                          check('edit — saves with PUT to the record\'s own url',
                            /method: isEdit \? 'PUT' : 'POST'/.test(src)
                              && /'\/api\/certificates\/devices\/' \+ encodeURIComponent\(editing\.attested_id\)/.test(src));
                          check('edit — a new record is still a POST, routed by the section',
                            /'\/api\/certificates\/devices'/.test(src)
                              && /'\/api\/certificates\/media'/.test(src)
                              && /'\/api\/devices\/declared'/.test(src));

                          // A NEW device has no media block — devices are recorded on the
                          // Devices tab, media on the Media tab. save() used to call
                          // collect(null) and throw before anything was sent — the Save click
                          // silently did nothing. Fire the real save against an injected 409
                          // and assert the request WAS built and the failure surfaced: that
                          // proves the collect path ran without throwing, end to end.
                          installResponse({ status: 409, body: JSON.stringify({ ok: false, error: 'injected' }) });
                          global.__reqs = [];
                          window.ManualAssets.openFor('device');
                          const nb = byId['manual-modal-body'];
                          const nv = (name, value) => { const f = nb.querySelector('[name=' + name + ']'); if (f) { f.value = value; } };
                          nv('cocid', 'COC-TEST'); nv('category', 'Laptop');
                          nv('make', 'Test'); nv('model', 'Unit'); nv('serial', 'TEST-SN-1');
                          nv('destroy_method', 'shredded'); nv('reason', 'other');
                          nv('destroy_vendor', ''); nv('destroy_date', '2026-11-19');
                          nv('destroy_witness', ''); nv('evidence_ref', '');
                          const nbWalk = [];
                          (function walk(n) { (n.children || []).forEach(c => { nbWalk.push(c); walk(c); }); })(nb);
                          const nbBox = nbWalk.find(n => n.getAttribute('id') === 'manual-modal-media');
                          check('save — a new device has no media block',
                            !nbBox);
                          let threw = null;
                          try { byId['manual-modal-save']._on.click(); } catch (e) { threw = e; }
                          check('save — clicking Record on a new device does not throw',
                            threw === null, threw && threw.message);
                          setTimeout(() => {
                            const post = global.__reqs.filter(r => r.method === 'POST');
                            let bodyNoMedia = 'no-request';
                            if (post.length) { try { bodyNoMedia = !('media' in JSON.parse(post[0].body)); } catch (e) { bodyNoMedia = 'bad-json'; } }
                            check('save — a new device posts to /api/devices/declared',
                              post.length === 1 && post[0].url === '/api/devices/declared',
                              JSON.stringify(global.__reqs.map(r => r.url)));
                            check('save — the payload carries no media key, not a crash',
                              bodyNoMedia === true, bodyNoMedia);
                            check('save — the server message is shown verbatim',
                              byId['manual-modal-error']._classes.has('hidden') === false
                                && /injected/.test(textOf(byId['manual-modal-error'])));
                            check('save — the button is restored after the failure',
                              byId['manual-modal-save'].textContent === 'Add device'
                                && byId['manual-modal-save'].disabled === false);

                            // Editing a device that ALREADY has no media must save too: the
                            // blank edit row collects to [], and the "needs a medium" gate must
                            // not fire for an edit or a media-less record becomes un-editable.
                            installResponse({ status: 409, body: JSON.stringify({ ok: false, error: 'injected-edit' }) });
                            global.__reqs = [];
                            window.ManualAssets.open('device', {
                              attested_id: 7, cocid: 'COC-TEST', category: 'laptop',
                              make: 'Test', model: 'Unit', sysserial: 'TEST-SN-2',
                              reason: 'other', destroy_method: 'shredded', destroy_vendor: '',
                              destroy_date: '', destroy_witness: '', evidence_ref: '', drives: []
                            });
                            // The device modal carries no media rows at all — the edit must not
                            // send a media key, or the server would reconcile the set.
                            const allNodes = [];
                            (function walk(n) { (n.children || []).forEach(c => { allNodes.push(c); walk(c); }); })(byId['manual-modal-body']);
                            check('edit — the device edit has no media box',
                              !allNodes.some(n => n.getAttribute('id') === 'manual-modal-media'));
                            let threw2 = null;
                            try { byId['manual-modal-save']._on.click(); } catch (e) { threw2 = e; }
                            // The save chains through the CSRF fetch, so the PUT lands a tick later.
                            setTimeout(() => {
                              const put = global.__reqs.filter(r => r.method === 'PUT');
                              check('edit — a media-less device still saves without throwing',
                                threw2 === null, threw2 && threw2.message);
                              check('edit — it PUTs to the record with no media key, leaving media alone',
                                put.length === 1 && put[0].url === '/api/certificates/devices/7'
                                  && (() => { try { return !('media' in JSON.parse(put[0].body)); } catch (e) { return false; } })(),
                                JSON.stringify(global.__reqs));
                              check('edit — the injected failure is shown, not the media gate',
                                /injected-edit/.test(textOf(byId['manual-modal-error']))
                                  && !/media item/.test(textOf(byId['manual-modal-error'])),
                                textOf(byId['manual-modal-error']));

                              // ONE device form: identity fields always save as a register
                              // entry; opening the attestation section turns the same save
                              // into an attested destruction record.
                              installResponse({ status: 409, body: JSON.stringify({ ok: false, error: 'already on file' }) });
                              global.__reqs = [];
                              window.ManualAssets.openFor('device');
                              const rbWalk = [];
                              (function walk(n) { (n.children || []).forEach(c => { rbWalk.push(c); walk(c); }); })(byId['manual-modal-body']);
                              const attSectionEl = rbWalk.find(n => n.getAttribute('id') === 'manual-attest-section');
                              const attDisclose = rbWalk.find(n => n.tagName === 'BUTTON' && /attest the outcome/.test(n.textContent || ''));
                              check('add — the device form is one form, no mode switch',
                                !rbWalk.some(n => n.tagName === 'BUTTON' && /Record device only|Attest destruction/.test(n.textContent || '')));
                              const rb = byId['manual-modal-body'];
                              check('add — the form has a COCID, serial, model and note',
                                !!rb.querySelector('[name=cocid]') && !!rb.querySelector('[name=serial]')
                                  && !!rb.querySelector('[name=model]') && !!rb.querySelector('[name=note]'));
                              check('add — it has no media box and the attestation starts hidden',
                                !rb.querySelector('#manual-modal-media') && !!attSectionEl && attSectionEl._classes.has('hidden'));
                              check('add — the attestation sits behind one disclosure',
                                !!attDisclose, rbWalk.filter(n => n.tagName === 'BUTTON').map(b => b.textContent).join(', '));
                              check('add — the title says what the record is',
                                byId['manual-modal-title'].textContent === 'Add a device',
                                byId['manual-modal-title'].textContent);
                              rb.querySelector('[name=cocid]').value = 'COC-REG';
                              rb.querySelector('[name=serial]').value = 'REG-SN-1';
                              rb.querySelector('[name=model]').value = 'Test Unit';
                              rb.querySelector('[name=note]').value = 'bench';
                              byId['manual-modal-save']._on.click();
                              setTimeout(() => {
                                const regPost = global.__reqs.filter(r => r.method === 'POST');
                                let regBody = null;
                                if (regPost.length) { try { regBody = JSON.parse(regPost[0].body); } catch (e) { regBody = { bad: true }; } }
                                check('add — the closed-section save posts a register entry',
                                  regPost.length === 1 && regPost[0].url === '/api/devices/declared',
                                  JSON.stringify(global.__reqs.map(r => r.url)));
                                check('add — the register payload is identity plus its job',
                                  !!regBody && regBody.cocid === 'COC-REG' && regBody.serial === 'REG-SN-1'
                                    && regBody.model === 'Test Unit' && regBody.note === 'bench'
                                    && !('media' in regBody) && !('device' in regBody),
                                  JSON.stringify(regBody));
                                check('add — the duplicate message is shown verbatim and the button restored',
                                  /already on file/.test(textOf(byId['manual-modal-error']))
                                    && byId['manual-modal-save'].textContent === 'Add device'
                                    && byId['manual-modal-save'].disabled === false,
                                  textOf(byId['manual-modal-error']));
                                // The COCID ties every device record to its job — required, and the
                                // refusal happens BEFORE any request is built.
                                global.__reqs = [];
                                window.ManualAssets.openFor('device');
                                const rb2 = byId['manual-modal-body'];
                                rb2.querySelector('[name=serial]').value = 'REG-SN-2';
                                byId['manual-modal-save']._on.click();
                                check('add — the COCID is required',
                                  global.__reqs.length === 0
                                    && /A Chain of Custody ID is required/.test(textOf(byId['manual-modal-error'])),
                                  JSON.stringify(global.__reqs) + ' | ' + textOf(byId['manual-modal-error']));
                                // Opening the disclosure turns the SAME form into an attestation.
                                global.__reqs = [];
                                const rb3 = byId['manual-modal-body'];
                                const rb3Walk = [];
                                (function walk(n) { (n.children || []).forEach(c => { rb3Walk.push(c); walk(c); }); })(rb3);
                                const disc = rb3Walk.find(n => n.tagName === 'BUTTON' && /attest the outcome/.test(n.textContent || ''));
                                disc._on.click();
                                const rb3Section = rb3Walk.find(n => n.getAttribute('id') === 'manual-attest-section');
                                check('add — the disclosure opens the attestation section',
                                  rb3Section && !rb3Section._classes.has('hidden'));
                                rb3.querySelector('[name=cocid]').value = 'COC-REG';
                                rb3.querySelector('[name=serial]').value = 'REG-SN-1';
                                rb3.querySelector('[name=model]').value = 'Test Unit';
                                rb3.querySelector('[name=note]').value = 'bench';
                                rb3.querySelector('[name=category]').value = 'laptop';
                                rb3.querySelector('[name=make]').value = 'Test';
                                rb3.querySelector('[name=reason]').value = 'other';
                                rb3.querySelector('[name=destroy_method]').value = 'shredded';
                                byId['manual-modal-save']._on.click();
                                setTimeout(() => {
                                  const attPost = global.__reqs.filter(r => r.method === 'POST');
                                  let attBody = null;
                                  if (attPost.length) { try { attBody = JSON.parse(attPost[0].body); } catch (e) { attBody = { bad: true }; } }
                                  check('add — the open-section save posts an attested device',
                                    attPost.length === 1 && attPost[0].url === '/api/certificates/devices',
                                    JSON.stringify(global.__reqs.map(r => r.url)));
                                  check('add — the attested payload carries the device and no media key',
                                    !!attBody && !!attBody.device && attBody.device.category === 'laptop'
                                      && attBody.device.reason === 'other' && attBody.device.destroy_method === 'shredded'
                                      && attBody.device.note === 'bench' && attBody.cocid === 'COC-REG'
                                      && !('media' in attBody),
                                    JSON.stringify(attBody));
                                  // An EDIT shows the attestation section outright — the record IS an
                                  // attestation — with no disclosure and no mode switch.
                                  window.ManualAssets.open('device', { attested_id: 3, cocid: 'COC-1', category: 'laptop', make: 'T', model: 'M', sysserial: 'SN-9', note: 'n1', reason: 'other', destroy_method: 'shredded', destroy_vendor: '', destroy_date: '', destroy_witness: '', evidence_ref: '', drives: [] });
                                  const edWalk2 = [];
                                  (function walk(n) { (n.children || []).forEach(c => { edWalk2.push(c); walk(c); }); })(byId['manual-modal-body']);
                                  const edSection = edWalk2.find(n => n.getAttribute('id') === 'manual-attest-section');
                                  check('edit — the attestation section is shown outright, no disclosure',
                                    !!edSection && !edSection._classes.has('hidden')
                                      && !edWalk2.some(n => n.tagName === 'BUTTON' && /attest the outcome/.test(n.textContent || '')));
                                  check('edit — the note field carries the stored note',
                                    !!byId['manual-modal-body'].querySelector('[name=note]')
                                      && byId['manual-modal-body'].querySelector('[name=note]').value === 'n1');
                                  // The remove flow below expects a 200 again.
                                  installResponse({ status: 200, body: JSON.stringify({ ok: true, removed: 1 }) });

                          global.__confirmAnswer = true;
                          global.__reqs = [];
                          abtns[2]._on.click();
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
                            mBtns[2]._on.click();
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
              }, 60);
              }, 60);
              }, 60);
              }, 60);
            });
        });
    });
}, 110);
