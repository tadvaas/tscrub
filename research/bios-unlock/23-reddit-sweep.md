# 23 — Reddit sweep: BIOS reprogramming and password removal

**Status:** Reddit sweep via Google `site:reddit.com` · 2026-10-23
**Scope:** what the r/homelab, r/hacking, r/laptops, r/Hewlett_Packard,
r/AskElectronics and r/coreboot communities are saying about BIOS unlock and SPI
reflash, and what it means for the tScrub fleet.
**Related:** `22-2026-trends.md` (Google/YouTube survey), `21-hardware-bom.md`
(fleet-weighted BOM), `10-hp-830g5-spi-sop.md` (dominant model).

## 1. TL;DR — the Reddit consensus

1. **CMOS battery and reset jumpers are dead ends on modern business laptops.**
   Multiple threads confirm the password survives a battery pull; it lives in an
   EEPROM/SPI flash or the Sure Start EC.
2. **The standard answer is "dump the BIOS, patch the password out, reflash".**
   The usual route is a hardware programmer plus a Badcaps/community patch, or a paid
   dump-patching service.
3. **WSON8 cannot be clipped with a SOIC8 clip.** Reddit is blunt about this: use a
   pogo-pin probe, solder wires to the pads, or desolder the chip. The Framework
   community specifically names a **1.8 V 8x6 mm WSON8 pogo probe**.
4. **HP vendor support is not a route for most of our units.** A former IT distributor
   employee says HP used to hand out a BIOS file that wiped the password, but
   **after 2018 they stopped** and quote motherboard replacement. An EliteBook 840 G10
   buyer was told the same by HP support.
5. **RCUnlocker and HPUnlocker are the community HP tools.** Reddit users report
   RCUnlocker (or manually zapping the hex from the dump) clears HP passwords.
   HPUnlocker.py is described as taking a BIOS dump and changing the password hex
   values to `FF`.
6. **A new HP Sure Start EC pin-short method is circulating.** On HP EliteDesk/
   ProDesk G6, shorting **pin #2 of the GigaDevice Sure Start embedded controller** to
   ground, then power-cycling, resets the BIOS password permanently. Confirmed by
   commenters on a ProBook 805 G6 and a ProDesk 600 G6. Not confirmed on EliteBook
   840 G6 / 830 G5. **Treat as [VERIFY] on a sacrificial unit only.**
7. **The CH341A 5 V problem is real.** Reddit repeatedly warns that some black
   CH341A clones put 5 V on the data lines even when the VCC jumper is on 3.3 V.
   For 1.8 V chips you need a level shifter/adapter; the T48 is the safer default.
8. **Never flash a clean image without preserving identity.** One user flashed a
   stock ASUS BIOS and ended up with MAC `00:11:22:33:44:55`. Patch the unit's own
   dump, or re-inject serial/MAC/UUID from the original.

## 2. Threads worth reading

