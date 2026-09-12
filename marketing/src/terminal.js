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

const DRIVES = [
  { dev: 'nvme0n1', kind: 'NVMe SSD', method: 'NVMe Crypto Purge', cert: 'DESTRUCTION' },
  { dev: 'sda', kind: 'SATA HDD', method: 'ATA Enhanced Erase', cert: 'DESTRUCTION' },
  { dev: 'sdb', kind: 'SCSI SSD', method: 'nwipe Quick', cert: 'SANITISATION' }
]

function el(cls, text) {
  const node = document.createElement('div')
  if (cls) node.className = cls
  if (text != null) node.textContent = text
  output.appendChild(node)
  output.scrollTop = output.scrollHeight
  return node
}

function bar(filled, width = 18) {
  return '█'.repeat(filled) + '░'.repeat(Math.max(0, width - filled))
}

function clear() {
  output.innerHTML = ''
}

async function wipeDrive(d, dryRun) {
  const node = el('', `  ${d.dev.padEnd(9)} ${bar(0)}   0%  RUNNING`)
  const steps = dryRun ? 4 : 10
  const total = dryRun ? 900 : 2800
  for (let i = 1; i <= steps; i++) {
    await sleep(total / steps)
    const pct = Math.round((i / steps) * 100)
    const f = Math.round((i / steps) * 18)
    node.textContent = `  ${d.dev.padEnd(9)} ${bar(f)} ${String(pct).padStart(3)}%  ${pct >= 100 ? 'COMPLETED' : 'RUNNING'}`
  }
  node.className = 't-ok'
  node.textContent = `  ${d.dev.padEnd(9)} ${bar(18)} 100%  COMPLETED`
}

async function runDemo(dryRun = false) {
  if (busy) return
  busy = true
  pendingConfirm = false
  el('t-cmd', `$ tscrub${dryRun ? ' --dry-run' : ''}`)
  el('t-muted', 'tScrub v1.0 — verifiable disk sanitisation')
  el('')
  el('t-info', '→ Discovering drives…')
  await sleep(450)
  for (const d of DRIVES) el('t-ok', `  ${d.dev.padEnd(9)} ${d.kind.padEnd(11)} [found]`)
  el('')
  el('t-info', '→ Classifying capabilities…')
  await sleep(400)
  el('t-ok', '  nvme0n1  → NVMe Crypto Purge  (PURGE)')
  el('t-ok', '  sda      → ATA Enhanced Erase (PURGE)')
  el('t-ok', '  sdb      → nwipe Quick        (CLEAR)')
  el('')
  if (dryRun) el('t-warn', '*** DRY RUN — no wipe executed ***')
  el('t-info', '→ Sanitising…')
  for (const d of DRIVES) await wipeDrive(d, dryRun)
  el('')
  el('t-ok', '✓ Complete. Report written to /tscrub_COC-48213.csv')
  el('t-muted', '  Chain of custody ID: COC-48213')
  el('')
  busy = false
}

function showReport() {
  el('t-muted', '  DEVICE      METHOD               CERT           STATUS')
  el('t-muted', '  ─────────   ──────────────────   ────────────   ─────────')
  for (const d of DRIVES) {
    el('t-ok', `  ${d.dev.padEnd(10)}  ${d.method.padEnd(18)}  ${d.cert.padEnd(12)}  COMPLETED`)
  }
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
    case 'ls': el('', 'license.key   report.csv   tscrub.sh'); break
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
rerun.addEventListener('click', () => { clear(); runDemo() })

runDemo()
})()
