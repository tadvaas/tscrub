# =============================================================================
# REPORT
# =============================================================================

report::csv() {
    local report_file="$REPORT_DIR${SCRIPT_NAME}_${COCID}_$(date -u +%Y%m%dT%H%M%SZ).csv"

    {
        echo "COCID,Timestamp,Model,Serial,Size,Bus,Type,Device,Class,Certification,Method,FinalStatus"
        for dev in "${devices[@]}"; do
            printf '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"\n' \
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
                "${devrow[$dev.status]}"
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

license::verify() {
    local lic="${1:-$LICENSE_FILE}"
    local customer expiry key_b64 sig_b64 msg tmp

    [[ -f "$lic" ]] || return 1
    report::_openssl || return 1
    [[ -n "$LICENSE_VENDOR_PUBLIC_KEY_B64" ]] || return 1

    customer="$(sed -n 's/.*"customer": "\([^"]*\)".*/\1/p' "$lic")"
    expiry="$(sed -n 's/.*"expiry": "\([^"]*\)".*/\1/p' "$lic")"
    key_b64="$(sed -n 's/.*"key": "\([^"]*\)".*/\1/p' "$lic")"
    sig_b64="$(sed -n 's/.*"signature": "\([^"]*\)".*/\1/p' "$lic")"

    [[ -n "$customer" && -n "$expiry" && -n "$key_b64" && -n "$sig_b64" ]] || return 1

    if [[ "$expiry" < "$(date -u +%Y-%m-%d)" ]]; then
        printf "%s[!] Licence expired on %s.\n" "$TABLE_INDENT" "$expiry" >&2
        return 1
    fi

    msg="${customer}|${expiry}|${key_b64}"
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

report::parse_ftp() {
    local param

    param="$(tr ' ' '\n' < /proc/cmdline | sed -n 's/^shredos_output=//p')"
    [ -z "$param" ] && return 1

    param="${param#\"}"
    param="${param%\"}"

    IFS=':' read -r SHRED_PROTO SHRED_HOST SHRED_PATH SHRED_USER SHRED_PASS _ <<< "$param"

    export SHRED_PROTO SHRED_HOST SHRED_PATH SHRED_USER SHRED_PASS
}

report::upload() {
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

