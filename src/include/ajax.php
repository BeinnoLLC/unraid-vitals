<?php
/**
 * unraid-vitals — AJAX endpoint for the UI.
 *
 *   ?action=data     latest snapshot + ring (default)
 *   ?action=refresh  force a new sample, then return it
 *   ?action=daily    daily aggregates from the flash rollups
 */

require_once __DIR__ . '/store.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'data';

try {
  if ($action === 'refresh') {
    $snap = v_tick(true);
    echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'daily') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    echo json_encode(['ok' => true, 'daily' => v_daily($days)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  $snap = v_latest();
  if (!$snap) $snap = v_tick(true);
  echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
