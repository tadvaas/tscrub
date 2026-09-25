# Licence Revocation & Rename Runbook

Pragmatic playbook for revoking or renaming a customer licence using controls
that already exist. There is intentionally **no revocation UI and no `revoked`
flag** — the "why not" note at the bottom explains the trade-off.

## Key fact: licences are verified offline

A `.lic` is checked on the appliance against the embedded vendor public key and
its expiry. The appliance does **not** phone home, so nothing in this runbook can
stop an appliance that already holds a valid `.lic` from booting. Everything
below blocks the **platform** side (sign-in, report upload, certificate
issuance, re-downloads). The offline levers are short expiry dates and, in an
extreme case, vendor-key rotation (see `vendor-key-rotation.md`).

## Revoke a licence (platform side)

A licence's platform privileges come from the **account** and its **API tokens**,
not from the `licences` row itself. To revoke:

1. **Suspend the account**
   - Admin UI: `/admin` → Users → the user → **Suspend** (sets `users.status`).
   - SQL:
     ```sql
     UPDATE users SET status='suspended' WHERE email='buyer@example.com';
     ```
2. **Revoke sessions and API tokens**
   - Admin UI: user details → **Revoke sessions**.
   - SQL:
     ```sql
     DELETE FROM api_tokens WHERE user_id=(SELECT id FROM users WHERE email='buyer@example.com');
     DELETE FROM sessions    WHERE user_id=(SELECT id FROM users WHERE email='buyer@example.com');
     ```
3. **Note the expiry.** The `.lic` itself still verifies on appliances until its
   `expiry` date — that is the offline limit. Keep paid licences short-dated so
   this window is small.

Effect: the customer can no longer sign in, upload reports, or issue
certificates. The existing certificate records and PDFs are unaffected (they are
immutable evidence).

## Rename / re-issue a licence

The customer name is signed into the licence JSON, so a rename is a re-issue
(one command, which also emails the new `.lic`):

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && \
  python3 issue_licence.py --tier <tier> "New Name Ltd" <YYYY-MM-DD> buyer@example.com'
```

The old `.lic` stays valid until its expiry, so pair a rename with revocation
(suspend + note) whenever the old identity must stop being used. Every
`issue_licence.py` run generates a fresh report-signing key, so the old key is
naturally abandoned.

## Leaked paid licence (private report key)

A paid `.lic` embeds the customer's private report-signing key. If one leaks:

1. Suspend the account and revoke tokens (above).
2. Re-issue with a **new** key (see Rename above) — the leaked key is abandoned.
3. Record the incident. Reports already signed with the old key still verify
   (their manifests embed that key) — they remain valid evidence but should be
   treated as attributable to the compromised key. Invalidating an old key for
   **new** uploads would need a revoked-key list, which is not built (see below).

## Why there is no revocation feature

- Boot verification is offline, so revocation can't reach the appliance.
- Report uploads are gated by API tokens (already revocable) and certificates by
  login (accounts are already suspendable) — the levers above cover the platform.
- Licences already expire; short expiries are the offline "revocation" lever.

Only build a `revoked` flag plus upload/certify enforcement if a real incident
(a leaked paid key) demands it, or if the appliance ever gains online checks.
