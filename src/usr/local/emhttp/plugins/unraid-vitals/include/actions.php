<?php
/**
 * unraid-vitals — control actions endpoint.
 *
 * Every state-changing action (restart/stop a container, start/stop/restart
 * a VM) lands here, POST only, CSRF-protected with the same token Unraid's
 * own webGui uses (var.ini csrf_token — see docs/README for why: this runs
 * as root via php-fpm, same trust boundary as dynamix.docker.manager and
 * the stock VM Manager page, so it must carry the same protection they do).
 *
 * Every target name is validated against a live `docker ps`/`virsh list`
 * lookup before any command runs — never trust the client-supplied name
 * directly in a shell command.
 */

require_once __DIR__ . '/collect.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function v_action_csrf_ok(): bool {
  $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $real = @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? null;
  return $real && hash_equals($real, (string)$sent);
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
