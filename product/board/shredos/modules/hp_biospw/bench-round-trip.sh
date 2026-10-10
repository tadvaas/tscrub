#!/bin/sh
# bench-round-trip.sh — prove the BIOS-password clear end to end on real HP
# hardware, using the *shipping* code path (bios_unlock::clear) rather than a
# hand-driven probe.
#
# Run it ON the appliance, as root, with the payload unpacked in /tmp/hp:
#
#     tar -xzf /tmp/hp.tar.gz -C /tmp/hp
#     sh /tmp/hp/bench-round-trip.sh tscrub1234
#
# It does NOT require the machine to start locked: it first SETS a password it
# knows (so the run is self-contained), checks the BIOS now demands it, then
# clears it with bios_unlock::clear and checks the BIOS no longer does. If the
# set fails, nothing changes. If the clear fails, the machine is left locked
# with the password you passed — which you know, so it is recoverable.
#
# Verify afterwards with:  dmidecode -t 24   (Administrator Password Status)

set -u

NEWPW="${1:-tscrub1234}"
MOD="/lib/modules/$(uname -r)/extra/hp_biospw.ko"
PROC=/proc/hp_biospw
PROBE_SETTING="Ownership Tag"
PW_SLOT="Setup Password"
UTF='<utf-16/>'

say() { printf '\n== %s ==\n' "$*"; }

# --- transport (same bytes the product builds) -------------------------------
raw2() {   # name value            -> 2 elements
    { printf '%s\000%s' "$1" "$2" > "$PROC"; } 2>/dev/null || { echo "write failed"; return 1; }
    cat "$PROC"
}
raw3() {   # name value credential -> 3 elements
    { printf '%s\000%s\000%s' "$1" "$2" "$3" > "$PROC"; } 2>/dev/null || { echo "write failed"; return 1; }
    cat "$PROC"
}

probe() {  # a privileged write with NO credential: 0x06 while locked, 0x00 once free
    cur="$(cat "/sys/class/firmware-attributes/hp-bioscfg/attributes/$PROBE_SETTING/current_value" 2>/dev/null)"
    raw2 "$PROBE_SETTING" "$cur"
}

say "0. environment"
uname -r
echo "module: $MOD"
[ -f "$MOD" ] || { echo "FATAL: module not found at $MOD"; exit 2; }
insmod "$MOD" 2>&1 || echo "(insmod said something — continuing)"
[ -e "$PROC" ] || { echo "FATAL: $PROC missing"; exit 2; }

say "1. is a password currently set?  (probe without a credential)"
BEFORE="$(probe)"
echo "$BEFORE"

if [ "$BEFORE" = "status 0x00" ]; then
    say "2. setting the password we will clear"
    SET="$(raw2 "$PW_SLOT" "$UTF$NEWPW")"
    echo "set (no credential): $SET"
    if [ "$SET" != "status 0x00" ]; then
        SET="$(raw3 "$PW_SLOT" "$UTF$NEWPW" "$UTF")"
        echo "set (empty credential): $SET"
    fi
    if [ "$SET" != "status 0x00" ]; then
        echo "SET FAILED — the machine is unchanged. Stopping."
        rmmod hp_biospw 2>/dev/null
        exit 1
    fi
    sleep 1
    say "3. does the BIOS now demand a password?"
    LOCKED="$(probe)"
    echo "$LOCKED  (expect refusal, 0x06)"
elif [ "$BEFORE" = "status 0x06" ]; then
    say "2. already locked — nothing to set"
    LOCKED="$BEFORE"
else
    echo "unexpected probe status '$BEFORE' — stopping so nothing is guessed at"
    rmmod hpbiospw 2>/dev/null
    exit 1
fi

say "4. THE SHIPPING PATH: bios_unlock::clear '$NEWPW'"
rmmod hp_biospw 2>/dev/null          # let the product load it itself
. /tmp/hp/36_bios.sh
. /tmp/hp/38_bios_unlock.sh
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "$NEWPW"
echo "RESULT: $BIOS_UNLOCK_RESULT"
echo "DETAIL: $BIOS_UNLOCK_DETAIL"

say "5. independent check: is a privileged write still refused?"
insmod "$MOD" 2>/dev/null
AFTER="$(probe)"
echo "$AFTER  (0x00 = no administrator password)"
rmmod hp_biospw 2>/dev/null

say "6. firmware's own reporting (reflects POST, so it flips after a reboot)"
dmidecode -t 24 2>/dev/null | sed -n '2,8p'

say "7. verdict"
if [ "$AFTER" = "status 0x00" ] && [ "$LOCKED" = "status 0x06" ]; then
    echo "PASS — the shipping path cleared a password that the BIOS had been demanding"
else
    echo "INCONCLUSIVE — before='$BEFORE' locked='$LOCKED' after='$AFTER'"
fi
