# tScrub Proxmox Test Harness

Automated end-to-end testing of `tscrub.sh` and the tScrub appliance ISO on a
dedicated Proxmox host, using disposable virtual machines and virtual disks.

> **Safety model** — every test runs inside an isolated, disposable QEMU VM.
> The VM can only see block devices explicitly attached to it: it can **never**
> touch the Proxmox host storage or any other VM's disks. Each scenario creates
> its own fresh, throwaway disks and destroys the VM when finished. Blast radius
> of a mistake is limited to that one test VM (`qm destroy` and re-run).

## Directory layout

```
tests/
├── README.md              # this file
└── proxmox/
    ├── config.sh          # host-specific settings (copy from config.example.sh)
    ├── config.example.sh  # template
    ├── lib.sh             # shared helpers (VM create / disks / capture)
    ├── setup.sh           # one-time host setup (images, licence, SSH key)
    ├── prepare-image.sh   # provisions the base image (root SSH + static IP)
    ├── run.sh             # runner: ./run.sh <scenario> [<scenario> ...]
    └── scenarios/         # one script per scenario
```

## Prerequisites

- A **dedicated** Proxmox VE 8.x/9.x host (not the one running production VMs).
- Root SSH access: `ssh root@<host>` (key auth).
- A Linux bridge with network access (e.g. `vmbr0`) for the upload tests.
- A test **licence** file (free tier is fine) — generated with
  `issue_licence.py` on the build host (see below).
- ~2 GB free disk for the images + the tScrub ISO (~155 MB).

## One-time setup

1. Generate a test licence (on the build host, where `vendor.key` lives):

   ```bash
   ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && python3 issue_licence.py "Test Rig" 2030-01-01 --tier free --json' \
     > tests/proxmox/test.lic
   ```

   (`test.lic` is git-ignored; free-tier licences carry no secret key.)

2. Copy the harness to the Proxmox host and configure it:

   ```bash
   scp -r tests/proxmox root@<proxmox-host>:/root/tscrub-tests/
   ssh root@<proxmox-host> 'cd /root/tscrub-tests && cp config.example.sh config.sh'
   # edit config.sh if your bridge/storage/network differ
   ssh root@<proxmox-host> 'cd /root/tscrub-tests && ./setup.sh'
   ```

3. `setup.sh` downloads the tScrub appliance ISO, a Debian 12 cloud image, the
   published `tscrub.sh`, and generates an SSH keypair for the test VMs.

## Running scenarios

```bash
ssh root@<proxmox-host> 'cd /root/tscrub-tests && ./run.sh all'
# or one at a time:
ssh root@<proxmox-host> 'cd /root/tscrub-tests && ./run.sh 01_nvme_only'
```

Each scenario prints a `PASS`/`FAIL` line and exits non-zero on failure.
Test VMs use VMIDs `9000`–`9999` (production VMs are untouched) and are
destroyed automatically on completion (and on failure).

## Scenario matrix

| # | Scenario | What it verifies |
|---|---|---|
| 01 | `01_nvme_only` | A single NVMe disk is discovered, classified, wiped, and reported `COMPLETED`. |
| 02 | `02_sata_only` | A single SATA (AHCI) disk is discovered and wiped. |
| 03 | `03_mixed` | NVMe + SATA + SCSI wiped in parallel; all report `COMPLETED`. |
| 04 | `04_no_licence` | `tscrub.sh` without a licence exits non-zero with a clear licence error. |
| 05 | `05_dry_run` | `--dry-run` writes a `DRY-RUN` report and does **not** touch the disks. |
| 06 | `06_upload_ftp` | The report is uploaded to a local FTP server (all three files arrive). |

Each scenario boots a **Debian test VM** whose *boot* disk is `virtio-blk`
(`/dev/vda`) — which `tscrub.sh` deliberately ignores — and attaches the disks
under test as SATA/SCSI/NVMe. This guarantees `tscrub.sh` can only ever wipe
the disposable test disks, never its own OS.

## How the test VMs work

- **Boot disk** = `virtio-blk` (`/dev/vda`) — invisible to `tscrub.sh`.
- **Test disks** = SATA (`/dev/sda…`), SCSI (`/dev/sdX`), NVMe (`/dev/nvme0n1`).
- **Console** = serial (`--vga serial0` + `--serial0 socket`), captured with
  `qm terminal <vmid>`.
- **Provisioning** = the Debian cloud image is prepared once by
  `prepare-image.sh`: SSH host keys, root key auth, and a static `$TEST_IP`
  (systemd-networkd); cloud-init is masked. VMs are driven over SSH as root —
  no cloud-init, no DHCP, one shared IP (scenarios run sequentially).
- The runner copies `tscrub.sh` + `test.lic` into the VM, runs the scenario,
  greps the output for the expected outcome, then destroys the VM.

## Known limitations (what this harness does NOT test)

- **Real firmware erase / SMART / frozen / OPAL / Block-SID** — QEMU drives are
  emulated (erase is a no-op, SMART reports `UNSUP`, no TCG subsystem). These
  need a real machine with real disks (see the physical-machine runbook).
- **The appliance ISO boot path** — covered separately as a smoke test (boot +
  screenshot); the script-level logic is what the matrix above exercises.

## Troubleshooting

- `qm terminal <vmid>` drops input but shows output → the VM isn't answering;
  check `qm status` and the serial console with `--vga serial0`.
- "No free VMID" → a previous run left a VM behind; `qm list`, then
  `qm destroy <vmid> --purge`.
- SSH to a test VM times out → confirm the static IP in `config.sh` is free on
  the bridge and matches `GATEWAY`.
