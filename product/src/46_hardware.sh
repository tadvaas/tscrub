# =============================================================================
# HARDWARE INVENTORY (extended capture)
# =============================================================================
#
# Collects the "report doubles as an asset record" extras beyond the existing
# system::gather_info fields: full PCI list, USB devices, full SMBIOS dump,
# per-interface network detail, UEFI boot entries, peripheral presence, and a
# derived "BIOS lockdown suspected" flag.
#
# Every source is env-overridable for unit-testing (mirrors battery::capture /
# display::capture), and every external tool is optional (graceful N/A).
#
# Big blobs (SMBIOS, PCI, USB) are JSON-only — they never enter the report CSV.

# --- configuration / test overrides ------------------------------------------
SYS_USB_DIR="${SYS_USB_DIR:-/sys/bus/usb/devices}"
SYS_NET_DIR="${SYS_NET_DIR:-/sys/class/net}"
SYS_EFIVARS_DIR="${SYS_EFIVARS_DIR:-/sys/firmware/efi/efivars}"
SYS_IIO_DIR="${SYS_IIO_DIR:-/sys/bus/iio/devices}"
SYS_INPUT_DEVICES_FILE="${SYS_INPUT_DEVICES_FILE:-/proc/bus/input/devices}"
SYS_ASOUND_CARDS_FILE="${SYS_ASOUND_CARDS_FILE:-/proc/asound/cards}"
SYS_VIDEO_GLOB="${SYS_VIDEO_GLOB:-/dev/video*}"

# --- state -------------------------------------------------------------------
SYS_USB_LIST="N/A"
SYS_PCI_LIST="N/A"
SYS_SMBIOS_RAW=""
SYS_NET_INTERFACES="N/A"
SYS_UEFI_BOOT="N/A"
SYS_PERIPHERALS=""
SYS_BIOS_LOCKDOWN=0

# Collapse whitespace + strip commas + trim, so a value never breaks the JSON
# or the report CSV. Prints the cleaned string.
hardware::_clean() {
    printf '%s' "${1:-}" | tr -d ',' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]][[:space:]]*/ /g'
}

# USB devices: "vendor:product  manufacturer  product" per non-root-hub device.
hardware::usb() {
    local d vid pid prod mfr entry list=""
    for d in "$SYS_USB_DIR"/*/; do
        [[ -r "${d}idVendor" ]] || continue
        vid="$(tr -d '\n' < "${d}idVendor" 2>/dev/null)"
        pid="$(tr -d '\n' < "${d}idProduct" 2>/dev/null)"
        [[ -n "$vid" && -n "$pid" ]] || continue
        prod="$(tr -d '\n' < "${d}product" 2>/dev/null)"
        mfr="$(tr -d '\n' < "${d}manufacturer" 2>/dev/null)"
        entry="${vid}:${pid}"
        [[ -n "$mfr" ]] && entry+=" ${mfr}"
        [[ -n "$prod" ]] && entry+=" ${prod}"
        entry="$(hardware::_clean "$entry")"
        list="${list}${list:+; }${entry}"
    done
    SYS_USB_LIST="${list:-N/A}"
}

# Full PCI device list (lspci -nn), one line per function.
hardware::pci() {
    local line list=""
    if command -v lspci >/dev/null 2>&1; then
        while IFS= read -r line || [[ -n "$line" ]]; do
            [[ -n "$line" ]] || continue
            line="$(hardware::_clean "$line")"
            list="${list}${list:+$'\n'}${line}"
        done < <(lspci -nn 2>/dev/null)
    fi
    SYS_PCI_LIST="${list:-N/A}"
}

# Full SMBIOS table dump (raw text; JSON-only — never in the CSV). dmidecode
# itself requires root, so a non-root run just yields an empty dump.
hardware::smbios() {
    local raw
    command -v dmidecode >/dev/null 2>&1 || { SYS_SMBIOS_RAW=""; return 0; }
    raw="$(dmidecode 2>/dev/null)"
    SYS_SMBIOS_RAW="$(printf '%s' "$raw" | tr -d '\r')"
}

# Network interfaces: name · MAC · operstate · driver (one entry per NIC).
hardware::interfaces() {
    local d name addr state driver entry list=""
    for d in "$SYS_NET_DIR"/*/; do
        [[ -r "${d}address" ]] || continue
        name="$(basename "$d")"
        addr="$(tr -d '\n' < "${d}address" 2>/dev/null)"
        state="$(tr -d '\n' < "${d}operstate" 2>/dev/null)"
        driver="$(sed -n 's/^DRIVER=//p' "${d}device/uevent" 2>/dev/null | head -n1)"
        entry="$name"
        [[ -n "$addr" ]] && entry+=" · $addr"
        [[ -n "$state" ]] && entry+=" · $state"
        [[ -n "$driver" ]] && entry+=" · $driver"
        entry="$(hardware::_clean "$entry")"
        list="${list}${list:+; }${entry}"
    done
    SYS_NET_INTERFACES="${list:-N/A}"
}

