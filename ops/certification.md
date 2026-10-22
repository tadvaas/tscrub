# Certification Submission Runbook

How to put tScrub in front of an independent laboratory, what to send them, and
what we are allowed to say before and after they issue a certificate.

Status: **runbook only — nothing has been submitted** (written 2026-10-22).
Backs `roadmap/product.md` §4.3 ("Third-party testing / certification").

## The rule

**Never claim what we don't hold.** That is not a style preference; it is the
product's whole argument. A single "ADISA-approved" line on a page we cannot
back with a reference number would undo the reason buyers trust the reports.

Three different things get called "certified". Keep them apart, always:

| Kind | Example | Who issues it | Do we hold it? |
|---|---|---|---|
| **Standard we implement** | NIST SP 800-88 Rev 1, IEEE 2883-2022 | Nobody certifies this. It is a document we implement against. | Implemented, **not** certified (there is no certifier) |
| **Product certification** | ADISA PCT `ADPC###`, ADISA PA `ADPA###` | ADISA (UKAS-accredited) | **No** |
| **Organisational certification** | Cyber Essentials (IASME), ISO/IEC 27001 | Certification body | Cyber Essentials: **yes, TFix Ltd.** ISO 27001: **no** |

The failure mode this file exists to prevent: taking TFix's Cyber Essentials and
implying the *product* is certified, or taking "supports NIST 800-88" and
implying a lab has blessed it. Never transplant one row onto another.

If a public surface makes a claim this file has no evidence pointer for, the
surface is wrong. Fix the surface — not this file.

## 1. What we may say today

Every row below is live and has an artefact behind it. Anything not in this
table is not yet claimable.

| Claim | Where it appears | Evidence | Who can check it |
|---|---|---|---|
| "Supports NIST 800-88 workflows; it is not itself a certification" | `compliance.html` footer | method → Clear/Purge mapping table on `compliance.html` | Anyone reading the page |
| Per-drive method, NIST level, technique, verification coverage | Certificate Annex A; CSV `Method`, `OverwriteStandard`, `Passes`, `EraseEngine`, `VerifyTechnique`, `VerifyCoveragePct` (cols 11, 82-86) | `product/src/50_report.sh` | The CSV itself |
| Report is tamper-evident | `.csv` + `.sig` + `.json` manifest (`tscrub-report/1`) | SHA-256 over CSV + Ed25519 over CSV | `tscrub verify`, or `openssl pkeyutl` (`ops/report-verification.md`) |
| Paid reports are **attributable**, free reports are not | `/verify?cert=…` states which | server compares manifest `public_key` to `licences.pub_key` | Upload a free and a paid report and compare |
| Machine-readable certificate | `/verify?cert=…&format=jsonld` | `marketing/server/jsonld.php` | Fetch the URL |
| Certificate verifies with **no network** | QR → `/verify-offline`, `#c=` signed code | Ed25519 over `kid\|cert_id\|issued_at`, key pinned in the page and published at `/.well-known/tscrub-cert-key.json` | Scan the QR in aeroplane mode |
| Key fingerprint | `ops/report-verification.md`, `/.well-known/tscrub-cert-key.json` | `be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02` | Hash the published key |
| Post-erase verification: sentinel + sampled / full read-back | CSV `Verify`, `VerifySectors`, `VerifyResult` | `product/src/43_verify.sh` | The report |
| Opt-in coverage check, house bar, **not** an IEEE number | `--verify-coverage PCT` | `verify::coverage_check()` | Run it |

### Explicit non-claims (do not add these without a certificate in hand)

- No ADISA certification of any kind (PCT, PA, SCT).
- No NAID AAA certification.
- No ISO/IEC 27001 certificate. `resources/iso-27001-data-erasure.html` talks
  about *auditee* obligations under control 8.10 — it does not claim we hold it.
- No "IEEE 2883 certified" — nothing certifies to IEEE 2883. The accurate
  phrasing, when we earn the right to use it, is "performs sanitization methods
  described by IEEE 2883-2022".
