# tScrub — Marketing site & backend

The tScrub website: a static **Vite + Tailwind CSS + Flowbite** marketing site,
plus the PHP/MySQL backend that powers accounts, licences, certificates, SMART
data reports, and admin.

## Stack

- Vite (build) + Tailwind CSS v3 (via PostCSS) + Flowbite (components/JS)
- PHP 8 (front controller `server/api.php`, PDO/MySQL) — see `server/`
- Python helpers: `sendmail.py` (SMTP), `issue_licence.py` (licence issuance)

## Layout

- `site/` — the Vite root: every page (`*.html`, `dashboard/`, `resources/`),
  the front-end JS/CSS (`site/src/`), and the static SEO files (`site/public/`).
- `server/` — the PHP/MySQL backend (deployed separately, never bundled).
- `vite.config.js` / `tailwind.config.js` / `postcss.config.js` / `package.json` — build config.
- `deploy.sh` / `deploy-server.sh` — rsync deployers.

## Develop

```sh
npm install
npm run dev
```

## Build

```sh
npm run build
```

Static output goes to `dist/`. The PHP backend is not bundled — it deploys
separately (below).

## Deploy

```sh
npm run deploy          # build + rsync dist/ to the web docroot
npm run deploy:server   # rsync server/*.php + *.py to the backend dir
```

Both use `marketing/deploy.sh` / `deploy-server.sh` (target host defaults live
there; see `ops/deploy.md` for the full picture, including the one-time MySQL
schema setup, migrations, admin seeding, and nginx `/api/` routing).

## Notes

- The dashboard (`/dashboard`, `/admin`, `/login`, `/register`) is `noindex`.
- Auth pages and the static site call the backend directly over `/api/*`.
- `site/public/llms.txt`, `site/public/llms-full.txt`, `robots.txt`, and `sitemap.xml`
  ship with the build — keep the LLM context files in sync with page changes.
- Compliance copy uses "aligns with" language intentionally — tScrub is not
  itself a certification.
