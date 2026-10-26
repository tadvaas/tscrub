/**
 * The dashboard's "the page must not twitch while it loads" contract.
 *
 * A loading indicator that is INSERTED into the flow changes the width (or the
 * height) of whatever it lands next to, so the toolbar and the pager jump every
 * time a fetch starts and again when it finishes. Nothing about that fails
 * loudly: the page still works, it just looks broken, and no PHP or API test can
 * see it. So the invariant is asserted here, on the markup and on the plumbing
 * that paints it:
 *
 *   - the switch's spinner lives in a slot that is ALWAYS in the layout and only
 *     changes opacity, never display and never its own size;
 *   - the panel's spinner is absolutely positioned inside a fixed-size overlay,
 *     so it cannot push anything;
 *   - the progress bar is a fixed-height strip whose inner block alone moves;
 *   - the pager buttons keep a min-width, so swapping a label for a spinner
 *     cannot resize them;
 *   - the busy state is started by the record-source switch and ended in the
 *     fetch's settle step — for BOTH pages.
 *
 * Run: node marketing/tests/dashboard_loading_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const siteRoot = process.env.DASHBOARD_ROOT || path.join(__dirname, '..');
const repoRoot = path.join(__dirname, '..');
const PAGES = [
  { file: path.join(siteRoot, 'site', 'dashboard', 'devices.html'), list: 'device-list', fetchSeq: 'devicesFetchSeq' },
  { file: path.join(siteRoot, 'site', 'dashboard', 'drives.html'), list: 'drive-list', fetchSeq: 'drivesFetchSeq' },
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

/** The class attribute of the element with this id (first match), or null. */
function classesOf(html, id) {
  const tag = html.match(new RegExp('<[a-z]+[^>]*\\bid="' + id + '"[^>]*>', 'i'));
  if (!tag) return null;
  const cls = tag[0].match(/\bclass="([^"]*)"/);
  return cls ? cls[1] : '';
}

/** The whole opening tag of the element with this id, or null. */
function tagOf(html, id) {
  const tag = html.match(new RegExp('<[a-z]+[^>]*\\bid="' + id + '"[^>]*>', 'i'));
  return tag ? tag[0] : null;
}

/** The inline script (there is exactly one per dashboard page). */
function inlineScript(html) {
  const m = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/);
  return m ? m[1] : '';
}

