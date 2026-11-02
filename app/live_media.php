<?php
declare(strict_types=1);
/**
 * Real, current pictures, song artwork, podcasts and videos for the sample data, so the demo looks like the real thing.
 * All sources are public and need no key:
 *   Songs + podcasts : Apple's charts (rss.applemarketingtools.com) and iTunes lookup for the 30-second previews and artwork
 *   Photos           : Openverse (api.openverse.org), Creative Commons photos; creator and licence are written into the item
 *   Videos           : trending Mastodon posts with a video attachment (author and link to the original are kept)
 * Applied only to sample rows (source = 'demo'); anything that cannot be reached keeps its generated picture.
 */
require_once __DIR__ . '/open_trends.php';

const LIVE_PHOTO_QUERIES = [
    'sneakers' => 'sneakers shoes', 'automotive' => 'sports car', 'food' => 'street food', 'fashion' => 'street fashion', 'beauty' => 'makeup', 'tech' => 'smartphone gadget', 'gaming' => 'video game controller',
    'fitness' => 'running workout', 'music' => 'concert live music', 'travel' => 'mountain hiking landscape', 'sport' => 'football match', 'home' => 'interior living room', 'finance' => 'laptop desk office', 'pets' => 'dog',
];

function live_art(string $u, int $px = 600): string { return preg_replace('#/\d+x\d+(bb)?\.(jpg|png|webp)$#', "/{$px}x{$px}bb.jpg", $u) ?? $u; }
function live_json(array $res, string $url): ?array { [$c, $b] = $res[$url] ?? [0, '']; $j = $c === 200 ? json_decode($b, true) : null; return is_array($j) ? $j : null; }

/** Downloads the current catalogue. @return array{songs:list,podcasts:list,photos:array,videos:list,notes:list} */
function live_media_catalog(): array {
    $cc = strtolower(preg_replace('/[^a-z]/i', '', (string)cfg('LIVE_MEDIA_COUNTRY'))); $cc = strlen($cc) === 2 ? $cc : 'us';
    $rss = rtrim((string)cfg('LIVE_MEDIA_APPLE_RSS'), '/'); $ov = rtrim((string)cfg('LIVE_MEDIA_OPENVERSE'), '/'); $it = rtrim((string)cfg('LIVE_MEDIA_ITUNES'), '/');
    $cats = array_values(array_unique([$cc, 'us'])); $urls = []; $notes = [];
    foreach ($cats as $c) { $urls['s' . $c] = "$rss/api/v2/$c/music/most-played/25/songs.json"; $urls['p' . $c] = "$rss/api/v2/$c/podcasts/top/25/podcasts.json"; }
    foreach (LIVE_PHOTO_QUERIES as $cat => $q) $urls['o' . $cat] = "$ov/v1/images/?" . http_build_query(['q' => $q, 'page_size' => 8, 'category' => 'photograph', 'license_type' => 'commercial', 'mature' => 'false']);
    $res = open_fetch_all(array_values($urls), 15);
    $get = fn(string $k) => live_json($res, $urls[$k]);
    $songs = []; foreach ($cats as $c) { $j = $get('s' . $c); if (!empty($j['feed']['results'])) { foreach ($j['feed']['results'] as $r) $songs[] = ['id' => (string)($r['id'] ?? ''), 'title' => (string)$r['name'], 'artist' => (string)$r['artistName'], 'art' => live_art((string)$r['artworkUrl100']), 'url' => (string)($r['url'] ?? ''), 'preview' => null]; break; } }
    $pods = []; foreach ($cats as $c) { $j = $get('p' . $c); if (!empty($j['feed']['results'])) { foreach ($j['feed']['results'] as $r) if (preg_match('#^https://#', (string)($r['url'] ?? ''))) $pods[] = ['title' => (string)$r['name'], 'artist' => (string)$r['artistName'], 'art' => live_art((string)$r['artworkUrl100']), 'url' => (string)$r['url']]; break; } }
    if (!$songs) $notes[] = 'Apple music chart not reachable'; if (!$pods) $notes[] = 'Apple podcast chart not reachable';
    if ($songs && ($ids = implode(',', array_filter(array_column($songs, 'id'))))) {   // 30-second previews
        $lu = "$it/lookup?" . http_build_query(['id' => $ids, 'country' => $cc, 'entity' => 'song']); $r2 = open_fetch_all([$lu], 15); $j = live_json($r2, $lu); $pv = [];
        foreach ($j['results'] ?? [] as $r) if (!empty($r['previewUrl']) && preg_match('#^https://#', (string)$r['previewUrl'])) $pv[(string)($r['trackId'] ?? '')] = (string)$r['previewUrl'];
        foreach ($songs as &$s) $s['preview'] = $pv[$s['id']] ?? null; unset($s);
    }
    $photos = []; foreach (array_keys(LIVE_PHOTO_QUERIES) as $cat) foreach ($get('o' . $cat)['results'] ?? [] as $r) {
        $thumb = (string)($r['thumbnail'] ?? ''); if (!open_img_ok($thumb) || empty($r['creator'])) continue;
        $photos[$cat][] = ['thumb' => $thumb, 'creator' => mb_substr((string)$r['creator'], 0, 60), 'license' => strtoupper((string)($r['license'] ?? '')) . ' ' . ($r['license_version'] ?? ''), 'landing' => (string)($r['foreign_landing_url'] ?? '')];
    }
    if (!$photos) $notes[] = 'Openverse not reachable';
    $videos = []; try { foreach (open_trends_fetch()['items'] as $x) if ($x['kind'] === 'video' && !empty($x['media_url']) && !empty($x['thumb_url'])) $videos[] = $x; } catch (Throwable $e) { $notes[] = 'Mastodon videos not reachable'; }
    return ['songs' => $songs, 'podcasts' => $pods, 'photos' => $photos, 'videos' => $videos, 'notes' => $notes];
}

