<?php
declare(strict_types=1);
/**
 * Current, topical, visual content for Trending and the sample recap: entertainment, Formula 1, sport, music, fashion, tech, cars, gaming, travel.
 *   YouTube : the public upload feed of well-known channels (https://www.youtube.com/feeds/videos.xml?channel_id=...). Real videos with their
 *             thumbnail, view count and like count. Played through YouTube's own embedded player (youtube-nocookie.com), never copied.
 *   Mastodon: the public hashtag timelines (/api/v1/timelines/tag/<tag>) for posts that carry a picture or video, ranked by likes and boosts.
 * No keys, no scraping of logged-in pages. Each item links back to the original and shows the author's name. Anything unreachable is skipped.
 */
require_once __DIR__ . '/open_trends.php';

/** topic (= a category in lexicon.php) => [label, YouTube channel handles, Mastodon hashtags]. Add your own in config.local.php: 'TOPIC_MEDIA_EXTRA' => ['motorsport' => ['@SomeChannel']]. */
const TOPIC_FEEDS = [
    'motorsport'    => ['F1 & MotoGP',   ['@Formula1', '@skysportsf1', '@redbullracing', '@MotoGP', '@ScuderiaFerrari', '@McLaren'],            ['formula1', 'f1', 'motogp']],
    'amapiano'      => ['Amapiano',      ['@MajorLeagueDJz', '@KabzaDeSmall', '@DJMaphorisa', '@UncleWaffles', '@Focalistic', '@YoungStunna', '@Tyla'],                    ['amapiano', 'amapianomusic', 'southafricanmusic']],
    'hiphop'        => ['Hip hop',       ['@HotNewHipHop', '@XXL', '@Complex', '@Genius', '@COLORSxSTUDIOS', '@DJBoothTV', '@HipHopDX'],                                ['hiphop', 'rap', 'hiphopmusic']],
    'sport'         => ['Sport',         ['@ESPN', '@NBA', '@UEFA', '@SuperSportTV', '@BleacherReport', '@OneFootball', '@NFL'],               ['football', 'nba', 'rugby', 'soccer', 'cricket']],
    'music'         => ['Music',         ['@billboard', '@Vevo', '@Spotify', '@RollingStone', '@MTV', '@COLORSxSTUDIOS', '@OfficialCharts'],    ['music', 'newmusic', 'hiphop', 'nowplaying']],
    'entertainment' => ['Entertainment', ['@MarvelEntertainment', '@Netflix', '@Variety', '@TheTonightShow', '@HBO', '@sonypictures'],         ['movies', 'film', 'tv', 'netflix']],
    'fashion'       => ['Fashion',       ['@vogue', '@GQ', '@HYPEBEAST', '@Highsnobiety', '@ELLEmagazine'],                                    ['fashion', 'streetwear', 'style']],
    'tech'          => ['Tech',          ['@MKBHD', '@TheVerge', '@WIRED', '@Mrwhosetheboss', '@UnboxTherapy', '@iJustine'],                    ['tech', 'gadgets', 'ai']],
    'automotive'    => ['Cars',          ['@TopGear', '@carwow', '@MotorTrend'],                                                                ['cars', 'automotive']],
    'gaming'        => ['Gaming',        ['@IGN', '@PlayStation'],                                                                              ['gaming', 'videogames']],
    'travel'        => ['Travel',        ['@NatGeoTravel', '@lonelyplanet'],                                                                    ['travel', 'photography']],
];

