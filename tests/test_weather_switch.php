<?php
// Checks the shared PHP interpretation of WEATHER_ENABLED. Run from the repo
// root with: php tests/test_weather_switch.php
// No configuration file, database, or network access is required.
if (PHP_SAPI !== 'cli') {
  exit('cli only');
}
require_once __DIR__ . '/../scripts/common.php';

$cases = [
  [['WEATHER_ENABLED' => '0'], false, 'explicit zero'],
  [['WEATHER_ENABLED' => ' 0 '], false, 'trimmed zero'],
  [['WEATHER_ENABLED' => '1'], true, 'explicit one'],
  [[], true, 'missing key'],
  [['WEATHER_ENABLED' => ''], true, 'blank legacy value'],
  [['WEATHER_ENABLED' => 'unexpected'], true, 'malformed legacy value'],
];

$failed = 0;
foreach ($cases as $case) {
  [$config, $expected, $label] = $case;
  $got = weather_sync_enabled($config);
  $ok = $got === $expected;
  if (!$ok) $failed++;
  printf("%s  %-24s => %s\n", $ok ? 'ok  ' : 'FAIL', $label, $got ? 'enabled' : 'disabled');
}

printf("\n%d cases, %d failed\n", count($cases), $failed);
exit($failed ? 1 : 0);