/** Picks a photo for some text (category by keywords, else rotates through all categories). */
function live_photo(array $cat, string $text, int $i): ?array {
    if (!$cat['photos']) return null; $k = categorize($text); $keys = array_keys($cat['photos']);
    $pool = $cat['photos'][$k] ?? $cat['photos'][$keys[$i % count($keys)]]; return $pool[intdiv($i, 3) % count($pool)];
}

/** Gets pictures in parallel (chunks of 20) and returns url => bytes for those that came back as usable images. */
function live_fetch_images(array $urls): array {
    $urls = array_values(array_filter(array_unique($urls), 'thumb_host_allowed')); $got = [];
    foreach (array_chunk($urls, 20) as $chunk) foreach (open_fetch_all($chunk, 15, 8 * 1024 * 1024) as $u => [$c, $b]) if ($c === 200 && strlen($b) > 200) $got[$u] = $b;
    return $got;
}

/** Spreads items so neighbours come from different topics (F1, music, fashion, ...). */
function live_interleave(array $items): array {
    $by = []; foreach ($items as $it) $by[$it['topic'] ?? '-'][] = $it; $out = [];
    while ($by) foreach (array_keys($by) as $k) { $out[] = array_shift($by[$k]); if (!$by[$k]) unset($by[$k]); }
    return $out;
}

/**
 * Real content for the signed-in user's sample recap: chart songs with artwork and previews (stories), Apple's top podcasts, and current topical
 * videos / pictures / discussions (YouTube + Mastodon hashtags: F1, sport, music, entertainment, fashion, tech, cars...). Sample rows are overwritten with
 * the real item, so platform, author and link are the real ones. If the topical feeds are unreachable, Creative Commons photos are used instead.
 * @return int items changed
 */
