// Tests for the offline certificate verifier: the shared verification module and
// the page that uses it.
//
// Why node can cover a browser page: the page has no verification logic of its
// own. Both input paths (camera scan and paste) end in the same verifyCode()
// from site/src/offline-verify.js, which is a plain ES module with no DOM and no
// network calls. So the crypto rules are tested here on every run, and the page
// tests below assert that the wiring really does route both paths into it.
//
// Run: node tests/offline_verify_page_test.cjs

const { webcrypto } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const ROOT = path.join(__dirname, '..');
const MODULE = path.join(ROOT, 'site/src/offline-verify.js');
const PAGE = path.join(ROOT, 'site/verify-offline.html');
const PAGE_JS = path.join(ROOT, 'site/src/verify-offline-page.js');
const SW = path.join(ROOT, 'site/public/sw-verify-offline.js');

let count = 0;
let fails = 0;
function check(label, ok) {
  count++;
  if (ok) { console.log('ok   - ' + label); } else { fails++; console.log('FAIL - ' + label); }
}
function eq(label, expected, actual) {
  const ok = expected === actual;
  check(label, ok);
  if (!ok) console.log('      expected: ' + JSON.stringify(expected) + '\n      actual:   ' + JSON.stringify(actual));
}

const b64url = (bytes) => Buffer.from(bytes).toString('base64')
  .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

async function newKey() {
  const kp = await webcrypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify']);
  const spki = Buffer.from(await webcrypto.subtle.exportKey('spki', kp.publicKey)).toString('base64');
  return { kp, spki };
}

async function signCode(priv, msg) {
  const sig = await webcrypto.subtle.sign({ name: 'Ed25519' }, priv, new TextEncoder().encode(msg));
  return msg + '|' + b64url(sig);
}

const CERT_A = 'COD-869047-DS-AIW-3693-QHMI';
const CERT_B = 'COD-111111-AA-BBB-2222-CCCC';
const AT = '2026-10-21T10:00:00Z';
const msgA = `2|test-1|${CERT_A}|${AT}`;
const msgB = `2|test-1|${CERT_B}|${AT}`;

