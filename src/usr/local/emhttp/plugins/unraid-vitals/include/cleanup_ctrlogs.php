<?php
/* unraid-vitals — cleanup kind: container_logs (P16-04 / #79)
 *
 * Oversized container JSON logs (/var/lib/docker/containers/<id>/<id>-json.log).
 * Preview: docker ps -a + log file size per container, only those over the
 * threshold (default 100 MB) become items. Apply: truncate the chosen
 * container's log (truncate keeps the fd valid — the running daemon keeps
 * writing into the same inode). Also surfaces docker's own log-rotation
 * setting (--log-opt max-size) as the lasting fix (the UI links to Docker
 * container edit; the check docker_log_large already raises a finding).
 */

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
  // biggest first — the point of this kind is the outliers
  usort($items, function ($a, $b) { return $b['bytes'] <=> $a['bytes']; });
  return ['ok' => true, 'items' => $items];
}

/** Apply: truncate (not delete!) each chosen log. Preview items carry path+ref. */
function v_cleanup_container_logs_apply(array $items): array {
  $done = 0; $bytes = 0; $results = [];
  foreach ($items as $it) {
    $path = (string)($it['path'] ?? '');
    $ref = (string)($it['ref'] ?? '');
    // gate: path must be exactly the canonical json.log path for a validated container
    if (!preg_match('/^\/var\/lib\/docker\/containers\/[a-f0-9]{64}\/[a-f0-9]{64}-json\.log$/', $path)) {
      return ['ok' => false, 'error' => 'path failed validation', 'detail' => $path];
    }
    // the container ref must currently resolve to the same id the path embeds
    $id = substr(basename($path), 0, 64);
    $realId = trim((string)@shell_exec('timeout 10 docker inspect --format \'{{.Id}}\' ' . escapeshellarg($ref) . ' 2>/dev/null'));
    if ($realId !== $id) {
      return ['ok' => false, 'error' => 'container ref no longer matches the previewed log', 'detail' => $ref];
    }
    $size = @filesize($path) ?: 0;
    // 'c' mode: open for write WITHOUT truncating at open, then ftruncate(0) —
    // atomicity note: docker daemon holds an append fd; truncating keeps the
    // inode valid so the daemon's next write lands at its offset (sparse file),
    // and docker handles this fine (this is exactly what `truncate -s 0` does).
    $fp = @fopen($path, 'c');
    if ($fp) { ftruncate($fp, 0); fclose($fp); $done++; $bytes += $size; $results[] = ['ref' => $ref, 'truncated' => true, 'bytes' => $size]; }
    else { $results[] = ['ref' => $ref, 'truncated' => false, 'error' => 'open failed']; }
  }
  return ['ok' => true, 'removed' => $done, 'of' => count($items), 'bytes_reclaimed' => $bytes, 'results' => $results];
}