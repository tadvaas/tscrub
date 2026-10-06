#!/usr/bin/env bash
# Tests for the extended hardware inventory: 46_hardware.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"

# --- hardware::usb ----------------------------------------------------------
mkdir -p "$tmpdir/usb/usb1" "$tmpdir/usb/1-1" "$tmpdir/usb/1-2" "$tmpdir/usb/1-3" "$tmpdir/usb/1-4" "$tmpdir/usb/1-5"
# usb1 has no idVendor -> skipped; 1-1 is a Linux Foundation root hub -> skipped
printf '1d6b' > "$tmpdir/usb/1-1/idVendor"
printf '0003' > "$tmpdir/usb/1-1/idProduct"
printf 'Linux 6.18.0 xhci-hcd' > "$tmpdir/usb/1-1/manufacturer"
printf 'xHCI Host Controller' > "$tmpdir/usb/1-1/product"
# normal keyboard
printf '04d9' > "$tmpdir/usb/1-2/idVendor"
printf '1702' > "$tmpdir/usb/1-2/idProduct"
printf 'HP' > "$tmpdir/usb/1-2/manufacturer"
printf 'USB Slim Keyboard' > "$tmpdir/usb/1-2/product"
# webcam with a placeholder "Generic" manufacturer
printf '05c8' > "$tmpdir/usb/1-3/idVendor"
printf '03b1' > "$tmpdir/usb/1-3/idProduct"
printf 'Generic' > "$tmpdir/usb/1-3/manufacturer"
printf 'HP HD Camera' > "$tmpdir/usb/1-3/product"
# bare device (no strings) -> resolved from usb.ids
printf '8087' > "$tmpdir/usb/1-4/idVendor"
printf '0a2b' > "$tmpdir/usb/1-4/idProduct"
# non-root-hub "Host Controller" -> skipped
printf '1234' > "$tmpdir/usb/1-5/idVendor"
printf '5678' > "$tmpdir/usb/1-5/idProduct"
printf 'Foo' > "$tmpdir/usb/1-5/manufacturer"
printf 'Foo Host Controller' > "$tmpdir/usb/1-5/product"

printf '8087  Intel Corp.\n\t0a2b  Bluetooth wireless interface\n' > "$tmpdir/usb.ids"

SYS_USB_DIR="$tmpdir/usb" SYS_USB_IDS_FILE="$tmpdir/usb.ids" hardware::usb
t::assert_eq "04d9:1702 HP USB Slim Keyboard; 05c8:03b1 HP HD Camera; Intel Corp. Bluetooth wireless interface (8087:0a2b)" \
    "$SYS_USB_LIST" "usb: root hubs/host controllers skipped, Generic stripped, bare ID resolved"

rm -rf "$tmpdir/usb"; mkdir -p "$tmpdir/usb"
SYS_USB_DIR="$tmpdir/usb" hardware::usb
t::assert_eq "N/A" "$SYS_USB_LIST" "usb: no devices -> N/A"

# --- hardware::pci (fake lspci from the fakes bin) --------------------------
hardware::pci
t::assert_contains "$SYS_PCI_LIST" "Fake GPU" "pci: full lspci list captured"

# sysfs fallback when lspci is broken (e.g. missing libpci.so.3).
mkdir -p "$tmpdir/pci/0000:00:02.0" "$tmpdir/pci/0000:00:1f.3"
printf '0x030000' > "$tmpdir/pci/0000:00:02.0/class"
printf '0x8086' > "$tmpdir/pci/0000:00:02.0/vendor"
printf '0x1912' > "$tmpdir/pci/0000:00:02.0/device"
printf '0x040300' > "$tmpdir/pci/0000:00:1f.3/class"
printf '0x8086' > "$tmpdir/pci/0000:00:1f.3/vendor"
printf '0xa170' > "$tmpdir/pci/0000:00:1f.3/device"
FAKE_LSPCI_BROKEN=1 SYS_PCI_DIR="$tmpdir/pci" hardware::pci
t::assert_eq "0000:00:02.0 [030000] Display 8086:1912"$'\n'"0000:00:1f.3 [040300] Multimedia 8086:a170" \
    "$SYS_PCI_LIST" "pci: sysfs fallback when lspci broken"