function topic_kv_get(string $k): ?string { $s = db()->prepare('SELECT v FROM kv WHERE k=?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? null : (string)$v; }
function topic_kv_set(string $k, string $v): void { db()->prepare('INSERT INTO kv (k, v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]); }

/** Channel handles (or UC… channel ids) for each topic, config extras included. */
function topic_channels(): array {
    $o = []; $extra = cfg('TOPIC_MEDIA_EXTRA'); $only = array_filter(array_map('trim', explode(',', (string)cfg('TOPIC_MEDIA_TOPICS'))));
    foreach (TOPIC_FEEDS as $t => [$label, $ch, $tags]) { if ($only && !in_array($t, $only, true)) continue; $o[$t] = ['channels' => array_merge($ch, is_array($extra) ? ($extra[$t] ?? []) : []), 'tags' => $tags, 'label' => $label]; }
    return $o;
}

/** Handle -> channel id (UC + 22 chars). Looked up once from the channel page and remembered in the database. @return array<string,string> */
function topic_resolve_channels(array $handles, array &$notes): array {
    $ids = []; $todo = [];
    foreach (array_unique($handles) as $h) {
        if (preg_match('/^UC[\w-]{22}$/', $h)) { $ids[$h] = $h; continue; }
        $c = topic_kv_get('yt:' . strtolower($h)); if ($c !== null && $c !== '') $ids[$h] = $c; elseif ($c === null) $todo[] = $h;
    }
    if ($todo) {
        $base = rtrim((string)cfg('TOPIC_YOUTUBE_BASE'), '/');
        // a consent cookie keeps YouTube from answering EU visitors with its cookie wall instead of the channel page
        $res = open_fetch_all(array_map(fn($h) => "$base/" . (str_starts_with($h, '@') ? $h : '@' . $h), $todo), 12, 3 * 1024 * 1024, ['Cookie: CONSENT=YES+1; SOCS=CAI', 'Accept-Language: en'], true);
        foreach ($todo as $h) {
            [$code, $html] = $res["$base/" . (str_starts_with($h, '@') ? $h : '@' . $h)] ?? [0, ''];
            if ($code === 200 && (preg_match('#<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[\w-]{22})"#', $html, $m) || preg_match('/"externalId":"(UC[\w-]{22})"/', $html, $m) || preg_match('/itemprop="(?:channelId|identifier)" content="(UC[\w-]{22})"/', $html, $m))) { $ids[$h] = $m[1]; topic_kv_set('yt:' . strtolower($h), $m[1]); }
            elseif ($code === 404) topic_kv_set('yt:' . strtolower($h), '');   // no such channel: stop asking
            else $notes[] = "YouTube $h: " . ($code ? "HTTP $code" : 'not reachable');
        }
    }
    return $ids;
}

/** One YouTube upload feed -> up to $max newest videos of the last 21 days as import items. */
function topic_parse_youtube(string $xml, string $topic, int $max): array {
    if (!preg_match_all('#<entry>(.*?)</entry>#s', $xml, $entries)) return [];
    $chan = preg_match('#<author>\s*<name>(.*?)</name>#s', $xml, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : ''; $out = [];
    foreach ($entries[1] as $e) {
        if (!preg_match('#<yt:videoId>([\w-]{6,20})</yt:videoId>#', $e, $v)) continue; $id = $v[1];
        $title = preg_match('#<media:title>(.*?)</media:title>#s', $e, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : '';
        $desc = preg_match('#<media:description>(.*?)</media:description>#s', $e, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : '';
        $at = preg_match('#<published>(.*?)</published>#', $e, $m) ? (strtotime($m[1]) ?: 0) : 0; if (!$at || $at < time() - 21 * DAY) continue;
        $views = preg_match('#<media:statistics views="(\d+)"#', $e, $m) ? (int)$m[1] : null; $likes = preg_match('#<media:starRating count="(\d+)"#', $e, $m) ? (int)$m[1] : null;
        $body = open_clean_text(preg_replace('#https?://\S+#', '', $desc) ?? '');
        $out[] = ['platform' => 'youtube', 'external_id' => 'yt:' . $id, 'url' => 'https://www.youtube.com/watch?v=' . $id, 'kind' => 'video', 'author' => open_author($chan, ''), 'title' => mb_substr(open_clean_text($title), 0, 120),
            'body' => $body !== '' ? $body : open_clean_text($title), 'posted_at' => min($at, time()), 'thumb_url' => rtrim((string)cfg('TOPIC_YOUTUBE_THUMBS'), '/') . "/vi/$id/hqdefault.jpg",
            'media_url' => "https://www.youtube-nocookie.com/embed/$id", 'media_type' => 'YOUTUBE', 'topic' => $topic, 'metrics' => array_filter(['views' => $views, 'likes' => $likes], fn($x) => $x !== null)];
        if (count($out) >= $max) break;
    }
    return $out;
}

/** The whole catalogue, fetched once per request. @return array{items:list,discussions:list,notes:list} items = videos and pictures with a topic; discussions = text posts with replies */
function topic_catalog(): array {
    static $cache = null; if ($cache !== null) return $cache;
    $notes = []; $topics = topic_channels(); $perChannel = max(1, min(8, (int)cfg('TOPIC_MEDIA_PER_CHANNEL'))); $tagCap = 10;
    $handles = []; foreach ($topics as $t) foreach ($t['channels'] as $h) $handles[] = $h;
    $ids = topic_resolve_channels($handles, $notes); $yt = rtrim((string)cfg('TOPIC_YOUTUBE_BASE'), '/'); $reqs = [];
    foreach ($topics as $topic => $t) {
        foreach ($t['channels'] as $h) if (isset($ids[$h])) $reqs["$yt/feeds/videos.xml?channel_id={$ids[$h]}"] = ['yt', $topic, $h];
        foreach (array_slice(open_mastodon_bases(), 0, 2) as $b) foreach ($t['tags'] as $tag) {
            $reqs["$b/api/v1/timelines/tag/$tag?limit=40&only_media=true"] = ['ms', $topic, $b]; $reqs["$b/api/v1/timelines/tag/$tag?limit=40"] = ['mt', $topic, $b];
        }
    }
    $res = open_fetch_all(array_keys($reqs), 15, 6 * 1024 * 1024, [], true); $items = []; $disc = []; $seen = []; $bad = 0; $media = [];
    foreach ($reqs as $url => [$type, $topic, $who]) {
        [$code, $body] = $res[$url] ?? [0, '']; if ($code !== 200) { if ($type === 'yt') $notes[] = "YouTube $who feed: " . ($code ? "HTTP $code" : 'not reachable'); else $bad++; continue; }
        if ($type === 'yt') { foreach (topic_parse_youtube($body, $topic, $perChannel) as $it) if (!isset($seen[$it['url']])) { $seen[$it['url']] = 1; $items[] = $it; } continue; }
        $j = json_decode($body, true); if (!is_array($j)) continue;
        foreach ($j as $s) {
            if (!is_array($s) || !in_array($s['language'] ?? 'en', ['en', null], true)) continue; $it = open_parse_mastodon($s, $who); if (!$it || isset($seen[$it['url']])) continue;
            $it['topic'] = $topic; $score = $it['metrics']['likes'] + 2 * $it['metrics']['shares'] + 3 * $it['metrics']['comments'];
            if ($type === 'ms' && $it['kind'] !== 'text' && !empty($it['thumb_url'])) { $seen[$it['url']] = 1; $media[$topic][] = [$score, $it]; }
            if ($type === 'mt' && $it['kind'] === 'text' && $it['metrics']['comments'] >= 2 && mb_strlen($it['body']) >= 40) { $seen[$it['url']] = 1; $disc[] = [$score, $it]; }
        }
    }
    if ($bad) $notes[] = "Mastodon hashtag timelines: $bad request(s) failed";
    foreach ($media as $list) { usort($list, fn($a, $b) => $b[0] <=> $a[0]); foreach (array_slice($list, 0, $tagCap) as [, $it]) $items[] = $it; }
    usort($disc, fn($a, $b) => $b[0] <=> $a[0]);
    if (!$items) $notes[] = 'no topical videos or pictures could be fetched';
    return $cache = ['items' => $items, 'discussions' => array_map(fn($x) => $x[1], array_slice($disc, 0, 40)), 'notes' => array_values(array_unique($notes))];
}

/** Stores the catalogue as Trending content. @return array{0:int,1:list<string>} [saved, notes] */
function topic_media_load(): array {
    if (!cfg('TOPIC_MEDIA')) return [0, []];
    @set_time_limit(180); $c = topic_catalog(); $n = open_store_items(array_merge($c['items'], $c['discussions'])); if ($n) trend_run();
    return [$n, $c['notes']];
}

/** The whole refresh: trending posts + topical videos/pictures, then Trending is re-scored. Used by refresh.php and the "Get latest content" button.
 *  @return array{saved:int,scored:int,notes:list<string>} */
function content_refresh(): array {
    @set_time_limit(240); require_once __DIR__ . '/engage.php'; $notes = []; $n = 0; $scored = 0;
    try { if (cfg('OPEN_TRENDS')) { [$a, $nn] = open_trends_load(); $n += $a; $notes = array_merge($notes, $nn); } } catch (Throwable $t) { $notes[] = 'trending posts: ' . $t->getMessage(); error_log('[recap] refresh: ' . $t->getMessage()); }
    try { if (cfg('TOPIC_MEDIA')) { [$a, $nn] = topic_media_load(); $n += $a; $notes = array_merge($notes, $nn); } } catch (Throwable $t) { $notes[] = 'topic videos: ' . $t->getMessage(); error_log('[recap] refresh: ' . $t->getMessage()); }
    try { $r = trend_run(); $scored = (int)($r['content'] ?? 0); } catch (Throwable $t) { $notes[] = 'scoring: ' . $t->getMessage(); error_log('[recap] refresh: ' . $t->getMessage()); }
    return ['saved' => $n, 'scored' => $scored, 'notes' => array_values(array_unique($notes))];
}
/** Seconds to wait before another refresh is allowed (0 = go ahead); marks the start when allowed. */
function content_refresh_gate(): int {
    $last = (int)(topic_kv_get('refresh_last') ?? 0); $wait = max(0, (int)cfg('REFRESH_MIN_SECONDS') - (time() - $last));
    if ($wait === 0) topic_kv_set('refresh_last', (string)time());
    return $wait;
}
