# Marketing — working backlog

Detail lives in `ROADMAP.md` §4 (Distribution & growth runbook). Order = highest
impact first. Tick items off as they ship; deploy after each batch with
`npm run build && npm run deploy`.

## 1. Measurement (do first — nothing else is measurable without it)

- [ ] Verify `tscrub.com` in Google Search Console (Domain property). (§4.1)
- [ ] Add privacy-friendly analytics (Plausible preferred — no cookie banner) to
      every public page. (§4.1)
- [ ] Wire conversion events: `signup` (register), `purchase` (Stripe
      `checkout.session.completed` / `payment_intent.succeeded`), `activation`
      (first report upload → first certificate). The last two already fire
      server-side; surface them to the analytics tool. (§4.1)
- [ ] Add the AI-crawler allowlist to `robots.txt` (GPTBot, ClaudeBot,
      PerplexityBot, Google-Extended, CCBot, anthropic-ai). (§4.1)
- [ ] Save a dated baseline before acting. (§4.2)

## 2. Authority — the hard 20% that produces clicks

- [ ] **Directory & association listings** — ADISA, NAID (i-SIGMA), IASME, BSIA;
      G2, Capterra, SourceForge, AlternativeTo, Slant; awesome-devsecops +
      data-protection lists on GitHub. Real backlinks in an afternoon. (§4.3)
- [ ] **Citation-magnet tools** (build, then pitch — a tool only becomes a
      citation magnet once someone cites it):
  - [ ] Compliance checker — "which erasure standard applies to me?"
  - [ ] Erasure cost / carbon estimator
  - [ ] Device value / refurb grader (promotes the product refurb-grade work)
  Each ships at a clean URL, is added to `llms.txt` + `sitemap.xml`, and is
  pitched. (§4.4)
- [ ] **Outreach** — target list (named person + verified email per target),
      LLM-drafted personalisation with human send, pipeline tracking
      `researched → sent → replied → linked`. (§4.5)

## 3. On-page gap closure (quick wins)

- [ ] Add `FAQPage` JSON-LD that mirrors the visible FAQ.
- [ ] Add a 2–3 sentence direct-answer paragraph under each H1 ("What is X?").
- [ ] Rewrite `<title>` + meta description on top pages to match the exact money
      query.
- [ ] Link money pages ↔ decision guides ↔ proof/tool in both directions.
- [ ] **"Wipe an SSD from BIOS" coverage page** — high-volume money query with no
      page; port the vendor menu table + frozen-drive fix into
      `resources/wipe-ssd-from-bios.html`. (§4.6)

## 4. Paid (gated)

- [ ] Google Ads — only after §1 events fire end-to-end; one campaign per
      audience (consumer vs commercial/ITAD); review at 6–8 weeks against CPA.
      (§4.7)

## 5. Cadence

- [ ] Weekly (15 min): GSC trend, AI-visibility pass, 1–2 pitches, one directory
      form.
- [ ] Monthly (1 h): gap analysis, build one page/tool for the top cluster,
      `build && deploy`.
- [ ] Quarterly (half day): regenerate PDF/report assets, review referring
      domains, decide whether authority justifies ads.

## Guardrails (never break)

- Verified claims only — no invented clients, stats, case studies, or
  certifications.
- No fake reviews/testimonials; schema and copy match reality.
- Never claim a certification we don't hold (ADISA, R2, NAID, etc.).
- Personalise every outreach email — the LLM drafts, a human sends.
- Keep `site/public/llms.txt` + `site/public/llms-full.txt` in sync with any
  new/retitled page or service.
