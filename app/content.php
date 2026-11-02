<?php
declare(strict_types=1);
/** Content model: platforms, classification, music detection, thumbnails, item upsert. */

const KINDS = ['story', 'podcast', 'discussion', 'post'];
const PLATFORMS = [
    'facebook'  => ['name' => 'Facebook',  'hosts' => ['facebook.com', 'fb.com', 'fb.watch', 'fb.me']],
    'instagram' => ['name' => 'Instagram', 'hosts' => ['instagram.com', 'instagr.am']],
    'threads'   => ['name' => 'Threads',   'hosts' => ['threads.net', 'threads.com']],
];
/** Open networks whose public trending feeds can be read without a login (see app/open_trends.php). Only Trending uses these; the personal recap stays Meta-only. */
const OPEN_PLATFORMS = [
    'mastodon' => ['name' => 'Mastodon', 'hosts' => []],
    'bluesky'  => ['name' => 'Bluesky',  'hosts' => ['bsky.app']],
    'youtube'  => ['name' => 'YouTube',  'hosts' => ['youtube.com', 'youtu.be']],
    'applemusic'    => ['name' => 'Apple Music',    'hosts' => ['music.apple.com']],
    'applepodcasts' => ['name' => 'Apple Podcasts', 'hosts' => ['podcasts.apple.com']],
];
/** Every platform label Trending can show: the three Meta ones plus the open networks. */
function trend_platforms(): array { return PLATFORMS + OPEN_PLATFORMS; }
const PODCAST_HOSTS = ['podcasts.apple.com', 'anchor.fm', 'podcasters.spotify.com', 'pca.st', 'overcast.fm', 'castbox.fm', 'podbean.com',
    'buzzsprout.com', 'megaphone.fm', 'simplecast.com', 'acast.com', 'podcastaddict.com', 'player.fm', 'stitcher.com', 'iheart.com', 'podtail.com'];

function host_of(string $url): string { return preg_replace('/^(www|m|mobile|web|l)\./', '', strtolower((string)parse_url($url, PHP_URL_HOST))); }
function host_matches(string $host, array $list): bool { foreach ($list as $h) if ($host === $h || str_ends_with($host, '.' . $h)) return true; return false; }

function detect_platform(string $url): ?string {
    $h = host_of($url); if ($h === '') return null;
    foreach (PLATFORMS as $id => $p) if (host_matches($h, $p['hosts'])) return $id;
    return null;
}

function normalize_url(string $url): string {
    $p = parse_url($url); if (!$p || empty($p['host'])) return $url;
    $q = [];
    if (!empty($p['query'])) { parse_str($p['query'], $q); foreach (array_keys($q) as $k) if (preg_match('/^(utm_|fbclid|igsh|igshid|mibextid|ref$|__|xmt|slof)/i', (string)$k)) unset($q[$k]); }
    $out = 'https://' . preg_replace('/^(www|m|mobile|web)\./', '', strtolower($p['host'])) . (rtrim($p['path'] ?? '/', '/') ?: '/');
    return $q ? $out . '?' . http_build_query($q) : $out;
}

/** Does this text/URL look like a podcast episode? */
function looks_like_podcast(string $url, string $text = ''): bool {
    $h = host_of($url); $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    if ($h === 'open.spotify.com' && preg_match('#^/(episode|show)/#', $path)) return true;
    if (host_matches($h, PODCAST_HOSTS)) return true;
    return (bool)preg_match('/#podcast\b|\bpodcast episode\b|\bnew episode\b.*\b(podcast|listen)\b|\bon the podcast\b/i', $text);
}

/** Category for a link (used for share / paste / extension where only the URL is known). */
function classify_url(string $url, string $platform, string $text = ''): string {
    $u = strtolower($url);
    if (looks_like_podcast($url, $text)) return 'podcast';
    if (preg_match('#/stories?/#', $u)) return 'story';
    if ($platform === 'threads') return 'discussion';
    if ($platform === 'facebook' && preg_match('#/groups/|/comment|[?&]comment_id=#', $u)) return 'discussion';
    if (preg_match('#/(reel|reels|tv|watch|videos?|photo|p)/#', $u) || str_contains($u, 'fb.watch')) return 'post';
    if ($platform === 'facebook' && preg_match('#/posts/|permalink|story_fbid#', $u)) return 'discussion';
    return 'post';
}

