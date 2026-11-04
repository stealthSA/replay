<?php
declare(strict_types=1);
/**
 * Real public trending posts from open networks, for the Trending tab. No login, no scraping:
 *   Mastodon : GET https://<instance>/api/v1/trends/statuses       (public, documented endpoint)
 *   Bluesky  : GET https://public.api.bsky.app/xrpc/app.bsky.feed.getFeed  with the public "What's Hot" feed
 * Only short text (max 280 chars), the author's name and handle, a link back to the original post, a small picture and the
 * public counts are stored. Every card links to the original so credit and context stay with the author.
 * Posts are saved as owner-less public content (source "import"), so they are ranked but can never receive ad offers.
 */
require_once __DIR__ . '/engage.php';
require_once __DIR__ . '/engage_api.php';   # import_one()

const BSKY_WHATS_HOT = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot';

/** Instance base URLs from config. A bare host means https; http is accepted only for loopback (local tests). */
function open_mastodon_bases(): array {
    $o = [];
    foreach (preg_split('/[\s,]+/', (string)cfg('OPEN_TRENDS_MASTODON'), -1, PREG_SPLIT_NO_EMPTY) as $x) {
        $u = preg_match('#^https?://#i', $x) ? $x : 'https://' . $x; $p = parse_url($u); $h = strtolower($p['host'] ?? '');
        if ($h === '' || (($p['scheme'] ?? '') === 'http' && !in_array($h, ['127.0.0.1', 'localhost', '::1'], true))) continue;
        $o[] = ($p['scheme'] ?? 'https') . '://' . $h . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    return array_values(array_unique($o));
}
function open_bluesky_base(): string {
    $b = rtrim((string)cfg('OPEN_TRENDS_BLUESKY'), '/'); if ($b === '') return '';
    $p = parse_url($b); $h = strtolower($p['host'] ?? '');
    return ($h !== '' && (($p['scheme'] ?? '') === 'https' || in_array($h, ['127.0.0.1', 'localhost'], true))) ? $b : '';
}
/** Hosts whose pictures may be copied into storage (the configured instances, Bluesky's CDN). */
function open_thumb_hosts(): array {
    $h = ['bsky.app', 'bsky.network', 'mzstatic.com', 'api.openverse.org', 'ytimg.com'];   // + Apple artwork and Openverse thumbnails (live_media.php)
    foreach (open_mastodon_bases() as $b) $h[] = strtolower((string)parse_url($b, PHP_URL_HOST));
    return array_values(array_unique($h));
}

/** GET several URLs at once. Returns url => [httpCode, body]. A failed transfer has code 0. */
function open_fetch_all(array $urls, int $timeout = 10, int $maxBytes = 6 * 1024 * 1024, array $headers = [], bool $follow = false): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_USERAGENT => 'CurrentRecap/1.0 (public trending reader)', CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, image/*, text/xml, text/html'], $headers), CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if ($follow) {   // YouTube answers with 302s (www / consent page); follow a few, https only, and keep the consent cookie for youtube.com
            curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_COOKIEFILE => '']);
            foreach (['CONSENT=YES+cb.20210328-17-p0.en+FX+917', 'SOCS=CAI'] as $ck) curl_setopt($ch, CURLOPT_COOKIELIST, 'Set-Cookie: ' . $ck . '; Domain=.youtube.com; Path=/; Secure');
        }
        curl_multi_add_handle($mh, $ch); $hs[$u] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $u => $ch) { $b = curl_multi_getcontent($ch); $out[$u] = [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_string($b) ? $b : '']; curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    return $out;
}

function open_clean_text(string $s): string {
    $s = preg_replace('#<br\s*/?>|</p>\s*<p[^>]*>#i', "\n", $s); $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n{3,}/", "\n\n", $s)) ?? '');
    return mb_strlen($s) > 280 ? rtrim(mb_substr($s, 0, 279)) . '…' : $s;
}
/** Pictures must be https (http only for the loopback test server). */
function open_img_ok(mixed $u): bool { return is_string($u) && (preg_match('#^https://#i', $u) || preg_match('#^http://(127\.0\.0\.1|localhost)[:/]#i', $u)); }
function open_ts(mixed $v): int { $t = is_string($v) ? (strtotime($v) ?: 0) : 0; return $t > 0 && $t <= time() + 300 ? $t : time(); }
function open_author(string $name, string $handle): string { $name = trim(preg_replace('/:[a-z0-9_]+:/i', '', $name) ?? ''); return mb_substr($name !== '' ? ($handle !== '' ? "$name ($handle)" : $name) : $handle, 0, 80); }

