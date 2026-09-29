# Phase 13 — Foundations & data integrity

[All proposed phases](./index.md)

---

## P13-01 — Checks engine: rule-based checks that return typed findings with evidence

**Type:** enhancement · **Priority:** high

Detection today is split between threshold alerts in `v_check_alerts()` and LLM agents that read one snapshot and have to guess. Add a deterministic checks engine that every diagnosis ticket in Phase 14 plugs into.

- `include/checks/` with one file per check; each returns zero or more findings: `{check_id, severity, subject, title, detail, evidence, fix_id}`
- Runner `scripts/vitals-checks.php`, run from cron on its own schedule, results written to a `check_results` table in the DB
- Findings carry evidence (the numbers and log lines that triggered them), so they can be shown and later fed to the AI agents
- `ajax.php?action=checks` returns current results; the UI shows them next to the AI findings
- Each check has an enable flag and severity override in `vitals.cfg`

Acceptance: a sample check (rootfs above 90%) runs from cron on Selene, appears in the UI with its evidence, and can be disabled from settings.

---

## P13-02 — Hourly rollups store real aggregates, not one sample

**Type:** bug · **Priority:** high

`v_rollup()` writes `cpu_avg` and `mem_avg` from the single snapshot taken when the hour rolls over. Every long-range chart and daily table is built on these, so a quiet minute at :00 hides a busy hour.

- Compute avg, min, max and p95 over the ring points of the closed hour for cpu, mem, load, net rx/tx, temp and gpu
- Network: store bytes transferred in the hour, not a sum of rates (`v_daily()` currently adds rates together)
- Keep reading old rollup lines; mark them as single-sample in the daily view

Acceptance: a synthetic hour with one 100% CPU sample at :30 and idle elsewhere produces `cpu_max=100` and a low `cpu_avg`.

---

## P13-03 — Force LF line endings in the repo

**Type:** bug · **Priority:** high

There is no `.gitattributes`, and a Windows checkout with `core.autocrlf=true` has CRLF in every file, including `install.sh`, `remove.sh` and `build.sh`. Running `build.sh` from such a checkout packages shell scripts that fail on Unraid with `bad interpreter`.

- Add `.gitattributes` with `* text=auto eol=lf` and binary rules for `*.png`
- `build.sh` fails the build if any staged `.sh`, `.php`, `.page` or `.mjs` file contains CR

Acceptance: a fresh Windows clone has LF in all scripts, and `build.sh` refuses a payload containing CRLF.

---

## P13-04 — README and install banner match the current app

**Type:** documentation · **Priority:** medium

The README describes the first read-only dashboard.

- Remove `VitalsSettings.page` (deleted) and the `/Settings/VitalsSettings` line in the `install.sh` banner
- Replace "No dependencies" with the real requirements of the AI layer
- Document control actions, AI agents, knowledge base, research, and where the DB lives
- Fix the menu location (`Vitals.page` registers under `Dashboard`, README says `Tools → Vitals`)
- `plugin.plg.template` changelog says "daily flash rollups"; they are hourly

Acceptance: every path and feature named in the README exists in the repo.

---

## P13-05 — Add the missing dashboard screenshot

**Type:** bug · **Priority:** medium

`assets/screenshot-dashboard.png` is referenced by the README and `ca_profile.xml` but is not in the repo (P0-07 was closed without it). Community Applications shows a broken image.

Acceptance: the file exists and both references render.

---

## P13-06 — A real compact dashboard tile

**Type:** enhancement · **Priority:** medium

`Vitals.Dashboard.page` is a copy of `Vitals.page`: both load the full ten-tab app. There is no compact tile for the stock dashboard, which the README promises.

- Tile shows 4–6 headline numbers, worst current finding, and a link to the full page
- Separate small entry script so the tile does not load uPlot

Acceptance: the tile renders on the stock dashboard on Selene at tile size (closes P1-02).

---

## P13-07 — Lint covers `.mjs` and runs in CI

**Type:** enhancement · **Priority:** medium

`npm run lint:js` only matches `*.js`, so the whole agent layer is unchecked. Nothing runs on push.

- `lint:js` includes `*.mjs`
- GitHub Actions workflow runs `npm run check` (PHP, JS, shell syntax) on every push and pull request

Acceptance: a syntax error in any `.mjs` file fails CI.

---

## P13-08 — Resync `docs/phases` with GitHub

**Type:** documentation · **Priority:** low

`docs/phases/index.md` lists 4 phases and 23 tickets. GitHub has 13 milestones and 38 issues.

- Regenerate the summaries for phases 4–12
- Script the regeneration (`scripts/sync-phases.sh` using `gh`) so it stops drifting

Acceptance: the index total matches `gh issue list --state all`.

---

## P13-09 — Pin `@smythos/sdk` to an exact version

**Type:** bug · **Priority:** medium

`agent/package.json` depends on `"@smythos/sdk": "latest"`. A breaking SDK release would break the agents on the next install with no code change on our side. `smythos-client.mjs` already documents one SDK behaviour it has to work around.

Acceptance: exact version in `package.json`, lockfile committed and consistent.

---

## P13-10 — Verify the persistent DB on Selene

**Type:** task · **Priority:** high

Follow-up to the DB move to appdata.

- Reboot test: KB documents and research jobs survive
- Array-stopped test: agents fail cleanly and nothing is created under `/mnt/user` in RAM
- SQLite on the `/mnt/user` FUSE layer: run agents and the PHP reader concurrently for a day, then `PRAGMA integrity_check`
- If locking problems appear, default `DATA_DIR` to the pool path behind appdata (e.g. `/mnt/cache/appdata/...`)
- If the agents run in a container, confirm the container sees the same path, or sets `VITALS_DB_PATH`

Acceptance: all four tests recorded on the ticket with results.

---

## P13-11 — CA listing text describes the default LLM endpoint accurately

**Type:** documentation · **Priority:** medium

`ca_profile.xml` says "Nothing leaves your network" and "no telemetry leaving your server". The default endpoints in `agent/lib/smythos-client.mjs` are remote hosts. The default is intentional; the listing text should say what it does so CA reviewers and users are not surprised.

Acceptance: the profile text states where analysis runs by default and how to point it elsewhere.
