// Page controller for /verify-offline.
//
// Two input paths — camera scan and paste — and one verifier: everything ends in
// verifyCode() from ./offline-verify.js, so a bug cannot exist on one path only.
//
// The camera needs a secure context (https or localhost). A copy of this page
// saved to disk as file:// therefore cannot scan; the paste box always works and
// the page says so rather than showing a button that silently fails.

import { verifyCode, parseCode, keyFingerprint, PINNED_KEYS } from './offline-verify.js';

const KID = 'tscrub-vendor-1';

const $ = (id) => document.getElementById(id);
const statusEl = $('ov-status');
const resultEl = $('ov-result');
const titleEl = $('ov-title');
const detailEl = $('ov-detail');
const codeEl = $('ov-code');
const expectEl = $('ov-expect');
const scanBtn = $('ov-scan');
const checkBtn = $('ov-check');
const video = $('ov-video');
const fpEl = $('ov-fingerprint');

function setStatus(text, kind) {
  statusEl.textContent = text;
  statusEl.className = 'ov-status' + (kind ? ' ov-' + kind : '');
  statusEl.hidden = text === '';
}

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

const REASON_TEXT = {
  malformed: 'The code is not in the expected format. It may have been mistyped or damaged in printing.',
  'bad-version': 'This code uses a format this page does not know. Update the verifier, or use the online check.',
  'unknown-key': 'The code names a signing key this verifier does not know. It was not issued by us, or it was issued under a retired key and this copy of the verifier is newer than it.',
  'bad-signature': 'The signature does not match the code. It has been altered, or it belongs to a different certificate.',
  'cert-mismatch': 'The signature is valid, but the Certificate ID in the code is not the one you entered.',
  unsupported: 'This browser cannot check Ed25519 signatures. Try a current browser, or use the online check.'
};

function showResult(res) {
  resultEl.hidden = false;
  // Never write innerHTML on resultEl: that would delete #ov-title/#ov-detail,
  // and the detached node we captured above would then be painted off-screen —
  // the panel would show a heading and nothing else. (Found in the browser, not
  // in the unit tests.)
  if (res.ok) {
    resultEl.className = 'ov-result ov-pass';
    titleEl.textContent = 'Genuine tScrub certificate code';
    detailEl.innerHTML =
      '<dl class="ov-dl">'
      + '<dt>Certificate ID</dt><dd>' + esc(res.certId) + '</dd>'
      + '<dt>Issued (UTC)</dt><dd>' + esc(res.issuedAt.replace('T', ' ').replace('Z', '')) + '</dd>'
      + '<dt>Signing key</dt><dd>' + esc(res.kid) + '</dd>'
      + '</dl>'
      + '<p class="ov-note">This proves the code was issued by tScrub for that Certificate ID at that time. '
      + 'It does not by itself prove which drives were erased: check the record for this certificate ID online, '
      + 'and compare it with the details printed on the certificate.</p>'
      + '<p class="ov-note"><a id="ov-online" rel="noopener">Look up ' + esc(res.certId) + ' in the online record</a> '
      + '(needs a network connection).</p>';
    const link = $('ov-online');
    if (link) link.href = 'https://tscrub.com/verify?cert=' + encodeURIComponent(res.certId);
  } else {
    resultEl.className = 'ov-result ov-fail';
    titleEl.textContent = 'Not verified';
    detailEl.innerHTML = '<p class="ov-note">' + esc(REASON_TEXT[res.reason] || 'This code could not be verified.') + '</p>';
  }
}

async function runVerify(code) {
  const raw = String(code == null ? '' : code).trim();
  if (raw === '') { setStatus('Enter a code, or scan the one on the certificate.', 'warn'); return; }
  setStatus('Checking…', '');
  const res = await verifyCode(raw, { expectCertId: String(expectEl.value || '').trim() });
  setStatus('', '');
  showResult(res);
}

checkBtn.addEventListener('click', () => runVerify(codeEl.value));

// Paste can arrive with a leading/trailing newline from a QR-reader app.
codeEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); runVerify(codeEl.value); }
});

// ---------------------------------------------------------------------------
// Camera scan, only where the platform actually supports it
// ---------------------------------------------------------------------------
const hasBarcodeDetector = typeof window.BarcodeDetector === 'function';
const isSecure = window.isSecureContext === true;

if (!hasBarcodeDetector || !isSecure || !navigator.mediaDevices) {
  scanBtn.hidden = true;
  const why = !isSecure
    ? 'Camera scanning needs https (a page saved to disk can only accept a pasted code).'
    : 'This browser cannot scan barcodes. Paste the code instead.';
  const hint = $('ov-scan-hint');
  if (hint) hint.textContent = why;
} else {
  let stream = null;
  let detector = null;
  let raf = 0;

  const stop = () => {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    video.hidden = true;
  };

  scanBtn.addEventListener('click', async () => {
    if (stream) { stop(); scanBtn.textContent = 'Scan with camera'; return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = stream;
      video.hidden = false;
      await video.play();
      detector = detector || new window.BarcodeDetector({ formats: ['qr_code'] });
      scanBtn.textContent = 'Stop scanning';
      setStatus('Point the camera at the code on the certificate.', '');

      const tick = async () => {
        if (!stream) return;
        try {
          const found = await detector.detect(video);
          if (found && found.length) {
            const value = found[0].rawValue || '';
            stop();
            scanBtn.textContent = 'Scan with camera';
            codeEl.value = value;
            await runVerify(value);
            return;
          }
        } catch (e) {
          // A single failed frame is normal; keep scanning.
        }
        raf = requestAnimationFrame(tick);
      };
      raf = requestAnimationFrame(tick);
    } catch (e) {
      stop();
      scanBtn.textContent = 'Scan with camera';
      setStatus('The camera could not be started. Paste the code instead.', 'warn');
    }
  });
}

// ---------------------------------------------------------------------------
// Which key this page trusts, so the reader can compare it with the published one
// ---------------------------------------------------------------------------
(async () => {
  const fp = await keyFingerprint(PINNED_KEYS[KID]);
  if (fpEl && fp) {
    fpEl.textContent = fp;
    fpEl.title = 'SHA-256 of the raw Ed25519 key — the fingerprint published at /.well-known/tscrub-cert-key.json';
  }
})();

// A code can also arrive by URL fragment (#c=…), which never leaves the device.
// The hash can change WITHOUT a page load: an installed verifier sits open, and
// an auditor taps a link for a different certificate. Without the hashchange
// listener that second tap silently does nothing (found while decoding a printed
// QR and navigating to it in an already-open tab).
function verifyFromHash() {
  if (!location.hash.startsWith('#c=')) return;
  const fromHash = decodeURIComponent(location.hash.slice(3));
  codeEl.value = fromHash;
  runVerify(fromHash);
}
verifyFromHash();
window.addEventListener('hashchange', verifyFromHash);

// ---------------------------------------------------------------------------
// Offline support: cache this page after the first successful load
// ---------------------------------------------------------------------------
if ('serviceWorker' in navigator && isSecure) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw-verify-offline.js').catch(() => {
      // Not fatal: the page still works while online.
    });
  });
}

export { parseCode };
