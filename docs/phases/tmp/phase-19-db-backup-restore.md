# Phase 19 — Database backup & restore

[All proposed phases](./index.md)

The DB (`vitals.db`) holds findings, the knowledge base and research jobs. It now lives in the appdata share (`<appdata>/unraid-vitals/vitals.db`), so it survives reboots. It still has no backup.

Note: because it is in appdata, the DB is also picked up by any appdata backup the user already runs. That is a copy of a live file and may be inconsistent; the tickets below produce consistent snapshots.

---

## P19-01 — Consistent snapshot backup

**Type:** enhancement · **Priority:** high

A file copy of a SQLite DB taken during a write can be corrupt.

- `agent/backup.mjs` takes a snapshot with SQLite's own mechanism (`VACUUM INTO` or the online backup API), never `cp`
- Output: `vitals-YYYYMMDD-HHMMSS.db`, compressed
- Written to a temporary name and renamed when complete
- Runs under the agents lock so it never overlaps an agent write

Acceptance: a backup taken while an agent run is writing opens cleanly and passes `PRAGMA integrity_check`.

---

## P19-02 — Backup schedule and retention

**Type:** enhancement · **Priority:** high

- Registered in the schedule registry (P18-01); daily by default
- Retention: keep N daily and M weekly backups (defaults 7 and 4)
- Old backups pruned after a new one succeeds, never before

Acceptance: after 10 days with defaults there are 7 daily backups and the matching weekly ones, nothing else.

---

## P19-03 — Backup destination

**Type:** enhancement · **Priority:** high

A backup next to the DB on the same pool does not survive losing that pool.

- `BACKUP_DIR` setting; default `<appdata>/unraid-vitals/backups`
- Settings page warns when the destination is on the same device as the DB
- Destination validated the same way as the data directory: parent must exist, nothing created under an unmounted `/mnt/user`
- Finding when the destination has less free space than three backups need

Acceptance: a destination on the same pool as the DB shows the warning; one on the array does not.

---

## P19-04 — Integrity check

**Type:** enhancement · **Priority:** medium

- `PRAGMA quick_check` on the live DB before each backup; full `integrity_check` weekly
- A failed check raises a critical notification and keeps the previous backups out of pruning
- Each backup is opened and checked after it is written

Acceptance: a deliberately corrupted DB raises the notification and no existing backup is pruned.

---

## P19-05 — Restore from the UI

**Type:** enhancement · **Priority:** medium

- List backups with date, size and document counts
- Restore needs confirmation and the CSRF token
- The current DB is backed up first, so a restore can be undone
- Refused while an agent run or research job is active

Acceptance: restoring yesterday's backup brings back yesterday's KB, and a "pre-restore" backup of today's exists.

---

## P19-06 — Back up now, and download

**Type:** enhancement · **Priority:** medium

- "Back up now" button on the settings page
- Download a backup through the browser
- Backup status row: last backup time, size, result, next scheduled

Acceptance: a manual backup appears in the list within seconds and downloads intact.

---

## P19-07 — Schema version and pre-upgrade backup

**Type:** enhancement · **Priority:** medium

The schema is created with `CREATE TABLE IF NOT EXISTS` in two places (`agent/lib/db.mjs` and `v_research_create()` in `store.php`). There is no version and no migration path; phases 9–12 add several tables and columns.

- `PRAGMA user_version` holds the schema version
- Numbered migrations, applied in order, in one place
- PHP stops creating tables; it only reads, and inserts research jobs
- Automatic backup before any migration runs

Acceptance: upgrading from a version-1 DB to version 2 leaves a pre-upgrade backup and a DB at `user_version=2`.

---

## P19-08 — Full plugin state bundle

**Type:** enhancement · **Priority:** low

- One archive with the DB snapshot, `vitals.cfg` and the flash rollups
- Restore on a fresh install brings back history, settings and knowledge base
- Separate from the KB JSON export in #38, which moves knowledge between boxes; this one restores a box

Acceptance: a bundle restored on a clean install reproduces the same 30-day charts and KB.
