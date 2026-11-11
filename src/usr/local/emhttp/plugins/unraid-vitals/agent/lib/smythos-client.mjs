
const PRIMARY = process.env.LLM_STUDIO_PRIMARY || 'https://llmstudio2.hazemhagrass.com';
const FALLBACK = process.env.LLM_STUDIO_BACKUP || 'https://llmstudio1.hazemhagrass.com';
const MODEL = process.env.VITALS_AGENT_MODEL || 'qwen3:14b';
const TIMEOUT_MS = Number(process.env.VITALS_AGENT_TIMEOUT_MS || 600000);

const NUM_CTX = Number(process.env.VITALS_AGENT_NUM_CTX || 8192);

const CHARS_PER_TOKEN = Number(process.env.VITALS_CHARS_PER_TOKEN || 2.8);

class OllamaError extends Error {
  constructor(message, causes) {
    super(message);
    this.name = 'OllamaError';
    this.causes = causes;
  }
}

export const lastCallStats = { prompt_eval_count: null, eval_count: null, num_ctx: NUM_CTX, near_limit: false, endpoint: null, format: null, retries: 0 };

export function estimateTokens(text) { return Math.ceil(String(text ?? '').length / CHARS_PER_TOKEN); }

export function budgetPrompt(sections, systemPrompt, maxTokens = 900, numCtx = NUM_CTX) {
  const budget = numCtx - maxTokens - estimateTokens(systemPrompt) - 64; 
  const parts = sections.map(s => ({ ...s, text: String(s.text ?? '') }));
  let used = parts.reduce((n, s) => n + estimateTokens(s.text), 0);
  const trimmed = [];
  const order = [...parts].sort((a, b) => (a.priority ?? 0) - (b.priority ?? 0));
  for (const s of order) {
    if (used <= budget) break;
    const over = used - budget;
    const keepTokens = Math.max(0, estimateTokens(s.text) - over);
    const keepChars = Math.floor(keepTokens * CHARS_PER_TOKEN);
    if (keepChars >= s.text.length) continue;
    const marker = `\n[... ${s.name || 'section'} truncated to fit the context window ...]`;
    s.text = s.text.slice(0, Math.max(0, keepChars - marker.length)) + marker;
    trimmed.push(s.name || 'section');
    used = parts.reduce((n, x) => n + estimateTokens(x.text), 0);
  }
  return { text: parts.map(s => s.text).join('\n\n'), trimmed, tokens: used };
}

async function callOnce(baseURL, systemPrompt, userPrompt, opts = {}) {
  
  
  
  
  const body = {
    model: opts.model || MODEL,
    stream: false,
    think: false,
    options: { temperature: opts.temperature ?? 0.2, num_predict: opts.maxTokens ?? 900, num_ctx: opts.numCtx ?? NUM_CTX },
    messages: [
      { role: 'system', content: systemPrompt },
      { role: 'user', content: userPrompt }
    ]
  };
  
  
  
  
  if (opts.format) body.format = opts.format;
  const res = await fetch(`${baseURL.replace(/\/$/, '')}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(opts.timeoutMs || TIMEOUT_MS)
  });
  if (!res.ok) throw new Error(`${baseURL} returned HTTP ${res.status}`); 
  const json = await res.json();
  const content = json?.message?.content;
  if (!content) throw new Error(`${baseURL} returned empty content`);
  const numCtx = body.options.num_ctx;
  Object.assign(lastCallStats, {
    prompt_eval_count: json.prompt_eval_count ?? null,
    eval_count: json.eval_count ?? null,
    num_ctx: numCtx,
    near_limit: json.prompt_eval_count != null && json.prompt_eval_count >= numCtx * 0.9,
    endpoint: baseURL,
    format: opts.format ? 'json_schema' : null,
    retries: lastCallStats.retries ?? 0
  });
  if (lastCallStats.near_limit) {
    console.warn(`[llm] prompt used ${json.prompt_eval_count}/${numCtx} context tokens — raise VITALS_AGENT_NUM_CTX or the prompt was truncated`);
  }
  return content;
}

async function promptOllama(systemPrompt, userPrompt, opts = {}) {
  const attempts = [
    () => callOnce(PRIMARY, systemPrompt, userPrompt, opts),
    () => callOnce(PRIMARY, systemPrompt, userPrompt, opts),
    () => callOnce(FALLBACK, systemPrompt, userPrompt, opts),
    () => callOnce(FALLBACK, systemPrompt, userPrompt, opts)
  ];
  const errors = [];
  lastCallStats.retries = 0;
  for (let i = 0; i < attempts.length; i++) {
    try { return await attempts[i](); }
    catch (e) {
      errors.push(e);
      lastCallStats.retries = i;
      if (i < attempts.length - 1) await new Promise(r => setTimeout(r, 5000));
    }
  }
  throw new OllamaError('both LLM studios unreachable after retry', {
    primary: errors[0], primaryRetry: errors[1], fallback: errors[2], fallbackRetry: errors[3]
  });
}

export function extractJson(text) {
  if (typeof text !== 'string') {
    throw new Error(`expected string from LLM call, got ${typeof text}: ${JSON.stringify(text).slice(0, 200)}`);
  }
  const fence = text.match(/```(?:json)?\s*([\s\S]*?)```/i);
  const body = (fence ? fence[1] : text).trim();
  if (body.startsWith('{') || body.startsWith('[')) {
    try { return JSON.parse(body); } catch {  }
  }
  const start = body.search(/[[{]/);
  if (start < 0) throw new Error('no JSON found in model response');
  let depth = 0, inStr = false, esc = false;
  for (let i = start; i < body.length; i++) {
    const c = body[i];
    if (inStr) {
      if (esc) esc = false;
      else if (c === '\\') esc = true;
      else if (c === '"') inStr = false;
      continue;
    }
    if (c === '"') inStr = true;
    else if (c === '{' || c === '[') depth++;
    else if (c === '}' || c === ']') { depth--; if (depth === 0) return JSON.parse(body.slice(start, i + 1)); }
  }
  throw new Error('unbalanced JSON in model response');
}

export async function makeAnalysisAgent(name, behavior, opts = {}) {
  const { Agent, Model } = await import('@smythos/sdk');
  const agent = new Agent({
    name,
    behavior,
    model: Model.Ollama(opts.model || MODEL, { baseURL: PRIMARY, temperature: opts.temperature ?? 0.2, maxTokens: opts.maxTokens ?? 900 })
  });
  agent.addSkill({
    name: 'analyze',
    description: `Analyze current Unraid state for the "${name}" domain and return findings as raw model text.`,
    inputs: { system: { source: null, type: 'Text', description: 'system prompt' },
              user: { source: null, type: 'Text', description: 'user prompt with the real data' } },
    process: async (input) => promptOllama(input.system, input.user, opts)
  });
  return agent;
}

export async function callAnalyze(agent, system, user) {
  const res = await agent.call('analyze', { system, user });
  const data = res?.data ?? res;
  if (data && typeof data === 'object' && typeof data._error === 'string') {
    const err = new OllamaError(data._error, { swallowedBy: 'smythos-skill' });
    err.causes = data;
    throw err;
  }
  return data;
}

export const config = { PRIMARY, FALLBACK, MODEL, TIMEOUT_MS, NUM_CTX };
