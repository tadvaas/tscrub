/**
 * The BIOS clear popover is a submission form, not a status view.
 *
 * The bug this pins down: the popover used to poll /api/bios/unlock, render
 * "Queued / Clearing…", and then — when the command resolved — REPLACE the form
 * with the verdict. A wrong password is a resolved command, so it left the
 * operator staring at "Wrong password." with no password field and no button.
 * The only way to try again was to close the popover and hope, or reload.
 *
 * The rule now:
 *
 *   - the popover shows the password form on every open, always;
 *   - it carries NO command state and NO verdict — nothing to get stuck on;
 *   - the submit control is re-enabled on every outcome, including success, so
 *     a retry is one click away (a newer unlock supersedes the queued one);
 *   - the outcome is reported where the evidence already is: the device Log
 *     (the command and its verdict) and the row (the Operations dot while it is
 *     queued or running).
 *
 * Run: node marketing/tests/bios_unlock_resubmit_test.cjs
 *      BIOS_ROOT=/tmp/mut node marketing/tests/bios_unlock_resubmit_test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.BIOS_ROOT || path.join(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'site', 'dashboard', 'devices.html'), 'utf8');
const log = fs.readFileSync(path.join(root, 'server', 'device_log.php'), 'utf8');

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

/** The BIOS popover region: from its banner comment to the next top-level var. */
function popoverBlock(src) {
  const start = src.indexOf('// ---- BIOS clear popover');
  if (start < 0) return '';
  const end = src.indexOf('var remotePollTimer = null;', start);
  return src.slice(start, end < 0 ? src.length : end);
}
const block = popoverBlock(html);

/** The body of a `functionName(...) { ... }` by brace depth. */
function fnBody(src, name) {
  const sig = new RegExp('function\\s+' + name + '\\s*\\([^)]*\\)\\s*\\{');
  const m = sig.exec(src);
  if (!m) return '';
  let i = m.index + m[0].length;
  let depth = 1;
  for (; i < src.length && depth > 0; i++) {
    const c = src[i];
    if (c === '{') depth++;
    else if (c === '}') depth--;
    else if (c === '\n') {
      // skip line comments so an unbalanced brace in prose cannot miscount
      const eol = src.indexOf('\n', i);
      const line = src.slice(i, eol < 0 ? src.length : eol);
      if (/^\s*\/\//.test(line)) i = eol < 0 ? src.length : eol;
    }
  }
  return src.slice(m.index + m[0].length, i - 1);
}

// --- the popover exists at all (guards against silent extraction drift) ------
check('BIOS popover block is present', block.length > 200, block.length);
check('openBiosPop and renderBiosIdleForm live in it',
  /function openBiosPop\s*\(/.test(block) && /function renderBiosIdleForm\s*\(/.test(block));

// --- no status surface: nothing in the popover can render a result ----------
check('no renderBiosPop() — the popover has no resolved state',
  !/renderBiosPop/.test(block), (block.match(/renderBiosPop/g) || []).length);
check('no biosUnlockState() — the popover does not read command state',
  !/biosUnlockState/.test(block));
check('no BIOS_VERDICT_MSG — verdicts are not rendered in the popover',
  !/BIOS_VERDICT_MSG/.test(block));
check('no _biosKind per-node state', !/_biosKind/.test(block));
check('no polling in the popover', !/setTimeout\s*\(/.test(block) && !/biosPollTimer/.test(block));
check('the dashboard no longer calls the unlock state route',
  !/\/api\/bios\/unlock/.test(html));

// --- the form is unconditional, so a re-open always offers the field --------
const open = fnBody(block, 'openBiosPop');
check('openBiosPop fills the popover with the form',
  /renderBiosIdleForm\(dev,\s*content\)/.test(open));
const renderCalls = (open.match(/renderBiosIdleForm\(/g) || []).length;
check('exactly one, unconditional form render (no branch can skip it)',
  renderCalls === 1, renderCalls);
check('the form is rendered BEFORE the popover is measured/positioned',
  open.indexOf('renderBiosIdleForm(dev, content)') < open.indexOf('positionAndDismissPop(pop, anchor, closeBiosPop)'));

// --- submission path intact -------------------------------------------------
const form = fnBody(block, 'renderBiosIdleForm');
check('the form posts a bios_unlock command with the password',
  /command:\s*'bios_unlock'/.test(form) && /password:\s*pwd/.test(form));
check('the submit control is created enabled', /var btn = el\('button'[^;]*'Clear password'\)/.test(form)
  && !/btn\.disabled\s*=\s*true[^\n]*\n[^\n]*var btn/.test(form));

// --- re-enabled on EVERY outcome (the actual defect) ------------------------
const okBranch = /if\s*\(res\.ok\)\s*\{([\s\S]*?)\n\s*\}\s*else\s*\{/.exec(form);
check('success branch found', !!okBranch);
if (okBranch) {
  check('success re-enables the submit button (retry is one click away)',
    /btn\.disabled\s*=\s*false/.test(okBranch[1]));
  check('success clears the password field', /inp\.value\s*=\s*''/.test(okBranch[1]));
  check('success does NOT swap the form out for a result',
    !/innerHTML|content\.textContent|appendChild/.test(okBranch[1]), okBranch[1].replace(/\s+/g, ' ').trim().slice(0, 80));
  check('success says sending another password replaces the queued one',
    /replaces it/i.test(okBranch[1]));
}
const elseBranch = /else\s*\{([\s\S]*?)\n\s*\}\s*\}\)\s*\.catch/.exec(form);
check('error branch re-enables the submit button', !!elseBranch && /btn\.disabled\s*=\s*false/.test(elseBranch[1]));
const catchBranch = /\.catch\(function\s*\(\)\s*\{([\s\S]*?)\}\);/.exec(form);
check('network-error branch re-enables the submit button', !!catchBranch && /btn\.disabled\s*=\s*false/.test(catchBranch[1]));

// --- the outcome is visible in the LOG -------------------------------------
check('the Log reports a wrong password as "Wrong password" with fail severity',
  /if \(\$verdict === 'wrong_password'\) return \['Wrong password', 'fail'\];/.test(log));
check('the Log reports a successful clear',
  /if \(\$status === 'done'\) return \['Cleared', 'ok'\];/.test(log));
check('a superseded clear is logged as replaced, not as a failure',
  /'superseded' => 'replaced by a newer command'/.test(log));
// Rows resolved through the legacy path stored an EMPTY verdict while the
// appliance detail said "wrong password" — the Log showed a bare "Failed".
// It now classifies through unlock_verdict(), the one verdict vocabulary.
check('the Log classifies a clear from the detail when the row has no verdict',
  /require_once __DIR__ \. '\/bios_unlock\.php'/.test(log)
  && /if \(\$cmd === 'bios_unlock'\) \{\s*\n\s*\$verdict = unlock_verdict\(/.test(log));
check('the no-clear-path guidance keys off the derived verdict, not the column',
  /if \(\$verdict === 'no_clear_path'\)/.test(log));

// --- and in the ROW while it is live ---------------------------------------
const dot = fnBody(html, 'remoteDot');
check('the row marks a queued/running clear via the command label',
  /COMMAND_LABEL\[r\.command\]/.test(dot) && /status === 'pending' \|\| r\.status === 'dispatched'/.test(dot));
check('COMMAND_LABEL names bios_unlock', /bios_unlock:\s*'BIOS unlock'/.test(html));

console.log('\n' + (fails === 0 ? 'PASS' : 'FAIL') + ' — ' + (count - fails) + '/' + count + ' checks');
process.exit(fails === 0 ? 0 : 1);
