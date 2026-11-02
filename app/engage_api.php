<?php
declare(strict_types=1);
/** API actions for Trending, My circle suggestions, Bid ads and notifications. Called from api.php (user is signed in, CSRF already checked for POST). */
require_once __DIR__ . '/ads.php';

function engage_dispatch(string $action, array $in, array $user, string $method): never {
    $uid = (int)$user['id'];
    $post = function () use ($method) { if ($method !== 'POST') fail('POST only', 405); };
    $get = fn(string $k, string $d = '') => (string)($_GET[$k] ?? $d);

    switch ($action) {
        case 'trend.list': {
            trend_refresh_if_stale(300);
            $f = ['kind' => $get('kind'), 'platform' => $get('platform'), 'category' => $get('category'), 'topic' => $get('topic'), 'range' => $get('range', 'week'), 'sort' => $get('sort'), 'samples' => $get('samples')];
            if ($get('own') === '1') {   // the signed-in user's own posts and how they are doing (private unless shared)
                $s = db()->prepare(CONTENT_SELECT . ' WHERE c.owner_user_id=? ORDER BY c.posted_at DESC LIMIT 60'); $s->execute([$uid]);
                out(['items' => content_public($s->fetchAll(), $uid), 'categories' => categories()]);
            }
            out(['items' => trending_list($uid, $f), 'categories' => categories()]);
        }
        case 'trend.surges': trend_refresh_if_stale(300); out(['surges' => topic_surges()]);

        case 'prefs.get': out(['prefs' => user_prefs($uid), 'advertiser' => ($a = adv_row($uid)) ? adv_public($a) : null]);
        case 'prefs.save': $post(); out(['prefs' => save_prefs($uid, array_intersect_key($in, array_flip(['share_pool', 'share_circle', 'open_to_ads'])))]);

        /* ---------------------------------------------------------- circle */
        case 'circle.state': out(circle_state($uid) + ['prefs' => user_prefs($uid)]);
        case 'circle.suggest': trend_refresh_if_stale(300); out(['items' => circle_suggestions($uid), 'circle' => count(circle_ids($uid))]);
        case 'circle.invite': {
            $post(); try { circle_invite($uid, (string)($in['email'] ?? '')); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
            out(['ok' => true, 'message' => 'If that person has an account, they have been sent your invitation.']);
        }
        case 'circle.respond': $post(); out(['ok' => circle_respond($uid, (int)($in['user_id'] ?? 0), !empty($in['accept']))]);
        case 'circle.remove': $post(); circle_remove($uid, (int)($in['user_id'] ?? 0)); out(['ok' => true]);

        /* ----------------------------------------------------- advertisers */
        case 'ads.profile': out(['advertiser' => ($a = adv_row($uid)) ? adv_public($a) : null, 'categories' => categories(), 'min_bid' => (int)cfg('BID_MIN_CENTS'), 'currency' => cfg('BID_CURRENCY'), 'ai' => (string)cfg('META_AI_KEY') !== '']);
        case 'ads.save': { $post(); $r = adv_save($uid, $in); if (!$r['ok']) fail($r['error']); try { trend_run(); } catch (Throwable $t) { error_log('[recap] ' . $t->getMessage()); } out($r); }
        case 'ads.opps': {
            $a = adv_row($uid); if (!$a) fail('Set up your advertiser profile first.', 403); trend_refresh_if_stale(300);
            $sg = array_values(array_filter(topic_surges(), fn($s) => surge_matches($a, $s)));
            out(['items' => ads_opportunities($a, $uid, 40), 'surges' => $sg, 'briefing' => ai_briefing($a, $sg), 'ai' => ai_enabled()]);
        }
        case 'bid.place': { $post(); $r = bid_place($uid, $in); if (!$r['ok']) fail($r['error']); out($r, 201); }
        case 'bid.suggest': {
            $post(); $a = adv_row($uid); if (!$a) fail('Set up your advertiser profile first.', 403);
            $t = ai_ad_message($a, (int)($in['content_id'] ?? 0)); if (!$t) fail('Suggestions are not available right now. Write your own message.', 503); out(['message' => $t]);
        }
        case 'bid.mockup': {   // Muse Image: a picture to go with the offer
            $post(); $a = adv_row($uid); if (!$a) fail('Set up your advertiser profile first.', 403);
            try { $m = ai_ad_mockup($a, (int)($in['content_id'] ?? 0)); } catch (RuntimeException $e) { error_log('[recap] ' . $e->getMessage()); fail(str_starts_with($e->getMessage(), 'Daily') ? $e->getMessage() : 'The picture could not be made right now. You can still send the offer without it.', 503); }
            if (!$m) fail('Pictures are not available right now.', 503); out(['url' => $m['url']]);
        }
        case 'bid.mine': out(['bids' => bids_mine($uid)]);
        case 'bid.offers': out(['offers' => bids_offers($uid), 'prefs' => user_prefs($uid)]);
        case 'bid.respond': { $post(); $r = bid_respond($uid, (int)($in['id'] ?? 0), !empty($in['accept'])); if (!$r['ok']) fail($r['error']); out($r); }
        case 'bid.withdraw': { $post(); $r = bid_withdraw($uid, (int)($in['id'] ?? 0)); if (!$r['ok']) fail($r['error']); out($r); }

        /* ---------------------------------------------------- notifications */
        case 'notif.list': out(notif_list($uid));
        case 'notif.read': $post(); notif_read($uid, isset($in['ids']) && is_array($in['ids']) ? array_map('intval', $in['ids']) : null); out(['ok' => true]);

        case 'trend.fetch': {   // "Get latest content" button
            $post(); if (!cfg('REFRESH_BUTTON')) fail('Disabled', 403);
            require_once __DIR__ . '/topic_media.php'; $wait = content_refresh_gate();
            if ($wait > 0) out(['ran' => false, 'wait' => $wait]);
            $r = content_refresh(); out(['ran' => true, 'saved' => $r['saved'], 'notes' => array_slice($r['notes'], 0, 3)]);
        }

        case 'demo.engage': {
            if (!demo_allowed()) fail('Disabled', 403);
            require_once __DIR__ . '/demo_engage.php'; out(['added' => load_demo_engagement($uid)]);
        }
    }
    fail('Unknown action', 404);
}

/** Licensed-data import (no session): a server-to-server call with the INGEST_KEY. Content is stored as public (owner-less) and cannot receive ad offers. */
function engage_ingest(array $in): never {
    $key = (string)cfg('INGEST_KEY'); $sent = (string)($_SERVER['HTTP_X_INGEST_KEY'] ?? '');
    if ($key === '' || !hash_equals($key, $sent)) fail('Import is disabled or the key is wrong.', 401);
    $items = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 200) : []; $n = 0; $skipped = 0;
    foreach ($items as $it) { if (!is_array($it) || !import_one($it)) $skipped++; else $n++; }
    try { trend_run(); } catch (Throwable $t) { error_log('[recap] ' . $t->getMessage()); }
    out(['imported' => $n, 'skipped' => $skipped]);
}

