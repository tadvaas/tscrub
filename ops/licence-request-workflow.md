# Licence Request Workflow

What to do when a visitor submits a licence/download request on the website.

## Overview

A visitor fills the form on **tscrub.com/download** ("Request download"). The request flows through:

```
download form → POST /submit → submit.php → sendmail.py → Apple SMTP
                                                          ↓
                                            notification lands in support@tscrub.com
```

There is **no automation after this point** — fulfilment is manual. This doc is the runbook.

---

## 1. Read the request

Open the notification email in **support@tscrub.com** (subject: *"tScrub download request"*).

It contains:
- **Name** — the requester
- **Email** — their reply address (`Reply-To` is set to this)
- **Organisation**
- **Plan** — Free, Pay-as-you-go, Team, or Enterprise (chosen in the form)
- **Message** — "Plan: <chosen plan>. Please send tScrub download links and licence details."

---

## 2. Decide the tier

| Tier | What they get | What you send |
|---|---|---|
| **Free** (£0) | Full erasure, self-signed reports | Download link only (no licence) |
| **Pay-as-you-go** (£0.25/device) | Signed reports, no subscription | Download link + billing instructions *(billing not built yet)* |
| **Team** (£99/mo) | Signed reports, licence key, support | Download link + `.lic` file |
| **Enterprise** (custom) | Everything + integrations/SLA | Download link + `.lic` file, follow up for scoping |

The plan is chosen on the **tscrub.com/download** page (pricing now lives there). If it's unclear, ask which plan they want before issuing anything.

---

## 3. Issue a licence (Team / Enterprise)

Run the issuer on the server:

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && python3 issue_licence.py "Customer Ltd" 2027-09-12 buyer@example.com'
```

Arguments:
1. **Customer name** — goes on the licence (e.g. `"Acme ITAD Ltd"`)
2. **Expiry** — `YYYY-MM-DD` (e.g. 1 year from today, or the end of their subscription)
3. **Email** — the buyer's address

This does two things automatically:
- Writes the licence to `~/webs/tscrub-form/licences/<slug>-<expiry>.lic` (on the server, for the record).
- Emails the `.lic` file to the buyer **from support@tscrub.com** with the download link.

### Expiry guidance
- Team (monthly): issue short windows and re-issue on renewal (e.g. next month-end).
- Enterprise (annual): issue to the contract end date.
- Trials: 14–30 days.

---

## 4. Send the download link

The free build is hosted at **https://tscrub.com/downloads/tscrub.sh** (checksum at `/downloads/tscrub.sh.sha256`). The licence email already includes this URL from `config.json` (`download_url`).

For **Free** and **pay-as-you-go** (no licence), reply manually with the download link (and billing next steps for pay-as-you-go).

---

## 5. Verify (optional QA)

The licence is self-verifying — the appliance checks the vendor signature at boot. To confirm a licence is valid before sending:

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && ls licences/'
```

Spot-check a report's signature on the appliance side with:

```bash
tscrub verify report.csv
```

**Vendor public key fingerprint** (published on tscrub.com/docs):
```
be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02
```

---

## Server reference

| Thing | Location |
|---|---|
| Form backend | `/home/oxwet/webs/tscrub-form/` |
| Handler / mailer / issuer | `submit.php`, `sendmail.py`, `issue_licence.py` |
| Config (SMTP, routing, vendor key path) | `config.json` |
| Vendor private key | `vendor.key` (mode 600, never leaves the server) |
| Issued licences | `licences/` |

Key routing (from `config.json`):
- `to_download` → `support@tscrub.com` (licence/download requests)
- `from_licence` → `support@tscrub.com` (licence emails sent from here)
- `to_contact` → `hello@tscrub.com` (general contact)
- `to_support` → `support@tscrub.com` (contact form "Support" option)

---

## Not built yet (future automation)

- [ ] Billing / payment gateway (Stripe)
- [ ] Auto-issue licence on payment webhook (removes this manual step)
- [ ] Drive-count metering from submitted signed reports
