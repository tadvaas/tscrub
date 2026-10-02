#!/usr/bin/env python3
"""
Patch the BIOS "hidden sectors" field in the appended WinPE FAT32 partition of a
hybrid tScrub ISO, so Windows bootmgr (loaded via isolinux chain.c32 -> VBR)
can read its BCD/boot.wim at the correct absolute LBA.

Usage: python3 patch_winpe_hidden_sectors.py <iso>
"""
import struct
import sys


def main(iso_path: str) -> None:
    with open(iso_path, "rb") as f:
        f.seek(0x1BE + 2 * 16)  # MBR partition entry #3 (extra.vfat)
        entry = f.read(16)
    ptype = entry[4]
    start_lba = struct.unpack("<I", entry[8:12])[0]
    sectors = struct.unpack("<I", entry[12:16])[0]
    if start_lba == 0:
        raise SystemExit("ERROR: partition 3 has start LBA 0 (missing MBR entry?)")

    off = start_lba * 512 + 0x1C  # FAT32 BPB 'hidden sectors' field
    with open(iso_path, "r+b") as f:
        f.seek(off)
        f.write(struct.pack("<I", start_lba))

    print(
        f"partition 3: type=0x{ptype:02x} start_lba={start_lba} "
        f"sectors={sectors} -> hidden_sectors patched @ byte {off}"
    )


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    main(sys.argv[1])
