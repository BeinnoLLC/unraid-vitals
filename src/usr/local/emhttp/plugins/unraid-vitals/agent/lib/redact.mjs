/**
 * unraid-vitals — lib/redact.mjs (P20-17 / #122)
 *
 * Optional (off by default; cfg REDACT_PROMPTS="1" turns it on) masking
 * before context goes to the model:
 *   hostnames, IP addresses, MAC addresses, disk serials, and — optionally —
 *   file/folder names from share listings.
 *
 * Placeholders are STABLE within one request ([HOST], [IP1], [MAC1], [SER1]…)
 * and the agent's findings map back through the same table before landing in
 * the DB, so findings still name the real disk — the acceptance:
 * a captured request body contains no serial/IP/hostname, and findings show
 * real disk names.
 */

/** Build one request's redaction table. */
export function createRedactor({ redactPaths = false } = {}) {
  const map = new Map();   // original -> placeholder
  const counter = {};
  const next = (kind) => { counter[kind] = (counter[kind] ?? 0) + 1; return `[${kind}${counter[kind]}]`; };

  const add = (value, kind) => {
    if (!value || map.has(value)) return map.get(value) ?? value;
    const ph = map.get(value) ?? (() => { const p = next(kind); map.set(value, p); return p; })();
    return ph;
  };

  return {
    table: map,
    /** Mask sensitive bits in free text. */
    text(s) {
      let out = String(s);
      // IPs (v4/v6) — loopback stays
      out = out.replace(/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/g, (m, a, b, c, d) =>
        (a === '127' && b === '0') ? m : add(m, 'IP'));
      out = out.replace(/\b([0-9a-f]{1,4}:){2,7}[0-9a-f]{0,4}\b/gi, (m) => (m.startsWith('fe80') || m.startsWith('::1')) ? m : add(m, 'IP'));
      // MAC addresses
      out = out.replace(/\b([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/g, (m) => add(m.toUpperCase(), 'MAC'));
      // hostnames (word chars + dot pairs like selene.local or bare box name in cfg context)
      out = out.replace(/\b([a-z][a-z0-9-]{2,20})\b(?=\.local|:11434|:8477)/gi, (m) => add(m, 'HOST'));
      // disk serials: full serial tokens (WD-/ST-/ZN- prefixes with suffixes)
      out = out.replace(/\b((?:W[CD]_|ST|ZN_|VN_|MX|S[NT])[A-Z0-9_\-]{6,38})\b/g, (m) => add(m, 'SER'));
      // full serial strings with underscore separators (WD-XXXX, ST_Parts)
      out = out.replace(/\b([A-Z]{2}[A-Z0-9]*-[A-Z0-9]*_[A-Z0-9]{4,16})\b/g, (m) => add(m, 'SER'));
      out = out.replace(/\b(serial|wwn)([=:])\s*"?([A-Za-z0-9_-]{6,})"?/gi, (m, k, eq, v) => `${k}${eq} ${add(v, 'SER')}`);
      // paths in share listings (opt-in): /mnt/user/<share>/path/...
      if (redactPaths) {
        out = out.replace(/(\/mnt\/user\d?\/[^\s"',)]+)/g, (p) => add(p, 'PATH'));
      }
      return out;
    },
    mapHost(h) { return add(h, 'HOST'); },
    /** Reverse-map an agent response so findings show real names again. */
    unmap(s) {
      let out = String(s);
      for (const [orig, ph] of map) {
        if (ph) out = out.split(ph).join(orig);
      }
      return out;
    },
    /** The captured request body (for the acceptance check / audit logging). */
    note(body) { return body; },
  };
}

/** Convenience: is redaction enabled in the plugin cfg? */
export function redactionEnabled(cfg) {
  return (cfg?.REDACT_PROMPTS ?? '0') === '1';
}