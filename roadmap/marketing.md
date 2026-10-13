# Marketing — working backlog

Refreshed 2026-10-11 against current (late-2026) search/AI distribution
practice — evidence and sources in `research/marketing/README.md`
(`→ Research: research/marketing/README.md`). Detail folded in below from the
former `ROADMAP.md` §4 Distribution & growth runbook (retired 2026-10-07).
**Priority order: technical SEO → on-page SEO → GEO first**, then the
gap-driven content engine, measurement, authority, lifecycle, partnerships and
paid. Tick items off as they ship; deploy after each batch with
`npm run build && npm run deploy`.

## 1. Technical SEO (do first — make everything crawlable, indexable & fast)

- [x] Verify `tscrub.com` in Google Search Console (Domain property).
- [x] **GSC API access (service account)** — GCP project + **Search Console
      API**; read-only SA `gsc-reader@vscode-mcp-499214…`; JSON key gitignored +
      `chmod 600` (repo-root `gsc-service-account.json`). `tscrub.com` verified
      and the SA added as a *Restricted* user. Gap engine + reporting live via
      `research/marketing/gsc_gaps.py`
      (`→ Research: research/marketing/README.md`).
- [ ] `robots.txt` hygiene — keep `Allow: /` + disallow `/login`, `/register`,
      `/dashboard`, `/admin`; keep the `Sitemap:` line. (The AI-crawler
      allowlist lives in §3.)
- [ ] `sitemap.xml` — every indexable page present with a current `lastmod`
      (pages are still stamped `2026-09-19`; refresh on each deploy, and add
      new pages).
- [ ] `<link rel="canonical">` on every public page (self-referencing, absolute,
      the `https://tscrub.com/<slug>` extensionless form).
- [ ] Consistent host + trailing-slash policy (`www` → apex 301; extensionless
      URLs via nginx `try_files`, already the case) — verify no `/x` vs
      `/x.html` duplicate indexing.
- [ ] **Force HTTPS** — `http://tscrub.com/*` returns **200** (no redirect) and
      both `http://` and `https://` URLs are indexed in GSC → duplicate content
      and split signals. Add a permanent 301 to `https://` at the edge.
      *Drafted:* `marketing/server/nginx-redirects.conf` (apply with sudo).
- [ ] Crawl hygiene — no broken internal links; 301 the retired pages
      (`/how-it-works`, `/getting-started`, `/updates` currently **404** yet
      still hold GSC impressions; also `/certify`, `/dashboard/reports`,
      `/dashboard/certify`, `/dashboard/licence`, `/dashboard/settings`);
      confirm login-gated dashboard pages are `noindex` as well as disallowed.
      *Retired-page 301s drafted in `marketing/server/nginx-redirects.conf`.*
- [ ] **Kick indexing** — submit the sitemap and Request Indexing (GSC URL
      Inspection) on the money pages. GSC shows only **2 queries / 14 pages /
      25 impressions over 16 months** (see `research/marketing/`), so indexing
      — not gap-mining — is the current bottleneck.
- [ ] Validate the existing `Article` JSON-LD on all 27 `resources/*` pages
      (Rich Results Test / Schema Markup Validator) before adding new types.
- [ ] Core Web Vitals / Lighthouse pass on the top pages (home, compliance,
      docs, download, pricing, resources hub).
- [ ] Serve the `.md` twins correctly once §3 ships (`Content-Type:
      text/markdown`, UTF-8, no rewrite loop).

## 2. On-page SEO (the pages themselves)

- [ ] Rewrite `<title>` + meta description on the top pages to match the exact
      money query (one page, one intent).
- [ ] One descriptive H1 per page; no skipped heading levels.
- [ ] Add a 2–3 sentence direct-answer paragraph under every H1 ("What is X?")
      — the block search engines quote for snippets and assistants quote as a
      citation (§3).
- [ ] Internal linking in both directions: money pages ↔ decision guides ↔
      proof/tool, with descriptive anchor text.
- [ ] Image `alt` text on every content image.
- [ ] FAQ content matches the `FAQPage` schema added in §3.
- [ ] **"Wipe an SSD from BIOS" coverage page** — high-volume money query with
      no page; port the vendor menu table + frozen-drive fix into
      `resources/wipe-ssd-from-bios.html`. (§4.6)
