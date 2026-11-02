<?php
declare(strict_types=1);
/**
 * Engagement + trend models.
 *
 *  1. Engagement score E per content type (video / image / text), weights as specified for this product:
 *       video : watch time, views, shares, downloads
 *       image : likes, downloads, comments
 *       text  : comments (primary), likes, shares
 *     Each metric is log-damped (ln(1+x)) so one huge number cannot swamp the rest. A metric the platform does not
 *     provide (for example downloads) is left out and the remaining weights are re-normalised, never counted as zero.
 *  2. Velocity V: how fast E grew over the last ~24 h (from stored snapshots), or the lifetime rate if too young.
 *  3. Trend score T = E x (1 + 0.5 ln(1 + 24 V)) x 0.5^(age / 72 h). Percentile of T inside the sharing pool is shown to users.
 *  4. Breakout = E / the author's usual E (median of their other posts). Drives "My circle suggestions".
 *  5. Topic model: hashtags, brands, categories and recurring two-word terms are extracted from the text; a topic "surges"
 *     when its last-24 h engagement is well above its daily average of the previous week (needs 2+ different authors).
 */
require_once __DIR__ . '/content.php';

const ENG_KINDS = ['video', 'image', 'text'];
const ENG_WEIGHTS = [
    'video' => ['watch' => 0.35, 'views' => 0.25, 'shares' => 0.25, 'downloads' => 0.15],
    'image' => ['likes' => 0.45, 'downloads' => 0.30, 'comments' => 0.25],
    'text'  => ['comments' => 0.55, 'likes' => 0.30, 'shares' => 0.15],
];
const HOUR = 3600;
const DAY = 86400;

function lexicon(): array { static $l = null; return $l ??= require __DIR__ . '/lexicon.php'; }
function categories(): array { return array_keys(lexicon()['categories']); }

/* ================================================================= scoring */

/** Raw metric columns -> the six inputs the model uses. Watch time is in minutes. Unknown stays null. */
function eng_inputs(array $m): array {
    $n = fn($k) => isset($m[$k]) && $m[$k] !== '' && $m[$k] !== null ? max(0.0, (float)$m[$k]) : null;
    $dl = $n('downloads') ?? $n('saves');            // Instagram "saved" stands in for downloads when downloads are not reported
    $ws = $n('watch_seconds');
    return ['views' => $n('views'), 'watch' => $ws === null ? null : $ws / 60, 'likes' => $n('likes'), 'comments' => $n('comments'), 'shares' => $n('shares'), 'downloads' => $dl];
}

function engagement_score(string $kind, array $m): float {
    $w = ENG_WEIGHTS[$kind] ?? ENG_WEIGHTS['text']; $in = eng_inputs($m);
    $sum = 0.0; $wt = 0.0;
    foreach ($w as $k => $weight) { if ($in[$k] === null) continue; $sum += $weight * log(1 + $in[$k]); $wt += $weight; }
    return $wt > 0 ? $sum / $wt : 0.0;
}

/** @param array<int,array{at:int,e:float}> $snaps oldest first */
function velocity_score(float $e, array $snaps, int $now, int $postedAt): float {
    $then = null;
    foreach ($snaps as $s) { if ($s['at'] >= $now - DAY && $s['at'] <= $now - HOUR) { $then = $s; break; } }
    if ($then) return max(0.0, $e - $then['e']) / (($now - $then['at']) / HOUR);
    return $e / max(1.0, ($now - $postedAt) / HOUR);       // too young for a 24 h comparison: lifetime rate
}

function trend_score(float $e, float $v, int $now, int $postedAt): float {
    $age = max(0.0, ($now - $postedAt) / HOUR);
    return $e * (1 + 0.5 * log(1 + 24 * max(0.0, $v))) * pow(0.5, $age / 72);
}

function median(array $xs): float {
    $xs = array_values(array_map('floatval', $xs)); sort($xs); $n = count($xs);
    if (!$n) return 0.0; $m = intdiv($n, 2);
    return $n % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
}
function breakout_ratio(float $e, ?float $baseline): float { return $e / max(1.0, (float)($baseline ?? 1.0)); }

/* ================================================================== topics */

