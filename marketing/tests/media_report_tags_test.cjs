/**
 * The Media page's per-report provenance tag.
 *
 * The row above a report list states the media item's latest provenance. The
 * list behind the Reports button states the same for EACH submission: one tag,
 * in the status column, exactly as the row shows it ("COMPLETED origin").
 *
 *   - submitted on a bench, no declared origin   -> `bench`
 *   - a declared origin for that job             -> `origin` (and only that:
 *                                                   the tag is the CURRENT state,
 *                                                   not a second fact beside it)
 *   - declared origin equal to the report's host -> nothing ("in place")
 *   - no host serial and no declaration          -> `no host`
 *
 * Both surfaces use the row's own `provenanceChip`, so the two can never drift
 * apart. The rule is pure, so it is extracted from the page's inline script and
 * run against synthetic reports; the wiring checks then assert the chip is
 * actually appended in the status cell of both renderers, and NOT in the CoC
 * cell (where it was tried first and read as part of the report ID).
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

/** Pull one function out of the page by brace matching. */
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

const src = extractFunction(js, 'provenanceChip');
check('provenanceChip is defined in the Media page', src !== null);

const el = (tag, cls, text) => ({ tag, cls: cls || '', text: text === undefined ? '' : text, title: '' });
const chipOf = src
  ? new Function('el', src + '\nreturn provenanceChip;')(el)
  : () => null;
const tags = (h) => {
  const c = chipOf(h);
  return c ? [c.text] : [];
};

// ---- not assigned: the bench is the current state -------------------------
const bench = { sysserial: 'BENCH123', host_processing: 1 };
check('a bench report with no assignment shows just bench',
  tags(bench).join(',') === 'bench', tags(bench).join(','));
check('the bench tag names the host',
  /BENCH123/.test((chipOf(bench) || {}).title || ''), (chipOf(bench) || {}).title);
check('the bench tag keeps the row chip\'s amber',
  /bg-amber-100/.test((chipOf(bench) || {}).cls || ''));

// ---- assigned: origin REPLACES bench, it does not join it -----------------
const declared = { sysserial: 'BENCH123', host_processing: 1, origin_asset: 'CZC1234ABC', origin_provenance: 'declared' };
check('once assigned the only tag is origin',
  tags(declared).join(',') === 'origin', tags(declared).join(','));
check('the origin tag names the device',
  /CZC1234ABC/.test((chipOf(declared) || {}).title || ''), (chipOf(declared) || {}).title);
check('the origin tag says the provenance is declared',
  /declared by operator/.test((chipOf(declared) || {}).title || ''), (chipOf(declared) || {}).title);
check('the origin tag keeps the row chip\'s sky',
  /bg-sky-100/.test((chipOf(declared) || {}).cls || ''));

// ---- declarations that add nothing ---------------------------------------
check('a declaration naming the host itself adds no tag',
  tags({ sysserial: 'CZC1234ABC', origin_asset: 'CZC1234ABC' }).length === 0,
  tags({ sysserial: 'CZC1234ABC', origin_asset: 'CZC1234ABC' }).join(','));
check('host/origin comparison ignores case',
  tags({ sysserial: 'czc1234abc', origin_asset: 'CZC1234ABC' }).length === 0,
  tags({ sysserial: 'czc1234abc', origin_asset: 'CZC1234ABC' }).join(','));

// ---- ordinary and hostless reports ---------------------------------------
check('an in-place report carries no tag',
  tags({ sysserial: 'HOST9' }).length === 0, tags({ sysserial: 'HOST9' }).join(','));
check('a report with no host serial is tagged no host',
  tags({ sysserial: '' }).join(',') === 'no host', tags({ sysserial: '' }).join(','));
check('a declaration satisfies a report with no host serial',
  tags({ sysserial: '', origin_asset: 'CZC1234ABC', origin_provenance: 'declared' }).join(',') === 'origin',
  tags({ sysserial: '', origin_asset: 'CZC1234ABC' }).join(','));

// ---- wiring: the same chip, in the status position, in both renderers -----
check('the two-tag helper is gone', !/reportProvenanceChips/.test(js));

// The definition matches the same pattern, so subtract it: four CALLS means the
// two rows and the two report lists, which is every surface that shows one.
const calls = (js.match(/provenanceChip\s*\(/g) || []).length - 1;
check('exactly four call sites (2 rows + 2 report lists)', calls === 4, calls);

const statusIdx = js.indexOf("var hstatusCell = el('td'");
const statusBody = statusIdx > -1 ? js.slice(statusIdx, statusIdx + 500) : '';
check('the table report row appends the chip to its status cell',
  /provenanceChip\(h\)/.test(statusBody) && /hstatusCell\.appendChild\(hstatusProv\)/.test(statusBody));

const cocIdx = js.indexOf("var hcoc = el('td'");
const cocEnd = js.indexOf('htr.appendChild(hcoc);', cocIdx);
const cocBody = (cocIdx > -1 && cocEnd > cocIdx) ? js.slice(cocIdx, cocEnd) : '';
check('the CoC cell stays free of provenance tags', cocBody !== '' && !/provenanceChip/.test(cocBody));

const cardIdx = js.indexOf("var hstat = el('div'");
const cardBody = cardIdx > -1 ? js.slice(cardIdx, cardIdx + 400) : '';
check('the card report row appends the chip beside its status',
  /hstat\.appendChild\(hprov\)/.test(cardBody));

check('the row still paints its own chip',
  /var prov = provenanceChip\(d\);/.test(js) && /if \(prov\) badges\.appendChild\(prov\);/.test(js));

console.log('\n' + (fails === 0 ? 'ALL TESTS PASSED' : fails + ' FAILED') + ' (' + count + ' checks)');
process.exit(fails === 0 ? 0 : 1);