| Subreddit | Thread | Age / size | Key takeaway |
|---|---|---|---|
| r/Hewlett_Packard | **"BYPASS HP BIOS PASSWORD:"** (`/comments/1tdx6ka/`) | 5 mo · 162 upvotes · 43 comments | Short pin #2 of the GigaDevice Sure Start EC to ground, power on, power off, hold power 15 s. OP says permanent reset; confirmed on ProBook 805 G6 and ProDesk 600 G6. Many model-specific questions unanswered. |
| r/homelab | **"Removing HP Elite Mini 600 G9 bios password"** (`/comments/1uju8hs/`) | 4 mo · 358 upvotes · 119 comments | Top comment: "same as a laptop, you have to basically remove the BIOS chip and reflash it." HP support pre-2018 gave a password-wipe BIOS file; post-2018 they quote a motherboard replacement. |
| r/laptops | **"BIOS locked HP Elitebook - Laptop otherwise works fine."** (`/comments/1th7s07/`) | 5 mo · 201 upvotes · 85 comments | OP has an EliteBook 840 G10; HP support says replace the motherboard. But the comments contain **ProBook 650 G8** and **`GD25B256DYIG`** pin-short successes. This is the strongest laptop evidence for testing the pin-short on a sacrificial 830 G5 before SPI. |
| r/hacking | **"Any way to get past a bios administrator password on an HP Elitebook 845 G8?"** (`/comments/1nmpcae/`) | 1 yr · 503 upvotes · 232 comments | The consensus recipe: dump with a programmer, patch/remove the password bits, reflash. Paid Badcaps-style patching is mentioned (~$30). A clean-image flash cost one user their MAC address. |
| r/homelab | **"BIOS password clear, hp elitdesk 705 g4 mini, amd 2400g"** | 1 yr · 20+ comments | "The pw is stored elsewhere. Using RcUnlocker (or just manually zapping the hex from the dump) clears it." |
| r/hardwarehacking | **"HP BIOS Administrator Password that was Never Set Solution"** | 1 yr · 6 comments | Solved by altering the BIOS in the chip directly; the Badcaps **HP Unlocker** tool is named. |
| r/AskElectronics | **"Is it possible to solder SOIC8 to clip to bios chip"** | 1 yr · 30+ comments | WSON8 cannot be clamped; a pogo-pin setup works, or break out the pins. |
| r/AskElectronics | **"Flashing HP ProDesk 400 G6 BIOS with CH341A – SOP16"** | 5 mo · 9 comments | `W25Q256JVFQ` in SOP16; a normal SOIC-8 clip will not work because the pinout differs. |
| r/chrultrabook | WSON-8 flash chip question | 1 yr · 3 comments | "You can't use a SOIC-8 chip clip on it. You can use a 6x5 pogo-pin probe or solder wires to the pins." |
| r/LegionGo | **"Bios warning"** | 2 mo · 60+ comments | WSON8 pogo-pin adapter is the likely route; the chip clip is "a nope". |
| r/coreboot | **"Make BB CH341A programmer flashes 3.3v bios eeproms"** | 5 yr · 10+ comments | Black CH341A can pump 5 V on 3.3 V chips; mod/level-shift or use a better programmer. |
| r/thinkpad | **"CH341a programmer bios patch"** | 10 mo · 3 comments | Some CH341A revisions use 5 V where they should use 3.3 V; 1.8 V chips need a level shifter. |
| r/thinkpad | **"[GUIDE] ThinkPad BIOS: Reading, Patching, and Flashing"** | 3 yr · 190+ comments | The canonical dump → patch → reflash guide; covers disabling the SVP before flashing and the usual tools. |
| r/thinkpad | **"T14 Gen 1 - Bios password reset?"** | 2 yr · 30+ comments | SVP is in the **MEC1663 EC**; top answer found the JTAG port and shorted pins 4, 8 or 10. Another user read the MEC1663 after soldering to the board. Very model-specific. |
| r/thinkpad | **"How to bypass a thinkpad's supervisor password?"** | 2 yr · 10+ comments | CH341A + Badcaps dumps is the cheap route; EC/MEC1663 work is the reliable route. |
| r/hardwarehacking | **"CH341A PROGRAMMER"** | 1 yr · 40+ comments | CH341A is 3.3 V or 5 V; 1.8 V needs an adapter. |

## 3. The new HP Sure Start EC short (watch / verify)

This is the most interesting Reddit-only finding of the sweep.

**Original source.** The method comes from Rhys Goodwin's blog post "Elitedesk 800 G6
Mini BIOS Password Removal" (`blog.rhysgoodwin.com/hp-elite-800-bios-password-removal/`,
now 404). The content is mirrored in `github.com/RhysGoodwin/rhysgoodwin.github.io/issues/4`.
The important sentence: the chip is **"the 16MB flash chip used by the Sure Start
embedded controller, it's not the main BIOS"**. So this is not an SPI programmer
technique; it is a deliberate corruption of the EC's security store.

**Reported method (HP EliteDesk/ProDesk G6):**

