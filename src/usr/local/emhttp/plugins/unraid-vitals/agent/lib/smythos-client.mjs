/**
 * unraid-vitals agent — shared SmythOS coded-agent factory.
 *
 * Pattern taken from the PostRocket reference project (~/workspace/smyth/forged/postrocket):
 * a real `@smythos/sdk` Agent + Model.Ollama() for identity/config, ONE skill
 * that does all the real work, invoked via `agent.call(name, input)` —
 * never `agent.prompt()`. agent.prompt() drives SmythOS's planner/
 * Conversation layer, which needs a cloud session and throws
 * "Cannot read properties of undefined (reading 'addUserMessage')" headless
 * (documented in the smythos-sdk-ollama-gotchas skill). agent.call() invokes
 * the skill directly — no planner involved, ~100% reliable in a cron job.
 *
 * The skill's own `process` function does the actual model call against
 * Ollama's native /api/chat with think:false (qwen3 burns the whole token
 * budget on hidden reasoning otherwise — see ollama-think-fix note below),
 * with primary→fallback studio failover and one retry each.
 */
const PRIMARY = process.env.LLM_STUDIO_PRIMARY || 'https://llmstudio2.hazemhagrass.com';
const FALLBACK = process.env.LLM_STUDIO_BACKUP || 'https://llmstudio1.hazemhagrass.com';
const MODEL = process.env.VITALS_AGENT_MODEL || 'qwen3:14b';
const TIMEOUT_MS = Number(process.env.VITALS_AGENT_TIMEOUT_MS || 600000);
// Context window sent as options.num_ctx. Without it Ollama uses the model's
// default (often 2048-4096) and silently truncates a long prompt — the model
// loses either the instructions or the data and the run still reports ok.
// 8192 fits a 24-disk snapshot + 4 KB log tail + contract with room for the
// answer; raise it for boxes with more disks if the recorded prompt tokens
// approach the limit (see lastCallStats.prompt_eval_count).
const NUM_CTX = Number(process.env.VITALS_AGENT_NUM_CTX || 8192);
// ~4 chars/token is the usual English/JSON estimate; the safety margin
// covers model-specific tokenizers being less efficient on '|', '=' and
// numbers, which dominate our prompts.
// Prose averages ~3.6 chars/token, but the prompts here are dense
// markdown/JSON/log lines (measured live: a budgeted 6.2k-"token" release
// advisory tokenized to 8024) — 2.8 is the conservative figure that keeps
// the estimate on the safe side for every section type we send.
const CHARS_PER_TOKEN = Number(process.env.VITALS_CHARS_PER_TOKEN || 2.8);

class OllamaError extends Error {
  constructor(message, causes) {
    super(message);
    this.name = 'OllamaError';
    this.causes = causes;
  }
}

/** Stats from the most recent successful model call — read by the
 *  orchestrator after each agent so runs can record prompt/response tokens
 *  and warn when the prompt got within 10% of num_ctx. */
export const lastCallStats = { prompt_eval_count: null, eval_count: null, num_ctx: NUM_CTX, near_limit: false, endpoint: null, format: null, retries: 0 };

export function estimateTokens(text) { return Math.ceil(String(text ?? '').length / CHARS_PER_TOKEN); }

/**
 * Fit a prompt into the context window. `sections` is an ordered list of
 * { text, priority } — LOWER priority is trimmed first (log tails before the
 * disk table before the instructions). Each trimmed section is cut from the
 * end and marked so the model knows data was elided rather than absent.
 * Returns { text, trimmed: [names], tokens }.
 */
export function budgetPrompt(sections, systemPrompt, maxTokens = 900, numCtx = NUM_CTX) {
  const budget = numCtx - maxTokens - estimateTokens(systemPrompt) - 64; // 64 = chat template overhead
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
  // Native /api/chat + think:false — the OpenAI-compat endpoint has no way
  // to disable qwen3's reasoning mode, so it burns max_tokens on hidden
  // "reasoning" and returns empty content. Native+think:false is ~10x
  // faster and returns clean content directly (measured: 25s -> 1.7s).
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
  // Structured output: when a JSON schema is given the model can only emit
  // JSON of that shape — braces inside a detail string stop being a parse
  // hazard. Models/servers that ignore `format` just return text and the
  // caller's extractJson() fallback still applies.
  if (opts.format) body.format = opts.format;
  const res = await fetch(`${baseURL.replace(/\/$/, '')}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(opts.timeoutMs || TIMEOUT_MS)
  });
  if (!res.ok) throw new Error(`${baseURL} returned HTTP ${res.status}`); // never echo body (secret-safe)
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

/** Primary→fallback with one retry per endpoint — a transient 5xx under a
 *  large prompt (observed: HTTP 521 on both studios for ~1min) shouldn't
 *  fail the whole run when the endpoint recovers in seconds. CPU-only
 *  inference on these studios is genuinely slow (measured 20-300s per call
 *  depending on server load) — that is expected, not a hang; the retry
 *  backoff is intentionally short (5s) so it doesn't compound the wait. */
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
    catch (e) { errors.push(e); lastCallStats.retries = i;
    catch (e) { errors.push(e); if (i < attempts.length - 1) await new Promise(r => setTimeout(r, 5000)); }
  }
  throw new OllamaError('both LLM studios unreachable after retry', {
    primary: errors[0], primaryRetry: errors[1], fallback: errors[2], fallbackRetry: errors[3]
  });
}

/** Extract the first balanced {...} or [...] from a model response that may
 *  include reasoning/markdown fences around it. Fast path: the whole body is
 *  JSON already (structured output). Slow path: a string-aware bracket walk
 *  so `{` / `}` inside a finding's detail text no longer break parsing. */
export function extractJson(text) {
  if (typeof text !== 'string') {
    throw new Error(`expected string from LLM call, got ${typeof text}: ${JSON.stringify(text).slice(0, 200)}`);
  }
  const fence = text.match(/```(?:json)?\s*([\s\S]*?)```/i);
  const body = (fence ? fence[1] : text).trim();
  if (body.startsWith('{') || body.startsWith('[')) {
    try { return JSON.parse(body); } catch { /* fall through to the walk */ }
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

/**
 * Build a SmythOS coded agent with one `analyze` skill. `buildPrompt(ctx)`
 * returns { system, user } strings; the skill process() calls Ollama and
 * returns raw text — callers extract/validate JSON themselves so each
 * specialist keeps its own response contract in one place (agents/contract.mjs).
 */
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

/** Thin call wrapper — agent.call() returns {data: <skill return value>}.
 *
 *  When an exception escapes a skill's process(), the SmythOS SDK swallows it
 *  and resolves with {data:{_error:'…'}} instead of rejecting. Callers then
 *  receive an OBJECT where they expect a string, and the first thing they do
 *  is `.trim()` — which throws "(intermediate value).trim is not a function"
 *  and buries the actual cause. Observed live: a studio outage surfaced in the
 *  log as a trim() TypeError, sending the investigation after a non-existent
 *  parsing bug. Re-throw here so every caller sees the real error. */
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
