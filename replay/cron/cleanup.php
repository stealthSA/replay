<?php
// Run from cron, e.g. every 15 minutes:
//   */15 * * * * php /path/to/replay/cron/cleanup.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/platforms.php';
require __DIR__ . '/../lib/ml.php';

$total = 0; $kept = 0;
foreach (db()->query('SELECT id FROM users WHERE disabled=0')->fetchAll() as $u) {
    $r = cleanup_user((int)$u['id']);
    $total += $r['deleted']; $kept += $r['kept_by_ml'];
}
echo date('c') . " cleaned=$total kept_by_ml=$kept\n";
