#!/usr/bin/env node
/**
 * unraid-vitals agent — VM event listener.
 *
 * "Listen to events from all the VMs" — rather than a live libvirt event
 * subscription (a long-running daemon is a much bigger footprint for a
 * plugin that otherwise runs entirely via cron), this polls virsh state on
 * every cron tick and diffs against the last-known state per VM, recorded
 * as kb_events (kind='vm', entity=<vm name>, alert_key='vm-state:<name>').
 * A state CHANGE (not the state itself) is what becomes an event — a VM
 * sitting 'running' every tick for hours produces zero events, only the
 * transition does. That keeps the KB from filling with one row per VM per
 * tick and makes createOrTouchEvent's idempotent-per-alert_key behavior do
 * exactly the right thing: the event auto-resolves when the VM returns to
 * a "settled" state (running or shut off), and a flapping VM (crash-loop)
 * shows as repeated open/resolve cycles in the event history — itself a
 * useful diagnostic signal, not just noise to filter.
 *
 * A resolved crash/shutdown event is a resolved kb_event exactly like any
 * alert-engine event, so it flows through the SAME distillation pipeline
 * (unlearnedResolvedEvents -> lessons/solutions) as everything else — no
 * separate "VM knowledge" path to maintain.
 *
 * Usage: node vmwatch.mjs
 */
import { createOrTouchEvent, resolveEventsByKey, getDb } from './lib/db.mjs';
import { vmList } from './lib/sources.mjs';

const SETTLED_STATES = new Set(['running', 'shut off']);

/** kb_events.severity CHECK allows only info|warning|alert|critical — there
 *  is deliberately no 'ok' (a resolved event is the "ok"), so a return to
 *  running is 'info'. */
function severityFor(from, to) {
  if (to === 'crashed' || to === 'in crash loop') return 'critical';
  if (to === 'paused') return 'warning';
  if (from === 'running' && to === 'shut off') return 'warning'; // could be intentional — worth a look, not alarming
  return 'info';
}

async function main() {
  getDb(); // ensure schema exists
  const vms = vmList();
  if (!vms.length) { console.log('[vmwatch] no VMs (virsh unavailable or none defined)'); return; }

  // Last-known state per VM lives as the evidence of the most recent event
  // for that alert_key — no separate state table, kb_events already is one.
  const d = getDb();
  let changed = 0;
  for (const vm of vms) {
    const alertKey = `vm-state:${vm.name}`;
    const prior = d.prepare(
      `SELECT evidence FROM kb_events WHERE alert_key = ? ORDER BY started_at DESC LIMIT 1`
    ).get(alertKey);
    const priorState = prior ? (JSON.parse(prior.evidence || '{}').state ?? null) : null;

    if (priorState === vm.state) continue; // no transition — the common case, zero writes
    changed++;

    if (priorState && SETTLED_STATES.has(priorState)) {
      // Transitioning AWAY from a settled state resolves any still-open
      // event for this VM (e.g. a prior 'paused' warning) before recording
      // the new transition, so events don't pile up unresolved forever.
      resolveEventsByKey(alertKey);
    }

    const severity = severityFor(priorState, vm.state);
    createOrTouchEvent({
      kind: 'vm', entity: vm.name, alertKey,
      severity,
      summary: priorState
        ? `${vm.name}: ${priorState} → ${vm.state}`
        : `${vm.name}: first-seen state ${vm.state}`,
      evidence: { state: vm.state, prior_state: priorState, observed_at: Math.floor(Date.now() / 1000) },
      source: 'vmwatch',
    });

    if (SETTLED_STATES.has(vm.state)) resolveEventsByKey(alertKey);
    // A settled arrival state resolves its OWN just-created event immediately
    // — the transition was the newsworthy thing, not an ongoing condition.
    // An unsettled state (paused, crashed) stays open until it moves again.

    console.log(`[vmwatch] ${vm.name}: ${priorState || '(unknown)'} -> ${vm.state} [${severity}]`);
  }
  if (!changed) console.log(`[vmwatch] ${vms.length} VM(s) checked, no state changes`);
}

main().catch(e => { console.error('[vmwatch] fatal:', e?.message || e); process.exit(1); });
