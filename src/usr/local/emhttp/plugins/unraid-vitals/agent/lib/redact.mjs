

export function createRedactor({ redactPaths = false } = {}) {
  const map = new Map();   
  const counter = {};
  const next = (kind) => { counter[kind] = (counter[kind] ?? 0) + 1; return `[${kind}${counter[kind]}]`; };

  const add = (value, kind) => {
    if (!value || map.has(value)) return map.get(value) ?? value;
    const ph = map.get(value) ?? (() => { const p = next(kind); map.set(value, p); return p; })();
    return ph;
  };

  return {
    table: map,
    
    text(s) {
      let out = String(s);
      
      out = out.replace(/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/g, (m, a, b, c, d) =>
        (a === '127' && b === '0') ? m : add(m, 'IP'));
      out = out.replace(/\b([0-9a-f]{1,4}:){2,7}[0-9a-f]{0,4}\b/gi, (m) => (m.startsWith('fe80') || m.startsWith('::1')) ? m : add(m, 'IP'));
      
      out = out.replace(/\b([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/g, (m) => add(m.toUpperCase(), 'MAC'));
      
      out = out.replace(/\b([a-z][a-z0-9-]{2,20})\b(?=\.local|:11434|:8477)/gi, (m) => add(m, 'HOST'));
      
      out = out.replace(/\b((?:W[CD]_|ST|ZN_|VN_|MX|S[NT])[A-Z0-9_\-]{6,38})\b/g, (m) => add(m, 'SER'));
      
      out = out.replace(/\b([A-Z]{2}[A-Z0-9]*-[A-Z0-9]*_[A-Z0-9]{4,16})\b/g, (m) => add(m, 'SER'));
      out = out.replace(/\b(serial|wwn)([=:])\s*"?([A-Za-z0-9_-]{6,})"?/gi, (m, k, eq, v) => `${k}${eq} ${add(v, 'SER')}`);
      
      if (redactPaths) {
        out = out.replace(/(\/mnt\/user\d?\/[^\s"',)]+)/g, (p) => add(p, 'PATH'));
      }
      return out;
    },
    mapHost(h) { return add(h, 'HOST'); },
    
    unmap(s) {
      let out = String(s);
      for (const [orig, ph] of map) {
        if (ph) out = out.split(ph).join(orig);
      }
      return out;
    },
    
    note(body) { return body; },
  };
}

export function redactionEnabled(cfg) {
  return (cfg?.REDACT_PROMPTS ?? '0') === '1';
}