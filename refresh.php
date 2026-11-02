<?php
// Refreshes the live content: trending posts (Mastodon, Bluesky), topical videos and pictures (F1, MotoGP, sport, music, entertainment, tech, fashion, cars...),
// then re-scores Trending. Two ways to run it:
//   1) cPanel "Cron Jobs":   */30 * * * *  /usr/local/bin/php /home/USER/public_html/refresh.php
//   2) Over the web (free cron services, or just open it in a browser): set 'CRON_KEY' => 'a-long-random-secret' in config.local.php, then call
//        https://YOURDOMAIN/refresh.php?key=a-long-random-secret
// Without CRON_KEY the web address answers 404. Runs at most once every 5 minutes.
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $key = (string)cfg('CRON_KEY');
    if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(404); exit; }
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
}
@set_time_limit(240); ignore_user_abort(true);
require_once __DIR__ . '/app/engage.php';
require_once __DIR__ . '/app/open_trends.php';
require_once __DIR__ . '/app/topic_media.php';
$db = db();
$wait = content_refresh_gate();
if (!$cli && $wait > 0) { http_response_code(429); echo "Ran recently; try again in $wait s.\n"; exit; }
if ($cli) topic_kv_set('refresh_last', (string)time());
$notes = []; $n = 0;
// One-time clean-up (config 'CLEAR_IMPORTED_ONCE' => true): removes old imported posts that are NOT about your priority topics. Kept: posts about those topics,
// anything a signed-in user owns, sample data and posts with ad offers. It runs once; the database remembers it, so leaving the line on does nothing further.
if (cfg('CLEAR_IMPORTED_ONCE') && topic_kv_get('cleared_imports_v1') === null) {
    try {
        $keep = array_values(array_intersect(array_map('trim', explode(',', (string)cfg('TOPIC_PRIORITY'))), categories()));
        $in = $keep ? "'" . implode("','", $keep) . "'" : "''";
        $rows = $db->query("SELECT c.id, c.thumb_path FROM content c WHERE c.source='import' AND c.owner_user_id IS NULL AND COALESCE(c.category,'') NOT IN ($in) AND NOT EXISTS (SELECT 1 FROM bids b WHERE b.content_id=c.id)")->fetchAll();
        $del = $db->prepare('DELETE FROM content WHERE id=?'); $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/';
        foreach ($rows as $r) { $del->execute([$r['id']]); if ($r['thumb_path'] && !str_contains((string)$r['thumb_path'], '..')) @unlink($dir . $r['thumb_path']); }
        topic_kv_set('cleared_imports_v1', (string)time()); echo 'Cleared ' . count($rows) . " older imported post(s) outside your topics (one time only).\n";
    } catch (Throwable $t) { $notes[] = 'clean-up: ' . $t->getMessage(); error_log('[recap] refresh: ' . $t->getMessage()); }
}
$res = content_refresh(); $n = $res['saved']; $scored = $res['scored']; $notes = array_merge($notes, $res['notes']);
echo date('c') . "\nSaved $n real post/video item(s); scored $scored.\n";
foreach ($notes as $x) echo "  note: $x\n";
try {
    $by = $db->query("SELECT platform, COUNT(*) c, SUM(thumb_path IS NOT NULL) t FROM content WHERE source='import' GROUP BY platform")->fetchAll();
    $wk = (int)$db->query("SELECT COUNT(*) FROM content WHERE source='import' AND posted_at >= " . (time() - 7 * 86400))->fetchColumn();
    echo "In the app now: " . ($by ? implode(', ', array_map(fn($r) => "{$r['platform']} {$r['c']} ({$r['t']} with pictures)", $by)) : 'nothing') . "; $wk from the last 7 days (what Trending shows).\n";
} catch (Throwable $t) {}
if ($n === 0) echo "Nothing saved. Most likely this server cannot make outbound HTTPS calls (check health.php, curl, storage/ writable).\n";
exit($cli && $n === 0 ? 2 : 0);
