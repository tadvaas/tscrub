import { defineConfig } from 'vite'
import { fileURLToPath, URL } from 'node:url'

const pages = {
  main: 'index.html',
  'getting-started': 'getting-started.html',
  how: 'how-it-works.html',
  compliance: 'compliance.html',
  compare: 'compare.html',
  pricing: 'pricing.html',
  faq: 'faq.html',
  download: 'download.html',
  privacy: 'privacy.html',
  terms: 'terms.html',
  docs: 'docs.html',
  contact: 'contact.html',
  certify: 'certify.html',
  login: 'login.html',
  register: 'register.html',
  dashboard: 'dashboard.html',
  'dashboard-certify': 'dashboard/certify.html',
  'dashboard-user': 'dashboard/user.html',
  'dashboard-licence': 'dashboard/licence.html',
  'dashboard-info': 'dashboard/info.html',
  'dashboard-reports': 'dashboard/reports.html',
  'dashboard-settings': 'dashboard/settings.html',
  admin: 'admin.html'
}

export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: Object.fromEntries(
        Object.entries(pages).map(([name, file]) => [
          name,
          fileURLToPath(new URL(file, import.meta.url))
        ])
      )
    }
  }
})
