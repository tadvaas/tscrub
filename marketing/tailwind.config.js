import flowbite from 'flowbite/plugin'
import typography from '@tailwindcss/typography'

/** @type {import('tailwindcss').Config} */
export default {
  // Class-based so the dashboard can offer a persisted toggle. The bootstrap
  // script injected by vite.config.js sets `.dark` before first paint, using
  // the saved choice or the OS preference.
  darkMode: 'class',
  content: [
    './site/*.html',
    './site/dashboard/**/*.html',
    './site/resources/**/*.html',
    './site/src/**/*.{html,js}',
    // The dashboard's own JS assets build DOM with Tailwind classes (the attested-record
    // modals and their icon buttons live in site/public/assets/manual-assets.js). Without
    // this glob, any class used ONLY there is never generated — it silently renders
    // unstyled if it does not also happen to appear in an HTML file. h-7/w-7 and gap-1.5
    // were missing for exactly that reason.
    './site/public/**/*.js',
    './node_modules/flowbite/**/*.js'
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace']
      },
      colors: {
        // "Sanitized green" accent — mirrors the tool's completed/green theme.
        brand: {
          50: '#ecfdf5',
          100: '#d1fae5',
          400: '#34d399',
          500: '#10b981',
          600: '#059669',
          700: '#047857',
          800: '#065f46',
          900: '#064e3b'
        },
        ink: {
          900: '#0b1220',
          800: '#111827',
          700: '#1f2937'
        }
      },
      keyframes: {
        // Sweeping segment for an indeterminate progress bar — the element is
        // w-1/3 inside an overflow-hidden track, so translateX(-100%) parks it
        // fully off the left edge and translateX(400%) sweeps it off the right.
        indeterminate: {
          '0%':   { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(400%)' }
        }
      },
      animation: {
        indeterminate: 'indeterminate 1.5s linear infinite'
      }
    }
  },
  plugins: [flowbite, typography]
}
