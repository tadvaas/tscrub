# =============================================================================
# DEVICE SCSI
# =============================================================================

# --- nwipe software-overwrite standards -------------------------------------
# nwipe v0.41 is the bundled engine (pinned in the image; src/version.c).
# These standards apply ONLY to the software-overwrite fallback. Firmware erase
# (ATA secure erase / NVMe sanitize / SCSI SANITIZE) is the default path and
# is unaffected.
nwipe::method_valid() {
    case "${1:-}" in
        zero|random|dod|dodshort|gutmann|is5enh) return 0 ;;
        *) return 1 ;;
    esac
}

# tScrub name -> nwipe --method= value (nwipe v0.41's accepted spellings).
nwipe::method_arg() {
    case "${1:-zero}" in
        zero)     printf '%s' "zero" ;;
        random)   printf '%s' "random" ;;
        dod)      printf '%s' "dod522022m" ;;
        dodshort) printf '%s' "dodshort" ;;
        gutmann)  printf '%s' "gutmann" ;;
        is5enh)   printf '%s' "is5enh" ;;
        *)        printf '%s' "zero" ;;
    esac
}

# Human label. Keep <= 20 chars: the TUI METHOD column is 20 wide.
nwipe::method_label() {
    case "${1:-zero}" in
        zero)     printf '%s' "Zero fill" ;;
        random)   printf '%s' "PRNG stream" ;;
        dod)      printf '%s' "DoD 5220.22-M" ;;
        dodshort) printf '%s' "DoD Short" ;;
        gutmann)  printf '%s' "Gutmann" ;;
        is5enh)   printf '%s' "HMG IS5 Enhanced" ;;
        *)        printf '%s' "Zero fill" ;;
    esac
}

# The ordered standard list, space-separated on one line. Single source of
# truth for the triage picker's cycle order: the validator, the CLI help and
# the picker must never disagree about which names exist.
nwipe::methods() {
    printf '%s' "zero random dod dodshort gutmann is5enh"
}

# Short label for the console legends (<= 12 chars, so the standard plus its
# round count still fits an 80-column footer).
nwipe::method_short() {
    case "${1:-zero}" in
        zero)     printf '%s' "Zero" ;;
        random)   printf '%s' "PRNG" ;;
        dod)      printf '%s' "DoD 7-pass" ;;
        dodshort) printf '%s' "DoD Short" ;;
        gutmann)  printf '%s' "Gutmann" ;;
        is5enh)   printf '%s' "IS5 Enh" ;;
        *)        printf '%s' "Zero" ;;
    esac
}

# Patterns (writes) per round, from nwipe v0.41's method table. The run's own
# log line ("pass P of Q, round R of N") overrides this when it appears.
nwipe::method_patterns() {
    case "${1:-zero}" in
        dod)             printf '%s' 7 ;;
        dodshort|is5enh) printf '%s' 3 ;;
        gutmann)         printf '%s' 35 ;;
        *)               printf '%s' 1 ;;
    esac
}

# Engine version, e.g. "0.41". Empty when it cannot be read; never invented.
# The engine's version, for the report's EraseEngine field.
#
# MEASURED 2026-10-22 against the bundled nwipe v0.41 source (src/options.c):
# `--version` is DECLARED in the long-option table but the `case 0:` handler
# never matches it, so it prints NOTHING and this probe silently returned an
# empty version — every software-overwrite job recorded "nwipe" instead of
# "nwipe 0.41". Found by an end-to-end VM run; the unit tests passed because the
# fake answered a flag the real binary ignores. The handler that does print it is
# the SHORT option `-V` ("%s version %s"). Probe that first, and keep
# `--version` only as a fallback for other builds.
nwipe::version() {
    local out=""
    command -v nwipe >/dev/null 2>&1 || return 0
    out="$( { nwipe -V 2>/dev/null || nwipe --version 2>/dev/null; } | head -n 1 || true)"
    if [[ "$out" =~ ([0-9]+\.[0-9]+(\.[0-9]+)?) ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
    fi
}

# Engine name + version for reports: "nwipe 0.41", or just "nwipe".
nwipe::eraser() {
    local v
    v="$(nwipe::version)"
    if [[ -n "$v" ]]; then
        printf 'nwipe %s' "$v"
    else
        printf 'nwipe'
    fi
}

# How often nwipe is asked for a progress line, in seconds. nwipe has NO timer
# of its own: --nogui writes "NN.NN%, round .., pass .., eta .., [phase]" only
# when it receives SIGUSR1, so the appliance's sample rate IS this value.
NWIPE_PUMP_SECONDS="${NWIPE_PUMP_SECONDS:-10}"

# The pass phase nwipe is in, as a short token for the STATUS cell, or nothing
# when the line carries none. This is the only place the operator can tell an
# erase that is WRITING from one that is VERIFYING — the percentage looks the
# same for both.
nwipe::phase_token() {   # <log line> -> WRITE|VERIFY|SYNC|RETRY|BLANK|OPS2
    local line="$1"
    case "$line" in
        *"[verifying]"*)  printf 'VERIFY' ;;
        *"[writing]"*)    printf 'WRITE' ;;
        *"[syncing]"*)    printf 'SYNC' ;;
        *"[retrying]"*)   printf 'RETRY' ;;
        *"[blanking]"*)   printf 'BLANK' ;;
        *"[OPS-II final]"*) printf 'OPS2' ;;
        *) return 1 ;;
    esac
}

