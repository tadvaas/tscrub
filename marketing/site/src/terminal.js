// Interactive terminal hero for tScrub — a simulated sanitize session.
;(function () {
const term = document.getElementById('term')
const output = document.getElementById('term-output')
const input = document.getElementById('term-input')
const rerun = document.getElementById('term-rerun')

// Only initialise on pages that include the terminal hero.
if (!term || !output || !input) return

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

let busy = false
let pendingConfirm = false
let paintTimer = null

const VERSION = 'v1.4.45'
const COCID = '48213'
const SPINNER = ['|', '/', '-', '\\']

// Drive fixtures for the demo. `etaSec` is the remaining wipe time in seconds;
// the ETA column counts down live, mirroring the appliance TUI. The compact
// table drops SMART/TEMP/BUS/CLASS (they only appear on wide terminals).
const DRIVES = [
  { device: 'nvme0n1', model: 'Samsung 990 PRO', serial: 'S4A1B2C3', type: 'NVMe', method: 'Crypto Purge',   cls: 'PURGE', remain: 240 },
  { device: 'sda',     model: 'Seagate EXOS',    serial: 'WDE1234',  type: 'SATA', method: 'Enhanced Erase', cls: 'PURGE', remain: 540 },
  { device: 'sdb',     model: 'Toshiba KPM5',    serial: 'X7Y8Z9',   type: 'SAS',  method: 'nwipe Quick',    cls: 'CLEAR', remain: 360 }
]

// Column widths for the device table (MODEL/SERIAL truncate with "…" like the
// real table; the rest are sized so their values never clip). W = total width.
const COLS = [13, 8, 5, 7, 14, 9, 6]
const COLS_LABELS = ['MODEL', 'SERIAL', 'TYPE', 'DEVICE', 'METHOD', 'STATUS', 'ETA']
const W = 68

function el(cls, text) {
  const node = document.createElement('div')
  if (cls) node.className = cls
  // A blank line still needs a space so it keeps its line height.
  node.textContent = (text == null || text === '') ? ' ' : text
  output.appendChild(node)
  output.scrollTop = output.scrollHeight
  return node
}

function pad(s, n) { return String(s).padEnd(n).slice(0, n) }
function ell(s, n) { return s.length > n ? s.slice(0, n - 1) + '…' : s }
function dash(n) { return '─'.repeat(n) }

function line(text, cls) { return el(cls || '', text) }

function clear() {
  output.innerHTML = ''
  output.style.whiteSpace = 'pre'
  output.style.overflowX = 'auto'
}

function stopTimer() {
  if (paintTimer) { clearInterval(paintTimer); paintTimer = null }
}

// Appliance screen themes: dark while booting, blue while wiping, green when
// every drive has finished. The whole widget (chrome + screen) is restyled.
function theme(name) {
  const t = {
    boot: { bg: '#0b1220', bar: '#111827', border: '#1e293b', fg: '#e2e8f0', muted: '#94a3b8' },
    run:  { bg: '#1e40af', bar: '#1e3a8a', border: '#1e3a8a', fg: '#eff6ff', muted: '#bfdbfe' },
    done: { bg: '#16a34a', bar: '#15803d', border: '#166534', fg: '#052e16', muted: '#14532d' }
  }[name]
  const box = document.getElementById('term-box')
  const bar = document.getElementById('term-bar')
  const title = document.getElementById('term-title')
  const prompt = document.getElementById('term-prompt')
  if (box) { box.style.background = t.bg; box.style.borderColor = t.border }
  if (bar) { bar.style.background = t.bar; bar.style.borderBottomColor = t.border }
  if (title) title.style.color = t.muted
  if (rerun) rerun.style.color = t.muted
  if (prompt) prompt.style.color = t.fg
  if (input) { input.style.color = t.fg; input.style.caretColor = t.fg }
  output.style.background = 'transparent'
  output.style.color = t.fg
}

function fmtElapsed(sec) {
  const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
}

function etaText(d) {
  if (d.status === 'DRY-RUN') return 'N/A'
  if (d.status === 'COMPLETED' || d.status === 'FAILED' || d.status === 'FROZEN' || d.status === 'BLOCKED') return '--'
  if (d.status === 'PLANNED') return '~' + Math.round(d.remain / 60) + 'min'
  const m = Math.floor(d.remain / 60), s = d.remain % 60
  return '~' + m + 'm' + String(s).padStart(2, '0') + 's'
}

// Two side-by-side System Info | Runtime panels, as in the appliance header.
function panels(elapsed, spinner) {
  const pw = 33
  const bar = '+' + dash(pw - 2) + '+'
  line(bar + '  ' + bar)
  line('| ' + pad('System Info', pw - 4) + ' |  ' + '| ' + pad('Runtime', pw - 4) + ' |')
  line(bar + '  ' + bar)
  const rows = [
    ['System:', 'Dell R740', 'Elapsed:', fmtElapsed(elapsed) + ' ' + spinner],
    ['System SN:', '7X2A3B4C5', 'COCID:', COCID],
    ['CPUs:', '2× Xeon 4214', 'Licence:', 'Acme ITAD'],
    ['RAM:', '64 GB', 'Tier:', 'Enterprise']
  ]
  for (const r of rows) {
    line('| ' + pad(r[0], 10) + pad(r[1], pw - 14) + ' |  ' + '| ' + pad(r[2], 10) + pad(r[3], pw - 14) + ' |')
  }
  line(bar + '  ' + bar)
}

function table(drives) {
  line(COLS_LABELS.map((l, i) => pad(l, COLS[i])).join(' ').trimEnd())
  line(dash(W))
  for (const d of drives) {
    const cells = [
      ell(d.model, COLS[0]), ell(d.serial, COLS[1]), pad(d.type, COLS[2]), pad(d.device, COLS[3]),
      pad(d.method, COLS[4]), pad(d.status, COLS[5]), pad(etaText(d), COLS[6])
    ]
    line(cells.map((c, i) => pad(c, COLS[i])).join(' ').trimEnd())
  }
  line(dash(W))
}

function footer() {
  const text = `tScrub ${VERSION} — tscrub.com`
  line(dash(W))
  line(' '.repeat(Math.max(0, Math.floor((W - text.length) / 2))) + text)
}

function paintScreen(drives, elapsed, spinner, phase, finish, dryRun) {
  clear()
  theme(phase)
  line('')
  if (dryRun && phase === 'run') line('*** DRY RUN — no wipe executed ***', 't-warn')
  panels(elapsed, spinner)
  line('')
  table(drives)
  line('')
  if (finish) {
    line('✓ Complete. Report written to /tScrub_48213_20260919T103000Z.csv')
    line('  Chain of custody ID: ' + COCID)
    line('')
  }
  footer()
}

async function runDemo(dryRun = false) {
  if (busy) return
  busy = true
  pendingConfirm = false
  stopTimer()
  clear()

  // Boot.
  theme('boot')
  line('tScrub started at boot', 't-info')
  line(`tScrub ${VERSION} — verifiable disk sanitisation`, 't-muted')
  line('')
  await sleep(700)

  const drives = DRIVES.map((d) => ({ ...d, status: dryRun ? 'DRY-RUN' : 'PLANNED' }))
  let elapsed = 0
  let spinner = 0
  const paint = (phase, finish) => paintScreen(drives, elapsed, SPINNER[spinner % 4], phase, finish, dryRun)

  paint('run')
  if (dryRun) {
    await sleep(900)
  } else {
    for (let i = 0; i < drives.length; i++) {
      await sleep(650)
      drives[i].status = 'RUNNING'
      paint('run')
    }
    await new Promise((resolve) => {
      paintTimer = setInterval(() => {
        elapsed += 1
        spinner += 1
        let allDone = true
        for (const d of drives) {
          if (d.status === 'RUNNING') { d.remain -= 60; if (d.remain <= 0) { d.remain = 0; d.status = 'COMPLETED' } }
          if (d.status !== 'COMPLETED' && d.status !== 'FAILED') allDone = false
        }
        paint('run')
        if (allDone) { stopTimer(); resolve() }
      }, 600)
    })
  }

  await sleep(300)
  paint('done', true)
  busy = false
}

function showReport() {
  clear()
  theme('done')
  line('')
  line('  DEVICE      METHOD               CERT           STATUS')
  line('  ' + dash(52))
  for (const d of DRIVES) {
    line('  ' + pad(d.device, 10) + '  ' + pad(d.method, 18) + '  ' + pad(d.cls, 12) + '  COMPLETED')
  }
  line('')
  footer()
}

function help() {
  el('t-muted', 'Available commands:')
  el('', '  sanitise      run a sanitise demo')
  el('', '  --dry-run     simulate without wiping anything')
  el('', '  report        show the certificate table')
  el('', '  ls            list files on the appliance')
  el('', '  whoami        identify the operator')
  el('', '  wipe --all    full wipe (demo)')
  el('', '  clear         clear the screen')
  el('', '  help          show this help')
}

function about() {
  el('', 'tScrub erases NVMe, SATA, and SCSI drives to NIST 800-88')
  el('', 'Clear, Purge, and Destroy — then writes a chain-of-custody')
  el('', 'report your auditors can actually read.')
}

function askConfirm() {
  pendingConfirm = true
  el('t-warn', '!! This would erase ALL drives on this machine.')
  el('t-warn', '   Type CONFIRM to proceed, or anything else to cancel.')
}

async function confirmed() {
  pendingConfirm = false
  el('t-err', 'Nice try — this is a demo. Nothing was actually wiped. 🙂')
  el('')
  await runDemo(true)
}

function handle(raw) {
  const cmd = raw.trim()
  if (cmd === '') return
  el('t-cmd', `operator@tscrub:~$ ${cmd}`)
  if (pendingConfirm) {
    if (cmd === 'CONFIRM') confirmed()
    else { pendingConfirm = false; el('t-muted', 'Cancelled.') }
    return
  }
  switch (cmd) {
    case 'help': help(); break
    case 'sanitise': case 'sanitize': case 'run': case 'demo': runDemo(); break
    case '--dry-run': case 'dry-run': case 'dry': runDemo(true); break
    case 'report': case 'cert': case 'certificate': showReport(); break
    case 'about': about(); break
    case 'whoami': el('', 'operator'); break
    case 'ls': el('', 'tscrub.lic   report.csv   tscrub.sh'); break
    case 'clear': case 'cls': clear(); break
    case 'wipe --all': case 'wipe all': askConfirm(); break
    default: el('t-err', `command not found: ${cmd} — type 'help'`)
  }
}

input.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    const value = input.value
    input.value = ''
    handle(value)
  }
})

term.addEventListener('click', () => input.focus())
if (rerun) rerun.addEventListener('click', () => { clear(); runDemo() })

runDemo()
})()
