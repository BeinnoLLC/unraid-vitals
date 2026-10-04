/**
 * unraid-vitals — lib/distiller.mjs (P10-… / #36)
 *
 * Resolved events auto-generate durable lessons + solution writeups:
 *   for every RESOLVED event with no solution row yet:
 *     1. a lesson: "what happened → what fixed/stopped it", confidence
 *        starting at 0.5;
 *     2. a solution row: detection (the event's summary/evidence) + the
 *        action taken (the resolving fact) + outcome=worked by construction
 *        (the event resolved — this is the fix that coincided, not proof, but
 *        it's the only fact we have);
 *     3. recurrence: the SAME {kind, entity} event re-opened later merges the
 *        lesson (times_seen + 1, confidence nudged up, merged_from keeps the
 *        provenance) instead of adding a second lesson.
 *
 * The distiller is deterministic (reads only the DB — no LLM call): an LLM
 * could write prettier lessons, but the value here is coverage — every
 * resolved event becomes reusable knowledge with zero cost.
 */

import { getDb, unlearnedResolvedEvents } from './db.mjs';

export function distill(limit = 25) {
  const db = getDb();
  const events = unlearnedResolvedEvents(limit);
  let lessons = 0, solutions = 0;

  for (const e of events) {
    // the resolving fact: what changed when the event closed
    const evidence = safeParse(e.evidence);
    const resolvedHow = evidence?.resolved_by ?? evidence?.last ?? null;
    const detection = `${e.kind} on ${e.entity}: ${e.summary}`;
    const action = resolvedHow
      ? `Resolved automatically — trigger cleared (${resolvedHow}).`
      : 'Resolved automatically — the breaching condition stopped being true (see evidence timestamps).';

    // lesson: same {kind,entity} seen before? merge. else new.
    const lessonKey = `${e.kind}:${e.entity}`;
    const prev = db.prepare(
      `SELECT id, times_seen, lesson FROM kb_lessons WHERE kind=? AND entity=? ORDER BY last_seen_at DESC LIMIT 1`
    ).get(e.kind, e.entity);
    let lessonId;
    if (prev) {
      db.prepare(`UPDATE kb_lessons SET times_seen = times_seen + 1,
                   confidence = MIN(0.95, confidence + 0.1),
                   last_seen_at = ?, merged_from = COALESCE(merged_from, ?)
                   WHERE id = ?`
      ).run(e.resolved_at ?? now(), lessonKey, prev.id);
      lessonId = prev.id;
    } else {
      const oneLiner = `${capitalize(e.kind)} on ${e.entity} resolved: ${shorten(e.summary, 120)}`;
      const guidance = guidanceFor(e.kind);
      db.prepare(
        `INSERT INTO kb_lessons (kind, entity, lesson, confidence, times_seen, first_seen_at, last_seen_at, merged_from)
         VALUES (?, ?, ?, 0.5, 1, ?, ?, ?)`
      ).run(e.kind, e.entity, guidance ? `${oneLiner}\n${guidance}` : oneLiner,
            e.started_at, e.resolved_at ?? now(), lessonKey);
      lessonId = db.prepare(`SELECT id FROM kb_lessons WHERE kind=? AND entity=? ORDER BY last_seen_at DESC LIMIT 1`).get(e.kind, e.entity)?.id;
    }

    db.prepare(
      `INSERT INTO kb_solutions (event_id, lesson_id, detection, action_taken, outcome, created_at)
       VALUES (?, ?, ?, ?, 'worked', ?)`
    ).run(e.id, lessonId ?? null, detection, action, now());

    lessons++; solutions++;
  }
  return { events: events.length, lessons, solutions };
}

/** Kind-specific durable guidance — the actual operational takeaway. */
function guidanceFor(kind) {
  switch (kind) {
    case 'temp': return 'Disk temperature breaches: check the specific drive position and case airflow first (a single hot drive in a dead zone beats a new fan). Trend matters more than the peak.';
    case 'load': return 'Sustained load breaches usually have an owner: check the top containers from the same window before blaming the box itself.';
    case 'fill': return 'Fill breaches: find the writer (fs_watch finding or du) before deleting; Cleanup tab kinds cover plugin-owned paths only.';
    case 'smart': return 'SMART events: growth since the last event matters more than the counter value — 1 stale reallocated sector for a year is fine; +6 in a week is a replacement case.';
    case 'fan_stall': return 'Fan stalls: usually a cable or a dead channel on the Super-I/O chip — 0 RPM with a non-zero duty cycle is the signature.';
    case 'memory': return 'Memory breaches on Unraid: check for a single container ballooning (topContainers) before adding swap.';
    case 'container': return 'Container restart loops: read the container log tail first; the exit code names the failure class.';
    case 'vm': return 'VM events: state flaps usually mean storage latency or a missing bridge — check the vm_storage check and br0 before the guest itself.';
    case 'finding': return 'Agent findings: recurring findings of the same agent/entity mean the recommended action was never taken — revisit the playbook.';
    case 'net': return 'Network events: a renegotiated link speed is almost always the cable/switch port, not the NIC.';
    default: return null;
  }
}

function safeParse(v) { try { return JSON.parse(v); } catch { return null; } }
function now() { return Math.floor(Date.now() / 1000); }
function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
function shorten(s, n) { s = String(s ?? ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }