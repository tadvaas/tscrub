# Appliance networking & upload troubleshooting

Field notes on the two failure modes that stopped the Lenovo X13 from uploading
its report to tscrub.com, and the techniques that fixed them. Both are now
covered by regression-proof code in the appliance and the standalone script.

## 1. "No IP after boot" — late link-up vs. hotplug re-DHCP

**Symptom:** the NIC's link comes up a few seconds *after* the boot-time DHCP
pass (e.g. Intel e1000e negotiating at ~6 s), so the appliance never gets an
IPv4 address — the end-of-run upload then fails with a connect error.

**Root cause:** ShredOS's `shredos_net.sh` monitors `/sys/class/net/*/carrier_*_count`
and, on carrier-up, ran `ifup <dev>`. busybox ifupdown's `ifup` is a **no-op**
when the interface is already in `/var/run/ifstate` ("interface already
configured"), so no fresh DHCP ever ran. A later attempt to "fix" this with
`ifdown -f <dev>; ifup <dev>` made it worse: `ifdown -f` drops the carrier,
which increments `carrier_down_count` and re-triggers the carrier-**down**
handler (which also does `ifdown -f; ifup`), and `ifup` re-triggers the
carrier-**up** handler — an infinite link-bounce loop that tears down every
DHCP ACK before it can be applied.

**Technique (correct fix):** on carrier-up, re-request DHCP **without touching
link state** — kill the stale client and start a fresh one directly:

```sh
killall udhcpc 2>/dev/null
udhcpc -i "$device" -b -R -O search -O staticroutes >/dev/null 2>&1 &
```

Never use `ifdown -f` inside the carrier-up handler — bouncing the link is what
creates the loop.

## 2. "curl: (60) SSL certificate verify" — dead RTC / wrong clock

**Symptom:** the report reaches the upload step but curl fails with
`(60) SSL certificate verify` even though the server certificate is valid. The
machine's BIOS clock is wrong (dead RTC battery, no way to set it), so the
certificate's validity window doesn't match the system time.

**Root cause:** TLS certificate verification depends on the client clock. A
wrong clock makes a valid certificate look not-yet-valid or expired.

**Technique (correct fix):** try the normal verified upload first; if it fails
specifically with curl error 60 (clock skew), retry once with `curl -k`:

```sh
if ! resp="$(curl "${args[@]}" "$url" 2>&1)"; then
    if [[ "$resp" == *"curl: (60)"* ]]; then
        resp="$(curl -k "${args[@]}" "$url" 2>&1)" || { ...; }
    fi
fi
```

Machines with correct clocks keep full verification; only broken-clock machines
fall back.

## Diagnostics toolkit used

- **`tScrub_debug_*.txt`** on the report USB stick (saved at end of every run):
  `ip link` / `ip addr` / `ip route`, `/etc/resolv.conf`, `/var/run/ifstate`,
  `/var/log/shredos_net.log`, `dmesg`, and `ps w`.
- **Proxmox QEMU harness** (`/root/tscrub-tests/latelink.sh` on the Proxmox
  host) boots the real appliance ISO with an e1000 NIC whose link is down at
  boot and up ~6 s later, to reproduce late-link issues. The appliance kernel
  has no serial driver and no `virtio-net`, so use `e1000` and rely on the USB
  debug snapshot + host-side `tcpdump` on the bridge.