# Seconds from nwipe's "eta hh:mm:ss" field, or nothing when the line has none.
#
# The fields are ZERO-PADDED and bash reads a leading zero as OCTAL, so
# "eta 01:47:09" used to abort with "value too great for base (error token is
# "09")" on the terminal the moment the seconds (or minutes) field was 08 or
# 09 — i.e. once a wipe had been running 9 seconds past an hour boundary. The
# 10# prefixes force base 10 for every field.
nwipe::eta_seconds() {   # <log line> -> seconds on stdout, rc 1 when absent
    local line="$1" h m s
    [[ "$line" =~ eta[[:space:]]+([0-9]+):([0-9]+):([0-9]+) ]] || return 1
    h="${BASH_REMATCH[1]}"; m="${BASH_REMATCH[2]}"; s="${BASH_REMATCH[3]}"
    printf '%s' "$(( 10#$h * 3600 + 10#$m * 60 + 10#$s ))"
}

device::exec_nwipe() {
    local dev="$1"
    local nwipe_help logfile rc line pct eta_sec
    local method label patterns passes eraser version
    local rounds_total=0 pass_total=0
    local -a nwipe_cmd
    local nwipe_pid pump_pid tail_pid

    if ! command -v nwipe > /dev/null 2>&1; then
        device::emit_status "$dev" FAILED
        echo "$dev LOG nwipe not found; cannot sanitize non-ATA device" >&3
        return
    fi

    method="${TS_NWIPE_METHOD:-zero}"
    passes="${TS_NWIPE_PASSES:-1}"
    if ! nwipe::method_valid "$method"; then
        echo "$dev LOG unknown software-overwrite method '$method'; using zero" >&3
        method="zero"
    fi
    if ! [[ "$passes" =~ ^[0-9]+$ ]] || (( passes < 1 || passes > 64 )); then
        passes=1
    fi
    label="$(nwipe::method_label "$method")"
    patterns="$(nwipe::method_patterns "$method")"
    version="$(nwipe::version)"
    eraser="nwipe"
    [[ -n "$version" ]] && eraser="nwipe $version"

    echo "$dev STATUS RUNNING" >&3
    # The attempt is recorded before the wipe so the report still names the
    # standard if the worker dies mid-pass. A failed run keeps these fields and
    # ends FAILED, which normalize_outcome labels honestly.
    echo "$dev METHOD $label" >&3
    echo "$dev STANDARD $method" >&3
    echo "$dev PASSES $(( passes * patterns ))" >&3
    echo "$dev ERASER $eraser" >&3
    echo "$dev LOG software overwrite started ($label, $passes round(s), $eraser)" >&3

    nwipe_cmd=(nwipe --autonuke "--method=$(nwipe::method_arg "$method")")
    nwipe_help="$(nwipe --help 2>&1 || true)"
    if grep -q -- '--nogui' <<<"$nwipe_help"; then
        nwipe_cmd+=(--nogui)
    fi
    # Only pass --rounds when asked: the default argv stays exactly as before
    # (nwipe's own default is one round), so unconfigured fleets are unchanged.
    if (( passes > 1 )); then
        nwipe_cmd+=("--rounds=$passes")
    fi
    # nwipe --nogui only logs progress when signalled (SIGUSR1). A --logfile is
    # flushed per line, so we can follow it live: run nwipe in the background,
    # pump SIGUSR1 every ~10s, and tail the log for "NN.NN%, …, eta HH:MM:SS".
    logfile="$(mktemp /tmp/tscrub-nwipe.XXXXXX)"
    nwipe_cmd+=(--logfile "$logfile")
    nwipe_cmd+=("/dev/$dev")

    # nwipe paints its startup banner (device table + PRNG benchmarks +
    # "Selected PRNG") to stdout, which clobbers the tScrub TUI. Everything
    # meaningful (device found, PRNG chosen, progress) is already written to
    # --logfile and forwarded below, so discard stdout and keep stderr in the
    # logfile.
    "${nwipe_cmd[@]}" >/dev/null 2>>"$logfile" &
    nwipe_pid=$!

    # Progress pump: nwipe blocks SIGUSR1 in main before wiping and logs a
    # progress line whenever the signal arrives. Start after a short delay so
    # the handler is installed, then signal every 10s while nwipe lives.
    (
        sleep 3
        while kill -0 "$nwipe_pid" 2>/dev/null; do
            sleep "$NWIPE_PUMP_SECONDS"
            kill -USR1 "$nwipe_pid" 2>/dev/null || true
        done
    ) 3>&- 4<&- &
    pump_pid=$!

    # Follow the log by re-reading only newly appended bytes once a second:
    # forward every line to the detail log (fd 5) and translate progress lines
    # ("NN.NN%, …, eta HH:MM:SS") into STATUS/ETA IPC so the parent ui::loop
    # can aggregate live % + time remaining for the dashboard heartbeat.
    local offset=0 size=0
    while kill -0 "$nwipe_pid" 2>/dev/null; do
        size=$(wc -c < "$logfile" 2>/dev/null || echo 0)
        if (( size > offset )); then
            # Process substitution (not a pipe) so the pass/round counters below
            # survive the loop: a pipeline runs the body in a subshell.
            while IFS= read -r line; do
                echo "$line" >&5
                if [[ "$line" =~ round[[:space:]]+([0-9]+)[[:space:]]+of[[:space:]]+([0-9]+),[[:space:]]+pass[[:space:]]+([0-9]+)[[:space:]]+of[[:space:]]+([0-9]+) ]]; then
                    rounds_total="${BASH_REMATCH[2]}"
                    pass_total="${BASH_REMATCH[4]}"
                fi
                if [[ "$line" =~ ([0-9]+)(\.[0-9]+)?% ]]; then
                    # nwipe prints "%05.2f", so this captures "08" from
                    # "08.67%". A bare 08 in arithmetic is OCTAL, and 8/9 are
                    # not octal digits, so ANY later arithmetic on it aborts
                    # bash with "value too great for base" — which kills the
                    # whole run (it is fatal to a non-interactive shell).
                    # 10# forces decimal at the point of capture.
                    pct=$(( 10#${BASH_REMATCH[1]} ))
                    if eta_sec="$(nwipe::eta_seconds "$line")"; then
                        echo "$dev ETA $eta_sec" >&3
                    fi
                    if phase="$(nwipe::phase_token "$line")"; then
                        echo "$dev PHASE $phase" >&3
                    fi
                    echo "$dev STATUS ${pct}%" >&3
                fi
            done < <(tail -c +$((offset + 1)) "$logfile" 2>/dev/null)
            offset=$size
        fi
        sleep 1
    done

    wait "$nwipe_pid"; rc=$?
    # Drain any lines written between the last poll and nwipe's exit.
    while IFS= read -r line; do
        echo "$line" >&5
        if [[ "$line" =~ round[[:space:]]+([0-9]+)[[:space:]]+of[[:space:]]+([0-9]+),[[:space:]]+pass[[:space:]]+([0-9]+)[[:space:]]+of[[:space:]]+([0-9]+) ]]; then
            rounds_total="${BASH_REMATCH[2]}"
            pass_total="${BASH_REMATCH[4]}"
        fi
        if [[ "$line" =~ ([0-9]+)(\.[0-9]+)?% ]]; then
            pct=$(( 10#${BASH_REMATCH[1]} ))   # decimal, never octal (see above)
            if eta_sec="$(nwipe::eta_seconds "$line")"; then
                echo "$dev ETA $eta_sec" >&3
            fi
            if phase="$(nwipe::phase_token "$line")"; then
                echo "$dev PHASE $phase" >&3
            fi
            echo "$dev STATUS ${pct}%" >&3
        fi
    done < <(tail -c +$((offset + 1)) "$logfile" 2>/dev/null)
    # nwipe's own log is authoritative for the physical pass count: prefer it
    # over the static table when it reports a sane round/pass pair.
    if [[ "$rounds_total" =~ ^[0-9]+$ && "$pass_total" =~ ^[0-9]+$ ]] \
        && (( rounds_total > 0 && pass_total > 0 )); then
        local seen_total=$(( rounds_total * pass_total ))
        if (( seen_total != passes * patterns )); then
            echo "$dev PASSES $seen_total" >&3
        fi
    fi
    kill "$pump_pid" 2>/dev/null || true
    wait "$pump_pid" 2>/dev/null || true
    rm -f "$logfile"

    if [ $rc -eq 0 ]; then
        device::emit_status "$dev" COMPLETED
    else
        device::emit_status "$dev" FAILED
        echo "$dev LOG software overwrite failed; manual physical destruction required" >&3
    fi
}

# True when the SCSI/SAS drive supports the SCSI SANITIZE command with the
# overwrite service action (opcode 0x48, SA 0x01). Conservative: any probe
# failure (missing tool, no REPORT SUPPORTED OPCODES support, or the drive
# rejecting the query) means "no" so the drive falls back to nwipe.
device::scsi_sanitize_supported() {
    local dev="$1" out
    command -v sg_sanitize >/dev/null 2>&1 || return 1
    command -v sg_opcodes >/dev/null 2>&1 || return 1
    out="$(sg_opcodes --opcode=0x48,0x1 "/dev/$dev" 2>/dev/null || true)"
    [[ "$out" == *"Command supported"* ]]
}

# SCSI/SAS firmware sanitise: `sg_sanitize --overwrite --zero`. Firmware
# overwrite is a Purge per NIST 800-88, so a successful sanitize is recorded
# as DESTRUCTION. When the drive rejects SANITIZE (invalid opcode / field in
# CDB — mis-detected support), fall back to nwipe software overwrite and
# correct the recorded outcome to the honest CLEAR/SANITISATION label.
device::exec_scsi_sanitize() {
    local dev="$1"
    local tmp_out rc out

    if ! command -v sg_sanitize >/dev/null 2>&1; then
        # Tool vanished since detection — degrade to nwipe honestly. The
        # outcome travels over the fd-3 IPC pipe (this runs in the wipe
        # worker's subshell, where direct devrow writes would be lost).
        echo "$dev CLASS CLEAR" >&3
        echo "$dev CERT SANITISATION" >&3
        device::exec_nwipe "$dev"
        return
    fi

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG SCSI sanitize (overwrite) started" >&3
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE (overwrite, zero)" >> "$LOG_FILE"

    tmp_out="$(mktemp /tmp/tscrub-sg.XXXXXX)" || {
        device::emit_status "$dev" FAILED
        return
    }

    # --quick skips sg_sanitize's 15-second reconsideration delay. With neither
    # --early nor --wait it polls REQUEST SENSE every 60 s until the sanitize
    # finishes, so this call blocks until completion. Output streams to the UI
    # (fd 5) and is tee'd into a temp file for the unsupported fallback check.
    sg_sanitize --overwrite --zero --quick "/dev/$dev" 2>&1 | tee "$tmp_out" >&5
    rc=${PIPESTATUS[0]}
    out="$(cat "$tmp_out" 2>/dev/null)"
    rm -f "$tmp_out"

    if (( rc == 0 )); then
        device::emit_status "$dev" COMPLETED
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE COMPLETED" >> "$LOG_FILE"
        return
    fi

    # A drive that rejects SANITIZE (invalid opcode / field in CDB) was
    # optimistically classified — fall back to nwipe and correct the outcome.
    if grep -qiE 'invalid (command operation code|field in cdb)|not supported' <<<"$out"; then
        echo "$dev LOG sanitize unsupported; falling back to nwipe" >&3
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE unsupported -> nwipe" >> "$LOG_FILE"
        # Correct the outcome over the fd-3 IPC pipe (same reason as the
        # tool-vanished branch above — direct devrow writes are lost here).
        echo "$dev CLASS CLEAR" >&3
        echo "$dev CERT SANITISATION" >&3
        device::exec_nwipe "$dev"
        return
    fi

    device::emit_status "$dev" FAILED
    echo "$dev LOG sanitize failed; manual physical destruction required" >&3
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE FAILED" >> "$LOG_FILE"
}

