<?php
declare(strict_types=1);
/** Tables for trending, circle, advertisers, bids and notifications. Called from migrate() on every start (IF NOT EXISTS). */
function migrate_engage(PDO $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS user_prefs (
      user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
      share_pool INTEGER NOT NULL DEFAULT 0,       -- opt in: my posts' engagement may appear in Trending / be offered ads
      share_circle INTEGER NOT NULL DEFAULT 1,     -- my accepted circle may see how my posts perform
      open_to_ads INTEGER NOT NULL DEFAULT 0       -- opt in: advertisers may send ad offers for my posts (I approve each)
    );
    CREATE TABLE IF NOT EXISTS content (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      owner_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,   -- NULL = imported public content
      platform TEXT NOT NULL, external_id TEXT, url TEXT, url_key TEXT,
      kind TEXT NOT NULL CHECK (kind IN ('video','image','text')),
      author TEXT, title TEXT, body TEXT, thumb_url TEXT, thumb_path TEXT,
      posted_at INTEGER NOT NULL,
      source TEXT NOT NULL DEFAULT 'api' CHECK (source IN ('api','import','demo')),
      m_views INTEGER, m_watch_seconds INTEGER, m_likes INTEGER, m_comments INTEGER, m_shares INTEGER, m_downloads INTEGER, m_saves INTEGER,
      engagement REAL NOT NULL DEFAULT 0, velocity REAL NOT NULL DEFAULT 0, trend REAL NOT NULL DEFAULT 0,
      pct INTEGER NOT NULL DEFAULT 0, breakout REAL NOT NULL DEFAULT 0,
      category TEXT, metrics_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL
    );
    CREATE UNIQUE INDEX IF NOT EXISTS idx_content_ext ON content(platform, external_id) WHERE external_id IS NOT NULL;
    CREATE UNIQUE INDEX IF NOT EXISTS idx_content_url ON content(url_key) WHERE url_key IS NOT NULL;
    CREATE INDEX IF NOT EXISTS idx_content_trend ON content(posted_at, trend DESC);
    CREATE INDEX IF NOT EXISTS idx_content_owner ON content(owner_user_id, posted_at DESC);
    CREATE TABLE IF NOT EXISTS content_metrics (content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE, at INTEGER NOT NULL, engagement REAL NOT NULL);
    CREATE INDEX IF NOT EXISTS idx_cm ON content_metrics(content_id, at);
    CREATE TABLE IF NOT EXISTS content_topics (content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE, topic TEXT NOT NULL, kind TEXT NOT NULL, PRIMARY KEY (content_id, topic));
    CREATE INDEX IF NOT EXISTS idx_ct_topic ON content_topics(topic);
    CREATE TABLE IF NOT EXISTS content_ai (content_id INTEGER PRIMARY KEY REFERENCES content(id) ON DELETE CASCADE, category TEXT, brands TEXT, products TEXT, sentiment TEXT, why TEXT, model TEXT, at INTEGER NOT NULL, ok INTEGER NOT NULL DEFAULT 1);
    CREATE TABLE IF NOT EXISTS ai_cache (k TEXT PRIMARY KEY, v TEXT NOT NULL, at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS circle (
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,      -- who asked
      member_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,    -- who was asked
      status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted')),
      created_at INTEGER NOT NULL, PRIMARY KEY (user_id, member_id)
    );
    CREATE TABLE IF NOT EXISTS advertisers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
      brand TEXT NOT NULL, website TEXT, keywords TEXT NOT NULL DEFAULT '', categories TEXT NOT NULL DEFAULT '',
      kinds TEXT NOT NULL DEFAULT '', platforms TEXT NOT NULL DEFAULT '',
      min_pct INTEGER NOT NULL DEFAULT 85, email_alerts INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS bids (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
      advertiser_id INTEGER NOT NULL REFERENCES advertisers(id) ON DELETE CASCADE,
      amount_cents INTEGER NOT NULL, currency TEXT NOT NULL DEFAULT 'ZAR', days INTEGER NOT NULL DEFAULT 7,
      message TEXT, link TEXT,
      status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','accepted','declined','outbid','withdrawn','expired','ended')),
      created_at INTEGER NOT NULL, decided_at INTEGER, starts_at INTEGER, ends_at INTEGER
    );
    CREATE INDEX IF NOT EXISTS idx_bids_content ON bids(content_id, status);
    CREATE INDEX IF NOT EXISTS idx_bids_adv ON bids(advertiser_id, status);
    CREATE TABLE IF NOT EXISTS notifications (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      type TEXT NOT NULL, title TEXT NOT NULL, body TEXT, content_id INTEGER, topic TEXT, ref TEXT NOT NULL,
      created_at INTEGER NOT NULL, read_at INTEGER, UNIQUE (user_id, ref)
    );
    CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id, read_at, created_at DESC);
    CREATE TABLE IF NOT EXISTS ad_mockups (   -- Muse Image ad pictures, one per advertiser and post
      advertiser_id INTEGER NOT NULL REFERENCES advertisers(id) ON DELETE CASCADE,
      content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
      path TEXT NOT NULL, prompt TEXT, at INTEGER NOT NULL, PRIMARY KEY (advertiser_id, content_id)
    );
    ");
    // columns added after the first release (SQLite has no ADD COLUMN IF NOT EXISTS)
    foreach ([['content', 'media_url', 'TEXT'], ['content', 'media_type', 'TEXT'], ['content_ai', 'transcript', 'TEXT'], ['content_ai', 'regions', 'TEXT'], ['bids', 'mockup_path', 'TEXT']] as [$t, $c, $type]) {
        $has = false; foreach ($db->query("PRAGMA table_info($t)")->fetchAll() as $r) if ($r['name'] === $c) $has = true;
        if (!$has) $db->exec("ALTER TABLE $t ADD COLUMN $c $type");
    }
}
