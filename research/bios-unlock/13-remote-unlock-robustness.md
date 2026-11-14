# Remote BIOS unlock — robustness plan (§8)

Status: **shipped v1.11.3** (2026-10-05) — all 8 fixes in
`product/src/38_bios_unlock.sh`, `marketing/server/bios_unlock.php`,
`marketing/server/api.php`; tests in `product/tests/test_bios_unlock.sh`
(18 tests).
Backlog: `roadmap/product.md` §3 + `ROADMAP.md` §8.

## 1. How competitors do it

**Nobody automates remote BIOS password clearing.** Verified across the research
folder:

- **BitRaser** — detects + reports BIOS locks (its `linuxpba` is OPAL/SED pre-boot
  auth, unrelated). No network-driven password clear.
- **Blancco / D-Secure / ZiPerase** — no remote BIOS unlock either; lock removal
  is manual (vendor tools, master-password generators, CMOS/service mode, SPI).

So tScrub's dashboard→appliance sysfs clear is **already a differentiator**; this
pass is about making it production-trustworthy, not copying a competitor.

## 2. Relevant research

- `research/bios-unlock/` — the in-house hardware runbooks + empirical findings:
  **writable password attributes exist only on Dell (`dell-wmi-sysman`), Lenovo
  (`think_lmi`) and HP (`hp-wmi`)**; HP 830 G5's sysfs clear is dead, most other
  vendors report `unsupported`, and power-on passwords usually aren't clearable
  via this path (doc 08).
- `research/bios-lock/` — the detection cascade (`36_bios.sh`) with the
  password-name / policy-name heuristics reused by the clear path.
- `ops/troubleshooting.md` — the TLS clock-skew `curl -k` retry pattern to mirror.

## 3. Current state (gaps)

| # | Gap |
|---|---|
| 1 | `38_bios_unlock.sh` `sed`-extracts `id`/`password` from JSON — passwords with `"`, `\`, or control chars corrupt. |
| 2 | A `dispatched` command whose result POST fails stays `dispatched` forever (password retained). |
| 3 | Superseded rows keep `password_enc`; no TTL for never-claimed pending rows. |
| 4 | Slot selection is first-match; doesn't prefer setup/AdminPassword over power-on/SystemPassword, and never re-verifies. |
| 5 | "write failed" can't distinguish wrong password from non-writable attribute. |
| 6 | `unlock_claim` matches `user_id + serial` only (ignores `uuid`). |
| 7 | No `curl -k` TLS clock-skew fallback. |
| 8 | `unlock_report` accepts a result for any status; state machine not enforced. |

## 4. Integration plan

1. **JSON-safe password** — `GET /api/bios/unlock/pending` returns
   `password_b64` (base64) alongside the legacy `password`; the appliance decodes
   with `base64 -d`. Base64 covers every character (quotes/backslash/control/
   unicode) with no JSON escaping. `id` stays a bare integer (sed-safe). Legacy
   field kept for one transition so a stale appliance doesn't break.
2. **Dispatched TTL + requeue** — `unlock_claim` first resets
   `status='dispatched' AND dispatched_at < NOW() - INTERVAL 10 MINUTE` back to
   `pending` (password is encrypted at rest, so it's safe to re-offer).
3. **Purge password residue** — `unlock_enqueue` clears `password_enc` on the rows
   it supersedes; add a stale-pending sweep (pending older than 7 days →
   `cancelled` + `password_enc=''`) run opportunistically from enqueue/latest.
4. **Explicit slot targeting + re-verify** — in `bios_unlock::clear`, score
   attributes: setup/admin (`*admin*`,`*setup*`) > system/power-on (`*system*`,
   `*power*`) > any other password-named attr; write to the best writable one.
   After the write, re-read the slot and only report `cleared` if it's actually
   unset.
5. **Confirm the clear took effect** — after writing, re-read
   `is_password_set`/`current_value`; if still set, report `failed` with detail
   `"password still set after clear (slot=<name>) — wrong password or read-only"`.
6. **`uuid` in the claim** — `unlock_claim($user, $serial, $uuid)` matches
   `serial = ? AND (uuid = ? OR uuid = '')`; fall back to serial-only when the
   staged row has an empty uuid (older rows).
7. **`curl -k` TLS clock-skew fallback** — wrap the pending GET + result POST in
   the same retry used by `report::upload_http`: on curl error 60, retry once
   with `-k`.
8. **Result state machine server-side** — `unlock_report` updates only
   `WHERE status = 'dispatched'`; a result for a non-dispatched row is rejected
   (and returns a flag so the appliance can retry after a requeue).

## 5. Test plan

**Appliance (extend `test_bios_unlock.sh`, fake sysfs + curl):**
- base64 password round-trip (`password_b64` decoded correctly, incl. quotes/
  backslash).
- slot priority: a tree with both `SystemPassword` and `AdminPassword` clears
  `AdminPassword` first; re-verify detects a still-set slot → `failed`.
- pending parse ignores the legacy field gracefully (no `password` → no-op).

**Server (PHP — no test harness in the repo, so: `php -l` + manual curl):**
- stage a password with `"` and `\` → pending returns `password_b64` that
  base64-decodes to the original.
- claim + simulate a lost result → row requeues to `pending` after the TTL.
- enqueue a second command → superseded row's `password_enc` is empty.
- report a result for a `pending` (non-dispatched) row → rejected.
- claim with a mismatched `uuid` → no match.

**Real hardware:** a Dell/Lenovo unit with a set admin password →
stage→clear→`cleared`; wrong password → `failed` with the new detail; RTC-skewed
unit → `-k` fallback path exercises.

## 6. Gotchas / what could break

- **Lockstep deploy** — the appliance and server change together, but the fleet
  runs older appliances. Keep `password` (legacy) in the response until the fleet
  is on the new build, then drop it.
- **BusyBox `base64`** — present in the image (already used for sedutil payload);
  confirm `base64 -d` handles the newline-wrapped output (server should emit a
  single-line base64).
- **Attribute-name heuristics** — `*min*` matches "admin", so policy-name
  exclusions must use `*minimum*`/`*maximum*` (already the case in `36_bios.sh`).
  Slot scoring must apply the same exclusions.
- **Re-read timing** — some firmware_attributes drivers reflect the cleared state
  only after a short delay or on the next boot; if re-read is unreliable, fall
  back to reporting the write result honestly (don't claim `cleared` on a write
  that returned success but still reads as set).
- **TTL race** — a result arriving after a TTL requeue could mark a re-queued row
  done; the `status='dispatched'` guard in `unlock_report` closes this.
- **HP path** — `hp-wmi` has no `is_password_set`/re-read signal; keep its
  write-result reporting as-is (can't re-verify), and note it.
- **Schema** — no new columns needed (TTL uses `dispatched_at`/`created_at`);
  `unlock_ensure_schema` stays idempotent.

## 7. Decisions for review

1. Include or defer the §8 **"unlock-only boot"** item (a lightweight boot flag
   to rescue a BIOS without a wipe)? Recommended: defer — it's a feature, not a
   robustness fix.
2. TTL values — dispatched 10 min, stale-pending 7 days. OK?
3. Transition — keep the legacy `password` field alongside `password_b64` for one
   release, or cut over cleanly?
