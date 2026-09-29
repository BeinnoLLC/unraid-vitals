# Phase 14 — Diagnosis checks

[All proposed phases](./index.md)

Every ticket here is one or more checks for the engine in P13-01. Each check returns findings with evidence and, where a safe fix exists, a link to a cleanup action (Phase 16) or playbook (P17-02).

---

## P14-01 — `docker.img` usage, growth and per-container writable layer

**Type:** enhancement · **Priority:** high

"Docker image is full" is one of the most common Unraid problems, usually caused by a container writing data inside the image instead of a mapped path.

- Collect usage of the Docker image mount and keep it in the ring and rollups
- Per-container writable layer size (`docker ps -s`), sampled hourly because it is slow
- Findings: image above 75% / 90%, image growing faster than N GB per day, single container layer above 1 GB

Acceptance: a test container writing 2 GB inside its own filesystem is named in the finding.

---

## P14-02 — Container log file sizes

**Type:** enhancement · **Priority:** medium

- Size of each container's JSON log file
- Finding when a log exceeds 500 MB or grows faster than 100 MB per day
- Links to the truncate action (P16-04)

Acceptance: a chatty test container is flagged with its log size and growth rate.

---

## P14-03 — rootfs, `/var/log` and `/tmp` fill

**Type:** enhancement · **Priority:** high

Unraid runs from RAM. A full `/var/log` or rootfs causes strange failures across the web UI.

- Collect usage of `/`, `/var/log`, `/tmp` and `/run`
- Findings at 80% and 95%, naming the largest files
- History in the ring so the growth is visible

Acceptance: filling `/var/log` to 85% on Selene raises the finding and names the file.

---

## P14-04 — Cache pool and mover health

**Type:** enhancement · **Priority:** high

- Last mover run time and duration
- Files sitting on a pool for a share configured to move to the array, older than the mover schedule
- Pool fill against the pool's minimum free space setting
- Findings: mover has not run in N days, pool will fill before the next mover run (uses P15-01)

Acceptance: a file left on cache for a moving share past one mover cycle is reported.

---

## P14-05 — Share placement conflicts

**Type:** enhancement · **Priority:** medium

- Pool-only share with files on array disks (classic: `appdata` or `system` split across cache and array, which makes Docker slow and keeps disks awake)
- Files on disks that the share's include/exclude settings rule out
- Same top-level folder name with different case on different disks

Acceptance: `appdata` with one folder on `disk1` is reported with the exact path.

---

## P14-06 — Unclean shutdowns and parity check history

**Type:** enhancement · **Priority:** medium

- Parse the parity check history log: date, duration, average speed, errors
- Findings: last check had errors, no check in N days, speed dropping across the last five checks
- Detect unclean shutdowns and show them on the timeline (P15-04)

Acceptance: the Array tab shows the last ten parity checks with a speed trend.

---

## P14-07 — Deeper SMART analysis

**Type:** enhancement · **Priority:** high

Today only reallocated and pending sectors raise alerts, and they raise on any non-zero value forever.

- Alert on growth, not on a standing lifetime value
- UDMA CRC error growth (usually a cable or backplane, not the disk)
- NVMe: percentage used, available spare, media errors, critical warning
- SSD wear indicators; age of the last short and long self-test
- Builds on the history charts in #31

Acceptance: a disk with 8 reallocated sectors that has not changed in 30 days produces an `info` finding, not an hourly alert.

---

## P14-08 — Spin-down analysis

**Type:** enhancement · **Priority:** medium

- Per-disk read/write counters from `/proc/diskstats` in the ring
- Spun-up time per disk per day
- Finding: disk never spun down in 24 hours, with the I/O pattern that kept it awake

Acceptance: a disk kept awake by a container scanning it every 10 minutes is reported with the interval.

---

## P14-09 — Syslog signature scanner

**Type:** enhancement · **Priority:** high

A library of known log patterns, each mapped to a finding with an explanation.

- Signatures: OOM killer, macvlan call traces, machine check and EDAC errors, BTRFS and XFS errors, ATA link resets, NIC link flaps, USB flash disconnects, kernel call traces
- Signatures live in one data file (`include/checks/signatures.json`) so adding one needs no code
- Scanner keeps a file offset so each run reads only new lines
- Matched lines are stored as evidence and passed to the AI agents (P20-05)

Acceptance: an injected OOM line in syslog produces a finding naming the killed process within one run.

---

## P14-10 — Pool health: btrfs and ZFS

**Type:** enhancement · **Priority:** medium

- btrfs: device error counters, last scrub date and result
- ZFS: pool state, errors, last scrub, ARC size against RAM
- Findings: non-zero device errors, degraded pool, no scrub in N days

Acceptance: a pool with a non-zero btrfs device error counter is reported with the device name.

---

## P14-11 — Network health

**Type:** enhancement · **Priority:** medium

`/proc/net/dev` is already read, but only byte counters are used.

- Errors, drops and collisions per interface, as deltas
- Negotiated link speed and duplex; finding when a gigabit-capable port links at 100 Mb
- MTU per interface; finding on mismatch inside a bond or bridge

Acceptance: forcing a port to 100 Mb raises the finding.

---

## P14-12 — Flash drive health

**Type:** enhancement · **Priority:** medium

- Free space on `/boot`
- Detect a read-only remount of `/boot`
- Age of the last flash backup
- Writes per day by this plugin, to prove the wear budget

Acceptance: `/boot` remounted read-only raises a critical finding.

---

## P14-13 — Docker hygiene

**Type:** enhancement · **Priority:** medium

- Restart loops (restart count rising)
- Host port conflicts between containers
- Appdata mapped through `/mnt/user` for containers that use SQLite
- Containers without a memory limit on a box under memory pressure

Acceptance: a container in a crash loop is reported with its restart count and last exit code.

---

## P14-14 — VM storage

**Type:** enhancement · **Priority:** low

- vdisk allocated size against actual size on disk
- Sum of allocated vdisks against free space on the hosting pool
- `libvirt.img` usage

Acceptance: overcommitted vdisks are reported with the shortfall.

---

## P14-15 — System maintenance checks

**Type:** enhancement · **Priority:** low

- Unraid OS and plugin updates available
- Clock sync status and drift
- SSD TRIM schedule present when SSD pools exist

Acceptance: a box with NTP disabled raises the finding.

---

## P14-16 — Share permission problems

**Type:** enhancement · **Priority:** low

- Sample each share for files not owned by `nobody:users` or without group write
- Bounded scan: capped entries per share, low I/O priority
- Finding lists affected top-level folders, links to a fix playbook

Acceptance: a folder created as root inside a share is reported.