/** One Mastodon status (as returned by /api/v1/trends/statuses) -> import item, or null to skip it. */
function open_parse_mastodon(array $s, string $instance): ?array {
    if (!empty($s['sensitive']) || !empty($s['spoiler_text']) || !empty($s['reblog'])) return null;
    $url = (string)($s['url'] ?? ''); if (!preg_match('#^https://#i', $url)) return null;
    $text = open_clean_text((string)($s['content'] ?? '')); $media = is_array($s['media_attachments'] ?? null) ? $s['media_attachments'] : [];
    if ($text === '' && !$media) return null;
    $kind = 'text'; $thumb = null; $video = null;
    foreach ($media as $m) {
        $t = (string)($m['type'] ?? ''); $k = in_array($t, ['video', 'gifv'], true) ? 'video' : ($t === 'image' ? 'image' : null); if (!$k) continue;
        if ($kind === 'text' || $k === 'video') $kind = $k;
        if ($k === 'video' && !$video && preg_match('#^https://#i', (string)($m['url'] ?? ''))) $video = (string)$m['url'];   // mp4 / webm: plays in the post details
        if (!$thumb && open_img_ok($m['preview_url'] ?? null)) $thumb = (string)$m['preview_url'];
    }
    $acct = (string)($s['account']['acct'] ?? ''); if ($acct === '') return null;
    $handle = '@' . (str_contains($acct, '@') ? $acct : $acct . '@' . (parse_url($instance, PHP_URL_HOST) ?: 'mastodon'));
    return ['platform' => 'mastodon', 'external_id' => mb_substr((string)($s['uri'] ?? $url), 0, 300), 'url' => $url, 'kind' => $kind, 'author' => open_author((string)($s['account']['display_name'] ?? ''), $handle),
        'title' => mb_substr($text, 0, 120), 'body' => $text, 'posted_at' => open_ts($s['created_at'] ?? null), 'thumb_url' => $thumb, 'media_url' => $kind === 'video' ? $video : null,
        'metrics' => ['likes' => (int)($s['favourites_count'] ?? 0), 'comments' => (int)($s['replies_count'] ?? 0), 'shares' => (int)($s['reblogs_count'] ?? 0)]];
}

