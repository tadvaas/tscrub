// Vite skips dot-directories when copying `public/`, so `/.well-known/…`
// (RFC 8615) files never reach dist. Copy them after the Vite build.
import { cpSync, existsSync, mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const src = fileURLToPath(new URL('../site/public/.well-known', import.meta.url))
const dst = fileURLToPath(new URL('../dist/.well-known', import.meta.url))

if (existsSync(src)) {
  mkdirSync(dst, { recursive: true })
  cpSync(src, dst, { recursive: true, force: true })
  console.log('Copied /.well-known/ -> dist')
} else {
  console.log('No site/public/.well-known to copy')
}
