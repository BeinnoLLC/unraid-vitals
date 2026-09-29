# Phase 16 — Cleanup actions

[All proposed phases](./index.md)

All actions run as root through php-fpm. Every one uses the pattern in `include/actions.php`: POST only, CSRF token, target validated against a live lookup. P16-01 is required before any other ticket in this phase.

---

## P16-01 — Cleanup safety framework

**Type:** enhancement · **Priority:** high

- Two-step flow for every action: **preview** (what would be removed, how much space) then **apply**
- Apply accepts only a preview id, never a client-supplied path list, and re-validates every item
- Audit log in the DB: who, when, action, items, bytes reclaimed, result
- Paths are resolved with `realpath()` and must sit under an allowed root for that action
- Audit log visible in the UI

Acceptance: an apply request with a forged item that was not in the preview is rejected and logged.

---

## P16-02 — Docker prune

**Type:** enhancement · **Priority:** high

- Separate previews for dangling images, unused images, stopped containers, unused volumes, build cache
- Unused volumes are off by default and need a second confirmation
- Shows reclaimable space before anything runs

Acceptance: preview total matches space actually reclaimed within 5%.

---

## P16-03 — Orphaned appdata folders

**Type:** enhancement · **Priority:** medium

- Appdata folders that no container, running or stopped, maps
- Shows size and last modified time
- Default action moves to a dated holding folder; permanent delete is a separate step

Acceptance: the appdata folder of a removed container is listed; a mapped one is never listed.

---

## P16-04 — Truncate oversized container logs

**Type:** enhancement · **Priority:** medium

- Truncate the JSON log of a chosen container
- Offers the Docker log size limit setting as the lasting fix

Acceptance: a 2 GB log is truncated with the container still running.

---

## P16-05 — Junk files

**Type:** enhancement · **Priority:** low

- Empty folders, `.DS_Store`, `Thumbs.db`, `@eaDir`, `._*` files
- Per-share opt-in; preview lists counts per folder

Acceptance: junk in an opted-in share is removed; other shares are untouched.

---

## P16-06 — Recycle bin

**Type:** enhancement · **Priority:** low

- Size of each share's recycle bin folder
- Empty per share or entries older than N days

Acceptance: sizes shown match `du`.

---

## P16-07 — Run mover now

**Type:** enhancement · **Priority:** medium

- Start mover from the UI with progress and a way to stop it
- Refuses during a parity check unless confirmed

Acceptance: mover started from the UI shows progress until it ends.

---

## P16-08 — Old logs and temp files

**Type:** enhancement · **Priority:** low

- Rotated logs under `/var/log` and the plugin's own logs in `/var/tmp/unraid-vitals`
- The plugin's `collector.log`, `agents.log` and the two `/tmp` agent logs have no rotation today; add size-capped rotation

Acceptance: `collector.log` never exceeds its cap after a week.
