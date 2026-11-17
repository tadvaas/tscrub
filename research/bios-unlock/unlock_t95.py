#!/usr/bin/env python3
"""unlock_t95.py - V2 (store-level) password patch for HP ZBook Fury 15.6 G8
(family T95/21WW, 32MB W25Q256FV dumps).

This is the bench PROBE, not the finished unlock: it deletes the live
UserCred entry (state byte -> 0xFD) in every auth-variable store. On the
830 G5 this worked on BIOS <= 01.06 only; on >= 01.24 the EC mirror
re-injected UserCred byte-identical. Every T95 release contains the mirror
machinery, so this image is expected to be RE-INJECTED too - the afterflash
re-read is the experiment that proves whether T95 restores the credential.
See research/bios-unlock/27-nvram-blank-unlock.md and
29-t95-patching.md for context.

Usage: python unlock_t95.py <dump.bin> [output.bin]
"""
import struct
import sys

SIG = bytes.fromhex('782cf3aa7b949a43a1802e144ec37792')


def parse(data, off):
    """Yield (entry_offset, state, name, name_size, data_size) for a store."""
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


def find_stores(data):
    return [i for i in range(len(data)) if data.startswith(SIG, i)]


def main():
    src = sys.argv[1] if len(sys.argv) > 1 else 'BINs/W25Q256FV_SOIC8-CND2048Y34.BIN'
    dst = sys.argv[2] if len(sys.argv) > 2 else src.replace('.BIN', '-UNLOCKED-V2.BIN')
    d = bytearray(open(src, 'rb').read())
    if len(d) == 33554704:
        d = d[:33554432]  # drop programmer OOB tail
    if len(d) != 33554432:
        print('unexpected size', len(d), '- expected 33554432 (W25Q256FV)')
        sys.exit(1)

    stores = find_stores(d)
    if not stores:
        print('no auth variable store found')
        sys.exit(1)

    n_deleted = 0
    n_live_before = 0
    other = {}
    for s in stores:
        for p, st, name, ns, ds in parse(d, s):
            if name == 'UserCred':
                if st == 0x3F:
                    n_live_before += 1
                    d[p + 2] = 0xFD
                    n_deleted += 1
            elif 'AuthNext' in name or name.startswith('Beam'):
                other.setdefault(name, 0)
                other[name] += 1

    print('stores:', len(stores))
    print('live UserCred entries deleted:', n_deleted, '(of', n_live_before, 'live)')
    for k, v in sorted(other.items()):
        print('  left untouched:', k, 'x', v)

    open(dst, 'wb').write(bytes(d))
    print('wrote', dst)

    # verify
    v = open(dst, 'rb').read()
    still_live = 0
    for s in find_stores(v):
        for p, st, name, ns, ds in parse(v, s):
            if name == 'UserCred' and st == 0x3F:
                still_live += 1
    print('live UserCred after patch:', still_live)
    sys.exit(1 if still_live else 0)


if __name__ == '__main__':
    main()
