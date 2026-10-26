/**
 * The `ts-*` control kit: one height per row, and no way to re-break it.
 *
 * The defect this kit exists to prevent: the Devices/Media toolbar used to carry
 * THREE control heights in one row — search 38px, Export 38px, Add 36px (it had
 * no border), and the record-source switch 30px — with three different
 * baselines. None of that fails a build, a PHP test or an API test; it is only
 * visible on screen, and it comes back the moment someone writes a control's
 * padding by hand.
 *
 * So the invariants are asserted here:
 *
 *   - every control takes its height from the kit, not from padding arithmetic;
 *   - a button and an input declare the SAME height (h-9), so they line up;
 *   - the segmented control is one control tall and its buttons FILL it;
 *   - the size scale is closed (base / --sm / --lg) — no fourth size appears;
 *   - no component applies brand-600, which would silently fail AA because the
 *     `html .bg-brand-600` contrast override only matches the utility class in
 *     the markup, not an @apply inside a component rule;
 *   - the page's JS never assigns className to a control — a JS-assigned class
 *     string wipes whatever component classes the kit put there.
 *
 * Run: node marketing/tests/ui_kit_test.cjs
 *      KIT_ROOT=/tmp/mut node marketing/tests/ui_kit_test.cjs   (mutated copy)
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.KIT_ROOT || path.join(__dirname, '..');
const css = fs.readFileSync(path.join(root, 'site', 'src', 'input.css'), 'utf8');
const PAGES = [
  { file: path.join(root, 'site', 'dashboard', 'devices.html'), label: 'devices' },
  { file: path.join(root, 'site', 'dashboard', 'drives.html'), label: 'drives' },
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

/** The body of `@layer components { ... }`, matched by brace depth. */
function componentsLayer(src) {
  const start = src.indexOf('@layer components {');
  if (start < 0) return '';
  let i = src.indexOf('{', start), depth = 0, end = -1;
  for (let j = i; j < src.length; j++) {
    if (src[j] === '{') depth++;
    else if (src[j] === '}') { depth--; if (depth === 0) { end = j; break; } }
  }
  return src.slice(i + 1, end);
}

/** The declaration body of one component rule, or '' if absent. */
function rule(layer, selector) {
  const at = layer.indexOf(selector + ' {');
  if (at < 0) return '';
  let depth = 0, out = '';
  for (let i = layer.indexOf('{', at); i < layer.indexOf('{', at) + 4000 && i < layer.length; i++) {
    const c = layer[i];
    if (c === '{') { depth++; if (depth === 1) continue; }
    if (c === '}') { depth--; if (depth === 0) break; }
    out += c;
  }
  return out;
}

