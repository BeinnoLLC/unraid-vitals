#!/usr/bin/env node

import { createOrTouchEvent, resolveEventsByKey, getDb } from './lib/db.mjs';
import { vmList } from './lib/sources.mjs';

const SETTLED_STATES = new Set(['running', 'shut off']);

function severityFor(from, to) {
  if (to === 'crashed' || to === 'in crash loop') return 'critical';
  if (to === 'paused') return 'warning';
  if (from === 'running' && to === 'shut off') return 'warning'; 
  return 'info';
}

async function main() {
  getDb(); 
  const vms = vmList();
  if (!vms.length) { console.log('[vmwatch] no VMs (virsh unavailable or none defined)'); return; }

  
  
  const d = getDb();
  let changed = 0;
  for (const vm of vms) {
    const alertKey = `vm-state:${vm.name}`;
    const prior = d.prepare(
      `SELECT evidence FROM kb_events WHERE alert_key = ? ORDER BY started_at DESC LIMIT 1`
    ).get(alertKey);
    const priorState = prior ? (JSON.parse(prior.evidence || '{}').state ?? null) : null;

    if (priorState === vm.state) continue; 
    changed++;

    if (priorState && SETTLED_STATES.has(priorState)) {
      
      
      
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
    
    
    

    console.log(`[vmwatch] ${vm.name}: ${priorState || '(unknown)'} -> ${vm.state} [${severity}]`);
  }
  if (!changed) console.log(`[vmwatch] ${vms.length} VM(s) checked, no state changes`);
}

main().catch(e => { console.error('[vmwatch] fatal:', e?.message || e); process.exit(1); });
