<?php
declare(strict_types=1);
/**
 * Meta integration: OAuth (Facebook Login, Instagram API with Instagram Login, Threads API), Graph fetchers,
 * token refresh, sync, and signed_request verification for the deauthorize / data-deletion callbacks.
 *
 * What the APIs can and cannot give us is in README.md ("What Meta lets an app see"). In short: your OWN posts, stories
 * (professional Instagram accounts) and threads/replies — not what you merely watched. Viewed content comes from the
 * browser extension / Share / paste-a-link paths.
 */
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/engage_sync.php';

function meta_urls(): array {
    $v = (string)cfg('META_GRAPH_VERSION');
    $mock = rtrim((string)cfg('META_MOCK_BASE'), '/');
    if ($mock !== '') return [
        'fb_dialog' => "$mock/fb/dialog/oauth", 'fb_graph' => "$mock/fb/graph",
        'ig_authorize' => "$mock/ig/authorize", 'ig_token' => "$mock/ig/token", 'ig_graph' => "$mock/ig/graph", 'ig_long' => "$mock/ig/graph/access_token", 'ig_refresh' => "$mock/ig/graph/refresh_access_token",
        'th_authorize' => "$mock/th/authorize", 'th_token' => "$mock/th/token", 'th_graph' => "$mock/th/graph", 'th_long' => "$mock/th/graph/access_token", 'th_refresh' => "$mock/th/graph/refresh_access_token",
    ];
    return [
        'fb_dialog' => "https://www.facebook.com/$v/dialog/oauth", 'fb_graph' => "https://graph.facebook.com/$v",
        'ig_authorize' => 'https://www.instagram.com/oauth/authorize', 'ig_token' => 'https://api.instagram.com/oauth/access_token',
        'ig_graph' => "https://graph.instagram.com/$v", 'ig_long' => 'https://graph.instagram.com/access_token', 'ig_refresh' => 'https://graph.instagram.com/refresh_access_token',
        'th_authorize' => 'https://threads.net/oauth/authorize', 'th_token' => 'https://graph.threads.net/oauth/access_token',
        'th_graph' => 'https://graph.threads.net/v1.0', 'th_long' => 'https://graph.threads.net/access_token', 'th_refresh' => 'https://graph.threads.net/refresh_access_token',
    ];
}

function meta_credentials(string $platform): array {
    return match ($platform) {
        'facebook' => [(string)cfg('META_APP_ID'), (string)cfg('META_APP_SECRET')],
        'instagram' => [(string)cfg('INSTAGRAM_APP_ID'), (string)cfg('INSTAGRAM_APP_SECRET')],
        'threads' => [(string)cfg('THREADS_APP_ID'), (string)cfg('THREADS_APP_SECRET')],
        default => ['', ''],
    };
}
function meta_configured(string $platform): bool { [$id, $sec] = meta_credentials($platform); return $id !== '' && $sec !== ''; }
function redirect_uri(string $platform): string { return base_url() . '/callback-' . $platform . '.php'; }

function meta_scopes(string $platform): string {
    return match ($platform) {
        'facebook' => trim('public_profile,' . (string)cfg('FB_EXTRA_SCOPES'), ','),   // add 'email' to FB_EXTRA_SCOPES once the app has that permission
        'instagram' => 'instagram_business_basic' . (cfg('ENGAGEMENT_INSIGHTS') ? ',instagram_business_manage_insights' : ''),
        'threads' => 'threads_basic,threads_read_replies' . (cfg('ENGAGEMENT_INSIGHTS') ? ',threads_manage_insights' : ''),
        default => '',
    };
}

/** Starts OAuth: returns the provider's authorization URL and remembers state in the session. */
/**
 * The OAuth "state" is signed with the app key instead of being matched against a session cookie. On phones the sign-in often finishes in a different
 * browser than it started in (the Facebook app's built-in browser, a Custom Tab, an installed PWA), so the session cookie from the start is not there on return.
 */