function phrase_in(string $hay, string $phrase): bool {
    $phrase = trim(mb_strtolower($phrase)); if ($phrase === '') return false;
    return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u', $hay);
}
function content_text(array $c): string { return mb_strtolower(trim((string)($c['title'] ?? '') . ' ' . (string)($c['body'] ?? ''))); }

/** @return array<int,array{topic:string,kind:string}> */
function extract_topics(string $text): array {
    $t = mb_strtolower(preg_replace('#https?://\S+#i', ' ', $text)); $lx = lexicon(); $out = [];
    if (preg_match_all('/#(\p{L}[\p{L}\p{N}_]{1,40})/u', $t, $m)) foreach (array_unique($m[1]) as $h) $out["h:$h"] = ['topic' => $h, 'kind' => 'hashtag'];
    foreach ($lx['brands'] as $brand => $spell) { foreach ($spell as $s) if (phrase_in($t, $s)) { $out['b:' . mb_strtolower($brand)] = ['topic' => $brand, 'kind' => 'brand']; break; } }
    $cat = categorize($t); if ($cat) $out["c:$cat"] = ['topic' => $cat, 'kind' => 'category'];
    $stop = array_flip($lx['stop']); $plain = preg_replace('/[#@]\S+/u', ' ', $t);
    preg_match_all('/[\p{L}][\p{L}\p{N}\'-]{2,}/u', $plain, $w); $w = $w[0]; $terms = [];
    for ($i = 0; $i + 1 < count($w); $i++) {
        if (isset($stop[$w[$i]]) || isset($stop[$w[$i + 1]])) continue;
        $terms[$w[$i] . ' ' . $w[$i + 1]] = true;
    }
    foreach (array_slice(array_keys($terms), 0, 6) as $term) $out["t:$term"] = ['topic' => $term, 'kind' => 'term'];
    return array_slice(array_values($out), 0, 16);
}

function categorize(string $text): ?string {
    $t = mb_strtolower($text); $best = null; $bestN = 0;
    foreach (lexicon()['categories'] as $cat => $kws) {
        $n = 0; foreach ($kws as $k) if (phrase_in($t, $k)) $n++;
        if ($n > $bestN) { $best = $cat; $bestN = $n; }
    }
    return $best;
}

function brand_of_text(string $text): ?string { foreach (extract_topics($text) as $t) if ($t['kind'] === 'brand') return $t['topic']; return null; }

/* ============================================================ content store */

function eng_metrics_from_row(array $r): array {
    return ['views' => $r['m_views'], 'watch_seconds' => $r['m_watch_seconds'], 'likes' => $r['m_likes'], 'comments' => $r['m_comments'], 'shares' => $r['m_shares'], 'downloads' => $r['m_downloads'], 'saves' => $r['m_saves']];
}

function cache_content_thumb(int $id, string $url): ?string { return cache_thumb('c', $id, $url); }

function add_snapshot(int $cid, int $at, float $e): void {
    $db = db(); $s = $db->prepare('SELECT at, engagement FROM content_metrics WHERE content_id=? ORDER BY at DESC LIMIT 1'); $s->execute([$cid]); $last = $s->fetch();
    if ($last && $at - (int)$last['at'] < 20 * 60 && abs((float)$last['engagement'] - $e) < 0.0001) return;
    if ($last && $at - (int)$last['at'] < 5 * 60) return;
    $db->prepare('INSERT INTO content_metrics (content_id, at, engagement) VALUES (?,?,?)')->execute([$cid, $at, $e]);
}

/**
 * Inserts or updates a piece of content with its metrics.
 * keys: platform, external_id, url, kind(video|image|text), author, title, body, thumb_url, posted_at, source(api|import|demo),
 *       owner_user_id|null, m => [views, watch_seconds, likes, comments, shares, downloads, saves]
 * @return array{0:int,1:bool} [id, isNew]
 */
