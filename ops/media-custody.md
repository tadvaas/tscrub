# Media custody runbook — a drive erased on another machine

**Applies to:** v1.11.87 and later (the appliance intake change and the
dashboard controls ship in that release). For older images see §8.

How to keep a customer's asset identity when its storage will not erase in its
own chassis and has to be pulled and erased on a bench machine. The customer
tracks **assets** by serial; the appliance records **media** by drive serial and
host. This runbook is how the two are joined without inventing a claim.

Plan of record: `research/media-provenance/README.md` (gitignored).
Customer-facing wording: `marketing/site/docs.html` §10, "Media removed and
erased on another machine".

---

## 1. The three identities

| identity | what it is | what we key it on |
|---|---|---|
| **Asset** | the unit the customer handed over and tracks | the **OEM system serial** the appliance reports |
| **Medium** | the data-bearing drive | the **drive serial** |
| **Host** | the machine attached to the drive when it was erased | `SystemSerial` on the report row |

When the drive is erased in its own chassis, host = asset and nothing needs
saying. When it is erased on the bench, host ≠ asset, and the certificate must
state both. The job (COCID) is the container for that evidence.

**Two rules that make the join possible:**

1. **One COCID per customer job**, and the bench erase runs under the **same
   COCID** as the asset intake.
2. The appliance is the only source of *observed* provenance. Boot the asset
   before pulling anything, even if the erase will fail.

---

## 2. Before the job

1. Confirm the COCID. Do not bench-batch several customers under one boot; the
   COCID is cached for the whole boot.
2. Mark your own bench machine as a **processing host** once:
   **Devices → the bench machine's row → Ops → Mark as our bench machine**.
   The card states whether the machine is currently a processing host, and the
   row carries a **Bench** badge. The machine is excluded from certificate asset
   counts, while the evidence it produces is still used. It stays in the Devices
   list unless someone hides it: **Devices → Filters → Processing host → Hide
   bench hosts**. That choice is remembered, so a refresh keeps the customer
   fleet on its own.
3. Have a marker or label for the drive. Write the **asset serial** and the
   **COCID** on it before it leaves the asset.

---

## 3. The procedure (v1.11.87)

1. **Boot the asset on tScrub under the customer's COCID**, before removing
   anything.
   - If a drive is discovered and the erase fails, **let the run finish**.
     `FAILED`, `BLOCKED`, `FROZEN` and `STALLED` are terminal states, so the
     report is produced and uploaded with both the asset serial and the drive
     serial on the row.
   - If nothing is discovered, the **NO STORAGE DETECTED** screen holds. The
     boot snapshot is pushed automatically on entry (v1.11.87), so the asset is
     recorded with `media_state=none` or `all_protected`. Press `F` once to
     rescan, then power off. On older images press `D` first (see §8).

