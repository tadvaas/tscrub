import flowbite from 'flowbite/plugin'
import typography from '@tailwindcss/typography'

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'media',
  content: [
    './site/*.html',
    './site/dashboard/**/*.html',
    './site/resources/**/*.html',
    './site/src/**/*.{html,js}',
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