for (const p of PAGES) {
  const html = fs.readFileSync(p.file, 'utf8');
  const js = inlineScript(html);
  const name = path.basename(p.file);

  console.log('\n== ' + name + ' ==');

  // ---- the switch's spinner slot -------------------------------------------
  // The slot must exist, be sized, and be OUTSIDE any show/hide mechanism:
  // `hidden`/`display` would drop it out of the layout and reflow the toolbar.
  const slotCls = classesOf(html, 'source-busy');
  check('switch has a busy slot', slotCls !== null);
  check('busy slot is sized (h-3 w-3)', /\bh-3\b/.test(slotCls || '') && /\bw-3\b/.test(slotCls || ''), slotCls);
  check('busy slot is shrink-0 so it cannot collapse', /\bshrink-0\b/.test(slotCls || ''), slotCls);
  check('busy slot starts transparent, not hidden', /\bopacity-0\b/.test(slotCls || '') && !/\bhidden\b/.test(slotCls || ''), slotCls);
  // ...and it must be inside the switch group, or the group's width still changes.
  const switchBlock = html.slice(html.indexOf('id="source-switch"'));
  const slotIdx = switchBlock.indexOf('id="source-busy"');
  const groupEnd = switchBlock.indexOf('</div>');
  check('busy slot sits inside the switch group', slotIdx > 0 && slotIdx < groupEnd, slotIdx + ' vs ' + groupEnd);

  // ---- the panel overlay ---------------------------------------------------
  const overlayTag = tagOf(html, 'list-busy');
  check('panel has a busy overlay', overlayTag !== null);
  check('overlay is out of the flow (absolute)', /\babsolute\b/.test(overlayTag || ''), overlayTag);
  check('overlay cannot swallow clicks', /\bpointer-events-none\b/.test(overlayTag || ''), overlayTag);
  check('overlay covers the panel exactly', /\binset-0\b/.test(overlayTag || ''), overlayTag);
  check('overlay starts hidden', /\bhidden\b/.test(overlayTag || ''), overlayTag);
  // The card must be a positioning context, otherwise `inset-0` resolves against
  // the page and the spinner lands somewhere else entirely. Walk out one level:
  // the list div's own tag, then its parent's.
  const listTagStart = html.lastIndexOf('<div', html.indexOf('id="' + p.list + '"'));
  const cardStart = html.lastIndexOf('<div', listTagStart - 1);
  const cardTag = html.slice(cardStart, html.indexOf('>', cardStart) + 1);
  check('panel card is the overlay\'s positioning context', /\brelative\b/.test(cardTag), cardTag);
  check('panel card still clips to its rounded corners', /\boverflow-hidden\b/.test(cardTag), cardTag);

  // The spinner must be centred by an absolutely positioned wrapper: a flex
  // child of the overlay would be a normal in-flow item and would decide the
  // overlay's own size.
  const overlayBody = html.slice(html.indexOf('id="list-busy"'), html.indexOf('</div>', html.indexOf('id="list-busy"')));
  check('centring wrapper is absolute', /\babsolute inset-0 flex items-center justify-center\b/.test(overlayBody), overlayBody.slice(0, 160));
  check('overlay spinner is fixed-size', /h-5 w-5 animate-spin/.test(overlayBody));

  // ---- the progress bar ----------------------------------------------------
  const barTag = tagOf(html, 'list-busy-bar');
  check('panel has a busy bar', barTag !== null);
  check('bar is out of the flow and fixed-height', /\babsolute\b/.test(barTag || '') && /\bh-0\.5\b/.test(barTag || ''), barTag);
  check('bar starts hidden', /\bhidden\b/.test(barTag || ''), barTag);
  // Only the inner block moves — the transform is what animates, never a width.
  const barBody = html.slice(html.indexOf('id="list-busy-bar"'), html.indexOf('</div>\n      </div>', html.indexOf('id="list-busy-bar"')));
  check('bar animates a w-1/3 block, not its own width', /animate-indeterminate/.test(barBody) && /w-1\/3/.test(barBody), barBody.slice(0, 200));

  // ---- the pager -----------------------------------------------------------
  // Label ⇄ spinner+"…" must not change the button's width: min-w does that.
  check('pager buttons keep a stable min-width', /min-w-\[88px\]/.test(js));
  check('pager spinner is fixed-size', /inline-block h-3 w-3 shrink-0 animate-spin/.test(js));

  // ---- the wiring ----------------------------------------------------------
  check('painting the busy state is opacity-only for the switch slot',
    /source-busy[\s\S]{0,80}opacity-100/.test(js) && /classList\.toggle\('opacity-0'/.test(js));
  check('the overlay is toggled, not rebuilt', /getElementById\('list-busy'\)[\s\S]{0,60}classList\.toggle\('hidden'/.test(js));
  check('the switch click starts the busy state', /startListBusy\(\);/.test(js));
  // Exactly one call site: the switch handler. The 10s auto-refresh must never
  // start it, or the page animates under the operator every poll.
  check('busy is started from the source-switch handler only',
    (js.match(/startListBusy\(\);/g) || []).length === 1, (js.match(/startListBusy\(\);/g) || []).length);
  check('busy is ended in the fetch settle step', /endListBusy\(seq\);/.test(js));
  check('the end is sequence-guarded', new RegExp('function endListBusy\\(seq\\) \\{\\s*\\n\\s*if \\(seq !== ' + p.fetchSeq + '\\) return;').test(js));
  check('a fast response still shows the animation for a beat', /320 - \(Date\.now\(\) - listBusyAt\)/.test(js));
  check('aria-busy is set on the panel', /setAttribute\('aria-busy'/.test(js));
  // The overlay is pointer-events-none, so nothing in it may rely on being clicked.
  check('the overlay is never given pointer events', !/list-busy'\)[\s\S]{0,120}pointer-events-auto/.test(js));
}

// ---- the animation must actually exist -------------------------------------
const tw = fs.readFileSync(path.join(repoRoot, 'tailwind.config.js'), 'utf8');
check('animate-indeterminate is defined in the Tailwind config', /indeterminate:\s*'indeterminate/.test(tw));
check('the indeterminate keyframes translate rather than resize', /translateX\(-100%\)/.test(tw) && /translateX\(400%\)/.test(tw));

console.log('\n' + count + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
