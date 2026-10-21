// Offline certificate-code verification.
//
// Shared by the /verify-offline page and by the node test, so the paste path
// and the camera path cannot drift apart: both end in verifyCode().
//
// Plan: research/offline-verification/README.md.
//
// THE TRUST MODEL, in one paragraph: a code is `2|<kid>|<cert_id>|<issued_at>|
// <sig>`, an Ed25519 signature over the first four fields. The payload never
// carries a public key — a code that brought its own key would prove only that
// its author can sign. Instead the keys below are PINNED: an unknown `kid` is a
// rejection, never a fallback. Trust in these bytes comes from how this page
// reached the device (TLS, and the service-worker cache afterwards), which is
// why the key is compiled in and the page is served from tscrub.com.
//
// WHAT A PASS MEANS: this certificate id was issued by tScrub at that time
// under our key. It does NOT prove the drive was erased, and it does not
// compare against the printed content — that is what the online record is for
// (the link is offered after a pass).

const subtle = (globalThis.crypto && globalThis.crypto.subtle) || null;

// kid -> base64 SPKI. Verified against /.well-known/tscrub-cert-key.json
// (schema tscrub-cert-key/1, fingerprint be81586c…5802).
export const PINNED_KEYS = Object.freeze({
  'tscrub-vendor-1': 'MCowBQYDK2VwAyEAbBDdsD4wQh7aoBRe890V8LcTOZNe6n6Cvh0AkrBA4B4='
});

const VERSION = '2';
const REASONS = Object.freeze([
  'malformed', 'bad-version', 'unknown-key', 'bad-signature', 'cert-mismatch', 'unsupported'
]);

function b64ToBytes(s) {
  const b = atob(s);
  const out = new Uint8Array(b.length);
  for (let i = 0; i < b.length; i++) out[i] = b.charCodeAt(i);
  return out;
}

function b64urlToBytes(s) {
  const b = s.replace(/-/g, '+').replace(/_/g, '/');
  const pad = b.length % 4 === 0 ? '' : '='.repeat(4 - (b.length % 4));
  return b64ToBytes(b + pad);
}

/** Same shapes the server enforces; a hostile payload cannot smuggle a '|'. */
function fieldsOk(certId, issuedAt) {
  return /^[A-Za-z0-9-]{1,64}$/.test(certId)
    && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(issuedAt);
}

/** Split a code without verifying it (used for display and for the tests). */
export function parseCode(raw) {
  const code = String(raw == null ? '' : raw).trim();
  const parts = code.split('|');
  if (parts.length !== 5) return { ok: false, reason: 'malformed' };
  const [version, kid, certId, issuedAt, sig] = parts;
  if (version !== VERSION) return { ok: false, reason: 'bad-version', kid, certId, issuedAt };
  if (!fieldsOk(certId, issuedAt)) return { ok: false, reason: 'malformed', kid, certId, issuedAt };
  if (!/^[A-Za-z0-9._-]{1,32}$/.test(kid)) return { ok: false, reason: 'malformed', kid, certId, issuedAt };
  if (!/^[A-Za-z0-9_-]+$/.test(sig)) return { ok: false, reason: 'malformed', kid, certId, issuedAt };
  return {
    ok: true,
    reason: '',
    kid,
    certId,
    issuedAt,
    // The signed bytes are exactly the first four fields rejoined. Any other
    // split changes the message, so the signature stops verifying.
    message: [version, kid, certId, issuedAt].join('|'),
    signature: sig
  };
}

/**
 * Verify a code.
 *
 * options.keys          kid -> base64 SPKI (defaults to PINNED_KEYS)
 * options.expectCertId  the Certificate ID printed on the page in front of the
 *                       auditor; when supplied, a mismatch is a rejection. This
 *                       is the one binding a paper-only check can make.
 * options.subtleImpl    test seam
 *
 * Resolves to {ok, reason, kid, certId, issuedAt} and never throws.
 */
export async function verifyCode(raw, options = {}) {
  const keys = options.keys || PINNED_KEYS;
  const impl = options.subtleImpl || subtle;
  const parsed = parseCode(raw);
  if (!parsed.ok) return { ok: false, reason: parsed.reason, kid: parsed.kid || '', certId: parsed.certId || '', issuedAt: parsed.issuedAt || '' };
  const base = { ok: false, reason: '', kid: parsed.kid, certId: parsed.certId, issuedAt: parsed.issuedAt };

  // Unknown kid is a rejection on its own — never "try the payload's key".
  const spki = keys[parsed.kid];
  if (!spki) return { ...base, reason: 'unknown-key' };
  if (!impl) return { ...base, reason: 'unsupported' };

  let ok = false;
  try {
    const key = await impl.importKey('spki', b64ToBytes(spki), { name: 'Ed25519' }, false, ['verify']);
    ok = await impl.verify(
      { name: 'Ed25519' },
      key,
      b64urlToBytes(parsed.signature),
      new TextEncoder().encode(parsed.message)
    );
  } catch (e) {
    return { ...base, reason: 'unsupported' };
  }
  if (!ok) return { ...base, reason: 'bad-signature' };

  if (options.expectCertId && options.expectCertId !== parsed.certId) {
    return { ...base, reason: 'cert-mismatch' };
  }
  return { ...base, ok: true, reason: '' };
}

/**
 * SHA-256 of the RAW 32-byte Ed25519 key, hex.
 *
 * This is the value /.well-known/tscrub-cert-key.json calls `fingerprint`
 * (be81586c…5802 for tscrub-vendor-1). It is NOT the hash of the SPKI DER: that
 * is a different value (9e0b73fe…) and getting it wrong would print a
 * fingerprint that never matches the published one, which is the sort of detail
 * an auditor checks first.
 */
export async function keyFingerprint(spkiB64, impl = subtle) {
  if (!impl) return '';
  const der = b64ToBytes(spkiB64);
  const raw = der.subarray(der.length - 32);
  const digest = await impl.digest('SHA-256', raw);
  return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
}

export { REASONS };