(async () => {
  const mod = await import(pathToFileURL(MODULE).href);
  const { verifyCode, parseCode, keyFingerprint, PINNED_KEYS } = mod;

  const a = await newKey();
  const b = await newKey();
  const keys = { 'test-1': a.spki };
  const codeA = await signCode(a.kp.privateKey, msgA);
  const codeB = await signCode(a.kp.privateKey, msgB);

  // -------------------------------------------------------------------------
  // 1. The happy path
  // -------------------------------------------------------------------------
  const ok = await verifyCode(codeA, { keys });
  eq('a valid code verifies', true, ok.ok);
  eq('a valid code reports no reason', '', ok.reason);
  eq('the certificate id is returned', CERT_A, ok.certId);
  eq('the issue time is returned', AT, ok.issuedAt);
  eq('the key id is returned', 'test-1', ok.kid);

  // -------------------------------------------------------------------------
  // 2. Tampering — every signed field, and the signature itself
  // -------------------------------------------------------------------------
  const tampered = [
    ['the certificate id', codeA.replace('869047', '869048')],
    ['the issue time', codeA.replace('T10:00:00Z', 'T10:00:01Z')],
    ['the key id', codeA.replace('test-1', 'test-2')],
    ['the signature', codeA.slice(0, -2) + (codeA.endsWith('AA') ? 'BB' : 'AA')]
  ];
  for (const [what, code] of tampered) {
    const r = await verifyCode(code, { keys: { ...keys, 'test-2': a.spki } });
    check('tampering with ' + what + ' fails', r.ok === false);
    check('tampering with ' + what + ' is not an unknown-key excuse', r.reason !== 'unsupported');
  }
  eq('an edited certificate id is a signature failure', 'bad-signature',
    (await verifyCode(codeA.replace('869047', '869048'), { keys })).reason);

  // A signature lifted from another certificate must not transfer.
  const swapA = msgA + '|' + codeB.split('|')[4];
  eq('a signature taken from another certificate is rejected', 'bad-signature',
    (await verifyCode(swapA, { keys })).reason);

  // -------------------------------------------------------------------------
  // 3. Trust: the key is pinned, never taken from the payload
  // -------------------------------------------------------------------------
  eq('an unknown kid is rejected', 'unknown-key', (await verifyCode(codeA, { keys: {} })).reason);
  check('an unknown kid never verifies', (await verifyCode(codeA, { keys: {} })).ok === false);
  // Non-empty map, none of them the code's kid: the realistic case (the page
  // pins one or two keys). Falling back to "some pinned key" must be a
  // different answer than "this kid is unknown", or a reader cannot tell a
  // forged key id from a bad signature.
  eq('an unknown kid is rejected even when other keys are pinned', 'unknown-key',
    (await verifyCode(codeA, { keys: { 'someone-else-1': b.spki } })).reason);

  const forged = await signCode(b.kp.privateKey, msgA);           // foreign key, our kid
  eq('a code signed by another key under our kid is rejected', 'bad-signature',
    (await verifyCode(forged, { keys })).reason);

  // Rotation: a pinned second kid keeps old certificates verifiable.
  const rotated = { 'test-1': a.spki, 'test-0': b.spki };
  check('an old kid still verifies after a rotation', (await verifyCode(codeA, { keys: rotated })).ok === true);

  // -------------------------------------------------------------------------
  // 4. The paper: the printed certificate id
  // -------------------------------------------------------------------------
  check('matching printed id verifies', (await verifyCode(codeA, { keys, expectCertId: CERT_A })).ok === true);
  eq('a different printed id is a mismatch', 'cert-mismatch',
    (await verifyCode(codeA, { keys, expectCertId: CERT_B })).reason);
  check('a mismatch is not "verified"',
    (await verifyCode(codeA, { keys, expectCertId: CERT_B })).ok === false);

  // -------------------------------------------------------------------------
  // 5. Malformed input
  // -------------------------------------------------------------------------
  const parts = codeA.split('|');
  const withField = (i, v) => { const p = parts.slice(); p[i] = v; return p.join('|'); };
  const bad = {
    empty: '',
    'four fields': parts.slice(0, 4).join('|'),
    'six fields': codeA + '|extra',
    'unknown version': withField(0, '3'),
    'version 1 (the withdrawn format)': withField(0, '1'),
    'pipe inside the certificate id': '2|test-1|COD|1|' + AT + '|' + parts[4],
    'space instead of T': withField(3, '2026-10-21 10:00:00'),
    'no Z suffix': withField(3, '2026-10-21T10:00:00'),
    'non-base64 signature': withField(4, 'not a signature!'),
    'empty signature': withField(4, '')
  };
  for (const [label, code] of Object.entries(bad)) {
    check('rejected: ' + label, (await verifyCode(code, { keys })).ok === false);
  }
  eq('parseCode explains the version problem', 'bad-version', parseCode(withField(0, '1')).reason);

  // The printed QR is a URL with the code in the fragment, so a phone's ordinary
  // camera opens the verifier instead of showing text. Pasting that URL must work
  // exactly like pasting the bare code.
  const asUrl = 'https://tscrub.com/verify-offline#c=' + codeA;
  eq('a pasted verifier URL unwraps to the same certificate id', CERT_A, parseCode(asUrl).certId);
  check('a pasted verifier URL verifies', (await verifyCode(asUrl, { keys })).ok === true);
  check('a pasted URL with a query (?c=) verifies too',
    (await verifyCode('https://tscrub.com/verify-offline?c=' + encodeURIComponent(codeA), { keys })).ok === true);
  check('a tampered URL still fails',
    (await verifyCode(asUrl.replace('869047', '869048'), { keys })).ok === false);

  // -------------------------------------------------------------------------
  // 6. The pinned key is the published one
  // -------------------------------------------------------------------------
  const PUBLISHED = 'be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02';
  eq('the pinned key id matches the published key document', 'tscrub-vendor-1', Object.keys(PINNED_KEYS)[0]);
  eq('the pinned key fingerprint matches the published one', PUBLISHED,
    await keyFingerprint(PINNED_KEYS['tscrub-vendor-1']));
  check('the pinned key is NOT the SPKI hash (a different value entirely)',
    (await keyFingerprint(PINNED_KEYS['tscrub-vendor-1'])) !== '9e0b73fe23da0fb22d94f3bda5fd837645a4fc6c005de5efe496acbe7c00c3f4');

  // -------------------------------------------------------------------------
  // 7. Verification must not need the network
  // -------------------------------------------------------------------------
  const src = fs.readFileSync(MODULE, 'utf8');
  check('the verification module makes no network calls', !/\bfetch\s*\(/.test(src));
  check('the verification module imports nothing', !/^\s*import\s/m.test(src));

  // -------------------------------------------------------------------------
  // 8. The page routes BOTH input paths into verifyCode()
  // -------------------------------------------------------------------------
  const pageJs = fs.readFileSync(PAGE_JS, 'utf8');
  const html = fs.readFileSync(PAGE, 'utf8');
  const sw = fs.readFileSync(SW, 'utf8');

  check('the page imports the shared verifier', /from '\.\/offline-verify\.js'/.test(pageJs));
  check('the paste button verifies', /checkBtn\.addEventListener\('click',[\s\S]{0,40}runVerify\(/.test(pageJs));
  check('the camera scan verifies the decoded value', /runVerify\(value\)/.test(pageJs));
  check('both paths go through one function', /async function runVerify\(/.test(pageJs)
    && (pageJs.match(/await verifyCode\(/g) || []).length === 1);
  check('the camera button is hidden without a secure context', /!isSecure/.test(pageJs) && /scanBtn\.hidden = true/.test(pageJs));
  check('the page explains what a pass does not prove',
    /does not, on its own, prove which drives were erased/.test(html));
  check('the page shows the trusted key fingerprint', /id="ov-fingerprint"/.test(html));
  // The panel used to wipe its own container (resultEl.innerHTML = …), which
  // deleted #ov-detail and painted the verified details into a detached node —
  // a pass showed a heading and nothing else. Only the browser found it, so the
  // structure is pinned here too.
  check('the result panel never wipes its own container', !/resultEl\.innerHTML\s*=/.test(pageJs));
  check('the result title is set on its own element', /titleEl\.textContent\s*=/.test(pageJs));
  check('the page has a title element inside the result panel', /id="ov-title"/.test(html));
  check('the page offers the online record after a pass', /verify\?cert=/.test(pageJs));
  check('the page registers the service worker', /navigator\.serviceWorker\.register\('\/sw-verify-offline\.js'\)/.test(pageJs));
  // Reported by the operator: scanning showed the heading "Verify a certificate
  // offline" with the verdict only at the bottom of the page. A scan already
  // carries the code, so the answer must come first.
  check('the result panel sits ABOVE the form in the document',
    html.indexOf('id="ov-result"') < html.indexOf('id="ov-form"'));
  check('the page heading becomes the verdict', /h1El\.textContent\s*=\s*'Genuine certificate'/.test(pageJs));
  check('a failed check also says so in the heading', /h1El\.textContent\s*=\s*'Certificate not verified'/.test(pageJs));
  check('the form steps out of the way once there is a verdict',
    /formEl\.hidden = true/.test(pageJs) && /introEl\.hidden = true/.test(pageJs));
  check('the form comes back on request', /againWrap\.addEventListener\('click', resetToForm\)/.test(pageJs));
  check('a manual check scrolls the verdict into view', /scrollIntoView\(\{ block: 'start'/.test(pageJs));
  check('the verdict is announced to screen readers', /role="status"/.test(html) && /aria-live="polite"/.test(html));
  // Tapping a link for a different certificate while the verifier is already
  // open changes only the fragment, which fires no navigation.
  check('a changed #c= fragment re-verifies', /addEventListener\('hashchange', verifyFromHash\)/.test(pageJs));
  check('the fragment path is shared by the first load and later changes',
    /function verifyFromHash\(/.test(pageJs) && /verifyFromHash\(\);/.test(pageJs));

  // -------------------------------------------------------------------------
  // 9. The service worker actually caches the page
  // -------------------------------------------------------------------------
  check('the service worker precaches the page', /const PAGE = '\/verify-offline\.html'/.test(sw));
  check('the service worker caches on install', /caches\.open\(CACHE\)[\s\S]*precachePage\(/.test(sw));
  // Precaching only the HTML is not enough: the bundle names are hashed, and
  // fetch interception only starts after activation, so the first visit would
  // cache nothing the page needs to run.
  check('the install step reads the page for its assets', /matchAll\([\s\S]*\\\/assets\\\//.test(sw));
  check('the install step caches those assets', /cache\.put\(u, r\)/.test(sw));
  check('the service worker is cache-first for hashed assets', /pathname\.startsWith\('\/assets\/'\)/.test(sw));
  check('the page is stale-while-revalidate, so a deploy reaches an installed verifier',
    /Serve the cached copy immediately, then refresh it/.test(sw));
  check('the service worker has a cache version to bump', /const CACHE = 'tscrub-verify-offline-v\d+'/.test(sw));

  console.log(`\n${count} checks, ${fails} failed`);
  process.exit(fails === 0 ? 0 : 1);
})().catch((e) => {
  console.error('harness error: ' + (e && e.stack ? e.stack : e));
  process.exit(2);
});