- [ ] **Comparison & alternative pages** — "tScrub vs Blancco", "tScrub vs
      BitRaser", "Blancco alternatives", "DBAN alternatives" (expand), "best
      disk erasure software". High-intent and heavily surfaced by assistants.
- [ ] **Information gain on every page** — each page must add something the
      competing results don't (our own field data, a unique table, a decision
      rule). Copycat/lookalike content is what AI summarisers skip.
- [ ] **Refresh before you publish** — the `/resources` guides are stamped
      `2026-09-22`; update decaying pages (content-refresh pass) before adding
      new ones — it usually out-earns new pages for both SEO and AI search.
- [ ] Fix stale dashboard reference in `llms-full.txt` — "Reports page" →
      "Drives page" (uploads moved when the Reports tab was folded into Drives);
      re-scan `llms.txt` + `llms-full.txt` for any other dead "Reports page"
      refs.

## 3. GEO — AI / answer-engine optimisation

Buyers increasingly ask an assistant, not Google. Own the answer.

- [ ] **AI-crawler allowlist in `robots.txt`** — never block the answer engines
      we want to be cited by: `GPTBot`, `OAI-SearchBot`, `ChatGPT-User`,
      `ClaudeBot`, `Claude-User`, `PerplexityBot`, `Perplexity-User`,
      `Google-Extended`, `Applebot-Extended`, `Bingbot`, `CCBot`,
      `meta-externalagent`, `cohere-ai`, `DuckAssistBot`, `MistralAI-User`.
      (§4.1)
- [ ] **Upgrade `llms.txt` to the v2 shape** — keep it short and expert-level
      (detail behind links) and publish a `/docs/llms.txt` for the docs. Mark it
      up with `rel="describedby"` → `llms.txt` and
      `rel="alternate" type="text/markdown"` → the `.md` twin on each page
      (HTML `<link>` or an nginx `Link:` header).
- [ ] **Ship markdown twins** of the money pages and guides (`page.html.md` at
      the same URL) — clean text agents can fetch without navbar/JS. Keep the
      large `llms-full.txt` for one-shot context. (Pairs with the §1 serving
      item.)
- [ ] **Structured data for machines** — add `FAQPage` (mirrors the visible
      FAQ), `BreadcrumbList`, `Organization` (+ `sameAs` to GitHub/G2/etc.),
      `SoftwareApplication`/`Product` with `Offer`, and `HowTo` for the wipe
      guides. Schema must match visible content.
- [ ] **Entity consistency** — one canonical description + NAP across
      `llms.txt`, schema, GitHub, LinkedIn, Crunchbase/Wikidata and the review
      profiles, so models resolve "tScrub" to the same entity.
