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

class OllamaError extends Error {
  constructor(message, causes) {
    super(message);
    this.name = 'OllamaError';
    this.causes = causes;
  }
}

async function callOnce(baseURL, systemPrompt, userPrompt, opts = {}) {
  // Native /api/chat + think:false — the OpenAI-compat endpoint has no way
  // to disable qwen3's reasoning mode, so it burns max_tokens on hidden
  // "reasoning" and returns empty content. Native+think:false is ~10x
  // faster and returns clean content directly (measured: 25s -> 1.7s).
  const res = await fetch(`${baseURL.replace(/\/$/, '')}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: opts.model || MODEL,
      stream: false,
      think: false,
      options: { temperature: opts.temperature ?? 0.2, num_predict: opts.maxTokens ?? 900 },
      messages: [
        { role: 'system', content: systemPrompt },
        { role: 'user', content: userPrompt }
      ]
    }),
    signal: AbortSignal.timeout(opts.timeoutMs || TIMEOUT_MS)
  });
  if (!res.ok) throw new Error(`${baseURL} returned HTTP ${res.status}`); // never echo body (secret-safe)
  const json = await res.json();
  const content = json?.message?.content;
  if (!content) throw new Error(`${baseURL} returned empty content`);
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
  for (let i = 0; i < attempts.length; i++) {
    try { return await attempts[i](); }
    catch (e) { errors.push(e); if (i < attempts.length - 1) await new Promise(r => setTimeout(r, 5000)); }
  }
  throw new OllamaError('both LLM studios unreachable after retry', {
    primary: errors[0], primaryRetry: errors[1], fallback: errors[2], fallbackRetry: errors[3]
  });
}

/** Extract the first balanced {...} or [...] from a model response that may
 *  include reasoning/markdown fences around it. */
export function extractJson(text) {
  const fence = text.match(/```(?:json)?\s*([\s\S]*?)```/i);
  const body = fence ? fence[1] : text;
  const start = body.search(/[[{]/);
  if (start < 0) throw new Error('no JSON found in model response');
  const open = body[start], close = open === '{' ? '}' : ']';
  let depth = 0;
  for (let i = start; i < body.length; i++) {
    if (body[i] === open) depth++;
    else if (body[i] === close) { depth--; if (depth === 0) return JSON.parse(body.slice(start, i + 1)); }
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

/** Thin call wrapper — agent.call() returns {data: <skill return value>}. */
export async function callAnalyze(agent, system, user) {
  const res = await agent.call('analyze', { system, user });
  return res?.data ?? res;
}

export const config = { PRIMARY, FALLBACK, MODEL, TIMEOUT_MS };
