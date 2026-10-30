/**
 * Filter options with nothing behind them must stay SELECTABLE.
 *
 * A fleet is mostly idle: "Erasing (0)", "Failed (0)", "BIOS: Unlocked (0)" is
 * the normal state of a bench at rest. The filter an operator sets up to WATCH a
 * transient — plug a drive in, wait for the row to appear under it — is precisely
 * one that has 0 matches when they reach for it. Disabling those options made the
 * monitoring case impossible: the chip greyed out and could not be pressed.
 *
 * The count is still the signal, so zero-count options read muted and explain
 * themselves on hover, but they toggle like any other.
 *
 * Static assertions, because the defect is a two-line client-side branch that no
 * PHP test and no API test can see.
 *
 * Run: node marketing/tests/filter_options_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const page = fs.readFileSync(path.join(__dirname, '..', 'site', 'dashboard', 'devices.html'), 'utf8');

let count = 0;
let fails = 0;
function check(label, ok, detail) {
  count++;
  if (ok) {
    console.log('ok   - ' + label);
  } else {
    fails++;
    console.log('FAIL - ' + label + (detail !== undefined ? ' (got: ' + JSON.stringify(detail) + ')' : ''));
  }
}

// The facet renderer, from its own definition to the next renderer.
const start = page.indexOf('function renderFilterGroups()');
const end = page.indexOf('function paintFilterHint()');
const renderer = start === -1 || end === -1 ? '' : page.slice(start, end);

check('the facet renderer was found', renderer !== '');

// The regression itself: a zero count must not disable the chip.
check('a zero-count option is never disabled',
  renderer !== '' && !/\bb\.disabled\s*=\s*true/.test(renderer));
check('and it is not styled as unclickable',
  renderer !== '' && !/cursor-not-allowed/.test(renderer));

// It still reads as "nothing right now": muted, with a reason.
check('a zero-count option is muted rather than hidden',
  renderer !== '' && /opacity-60/.test(renderer));
check('and a zero-count option explains itself on hover',
  renderer !== '' && /title\s*=\s*'Nothing matches right now/.test(renderer));

// Selecting it must work like any other chip: the click handler stays attached
// unconditionally (a `return` before it would leave an inert chip that looks on).
const zeroBlock = renderer.indexOf('if (n === 0 && !on)');
const clickHandler = renderer.indexOf("b.addEventListener('click'");
check('the zero-count branch runs before the click handler is attached',
  zeroBlock !== -1 && clickHandler !== -1 && zeroBlock < clickHandler);
check('the zero-count branch does not return early',
  zeroBlock !== -1 && !/\}\s*else\s*\{[^}]*return/.test(renderer.slice(zeroBlock)));

// The count itself remains visible, which is what makes a 0 understandable.
check('the count is still printed beside the label',
  renderer !== '' && renderer.includes("' (' + n + ')'"));

// Applied filters are shown as removable chips, so a zero-match filter can be
// switched off without opening the modal again.
check('an applied filter can be removed from the toolbar',
  page.includes('function paintFilters()') && /Clear all/.test(page));

// An empty list has three causes. With a filter set — including one deliberately
// chosen at "(0)" to watch a transient — "No devices yet" would tell an operator
// with 218 machines that the fleet is empty, and offer no way back.
check('the empty state distinguishes a filter from an empty account',
  page.includes("var filtered = currentQ !== '' || source !== 'all' || countFilters(filters) > 0;"));
check('and it offers a way back',
  /No devices match the current filters\./.test(page) && /'Clear filters'/.test(page));
check('the no-devices-yet copy is kept for a genuinely empty account',
  /No devices yet\. Boot an appliance/.test(page));

console.log('');
if (fails === 0) {
  console.log('ALL TESTS PASSED (' + count + ' checks)');
  process.exit(0);
}
console.log(fails + ' of ' + count + ' checks FAILED');
process.exit(1);
