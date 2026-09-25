# Licence & Download Workflow

How customers get tScrub and a licence, and how paid tiers are fulfilled.

## Overview

tScrub **always requires a licence** — even the free tier. Downloads and licence
issuance are self-serve, backed by MySQL:

```
login/register → /dashboard/licences (pick tier) → POST /api/licence {tier} → licences table
                                                                      ↓
                                                         .lic downloaded from the dashboard
```

The old email-gated "Request download" form (POST `/submit`) is gone from the
download page — `/submit` is now only the contact form. Every issued licence is a
row in the `licences` table and shows on the user's dashboard and the admin page.

---

## 1. Self-serve (default path)

1. Visitor signs up at `/register` (personal or company) and verifies their email.
2. They sign in and open the dashboard **Licences** page (`/dashboard/licences`),
   pick a tier, and click **Issue licence**.
3. The licence is issued to their account (a `licences` row with the tier) and
   they download the `.lic` from the dashboard Licences page.
4. They download the bootable appliance ISO from the Download page (`/download`)
   and put the `.lic` on the boot USB — or serve it via `--license-url` /
   `tscrub_license_url=`.

No manual step for the free tier.

## 2. Tiers

| Tier | Licence `tier` | Notes |
|---|---|---|
| Free (£0) | `free` | Full erasure, self-signed reports (tamper-evident, not attributable). Fully self-serve. |
| Pay-as-you-go (£0.25/device) | `payg` | Self-serve licence today; billing not wired (below). |
| Team (£99/mo) | `team` | Self-serve licence today; billing not wired. |
| Enterprise (custom) | `enterprise` | Contact sales; issue manually (section 3). |

**Governance note:** payg/team/enterprise licences are **admin-only**. The API
(`POST /api/licence`) and the UI both reject paid tiers for non-admins (403), and
the pricing/plan buttons become "Contact us" for non-admins. Admins issue paid
licences from `/admin` (per-user "Issue licence") or via
`POST /api/admin/users/{id}/licence`; every admin action is written to
`admin_audit_log` (viewable on `/admin`).

## 3. Manual issue (CLI fallback / Enterprise)

The website issues licences by calling `issue_licence.py --tier <tier> --json`.
To issue one by hand (or re-issue):

```bash
# with file + email (now takes a tier)
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && python3 issue_licence.py --tier enterprise "Acme ITAD Ltd" 2027-09-12 buyer@example.com'

# machine-readable: print the .lic JSON to stdout (no file, no email)
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && python3 issue_licence.py --tier free --json "Acme ITAD Ltd" 2027-09-12'
```

The CLI writes `licences/<slug>-<expiry>.lic` and emails, but does **not** create
a DB row — use the dashboard/admin path for DB-tracked licences; the CLI is for
ad-hoc/Enterprise issuance.

> **Attribution note:** the server binds uploaded reports to the licence's
> report key via `licences.pub_key`, which only exists for DB-tracked (web/admin)
> licences. A licence issued only via the CLI has no DB row, so reports signed
> with it will be recorded but **not attributable** — issue paid licences through
> `/admin` (or `POST /api/admin/users/{id}/licence`) to enable attribution.

## 4. Expiry guidance

- Free: 1 year, renewable.
- Team (monthly): short windows, re-issue on renewal.
- Enterprise (annual): contract end date.
- Trials: 14–30 days.

## 5. Admin view

Sign in as an admin and open `/admin`:
- **Users** — list (with cert/licence counts), per-user role change, suspend/activate, revoke sessions.
- **User details** — a user's certificates, licences, tokens, and active sessions, plus an "Issue licence" form (any tier).
- **Certificates** — every certificate (owner, CoC, devices, issued date).
- **Licences** — every licence across all users (tier, customer, owner, expiry).
- **Audit log** — every admin action (admin, action, target, IP, time).

Every admin mutation (role, status, session revoke, licence issuance) is recorded
in `admin_audit_log`.

DB access for audits:

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && \
  printf "[client]\nhost=127.0.0.1\nuser=tScrub\npassword=<DB_PASSWORD>\ndatabase=tScrub\n" > /tmp/.my.cnf && \
  mysql --defaults-extra-file=/tmp/.my.cnf -e "SELECT id,user_id,tier,customer,expiry,created_at FROM licences ORDER BY id DESC LIMIT 20" && \
  rm -f /tmp/.my.cnf'
```

## 6. Revoke & rename

There is no revocation UI by design (licences are verified offline; see
`licence-revocation.md`). To revoke, suspend the account and revoke its API
tokens/sessions; to rename, re-issue with a new customer name. Full steps:
**[`ops/licence-revocation.md`](licence-revocation.md)**.

---

## Server reference

| Thing | Location |
|---|---|
| Backend (PHP/Python) | `/home/oxwet/webs/tscrub-form/` |
| Database | MySQL `tScrub` @ 127.0.0.1:3306 (creds in `config.json`) |
| Tables | `users`, `sessions`, `certificates`, `certificate_reports`, `certificate_drives`, `licences`, `api_tokens`, `tokens`, `admin_audit_log` |
| Config (SMTP, DB, vendor key path) | `config.json` |
| Vendor private key | `vendor.key` (mode 640 group www-data, never leaves the server) |
| Issued licences | `licences` table (legacy files kept in `licences/`) |

Key routing (from `config.json`):
- `to_download` → `support@tscrub.com`
- `from_licence` → `support@tscrub.com`
- `to_contact` → `hello@tscrub.com`
- `to_support` → `support@tscrub.com`

---

## Not built yet

- [ ] Billing / payment gateway (Stripe) → gate payg/team/enterprise licence issuance
- [ ] Auto-issue licence on payment webhook
- [ ] Drive-count metering from submitted signed reports (link reports → licences via a `licence_id`)
