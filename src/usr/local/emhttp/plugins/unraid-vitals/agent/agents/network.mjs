/** Network specialist — throughput anomalies, saturation, unusual interfaces. */
import { makeAnalysisAgent, callAnalyze, extractJson } from '../lib/smythos-client.mjs';
import { RESPONSE_CONTRACT, safeParseFindings } from './contract.mjs';
import { latestSnapshot } from '../lib/sources.mjs';

export const AGENT_ID = 'network';

export async function run() {
  const snap = latestSnapshot();
  const net = snap.net || {};
  const active = Object.entries(net).filter(([, v]) => v.rx_total || v.tx_total);
  const totalRx = active.reduce((s, [, v]) => s + (v.rx_rate || 0), 0);
  const totalTx = active.reduce((s, [, v]) => s + (v.tx_rate || 0), 0);

  const system = `You are a network specialist for an Unraid NAS. You watch per-interface throughput to catch saturation, unusually busy interfaces, or containers/VMs generating unexpected traffic. ${RESPONSE_CONTRACT}`;
  const user = `Total: ${(totalRx / 1e6).toFixed(1)} MB/s down, ${(totalTx / 1e6).toFixed(1)} MB/s up across ${active.length} active interfaces.
Per interface (name: rx rate, tx rate, rx total, tx total, all bytes/bytes-per-sec):
${active.map(([k, v]) => `- ${k}: rx=${v.rx_rate}B/s tx=${v.tx_rate}B/s rx_total=${v.rx_total} tx_total=${v.tx_total}`).join('\n')}

Flag: any interface sustaining a rate that looks like saturation for a typical home/media-server gigabit link (>110MB/s sustained), container network interfaces (vethXXXX/docker0) with disproportionate traffic vs the physical uplink, and anything that looks like a stuck/runaway transfer.`;

  const text = await callAnalyze(
    await makeAnalysisAgent('Vitals-Network',
      'You are the network specialist inside Unraid Vitals, a background health-monitoring agent. You are given per-interface throughput data and must return structured findings, nothing else.',
      { maxTokens: 700 }),
    system, user
  );
  return safeParseFindings(extractJson(text));
}
