# Backups of the SDOC VM

Every database and every stored file on the SDOC VM (`10.5.23.138`) is backed
up 12 times a day to a disk of its own, encrypted so that the VM itself cannot
read its backups. Set up on 2026-11-01.

## What, when and where

- **When**: hourly from 08:00 to 18:00 and at 01:00, Nepal time
  (`sdoc-backup.timer`; `systemctl list-timers sdoc-backup.timer` shows the
  next run). A run takes seconds, except the weekly full copy of the files.
- **Every run**:
  - a `pg_dump -Fc` of each database: the tracker (`tasks-postgres`), the
    SDOC portal (`sdoc-postgres`), the forge (`git-postgres`) and Dokploy's own
    (`dokploy-postgres`); together about 12 MB;
  - the RustFS data directory (`/srv/rustfs/data`: the portal's files, every
    bucket, its users and settings), in weekly chains: the first run of the
    week (Sunday 01:00) copies everything (about 12 GB, around 20 minutes), the
    others only what changed since the run before (GNU tar
    `--listed-incremental`).
- **At 01:00 only**: each database's roles (`pg_dumpall --roles-only`), the
  forge's repositories and data, Dokploy's settings (`/etc/dokploy` without
  the re-cloned app sources and logs) and the VM's own configuration
  (`/etc/fstab`, `/etc/systemd/system`, `/usr/local/sbin`, root's crontab).
- **Kept**: databases every run for 7 days and the 01:00 ones for a year;
  the files in the last five weekly chains; forge, Dokploy and VM settings
  for 30 nights.
- **Where**: `/srv/backups`, the logical volume `backups` (200 GB, XFS) in
  `backup-vg` on the VM's second disk (`xvdc1`), not the system disk, so a
  mistake or a damaged filesystem on the system disk cannot take the backups
  with it, and a full backup volume cannot fill a live one. Like the other
  volumes it is mounted with `nofail` over an immutable directory, and the
  script refuses to run when it is not mounted. `xvdc2` (300 GB) is unused,
  kept for RustFS to grow into (add it to `ubuntu-vg`, then
  `lvextend -r`).

```
/srv/backups/
  db/{tracker,portal,forge,dokploy}/<date>_<HHMM>.dump.age    (+ .roles.sql.age at 01:00)
  files/<Sunday>/<date>_<HHMM>-full.tar.age, ...-inc.tar.age, state.snar
  forge/  dokploy/  vm/<date>_0100.tar.age
  last-run                                                    (time and ok/FAILED)
```

## Encryption

Everything is encrypted with [age](https://age-encryption.org) to the public
key in `/etc/sdoc-backup/recipient.txt`. The private key was generated in the
VM's memory, written only to `backup-key.txt` in the `sdoc` checkout (ignored
by git) and wiped from the VM, so a copy of the VM's disks shows nothing
readable. **Keep `backup-key.txt` in a password manager as well: without it no
backup can be restored.** Restoring RustFS also needs its root keys and
`RUSTFS_IAM_MASTER_KEY` (`rustfs-access.txt`), which encrypts its users.

## Checking

```sh
systemctl list-timers sdoc-backup.timer      # next and last run
journalctl -u sdoc-backup --since today      # "ok: ..." / "FAILED: ..." per step
cat /srv/backups/last-run; df -h /srv/backups
```

A run exits non-zero, and `systemctl status sdoc-backup` shows it failed,
when any step fails; it warns at 80% full. Nothing alerts anyone yet.

## Restoring

On a machine with `age` (`brew install age`) and the key:

```sh
# A database (here the tracker), into a new empty database:
age -d -i backup-key.txt tracker/2026-11-01_1400.dump.age \
  | pg_restore -d tasks_restored --no-owner --no-privileges

# The files: the week's full copy, then every incremental after it, in order.
mkdir restore && for f in $(ls files/2026-11-01/*.tar.age | sort); do
  age -d -i backup-key.txt "$f" | tar -x --listed-incremental=/dev/null -C restore
done
# restore/data is a RustFS data directory: run rustfs/rustfs:1.0.4 on it with
# the same root keys and RUSTFS_IAM_MASTER_KEY, then read the objects back.
```

The archives keep RustFS's owner (uid 10001). Extracting only some members
(`tar -x ... --wildcards 'data/sdoc-private/notices/*'`) leaves the folders above
them owned by whoever extracts, and RustFS then stops with "File access denied":
`chown -R 10001:10001 restore/data` first.

Checked on 2026-11-01, with the key on the administrator's machine (and, for the
files, held in the VM's memory for the drill only, then wiped):

- each database's first backup decrypted and restored into an empty database,
  with the same row counts as production (the portal's documents short only
  by those uploaded after the backup);
- the first full copy of the files (12.7 GB) decrypted and unpacked in eight
  minutes; RustFS's settings and users and the portal's notices and blog files
  from it served by a throwaway RustFS byte for byte as the live one (and as
  the public site served them before the move).

## Still to do

- **A copy off the VM.** These backups live on the same VM: losing the VM
  loses them. Being encrypted, a copy can go anywhere (the backup VM when it
  exists, or a periodic copy to an administrator's machine).
- **An alert** when a run fails.

The script and units: [`ops/backup/`](../ops/backup/). Install or update them
on the VM with `sudo install -m 700 sdoc-backup /usr/local/sbin/`,
`sudo install -m 644 sdoc-backup.service sdoc-backup.timer /etc/systemd/system/`,
`sudo systemctl daemon-reload && sudo systemctl enable --now sdoc-backup.timer`.
