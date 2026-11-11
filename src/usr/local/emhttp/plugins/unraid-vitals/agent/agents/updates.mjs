
import { dockerContainerImages, registryDigest } from '../lib/sources.mjs';

export const AGENT_ID = 'updates';

export async function run() {
  const containers = dockerContainerImages();
  const findings = [];
  let checked = 0, upToDate = 0, unknown = 0;

  for (const c of containers) {
    if (!c.image) { unknown++; continue; }
    const remote = registryDigest(c.image);
    checked++;
    if (!remote) { unknown++; continue; }
    if (!c.localDigest) { unknown++; continue; }
    if (remote === c.localDigest) { upToDate++; continue; }
    findings.push({
      severity: 'info',
      title: `Update available: ${c.name}`,
      detail: `${c.name} is running ${c.image}, whose local image digest no longer matches the registry's current digest — a newer image has been published upstream.`,
      recommendation: `Review the container's changelog, then pull the new image and recreate ${c.name} (Docker tab → Force Update, or 'docker pull ${c.image}').`,
      subject: c.name
    });
  }

  if (!findings.length) {
    findings.push({
      severity: 'ok',
      title: containers.length
        ? `All ${containers.length} running container(s) checked — no pending image updates detected`
        : 'No running containers to check',
      detail: `Checked ${checked}/${containers.length} against their registry; ${upToDate} up to date, ${unknown} unknown (private registry, no network, or no digest recorded — not necessarily current).`,
      recommendation: null,
      subject: null
    });
  }

  return findings;
}
