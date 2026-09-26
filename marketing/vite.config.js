import { defineConfig } from 'vite'
import { fileURLToPath, URL } from 'node:url'

const pages = {
  main: 'index.html',
  'getting-started': 'getting-started.html',
  compliance: 'compliance.html',
  compare: 'compare.html',
  pricing: 'pricing.html',
  faq: 'faq.html',
  download: 'download.html',
  privacy: 'privacy.html',
  terms: 'terms.html',
  docs: 'docs.html',
  contact: 'contact.html',
  resources: 'resources.html',
  'resources-standards': 'resources/standards.html',
  'resources-drive-types': 'resources/drive-types.html',
  'resources-wipe-methods': 'resources/wipe-methods.html',
  'resources-certificates': 'resources/certificates.html',
  'resources-buyers-guide': 'resources/buyers-guide.html',
  'resources-nvme-erase': 'resources/nvme-erase.html',
  'resources-ssd-erase': 'resources/ssd-erase.html',
  'resources-hdd-erase': 'resources/hdd-erase.html',
  'resources-sas-erase': 'resources/sas-erase.html',
  'resources-psid-revert': 'resources/psid-revert.html',
  'resources-block-sid': 'resources/block-sid.html',
  'resources-secure-vs-enhanced-erase': 'resources/secure-vs-enhanced-erase.html',
  'resources-crypto-vs-block-erase': 'resources/crypto-vs-block-erase.html',
  'resources-software-overwrite': 'resources/software-overwrite.html',
  'resources-degaussing-destruction': 'resources/degaussing-destruction.html',
  'resources-nist-800-88': 'resources/nist-800-88.html',
  'resources-gdpr-data-erasure': 'resources/gdpr-data-erasure.html',
  'resources-hipaa-data-disposal': 'resources/hipaa-data-disposal.html',
  'resources-iso-27001-data-erasure': 'resources/iso-27001-data-erasure.html',
  'resources-chain-of-custody': 'resources/chain-of-custody.html',
  'resources-report-signing': 'resources/report-signing.html',
  'resources-verify-certificate': 'resources/verify-certificate.html',
  'resources-smart-audit-evidence': 'resources/smart-audit-evidence.html',
  'resources-dban-alternatives': 'resources/dban-alternatives.html',
  'resources-open-source-vs-commercial': 'resources/open-source-vs-commercial.html',
  'resources-itad-guide': 'resources/itad-guide.html',
  'resources-data-centre-decommissioning': 'resources/data-centre-decommissioning.html',
  certify: 'certify.html',
  login: 'login.html',
  register: 'register.html',
  dashboard: 'dashboard.html',
  'dashboard-licence': 'dashboard/licence.html',
  'dashboard-reports': 'dashboard/reports.html',
  'dashboard-certificates': 'dashboard/certificates.html',
  'dashboard-account': 'dashboard/account.html',
  'dashboard-licences': 'dashboard/licences.html',
  'dashboard-billing': 'dashboard/billing.html',
  'dashboard-index': 'dashboard/index.html',
  admin: 'admin.html'
}

export default defineConfig({
  root: 'site',
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    rollupOptions: {
      input: Object.fromEntries(
        Object.entries(pages).map(([name, file]) => [
          name,
          fileURLToPath(new URL('site/' + file, import.meta.url))
        ])
      )
    }
  }
})
