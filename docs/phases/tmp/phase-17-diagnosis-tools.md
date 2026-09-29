# Phase 17 — Diagnosis tools

[All proposed phases](./index.md)

---

## P17-01 — One-click full health check

**Type:** enhancement · **Priority:** high

- "Run health check" runs every check in the engine (P13-01) now, with progress
- Result: a score, and one list sorted by severity with evidence and fix links
- Previous results kept so two runs can be compared

Acceptance: a box with a full `docker.img` and a stale parity check shows both, highest severity first.

---

## P17-02 — Fix playbooks

**Type:** enhancement · **Priority:** medium

- Each check id maps to a playbook: what it means, how to confirm, steps to fix, what not to do
- Steps that have a safe action show a button that goes through the cleanup framework (P16-01)
- Playbooks are Markdown files shipped with the plugin and also seeded into the KB (P20-11)

Acceptance: the `docker.img` finding opens a playbook whose first step names the offending container.

---

## P17-03 — Diagnostics export

**Type:** enhancement · **Priority:** medium

- Trigger Unraid's own diagnostics bundle from the UI
- Add a Vitals summary: current findings, last 24 hours of key metrics, recent signature matches
- Anonymized text version for pasting into a forum post (share names, hostnames, IPs, serials masked)

Acceptance: the forum summary contains no share name, IP or disk serial.

---

## P17-04 — SMART self-tests

**Type:** enhancement · **Priority:** medium

- Start a short or long self-test per disk from the UI
- Show progress and result history
- Schedule through the registry in P18-01; never start during a parity check

Acceptance: a short test started from the UI shows its result when finished.

---

## P17-05 — "What changed?" timeline

**Type:** enhancement · **Priority:** medium

- Daily snapshot of: container list and image tags, plugin versions, Unraid version, share settings, disk assignments
- Timeline of differences

Acceptance: updating a container image appears on the timeline with old and new tag.
