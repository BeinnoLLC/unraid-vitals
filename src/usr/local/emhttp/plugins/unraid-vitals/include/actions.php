<?php
/**
 * unraid-vitals — control actions endpoint.
 *
 * Every state-changing action (restart/stop a container, start/stop/restart
 * a VM) lands here, POST only, CSRF-protected with the same token Unraid's
 * own webGui uses (var.ini csrf_token — see docs/README for why: this runs
 * as root via php-fpm, same trust boundary as dynamix.docker.manager and
 * the stock VM Manager page, so it must carry the same protection they do).
 * Since auto_prepend strips the standard CSRF channel before plugin code
 * runs, the token is required on the plugin-controlled channel and validated
 * here with hash_equals (include/csrf.php) — absent or wrong means 403.
 *
 * Every target name is validated against a live `docker ps`/`virsh list`
 * lookup before any command runs — never trust the client-supplied name
 * directly in a shell command.
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/collect.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/**
 * CSRF gate for this endpoint.
 *
 * Delegates to the shared strict gate (include/csrf.php). Unraid's global
 * auto_prepend_file (webGui/include/local_prepend.php) validates the standard
 * CSRF token on every POST and then UNSETS it from $_POST / the X-CSRF-Token
 * header before plugin code runs, so the plugin cannot rely on that channel —
 * and must not treat "token absent" as "already checked". Instead the UI sends
 * the real var.ini token on the plugin-controlled channel (X-Vitals-CSRF
 * header / `vitals_csrf` field) and this gate requires it with hash_equals:
 * a missing or wrong token is rejected here (including CLI), so a tokenless
 * POST can never restart/stop a container or VM even if auto_prepend is
 * bypassed or its contract changes. See include/csrf.php for the full design.
 */
function v_action_csrf_ok(): bool {
  return v_csrf_token_ok();
}

/** True if $name is a real, currently-known container name (defends
 *  against command injection via a name the client made up). */
function v_action_known_container(string $name): bool {
  $names = explode("\n", v_run("docker ps -a --format '{{.Names}}'", 8));
  return in_array($name, array_map('trim', $names), true);
}

function v_action_known_vm(string $name): bool {
  $names = explode("\n", v_run("virsh list --all --name", 8));
  return in_array($name, array_map('trim', $names), true);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
  }
  if (!v_action_csrf_ok()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad csrf token']);
    exit;
  }

  $op = (string)($_POST['op'] ?? '');
  $target = trim((string)($_POST['target'] ?? ''));
  if ($target === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing target']);
    exit;
  }

  $dockerOps = ['docker_start' => 'start', 'docker_stop' => 'stop', 'docker_restart' => 'restart'];
  $vmOps = ['vm_start' => 'start', 'vm_stop' => 'shutdown', 'vm_force_stop' => 'destroy', 'vm_restart' => 'reboot'];

  if (isset($dockerOps[$op])) {
    if (!v_action_known_container($target)) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'error' => 'unknown container']);
      exit;
    }
    $out = v_run('docker ' . $dockerOps[$op] . ' ' . escapeshellarg($target), 30);
    echo json_encode(['ok' => true, 'op' => $op, 'target' => $target, 'output' => $out]);
    exit;
  }

  if (isset($vmOps[$op])) {
    if (!v_action_known_vm($target)) {
      http_response_code(404);
      echo json_encode(['ok' => false, 'error' => 'unknown VM']);
      exit;
    }
    $out = v_run('virsh ' . $vmOps[$op] . ' ' . escapeshellarg($target), 30);
    echo json_encode(['ok' => true, 'op' => $op, 'target' => $target, 'output' => $out]);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'unknown op']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