- No Common Criteria / FIPS 140 validation.
- Cyber Essentials is **TFix Ltd's**, and only in the accreditation list on
  `compliance.html`. It must never appear as a property of the appliance.

## 2. The schemes, and what they actually test

### 2.1 ADISA Product Claims Test (PCT) — the entry point

Source: <https://adisacertification.com/adisa-research-centre-product-claims-test-pct/>
(fetched 2026-10-22; the old `adisa.global` paths 404 after their site move —
see `research/adisa/README.md`).

- The **applicant writes the claim**; the laboratory attests whether it is
  **True or Not True**. There is no media limitation and no fixed test list —
  the scope is whatever you claim.
- Forensic verification at three **threat levels** (their Threat Matrix): the lab
  attempts recovery using techniques matched to a threat actor.
- Open to hardware *and* software; explicitly positioned as "third-party
  evidence that the product can be trusted to perform its intended task".
- Reference format `ADPC###`. 155 such certifications exist today.

This is the right first submission: it is claim-driven, so we choose a claim we
can already prove, and it does not force the IEEE sampling bar.

### 2.2 ADISA Product Assurance (PA) — the one that carries weight

Source: <https://adisacertification.com/adisa-research-centre-product-assurance-pa/>

PA is the higher bar, and its wording maps almost one-to-one onto things a
sanitisation appliance either does or cannot do:

- Validates against **NIST 800-88 and/or IEEE 2883**, Clear and Purge, *per
  media type and interface* within the declared scope.
- Analyses the **specific sanitisation commands** the product sends — not just
  the outcome. This is where an appliance that shells out to `hdparm`/`nvme` is
  either doing the real ATA/NVMe command or is not.
- Confirms **behaviour on a drive that does not support the required command** —
  i.e. our fallback path has to be deliberate and documented, not incidental.
- Applies **data-recovery attacks** to the sanitised media, at a higher assurance
  than PCT.
- Reference format `ADPA###`. 26 exist today, including Blancco Drive Eraser
  across NIST 800-88r1/r2 + IEEE 2883:2022 (`ADPA050`).

Note the **"and/or"**: a NIST-only PA submission is possible. That matters,
because an IEEE claim drags in a sampling requirement we have not yet field-tested
(§4) and would force us to buy the standard before citing it (§4.6).

### 2.3 ADISA Security Claims Test (SCT)

Very new/odd media (MDSD, wearables). Only 2 certifications exist. Not a
sensible first move.

### 2.4 NAID AAA (i-SIGMA)

Certifies an **operator and its processes** (plant-based destruction, sanitisation
services), not a software product. Relevant to TFix as a service business, not to
tScrub as a product. **Scope unverified**: `naidonline.org` redirects to
`isigmaonline.org`, and both `/naid-aaa-certification/` paths returned 404 to an
automated fetch on 2026-10-22 — do not quote requirements from memory, get the
current standard pack in writing before relying on this row.

### 2.5 ISO/IEC 27001

An ISMS certification for the *organisation*. If TFix ever certifies, the scope
statement must name tScrub before any page implies the product is covered.
Today: no claim anywhere, and the site is correct to say only that the reports
*help* with an ISO 27001 programme.

### 2.6 The standards, which are not certifications

- **NIST SP 800-88 Rev 1** — a guideline. We target it explicitly and say so.
- **IEEE 2883-2022** — a standard. We record technique and coverage against it
  (v1.11.73) and claim nothing. Per `research/ieee-2883-2022/decisions.md` D6, we
  do **not** cite clause numbers we cannot quote until the PDF is purchased.

## 3. The evidence pack we already hold

What a laboratory asks for, and where it comes from:

