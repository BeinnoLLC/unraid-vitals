<?php

declare(strict_types=1);

require_once __DIR__ . '/store.php';

function v_cleanup_container_logs_preview(): array {
  $names = @shell_exec('timeout 15 docker ps -a --format \'{{.Names}}\' 2>/dev/null') ?: '';
  $items = [];
  foreach (explode("\n", trim($names)) as $name) {
    if ($name === '') continue;
    $id = trim((string)@shell_exec('timeout 10 docker inspect --format \'{{.Id}}\' ' . escapeshellarg($name) . ' 2>/dev/null'));
    if (!preg_match('/^[a-f0-9]{64}$/', $id)) continue;
    $log = '/var/lib/docker/containers/' . $id . '/' . $id . '-json.log';
    $size = @filesize($log);
    if ($size === false) continue;
    $items[] = ['ref' => $name, 'bytes' => $size, 'path' => $log];
  }
  
  usort($items, function ($a, $b) { return $b['bytes'] <=> $a['bytes']; });
  return ['ok' => true, 'items' => $items];
}

function v_cleanup_container_logs_apply(array $items): array {
  $done = 0; $bytes = 0; $results = [];
  foreach ($items as $it) {
    $path = (string)($it['path'] ?? '');
    $ref = (string)($it['ref'] ?? '');
    
    if (!preg_match('/^\/var\/lib\/docker\/containers\/[a-f0-9]{64}\/[a-f0-9]{64}-json\.log$/', $path)) {
      return ['ok' => false, 'error' => 'path failed validation', 'detail' => $path];
    }
    
    $id = substr(basename($path), 0, 64);
    $realId = trim((string)@shell_exec('timeout 10 docker inspect --format \'{{.Id}}\' ' . escapeshellarg($ref) . ' 2>/dev/null'));
    if ($realId !== $id) {
      return ['ok' => false, 'error' => 'container ref no longer matches the previewed log', 'detail' => $ref];
    }
    $size = @filesize($path) ?: 0;
    
    
    
    
    $fp = @fopen($path, 'c');
    if ($fp) { ftruncate($fp, 0); fclose($fp); $done++; $bytes += $size; $results[] = ['ref' => $ref, 'truncated' => true, 'bytes' => $size]; }
    else { $results[] = ['ref' => $ref, 'truncated' => false, 'error' => 'open failed']; }
  }
  return ['ok' => true, 'removed' => $done, 'of' => count($items), 'bytes_reclaimed' => $bytes, 'results' => $results];
}