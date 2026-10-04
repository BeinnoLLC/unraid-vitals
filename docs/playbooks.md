# Vitals fix playbooks

One playbook per check id. Each answers: what it means → how to confirm →
how to fix → what NOT to do. Steps with a safe, automatable action link into
the Cleanup tab kinds (preview→apply); everything else stays manual on purpose.

The Diagnostics tab opens the playbook for a finding automatically.

## docker.img usage & writable layers (P14-01)

**What it means** — the docker.img loop file (default 20 GB) holds images AND
every container's writable layer. A container writing into its image instead
of a mapped volume quietly eats the file until Docker dies.

**Confirm** — Docker tab (or `docker ps -a -s`): the size column's first
number is the writable layer. The finding names the container and its size.

**Fix** —
1. Open the container's settings in the Docker tab; fix the path that writes
   data inside the image (map it to appdata).
2. If the data inside matters, `docker cp` it out first — it is deleted on
   recreate.
3. Recreate the container. The layer resets to ~0 B.
4. If docker.img itself is full of dead images, run **Cleanup → Dangling
   images / Unused images** (preview→apply).

**Not** — never delete the container's appdata to "reset" it; never grow
docker.img while an fsck/mover/parity is running.

## Container log sizes (P14-02)

**What it means** — a container's json log grows without bound unless the
container sets `--log-opt max-size`. Chatty apps fill the docker.img or the
disk backing /var/lib/docker.

**Confirm** — the finding names the container with size and growth/day.

**Fix** —
1. Stop the app's chatter at the source (app log config, not docker).
2. **Cleanup → Oversized container logs** truncates the log (preview→apply,
   safe while the container runs).
3. Lasting: add `--log-opt max-size=10m --log-opt max-file=3` to the
   container (Docker tab, advanced view).

**Not** — do not delete the whole container to reset a log; do not set
max-file=1 (loses the crash context you need next time it fails).

## rootfs / /var/log /tmp fill (P14-03)

**What it means** — Unraid's root is RAM-backed. Filling it wedges docker,
syslog, and the webGUI. Common causes: docker loop device too small, runaway
app writing to / (unmapped log), syslog flooded.

**Confirm** — the finding names the mount and percentage; `df -h / /var/log`
in a terminal shows the same.

**Fix** —
1. Find the writer: `lsof +D /var/log | sort -k7 -n | tail` (or the fs_watch
   finding naming the file).
2. Clear the specific file (truncate log-style via **Cleanup → Plugin
   logs/tmp** for plugin-owned files; app files need the app fixed).
3. If docker.img is the culprit, see the docker.img playbook.

**Not** — `rm -rf /var/log/*` (destroys history you may need the same day);
rebooting "to clear RAM" without finding the writer (it refills).

## Cache pool & mover health (P14-04)

**What it means** — files stuck on the wrong tier: on cache for a
cache=no/yes share (mover moves them), on array for cache=only (never move —
usually a mis-set share or files created before the share changed).

**Confirm** — the finding names share + paths; Shares page shows the share's
Use Cache setting.

**Fix** —
1. Run **Run mover now** (Cleanup tab top panel) for mover-eligible shares
   (cache=yes/prefer).
2. For cache=only shares with array-side files: fix the share setting or
   move manually (mover refuses to move them).
3. Re-run the check to confirm the paths cleared.

**Not** — do not `mv` between pool/array while a parity check runs (or set
the mover running with one — the mover panel asks for explicit confirmation
during parity for a reason); do not "fix" by deleting the copies before
verifying which side is current.

## Share placement conflicts (P14-05)

**What it means** — one share with folders on both pool and array. Unraid
cannot make that one share "cache-only" or "array-only" — reads/writes get
split-brain behavior.

**Confirm** — the finding lists the exact paths per side.

**Fix** — decide the share's primary tier, then move the strays to match:
mover handles pool→array; pool←array needs a manual copy (mover only moves
one direction).

**Not** — renaming folders inside /mnt/user/<share> manually while the share
is in use — do it from the array side during low activity.

## Unclean shutdowns & parity history (P14-06)

**What it means** — the array did not stop cleanly last time (power cut,
frozen browser tab during reboot). The next start runs a correcting parity
check automatically — that check is on the history with the "auto" marker.

**Confirm** — Array tab shows the unclean-shutdown banner + the history
table (dates, speeds, errors).

**Fix** — let the automatic check finish; then investigate the power or the
shutdown flow that caused it.

**Not** — cancel the auto check ("I'll check later") — parity stays
unverified; skipping correcting checks after an unclean shutdown is how
silent corruption spreads.

## SMART deep analysis (P14-07)

**What it means** — sector counters are moving (reallocated/pending/uncorrectable) or NVMe reports integrity errors/wear.

**Confirm** — the finding names the attribute, the 30-day growth and disks;
`smartctl -A /dev/sdX` shows the same counters.

**Fix** —
1. Growing reallocated/pending/uncorrectable ⇒ back up, then replace the
   disk at a scheduled window. Keep parity intact meanwhile.
2. CRC errors growing ⇒ reseat/replace the cable first (free fix).
3. Stable for 30+ days ⇒ the standing value is recorded as info; no action.

**Not** — do not "clear" counters (they come back and hide the trend); never
pull a parity disk while the array is started.

## Spin-down analysis (P14-08)

