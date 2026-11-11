#!/usr/bin/env node

import { getDb, getResearchJob, startResearchJob, finishResearchJob, failResearchJob, searchKb, ingestFindingToKb } from './lib/db.mjs';
import { makeAnalysisAgent, callAnalyze, extractJson, budgetPrompt } from './lib/smythos-client.mjs';
import { selectModels } from './lib/models.mjs';
import { TOOLS_PROMPT, runResearchTool, parseToolCall } from './lib/research-tools.mjs';
import { latestSnapshot, vmList, activeSource } from './lib/sources.mjs';
import { window as timelineWindow, describeWindow } from './lib/timeline.mjs';

const BEHAVIOR = `You are the Unraid Vitals research assistant. You answer questions about
this specific Unraid server using ONLY the context provided (knowledge-base excerpts,
recent event history, and a live system snapshot) — never invent metrics, disk names, or
container/VM names that aren't in the context. If the context doesn't contain enough to
answer, say so plainly and suggest what data would help. Be concise and factual, not hype-y.`;

function detectVmScope(prompt, vms) {
  const lower = prompt.toLowerCase();
  return vms.find(vm => lower.includes(vm.name.toLowerCase())) || null;
}

function detectWindowHours(prompt) {
  const p = prompt.toLowerCase();
  let m;
  if ((m = p.match(/(?:past|last|previous)\s+(\d+)\s*(day|hour|hr|week|h\b|d\b)/))) {
    const n = Number(m[1]); const u = m[2];
    return Math.min(720, u.startsWith('w') ? n * 168 : u.startsWith('d') ? n * 24 : n);
  }
  if (/\b(a|one)\s+week\b|this week|past week|last week/.test(p)) return 168;
  if (/yesterday/.test(p)) return 48;
  if (/today|since (this )?morning|last night|tonight|overnight/.test(p)) return 24;
  if ((m = p.match(/since\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)/))) {
    const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    const diff = (new Date().getDay() - days.indexOf(m[1]) + 7) % 7 || 7;
    return diff * 24;
  }
  if (/right now|currently|at the moment/.test(p)) return 2;
  return 48;
}

function isAnalytical(prompt) {
  return /\b(why|cause|caused|reason|trend|compare|diagnos|correlat|investigate|analy[sz]e|root cause|what happened|degrad|slow(er|down)?|spike|unusual|anomal)\b/i.test(prompt);
}

