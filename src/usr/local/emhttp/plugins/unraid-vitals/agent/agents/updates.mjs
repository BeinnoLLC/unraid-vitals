/**
 * Update-notifier specialist — container image updates.
 *
 * Deliberately self-contained: rather than reverse-engineer Unraid's own
 * (undocumented, UI-only) update-check state files, this diffs each running
 * container's actual local image digest against the registry's current
 * digest via `docker manifest inspect` — works for any registry that serves
 * public manifests without extra tooling, degrades to "unknown" (not a
 * false "up to date") for anything it can't reach: private registries
 * needing auth, no network egress, rate-limited pulls, locally-built images
 * with no upstream at all.
 *
 * This agent does not call the LLM — a digest either matches or it doesn't,
 * there's nothing to "reason" about. It still writes through the same
 * findings/KB path as the LLM specialists so the UI renders it identically.
 */
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
