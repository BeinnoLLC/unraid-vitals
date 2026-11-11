
import { getActiveSource, registerSource, registerBuiltins, getSource, listSources } from '../sources/registry.mjs';
import { createFixtureSource } from '../sources/fixture.mjs';
import { makeDisk, makeContainer, makeVm, UnsupportedError } from '../core/ports.mjs';

let active;
function src() {
  if (!active) active = getActiveSource();
  return active;
}

export function __setSource(s) { active = s; }
export function __resetSource() { active = null; }

export function activeSource() { return src(); }
export { registerSource, registerBuiltins, getSource, listSources };

export function latestSnapshot() { return src().snapshot(); }
export function history() { return src().history(); }
export function alerts() { return src().alerts(); }
export function historyWindow(hours = 6) {
  const s = src();
  return typeof s.historyWindow === 'function' ? s.historyWindow(hours)
    : s.history().filter((p) => (p.t ?? 0) >= Math.floor(Date.now() / 1000) - hours * 3600);
}
export function tail(path, lines = 200) { return src().tail(path, lines); }
export function recentSyslogWarnings(minutes = 60, maxLines = 400) { return src().syslogWarnings(minutes, maxLines); }
export function dockerLogsTail(container, minutes = 30, maxLines = 150) {
  const s = src();
  return typeof s.dockerLogs === 'function' ? s.dockerLogs(container, minutes, maxLines) : '';
}
export function vmList() {
  try { return src().vms(); } catch (e) { if (e instanceof UnsupportedError) return []; throw e; }
}
export function dockerContainerImages() { return src().containerImages(); }
export function registryDigest(ref) { return src().registryDigest(ref); }

export { createFixtureSource };
export function activeSourceId() { return src().id; }
export function activeSourceCapabilities() { return src().capabilities ? src().capabilities() : {}; }
