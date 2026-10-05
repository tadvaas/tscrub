#!/usr/bin/env bash
# Tests for the extended hardware inventory: 46_hardware.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"

# --- hardware::usb ----------------------------------------------------------
mkdir -p "$tmpdir/usb/usb1" "$tmpdir/usb/1-1" "$tmpdir/usb/1-2"
# root hub (usb1) has no idVendor -> skipped
printf '1d6b' > "$tmpdir/usb/1-1/idVendor"
printf '0003' > "$tmpdir/usb/1-1/idProduct"
printf 'Linux Foundation' > "$tmpdir/usb/1-1/manufacturer"
printf '3.0 root hub' > "$tmpdir/usb/1-1/product"
printf '04d9' > "$tmpdir/usb/1-2/idVendor"
printf '1702' > "$tmpdir/usb/1-2/idProduct"
printf 'HP' > "$tmpdir/usb/1-2/manufacturer"
printf 'USB Slim Keyboard' > "$tmpdir/usb/1-2/product"
SYS_USB_DIR="$tmpdir/usb" hardware::usb
t::assert_eq "1d6b:0003 Linux Foundation 3.0 root hub; 04d9:1702 HP USB Slim Keyboard" \
    "$SYS_USB_LIST" "usb: two devices, root hub skipped"

rm -rf "$tmpdir/usb"; mkdir -p "$tmpdir/usb"
SYS_USB_DIR="$tmpdir/usb" hardware::usb
t::assert_eq "N/A" "$SYS_USB_LIST" "usb: no devices -> N/A"

# --- hardware::pci (fake lspci from the fakes bin) --------------------------
hardware::pci
t::assert_contains "$SYS_PCI_LIST" "Fake GPU" "pci: full lspci list captured"

# --- hardware::smbios (fake dmidecode full dump) ----------------------------
hardware::smbios
t::assert_contains "$SYS_SMBIOS_RAW" "BIOS Information" "smbios: full dump captured"

# --- hardware::interfaces ----------------------------------------------------
mkdir -p "$tmpdir/net/eth0/device" "$tmpdir/net/lo"
printf '00:11:22:33:44:55' > "$tmpdir/net/eth0/address"
printf 'up' > "$tmpdir/net/eth0/operstate"
printf 'DRIVER=e1000e\n' > "$tmpdir/net/eth0/device/uevent"
printf '00:00:00:00:00:00' > "$tmpdir/net/lo/address"
printf 'unknown' > "$tmpdir/net/lo/operstate"
SYS_NET_DIR="$tmpdir/net" hardware::interfaces
t::assert_eq "eth0 · 00:11:22:33:44:55 · up · e1000e; lo · 00:00:00:00:00:00 · unknown" \
    "$SYS_NET_INTERFACES" "interfaces: name/mac/state/driver per NIC"

rm -rf "$tmpdir/net"; mkdir -p "$tmpdir/net"
SYS_NET_DIR="$tmpdir/net" hardware::interfaces
t::assert_eq "N/A" "$SYS_NET_INTERFACES" "interfaces: none -> N/A"

# --- hardware::uefi_boot -----------------------------------------------------
mkdir -p "$tmpdir/efivars"
printf '\x07\x00\x00\x00Windows Boot Manager' > "$tmpdir/efivars/Boot0001-8be4df61-93ca-11d2-aa0d-00e098032b8c"
printf '\x07\x00\x00\x00ubuntu' > "$tmpdir/efivars/Boot0002-8be4df61-93ca-11d2-aa0d-00e098032b8c"
SYS_EFIVARS_DIR="$tmpdir/efivars" hardware::uefi_boot
t::assert_eq "Boot0001: Windows Boot Manager; Boot0002: ubuntu" "$SYS_UEFI_BOOT" \
    "uefi: boot entries with UTF-16 descriptions decoded"

SYS_EFIVARS_DIR="$tmpdir/no-efi" hardware::uefi_boot
t::assert_eq "N/A" "$SYS_UEFI_BOOT" "uefi: no efivars -> N/A"

# --- hardware::peripherals ---------------------------------------------------
printf 'I: Bus=0003 Vendor=04d9 Product=1702 Version=0110\nN: Name="HID 04d9:1702"\n' > "$tmpdir/input"
printf ' 0 [HDMI      ]: HDA-Intel - HDA Intel PCH\n 1 [PCH       ]: HDA-Intel - HDA PCH\n' > "$tmpdir/asound"
touch "$tmpdir/video0"
SYS_INPUT_DEVICES_FILE="$tmpdir/input" SYS_ASOUND_CARDS_FILE="$tmpdir/asound" \
SYS_VIDEO_GLOB="$tmpdir/video0" SYS_IIO_DIR="$tmpdir/no-iio" hardware::peripherals
t::assert_eq "webcam:1; touchscreen:0; fingerprint:0; accelerometer:0; audio:2" \
    "$SYS_PERIPHERALS" "peripherals: webcam + 2 audio codecs, no touch/fp/accel"

printf 'N: Name="Goodix Touchscreen"\n' >> "$tmpdir/input"
printf 'N: Name="SYNA8004 Fingerprint"\n' >> "$tmpdir/input"
mkdir -p "$tmpdir/iio/device0"
SYS_INPUT_DEVICES_FILE="$tmpdir/input" SYS_ASOUND_CARDS_FILE="$tmpdir/asound" \
SYS_VIDEO_GLOB="$tmpdir/video0" SYS_IIO_DIR="$tmpdir/iio" hardware::peripherals
t::assert_eq "webcam:1; touchscreen:1; fingerprint:1; accelerometer:1; audio:2" \
    "$SYS_PERIPHERALS" "peripherals: touchscreen + fingerprint + accel detected"

# --- hardware::lockdown (derived from per-drive state) -----------------------
devices=(sda nvme0n1)
declare -A opal_locked devrow
opal_locked[sda]="NO";  devrow[sda.class]="PURGE"
opal_locked[nvme0n1]="NO"; devrow[nvme0n1.class]="CLEAR"
hardware::lockdown
t::assert_eq "0" "$SYS_BIOS_LOCKDOWN" "lockdown: clean drives -> 0"

opal_locked[sda]="YES"
hardware::lockdown
t::assert_eq "1" "$SYS_BIOS_LOCKDOWN" "lockdown: SED-locked drive -> 1"

opal_locked[sda]="NO"; devrow[sda.class]="FROZEN"
hardware::lockdown
t::assert_eq "1" "$SYS_BIOS_LOCKDOWN" "lockdown: still-frozen drive -> 1"

rm -rf "$tmpdir"
t::summary
