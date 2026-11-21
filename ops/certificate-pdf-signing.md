# Certificate PDF Signing (`sign.crt` / `sign.key`) Runbook

How the server signs the PDF artifacts it produces — Certificate of
Destruction, per-device diagnostics, and drive reports — so they are
tamper-evident.

## What it signs

- Certificate of Destruction — `render_cert.php`
- ITAD diagnostics report PDF — `render_diag.php`
- Drive report PDF — `render_drive.php`

All three embed a detached PKCS#7 signature via TCPDF's `setSignature()` when the
licence is a **paid** tier (`$canSign`). Free-tier PDFs are rendered with a
`FREE TIER — NO VERIFICATION` footer instead of a signature.

## Key material

- `sign.crt` — self-signed X.509 certificate (PEM).
- `sign.key` — matching private key (PEM, mode 640).

Both live in `~/webs/tscrub-form/` on the server. `deploy-server.sh` excludes
them (along with `config.json`, `certificates/`, and `vendor.key`), so a deploy
never overwrites or removes them. They are **not** in the repo.

This key is **separate** from:

- the licence / report vendor key (`vendor.key`, Ed25519) — see
  `vendor-key-rotation.md`;
- the Secure Boot MOK key (`~/ipxe-sb/vendor.key`, shared with PXE) — see
  `secure-boot-mok.md`.

## One-time generation (on the server)

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && \
  openssl req -x509 -newkey rsa:2048 -nodes \
    -keyout sign.key -out sign.crt -days 3650 \
    -subj "/CN=tScrub Certificate Signing/O=TFix Ltd/C=GB" && \
  chmod 640 sign.key sign.crt'
```

Adjust the `-subj` to whatever identity you want shown in the PDF signature
panel. Re-run only if the files are missing (see Rotation below).

## How it is consumed

- `render_cert.php`, `render_diag.php`, and `render_drive.php` read
  `__DIR__ . '/sign.crt'` and `__DIR__ . '/sign.key'` and pass them to TCPDF
  `setSignature()` as `file://` URIs.
- TCPDF embeds the signature block in the PDF; PDF readers show the document as
  signed by the certificate's subject.

## Trailing-newline requirement (LibreOffice)

TCPDF's stock `Output()` strips the final newline after `%%EOF` before signing,
leaving signed files ending with a bare `%%EOF`. PDFium (used by LibreOffice's
PDF signature check) only records a "trailer end" when `%%EOF` is followed by
`\r`/`\n`, so LibreOffice reported those files as **"only partially signed"**
while Adobe and poppler (`pdfsig`) still said "valid / total document signed".

Fix: `tScrubPDF` (the TCPDF subclass in `render_cert.php`) overrides `Output()`
to close the document first and pad one extra newline into the buffer, so
TCPDF's strip leaves exactly one trailing newline *inside* the signed content —
and the ByteRange, computed over that same buffer, covers it. All three PDF
renderers (`render_cert.php`, `render_diag.php`, `render_drive.php`) already
build `tScrubPDF`, so they all get the fix. Guard:
`marketing/tests/cert_signature_test.php` (signs a PDF with a throwaway
in-memory certificate and asserts the output ends `%%EOF\n` with the second
byte range reaching end-of-file).

## Rotation

- Regenerating the key invalidates nothing retroactively: each PDF embeds the
  certificate it was signed with, so existing PDFs keep validating against their
  embedded certificate. New PDFs use the new certificate.
- The certificate travels inside each PDF's signature, so no separate archive is
  needed for old PDFs — keep the old `sign.crt` only if you want to inspect it
  out-of-band.
- No code change is required after rotation — the files are read by path.
