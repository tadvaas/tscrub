# =============================================================================
# REPORT
# =============================================================================

# Double any embedded double-quote so the CSV row stays RFC-4180-safe.
report::_csv_field() {
    local s="${1:-}"
    printf '%s' "${s//\"/\"\"}"
}

# Human-friendly destination for messages: a temp USB mountpoint reads as
# "/tmp/tscrub-usb.XXXXXX", which looks like RAM — say "the USB stick" instead.
report::_where() {
    if [[ -n "${REPORT_USB_MNT:-}" ]]; then
        printf '%s' "the USB stick"
    else
        printf '%s' "${REPORT_DIR:-/}"
    fi
}

report::csv() {
    local now report_file outdir rel
    now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    # Group reports under reports/<COCID>/ so a busy stick stays tidy and each
    # Chain of Custody run is easy to find. COCID is validated to 5 digits.
    outdir="$REPORT_DIR/reports"
    [[ -n "$COCID" ]] && outdir="$outdir/$COCID"
    if [[ ! -d "$outdir" ]]; then
        mkdir -p "$outdir" 2>/dev/null || outdir="$REPORT_DIR"
    fi
    report_file="$outdir/${SCRIPT_NAME}_${COCID}_$(date -u +%Y%m%dT%H%M%SZ).csv"

    # Machine profile (one value per machine, repeated on every drive row so the
    # CSV is self-contained and the server can attribute each drive to its host).
    local sys_system sys_cpu sys_gpu
    sys_system="$(printf '%s %s' "${SYS_MANUFACTURER:-N/A}" "${SYS_PRODUCT:-N/A}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    [[ -n "$sys_system" ]] || sys_system="N/A"
    sys_cpu="${SYS_CPU_LIST:-N/A}"; sys_cpu="${sys_cpu//$'\n'/; }"
    sys_gpu="${SYS_GPU_LIST:-N/A}"; sys_gpu="${sys_gpu//$'\n'/; }"

    {
        echo "COCID,Timestamp,Model,Serial,Size,Bus,Type,Device,Class,Certification,Method,FinalStatus,SMART,TempC,PowerOnHours,PowerCycles,ReallocSectors,PctUsed,AvailSpare,TBW_TB,SMARTPOST,TempCPost,PowerOnHoursPost,System,SystemSerial,BaseboardSerial,CPU,GPU,RAM"
        for dev in "${devices[@]}"; do
            printf '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"\n' \
                "$(report::_csv_field "$COCID")" \
                "$now" \
                "$(report::_csv_field "${devrow[$dev.model]}")" \
                "$(report::_csv_field "${devrow[$dev.serial]}")" \
                "$(report::_csv_field "${devrow[$dev.size]}")" \
                "$(report::_csv_field "${devrow[$dev.bus]}")" \
                "$(report::_csv_field "${devrow[$dev.type]}")" \
                "$(report::_csv_field "${devrow[$dev.device]}")" \
                "$(report::_csv_field "${devrow[$dev.class]}")" \
                "$(report::_csv_field "${devrow[$dev.cert]}")" \
                "$(report::_csv_field "${devrow[$dev.method]}")" \
                "$(report::_csv_field "${devrow[$dev.status]}")" \
                "$(report::_csv_field "${devrow[$dev.smart]}")" \
                "$(report::_csv_field "${devrow[$dev.temp]}")" \
                "$(report::_csv_field "${devrow[$dev.poh]}")" \
                "$(report::_csv_field "${devrow[$dev.cycles]}")" \
                "$(report::_csv_field "${devrow[$dev.realloc]}")" \
                "$(report::_csv_field "${devrow[$dev.pct_used]}")" \
                "$(report::_csv_field "${devrow[$dev.spare]}")" \
                "$(report::_csv_field "${devrow[$dev.tbw]}")" \
                "$(report::_csv_field "${devrow[$dev.smart_post]}")" \
                "$(report::_csv_field "${devrow[$dev.temp_post]}")" \
                "$(report::_csv_field "${devrow[$dev.poh_post]}")" \
                "$(report::_csv_field "$sys_system")" \
                "$(report::_csv_field "${SYS_SERIAL:-N/A}")" \
                "$(report::_csv_field "${SYS_BASEBOARD_SERIAL:-N/A}")" \
                "$(report::_csv_field "$sys_cpu")" \
                "$(report::_csv_field "$sys_gpu")" \
                "$(report::_csv_field "${SYS_RAM_GB:-N/A}")"
        done
    } > "$report_file"

    if [[ ! -s "$report_file" ]]; then
        printf "%s[!] FAILED to write report to %s — is the filesystem writable?\n" "$TABLE_INDENT" "$report_file" >&5
        return 1
    fi

    report::sign "$report_file"

    rel="$(basename "$report_file")"
    if [[ "$outdir" != "$REPORT_DIR" ]]; then
        rel="reports"
        [[ -n "$COCID" ]] && rel="$rel/$COCID"
        rel="$rel/$(basename "$report_file")"
    fi
    printf "%sReport written to %s: %s\n" "$TABLE_INDENT" "$(report::_where)" "$rel" >&5

    echo "$report_file"
}

# --- Signed report sidecar ---------------------------------------------------
# Every report gets a SHA-256 checksum, an Ed25519 signature, and a JSON
# manifest so a Certificate of Destruction is tamper-evident and verifiable.
# Signing uses openssl 3.x (`pkeyutl -rawin`). When openssl or a signing key is
# unavailable, the manifest still records the checksum (integrity-only).
#
# Key locations: $REPORT_KEY (explicit file) or $REPORT_KEY_DIR/report.key.
# When neither exists, an ephemeral key is generated so reports are at least
# self-signed.

report::_openssl() {
    command -v openssl >/dev/null 2>&1
}