function upsert_content(array $c): array {
    $db = db(); $now = (int)($c['now'] ?? time());
    $kind = in_array($c['kind'] ?? '', ENG_KINDS, true) ? $c['kind'] : 'text';
    $url = (string)($c['url'] ?? ''); $key = $url !== '' ? normalize_url($url) : null; $ext = $c['external_id'] ?? null; $plat = (string)$c['platform'];
    $row = null;
    if ($ext !== null) { $s = $db->prepare('SELECT * FROM content WHERE platform=? AND external_id=?'); $s->execute([$plat, $ext]); $row = $s->fetch() ?: null; }
    if (!$row && $key) { $s = $db->prepare('SELECT * FROM content WHERE url_key=?'); $s->execute([$key]); $row = $s->fetch() ?: null; }
    $m = $c['m'] ?? []; $title = mb_substr(trim((string)($c['title'] ?? '')), 0, 200); $body = mb_substr(trim((string)($c['body'] ?? '')), 0, 4000);
    $e = engagement_score($kind, $m);
    $cols = ['m_views' => 'views', 'm_watch_seconds' => 'watch_seconds', 'm_likes' => 'likes', 'm_comments' => 'comments', 'm_shares' => 'shares', 'm_downloads' => 'downloads', 'm_saves' => 'saves'];
    $vals = []; foreach ($cols as $col => $k) $vals[$col] = isset($m[$k]) && $m[$k] !== null && $m[$k] !== '' ? max(0, (int)$m[$k]) : null;
    $posted = (int)($c['posted_at'] ?? $now);
    if ($row) {
        $id = (int)$row['id']; $isNew = false; $posted = (int)$row['posted_at'];
        // keep earlier known values when this feed does not report a metric
        foreach ($vals as $col => $v) if ($v === null) $vals[$col] = $row[$col];
        $e = engagement_score($kind, eng_metrics_from_row($vals));
        $set = implode(',', array_map(fn($k) => "$k=?", array_keys($vals)));
        $db->prepare("UPDATE content SET $set, kind=?, title=?, body=?, author=COALESCE(?,author), url=COALESCE(?,url), media_url=COALESCE(?,media_url), media_type=COALESCE(?,media_type), engagement=?, metrics_at=? WHERE id=?")
           ->execute(array_merge(array_values($vals), [$kind, $title ?: $row['title'], $body ?: $row['body'], $c['author'] ?? null, $url ?: null, $c['media_url'] ?? null, $c['media_type'] ?? null, $e, $now, $id]));
    } else {
        $isNew = true;
        $cols2 = array_keys($vals);
        $db->prepare('INSERT INTO content (owner_user_id, platform, external_id, url, url_key, kind, author, title, body, thumb_url, posted_at, source, media_url, media_type, ' . implode(',', $cols2) . ', engagement, metrics_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,' . implode(',', array_fill(0, count($cols2), '?')) . ',?,?,?)')
           ->execute(array_merge([$c['owner_user_id'] ?? null, $plat, $ext, $url ?: null, $key, $kind, $c['author'] ?? null, $title ?: null, $body ?: null, $c['thumb_url'] ?? null, $posted, in_array($c['source'] ?? '', ['api', 'import', 'demo'], true) ? $c['source'] : 'api', $c['media_url'] ?? null, $c['media_type'] ?? null], array_values($vals), [$e, $now, $now]));
        $id = (int)$db->lastInsertId();
    }
    $snaps = $db->prepare('SELECT at, engagement AS e FROM content_metrics WHERE content_id=? ORDER BY at'); $snaps->execute([$id]);
    $snaps = array_map(fn($r) => ['at' => (int)$r['at'], 'e' => (float)$r['e']], $snaps->fetchAll());
    $v = velocity_score($e, $snaps, $now, $posted);
    $db->prepare('UPDATE content SET velocity=?, trend=? WHERE id=?')->execute([$v, trend_score($e, $v, $now, $posted), $id]);
    add_snapshot($id, $now, $e);
    // topics + category
    $text = $title . ' ' . $body; $topics = extract_topics($text);
    $db->prepare('DELETE FROM content_topics WHERE content_id=?')->execute([$id]);
    $ins = $db->prepare('INSERT OR IGNORE INTO content_topics (content_id, topic, kind) VALUES (?,?,?)');
    foreach ($topics as $t) $ins->execute([$id, mb_strtolower($t['topic']), $t['kind']]);
    $db->prepare('UPDATE content SET category=? WHERE id=?')->execute([categorize($text), $id]);
    if (function_exists('ai_apply')) ai_apply($id);
    // thumbnail (best effort, once)
    $r = $db->prepare('SELECT thumb_url, thumb_path FROM content WHERE id=?'); $r->execute([$id]); $cur = $r->fetch();
    if (!empty($c['thumb_url']) && (!$cur['thumb_url'] || $cur['thumb_url'] !== $c['thumb_url'])) $db->prepare('UPDATE content SET thumb_url=? WHERE id=?')->execute([$c['thumb_url'], $id]);
    if (!empty($c['thumb_path'])) $db->prepare('UPDATE content SET thumb_path=? WHERE id=?')->execute([$c['thumb_path'], $id]);
    elseif (!empty($c['thumb_url']) && !$cur['thumb_path']) { $p = cache_content_thumb($id, $c['thumb_url']); if ($p) $db->prepare('UPDATE content SET thumb_path=? WHERE id=?')->execute([$p, $id]); }
    return [$id, $isNew];
}

