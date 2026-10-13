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
function check(label, ok) {
  count++;
  if (ok) console.log('ok   - ' + label);
  else { fails++; console.log('FAIL - ' + label); }
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
  global.AbortController = class {
    constructor() { this.signal = { aborted: false }; }
    abort() { this.signal.aborted = true; }
  };
}

installHarness(() => Promise.reject(new Error('no fetch installed')));
eval(src + '\n//# sourceURL=manual-assets.js');

// The module resolves the form from #manual-modal-body, so that is the node to
// populate — building a detached one would exercise a different path from the page.
function fillValidForm() {
  const b = byId['manual-modal-body'];
  const cocid = makeNode('input');
  cocid.setAttribute('name', 'cocid');
  cocid.value = 'COC-1';

  const media = makeNode('div');
  media.id = 'manual-modal-media';
  const row = makeNode('div');
  ['type', 'size', 'make', 'model', 'serial', 'bus', 'reason', 'destroy_method'].forEach(k => {
    const f = makeNode('input');
    f.setAttribute('name', k);
    // A real input reflects `name`/`value` as properties, which is how collect()
    // reads them; setting only the attribute would silently produce empty rows.
    f.name = k;
    f.value = k === 'serial' ? 'SN-1' : (k === 'reason' ? 'media_soldered' : (k === 'destroy_method' ? 'shredded' : 'x'));
    row.appendChild(f);
  });
  row.querySelectorAll = () => row.children;
  media.appendChild(row);

  b.children = [];
  b.appendChild(cocid);
  b.appendChild(media);

  b.querySelector = (sel) => {
    if (sel === '#manual-modal-media') return media;
    const m = /\[name=([^\]]+)\]/.exec(sel);
    if (m) {
      for (let i = 0; i < b.children.length; i++) {
        if (b.children[i].getAttribute('name') === m[1]) return b.children[i];
      }
    }
    return null;
  };
  return b;
}

function installResponse(response) {
  installHarness((url, opts) => {
    void opts;
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
  check('a 201 success — the page reloads', global.__reloaded === true);
  check('a 201 success — the modal closes', byId['manual-modal']._classes.has('hidden'));
  check('a 201 success — no error is shown', (byId['manual-modal-error'].textContent || '') === '');
  // Deliberately not re-enabled: the page is about to reload, and re-enabling the
  // button would invite a second, duplicate record during that window.
  check('a 201 success — the button is not re-enabled (the page is reloading)',
    byId['manual-modal-save'].disabled === true);

  runFailureCase('a 400 with a JSON message',
    { status: 400, body: JSON.stringify({ ok: false, error: 'Media 1: A serial is required.' }) },
    'A serial is required.', () => {

      runFailureCase('a 500 returning HTML, not JSON',
        { status: 500, body: '<br /><b>Fatal error</b>: Uncaught Error: boom' },
        'Save failed (HTTP 500)', () => {

          runFailureCase('an aborted request', 'abort',
            'did not answer within 30 seconds', () => {
              console.log('\n' + count + ' checks, ' + fails + ' failed');
              process.exit(fails === 0 ? 0 : 1);
            });
        });
    });
}, 110);
