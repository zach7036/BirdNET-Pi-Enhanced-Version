<?php
// Checks wikipedia_commons_file_name() against the image URL shapes the
// Wikipedia summary API and the Commons imageinfo API actually return
// (captured 2026-08-21). Run from the repo root with a PHP CLI:
//   php tests/test_image_urls.php
// Exits non-zero on the first mismatch. No network access.
if (PHP_SAPI !== 'cli') {
  exit('cli only');
}
require_once __DIR__ . '/../scripts/common.php';

$cases = [
  // Plain original with the tracking query string (most species)
  'https://upload.wikimedia.org/wikipedia/commons/9/97/American_robin_%2871307%29.jpg?utm_source=en.wikipedia.org&utm_campaign=api&utm_content=thumbnail_unscaled'
    => 'American_robin_(71307).jpg',
  // Pre-scaled thumbnail of a large original (Killdeer, 5800x4143 PNG)
  'https://upload.wikimedia.org/wikipedia/commons/thumb/c/cb/Killdeer_Heislerville.png/3840px-Killdeer_Heislerville.png?utm_source=en.wikipedia.org&utm_campaign=api&utm_content=thumbnail'
    => 'Killdeer_Heislerville.png',
  // Commons imageinfo thumburl (what gets stored once the lookup works)
  'https://upload.wikimedia.org/wikipedia/commons/thumb/5/5c/Male_northern_cardinal_in_Central_Park_%2852612%29.jpg/1280px-Male_northern_cardinal_in_Central_Park_%2852612%29.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'
    => 'Male_northern_cardinal_in_Central_Park_(52612).jpg',
  // Older rows without a query string
  'https://upload.wikimedia.org/wikipedia/commons/3/3c/Strix-varia-005.jpg'
    => 'Strix-varia-005.jpg',
  'https://upload.wikimedia.org/wikipedia/commons/thumb/2/27/Tufted_titmouse_%2884917%29.jpg/3840px-Tufted_titmouse_%2884917%29.jpg'
    => 'Tufted_titmouse_(84917).jpg',
  // Local (non-Commons) upload thumbnail
  'https://upload.wikimedia.org/wikipedia/en/thumb/a/ab/Some_bird.jpg/800px-Some_bird.jpg'
    => 'Some_bird.jpg',
  // Fragment and surrounding whitespace
  " https://upload.wikimedia.org/wikipedia/commons/a/aa/Av_Mourning_Dove_JG.jpg#top \n"
    => 'Av_Mourning_Dove_JG.jpg',
  // Apostrophe and space encodings survive
  'https://upload.wikimedia.org/wikipedia/commons/1/1a/Bachman%27s_sparrow%20closeup.jpg?utm_source=x'
    => "Bachman's_sparrow closeup.jpg",
  // Garbage in, empty out (callers treat '' as "no metadata")
  '' => '',
  'not a url' => 'not a url',
  'https://upload.wikimedia.org/' => '',
];

$failed = 0;
foreach ($cases as $url => $expected) {
  $got = wikipedia_commons_file_name($url);
  $ok = $got === $expected;
  if (!$ok) $failed++;
  printf("%s  %s\n      got: %s\n", $ok ? 'ok  ' : 'FAIL', $url === '' ? "(empty)" : $url, var_export($got, true));
}
printf("\n%d cases, %d failed\n", count($cases), $failed);
exit($failed ? 1 : 0);
