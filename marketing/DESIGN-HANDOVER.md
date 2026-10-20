# Marketing Site — Architecture & Design Handover

> **Audience:** another LLM (or developer) asked to reproduce this visual design and
> page structure for a *different* project.
>
> **Source of truth:** `marketing/` in the tScrub repository, as of October 2026.
>
> **What this document is:** a complete description of the software, folder layout,
> build pipeline, design tokens, page templates and component recipes used by the
> tScrub marketing site, plus the non-obvious rules that keep it working.
>
> **What this document is not:** a copy of the tScrub copy, branding, product claims,
> backend, or business logic. Everything under "Replace for your project" must change.

---

## Contents

1. [Executive summary](#1-executive-summary)
2. [Software stack](#2-software-stack)
3. [Directory structure](#3-directory-structure)
4. [Build pipeline](#4-build-pipeline)
5. [Page inventory and routes](#5-page-inventory-and-routes)
6. [Design system](#6-design-system)
7. [Component recipes](#7-component-recipes)
8. [Page templates](#8-page-templates)
9. [Shared JavaScript](#9-shared-javascript)
10. [SEO and AI-search files](#10-seo-and-ai-search-files)
11. [Accessibility and responsive rules](#11-accessibility-and-responsive-rules)
12. [Gotchas that will bite you](#12-gotchas-that-will-bite-you)
13. [Step-by-step replication checklist](#13-step-by-step-replication-checklist)
14. [Deployment](#14-deployment)
15. [Replace for your project](#15-replace-for-your-project)

---

## 1. Executive summary

This is a **hand-authored multi-page static website**, not a framework app and not a
static-site generator.

- Every page is a **standalone `.html` file** with the full `<head>`, navbar, content
  and footer written inline. There is **no layout inheritance, no partials, no
  front-matter, and no templating engine**. The navbar and footer are duplicated in
  every page on purpose.
- Styling is **Tailwind CSS v3 utility classes written directly in the HTML**. There
  are almost no custom CSS component classes — only a handful of flourish utilities
  (`bg-grid-slate`, `bg-grid-ink`, `bg-grad-brand`, `lift`) and a few base rules.
- Interactivity comes from **Flowbite's JavaScript** (mobile navbar collapse) plus
  small hand-written vanilla ES modules (`terminal.js`, `auth.js`, `dashboard.js`).
- **Vite** is used only as a multi-page bundler: it compiles the Tailwind entry CSS,
  bundles the JS, and copies static assets into `dist/`. It does not generate HTML.
- The website and the PHP/MySQL backend are **separate deployables**. The static build
  (`dist/`) is rsynced to a web docroot; the backend lives outside that docroot and is
  reached over `/api/*`.

Design personality: clean, technical, trustworthy B2B SaaS. White/near-black surfaces,
slate body text, one emerald ("sanitized green") accent, Inter typography, rounded
cards, blueprint grid overlays, and a dark terminal illustration in the hero.

---

## 2. Software stack

| Piece | Version / choice | Purpose |
|---|---|---|
| **Vite** | `^5.4.10` | Multi-page build, JS bundling, `public/` copying |
| **Tailwind CSS** | `^3.4.14` | All styling, utility-first |
| **PostCSS** | `^8.4.47` | Tailwind processing |
| **Autoprefixer** | `^10.4.20` | Vendor prefixes |
| **Flowbite** | `^2.5.2` | JS behaviours (navbar collapse, dropdowns) |
| **@tailwindcss/typography** | `^0.5.x` | `prose` classes for article bodies |
| **Inter** | Google Fonts, weights 400–800 | UI and headings |
| **Node** | 18+ | Tooling only |
| **Static host** | nginx | Serves `dist/`, rewrites clean URLs to `.html` |

Install:

```bash
npm install
# NOTE: the source project resolves @tailwindcss/typography transitively and does
# not declare it. For a clean project, declare it explicitly:
npm install -D @tailwindcss/typography
```

`package.json` scripts:

```json
{
  "scripts": {
    "dev": "vite",
    "build": "vite build && node scripts/copy-well-known.mjs",
    "preview": "vite preview",
    "deploy": "bash deploy.sh",
    "deploy:server": "bash deploy-server.sh"
  }
}
```

There is **no React, Vue, Svelte, Astro, Eleventy, Hugo, Jekyll or Next.js**. Do not
introduce one unless the target project explicitly wants it; the design is entirely
reproducible with plain HTML + Tailwind.

---

## 3. Directory structure

```text
marketing/
├─ package.json
├─ vite.config.js            # multi-page entry map + outDir
├─ tailwind.config.js        # design tokens, content globs, plugins
├─ postcss.config.js         # tailwindcss + autoprefixer
├─ add-dark-variants.py      # one-off dev script (NOT part of the build)
├─ deploy.sh                 # build + rsync dist/ to the web host
├─ deploy-server.sh          # rsync the PHP backend (unrelated to the design)
├─ scripts/
│  └─ copy-well-known.mjs    # copies site/public/.well-known into dist/
├─ site/                     # ← Vite root
│  ├─ index.html             # landing page
│  ├─ compliance.html
│  ├─ compare.html
│  ├─ pricing.html
│  ├─ faq.html
│  ├─ download.html
│  ├─ docs.html
│  ├─ contact.html
│  ├─ privacy.html
│  ├─ terms.html
│  ├─ login.html
│  ├─ register.html
│  ├─ certify.html           # redirect stub
│  ├─ dashboard.html         # redirect stub → /dashboard/devices
│  ├─ dashboard/             # authenticated application pages
│  │  ├─ devices.html
│  │  ├─ drives.html
│  │  ├─ reports.html
│  │  ├─ certificates.html
│  │  ├─ licences.html
│  │  ├─ licence.html        # redirect stub
│  │  ├─ billing.html
│  │  ├─ account.html
│  │  ├─ mdm.html            # redirect stub
│  │  └─ index.html          # redirect stub
│  ├─ resources/
│  │  ├─ _template.html      # copy-source template, NOT built
│  │  ├─ standards.html
│  │  ├─ nist-800-88.html
│  │  └─ … ~30 long-form SEO articles
│  ├─ src/
│  │  ├─ main.js             # shared entry imported by public pages
│  │  ├─ input.css           # Tailwind directives + custom utilities
│  │  ├─ terminal.js         # animated terminal in the home hero
│  │  ├─ auth.js             # keeps the nav CTA consistent
│  │  └─ dashboard.js        # injects dashboard sub-nav + account chrome
│  └─ public/                # copied verbatim to dist/
│     ├─ logo.png
│     ├─ logo.svg
│     ├─ robots.txt
│     ├─ sitemap.xml
│     ├─ llms.txt
│     ├─ llms-full.txt
│     ├─ assets/
│     │  └─ manual-assets.js # hand-written dashboard module (not bundled)
│     └─ .well-known/        # copied by the extra build script
└─ server/                   # PHP backend — NOT part of the static build
```

Key point: **`site/` is the Vite root**, so URLs in HTML are written as `/src/main.js`
and `/logo.png`, and `dist/` becomes the document root.

---

## 4. Build pipeline

### 4.1 `vite.config.js` — multi-page entry map

Vite needs every HTML page listed explicitly. There is **no glob**, so adding a page
without registering it means it is not built or deployed.

```js
import { defineConfig } from 'vite'
import { fileURLToPath, URL } from 'node:url'

const pages = {
  main: 'index.html',
  compliance: 'compliance.html',
  pricing: 'pricing.html',
  docs: 'docs.html',
  resources: 'resources.html',
  'resources-nist-800-88': 'resources/nist-800-88.html',
  // dashboard pages are registered too
  'dashboard-devices': 'dashboard/devices.html'
}

export default defineConfig({
  root: 'site',
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    rollupOptions: {
      input: Object.fromEntries(
        Object.entries(pages).map(([name, file]) => [
          name,
          fileURLToPath(new URL('site/' + file, import.meta.url))
        ])
      )
    }
  }
})
```

Notes:

- `_template.html` is deliberately **absent** from `pages`, so the template is never
  built or deployed.
- The entry key is cosmetic; the output path follows the source path, e.g.
  `site/resources/nist-800-88.html` → `dist/resources/nist-800-88.html`.
- Hashed asset filenames are produced by Vite (`dist/assets/*.js`, `*.css`). HTML
  references them automatically.

### 4.2 `postcss.config.js`

```js
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {}
  }
}
```

### 4.3 `tailwind.config.js`

This file **is the design system**. Reproduce it almost verbatim, changing colours and
names only.

```js
import flowbite from 'flowbite/plugin'
import typography from '@tailwindcss/typography'

export default {
  darkMode: 'class',
  content: [
    './site/*.html',
    './site/dashboard/**/*.html',
    './site/resources/**/*.html',
    './site/src/**/*.{html,js}',
    './site/public/**/*.js',
    './node_modules/flowbite/**/*.js'
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace']
      },
      colors: {
        brand: {
          50: '#ecfdf5', 100: '#d1fae5', 400: '#34d399', 500: '#10b981',
          600: '#059669', 700: '#047857', 900: '#064e3b'
        },
        ink: { 900: '#0b1220', 800: '#111827', 700: '#1f2937' }
      },
      keyframes: {
        indeterminate: {
          '0%':   { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(400%)' }
        }
      },
      animation: {
        indeterminate: 'indeterminate 1.5s linear infinite'
      }
    }
  },
  plugins: [flowbite, typography]
}
```

**Critical:** `./site/public/**/*.js` is in `content`. Dashboard JS builds DOM with
Tailwind class names; without that glob, classes used only inside JS are never
generated and render unstyled.

**Critical:** `darkMode: 'class'` means the `.dark` class on `<html>` drives every
`dark:` utility. A bootstrap script injected by `vite.config.js` sets it before
first paint from `localStorage.tscrub_theme`, falling back to the OS preference.
The dashboard header carries the toggle; the marketing pages follow the same saved
choice and still default to the OS.

### 4.4 `site/src/main.js`

```js
import 'flowbite'
import './input.css'
import './terminal.js'
import './auth.js'
import './dashboard.js'
```

Every public page ends with:

```html
<script type="module" src="/src/main.js"></script>
```

The five redirect stubs intentionally omit it.

### 4.5 `scripts/copy-well-known.mjs`

Vite skips dot-directories when copying `public/`, so `.well-known` is copied after
the build:

```js
import { cpSync, existsSync, mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const src = fileURLToPath(new URL('../site/public/.well-known', import.meta.url))
const dst = fileURLToPath(new URL('../dist/.well-known', import.meta.url))

if (existsSync(src)) {
  mkdirSync(dst, { recursive: true })
  cpSync(src, dst, { recursive: true, force: true })
  console.log('Copied /.well-known/ -> dist')
} else {
  console.log('No site/public/.well-known to copy')
}
```

### 4.6 Build commands

```bash
npm run dev      # Vite dev server, hot reload
npm run build    # → dist/
npm run preview  # serve the built output locally
```

Output shape:

```text
dist/
├─ index.html
├─ pricing.html
├─ resources/nist-800-88.html
├─ assets/index-<hash>.js
├─ assets/index-<hash>.css
├─ logo.png
├─ robots.txt
├─ sitemap.xml
├─ llms.txt
└─ .well-known/
```

---

## 5. Page inventory and routes

Clean, extensionless URLs are served by nginx (`/resources/nist-800-88`, not
`...html`). Both forms happen to resolve in production, but **always link the
extensionless form**.

| Route | File | Type |
|---|---|---|
| `/` | `site/index.html` | Landing page |
| `/compliance` | `compliance.html` | Product/standards detail |
| `/compare` | `compare.html` | Competitor comparison |
| `/pricing` | `pricing.html` | Four-tier pricing |
| `/faq` | `faq.html` | Grouped Q&A |
| `/download` | `download.html` | Download + OS tabs + checksum |
| `/docs` | `docs.html` | Long documentation with sticky TOC |
| `/contact` | `contact.html` | Contact form |
| `/privacy`, `/terms` | legal pages | Prose |
| `/login`, `/register` | auth pages | Forms, `noindex` |
| `/resources` | `resources.html` | Article index (card grid) |
| `/resources/<slug>` | `resources/<slug>.html` | ~30 SEO articles |
| `/dashboard/*` | `dashboard/*.html` | Authenticated app, `noindex` |
| `/admin` | `admin.html` | Admin app, `noindex` |

Dashboard shell pages (`dashboard.html`, `dashboard/index.html`, `licence.html`,
`mdm.html`) and `certify.html` are **redirect stubs**: a 0-second meta refresh plus
`location.replace()` and `noindex`.

---

## 6. Design system

### 6.1 Colour palette

| Token | Hex | Usage |
|---|---|---|
| `brand-50` | `#ecfdf5` | Tinted badge backgrounds, icon tiles, soft panels |
| `brand-100` | `#d1fae5` | Rare tint |
| `brand-400` | `#34d399` | Accent text on dark, terminal green |
| `brand-500` | `#10b981` | Accent borders on dark, status dots |
| `brand-600` | `#059669` | **Primary button**, links, active nav, logo mark |
| `brand-700` | `#047857` | Primary button hover, accent text on light |
| `brand-900` | `#064e3b` | Dark-mode tinted panel backgrounds (at 30% opacity) |
| `ink-900` | `#0b1220` | Dark page background, footer, terminal chrome |
| `ink-800` | `#111827` | Dark raised surface, terminal bar |
| `ink-700` | `#1f2937` | Dark borders and dividers |
| `white` | `#ffffff` | Light page background |
| `slate-50` | — | Alternating section background |
| `slate-200` | — | Light borders |
| `slate-600` | — | Body copy on light |
| `slate-800` | — | Default body text |
| `slate-400` | — | Body copy on dark |

Semantic badge colours are the standard Tailwind families, always paired light/dark:
`bg-red-100 dark:bg-red-900/40`, `text-emerald-700 dark:text-emerald-400`, etc.

### 6.2 Typography

- **Font:** Inter, weights 400, 500, 600, 700, 800.
- **Mono:** system monospace stack for code, terminal, serials and fingerprints.
- Loaded per page (not via CSS `@import`):

```html
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
```

Scale in practice:

| Role | Classes |
|---|---|
| Page H1 (marketing) | `text-4xl font-extrabold tracking-tight sm:text-5xl` |
| Page H1 (article/docs/dashboard) | `text-4xl` / `text-3xl` `font-extrabold tracking-tight` |
| Section H2 | `text-3xl font-extrabold tracking-tight` |
| Sub-section H2 | `text-2xl font-bold` |
| Card H2/H3 | `text-lg font-bold` |
| Body | default 16px, `text-slate-600 dark:text-slate-400` |
| Lead paragraph | `text-lg text-slate-600 dark:text-slate-400` |
| Small / meta | `text-sm` |
| Micro-label | `text-xs font-semibold` |
| Eyebrow | `text-xs font-semibold uppercase tracking-widest` |

Headings are always `text-ink-900 dark:text-white`.

### 6.3 Layout tokens

| Concern | Convention |
|---|---|
| Page container | `mx-auto max-w-7xl px-4` |
| Reading column | `mx-auto max-w-3xl px-4` |
| Dashboard column | `mx-auto w-full max-w-5xl px-4 py-10` |
| Section vertical rhythm | `py-16` or `py-20`; hero `py-16 md:py-24` |
| Grid gutter | `gap-6` (cards), `gap-12` (two-column feature layout) |
| Card radius | `rounded-2xl`; small tiles `rounded-xl`; controls `rounded-lg`; pills `rounded-full` |
| Card border | `border border-slate-200 dark:border-ink-700` |
| Divider | `divide-y divide-slate-100 dark:divide-ink-800` |
| Card shadow | `shadow-sm` (normal), `shadow-lg` (highlighted plan), `shadow-2xl` (modal/terminal) |
| Button padding | `px-4 py-2` (nav), `px-5 py-3` (hero), `px-4 py-2.5` (forms/cards) |
| Icon tile | `h-11 w-11 rounded-xl bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400` |

### 6.4 Dark mode

Dark mode is **class-based** (`darkMode: 'class'`). A bootstrap script injected in
`<head>` by `vite.config.js` adds `.dark` before the stylesheet loads, using the
saved choice (`localStorage.tscrub_theme`) or the OS preference. The dashboard app
bar exposes a persisted toggle; public pages follow the same saved choice.

Rules:

1. Every light surface gets a dark twin:
   `bg-white dark:bg-ink-900`, `bg-slate-50 dark:bg-ink-800`,
   `border-slate-200 dark:border-ink-700`.
2. Every light text colour gets a dark twin:
   `text-slate-600 dark:text-slate-400`, `text-ink-900 dark:text-white`.
3. Brand tints on dark become translucent:
   `bg-brand-50 dark:bg-brand-900/30`.
4. Hero gradients and grids are overridden with `.dark` selectors in `input.css`.
5. White cards (`rounded-xl` / `rounded-2xl`) are lifted to `ink-800` on a dark
   canvas by a rule in `input.css`, so they do not merge into the `ink-900` page.
6. The footer is permanently dark (`bg-ink-900`) in both themes.
7. Sections that are intentionally dark in both themes (`bg-ink-900`, `bg-ink-800`,
   white text, brand gradients) do not need `dark:` twins.

`add-dark-variants.py` is a **one-off migration helper**, not part of the build. It
scans `site/**/*.html` and appends dark twins for a fixed mapping of utilities. New
pages should be written with both variants from the start; do not rely on the script.

### 6.5 Custom CSS (`site/src/input.css`)

Beyond the three Tailwind directives, these are the only custom pieces:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

html { scroll-behavior: smooth; }

body {
  @apply flex min-h-screen flex-col bg-white text-slate-800 antialiased
         dark:bg-ink-900 dark:text-slate-200;
}

/* prevent wide tables/pre forcing page overflow */
body > * { @apply w-full min-w-0; }
footer { @apply mt-auto; }
code { @apply break-all; }

/* stable nav CTA width across auth states */
#nav-cta { min-width: 8rem; justify-content: center; }
/* reserve height for JS-injected dashboard nav */
#dashboard-nav { min-height: 46px; }

/* blueprint grid, light sections */
.bg-grid-slate {
  background-image:
    linear-gradient(to right, rgb(100 116 139 / 0.08) 1px, transparent 1px),
    linear-gradient(to bottom, rgb(100 116 139 / 0.08) 1px, transparent 1px);
  background-size: 36px 36px;
}

/* blueprint grid, dark sections */
.bg-grid-ink {
  background-image:
    linear-gradient(to right, rgb(255 255 255 / 0.045) 1px, transparent 1px),
    linear-gradient(to bottom, rgb(255 255 255 / 0.045) 1px, transparent 1px);
  background-size: 36px 36px;
}

/* hero wash */
.bg-grad-brand {
  background-image: linear-gradient(180deg, #ecfdf5 0%, #ffffff 70%);
}

/* card hover lift */
.lift { transition: transform .18s ease, box-shadow .18s ease; }
.lift:hover { transform: translateY(-3px);
              box-shadow: 0 16px 32px -18px rgb(11 18 32 / .28); }

.dark .bg-grid-slate {
  background-image:
    linear-gradient(to right, rgb(255 255 255 / 0.05) 1px, transparent 1px),
    linear-gradient(to bottom, rgb(255 255 255 / 0.05) 1px, transparent 1px);
}
.dark .bg-grad-brand {
  background-image: linear-gradient(180deg, #052e21 0%, #0b1220 70%);
}
.dark .lift:hover { box-shadow: 0 16px 32px -18px rgb(0 0 0 / .6); }

/* terminal palette */
.t-cmd { color: #34d399; } .t-info { color: #60a5fa; }
.t-ok  { color: #34d399; } .t-warn { color: #fbbf24; }
.t-err { color: #f87171; } .t-muted { color: #64748b; }
.t-lock { color: #fff; background: #b91c1c; border-radius: 2px; }

/* expanding table sub-row */
@keyframes row-expand {
  from { opacity: 0; max-height: 0; transform: translateY(-4px); }
  to   { opacity: 1; max-height: 100rem; transform: translateY(0); }
}
.row-expand { overflow: hidden; animation: row-expand 260ms cubic-bezier(.22,1,.36,1); }

@media (prefers-reduced-motion: reduce) {
  .row-expand { animation: none; }
  /* also respect reduced motion for the terminal animation in JS if you add one */
}
```

### 6.6 Iconography

- Inline SVG only. No icon font or sprite.
- Heroicons-style outline icons: `fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"`.
- Sizes: `h-5 w-5` inside `h-11 w-11` tiles; `h-4 w-4` inline in buttons.
- Icons inherit `currentColor`, so colour comes from the text class.
- Never reference an icon by name in a class; paste the path data.

---

## 7. Component recipes

Every snippet below is production-shaped. Replace copy and hrefs, keep the classes.

### 7.1 Page skeleton

```html
<!doctype html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="icon" type="image/png" href="/logo.png?v=2" />
  <title>Page title — Brand</title>
  <link rel="canonical" href="https://example.com/page" />
  <meta name="description" content="150–160 char description." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
</head>
<body class="bg-white dark:bg-ink-900 text-slate-800 dark:text-slate-200">
  <!-- nav -->
  <!-- content -->
  <!-- footer -->
  <script type="module" src="/src/main.js"></script>
</body>
</html>
```

### 7.2 Navbar

Sticky, translucent, blurred, hairline border. Mobile menu is toggled by Flowbite via
`data-collapse-toggle` / matching `id`.

```html
<nav class="sticky top-0 z-50 bg-white/90 dark:bg-ink-900/90 backdrop-blur
            border-b border-slate-200 dark:border-ink-700">
  <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between px-4 py-3">

    <a href="/" class="flex items-center gap-2">
      <img src="/logo.png?v=2" alt="Brand" class="h-8 w-8" />
      <span class="text-xl font-extrabold tracking-tight text-ink-900 dark:text-white">Brand</span>
    </a>

    <button data-collapse-toggle="navbar-cta" type="button"
            class="inline-flex items-center rounded-lg p-2 text-sm text-slate-500
                   dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-ink-800 md:hidden"
            aria-controls="navbar-cta" aria-expanded="false">
      <span class="sr-only">Open menu</span>
      <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 20 20"><!-- hamburger path --></svg>
    </button>

    <div id="navbar-cta" class="hidden w-full items-center justify-between md:order-1 md:flex md:w-auto">
      <ul class="mt-4 flex flex-col gap-1 font-medium md:mt-0 md:flex-row md:items-center md:gap-1">
        <li><a href="/docs" class="block rounded-lg px-3 py-2 text-sm text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-ink-800 hover:text-ink-900 dark:hover:text-white">Docs</a></li>
        <!-- repeat for Resources, Compliance, Pricing -->
      </ul>
      <a href="/dashboard" id="nav-cta"
         class="mt-4 inline-flex items-center rounded-lg border border-brand-600
                dark:border-brand-500 bg-white dark:bg-ink-900 px-4 py-2 text-sm
                font-semibold text-brand-700 dark:text-brand-400
                hover:bg-brand-50 dark:hover:bg-brand-900/30 md:mt-0 md:ml-3">Dashboard</a>
      <a href="/download"
         class="mt-4 inline-flex items-center rounded-lg bg-brand-600 px-4 py-2
                text-sm font-semibold text-white shadow-sm hover:bg-brand-700 md:mt-0 md:ml-3">Download</a>
    </div>
  </div>
</nav>
```

The current page's nav item is marked by replacing the muted classes with
`font-semibold text-ink-900 dark:text-white`. There is no automatic active state.

### 7.3 Buttons

```html
<!-- primary -->
<a class="inline-flex items-center rounded-lg bg-brand-600 px-5 py-3 text-sm
          font-semibold text-white shadow-sm hover:bg-brand-700">Get started</a>

<!-- secondary / outline -->
<a class="inline-flex items-center rounded-lg border border-slate-300 dark:border-slate-700
          bg-white dark:bg-ink-900 px-5 py-3 text-sm font-semibold
          text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-ink-800">Learn more</a>

<!-- brand-outline (nav dashboard) -->
<a class="inline-flex items-center rounded-lg border border-brand-600 dark:border-brand-500
          bg-white dark:bg-ink-900 px-4 py-2 text-sm font-semibold
          text-brand-700 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-900/30">Dashboard</a>

<!-- on a dark/brand band -->
<a class="inline-flex items-center rounded-lg bg-white px-5 py-3 text-sm font-semibold
          text-brand-700 hover:bg-brand-50">Download</a>

<!-- text link -->
<a class="text-sm font-semibold text-brand-600 dark:text-brand-400
          hover:text-brand-700 dark:hover:text-brand-400">Read more →</a>
```

### 7.4 Eyebrow pill / badge

```html
<span class="mb-4 inline-flex items-center gap-2 rounded-full border border-brand-200
             bg-brand-50 dark:bg-brand-900/30 px-3 py-1 text-xs font-semibold
             text-brand-700 dark:text-brand-400">
  <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
  Category label
</span>
```

Status badges use semantic colours:

```html
<span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs
             font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">Pass</span>
<span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs
             font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-400">Fail</span>
<span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs
             font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">Warning</span>
```

### 7.5 Hero with blueprint grid

```html
<section class="relative overflow-hidden border-b border-slate-200 dark:border-ink-700 bg-grad-brand">
  <div class="pointer-events-none absolute inset-0 bg-grid-slate"></div>
  <div class="relative mx-auto grid max-w-7xl gap-12 px-4 py-16 md:grid-cols-2 md:items-center md:py-24">
    <div>
      <span class="…eyebrow pill…">Compliance-grade …</span>
      <h1 class="text-4xl font-extrabold tracking-tight text-ink-900 dark:text-white sm:text-5xl">
        Headline that states the outcome.
      </h1>
      <p class="mt-4 max-w-xl text-lg text-slate-600 dark:text-slate-400">Supporting paragraph.</p>
      <div class="mt-8 flex flex-wrap gap-4">
        <!-- primary + secondary buttons -->
      </div>
      <ul class="mt-8 space-y-2 text-sm text-slate-600 dark:text-slate-400">
        <li class="flex items-start gap-2"><span class="mt-0.5 text-brand-600 dark:text-brand-400">✓</span> Proof point</li>
      </ul>
    </div>
    <!-- right-hand visual -->
  </div>
</section>
```

Centred hero variant (index pages, pricing, resources):

```html
<section class="relative overflow-hidden border-b border-slate-200 dark:border-ink-700 bg-grad-brand">
  <div class="pointer-events-none absolute inset-0 bg-grid-slate"></div>
  <div class="relative mx-auto max-w-3xl px-4 py-16 text-center">
    <span class="…eyebrow pill…">Resources</span>
    <h1 class="text-4xl font-extrabold tracking-tight text-ink-900 dark:text-white sm:text-5xl">Title</h1>
    <p class="mt-4 text-lg text-slate-600 dark:text-slate-400">Lead.</p>
  </div>
</section>
```

### 7.6 Feature list (sticky heading + divided rows)

```html
<section id="features" class="mx-auto max-w-7xl px-4 py-20">
  <div class="grid gap-12 lg:grid-cols-12">
    <div class="lg:col-span-4">
      <div class="lg:sticky lg:top-24">
        <span class="…eyebrow pill…">Capabilities</span>
        <h2 class="text-3xl font-extrabold tracking-tight text-ink-900 dark:text-white">Section headline</h2>
        <p class="mt-3 text-slate-600 dark:text-slate-400">Section lead.</p>
        <a href="/compliance" class="mt-5 inline-flex items-center text-sm font-semibold
                  text-brand-600 dark:text-brand-400 hover:text-brand-700">Learn more →</a>
      </div>
    </div>
    <div class="lg:col-span-8">
      <div class="divide-y divide-slate-100 dark:divide-ink-800">
        <div class="flex gap-5 py-6 first:pt-0 last:pb-0">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl
                      bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <!-- icon path -->
            </svg>
          </div>
          <div>
            <h3 class="font-bold text-ink-900 dark:text-white">Feature name</h3>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Feature description.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
```

### 7.7 Card grid (resource index / related links)

```html
<div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
  <a href="/resources/standards"
     class="lift group rounded-2xl border border-slate-200 dark:border-ink-700
            bg-white dark:bg-ink-900 p-6 hover:border-brand-300">
    <div class="flex h-11 w-11 items-center justify-center rounded-xl
                bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400">
      <!-- icon -->
    </div>
    <h2 class="mt-4 text-lg font-bold text-ink-900 dark:text-white
               group-hover:text-brand-700 dark:group-hover:text-brand-400">Card title</h2>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Card description.</p>
    <span class="mt-4 inline-flex items-center text-sm font-semibold
                 text-brand-600 dark:text-brand-400">Read the guide →</span>
  </a>
</div>
```

### 7.8 Pricing cards

```html
<div class="mx-auto mt-12 grid max-w-5xl gap-6 sm:grid-cols-2 lg:grid-cols-4">

  <div class="flex flex-col rounded-2xl border border-slate-200 dark:border-ink-700 p-6 shadow-sm">
    <h2 class="text-lg font-bold text-ink-900 dark:text-white">Tier</h2>
    <p class="mt-2 text-3xl font-extrabold text-ink-900 dark:text-white">£0</p>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">qualifier</p>
    <ul class="mt-6 flex-1 space-y-3 text-sm text-slate-600 dark:text-slate-400">
      <li>• Feature</li>
    </ul>
    <a href="/register" class="mt-8 block w-full rounded-lg bg-brand-600 px-4 py-2.5
              text-center text-sm font-semibold text-white hover:bg-brand-700">CTA</a>
  </div>

  <!-- highlighted tier -->
  <div class="relative flex flex-col rounded-2xl border-2 border-brand-600 dark:border-brand-500 p-6 shadow-lg">
    <span class="absolute -top-3 left-6 rounded-full bg-brand-600 px-3 py-0.5
                 text-xs font-semibold text-white">Most popular</span>
    <!-- same internals -->
  </div>
</div>
```

### 7.9 Dark CTA band

```html
<section class="relative overflow-hidden bg-gradient-to-br from-brand-600 to-brand-700 text-white">
  <div class="pointer-events-none absolute inset-0 bg-grid-ink"></div>
  <div class="relative mx-auto flex max-w-7xl flex-col items-center gap-6 px-4 py-16 text-center">
    <h2 class="text-3xl font-extrabold tracking-tight">Closing headline</h2>
    <p class="max-w-xl text-brand-50">One sentence of reassurance.</p>
    <a href="/download"
       class="inline-flex items-center rounded-lg bg-white px-5 py-3 text-sm font-semibold
              text-brand-700 hover:bg-brand-50">Primary action</a>
  </div>
</section>
```

### 7.10 Footer

Permanently dark, single row on desktop, stacked on mobile.

```html
<footer class="bg-ink-900 text-slate-400 dark:text-slate-500">
  <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4
              px-4 py-8 text-sm md:flex-row">
    <div class="flex items-center gap-2">
      <span class="inline-flex h-6 w-6 items-center justify-center rounded bg-brand-600
                   font-mono text-xs font-bold text-white">t</span>
      <span class="font-semibold text-white">Brand</span>
    </div>
    <ul class="flex flex-wrap justify-center gap-6">
      <li><a href="/pricing" class="hover:text-white">Pricing</a></li>
      <!-- … -->
    </ul>
    <p class="text-xs">© 2026 Brand. Made in England.</p>
  </div>
</footer>
```

### 7.11 Long-form article body (`prose`)

The article body is the only place the typography plugin is used.

```html
<div class="prose prose-slate dark:prose-invert prose-lg max-w-none mt-8
            prose-headings:text-ink-900 dark:prose-headings:text-white
            prose-a:text-brand-600 dark:prose-a:text-brand-400
            hover:prose-a:text-brand-700 dark:hover:prose-a:text-brand-400">
  <p>Opening answer — state the answer within the first 100 words.</p>
  <h2>Section</h2>
  <p>Body.</p>
  <h3>Sub-section</h3>
  <p>Body.</p>
  <div class="overflow-x-auto">
    <table>
      <thead><tr><th>Column</th><th>Column</th></tr></thead>
      <tbody><tr><td>Cell</td><td>Cell</td></tr></tbody>
    </table>
  </div>
  <h2>FAQ</h2>
  <h3>Question?</h3>
  <p>Answer.</p>
</div>
```

Rules:

- **One `<h1>` per page**, outside the `prose` block.
- Every table is wrapped in `<div class="overflow-x-auto">`.
- Do not add `text-*` colour classes to `<p>` inside `prose`; the prose theme handles it.
- Inline code uses `<code class="font-mono">…</code>` (the body rule makes it wrap).

### 7.12 Article header: breadcrumb + meta row

```html
<nav class="mx-auto max-w-3xl px-4 pt-8" aria-label="Breadcrumb">
  <ol class="flex flex-wrap items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
    <li><a href="/" class="hover:text-brand-600 dark:hover:text-brand-400">Home</a></li>
    <li aria-hidden="true">/</li>
    <li><a href="/resources" class="hover:text-brand-600 dark:hover:text-brand-400">Resources</a></li>
    <li aria-hidden="true">/</li>
    <li class="text-slate-700 dark:text-slate-300">Article</li>
  </ol>
</nav>

<article class="mx-auto max-w-3xl px-4 py-10">
  <h1 class="text-4xl font-extrabold tracking-tight text-ink-900 dark:text-white">Title</h1>
  <p class="mt-3 text-lg leading-relaxed text-slate-600 dark:text-slate-400">Lead.</p>

  <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
    <span class="inline-flex items-center gap-1.5">
      <span class="inline-flex h-5 w-5 items-center justify-center rounded-full
                   bg-brand-600 text-[10px] font-bold text-white">t</span>
      Brand Team
    </span>
    <span aria-hidden="true">·</span>
    <span>Last updated 22 Sep 2026</span>
    <span aria-hidden="true">·</span>
    <span>7 min read</span>
  </div>

  <div class="prose …">…</div>

  <!-- related links + CTA -->
</article>
```

### 7.13 Documentation sidebar TOC + content

```html
<div class="mt-12 grid grid-cols-1 gap-12 lg:grid-cols-[260px_1fr]">
  <aside class="hidden lg:block">
    <nav class="sticky top-24 space-y-7 text-sm" aria-label="Table of contents">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">Get started</p>
        <ul class="mt-3 space-y-2 border-l border-slate-200 dark:border-ink-700 pl-4">
          <li><a href="#quickstart" class="block text-slate-600 dark:text-slate-400
                        hover:text-ink-900 dark:hover:text-white">1. Quick start</a></li>
        </ul>
      </div>
    </nav>
  </aside>

  <div class="min-w-0 space-y-12">
    <section id="quickstart" class="scroll-mt-24">
      <h2 class="text-2xl font-bold text-ink-900 dark:text-white">Quick start</h2>
      <!-- content -->
    </section>
  </div>
</div>
```

`scroll-mt-24` offsets the sticky navbar when an in-page anchor is followed.

### 7.14 FAQ / grouped Q&A

There is no accordion on the public FAQ; it is a scannable divided list grouped by
category.

```html
<section class="mx-auto max-w-3xl px-4 py-16">
  <div class="text-center">
    <h1 class="text-4xl font-extrabold tracking-tight text-ink-900 dark:text-white">Questions</h1>
    <p class="mt-3 text-slate-600 dark:text-slate-400">Lead.</p>
  </div>

  <div class="mt-12 space-y-12">
    <div>
      <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-600 dark:text-brand-400">Category</h2>
      <div class="mt-2 divide-y divide-slate-100 dark:divide-ink-800">
        <div class="py-5">
          <h3 class="font-bold text-ink-900 dark:text-white">Question?</h3>
          <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Answer.</p>
        </div>
      </div>
    </div>
  </div>
</section>
```

### 7.15 Code block

```html
<pre class="mt-3 overflow-x-auto rounded-lg bg-ink-900 p-4 font-mono text-xs text-slate-200">command --flag</pre>
```

Always HTML-escape `<`, `>`, `&` inside `<pre>`. Long unbroken tokens wrap because of
the `code { break-all }` base rule; `<pre>` itself scrolls horizontally.

### 7.16 Tabs (OS selector on the download page)

Hand-written, not Flowbite. Buttons carry `data-ostab` and `data-ostab-target`;
panels use a stacked-grid technique so the tallest panel defines the height.

```html
<div class="mt-5" data-ostabs>
  <div class="border-b border-slate-200 dark:border-ink-700">
    <ul class="-mb-px flex flex-wrap gap-1 text-sm font-medium" role="tablist">
      <li role="presentation">
        <button type="button" data-ostab data-ostab-target="#usb-win"
                class="inline-block border-b-2 border-brand-600 dark:border-brand-500 px-4 py-2
                       font-semibold text-brand-700 dark:text-brand-400"
                role="tab" aria-selected="true" aria-controls="usb-win">Windows</button>
      </li>
      <li role="presentation">
        <button type="button" data-ostab data-ostab-target="#usb-mac"
                class="inline-block border-b-2 border-transparent px-4 py-2
                       text-slate-500 dark:text-slate-400"
                role="tab" aria-selected="false" aria-controls="usb-mac">macOS</button>
      </li>
    </ul>
  </div>
  <div class="grid pt-4">
    <div id="usb-win" data-ostab-panel role="tabpanel" class="col-start-1 row-start-1 visible">…</div>
    <div id="usb-mac" data-ostab-panel role="tabpanel" class="col-start-1 row-start-1 invisible">…</div>
  </div>
</div>
```

### 7.17 Modal shell (dashboard)

```html
<div id="modal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
  <div class="absolute inset-0 flex items-center justify-center p-3 sm:p-6">
    <div class="flex max-h-full w-full max-w-3xl flex-col overflow-hidden rounded-2xl
                bg-white dark:bg-ink-900 shadow-2xl ring-1 ring-slate-900/10">
      <div class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200
                  dark:border-ink-700 px-5 py-4">
        <h2 id="modal-title" class="truncate text-lg font-bold text-ink-900 dark:text-white"></h2>
        <button type="button" class="rounded-lg border border-slate-300 dark:border-slate-700
                px-3 py-2 text-sm font-semibold text-slate-700 dark:text-slate-300
                hover:bg-slate-50 dark:hover:bg-ink-800">Close</button>
      </div>
      <div class="flex-1 overflow-y-auto bg-slate-50 dark:bg-ink-800 p-5"></div>
      <div class="flex shrink-0 items-center justify-end gap-2 border-t border-slate-200
                  dark:border-ink-700 px-5 py-3"></div>
    </div>
  </div>
</div>
```

### 7.18 Loading skeleton

```html
<div class="flex animate-pulse flex-col gap-2 p-6">
  <div class="h-4 w-64 rounded bg-slate-200 dark:bg-ink-700"></div>
  <div class="h-3 w-96 rounded bg-slate-200 dark:bg-ink-700"></div>
</div>
```

### 7.19 Data table container

```html
<div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 dark:border-ink-700
            bg-white dark:bg-ink-900 shadow-sm">
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 dark:bg-ink-800 text-left text-xs uppercase tracking-wider
                    text-slate-500 dark:text-slate-400">
        <tr><th class="px-4 py-3 font-semibold">Column</th></tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-ink-800 text-slate-700 dark:text-slate-300">
        <tr><td class="px-4 py-3">Cell</td></tr>
      </tbody>
    </table>
  </div>
</div>
```

---

## 8. Page templates

### 8.1 Marketing landing page (`index.html`)

Section order (this rhythm is the visual signature):

1. **Navbar** — sticky, translucent.
2. **Hero** — two columns: value proposition + proof bullets on the left, dark
   simulated terminal on the right. Blueprint grid overlay, soft brand wash.
3. **Trust strip** — one-line list of standards/certifications, centred, muted.
4. **Capabilities** — sticky left heading (`lg:col-span-4`) + divided feature rows
   (`lg:col-span-8`), each with a brand icon tile.
5. **Product/evidence section** — usually a dark (`bg-ink-900`) panel with a mock
   report, terminal or table, plus a light "how it works" 3-step block.
6. **Audience cards** — 2–3 bordered cards naming who it is for.
7. **Compare teaser** — centred band on `bg-slate-50 dark:bg-ink-800` with one button.
8. **FAQ teaser** — centred heading + "Read the FAQ →".
9. **Dark brand CTA band** — gradient + grid, one or two buttons.
10. **Footer**.

Every section is full width; the inner container is `mx-auto max-w-7xl px-4`.

### 8.2 Resource article (`site/resources/_template.html`)

The template file is the canonical recipe. To add an article:

1. Copy `site/resources/_template.html` → `site/resources/<slug>.html`.
2. Replace `TITLE`, `META_DESC`, `CANONICAL_PATH`, `BREADCRUMB`, `H1`, `LEAD`,
   author/date/reading-time, and the body.
3. Add the JSON-LD `Article` block in `<head>` (the template and existing articles
   include one).
4. Register the page in `vite.config.js` (`pages` map).
5. Add the URL to `site/public/sitemap.xml`.
6. Add the page to `site/public/llms.txt` and `site/public/llms-full.txt`.
7. Link it from `site/resources.html` (card grid) and from 2–3 related articles.

Article structure:

- Breadcrumb → H1 → lead → author/date/read-time → `prose` body → "Keep reading"
  (3 related cards) → dark CTA band → footer.
- Body: opening answer in the first 100 words; `h2` sections; `h3` sub-sections;
  at least one comparison table wrapped in `overflow-x-auto`; an FAQ section at the
  end; internal links to related resources.

### 8.3 Documentation page (`docs.html`)

- Centred H1 + lead.
- Two-column grid: `lg:grid-cols-[260px_1fr]`.
- Left column: `hidden lg:block` sticky TOC (`sticky top-24`) grouped under
  uppercase micro-labels, each group a `border-l` list.
- Right column: numbered `<section id="…">` blocks with `scroll-mt-24`.
- Tables, `pre` blocks and callout panels follow the component recipes.

### 8.4 Pricing page (`pricing.html`)

- Centred H1 + lead.
- Four-tier grid (`sm:grid-cols-2 lg:grid-cols-4`), each card `flex flex-col`, CTA at
  the bottom (`mt-8`), feature list `flex-1`.
- One card is highlighted with a 2px brand border, `shadow-lg` and a "Most popular"
  pill positioned `absolute -top-3 left-6`.
- Footnote paragraph in `text-xs text-slate-400`.
- "Pricing questions" block: stacked bordered cards, not an accordion.

### 8.5 Auth pages (`login.html`, `register.html`)

- Same navbar and footer.
- Centred narrow card (`max-w-md`) on the page background, bordered `rounded-2xl`,
  `shadow-sm`.
- Inputs: `rounded-lg border border-slate-300 dark:border-slate-700 bg-white
  dark:bg-ink-900 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500`.
- `noindex` meta on every auth page.
- No hero, no CTA band.

### 8.6 Dashboard shell (`dashboard/*.html`)

- Same navbar.
- Content wrapper: `mx-auto w-full max-w-5xl px-4 py-10`.
- `<h1>Dashboard</h1>` followed by `<div id="dashboard-nav" class="mt-4"></div>`
  (the sub-nav is injected by `dashboard.js`).
- Page title `<h2 class="text-2xl font-extrabold tracking-tight">` + muted one-line
  description.
- Toolbar row: search input (`flex-1 min-w-0`), secondary buttons, primary "Add".
- Content card: `rounded-2xl border … bg-white dark:bg-ink-900 shadow-sm` with a
  skeleton until data loads.
- Modals live at the end of `<body>` as fixed overlays (recipe 7.17).
- `noindex` meta on every dashboard page.

### 8.7 Redirect stub

```html
<!doctype html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Redirecting — Brand</title>
  <meta http-equiv="refresh" content="0; url=/dashboard/devices" />
  <meta name="robots" content="noindex" />
</head>
<body>
  <script>location.replace('/dashboard/devices');</script>
</body>
</html>
```

---

## 9. Shared JavaScript

All behaviour is vanilla ES modules. There is no framework and no state manager.

### 9.1 `src/terminal.js` — home page terminal animation

- Guarded: returns immediately unless `#term`, `#term-output` and `#term-input` exist.
- Types out a simulated session, paints a live table with ETA countdowns, and switches
  the widget theme (`boot` dark, `run` blue, `done` green).
- Cleans up timers on re-run. Safe on every page because of the guard.
- Optional: delete it for a project that does not want a terminal hero, and remove the
  `#term-box` markup.

### 9.2 `src/auth.js` — nav consistency

- Fetches `/api/me` (ignore failure).
- Always sets `#nav-cta` text to "Dashboard" and href to `/dashboard`, so the button
  width and label are stable whether or not the user is signed in.
- Provides a `signOut()` helper that GETs `/api/csrf`, POSTs `/api/logout` with the
  token, then redirects home.

For a non-authenticated marketing site, this module can be dropped.

### 9.3 `src/dashboard.js` — dashboard chrome

- Fills the app-bar tab bar (`#app-tabs`) and the mobile menu from one link list,
  highlighting the current path.
- Paints the organisation name + account email in `#app-user`, using a
  `sessionStorage` cache for instant paint, then refreshes from `/api/me`.
- Owns the persisted dark-mode toggle in the app bar.
- Reveals the hidden Administrator card on the Account page when the account role
  is `admin`. Admin navigation is deliberately kept out of the app bar.
- Shows an expiry banner when the tenant subscription is ending (tScrub-specific;
  remove for another project).

### 9.4 `public/assets/manual-assets.js`

- Hand-written dashboard module, loaded directly with
  `<script src="/assets/manual-assets.js?v=17" defer>` (not bundled).
- Because it is hand-written and lives in `public/`, it needs a **manual cache-bust
  query** (`?v=N`) bumped on every change.
- Its Tailwind classes are only generated because `./site/public/**/*.js` is in the
  Tailwind `content` globs.

### 9.5 Conventions for new JS

- Vanilla, IIFE or module, no dependencies beyond Flowbite.
- Guard every DOM lookup; the same bundle runs on every page.
- Build DOM with `document.createElement` and `textContent` (never interpolate
  untrusted strings into `innerHTML`).
- Put page-specific code behind an element-existence check, not a URL check.
- If a class is used only in JS, confirm it appears in the Tailwind `content` globs.

---

## 10. SEO and AI-search files

The site treats search and LLM discoverability as first-class deliverables.

### 10.1 Per-page `<head>`

Every public page has:

```html
<title>Primary keyword — Brand</title>
<link rel="canonical" href="https://example.com/exact-path" />
<meta name="description" content="150–160 characters, benefit-led." />
```

Auth, dashboard and admin pages add:

```html
<meta name="robots" content="noindex" />
```

### 10.2 Structured data

Long-form articles and key pages include a JSON-LD block in `<head>`:

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "…",
  "description": "…",
  "datePublished": "2026-09-22",
  "dateModified": "2026-09-22",
  "author": { "@type": "Organization", "name": "Brand" },
  "publisher": { "@type": "Organization", "name": "Brand", "url": "https://example.com" },
  "mainEntityOfPage": "https://example.com/resources/slug"
}
</script>
```

### 10.3 `robots.txt`

```text
User-agent: *
Allow: /
Disallow: /login
Disallow: /register
Disallow: /dashboard
Disallow: /admin

Sitemap: https://example.com/sitemap.xml
```

### 10.4 `sitemap.xml`

- Standard `urlset`.
- Every public URL, `lastmod` in `YYYY-MM-DD`.
- Priority convention: home `1.0`; primary product pages `0.8`; comparison/contact
  `0.7`; articles `0.6`; legal `0.3`.
- Update it whenever a page is added or its content materially changes.

### 10.5 `llms.txt` and `llms-full.txt`

`llms.txt` is a compact, curated map for AI crawlers:

```markdown
# Brand

> One-paragraph description of what the product is.

## Pages

- [Home](https://example.com/): What the page covers.
- [Pricing](https://example.com/pricing): Tiers, prices, what counts as a unit.

## Resources

- [Guide title](https://example.com/resources/slug): One-line summary.

## Legal

- [Privacy Policy](https://example.com/privacy)

## Key facts

- Bullet list of concrete, verifiable product facts.
```

`llms-full.txt` is the expanded version with the same URLs and more detail.

**Rule:** whenever a page is added, retitled, or its services/features change, update
both files in the same pass.

---

## 11. Accessibility and responsive rules

These patterns are used consistently; preserve them when replicating.

- **Landmarks:** `<nav>`, `<section>`, `<article>`, `<aside>`, `<footer>`; each nav
  that is not the main navbar has `aria-label`.
- **Breadcrumb:** `<nav aria-label="Breadcrumb">` + `<ol>`; separators are
  `<li aria-hidden="true">/</li>`.
- **Mobile menu:** button has `aria-controls`, `aria-expanded`, and an
  `.sr-only` label.
- **Icons that convey meaning:** add `aria-hidden="true"` when decorative; give the
  link/button a text label otherwise.
- **Focus:** do not remove outlines. Tailwind's default focus ring is used on inputs
  (`focus:border-brand-500 focus:ring-brand-500`).
- **Colour contrast:** body copy is `slate-600` on white and `slate-400` on
  `ink-900`; do not use `slate-400` on white for body text.
- **Contrast overrides live in `src/input.css`,** not in the class lists — they
  have to, because the utilities are emitted after the unlayered rules:
  - `html .bg-brand-600` → `brand-700`, hover → `brand-800`. White on
    `brand-600` is 3.77:1, so every brand fill that carries a label (buttons,
    numbered step markers, the "Most popular" pill) is one step darker.
  - `html:not(.dark) .text-brand-600` and `html:not(.dark) .prose a` →
    `brand-700`, for the same reason in text form (docs links, article prose).
  - `html.dark .text-slate-500` / `html.dark .dark\:text-slate-500` → `#94a3b8`
    (~6.9:1 on `ink-800`). The `html.dark` prefix is required: the dark variant
    compiles to `.dark\:text-slate-500:is(.dark *)`, which is (0,2,0) and ties
    with a plain `.dark …` selector, so order would decide it.
  - `html:not(.dark) .ts-dash .text-slate-400` and `.text-slate-300` →
    `#64748b`. `.ts-dash` is set on the `<body>` of every dashboard page and
    `admin.html`. It is scoped deliberately: the marketing pages keep
    permanently dark surfaces (footer, terminal demo, dark table heads) where
    `slate-400` is the correct value on `ink-900`.
- **Measure, don't eyeball.** `getComputedStyle` on the rendered page, with the
  theme forced via `localStorage.tscrub_theme`, is the only reliable check: a
  rule can lose on specificity or source order while reading correctly in the
  source file.
- **Dialogs: use `window.TSDialog.open(el[, trigger])` / `close(el)`.** Never
  toggle `hidden` + `body.style.overflow` by hand. The helper
  (`site/src/dialog.js`, inlined into `<head>` on every page by the
  `tscrub-a11y-shell` Vite plugin, so it exists before any inline page script)
  owns the keyboard contract: focus the first control on open, keep Tab /
  Shift-Tab inside the topmost panel, close nested dialogs in order, and return
  focus to the element that opened it. `aria-modal="true"` without that is a
  promise the page does not keep — the first pass on this site declared it on
  eleven dialogs and trapped none of them. `site/public/assets/manual-assets.js`
  keeps a no-helper fallback so its DOM-only smoke test still runs.
- **Skip link:** injected as the first element in `<body>` by the same plugin,
  but only on pages that contain `id="main-content"`, so it can never link
  nowhere. Every page now has that target: the dashboard shell (plus
  `admin.html`) uses `role="main"` on its existing content `<section>`, and the
  marketing pages wrap their content in a real `<main id="main-content"
  tabindex="-1">` that opens after the navbar's `</nav>` and closes before the
  footer. On the resources pages the breadcrumb `<nav>` sits INSIDE main — it is
  page content, not chrome. `tabindex="-1"` matters: without it the fragment
  moves the scroll position but not focus. The redirect stubs deliberately get
  neither.
- **The skip-link CSS is deliberately NOT in `@layer components`:** Tailwind
  only emits a component class if the name appears in the content globs, and
  `ts-skip-link` exists only in `vite.config.js`. The first build shipped no rule
  at all, so the focused link would have rendered as ordinary body text.
- **Shared dashboard primitives live in `@layer components` in `src/input.css`:**
  - `.ts-th` — a sticky header cell. It owns the sticky contract (position,
    z-index, an OPAQUE background) plus the column-label typography. Pages add
    only width, padding and `truncate` via utilities.
  - `.ts-chip` + `.ts-chip--emerald|amber|red|sky|slate` — the status palette.
    Pages map a status to a tone and must not re-inline the colours: the same
    status has to look the same on Devices and Media, and a contrast fix has to
    land once. `--slate` is `slate-600` because it sits on `slate-100`, where
    `slate-500` is 4.34:1.
  These are component classes rather than a shared JS module on purpose: each
  dashboard renderer is an inline script, and a module would make the helpers
  load-order dependent on three pages to save a few lines.
- **Motion:** animation is subtle and short (150–260 ms). The one custom keyframe
  (`row-expand`) is disabled under `prefers-reduced-motion`.
- **Responsive breakpoints:** mobile-first. The common jumps are `sm:` (640px) for
  headline size, `md:` (768px) for two-column layouts and the nav, `lg:` (1024px) for
  three/four-column grids and sticky sidebars.
- **Overflow:** wide content (tables, code, terminal) is wrapped in `overflow-x-auto`
  or `overflow-hidden`; the `body > * { min-w-0 }` rule prevents page-level
  horizontal scroll.
- **Stacked tab panels need `[&>*]:min-w-0`.** The OS-tab groups on `docs` and
  `download` keep every panel in one grid cell (`col-start-1 row-start-1`) so the
  group keeps the height of the tallest one. A grid item's automatic minimum size
  is its content, so a wide `<pre>` in a HIDDEN panel sized the column to 362px in
  a 328px container and pushed the whole page 18–214px wide on a phone — the
  visible panel inherited the width even though it contains nothing wide. The
  fix is on the container, not the panel: `[&>*]:min-w-0` lets the items shrink
  and each `pre`/table then scrolls inside its own `overflow-x-auto`.
  Same class of bug on the home page's report/method two-up grid.
- **Tap targets:** nav links and buttons use at least `py-2`; primary buttons `py-3`
  in heroes.

---

## 12. Gotchas that will bite you

These are real defects that were hit in this codebase. Keep the fixes.

1. **Tailwind only generates classes it can see.** Any class used only inside a JS
   string (dynamically built DOM) is absent from the CSS unless that JS file is in the
   `content` globs. Hence `'./site/public/**/*.js'` and `'./site/src/**/*.{html,js}'`.
   Symptom: an element renders unstyled (e.g. `h-7 w-7`, `gap-1.5` missing).
2. **Every page must be registered in `vite.config.js`.** Vite does not discover HTML
   automatically. An unregistered page silently never reaches `dist/`.
3. **`_template.html` must stay unregistered.** Otherwise the placeholder page ships.
4. **Use absolute links (`/pricing`, `/resources/x`).** Root-level pages can get away
   with relative links (`pricing`) because they are at `/`, but inside
   `/resources/…` a relative `pricing` resolves to `/resources/pricing`. Prefer
   absolute everywhere.
5. **Dark mode is class-based.** A bootstrap script sets `.dark` before first paint;
   the dashboard toggle persists the choice. When adding a component, write the
   `dark:` twin at the same time. `add-dark-variants.py` is a migration tool, not a
   build step.
6. **Vite skips dot-directories in `public/`.** `.well-known` needs the explicit copy
   script.
7. **Static assets in `public/` are not hashed.** If the filename stays the same,
   browsers and CDNs may serve stale bytes. Bump a query string (`?v=N`) in the HTML
   when changing `logo.png` or any hand-written `public/assets/*.js`.
8. **HTML without cache headers is heuristically cached by browsers.** A redeploy can
   appear not to have happened because an old `<script src="…?v=14">` still runs. Verify
   with a throwaway query string before debugging the code.
9. **Do not edit files with two parallel replacements.** Multiple edits to the same
   file must be sequential or use a single multi-edit call, or text can be spliced into
   the wrong place.
10. **`prose` is for article bodies only.** Do not wrap landing-page sections in
    `prose`; it changes heading and list styling unpredictably.
11. **Tables must be wrapped in `overflow-x-auto`.** Otherwise they force page-level
    horizontal scrolling on mobile.
12. **Do not use `text-ink-*` headings on dark sections without a dark twin.** On
    `bg-ink-900` the heading is invisible unless it is `text-white`.
13. **A stable nav label needs a fixed width.** `#nav-cta` is `min-width: 8rem` so the
    nav does not shift between auth states.
14. **JS-injected content needs reserved space.** `#dashboard-nav` has
    `min-height: 46px` so the page does not jump when the sub-nav appears.
15. **Fonts are loaded per page.** If you add a page and forget the Google Fonts
    `<link>`, it falls back to the system sans stack and looks subtly wrong.

---

## 13. Step-by-step replication checklist

### Phase 1 — scaffold

1. Create `marketing/` with `site/`, `site/src/`, `site/public/`, `scripts/`.
2. Add `package.json` with the scripts above and install Vite, Tailwind, PostCSS,
   Autoprefixer, Flowbite, `@tailwindcss/typography`.
3. Copy `postcss.config.js` verbatim.
4. Adapt `tailwind.config.js`: keep the structure, replace `brand` and `ink` values if
   the new brand has its own palette, keep the content globs.
5. Create `site/src/input.css` with the directives and custom utilities from §6.5.
6. Create `site/src/main.js` with `import 'flowbite'` and `import './input.css'`.

### Phase 2 — shell

7. Create `site/index.html` using the skeleton in §7.1.
8. Paste the navbar (§7.2) and footer (§7.10). These are duplicated on every page;
   keep the markup byte-identical except for the active nav item.
9. Add the hero, trust strip, feature list, CTA band and footer using §7.
10. Run `npm run dev` and confirm:
    - mobile menu opens/closes;
    - dark mode starts from the OS, and the dashboard toggle persists a choice;
    - no horizontal scroll at 375px;
    - the nav does not jump.

### Phase 3 — page set

11. Duplicate the shell for each public page:
    `compliance`, `compare`, `pricing`, `faq`, `download`, `docs`, `contact`,
    `privacy`, `terms`, `login`, `register`, `resources`.
12. Build `resources/_template.html` from §8.2 and leave it unregistered.
13. Add `/resources/<slug>` articles by copying the template.
14. Add `vite.config.js` entries for every page.

### Phase 4 — SEO

15. Add `<title>`, canonical, description and `noindex` where appropriate to every page.
16. Add JSON-LD to articles and key commercial pages.
17. Write `robots.txt`, `sitemap.xml`, `llms.txt`, `llms-full.txt`.
18. Put them in `site/public/`.

### Phase 5 — verify

19. `npm run build`.
20. Confirm every page exists in `dist/` at the expected path and `_template.html`
    does not.
21. Serve `dist/` and check:
    - every nav/footer link resolves (no 404);
    - every page has exactly one `<h1>`;
    - all images have `alt`;
    - dark mode on every page;
    - 320px, 768px, 1280px widths;
    - keyboard tab order and visible focus.
22. Run an accessibility audit (axe/Lighthouse) and a link checker.
23. Deploy and re-check the canonical, sitemap and robots URLs over HTTPS.

---

## 14. Deployment

The reference deployment is deliberately simple:

```bash
npm run build
rsync -avz --delete --exclude downloads/ dist/ user@host:~/webs/site/
```

`deploy.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

HOST="${WEB_HOST:-user@host}"
DEST="${WEB_DEST:-~/webs/site}"
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)

cd "$(dirname "$0")"

npm run build

ssh "${SSH_OPTS[@]}" "$HOST" "mkdir -p $DEST"
rsync -avz --delete --exclude downloads/ \
  -e "ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
  dist/ "$HOST:$DEST/"

echo "Deploy complete."
```

Server requirements:

- Document root points at the synced `dist/`.
- Extensionless URLs resolve to `.html`. With nginx, inside the site `location /`:

```nginx
location / {
    try_files $uri $uri.html $uri/ =404;
}
```

- `index.html` is served for `/`.
- HTTPS redirect and any retired-page 301s live in separate nginx snippets.
- Long-lived caching may be applied to `/assets/*` (Vite hashes those filenames), but
  **not** to HTML. If an asset in `public/` is cached aggressively, bump its `?v=N`.

Post-deploy smoke checks:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/robots.txt
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/sitemap.xml
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/resources/nist-800-88
```

---

## 15. Replace for your project

Everything in this list is tScrub-specific. Change it before reusing the design.

| Item | Where |
|---|---|
| Product name, logo, wordmark | Every page nav/footer, `<title>`, `logo.png`, `logo.svg` |
| Canonical domain | Every `<link rel="canonical">`, sitemap, robots, llms files, JSON-LD |
| Copy and product claims | All pages |
| Navigation labels and order | Navbar (duplicated per page) |
| Footer links | Footer (duplicated per page) |
| Accent colour | `brand` scale in `tailwind.config.js`; also `.bg-grad-brand` and terminal colours in `input.css` |
| Dark surface colours | `ink` scale in `tailwind.config.js` |
| Resource article set | `site/resources/*.html`, `resources.html`, sitemap, llms files |
| API endpoints | `auth.js`, `dashboard.js`, auth/dashboard pages (`/api/*`) |
| Backend and deployment | `server/`, `deploy-server.sh`, nginx snippets |
| Terminal demo data | `site/src/terminal.js` (`DRIVES`, `COCID`, typed commands) |
| Dashboard modules | `dashboard.js`, `public/assets/manual-assets.js` |

### What to keep

- The token structure (`brand`, `ink`, slate body text).
- The layout rhythm: `max-w-7xl` containers, `mx-auto max-w-3xl` reading column,
  `py-16`/`py-20` sections.
- The navbar/footer patterns.
- The component class strings in §7.
- The `prose` article wrapper.
- The dark-mode pairing rule.
- The Tailwind content globs.
- The page-registration discipline in `vite.config.js`.
- The SEO/llms file discipline.

If the target project adopts a framework (Astro, Eleventy, Next.js), keep this visual
system and component markup, but **move the duplicated nav/footer into a layout** and
**generate the sitemap/llms files from the page list** so they cannot drift. Do not
simultaneously change the design tokens; replicate first, refactor second.
