/** Model discovery: what the two LLM studios can actually serve.
 *
 * The admin used to hand-maintain VITALS_DIAG_MODELS, which drifts: it
 * listed nomic-embed-text (an embedding model that can never answer a chat
 * prompt) and omitted models that had been pulled later. Every stale entry
 * costs a full doomed call in each multi-model pass.
 *
 * This queries /api/tags on both studios, merges them, and returns the
 * models that are actually usable for a chat call, tagged with where they
 * live. Cached briefly — a discovery call must never be on the hot path of
 * an agent run.
 */

// Overridable so tests can point discovery at a stub instead of the network.
let PRIMARY = process.env.LLM_STUDIO_PRIMARY || 'https://llmstudio2.hazemhagrass.com';
let FALLBACK = process.env.LLM_STUDIO_BACKUP || 'https://llmstudio1.hazemhagrass.com';
export function setStudioEndpoints(primary, fallback) {
  PRIMARY = primary;
  FALLBACK = fallback;
}
const CACHE_MS = Number(process.env.VITALS_MODEL_DISCOVERY_TTL_MS || 600000);

// Never offered as a diagnostic/research model: no chat ability.
const NON_CHAT = /^(nomic-embed|all-minilm|mxbai-embed|text-embedding|bge-|e5-|gte-)/i;

let cache = { at: 0, models: null };

async function tags(baseURL) {
  const res = await fetch(`${baseURL.replace(/\/$/, '')}/api/tags`, { signal: AbortSignal.timeout(8000) });
  if (!res.ok) throw new Error(`${baseURL} /api/tags → HTTP ${res.status}`);
  const json = await res.json();
  return Array.isArray(json?.models) ? json.models : [];
}

/** Ids that can serve a chat request, merged across studios. */
export async function discoverModels({ force = false } = {}) {
  if (!force && cache.models && Date.now() - cache.at < CACHE_MS) return cache.models;

  const found = new Map();
  const [a, b] = await Promise.allSettled([tags(PRIMARY), tags(FALLBACK)]);

  for (const raw of a.status === 'fulfilled' ? a.value : []) {
    const name = String(raw?.name || '').trim();
    if (!name || NON_CHAT.test(name)) continue;
    if (!found.has(name)) found.set(name, { name, on: [] });
    found.get(name).on.push(PRIMARY);
  }
  for (const raw of b.status === 'fulfilled' ? b.value : []) {
    const name = String(raw?.name || '').trim();
    if (!name || NON_CHAT.test(name)) continue;
    if (!found.has(name)) found.set(name, { name, on: [] });
    found.get(name).on.push(FALLBACK);
  }

  const models = [...found.values()].map((m) => ({ ...m, both: m.on.length > 1 }));
  cache = { at: Date.now(), models };
  return models;
}

/** Ids only, for feeding VITALS_DIAG_MODELS. */
export async function discoverModelIds(opts) {
  return (await discoverModels(opts)).map((m) => m.name);
}

/**
 * Pick which models a multi-model pass should use.
 *
 * Prefers what the admin explicitly enabled (so their on/off choice is
 * respected), falls back to discovery when that list is empty or names
 * models that are gone. Order is deterministic: biggest context-friendly
 * models first, so the primary model in a 3-model pass is a strong one.
 */
export async function selectModels({ enabled = '', prefer = [], limit = 3, exclude = [] } = {}) {
  const ids = await discoverModelIds().catch(() => []);
  if (!ids.length) return prefer.slice(0, limit);

  const available = new Set(ids);
  const enabledIds = String(enabled || '').split(',').map((s) => s.trim()).filter((s) => s && available.has(s) && !NON_CHAT.test(s));
  const base = enabledIds.length ? enabledIds : ids.filter((id) => !exclude.includes(id));

  // Stable order: honour `prefer` first (newest pulls usually belong there),
  // then whatever discovery returned, deduped, capped.
  const ordered = [...prefer.filter((p) => base.includes(p)), ...base].filter((id, i, arr) => arr.indexOf(id) === i);
  return ordered.slice(0, limit);
}