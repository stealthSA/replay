<?php
declare(strict_types=1);
/**
 * Current Recap — bootstrap: configuration, helpers, database, sessions, auth.
 * Every entry point (root *.php, meta/*.php, cron/*.php) requires this file first.
 */
define('APP_ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

/* ------------------------------------------------------------------ config */
function cfg(string $key, $default = null) {
    static $c = null;
    if ($c === null) {
        $c = [
            'APP_NAME' => 'Current Recap',
            'BASE_URL' => '',                         // e.g. https://recap.example.com — auto-detected when empty
            'CONTACT_EMAIL' => 'privacy@example.com', // shown on the privacy / data-deletion pages (Meta requires one)
            'STORAGE_DIR' => APP_ROOT . '/storage',
            'DEV_LOGIN' => false,                     // localhost-only test login + sample data. Never enable in production.
            'ALLOW_PASSWORD_SIGNUP' => true,
            'DEMO_DATA' => false,                     // true = signed-in users see a "Load sample data" button on a LIVE server too (fictional creators + real Mastodon/Bluesky posts). Does NOT enable the dev login.
            // Meta app (developers.facebook.com → your app → App settings → Basic)
            'META_APP_ID' => '', 'META_APP_SECRET' => '',
            'META_GRAPH_VERSION' => 'v23.0',
            'FB_EXTRA_SCOPES' => '',                  // e.g. 'user_posts' once Meta has approved it (App Review)
            // "Instagram API with Instagram Login" product → Instagram app ID / secret (different from the Meta app ID)
            'INSTAGRAM_APP_ID' => '', 'INSTAGRAM_APP_SECRET' => '',
            // Threads product → Threads app ID / secret
            'THREADS_APP_ID' => '', 'THREADS_APP_SECRET' => '',
            // Point every Meta endpoint at tools/mock_meta.php for local testing, e.g. http://127.0.0.1:8300
            'META_MOCK_BASE' => '',
            'SYNC_MIN_INTERVAL' => 1800,              // seconds between automatic syncs of one connection
            'ENGAGEMENT_INSIGHTS' => false,           // true = also request Instagram/Threads insights permissions (views, shares, watch time). Needs Meta App Review for live use.
            'BID_MIN_CENTS' => 5000,                  // smallest ad bid, in cents of BID_CURRENCY
            'BID_CURRENCY' => 'ZAR',
            'ALERT_EMAILS' => false,                  // also e-mail advertisers who tick "e-mail me" (needs working PHP mail())
            // Meta Model API (Muse models). Key stays on the server. Empty key = keyword models only.
            'META_AI_KEY' => '', 'META_AI_BASE_URL' => 'https://api.meta.ai/v1',
            'META_AI_TEXT_MODEL' => 'muse-spark-1.3',  // tags posts, writes ad messages (not the -contributor variants: Meta may train on those)
            'META_AI_BRIEF_MODEL' => 'muse-wonder-1.0', // trend briefings for advertisers
            'META_AI_LOCAL_URL' => '', 'META_AI_LOCAL_MODEL' => '', // open-weights server (Muse Glimmer / Spark Open via vLLM or llama.cpp): used for post tagging so post text stays on your server
            'META_AI_VISION' => false,                // also send the post picture to the tagging model
            'META_AI_MAX_PER_RUN' => 15,
            'META_AI_IMAGE_MODEL' => 'muse-image-1.0',  // Muse Image: ad picture mock-ups in the offer dialog (POST /v1/images/generations)
            'META_AI_IMAGE_PER_DAY' => 20,              // cap per advertiser per day (each picture is billed by Meta)
            'META_AI_VOICE' => true,                    // Muse Voice Transcribe: what is SAID in shared videos (needs ffmpeg on the server)
            'META_AI_VOICE_MODEL' => 'muse-voice-transcribe-1.0', 'META_AI_VOICE_MAX' => 3, 'META_AI_FFMPEG' => 'ffmpeg',
            'META_AI_SAM' => true,                      // SAM 3.1: finds the products Muse Spark named inside the post picture
            'META_AI_SAM_MODEL' => 'sam-3.1', 'META_AI_SAM_MAX' => 5,              // posts analysed per trend run
            // Real public trending posts from open networks (no login needed): Mastodon's trending-posts endpoint and Bluesky's What's Hot feed.
            // Real pictures, song artwork, podcasts and videos for the sample data (all public, no keys): Apple's charts + iTunes previews, Openverse (CC-licensed photos), Mastodon videos.
            'LIVE_MEDIA' => true, 'LIVE_MEDIA_COUNTRY' => 'za',   // chart country (2 letters); falls back to 'us' if that chart is unavailable
            'LIVE_MEDIA_APPLE_RSS' => 'https://rss.applemarketingtools.com', 'LIVE_MEDIA_ITUNES' => 'https://itunes.apple.com', 'LIVE_MEDIA_OPENVERSE' => 'https://api.openverse.org',
            'TOPIC_MEDIA' => true,                    // real topical videos (YouTube) and pictures (Mastodon hashtags): F1, sport, music, entertainment, fashion, tech, cars, gaming, travel
            'TOPIC_MEDIA_PER_CHANNEL' => 4, 'TOPIC_MEDIA_TOPICS' => '', 'TOPIC_MEDIA_EXTRA' => [], 'TOPIC_YOUTUBE_BASE' => 'https://www.youtube.com', 'TOPIC_YOUTUBE_THUMBS' => 'https://i.ytimg.com',
            'REFRESH_BUTTON' => true, 'REFRESH_MIN_SECONDS' => 300,   // "Get latest content" button on Trending for signed-in users; at most one fetch per REFRESH_MIN_SECONDS for the whole site
            'CLEAR_IMPORTED_ONCE' => false,           // true = the next refresh.php run removes old imported posts outside TOPIC_PRIORITY, ONCE (remembered in the database), then you can leave it or set it false
            'TOPIC_PRIORITY' => 'motorsport,music,amapiano,hiphop,sport,entertainment,tech,fashion',   // these topics come first in Trending ('' = no preference)
            'OPEN_TRENDS_TOPICS_ONLY' => false,       // true = general Mastodon/Bluesky trending posts are kept only when they are about the TOPIC_PRIORITY topics (no random chatter)
            'OPEN_TRENDS' => true,                    // false = "Load sample data" and tools/fetch_open_trends.php skip them
            'OPEN_TRENDS_MASTODON' => 'mastodon.social,mastodon.online,fosstodon.org,mstdn.social', // comma list of instances (host, or http://host:port for local tests)
            'OPEN_TRENDS_BLUESKY' => 'https://public.api.bsky.app', // Bluesky public AppView ('' = skip Bluesky)
            'OPEN_TRENDS_PER_SOURCE' => 12,           // posts kept from each instance / feed
            'CRON_KEY' => '',                         // secret for refresh.php over the web (cron-job.org etc.). Empty = web refresh disabled (command-line cron still works)
            'INGEST_KEY' => '',                       // secret for licensed-data imports (tools/import_content.php, api.php?a=content.ingest). Empty = disabled
        ];
        $local = APP_ROOT . '/config.local.php';
        if (is_file($local)) { $o = require $local; if (is_array($o)) $c = array_replace($c, $o); }
        foreach (array_keys($c) as $k) { $e = getenv('RECAP_' . $k); if ($e !== false && $e !== '') $c[$k] = is_bool($c[$k]) ? filter_var($e, FILTER_VALIDATE_BOOLEAN) : $e; }
    }
    return $c[$key] ?? $default;
}

function base_url(): string {
    $b = rtrim((string)cfg('BASE_URL'), '/');
    if ($b !== '') return $b;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $dir = preg_replace('#/(meta|auth)$#', '', $dir);
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
/** May the sample-data button be used? Locally with DEV_LOGIN, or anywhere when DEMO_DATA is on. */
function demo_allowed(): bool { return (bool)cfg('DEMO_DATA') || ((bool)cfg('DEV_LOGIN') && is_loopback()); }
function is_loopback(): bool { return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); }

/* ----------------------------------------------------------------- output */
function out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------------------------------------------------------------- crypto */
/** App key for encrypting stored Meta tokens. Generated once, kept in storage/ (never in the web root). */
function app_key(): string {
    static $k = null; if ($k) return $k;
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/db'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $f = $dir . '/secret.key';
    if (!is_file($f)) { file_put_contents($f, bin2hex(random_bytes(32))); @chmod($f, 0600); }
    return $k = hex2bin(trim((string)file_get_contents($f)));
}
function enc(string $plain): string {
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}
function dec(?string $blob): ?string {
    if (!$blob) return null; $raw = base64_decode($blob, true); if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

/* -------------------------------------------------------------- database */
function db(): PDO {
    static $pdo = null; if ($pdo) return $pdo;
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/db'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $dir . '/recap.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    migrate($pdo);
    require_once __DIR__ . '/schema_engage.php'; migrate_engage($pdo);
    return $pdo;
}

function migrate(PDO $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      meta_id TEXT UNIQUE,                 -- Facebook user id when they signed in with Facebook
      email TEXT UNIQUE,
      name TEXT NOT NULL DEFAULT '',
      picture TEXT,
      password_hash TEXT,
      theme TEXT NOT NULL DEFAULT 'dark' CHECK (theme IN ('dark','light')),
      disabled INTEGER NOT NULL DEFAULT 0,
      created_at INTEGER NOT NULL,
      last_login INTEGER
    );
    CREATE TABLE IF NOT EXISTS connections (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      platform TEXT NOT NULL CHECK (platform IN ('facebook','instagram','threads')),
      account_id TEXT NOT NULL,
      account_name TEXT, account_handle TEXT, picture TEXT,
      token_enc TEXT,                       -- AES-256-GCM encrypted access token
      token_expires INTEGER,
      scopes TEXT,
      status TEXT NOT NULL DEFAULT 'ok' CHECK (status IN ('ok','expired','revoked','error')),
      last_sync_at INTEGER NOT NULL DEFAULT 0,
      last_sync_count INTEGER NOT NULL DEFAULT 0,
      last_error TEXT,
      created_at INTEGER NOT NULL,
      UNIQUE (user_id, platform, account_id)
    );
    CREATE TABLE IF NOT EXISTS items (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      platform TEXT NOT NULL,
      kind TEXT NOT NULL DEFAULT 'post' CHECK (kind IN ('story','podcast','discussion','post')),
      source TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('api','extension','share','manual','demo')),
      external_id TEXT,
      url TEXT, url_key TEXT,
      title TEXT, body TEXT, author TEXT,
      thumb_url TEXT, thumb_path TEXT, media_url TEXT, media_type TEXT,
      music_title TEXT, music_artist TEXT,
      music_source TEXT,                    -- 'detected' (parsed from the caption) or 'user'
      interaction TEXT NOT NULL DEFAULT 'viewed' CHECK (interaction IN ('posted','viewed','replied','shared')),
      interacted_at INTEGER NOT NULL,
      first_seen_at INTEGER NOT NULL,
      view_count INTEGER NOT NULL DEFAULT 1,
      dwell_seconds INTEGER NOT NULL DEFAULT 0,
      pinned INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_items_user_kind ON items(user_id, kind, interacted_at DESC);
    CREATE INDEX IF NOT EXISTS idx_items_user_time ON items(user_id, interacted_at DESC);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_items_ext ON items(user_id, platform, external_id) WHERE external_id IS NOT NULL;
    CREATE UNIQUE INDEX IF NOT EXISTS idx_items_url ON items(user_id, url_key) WHERE url_key IS NOT NULL;
    CREATE TABLE IF NOT EXISTS saved_music (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      item_id INTEGER REFERENCES items(id) ON DELETE SET NULL,
      title TEXT NOT NULL, artist TEXT NOT NULL DEFAULT '',
      platform TEXT, story_title TEXT, thumb_item INTEGER,
      status TEXT NOT NULL DEFAULT 'later' CHECK (status IN ('later','listened')),
      created_at INTEGER NOT NULL, listened_at INTEGER,
      song_key TEXT NOT NULL,
      UNIQUE (user_id, song_key)
    );
    CREATE TABLE IF NOT EXISTS api_tokens (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      token_hash TEXT NOT NULL UNIQUE, label TEXT NOT NULL,
      created_at INTEGER NOT NULL, last_used_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS deletion_requests (
      code TEXT PRIMARY KEY, platform TEXT, account_id TEXT,
      requested_at INTEGER NOT NULL, completed_at INTEGER, note TEXT
    );
    ");
}

/* --------------------------------------------------------------- session */
function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_name('recap_sid');
    session_start();
}
function csrf(): string { session_boot(); return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function check_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf(), (string)$sent)) fail('Session expired — please reload the page.', 403);
}

