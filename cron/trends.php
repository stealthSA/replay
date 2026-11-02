<?php
// Recomputes Trending / circle breakouts, creates alerts for advertisers and circles, ends finished ad offers.
//   */15 * * * * php /path/to/current-recap/cron/trends.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/engage.php';
$r = trend_run();
echo date('c') . " scored {$r['content']} post(s), created {$r['notifications']} notification(s)\n";
