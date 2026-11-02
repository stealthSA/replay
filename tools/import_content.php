<?php
// Imports LICENSED public post data (for example from a data partner or Meta's Content Library export) so Trending covers more than app users.
//   php tools/import_content.php posts.json     (a JSON list, or {"items":[...]})
//   php tools/import_content.php posts.csv      (header: platform,url,kind,author,title,body,posted_at,views,watch_seconds,likes,comments,shares,downloads,saves)
// kind = video | image | text. Leave a metric empty when the source does not provide it (it is then ignored, not counted as zero).
// Imported posts have no owner in this app, so they appear in Trending but cannot receive ad offers.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/engage_api.php';
$f = $argv[1] ?? ''; if (!is_file($f)) { fwrite(STDERR, "Usage: php tools/import_content.php posts.json|posts.csv\n"); exit(1); }
$items = [];
if (preg_match('/\.csv$/i', $f)) { $h = fopen($f, 'r'); $cols = fgetcsv($h); while (($r = fgetcsv($h)) !== false) if (count($r) === count($cols)) $items[] = array_combine($cols, $r); fclose($h); }
else { $j = json_decode((string)file_get_contents($f), true); $items = is_array($j) ? ($j['items'] ?? $j) : []; }
$ok = 0; $skip = 0; foreach ($items as $it) { if (is_array($it) && import_one($it)) $ok++; else $skip++; }
trend_run(); echo "Imported $ok, skipped $skip.\n";
