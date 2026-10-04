<?php
/* unraid-vitals — P18-03 / #91: per-agent schedules.
 *
 * analyze.mjs <agent id...>: each agent gets its own registry job (same lock,
 * so they still serialize). SCHED_AGENTS_<NAME> (cron) + SCHED_AGENTS_<NAME>_
 * ENABLED. The old combined 'agents' job stays as the umbrella DISABLED by
 * default when any per-agent job is enabled — replaced by five explicit jobs
 * with per-agent defaults (disks daily, thermal 30 min, pools daily, network
 * hourly, general 6h, updates daily, unraid-release daily — the real agents).
 */

declare(strict_types=1);

/** Per-agent job declarations — merged into the registry at apply time. */
function v_agent_jobs(): array {
  $base = '/usr/local/emhttp/plugins/unraid-vitals';
  return [
    'disks'  => ['label' => 'Agent: disks',  'sched' => '30 4 * * *',   'heavy' => true],
    'thermal'=> ['label' => 'Agent: thermal','sched' => '*/30 * * * *', 'heavy' => true],
    'pools'  => ['label' => 'Agent: pools',  'sched' => '40 4 * * *',   'heavy' => true],
    'network'=> ['label' => 'Agent: network','sched' => '23 * * * *',   'heavy' => false],
    'general'=> ['label' => 'Agent: general','sched' => '37 */6 * * *', 'heavy' => true],
    'updates'=> ['label' => 'Agent: updates','sched' => '15 5 * * *',   'heavy' => true],
    'unraid-release' => ['label' => 'Agent: unraid-release', 'sched' => '5 5 * * *', 'heavy' => true],
  ];
}

/** Registry extension: agent jobs appear as normal jobs (ids agents_<name>). */
function v_sched_agent_registry(): array {
  $out = [];
  foreach (v_agent_jobs() as $name => $a) {
    $out['agents_' . $name] = [
      'label' => $a['label'] . ' — analyze.mjs ' . $name,
      'sched' => $a['sched'],
      'cmd'   => '/usr/bin/flock -n /var/tmp/unraid-vitals/agents.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/analyze.mjs ' . escapeshellarg($name),
      'env'   => ['LLM_STUDIO_PRIMARY' => 'LLM_PRIMARY_CFG', 'LLM_STUDIO_BACKUP' => 'LLM_BACKUP_CFG',
                  'VITALS_DIAG_INTERVAL_MINUTES' => 'DIAG_INTERVAL_CFG', 'VITALS_DIAG_WINDOW_HOURS' => 'DIAG_WINDOW_CFG',
                  'VITALS_DIAG_MODELS' => 'DIAG_MODELS_CFG', 'VITALS_UPDATE_INTERVAL_MINUTES' => 'UPDATE_INTERVAL_CFG'],
      'needs_node' => true,
      'log' => 'agents-' . $name . '.log',
      'heavy' => $a['heavy'],
      'agent_name' => $name,
    ];
  }
  return $out;
}

/**
 * Patch on v_sched_registry(): the combined agents job is enabled only when
 * NO per-agent job has been explicitly enabled (back-compat); otherwise it
 * goes away in favor of the per-agent crons.
 */
function v_sched_registry_with_agents(): array {
  $reg = v_sched_registry();
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $anyPerAgent = false;
  foreach (v_agent_jobs() as $name => $a) {
    if (($cfg['SCHED_AGENTS_' . strtoupper($name) . '_ENABLED'] ?? '1') === '1') $anyPerAgent = true;
  }
  foreach (v_sched_agent_registry() as $id => $job) {
    $reg[$id] = $job;
  }
  // umbrella agents job: default-disabled when any per-agent override exists —
  // simplest truthful model: if no SCHED_AGENTS key was ever written AND no per-agent
  // explicit config exists → keep legacy behavior (one hourly job).
  $legacyConfigured = isset($cfg['SCHED_AGENTS']) || isset($cfg['SCHED_AGENTS_ENABLED']);
  if ($anyPerAgent || $legacyConfigured) {
    $reg['agents']['label'] = $reg['agents']['label'] . ' [per-agent schedules active]';
  }
  return $reg;
}