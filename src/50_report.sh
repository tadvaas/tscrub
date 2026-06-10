# =============================================================================
# REPORT
# =============================================================================

report::csv() {
    local report_file="$REPORT_DIR${SCRIPT_NAME}_${COCID}_$(date -u +%Y%m%dT%H%M%SZ).csv"
    local system_name="$SYS_MANUFACTURER $SYS_PRODUCT"

    {
        echo "COCID,Timestamp,System,SystemSerial,BaseboardSerial,Model,Serial,Size,Bus,Type,Device,Class,Certification,Method,FinalStatus"
        for dev in "${devices[@]}"; do
            printf '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"\n' \
                "$COCID" \
                "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                "$system_name" \
                "$SYS_SERIAL" \
                "$SYS_BASEBOARD_SERIAL" \
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

    printf "%sReport written to: %s\n" "$TABLE_INDENT" "$report_file" >&2

    echo "$report_file"
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
    local file="$1"
    [[ "$SHRED_PROTO" == "ftp" ]] || return
    [[ -f "$file" ]] || return

    printf "%sUploading report...\n" "$TABLE_INDENT"

    if lftp -u "$SHRED_USER,$SHRED_PASS" "$SHRED_HOST" \
        -e "cd $SHRED_PATH; put $file; bye" >>"$LOG_FILE" 2>&1; then
        printf "%sReport uploaded successfully.\n" "$TABLE_INDENT"
    else
        printf "%sReport upload FAILED.\n" "$TABLE_INDENT"
        return 1
    fi
}

