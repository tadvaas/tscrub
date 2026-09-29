#!/usr/bin/env python3
"""One-off dev tool: inject Tailwind `dark:` variants across marketing/site/**/*.html.

For every light utility in MAPPING, appends `dark:<same-variant-prefix><dark-twin>`.
Idempotent: tokens already carrying a `dark:` variant (or already followed by one)
are skipped. Already-dark sections (bg-ink-*, text-white, text-slate-100/200/300,
bg-grid-ink, ring-*, from/to gradients) are simply absent from MAPPING and so untouched.
"""
import glob
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
SITE = os.path.join(HERE, 'site')

# light utility -> dark twin (variant prefixes are preserved automatically)
MAPPING = {
    # surfaces & borders
    'bg-white/90': 'bg-ink-900/90',
    'bg-white': 'bg-ink-900',
    'bg-slate-50': 'bg-ink-800',
    'bg-slate-100': 'bg-ink-800',
    'bg-slate-200': 'bg-ink-700',
    'bg-slate-300': 'bg-ink-700',
    'border-slate-100': 'border-ink-800',
    'border-slate-200': 'border-ink-700',
    'border-slate-300': 'border-slate-700',
    'divide-slate-100': 'divide-ink-800',
    # body / heading text
    'text-slate-800': 'text-slate-200',
    'text-slate-700': 'text-slate-300',
    'text-slate-600': 'text-slate-400',
    'text-slate-500': 'text-slate-400',
    'text-slate-400': 'text-slate-500',
    'text-ink-900': 'text-white',
    'text-ink-800': 'text-slate-100',
    'text-ink-700': 'text-slate-200',
    # brand accents
    'text-brand-700': 'text-brand-400',
    'text-brand-600': 'text-brand-400',
    'bg-brand-50': 'bg-brand-900/30',
    'border-brand-600': 'border-brand-500',
    # badges
    'bg-red-100': 'bg-red-900/40',
    'bg-emerald-100': 'bg-emerald-900/40',
    'bg-amber-100': 'bg-amber-900/40',
    'bg-sky-100': 'bg-sky-900/40',
    'bg-blue-100': 'bg-blue-900/40',
    'text-red-700': 'text-red-400',
    'text-red-600': 'text-red-400',
    'text-emerald-700': 'text-emerald-400',
    'text-emerald-900': 'text-emerald-300',
    'text-amber-700': 'text-amber-400',
    'text-amber-900': 'text-amber-300',
    'text-sky-700': 'text-sky-400',
    'text-blue-700': 'text-blue-400',
    # typography prose
    'prose-slate': 'prose-invert',
}

TOKEN_ALTS = '|'.join(re.escape(t) for t in sorted(MAPPING, key=len, reverse=True))

PATTERN = re.compile(
    r'(?<![A-Za-z0-9_-])'
    r'(?P<pre>(?:[A-Za-z0-9_-]+:)*)'
    r'(?P<tok>' + TOKEN_ALTS + r')'
    r'(?![A-Za-z0-9_/-])'
    r'(?!\s+dark:)'
)


def repl(m):
    pre = m.group('pre')
    # skip if a `dark:` variant is already present in the prefix stack
    if pre and 'dark' in [s for s in pre.split(':') if s]:
        return m.group(0)
    tok = m.group('tok')
    return f"{pre}{tok} dark:{pre}{MAPPING[tok]}"


def main():
    files = sorted(glob.glob(os.path.join(SITE, '**', '*.html'), recursive=True))
    total = 0
    for f in files:
        with open(f, encoding='utf-8') as fh:
            src = fh.read()
        out, n = PATTERN.subn(repl, src)
        if n:
            with open(f, 'w', encoding='utf-8') as fh:
                fh.write(out)
            total += n
            print(f"{os.path.relpath(f, HERE)}: +{n}")
    print(f"\ntotal dark variants injected: {total}")


if __name__ == '__main__':
    main()
