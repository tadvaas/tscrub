# Media custody runbook — a drive erased on another machine

**Applies to:** v1.11.87 and later (the appliance intake change and the
dashboard controls ship in that release). For older images see §8.

How to keep a customer's device identity when its storage will not erase in its
own chassis and has to be pulled and erased on a bench machine. The customer
tracks **devices** by serial; the appliance records **media** by drive serial and
host. This runbook is how the two are joined without inventing a claim.

Plan of record: `research/media-provenance/README.md` (gitignored).
Customer-facing wording: `marketing/site/docs.html` §10, "Media removed and
erased on another machine".

---

## 1. The three identities

| identity | what it is | what we key it on |
|---|---|---|
| **Device** | the unit the customer handed over and tracks | the **OEM system serial** the appliance reports |
| **Media item** | the data-bearing drive | the **drive serial** |
| **Host** | the machine attached to the drive when it was erased | `SystemSerial` on the report row |

When the drive is erased in its own chassis, host = device and nothing needs
saying. When it is erased on the bench, host ≠ device, and the certificate must
state both. The job (COCID) is the container for that evidence.

**Two rules that make the join possible:**

1. **One COCID per customer job**, and the bench erase runs under the **same
   COCID** as the device intake.
2. The appliance is the only source of *observed* provenance. Boot the device
   before pulling anything, even if the erase will fail.

---

## 2. Before the job

1. Confirm the COCID. Do not bench-batch several customers under one boot; the
   COCID is cached for the whole boot.
2. Mark your own bench machine as a **processing host** once:
   **Devices → the bench machine's row → Ops → Mark as our bench machine**.
   The card states whether the machine is currently a processing host, and the
   row carries a **Bench** badge. The machine is excluded from the certificate's
   device counts, while the evidence it produces is still used. It stays in the
   Devices list unless someone hides it: **Devices → Filters → Processing host →
   Hide bench hosts**. That choice is remembered, so a refresh keeps the customer
   fleet on its own.
3. Have a marker or label for the drive. Write the **device serial** and the
   **COCID** on it before it leaves the device.

---

## 3. The procedure (v1.11.87)

1. **Boot the device on tScrub under the customer's COCID**, before removing
   anything.
   - If a drive is discovered and the erase fails, **let the run finish**.
     `FAILED`, `BLOCKED`, `FROZEN` and `STALLED` are terminal states, so the
     report is produced and uploaded with both the device serial and the drive
     serial on the row.
   - If nothing is discovered, the **NO STORAGE DETECTED** screen holds. The
     boot snapshot is pushed automatically on entry (v1.11.87), so the device is
     recorded with `media_state=none` or `all_protected`. Press `F` once to
     rescan, then power off. On older images press `D` first (see §8).

