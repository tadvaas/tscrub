# Monetisation — working backlog

Detail lives in `ROADMAP.md` §5 and §9.3; Stripe specifics in
`ops/stripe-integration.md`. Order = highest impact first. Tick items off as they
ship.

## 1. Security prerequisite

- [ ] **Stripe key rotation** — rotate `sk_live` + webhook secret (both were
      pasted into chat), update `~/webs/tscrub-form/config.json`. ~0 product
      code; do first. (§5)

## 2. Self-serve subscriptions

- [ ] **Subscription billing (Team/Enterprise)** — wire the reserved
      `subscriptions` table to Stripe recurring prices:
      - Team £99/mo → 100 erasures/month
      - Enterprise £399/mo → 500 erasures/month
      The webhook credits the monthly allowance and sets the `team`/`enterprise`
      tier automatically. Today these plans are "contact us" only. (§5)
- [ ] **Billing page** — surface a subscription card (current plan, balance,
      renew/upgrade/downgrade) alongside the existing PAYG credit packs in
      `dashboard/billing.html`.

## 3. Licence & credit model

- [ ] **Pooled licence allocation** — an org admin (see `roadmap/product.md`
      "Organisations & seats") issues sub-tokens against a pooled
      `team`/`enterprise` licence with a per-pool usage counter. (§9.3)
- [ ] **Non-expiring PAYG credits** — credits stop expiring (drop any expiry on
      the wallet). (§9.3)
- [ ] **Perpetual-until-activated licences** — a paid licence flag where expiry
      starts at first report upload, alongside the current dated expiry. (§9.3)

## 4. Pricing & support

- [ ] **Tiered support SLA** — publish a support SLA on the pricing page;
      Team/Enterprise get named support, free tier gets docs/FAQ/community.
      (§9.4 — copy lives with `roadmap/marketing.md`; the plan structure lives
      here.)
- [ ] **Pricing page audit** — confirm PAYG £3/device + packs (10/50/100) match
      the live Stripe prices after any key rotation.

## 5. Deferred / gated

- [ ] Google Ads (paid) — gated: only after organic + conversion tracking are
      healthy (`roadmap/marketing.md` §1 events fire end-to-end). (§4.7)
