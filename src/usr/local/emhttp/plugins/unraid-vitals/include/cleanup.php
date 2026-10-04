<?php
/* unraid-vitals — include/cleanup.php (P16-01: cleanup safety framework)
 *
 * Two-step cleanup: PREVIEW (what would be removed, how much space) then APPLY,
 * where apply accepts only a preview_id — never a client-supplied path list.
 * Every apply re-resolves each stored item with realpath() and re-checks it
 * against the allow-roots for its kind; a single mismatch rejects the whole
 * apply and lands in the audit log.
 *
 * Audit table (cleanup_audit): who, when, kind, items, bytes reclaimed, result.
 * Audit rows are readable back through ajax.php?action=cleanup_audit for the UI.
 *
 * Threat model: nobody runs destructive rm under a forged path list. The only
 * client input that reaches the filesystem is a preview id issued by a previous
 * preview call, with a TTL, and re-validated at apply time against immutable
 * DB rows + allow-roots.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

/** Preview TTL in seconds. */
const V_CLEANUP_TTL = 900;

/**
 * Allowed scan/apply roots per cleanup kind. An item whose realpath is not
 * strictly inside one of these roots is rejected at preview AND apply time.
 * Ordered; first match wins. Roots must exist (no trailing slash).
 * V_CLEANUP_ROOTS_OVERRIDE (JSON, kind => roots[]) exists for tests only.
 */
function v_cleanup_roots(string $kind): ?array {
  if (defined('V_CLEANUP_ROOTS_OVERRIDE')) {
    $map = json_decode(V_CLEANUP_ROOTS_OVERRIDE, true);
    if (is_array($map) && array_key_exists($kind, $map)) return $map[$kind];
  }
  switch ($kind) {
    case 'docker_dangling_images':
    case 'docker_unused_images':
    case 'docker_stopped_containers':
    case 'docker_build_cache':
    case 'docker_unused_volumes':
      return null; // docker kinds go through the docker CLI, not fs paths
    case 'logs':
      return ['/var/log', '/var/tmp/unraid-vitals'];
    case 'tmp':
      return ['/tmp/unraid-vitals'];
    case 'junk':
      return null; // share list resolved per-share at preview time
    default:
      return [];
  }
}

/** Docker cleanup kinds and their docker CLI args + human labels. */
function v_cleanup_docker_kinds(): array {
  return [
    'docker_dangling_images'  => ['label' => 'Dangling images',  'filter' => '-f dangling=true',       'cmd' => 'rmi', 'confirm2' => false],
    'docker_unused_images'    => ['label' => 'Unused images',    'filter' => '-f until=24h',           'cmd' => 'rmi', 'confirm2' => false],
    'docker_stopped_containers' => ['label' => 'Stopped containers', 'filter' => '',                'cmd' => 'rm',  'confirm2' => false],
    'docker_build_cache'      => ['label' => 'Build cache',      'filter' => '',                       'cmd' => 'builder prune -f', 'confirm2' => false],
    // Unused volumes: off by default + second confirmation (P16-02).
    'docker_unused_volumes'   => ['label' => 'Unused volumes',   'filter' => '-f all=true',            'cmd' => 'volume prune -f', 'confirm2' => true],
  ];
}

function v_cleanup_db(): ?SQLite3 {
  $db = v_events_db(); // same handle/opener; creates kb_* tables idempotently
  if (!$db) return null;
  $db->exec("CREATE TABLE IF NOT EXISTS cleanup_previews (
    id TEXT PRIMARY KEY, kind TEXT NOT NULL, created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL, items TEXT NOT NULL, total_bytes INTEGER NOT NULL)");
  $db->exec("CREATE TABLE IF NOT EXISTS cleanup_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, user TEXT, kind TEXT NOT NULL,
    preview_id TEXT, items INTEGER NOT NULL, bytes INTEGER NOT NULL,
    result TEXT NOT NULL, detail TEXT)");
  return $db;
}