unset FAKE_LSPCI_BROKEN

# --- hardware::smbios (fake dmidecode full dump) ----------------------------
hardware::smbios
t::assert_contains "$SYS_SMBIOS_RAW" "BIOS Information" "smbios: full dump captured"

# --- hardware::smbios_extras (Types 0/2/3/4/11/22/41) -----------------------
hardware::smbios_extras
t::assert_eq "U3E1" "$SYS_CPU_SOCKET" "smbios: cpu socket"
t::assert_eq "Core i5" "$SYS_CPU_FAMILY" "smbios: cpu family"
t::assert_eq "E9 06 08 00 FF FB EB BF" "$SYS_CPU_ID" "smbios: cpu id"
t::assert_eq "0.8 V" "$SYS_CPU_VOLTAGE" "smbios: cpu voltage"
t::assert_eq "4.0" "$SYS_BIOS_REV" "smbios: bios revision"
t::assert_eq "4.83" "$SYS_BIOS_FW_REV" "smbios: firmware revision"
t::assert_eq "Not Present" "$SYS_CHASSIS_LOCK" "smbios: chassis lock"
t::assert_eq "Boot: Safe · Power: Safe · Thermal: Safe · Security: None" "$SYS_CHASSIS_STATE" "smbios: chassis state"
t::assert_eq "Onboard IGD · Video · 0000:00:02.0" "$SYS_ONBOARD_DEVICES" "smbios: onboard devices"
t::assert_eq "FBYTE#3X476J6S; BUILDID#17WWCSBT602#SABU#DABU; EDK2_1" "$SYS_OEM_STRINGS" "smbios: oem strings"
t::assert_eq "SS03050XL" "$SYS_BATTERY_MODEL" "smbios: battery model"
t::assert_eq "LION" "$SYS_BATTERY_CHEM" "smbios: battery chemistry"

# --- hardware::interfaces ----------------------------------------------------
mkdir -p "$tmpdir/net/eth0/device" "$tmpdir/net/lo" "$tmpdir/net/sit0"
printf '00:11:22:33:44:55' > "$tmpdir/net/eth0/address"
printf 'up' > "$tmpdir/net/eth0/operstate"
printf 'DRIVER=e1000e\n' > "$tmpdir/net/eth0/device/uevent"
printf '00:00:00:00:00:00' > "$tmpdir/net/lo/address"
printf 'unknown' > "$tmpdir/net/lo/operstate"
printf '00:00:00:00' > "$tmpdir/net/sit0/address"
printf 'unknown' > "$tmpdir/net/sit0/operstate"
SYS_NET_DIR="$tmpdir/net" hardware::interfaces
t::assert_eq "eth0 · 00:11:22:33:44:55 · up · e1000e" \
    "$SYS_NET_INTERFACES" "interfaces: physical NIC kept, lo/sit0 skipped"

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
mkdir -p "$tmpdir/no-usb" "$tmpdir/no-pci"
printf 'I: Bus=0003 Vendor=04d9 Product=1702 Version=0110\nN: Name="HID 04d9:1702"\n' > "$tmpdir/input"
printf ' 0 [HDMI      ]: HDA-Intel - HDA Intel PCH\n 1 [PCH       ]: HDA-Intel - HDA PCH\n' > "$tmpdir/asound"
touch "$tmpdir/video0"
SYS_INPUT_DEVICES_FILE="$tmpdir/input" SYS_ASOUND_CARDS_FILE="$tmpdir/asound" \
SYS_VIDEO_GLOB="$tmpdir/video0" SYS_IIO_DIR="$tmpdir/no-iio" \
SYS_USB_DIR="$tmpdir/no-usb" SYS_PCI_DIR="$tmpdir/no-pci" hardware::peripherals
t::assert_eq "webcam:1; touchscreen:0; fingerprint:0; accelerometer:0; audio:1" \
    "$SYS_PERIPHERALS" "peripherals: webcam node + audio ALSA, no touch/fp/accel"

