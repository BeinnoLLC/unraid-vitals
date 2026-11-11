<?php

declare(strict_types=1);

require_once __DIR__ . '/agent_schedules.php';

const V_JOBCONTROL_DIR = '/var/tmp/unraid-vitals';

if (!defined('V_CROND_DIR')) define('V_CROND_DIR', '/etc/cron.d');

function v_sched_registry(): array {
  $reg = [
    'collector' => [
      'label' => 'Metrics collector', 'sched' => '* * * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-collect.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'collector.log', 'timeout' => 300,
    ],
    'checks' => [
      'label' => 'Diagnosis checks engine (every 5 min)', 'sched' => '*/5 * * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-checks.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'checks.log', 'timeout' => 600,
    ],
    'logrotate' => [
      'label' => 'Plugin log rotation (5 MiB caps, daily)', 'sched' => '25 4 * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-logrotate.php',
      'env' => [], 'needs_node' => false, 'log' => 'logrotate.log', 'timeout' => 600,
    ],
    'storage' => [
      'label' => 'Storage analyzer (nightly, low I/O priority)', 'sched' => '10 3 * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-storage-scan.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'storage-scan.log', 'timeout' => 7200,
      'heavy' => true, 'default_lock' => true,
    ],
    'dupscan' => [
      'label' => 'Duplicate file finder (weekly)', 'sched' => '0 4 * * 0',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-dup-scan.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'dup-scan.log', 'timeout' => 21600,
      'heavy' => true, 'default_lock' => true,
    ],
    'weekly' => [
      'label' => 'Weekly health report (Mon 10:40)', 'sched' => '40 6 * * 1',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-weekly-report.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'weekly-report.log', 'timeout' => 1800,
    ],
    'prune' => [
      'label' => 'Retention prune (nightly — rollups, KB, research jobs)',
      'sched' => '17 4 * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-prune.php',
      'env' => [], 'needs_node' => false, 'log' => 'prune.log', 'timeout' => 600,
      'heavy' => true, 'default_lock' => true,
    ],
    'dbbackup' => [
      'label' => 'Consistent DB backup (daily 3am, 7 daily + 4 weekly kept)',
      'sched' => '0 3 * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-dbbackup.php',
      'env' => [], 'needs_node' => false, 'log' => 'dbbackup.log', 'timeout' => 1800,
      'heavy' => true, 'default_lock' => true,
    ],
    'vmwatch' => [
      'label' => 'VM event listener (every 2 min)', 'sched' => '*/2 * * * *',
      'cmd' => '/usr/bin/flock -n /var/tmp/unraid-vitals/vmwatch.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/vmwatch.mjs',
      'env' => [], 'needs_node' => true, 'log' => 'vmwatch.log', 'timeout' => 600,
      'heavy' => false, 'default_lock' => true,
    ],
    'study' => [
      'label' => 'Study-mode ticker (every 5 min)', 'sched' => '*/5 * * * *',
      'cmd' => '/usr/bin/flock -n /var/tmp/unraid-vitals/study.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/study.mjs',
      'env' => ['LLM_STUDIO_PRIMARY' => 'LLM_PRIMARY_CFG', 'LLM_STUDIO_BACKUP' => 'LLM_BACKUP_CFG'],
      'needs_node' => true, 'log' => 'study.log', 'timeout' => 3600,
      'heavy' => true, 'default_lock' => true,
    ],
    'agents' => [
      'label' => 'Background AI agents (hourly)', 'sched' => '7 * * * *',
      'cmd' => '/usr/bin/flock -n /var/tmp/unraid-vitals/agents.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/analyze.mjs',
      'env' => ['LLM_STUDIO_PRIMARY' => 'LLM_PRIMARY_CFG', 'LLM_STUDIO_BACKUP' => 'LLM_BACKUP_CFG',
                'VITALS_DIAG_INTERVAL_MINUTES' => 'DIAG_INTERVAL_CFG', 'VITALS_DIAG_WINDOW_HOURS' => 'DIAG_WINDOW_CFG',
                'VITALS_DIAG_MODELS' => 'DIAG_MODELS_CFG', 'VITALS_UPDATE_INTERVAL_MINUTES' => 'UPDATE_INTERVAL_CFG'],
      'needs_node' => true, 'log' => 'agents.log', 'timeout' => 21600,
      'heavy' => true, 'default_lock' => true,
    ],
  ];

  
  
  
  
  
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $anyPerAgent = false;
  foreach (v_sched_agent_registry() as $id => $job) {
    $reg[$id] = $job;
    if (($cfg['SCHED_' . strtoupper($id) . '_ENABLED'] ?? '1') === '1') $anyPerAgent = true;
  }
  if ($anyPerAgent) unset($reg['agents']);
  return $reg;
}