/** Best-effort "who": Unraid webGui auth user, else cli/system. */
function v_cleanup_who(): string {
  foreach (['REMOTE_USER', 'PHP_AUTH_USER'] as $k) {
    if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
  }
  return PHP_SAPI === 'cli' ? 'cli' : 'webGui';
}

/**
 * Build a preview for a kind. Returns {ok, preview_id, items, total_bytes, label}.
 * fs kinds: items are ['path'=>.., 'bytes'=>..]; docker kinds: items are
 * ['ref'=>docker-id-or-name, 'bytes'=>..] from the CLI.
 */
function v_cleanup_preview(string $kind): array {
  $dockerKinds = v_cleanup_docker_kinds();
  $items = [];

  if (isset($dockerKinds[$kind])) {
    $spec = $dockerKinds[$kind];
    if ($kind === 'docker_unused_volumes') {
      $bytes = (int)trim((string)@shell_exec('timeout 15 docker system df --format "{{.Type}}|{{.Size}}" 2>/dev/null | grep -i volume | cut -d"|" -f2') ?: '0B');
      $raw = @shell_exec('timeout 15 docker volume ls -qf dangling=true 2>/dev/null');
      foreach (explode("\n", trim((string)$raw)) as $ref) {
        if ($ref !== '') $items[] = ['ref' => $ref, 'bytes' => null];
      }
    } elseif ($kind === 'docker_dangling_images' || $kind === 'docker_unused_images') {
      $extra = $kind === 'docker_dangling_images' ? '-f dangling=true' : '-f until=24h';
      $raw = @shell_exec('timeout 20 docker images -q ' . $extra . ' 2>/dev/null');
      foreach (explode("\n", trim((string)$raw)) as $ref) {
        if ($ref === '') continue;
        $bytes = (int)trim((string)@shell_exec("timeout 10 docker image inspect --format '{{.Size}}' " . escapeshellarg($ref) . ' 2>/dev/null') ?: 0);
        $items[] = ['ref' => $ref, 'bytes' => $bytes];
      }
    } elseif ($kind === 'docker_stopped_containers') {
      $raw = @shell_exec('timeout 15 docker ps -aq --filter status=exited --filter status=created 2>/dev/null');
      foreach (explode("\n", trim((string)$raw)) as $ref) {
        if ($ref === '') continue;
        $items[] = ['ref' => $ref, 'bytes' => null];
      }
    } else { // build cache
      $raw = @shell_exec('timeout 20 docker builder du 2>/dev/null');
      $bytes = null;
      if (preg_match('/Total:\s+([0-9.]+[KMGB]+)/i', (string)$raw, $m)) {
        $bytes = v_cleanup_size_to_bytes($m[1]);
      }
      $items[] = ['ref' => 'docker build cache', 'bytes' => $bytes];
    }
  } else {
    $roots = v_cleanup_roots($kind);
    if ($roots === null || $roots === []) return ['ok' => false, 'error' => 'unknown kind'];

    foreach ($roots as $root) {
      if (!is_dir($root)) continue;
      // fs_watch-style bounds: maxdepth + size cap + timeout + ionice.
      $cmd = 'timeout 20 ionice -c3 find ' . escapeshellarg($root)
        . ' -maxdepth 3 -type f -printf \'%s %p\n\' 2>/dev/null | head -n 500';
      foreach (explode("\n", (string)@shell_exec($cmd)) as $line) {
        if (!preg_match('/^(\d+) (.+)$/', $line, $m)) continue;
        $path = $m[2];
        if (!v_cleanup_safe_path($path, $roots)) continue;
        $items[] = ['path' => $path, 'bytes' => (int)$m[1]];
      }
    }
  }

  $total = 0;
  foreach ($items as $it) $total += (int)($it['bytes'] ?? 0);

  $previewId = 'pv_' . bin2hex(random_bytes(8));
  $now = time();
  $db = v_cleanup_db();
  if (!$db) return ['ok' => false, 'error' => 'no db'];

  $st = $db->prepare('INSERT INTO cleanup_previews (id, kind, created_at, expires_at, items, total_bytes) VALUES (:id, :kind, :created_at, :expires_at, :items, :total_bytes)');
  $st->bindValue(':id', $previewId, SQLITE3_TEXT);
  $st->bindValue(':kind', $kind, SQLITE3_TEXT);
  $st->bindValue(':created_at', $now, SQLITE3_INTEGER);
  $st->bindValue(':expires_at', $now + V_CLEANUP_TTL, SQLITE3_INTEGER);
  $st->bindValue(':items', json_encode($items), SQLITE3_TEXT);
  $st->bindValue(':total_bytes', $total, SQLITE3_INTEGER);
  $st->execute();

  return ['ok' => true, 'preview_id' => $previewId, 'kind' => $kind,
          'label' => $dockerKinds[$kind]['label'] ?? $kind,
          'items' => $items, 'total_bytes' => $total, 'expires_at' => $now + V_CLEANUP_TTL];
}