printf 'N: Name="Goodix Touchscreen"\n' >> "$tmpdir/input"
printf 'N: Name="SYNA8004 Fingerprint"\n' >> "$tmpdir/input"
mkdir -p "$tmpdir/iio/device0"
SYS_INPUT_DEVICES_FILE="$tmpdir/input" SYS_ASOUND_CARDS_FILE="$tmpdir/asound" \
SYS_VIDEO_GLOB="$tmpdir/video0" SYS_IIO_DIR="$tmpdir/iio" \
SYS_USB_DIR="$tmpdir/no-usb" SYS_PCI_DIR="$tmpdir/no-pci" hardware::peripherals
t::assert_eq "webcam:1; touchscreen:1; fingerprint:1; accelerometer:1; audio:1" \
    "$SYS_PERIPHERALS" "peripherals: touchscreen + fingerprint + accel detected"

# Webcam via USB video interface class + audio via PCI class (no /dev/video, no ALSA).
mkdir -p "$tmpdir/usb-cam/1-9/1-9:1.0" "$tmpdir/pci-audio/0000:00:1f.3"
printf 'ef' > "$tmpdir/usb-cam/1-9/bDeviceClass"
printf 'HP HD Camera' > "$tmpdir/usb-cam/1-9/product"
printf '0e' > "$tmpdir/usb-cam/1-9/1-9:1.0/bInterfaceClass"
printf '0x040300' > "$tmpdir/pci-audio/0000:00:1f.3/class"
SYS_INPUT_DEVICES_FILE="$tmpdir/no-input" SYS_ASOUND_CARDS_FILE="$tmpdir/no-asound" \
SYS_VIDEO_GLOB="$tmpdir/no-video*" SYS_IIO_DIR="$tmpdir/no-iio" \
SYS_USB_DIR="$tmpdir/usb-cam" SYS_PCI_DIR="$tmpdir/pci-audio" hardware::peripherals
t::assert_eq "webcam:1; touchscreen:0; fingerprint:0; accelerometer:0; audio:1" \
    "$SYS_PERIPHERALS" "peripherals: webcam via USB iface class + audio via PCI class"

# Top-level USB interface dirs (e.g. root hub interfaces 1-0:1.0, 2-0:1.0, …)
# have no bDeviceClass/product files. They must be skipped silently — the old
# code emitted "No such file or directory" redirect errors for every one.
mkdir -p "$tmpdir/usb-iface/1-0/1-0:1.0" "$tmpdir/usb-iface/1-0:1.0"
printf '09' > "$tmpdir/usb-iface/1-0/bDeviceClass"
printf 'xHCI Host Controller' > "$tmpdir/usb-iface/1-0/product"
printf '09' > "$tmpdir/usb-iface/1-0/1-0:1.0/bInterfaceClass"
SYS_INPUT_DEVICES_FILE="$tmpdir/no-input" SYS_ASOUND_CARDS_FILE="$tmpdir/no-asound" \
SYS_VIDEO_GLOB="$tmpdir/no-video*" SYS_IIO_DIR="$tmpdir/no-iio" \
SYS_USB_DIR="$tmpdir/usb-iface" SYS_PCI_DIR="$tmpdir/no-pci" \
hardware::peripherals 2>"$tmpdir/periph-err"
t::assert_eq "" "$(<"$tmpdir/periph-err")" "peripherals: no redirect errors for USB interface dirs"
t::assert_eq "webcam:0; touchscreen:0; fingerprint:0; accelerometer:0; audio:0" \
    "$SYS_PERIPHERALS" "peripherals: hub (class 09) not webcam or audio"

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