function session_user(): ?array {
    session_boot();
    if (empty($_SESSION['uid'])) return null;
    $s = db()->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$_SESSION['uid']]);
    $u = $s->fetch();
    return ($u && !$u['disabled']) ? $u : null;
}
function login_user(array $u): void {
    session_boot(); session_regenerate_id(true); $_SESSION['uid'] = (int)$u['id'];
    db()->prepare('UPDATE users SET last_login=? WHERE id=?')->execute([time(), $u['id']]);
}
function public_user(array $u): array {
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'picture' => $u['picture'], 'theme' => $u['theme'], 'viaMeta' => !empty($u['meta_id'])];
}
/** Extension / share tokens: stored only as SHA-256 hashes; they can only add items. */
function token_user(string $raw): ?array {
    if (!preg_match('/^rcp_[a-f0-9]{48}$/', $raw)) return null;
    $s = db()->prepare('SELECT t.id AS tid, t.last_used_at AS tused, u.* FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=?');
    $s->execute([hash('sha256', $raw)]); $r = $s->fetch();
    if (!$r || $r['disabled']) return null;
    if (time() - (int)$r['tused'] > 60) db()->prepare('UPDATE api_tokens SET last_used_at=? WHERE id=?')->execute([time(), $r['tid']]);
    return $r;
}

/** Deletes a user and everything attached (cascade) plus cached thumbnails. */
function delete_user_data(int $uid): void {
    $cs = db()->prepare('SELECT thumb_path FROM content WHERE owner_user_id=? AND thumb_path IS NOT NULL'); $cs->execute([$uid]);
    foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $tp) @unlink(rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $tp);
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $uid;
    if (is_dir($dir)) { foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
}