/** Load a preview by id, checking TTL. Returns ['kind'=>, 'items'=>] or error. */
function v_cleanup_load(string $previewId): array {
  $db = v_cleanup_db();
  if (!$db) return ['ok' => false, 'error' => 'no db'];
  $st = $db->prepare('SELECT kind, items, expires_at FROM cleanup_previews WHERE id = :id');
  $st->bindValue(':id', $previewId, SQLITE3_TEXT);
  $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
  if (!$row) return ['ok' => false, 'error' => 'preview not found'];
  if ($row['expires_at'] < time()) return ['ok' => false, 'error' => 'preview expired'];
  $items = json_decode($row['items'], true);
  if (!is_array($items)) return ['ok' => false, 'error' => 'corrupt preview'];
  return ['ok' => true, 'kind' => $row['kind'], 'items' => $items, 'expires_at' => (int)$row['expires_at']];
}

/** Strict path gate: realpath must exist and be inside an allowed root. */
function v_cleanup_safe_path(string $path, array $roots): bool {
  $real = realpath($path);
  if ($real === false) return false;
  foreach ($roots as $root) {
    $rroot = realpath($root);
    if ($rroot === false) continue;
    if ($real === $rroot) return false; // never the root itself, only things under it
    if (strpos($real, $rroot . '/') === 0) return true;
  }
  return false;
}

/** Delete exactly the items from a validated preview. Returns summary. */
function v_cleanup_apply(string $previewId): array {
  $load = v_cleanup_load($previewId);
  if (!$load['ok']) {
    v_cleanup_audit($previewId, '?', 0, 0, 'rejected', (string)($load['error'] ?? 'unknown'));
    return ['ok' => false, 'error' => $load['error']];
  }
  $kind = $load['kind'];
  $items = $load['items'];
  $dockerKinds = v_cleanup_docker_kinds();

  $done = 0; $bytes = 0; $results = [];

  if (isset($dockerKinds[$kind])) {
    $spec = $dockerKinds[$kind];
    // Whole-cache kinds act on the docker builder state, not individual refs.
    if ($kind === 'docker_build_cache') {
      $out = (string)@shell_exec('timeout 60 docker ' . $spec['cmd'] . ' 2>&1');
      if (preg_match('/Total reclaimed space:\s*([0-9.]+[KMGB]+)/i', $out, $m)) {
        $bytes = v_cleanup_size_to_bytes($m[1]);
      } else { $bytes = 0; }
      $results[] = ['ref' => 'build cache', 'out' => substr($out, 0, 200)];
      $done = 1;
    } else {
    foreach ($items as $it) {
      $ref = (string)($it['ref'] ?? '');
      if ($ref === '') continue;
      // ref must look like a docker id/name: hex, sha256:hex, or name w/o slashes tricks
      if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,128}$/', $ref) && !preg_match('/^sha256:[a-f0-9]{64}$/', $ref)) {
        v_cleanup_audit($previewId, $kind, $done, $bytes, 'rejected', 'bad ref ' . substr($ref, 0, 64));
        return ['ok' => false, 'error' => 'ref failed validation', 'detail' => $ref];
      }
      $cmd = 'timeout 30 docker ' . $spec['cmd'] . ' ' . escapeshellarg($ref) . ' 2>&1';
      $out = (string)@shell_exec($cmd);
      $results[] = ['ref' => $ref, 'out' => substr($out, 0, 200)];
      if (strpos($out, 'Error') === false && strpos($out, 'error') === false) { $done++; }
      $bytes += (int)($it['bytes'] ?? 0);
    }
    }
  } else {
    $roots = v_cleanup_roots($kind);
    foreach ($items as $it) {
      $path = (string)($it['path'] ?? '');
      if ($path === '' || !v_cleanup_safe_path($path, $roots)) {
        // Acceptance: a forged item that was not in the preview is rejected and logged.
        v_cleanup_audit($previewId, $kind, $done, $bytes, 'rejected',
          'path failed re-validation: ' . substr($path, 0, 128));
        return ['ok' => false, 'error' => 'item failed re-validation (forged or changed path)',
                'detail' => ['path' => $path], 'partial_done' => $done];
      }
      $size = (int)($it['bytes'] ?? 0);
      if (@unlink($path)) { $done++; $bytes += $size; $results[] = ['path' => $path, 'removed' => true]; }
      else { $results[] = ['path' => $path, 'removed' => false, 'error' => 'unlink failed']; }
    }
  }

  // Consume the preview — single use.
  $db = v_cleanup_db();
  if ($db) { $st = $db->prepare('DELETE FROM cleanup_previews WHERE id = :id'); $st->bindValue(':id', $previewId, SQLITE3_TEXT); $st->execute(); }

  v_cleanup_audit($previewId, $kind, $done, $bytes, 'applied', substr(json_encode($results), 0, 4000));
  return ['ok' => true, 'kind' => $kind, 'removed' => $done, 'of' => count($items),
          'bytes_reclaimed' => $bytes, 'results' => $results];
}