> **If the device never boots tScrub at all.** There is no snapshot and no
> observation, so the appliance knows nothing about it. Do not invent a report
> and do not expect the machine in the Devices list yet. Read the **OEM system
> serial** from the chassis label (plus the model and the customer's asset tag)
> before pulling the drive, then follow steps 2-4. Saving the link will tell you
> the device is not on file and offer **Record device … and link**: that adds it
> to Devices as a **declared device** (badged `Declared`) and records the origin
> in one step. Put the model, tag and `device did not boot` in the notes. Every
> statement about the link is then **declared by operator**, and the certificate
> names the device by that serial only.

2. **Photograph the drive label and the screen**, then pull the drive.
3. **Erase the drive on the bench under the same COCID.** USB caddy or SATA is
   fine. Note that a USB bridge is **software overwrite only**: the report
   records `Bus=USB`, the overwrite standard and the verification technique,
   and makes no firmware-erase or IEEE 2883 claim.
4. **Link only if the appliance never saw the two together.** The link is
   derived automatically when the media item appeared in the device's boot
   snapshot or in a failed row on that device. If the device could not boot, or
   the drive arrived loose, declare it:
   **Media → the media item's row → Reports ▸ → View → Reassign origin**, then
   enter the device serial, the COCID, the reason and an optional note. The
   origin is recorded as **declared by operator** and is never shown as
   instrument-verified. Saving **replaces** any origin already recorded for that
   media item in that job, so a mistyped serial is corrected by reassigning
   again.
   **The list behind Reports tags every submission.** Each report carries one
   tag for its current state, in the status column exactly as the row above
   carries it: `bench` while the job has no declared origin, and `origin` once
   one is assigned (the serial is in the tooltip). So the blocked in-place
   attempts and the bench erase that followed are told apart at a glance, and a
   report whose origin was declared stops looking like an unexplained bench
   erase. A declaration naming the report's own host adds no tag, because that
   media item was in place for that job.
   **The device must be in Devices first.** A serial that is neither an observed
   machine nor a declared device is refused ("No device with serial … is in
   Devices"), and the form offers **Record device … and link**, which adds it to
   Devices as a declared device and records the origin in one step. Nothing is
   recorded unless you press that button, so a typo never becomes a certificate
   line by accident.
5. **Check the job before issuing.** On Certificates, the job shows
   `N devices to review` when a device is not the ordinary in-place case.
6. **Generate the certificate.** The PDF now carries:
   - page 1: `1 device with media removed` (or `no data-bearing media`) in the
     subtitle;
   - Annex A: a **DEVICE ACCOUNTING** block naming the device, its media, the
     host that sanitised each media item and the provenance class;
   - Annex A per-drive: an **Origin device** column, shown only when it differs
     from `Machine SN`.
   The signed JSON-LD twin carries the same facts (`originAsset`,
   `originProvenance`, `hostSerial`, `assetAccounting` — the twin's keys keep
   their original names, which are a machine contract), and `/verify` states the
   same device figures.

---

## 4. What the certificate says, and what it must never say

- It says the **media item was sanitised on a different host**. It does **not**
  say the device was sanitised in place. The attestation gains a clause whenever
  a device had media removed.
- Provenance is printed as `observed` or `declared by operator`. A declared
  link is never a verified fact.
- In the media record, an ordinary media item states the **reporting host as the
  origin device** (it was sanitised in place). The "processed on bench machine /
  may need an origin" caveat appears only when the reporting host is a marked
  processing host. Even then it says *if*: a loose media item or the bench's own
  drive has no customer device to record. The state is colour-coded: green for an
  in-place or declared origin, amber for a bench-processed media item or a report
  with no host serial.
- A marked processing host is **evidence, never a device** — and that covers its
  boot snapshots as well as its erase reports. The bench boots with the customer's
  drives inside it, so its inventory lists every media item it is about to erase;
  that inventory is not intake evidence and does not claim those media. Only an
  **unmarked** host's snapshot does, which is why unmarking a bench changes past
  provenance for its jobs.
- A standalone media item with no link stays standalone. The dashboard shows `—`
  rather than guessing an origin.
- The Media list carries **one** provenance chip beside the status, never two: a
  declared origin outranks the bench tag, because the bench is *how* the media
  item was erased while the origin is *what* it belonged to. `bench` (amber)
  means the last report came from a processing host and no origin is recorded —
  open **Reports ▸ → View → Reassign origin** if it came from a customer device.
- A device with no data-bearing media is counted as an exception, not as a
  destroyed device and not as a device with no certificate.
- A device that never booted has no system name on file. Its **DEVICE
  ACCOUNTING** line carries the serial you declared and nothing else: no model
  and no asset tag. Put those in the declaration note (Media ▸ View ▸ Reassign
  origin), which is kept in the device log, and on the job paperwork.
- Page 1's machine profile is the **bench** that ran the erasure, not the device.
  The device appears under DEVICE ACCOUNTING and in the per-drive `Origin device`
  column.

---

## 5. Covering note (until a customer asks for a separate document)

The certificate is the record. If the customer's process needs a one-page
sheet, use these fields and attach it to the attested item's evidence
reference so it lives with the job:

```
Job COCID:
Customer:
Device serial (OEM system serial):
Asset tag (optional, display only):
Media serial / model / size:
Failure observed:      not detected | erase failed (<status>) | already removed
Intake evidence:       boot snapshot <time> | failed erase report <name> | none (device did not boot)
Erasure evidence:      report <name>, SHA-256 <hash>, <time>
Erase host:            <system serial>
Method / standard:     <method>, <standard or "no standard claim (USB)">,
                       verification <sentinel | sampled N%>
Provenance:            observed on the device | declared by operator (<name>)
Operator / witness:
```

Do not describe the device as "wiped" or "sanitised". The data-bearing media
was sanitised; the chassis was not.

---

## 6. Troubleshooting

| Message / symptom | Cause | Fix |
|---|---|---|
| `That media serial was recorded in a report on a different host` | The media item completed or failed under a host that is not yet a processing host, so the observation outranks a declaration. | If that host is the bench, mark it as a processing host first, then link. Otherwise the device serial is wrong. |
| `already attributed to another device in this job` | A declaration for this media item in this job names a different device, and the two cannot both hold. | Reassign the origin again: saving replaces the previous declaration instead of adding a second one. The old link survives if the new one is refused. |
| `No device with serial … is in Devices` (409) | Media may only be assigned to a device that is on file. The serial is neither an observed machine nor a declared device, so it is most likely a typo. | Check the chassis label. If the machine really never booted tScrub, press **Record device … and link** in the form: it adds the device as a declared device and links in one step. |
| `origin ambiguous between devices` | Two non-processing hosts both reported the media item. | Decide which is the device and mark the other as a processing host, or correct the COCID/report. The certificate will not pick one. |
| `no completed erasure evidence` | The link exists but no erasure report for that media item is in this COCID yet. | Run or upload the bench report under the same COCID. |
| A no-media device says `boot snapshot <time>` only | The appliance never saw a drive. | If media existed, declare the link. If not, this is the correct record. |
| The declared origin does not appear on the Media row | The list shows provenance as ONE chip beside the status: `origin` (sky) when an origin is declared, `bench` (amber) when the last report came from a processing host and no origin is recorded. The device serial itself is in the chip's tooltip and in the record. | Open the media record (Reports ▸ → View) and read **Origin device**; generate or regenerate the certificate and read Annex A. |
| `The device serial is empty or a firmware placeholder` | The machine reported `N/A` or a placeholder. | The customer cannot track a device by a serial the machine does not have; agree an identifier and record it before the job. |
| The same drive appears twice | Two cheap drives report the same serial, or a drive was erased twice. | Do not link it. Resolve the duplicate physically (label and re-check). A re-installed drive is re-sanitisation, not a second media item. |
| The bench row is counted as a customer device | It is marked as a processing host, so it is evidence and not a device. | Nothing to fix — that is the rule. If the row itself should not be in the list, use **Filters → Processing host → Hide bench hosts**, or `?bench=hide`. |
| A bench row is missing from the list | Benches are shown by default, so it was hidden on purpose: the view remembers the choice. | Remove the `Bench hosts hidden` chip above the table, or open **Filters → Processing host → Hide bench hosts** to clear it. |

---

## 7. Limits worth knowing

- A declaration is an **operator attestation**. Nothing can turn it into an
  instrument observation after the fact.
- A **declared device** is a register entry, not evidence. It exists so a media
  origin can name a machine tScrub never booted. It carries the `Declared` badge,
  it is never presented as inspected, and it has no measured facts: no BIOS lock,
  no TPM, no drives. If the machine ever does boot tScrub, its diagnostics report
  owns the row and the badge disappears. It can be removed from the Ops panel
  only while no media origin and no report refers to it.
- A drive the appliance never saw in the device can only be linked by
  declaration. That is why step 1 exists.
- A USB bridge erase is software overwrite only and carries no sanitisation
  standard claim.
- Blank, `N/A` or duplicated serials refuse to link. The certificate would
  rather list the media item standalone than attribute it to the wrong device.
- A non-booting device is named by its declared serial only; model and asset tag
  are not printed on the certificate.
- The processing-host flag is **live, not historical**. Removing it keeps every
  report and declared link, but future provenance resolution treats the host as
  an ordinary machine: its media are assumed sanitised in place, and the report's
  host can outrank a declared origin. Leave it set when decommissioning a bench;
  only unmark a mistake. The media index's `bench` chip follows the current flag.
- A `clock?` marker means the report's erasure timestamp is more than a year
  before its upload, or in the future. The appliance RTC was wrong; use the
  submission time and the report hash as the reliable parts, and say so if the
  customer asks about the printed erasure time. The certificate still prints the
  report's raw timestamps.
- Do not record a non-booting device's already-sanitised drive as **physically
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
reports from any appliance version, but a device that never got a snapshot has
no observed link and needs a declaration.

---

## 9. Quick reference

| task | where |
|---|---|
| Declare an origin | Media → the media item's row → Reports ▸ → View → Reassign origin |
| Remove a declaration | the same record view: **Remove this link** (that job only), or `DELETE /api/media/link?cocid=<COCID>&media_serial=<serial>` |
| Mark/unmark a bench host | Devices → row → Ops → Mark as our bench machine |
| Record a device that never boots tScrub | Reassign origin → Save → **Record device … and link**, or Devices → row → Ops → Declared device → Remove |
| Hide/show bench hosts | Devices → Filters → Processing host → Hide bench hosts (or `?bench=hide`); benches are shown by default |
| List declared links | `GET /api/media/links?cocid=<COCID>` |
| Device figures on a certificate | PDF Annex A, JSON-LD `assetAccounting`, `/verify` |
| Re-issue after a new link | Certificates → generate/regenerate the job |

---

## Related docs

- `ops/report-verification.md` — verifying the erasure report itself.
- `ops/report-output.md` — what the appliance writes and what it contains.
- `ops/deploy.md` — release procedure.
- `research/media-provenance/README.md` — design, data model and as-built notes.
