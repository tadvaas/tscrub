# tScrub — Marketing site

Static marketing site for tScrub, built with **Vite + Tailwind CSS + Flowbite**.

## Stack

- Vite (build/dev server)
- Tailwind CSS v3 (via PostCSS)
- Flowbite (components + JS)

## Develop

```sh
npm install
npm run dev
```

## Build

```sh
npm run build
```

Output goes to `dist/`. This is a fully static site — upload the `dist/`
folder to any static web server (or the private network server once it's set
up).

## Deploy

The site is a static bundle. To publish it to a private server later, upload
the contents of `dist/` to the web root, e.g.:

```sh
npm run build
rsync -avz --delete dist/ user@server:/var/www/tscrub/
```

## Notes

- The download form is a placeholder (`action="#"`); wire it to Formspree,
  Mailchimp, or your own backend when registration goes live.
- Compliance copy uses "aligns with" language intentionally — tScrub is not
  itself a certification.