/** One Bluesky postView (from app.bsky.feed.getFeed) -> import item, or null to skip it. */
function open_parse_bluesky(array $p): ?array {
    $uri = (string)($p['uri'] ?? ''); $h = (string)($p['author']['handle'] ?? ''); $rec = $p['record'] ?? [];
    if (!preg_match('#^at://[^/]+/app\.bsky\.feed\.post/([A-Za-z0-9]+)$#', $uri, $m) || $h === '' || !is_array($rec)) return null;
    foreach (($p['labels'] ?? []) as $l) if (in_array($l['val'] ?? '', ['porn', 'sexual', 'nudity', 'graphic-media', 'gore', '!hide', '!warn'], true)) return null;
    $text = open_clean_text((string)($rec['text'] ?? '')); $e = $p['embed'] ?? []; $t = (string)($e['$type'] ?? ''); $kind = 'text'; $thumb = null;
    if (str_starts_with($t, 'app.bsky.embed.images')) { $kind = 'image'; $thumb = $e['images'][0]['thumb'] ?? null; }
    elseif (str_starts_with($t, 'app.bsky.embed.video')) { $kind = 'video'; $thumb = $e['thumbnail'] ?? null; }
    elseif (str_starts_with($t, 'app.bsky.embed.recordWithMedia')) { $mt = (string)($e['media']['$type'] ?? ''); if (str_starts_with($mt, 'app.bsky.embed.images')) { $kind = 'image'; $thumb = $e['media']['images'][0]['thumb'] ?? null; } elseif (str_starts_with($mt, 'app.bsky.embed.video')) { $kind = 'video'; $thumb = $e['media']['thumbnail'] ?? null; } }
    if ($text === '' && $kind === 'text') return null;
    return ['platform' => 'bluesky', 'external_id' => $uri, 'url' => 'https://bsky.app/profile/' . $h . '/post/' . $m[1], 'kind' => $kind, 'author' => open_author((string)($p['author']['displayName'] ?? ''), '@' . $h),
        'title' => mb_substr($text, 0, 120), 'body' => $text, 'posted_at' => open_ts($rec['createdAt'] ?? ($p['indexedAt'] ?? null)), 'thumb_url' => open_img_ok($thumb) ? $thumb : null,
        'metrics' => ['likes' => (int)($p['likeCount'] ?? 0), 'comments' => (int)($p['replyCount'] ?? 0), 'shares' => (int)($p['repostCount'] ?? 0) + (int)($p['quoteCount'] ?? 0)]];
}

/** Downloads the current public trending posts. Returns ['items' => [...], 'notes' => [...]] (notes = what could not be reached). */
function open_trends_fetch(): array {
    $per = max(1, min(40, (int)cfg('OPEN_TRENDS_PER_SOURCE'))); $reqs = []; $notes = [];
    foreach (open_mastodon_bases() as $b) $reqs[$b . '/api/v1/trends/statuses?limit=' . min(40, $per * 2)] = ['mastodon', $b];
    if (($bs = open_bluesky_base()) !== '') $reqs[$bs . '/xrpc/app.bsky.feed.getFeed?feed=' . rawurlencode(BSKY_WHATS_HOT) . '&limit=' . min(100, $per * 3)] = ['bluesky', $bs];
    $res = open_fetch_all(array_keys($reqs)); $items = []; $seen = [];
    foreach ($reqs as $url => [$net, $base]) {
        [$code, $body] = $res[$url]; $j = json_decode($body, true); $label = ($net === 'mastodon' ? '' : 'Bluesky ') . (parse_url($base, PHP_URL_HOST) ?: $base);
        if ($code !== 200 || !is_array($j)) { $notes[] = "$label: " . ($code ? "HTTP $code" : 'not reachable'); continue; }
        $list = $net === 'mastodon' ? $j : array_map(fn($x) => $x['post'] ?? null, $j['feed'] ?? []); $n = 0;
        foreach ($list as $x) {
            if (!is_array($x) || $n >= $per) continue;
            $it = $net === 'mastodon' ? open_parse_mastodon($x, $base) : open_parse_bluesky($x);
            if (!$it || isset($seen[$it['url']])) continue;
            $seen[$it['url']] = 1; $items[] = $it; $n++;
        }
        if ($n === 0) $notes[] = "$label: no usable posts";
    }
    return ['items' => $items, 'notes' => $notes];
}

