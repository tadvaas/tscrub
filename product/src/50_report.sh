# =============================================================================
# REPORT
# =============================================================================

report::csv() {
    local report_file="$REPORT_DIR${SCRIPT_NAME}_${COCID}_$(date -u +%Y%m%dT%H%M%SZ).csv"

    {
        echo "COCID,Timestamp,Model,Serial,Size,Bus,Type,Device,Class,Certification,Method,FinalStatus,SMART,TempC,PowerOnHours,PowerCycles,ReallocSectors,PctUsed,AvailSpare,TBW_TB,SMARTPOST,TempCPost,PowerOnHoursPost"
        for dev in "${devices[@]}"; do
            printf '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"\n' \
                "$COCID" \
                "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                "${devrow[$dev.model]}" \
                "${devrow[$dev.serial]}" \
                "${devrow[$dev.size]}" \
                "${devrow[$dev.bus]}" \
                "${devrow[$dev.type]}" \
                "${devrow[$dev.device]}" \
                "${devrow[$dev.class]}" \
                "${devrow[$dev.cert]}" \
                "${devrow[$dev.method]}" \
                "${devrow[$dev.status]}" \
                "${devrow[$dev.smart]}" \
                "${devrow[$dev.temp]}" \
                "${devrow[$dev.poh]}" \
                "${devrow[$dev.cycles]}" \
                "${devrow[$dev.realloc]}" \
                "${devrow[$dev.pct_used]}" \
                "${devrow[$dev.spare]}" \
                "${devrow[$dev.tbw]}" \
                "${devrow[$dev.smart_post]}" \
                "${devrow[$dev.temp_post]}" \
                "${devrow[$dev.poh_post]}"
        done
    } > "$report_file"

    report::sign "$report_file"

    printf "%sReport written to: %s\n" "$TABLE_INDENT" "$report_file" >&2

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
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" 2>/dev/null | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" 2>/dev/null | awk '{print $1}'
    fi
}