- [ ] **AI-visibility baseline + monthly pass** — a fixed prompt set ("how do I
      wipe an NVMe SSD", "is this laptop Autopilot enrolled", "Blancco
      alternatives", "open source disk erasure") across ChatGPT, Perplexity,
      Gemini, Copilot and AI Overviews; log whether tScrub appears/is cited and
      which competitors are.
- [ ] **Information gain is the bar** — answer engines cite the source that adds
      something (original data, a definitive rule), not the Nth summary; that is
      our biggest lever, and we hold the field data (§6).
- [ ] **One authoritative AI-facing facts layer** — a single always-current
      "key facts / about" source (extend `llms.txt` + an `Organization` `sameAs`
      set) so assistants quote the description we publish.
- Guardrail: **no AEO snake-oil** — only real citations and real referrals
  (§5), never a vendor "visibility score". Source mix shifts fast (e.g. ChatGPT
  citing Reddit fell in Aug 2026) — build durable, owned authority, not one
  channel.

## 4. Content engine — gap-driven (GSC + analytics → content)

Find the gaps, brief them, publish, link, measure, repeat. GSC (set up in §1)
is enough to start — the query half needs no new tooling.

- [ ] **GSC query mining (primary gap source)** — via the §1 service-account
      access, pull the Queries report and bucket every row:
  - *Striking distance* — positions ~8–20 with real impressions (one push from
    page 1).
  - *Low-CTR* — high impressions, weak click-through → rewrite title/meta (§2).
  - *Zero-click* — we rank but the page doesn't answer the question.
  - *Uncovered* — demand we rank for nowhere → new page.
- [ ] **Analytics behaviour layer** — landing pages with high bounce / no
      conversion, and the pages that precede `signup` / `purchase` /
      `activation` (§5). *(Tool choice: see the GA4-vs-Plausible decision.)*
- [ ] **Cluster → brief → publish** — group queries into topics; each becomes
      either an update to an existing guide, a new `/resources` guide, or an
      `/insights` post (see the blog decision).
- [ ] **Information-gain test at brief time** — before writing, ask "what can we
      add that the ranking pages don't have?" If the answer is "nothing",
      refresh an existing page instead of publishing another lookalike.
- [ ] **Wire it up** — add every new page to `sitemap.xml` + `llms.txt`, link it
      internally (§2), then re-check position/CTR in GSC after ~4 weeks and
      iterate.
- [ ] **Feed the AI gap too** — run the same clusters against the §3 prompt set
      to see which answers we still don't own.
- [ ] **LLM-assisted research & reporting** — use an LLM to cluster GSC queries,
      draft briefs and assemble the monthly report (an "AI research engine"
      pattern); a human edits before publish.

### Decision — do we need a blog?

**No blog as such; yes to a dated, authored `/insights` collection.** The
evergreen library already lives in `/resources` (27 definitive guides), so a
second chronological silo would cannibalise it and impose a cadence we won't
keep. What's missing a home is *first-party, time-stamped* content: original
research/data (the §6 digital-PR asset), standards/news analysis, and product
deep-dives.

- `/resources/` = evergreen definitive guides (the library) — unchanged.
- `/insights/` (a.k.a. Field Notes) = dated, authored posts with
  `datePublished`/`dateModified`, an RSS + JSON feed, on the *gap-driven*
  cadence (no calendar).
- Reuse the guide template + `Article` schema; cross-link both ways; add to
  `sitemap.xml` + `llms.txt`.
- If the only goal is release announcements, **skip it** — `/docs` release
  history + `CHANGELOG` already cover that.

### Decision — GA4 or Plausible? (needs sign-off)

GSC is the only source of query-level gap data, so it's non-negotiable. The
behaviour layer is the real choice:

- **GA4** — richest funnels + native GSC linkage, but it sets cookies, and
  `privacy.html` §13 currently states the site "does not set tracking cookies"
  → GA4 means a consent banner **and** a privacy-policy rewrite.
- **Plausible** (the house preference) — cookieless, keeps the policy true,
  covers pages / engagement / conversions for our needs.

**Recommendation: GSC + Plausible.** Add GA4 only if a consent banner and the
policy change are acceptable for deeper funnel reporting (then keep Plausible as
the always-on baseline and load GA4 only after consent).

## 5. Measurement (enables reporting — trivial bits can run in parallel with §1–3)

- [ ] Add privacy-friendly analytics (Plausible preferred — no cookie banner) to
      every public page. (§4.1)
- [ ] Wire conversion events: `signup` (register), `purchase` (Stripe
      `checkout.session.completed` / `payment_intent.succeeded`), `activation`
      (first report upload → first certificate). The last two already fire
      server-side; surface them to the analytics tool. (§4.1)
- [ ] **Track AI-assistant referrals as their own channel** — record referrer
      hosts for `chatgpt.com`, `perplexity.ai`, `gemini.google.com`,
      `copilot.microsoft.com`, `claude.ai`, `bing.com/chat`, and AI Overviews
      click-throughs, and report them like any other source.
- [ ] **Save a dated baseline** (traffic, rankings, referring domains, brand
      search, AI citations) as §1–3 start shipping. (§4.2)

## 6. Authority — the hard 20% that produces clicks

- [ ] **Directory & review listings** — associations: ADISA, NAID (i-SIGMA),
      IASME, BSIA. Review sites: G2, Capterra, GetApp, Software Advice,
      TrustRadius, PeerSpot, SourceForge, AlternativeTo, Slant, SaaSworthy.
      Real backlinks in an afternoon. (§4.3)
- [ ] **Citation-magnet tools** (build, then pitch — a tool only becomes a
      citation magnet once someone cites it):
  - [ ] Compliance checker — "which erasure standard applies to me?"
  - [ ] Erasure cost / carbon estimator
  - [ ] Device value / refurb grader (promotes the product refurb-grade work)
  - [ ] "Is this device Autopilot-enrolled?" explainer (owns our real niche)
  Each ships at a clean URL, is added to `llms.txt` + `sitemap.xml`, and is
  pitched. (§4.4)
- [ ] **Digital PR via original data** — publish what only we can (in
      `/insights`): drive firmware quirks, BIOS-lock/Autopilot field findings
      (anonymised), a small aggregated "state of ITAD fleet" dataset. Pitch to
      ITAD/security/enterprise-IT press. Original data is the most citable asset
      for both journalists and LLMs.
- [ ] **Community/forum distribution** — `Show HN` on release; answer real posts
      in r/sysadmin, r/Intune, r/ITAD, r/homelab, r/selfhosted, r/datahoarder,
      Spiceworks, Technibble, Lobsters, PrivacyGuides — link the relevant guide
      and disclose affiliation.
- [ ] **Ecosystem wedge — Microsoft Intune/Autopilot** — the Autopilot-lock
      check is a genuine entry point for Windows admins: publish walkthroughs to
      the Microsoft Tech Community, Microsoft Q&A, r/Intune and the Intune blog
      ecosystem, targeting "check if device is Autopilot enrolled".
- [ ] **GitHub & awesome-lists** — repo hygiene (clear README, topics, releases,
      `good first issue`), then PRs to the relevant `awesome-*` /
      `awesome-sysadmin` ITAM sections. (§4.3)
- [ ] **Video SEO** — short "how to wipe an NVMe SSD / check Autopilot lock"
      screen-captures on YouTube; the transcript is crawlable and the video
      embeds on the matching guide pages.
- [ ] **LinkedIn (organic first)** — the ITAD/compliance buyer lives there;
      republish the same insights as posts, and only test LinkedIn ads once
      organic proves the audience.
- [ ] **Survey-driven whitepaper** — a short industry survey (ITAD erasure
      practices) as the annual flagship original-data asset that feeds the PR
      above.
- [ ] **Outreach** — target list (named person + verified email per target),
      LLM-drafted personalisation with human send, pipeline tracking
      `researched → sent → replied → linked`. (§4.5)

## 7. Product-led growth & lifecycle

- [ ] **Email lifecycle** — the download form is the only list we own: a
      "getting started" sequence (download → boot → first licence → first
      certificate), an activation nudge, and a low-volume ITAD-compliance
      newsletter.
- [ ] **Frictionless first run** — the free self-serve licence + ISO download is
      the growth loop; measure time-to-first-certificate and remove steps.
- [ ] **Free-to-paid conversion loop** — instrument and improve `free →
      PAYG/Team` (against free-to-paid benchmarks); the free licence is the top
      of the funnel, so treat `free → first paid` as the north-star marketing
      metric.
- [ ] **Packaging review (~6-monthly)** — pricing/packaging is a marketing
      surface that now decays fast; review tiers/credits roughly every 6 months
      (owner: `roadmap/monetisation.md`).

## 8. Partnerships / channel

- [ ] **ITAD companies, refurbishers, MSPs/VARs** — co-marketing, partner
      listings, and integration pages (Microsoft Intune/Autopilot,
      HPE/Dell/Lenovo firmware tools). A partner page per ecosystem is both a
      backlink and a conversion surface.

## 9. Paid (gated)

- [ ] Google Ads — only after §5 events fire end-to-end; one campaign per
      audience (consumer vs commercial/ITAD); review at 6–8 weeks against CPA.
      (§4.7)

## 10. Cadence

- [ ] Weekly (15 min): GSC trend + quick gap scan, AI-visibility pass, 1–2
      pitches, one directory form.
- [ ] Monthly (1 h): gap analysis (GSC queries + analytics), brief & build one
      page/tool for the top cluster, `build && deploy`.
- [ ] Quarterly (half day): content-refresh sweep (update decaying pages),
      regenerate PDF/report assets, review referring domains, refresh the
      AI-visibility + referral baseline, review pricing/packaging, decide whether
      authority justifies ads.

## Guardrails (never break)

- Verified claims only — no invented clients, stats, case studies, or
  certifications.
- No fake reviews/testimonials; schema and copy match reality (schema mirrors
  visible content).
- Never claim a certification we don't hold (ADISA, R2, NAID, etc.).
- No AEO/GEO snake-oil — measure real citations and real referrals, not vendor
  scores.
- Personalise every outreach email — the LLM drafts, a human sends.
- Keep `site/public/llms.txt` + `site/public/llms-full.txt` in sync with any
  new/retitled page or service (and the `.md` twins once §3 ships).
- **Secrets never enter the repo or the chat** — GSC/GA/vendor keys live outside
  the tree at `chmod 600` (e.g. `~/.config/tscrub/`); share only the file path.
