<?php
// Optional: keep stories (they expire on Instagram after 24 hours) and threads up to date without the user opening the app.
//   */30 * * * * php /path/to/current-recap/cron/sync.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/content.php';
require_once __DIR__ . '/../app/meta.php';
$n = 0; $new = 0;
foreach (db()->query('SELECT DISTINCT user_id FROM connections')->fetchAll(PDO::FETCH_COLUMN) as $uid) { $r = sync_user((int)$uid, false); $n++; $new += $r['new']; }
echo date('c') . " synced $n user(s), $new new item(s)\n";
