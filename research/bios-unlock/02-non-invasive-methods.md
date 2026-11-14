# 02 — Non-invasive methods (always try first)

These require **no disassembly and no flashing**. Do them in order before opening the
case.

## 1. tScrub's own sysfs clear (the fastest win)

tScrub already has a remote BIOS unlock that clears the admin password over the Linux
`firmware_attributes` / `hp-wmi` interface:

- **Dell / Lenovo:** writes `current_password` then blanks `new_password` on the
  admin-password attribute under `/sys/class/firmware_attributes/*/attributes/`.
- **HP:** falls back to `hp-wmi/bios_password` — **verified dead on the EliteBook 830 G5
  (kernel 6.18): no `hp_wmi` module, no node** (see `08-empirical-findings.md`).

This works **only when**:
1. The OS can *reach* the attribute (BIOS exposes it to the OS), and
2. The admin password is either not set (trivial) **or** the current password is known.

Because the *current* password is usually what's lost, the sysfs path mostly helps with
**clearing a known password / post-grading hygiene**, not the lost-password case.
Still: run it — some fleets have `current_password` left blank because the lock is
actually a *power-on* password or an unset-but-prompting quirk.

## 2. Master-password generators

Several vendors derive a master/backdoor password from the **serial / service tag**.
There are public generators (the best known is **bios-pw.org**). These are legitimate
for owned hardware.

- **Dell:** enter the service tag (e.g. `ABC1234`) and the generator returns a master
  password for the *setup* password. Many older Dell models also accept the suffix
  trick: type the service tag followed by `-595B` (and on some generations `-2F2A`) in
  the password field; it accepts even with the "wrong" suffix on correct tag.
- **HP:** after **three** failed attempts, the BIOS shows a hash/code (e.g.
  `i 78634292`). That code goes into the generator to produce the unlock password.
- **Lenovo:** no public master password. Skip to service mode / SPI.

Try the generator output in both the setup password prompt and the power-on password
prompt.

## 3. Historical default / backdoor passwords

Still worth a quick try on legacy boards (a surprising number of decommissioned units
ship with factory defaults):

```
AMI, AMI_SW, A.M.I., AWARD, AWARD_SW, LKWPETER, j262, 589589, 589721,
BIOS, CMOS, phoenix, PHOENIX, ALFAROME, CONDO, CONCAT, djonet,
SER, SKY_FOX, setup, toshiba, Toshiba
```

(AMI/Award were the big two legacy BIOS vendors; the "SW" variants are their known
backdoors. Case usually insensitive.)

## 4. Vendor configuration tools

When the BIOS is *unlocked but needs bulk reconfiguration* (e.g. clearing a known
password fleet-wide, re-enabling Secure Boot, resetting to factory), use the vendor
CLI instead of doing it by hand:

| Vendor | Tool |
|---|---|
| Dell | Dell Command Configure (`cctk`) |
| HP | HP BIOS Configuration Utility (BCU) |
| Lenovo | WMI scripts / `thinkpad_acpi` |

These can also *reflash* firmware (see `04`) but are primarily config tools.

## 5. Vendor unlock programs (owner-assisted)

- **Dell** and **HP** support can provide an unlock code or master password for the
  registered owner of record. Needs service tag + proof of ownership.
- **Apple** unlocks EFI firmware passwords and Activation Lock with a proof-of-purchase
  invoice containing the serial. There is **no** self-serve master password and
  Apple does not hand one out.

If a unit has a clear owner-of-record (many corporate fleet units still registered),
the vendor path is often cheaper than bench time. Queue those units separately.

---

**If none of the above clears the lock**, move to
[`03-cmos-and-service-mode.md`](03-cmos-and-service-mode.md), then
[`04-spi-flash-reflash.md`](04-spi-flash-reflash.md).
