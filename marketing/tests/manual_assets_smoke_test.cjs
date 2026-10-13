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
    querySelector() { return null; },
    querySelectorAll() { return []; },
  };
  n.classList._o = n;
  return n;
}

const byId = {};
['manual-modal', 'manual-modal-title', 'manual-modal-sub', 'manual-modal-save',
 'manual-modal-body', 'manual-modal-close', 'manual-modal-backdrop',
 'manual-modal-error'].forEach(id => { const n = makeNode('div'); n.id = id; byId[id] = n; });

global.document = {
  readyState: 'complete',
  body: makeNode('body'),
  getElementById(id) { return byId[id] || null; },
  querySelector() { return null; },
  addEventListener() {},
  createElement: makeNode,
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
function fieldset(names) {
  const wrap = makeNode('div');
  names.forEach(k => {
    const f = makeNode('input');
    f.setAttribute('name', k);
    // A real input reflects `name`/`value` as properties, which is how collect() and
    // save() read them; setting only the attribute would silently produce empty rows.
    f.name = k;
    f.value = k === 'serial' ? 'SN-1'
      : (k === 'reason' ? 'media_soldered'
      : (k === 'destroy_method' ? 'shredded' : 'x'));
    wrap.appendChild(f);
  });
  wrap.querySelectorAll = () => wrap.children;
  return wrap;
}

function fillValidForm() {
  const b = byId['manual-modal-body'];
  const cocid = makeNode('input');
  cocid.setAttribute('name', 'cocid');
  cocid.name = 'cocid';
  cocid.value = 'COC-1';

  const deviceFields = fieldset(['category', 'make', 'model', 'serial']);
  const deviceSerial = deviceFields.children.filter(c => c.name === 'serial')[0];

  const media = makeNode('div');
  media.id = 'manual-modal-media';
  const row = fieldset(['type', 'size', 'make', 'model', 'serial', 'bus']);
  media.appendChild(row);

  const deviceEvidence = fieldset(['reason', 'destroy_method', 'destroy_vendor', 'destroy_date', 'destroy_witness', 'evidence_ref']);

  b.children = [];
  b.appendChild(cocid);
  b.appendChild(deviceFields);
  b.appendChild(media);
  b.appendChild(deviceEvidence);

  b.querySelector = (sel) => {
    if (sel === '#manual-modal-media') return media;
    const m = /\[name=([^\]]+)\]/.exec(sel);
    if (m) {
      // First match in document order, exactly as querySelector behaves — which is why
      // the device's own serial wins over the media row's.
      for (let i = 0; i < b.children.length; i++) {
        const c = b.children[i];
        if (c.getAttribute('name') === m[1]) return c;
        for (let j = 0; j < c.children.length; j++) {
          if (c.children[j].getAttribute('name') === m[1]) return c.children[j];
        }
      }
    }
    return null;
  };
  void deviceSerial;
  return b;
}

function installResponse(response) {
  installHarness((url, opts) => {
    global.__reqs.push({ url: String(url), method: (opts && opts.method) || 'GET' });
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
                check('remove — the confirm names what will go', /this drive/.test(global.__lastConfirm),
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

                          console.log('\n' + count + ' checks, ' + fails + ' failed');
                          process.exit(fails === 0 ? 0 : 1);
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