report::_sha256() {
    local h=""
    if command -v sha256sum >/dev/null 2>&1; then
        h="$(sha256sum "$1" 2>/dev/null | awk '{print $1}')"
    elif command -v shasum >/dev/null 2>&1; then
        h="$(shasum -a 256 "$1" 2>/dev/null | awk '{print $1}')"
    elif command -v openssl >/dev/null 2>&1; then
        h="$(openssl dgst -sha256 "$1" 2>/dev/null | awk '{print $NF}')"
    fi
    [[ -n "$h" ]] || printf "%s[!] No SHA-256 tool available — report checksum omitted.\n" "$TABLE_INDENT" >&5
    printf '%s' "$h"
}

report::_signing_key() {
    local key_dir="${REPORT_KEY_DIR:-/etc/tscrub}"
    local key="$key_dir/report.key"

    [[ -n "${REPORT_KEY:-}" && -f "$REPORT_KEY" ]] && { echo "$REPORT_KEY"; return 0; }
    [[ -f "$key" ]] && { echo "$key"; return 0; }

    if report::_openssl; then
        # Prefer the persistent location; fall back to a writable tmp dir when
        # the rootfs is read-only (e.g. the appliance squashfs) so free reports
        # are still self-signed.
        if ! mkdir -p "$key_dir" 2>/dev/null || [[ ! -w "$key_dir" ]]; then
            key_dir="$(mktemp -d /tmp/tscrub-key.XXXXXX)" 2>/dev/null || return 1
            key="$key_dir/report.key"
        fi
        if openssl genpkey -algorithm ED25519 -out "$key" 2>/dev/null; then
            chmod 600 "$key" 2>/dev/null || true
            echo "$key"
            return 0
        fi
    fi
    return 1
}

# Escape a value for safe embedding inside a JSON string literal.
report::_json_field() {
    local s="${1:-}"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\r'/\\r}"
    s="${s//$'\t'/\\t}"
    printf '%s' "$s"
}

