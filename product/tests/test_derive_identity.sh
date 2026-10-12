#!/usr/bin/env bash
# Tests for derived device identity: system::derive_identity() in 00_bootstrap.sh.
# Machines with a placeholder SMBIOS serial (QEMU/KVM VMs, whitebox boards) get a
# stable, unique derived serial so they are visible in the dashboard instead of
# being rejected by /api/heartbeat as "Serial required.".
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
NET="$tmpdir/net"
BLK="$tmpdir/blk"
mkdir -p "$NET" "$BLK"

# Override the sysfs globs so the derivation runs without root.
export TS_DERIVE_NET_GLOB="$NET/*/address"
export TS_DERIVE_BLK_GLOB="$BLK/*/device/serial"

nic() {  # <iface> <mac>
    mkdir -p "$NET/$1"
    printf '%s\n' "$2" > "$NET/$1/address"
}
disk() {  # <name> <serial>
    mkdir -p "$BLK/$1/device"
    printf '%s\n' "$2" > "$BLK/$1/device/serial"
}
reset_ident() {
    SYS_SERIAL="N/A"; SYS_UUID="N/A"; SYS_SERIAL_SOURCE="dmi"; SYS_SERIAL_DMI="N/A"
}
clear_hw() { rm -rf "$NET" "$BLK"; mkdir -p "$NET" "$BLK"; }

# --- A real OEM serial is never touched -------------------------------------
SYS_SERIAL="ABC123"; SYS_UUID="4C4C4544-0036-5710-8032-B5C04F433633"
SYS_SERIAL_SOURCE="dmi"; SYS_SERIAL_DMI="ABC123"
system::derive_identity
t::assert_eq "ABC123" "$SYS_SERIAL" "real serial: left untouched"
t::assert_eq "4C4C4544-0036-5710-8032-B5C04F433633" "$SYS_UUID" "real serial: uuid untouched"
t::assert_eq "dmi" "$SYS_SERIAL_SOURCE" "real serial: source stays dmi"

# --- Placeholder serial + one NIC -> derived WB-<12hex> ----------------------
clear_hw; nic eth0 "aa:bb:cc:dd:ee:01"
reset_ident
system::derive_identity
id_a="$SYS_SERIAL"
t::check "placeholder + NIC: derived WB- format" '[[ "$id_a" =~ ^WB-[0-9A-F]{12}$ ]]'
t::assert_eq "derived" "$SYS_SERIAL_SOURCE" "placeholder + NIC: source is derived"
t::assert_eq "N/A" "$SYS_SERIAL_DMI" "placeholder + NIC: raw DMI value preserved"
t::check "placeholder + NIC: uuid minted (v5-shaped)" \
    '[[ "$SYS_UUID" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-a[0-9a-f]{3}-[0-9a-f]{12}$ ]]'
t::check "placeholder + NIC: uuid is not the QEMU sentinel" \
    '[[ "$SYS_UUID" != "03000200-0400-0500-0006-000700080009" ]]'

# Deterministic across runs.
reset_ident
system::derive_identity
t::assert_eq "$id_a" "$SYS_SERIAL" "placeholder + NIC: deterministic across runs"

# --- Every NIC contributes; loopback / all-zero MACs are ignored ------------
nic wlan0 "aa:bb:cc:dd:ee:02"
reset_ident; system::derive_identity
id_b="$SYS_SERIAL"
t::check "second NIC changes the identity" '[[ "$id_a" != "$id_b" ]]'

nic lo "00:00:00:00:00:00"
reset_ident; system::derive_identity
t::assert_eq "$id_b" "$SYS_SERIAL" "loopback / all-zero MAC ignored"

# Tunnel placeholders are NOT MACs: `sit0` reports the short all-zero string
# "00:00:00:00" (which is not the 6-octet loopback address, so a naive
# `!= 00:00:00:00:00:00` filter lets it through). Hashing it in would pollute
# the seed, and worse, would stop the disk fallback engaging on a NIC-less box.
nic sit0 "00:00:00:00"
reset_ident; system::derive_identity
t::assert_eq "$id_b" "$SYS_SERIAL" "tunnel placeholder (sit0) ignored"

# Two identical MACs (duplicate NIC records) must not change the identity.
nic wlan0 "aa:bb:cc:dd:ee:01"
reset_ident; system::derive_identity
t::assert_eq "$id_a" "$SYS_SERIAL" "duplicate MACs de-duplicated"

# --- Virtual placeholders only: no real NIC at all -------------------------
# Must fall through to the disk/placeholder path rather than hashing the
# zero strings in (which would give every NIC-less machine ONE shared id).
clear_hw; nic lo "00:00:00:00:00:00"; nic sit0 "00:00:00:00"
reset_ident; system::derive_identity
t::assert_eq "N/A" "$SYS_SERIAL" "no real NIC + no disks: stays N/A"
t::assert_eq "placeholder" "$SYS_SERIAL_SOURCE" "no real NIC + no disks: flagged placeholder"

# --- Disk serials are the fallback when there are no NICs -------------------
clear_hw; disk sda "S1DEMODISK0001"
reset_ident; system::derive_identity
id_disk="$SYS_SERIAL"
t::check "placeholder + disk only: derived WB- format" '[[ "$id_disk" =~ ^WB-[0-9A-F]{12}$ ]]'
t::assert_eq "derived" "$SYS_SERIAL_SOURCE" "placeholder + disk only: source is derived"
t::check "disk-only identity differs from NIC identity" '[[ "$id_disk" != "$id_a" ]]'

# NICs take priority over disks when both are present.
nic eth0 "aa:bb:cc:dd:ee:01"
reset_ident; system::derive_identity
t::assert_eq "$id_a" "$SYS_SERIAL" "NIC MACs take priority over disk serials"

# --- No stable tokens at all -> stays N/A, flagged as placeholder ------------
clear_hw
reset_ident; system::derive_identity
t::assert_eq "N/A" "$SYS_SERIAL" "no tokens: serial stays N/A"
t::assert_eq "N/A" "$SYS_UUID" "no tokens: uuid stays N/A"
t::assert_eq "placeholder" "$SYS_SERIAL_SOURCE" "no tokens: source is placeholder"

# --- A real UUID is preserved even when the serial is derived ---------------
clear_hw; nic eth0 "aa:bb:cc:dd:ee:01"
reset_ident
SYS_UUID="4C4C4544-0036-5710-8032-B5C04F433633"
system::derive_identity
t::assert_eq "4C4C4544-0036-5710-8032-B5C04F433633" "$SYS_UUID" "real uuid preserved on derived serial"

rm -rf "$tmpdir"
t::summary
