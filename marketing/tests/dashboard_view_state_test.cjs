/**
 * The dashboard's view state must survive a refresh, a bookmark, a shared link,
 * and the trip to another tab and back.
 *
 * Why a test: every one of those is invisible to a PHP test and to a smoke test
 * that only loads the page once. The failure mode is silent and annoying — the
 * operator filters to Attested, refreshes, and is looking at All again, having
 * concluded the page lost their place (or worse, that records are missing).
 *
 * Invariants asserted here:
 *
 *   - the source filter, the search, the sort and the page are READ from the
 *     query string, never initialised to a literal;
 *   - everything that changes the view writes it back (7 call sites: switch,
 *     search, clear, sort, render, prev, next);
 *   - replaceState, not pushState — a history entry per keystroke would make
 *     Back rewind the search one character at a time;
 *   - Back/Forward re-reads the URL and ignores the remembered value;
 *   - an explicit query string suppresses the remembered view, so the Add modal
 *     landing on "?q=<serial>" cannot arrive filtered to a source the new record
 *     is not in;
 *   - an unknown `source` means All, mirroring list_source() on the server, so a
 *     hand-edited URL cannot hide records;
 *   - VIEW_SORTS covers every sortable column the page actually renders — add a
 *     column and forget the list, and restoring `?sort=` silently falls back to
 *     the default, which looks like the sort was ignored;
 *   - localStorage is guarded (private mode / quota must not break the page).
 *
 * Run: node marketing/tests/dashboard_view_state_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.VIEW_ROOT || path.join(__dirname, '..');
const PAGES = [
  // syncCalls includes the definition. Devices carries ONE extra call site for the
  // applied-filter path: Apply, a chip's ×, Clear all and the bench chip all go
  // through reloadFiltered(), which writes the view once, so they cannot drift
  // apart — and one more for the "Clear filters" button in the empty state;
  // Media has none of those.
  { file: 'devices.html', key: 'tscrub.view.devices', sortDefault: 'status', syncCalls: 10 },
  { file: 'drives.html', key: 'tscrub.view.drives', sortDefault: 'date', syncCalls: 8 },
];

let count = 0;
let fails = 0;
function check(label, ok, detail) {
  count++;
  if (ok) console.log('ok   - ' + label);
  else {
    fails++;
    console.log('FAIL - ' + label + (detail !== undefined ? ' (got: ' + detail + ')' : ''));
  }
}

for (const p of PAGES) {
  const html = fs.readFileSync(path.join(root, 'site', 'dashboard', p.file), 'utf8');
  const m = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/);
  const js = m ? m[1] : '';

  console.log('\n== ' + p.file + ' ==');

  // ---- read from the URL, not from a literal ------------------------------
  check('source is read from the view state',
    /var source = viewSource\(viewGet\('source'\)\);/.test(js));
  check('page is read from the view state',
    /var page = viewInt\(viewGet\('page'\), 1\)/.test(js));
  check('sort key is read from the view state',
    /var sortKey = viewSort\(viewGet\('sort'\), VIEW_DEFAULTS\.sort\);/.test(js));
  check('sort direction is read from the view state',
    /var sortDir = viewDir\(viewGet\('dir'\), VIEW_DEFAULTS\.dir\);/.test(js));
  check('the search box is filled from the view state',
    /currentQ = viewGet\('q'\);/.test(js) && /searchInput\.value = currentQ;/.test(js));
  check('the default sort is the page\'s own',
    new RegExp("VIEW_DEFAULTS = \\{ sort: '" + p.sortDefault + "'").test(js));
  check('the storage key is page-scoped',
    js.includes("'" + p.key + "'"));

  // ---- write back ---------------------------------------------------------
  // definition + N call sites: switch, search, clear, sort, render, prev, next,
  // and on Devices the three that change the advanced filter plus Clear filters.
  const calls = (js.match(/syncView\(\)/g) || []).length;
  check('every view change writes back (' + (p.syncCalls - 1) + ' call sites + the definition)',
    calls === p.syncCalls, calls);
  check('uses replaceState, never pushState',
    /history\.replaceState/.test(js) && !/history\.pushState/.test(js));
  check('Back/Forward re-reads the URL', /addEventListener\('popstate'/.test(js));
  check('popstate ignores the remembered value',
    /localStorage\.getItem\(VIEW_KEY\)/.test(js) &&
    !/popstate[\s\S]{0,400}VIEW_SAVED/.test(js));

  // ---- the Add-modal safety ----------------------------------------------
  check('an explicit query string suppresses the remembered view',
    /var VIEW_EXPLICIT = \['q', 'source', 'sort', 'dir', 'page'(?:, 'ff')?(?:, 'bench')?\]\.some/.test(js),
    (js.match(/var VIEW_EXPLICIT = \[[^\]]*\]/) || [''])[0]);
  check('an empty param falls through to the remembered view',
    /if \(VIEW_EXPLICIT\) \{ return ''; \}/.test(js));

  // ---- hostile input ------------------------------------------------------
  check('an unknown source means All (mirrors list_source)',
    /function viewSource\(v\) \{ return \(v === 'verified' \|\| v === 'attested'\) \? v : 'all'; \}/.test(js));
  check('a bad page number falls back to 1',
    /function viewInt\(v, dflt\) \{ var n = parseInt\(v, 10\); return isFinite\(n\) && n > 0 \? n : dflt; \}/.test(js));

  // ---- the sort allow-list must cover every sortable column ---------------
  const listed = (js.match(/var VIEW_SORTS = \[([^\]]*)\]/) || [, ''])[1]
    .split(',').map((s) => s.trim().replace(/'/g, '')).filter(Boolean);
  // Column tuples are ['Label', 'w-[12%]', 'sortkey'] or, when the header carries a
  // tooltip, ['Label', 'w-[12%]', 'sortkey', 'explanation']. Both must count — the
  // titled columns are exactly the ones a partial regex would silently skip
  // (Submitted/date was skipped on the first pass).
  const rendered = [...js.matchAll(/\['[^']*',\s*'[^']*',\s*'([a-z_]+)'(?:,\s*'[^']*')?\]/g)].map((x) => x[1]);
  const missing = [...new Set(rendered)].filter((k) => !listed.includes(k));
  check('VIEW_SORTS covers every sortable column', missing.length === 0 && listed.length > 0,
    'missing: ' + missing.join(',') + ' | listed: ' + listed.join(','));

  // ---- storage must never break the page ---------------------------------
  check('reading localStorage is guarded',
    /try \{ VIEW_SAVED = JSON\.parse\(localStorage\.getItem\(VIEW_KEY\) \|\| '\{\}'\) \|\| \{\}; \} catch/.test(js));
  check('writing localStorage is guarded', /localStorage\.setItem\(VIEW_KEY[\s\S]{0,240}\} catch/.test(js));
  check('the URL is written even if storage throws',
    js.indexOf('history.replaceState') < js.indexOf('localStorage.setItem(VIEW_KEY'));
}

console.log('\n' + count + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
