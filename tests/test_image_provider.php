<?php
// Checks the configuration normalization used to disable outbound species
// image lookups. Run from the repo root with:
//   php tests/test_image_provider.php
// No configuration file or network access is required.
if (PHP_SAPI !== 'cli') {
  exit('cli only');
}
require_once __DIR__ . '/../scripts/common.php';

$cases = [
  '' => '',
  'NONE' => '',
  'None' => '',
  ' none ' => '',
  '0' => '0',
  'WIKIPEDIA' => 'WIKIPEDIA',
  ' FLICKR ' => 'FLICKR',
];

$failed = 0;
foreach ($cases as $input => $expected) {
  $got = normalize_image_provider($input);
  $ok = $got === $expected;
  if (!$ok) $failed++;
  printf("%s  %-12s => %s\n", $ok ? 'ok  ' : 'FAIL', var_export($input, true), var_export($got, true));
}

[$primary, $fallback] = make_image_provider(['IMAGE_PROVIDER' => 'NONE']);
if ($primary !== null || $fallback !== null) {
  $failed++;
  echo "FAIL  make_image_provider() did not disable literal NONE\n";
} else {
  echo "ok    make_image_provider() disables literal NONE\n";
}

[$primary, $fallback] = make_image_provider(['IMAGE_PROVIDER' => '0']);
if ($primary !== null || $fallback !== null) {
  $failed++;
  echo "FAIL  make_image_provider() changed legacy IMAGE_PROVIDER=0 behavior\n";
} else {
  echo "ok    make_image_provider() preserves legacy IMAGE_PROVIDER=0 behavior\n";
}

printf("\n%d cases, %d failed\n", count($cases) + 2, $failed);
exit($failed ? 1 : 0);
