<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/meta.php';

$action = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];
$in = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
$str = fn(string $k, int $max = 200): string => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);

set_exception_handler(function (Throwable $t) { error_log('[recap] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine()); fail('Server error', 500); });

/* ------------------------------------------------------------- public */
if ($action === 'boot') {
    $u = session_user();
    out(['csrf' => csrf(), 'user' => $u ? public_user($u) : null, 'app' => cfg('APP_NAME'),
         'meta' => ['facebook' => meta_configured('facebook'), 'instagram' => meta_configured('instagram'), 'threads' => meta_configured('threads')],
         'devLogin' => (bool)cfg('DEV_LOGIN') && is_loopback(), 'demoData' => demo_allowed(), 'refreshButton' => (bool)cfg('REFRESH_BUTTON'), 'passwordSignup' => (bool)cfg('ALLOW_PASSWORD_SIGNUP'),
         'platforms' => array_map(fn($p) => $p['name'], PLATFORMS)]);
}

/* extension ingest: token auth, no CSRF (cross-origin extension), least privilege */
if ($action === 'ingest') {
    if ($method !== 'POST') fail('POST only', 405);
    $tu = token_user((string)($_SERVER['HTTP_X_RECAP_TOKEN'] ?? '')); if (!$tu) fail('Invalid or revoked token', 401);
    $url = $str('url', 2000);
    if (!preg_match('#^https://#i', $url)) fail('Invalid link');
    $platform = detect_platform($url); if (!$platform) fail('Not a Meta link');
    [$id, $new] = upsert_item((int)$tu['id'], ['platform' => $platform, 'source' => 'extension', 'url' => $url, 'title' => $str('title'), 'dwell' => (int)($in['dwell'] ?? 0), 'interaction' => 'viewed']);
    out(['id' => $id, 'new' => $new], $new ? 201 : 200);
}

/* licensed-data import: server-to-server, protected by INGEST_KEY (disabled when the key is empty) */
if ($action === 'content.ingest') {
    if ($method !== 'POST') fail('POST only', 405);
    require_once __DIR__ . '/app/engage_api.php'; engage_ingest($in);
}

/* token check used by the extension's setup page */
if ($action === 'ping') { $tu = token_user((string)($_SERVER['HTTP_X_RECAP_TOKEN'] ?? '')); if (!$tu) fail('Invalid or revoked token', 401); out(['user' => ['email' => $tu['email']]]); }

/* auth */
if (in_array($action, ['auth.login', 'auth.register', 'auth.dev'], true)) {
    if ($method !== 'POST') fail('POST only', 405); check_csrf();
    $db = db();
    if ($action === 'auth.dev') {
        if (!cfg('DEV_LOGIN') || !is_loopback()) fail('Disabled', 403);
        $email = strtolower($str('email', 120)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Valid email required');
        $s = $db->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); $u = $s->fetch();
        if (!$u) { $db->prepare('INSERT INTO users (email, name, created_at) VALUES (?,?,?)')->execute([$email, $str('name', 80) ?: $email, time()]); $s->execute([$email]); $u = $s->fetch(); }
        login_user($u); out(['user' => public_user($u)]);
    }
    $email = strtolower($str('email', 120)); $pw = (string)($in['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.');
    if ($action === 'auth.register') {
        if (!cfg('ALLOW_PASSWORD_SIGNUP')) fail('Sign-up with a password is turned off.', 403);
        if (strlen($pw) < 8) fail('Password must be at least 8 characters.');
        $name = $str('name', 80) ?: strstr($email, '@', true);
        $s = $db->prepare('SELECT 1 FROM users WHERE email=?'); $s->execute([$email]); if ($s->fetch()) fail('An account with that email already exists — sign in instead.');
        $db->prepare('INSERT INTO users (email, name, password_hash, created_at) VALUES (?,?,?,?)')->execute([$email, $name, password_hash($pw, PASSWORD_DEFAULT), time()]);
        $s = $db->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); $u = $s->fetch(); login_user($u); out(['user' => public_user($u)], 201);
    }
    $s = $db->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); $u = $s->fetch();
    if (!$u || !$u['password_hash'] || !password_verify($pw, $u['password_hash'])) { usleep(400000); fail('Email or password is incorrect.', 401); }
    if ($u['disabled']) fail('This account has been disabled.', 403);
    login_user($u); out(['user' => public_user($u)]);
}
if ($action === 'auth.logout') { if ($method !== 'POST') fail('POST only', 405); check_csrf(); session_boot(); $_SESSION = []; session_destroy(); out(['ok' => true]); }

/* ------------------------------------------------------- authenticated */
$user = session_user(); if (!$user) fail('Not signed in', 401);
$uid = (int)$user['id']; $db = db();
if ($method === 'POST') check_csrf();

/** Set of saved-song keys, to flag items whose music is already saved. */
$savedKeys = function () use ($db, $uid): array { $s = $db->prepare('SELECT song_key FROM saved_music WHERE user_id=?'); $s->execute([$uid]); return array_flip($s->fetchAll(PDO::FETCH_COLUMN)); };
$decorate = function (array $rows) use ($savedKeys): array {
    $keys = $savedKeys(); $out = [];
    foreach ($rows as $r) { $r['saved_song'] = $r['music_title'] && isset($keys[song_key($r['music_title'], (string)$r['music_artist'])]) ? 1 : 0; $out[] = item_public($r); }
    return $out;
};
$rangeSince = function (string $range): int { return match ($range) { 'day' => time() - 86400, 'week' => time() - 7 * 86400, 'month' => time() - 30 * 86400, default => 0 }; };

/* Trending, My circle, Bid ads, notifications (app/engage_api.php) */
if (preg_match('/^(trend|prefs|circle|ads|bid|notif)\./', $action) || $action === 'demo.engage') { require_once __DIR__ . '/app/engage_api.php'; engage_dispatch($action, $in, $user, $method); }

switch ($action) {
    case 'me': out(['user' => public_user($user)]);

    case 'home': {
        $since = $rangeSince((string)($_GET['range'] ?? 'month')); $platform = (string)($_GET['platform'] ?? '');
        $w = 'user_id=? AND interacted_at>=?'; $a = [$uid, $since]; if (isset(trend_platforms()[$platform])) { $w .= ' AND platform=?'; $a[] = $platform; }
        $s = $db->prepare("SELECT kind, COUNT(*) n FROM items WHERE $w GROUP BY kind"); $s->execute($a);
        $counts = array_fill_keys(KINDS, 0); foreach ($s->fetchAll() as $r) $counts[$r['kind']] = (int)$r['n'];
        $s = $db->prepare("SELECT platform, COUNT(*) n FROM items WHERE user_id=? AND interacted_at>=? GROUP BY platform"); $s->execute([$uid, $since]);
        $byPlatform = []; foreach ($s->fetchAll() as $r) $byPlatform[$r['platform']] = (int)$r['n'];
        $rails = [];
        foreach (KINDS as $k) { $s = $db->prepare("SELECT * FROM items WHERE $w AND kind=? ORDER BY interacted_at DESC LIMIT 12"); $s->execute(array_merge($a, [$k])); $rails[$k] = $decorate($s->fetchAll()); }
        $s = $db->prepare("SELECT m.*, i.thumb_path FROM saved_music m LEFT JOIN items i ON i.id=m.item_id WHERE m.user_id=? AND m.status='later' ORDER BY m.created_at DESC LIMIT 6"); $s->execute([$uid]);
        $music = array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'artist' => $r['artist'], 'platform' => $r['platform'], 'story' => $r['story_title'], 'thumb' => $r['thumb_path'] ? 'thumb.php?id=' . $r['item_id'] : null], $s->fetchAll());
        $c = $db->prepare('SELECT COUNT(*) FROM connections WHERE user_id=?'); $c->execute([$uid]);
        $t = $db->prepare('SELECT COUNT(*) FROM items WHERE user_id=?'); $t->execute([$uid]);
        $m = $db->prepare("SELECT COUNT(*) FROM saved_music WHERE user_id=? AND status='later'"); $m->execute([$uid]);
        out(['counts' => $counts, 'byPlatform' => $byPlatform, 'rails' => $rails, 'music' => $music, 'connections' => (int)$c->fetchColumn(), 'total' => (int)$t->fetchColumn(), 'musicLater' => (int)$m->fetchColumn()]);
    }

    case 'items.list': {
        $w = 'user_id=?'; $a = [$uid];
        $kind = (string)($_GET['kind'] ?? ''); if (in_array($kind, KINDS, true)) { $w .= ' AND kind=?'; $a[] = $kind; }
        $platform = (string)($_GET['platform'] ?? ''); if (isset(trend_platforms()[$platform])) { $w .= ' AND platform=?'; $a[] = $platform; }
        if (($since = $rangeSince((string)($_GET['range'] ?? ''))) > 0) { $w .= ' AND interacted_at>=?'; $a[] = $since; }
        if (!empty($_GET['music'])) $w .= ' AND music_title IS NOT NULL';
        if (!empty($_GET['pinned'])) $w .= ' AND pinned=1';
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; $w .= " AND (title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\' OR author LIKE ? ESCAPE '\\' OR music_title LIKE ? ESCAPE '\\' OR music_artist LIKE ? ESCAPE '\\')"; array_push($a, $like, $like, $like, $like, $like); }
        $order = ['views' => 'view_count DESC, interacted_at DESC', 'oldest' => 'interacted_at ASC'][$_GET['sort'] ?? ''] ?? 'interacted_at DESC';
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 48))); $offset = max(0, (int)($_GET['offset'] ?? 0));
        $tot = $db->prepare("SELECT COUNT(*) FROM items WHERE $w"); $tot->execute($a);
        $s = $db->prepare("SELECT * FROM items WHERE $w ORDER BY $order LIMIT $limit OFFSET $offset"); $s->execute($a);
        out(['items' => $decorate($s->fetchAll()), 'total' => (int)$tot->fetchColumn()]);
    }

    case 'item.get': {
        $s = $db->prepare('SELECT * FROM items WHERE id=? AND user_id=?'); $s->execute([(int)($_GET['id'] ?? 0), $uid]); $r = $s->fetch(); if (!$r) fail('Not found', 404);
        $d = $decorate([$r])[0];
        if ($r['music_title']) { $m = $db->prepare('SELECT id, status FROM saved_music WHERE user_id=? AND song_key=?'); $m->execute([$uid, song_key($r['music_title'], (string)$r['music_artist'])]); $d['saved'] = $m->fetch() ?: null; }
        out(['item' => $d]);
    }

    case 'item.update': {
        $s = $db->prepare('SELECT * FROM items WHERE id=? AND user_id=?'); $s->execute([(int)($in['id'] ?? 0), $uid]); $r = $s->fetch(); if (!$r) fail('Not found', 404);
        $sets = []; $args = [];
        if (isset($in['kind']) && in_array($in['kind'], KINDS, true)) { $sets[] = 'kind=?'; $args[] = $in['kind']; }
        if (isset($in['pinned'])) { $sets[] = 'pinned=?'; $args[] = $in['pinned'] ? 1 : 0; }
        if (array_key_exists('music_title', $in)) {
            $t = $str('music_title', 120); $ar = $str('music_artist', 120);
            if ($t === '') { array_push($sets, 'music_title=NULL', 'music_artist=NULL', 'music_source=NULL'); }
            else { array_push($sets, 'music_title=?', 'music_artist=?', "music_source='user'"); array_push($args, $t, $ar ?: null); }
        }
        if ($sets) { $args[] = $r['id']; $db->prepare('UPDATE items SET ' . implode(',', $sets) . ' WHERE id=?')->execute($args); }
        $s->execute([$r['id'], $uid]); out(['item' => $decorate([$s->fetch()])[0]]);
    }

    case 'item.delete': {
        $s = $db->prepare('SELECT thumb_path FROM items WHERE id=? AND user_id=?'); $s->execute([(int)($in['id'] ?? 0), $uid]); $r = $s->fetch(); if (!$r) fail('Not found', 404);
        $db->prepare('DELETE FROM items WHERE id=? AND user_id=?')->execute([(int)$in['id'], $uid]);
        if ($r['thumb_path']) @unlink(rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $r['thumb_path']);
        out(['ok' => true]);
    }

    case 'item.add': {   // paste a link, or the PWA Share target
        $url = $str('url', 2000); if (!preg_match('#^https?://#i', $url)) fail('Paste a full link starting with https://');
        $platform = detect_platform($url);
        if (!$platform) {
            $platform = (string)($in['platform'] ?? '');
            if (!isset(PLATFORMS[$platform]) || !looks_like_podcast($url, $str('title'))) fail('That link is not from Facebook, Instagram or Threads. (Podcast links are accepted too — choose where you saw it.)');
        }
        $kind = in_array($in['kind'] ?? '', KINDS, true) ? $in['kind'] : null;
        [$id, $new] = upsert_item($uid, ['platform' => $platform, 'kind' => $kind, 'source' => ($in['via'] ?? '') === 'share' ? 'share' : 'manual', 'url' => $url, 'title' => $str('title') ?: null,
            'music_title' => $str('music_title', 120) ?: null, 'music_artist' => $str('music_artist', 120) ?: null, 'interaction' => 'viewed']);
        $s = $db->prepare('SELECT * FROM items WHERE id=?'); $s->execute([$id]); out(['item' => $decorate([$s->fetch()])[0], 'new' => $new], $new ? 201 : 200);
    }

    /* ----------------------------------------------------- saved music */
    case 'music.list': {
        $status = in_array($_GET['status'] ?? '', ['later', 'listened'], true) ? $_GET['status'] : 'later';
        $s = $db->prepare('SELECT m.*, i.thumb_path, i.kind AS item_kind, i.url AS item_url FROM saved_music m LEFT JOIN items i ON i.id=m.item_id WHERE m.user_id=? AND m.status=? ORDER BY COALESCE(m.listened_at, m.created_at) DESC'); $s->execute([$uid, $status]);
        $n = $db->prepare('SELECT status, COUNT(*) c FROM saved_music WHERE user_id=? GROUP BY status'); $n->execute([$uid]); $counts = ['later' => 0, 'listened' => 0]; foreach ($n->fetchAll() as $r) $counts[$r['status']] = (int)$r['c'];
        out(['songs' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'artist' => $r['artist'], 'platform' => $r['platform'], 'story' => $r['story_title'], 'item_id' => $r['item_id'] ? (int)$r['item_id'] : null,
            'thumb' => $r['thumb_path'] ? 'thumb.php?id=' . $r['item_id'] : null, 'url' => $r['item_url'], 'status' => $r['status'], 'saved_at' => (int)$r['created_at'], 'listened_at' => $r['listened_at'] ? (int)$r['listened_at'] : null], $s->fetchAll()), 'counts' => $counts]);
    }
    case 'music.save': {   // "Save for listening later" — from a story, or typed in
        $itemId = (int)($in['item_id'] ?? 0); $title = $str('title', 120); $artist = $str('artist', 120); $platform = null; $storyTitle = null; $item = null;
        if ($itemId) { $s = $db->prepare('SELECT * FROM items WHERE id=? AND user_id=?'); $s->execute([$itemId, $uid]); $item = $s->fetch(); if (!$item) fail('Not found', 404);
            $platform = $item['platform']; $storyTitle = $item['title']; if ($title === '') { $title = (string)$item['music_title']; $artist = (string)($item['music_artist'] ?? ''); } }
        if ($title === '') fail('Enter the song title.');
        $key = song_key($title, $artist);
        $s = $db->prepare('SELECT * FROM saved_music WHERE user_id=? AND song_key=?'); $s->execute([$uid, $key]); $ex = $s->fetch();
        if ($ex) { if ($ex['status'] === 'listened') $db->prepare("UPDATE saved_music SET status='later', listened_at=NULL WHERE id=?")->execute([$ex['id']]); out(['id' => (int)$ex['id'], 'existing' => true]); }
        $db->prepare('INSERT INTO saved_music (user_id, item_id, title, artist, platform, story_title, status, created_at, song_key) VALUES (?,?,?,?,?,?,\'later\',?,?)')
           ->execute([$uid, $itemId ?: null, $title, $artist, $platform, $storyTitle, time(), $key]);
        out(['id' => (int)$db->lastInsertId(), 'existing' => false], 201);
    }
    case 'music.update': {
        $s = $db->prepare('SELECT * FROM saved_music WHERE id=? AND user_id=?'); $s->execute([(int)($in['id'] ?? 0), $uid]); $m = $s->fetch(); if (!$m) fail('Not found', 404);
        if (isset($in['status']) && in_array($in['status'], ['later', 'listened'], true)) $db->prepare('UPDATE saved_music SET status=?, listened_at=? WHERE id=?')->execute([$in['status'], $in['status'] === 'listened' ? time() : null, $m['id']]);
        out(['ok' => true]);
    }
    case 'music.delete': {
        $db->prepare('DELETE FROM saved_music WHERE id=? AND user_id=?')->execute([(int)($in['id'] ?? 0), $uid]); out(['ok' => true]);
    }

    /* -------------------------------------------------------- connections */
    case 'connections.list': {
        $s = $db->prepare('SELECT * FROM connections WHERE user_id=? ORDER BY platform, id'); $s->execute([$uid]);
        out(['connections' => array_map(fn($c) => ['id' => (int)$c['id'], 'platform' => $c['platform'], 'name' => $c['account_name'], 'handle' => $c['account_handle'], 'picture' => $c['picture'],
            'status' => $c['status'], 'last_sync_at' => (int)$c['last_sync_at'], 'last_sync_count' => (int)$c['last_sync_count'], 'error' => $c['last_error'], 'expires' => $c['token_expires'] ? (int)$c['token_expires'] : null,
            'scopes' => $c['scopes']], $s->fetchAll())]);
    }
    case 'sync': {   // one connection (id) or everything; "auto" respects the minimum interval
        if (!empty($in['id'])) {
            $s = $db->prepare('SELECT * FROM connections WHERE id=? AND user_id=?'); $s->execute([(int)$in['id'], $uid]); $c = $s->fetch(); if (!$c) fail('Not found', 404);
            [$n, $err] = sync_connection($c); out(['synced' => $n, 'error' => $err]);
        }
        out(sync_user($uid, empty($in['auto'])));
    }
    case 'connection.disconnect': {
        $s = $db->prepare('SELECT * FROM connections WHERE id=? AND user_id=?'); $s->execute([(int)($in['id'] ?? 0), $uid]); $c = $s->fetch(); if (!$c) fail('Not found', 404);
        $db->prepare('DELETE FROM connections WHERE id=?')->execute([$c['id']]);
        if (!empty($in['delete_items'])) { $db->prepare("DELETE FROM items WHERE user_id=? AND platform=? AND source='api'")->execute([$uid, $c['platform']]); }
        out(['ok' => true]);
    }

    /* ------------------------------------------------------ extension tokens */
    case 'token.list': { $s = $db->prepare('SELECT id,label,created_at,last_used_at FROM api_tokens WHERE user_id=? ORDER BY id DESC'); $s->execute([$uid]); out(['tokens' => $s->fetchAll()]); }
    case 'token.create': {
        $c = $db->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id=?'); $c->execute([$uid]); if ((int)$c->fetchColumn() >= 5) fail('You can have up to 5 tokens — revoke one first.');
        $raw = 'rcp_' . bin2hex(random_bytes(24));
        $db->prepare('INSERT INTO api_tokens (user_id, token_hash, label, created_at) VALUES (?,?,?,?)')->execute([$uid, hash('sha256', $raw), $str('label', 40) ?: 'My browser', time()]);
        out(['token' => $raw, 'server' => base_url() . '/'], 201);
    }
    case 'token.revoke': { $db->prepare('DELETE FROM api_tokens WHERE id=? AND user_id=?')->execute([(int)($in['id'] ?? 0), $uid]); out(['ok' => true]); }

    /* ---------------------------------------------------------- settings / account */
    case 'settings.save': {
        if (isset($in['theme']) && in_array($in['theme'], ['dark', 'light'], true)) $db->prepare('UPDATE users SET theme=? WHERE id=?')->execute([$in['theme'], $uid]);
        out(['ok' => true]);
    }
    case 'account.export': {
        $tables = ['items' => 'SELECT platform,kind,source,url,title,body,author,music_title,music_artist,interaction,interacted_at,view_count,pinned FROM items WHERE user_id=?',
            'saved_music' => 'SELECT title,artist,platform,story_title,status,created_at,listened_at FROM saved_music WHERE user_id=?',
            'connections' => 'SELECT platform,account_id,account_name,account_handle,status,last_sync_at,created_at FROM connections WHERE user_id=?',
            'my_post_performance' => 'SELECT platform,kind,url,title,posted_at,m_views,m_watch_seconds,m_likes,m_comments,m_shares,m_downloads,m_saves,engagement,pct,breakout FROM content WHERE owner_user_id=?',
            'sharing_preferences' => 'SELECT share_pool,share_circle,open_to_ads FROM user_prefs WHERE user_id=?',
            'ad_offers_received' => 'SELECT b.amount_cents,b.currency,b.days,b.message,b.link,b.status,b.created_at FROM bids b JOIN content c ON c.id=b.content_id WHERE c.owner_user_id=?',
            'advertiser_profile' => 'SELECT brand,website,keywords,categories,kinds,platforms,min_pct FROM advertisers WHERE user_id=?'];
        $dump = ['exported_at' => date('c'), 'user' => ['name' => $user['name'], 'email' => $user['email']]];
        foreach ($tables as $k => $q) { $s = $db->prepare($q); $s->execute([$uid]); $dump[$k] = $s->fetchAll(); }
        header('Content-Disposition: attachment; filename="current-recap-export.json"'); out($dump);
    }
    case 'account.delete': {
        if (($in['confirm'] ?? '') !== 'DELETE') fail('Type DELETE to confirm.');
        delete_user_data($uid); session_boot(); $_SESSION = []; session_destroy(); out(['ok' => true]);
    }

    case 'demo.load': {
        if (!demo_allowed()) fail('Disabled', 403);
        require_once __DIR__ . '/app/demo.php'; require_once __DIR__ . '/app/demo_engage.php'; $a = load_demo($uid); try { $a += load_demo_engagement($uid); trend_run(); } catch (Throwable $t) { error_log('[recap] ' . $t->getMessage()); }
        // real, public trending posts (Mastodon + Bluesky) so Trending is not only invented data; never blocks the sample data if the network is down
        $real = 0; $notes = [];
        if (cfg('OPEN_TRENDS')) { try { require_once __DIR__ . '/app/open_trends.php'; [$real, $notes] = open_trends_load(); } catch (Throwable $t) { error_log('[recap] open trends: ' . $t->getMessage()); $notes = ['could not load real posts']; } }
        // current topical videos and pictures: F1, sport, music, entertainment, fashion, tech, cars... (YouTube feeds + Mastodon hashtags)
        $topical = 0;
        if (cfg('TOPIC_MEDIA')) { try { require_once __DIR__ . '/app/topic_media.php'; [$topical, $tn] = topic_media_load(); $notes = array_merge($notes, $tn); } catch (Throwable $t) { error_log('[recap] topic media: ' . $t->getMessage()); $notes[] = 'topical videos could not be loaded'; } }
        // real song artwork + previews, podcasts, CC photos and videos replace the generated pictures on the sample rows
        $media = 0;
        if (cfg('LIVE_MEDIA')) { try { require_once __DIR__ . '/app/live_media.php'; $cat = live_media_catalog(); $media = live_media_apply_recap($uid, $cat) + live_media_apply_trending($cat); $notes = array_merge($notes, $cat['notes']); } catch (Throwable $t) { error_log('[recap] live media: ' . $t->getMessage()); $notes[] = 'real pictures could not be loaded'; } }
        out(['added' => $a, 'real' => $real + $topical, 'media' => $media, 'real_notes' => $notes]);
    }
}
fail('Unknown action', 404);