function import_one(array $it): bool {
    $url = (string)($it['url'] ?? ''); if (!preg_match('#^https://#i', $url)) return false;
    $plat = isset(trend_platforms()[$it['platform'] ?? '']) ? $it['platform'] : detect_platform($url); if (!$plat) return false;
    $at = $it['posted_at'] ?? time(); $ts = is_numeric($at) ? (int)$at : (strtotime((string)$at) ?: time()); if ($ts > time() + 300) $ts = time();
    $m = is_array($it['metrics'] ?? null) ? $it['metrics'] : $it;
    $mm = []; foreach (['views', 'watch_seconds', 'likes', 'comments', 'shares', 'downloads', 'saves'] as $k) if (isset($m[$k]) && $m[$k] !== '' && is_numeric($m[$k])) $mm[$k] = max(0, (int)$m[$k]);
    upsert_content(['platform' => $plat, 'url' => $url, 'external_id' => isset($it['external_id']) ? (string)$it['external_id'] : null, 'kind' => in_array($it['kind'] ?? '', ENG_KINDS, true) ? $it['kind'] : 'text',
        'author' => isset($it['author']) ? mb_substr((string)$it['author'], 0, 80) : null, 'title' => (string)($it['title'] ?? ''), 'body' => (string)($it['body'] ?? ''), 'thumb_url' => $it['thumb_url'] ?? null, 'media_url' => isset($it['media_url']) && preg_match('#^https://#i', (string)$it['media_url']) ? (string)$it['media_url'] : null, 'media_type' => isset($it['media_url']) ? (($it['media_type'] ?? '') === 'YOUTUBE' && preg_match('#^https://www\.youtube-nocookie\.com/embed/[\w-]{6,20}$#', (string)$it['media_url']) ? 'YOUTUBE' : 'VIDEO') : null,
        'posted_at' => $ts, 'source' => 'import', 'owner_user_id' => null, 'm' => $mm]);
    return true;
}
