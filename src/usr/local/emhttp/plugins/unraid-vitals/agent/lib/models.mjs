

let PRIMARY = process.env.LLM_STUDIO_PRIMARY || 'https://llmstudio2.hazemhagrass.com';
let FALLBACK = process.env.LLM_STUDIO_BACKUP || 'https://llmstudio1.hazemhagrass.com';
export function setStudioEndpoints(primary, fallback) {
  PRIMARY = primary;
  FALLBACK = fallback;
}
const CACHE_MS = Number(process.env.VITALS_MODEL_DISCOVERY_TTL_MS || 600000);

const NON_CHAT = /^(nomic-embed|all-minilm|mxbai-embed|text-embedding|bge-|e5-|gte-)/i;

let cache = { at: 0, models: null };

async function tags(baseURL) {
  const res = await fetch(`${baseURL.replace(/\/$/, '')}/api/tags`, { signal: AbortSignal.timeout(8000) });
  if (!res.ok) throw new Error(`${baseURL} /api/tags → HTTP ${res.status}`);
  const json = await res.json();
  return Array.isArray(json?.models) ? json.models : [];
}

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

export async function discoverModelIds(opts) {
  return (await discoverModels(opts)).map((m) => m.name);
}

export async function selectModels({ enabled = '', prefer = [], limit = 3, exclude = [] } = {}) {
  const ids = await discoverModelIds().catch(() => []);
  if (!ids.length) return prefer.slice(0, limit);

  const available = new Set(ids);
  const enabledIds = String(enabled || '').split(',').map((s) => s.trim()).filter((s) => s && available.has(s) && !NON_CHAT.test(s));
  const base = enabledIds.length ? enabledIds : ids.filter((id) => !exclude.includes(id));

  
  
  const ordered = [...prefer.filter((p) => base.includes(p)), ...base].filter((id, i, arr) => arr.indexOf(id) === i);
  return ordered.slice(0, limit);
}