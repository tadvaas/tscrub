#!/usr/bin/env python
"""Enumerate all variables in the S92 auth store with name + GUID + state."""
import sys, struct

def guid_str(b):
    if not any(b): return None
    return '%02X%02X%02X%02X-%02X%02X-%02X%02X-%02X%02X-%02X%02X%02X%02X%02X%02X' % (
        b[3],b[2],b[1],b[0],b[5],b[4],b[7],b[6],b[8],b[9],b[10],b[11],b[12],b[13],b[14],b[15])

def scan(path, base, size):
    d = open(path,'rb').read()
    end = base + size
    o = base
    entries = []
    while o + 0x3C < end:
        if d[o] == 0xFF:  # free space to end
            break
        if d[o] != 0xAA or d[o+1] != 0x55:
            o += 1
            continue
        state = d[o+2]
        name_size = int.from_bytes(d[o+0x24:o+0x28],'little')
        data_size = int.from_bytes(d[o+0x28:o+0x2C],'little')
        g = guid_str(d[o+0x2C:o+0x3C])
        name = d[o+0x3C:o+0x3C+name_size]
        try: nm = name.decode('utf-16-le','replace')
        except Exception: nm = repr(name)
        total = 0x3C + name_size + data_size
        total = (total + 7) & ~7
        entries.append((o, state, nm, g, data_size, total))
        o += total
    return entries

if __name__ == '__main__':
    path = sys.argv[1]
    base = int(sys.argv[2], 0)
    size = int(sys.argv[3], 0)
    for o, st, nm, g, ds, tot in scan(path, base, size):
        print('%08x state=%02x name=%-28s guid=%-37s data=%d' % (o, st, nm, g, ds))