# Sign a report CSV. Writes <csv>.sig (base64 Ed25519 signature) and a
# <stem>.json manifest alongside the CSV.
report::sign() {
    local csv="$1" key sig manifest sha256 pub_b64 signed=false
    local dev i n

    [[ -f "$csv" ]] || return 1
    sig="${csv}.sig"
    manifest="${csv%.csv}.json"
    sha256="$(report::_sha256 "$csv")"

    if key="$(report::_signing_key)" && [[ -n "$key" ]]; then
        if openssl pkeyutl -sign -inkey "$key" -rawin -in "$csv" -out "$sig.tmp" 2>/dev/null; then
            openssl base64 -in "$sig.tmp" > "$sig"
            rm -f "$sig.tmp"
            signed=true
        else
            rm -f "$sig" "$sig.tmp"
        fi
    fi

    {
        printf '{\n'
        printf '  "schema": "tscrub-report/1",\n'
        printf '  "cocid": "%s",\n' "$(report::_json_field "$COCID")"
        printf '  "created": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf '  "report": "%s",\n' "$(report::_json_field "$(basename "$csv")")"
        printf '  "sha256": "%s",\n' "$sha256"
        printf '  "signed": %s,\n' "$signed"
        if [[ "$signed" == true ]]; then
            pub_b64="$(openssl pkey -in "$key" -pubout 2>/dev/null | openssl base64 -A)"
            printf '  "public_key": "%s",\n' "$(report::_json_field "$pub_b64")"
        fi
        printf '  "drives": [\n'
        n=${#devices[@]}
        i=0
        for dev in "${devices[@]}"; do
            i=$((i+1))
            printf '    {"device":"%s","status":"%s","method":"%s","cert":"%s"}%s\n' \
                "$(report::_json_field "$dev")" \
                "$(report::_json_field "${devrow[$dev.status]}")" \
                "$(report::_json_field "${devrow[$dev.method]}")" \
                "$(report::_json_field "${devrow[$dev.cert]}")" \
                "$([[ $i -lt $n ]] && printf ',')"
        done
        printf '  ]\n'
        printf '}\n'
    } > "$manifest"

    if [[ "$signed" == true ]]; then
        printf "%sReport signed (Ed25519): %s\n" "$TABLE_INDENT" "$(basename "$csv").sig" >&5
    else
        printf "%sReport checksum recorded (signing unavailable): %s\n" "$TABLE_INDENT" "$(basename "$manifest")" >&5
    fi
}

# Verify a report. Usage: report::verify <report.csv> [public-key.pem]
# Prints SHA-256, manifest check, and signature check; exits non-zero on any
# failure so callers can script against it.
report::verify() {
    local csv="$1" pub="${2:-}" manifest sig sha256 recorded ok=0 tmppub tmpsig

    [[ -f "$csv" ]] || { echo "report not found: $csv" >&2; return 2; }
    manifest="${csv%.csv}.json"
    sig="${csv}.sig"
    sha256="$(report::_sha256 "$csv")"
    printf 'SHA-256: %s\n' "$sha256"

    if [[ -f "$manifest" ]]; then
        recorded="$(sed -n 's/.*"sha256": "\([0-9a-fA-F]\{64\}\)".*/\1/p' "$manifest")"
        if [[ -n "$recorded" ]]; then
            if [[ "$recorded" == "$sha256" ]]; then
                printf 'Manifest: OK\n'
            else
                printf 'Manifest: MISMATCH\n' >&2
                ok=1
            fi
        fi
    fi

    if [[ -f "$sig" ]] && report::_openssl; then
        tmppub=""
        if [[ -z "$pub" ]]; then
            tmppub="$(mktemp /tmp/tscrub-pub.XXXXXX)"
            sed -n 's/.*"public_key": "\([^"]*\)".*/\1/p' "$manifest" | openssl base64 -d -out "$tmppub" 2>/dev/null
            pub="$tmppub"
        fi
        if [[ -s "$pub" ]]; then
            tmpsig="$(mktemp /tmp/tscrub-sig.XXXXXX)"
            openssl base64 -d -in "$sig" -out "$tmpsig" 2>/dev/null
            if openssl pkeyutl -verify -pubin -inkey "$pub" -rawin -in "$csv" -sigfile "$tmpsig" >/dev/null 2>&1; then
                printf 'Signature: VALID\n'
            else
                printf 'Signature: INVALID\n' >&2
                ok=1
            fi
            rm -f "$tmpsig"
        else
            printf 'Signature: INVALID (no public key)\n' >&2
            ok=1
        fi
        [[ -n "$tmppub" ]] && rm -f "$tmppub"
    else
        printf 'Signature: none (checksum only)\n'
    fi

    return "$ok"
}

# --- Licence -----------------------------------------------------------------
# A Team/Enterprise licence binds report signing to a vendor-issued key so
# reports are attributable to a specific customer.
#
# Licence format (JSON):
#   { "schema": "tscrub-license/1", "customer": "...", "expiry": "YYYY-MM-DD",
#     "key": "<base64 Ed25519 PRIVATE key PEM>",
#     "signature": "<base64 Ed25519 signature over \"customer|expiry|key\">" }
#
# The vendor public key is embedded in the image as LICENSE_VENDOR_PUBLIC_KEY_B64.
# Licences are issued with scripts/issue_license.sh (vendor side).

# Fetch a licence from a URL (e.g. local-network hosted .lic). Sets LICENSE_FILE
# to a mode-600 temp file on success. Prefers curl, then wget. NOTE: a .lic
# contains the PRIVATE report-signing key, so host it on an authenticated or
# isolated LAN endpoint rather than a public URL.
license::fetch() {
    local url="$1" out

    [[ -n "$url" ]] || return 1
    out="$(mktemp /tmp/tscrub-lic.XXXXXX)"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --connect-timeout 10 --max-time 30 "$url" -o "$out" 2>/dev/null || { rm -f "$out"; return 1; }
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O "$out" --timeout=30 "$url" 2>/dev/null || { rm -f "$out"; return 1; }
    else
        rm -f "$out"
        return 1
    fi
    [[ -s "$out" ]] || { rm -f "$out"; return 1; }
    chmod 600 "$out" 2>/dev/null || true
    LICENSE_FILE="$out"
    return 0
}

# Look for a licence file at the root of the boot USB. Used as the default when
# no explicit path/URL is configured, so a customer can drop their .lic onto the
# boot stick and tScrub picks it up.
#
# The appliance is a hybrid ISO: when written to a stick it has an ISO9660 boot
# volume (read-only, the "root" most OSes show) plus a small writable FAT
# partition. The .lic may land on either, and some sticks report RM=0 (fixed
# disk) instead of removable, so we scan FAT *and* ISO9660 volumes, and fall
# back to non-removable volumes if nothing was found on removable ones. We only
# ever mount read-only and only look at the root of each volume, so this is safe
# even though the same machine's internal disks are about to be wiped.
license::detect_usb() {
    local name type rm fstype dev mnt cand lic mounted pass

    command -v lsblk >/dev/null 2>&1 || return 1

    for pass in 1 2; do
        while read -r name type rm fstype; do
            [[ "$type" == "part" || "$type" == "disk" ]] || continue
            case "$fstype" in
                vfat|fat16|fat32|iso9660) : ;;
                *) continue ;;
            esac
            # Pass 1 = removable media only; pass 2 = anything else (RM=0 sticks).
            if [[ "$pass" -eq 1 ]]; then
                [[ "$rm" == "1" ]] || continue
            fi
            dev="/dev/$name"

            mounted=0
            mnt="$(findmnt -no TARGET "$dev" 2>/dev/null || true)"
            if [[ -z "$mnt" || ! -d "$mnt" ]]; then
                mnt="$(mktemp -d /tmp/tscrub-usb.XXXXXX)"
                mount -o ro "$dev" "$mnt" 2>/dev/null || { rmdir "$mnt" 2>/dev/null; continue; }
                mounted=1
            fi

            cand=""
            for f in "$mnt"/license.key "$mnt"/*.lic; do
                [[ -f "$f" ]] || continue
                cand="$f"
                break
            done

            if [[ -n "$cand" ]]; then
                lic="$(mktemp /tmp/tscrub-lic.XXXXXX)"
                if cp "$cand" "$lic" 2>/dev/null; then
                    chmod 600 "$lic" 2>/dev/null || true
                    LICENSE_FILE="$lic"
                    LICENSE_USB_DEV="$dev"
                    if [[ "$mounted" -eq 1 ]]; then umount "$mnt" 2>/dev/null; rmdir "$mnt" 2>/dev/null; fi
                    return 0
                fi
                rm -f "$lic"
            fi

            if [[ "$mounted" -eq 1 ]]; then umount "$mnt" 2>/dev/null; rmdir "$mnt" 2>/dev/null; fi
        done < <(lsblk -rno NAME,TYPE,RM,FSTYPE 2>/dev/null)
    done

    return 1
}

# Read a tscrub.conf (KEY=VALUE, one per line) from the root of the boot USB so
# a customer can preconfigure the dashboard upload, the COCID, or a licence URL
# without editing GRUB or the kernel command line. Keys mirror the kernel params:
#   tscrub_upload=<url>          (optional — dashboard URL is built-in)
#   tscrub_api_token=<64-hex>    (dashboard upload — only this is required)
#   tscrub_cocid=12345
#   tscrub_license_url=http://host/license.key
#   tscrub_output=/path | ftp:host:path:user:pass | sftp:...
# CLI flags always win; the file fills in only what isn't already set.
config::load_usb() {
    local name type rm fstype dev mnt conf mounted pass line key val

    CONFIG_USB_DEBUG=""
    command -v lsblk >/dev/null 2>&1 || {
        CONFIG_USB_DEBUG+="lsblk not available"$'\n'
        return 1
    }

    for pass in 1 2; do
        while read -r name type rm fstype; do
            [[ "$type" == "part" || "$type" == "disk" ]] || continue
            case "$fstype" in
                vfat|fat16|fat32|iso9660) : ;;
                *) continue ;;
            esac
            # Pass 1 = removable media only; pass 2 = anything else (RM=0 sticks).
            if [[ "$pass" -eq 1 ]]; then
                [[ "$rm" == "1" ]] || continue
            fi
            dev="/dev/$name"
            CONFIG_USB_DEBUG+="volume ${dev} type=${fstype} rm=${rm:-?} pass=${pass}"$'\n'

            mounted=0
            mnt="$(findmnt -no TARGET "$dev" 2>/dev/null || true)"
            if [[ -z "$mnt" || ! -d "$mnt" ]]; then
                mnt="$(mktemp -d /tmp/tscrub-cfg.XXXXXX)"
                mount -o ro "$dev" "$mnt" 2>/dev/null || {
                    CONFIG_USB_DEBUG+="  mount failed"$'\n'
                    rmdir "$mnt" 2>/dev/null
                    continue
                }
                mounted=1
            fi

            conf="$mnt/tscrub.conf"
            if [[ -f "$conf" ]]; then
                CONFIG_USB_DEBUG+="tscrub.conf found on ${dev}"$'\n'
                while IFS= read -r line || [[ -n "$line" ]]; do
                    # Windows editors often prepend a UTF-8 BOM; strip it so the
                    # first key still matches.
                    line="${line#$'\357\273\277'}"
                    [[ "$line" =~ ^[[:space:]]*(#.*)?$ ]] && continue
                    key="${line%%=*}"
                    val="${line#*=}"
                    key="$(printf '%s' "$key" | xargs)"
                    val="$(printf '%s' "$val" | xargs)"
                    case "$key" in
                        tscrub_upload)
                            if [[ -z "${TSCRUB_UPLOAD_URL:-}" ]]; then
                                TSCRUB_UPLOAD_URL="$val"
                                CONFIG_USB_DEBUG+="  tscrub_upload: set"$'\n'
                            fi
                            ;;
                        tscrub_api_token)
                            if [[ -z "${TSCRUB_API_TOKEN:-}" ]]; then
                                TSCRUB_API_TOKEN="$val"
                                CONFIG_USB_DEBUG+="  tscrub_api_token: set (redacted)"$'\n'
                            fi
                            ;;
                        tscrub_cocid)
                            if [[ -z "${COCID:-}" ]]; then
                                COCID="$val"
                                CONFIG_USB_DEBUG+="  tscrub_cocid: set"$'\n'
                            fi
                            ;;
                        tscrub_license_url)
                            if [[ -z "${LICENSE_URL:-}" ]]; then
                                LICENSE_URL="$val"
                                CONFIG_USB_DEBUG+="  tscrub_license_url: set"$'\n'
                            fi
                            ;;
                        tscrub_output)
                            if [[ "$val" == ftp:* || "$val" == sftp:* ]]; then
                                # Same split as report::parse_output: the last
                                # field absorbs any extra colons, so passwords
                                # may contain ':'.
                                if [[ -z "${TSCRUB_NET_PROTO:-}" ]]; then
                                    IFS=':' read -r TSCRUB_NET_PROTO TSCRUB_NET_HOST TSCRUB_NET_PATH TSCRUB_NET_USER TSCRUB_NET_PASS <<< "$val"
                                fi
                                CONFIG_USB_DEBUG+="  tscrub_output: net upload set"$'\n'
                            else
                                [[ -z "${REPORT_OUTPUT:-}" ]] && REPORT_OUTPUT="$val"
                                CONFIG_USB_DEBUG+="  tscrub_output: path set"$'\n'
                            fi
                            ;;
                        *)
                            CONFIG_USB_DEBUG+="  ignored key: ${key}"$'\n'
                            ;;
                    esac
                done < "$conf"
                if [[ "$mounted" -eq 1 ]]; then umount "$mnt" 2>/dev/null; rmdir "$mnt" 2>/dev/null; fi
                return 0
            fi
            CONFIG_USB_DEBUG+="  no tscrub.conf"$'\n'

            if [[ "$mounted" -eq 1 ]]; then umount "$mnt" 2>/dev/null; rmdir "$mnt" 2>/dev/null; fi
        done < <(lsblk -rno NAME,TYPE,RM,FSTYPE 2>/dev/null)
    done

    CONFIG_USB_DEBUG+="no tscrub.conf found on any volume"$'\n'
    return 1
}

# Resolve the licence location, in priority order:
#   1. explicit path (--license / tscrub_license=)
#   2. URL (--license-url / tscrub_license_url=)
#   3. a licence file at the root of the boot USB (license.key or *.lic)
#   4. the compiled default path (/etc/tscrub/license.key)
license::detect() {
    local param url

    # Only consult the kernel command line when no explicit --license /
    # --license-url was given on the CLI, so the operator's choice always wins.
    if [[ "$LICENSE_SOURCE_SET" -eq 0 ]]; then
        param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_license=//p' | head -n 1)"
        if [[ -n "$param" ]]; then
            param="${param#\"}"
            param="${param%\"}"
            LICENSE_FILE="$param"
            LICENSE_SOURCE_SET=1
        fi

        url="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_license_url=//p' | head -n 1)"
        if [[ -n "$url" ]]; then
            url="${url#\"}"
            url="${url%\"}"
            LICENSE_URL="$url"
            LICENSE_SOURCE_SET=1
        fi
    fi

    if [[ -n "$LICENSE_URL" ]]; then
        # On PXE/bare-metal boots the licence is fetched before the boot-time
        # DHCP has necessarily completed (the UEFI iPXE stack had its own
        # lease, but the booted Linux kernel re-DHCPs in the background).
        # Ensure a default route exists first — a no-op when already up.
        if command -v ip >/dev/null 2>&1; then
            network::ensure
        fi
        if license::fetch "$LICENSE_URL"; then
            printf "%sLicence fetched from %s.\n" "$TABLE_INDENT" "$LICENSE_URL"
        else
            printf "%s[!] Licence fetch failed: %s\n" "$TABLE_INDENT" "$LICENSE_URL" >&2
        fi
    fi

    # Default when nothing was configured: a licence on the boot USB.
    if [[ "$LICENSE_SOURCE_SET" -eq 0 && -z "$LICENSE_URL" ]]; then
        license::detect_usb || true
    fi
}

license::verify() {
    local lic="${1:-$LICENSE_FILE}"
    local customer expiry tier key_b64 sig_b64 msg tmp

    [[ -f "$lic" ]] || return 1
    if ! report::_openssl; then
        printf "%s[!] openssl is not available — cannot verify the licence.\n" "$TABLE_INDENT" >&2
        return 1
    fi
    [[ -n "$LICENSE_VENDOR_PUBLIC_KEY_B64" ]] || return 1

    customer="$(sed -n 's/.*"customer": *"\([^"]*\)".*/\1/p' "$lic")"
    expiry="$(sed -n 's/.*"expiry": *"\([^"]*\)".*/\1/p' "$lic")"
    tier="$(sed -n 's/.*"tier": *"\([^"]*\)".*/\1/p' "$lic")"
    key_b64="$(sed -n 's/.*"key": *"\([^"]*\)".*/\1/p' "$lic")"
    sig_b64="$(sed -n 's/.*"signature": *"\([^"]*\)".*/\1/p' "$lic")"

    if [[ -z "$customer" || -z "$expiry" || -z "$sig_b64" ]]; then
        printf "%s[!] Licence file is malformed (missing customer/expiry/signature).\n" "$TABLE_INDENT" >&2
        return 1
    fi
    tier="${tier:-free}"

    if [[ "$expiry" < "$(date -u +%Y-%m-%d)" ]]; then
        printf "%s[!] Licence expired on %s.\n" "$TABLE_INDENT" "$expiry" >&2
        return 1
    fi

    msg="${customer}|${expiry}|${tier}|${key_b64}"
    tmp="$(mktemp -d /tmp/tscrub-lic.XXXXXX)"
    printf '%s\n' "$LICENSE_VENDOR_PUBLIC_KEY_B64" | openssl base64 -d -out "$tmp/vendor.pub" 2>/dev/null
    printf '%s' "$msg" > "$tmp/msg"
    printf '%s\n' "$sig_b64" | openssl base64 -d -out "$tmp/sig" 2>/dev/null

    if openssl pkeyutl -verify -pubin -inkey "$tmp/vendor.pub" -rawin -in "$tmp/msg" -sigfile "$tmp/sig" >/dev/null 2>&1; then
        LICENSE_CUSTOMER="$customer"
        LICENSE_EXPIRY="$expiry"
        LICENSE_TIER="$tier"
        rm -rf "$tmp"
        return 0
    fi
    printf "%s[!] Licence signature verification failed.\n" "$TABLE_INDENT" >&2
    rm -rf "$tmp"
    return 1
}

# Extract the report-signing key from a verified licence so subsequent reports
# are signed with the customer's key (attributable). Call after license::verify.
license::apply() {
    local lic="${1:-$LICENSE_FILE}"
    local key_b64 key_file

    key_b64="$(sed -n 's/.*"key": *"\([^"]*\)".*/\1/p' "$lic")"
    [[ -n "$key_b64" ]] || return 1

    key_file="$(mktemp /tmp/tscrub-report-key.XXXXXX)"
    printf '%s\n' "$key_b64" | openssl base64 -d -out "$key_file" 2>/dev/null
    [[ -s "$key_file" ]] || { rm -f "$key_file"; return 1; }
    chmod 600 "$key_file" 2>/dev/null || true

    REPORT_KEY="$key_file"
    export REPORT_KEY
    return 0
}

# --- Output location ---------------------------------------------------------
# Reports are written to REPORT_DIR. Resolution order:
#   1. explicit --output / tscrub_output=<path>
#   2. the appliance's writable USB partition (the boot stick)
#   3. / (RAM) as a last resort, with a warning
report::detect_output() {
    local param dir

    # Only fall back to the kernel command line when --output wasn't given.
    # tscrub_output= may also carry a network destination (ftp:/sftp:) — those
    # are handled by report::parse_output, not here.
    if [[ -z "${REPORT_OUTPUT:-}" ]]; then
        param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_output=//p' | head -n 1)"
        if [[ -n "$param" ]]; then
            param="${param#\"}"
            param="${param%\"}"
            case "$param" in
                ftp:*|sftp:*) : ;;
                *) REPORT_OUTPUT="$param" ;;
            esac
        fi
    fi

    if [[ -n "${REPORT_OUTPUT:-}" ]]; then
        dir="$REPORT_OUTPUT"
        if [[ -d "$dir" && -w "$dir" ]]; then
            REPORT_DIR="${dir%/}/"
            return 0
        fi
        printf "%s[!] Output path '%s' is not writable — falling back.\n" "$TABLE_INDENT" "$dir" >&5
    fi

    if report::mount_boot_usb; then
        return 0
    fi

    printf "%s[!] No writable USB partition found — report stays in RAM (/).\n" "$TABLE_INDENT" >&5
    REPORT_DIR="/"
    REPORT_USB_STATUS="fail"
    REPORT_USB_REASON="no writable USB partition found (report stays in RAM)"
    return 0
}

# Mount the appliance's writable USB partition and point REPORT_DIR at it.
# Prefers the volume the licence was found on (the customer drops their .lic on
# the stick — with a dd'd hybrid ISO that's the appended TSCRUB-USB partition,
# with a Rufus-written stick it's the single FAT partition), then falls back to
# scanning FAT/exFAT volumes like ShredOS (prefer boot/version.txt, then the
# first writable one). No removable-flag assumption anywhere.
report::mount_boot_usb() {
    local dev mnt fallback list

    if [[ -n "${LICENSE_USB_DEV:-}" && -e "$LICENSE_USB_DEV" ]]; then
        mnt="$(mktemp -d /tmp/tscrub-usb.XXXXXX)"
        if mount -o rw "$LICENSE_USB_DEV" "$mnt" 2>/dev/null && [[ -w "$mnt" ]]; then
            REPORT_DIR="${mnt%/}/"
            REPORT_USB_MNT="${mnt%/}"
            return 0
        fi
        rmdir "$mnt" 2>/dev/null || true
    fi

    command -v fdisk >/dev/null 2>&1 || command -v lsblk >/dev/null 2>&1 || return 1

    list="$(mktemp /tmp/tscrub-fats.XXXXXX)"
    if command -v fdisk >/dev/null 2>&1; then
        fdisk -l 2>/dev/null | grep -iE "exfat|fat16|fat32" | awk '{print $1}' > "$list"
    else
        lsblk -rno NAME,TYPE,FSTYPE 2>/dev/null | awk '$2=="part" && ($3=="vfat"||$3=="exfat") {print "/dev/"$1}' > "$list"
    fi

    while read -r dev; do
        [[ -n "$dev" ]] || continue

        mnt="$(mktemp -d /tmp/tscrub-usb.XXXXXX)"
        if ! mount -o rw "$dev" "$mnt" 2>/dev/null || [[ ! -w "$mnt" ]]; then
            rmdir "$mnt" 2>/dev/null || true
            continue
        fi

        # The writable boot partition carries boot/version.txt (the EFI and
        # ISO9660 volumes don't) — prefer it over any other FAT volume.
        if [[ -f "$mnt/boot/version.txt" ]]; then
            REPORT_DIR="${mnt%/}/"
            REPORT_USB_MNT="${mnt%/}"
            if [[ -n "$fallback" ]]; then
                umount "$fallback" 2>/dev/null || true
                rmdir "$fallback" 2>/dev/null || true
            fi
            rm -f "$list"
            return 0
        fi

        if [[ -z "$fallback" ]]; then
            fallback="${mnt%/}"
        else
            umount "$mnt" 2>/dev/null || true
            rmdir "$mnt" 2>/dev/null || true
        fi
    done < "$list"
    rm -f "$list"

    if [[ -n "$fallback" ]]; then
        REPORT_DIR="$fallback"
        REPORT_USB_MNT="$fallback"
        return 0
    fi
    return 1
}

# Flush the report to disk and release the USB mount (if any). FAT writes are
# buffered; without a sync/umount a hard reboot drops them — ShredOS unmounts
# its archive drive after copying for exactly this reason.
report::sync_out() {
    sync
    if [[ -n "${REPORT_USB_MNT:-}" ]]; then
        umount "$REPORT_USB_MNT" 2>/dev/null || true
        rmdir "$REPORT_USB_MNT" 2>/dev/null || true
        REPORT_USB_MNT=""
    fi
}

# --- Network upload ----------------------------------------------------------
# Kernel command line: tscrub_api_token=<64-hex token> enables the dashboard
# upload; tscrub_upload=<url> optionally overrides the built-in endpoint.
report::parse_upload() {
    local param

    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_upload=//p' | head -n 1)"
    if [[ -n "$param" ]]; then
        param="${param#\"}"
        param="${param%\"}"
        TSCRUB_UPLOAD_URL="$param"
    fi

    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_api_token=//p' | head -n 1)"
    if [[ -n "$param" ]]; then
        param="${param#\"}"
        param="${param%\"}"
        TSCRUB_API_TOKEN="$param"
    fi

    export TSCRUB_UPLOAD_URL TSCRUB_API_TOKEN
}

# Parse a network upload destination from the kernel command line.
# tscrub_output= accepts ftp:host:path:user:pass or sftp:host:path:user:pass.
# shredos_output= is kept as a deprecated alias for backwards compatibility.
report::parse_output() {
    local param

    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_output=//p' | head -n 1)"
    if [[ "$param" != ftp:* && "$param" != sftp:* ]]; then
        param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^shredos_output=//p' | head -n 1)"
    fi
    [[ "$param" == ftp:* || "$param" == sftp:* ]] || return 1

    param="${param#\"}"
    param="${param%\"}"

    # The last field absorbs any additional colons, so passwords may contain ':'.
    IFS=':' read -r TSCRUB_NET_PROTO TSCRUB_NET_HOST TSCRUB_NET_PATH TSCRUB_NET_USER TSCRUB_NET_PASS <<< "$param"

    export TSCRUB_NET_PROTO TSCRUB_NET_HOST TSCRUB_NET_PATH TSCRUB_NET_USER TSCRUB_NET_PASS
}

# Ensure the machine has an IPv4 default route before attempting an upload.
# The boot-time DHCP can miss the window when a NIC's link comes up late (an
# e1000e link often rises several seconds after init), leaving no IP and hence
# curl's "Could not resolve host". Runs udhcpc in the foreground on any
# carrier-up interface and exits as soon as a lease is obtained.
network::ensure() {
    # Already routable? nothing to do.
    ip route 2>/dev/null | grep -q '^default ' && return 0

    local dev waited=0 found=0
    # Wait up to ~20s for a carrier to appear (e1000e/USB NICs can take
    # several seconds to negotiate after boot).
    while (( waited < 20 )); do
        found=0
        for dev in /sys/class/net/*; do
            dev="${dev##*/}"
            case "$dev" in lo|sit*) continue ;; esac
            [[ "$(cat "/sys/class/net/$dev/carrier" 2>/dev/null)" == "1" ]] && { found=1; break; }
        done
        (( found )) && break
        sleep 1
        waited=$(( waited + 1 ))
    done

    for dev in /sys/class/net/*; do
        dev="${dev##*/}"
        case "$dev" in lo|sit*) continue ;; esac
        [[ "$(cat "/sys/class/net/$dev/carrier" 2>/dev/null)" == "1" ]] || continue

        printf "%sNetwork: no IPv4 route — requesting DHCP on %s...\n" "$TABLE_INDENT" "$dev" >&5
        udhcpc -i "$dev" -n -q -t 6 -T 2 -A 3 -O search -O staticroutes >/dev/null 2>&1
        ip route 2>/dev/null | grep -q '^default ' && break
    done

    ip route 2>/dev/null | grep -q '^default ' || return 1

    # Lease obtained but no DNS handed out? Fall back to public resolvers so
    # the tscrub.com upload can resolve.
    if ! grep -q 'nameserver' /etc/resolv.conf 2>/dev/null; then
        printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf 2>/dev/null
    fi
    return 0
}

# Push a report to the tScrub dashboard (POST /api/reports). Requires curl for
# multipart form upload.
report::upload_http() {
    local file="$1" manifest sig url token args=() resp count

    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    [[ -f "$file" ]] || return 0
    command -v curl >/dev/null 2>&1 || {
        printf "%sReport upload skipped: curl not available.\n" "$TABLE_INDENT" >&5
        REPORT_DASH_STATUS="fail"
        REPORT_DASH_REASON="curl not available"
        return 1
    }

    manifest="${file%.csv}.json"
    sig="${file}.sig"
    url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    token="$TSCRUB_API_TOKEN"

    args=(-fsS --connect-timeout 10 --max-time 60 -H "X-Api-Token: $token" -F "reports[]=@$file" -F "reports[]=@$manifest")
    [[ -f "$sig" ]] && args+=(-F "reports[]=@$sig")

    printf "%sUploading report to %s...\n" "$TABLE_INDENT" "$url" >&5

    if ! resp="$(curl "${args[@]}" "$url" 2>&1)"; then
        # A stale/dead RTC clock makes TLS certificate verification fail
        # (curl error 60) even though the server certificate is fine. The
        # machines we erase often can't have their clock set, so retry once
        # without certificate verification as a last resort.
        if [[ "$resp" == *"curl: (60)"* ]]; then
            printf "%sCertificate verification failed (system clock skew?) — retrying without verification.\n" "$TABLE_INDENT" >&5
            if ! resp="$(curl -k "${args[@]}" "$url" 2>&1)"; then
                printf "%sReport upload FAILED: %s\n" "$TABLE_INDENT" "$resp" >&5
                REPORT_DASH_STATUS="fail"
                REPORT_DASH_REASON="$resp"
                return 1
            fi
        else
            printf "%sReport upload FAILED: %s\n" "$TABLE_INDENT" "$resp" >&5
            REPORT_DASH_STATUS="fail"
            REPORT_DASH_REASON="$resp"
            return 1
        fi
    fi

    count="$(printf '%s' "$resp" | sed -n 's/.*"count":\([0-9]*\).*/\1/p' | head -n1)"
    if [[ -n "$count" ]]; then
        printf "%sSynced to tScrub dashboard — %s report(s) stored.\n" "$TABLE_INDENT" "$count" >&5
    else
        printf "%sReport uploaded successfully.\n" "$TABLE_INDENT" >&5
    fi
    REPORT_DASH_STATUS="ok"
    REPORT_DASH_REASON=""
    return 0
}

# Upload the CSV + sidecars over FTP or SFTP via lftp (lftp supports both).
report::upload_net() {
    local file="$1" manifest sig uploads="" proto host path user pass url

    proto="${TSCRUB_NET_PROTO:-}"
    host="${TSCRUB_NET_HOST:-}"
    path="${TSCRUB_NET_PATH:-}"
    user="${TSCRUB_NET_USER:-}"
    pass="${TSCRUB_NET_PASS:-}"

    [[ "$proto" == "ftp" || "$proto" == "sftp" ]] || return
    [[ -f "$file" ]] || return

    manifest="${file%.csv}.json"
    sig="${file}.sig"

    # Upload the CSV plus its sidecars (manifest always exists; the signature
    # only when signing succeeded).
    uploads="put '${file}'"
    [[ -f "$manifest" ]] && uploads="$uploads; put '${manifest}'"
    [[ -f "$sig" ]] && uploads="$uploads; put '${sig}'"

    printf "%sUploading report (CSV + manifest + signature) via %s...\n" "$TABLE_INDENT" "$proto" >&5

    url="${proto}://${host}"
    local lftp_out
    if lftp_out="$(lftp -u "$user,$pass" "$url" \
        -e "set net:timeout 15; set net:max-retries 1; set sftp:auto-confirm yes; cd '${path}'; $uploads; bye" 2>&1)"; then
        [[ -n "$lftp_out" ]] && printf '%s\n' "$lftp_out" >>"$LOG_FILE"
        printf "%sReport uploaded successfully.\n" "$TABLE_INDENT" >&5
        REPORT_NET_STATUS="ok"
        REPORT_NET_REASON=""
    else
        [[ -n "$lftp_out" ]] && printf '%s\n' "$lftp_out" >>"$LOG_FILE"
        printf "%sReport upload FAILED.\n" "$TABLE_INDENT" >&5
        REPORT_NET_STATUS="fail"
        REPORT_NET_REASON="${lftp_out##*$'\n'}"
        [[ -n "$REPORT_NET_REASON" ]] || REPORT_NET_REASON="lftp transfer failed"
        return 1
    fi
}

# Upload dispatcher: dashboard push (appliance token) takes priority, then a
# network upload (tscrub_output=ftp:.../sftp:...); otherwise the report stays
# local. Returns non-zero when a configured destination failed and nothing was
# delivered (so fn_main can flag the finish screen amber). Returns 0 when
# nothing was configured, or when at least one destination accepted the report.
report::upload() {
    local file="$1" fallback_hint=""

    [[ -f "$file" ]] || return 1

    REPORT_DASH_STATUS=""
    REPORT_DASH_REASON=""
    REPORT_NET_STATUS=""
    REPORT_NET_REASON=""

    [[ -n "${TSCRUB_NET_PROTO:-}" ]] && fallback_hint=" — falling back to ${TSCRUB_NET_PROTO}"

    if [[ -n "${TSCRUB_API_TOKEN:-}" ]]; then
        if report::upload_http "$file"; then
            return 0
        fi
        printf "%sDashboard upload failed%s.\n" "$TABLE_INDENT" "$fallback_hint" >&5
    fi

    if [[ -n "${TSCRUB_NET_PROTO:-}" ]]; then
        if report::upload_net "$file"; then
            return 0
        fi
        return 1
    fi

    if [[ -n "${TSCRUB_API_TOKEN:-}" ]]; then
        return 1
    fi
    return 0
}

# Print a per-destination delivery summary for the finish screen. Shows the
# outcome (OK / FAILED + reason / not configured) of each report destination.
report::print_summary() {
    local usb dash net netlabel

    case "${REPORT_USB_STATUS:-}" in
        ok)   usb="OK" ;;
        fail) usb="FAILED — ${REPORT_USB_REASON:-unknown}" ;;
        *)    usb="not attempted" ;;
    esac
    case "${REPORT_DASH_STATUS:-}" in
        ok)   dash="OK" ;;
        fail) dash="FAILED — ${REPORT_DASH_REASON:-unknown}" ;;
        *)    dash="not configured" ;;
    esac
    case "${REPORT_NET_STATUS:-}" in
        ok)   net="OK" ;;
        fail) net="FAILED — ${REPORT_NET_REASON:-unknown}" ;;
        *)    net="not configured" ;;
    esac
    netlabel="Network"
    [[ -n "${TSCRUB_NET_PROTO:-}" ]] && netlabel="Network (${TSCRUB_NET_PROTO})"

    printf "%sReport delivery:\n" "$TABLE_INDENT"
    printf "%s  %-14s %s\n" "$TABLE_INDENT" "USB:" "$usb"
    if [[ -n "${TSCRUB_API_TOKEN:-}" ]]; then
        printf "%s  %-14s %s\n" "$TABLE_INDENT" "Dashboard:" "$dash"
    fi
    if [[ -n "${TSCRUB_NET_PROTO:-}" ]]; then
        printf "%s  %-14s %s\n" "$TABLE_INDENT" "${netlabel}:" "$net"
    fi
}