/**
 * Looks for a song in a caption: "🎵 Song – Artist", "♪ Artist - Song", "Now playing: …", "Music: …".
 * Returns [title, artist] or null. The order after the marker is a best guess, so the UI lets users swap it.
 */
function parse_music(?string $text): ?array {
    if (!$text) return null;
    if (!preg_match('/(?:[\x{1F3B5}\x{1F3B6}\x{266A}\x{266B}]|\b(?:now playing|music|song|listening to|track)\s*[:\-])\s*[“"\']?([^\n\r#@]{3,120})/iu', $text, $m)) return null;
    $line = trim($m[1], " \t\"'“”.");
    if ($line === '') return null;
    $parts = preg_split('/\s+(?:[-–—•·]|by)\s+/iu', $line, 2);
    if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') return [mb_substr(trim($parts[0]), 0, 120), mb_substr(trim($parts[1]), 0, 120)];
    return [mb_substr($line, 0, 120), ''];
}

function song_key(string $title, string $artist): string { return mb_strtolower(preg_replace('/\s+/u', ' ', trim($title))) . '|' . mb_strtolower(preg_replace('/\s+/u', ' ', trim($artist))); }

/* ---------------------------------------------------------------- thumbs */
/** Meta's CDN links expire within days, so the picture is copied to our storage while it is still valid. */
function thumb_host_allowed(string $url): bool {
    $p = parse_url($url); if (!$p || empty($p['host'])) return false;
    $mock = (string)cfg('META_MOCK_BASE');
    if ($mock !== '' && ($p['host'] ?? '') === (parse_url($mock, PHP_URL_HOST) ?: '')) return true;
    if (($p['scheme'] ?? '') !== 'https') return false;
    $h = strtolower($p['host']);
    return host_matches($h, ['fbcdn.net', 'cdninstagram.com', 'facebook.com', 'fbsbx.com', 'instagram.com', 'threads.net', 'threads.com']) || host_matches($h, open_thumb_hosts());
}

