#!/usr/bin/env bash
# One-time image provisioning. The Debian 12 genericcloud image ships with no
# default user and its cloud-init NoCloud datasource is NOT auto-detected by
# Proxmox, so we provision it directly:
#   - SSH host keys            (so sshd can start)
#   - root key auth            (PermitRootLogin prohibit-password)
#   - static eth0 address      (systemd-networkd; no cloud-init, no DHCP)
#   - mask cloud-init          (so it never runs and overrides our config)
# Run once, after setup.sh has downloaded the image and generated the SSH key.
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HARNESS_DIR/config.sh"

IMG="$IMAGE_DIR/$DEBIAN_IMG_NAME"
[[ -f "$IMG" ]] || { echo "missing $IMG (run setup.sh first)" >&2; exit 1; }
[[ -f "$SSH_PUBKEY" ]] || { echo "missing $SSH_PUBKEY (run setup.sh first)" >&2; exit 1; }

qemu-nbd --disconnect /dev/nbd0 2>/dev/null || true
sleep 1
qemu-nbd --connect=/dev/nbd0 "$IMG"
sleep 2
mount -o rw /dev/nbd0p1 /mnt/dbg

cleanup() {
    umount /mnt/dbg/dev  2>/dev/null || true
    umount /mnt/dbg/sys  2>/dev/null || true
    umount /mnt/dbg/proc 2>/dev/null || true
    umount /mnt/dbg      2>/dev/null || true
    qemu-nbd --disconnect /dev/nbd0 2>/dev/null || true
}
trap cleanup EXIT

# 1. SSH host keys
mkdir -p /tmp/tscrub-k
rm -f /tmp/tscrub-k/ssh_host_*
ssh-keygen -q -t ed25519 -N "" -f /tmp/tscrub-k/ssh_host_ed25519_key
ssh-keygen -q -t rsa -b 4096 -N "" -f /tmp/tscrub-k/ssh_host_rsa_key
cp /tmp/tscrub-k/ssh_host_* /mnt/dbg/etc/ssh/
chmod 600 /mnt/dbg/etc/ssh/ssh_host_*_key

# 2. root key auth
mkdir -p /mnt/dbg/root/.ssh
cp "$SSH_PUBKEY" /mnt/dbg/root/.ssh/authorized_keys
chmod 700 /mnt/dbg/root/.ssh
chmod 600 /mnt/dbg/root/.ssh/authorized_keys
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /mnt/dbg/etc/ssh/sshd_config
grep -q '^PermitRootLogin' /mnt/dbg/etc/ssh/sshd_config || echo 'PermitRootLogin prohibit-password' >> /mnt/dbg/etc/ssh/sshd_config

# 3. static network via systemd-networkd (the image's renderer)
mkdir -p /mnt/dbg/etc/systemd/network
cat > /mnt/dbg/etc/systemd/network/eth0.network <<EOF
[Match]
Name=eth0

[Network]
Address=$TEST_IP/24
Gateway=$GATEWAY
DNS=$DNS
EOF

# 4. mask cloud-init so it never runs
for u in cloud-init-local cloud-init cloud-config cloud-final; do
    ln -sf /dev/null "/mnt/dbg/etc/systemd/system/$u.service"
done

# 5. install the FULL (non-cloud) kernel. The genericcloud image ships
#    linux-image-cloud-amd64, which drops the ahci module — so SATA/AHCI test
#    disks never appear as /dev/sdX. The standard kernel has SATA_AHCI=m.
rm -f /mnt/dbg/etc/resolv.conf   # image's resolv.conf is a dangling symlink
cp /etc/resolv.conf /mnt/dbg/etc/resolv.conf
mount -t proc  proc  /mnt/dbg/proc
mount -t sysfs sysfs /mnt/dbg/sys
mount --bind /dev /mnt/dbg/dev
chroot /mnt/dbg /bin/bash <<'CHROOT'
set -e
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq linux-image-amd64
# Purge every installed cloud-kernel package (metapackage AND the real
# linux-image-*-cloud-amd64) so grub can only boot the full kernel.
for p in $(dpkg-query -W -f='${Package}\n' | grep -E '^linux-image-.*cloud'); do
    apt-get purge -y -qq "$p"
done
update-grub || true
CHROOT
umount /mnt/dbg/dev
umount /mnt/dbg/sys
umount /mnt/dbg/proc

echo "Image prepared: $IMG (root@$TEST_IP)"
