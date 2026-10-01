// Offline test of model discovery: no network, no DB, no LLM. Both studios
// are stubbed via setStudioEndpoints + a fake fetch.
import test from 'node:test';
import assert from 'node:assert/strict';

const MOD = '../src/usr/local/emhttp/plugins/unraid-vitals/agent/lib/models.mjs';

function stubFetch(map) {
  return async (url) => {
    const key = Object.keys(map).find((k) => url.includes(k));
    if (key === undefined) return { ok: false, status: 404, json: async () => ({}) };
    const entry = map[key];
    if (entry.error) return { ok: false, status: entry.error };
    return { ok: true, status: 200, json: async () => ({ models: entry.map((name) => ({ name })) }) };
  };
}

async function fresh(map) {
  globalThis.fetch = stubFetch(map);
  const mod = await import(`${MOD}?t=${Math.random()}`);
  mod.setStudioEndpoints('https://primary.test', 'https://fallback.test');
  return mod;
}

test('merges both studios and tags where each model lives', async () => {
  const { discoverModels } = await fresh({
    'primary.test': ['gemma3:12b', 'qwen3:14b'],
    'fallback.test': ['gemma3:12b', 'qwen2.5:7b']
  });
  const all = await discoverModels({ force: true });
  const byId = Object.fromEntries(all.map((m) => [m.name, m]));
  assert.deepEqual(byId['gemma3:12b'].on, ['https://primary.test', 'https://fallback.test']);
  assert.equal(byId['gemma3:12b'].both, true);
  assert.equal(byId['qwen2.5:7b'].both, false);
});

test('embedding models are never offered as chat models', async () => {
  const { discoverModels } = await fresh({
    'primary.test': ['qwen3:14b', 'nomic-embed-text:latest', 'bge-m3', 'mxbai-embed-large'],
    'fallback.test': ['all-minilm']
  });
  const names = (await discoverModels({ force: true })).map((m) => m.name);
  assert.deepEqual(names, ['qwen3:14b']);
});

test('enabled list drops models that no longer exist', async () => {
  const { selectModels } = await fresh({
    'primary.test': ['qwen3:14b', 'gemma3:12b'],
    'fallback.test': ['qwen3:14b', 'gemma3:12b']
  });
  const sel = await selectModels({ enabled: 'qwen3:14b,ghost:99b,gemma3:12b', limit: 3 });
  assert.deepEqual(sel, ['qwen3:14b', 'gemma3:12b']);
  assert.ok(!sel.includes('ghost:99b'), 'a model that is not installed must not be requested');
});

test('prefer list leads the selection when it is available', async () => {
  const { selectModels } = await fresh({
    'primary.test': ['qwen3:14b', 'gemma3:12b', 'qwen2.5:7b'],
    'fallback.test': ['qwen3:14b', 'gemma3:12b', 'qwen2.5:7b']
  });
  const sel = await selectModels({ enabled: '', prefer: ['qwen2.5:7b', 'gemma3:12b'], limit: 2 });
  assert.equal(sel[0], 'qwen2.5:7b');
  assert.equal(sel.length, 2);
});

test('one studio being down still yields the other studio models', async () => {
  const { discoverModels } = await fresh({
    'primary.test': { error: 503 },
    'fallback.test': ['qwen3:14b']
  });
  const names = (await discoverModels({ force: true })).map((m) => m.name);
  assert.deepEqual(names, ['qwen3:14b']);
});

test('both studios down falls back to the caller prefer list', async () => {
  const { selectModels } = await fresh({
    'primary.test': { error: 503 },
    'fallback.test': { error: 503 }
  });
  const sel = await selectModels({ enabled: '', prefer: ['gemma3:12b', 'qwen2.5:7b'], limit: 3 });
  assert.deepEqual(sel, ['gemma3:12b', 'qwen2.5:7b']);
});