#!/usr/bin/env python3
"""830 G5 BIOS unlock: V5 patch — delete `UserCred` + staged credentials and
set `ClearSecurityOperation` = 1.

Recipe (2026-11-16):
- The BIOS admin password lives in the UEFI authenticated variable `UserCred`
  (GUID f66687ff-8cf3-4a19-b4ac-f5f0..., data starts with UTF-16 "AdminPW")
  inside the NVRAM store (signature aaf32c78-947b-439a-a180-2e144ec37792).
- Flip its state byte from 0x3F (added) to 0xFD (deleted); identity variables
  HP_MUD / FactoryConfig / BuildId stay intact, so no "BIOS Identity Data
  Needs to Be Recovered" screen appears.
- On SAT601-class revisions delete the staged `AuthNextCredential*` variables,
  otherwise the BIOS re-applies the staged credential on boot.
- Set `ClearSecurityOperation` = 1: on BIOS >= 01.27 the EC re-injects the
  password at boot after a UserCred-only patch (proven byte-for-byte by the
  afterflash dumps of 5CG8528JYH and 5CG8528GQT, 2026-11-16).
- The UserCred offset varies per unit -> always located by walking the store.

Usage:
    unlock_usercred.py INPUT.BIN [OUTPUT.BIN]

Input may include Xgpro's 272-byte "-Configuration-" trailer (auto-stripped).
Output defaults to <input>-UNLOCKED-V5.BIN (exactly 32 MiB).
"""

import struct
import sys

SIG = bytes.fromhex('782cf3aa7b949a43a1802e144ec37792')
DELETED = 0xFD


def parse_store(data, off):
    size = struct.unpack('<I', data[off + 0x10:off + 0x14])[0]
    p = off + 0x1C
    end = off + size
    entries = []
    while p + 0x3C <= end:
        if struct.unpack('<H', data[p:p + 2])[0] != 0x55AA:
            break
        state = data[p + 2]
        name_size = struct.unpack('<I', data[p + 0x24:p + 0x28])[0]
        data_size = struct.unpack('<I', data[p + 0x28:p + 0x2C])[0]
        name = data[p + 0x3C:p + 0x3C + name_size].decode('utf-16-le', 'replace')
        entries.append((p, state, name, data_size, name_size))
        p += 0x3C + name_size + data_size
        p = (p + 3) & ~3
    return entries


def main(argv):
    src = argv[1]
    out = argv[2] if len(argv) > 2 else src + '-UNLOCKED-V5.BIN'

    raw = open(src, 'rb').read()
    data = raw
    if len(raw) == 33554704:
        data = raw[:33554432]
        print('stripped 272-byte Xgpro trailer')

    assert data[0x10:0x14] == bytes.fromhex('5aa5f00f'), 'not an Intel SPI image'
    assert len(data) == 33554432, f'unexpected image size {len(data)}'

    stores = [i for i in range(len(data)) if data.startswith(SIG, i)]
    entries = []
    for s in stores:
        entries += parse_store(data, s)
    print(f'stores: {len(stores)}, entries: {len(entries)}')

    creds = [e for e in entries if 'UserCred' in e[2]]
    live = [e for e in creds if e[1] == 0x3F]
    if len(creds) != len(live):
        print(f'UserCred entries: {len(creds)} total, {len(live)} live (stale deleted copies ignored)')
    assert len(live) == 1, f'expected exactly one live UserCred, found {len(live)} of {len(creds)}'
    p, state, name, dsize, _ = live[0]

    bid = [e for e in entries if 'BuildId' in e[2]]
    if bid:
        bp, bst, bn, bds, bns = bid[0]
        print(f'BIOS BuildId: {data[bp + 0x3C + bns:bp + 0x3C + bns + bds].rstrip(chr(0).encode()).decode("utf-16-le", "replace")}')

    pol = [e for e in entries if 'AdminAtPowerOnAuthPolicy' in e[2]]
    if pol:
        pp, pst, pn, pds, pns = pol[0]
        pv = data[pp + 0x3C + pns]
        print(f'INFO: AdminAtPowerOnAuthPolicy is PRESENT (value {pv}).'
              f' This flag alone does NOT predict the outcome - on SOME units'
              f' the EC also holds the admin credential and the prompt returns'
              f' after flash. Flash and verify; if the password returns,'
              f' re-read the chip and send the dump.')

    auth = [e for e in entries if e[1] == 0x3F and e[2].startswith('AuthNextCredential')]
    if auth:
        print(f'AuthNextCredential* live entries to delete: {len(auth)}')
        for ap, ast, an, ad, ans in auth:
            print(f'  {an.rstrip(chr(0))!r} @0x{ap:X}')

    cs = [e for e in entries if e[2].rstrip('\x00') == 'ClearSecurityOperation']
    if cs:
        cp, cst, cn, cds, cns = cs[0]
        cs_off = cp + 0x3C + cns
        print(f'ClearSecurityOperation @0x{cp:X} data=0x{data[cs_off]:02X} -> 0x01')
    else:
        cs_off = None
        print('ClearSecurityOperation not found - EC clear request skipped')

    patched = bytearray(data)
    patched[p + 2] = DELETED
    for ap, ast, an, ad, ans in auth:
        patched[ap + 2] = DELETED
    if cs_off is not None:
        patched[cs_off] = 0x01
    open(out, 'wb').write(patched)

    v = open(out, 'rb').read()
    changed = [i for i in range(len(data)) if data[i] != v[i]]
    expected = sorted([p + 2] + [ap + 2 for ap, *_ in auth] + ([cs_off] if cs_off is not None else []))
    assert changed == expected, f'unexpected changed bytes: {[hex(i) for i in changed]} vs {[hex(i) for i in expected]}'
    print(f'wrote {out}: {len(changed)} byte(s) changed, UserCred deleted (0x{DELETED:02X}), ClearSecurityOperation=1')
    print('ME/identity untouched.')


if __name__ == '__main__':
    sys.exit(main(sys.argv))