1. Power off, unplug the PSU, remove the battery if present.
2. Locate the **GigaDevice / Winbond 16 MB Sure Start EC flash** on the board.
3. Short **pin #2** to a ground point with a jumper wire.
4. Plug in power, power on. The board may flash red / beep. Do not panic.
5. Power off, unplug, hold the power button for ~15 s to drain.
6. Power on. The BIOS password should be gone and settings back to defaults.

**Live variant (most successful reports):** leave the power cord plugged in, short pin 2,
press power, let the beeps finish, power down with the button, unplug, drain, plug back
in. May need 3–4 attempts.

**Confirmed successes and failures:**

| Model | Result | Notes |
|---|---|---|
| EliteDesk 800 G6 Mini / SFF | **Works** | Original blog; multiple confirmations |
| EliteDesk 800 G4 Mini (65 W) | **Works** | `25Q128JVSIQ`; sometimes leaves the Endpoint Security error |
| ProBook 805 G6 | **Works** | Same pin position; one report of a 15 s Endpoint Security splash timer that a BIOS update did not fix |
| ProDesk 600 G6 | **Works** | Confirmed by commenter |
| ProBook 650 G8 | **Works** | r/laptops user shorted the pin, took **3 tries**, BIOS access restored. Cable plugged in during the procedure |
| HP laptop with `GD25B256DYIG` | **Works** | User says "not the bios one", so the second/EC chip. Pin 2 to the USB port shield; admin password gone. Model not stated |
| Elite Mini 600 G9 | **Not confirmed / fails** | Multiple reports |
| EliteDesk 800 G9 / 800 G9M | **Fails** | Multiple reports |
| ProDesk 400 G6 Mini | **Fails** | No 16 MB Sure Start chip present |
| EliteBook 840 G10 | **No confirmed success** | The r/laptops OP's 2023 unit; HP said replace the motherboard |
| EliteBook 840 G6 / 830 G5 | **Unverified** | The open question for us |

**The laptop data points matter.** The r/laptops thread "BIOS locked HP Elitebook -
Laptop otherwise works fine" adds two laptop successes: a **ProBook 650 G8** (pin
short, 3 tries, cable plugged in) and a second/EC chip marked **`GD25B256DYIG`** (pin 2
to the USB port shield, admin password gone). The OP's **EliteBook 840 G10** did not
succeed, and HP told them to replace the motherboard. So this is no longer a
desktop-only method, but it is still unproven on the 830 G5.

The same thread has a warning for the SPI route: a repairer says that on newer HP you
often get the **Endpoint Security error** after reflashing the main chip, and the fix is
to wipe the serial/identity area in the dump to force **manufacturing mode**, then
re-enter the serial. That is a second rework, not a clean unlock.

**Risks:**

- Bricking. At least one GitHub commenter says the board was bricked; another says the
  jumper wire heated up and smelled of burning on a 2-pin Winbond chip.
- **HP Endpoint Security Controller Problem** or a 15 s splash timer after a successful
  password clear. On newer HP, expect a manufacturing-mode/serial rewrite after an SPI
  reflash.
- Wrong pin. Pin 1 is marked by a dot/notch; pin 2 on a 25-series WSON8 is usually
  `DO`/`IO1`, not ground. The desktop method deliberately pulls that signal low at boot;
  on a different board it may be a different signal. Confirm from the schematic.

**Our position:**

- **Do not try this on a customer unit.** Shorting the wrong pin on a powered board can
  destroy the EC or the board.
- If we test it, use a sacrificial HP G6/G5 unit and a current-limited bench PSU.
- Find the GigaDevice EC and confirm pin #2 from the boardview/schematic before
  shorting anything. Photograph the board first.
- If it works on the 830 G5/830 G6, it may be faster and less risky than a WSON8 pogo
  reflash. If it does not, we have lost nothing but bench time on a sacrificial unit.
- Add the result to the per-model survey worksheet either way.

## 3b. Lenovo T14 / X13 — the MEC1663 EC

The ThinkPad threads add one important clue: on the 2020 T14/X13 (`20T3` / `20U4`) the
supervisor password is stored in the **MEC1663 embedded controller**, not only in the
main SPI flash. That means:

- A main-BIOS-only patch may leave the SVP in place.
- r/thinkpad reports two EC routes: **SVOD + MEC1663 JTAG pinout** (Badcaps has a
  "T14s - MEC-1663 - JTAG - Pinout" thread), or **shorting JTAG pins 4/8/10** at boot.
- The cheaper route is **Lenovo Auto-Patcher** on the BIOS dump, but its README says
  it supports Lenovo **up to 8th gen**. Our T14/X13 Gen 1 are **10th gen**, so it may
  not apply; it also may not clear the EC copy.

For 9 units, do not build MEC1663 rework capability. Check the Auto-Patcher's support
on one sacrificial T14; if it refuses or the SVP survives, outsource those units or
swap the board. Full detail and the YouTube guides are in
`24-technique-deep-dive.md` §3.

## 4. What this confirms in our plan

| Reddit finding | What we already planned | Action |
|---|---|---|
| WSON8 cannot use a SOIC8 clip | Yes (`21-hardware-bom.md`) | Keep WSON8 pogo probes + hot air as the core |
| 1.8 V WSON8 exists | Yes | Keep the 1.8 V adapter and VCC measurement |
| CH341A can output 5 V | Partly | Add the explicit "measure/logic-level" check; prefer T48 |
| HP vendor route is dead post-2018 | Yes | Keep SPI reflash as the primary HP route |
| RCUnlocker / HPUnlocker work on HP dumps | Yes | Add to the software stack and test on a 650 G4/830 G5 dump |
| Clean images lose MAC/serial | Yes | Keep "patch in place, never clone" as a hard rule |
| Sure Start EC pin short | **New** | Add to the sacrificial-unit test list as [VERIFY] |

## 5. Reddit caveats

- Reddit is full of confident but wrong answers. The same threads contain "remove the
  CMOS battery", "short the TPM", and "just reinstall Windows" advice that does not
  apply to a modern HP with Sure Start.
- Model and board revision matter. A method that works on a ProDesk 600 G6 may not
  work on an EliteBook 830 G5, even if both are "HP G6".
- Upvotes are not evidence. The Sure Start EC thread has 162 upvotes, but only a few
  confirmed reports; treat it as a lead, not a procedure.
- Paid dump-patching services still exist (~$30), but the DIY tools and community
  patches are now good enough that we should learn the patch ourselves.

## 6. Direct links

- `https://www.reddit.com/r/Hewlett_Packard/comments/1tdx6ka/bypass_hp_bios_password/`
- `https://www.reddit.com/r/homelab/comments/1uju8hs/removing_hp_elite_mini_600_g9_bios_password/`
- `https://www.reddit.com/r/laptops/comments/1th7s07/bios_locked_hp_elitebook_laptop_otherwise_works/`
- `https://www.reddit.com/r/hacking/comments/1nmpcae/any_way_to_get_past_a_bios_administrator_password/`
- `https://www.reddit.com/r/homelab/comments/1vi4oce/locked_bios_on_hp_elite_mini_600_g9/`
- `https://github.com/RhysGoodwin/rhysgoodwin.github.io/issues/4` (mirror of the
  original Sure Start EC article and its comments)
- `https://blog.rhysgoodwin.com/hp-elite-800-bios-password-removal/` (original, now 404)
- `https://www.reddit.com/r/thinkpad/` — search for "ThinkPad BIOS: Reading, Patching,
  and Flashing", "T14 Gen 1 - Bios password reset?", and "bypass a thinkpad's supervisor
  password"

## 7. Open questions

- Does the Sure Start EC pin-short work on the **830 G5** or **830 G6**? If yes, which
  GigaDevice part and pin #2 location?
- Are there boardviews/schematics for the 830 G5 that show the Sure Start EC and its
  pin 1 marker?
- Does RCUnlocker clear the 830 G5 password on the **16 MB** dump on our BIOS
  revisions, and does the unit still boot with identity intact?
- Does `libv/hp_bios_passwd_erase` clear the 650 G4 on our revisions?
- Are the newer HP models (G8/G10/Fury G8) 1.8 V or 3.3 V?