| What they need | Where it is | State |
|---|---|---|
| An identifiable build under test | `marketing/server/download-manifest.json`: version `v1.11.73`, filename carries build date + build-host git sha (`…20261021-47fcf2ac`), ISO `sha256:03f37aa4…513a`, script `sha256:57b9a31e…432c9` | ✅ |
| Something they can boot | Full appliance ISO (ShredOS fork; Secure Boot shim + MOK, `ops/secure-boot-mok.md`) or `tscrub.sh` on a distro | ✅ |
| A licence that does not expire mid-test | Signed licence file read from the boot USB or `/etc/tscrub/license.lic`; also `--license PATH` and `--license-url URL` | ✅ — but issue a bench licence with a deliberate expiry date |
| The method matrix, per bus and media | `product/src/30_device.sh`…`33_device_ata.sh`, `44_device_sed.sh` | ✅ |
| The record format | 86-column CSV + manifest + Ed25519 signature; `tscrub verify` | ✅ |
| A way for *their* client to check the result | Certificate PDF, JSON-LD, offline QR code | ✅ |
| Our own test evidence | `product/tests` (1140 checks, 0 failures at v1.11.73) | ✅ internal only |

The single most useful artefact for a lab is the **manifest**, because it lets
them prove the binary they tested is the binary we ship. Quote all four fields
(version, filename, ISO sha256, script sha256) in the submission, not just the
version.

## 4. Gaps to close before we apply

Ordered by what would actually block a submission.

| # | Gap | Why the scheme needs it | How we close it |
|---|---|---|---|
| 4.1 | `--verify-coverage` has **never produced a number on real hardware** | Any claim involving sampled Clear verification rests on it | Needs a target that actually reaches **nwipe**, which is not any drive: coverage sampling is gated on the software-overwrite path (`devrow[standard]` is only emitted by the nwipe worker), because a firmware erase leaves undefined content where a percentage read proves readability, not absence. **Field test 2026-10-22 on `192.168.0.95` (HP EliteBook 830 G5, Samsung PM981a `MZVLB512HAJQ`) confirmed the gate works but could not exercise the sampler:** the drive accepted `NVMe Format`, so the run recorded `VerifyTechnique=sentinel`, `VerifyCoveragePct` empty, and the certificate printed "Sentinel" — correctly claiming nothing. To reach nwipe we need either a **virtio-scsi disk in a VM** (appears as `sd*`, no ATA pass-through) or a **SATA drive on a real SATA controller**. USB-attached drives are excluded from discovery, so a USB caddy does not work. **Do not use `192.168.0.5` (Proxmox)** |
| 4.2 | The coverage bar is **our** number, not IEEE's | Quoting 5% as an IEEE requirement would be an unsourced claim | Keep describing it as policy; do not attribute it to the standard |
| 4.3 | No written **unsupported-drive behaviour** statement | PA explicitly tests what happens when the drive refuses the command | Write the sentence (fallback chain per bus + what the report records), then reproduce it: a USB-bridged ATA drive, a Block-SID-blocked NVMe, a drive that rejects Sanitize |
| 4.4 | Media/interface scope not documented as a list | PA certifies per interface and media type | Produce the in-scope vs out-of-scope table (HDD, SATA SSD, NVMe SSD, SAS; out: ZNS, SMR, optical, embedded) |
| 4.5 | Version discipline during a test window | A lab tests one build; a mid-test release invalidates it | Freeze `main`, tag, no field fixes, no rebuilds unless the lab agrees |
| 4.6 | IEEE 2883 PDF not purchased | Cannot cite a clause we cannot quote | Buy it, or submit NIST-only (§2.2 allows it) |
| 4.7 | Test-media seeding not documented | The lab plants its own data, but will ask what the product expects | One-page "how we seed and what a pass looks like" |
| 4.8 | No single claim sentence | PCT requires the applicant to state the claim | Draft it (§5.0) and have it reviewed before it leaves the building |

## 5. The runbook

### 5.0 Draft the claim — one sentence, attestable

PCT asks the lab to mark the claim True or Not True, so it has to be narrow
enough to be decided. Draft for review (not yet submitted to anyone):

> *"The submitted build of tScrub renders user data on SATA hard drives, SATA
> SSDs, NVMe SSDs and SAS drives irretrievable by issuing the media's own
> sanitisation command (ATA Sanitize / Enhanced Secure Erase, NVMe Format or
> Sanitize, TCG crypto erase) or, where the drive does not support one, a
> software overwrite followed by post-erasure read-back verification."*

