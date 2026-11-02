<?php
declare(strict_types=1);
/**
 * Captures engagement numbers for the signed-in user's OWN posts while syncing (that is all Meta's APIs expose).
 *  Instagram : like_count + comments_count (instagram_business_basic). With ENGAGEMENT_INSIGHTS on: views, shares, saves and reel watch time.
 *  Threads   : only with ENGAGEMENT_INSIGHTS on (threads_manage_insights): views, likes, replies, reposts, quotes, shares.
 *  Facebook  : likes, comments, shares counts on /me/posts (needs user_posts).
 * Nothing here makes a post public: it only appears in Trending / to advertisers after its owner opts in (user_prefs).
 */
require_once __DIR__ . '/engage.php';

function engage_insights_enabled(): bool { return (bool)cfg('ENGAGEMENT_INSIGHTS'); }

/** Meta insights come back as data:[{name, values:[{value}]} | {name, total_value:{value}}]. */
function insights_map(?array $j): array {
    $o = []; foreach ($j['data'] ?? [] as $d) { $v = $d['values'][0]['value'] ?? ($d['total_value']['value'] ?? null); if ($v !== null && is_numeric($v)) $o[(string)$d['name']] = (float)$v; }
    return $o;
}

function engage_save_own(int $uid, array $c): void {
    try { upsert_content($c + ['owner_user_id' => $uid, 'source' => 'api']); } catch (Throwable $t) { error_log('[recap] engage upsert: ' . $t->getMessage()); }
}

/** @return string[] notes to show next to the connection */
function engage_instagram(int $uid, array $conn, array $media, callable $fetch, array $u): array {
    $notes = []; $insightsFailed = false; $author = $conn['account_handle'] ?: $conn['account_name']; $i = 0;
    foreach ($media as $p) {
        $isVideo = (($p['media_type'] ?? '') === 'VIDEO') || in_array($p['media_product_type'] ?? '', ['REELS', 'VIDEO'], true);
        $m = ['likes' => $p['like_count'] ?? null, 'comments' => $p['comments_count'] ?? null];
        if (engage_insights_enabled() && !$insightsFailed && $i++ < 30) {
            try {
                $metrics = 'views,shares,saved' . (($p['media_product_type'] ?? '') === 'REELS' ? ',ig_reels_video_view_total_time' : '');
                $im = insights_map($fetch($u['ig_graph'] . '/' . $p['id'] . '/insights', ['metric' => $metrics]));
                $m['views'] = $im['views'] ?? null; $m['shares'] = $im['shares'] ?? null; $m['saves'] = $im['saved'] ?? null;
                if (isset($im['ig_reels_video_view_total_time'])) $m['watch_seconds'] = $im['ig_reels_video_view_total_time'] / 1000;
            } catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'expired')) throw $e; $insightsFailed = true; $notes[] = 'Instagram insights: ' . $e->getMessage() . ' (needs the instagram_business_manage_insights permission).'; }
        }
        $caption = (string)($p['caption'] ?? '');
        engage_save_own($uid, ['platform' => 'instagram', 'external_id' => (string)$p['id'], 'url' => $p['permalink'] ?? '', 'kind' => $isVideo ? 'video' : 'image', 'author' => $author,
            'title' => $caption !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $caption), 0, 120) : 'Instagram ' . strtolower($p['media_product_type'] ?? 'post'), 'body' => $caption,
            'thumb_url' => $p['thumbnail_url'] ?? (in_array($p['media_type'] ?? '', ['IMAGE', 'CAROUSEL_ALBUM'], true) ? ($p['media_url'] ?? null) : null),
            'posted_at' => ts($p['timestamp'] ?? null), 'm' => $m]);
    }
    return $notes;
}

function engage_threads(int $uid, array $conn, array $posts, callable $fetch, array $u): array {
    $notes = []; $failed = false; $i = 0;
    if ($posts && !engage_insights_enabled()) $notes[] = 'Threads engagement numbers need ENGAGEMENT_INSIGHTS (threads_manage_insights) in config.local.php.';
    foreach ($posts as $p) {
        $mt = (string)($p['media_type'] ?? ''); $kind = $mt === 'VIDEO' ? 'video' : ($mt === 'IMAGE' || $mt === 'CAROUSEL_ALBUM' ? 'image' : 'text'); $m = [];
        if (engage_insights_enabled() && !$failed && $i++ < 30) {
            try {
                $im = insights_map($fetch($u['th_graph'] . '/' . $p['id'] . '/insights', ['metric' => 'views,likes,replies,reposts,quotes,shares']));
                $m = ['views' => $im['views'] ?? null, 'likes' => $im['likes'] ?? null, 'comments' => $im['replies'] ?? null, 'shares' => isset($im['shares']) || isset($im['reposts']) || isset($im['quotes']) ? ($im['shares'] ?? 0) + ($im['reposts'] ?? 0) + ($im['quotes'] ?? 0) : null];
            } catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'expired')) throw $e; $failed = true; $notes[] = 'Threads insights: ' . $e->getMessage(); }
        }
        $text = (string)($p['text'] ?? '');
        engage_save_own($uid, ['platform' => 'threads', 'external_id' => (string)$p['id'], 'url' => $p['permalink'] ?? '', 'kind' => $kind, 'author' => $p['username'] ?? ($conn['account_handle'] ?: $conn['account_name']),
            'title' => $text !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 120) : 'Thread', 'body' => $text, 'thumb_url' => $p['thumbnail_url'] ?? ($kind === 'image' ? ($p['media_url'] ?? null) : null),
            'posted_at' => ts($p['timestamp'] ?? null), 'm' => $m]);
    }
    return $notes;
}

function engage_facebook(int $uid, array $conn, array $posts): void {
    foreach ($posts as $p) {
        $st = (string)($p['status_type'] ?? ''); $text = (string)($p['message'] ?? $p['story'] ?? '');
        $kind = $st === 'added_video' ? 'video' : ($st === 'added_photos' ? 'image' : (!empty($p['full_picture']) && $text === '' ? 'image' : 'text'));
        $m = ['likes' => $p['likes']['summary']['total_count'] ?? ($p['reactions']['summary']['total_count'] ?? null), 'comments' => $p['comments']['summary']['total_count'] ?? null, 'shares' => $p['shares']['count'] ?? 0];
        engage_save_own($uid, ['platform' => 'facebook', 'external_id' => (string)$p['id'], 'url' => $p['permalink_url'] ?? '', 'kind' => $kind, 'author' => $conn['account_name'],
            'title' => mb_substr($text !== '' ? $text : 'Facebook post', 0, 120), 'body' => $text, 'thumb_url' => $p['full_picture'] ?? null, 'posted_at' => ts($p['created_time'] ?? null), 'm' => $m]);
    }
}