async function main() {
  const jobId = Number(process.argv[2]);
  if (!jobId) { console.error('usage: node research.mjs <jobId>'); process.exit(1); }

  const job = getResearchJob(jobId);
  if (!job) { console.error(`job ${jobId} not found`); process.exit(1); }

  startResearchJob(jobId);
  try {
    let contextPrefix = '';
    let kbHits = searchKb(job.prompt, 12);
    if (/^auto:unraid-release:/.test(job.origin || '')) {
      
      const ver = job.origin.split(':')[2];
      const rel = getDb().prepare(`SELECT * FROM kb_documents WHERE source = 'unraid-release' AND source_ref = ?`).get(ver);
      if (rel) {
        kbHits = kbHits.filter(d => d.id !== rel.id);
        contextPrefix = `Official Unraid ${ver} release notes:\n${rel.content.slice(0, 7000)}\n`;
      }
    }
    const source = activeSource();
    const snap = source.snapshot() ?? latestSnapshot();
    const vms = vmList();
    const scopedVm = detectVmScope(job.prompt, vms);
    const windowHours = detectWindowHours(job.prompt);
    
    
    
    
    const tl = timelineWindow(windowHours, { entity: scopedVm ? scopedVm.name : undefined });

    
    
    
    
    const trigger = [];
    if (contextPrefix) trigger.push(contextPrefix);
    if (job.context) trigger.push(`Why this research was opened (${job.origin || 'auto'}):\n${String(job.context).slice(0, 4000)}\n`);

    const timeline = [];
    timeline.push(`Time range the question is about: last ${windowHours}h (${new Date(tl.from * 1000).toISOString()} → ${new Date(tl.to * 1000).toISOString()}). Answer about THIS range unless the question says otherwise; say explicitly when data for part of the range is hourly-only or missing.`);
    timeline.push('\nTimeline (pre-aggregated — quote these numbers, do not recompute):');
    timeline.push(describeWindow(tl));
    if (scopedVm) {
      timeline.push(`\nThe question is about VM "${scopedVm.name}" — current state: ${scopedVm.state}. Events above are already filtered to this VM.${tl.events.length ? '' : ` No state transitions recorded for it in the last ${windowHours}h (settled the whole time, or vmwatch has not run yet).`}`);
    } else if (vms.length) {
      timeline.push(`\nAll VMs currently: ${vms.map(v => `${v.name}=${v.state}`).join(', ')}`);
    }

    const kb = [];
    if (kbHits.length) {
      kb.push('\nKnowledge base excerpts (agent findings, past research, study reports):');
      for (const doc of kbHits) {
        kb.push(`- [doc#${doc.id}] (${doc.topic || doc.source}, ${new Date(doc.created_at * 1000).toISOString().slice(0, 16)}) ${doc.title}\n  ${doc.content.slice(0, 400)}`);
      }
    } else {
      kb.push('\n(No matching knowledge-base documents.)');
    }

    const snapshot = [];
    if (snap) {
      
      
      
      
      
      const p2 = job.prompt.toLowerCase();
      const wants = (re) => re.test(p2);
      const detail = {};
      if (wants(/disk|drive|smart|reallocat|pending|sector|spin|temperature|hot|cool|temp\b/)) {
        detail.diskTemps = (snap.disks || []).map(d => ({ name: d.name, role: d.role ?? '', temp: d.temp, usedPct: d.usedPct, spin: d.spundown }));
        detail.smart = snap.smart;
        if (snap.tempMax != null) detail.tempMax = snap.tempMax;
      }
      if (wants(/fan|cooling|airflow|throttl/)) detail.sensors = snap.sensors && { fans: snap.sensors.fans, temps: (snap.sensors.temps || []).slice(0, 12) };
      if (wants(/pool|zfs|btrfs|scrub|raid|parity/)) {
        detail.poolHealth = snap.pool_health ?? null;
        detail.parityHistory = (snap.parity_history || []).slice(-5);
      }
      if (wants(/network|interface|eth\d|bond|vlan|mtu|link|port|nics?/)) detail.net = { link: snap.net_link, net: snap.net };
      if (wants(/share|smb|nfs|export/)) detail.shareList = snap.shares && snap.shares.list;
      if (wants(/docker|container|image|compose/)) detail.dockerDetail = { running: snap.docker?.running, count: snap.docker?.count, containers: (snap.docker?.containers || []).slice(0, 12) };
      if (wants(/vm|vmware|virt|kvm/)) detail.vms = snap.vms;
      if (wants(/log|syslog|kernel|oom|crash|dmesg/)) detail.syslogTail = String(source.tail('syslog', 60) ?? '').slice(-1800);

      snapshot.push('\nLive snapshot (baseline):');
      snapshot.push(JSON.stringify({
        time: snap.time, system: snap.system, load: snap.load,
        array: snap.array && snap.array.totals, docker: snap.docker && { running: snap.docker.running, count: snap.docker.count },
        vms: snap.vms && { running: snap.vms.running, count: snap.vms.count },
        shares: snap.shares && { total: snap.shares.total },
      }).slice(0, 1200));
      const detailKeys = Object.keys(detail);
      if (detailKeys.length) {
        snapshot.push(`\nTopic detail (the question is about: ${detailKeys.join(', ')}):`);
        snapshot.push(JSON.stringify(detail).slice(0, 6000));
      }
    }

    
    
    
    const budgeted = budgetPrompt([
      { name: 'question', priority: 100, text: `Question: ${job.prompt}\n\nContext:` },
      { name: 'trigger', priority: 80, text: trigger.join('\n') },
      { name: 'timeline', priority: 50, text: timeline.join('\n') },
      { name: 'knowledge-base', priority: 30, text: kb.join('\n') },
      { name: 'snapshot', priority: 10, text: snapshot.join('\n') },
      { name: 'instructions', priority: 100, text: `Respond with strict JSON: {"answer": "<markdown-formatted answer>", "used_docs": [<doc ids you actually relied on>]}` },
    ], BEHAVIOR, 1400); 
    if (budgeted.trimmed.length) console.warn(`[research#${jobId}] trimmed to fit context: ${budgeted.trimmed.join(', ')} (${budgeted.tokens} tok)`);
    const userPrompt = budgeted.text;

    let answer, usedDocs;
    if (isAnalytical(job.prompt)) {
      
      
      
      
      
      
      
      
      
      
      const models = await selectModels({
        enabled: process.env.VITALS_DIAG_MODELS || '',
        prefer: ['gemma3:12b', 'qwen2.5:7b', 'deepseek-coder-v2:16b'],
        limit: 3
      });
      
      
      
      
      let drafts = [];
      let lastErr;
      for (let attempt = 1; attempt <= 3 && !drafts.length; attempt++) {
        const perModel = [];
        for (const model of models) {
          try {
            const agent = await makeAnalysisAgent(`Vitals-Research-${model}`, BEHAVIOR, { maxTokens: 900, temperature: 0.3, model });
            
            
            
            let round = 0, toolUser = userPrompt;
            let parsed;
            for (;;) {
              const raw = await callAnalyze(agent, BEHAVIOR + TOOLS_PROMPT, toolUser);
              const call = parseToolCall(raw);
              if (!call || ++round > 3) { parsed = extractJson(raw); break; }
              const result = runResearchTool(call.name, call.args);
              console.log(`[research] tool ${round}: ${call.name}(${JSON.stringify(call.args).slice(0,80)}) -> ${JSON.stringify(result).slice(0,80)}…`);
              toolUser = `${userPrompt}\n\nTOOL RESULT ${round} — ${call.name}(${JSON.stringify(call.args)}):\n${JSON.stringify(result).slice(0, 4000)}\n\nContinue: either the next tool call or the final JSON answer.`;
            }
            if (typeof parsed?.answer === 'string') perModel.push({ model, answer: parsed.answer, used: Array.isArray(parsed.used_docs) ? parsed.used_docs : [] });
            else lastErr = `${model}: no answer field in JSON`;
          } catch (e) { lastErr = e?.message || String(e); console.warn(`[research#${jobId}] ${model} failed: ${lastErr}`); }
        }
        drafts = perModel;
        if (!drafts.length && attempt < 3) {
          console.warn(`[research#${jobId}] no drafts on attempt ${attempt} (${lastErr}); retrying in ${attempt * 20}s`);
          await new Promise(r => setTimeout(r, attempt * 20000));
        }
      }
      if (!drafts.length) throw new Error(`all models failed after 3 attempts: ${lastErr}`);
      if (drafts.length === 1) { answer = drafts[0].answer; usedDocs = drafts[0].used; }
      else {
        const synthPrompt = `Question: ${job.prompt}\n\n${drafts.map(d => `--- Answer from ${d.model} ---\n${d.answer}`).join('\n\n')}\n\n` +
          `Write ONE final markdown answer. Where the drafts agree, state it plainly. Where they disagree on a fact or a cause, keep BOTH positions and name which model said which — do not pick silently. Cut anything not grounded in the drafts. End with a one-line "Confidence:" note (high = all agreed, medium = mostly, low = conflicting). ` +
          `Respond with strict JSON: {"answer": "<markdown>", "used_docs": [<ids>]}`;
        const synth = await makeAnalysisAgent('Vitals-Research-Synthesis', BEHAVIOR, { maxTokens: 1100, temperature: 0.2 });
        const parsed = extractJson(await callAnalyze(synth, BEHAVIOR, synthPrompt));
        answer = typeof parsed?.answer === 'string' ? parsed.answer : drafts[0].answer;
        answer += `\n\n<sub>Cross-checked across ${drafts.length} local models: ${drafts.map(d => d.model).join(', ')}</sub>`;
        usedDocs = [...new Set(drafts.flatMap(d => d.used))];
      }
    } else {
      const agent = await makeAnalysisAgent('Vitals-Research', BEHAVIOR, { maxTokens: 900, temperature: 0.3 });
      const raw = await callAnalyze(agent, BEHAVIOR, userPrompt);
      const parsed = extractJson(raw);
      answer = typeof parsed?.answer === 'string' ? parsed.answer : String(raw).slice(0, 4000);
      usedDocs = Array.isArray(parsed?.used_docs) ? parsed.used_docs : kbHits.map(d => d.id);
    }

    finishResearchJob(jobId, answer, usedDocs);
    
    ingestFindingToKb('research', { id: jobId, title: job.prompt, detail: answer, severity: 'info' });
    console.log(`[research#${jobId}] done`);
  } catch (e) {
    failResearchJob(jobId, e?.message || String(e));
    console.error(`[research#${jobId}] FAILED: ${e?.message || e}`);
    process.exit(1);
  }
}

main();