That sentence is defensible because every clause is already in the report. Do
not widen it — "all media" or "all interfaces" invites a Not True.

### 5.1 Freeze and identify the build

```bash
git tag -a v1.12.0 -m "ADISA PCT submission build"
make -C product build
sha256sum <the exact tscrub.sh that will be submitted>
```

Build the ISO on the build host per `ops/howto.md` §2.2, then confirm the
published manifest matches what you are sending. A mismatch here is the one
error that voids the whole exercise.

### 5.2 Enquire before applying

<https://adisacertification.com/contact-us/> — ask, in writing:

1. Current fee schedule and lead time for PCT and PA.
2. The **question set / application form fields** (the online forms are
   JavaScript-rendered; nothing is machine-readable, so request them as a
   document).
3. Whether a **bootable appliance ISO** is acceptable as the product under test,
   or whether they need it installed on a host OS.
4. Which media and interfaces can be in one submission, and whether scope can be
   extended later without a full retest.
5. Retest policy when we ship a new version.
6. What happens if part of the claim comes back **Not True** — is a narrowed
   claim re-tested at reduced cost?

### 5.3 Apply

- PCT: <https://adisacertification.com/application-form-product-claims-testing/>
- PA: <https://adisacertification.com/application-form-product-assurance/>

Attach the evidence pack from §3, plus the signed licence file and the
seeding sheet (§4.7).

### 5.4 During the test

Assume findings. When the lab reports something, the fix is **first** to narrow
the claim, **second** to change the appliance. A claim we narrow is honest; a
claim we argue about wastes the fee.

### 5.5 On issue — the only moment copy may change

1. Record the reference (`ADPC###` / `ADPA###`), its exact scope wording, and
   the expiry date in §7 of this file.
2. Only then update `compliance.html`, the relevant `resources/` page, and
   `site/public/llms.txt` / `llms-full.txt` (standing preference: LLM context
   files move in the same pass).
3. Quote the reference and the scope **verbatim**. "ADISA Product Assurance
   certified (ADPA###): NVMe SSD and SATA HDD, NIST SP 800-88" — not
   "ADISA certified".
4. Never extend the scope in prose. If the certificate says NVMe and SATA HDD,
   SAS stays out of the sentence.

### 5.6 Renewal

Diarise the expiry the day the certificate arrives. A lapsed certificate comes
off the site **the same day** it lapses; the historical entry stays in §7.

## 6. Claim checklist for any PR that touches compliance copy

```bash
# any new certification claim must have a row in §1 or §7
grep -rn "ADISA\|NAID\|i-SIGMA\|ISO 27001\|IEEE 2883\|NIST" marketing/site --include='*.html'
# the standard wording we must keep
grep -n "not itself a certification" marketing/site/compliance.html
# LLM context files must move with the pages
grep -n "certif" marketing/site/public/llms.txt marketing/site/public/llms-full.txt
```

If the diff adds a certification word that §1 has no evidence row for, the PR
is wrong.

## 7. Submission log

| Scheme | Reference | Scope as issued | Issued | Expires | Status |
|---|---|---|---|---|---|
| ADISA PCT | — | — | — | — | **Not applied** |
| ADISA PA | — | — | — | — | **Not applied** |
| NAID AAA | — | — | — | — | Not applicable to the product; TFix question |
| ISO/IEC 27001 | — | — | — | — | Not held |

## Sources

- ADISA PCT: <https://adisacertification.com/adisa-research-centre-product-claims-test-pct/> (2026-10-22)
- ADISA PA: <https://adisacertification.com/adisa-research-centre-product-assurance-pa/> (2026-10-22)
- ADISA directory of certified products: `research/adisa/README.md` (193 records, 4 schemes, fetched 2026-10-16)
- IEEE 2883 claim guardrails: `research/ieee-2883-2022/README.md` §9
- Offline verification (what the QR actually attests): `research/offline-verification/README.md`
- Report signature and attribution: `ops/report-verification.md`
