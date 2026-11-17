#!/usr/bin/env python
"""Find all AA55 variable entries in a store region and parse them."""
import sys, re, struct

def guid_str(b):
    return '%02X%02X%02X%02X-%02X%02X-%02X%02X-%02X%02X-%02X%02X%02X%02X%02X%02X' % (
        b[3],b[2],b[1],b[0],b[5],b[4],b[7],b[6],b[8],b[9],b[10],b[11],b[12],b[13],b[14],b[15])

def main():
    path, base, size = sys.argv[1], int(sys.argv[2],0), int(sys.argv[3],0)
    d = open(path,'rb').read()[base:base+size]
    for m in re.finditer(b'\xaa\x55', d):
        o = m.start()
        if o + 0x3C > len(d): continue
        state = d[o+2]
        if state not in (0x3f, 0xfd, 0x3c, 0x7f): continue
        ns = int.from_bytes(d[o+0x24:o+0x28],'little')
        ds = int.from_bytes(d[o+0x28:o+0x2C],'little')
        if ns > 4096 or ds > 0x100000: continue
        g = guid_str(d[o+0x2C:o+0x3C])
        name = d[o+0x3C:o+0x3C+ns].decode('utf-16-le','replace')
        tot = (0x3C + ns + ds + 3) & ~3
        print('%08x state=%02x name=%-28s guid=%s data=%d end=%08x' % (base+o, state, name, g, ds, base+o+tot))

if __name__ == '__main__':
    main()
