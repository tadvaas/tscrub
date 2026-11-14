#!/usr/bin/env python3
"""Extract ACPI tables (DSDT/SSDT/...) out of a full SPI flash dump.

The BIOS region of an HP ROM image carries the ACPI tables verbatim, so we can
recover the AML that implements the WMIB (HP WMI BIOS) methods and disassemble it
with `iasl -d` to learn exactly what buffer format the firmware expects.

Usage: extract_acpi.py <dump.bin> <outdir>
"""

import os
import sys

SIGS = (b"DSDT", b"SSDT", b"FACP", b"APIC", b"HPET", b"FPDT", b"BGRT", b"TCPA",
        b"MSDM", b"DMAR", b"WSMT", b"UEFI", b"ASF!", b"SLIC", b"MSCT")


def table_length(buf: bytes, off: int) -> tuple[str, int, bool]:
    """Return (signature, length, checksum_ok) for a candidate ACPI header."""
    if off + 36 > len(buf):
        return "", 0, False
    sig = buf[off:off + 4]
    length = int.from_bytes(buf[off + 4:off + 8], "little")
    if not (36 <= length <= 0x1000000) or off + length > len(buf) + 0:
        return "", 0, False
    if off + length > len(buf):
        return "", 0, False
    csum = sum(buf[off:off + length]) & 0xFF
    return sig.decode("ascii", "replace"), length, csum == 0


def main() -> int:
    if len(sys.argv) != 3:
        print(__doc__)
        return 2
    dump_path, outdir = sys.argv[1], sys.argv[2]
    buf = open(dump_path, "rb").read()
    os.makedirs(outdir, exist_ok=True)
    print(f"dump: {dump_path} ({len(buf):,} bytes)")

    found = []
    for sig in SIGS:
        start = 0
        while True:
            off = buf.find(sig, start)
            if off < 0:
                break
            start = off + 1
            s, length, ok = table_length(buf, off)
            if s != sig.decode():
                continue
            oemid = buf[off + 10:off + 16].decode("ascii", "replace").strip("\x00 ")
            oemtable = buf[off + 16:off + 24].decode("ascii", "replace").strip("\x00 ")
            rev = buf[off + 8]
            found.append((off, s, length, ok, oemid, oemtable, rev))

    # HP ships many SSDTs; dedupe identical tables (a dump often holds copies).
    print(f"\n{'offset':>10}  {'sig':4} {'len':>8}  {'ck':2}  oem")
    seen = {}
    written = []
    for off, s, length, ok, oemid, oemtable, rev in found:
        data = buf[off:off + length]
        digest = hash(data)
        dup = digest in seen
        print(f"{off:#010x}  {s:4} {length:>8}  {'ok' if ok else '--'}  {oemid} / {oemtable} r{rev}"
              + ("   (duplicate)" if dup else ""))
        if not ok or dup:
            continue
        seen[digest] = off
        # AML bytecode only lives in DSDT/SSDT; the rest are fixed structs but
        # harmless to write out.
        name = f"{s}_{oemid.strip() or 'x'}_{off:#x}.aml".replace(" ", "")
        out = os.path.join(outdir, name)
        with open(out, "wb") as fh:
            fh.write(data)
        written.append(out)

    print(f"\nwrote {len(written)} table(s) to {outdir}")
    for w in written:
        print("  " + w)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