function oauth_state_make(string $platform, string $mode, string $h = ''): string {
    $p = rtrim(strtr(base64_encode(json_encode(['p' => $platform, 'm' => $mode, 't' => time(), 'n' => bin2hex(random_bytes(8)), 'h' => $h])), '+/', '-_'), '=');
    return $p . '.' . hash_hmac('sha256', $p, app_key());
}
/** @return array|null ['platform','mode','at'] when the signature is valid and the link is younger than 15 minutes */
function oauth_state_check(string $state, string $platform): ?array {
    if (substr_count($state, '.') !== 1) return null;
    [$p, $sig] = explode('.', $state); if (!hash_equals(hash_hmac('sha256', $p, app_key()), $sig)) return null;
    $d = json_decode((string)base64_decode(strtr($p, '-_', '+/'), true), true);
    if (!is_array($d) || ($d['p'] ?? '') !== $platform || !in_array($d['m'] ?? '', ['login', 'connect'], true) || time() - (int)($d['t'] ?? 0) > 900) return null;
    return ['platform' => $platform, 'mode' => $d['m'], 'at' => (int)$d['t'], 'state' => $state, 'h' => preg_match('/^[a-f0-9]{64}$/', (string)($d['h'] ?? '')) ? $d['h'] : ''];
}

/**
 * Hand-off for installed apps (PWA). The Facebook sign-in can finish in a different browser than the installed app (iOS opens a separate sheet), so the app
 * cannot see that login. The app keeps a secret, sends only its SHA-256 in the sign-in link, and after Facebook finishes the server parks the user id under that hash.
 * The app then trades its secret for a session. One use, 10 minutes.
 */
function handoff_table(): void { db()->exec('CREATE TABLE IF NOT EXISTS login_handoff (h TEXT PRIMARY KEY, user_id INTEGER NOT NULL, at INTEGER NOT NULL)'); }
function handoff_store(string $h, int $uid): void { handoff_table(); db()->prepare('DELETE FROM login_handoff WHERE at < ?')->execute([time() - 600]); db()->prepare('INSERT OR REPLACE INTO login_handoff (h, user_id, at) VALUES (?,?,?)')->execute([$h, $uid, time()]); }
function handoff_claim(string $secret): ?int {
    if (!preg_match('/^[a-f0-9]{32,128}$/', $secret)) return null; handoff_table(); $h = hash('sha256', $secret);
    $s = db()->prepare('SELECT user_id FROM login_handoff WHERE h=? AND at >= ?'); $s->execute([$h, time() - 600]); $uid = $s->fetchColumn(); if ($uid === false) return null;
    db()->prepare('DELETE FROM login_handoff WHERE h=?')->execute([$h]); return (int)$uid;
}

function oauth_start_url(string $platform, string $mode, string $handoff = ''): string {
    session_boot(); $u = meta_urls(); [$id] = meta_credentials($platform);
    $state = oauth_state_make($platform, $mode, $handoff);
    $_SESSION['oauth'] = ['state' => $state, 'platform' => $platform, 'mode' => $mode, 'at' => time()];
    $q = ['client_id' => $id, 'redirect_uri' => redirect_uri($platform), 'state' => $state, 'scope' => meta_scopes($platform), 'response_type' => 'code'];
    $base = ['facebook' => $u['fb_dialog'], 'instagram' => $u['ig_authorize'], 'threads' => $u['th_authorize']][$platform];
    if ($platform === 'instagram') $q['enable_fb_login'] = '0';
    return $base . '?' . http_build_query($q);
}

/* ----------------------------------------------------------------- http */
/** @return array [status, decoded json|null] */
function http_json(string $method, string $url, array $params = [], int $timeout = 20): array {
    $ch = curl_init();
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'CurrentRecap/1.0', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];
    if ($method === 'POST') { $opt[CURLOPT_URL] = $url; $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = http_build_query($params); }
    else { $opt[CURLOPT_URL] = $params ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params) : $url; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false) return [0, ['error' => ['message' => 'Network error: ' . $err]]];
    return [$code, json_decode((string)$body, true)];
}
function meta_error(?array $j, int $code): string {
    $m = $j['error']['message'] ?? ($j['error_message'] ?? ($j['error_description'] ?? null));
    if (is_array($j['error'] ?? null)) { /* Graph style */ } elseif (isset($j['error']) && is_string($j['error'])) $m = $m ?: $j['error'];
    return $m ? (string)$m : 'Unexpected reply from Meta (HTTP ' . $code . ')';
}
function meta_error_is_auth(?array $j): bool {
    $c = (int)($j['error']['code'] ?? 0);
    return in_array($c, [190, 102, 463, 467], true) || (($j['error']['type'] ?? '') === 'OAuthException' && $c === 190);
}

