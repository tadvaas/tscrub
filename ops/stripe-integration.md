# Stripe billing & Apple Pay integration

Reference for anyone (or any LLM) working on Quantum Pings payments. Describes how
the Plus/Patron subscription flow works end-to-end: environment, database, backend,
webhooks, frontend, and Apple Pay. All secret values are redacted — only variable
names, formats, and non-secret identifiers are shown.

---

## 1. What the system sells

Three tiers, all billed in GBP:

| Tier   | Monthly | Annual  | Stripe price env var          |
| ------ | ------- | ------- | ----------------------------- |
| Free   | £0      | £0      | —                             |
| Plus   | £4.99   | £39.99  | `STRIPE_PRICE_PLUS_MONTHLY` / `STRIPE_PRICE_PLUS_ANNUAL` |
| Patron | £9.99   | £99.00  | `STRIPE_PRICE_PATRON_MONTHLY` / `STRIPE_PRICE_PATRON_ANNUAL` |

Price IDs are non-secret resource identifiers (e.g. `price_1UJDII...`). The only
secrets are the secret key and the webhook signing secret.

A "Plus member" (`hasPlus`) is anyone whose `tier` is `plus` or `patron`, **or** who
has the `complimentary` flag set (a manually-granted Plus).

---

## 2. Environment variables (server `.env`, NOT synced by deploy)

The server `.env` lives at `/home/oxwet/webs/qp/.env`. Keys:

```
STRIPE_SECRET_KEY=sk_live_…          # or sk_test_… in test mode
STRIPE_PUBLISHABLE_KEY=pk_live_…     # safe to expose to the browser
STRIPE_WEBHOOK_SECRET=whsec_…        # signs webhook payloads
APP_BASE_URL=https://www.quantumpings.com
STRIPE_PRICE_PLUS_MONTHLY=price_…
STRIPE_PRICE_PLUS_ANNUAL=price_…
STRIPE_PRICE_PATRON_MONTHLY=price_…
STRIPE_PRICE_PATRON_ANNUAL=price_…
```

`.env.example` in the repo documents the same names. After editing `.env`, restart
the process **with `--update-env`** (`pm2 restart qp --update-env`) so PM2 reloads it.

---

## 3. Database (`schema.sql` → `subscriptions` table)

One row per user (unique on `user_id`):

| Column                  | Type     | Purpose |
| ----------------------- | -------- | ------- |
| `user_id`               | INT      | FK to `users` |
| `tier`                  | VARCHAR  | `free` / `plus` / `patron` |
| `status`                | VARCHAR  | `none` / `active` / `past_due` |
| `stripe_customer_id`    | VARCHAR  | Stripe customer (`cus_…`) |
| `stripe_subscription_id`| VARCHAR  | Stripe subscription (`sub_…`) |
| `current_period_end`    | DATETIME | when the paid period ends |
| `cancel_at_period_end`  | TINYINT  | plan cancels at period end |
| `complimentary`         | TINYINT  | manually-granted Plus |
| `sensitivity`           | VARCHAR  | `gentle` / `balanced` / `alert` |
| `quiet_start` / `quiet_end` | VARCHAR | quiet-hours window (HH:MM) |
| `retain_forever`        | TINYINT  | keep history forever |

---

## 4. Backend

### `lib/billing.js`

- Lazy-loads the `stripe` SDK only when `STRIPE_SECRET_KEY` is set, so a deploy
  without billing still boots. `configured()` returns `!!stripe`.
- `status()` → health object used by `/api/diagnostics` and the admin panel:
  `{ configured, mode ('live'|'test'|'off'), webhookSecretSet, publishableKeySet, prices: {…} }`.
- `priceFor(plan)` → maps a plan key to a price ID.
- `findOrCreateCustomer(user)` → reuses `subscriptions.stripe_customer_id`, else
  creates a Stripe customer and stores the id.
- `createCheckoutSession(user, plan)` → creates the Checkout Session:
  ```js
  stripe.checkout.sessions.create({
    mode: 'subscription',
    customer: customer.id,
    client_reference_id: String(user.id),
    line_items: [{ price: priceId, quantity: 1 }],
    allow_promotion_codes: true,
    ui_mode: 'embedded',
    return_url: APP_BASE_URL + '/upgrade?success=1',
    metadata: { user_id: String(user.id), plan },
    subscription_data: { metadata: { user_id: String(user.id), plan } },
  });
  // returns { clientSecret: session.client_secret }
  ```
  Note: `ui_mode: 'embedded'` keeps payment in-page; `return_url` is where Stripe
  redirects after completion (it appends `redirect_status=succeeded|failed`).
