# tScrub Proxmox test harness — host-specific settings.
# Copy to config.sh and edit for your Proxmox host.

# Proxmox storage for VM disks (must support "images" content, e.g. local-lvm).
PROXMOX_STORAGE="local-lvm"

# Linux bridge for VM networking.
BRIDGE="vmbr0"

# Where ISO/cloud images live on the Proxmox host.
ISO_DIR="/var/lib/vz/template/iso"
IMAGE_DIR="/var/lib/vz/tscrub-test"

# Debian cloud image (used as the test VM base).
DEBIAN_IMG_NAME="debian-12-genericcloud-amd64.qcow2"
DEBIAN_IMG_URL="https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2"

# Published tScrub script (full build, embedded sedutil payload).
TSCRUB_URL="https://tscrub.com/downloads/tscrub.sh"

# Static IP baked into the prepared image (scenarios run sequentially, so one
# IP is enough). Pick a free address on your LAN.
TEST_IP="192.168.0.243"
GATEWAY="192.168.0.1"
DNS="192.168.0.1"

# The image has no default user; prepare-image.sh enables root key auth, so we
# SSH as root.
VM_USER="root"

# File containing the public key baked into the prepared image (created by setup.sh).
SSH_PUBKEY="/var/lib/vz/tscrub-test/test.pub"

# File containing the free-tier test licence (see README, not committed).
TEST_LICENCE="/var/lib/vz/tscrub-test/test.lic"

# Upload-test FTP server (see 06_upload_ftp scenario).
# Port 21 (not a high port) — tscrub's lftp upload connects to the default
# FTP port; the harness runs as root so binding 21 is fine.
FTP_PORT="21"
FTP_USER="tscrub"
FTP_PASS="testpass"
