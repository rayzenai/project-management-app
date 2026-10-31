#!/bin/sh
# dind's entrypoint (see Dockerfile): /var/lib/docker, where every image,
# container and job workspace lives, is an ext4 filesystem in a file of
# DIND_DISK_SIZE on the dind-data volume, mounted through a loop device.
# A job that fills it fills only that file. Then dockerd starts as the
# image starts it.
set -eu

disk=/store/docker.ext4

if [ ! -f "$disk" ]; then
    truncate -s "${DIND_DISK_SIZE:-30G}" "$disk"
    mkfs.ext4 -q -F -m 0 "$disk"
fi

# After an unclean stop: repair what is safe to repair, else refuse to start.
e2fsck -p "$disk" || [ $? -le 1 ]

# The image declares /var/lib/docker a volume, so something is always
# mounted there (on the VM's own disk): mount the file over it, unless a
# loop device is there already.
mkdir -p /var/lib/docker
grep -q '^/dev/loop[0-9]* /var/lib/docker ' /proc/mounts || mount -o loop "$disk" /var/lib/docker

exec dockerd-entrypoint.sh "$@"
