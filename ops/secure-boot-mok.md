# Secure Boot (shim + MOK) Runbook

How the tScrub appliance boots on UEFI machines with **Secure Boot enabled**,
and how to rebuild/sign the image.

## Concept

The appliance bootloader is not signed by Microsoft, so plain UEFI Secure Boot
rejects it ("Selected image did not authenticate"). We fix this with the
standard **shim + Machine Owner Key (MOK)** chain — the same mechanism Ubuntu,
Debian and Fedora use:

```
UEFI firmware (Secure Boot ON)
  └─ shimx64.efi        Microsoft-signed (trusted by the firmware)
       └─ grubx64.efi    signed with OUR MOK (enrolled by the user)
            └─ bzImage   signed with OUR MOK
```

On the **first** boot, shim can't yet verify `grubx64.efi`, so it launches
**MokManager**; the operator enrols our certificate once, and every later boot
is automatic.

## Key material

- **MOK private key:** `~/.tscrub-mok/mok.key` on the build host (mode 600). It
  signs *code* (GRUB + kernel) — **never** ship or commit it. Losing it means
  re-issuing + re-enrolling; leaking it lets anyone sign bootloaders trusted by
  every enrolled machine.
- **MOK certificate:** `~/.tscrub-mok/mok.crt` (PEM) + the DER form
  `ENROLL_THIS_KEY_IN_MOK_MANAGER.cer` that ships **on the ISO** for enrollment.
- This key is **separate** from the licence vendor key (`vendor.key`, Ed25519)
  and the PDF signing key (`sign.key`, X.509).

One-time generation (on the build host):

```bash
mkdir -p ~/.tscrub-mok && cd ~/.tscrub-mok
openssl genrsa -out mok.key 2048
openssl req -x509 -new -nodes -key mok.key -subj "/CN=tScrub Secure Boot Key/" -days 3650 -out mok.crt
openssl x509 -in mok.crt -outform DER -out ENROLL_THIS_KEY_IN_MOK_MANAGER.cer
chmod 600 mok.key
```

## Build integration

- `board/shredos/sign_secureboot.sh` — signs the **kernel** (`bzImage`) **and
  GRUB** (`grubx64.efi`) at build time, then installs the chain into BOTH the
  EFI system partition and the ISO9660 tree: `shimx64.efi` → `EFI/BOOT/bootx64.efi`,
  the freshly-signed GRUB → `EFI/BOOT/grubx64.efi`, plus `mmx64.efi` (MokManager)
  and `ENROLL_THIS_KEY_IN_MOK_MANAGER.cer`.
- GRUB gets an **SBAT section embedded by `grub-mkimage --sbat`** (see
  `boot/grub2/grub2.mk`, file `board/shredos/grub.sbat.csv`). **This is mandatory**:
  shim 15.x writes a default `SbatLevel` variable on first boot and then REFUSES to
  load a next-stage binary that has no `.sbat` section — even after the MOK is
  enrolled. Symptom of a missing SBAT: MokManager keeps reappearing on every
  reboot despite a successful enrolment. The unsigned GRUB is copied to
  `output/images/grubx64-unsigned.efi` by `grub2.mk` so the signing script can read
  it from a stable path (the `efi-part/EFI/BOOT/bootx64.efi` path is overwritten
  with shim on each build).
- `fs/iso9660/iso9660.mk` — calls the script from
  `ROOTFS_ISO9660_INSTALL_GRUB2_EFI` (both the GRUB2-EFI and BOTH variants) and
  the EFI system partition is 8 MB (`BR2_TARGET_ROOTFS_ISO9660_GRUB2_EFI_PARTITION_SIZE="8M"`,
  up from 3 MB, so shim + grubx64 + mmx64 + ia32 all fit).

### Prebuilt bootloaders (shim + MokManager only)

`shimx64.efi` (Microsoft-signed shim) and `mmx64.efi` (MokManager) are prebuilt
in `board/shredos/`. GRUB is **built and signed at build time** (with SBAT) — no
prebuilt `grubx64.efi`. Refresh shim/MokManager only when they change (rare):

```bash
cp -Lf /usr/lib/shim/shimx64.efi.signed ~/shredos.x86_64/board/shredos/shimx64.efi
cp -f /usr/lib/shim/mmx64.efi ~/shredos.x86_64/board/shredos/mmx64.efi
cp -f ~/.tscrub-mok/ENROLL_THIS_KEY_IN_MOK_MANAGER.cer ~/shredos.x86_64/board/shredos/
```

> Gotcha: `sbsign` (and earlier in-place signing experiments) can silently strip
> the signature from the prebuilt `board/shredos/{shimx64,mmx64,grubx64}.efi` —
> before a build, restore them from git: `git checkout -- board/shredos/{shimx64,mmx64,grubx64}.efi`.

Host requirements (one-time, root):

```bash
sudo apt-get install -y sbsigntool shim-signed
```

`sbsign` signs; `shim-signed` provides `/usr/lib/shim/shimx64.efi.signed` and
`/usr/lib/shim/mmx64.efi`.

## Rebuild + verify

```bash
cd ~/shredos.x86_64 && make   # sign_secureboot.sh runs during ISO assembly
```

Verify the artifacts:

```bash
# kernel is signed
sbverify --list output/images/bzImage
# EFI FAT inside the ISO contains the chain (shim as BOOTX64.EFI + signed GRUB + MokManager + cert)
7z l output/images/tscrub-*.iso | grep -E "efi.img|bootx64|grubx64|mmx64|ENROLL"
```

## User enrollment (first boot, per machine)

1. Boot the USB with Secure Boot **on**. Shim reports a verification failure and
   opens **MokManager** (blue screen).
2. Choose **Enroll MOK → Continue → Yes** (shim 15.x auto-finds
   `ENROLL_THIS_KEY_IN_MOK_MANAGER.cer` on the ESP — there is no "Enroll key from
   disk" step in this version).
3. Reboot. The appliance now boots normally with Secure Boot on.

> If MokManager reappears after a successful enrolment, the build is missing the
> **SBAT section** on `grubx64.efi` (see above) — re-download the current ISO.

Customers who prefer not to enrol can still **disable Secure Boot** (or use
CSM/Legacy boot) — the BIOS path is unaffected by all of this.

## Key rotation

If `mok.key` is compromised or expires, generate a new key/cert, rebuild, and
publish. Existing machines keep working (their enrolled MOK is not tied to this
keypair's lifetime), but they should enrol the new cert on the next major image
refresh. See `ops/vendor-key-rotation.md` for the analogous licence-key runbook.

## Notes / limitations

- **x64 only for now** — `shim-signed` ships no 32-bit shim on this host, so
  `bootia32.efi` stays unsigned; 32-bit UEFI machines still need Secure Boot off.
- The hybrid ISO's El Torito EFI image is what gets signed, so both "boot the
  ISO as a CD" and "dd to USB" cases are covered.
- Full Secure-Boot boot verification needs a Secure-Boot-enabled machine (or
  QEMU + OVMF with the MOK enrolled); the build-time `sbverify` check confirms
  signatures but not the firmware handshake.
