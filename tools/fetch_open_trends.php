<?php
// Loads real public trending posts (Mastodon, Bluesky) and current topical videos/pictures (YouTube channel feeds, Mastodon hashtags: F1, sport, music, entertainment, fashion, tech, cars...) into Trending. Run it on your own machine (needs internet):
//   php tools/fetch_open_trends.php          (run again, or from cron, to refresh the counts; growth needs 2+ runs)
// Instances and limits: OPEN_TRENDS_* keys in config.local.php. Pressing "Load sample data" in the dev login does the same thing.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/open_trends.php';
require_once __DIR__ . '/../app/topic_media.php';
[$n, $notes] = open_trends_load(); [$t, $tn] = topic_media_load(); $n += $t; $notes = array_merge($notes, $tn);
echo "Saved $n real trending post(s).\n"; foreach ($notes as $x) echo "  note: $x\n";
exit($n > 0 ? 0 : 2);