/** Stores fetched items (import_one) and copies their pictures in parallel. @return int saved */
function open_store_items(array $items): int {
    $db = db(); $n = 0; $thumbs = [];
    foreach ($items as $it) {
        $th = $it['thumb_url'] ?? null; unset($it['thumb_url']); $topic = $it['topic'] ?? null; unset($it['topic']);
        if (!import_one($it)) continue;
        $n++;
        $s = $db->prepare('SELECT id, thumb_path FROM content WHERE platform=? AND external_id=?'); $s->execute([$it['platform'], $it['external_id']]); $row = $s->fetch(); if (!$row) continue;
        if ($topic && in_array($topic, categories(), true)) {   // the feed's topic decides the category (F1 channel -> motorsport), whatever the caption says
            $db->prepare('UPDATE content SET category=? WHERE id=?')->execute([$topic, $row['id']]);
            $db->prepare('INSERT OR IGNORE INTO content_topics (content_id, topic, kind) VALUES (?,?,?)')->execute([$row['id'], $topic, 'category']);
        }
        if ($th && !$row['thumb_path'] && thumb_host_allowed($th)) $thumbs[(int)$row['id']] = $th;
    }
    if ($thumbs) {   // pictures are copied in parallel, so slow servers do not add up
        $uniq = array_values(array_unique($thumbs)); $got = [];
        foreach (array_chunk($uniq, 20) as $chunk) foreach (open_fetch_all($chunk, 10, 8 * 1024 * 1024) as $u => $r) $got[$u] = $r;
        foreach ($thumbs as $id => $u) {
            [$c, $b] = $got[$u] ?? [0, '']; if ($c !== 200) continue;
            if (str_contains($u, '/vi/')) $b = yt_crop_16x9($b);
            $p = save_thumb_data('c', $id, $b); if ($p) $db->prepare('UPDATE content SET thumb_url=?, thumb_path=? WHERE id=?')->execute([$u, $p, $id]);
        }
    }
    return $n;
}
/** YouTube's hqdefault picture is 4:3 with black bars above and below a 16:9 frame; crop them off. */
function yt_crop_16x9(string $b): string {
    $im = @imagecreatefromstring($b); if (!$im) return $b; $w = imagesx($im); $h = imagesy($im); $nh = (int)round($w * 9 / 16);
    if ($nh >= $h - 4) return $b; $c = imagecrop($im, ['x' => 0, 'y' => (int)(($h - $nh) / 2), 'width' => $w, 'height' => $nh]); if (!$c) return $b;
    ob_start(); imagejpeg($c, null, 88); return (string)ob_get_clean();
}

/** Fetches and stores them (re-running refreshes the counts, which feeds the growth model). Returns [saved, notes]. */
function open_trends_load(): array {
    @set_time_limit(120);
    $r = open_trends_fetch(); $notes = $r['notes'];
    if (cfg('OPEN_TRENDS_TOPICS_ONLY')) {   // keep only general posts that are clearly about the priority topics, and tag them with that topic
        $want = array_filter(array_map('trim', explode(',', (string)cfg('TOPIC_PRIORITY')))); $keep = [];
        foreach ($r['items'] as $it) { $c = categorize($it['title'] . ' ' . $it['body']); if ($c && in_array($c, $want, true)) { $it['topic'] = $c; $keep[] = $it; } }
        if (!$keep) $notes[] = 'no general trending post matched your topics'; $r['items'] = $keep;
    }
    $n = open_store_items($r['items']);
    if ($n) trend_run();
    return [$n, $notes];
}

/** Called when Trending is opened: reloads the public posts if the last load is older than OPEN_TRENDS_AUTO_MINUTES. Never throws; one visitor at a time. */
function open_trends_auto(): void {
    $min = (int)cfg('OPEN_TRENDS_AUTO_MINUTES'); if (!cfg('OPEN_TRENDS') || $min <= 0) return;
    $db = db(); $s = $db->prepare("SELECT v FROM kv WHERE k='open_trends_last'"); $s->execute(); $last = (int)$s->fetchColumn();
    if (time() - $last < $min * 60) return;
    $db->prepare("INSERT INTO kv (k, v) VALUES ('open_trends_last', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([(string)time()]);   // claim first, so a slow or failed fetch is not retried on every page view
    try { open_trends_load(); } catch (Throwable $t) { error_log('[recap] open trends auto: ' . $t->getMessage()); }
}
