#!/usr/bin/env bash
# Shared helpers for the tScrub Proxmox test harness. Runs ON the Proxmox host.
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="$HARNESS_DIR/config.sh"
[[ -f "$CONFIG" ]] || { echo "missing $CONFIG (copy config.example.sh)" >&2; exit 1; }
source "$CONFIG"
SSH_KEY="$IMAGE_DIR/test"

# ---- VM lifecycle -----------------------------------------------------------

next_vmid() {
    local vmid
    for vmid in $(seq 9000 9999); do
        qm status "$vmid" &>/dev/null || { echo "$vmid"; return 0; }
    done
    echo "no free VMID in 9000-9999" >&2
    return 1
}

# Create a Debian test VM whose boot disk is virtio-blk (/dev/vda) — ignored by
# tscrub.sh — with a serial console and SSH (cloud-init, static IP).
debian_vm_create() {
    local vmid="$1" name="$2" ip="$3"
    qm create "$vmid" --name "$name" --machine q35 --memory 2048 --cores 2 \
        --net0 "virtio,bridge=$BRIDGE" --ostype l26 \
        --serial0 socket --vga serial0

    # Import the prepared Debian image as the (safe) virtio-blk boot disk.
    # prepare-image.sh already baked in root key auth + the static IP.
    # Capture the ACTUAL imported disk name — a stale volume under the same
    # VMID would otherwise make importdisk pick disk-1 while we attach disk-0.
    local disk
    disk="$(qm importdisk "$vmid" "$IMAGE_DIR/$DEBIAN_IMG_NAME" "$PROXMOX_STORAGE" \
        | sed -n "s/.*successfully imported disk '\(.*\)'.*/\\1/p" | tail -1)"
    [[ -n "$disk" ]] || { echo "importdisk failed for VM $vmid" >&2; return 1; }
    qm set "$vmid" --virtio0 "$disk,format=raw"
    qm set "$vmid" --boot order=virtio0
}

# Attach a disposable test disk. Disks are only ever attached to the test VM.
disk_sata()  { qm set "$1" --sata"$2"  "$PROXMOX_STORAGE:$3,format=raw"; }
disk_scsi()  { qm set "$1" --scsihw virtio-scsi-pci; qm set "$1" --scsi"$2" "$PROXMOX_STORAGE:$3,format=raw"; }

disk_nvme() {
    local vmid="$1" idx="$2" gb="$3"
    local img="$IMAGE_DIR/vm-$vmid-nvme$idx.qcow2"
    mkdir -p "$IMAGE_DIR"
    qemu-img create -f qcow2 "$img" "${gb}G" >/dev/null
    # Existing -args are additive; append one nvme device at a time.
    local prev
    prev="$(qm config "$vmid" | sed -n 's/^args: //p' || true)"
    qm set "$vmid" -args "${prev} -drive file=$img,if=none,id=nvme$idx,format=qcow2 -device nvme,drive=nvme$idx,serial=TSNVME$idx"
}

vm_start() {
    qm start "$1"
}

# Wait until the VM answers on SSH. Returns the IP on success.
vm_wait_ssh() {
    local vmid="$1" ip="$2" timeout="${3:-180}" t=0
    while (( t < timeout )); do
        if ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=3 \
            "$VM_USER@$ip" true 2>/dev/null; then
            echo "$ip"
            return 0
        fi
        sleep 3; t=$((t+3))
    done
    echo "VM $vmid never became reachable via SSH at $ip" >&2
    return 1
}

# SSH to a running test VM.
vm_ssh() {
    local ip="$1"; shift
    ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 \
        "$VM_USER@$ip" "$@"
}

# Copy tscrub.sh + the test licence into a running test VM.
vm_push_files() {
    local ip="$1"
    vm_ssh "$ip" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
    scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
        "$IMAGE_DIR/tscrub.sh" "$TEST_LICENCE" "$VM_USER@$ip:/tmp/tscrub/" >/dev/null
}

# Install the tools tscrub.sh depends on (Debian cloud image is minimal).
vm_prepare() {
    local ip="$1"
    vm_ssh "$ip" 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nvme-cli hdparm smartmontools nwipe lftp dmidecode >/dev/null'
}

# Run tscrub.sh in the VM, feeding the CoC ID via the controlling tty. Echoes
# the combined output. Remaining args are passed straight to tscrub.sh.
vm_run_tscrub() {
    local ip="$1" cocid="${2:-12345}"; shift 2
    vm_push_files "$ip"
    # Feed the CoC ID, then "C" to answer the post-run prompt (Continue).
    printf '%s\nC\n' "$cocid" | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
        "$VM_USER@$ip" "cd /tmp/tscrub && sudo ./tscrub.sh $* 2>&1"
}

vm_destroy() {
    local vmid="$1"
    qm stop "$vmid" &>/dev/null || true
    qm destroy "$vmid" --purge --skiplock &>/dev/null || true
    rm -f "$IMAGE_DIR/vm-$vmid-nvme"*.qcow2 2>/dev/null || true
}

# ---- Assertions -------------------------------------------------------------

assert_contains() { # haystack needle label
    grep -qF -- "$2" <<<"$1" && { echo "PASS: $3"; } || { echo "FAIL: $3 (missing \"$2\")" >&2; return 1; }
}

assert_not_contains() {
    grep -qF -- "$2" <<<"$1" && { echo "FAIL: $3 (unexpected \"$2\")" >&2; return 1; } || { echo "PASS: $3"; }
}
