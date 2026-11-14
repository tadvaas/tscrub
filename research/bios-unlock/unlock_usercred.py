#!/usr/bin/env python3
"""830 G5 BIOS unlock: delete the HP `UserCred` NVRAM variable (one byte).

Confirmed 2026-11-14 on units 5CG8528HKL and 5CG8403V0S:
- The BIOS admin password lives in the UEFI authenticated variable `UserCred`
  (GUID f66687ff-8cf3-4a19-b4ac-f5f0..., data starts with UTF-16 "AdminPW")
  inside the NVRAM store (signature aaf32c78-947b-439a-a180-2e144ec37792).
- Flipping the variable's state byte from 0x3F (added) to 0xFD (deleted) removes
  the password while keeping HP_MUD / FactoryConfig / BuildId (identity) intact,
  so no "BIOS Identity Data Needs to Be Recovered" screen appears.
- The UserCred offset varies per unit -> always located by walking the store.

Usage:
    unlock_usercred.py INPUT.BIN [OUTPUT.BIN]

Input may include Xgpro's 272-byte "-Configuration-" trailer (auto-stripped).
Output defaults to <input>-UNLOCKED-V2.BIN (exactly 32 MiB).
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
        entries.append((p, state, name, data_size))
        p += 0x3C + name_size + data_size
        p = (p + 3) & ~3
    return entries


def main(argv):
    src = argv[1]
    out = argv[2] if len(argv) > 2 else src + '-UNLOCKED-V2.BIN'

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
    assert len(creds) == 1, f'expected exactly one UserCred, found {len(creds)}'
    p, state, name, dsize = creds[0]
    assert state == 0x3F, f'UserCred state 0x{state:02X}, expected 0x3F (already unlocked?)'
    print(f'UserCred @0x{p:X} data={dsize} B')

    mud = [e for e in entries if 'HP_MUD' in e[2]]
    print(f'HP_MUD entries: {len(mud)}')

    patched = bytearray(data)
    patched[p + 2] = DELETED
    open(out, 'wb').write(patched)

    v = open(out, 'rb').read()
    changed = [i for i in range(len(data)) if data[i] != v[i]]
    assert changed == [p + 2], f'unexpected changed bytes: {[hex(i) for i in changed]}'
    print(f'wrote {out}: 1 byte changed @0x{p + 2:X}, UserCred deleted (0x{DELETED:02X})')
    print('ME/identity untouched.')


if __name__ == '__main__':
    sys.exit(main(sys.argv))
