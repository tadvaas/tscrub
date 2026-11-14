# 04 — SPI flash reflash (the hardware fix)

This is the core technique for the fleet: when the password lives in the firmware SPI
flash and no software/service path clears it, we **dump the flash, patch the dump, and
write it back**. On some boards the EC/KBC also has its own flash (Lenovo especially).

> Read this whole doc before touching a board. The failure mode is a bricked unit.

## When to reach for this

- Setup **or** power-on password present, and:
  - sysfs clear failed / attribute not exposed (`02`),
  - no master password available (`02`),
  - CMOS / jumper / service mode did not clear it (`03`).

## Equipment

| Item | Why |
|---|---|
| **XGecu T48** (preferred) or CH341A (+ **1.8 V adapter**) | reads/writes SPI NOR; T48 is the production choice |
| SOIC8 test clip (SOP8 only) | clip onto chips with legs; **does not fit WSON8** |
| WSON8 pogo-pin probe (8x6 / 5x6) | in-circuit read/write on WSON8 pads; see `21` |
| WSON8-to-DIP8 socket + hot air | desolder when the pogo read is unstable |
| `flashrom` | dump / write / verify, cross-platform |
| Magnifier / USB microscope | read chip markings |
| Hot-air rework + flux | when the chip must come off |
| Bench PSU (optional) | power the board safely while flashing |

Chips to expect: Winbond (`25Q…`), Macronix (`MX25…`), GigaDevice (`GD25…`), ISSI,
XMC, and SOP8/WSON8 packages. **Check the voltage from the part marking** — post-2015
boards can be **1.8 V or 3.3 V** (the fleet's 830 G5 `W25Q256` is 3.3 V). A 3.3 V
programmer on a 1.8 V chip can kill it; measure VCC before connecting.

The 830 G5 has **two** chips: `25Q256JV` 32 MB main and `25Q128JV`/`25Q128JVSN` 16 MB
**EC BIOS** (U366/UC6). The HP password store is in the EC firmware, so try RCUnlocker
on the 16 MB dump first. See `24-technique-deep-dive.md` §2.

## Workflow

### Step 1 — locate the chip
- Open the unit, remove battery + AC.
- Find the SPI NOR near the EC/KBC or PCH (markings `25Q`, `MX25`, `GD25`…).
- On Lenovo, note **two** chips may matter: the main BIOS flash and a smaller
  security/EEPROM — the supervisor password is often in the second one.

### Step 2 — dump (back up) twice
```bash
flashrom -p ch341a_spi -r dump1.bin
flashrom -p ch341a_spi -r dump2.bin
sha256sum dump1.bin dump2.bin   # MUST match
```
If the two dumps differ, the clip contact is poor — re-seat and redo. **Do not proceed
on a single dump.**

Optionally read the chip ID first to confirm detection:
```bash
flashrom -p ch341a_spi
```

### Step 3 — patch the dump (never clone)
The right approach is to **modify the unit's own image**, not to flash a random image
from the internet (that clones another unit's serial / MAC / Windows key and may not
even boot on this board).

Three common patch strategies, in order of preference:

1. **Vendor clean image + re-inject identity.** If the vendor publishes a factory BIOS
   update (`*.bin`/`*.cap`), flash that, then restore the unit's serial / service tag /
   MAC / UUID (many vendor flashers do this automatically; with raw `flashrom` you
   patch those regions back from `dump1.bin`). The ME/firmware descriptor region must
   match the board.
2. **NVRAM variable surgery with UEFITool.** Open `dump1.bin` in UEFITool, locate the
   NVRAM / setup variable store, find the password variable, and clear it. Extract +
   re-insert. This preserves *everything else* (serial, MAC, keys) — the cleanest
   option when the variable is findable.
3. **Known-region byte patch.** Some models have a documented byte/region for the
   password (community findings per model). Apply only if a *reliable* reference
   exists for that exact board.

> **Do not** run generic "bios password removed" binaries from untrusted sources on
> your flash dumps — verify the patch with UEFITool's diff or a checksum before
> flashing.

### Step 4 — write back and verify
```bash
flashrom -p ch341a_spi -w patched.bin
flashrom -p ch341a_spi -v patched.bin   # verify
```

### Step 5 — power test
- Reassemble (battery/AC), boot.
- Password should be gone or the field cleared. Set a **known** admin password, reboot,
  clear it — confirms full control and that the board is healthy.
- Check the serial / MAC / Windows activation are intact (they should be if you patched
  in-place).

## Lenovo EC / security-chip note
On ThinkPads, if the supervisor password survives a BIOS-flash reflash, the password is
in the **security EEPROM** (a small I2C/SPI chip, e.g. the `24C`/`AT24` series or an
SOP8 near the KBC). You reprogram that chip the same way (dump → patch → write). Some
generations use a newer LPC-based SVP storage that requires the "short two pins +
reboot" trick or a dedicated SVP utility; check the per-model community documentation.

## Safety & gotchas

- **Voltage.** Confirm 1.8 V vs 3.3 V. Use the level adapter.
- **Power.** Flashing with the board *unpowered* via clip is safest. If the chip won't
  read unpowered (some boards), power the board from the bench PSU **only** — never on
  battery + clip, and never with AC adaptor attached.
- **Orientation.** Pin 1 of the clip to pin 1 of the chip (dot/notch). Reversed =
  possible damage.
- **WSON8** chips (bottom pads) have no legs: try a pogo-pin probe in-circuit first;
  if the read is unstable, hot-air them off and use a socket.
- **ME/descriptor region.** Some images won't boot if the ME region is blanked; keep it
  from the original dump.
- **Serial/MAC/UUID.** Verify after flashing; these are part of the resale value.
- **One unit first.** Prove the exact procedure per model on a sacrificial unit, then
  write the SOP for the floor.

## When to stop and replace
- Chip unreadable after multiple attempts (bad clip or dead chip).
- No reliable image for the board and the dump can't be patched.
- Board fails to POST after a verified flash (pre-existing fault).

Record the outcome per unit (model, chip, method, result) so the batch workflow
improves and the salvage decision is auditable.

Next: [`05-vendor-matrix.md`](05-vendor-matrix.md) for the per-vendor cheat sheet.
