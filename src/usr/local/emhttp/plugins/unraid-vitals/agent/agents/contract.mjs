/**
 * Shared JSON-response contract every specialist agent uses.
 * The model is asked to return: { findings: [ {severity,title,detail,recommendation,subject} ] }
 */
export const RESPONSE_CONTRACT = `Respond with ONLY a JSON object, no prose outside it:
{"findings":[{"severity":"ok|info|warning|error|critical","title":"short headline (<=80 chars)","detail":"1-3 sentences, specific, reference real numbers from the data","recommendation":"a concrete next action, or null if severity is ok","subject":"the specific disk/container/VM/interface name this is about, or null for whole-system"}]}
Rules:
- Always include exactly ONE "ok" finding summarising the healthy baseline if nothing is wrong in that area.
- Never invent numbers that are not in the data you were given.
- Keep the array to at most 8 findings — pick the most important.
- "critical" = data loss / imminent failure risk. "error" = broken now. "warning" = trending toward a problem. "info" = worth knowing, not urgent.`;

export function safeParseFindings(json) {
  const arr = Array.isArray(json?.findings) ? json.findings : [];
  return arr
    .filter(f => f && typeof f.title === 'string' && typeof f.severity === 'string')
    .map(f => ({
      severity: ['ok', 'info', 'warning', 'error', 'critical'].includes(f.severity) ? f.severity : 'info',
      title: String(f.title).slice(0, 200),
      detail: f.detail ? String(f.detail).slice(0, 1000) : null,
      recommendation: f.recommendation ? String(f.recommendation).slice(0, 500) : null,
      subject: f.subject ? String(f.subject).slice(0, 100) : null
    }));
}