/* ============================================================== visibility */

function user_prefs(int $uid): array {
    $s = db()->prepare('SELECT * FROM user_prefs WHERE user_id=?'); $s->execute([$uid]); $r = $s->fetch();
    return $r ? ['share_pool' => (bool)$r['share_pool'], 'share_circle' => (bool)$r['share_circle'], 'open_to_ads' => (bool)$r['open_to_ads']] : ['share_pool' => false, 'share_circle' => true, 'open_to_ads' => false];
}
function save_prefs(int $uid, array $in): array {
    $p = user_prefs($uid); foreach (['share_pool', 'share_circle', 'open_to_ads'] as $k) if (array_key_exists($k, $in)) $p[$k] = (bool)$in[$k];
    if (array_key_exists('share_pool', $in) && !$in['share_pool']) $p['open_to_ads'] = false;   // leaving the pool also closes ad offers
    elseif ($p['open_to_ads']) $p['share_pool'] = true;                                         // offers only work for posts in the shared pool
    db()->prepare('INSERT INTO user_prefs (user_id, share_pool, share_circle, open_to_ads) VALUES (?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET share_pool=excluded.share_pool, share_circle=excluded.share_circle, open_to_ads=excluded.open_to_ads')
        ->execute([$uid, (int)$p['share_pool'], (int)$p['share_circle'], (int)$p['open_to_ads']]);
    return $p;
}