**What it means** — a disk never spins down. Something wakes it — a scanner,
an unconfigured appdata share, a monitoring container.

**Confirm** — the finding reports the measured median gap between activity
bursts (e.g. "every 10 minutes") — that interval usually names the culprit.

**Fix** — find what runs on that interval (cron, container healthcheck,
media scanner) and fix its path; then verify by watching the drive's LED or
spin log.

**Not** — do not force disk spindown with hdparm -y in a loop; find the waker.

## Syslog signature scanner (P14-09)

**What it means** — the scanner matched a known-bad pattern (OOM kills,
hardware faults, fs errors) in the system log.

**Confirm** — the finding carries the exact syslog line, timestamped.

**Fix** — per signature: OOM ⇒ find the memory hog (see its evidence);
hardware fault ⇒ SMART/BIOS; filesystem ⇒ filesystem playbook.

**Not** — do not rotate syslog to silence it; the scanner resumes mid-stream
by offset anyway.

## Pool health: btrfs & ZFS (P14-10)

**What it means** — device error counters nonzero (btrfs), scrub never run,
ZFS pool degraded/delays.

**Confirm** — the finding names device + counts; `btrfs device stats
/mnt/user0` matches.

**Fix** —
1. Nonzero counters: scrub first; if counters grow after a clean scrub,
   test the device.
2. Never-scrubbed pool: schedule a scrub (off-peak).

**Not** — do not balance/rebalance to "fix" errors before scrubbing; do not
resliver ZFS without knowing which vdev matters.

## Network health (P14-11)

**What it means** — a port renegotiated lower than its best-seen speed
(cable/switch/NIC trouble), MTU mismatch inside bonds/bridges, error/drop
deltas.

**Confirm** — the finding names the interface, the current and best-seen
speed.

**Fix** — reseat cable/switch port; check switch config; only after that,
suspect the NIC.

**Not** — locking the port to a specific speed to silence the finding.

## Flash drive health (P14-12)

**What it means** — /boot is small and slow; fill or read-only remount = you
lose plugins/cfg writes silently.

**Confirm** — the finding names free space % or a read-only mount.

**Fix** —
1. Read-only: reboot (safe — nothing writes), then check the stick
   (`dmesg | grep -i sd[a-z]`), replace if it repeats.
2. Full: Clean up plugin backups/logs; move big plugins' data to appdata.

**Not** — do not write heavy data to /boot "temporarily"; do not ignore a
read-only remount (the next boot may not come clean).

## Docker hygiene (P14-13)

**What it means** — containers crash-looping (restarts, exit codes), stale
volumes, orphaned images.

**Confirm** — the Docker tab shows restart counts; the finding names the
container + last exit code.

**Fix** —
1. Crash loop: read the container's log (Docker tab → logs; also
   **Cleanup → container logs** to truncate if huge).
2. Stale/orphans: **Cleanup → Stopped containers / Unused volumes** (double
   confirm for volumes — delete is real).

**Not** — "fix" a crash loop with restart=always escalation; that hides the
exit code.

## VM storage (P14-14)

**What it means** — a vdisk is overcommitted (bigger than the space left in
its backing pool) or a VM writes to a pool without TRIM.

**Confirm** — the finding shows pool free vs vdisk virtual size (shortfall).

**Fix** — shrink the vdisk image from inside the guest (qcow2/raw trim),
move the VM to a bigger pool, or free space.

**Not** — deleting the vdisk to reclaim space "later"; snapshots count as
used — prune them first.

## System maintenance (P14-15)

**What it means** — update checks stale/never-run, NTP off or unrunning,
SSDs without TRIM.

**Fix** — Settings → Update check enable; Date and Time → NTP on;
Scheduler → TRIM weekly (or add the dynamix ssd_trim cron).

**Not** — disabling update checks "because they bother me".

## Share permissions (P14-16)

**What it means** — files not owned by nobody:users (or without group
write) inside a share — usually created by root shells or misconfigured
apps; SMB clients then cannot write them.

**Confirm** — the finding lists affected top-level folders; `ls -la`
confirms the owner.

**Fix** — Tools → New Permissions on the share, or
`chown -R nobody:users` + `chmod -R g+rw` on the affected tree (bounded to
the named folders).

**Not** — 777 everything; changing owners on system/appdata paths outside
the share.

## Log sizes of the plugin itself (log_size)

**What it means** — the plugin's own logs exceed the healthy cap; rotation
runs daily but repeated growth means a caller logs too much.

**Fix** — the finding names the file; check the caller; the daily rotation
keeps it bounded meanwhile.

## Anomaly baseline (anomaly_baseline)

**What it means** — current metrics departed from the hour-of-week
baseline. Not necessarily an outage — a backup window at 03:00 every day is
"anomalously" busy every day, forever.

**Confirm** — the finding shows metric, expected-vs-actual, hour of week.

**Fix** — investigate only when the hour-of-week is odd; a recurring sane
pattern becomes the new baseline automatically.

## Capacity forecast (capacity_forecast)

**What it means** — a pool fills up within the forecast window at the
current growth rate.

**Fix** — free space (Cleanup kinds), grow the pool, or prune retention.

**Not** — turning off the forecast because "it is annoying".

## fs_watch (fs_watch_full)

**What it means** — a file under a protected path grew fast (usually the
writer the rootfs playbook wants named).

**Fix** — see the rootfs playbook.