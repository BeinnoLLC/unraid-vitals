<?php

declare(strict_types=1);

require_once __DIR__ . '/store.php';

function v_mover_pidfile(): string { return '/var/run/mover.pid'; }

function v_mover_running(): bool {
  $pid = (int)trim((string)@file_get_contents(v_mover_pidfile()) ?: '0');
  if ($pid <= 1) return false;
  
  return @file_exists('/proc/' . $pid) && trim((string)@file_get_contents('/proc/' . $pid . '/comm')) === 'bash';
}

function v_mover_parity_busy(array $snap = []): bool {
  $state = (string)($snap['array']['state'] ?? $snap['system']['mdState'] ?? '');
  
  $busyStates = ['resync', 'rebuild', 'check', 'clear', 'mdresync'];
  foreach ($busyStates as $b) if (stripos($state, $b) !== false) return true;
  
  
  
  $md = @file_get_contents('/proc/mdstat') ?: '';
  return (bool)preg_match('/\b(resync|recovery|check)\s*=\s*[0-9.]+%/', $md);
}

function v_mover_start(array $snap = [], string $confirmParity = ''): array {
  if (v_mover_running()) return ['ok' => false, 'error' => 'mover is already running'];
  if (v_mover_parity_busy($snap) && $confirmParity !== 'yes') {
    return ['ok' => false, 'error' => 'parity check/sync is running — confirm to start the mover anyway',
            'confirm_required' => true];
  }
  $out = (string)@shell_exec('nohup /usr/local/sbin/mover start > /var/tmp/unraid-vitals/mover-run.log 2>&1 & echo $!');
  return ['ok' => true, 'started' => true, 'spawn_pid' => (int)trim($out)];
}

function v_mover_stop(): array {
  $pid = (int)trim((string)@file_get_contents(v_mover_pidfile()) ?: '0');
  if ($pid <= 1 || !@file_exists('/proc/' . $pid)) return ['ok' => false, 'error' => 'mover not running'];
  @shell_exec('timeout 5 kill -TERM ' . (int)$pid . ' 2>&1');
  return ['ok' => true, 'stopped' => $pid];
}

function v_mover_status(): array {
  $running = v_mover_running();
  $pid = (int)trim((string)@file_get_contents(v_mover_pidfile()) ?: '0');
  $lines = [];
  if (is_file('/var/tmp/unraid-vitals/mover-run.log')) {
    $lines = array_slice(@file('/var/tmp/unraid-vitals/mover-run.log', FILE_IGNORE_NEW_LINES) ?: [], -30);
  } else {
    
    $out = @shell_exec('grep -h "mover" /var/log/syslog 2>/dev/null | tail -n 12') ?: '';
    $lines = explode("\n", trim($out));
  }
  return ['ok' => true, 'running' => $running, 'pid' => $running ? $pid : 0,
          'parity_busy' => v_mover_parity_busy(), 'log' => array_filter($lines)];
}