function cache_thumb(int|string $uid, int $itemId, string $url): ?string {
    if (!thumb_host_allowed($url) || !function_exists('imagecreatefromstring')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_MAXFILESIZE => 8 * 1024 * 1024, CURLOPT_USERAGENT => 'CurrentRecap/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    $data = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if (!is_string($data) || $code !== 200) return null;
    return save_thumb_data($uid, $itemId, $data);
}

/** Resizes downloaded picture bytes and stores them as the thumbnail. Returns the relative path, or null if it is not a usable image. */
function save_thumb_data(int|string $uid, int $itemId, string $data): ?string {
    if (strlen($data) < 200 || !function_exists('imagecreatefromstring')) return null;
    $img = @imagecreatefromstring($data); if (!$img) return null;
    $w = imagesx($img); $h = imagesy($img); $max = 640;
    if ($w > $max || $h > $max) { $s = $max / max($w, $h); $n = imagecreatetruecolor((int)round($w * $s), (int)round($h * $s)); imagecopyresampled($n, $img, 0, 0, 0, 0, imagesx($n), imagesy($n), $w, $h); $img = $n; }
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $uid; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rel = $uid . '/' . $itemId . '.jpg';
    $ok = imagejpeg($img, rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $rel, 82);
    return $ok ? $rel : null;
}

/* ---------------------------------------------------------------- upsert */
/**
 * Inserts or updates one item. $it keys: platform, kind, source, external_id, url, title, body, author, thumb_url, media_url,
 * media_type, music_title, music_artist, interaction, interacted_at. Returns [id, isNew].
 */
function upsert_item(int $uid, array $it): array {
    $db = db(); $now = time();
    $url = (string)($it['url'] ?? ''); $key = $url !== '' ? normalize_url($url) : null;
    $ext = $it['external_id'] ?? null;
    $row = null;
    if ($ext !== null) { $s = $db->prepare('SELECT * FROM items WHERE user_id=? AND platform=? AND external_id=?'); $s->execute([$uid, $it['platform'], $ext]); $row = $s->fetch() ?: null; }
    if (!$row && $key) { $s = $db->prepare('SELECT * FROM items WHERE user_id=? AND url_key=?'); $s->execute([$uid, $key]); $row = $s->fetch() ?: null; }

    $title = isset($it['title']) ? mb_substr(trim((string)$it['title']), 0, 200) : null;
    $body = isset($it['body']) ? mb_substr(trim((string)$it['body']), 0, 4000) : null;
    $music = [$it['music_title'] ?? null, $it['music_artist'] ?? null]; $musicSrc = !empty($it['music_title']) ? ($it['music_source'] ?? 'user') : null;
    if (!$music[0]) { $d = parse_music($body ?: $title); if ($d) { $music = $d; $musicSrc = 'detected'; } }

    if ($row) {
        $viewGap = ($it['source'] ?? '') === 'api' ? 0 : ($now - (int)$row['interacted_at'] >= 1800 ? 1 : 0);
        $sets = ['view_count = view_count + ?', 'dwell_seconds = MIN(86400, dwell_seconds + ?)']; $args = [$viewGap, max(0, (int)($it['dwell'] ?? 0))];
        if (($it['source'] ?? '') !== 'api') { $sets[] = 'interacted_at = ?'; $args[] = $now; }
        foreach (['title' => $title, 'body' => $body, 'author' => $it['author'] ?? null, 'thumb_url' => $it['thumb_url'] ?? null, 'media_url' => $it['media_url'] ?? null, 'media_type' => $it['media_type'] ?? null] as $c => $v) {
            if ($v !== null && $v !== '') { $sets[] = "$c = ?"; $args[] = $v; }
        }
        if ($music[0] && $row['music_source'] !== 'user') { array_push($sets, 'music_title = ?', 'music_artist = ?', 'music_source = ?'); array_push($args, $music[0], $music[1] ?: null, $musicSrc); }
        $args[] = $row['id'];
        $db->prepare('UPDATE items SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        $id = (int)$row['id']; $isNew = false;
    } else {
        $kind = in_array($it['kind'] ?? '', KINDS, true) ? $it['kind'] : classify_url($url, $it['platform'], (string)($body ?? $title));
        $at = (int)($it['interacted_at'] ?? $now);
        $db->prepare('INSERT INTO items (user_id, platform, kind, source, external_id, url, url_key, title, body, author, thumb_url, media_url, media_type,
                      music_title, music_artist, music_source, interaction, interacted_at, first_seen_at, dwell_seconds) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $it['platform'], $kind, $it['source'] ?? 'manual', $ext, $url ?: null, $key, $title ?: null, $body ?: null, $it['author'] ?? null,
                      $it['thumb_url'] ?? null, $it['media_url'] ?? null, $it['media_type'] ?? null, $music[0], $music[1] ?: null, $musicSrc,
                      in_array($it['interaction'] ?? '', ['posted', 'viewed', 'replied', 'shared'], true) ? $it['interaction'] : 'viewed', $at, $now, max(0, (int)($it['dwell'] ?? 0))]);
        $id = (int)$db->lastInsertId(); $isNew = true;
    }
    // Copy the picture locally once (best effort).
    $r = $db->prepare('SELECT thumb_url, thumb_path FROM items WHERE id=?'); $r->execute([$id]); $cur = $r->fetch();
    if ($cur && $cur['thumb_url'] && !$cur['thumb_path']) {
        $p = cache_thumb($uid, $id, $cur['thumb_url']);
        if ($p) $db->prepare('UPDATE items SET thumb_path=? WHERE id=?')->execute([$p, $id]);
    }
    return [$id, $isNew];
}

function item_public(array $r): array {
    return [
        'id' => (int)$r['id'], 'platform' => $r['platform'], 'kind' => $r['kind'], 'source' => $r['source'], 'url' => $r['url'],
        'title' => $r['title'], 'body' => $r['body'], 'author' => $r['author'],
        'thumb' => $r['thumb_path'] ? 'thumb.php?id=' . $r['id'] : null,
        'media_url' => $r['media_url'], 'media_type' => $r['media_type'],
        'music' => $r['music_title'] ? ['title' => $r['music_title'], 'artist' => $r['music_artist'] ?? '', 'source' => $r['music_source']] : null,
        'interaction' => $r['interaction'], 'at' => (int)$r['interacted_at'], 'views' => (int)$r['view_count'], 'pinned' => (bool)$r['pinned'],
        'saved_song' => isset($r['saved_song']) ? (bool)$r['saved_song'] : false,
    ];
}