# Write a diagnostics bundle (network state, dmesg, tScrub log, report outcome)
# to the ROOT of the report USB stick, so a failed upload or boot can be
# debugged after the fact. Runs before report::sync_out (the USB is still
# mounted there).
debug::save() {
    local dir="" out

    if [[ -n "${REPORT_USB_MNT:-}" && -w "$REPORT_USB_MNT" ]]; then
        dir="${REPORT_USB_MNT%/}"
    elif [[ -n "${REPORT_DIR:-}" && "$REPORT_DIR" != "/" && -w "$REPORT_DIR" ]]; then
        dir="${REPORT_DIR%/}"
    fi
    [[ -n "$dir" ]] || return 0

    out="${dir}/${SCRIPT_NAME}_debug_$(date -u +%Y%m%dT%H%M%SZ).txt"
    {
        echo "tScrub debug snapshot — $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
        echo "script_version=$SCRIPT_VERSION"
        echo "cocid=${COCID:-}"
        echo
        echo "=== kernel command line ==="
        cat /proc/cmdline 2>/dev/null; echo
        echo
        echo "=== network links ==="
        ip link 2>/dev/null || ifconfig -a 2>/dev/null
        echo
        echo "=== network addresses ==="
        ip addr 2>/dev/null || ifconfig 2>/dev/null
        echo
        echo "=== routes ==="
        ip route 2>/dev/null || route -n 2>/dev/null
        echo
        echo "=== /etc/resolv.conf ==="
        cat /etc/resolv.conf 2>/dev/null
        echo
        echo "=== report delivery ==="
        printf 'USB: %s %s\n' "${REPORT_USB_STATUS:-n/a}" "${REPORT_USB_REASON:-}"
        printf 'Dashboard: %s %s\n' "${REPORT_DASH_STATUS:-not configured}" "${REPORT_DASH_REASON:-}"
        printf 'Network: %s %s\n' "${REPORT_NET_STATUS:-not configured}" "${REPORT_NET_REASON:-}"
        echo
        echo "=== tscrub.conf (on-USB config) ==="
        printf '%s' "${CONFIG_USB_DEBUG:-not checked}"
        echo
        echo "=== processes ==="
        ps w 2>/dev/null
        echo
        echo "=== /var/run/ifstate ==="
        cat /var/run/ifstate 2>/dev/null
        echo
        echo "=== /var/log/shredos_net.log ==="
        cat /var/log/shredos_net.log 2>/dev/null
        echo
        echo "=== dmesg ==="
        dmesg 2>/dev/null
        echo
        echo "=== tScrub log (${LOG_FILE:-})"
        cat "${LOG_FILE:-/tScrub.log}" 2>/dev/null
    } > "$out" 2>/dev/null

    if [[ -s "$out" ]]; then
        printf "%sDebug snapshot saved to %s\n" "$TABLE_INDENT" "$(basename "$out")" >&5
    fi
}

