<?php
// CLI request adapter used by test_review_api.py, never a web endpoint.
if (PHP_SAPI !== 'cli' || empty($argv[1]) || !is_file($argv[1] . '/.review-test-fixture')) exit(2);
define('__ROOT__', realpath($argv[1]));
chdir(__ROOT__); // Relative paths must also stay inside the throwaway fixture.
$request = json_decode($argv[2], true);
// Common's config cache avoids reading a real config. Suppress its stat()
// warning on machines without /etc/birdnet, as the web entry point does.
error_reporting(E_ERROR);
$_SESSION = ['my_config' => ['VISIT_GAP_MINUTES' => 5, 'CADDY_PWD' => 'test-password'], 'my_timezone' => 'UTC'];
$_SERVER['REQUEST_URI'] = $request['uri'];
$_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'GET';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
if ($request['auth'] ?? true) {
  $_SERVER['PHP_AUTH_USER'] = 'birdnet';
  $_SERVER['PHP_AUTH_PW'] = 'test-password';
}
if ($request['csrf'] ?? true) $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
parse_str(parse_url($request['uri'], PHP_URL_QUERY) ?? '', $_GET);
$_POST = $request['body'] ?? [];
ob_start();
register_shutdown_function(function () {
  $body = ob_get_clean();
  echo json_encode(['code' => http_response_code() ?: 200, 'body' => json_decode($body, true), 'raw' => $body]);
});
require __DIR__ . '/../scripts/api.php';
