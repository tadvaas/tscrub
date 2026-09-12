import flowbite from 'flowbite/plugin'

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './*.html',
    './src/**/*.{html,js}',
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
          500: '#10b981',
          600: '#059669',
          700: '#047857'
        },
        ink: {
          900: '#0b1220',
          800: '#111827',
          700: '#1f2937'
        }
      }
    }
  },
  plugins: [flowbite]
}
