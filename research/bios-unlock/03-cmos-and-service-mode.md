# 03 — CMOS and service-mode tricks

When software fails, the next rung is **electrical / board-level but non-destructive**:
CMOS clear and vendor service mode. No SPI flashing involved.

## 1. CMOS battery / CLR_CMOS

On **legacy** boards (roughly pre-2014), the setup password often lived in CMOS RAM
backed by the CR2032 coin cell. Removing the battery and shorting the CLR_CMOS jumper
(or holding the power button with the battery out) clears it.

Procedure:
1. Disconnect AC + main battery.
2. Remove the CR2032 (some boards: a CLR_CMOS jumper instead).
3. Hold power button ~30 s (drains residual charge) or short the jumper ~10 s.
4. Reassemble and boot — the setup password is gone.

> **Reality check.** On almost all **UEFI** laptops (Haswell and newer), the password is
> stored in **SPI flash**, not CMOS. Removing the battery only resets the clock and
> settings, not the password. Still do it first: it's 5 minutes and occasionally a
> board stores the password in a battery-backed RTC/NVRAM region.

## 2. Motherboard jumpers / pads

Many business laptops have a marked **service-mode / password-clear** jumper or a pair
of pads:

- **Dell Latitude/Precision:** service-mode jumper near the memory (often labelled
  `PSWD` or service mode). Booting with it jumpered disables/bypasses the password,
  then you clear the password in BIOS and remove the jumper.
- **HP EliteBook/ProBook:** some generations have a clear-password jumper/pads near the
  battery connector.
- **Acer / ASUS:** frequently a `CLRPWD` / password jumper.

Always check the board's service manual for the exact location — random shorting can
damage the board.

## 3. Lenovo ThinkPad service mode

Lenovo has **no master password** and the supervisor password is **not** cleared by the
CMOS battery. Instead:

- Some generations expose a **service-mode** (two pads / pins near the keyboard
  connector or a "blue switch") that, when shorted at boot, bypasses the supervisor
  password long enough to enter BIOS and clear it.
- Many newer ThinkPads store the password in a dedicated **security/EEPROM chip**, and
  there is **no** service-mode bypass — the fix is EEPROM reprogramming (a variant of
  the SPI work in `04`).

So for ThinkPads: try service mode if the model has it; otherwise go straight to
`04-spi-flash-reflash.md`.

## 4. Dell "service mode" (fleet feature)

On some Dell business models, a **service mode** can be entered from the boot menu,
which allows the IT department to clear a lost password if the asset was provisioned
for it. Rare on donated stock, but check the model's manual before reaching for the
programmer.

---

**When these fail** (the common case on modern units), the password is in SPI flash and
the fix is a **firmware reflash** — the next doc.
