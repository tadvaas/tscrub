#!/usr/bin/env python3
"""unlock_s92_v3.py - V3 (empty-live-UserCred) patch for HP ZBook Fury 15 G7
(S92/20WW) dumps.

Rationale (doc 30): V2 (delete UserCred) FAILED on CND14004KD - the EC mirror
re-injected a NEW live UserCred at boot (afterflash dump proved it). But the
830 G5 precedent shows mirror-era code neither re-injects nor validates when a
LIVE UserCred with no credential records exists (KEEPBB empty skeleton stayed
empty through a BIOS update to mirror-era code). The unlocked sibling
CND2048Y34 (T95, same 4544B UserCred format) provides the canonical
"unlocked" blob - only 21 bytes differ from the locked KD blob (the AdminPW
record and record-presence flags).

This patch replaces the UserCred CONTENT (state stays LIVE 0x3F) with the
unlocked unit's blob in every auth store copy.

Usage: python unlock_s92_v3.py <locked.bin> <unlocked-template.bin> [out.bin]
"""
import struct
import sys

SIG = bytes.fromhex('782cf3aa7b949a43a1802e144ec37792')


def parse(data, off):
    size = struct.unpack('<I', data[off + 0x10:off + 0x14])[0]
    p, end = off + 0x1C, off + size
    out = []
    while p + 0x3C <= end:
        if struct.unpack('<H', data[p:p + 2])[0] != 0x55AA:
            break
        st = data[p + 2]
        ns = struct.unpack('<I', data[p + 0x24:p + 0x28])[0]
        ds = struct.unpack('<I', data[p + 0x28:p + 0x2C])[0]
        name = data[p + 0x3C:p + 0x3C + ns].decode('utf-16-le', 'replace').rstrip('\x00')
        out.append((p, st, name, ns, ds))
        p += 0x3C + ns + ds
        p = (p + 3) & ~3
    return out


def all_entries(data):
    out = []
    for i in range(len(data)):
        if data.startswith(SIG, i):
            out.extend(parse(data, i))
    return out


def get_usercred_blob(data, state=None):
    for p, st, name, ns, ds in all_entries(data):
        if name == 'UserCred' and (state is None or st == state):
            return data[p + 0x3C + ns:p + 0x3C + ns + ds]
    return None


def main():
    locked_fn = sys.argv[1]
    tmpl_fn = sys.argv[2]
    dst = sys.argv[3] if len(sys.argv) > 3 else locked_fn.replace('.BIN', '-UNLOCKED-V3.BIN')

    locked = bytearray(open(locked_fn, 'rb').read())
    if len(locked) == 33554704:
        locked = locked[:33554432]
    tmpl = open(tmpl_fn, 'rb').read()
    if len(tmpl) == 33554704:
        tmpl = tmpl[:33554432]

    tmpl_blob = get_usercred_blob(tmpl, 0x3F)
    if tmpl_blob is None or len(tmpl_blob) != 4544:
        print('unlocked template has no live 4544B UserCred:', tmpl_blob and len(tmpl_blob))
        sys.exit(1)
    print('template UserCred blob: %d bytes' % len(tmpl_blob))

    n = 0
    for p, st, name, ns, ds in all_entries(locked):
        if name == 'UserCred':
            off = p + 0x3C + ns
            locked[off:off + ds] = tmpl_blob
            locked[p + 2] = 0x3F
            n += 1
    print('UserCred entries rewritten:', n)

    open(dst, 'wb').write(bytes(locked))
    print('wrote', dst)

    # verify
    v = open(dst, 'rb').read()
    bad = 0
    seen = 0
    for p, st, name, ns, ds in all_entries(v):
        if name == 'UserCred':
            seen += 1
            blob = v[p + 0x3C + ns:p + 0x3C + ns + ds]
            if st != 0x3F or blob != tmpl_blob:
                bad += 1
    print('UserCred entries after patch:', seen, '- mismatches:', bad)
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