const POOL_SQL = '(c.owner_user_id IS NULL OR COALESCE(p.share_pool,0)=1)';
function circle_ids(int $uid): array {
    $s = db()->prepare("SELECT CASE WHEN user_id=? THEN member_id ELSE user_id END FROM circle WHERE status='accepted' AND (user_id=? OR member_id=?)"); $s->execute([$uid, $uid, $uid]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}
/** Content a user may see: their own, the shared pool, and the circle-shared posts of their circle. */
function visible_sql(int $uid): array {
    $ids = circle_ids($uid);
    $sql = '(c.owner_user_id = ? OR ' . POOL_SQL . ($ids ? ' OR (COALESCE(p.share_circle,1)=1 AND c.owner_user_id IN (' . implode(',', $ids) . '))' : '') . ')';
    return [$sql, [$uid]];
}

/* ================================================================= queries */

function active_sponsors(array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $s = db()->prepare("SELECT b.id AS bid_id, b.mockup_path, b.content_id, a.brand, b.link, b.message FROM bids b JOIN advertisers a ON a.id=b.advertiser_id WHERE b.status='accepted' AND b.ends_at>=? AND b.content_id IN ($in)"); $s->execute([time()]);
    $o = []; foreach ($s->fetchAll() as $r) $o[(int)$r['content_id']] = ['brand' => $r['brand'], 'link' => $r['link'], 'message' => $r['message'], 'mockup' => $r['mockup_path'] ? 'thumb.php?ad=' . (int)$r['bid_id'] : null];
    return $o;
}
function sparklines(array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $o = []; foreach (db()->query("SELECT content_id, engagement FROM content_metrics WHERE content_id IN ($in) ORDER BY at")->fetchAll() as $r) $o[(int)$r['content_id']][] = round((float)$r['engagement'], 3);
    return array_map(fn($a) => count($a) > 24 ? array_values(array_slice($a, -24)) : $a, $o);
}

/** @param array<int,array> $rows content rows joined with owner_name, open_to_ads */
function content_public(array $rows, int $viewer): array {
    $ids = array_map(fn($r) => (int)$r['id'], $rows); $spon = active_sponsors($ids); $spark = sparklines($ids); $tp = [];
    if ($ids) { $in = implode(',', $ids); foreach (db()->query("SELECT content_id, topic, kind FROM content_topics WHERE content_id IN ($in) AND kind IN ('hashtag','brand','category') ORDER BY CASE kind WHEN 'brand' THEN 0 WHEN 'category' THEN 1 ELSE 2 END")->fetchAll() as $t) $tp[(int)$t['content_id']][] = ['topic' => $t['topic'], 'kind' => $t['kind']]; }
    $aiw = function_exists('ai_why') ? ai_why($ids) : []; $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id']; $mine = (int)$r['owner_user_id'] === $viewer && $viewer > 0;
        $out[] = ['id' => $id, 'platform' => $r['platform'], 'kind' => $r['kind'], 'url' => $r['url'], 'sample' => $r['source'] === 'demo', 'author' => $r['author'], 'title' => $r['title'], 'body' => $r['body'] ? mb_substr($r['body'], 0, 320) : null,
            'thumb' => $r['thumb_path'] ? 'thumb.php?c=' . $id : null, 'media' => !empty($r['media_url']) && preg_match('#^https://#i', (string)$r['media_url']) ? ['url' => $r['media_url'], 'type' => $r['media_type'] ?: 'VIDEO'] : null, 'posted_at' => (int)$r['posted_at'], 'category' => $r['category'],
            'metrics' => ['views' => $r['m_views'] !== null ? (int)$r['m_views'] : null, 'watch_minutes' => $r['m_watch_seconds'] !== null ? round($r['m_watch_seconds'] / 60, 1) : null, 'likes' => $r['m_likes'] !== null ? (int)$r['m_likes'] : null,
                'comments' => $r['m_comments'] !== null ? (int)$r['m_comments'] : null, 'shares' => $r['m_shares'] !== null ? (int)$r['m_shares'] : null, 'downloads' => $r['m_downloads'] !== null ? (int)$r['m_downloads'] : ($r['m_saves'] !== null ? (int)$r['m_saves'] : null)],
            'pct' => (int)$r['pct'], 'engagement' => round((float)$r['engagement'], 2), 'velocity' => round((float)$r['velocity'], 3), 'breakout' => round((float)$r['breakout'], 1),
            'topics' => array_slice($tp[$id] ?? [], 0, 4), 'ai' => $aiw[$id] ?? null, 'spark' => $spark[$id] ?? [], 'mine' => $mine, 'owner' => $r['owner_name'] ?? null,
            'biddable' => $r['owner_user_id'] !== null && !empty($r['open_to_ads']) && !$mine && !isset($spon[$id]), 'sponsor' => $spon[$id] ?? null];
    }
    return $out;
}

const CONTENT_SELECT = 'SELECT c.*, u.name AS owner_name, COALESCE(p.open_to_ads,0) AS open_to_ads FROM content c LEFT JOIN user_prefs p ON p.user_id=c.owner_user_id LEFT JOIN users u ON u.id=c.owner_user_id';

