<?php

declare(strict_types=1);

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
  
  
  
  $legacyConfigured = isset($cfg['SCHED_AGENTS']) || isset($cfg['SCHED_AGENTS_ENABLED']);
  if ($anyPerAgent || $legacyConfigured) {
    $reg['agents']['label'] = $reg['agents']['label'] . ' [per-agent schedules active]';
  }
  return $reg;
}