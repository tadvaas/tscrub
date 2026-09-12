# tScrub tests

A bash 4+ test harness that exercises the sanitization logic **without touching
real drives**. It does this by:

- mocking the external commands (`nvme`, `hdparm`, `sedutil-cli`, `blockdev`,
  `nwipe`, `lscpu`, `lspci`, `dmidecode`, `realpath`, `readlink`, …) via
  `tests/fakes/bin` on `$PATH`, and
- pointing device discovery at a fake block-device tree through the
  `SYS_BLOCK_DIR` environment variable (`tests/fixtures/sys/block`).

## Prerequisites

The production script requires bash 4+ (associative arrays, `coproc`). On macOS
the stock `/bin/bash` is 3.2, so install a modern bash first:

```sh
brew install bash
```

## Run

```sh
/opt/homebrew/bin/bash tests/run.sh
```

`run.sh` first syntax-checks every `src/*.sh` and `scripts/*.sh`, then runs each
`tests/test_*.sh`.

## What's covered

| File                | Covers |
|---------------------|--------|
| `test_ui.sh`        | `ui::format_runtime`, `ui::eta_text_for`, `ui::all_drives_terminal`, `ui::any_drive_failed` |
| `test_classify.sh`  | `device::classify` mappings, incl. the `CAP_ATA_FROZEN` → `FROZEN` fix |
| `test_execute.sh`   | `device::execute` for ATA frozen/enhanced and NVMe `0x4286`→BLOCKED / success |
| `test_loop.sh`      | `ui::loop` EOF break, the terminal-without-EOF break, and `wipe_start` recording |
| `test_discover.sh`  | `device::discover` over the fake tree (USB exclusion, bus/type/serial) |
| `test_smoke.sh`     | End-to-end dry-run flow (discover → detect → build → execute → loop) |

## Notes

- `test_loop.sh` deliberately holds the IPC pipe open after the last terminal
  status to prove the UI loop no longer spins forever (see `ui::loop` timeout
  branch).
- The fake `hdparm`/`nvme` behavior is controlled by `FAKE_*` environment
  variables (e.g. `FAKE_HDPARM_MODE=frozen`, `FAKE_NVME_SSTAT=0x3`); see the
  fake scripts for the full list.
- A real end-to-end run still requires Linux + root + real (or loop) devices;
  these tests cover the logic that can be exercised portably.
