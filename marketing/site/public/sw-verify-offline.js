// Service worker for the offline certificate verifier.
//
// Why this exists: getUserMedia (the camera) needs a secure context, so a copy
// of the page saved to disk as file:// cannot scan. A cached page served from
// tscrub.com can, and keeps working with no network — that is the whole point of
// the offline code.
//
// Strategy:
//   - precache the page at install, so the very first visit is enough;
//   - the page itself is STALE-WHILE-REVALIDATE: served instantly from cache,
//     then refreshed in the background. Cache-first-only looked tidy but strands an
//     installed verifier on an old page whose hashed assets a later deploy has
//     deleted (found the hard way: the rebuilt page 404'd its own bundle);
//   - cache-first for the built assets it references (/assets/…), which are
//     content-hashed, so a stale entry can never serve the wrong file;
//   - network-first with a cache fallback for everything else.
//
// Bump CACHE whenever this file changes.

const CACHE = 'tscrub-verify-offline-v3';
const PAGE = '/verify-offline.html';

// The page's bundle names are content-hashed and unknown here, so the install
// step reads the page and caches whatever it references. Without this the FIRST
// visit caches only the HTML (fetch interception starts after activation), and
// the next visit offline shows a page whose script never loads — which is the
// whole feature failing silently. Found exactly that way: the page rendered, the
// fingerprint stayed "…" and no code could be checked.
async function precachePage(cache) {
  const res = await fetch(PAGE, { cache: 'reload' });
  if (!res || !res.ok) return;
  await cache.put(PAGE, res.clone());
  const html = await res.text();
  const urls = new Set();
  for (const m of html.matchAll(/(?:src|href)="(\/assets\/[^"]+|\/logo\.png[^"]*)"/g)) {
    urls.add(m[1]);
  }
  await Promise.all(Array.from(urls).map((u) =>
    fetch(u, { cache: 'reload' }).then((r) => (r && r.ok ? cache.put(u, r) : null)).catch(() => null)
  ));
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((c) => precachePage(c))
      .catch(() => {})
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // The page and its hashed assets.
  const isPage = url.pathname === PAGE || url.pathname === '/verify-offline';
  const isAsset = url.pathname.startsWith('/assets/');

  if (isPage) {
    // Serve the cached copy immediately, then refresh it for next time.
    event.respondWith(
      caches.match(req, { ignoreSearch: true }).then((hit) => {
        const network = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(PAGE, copy)).catch(() => {});
          }
          return res;
        }).catch(() => null);
        return hit || network || caches.match(PAGE, { ignoreSearch: true });
      })
    );
    return;
  }

  if (isAsset) {
    event.respondWith(
      caches.match(req, { ignoreSearch: true }).then((hit) => hit || fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      }))
    );
    return;
  }

  // Everything else: try the network, fall back to whatever we have.
  event.respondWith(
    fetch(req).then((res) => {
      if (res && res.ok) {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
      }
      return res;
    }).catch(() => caches.match(req))
  );
});