const kit = componentsLayer(css).replace(/\/\*[\s\S]*?\*\//g, '');

console.log('== the kit exists ==');
for (const sel of ['.ts-btn', '.ts-btn--primary', '.ts-btn--secondary', '.ts-btn--danger',
                   '.ts-btn--sm', '.ts-btn--lg', '.ts-input', '.ts-input--icon',
                   '.ts-seg', ".ts-seg > button", ".ts-seg > button[aria-pressed='true']"]) {
  check('declares ' + sel, kit.includes(sel));
}

console.log('\n== one control height ==');
check('.ts-btn declares a height', /\bh-9\b/.test(rule(kit, '.ts-btn')), rule(kit, '.ts-btn').slice(0, 80));
check('.ts-input declares the SAME height (h-9)', /\bh-9\b/.test(rule(kit, '.ts-input')), rule(kit, '.ts-input').slice(0, 80));
check('.ts-seg declares the same height', /\bh-9\b/.test(rule(kit, '.ts-seg')), rule(kit, '.ts-seg').slice(0, 80));
check('segmented buttons FILL the group rather than sizing themselves',
  /\bh-full\b/.test(rule(kit, '.ts-seg > button')), rule(kit, '.ts-seg > button').slice(0, 80));
check('.ts-btn declares no vertical padding (height is the authority)',
  !/\bpy-/.test(rule(kit, '.ts-btn')), rule(kit, '.ts-btn').slice(0, 120));
check('.ts-input declares no vertical padding', !/\bpy-/.test(rule(kit, '.ts-input')));

console.log('\n== the size scale is closed ==');
const sizes = [...kit.matchAll(/\.ts-btn(--[a-z]+)?\s*\{/g)].map((m) => m[1] || '(base)');
check('only base / --sm / --lg / --primary / --secondary / --danger are declared',
  sizes.every((s) => ['(base)', '--primary', '--secondary', '--danger', '--sm', '--lg'].includes(s)), sizes.join(','));
check('--sm is one step down (h-7)', /\bh-7\b/.test(rule(kit, '.ts-btn--sm')));
check('--lg is one step up (h-11)', /\bh-11\b/.test(rule(kit, '.ts-btn--lg')));
const sizeMods = sizes.filter((s) => ['--sm', '--lg'].includes(s));
check('exactly two size modifiers', new Set(sizeMods).size === 2, sizeMods.join(','));

console.log('\n== the brand-600 contrast trap ==');
// `html .bg-brand-600 { @apply bg-brand-700 }` only matches the utility class in
// the markup. An @apply inside a component inlines the raw colour, so a
// component must name the accessible value itself.
// (Comments are stripped above: this kit's own documentation names both colours.)
check('no component applies bg-brand-600', !/bg-brand-600/.test(kit), (kit.match(/.{40}bg-brand-600.{40}/) || [''])[0]);
check('--primary applies the accessible brand-700', /\bbg-brand-700\b/.test(rule(kit, '.ts-btn--primary')));
check('--primary darkens on hover', /hover:bg-brand-800/.test(rule(kit, '.ts-btn--primary')));

console.log('\n== pages use the kit ==');
for (const p of PAGES) {
  const html = fs.readFileSync(p.file, 'utf8');
  const js = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/);
  const jsSrc = js ? js[1] : '';
  const btn = (id) => {
    const m = html.match(new RegExp('<[a-z]+[^>]*\\bid="' + id + '"[^>]*>'));
    return m ? (m[0].match(/class="([^"]*)"/) || [, ''])[1] : null;
  };

  check(p.label + ': source switch is a .ts-seg', /class="ts-seg"/.test(html));
  check(p.label + ': switch buttons carry no class attribute at all',
    /<button type="button" data-source="all" title=/.test(html));
  check(p.label + ': search uses .ts-input --icon',
    /class="ts-input ts-input--icon"/.test(html));
  check(p.label + ': Export is .ts-btn --secondary', /ts-btn ts-btn--secondary/.test(btn('export-btn') || ''), btn('export-btn'));
  check(p.label + ': Add is .ts-btn --primary', /ts-btn ts-btn--primary/.test(btn('add-btn') || ''), btn('add-btn'));
  check(p.label + ': Clear is .ts-btn --secondary + hidden',
    /ts-btn ts-btn--secondary/.test(btn('search-clear') || '') && /\bhidden\b/.test(btn('search-clear') || ''), btn('search-clear'));
  // No control may still size itself by padding.
  const toolbar = html.slice(html.indexOf('id="source-switch"'), html.indexOf('id="issue-none"') > 0 ? html.indexOf('id="issue-none"') : html.length);
  check(p.label + ': no toolbar control sizes itself with py-*',
    !/class="[^"]*\bpy-2\b[^"]*"[^>]*>(Clear|Export|Add)/.test(html) && !/id="(export-btn|add-btn|search-clear)"[^>]*class="[^"]*py-/.test(html));
  check(p.label + ': the switch never styles itself from JS',
    !/className\s*=\s*'rounded-md/.test(jsSrc));
  check(p.label + ': paintSourceSwitch writes only aria-pressed',
    /setAttribute\('aria-pressed'/.test(jsSrc) && !/b\.className\s*=/.test(jsSrc));
  if (p.label === 'drives') {
    check('drives: upload form uses the small button', /ts-btn ts-btn--primary ts-btn--sm/.test(html));
  }
}

console.log('\n' + count + ' checks, ' + fails + ' failed');
process.exit(fails === 0 ? 0 : 1);
