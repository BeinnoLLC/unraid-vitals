

const RULES = [
  [/\b(sha?security|cve|vulnerab|exploit|breach|auth|permission|root exploit|hardening)\b|\bcve-/, 'security'],
  [/\b(smart|reallocated|pending sector|uncorrectable|crc|disk|drive|nvme|ssd|hd[dt]?)\b/, 'storage'],
  [/\b(parity|array|unraid array|xfs|btrfs|zfs|raid|rebuild|shrink)\b/, 'array'],
  [/\b(cache pool|cache|zfs pool|pool full|pool usage)\b/, 'pool'],
  [/\b(docker|container|image registry|compose)\b/, 'docker'],
  [/\b(vm|kvm|qemu|virtio|passthrough|vcpu)\b/, 'vm'],
  [/\b(network|eth0|bond|vlan|dns|dhcp|mtu|interface|wireguard|tailscale)\b/, 'network'],
  [/\b(temperature|thermal|overheat|fan|rpm|cooling|throttl)\b/, 'thermal'],
  [/\b(memory|ram|oom|out of memory|swap|mem leak)\b/, 'memory'],
  [/\b(cpu|load average|core|frequency|thermal throttle)\b/, 'cpu'],
  [/\b(upgrade|update|release|version|patch os|unraid os \d)\b/, 'updates'],
  [/\b(ups|nis|nut|battery|power)\b/, 'power'],
  [/\b(share|smb|nfs|user share)\b/, 'shares'],
  [/\b(backup|restore|snapshot|appdata)\b/, 'backup'],
  [/\b(gpu|nvidia|vulkan|transcode)\b/, 'gpu'],
  [/\b(license|trial|key|eula)\b/, 'licensing'],
];

export function suggestTags(text, existingTags = {}, llmSuggested = []) {
  const t = String(text || '').toLowerCase();
  const scored = [];
  for (const [re, tag] of RULES) {
    if (re.test(t)) {
      
      
      const prior = (existingTags[tag] || 0) * 0.3;
      scored.push([tag, 1 + prior]);
    }
  }
  for (const raw of (llmSuggested || [])) {
    const tag = String(raw).toLowerCase().replace(/[^a-z0-9- ]+/g, '').replace(/\s+/g, '-').slice(0, 24);
    if (!tag || scored.some(([x]) => x === tag)) continue;
    scored.push([tag, 0.8 + (existingTags[tag] || 0) * 0.3]);
  }
  scored.sort((a, b) => b[1] - a[1]);
  return scored.slice(0, 6).map(([x]) => x);
}