> **If the asset never boots tScrub at all.** There is no snapshot and no
> observation, so the appliance knows nothing about it. Do not invent a device
> record and do not expect the laptop in the Devices list. Read the **OEM system
> serial** from the chassis label (plus the model and the customer's asset tag)
> before pulling the drive, then follow steps 2-4 and declare the link with that
> serial. Put the model, tag and `asset did not boot` in the declaration note.
> Every statement about the link is then **declared by operator**, and the
> certificate names the asset by that serial only.

2. **Photograph the drive label and the screen**, then pull the drive.
3. **Erase the drive on the bench under the same COCID.** USB caddy or SATA is
   fine. Note that a USB bridge is **software overwrite only**: the report
   records `Bus=USB`, the overwrite standard and the verification technique,
   and makes no firmware-erase or IEEE 2883 claim.
4. **Link only if the appliance never saw the two together.** The link is
   derived automatically when the medium appeared in the asset's boot snapshot
   or in a failed row on that asset. If the asset could not boot, or the drive
   arrived loose, declare it:
   **Media → the medium's row → Reports ▸ → View → Reassign origin**, then enter
   the asset serial, the COCID, the reason and an optional note. The origin is
   recorded as **declared by operator** and is never shown as
   instrument-verified.
5. **Check the job before issuing.** On Certificates, the job shows
   `N assets to review` when an asset is not the ordinary in-place case.
6. **Generate the certificate.** The PDF now carries:
   - page 1: `1 asset with media removed` (or `no data-bearing media`) in the
     subtitle;
   - Annex A: an **ASSET ACCOUNTING** block naming the asset, its media, the
     host that sanitised each medium and the provenance class;
   - Annex A per-drive: an **Origin asset** column, shown only when it differs
     from `Machine SN`.
   The signed JSON-LD twin carries the same facts (`originAsset`,
   `originProvenance`, `hostSerial`, `assetAccounting`), and `/verify` states
   the same asset figures.

---

## 4. What the certificate says, and what it must never say

- It says the **medium was sanitised on a different host**. It does **not** say
  the asset was sanitised in place. The attestation gains a clause whenever an
  asset had media removed.
- Provenance is printed as `observed` or `declared by operator`. A declared
  link is never a verified fact.
- In the medium's record, an ordinary medium states the **reporting host as the
  origin asset** (it was sanitised in place). The "processed on bench machine /
  may need an origin" caveat appears only when the reporting host is a marked
  processing host. Even then it says *if*: a loose medium or the bench's own
  drive has no customer asset to record. The state is colour-coded: green for an
  in-place or declared origin, amber for a bench-processed medium or a report
  with no host serial.
- A standalone medium with no link stays standalone. The dashboard shows `—`
  rather than guessing an origin.
- An asset with no data-bearing media is counted as an exception, not as a
  destroyed device and not as a device with no certificate.
- An asset that never booted has no system name on file. Its **ASSET
  ACCOUNTING** line carries the serial you declared and nothing else: no model
  and no asset tag. Put those in the declaration note (Media ▸ View ▸ Reassign
  origin), which is kept in the device log, and on the job paperwork.
- Page 1's machine profile is the **bench** that ran the erasure, not the asset.
  The asset appears under ASSET ACCOUNTING and in the per-drive `Origin asset`
  column.

---

## 5. Covering note (until a customer asks for a separate document)

The certificate is the record. If the customer's process needs a one-page
sheet, use these fields and attach it to the attested item's evidence
reference so it lives with the job:

```
Job COCID:
Customer:
Asset serial (OEM system serial):
Asset tag (optional, display only):
Medium serial / model / size:
Failure observed:      not detected | erase failed (<status>) | already removed
Intake evidence:       boot snapshot <time> | failed erase report <name> | none (asset did not boot)
Erasure evidence:      report <name>, SHA-256 <hash>, <time>
Erase host:            <system serial>
Method / standard:     <method>, <standard or "no standard claim (USB)">,
                       verification <sentinel | sampled N%>
Provenance:            observed in the asset | declared by operator (<name>)
Operator / witness:
```

Do not describe the asset as "wiped" or "sanitised". The data-bearing medium
was sanitised; the chassis was not.

---

## 6. Troubleshooting

| Message / symptom | Cause | Fix |
|---|---|---|
| `That media serial was recorded in a report on a different host` | The medium completed or failed under a host that is not yet a processing host, so the observation outranks a declaration. | If that host is the bench, mark it as a processing host first, then link. Otherwise the asset serial is wrong. |
| `already attributed to another asset` | Another declared link claims the medium in this job. | Remove the wrong link (Media ▸ link, or `DELETE /api/media/link/{id}`), then re-add. |
| `origin ambiguous between assets` | Two non-processing hosts both reported the medium. | Decide which is the asset and mark the other as a processing host, or correct the COCID/report. The certificate will not pick one. |
| `no completed erasure evidence` | The link exists but no erasure report for that medium is in this COCID yet. | Run or upload the bench report under the same COCID. |
| A no-media asset says `boot snapshot <time>` only | The appliance never saw a drive. | If media existed, declare the link. If not, this is the correct record. |
| The declared origin does not appear on the Media row | Only **declared** links are attached to the list, and the list shows them as the `elsewhere` chip rather than in a column. Observed origins are derived at certificate time. | Open the medium's record (Reports ▸ → View) and read **Origin asset**; generate or regenerate the certificate and read Annex A. |
| `asset serial is empty or a firmware placeholder` | The asset reported `N/A` or a placeholder. | The customer cannot track an asset by a serial the machine does not have; agree an identifier and record it before the job. |
| The same drive appears twice | Two cheap drives report the same serial, or a drive was erased twice. | Do not link it. Resolve the duplicate physically (label and re-check). A re-installed drive is re-sanitisation, not a second medium. |
| The bench row is counted as a customer asset | It is marked as a processing host, so it is evidence and not an asset. | Nothing to fix — that is the rule. If the row itself should not be in the list, use **Filters → Processing host → Hide bench hosts**, or `?bench=hide`. |
| A bench row is missing from the list | Benches are shown by default, so it was hidden on purpose: the view remembers the choice. | Remove the `Bench hosts hidden` chip above the table, or open **Filters → Processing host → Hide bench hosts** to clear it. |

---

## 7. Limits worth knowing

- A declaration is an **operator attestation**. Nothing can turn it into an
  instrument observation after the fact.
- A drive the appliance never saw in the asset can only be linked by
  declaration. That is why step 1 exists.
- A USB bridge erase is software overwrite only and carries no sanitisation
  standard claim.
- Blank, `N/A` or duplicated serials refuse to link. The certificate would
  rather list the medium standalone than attribute it to the wrong asset.
- A non-booting asset is named by its declared serial only; model and asset tag
  are not printed on the certificate.
- The processing-host flag is **live, not historical**. Removing it keeps every
  report and declared link, but future provenance resolution treats the host as
  an ordinary machine: its media are assumed sanitised in place, and the report's
  host can outrank a declared origin. Leave it set when decommissioning a bench;
  only unmark a mistake. The media index's `Bench` tag follows the current flag.
- A `clock?` marker means the report's erasure timestamp is more than a year
  before its upload, or in the future. The appliance RTC was wrong; use the
  submission time and the report hash as the reliable parts, and say so if the
  customer asks about the printed erasure time. The certificate still prints the
  report's raw timestamps.
- Do not record a non-booting asset's already-sanitised drive as **physically
  destroyed** in a manual attestation to make the chassis appear. The drive was
  sanitised, not destroyed; the two fates need separate records.
- The bench view is part of the remembered view (query string and local
  storage), so a refresh keeps it. The processing-host flag itself is stored
  server-side.

---

## 8. Older appliance images

Before v1.11.87 a machine with no media recorded only a heartbeat. The
workaround on an older image is to press **D** on the NO STORAGE DETECTED screen
(that pushes the registration and diagnostics snapshot), then **F** to rescan,
then power off. The certificate-side features are server-side and work with
reports from any appliance version, but an asset that never got a snapshot has
no observed link and needs a declaration.

---

## 9. Quick reference

| task | where |
|---|---|
| Declare an origin | Media → medium's row → Reports ▸ → View → Reassign origin |
| Remove a declaration | same panel, or `DELETE /api/media/link/{id}` |
| Mark/unmark a bench host | Devices → row → Ops → Mark as our bench machine |
| Hide/show bench hosts | Devices → Filters → Processing host → Hide bench hosts (or `?bench=hide`); benches are shown by default |
| List declared links | `GET /api/media/links?cocid=<COCID>` |
| Asset figures on a certificate | PDF Annex A, JSON-LD `assetAccounting`, `/verify` |
| Re-issue after a new link | Certificates → generate/regenerate the job |

---

## Related docs

- `ops/report-verification.md` — verifying the erasure report itself.
- `ops/report-output.md` — what the appliance writes and what it contains.
- `ops/deploy.md` — release procedure.
- `research/media-provenance/README.md` — design, data model and as-built notes.
