/**
 * Per-report provenance tags in the Media page's report list.
 *
 * The row above the list states the media item's LATEST provenance. The list
 * behind the Reports button states what was true of each submission, plus what
 * has since been declared about its job — a report that ran on the bench, and a
 * report whose job was later pointed at a device, are different facts and can
 * both be true of one report. The rules are pure, so the function is extracted
 * from the page's inline script and run against synthetic reports:
 *
 *   - submitted by a processing host            -> `bench`
 *   - declared origin different from its host   -> `origin` (serial in the title)
 *   - both                                      -> both tags
 *   - declared origin equal to the host         -> nothing ("in place")
 *   - no host serial and no declaration         -> `no host`
 *   - no host serial but a declaration          -> `origin` only
 *
 * The wiring checks at the end assert the helper is called from BOTH renderers:
 * the card list and the table sub-row. A tag that exists in only one of them is
 * invisible at half the page widths, and no assertion about the helper would
 * notice.
 *
 * Run: node marketing/tests/media_report_tags_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const pagePath = process.env.DRIVES_PAGE
  || path.join(__dirname, '..', 'site', 'dashboard', 'drives.html');
const html = fs.readFileSync(pagePath, 'utf8');

const m = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/);
const js = m ? m[1] : '';

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

/**
 * Pull one function out of the page by brace matching. The function is
 * self-contained (it only calls `el`), which is what makes this safe: there is
 * no capture of page state and nothing to stub beyond the element factory.
 */
function extractFunction(src, name) {
  const start = src.indexOf('function ' + name + '(');
  if (start === -1) return null;
  const open = src.indexOf('{', start);
  if (open === -1) return null;
  let depth = 0;
  for (let i = open; i < src.length; i++) {
    if (src[i] === '{') depth++;
    else if (src[i] === '}') {
      depth--;
      if (depth === 0) return src.slice(start, i + 1);
    }
  }
  return null;
}

const src = extractFunction(js, 'reportProvenanceChips');
check('reportProvenanceChips is defined in the Media page', src !== null);

const el = (tag, cls, text) => ({ tag, cls: cls || '', text: text === undefined ? '' : text, title: '' });
const chipsOf = src
  ? new Function('el', src + '\nreturn reportProvenanceChips;')(el)
  : () => [];
const tags = (h) => chipsOf(h).map((c) => c.text);
const chip = (h, text) => chipsOf(h).find((c) => c.text === text) || null;

// ---- a report submitted by the bench --------------------------------------
const bench = { sysserial: 'BENCH123', host_processing: 1 };
check('bench report carries the bench tag', tags(bench).join(',') === 'bench', tags(bench).join(','));
check('bench tag names the host in its title',
  /BENCH123/.test((chip(bench, 'bench') || {}).title || ''), (chip(bench, 'bench') || {}).title);
check('bench tag is the row chip\'s amber, not a new colour',
  /bg-amber-100/.test((chip(bench, 'bench') || {}).cls || ''));

// ---- a report whose job has a declared origin -----------------------------
const declared = { sysserial: 'BENCH123', host_processing: 1, origin_asset: 'CZC1234ABC', origin_provenance: 'declared' };
check('bench report with a declared origin carries both tags',
  tags(declared).join(',') === 'bench,origin', tags(declared).join(','));
check('origin tag names the device in its title',
  /CZC1234ABC/.test((chip(declared, 'origin') || {}).title || ''), (chip(declared, 'origin') || {}).title);
check('origin tag says the provenance is declared',
  /declared by operator/.test((chip(declared, 'origin') || {}).title || ''), (chip(declared, 'origin') || {}).title);
check('origin tag is the row chip\'s sky, not a new colour',
  /bg-sky-100/.test((chip(declared, 'origin') || {}).cls || ''));

// A declaration made on a report that ran in the device's own chassis: the
// origin is the host, so there is nothing to flag.
check('declared origin equal to the host adds no tag',
  tags({ sysserial: 'CZC1234ABC', origin_asset: 'CZC1234ABC' }).length === 0,
  tags({ sysserial: 'CZC1234ABC', origin_asset: 'CZC1234ABC' }).join(','));
check('host/origin comparison ignores case',
  tags({ sysserial: 'czc1234abc', origin_asset: 'CZC1234ABC' }).length === 0,
  tags({ sysserial: 'czc1234abc', origin_asset: 'CZC1234ABC' }).join(','));

// ---- ordinary reports ------------------------------------------------------
check('an in-place report carries no tag',
  tags({ sysserial: 'HOST9' }).length === 0, tags({ sysserial: 'HOST9' }).join(','));

// ---- reports with no host serial ------------------------------------------
check('a report with no host serial is tagged no host',
  tags({ sysserial: '' }).join(',') === 'no host', tags({ sysserial: '' }).join(','));
check('a declaration alone satisfies a report with no host serial',
  tags({ sysserial: '', origin_asset: 'CZC1234ABC', origin_provenance: 'declared' }).join(',') === 'origin',
  tags({ sysserial: '', origin_asset: 'CZC1234ABC' }).join(','));

// ---- wiring: both renderers must paint the tags ---------------------------
const definitionCount = (js.match(/reportProvenanceChips\s*\(/g) || []).length;
check('the helper has exactly two call sites (one per renderer)',
  definitionCount - 1 === 2, definitionCount - 1);

const historyStart = js.indexOf('function historyList(');
const historyEnd = js.indexOf('function driveCard(');
const cardBody = (historyStart > -1 && historyEnd > historyStart)
  ? js.slice(historyStart, historyEnd)
  : '';
check('the card renderer calls the helper', /reportProvenanceChips\(h\)/.test(cardBody));

const cocStart = js.indexOf("var hcoc = el('td'");
const cocBody = cocStart > -1 ? js.slice(cocStart, cocStart + 1200) : '';
check('the table renderer calls the helper', /reportProvenanceChips\(h\)/.test(cocBody));

// The row keeps its latest-state chip: the per-report tags are additional, not a
// replacement for the thing the operator sees without opening the list.
check('the row still paints its own latest-state chip', /var prov = provenanceChip\(d\);\s*\n\s*if \(prov\) badges\.appendChild\(prov\);/.test(js));

console.log('\n' + (fails === 0 ? 'ALL TESTS PASSED' : fails + ' FAILED') + ' (' + count + ' checks)');
process.exit(fails === 0 ? 0 : 1);
