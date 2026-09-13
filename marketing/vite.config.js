import { defineConfig } from 'vite'
import { fileURLToPath, URL } from 'node:url'

const pages = {
  main: 'index.html',
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
  certify: 'certify.html'
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