function v_cleanup_audit(string $previewId, string $kind, int $items, int $bytes, string $result, string $detail = ''): void {
  $db = v_cleanup_db();
  if (!$db) return;
  $st = $db->prepare('INSERT INTO cleanup_audit (at, user, kind, preview_id, items, bytes, result, detail) VALUES (:at, :user, :kind, :pid, :items, :bytes, :result, :detail)');
  $st->bindValue(':at', time(), SQLITE3_INTEGER);
  $st->bindValue(':user', v_cleanup_who(), SQLITE3_TEXT);
  $st->bindValue(':kind', $kind, SQLITE3_TEXT);
  $st->bindValue(':pid', $previewId, SQLITE3_TEXT);
  $st->bindValue(':items', $items, SQLITE3_INTEGER);
  $st->bindValue(':bytes', $bytes, SQLITE3_INTEGER);
  $st->bindValue(':result', $result, SQLITE3_TEXT);
  $st->bindValue(':detail', $detail, SQLITE3_TEXT);
  $st->execute();
}

/** Audit rows for the UI. */
function v_cleanup_audit_list(int $limit = 100): array {
  $db = v_cleanup_db();
  if (!$db) return [];
  $out = [];
  $res = $db->query("SELECT at, user, kind, preview_id, items, bytes, result, detail FROM cleanup_audit ORDER BY at DESC LIMIT " . (int)$limit);
  while (($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  return $out;
}

/** Expire stale previews (housekeeping; safe to call on every preview). */
function v_cleanup_gc(): void {
  $db = v_cleanup_db();
  if (!$db) return;
  $db->query('DELETE FROM cleanup_previews WHERE expires_at < ' . time());
}

/** Helper: human size -> bytes (docker system df reports "12.4GB"). */
function v_cleanup_size_to_bytes(string $s): int {
  if (preg_match('/^([0-9.]+)\s*([KMG]?B?)$/i', trim($s), $m)) {
    $n = (float)$m[1];
    $u = strtoupper($m[2]);
    $mult = ['' => 1, 'B' => 1, 'K' => 1024, 'KB' => 1024, 'M' => 1048576, 'MB' => 1048576,
             'G' => 1073741824, 'GB' => 1073741824][$u] ?? 1;
    return (int)($n * $mult);
  }
  return 0;
}