<?php
/**
 * unraid-vitals — shared CSRF gate for state-mutating endpoints.
 *
 * Why this exists (UNRAIDPLUG-223 hardening, Hazem decision 38):
 *
 * Unraid's global auto_prepend_file (webGui/include/local_prepend.php)
 * validates the standard `csrf_token` on POSTs and then UNSETS it from
 * $_POST['csrf_token'] and $_SERVER['HTTP_X_CSRF_TOKEN'] before plugin code
 * runs. The plugin therefore cannot re-validate that channel, and must NEVER
 * treat "token absent" as "auto_prepend already checked it" — that trust made
 * every state-mutating endpoint accept tokenless POSTs whenever auto_prepend
 * was bypassed (CLI/CGI quirks), misconfigured, or its contract changed.
 *
 * So the plugin owns its own channel that auto_prepend does not touch:
 *
 *   - X-Vitals-CSRF request header  -> $_SERVER['HTTP_X_VITALS_CSRF']  (primary)
 *   - `vitals_csrf` POST field      -> $_POST['vitals_csrf']           (fallback)
 *
 * The UI sends the real var.ini csrf_token over this channel; this gate
 * compares it with hash_equals(). A request whose channel token is MISSING or
 * MISMATCHED is rejected — there is no "absent means fine" path and no
 * CLI bypass. The standard csrf_token / X-CSRF-Token values are deliberately
 * NOT accepted here: they belong to auto_prepend's channel (it strips them),
 * and accepting them would silently re-open the trust gap the moment its
 * stripping behaviour changed.
 */

/** Path to Unraid's var.ini. Tests override via the V_VAR_INI constant. */
function v_var_ini_path(): string {
  return defined('V_VAR_INI') ? (string)V_VAR_INI : '/var/local/emhttp/var.ini';
}

/** The real server-side CSRF token (var.ini csrf_token), or null if unreadable. */
function v_real_csrf_token(): ?string {
  $tok = @parse_ini_file(v_var_ini_path())['csrf_token'] ?? null;
  return (is_string($tok) && $tok !== '') ? $tok : null;
}

/**
 * Every non-empty token candidate supplied on the plugin-controlled channel.
 * The header wins nothing over the field: if BOTH are present they must BOTH
 * match, so a mismatched extra candidate can never ride along unnoticed.
 */
function v_csrf_channel_tokens(): array {
  $out = [];
  foreach ([$_SERVER['HTTP_X_VITALS_CSRF'] ?? null, $_POST['vitals_csrf'] ?? null] as $cand) {
    if (is_string($cand) && $cand !== '') $out[] = $cand;
  }
  return $out;
}

/**
 * True only for a POST whose channel token(s) all hash_equals() the real
 * token. Missing token, wrong token, non-POST, or an unreadable real token
 * all return false — a mutating request must prove it carries the token.
 */
function v_csrf_token_ok(): bool {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
  $real = v_real_csrf_token();
  if ($real === null) return false;
  $sent = v_csrf_channel_tokens();
  if (!$sent) return false;
  foreach ($sent as $cand) if (!hash_equals($real, $cand)) return false;
  return true;
}
