# 12 — Bench setup (Windows 11) — quick reference

Everything for the SPI unlock bench, tuned for a **Windows 11** workstation.

## Hardware (unchanged)

| Item | Note |
|---|---|
| **XGecu T48** (preferred) or CH341A (black PCB) | T48 is the production programmer; CH341A is the budget backup |
| **WSON8 pogo-pin probe (8x6)** | in-circuit read/write on the 830 G5's WSON8 pads |
| SOIC8 test clip | only for SOP8 chips — does not fit WSON8 |
| 1.8 V adapter | only if the chip is 1.8 V (the 830 G5 W25Q256 is 3.3 V) |
| WSON8 socket **or** hot-air + flux | desolder if the pogo read is unstable |
| Bench PSU + croc clips | optional |
| Loupe / magnifier | read chip markings |

## Windows 11 software stack

1. **Zadig** — install the **WinUSB** driver for the CH341A. By default Windows loads
   the CH340 *serial* driver, which flash tools can't use. In Zadig: Options → List All
   Devices → select the CH340/CH341A → replace with **WinUSB**.
2. **NeoProgrammer** (or **AsProgrammer**) — GUI chip reader/writer for the CH341A;
   supports Winbond `W25Q256`. **Primary tool** — detect chip → Read → Write → Verify.
3. **flashrom** (Windows build) — optional, scriptable alternative to NeoProgrammer;
   needs the Zadig step done first.
4. **UEFITool** (Windows build) — open the BIOS region, find the **"Setup"** NVRAM
   variable, clear the admin-password field.
5. **AMIBCP** (Windows-only) — optional, AMI setup-structure editor.
6. **HxD** — hex editor for byte-diffing (the buy-one-diff-one trick) and patch checks.
7. **RCUnlocker** — run it on the **830 G5's 16 MB EC dump** first; it is the
   2023–2025 Badcaps consensus. If it refuses the file or does not clear the password,
   try **`schorschii/HPUnlocker.py`** on the same dump. Keep the original dump and test
   one chip at a time. See `24-technique-deep-dive.md` §2.

> **WSL2 is not recommended** for the CH341A — USB passthrough is unreliable. Use the
> native Windows tools above.

## Do I need to remove the chip?

- **SOP8** (pins on the sides) → **clip it in place**, laptop powered off (battery + AC
  removed). No removal.
- **WSON8** (pads underneath) → **pogo-pin probe in place** first; if the read is
  unstable, desolder with hot-air and use a WSON8 socket.
- **In-circuit safety:** the CH341A can back-power the board via VCC. Either clip fully
  with battery/AC disconnected, or leave the programmer's 3.3 V disconnected and power
  the board from the bench PSU.

## Workflow on Windows

1. Zadig → WinUSB on the CH341A.
2. NeoProgrammer → Detect → **Read** → save `dump1.bin`; read again → `dump2.bin`.
3. Hash-check both match (PowerShell):
   ```
   Get-FileHash dump1.bin -Algorithm SHA256
   Get-FileHash dump2.bin -Algorithm SHA256
   ```
4. Patch the dump (UEFITool: clear the "Setup" password field) → save `patched.bin`.
5. NeoProgrammer → **Write** `patched.bin` → **Verify**.
6. Reassemble → boot test → confirm no password + serial/UUID/MAC/MSDM unchanged.

See `10-hp-830g5-spi-sop.md` for the full 830 G5 procedure and `11` for the dump map.
