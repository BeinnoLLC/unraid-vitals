<?php
/* unraid-vitals — cleanup kind: orphan_appdata (P16-03 / #78)
 *
 * Appdata folders that no container maps (running or stopped). Detection:
 * docker ps -a --format gives per-container Binds (/host:/container paths) —
 * any top-level folder under the appdata root that appears in NO container's
 * binds is an orphan candidate. Shows size + last-modified. Apply moves it to
 * a dated holding folder (<appdata>/unraid-vitals-held/<date>/) instead of
 * deleting; permanent delete is a separate, deliberate step (rm in a file
 * manager, or a later dedicated kind).
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

/** Appdata root (same resolution the storage analyzer uses). */
function v_cleanup_appdata_root(): string {
  $docker = @parse_ini_file(VITALS_DOCKER_CFG) ?: [];
  $root = trim((string)($docker['DOCKER_APP_CONFIG_PATH'] ?? ''));
  if ($root === '') $root = '/mnt/user/appdata';
  return rtrim($root, '/');
}

/**
 * Map of container -> list of host paths it binds (running or stopped).
 */
function v_cleanup_container_binds(): array {
  $names = @shell_exec('timeout 15 docker ps -a --format \'{{.Names}}\' 2>/dev/null') ?: '';
  $binds = [];
  foreach (explode("\n", trim($names)) as $name) {
    if ($name === '') continue;
    // inspect gives full paths (ps --format truncates them); join Mounts with ' | '
    $out = @shell_exec('timeout 20 docker inspect --format \'{{range .Mounts}}{{.Source}} | {{end}}\' ' . escapeshellarg($name) . ' 2>/dev/null') ?: '';
    $binds[$name] = $out;
  }
  return $binds;
}

/**
 * PREVIEW: orphaned top-level folders under the appdata root.
 * Items: ['path' => folder, 'bytes' => du size, 'mtime' => last modified ts].
 */
function v_cleanup_orphan_appdata_preview(): array {
  $root = v_cleanup_appdata_root();
  if (!is_dir($root)) return ['ok' => false, 'error' => 'appdata root missing'];
  $binds = v_cleanup_container_binds();
  $referenced = [];
  foreach ($binds as $specs) {
    foreach (explode(' | ', $specs) as $spec) {
      $host = trim(explode(':', $spec)[0] ?? '');
      if ($host === '') continue;
      // mark the top-level folder under root that this bind falls inside
      if (strpos($host, $root . '/') === 0) {
        $rel = substr($host, strlen($root) + 1);
        $top = strtok($rel, '/');
        if ($top !== false) $referenced[$top] = true;
      } elseif ($host === $root) {
        $referenced['__ROOT__'] = true;
      }
    }
  }

  $items = [];
  foreach (scandir($root) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if ($entry === 'unraid-vitals' || $entry === 'unraid-vitals-held') continue; // our own data
    if (isset($referenced['__ROOT__']) || isset($referenced[$entry])) continue;
    $path = $root . '/' . $entry;
    if (!is_dir($path)) continue;
    $size = (int)trim((string)(@shell_exec('timeout 20 ionice -c3 du -sb ' . escapeshellarg($path) . ' 2>/dev/null') ?: '0'));
    $mtime = @filemtime($path) ?: 0;
    $items[] = ['path' => $path, 'bytes' => $size, 'mtime' => $mtime];
  }
  return ['ok' => true, 'items' => $items];
}

/**
 * APPLY: move each orphan to <appdata>/unraid-vitals-held/<UTC-date>/<name>.
 * Non-destructive by design; identical names get a numeric suffix.
 */
function v_cleanup_orphan_appdata_apply(array $items): array {
  $root = v_cleanup_appdata_root();
  $held = $root . '/unraid-vitals-held/' . gmdate('Ymd-His');
  $done = 0; $bytes = 0; $results = [];
  foreach ($items as $it) {
    $path = (string)($it['path'] ?? '');
    $real = realpath($path);
    // gate: must be a direct child of the appdata root, nothing else
    if (!$real || $real !== $root . '/' . basename($path) || basename($path) === '') {
      return ['ok' => false, 'error' => 'item failed re-validation', 'detail' => $path, 'partial_done' => $done];
    }
    if (!is_dir($real)) { $results[] = ['path' => $real, 'error' => 'not a directory (moved on?)']; continue; }
    if (!@mkdir($held, 0755, true) && !is_dir($held)) { return ['ok' => false, 'error' => 'cannot create holding folder']; }
    $target = $held . '/' . basename($real);
    $n = 1;
    while (file_exists($target)) { $target = $held . '/' . basename($real) . '-' . $n++; }
    if (@rename($real, $target)) {
      $done++; $bytes += (int)($it['bytes'] ?? 0);
      $results[] = ['path' => $real, 'moved_to' => $target];
    } else {
      $results[] = ['path' => $real, 'error' => 'rename failed'];
    }
  }
  return ['ok' => true, 'removed' => $done, 'of' => count($items), 'bytes_reclaimed' => $bytes, 'results' => $results];
}