report::_signing_key() {
    local key_dir="${REPORT_KEY_DIR:-/etc/tscrub}"
    local key="$key_dir/report.key"

    [[ -n "${REPORT_KEY:-}" && -f "$REPORT_KEY" ]] && { echo "$REPORT_KEY"; return 0; }
    [[ -f "$key" ]] && { echo "$key"; return 0; }

    if report::_openssl; then
        mkdir -p "$key_dir" 2>/dev/null || true
        if openssl genpkey -algorithm ED25519 -out "$key" 2>/dev/null; then
            chmod 600 "$key" 2>/dev/null || true
            echo "$key"
            return 0
        fi
    fi
    return 1
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
        printf '  "cocid": "%s",\n' "$COCID"
        printf '  "created": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf '  "report": "%s",\n' "$(basename "$csv")"
        printf '  "sha256": "%s",\n' "$sha256"
        printf '  "signed": %s' "$signed"
        if [[ "$signed" == true ]]; then
            pub_b64="$(openssl pkey -in "$key" -pubout 2>/dev/null | openssl base64 -A)"
            printf ',\n  "public_key": "%s"' "$pub_b64"
        fi
        printf '\n  "drives": [\n'
        n=${#devices[@]}
        i=0
        for dev in "${devices[@]}"; do
            i=$((i+1))
            printf '    {"device":"%s","status":"%s","method":"%s","cert":"%s"}%s\n' \
                "$dev" "${devrow[$dev.status]}" "${devrow[$dev.method]}" "${devrow[$dev.cert]}" \
                "$([[ $i -lt $n ]] && printf ',')"
        done
        printf '  ]\n'
        printf '}\n'
    } > "$manifest"

    if [[ "$signed" == true ]]; then
        printf "%sReport signed (Ed25519): %s.sig\n" "$TABLE_INDENT" "$csv" >&2
    else
        printf "%sReport checksum recorded (signing unavailable): %s\n" "$TABLE_INDENT" "$manifest" >&2
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

# Decode a licence embedded at build time (LICENSE_EMBEDDED_B64 = base64 of the
# .lic JSON) to a mode-600 temp file and point LICENSE_FILE at it. Customer
# builds use this so no licence needs to be supplied at boot.
license::apply_embedded() {
    local out

    [[ -n "$LICENSE_EMBEDDED_B64" ]] || return 1
    out="$(mktemp /tmp/tscrub-lic.XXXXXX)"
    printf '%s\n' "$LICENSE_EMBEDDED_B64" | openssl base64 -d -out "$out" 2>/dev/null || { rm -f "$out"; return 1; }
    [[ -s "$out" ]] || { rm -f "$out"; return 1; }
    chmod 600 "$out" 2>/dev/null || true
    LICENSE_FILE="$out"
    return 0
}

# Resolve the licence location, in priority order:
#   1. explicit path (--license / tscrub_license=/shredos_license=)
#   2. URL (--license-url / tscrub_license_url=/shredos_license_url=)
#   3. licence embedded at build time (customer builds)
#   4. the compiled default path (/etc/tscrub/license.key)
license::detect() {
    local param url

    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^(tscrub_license|shredos_license)=//p' | head -n 1)"
    if [[ -n "$param" ]]; then
        param="${param#\"}"
        param="${param%\"}"
        LICENSE_FILE="$param"
        LICENSE_SOURCE_SET=1
    fi

    url="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^(tscrub_license_url|shredos_license_url)=//p' | head -n 1)"
    if [[ -n "$url" ]]; then
        url="${url#\"}"
        url="${url%\"}"
        LICENSE_URL="$url"
        LICENSE_SOURCE_SET=1
    fi

    if [[ -n "$LICENSE_URL" ]]; then
        if license::fetch "$LICENSE_URL"; then
            printf "%sLicence fetched from %s.\n" "$TABLE_INDENT" "$LICENSE_URL"
        else
            printf "%s[!] Licence fetch failed: %s\n" "$TABLE_INDENT" "$LICENSE_URL" >&2
        fi
    fi

    # Customer builds carry an embedded licence; use it when no external
    # source was configured.
    if [[ "$LICENSE_SOURCE_SET" -eq 0 ]]; then
        license::apply_embedded || true
    fi
}

license::verify() {
    local lic="${1:-$LICENSE_FILE}"
    local customer expiry tier key_b64 sig_b64 msg tmp

    [[ -f "$lic" ]] || return 1
    report::_openssl || return 1
    [[ -n "$LICENSE_VENDOR_PUBLIC_KEY_B64" ]] || return 1

    customer="$(sed -n 's/.*"customer": "\([^"]*\)".*/\1/p' "$lic")"
    expiry="$(sed -n 's/.*"expiry": "\([^"]*\)".*/\1/p' "$lic")"
    tier="$(sed -n 's/.*"tier": "\([^"]*\)".*/\1/p' "$lic")"
    key_b64="$(sed -n 's/.*"key": "\([^"]*\)".*/\1/p' "$lic")"
    sig_b64="$(sed -n 's/.*"signature": "\([^"]*\)".*/\1/p' "$lic")"

    [[ -n "$customer" && -n "$expiry" && -n "$sig_b64" ]] || return 1
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
        rm -rf "$tmp"
        return 0
    fi
    rm -rf "$tmp"
    return 1
}

# Extract the report-signing key from a verified licence so subsequent reports
# are signed with the customer's key (attributable). Call after license::verify.
license::apply() {
    local lic="${1:-$LICENSE_FILE}"
    local key_b64 key_file

    key_b64="$(sed -n 's/.*"key": "\([^"]*\)".*/\1/p' "$lic")"
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
#   2. the first writable removable FAT32/vfat partition (the boot stick)
#   3. / (RAM) as a last resort, with a warning
report::detect_output() {
    local param dir

    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_output=//p' | head -n 1)"
    if [[ -n "$param" ]]; then
        param="${param#\"}"
        param="${param%\"}"
        REPORT_OUTPUT="$param"
    fi

    if [[ -n "${REPORT_OUTPUT:-}" ]]; then
        dir="$REPORT_OUTPUT"
        if [[ -d "$dir" && -w "$dir" ]]; then
            REPORT_DIR="${dir%/}/"
            return 0
        fi
        printf "%s[!] Output path '%s' is not writable — falling back.\n" "$TABLE_INDENT" "$dir" >&2
    fi

    if report::mount_boot_usb; then
        return 0
    fi

    printf "%s[!] No writable USB partition found — report stays in RAM (/).\n" "$TABLE_INDENT" >&2
    REPORT_DIR="/"
    return 0
}

# Mount the first writable removable FAT32/vfat partition (normally the ShredOS
# boot stick) and point REPORT_DIR at it.
report::mount_boot_usb() {
    local name type rm ro fstype dev mnt

    command -v lsblk >/dev/null 2>&1 || return 1

    while read -r name type rm ro fstype; do
        [[ "$type" == "part" && "$rm" == "1" && "$ro" == "0" ]] || continue
        [[ "$fstype" == "vfat" || "$fstype" == "fat32" ]] || continue
        dev="/dev/$name"

        # Reuse the mountpoint if the partition is already mounted writable.
        mnt="$(findmnt -no TARGET "$dev" 2>/dev/null || true)"
        if [[ -n "$mnt" && -d "$mnt" && -w "$mnt" ]]; then
            REPORT_DIR="${mnt%/}/"
            return 0
        fi

        mnt="$(mktemp -d /tmp/tscrub-usb.XXXXXX)"
        if mount -o rw "$dev" "$mnt" 2>/dev/null && [[ -w "$mnt" ]]; then
            REPORT_DIR="${mnt%/}/"
            return 0
        fi
        rm -rf "$mnt" 2>/dev/null || true
    done < <(lsblk -rno NAME,TYPE,RM,RO,FSTYPE 2>/dev/null)

    return 1
}

# --- Network upload ----------------------------------------------------------
# Kernel command line: tscrub_upload=https://tscrub.com/api/reports and
# tscrub_api_token=<64-hex token> (from the dashboard Account page).
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

report::parse_ftp() {
    local param

    param="$(tr ' ' '\n' < /proc/cmdline | sed -n 's/^shredos_output=//p')"
    [ -z "$param" ] && return 1

    param="${param#\"}"
    param="${param%\"}"

    IFS=':' read -r SHRED_PROTO SHRED_HOST SHRED_PATH SHRED_USER SHRED_PASS _ <<< "$param"

    export SHRED_PROTO SHRED_HOST SHRED_PATH SHRED_USER SHRED_PASS
}

# Push a report to the tScrub dashboard (POST /api/reports). Requires curl for
# multipart form upload.
report::upload_http() {
    local file="$1" manifest sig url token args=() resp count

    [[ -n "${TSCRUB_UPLOAD_URL:-}" && -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    [[ -f "$file" ]] || return 0
    command -v curl >/dev/null 2>&1 || {
        printf "%sReport upload skipped: curl not available.\n" "$TABLE_INDENT"
        return 1
    }

    manifest="${file%.csv}.json"
    sig="${file}.sig"
    url="$TSCRUB_UPLOAD_URL"
    token="$TSCRUB_API_TOKEN"

    args=(-fsS --connect-timeout 10 --max-time 60 -H "X-Api-Token: $token" -F "reports[]=@$file" -F "reports[]=@$manifest")
    [[ -f "$sig" ]] && args+=(-F "reports[]=@$sig")

    printf "%sUploading report to %s...\n" "$TABLE_INDENT" "$url"

    if ! resp="$(curl "${args[@]}" "$url" 2>&1)"; then
        printf "%sReport upload FAILED.\n" "$TABLE_INDENT"
        return 1
    fi

    count="$(printf '%s' "$resp" | sed -n 's/.*"count":\([0-9]*\).*/\1/p' | head -n1)"
    if [[ -n "$count" ]]; then
        printf "%sSynced to tScrub dashboard — %s certificate(s).\n" "$TABLE_INDENT" "$count"
    else
        printf "%sReport uploaded successfully.\n" "$TABLE_INDENT"
    fi
    return 0
}

report::upload_ftp() {
    local file="$1" manifest sig uploads=""

    [[ "$SHRED_PROTO" == "ftp" ]] || return
    [[ -f "$file" ]] || return

    manifest="${file%.csv}.json"
    sig="${file}.sig"

    # Upload the CSV plus its sidecars (manifest always exists; the signature
    # only when signing succeeded). FTP only — ShredOS has no scp/https.
    uploads="put $file"
    [[ -f "$manifest" ]] && uploads="$uploads; put $manifest"
    [[ -f "$sig" ]] && uploads="$uploads; put $sig"

    printf "%sUploading report (CSV + manifest + signature)...\n" "$TABLE_INDENT"

    if lftp -u "$SHRED_USER,$SHRED_PASS" "$SHRED_HOST" \
        -e "cd $SHRED_PATH; $uploads; bye" >>"$LOG_FILE" 2>&1; then
        printf "%sReport uploaded successfully.\n" "$TABLE_INDENT"
    else
        printf "%sReport upload FAILED.\n" "$TABLE_INDENT"
        return 1
    fi
}

# Upload dispatcher: network push (tscrub_upload=) takes priority, then FTP
# (shredos_output=ftp:...); otherwise the report just stays local.
report::upload() {
    local file="$1"

    [[ -f "$file" ]] || return

    if [[ -n "${TSCRUB_UPLOAD_URL:-}" ]]; then
        report::upload_http "$file"
        return
    fi

    report::upload_ftp "$file"
}