/* ------------------------------------------------------ token exchange */
/** Exchanges the OAuth code for a long-lived token and loads the profile. @return array [profile, token, expires, scopes] */
function oauth_complete(string $platform, string $code): array {
    $u = meta_urls(); [$id, $secret] = meta_credentials($platform); $redir = redirect_uri($platform);
    if ($platform === 'facebook') {
        [$c, $j] = http_json('GET', $u['fb_graph'] . '/oauth/access_token', ['client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => $redir, 'code' => $code]);
        if ($c !== 200 || empty($j['access_token'])) throw new RuntimeException(meta_error($j, $c));
        $tok = (string)$j['access_token']; $exp = isset($j['expires_in']) ? time() + (int)$j['expires_in'] : null;
        [$c, $l] = http_json('GET', $u['fb_graph'] . '/oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $id, 'client_secret' => $secret, 'fb_exchange_token' => $tok]);
        if ($c === 200 && !empty($l['access_token'])) { $tok = (string)$l['access_token']; $exp = isset($l['expires_in']) ? time() + (int)$l['expires_in'] : $exp; }
        [$c, $me] = http_json('GET', $u['fb_graph'] . '/me', ['fields' => 'id,name,email,picture.type(large)', 'access_token' => $tok]);
        if ($c !== 200 || empty($me['id'])) throw new RuntimeException(meta_error($me, $c));
        [$c, $perm] = http_json('GET', $u['fb_graph'] . '/me/permissions', ['access_token' => $tok]);
        $granted = []; foreach (($perm['data'] ?? []) as $p) if (($p['status'] ?? '') === 'granted') $granted[] = $p['permission'];
        return [['id' => (string)$me['id'], 'name' => (string)($me['name'] ?? ''), 'handle' => null, 'email' => $me['email'] ?? null, 'picture' => $me['picture']['data']['url'] ?? null], $tok, $exp, implode(',', $granted)];
    }
    if ($platform === 'instagram') {
        [$c, $j] = http_json('POST', $u['ig_token'], ['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'authorization_code', 'redirect_uri' => $redir, 'code' => $code]);
        if (isset($j['data'][0])) $j = $j['data'][0];   // some responses wrap in data[]
        if (empty($j['access_token'])) throw new RuntimeException(meta_error($j, $c));
        $tok = (string)$j['access_token']; $exp = time() + 3600; $perms = is_array($j['permissions'] ?? null) ? implode(',', $j['permissions']) : (string)($j['permissions'] ?? '');
        [$c, $l] = http_json('GET', $u['ig_long'], ['grant_type' => 'ig_exchange_token', 'client_secret' => $secret, 'access_token' => $tok]);
        if ($c === 200 && !empty($l['access_token'])) { $tok = (string)$l['access_token']; $exp = time() + (int)($l['expires_in'] ?? 5184000); }
        [$c, $me] = http_json('GET', $u['ig_graph'] . '/me', ['fields' => 'user_id,username,name,account_type,profile_picture_url', 'access_token' => $tok]);
        if ($c !== 200) throw new RuntimeException(meta_error($me, $c));
        $aid = (string)($me['user_id'] ?? $me['id'] ?? $j['user_id'] ?? '');
        if ($aid === '') throw new RuntimeException('Instagram did not return an account id.');
        return [['id' => $aid, 'name' => (string)($me['name'] ?? $me['username'] ?? ''), 'handle' => $me['username'] ?? null, 'picture' => $me['profile_picture_url'] ?? null], $tok, $exp, $perms ?: 'instagram_business_basic'];
    }
    // threads
    [$c, $j] = http_json('POST', $u['th_token'], ['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'authorization_code', 'redirect_uri' => $redir, 'code' => $code]);
    if (empty($j['access_token'])) throw new RuntimeException(meta_error($j, $c));
    $tok = (string)$j['access_token']; $exp = time() + 3600;
    [$c, $l] = http_json('GET', $u['th_long'], ['grant_type' => 'th_exchange_token', 'client_secret' => $secret, 'access_token' => $tok]);
    if ($c === 200 && !empty($l['access_token'])) { $tok = (string)$l['access_token']; $exp = time() + (int)($l['expires_in'] ?? 5184000); }
    [$c, $me] = http_json('GET', $u['th_graph'] . '/me', ['fields' => 'id,username,name,threads_profile_picture_url', 'access_token' => $tok]);
    if ($c !== 200 || empty($me['id'])) throw new RuntimeException(meta_error($me, $c));
    return [['id' => (string)$me['id'], 'name' => (string)($me['name'] ?? $me['username'] ?? ''), 'handle' => $me['username'] ?? null, 'picture' => $me['threads_profile_picture_url'] ?? null], $tok, $exp, meta_scopes('threads')];
}

function save_connection(int $uid, string $platform, array $profile, string $token, ?int $expires, string $scopes): int {
    db()->prepare('INSERT INTO connections (user_id, platform, account_id, account_name, account_handle, picture, token_enc, token_expires, scopes, status, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,\'ok\',?)
                   ON CONFLICT(user_id, platform, account_id) DO UPDATE SET account_name=excluded.account_name, account_handle=excluded.account_handle, picture=excluded.picture,
                     token_enc=excluded.token_enc, token_expires=excluded.token_expires, scopes=excluded.scopes, status=\'ok\', last_error=NULL')
        ->execute([$uid, $platform, $profile['id'], $profile['name'], $profile['handle'], $profile['picture'], enc($token), $expires, $scopes, time()]);
    $s = db()->prepare('SELECT id FROM connections WHERE user_id=? AND platform=? AND account_id=?'); $s->execute([$uid, $platform, $profile['id']]);
    return (int)$s->fetchColumn();
}

/** Instagram/Threads long-lived tokens (60 days) can be refreshed; Facebook ones need the user to log in again. */
function refresh_token_if_needed(array &$conn): void {
    if (!in_array($conn['platform'], ['instagram', 'threads'], true) || empty($conn['token_expires'])) return;
    $left = (int)$conn['token_expires'] - time();
    if ($left > 7 * 86400) return;
    if ($left <= 0) { // already expired — refresh only works on unexpired tokens
        db()->prepare("UPDATE connections SET status='expired', last_error='Access expired — reconnect this account.' WHERE id=?")->execute([$conn['id']]); $conn['status'] = 'expired'; return;
    }
    $u = meta_urls(); $tok = dec($conn['token_enc']); if (!$tok) return;
    [$c, $j] = http_json('GET', $conn['platform'] === 'instagram' ? $u['ig_refresh'] : $u['th_refresh'], ['grant_type' => $conn['platform'] === 'instagram' ? 'ig_refresh_token' : 'th_refresh_token', 'access_token' => $tok]);
    if ($c === 200 && !empty($j['access_token'])) {
        $exp = time() + (int)($j['expires_in'] ?? 5184000);
        db()->prepare('UPDATE connections SET token_enc=?, token_expires=? WHERE id=?')->execute([enc((string)$j['access_token']), $exp, $conn['id']]);
        $conn['token_enc'] = enc((string)$j['access_token']); $conn['token_expires'] = $exp;
    }
}

/* ----------------------------------------------------------------- sync */
function ts(?string $iso): int { $t = $iso ? strtotime($iso) : false; return $t ?: time(); }

/** Pulls what the API allows for one connection and upserts it. @return array [count, message|null] */
function sync_connection(array $conn): array {
    $uid = (int)$conn['user_id']; $u = meta_urls();
    refresh_token_if_needed($conn);
    if ($conn['status'] !== 'ok') return [0, $conn['last_error'] ?: 'Reconnect this account.'];
    $tok = dec($conn['token_enc']); if (!$tok) { return [0, 'Stored token could not be read — reconnect.']; }
    $n = 0; $notes = [];
    $fetch = function (string $url, array $params) use ($tok, &$conn): ?array {
        [$c, $j] = http_json('GET', $url, $params + ['access_token' => $tok]);
        if ($c === 200 && is_array($j)) return $j;
        if (meta_error_is_auth($j)) {
            db()->prepare("UPDATE connections SET status='expired', last_error=? WHERE id=?")->execute(['Meta says this access has expired or was revoked — reconnect.', $conn['id']]);
            throw new RuntimeException('Meta says this access has expired or was revoked — reconnect.');
        }
        throw new RuntimeException(meta_error($j, $c));
    };
    try {
        if ($conn['platform'] === 'facebook') {
            if (!str_contains((string)$conn['scopes'], 'user_posts')) {
                $notes[] = 'Facebook only shares your own posts with apps that have the user_posts permission (needs Meta App Review). Add it to FB_EXTRA_SCOPES and reconnect once approved.';
            } else {
                $j = $fetch($u['fb_graph'] . '/me/posts', ['fields' => 'id,message,story,created_time,permalink_url,full_picture,status_type,attachments{type,url,title,media{image{src}}},likes.summary(true).limit(0),comments.summary(true).limit(0),shares', 'limit' => 50]);
                engage_facebook($uid, $conn, $j['data'] ?? []);
                foreach ($j['data'] ?? [] as $p) {
                    $text = (string)($p['message'] ?? $p['story'] ?? ''); $link = $p['attachments']['data'][0]['url'] ?? '';
                    $isMedia = in_array($p['status_type'] ?? '', ['added_photos', 'added_video', 'shared_story'], true) && $text === '';
                    $kind = looks_like_podcast($link, $text) ? 'podcast' : ($isMedia ? 'post' : 'discussion');
                    upsert_item($uid, ['platform' => 'facebook', 'kind' => $kind, 'source' => 'api', 'external_id' => (string)$p['id'], 'url' => $p['permalink_url'] ?? '',
                        'title' => mb_substr($text !== '' ? $text : ($p['attachments']['data'][0]['title'] ?? 'Facebook post'), 0, 120), 'body' => $text, 'author' => $conn['account_name'],
                        'thumb_url' => $p['full_picture'] ?? null, 'interaction' => 'posted', 'interacted_at' => ts($p['created_time'] ?? null)]);
                    $n++;
                }
            }
        } elseif ($conn['platform'] === 'instagram') {
            $fields = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp';
            $stories = []; $media = [];
            try { $stories = $fetch($u['ig_graph'] . '/me/stories', ['fields' => $fields, 'limit' => 50])['data'] ?? []; }
            catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'expired')) throw $e; $notes[] = 'Stories: ' . $e->getMessage(); }
            $media = $fetch($u['ig_graph'] . '/me/media', ['fields' => $fields . ',like_count,comments_count', 'limit' => 50])['data'] ?? [];
            foreach ($stories as $p) { $n += ig_item($uid, $conn, $p, 'story'); }
            foreach ($media as $p) { $n += ig_item($uid, $conn, $p, null); }
            $notes = array_merge($notes, engage_instagram($uid, $conn, $media, $fetch, $u));
        } else { // threads
            $f = 'id,text,permalink,timestamp,media_type,media_url,thumbnail_url,username,is_quote_post';
            $threads = $fetch($u['th_graph'] . '/me/threads', ['fields' => $f, 'limit' => 50])['data'] ?? [];
            foreach ($threads as $p) { $n += th_item($uid, $conn, $p, 'posted'); }
            $notes = array_merge($notes, engage_threads($uid, $conn, $threads, $fetch, $u));
            try { foreach ($fetch($u['th_graph'] . '/me/replies', ['fields' => $f, 'limit' => 50])['data'] ?? [] as $p) { $n += th_item($uid, $conn, $p, 'replied'); } }
            catch (RuntimeException $e) { if (str_contains($e->getMessage(), 'expired')) throw $e; $notes[] = 'Replies: ' . $e->getMessage(); }
        }
        db()->prepare("UPDATE connections SET last_sync_at=?, last_sync_count=?, last_error=?, status='ok' WHERE id=?")->execute([time(), $n, $notes ? implode(' ', $notes) : null, $conn['id']]);
        return [$n, $notes ? implode(' ', $notes) : null];
    } catch (RuntimeException $e) {
        $st = $conn['status'];
        db()->prepare('UPDATE connections SET last_sync_at=?, last_error=?, status=CASE WHEN status=\'ok\' THEN \'error\' ELSE status END WHERE id=?')->execute([time(), $e->getMessage(), $conn['id']]);
        return [$n, $e->getMessage()];
    }
}

function ig_item(int $uid, array $conn, array $p, ?string $forceKind): int {
    $caption = (string)($p['caption'] ?? ''); $type = (string)($p['media_type'] ?? '');
    $kind = $forceKind ?? (looks_like_podcast('', $caption) ? 'podcast' : 'post');
    upsert_item($uid, ['platform' => 'instagram', 'kind' => $kind, 'source' => 'api', 'external_id' => (string)$p['id'], 'url' => $p['permalink'] ?? '',
        'title' => $caption !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $caption), 0, 120) : ($kind === 'story' ? 'Instagram story' : 'Instagram ' . strtolower($p['media_product_type'] ?? 'post')),
        'body' => $caption, 'author' => $conn['account_handle'] ?: $conn['account_name'],
        'thumb_url' => $p['thumbnail_url'] ?? ($type === 'IMAGE' || $type === 'CAROUSEL_ALBUM' ? ($p['media_url'] ?? null) : null),
        'media_url' => $p['media_url'] ?? null, 'media_type' => $type === 'VIDEO' ? 'video' : 'image', 'interaction' => 'posted', 'interacted_at' => ts($p['timestamp'] ?? null)]);
    return 1;
}
function th_item(int $uid, array $conn, array $p, string $interaction): int {
    $text = (string)($p['text'] ?? '');
    upsert_item($uid, ['platform' => 'threads', 'kind' => looks_like_podcast('', $text) ? 'podcast' : 'discussion', 'source' => 'api', 'external_id' => (string)$p['id'], 'url' => $p['permalink'] ?? '',
        'title' => $text !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 120) : 'Thread', 'body' => $text, 'author' => $p['username'] ?? $conn['account_handle'],
        'thumb_url' => $p['thumbnail_url'] ?? (($p['media_type'] ?? '') === 'IMAGE' ? ($p['media_url'] ?? null) : null), 'media_url' => $p['media_url'] ?? null,
        'media_type' => ($p['media_type'] ?? '') === 'VIDEO' ? 'video' : (($p['media_type'] ?? '') === 'IMAGE' ? 'image' : null), 'interaction' => $interaction, 'interacted_at' => ts($p['timestamp'] ?? null)]);
    return 1;
}

function sync_user(int $uid, bool $force = false): array {
    $s = db()->prepare('SELECT * FROM connections WHERE user_id=?'); $s->execute([$uid]);
    $res = ['synced' => 0, 'new' => 0, 'errors' => []];
    foreach ($s->fetchAll() as $c) {
        if (!$force && time() - (int)$c['last_sync_at'] < (int)cfg('SYNC_MIN_INTERVAL')) continue;
        $before = (int)db()->query('SELECT COUNT(*) FROM items WHERE user_id=' . (int)$uid)->fetchColumn();
        [$n, $err] = sync_connection($c);
        $after = (int)db()->query('SELECT COUNT(*) FROM items WHERE user_id=' . (int)$uid)->fetchColumn();
        $res['synced'] += $n; $res['new'] += $after - $before;
        $st = db()->prepare('SELECT status FROM connections WHERE id=?'); $st->execute([$c['id']]);
        if ($err && $st->fetchColumn() !== 'ok') $res['errors'][] = PLATFORMS[$c['platform']]['name'] . ': ' . $err;
    }
    if ($res['synced']) { try { trend_run(); } catch (Throwable $t) { error_log('[recap] trend_run: ' . $t->getMessage()); } }   // refresh Trending / alerts with the new numbers
    return $res;
}

/* ------------------------------------------------- signed_request (Meta) */
/** Verifies a signed_request from a deauthorize / data-deletion callback. @return array [platform, payload]|null */
function verify_signed_request(string $sr): ?array {
    if (!str_contains($sr, '.')) return null;
    [$sig, $payload] = explode('.', $sr, 2);
    $b64 = fn(string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    $sigRaw = $b64($sig); $json = $b64($payload);
    if ($sigRaw === false || $json === false) return null;
    $data = json_decode($json, true); if (!is_array($data) || ($data['algorithm'] ?? '') !== 'HMAC-SHA256') return null;
    foreach (['facebook', 'instagram', 'threads'] as $p) {
        [, $secret] = meta_credentials($p); if ($secret === '') continue;
        if (hash_equals(hash_hmac('sha256', $payload, $secret, true), $sigRaw)) return [$p, $data];
    }
    return null;
}