# UEFI boot entries: Boot#### variable names + their UTF-16LE descriptions
# (the first 4 bytes are attributes; ASCII descriptions decode by dropping the
# high bytes). Read-only — never writes an efivar.
hardware::uefi_boot() {
    local f name desc entry list=""
    [[ -d "$SYS_EFIVARS_DIR" ]] || { SYS_UEFI_BOOT="N/A"; return 0; }
    for f in "$SYS_EFIVARS_DIR"/Boot[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]-*; do
        [[ -e "$f" ]] || continue
        name="$(basename "$f")"; name="${name%%-*}"
        desc="$(dd if="$f" bs=1 skip=4 2>/dev/null | tr -d '\0' | tr -cd '[:print:]' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
        entry="$name"
        [[ -n "$desc" ]] && entry+=": $desc"
        entry="$(hardware::_clean "$entry")"
        list="${list}${list:+; }${entry}"
    done
    SYS_UEFI_BOOT="${list:-N/A}"
}

# Peripheral presence (grading signals): webcam, touchscreen, fingerprint,
# accelerometer, and the number of audio codecs.
hardware::peripherals() {
    local webcam=0 touch=0 fp=0 accel=0 audio_n=0
    [[ -n "$(ls $SYS_VIDEO_GLOB 2>/dev/null)" ]] && webcam=1
    grep -qi 'touchscreen' "$SYS_INPUT_DEVICES_FILE" 2>/dev/null && touch=1
    grep -qiE 'fingerprint|fprint' "$SYS_INPUT_DEVICES_FILE" 2>/dev/null && fp=1
    [[ -n "$(ls "$SYS_IIO_DIR" 2>/dev/null)" ]] && accel=1
    audio_n="$(grep -cE '^[[:space:]]*[0-9]+ ' "$SYS_ASOUND_CARDS_FILE" 2>/dev/null)"
    [[ "$audio_n" =~ ^[0-9]+$ ]] || audio_n=0

    SYS_PERIPHERALS="webcam:$webcam; touchscreen:$touch; fingerprint:$fp; accelerometer:$accel; audio:$audio_n"
}

# Derived "BIOS lockdown suspected" flag: any drive that is SED-locked (OPAL
# YES) or still frozen after the unfreeze attempt signals firmware locking that
# will block erasure. Computed from existing signals — no writes, no new reads.
# Runs AFTER device detection + classification (devrow is populated).
hardware::lockdown() {
    local dev lock=0
    for dev in "${devices[@]}"; do
        if [[ "${opal_locked[$dev]:-}" == "YES" || "${devrow[$dev.class]:-}" == "FROZEN" ]]; then
            lock=1
            break
        fi
    done
    SYS_BIOS_LOCKDOWN=$lock
}

# Orchestrator for the machine-level (non-drive) extras. Called from
# system::gather_info. hardware::lockdown is called separately (after device
# discovery) because it needs the per-drive state.
hardware::inventory() {
    hardware::usb
    hardware::pci
    hardware::smbios
    hardware::interfaces
    hardware::uefi_boot
    hardware::peripherals
}