function live_media_apply_recap(int $uid, ?array $cat = null): int {
    $cat ??= live_media_catalog(); $db = db(); @set_time_limit(240); $plan = [];
    require_once __DIR__ . '/topic_media.php';
    $tc = cfg('TOPIC_MEDIA') ? topic_catalog() : ['items' => [], 'discussions' => [], 'notes' => []];
    $vids = live_interleave(array_values(array_filter($tc['items'], fn($x) => $x['kind'] === 'video'))); $pics = live_interleave(array_values(array_filter($tc['items'], fn($x) => $x['kind'] === 'image')));
    $discs = live_interleave($tc['discussions']); $topical = count($vids) + count($pics) >= 12;
    // Posts & Reels get most of the videos and a share of the pictures; Stories get the rest, so both tabs are full
    $nv = count($vids); $np = count($pics); $cutV = (int)ceil($nv * 0.75); $cutP = (int)floor($np * 0.3);
    $postPool = live_interleave(array_merge(array_slice($vids, 0, $cutV), array_slice($pics, 0, $cutP))); $storyPool = live_interleave(array_merge(array_slice($pics, $cutP), array_slice($vids, $cutV)));
    $pp = 0; $sp = 0;
    $rows = $db->prepare("SELECT id, kind, title, body, music_title FROM items WHERE user_id=? AND source='demo' AND thumb_url IS NULL ORDER BY id"); $rows->execute([$uid]);
    $si = 0; $pi = 0; $vi = 0; $vi2 = 0; $ci = 0; $di = 0; $n = 0; $used = [];
    $free = function (array &$list, int &$i) use ($db, $uid, &$used) {   // next real item this recap does not hold yet
        while ($i < count($list)) { $x = $list[$i++]; $k = normalize_url($x['url']); if (isset($used[$k])) continue; $q = $db->prepare('SELECT 1 FROM items WHERE user_id=? AND url_key=?'); $q->execute([$uid, $k]); if ($q->fetchColumn()) continue; $used[$k] = 1; return $x; }
        return null;
    };
    foreach ($rows->fetchAll() as $i => $r) {
        $id = (int)$r['id']; $up = []; $img = null; $real = null;
        $song = null;
        if ($r['kind'] === 'story' && $r['music_title'] && $cat['songs'] && $si < count($cat['songs'])) {   // each chart song once; further music stories become topical pictures below
            $song = $cat['songs'][$si++]; $sk = normalize_url($song['url'] ?: 'https://music.apple.com/x/' . $song['id']);
            $x = $db->prepare('SELECT 1 FROM items WHERE user_id=? AND url_key=?'); $x->execute([$uid, $sk]); if ($x->fetchColumn()) continue;
        }
        if ($song) {
            $img = $song['art'];
            $up = ['platform' => 'applemusic', 'url' => $song['url'] ?: 'https://music.apple.com/x/' . $song['id'], 'url_key' => $sk, 'title' => $song['title'], 'author' => $song['artist'], 'music_title' => $song['title'], 'music_artist' => $song['artist'], 'music_source' => 'detected',
                'body' => 'On the charts right now 🎵 ' . $song['title'] . ' - ' . $song['artist']];
            if ($song['preview']) { $up['media_url'] = $song['preview']; $up['media_type'] = 'AUDIO'; }
        } elseif ($r['kind'] === 'podcast' && $pi < count($cat['podcasts'])) {
            $p = $cat['podcasts'][$pi++]; $x = $db->prepare('SELECT 1 FROM items WHERE user_id=? AND url_key=?'); $x->execute([$uid, normalize_url($p['url'])]); if ($x->fetchColumn()) continue;   // that show is already in this recap
            $img = $p['art'];
            $up = ['platform' => 'applepodcasts', 'title' => $p['title'], 'body' => 'Top podcast right now on Apple Podcasts', 'author' => $p['artist'], 'url' => $p['url'], 'url_key' => normalize_url($p['url'])];
        } elseif ($topical && $r['kind'] === 'discussion') { $real = $free($discs, $di);
        } elseif ($topical && in_array($r['kind'], ['story', 'post'], true)) {
            $real = $r['kind'] === 'post' ? $free($postPool, $pp) : $free($storyPool, $sp);
        } elseif ($r['kind'] === 'post' && str_starts_with((string)$r['title'], 'Reel:') && $vi2 < count($cat['videos'])) {   // topical feeds unreachable: trending Mastodon video
            $v = $cat['videos'][$vi2++]; $img = $v['thumb_url'];
            $up = ['media_url' => $v['media_url'], 'media_type' => 'VIDEO', 'body' => mb_substr($v['body'], 0, 200) . ' · Video by ' . $v['author'] . ' on Mastodon: ' . $v['url']];
        } elseif (in_array($r['kind'], ['story', 'post'], true) && ($ph = live_photo($cat, $r['title'] . ' ' . $r['body'], $i))) {   // topical feeds unreachable: CC photo
            $img = $ph['thumb']; $up = ['body' => rtrim((string)$r['body']) . ' · Photo by ' . $ph['creator'] . ' (' . trim($ph['license']) . ') via Openverse' . ($ph['landing'] ? ': ' . $ph['landing'] : '')];
        }
        if ($real) {
            $img = $real['thumb_url'] ?? null; $posted = (int)$real['posted_at'];
            $up = ['music_title' => null, 'music_artist' => null, 'music_source' => null, 'platform' => $real['platform'], 'title' => $real['title'], 'body' => $real['body'], 'author' => $real['author'], 'url' => $real['url'], 'url_key' => normalize_url($real['url']), 'interacted_at' => max($posted, time() - 6 * DAY), 'first_seen_at' => max($posted, time() - 6 * DAY)];
            if (!empty($real['media_url'])) { $up['media_url'] = $real['media_url']; $up['media_type'] = $real['media_type'] ?? 'VIDEO'; }
        }
        if ($img || $real) $plan[$id] = [(string)$img, $up, (bool)$real];
    }
    $bytes = live_fetch_images(array_filter(array_column($plan, 0)));
    foreach ($plan as $id => [$img, $up, $isReal]) {
        if ($img === '') { $up['thumb_path'] = null; $up['thumb_url'] = ''; }   // text-only post: no picture, the app shows its icon tile
        elseif (!isset($bytes[$img]) || !($p = save_thumb_data($uid, $id, str_contains($img, '/vi/') ? yt_crop_16x9($bytes[$img]) : $bytes[$img]))) continue;
        else { $up['thumb_path'] = $p; $up['thumb_url'] = $img; }
        try { $db->prepare('UPDATE items SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($up))) . ' WHERE id=?')->execute(array_merge(array_values($up), [$id])); $n++; } catch (PDOException $e) { error_log('[recap] live media row: ' . $e->getMessage()); }
    }
    // made-up rows (invented shows, links and posts) are dropped once real ones took their place, so every tab holds real content only
    if ($cat['podcasts']) $db->prepare("DELETE FROM items WHERE user_id=? AND source='demo' AND kind='podcast' AND thumb_url IS NULL")->execute([$uid]);
    if ($topical && $n > 10) $db->prepare("DELETE FROM items WHERE user_id=? AND source='demo' AND kind IN ('story','post','discussion') AND thumb_url IS NULL AND NOT (kind='discussion' AND ? = 0)")->execute([$uid, count($discs)]);
    return $n;
}

/** Real photos for the fictional Trending posts (so the cards look right next to the real Mastodon/Bluesky ones). */
function live_media_apply_trending(?array $cat = null): int {
    $cat ??= live_media_catalog(); if (!$cat['photos']) return 0; $db = db(); @set_time_limit(180); $plan = []; $n = 0;
    foreach ($db->query("SELECT id, title, body, category FROM content WHERE source='demo' AND thumb_url IS NULL ORDER BY id")->fetchAll() as $i => $r)
        if ($ph = live_photo($cat, ($r['category'] ?? '') . ' ' . $r['title'] . ' ' . $r['body'], $i)) $plan[(int)$r['id']] = $ph['thumb'];
    $bytes = live_fetch_images(array_values($plan));
    foreach ($plan as $id => $u) if (isset($bytes[$u]) && ($p = save_thumb_data('c', $id, $bytes[$u]))) { $db->prepare('UPDATE content SET thumb_path=?, thumb_url=? WHERE id=?')->execute([$p, $u, $id]); $n++; }
    return $n;
}
