<?php
/**
 * Test helper for tests/test-csrf.php — NOT a suite (run-php-tests.sh only
 * collects test-*.php). Boots exactly one request against an endpoint the way
 * the web server would (Unraid's auto_prepend does NOT run, so the plugin gate
 * is the only defence — which is precisely what is being tested), prints the
 * endpoint's JSON body and the HTTP status code, then lets the endpoint exit.
 *
 * Usage:
 *   VITALS_TEST_VAR_INI=/path/to/stub/var.ini    stub token source (defines V_VAR_INI)
 *   VITALS_TEST_HEADER_TOKEN=<token>             optional X-Vitals-CSRF header value
 *   php csrf-probe.php <endpoint.php> <query-json> <post-urlencoded> <METHOD>
 *
 * The stub var.ini holds a FAKE token; the real one is never read or printed.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

if (getenv('VITALS_TEST_VAR_INI')) define('V_VAR_INI', getenv('VITALS_TEST_VAR_INI'));

register_shutdown_function(function () {
  echo "\n__HTTP__=" . (http_response_code() ?: 200) . "\n";
});

$_GET = json_decode($argv[2] ?? '{}', true) ?: [];
$_POST = [];
parse_str($argv[3] ?? '', $_POST);
$_SERVER['REQUEST_METHOD'] = strtoupper($argv[4] ?? 'POST');
$h = getenv('VITALS_TEST_HEADER_TOKEN');
if ($h !== false && $h !== '') $_SERVER['HTTP_X_VITALS_CSRF'] = $h;

include $argv[1];
