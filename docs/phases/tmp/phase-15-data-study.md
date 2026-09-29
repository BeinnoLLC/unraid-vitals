# Phase 15 — Data study

[All proposed phases](./index.md)

Depends on P13-02: forecasts and baselines built on single-sample rollups would be wrong.

---

## P15-01 — Capacity forecast: days until full

**Type:** enhancement · **Priority:** high

- Linear fit over the last 30 days of fill data per array disk, pool and `docker.img`
- Show "full in about N days" with the fitted growth per day; show nothing when the fit is poor or the trend is flat
- Finding when any target will fill within 14 days

Acceptance: synthetic data growing 10 GB per day on a disk with 100 GB free reports about 10 days.

---

## P15-02 — Per-disk I/O history

**Type:** enhancement · **Priority:** medium

- Read and write throughput and IOPS per disk from `/proc/diskstats`
- Stored in the ring per disk; charted on the Array tab

Acceptance: a parity check is visible as sustained reads on every array disk.

---

## P15-03 — Anomaly detection against a baseline

**Type:** enhancement · **Priority:** medium

- Baseline per metric per hour-of-week (median and spread) from the rollups
- Flag samples far outside the baseline for three consecutive samples
- Needs at least two weeks of data; says so until then

Acceptance: CPU at 90% at 03:00 on a box that idles at that hour is flagged; the same load during a nightly backup window is not.

---

## P15-04 — Event markers on charts

**Type:** enhancement · **Priority:** medium

Builds on the event store in #35.

- Vertical markers on every time chart: parity check, mover run, container restart, reboot, alert raised
- Hover shows the event; click opens it

Acceptance: a temperature spike lines up visually with the parity check that caused it.

---

## P15-05 — Storage analyzer

**Type:** enhancement · **Priority:** medium

- Scheduled scan (nightly by default, low I/O priority, skips spun-down disks unless told otherwise)
- Largest folders per share, size per share over time, breakdown by file type, data not accessed in more than a year
- Results cached in the DB; the UI never scans on request

Acceptance: the Shares tab shows the top 20 folders per share and a 30-day growth chart.

---

## P15-06 — Duplicate file finder

**Type:** enhancement · **Priority:** low

- Candidates by identical size, confirmed by hash of first and last blocks, then full hash
- Report only; deletion goes through the cleanup framework (P16-01)

Acceptance: two copies of a 1 GB file on different disks are reported as one group.

---

## P15-07 — Weekly container resource report

**Type:** enhancement · **Priority:** low

- Per container: average and peak CPU and memory, restarts, week-over-week change
- Needs per-container data in the rollups (today it is only in the 24-hour ring)

Acceptance: the Docker tab shows last week against the week before.

---

## P15-08 — Energy use and cost

**Type:** enhancement · **Priority:** low

- Power draw from UPS load and nominal power, plus GPU power where reported
- kWh per day and cost, with the price per kWh in settings

Acceptance: a day's kWh matches the UPS figures within 5%.

---

## P15-09 — Disk fleet report

**Type:** enhancement · **Priority:** medium

- Per disk: model, age, power-on hours, start-stop count, temperature history, SMART counter growth
- Ranked risk list with the reasons shown

Acceptance: the disk with growing pending sectors ranks first.

---

## P15-10 — Export and weekly health report

**Type:** enhancement · **Priority:** low

- Export ring and rollups as CSV or JSON from the UI
- Weekly summary sent through Unraid notifications: worst findings, capacity forecast, changes since last week

Acceptance: exported CSV opens in a spreadsheet with one row per sample.
