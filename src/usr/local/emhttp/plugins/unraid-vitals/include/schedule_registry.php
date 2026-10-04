<?php
/* unraid-vitals — include/schedule_registry.php (P18-01 / #89)
 *
 * The single source of truth for every scheduled job the plugin installs.
 * Adding a job here and running `install.sh --reapply` (or
 * scripts/vitals-cron-apply.php) creates its /etc/cron.d file with no other
 * code change. remove.sh uninstalls by glob, so nothing can be left behind.
 *
 * Declaration fields: id, label, sched (default 5-field cron), cmd, env
 * (extra KEY="value" pairs — expanded from env vars ending in _CFG by
 * install.sh), needs_node (job silently skipped when node/deps missing),
 * log (basename of the log under /var/tmp/unraid-vitals).
 */

declare(strict_types=1);

/**
 * @return array<string, array{id:string,label:string,sched:string,cmd:string,env:array<string,string>,needs_node:bool,log:string}>
 */
function v_sched_registry(): array {
  return [
    'collector' => [
      'label' => 'Metrics collector', 'sched' => '* * * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-collect.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'collector.log',
    ],
    'checks' => [
      'label' => 'Diagnosis checks engine (every 5 min)', 'sched' => '*/5 * * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-checks.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'checks.log',
    ],
    'logrotate' => [
      'label' => 'Plugin log rotation (5 MiB caps, daily)', 'sched' => '25 4 * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-logrotate.php',
      'env' => [], 'needs_node' => false, 'log' => 'logrotate.log',
    ],
    'storage' => [
      'label' => 'Storage analyzer (nightly, low I/O priority)', 'sched' => '10 3 * * *',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-storage-scan.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'storage-scan.log',
    ],
    'dupscan' => [
      'label' => 'Duplicate file finder (weekly)', 'sched' => '0 4 * * 0',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-dup-scan.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'dup-scan.log',
    ],
    'weekly' => [
      'label' => 'Weekly health report (Mon 10:40)', 'sched' => '40 6 * * 1',
      'cmd' => '/usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-weekly-report.php --quiet',
      'env' => [], 'needs_node' => false, 'log' => 'weekly-report.log',
    ],
    'prune' => [
      'label' => 'Retention prune (nightly)', 'sched' => '17 4 * * *',
      'cmd' => '/usr/bin/php /boot/config/plugins/unraid-vitals/prune.php',
      'env' => [], 'needs_node' => false, 'log' => 'prune.log',
    ],
    'vmwatch' => [
      'label' => 'VM event listener (every 2 min)', 'sched' => '*/2 * * * *',
      'cmd' => '/usr/bin/flock -n /var/tmp/unraid-vitals/vmwatch.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/vmwatch.mjs',
      'env' => [], 'needs_node' => true, 'log' => 'vmwatch.log',
    ],
    'study' => [
      'label' => 'Study-mode ticker (every 5 min)', 'sched' => '*/5 * * * *',
      'cmd' => '/usr/bin/flock -n /var/tmp/unraid-vitals/study.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/study.mjs',
      'env' => ['LLM_STUDIO_PRIMARY' => 'LLM_PRIMARY_CFG', 'LLM_STUDIO_BACKUP' => 'LLM_BACKUP_CFG'],
      'needs_node' => true, 'log' => 'study.log',
    ],
    'agents' => [
      'label' => 'Background AI agents (hourly)', 'sched' => '7 * * * *',
      'cmd' => '/usr/bin/flock -n /var/tmp/unraid-vitals/agents.lock NODE_BIN_PLACEHOLDER /usr/local/emhttp/plugins/unraid-vitals/agent/analyze.mjs',
      'env' => ['LLM_STUDIO_PRIMARY' => 'LLM_PRIMARY_CFG', 'LLM_STUDIO_BACKUP' => 'LLM_BACKUP_CFG',
                'VITALS_DIAG_INTERVAL_MINUTES' => 'DIAG_INTERVAL_CFG', 'VITALS_DIAG_WINDOW_HOURS' => 'DIAG_WINDOW_CFG',
                'VITALS_DIAG_MODELS' => 'DIAG_MODELS_CFG', 'VITALS_UPDATE_INTERVAL_MINUTES' => 'UPDATE_INTERVAL_CFG'],
      'needs_node' => true, 'log' => 'agents.log',
    ],
  ];
}

/**
 * Apply the registry to /etc/cron.d: write every job's file (SCHED_<ID> from
 * vitals.cfg overrides the schedule, SCHED_<ID>_ENABLED=0 disables/removes it),
 * and delete /etc/cron.d/unraid-vitals-* files that no longer map to a job.
 * $envVars maps install.sh's exported *_CFG vars → values (agent crons embed
 * them inline). Returns [applied, removed, skipped].
 */
function v_sched_apply(string $stateDir, string $flashDir, array $envVars = []): array {
  $cfg = @parse_ini_file($flashDir . '/vitals.cfg') ?: [];
  $applied = 0; $removed = 0; $skipped = 0;
  $written = [];
  $nodeBin = trim((string)($envVars['NODE_BIN'] ?? ''));
  $nodeMissing = $nodeBin === '';

  foreach (v_sched_registry() as $id => $job) {
    $file = '/etc/cron.d/unraid-vitals' . (($id === 'collector') ? '' : '-' . $id);
    $key = 'SCHED_' . strtoupper($id);

    if ($job['needs_node'] && $nodeMissing) {
      // node/deps absent: remove the cron file if present (old behavior kept:
      // a dead job must not sit in /etc/cron.d failing every minute).
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
    // validate schedule shape (5 fields, safe charset) before trusting it
    if (!preg_match('/^[\d*,\/\-]+\s+[\d*,\/\-]+\s+[\d*,\/\-A-Za-z]+\s+[\d*,\/\-A-Za-z]+\s+[\d*,\/\-A-Za-z]+$/', $sched)) {
      $skipped++;
      continue;
    }

    $cmd = str_replace('NODE_BIN_PLACEHOLDER', escapeshellarg($nodeBin ?: 'node'), $job['cmd']);
    foreach ($job['env'] as $envKey => $cfgVar) {
      $val = (string)($envVars[$cfgVar] ?? '');
      $cmd = $envKey . '="' . str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $val) . '" ' . $cmd;
    }
    $lines = [
      '# unraid-vitals — ' . $job['label'],
      '# schedule: ' . ($cfg[$key] ?? 'default') . ($cfg[$key] ? '' : ' (default)'),
      $sched . ' ' . $cmd . ' >> /var/tmp/unraid-vitals/' . $job['log'] . ' 2>&1',
    ];
    @file_put_contents($file, implode("\n", $lines) . "\n");
    @chmod($file, 0644);
    // persist a copy on flash so a reboot reinstates it without a reinstall
    @file_put_contents($flashDir . '/' . $id . '.cron', implode("\n", $lines) . "\n");
    $written[] = $file;
    $applied++;
  }

  // remove orphans — files for jobs no longer in the registry (glob cleanup;
  // remove.sh relies on the same glob, so nothing can be left behind)
  foreach (glob('/etc/cron.d/unraid-vitals*') ?: [] as $f) {
    if (!in_array($f, $written, true)) { @unlink($f); $removed++; }
  }
  return ['applied' => $applied, 'removed' => $removed, 'skipped' => $skipped];
}