function trending_list(int $uid, array $f): array {
    $since = match ($f['range'] ?? 'week') { 'day' => time() - DAY, 'month' => time() - 30 * DAY, default => time() - 7 * DAY };
    $w = [POOL_SQL, 'c.posted_at >= ?']; $a = [$since];
    // invented sample posts are hidden as soon as real ones exist, unless the viewer asks for them
    if (($f['samples'] ?? '') !== '1' && (int)db()->query("SELECT COUNT(*) FROM content WHERE source='import'")->fetchColumn() > 0) $w[] = "c.source != 'demo'";
    if (in_array($f['kind'] ?? '', ENG_KINDS, true)) { $w[] = 'c.kind=?'; $a[] = $f['kind']; }
    if (isset(trend_platforms()[$f['platform'] ?? ''])) { $w[] = 'c.platform=?'; $a[] = $f['platform']; }
    if (in_array($f['category'] ?? '', categories(), true)) { $w[] = 'c.category=?'; $a[] = $f['category']; }
    if (($f['topic'] ?? '') !== '') { $w[] = 'EXISTS (SELECT 1 FROM content_topics t WHERE t.content_id=c.id AND t.topic=?)'; $a[] = mb_strtolower(mb_substr((string)$f['topic'], 0, 60)); }
    $order = ($f['sort'] ?? '') === 'new' ? 'c.posted_at DESC' : (($f['sort'] ?? '') === 'surging' ? 'c.velocity DESC' : 'c.trend DESC');
    if (($f['category'] ?? '') === '' && ($f['topic'] ?? '') === '' && !in_array($f['sort'] ?? '', ['new', 'surging'], true)) {   // the owner's priority topics first
        $pr = array_values(array_intersect(array_map('trim', explode(',', (string)cfg('TOPIC_PRIORITY'))), categories()));
        if ($pr) $order = "(CASE WHEN c.category IN ('" . implode("','", $pr) . "') THEN 0 ELSE 1 END), " . $order;
    }
    $s = db()->prepare(CONTENT_SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY $order LIMIT " . min(60, max(1, (int)($f['limit'] ?? 36)))); $s->execute($a);
    return content_public($s->fetchAll(), $uid);
}

/** Circle members' posts that are doing better than their usual, best first. */
function circle_suggestions(int $uid, int $limit = 30): array {
    $ids = circle_ids($uid); if (!$ids) return [];
    $in = implode(',', $ids);
    $s = db()->prepare(CONTENT_SELECT . " WHERE c.owner_user_id IN ($in) AND COALESCE(p.share_circle,1)=1 AND c.posted_at >= ? AND ((c.breakout >= 1.5 AND c.pct >= 50) OR c.pct >= 80) ORDER BY (c.breakout * (1 + c.pct/100.0)) DESC, c.trend DESC LIMIT " . (int)$limit);
    $s->execute([time() - 14 * DAY]);
    return content_public($s->fetchAll(), $uid);
}

/** Topics whose engagement in the last 24 h is well above their previous-week daily average. */
function topic_surges(?int $now = null, int $limit = 12): array {
    $now ??= time(); $db = db();
    $s = $db->prepare("SELECT t.topic, t.kind, c.id, c.engagement AS e, c.posted_at, COALESCE(c.author, CAST(c.owner_user_id AS TEXT), 'x') AS au
        FROM content_topics t JOIN content c ON c.id=t.content_id LEFT JOIN user_prefs p ON p.user_id=c.owner_user_id
        WHERE " . POOL_SQL . " AND c.posted_at >= ? AND t.kind IN ('hashtag','brand','category','term')");
    $s->execute([$now - 8 * DAY]);
    $agg = [];
    foreach ($s->fetchAll() as $r) {
        $k = $r['kind'] . '|' . $r['topic']; $a = &$agg[$k];
        $a ??= ['topic' => $r['topic'], 'kind' => $r['kind'], 'cur' => 0.0, 'base' => 0.0, 'n' => 0, 'authors' => [], 'top' => null, 'topE' => -1.0];
        if ((int)$r['posted_at'] >= $now - DAY) { $a['cur'] += (float)$r['e']; $a['n']++; $a['authors'][$r['au']] = 1; if ((float)$r['e'] > $a['topE']) { $a['topE'] = (float)$r['e']; $a['top'] = (int)$r['id']; } }
        else $a['base'] += (float)$r['e'];
        unset($a);
    }
    $out = [];
    foreach ($agg as $a) {
        if ($a['n'] < 2 || count($a['authors']) < 2) continue;
        $ratio = ($a['cur'] + 1) / (($a['base'] / 7) + 1);
        if ($ratio < 1.8) continue;
        $out[] = ['topic' => $a['topic'], 'kind' => $a['kind'], 'ratio' => round($ratio, 1), 'posts' => $a['n'], 'authors' => count($a['authors']), 'top_content' => $a['top'], 'rank' => $ratio * log(1 + $a['n'])];
    }
    usort($out, fn($x, $y) => $y['rank'] <=> $x['rank']);
    return array_map(function ($o) { unset($o['rank']); return $o; }, array_slice($out, 0, $limit));
}

/* ================================================================ trend run */

function trend_refresh_if_stale(int $maxAge = 300): void {
    $s = db()->prepare("SELECT v FROM kv WHERE k='trend_last_run'"); $s->execute(); $last = (int)$s->fetchColumn();
    if (time() - $last >= $maxAge) trend_run();
}

function trend_run(?int $now = null): array {
    $now ??= time(); $db = db();
    $rows = $db->prepare('SELECT c.*, COALESCE(p.share_pool,0) AS sp FROM content c LEFT JOIN user_prefs p ON p.user_id=c.owner_user_id WHERE c.posted_at >= ?'); $rows->execute([$now - 30 * DAY]); $rows = $rows->fetchAll();
    $snapQ = $db->prepare('SELECT at, engagement AS e FROM content_metrics WHERE content_id=? ORDER BY at');
    $E = []; $T = []; $V = [];
    foreach ($rows as $r) {
        $id = (int)$r['id']; $e = engagement_score($r['kind'], eng_metrics_from_row($r));
        $snapQ->execute([$id]); $snaps = array_map(fn($x) => ['at' => (int)$x['at'], 'e' => (float)$x['e']], $snapQ->fetchAll());
        $v = velocity_score($e, $snaps, $now, (int)$r['posted_at']); $E[$id] = $e; $V[$id] = $v; $T[$id] = trend_score($e, $v, $now, (int)$r['posted_at']);
    }
    // baselines: author's median E over their other posts (90 d), else the pool median for that kind
    $poolMed = []; foreach (ENG_KINDS as $k) { $xs = []; foreach ($rows as $r) if ($r['kind'] === $k && ($r['owner_user_id'] === null || (int)$r['sp'] === 1)) $xs[] = $E[(int)$r['id']]; $poolMed[$k] = median($xs); }
    $hist = $db->prepare('SELECT id, engagement FROM content WHERE owner_user_id=? AND posted_at >= ? AND id != ?');
    $upd = $db->prepare('UPDATE content SET engagement=?, velocity=?, trend=?, breakout=?, pct=? WHERE id=?');
    $poolT = []; foreach ($rows as $r) if ($r['owner_user_id'] === null || (int)$r['sp'] === 1) if ((int)$r['posted_at'] >= $now - 14 * DAY) $poolT[] = $T[(int)$r['id']];
    sort($poolT); $n = count($poolT);
    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $id = (int)$r['id']; $base = null;
            if ($r['owner_user_id'] !== null) { $hist->execute([$r['owner_user_id'], $now - 90 * DAY, $id]); $es = array_map(fn($x) => (float)$x['engagement'], $hist->fetchAll()); if (count($es) >= 3) $base = median($es); }
            $base ??= $poolMed[$r['kind']] ?? 1.0;
            $less = 0; foreach ($poolT as $t) { if ($t < $T[$id]) $less++; else break; }
            $upd->execute([$E[$id], $V[$id], $T[$id], breakout_ratio($E[$id], $base), $n ? (int)round(100 * $less / $n) : 0, $id]);
        }
        $db->prepare("DELETE FROM content_metrics WHERE at < ?")->execute([$now - 45 * DAY]);
        $db->prepare("UPDATE bids SET status='ended' WHERE status='accepted' AND ends_at < ?")->execute([$now]);
        $db->prepare("UPDATE bids SET status='expired' WHERE status='pending' AND created_at < ?")->execute([$now - 14 * DAY]);
        $db->commit();
    } catch (Throwable $t) { $db->rollBack(); throw $t; }
    require_once __DIR__ . '/ads.php';
    if (ai_enabled()) { try { ai_tag_pending(); } catch (Throwable $t) { error_log('[recap] ' . $t->getMessage()); } }
    $made = ads_notify_all($now);
    $db->prepare("INSERT INTO kv (k, v) VALUES ('trend_last_run', ?) ON CONFLICT(k) DO UPDATE SET v=excluded.v")->execute([(string)$now]);
    return ['content' => count($rows), 'notifications' => $made];
}

require_once __DIR__ . '/meta_ai.php';