function v_sched_build_cmd(string $id, array $envVars = []): string {
  $reg = v_sched_registry();
  if (!isset($reg[$id])) return '';
  $job = $reg[$id];
  $nodeBin = trim((string)($envVars['NODE_BIN'] ?? 'node'));
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $cmdMap = ['LLM_PRIMARY_CFG' => 'LLM_STUDIO_PRIMARY', 'LLM_BACKUP_CFG' => 'LLM_STUDIO_BACKUP',
             'DIAG_INTERVAL_CFG' => 'VITALS_DIAG_INTERVAL_MINUTES', 'DIAG_WINDOW_CFG' => 'VITALS_DIAG_WINDOW_HOURS',
             'DIAG_MODELS_CFG' => 'VITALS_DIAG_MODELS', 'UPDATE_INTERVAL_CFG' => 'VITALS_UPDATE_INTERVAL_MINUTES'];
  $cmd = str_replace('NODE_BIN_PLACEHOLDER', escapeshellarg($nodeBin ?: 'node'), $job['cmd']);
  foreach ($job['env'] as $envKey => $cfgVar) {
    $val = (string)($envVars[$cfgVar] ?? ($cfg[$cmdMap[$cfgVar] ?? $cfgVar] ?? ''));
    $cmd = $envKey . '="' . str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $val) . '" ' . $cmd;
  }
  return $cmd;
}

function v_sched_apply(string $stateDir, string $flashDir, array $envVars = []): array {
  $cfg = @parse_ini_file($flashDir . '/vitals.cfg') ?: [];
  $applied = 0; $removed = 0; $skipped = 0;
  $written = [];
  $nodeBin = trim((string)($envVars['NODE_BIN'] ?? ''));
  $nodeMissing = $nodeBin === '';

  foreach (v_sched_registry() as $id => $job) {
    $file = V_CROND_DIR . '/unraid-vitals' . (($id === 'collector') ? '' : '-' . $id);
    $key = 'SCHED_' . strtoupper($id);

    if ($job['needs_node'] && $nodeMissing) {
      
      
      if (@file_exists($file)) { @unlink($file); $removed++; }
      $skipped++;
      continue;
    }

    if (($cfg[$key . '_ENABLED'] ?? '1') === '0') {
      if (@file_exists($file)) { @unlink($file); $removed++; }
      continue;
    }

    $sched = (string)($cfg[$key] ?? $job['sched']);
    if ($id === 'collector') {
      $interval = (int)($cfg['INTERVAL'] ?? 1);
      if ($interval > 1) $sched = '*/' . $interval . ' * * * *';
    }
    
    if (!preg_match('/^[\d*,\/\-]+\s+[\d*,\/\-]+\s+[\d*,\/\-A-Za-z]+\s+[\d*,\/\-A-Za-z]+\s+[\d*,\/\-A-Za-z]+$/', $sched)) {
      $skipped++;
      continue;
    }

    $cmd = v_sched_build_cmd($id, $envVars);
    
    
    $lines = [
      '# unraid-vitals — ' . $job['label'],
      '# schedule: ' . ($cfg[$key] ?? 'default') . (($cfg[$key] ?? null) ? '' : ' (default)'),
      $sched . ' /usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-jobwrap.php ' . escapeshellarg($id) . ' >> /var/tmp/unraid-vitals/jobwrap.log 2>&1',
    ];
    @file_put_contents($file, implode("\n", $lines) . "\n");
    @chmod($file, 0644);
    
    @file_put_contents($flashDir . '/' . $id . '.cron', implode("\n", $lines) . "\n");
    $written[] = $file;
    $applied++;
  }

  
  
  foreach (glob(V_CROND_DIR . '/unraid-vitals*') ?: [] as $f) {
    if (!in_array($f, $written, true)) { @unlink($f); $removed++; }
  }
  return ['applied' => $applied, 'removed' => $removed, 'skipped' => $skipped];
}