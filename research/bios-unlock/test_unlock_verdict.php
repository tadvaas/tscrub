<?php
declare(strict_types=1);

/**
 * Harness for unlock_verdict() / unlock_clip() in marketing/server/bios_unlock.php.
 *
 * The wording asserted here is the wording actually emitted on the wire by
 * product/src/38_bios_unlock.sh — keep the two in step when either changes.
 *
 * Run on the server next to a copy of bios_unlock.php + its requires:
 *   mkdir -p ~/webs/tscrub-form/unlock-test && cp db.php http.php org.php bios_unlock.php …
 *   php unlock-test/test_unlock_verdict.php
 */

require __DIR__ . '/bios_unlock.php';

$pass = 0;
$fail = 0;

function check(string $label, string $want, string $got): void {
    global $pass, $fail;
    if ($want === $got) {
        $pass++;
        printf("  ok   %-58s %s\n", $label, $got);
    } else {
        $fail++;
        printf("  FAIL %-58s want=%s got=%s\n", $label, $want, $got);
    }
}

echo "unlock_verdict() — real appliance strings\n";

/* v1.11.45+: writes accepted but the flag never moved (HP hp-bioscfg). 253 chars,
 * which is why the detail column had to be widened from VARCHAR(255). */
check(
    'v1.11.45 no-clear-path (relative sysfs path)',
    'no_clear_path',
    unlock_verdict('failed', 'password still set after clear — the writes were accepted (nothing was rejected) but the password is unchanged, so this firmware exposes no clear path from Linux and the password cannot be validated here (sysfs: hp-bioscfg/authentication/Setup Password)')
);

/* Same case with an absolute path — 284 chars, over the old 255 limit. */
check(
    'no-clear-path (absolute sysfs path)',
    'no_clear_path',
    unlock_verdict('failed', 'password still set after clear — the writes were accepted (nothing was rejected) but the password is unchanged, so this firmware exposes no clear path from Linux and the password cannot be validated here (sysfs: /sys/class/firmware-attributes/hp-bioscfg/authentication/Setup Password)')
);

/* v1.11.44 wording — ambiguous on purpose, and the reason ORDER MATTERS: it
 * contains the words "wrong password" too, so it must classify as no_clear_path
 * and never as wrong_password. */
check(
    'v1.11.44 ambiguous wording → no_clear_path',
    'no_clear_path',
    unlock_verdict('failed', 'password still set after clear (sysfs: hp-bioscfg/authentication/Setup Password) — wrong password, or this firmware exposes no reset path from Linux')
);

check(
    'wrong password (rejected write)',
    'wrong_password',
    unlock_verdict('failed', 'wrong password — the firmware rejected the current password (sysfs: dell-wmi-sysman/attributes/AdminPassword)')
);

check(
    'password policy',
    'policy',
    unlock_verdict('failed', 'rejected — the value does not meet the firmware password policy (sysfs: hp-bioscfg/authentication/Setup Password)')
);

check(
    'firmware cannot set/clear',
    'not_supported',
    unlock_verdict('failed', 'this firmware/driver does not support setting or clearing BIOS passwords (sysfs: think_lmi/attributes/AdminPassword)')
);

check(
    'needs CAP_SYS_ADMIN',
    'needs_privilege',
    unlock_verdict('failed', 'not permitted — root / CAP_SYS_ADMIN is required (sysfs: think_lmi/attributes/AdminPassword)')
);

check(
    'attribute is read-only',
    'read_only',
    unlock_verdict('failed', 'the password attribute is read-only (sysfs: x/attributes/AdminPassword)')
);

check(
    'attribute not writable (open failed)',
    'read_only',
    unlock_verdict('failed', 'the password attribute is not writable (sysfs: x/attributes/AdminPassword)')
);

check(
    'no firmware-attributes device at all',
    'no_interface',
    unlock_verdict('unsupported', 'no firmware-attributes device published by the kernel (class dir empty, no orphaned tree matching /sys/devices/*) — no BIOS password interface to write to')
);

check(
    'pre-v1.11.44 generic discovery failure',
    'unsupported',
    unlock_verdict('unsupported', 'no writable admin-password attribute found')
);

check(
    'cleared',
    'cleared',
    unlock_verdict('cleared', 'sysfs: hp-bioscfg/authentication/Setup Password')
);

check(
    'cleared wins over a misleading detail',
    'cleared',
    unlock_verdict('cleared', 'wrong password')
);

check(
    'unknown wording degrades to failed',
    'failed',
    unlock_verdict('failed', 'something the classifier has never seen')
);

check(
    'empty result degrades to failed',
    'failed',
    unlock_verdict('', '')
);

/* An explicit appliance-supplied verdict wins (forward compatibility: a future
 * appliance can send the verdict directly instead of relying on this matching). */
check(
    'explicit verdict wins',
    'wrong_password',
    unlock_verdict('failed', 'anything at all', 'wrong_password')
);

echo "\nunlock_clip()\n";
check('short value untouched', 'hello', unlock_clip('  hello  ', 512));
check('clips to 512 chars', '512', (string)mb_strlen(unlock_clip(str_repeat('x', 600), 512)));
check('ellipsis marks a clip', 'y', (string)(str_ends_with(unlock_clip(str_repeat('x', 600), 512), '…') ? 'y' : 'n'));
check('multibyte clip stays valid', '512', (string)mb_strlen(unlock_clip(str_repeat('é', 600), 512)));
check('verdict column width', '24', (string)mb_strlen(unlock_clip(str_repeat('z', 99), 24)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
