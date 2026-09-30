/**
 * Auto-research: turn the most critical findings into research/study jobs
 * without the admin having to ask.
 *
 *   disk failure / SMART degradation  → 12h study scoped to THAT disk
 *   new Unraid OS release             → "should we upgrade to vX?" report
 *                                        grounded in the real release notes
 *   critical thermal / pool / general → one-shot root-cause research
 *
 * Rules are pure (finding → {key, mode, prompt, context, cooldown}) so they
 * are unit-testable; firing is idempotent per key via auto_triggers.
 * The resulting jobs carry origin='auto:<key>' so the Research tab can
 * show a badge and the admin sees WHY a job exists.
 */
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { claimAutoTrigger, recordAutoTriggerJob, createResearchJob, createStudyJob, getDb } from './db.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const H = 3600;

const DISK_RE = /\b(disk\d+|parity2?|cache\d*|sd[a-z]+|nvme\d+n\d+)\b/i;

/** Decide what, if anything, a finding should trigger. Returns null for
 *  "nothing". Exported for tests. */
export function ruleFor(agent, f) {
  const sev = f.severity;
  const text = `${f.title} ${f.detail || ''}`;

  if (agent === 'unraid-release' && f.meta?.latest) {
    return {
      key: `unraid-release:${f.meta.latest}`, mode: 'once', cooldown: 30 * 24 * H,
      prompt: `Unraid OS ${f.meta.latest} is available and this server runs ${f.meta.installed}. Using the official release notes in the knowledge base, explain to the admin whether and why this server should upgrade to ${f.meta.latest}: list the fixes/security updates/features that matter for THIS server's hardware, Docker containers, VMs and array state, call out known issues and rollback caveats that apply here, and end with a clear recommendation (upgrade now / wait / skip) and a one-line reason.`,
      context: null,
    };
  }

  if (agent === 'disks' && (sev === 'critical' || sev === 'error')) {
    const disk = (f.subject && DISK_RE.test(f.subject)) ? f.subject : (text.match(DISK_RE) || [])[1];
    if (!disk) return null;
    return {
      key: `disk:${disk}`, mode: 'study', hours: 12, tick: 30, cooldown: 3 * 24 * H,
      prompt: `Study ${disk} specifically for the next 12 hours: it was flagged "${f.title}". Track its SMART attributes (reallocated, pending, uncorrectable, CRC), temperature, error count and spin state on every tick, note any change, correlate with array activity and parity operations, and in the final report state whether the drive is failing, how urgent replacement is, and the safest replacement/rebuild procedure for this array layout.`,
      context: `Triggering finding (${sev}): ${f.title}\n${f.detail || ''}\n${f.recommendation || ''}`,
    };
  }

  if (sev === 'critical') {
    return {
      key: `${agent}:${(f.subject || f.title).toLowerCase().replace(/[^a-z0-9]+/g, '-').slice(0, 60)}`,
      mode: 'once', cooldown: 24 * H,
      prompt: `Investigate the critical condition "${f.title}" (${agent} agent). Establish the root cause from the timeline, what is at risk, and the exact remediation steps for this server. Be specific about which container/disk/interface is involved.`,
      context: `Triggering finding (critical): ${f.title}\n${f.detail || ''}\n${f.recommendation || ''}`,
    };
  }
  return null;
}

/** Evaluate every finding of one agent run; file jobs; kick research.mjs
 *  for one-shot jobs (study jobs are advanced by the study cron). Returns
 *  the jobs it filed so analyze.mjs can log them. */
export function fireAutoResearch(agent, findings, { launch = true } = {}) {
  const filed = [];
  for (const f of findings) {
    const rule = ruleFor(agent, f);
    if (!rule) continue;
    if (!claimAutoTrigger(rule.key, rule.cooldown)) continue;
    const origin = `auto:${rule.key}`;
    let jobId;
    if (rule.mode === 'study') {
      jobId = createStudyJob(rule.prompt, rule.hours * 60, rule.tick);
      getDb().prepare(`UPDATE research_jobs SET origin = ?, context = ? WHERE id = ?`).run(origin, rule.context, jobId);
    } else {
      jobId = createResearchJob(rule.prompt, { origin, context: rule.context });
    }
    recordAutoTriggerJob(rule.key, jobId);
    filed.push({ key: rule.key, mode: rule.mode, jobId });
    if (launch) launchJob(rule.mode, jobId);
  }
  return filed;
}

function launchJob(mode, jobId) {
  const script = join(HERE, '..', mode === 'study' ? 'study.mjs' : 'research.mjs');
  const args = mode === 'study' ? ['--once', String(jobId)] : [String(jobId)];
  try {
    const child = spawn(process.execPath, [script, ...args], { detached: true, stdio: 'ignore', env: process.env });
    child.unref();
  } catch (e) { console.warn(`[auto-research] launch failed for job ${jobId}: ${e?.message || e}`); }
}
