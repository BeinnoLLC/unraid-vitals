/**
 * Network specialist — per-interface throughput.
 *
 * This used to call the model directly: no structured-output schema, no
 * JSON-repair retry, and no grounding. A single unbalanced sample therefore
 * failed the whole agent run (two consecutive "unbalanced JSON in model
 * response" errors in the live run log). It now goes through
 * runSpecialist() like every other specialist, so it gets the schema, the
 * one-shot repair retry and the subject/number grounding for free.
 */
import { runSpecialist } from './contract.mjs';
import { activeSource } from '../lib/sources.mjs';

export const AGENT_ID = 'network';

export async function run() {
  const source = activeSource();
  const snap = source.snapshot();
  const net = snap.net || {};
  const active = Object.entries(net).filter(([, v]) => v.rx_total || v.tx_total);
  const totalRx = active.reduce((s, [, v]) => s + (v.rx_rate || 0), 0);
  const totalTx = active.reduce((s, [, v]) => s + (v.tx_rate || 0), 0);

  const ifaceLines = active
    .map(([k, v]) => `- ${k}: rx=${v.rx_rate}B/s tx=${v.tx_rate}B/s rx_total=${v.rx_total} tx_total=${v.tx_total}`)
    .join('\n') || '(no active interfaces)';

  return runSpecialist({
    agentName: 'Vitals-Network',
    behavior: 'You are the network specialist inside Unraid Vitals, a background health-monitoring agent. You are given per-interface throughput data and must return structured findings, nothing else.',
    systemRole: 'You are a network specialist for an Unraid NAS. You watch per-interface throughput to catch saturation, unusually busy interfaces, or containers/VMs generating unexpected traffic.',
    maxTokens: 700,
    knownSubjects: [...active.map(([k]) => k), snap.system?.name],
    sections: [
      { name: 'throughput', priority: 5, text: `Total: ${(totalRx / 1e6).toFixed(1)} MB/s down, ${(totalTx / 1e6).toFixed(1)} MB/s up across ${active.length} active interfaces.` },
      { name: 'interfaces', priority: 3, text: `Per interface (name: rx rate, tx rate, rx total, tx total, all bytes/bytes-per-sec):\n${ifaceLines}` },
      { name: 'instructions', priority: 9, text: 'Flag: any interface sustaining a rate that looks like saturation for a typical home/media-server gigabit link (>110MB/s sustained), container network interfaces (vethXXXX/docker0) with disproportionate traffic vs the physical uplink, and anything that looks like a stuck/runaway transfer.' }
    ]
  });
}