- `cancelSubscription(userId)` → `cancel_at_period_end` on the Stripe subscription
  (or just the DB flag if there's no Stripe sub).
- `tierFromPlan(plan)` → `"plus_monthly".split('_')[0]` → `"plus"`.
- `userIdForSubscription(subId)` → resolve `user_id` from `stripe_subscription_id`
  (for invoice events, which don't carry our metadata).
- `handleWebhook(signature, rawBody)` → `stripe.webhooks.constructEvent(rawBody,
  signature, STRIPE_WEBHOOK_SECRET)`, then switches on `event.type`:

| Event                          | Action |
| ------------------------------ | ------ |
| `checkout.session.completed`   | `subscriptions.setTier(userId, tier, {status:'active', stripeSubscriptionId})` |
| `customer.subscription.updated`| set status (`past_due`→`active`), `current_period_end`, `cancel_at_period_end` |
| `customer.subscription.deleted`| demote to `free`, clear Stripe ids |
| `invoice.paid`                 | set status `active`, extend `current_period_end` |
| `invoice.payment_failed`       | set status `past_due` |

`user_id` is read from `metadata.user_id` on the event object, falling back to
`userIdForSubscription(obj.subscription)` for invoice events.

### `lib/subscriptions.js`

Plan entitlements and the `requirePlus` middleware. `toJson(ent)` exposes
`{ tier, hasPlus, complimentary, cancelAtPeriodEnd, sensitivity, quietStart,
quietEnd, retainForever }`. Also holds the free-prompt cap and the sensitivity
presets (gentle/balanced/alert).

---

## 5. Routes (`server.js`)

| Method | Path                        | Auth        | Purpose |
| ------ | --------------------------- | ----------- | ------- |
| GET    | `/api/plan`                 | `requireAuth` | return plan JSON |
| POST   | `/api/plan/checkout`        | `requireAuth` | start a Checkout Session; validates `plan` against `/^(plus\|patron)_(monthly\|annual)$/`, returns `{ clientSecret }` |
| POST   | `/api/plan/cancel`          | `requireAuth` | cancel at period end |
| POST   | `/api/plan/preferences`     | `requirePlus` | save sensitivity / quiet hours |
| GET    | `/api/stripe-publishable-key` | — | returns `{ publicKey }` (safe to expose) |
| POST   | `/api/stripe/webhook`       | — (signed)  | webhook; returns 400 if signature invalid |

Critical detail — the raw body must be captured for signature verification:

```js
app.use(express.json({
  limit: '100kb',
  verify: (req, res, buf) => {
    if (req.path === '/api/stripe/webhook') req.rawBody = buf;
  },
}));
```

---

## 6. Frontend (`public/upgrade.html` + `public/js/upgrade.js`)

- `upgrade.html` loads `https://js.stripe.com/v3/` plus `/js/upgrade.js`.
- Plan-key mapping: the toggle stores `monthly`/`yearly`; the key is built as
  `tier + '_' + (yearly ? 'annual' : 'monthly')`. (`yearly` must map to `annual`
  — Stripe's price suffix.)
- `checkout(tier)`:
  1. `POST /api/plan/checkout` → `clientSecret`
  2. `GET /api/stripe-publishable-key` → `publicKey`
  3. `Stripe(publicKey).initEmbeddedCheckout({ clientSecret })`
  4. `embedded.mount('#embedded-checkout')`
- **Single-instance rule**: Stripe allows only one Embedded Checkout at a time.
  `destroyEmbedded()` calls `.destroy()` on the previous instance before creating a
  new one (clicking "Get Plus" then "Become a Patron", closing, or switching the
  interval). A `checkoutBusy` flag prevents overlapping requests. A Flowbite-style
  spinner shows on the clicked button while preparing.
- **Plan-aware page state** (`renderPlan`):
  - Not signed in → sign-in card + full pricing.
  - Free → full three-card pricing.
  - Plus / complimentary → thank-you banner, Free card hidden, Plus card becomes
    "✓ You're on Plus", Patron card relabeled "Upgrade to Patron", grid recentered
    to two columns.
  - Patron → thank-you banner, pricing/toggle/VAT note hidden.
- **Refresh**: after payment Stripe redirects to `/upgrade?redirect_status=succeeded`
  (full reload re-fetches `/api/plan`); `upgrade.js` also re-checks on `visibilitychange`
  and `pageshow`.

---

## 7. Apple Pay

### 7.1 Domain registration

The paying domain must be registered with Apple Pay in Stripe (Dashboard →
Settings → Payment methods → Apple Pay → Add domain), or via API:

```js
stripe.applePayDomains.create({ domain_name: 'www.quantumpings.com' });
```

Only `www.quantumpings.com` is registered (Stripe's own `billing.stripe.com` and
`invoice.stripe.com` are unrelated defaults).

### 7.2 Domain association file

Apple verifies the domain by fetching
`/.well-known/apple-developer-merchantid-domain-association`.

Key facts:

- The file is **shared/static across all Stripe merchants** (it certifies Stripe's
  Apple Pay merchant identity). It is 9,114 bytes, SHA-256
  `a6678f3e2431fabb9049ffa94b9c4ad2f767da2ffc28ce7baca6528c9e310a28`.
- It can be fetched from Stripe's own copy:
  `https://checkout.stripe.com/.well-known/apple-developer-merchantid-domain-association`.
- It is committed at `public/.well-known/apple-developer-merchantid-domain-association`.
- `express.static` **ignores dotfiles** (`.well-known` starts with a dot), so it is
  served by an explicit route in `server.js`:

  ```js
  app.get('/.well-known/apple-developer-merchantid-domain-association', (req, res) => {
    res.setHeader('Content-Type', 'application/octet-stream');
    res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
    res.sendFile(path.join(__dirname, 'public', '.well-known', 'apple-developer-merchantid-domain-association'));
  });
  ```

### 7.3 Browser behaviour

- Apple Pay only renders in **Safari** (macOS/iOS) with a card in Apple Wallet.
  Chrome and Firefox never show Apple Pay — Chrome shows **Google Pay** instead.
- In Embedded Checkout the wallet buttons appear automatically, driven by Dashboard
  payment-method settings plus the customer's browser/device — there is no
  per-session flag to force them.

---

## 8. Stripe Dashboard configuration

- **Prices**: 4 subscription prices (Plus monthly/annual, Patron monthly/annual),
  all in GBP, each mapped in `.env`.
- **Webhook endpoint**: `https://www.quantumpings.com/api/stripe/webhook`, subscribed
  to at least: `checkout.session.completed`, `customer.subscription.updated`,
  `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`.
  (Extra events Stripe sends are simply ignored by the `default` branch.)
- **Apple Pay**: domain `www.quantumpings.com` registered.

---

## 9. Operations & testing

- `GET /api/diagnostics` returns the `billing` object (configured, mode, webhook
  secret, publishable key, prices). The admin panel's Billing card renders the same
  data and lists any missing pieces.
- A real end-to-end test requires a real user token; the checkout UI and logic can
  be exercised in the browser by stubbing `fetch` for `/api/plan*` and
  `window.Stripe.initEmbeddedCheckout` (used during development).
- To verify Apple Pay, test in Safari on macOS/iOS with an Apple Pay card; confirm
  the association file is served (`curl -I https://www.quantumpings.com/.well-known/apple-developer-merchantid-domain-association`).

---

## 10. Gotchas & lessons learned

1. **Adding the `stripe` dependency**: run `npm install` locally first so
   `package-lock.json` updates — the server runs `npm ci`, which fails on a
   missing lockfile entry.
2. **Checkout response shape**: return `{ clientSecret }` directly. Wrapping it as
   `{ url: { clientSecret } }` breaks the frontend's `res.clientSecret` read.
3. **`yearly` vs `annual`**: the UI says "yearly" but Stripe's price suffix is
   `_annual`; the frontend must translate.
4. **One Embedded Checkout at a time**: always `.destroy()` the previous instance
   before `initEmbeddedCheckout`, or Stripe throws "You cannot have multiple
   Embedded Checkout objects."
5. **Webhook needs the raw body** — captured via `express.json({ verify })`.
6. **Publishable key** must exist in the server `.env` and the app restarted with
   `--update-env`, or `initEmbeddedCheckout` fails ("Billing is not fully configured").
7. **Apple Pay ≠ Chrome**: don't chase "Apple Pay missing" in Chrome; verify in Safari.
