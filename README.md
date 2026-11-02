# Current Recap

A recap of what you interacted with on **Facebook, Instagram and Threads**, sorted into **Stories, Podcasts, Discussions and Posts & Reels** for later. It uses the Replay interface, the Current logo, **dark mode by default** with a light-mode switch, and a **Save for listening later** button on any story that has music.

Plain PHP 8.1+ (PDO + SQLite), no framework, no build step. Installable as a PWA.

## What Meta allows (read this first)

Meta's official APIs only let an app read **the signed-in user's own content**:

| Source | What you get |
|---|---|
| Instagram API with Instagram Login | Your posts and reels, and your stories (**professional accounts only**, stories live 24 h) |
| Threads API | Your threads and your replies |
| Facebook Login | Sign-in. Reading your Facebook posts needs the `user_posts` permission and **Meta App Review** |

Meta gives no app a user's **viewing history** (what they watched or opened), and Messenger and WhatsApp content is never read. To cover content a person *viewed*, Current Recap adds three non-API routes that the user controls:

1. **Browser extension** (`/extension.php` downloads it): adds Meta pages you stay on for a few seconds.
2. **Share target**: once installed as a PWA on Android, "Current Recap" shows up in the Share sheet of the Facebook, Instagram and Threads apps.
3. **Paste a link** on the Connections page.

Podcasts: a link is classed as a podcast when it points to Spotify, Apple Podcasts, Anchor, Buzzsprout etc. (or says `#podcast`). Music in stories: Instagram does not expose the music sticker through the API, so the song is detected from the caption (`🎵 Song - Artist`) and can always be added or corrected by hand.

## Set up the Meta app (developers.facebook.com)

1. **Create an app** (type *Business* or *Consumer*). Copy App ID / Secret into `config.local.php` (`META_APP_ID`, `META_APP_SECRET`).
2. Add **Facebook Login** and set *Valid OAuth Redirect URI* to `https://YOURSITE/callback-facebook.php`.
3. Add **Instagram API with Instagram Login**, copy the Instagram app ID / secret, redirect `https://YOURSITE/callback-instagram.php`.
4. Add the **Threads API** use case, copy its app ID / secret, redirect `https://YOURSITE/callback-threads.php`.
5. In *App settings → Basic* set the **Privacy Policy URL** (`/privacy.php`), **Terms** (`/terms.php`) and **Data deletion**: callback URL `/meta/data-deletion.php` (or instructions URL `/delete-data.php`). In Facebook Login settings set **Deauthorize callback** to `/meta/deauthorize.php`.
6. Add testers (*App roles*) while the app is in Development mode. Go **Live** after App Review.

`https://YOURSITE/health.php` prints every URL above with your real domain, and checks PHP extensions and folder permissions. Meta requires **HTTPS**.

App Review: request `instagram_business_basic`, `threads_basic`, `threads_read_replies` (and `user_posts` if you want Facebook posts, then add `FB_EXTRA_SCOPES => 'user_posts'`). Prepare a screencast and your privacy policy.

## Install

Upload the **contents of this folder** into your site's web root (e.g. `public_html/`): `index.html`, the `*.php` files, `assets/` and `meta/` run from the root, and `app/`, `cron/`, `tools/`, `extension/`, `storage/` stay as private folders beside them (blocked by the included `.htaccess` files; on nginx deny those paths yourself). If your host lets you, a document root of the old `public/` layout still works too.

```bash
cp config.local.example.php config.local.php   # fill in the Meta IDs and secrets
php -S localhost:8000                           # local try-out (run inside this folder)
```
Set `STORAGE_DIR` to a writable folder (ideally outside the web root) for the database, thumbnails and the encryption key. `storage/` must be writable by PHP. Back up that folder.

Optional cron keeps 24-hour stories captured: `*/30 * * * * php /path/to/cron/sync.php`

## Trending, My circle suggestions and Bid ads

Three tabs under **Discover** in the menu, plus a notification bell.

**Trending** ranks posts by an engagement model, per content type:

| Type | Signals used (weight) |
|---|---|
| Videos | watch time .35, views .25, shares .25, downloads .15 |
| Pictures | likes .45, downloads .30, comments .25 (saves stand in for downloads) |
| Text posts | comments .55, likes .30, shares .15 |

- Score = weighted sum of ln(1 + count). A metric the platform does not report (for example downloads) is left out and the other weights are re-normalised. It is never counted as zero.
- Velocity comes from snapshots taken over the last ~24 h (lifetime rate if there are none). Trend score = engagement x velocity boost x 0.5^(age / 72 h). Each post also gets a percentile within the pool of the last 14 days.
- Breakout = engagement divided by that author's median over 90 days. This feeds My circle suggestions.
- Topic model: hashtags, brands and categories (`app/lexicon.php`, edit it to add brands) plus common word pairs. A topic is "rising" at 1.8x its weekly norm with at least 2 posts from at least 2 creators.

**My circle suggestions** shows posts from people in your circle (mutual invitation, by email) that are taking off compared with what they normally get.

**Bid ads** is for advertisers. Create an advertiser profile (brand, keywords, categories, minimum percentile), then you get notifications and an Opportunities list. Example: a Nike sneaker range is rising, so Nike gets the opportunity; an RCP exhaust video is rising, so RCP is offered it. An advertiser sends an offer (amount, days, message, https link). **The creator must accept**; accepting the highest offer declines the rest. Accepted ads show a "Sponsored" label. **No money moves in this app**: it records offers and acceptances only, settle payment yourself.

### The "all users" limit
Meta does not let any app read other people's posts or their engagement numbers. So the pool is:
1. people who turn on **Share my post stats** (off by default), and **Open to ad offers** (off by default), and
2. public data you import under a licence you hold (`tools/import_content.php`, or `POST api.php?a=content.ingest` with header `X-Ingest-Key`). Imported items have no owner and cannot receive offers.

Own-post numbers arrive when someone syncs: Instagram likes and comments are available with the basic permission. Views, shares, saves and reel watch time need `instagram_business_manage_insights`, Threads numbers need `threads_manage_insights`, and Facebook posts need `user_posts` plus App Review. Turn on `ENGAGEMENT_INSIGHTS` only once those are approved.

### Config keys (`config.local.php`)
```php
'ENGAGEMENT_INSIGHTS' => false,   // true once Meta approved the insights scopes
'INGEST_KEY'          => '',      // long random string; empty disables the import endpoint
'BID_MIN_CENTS'       => 5000,    // smallest offer (cents)
'BID_CURRENCY'        => 'ZAR',
'ALERT_EMAILS'        => false,   // true = also e-mail advertisers who tick "e-mail me" (needs working mail())
```
Cron (every 15 min): `*/15 * * * * php /path/to/cron/trends.php`. It recomputes trends and sends notifications; the app also refreshes them on use.

### Sample data on a live server
Set `'DEMO_DATA' => true` in `config.local.php` (or env `RECAP_DEMO_DATA=1`). Every signed-in user then sees **Load sample data** on the home page. It adds fictional creators, circle and advertiser data to the shared database (Trending cards are tagged *Sample*) and fetches real Mastodon/Bluesky posts. This does not enable the dev login, which stays localhost-only; keep `DEV_LOGIN` false in production. Turn `DEMO_DATA` off again for a real launch. The sample users have `@example.invalid` addresses and cannot sign in.

### Current topical content (F1, sport, music, entertainment, fashion, tech, cars, gaming, travel)
**Load sample data** (and `php tools/fetch_open_trends.php`) also loads today's content for those topics, so Trending and the sample recap are full of real pictures and real videos:
- **YouTube:** the public upload feed of well-known channels (`app/topic_media.php`, list `TOPIC_FEEDS`). Each video keeps its real thumbnail, view count and like count and plays in YouTube's own embedded player (`youtube-nocookie.com`). Nothing is copied or re-hosted except a small thumbnail.
- **Mastodon hashtags:** public hashtag timelines (#formula1, #football, #fashion, #tech, #cars ...), pictures and videos only, ranked by likes and boosts.
- **Songs and podcasts:** Apple's current charts with artwork and 30-second previews.
- Every card names the author and links to the original. The topic (for example *motorsport*) sets the category.
- Add your own channels in `config.local.php`: `'TOPIC_MEDIA_EXTRA' => ['motorsport' => ['@SomeChannel'], 'fashion' => ['UCxxxxxxxxxxxxxxxxxxxxxx']]` (a `@handle` or a channel id). `TOPIC_MEDIA_TOPICS => 'motorsport,tech'` limits it to some topics, `TOPIC_MEDIA => false` turns it off.
- Trending hides the invented sample posts as soon as real ones exist (**+ Invented samples** brings them back).
- Needs internet access from the server. If a channel or feed cannot be reached it is skipped, and `php tools/fetch_open_trends.php` prints which ones. Test: `php tests/test_topic_media.php`.

### Real trending posts (Mastodon and Bluesky)
`php tools/fetch_open_trends.php` (also run by **Load sample data**) reads the public trending-posts endpoint of a few Mastodon instances and Bluesky's public *What's Hot* feed. No login or scraping. It stores only short text (280 characters), the author name and handle, a link to the original post, a small picture and the public counts, so every card links back to its author. Posts get the labels *Mastodon* / *Bluesky*, have no owner and cannot receive ad offers. Sensitive, content-warning and labelled posts are skipped. Run it again (or from cron) to refresh counts; growth ("Rising") needs two or more runs. Needs internet access. Settings: `OPEN_TRENDS`, `OPEN_TRENDS_MASTODON`, `OPEN_TRENDS_BLUESKY`, `OPEN_TRENDS_PER_SOURCE` in `config.local.php`. Facebook, Instagram and Threads posts of other people are still not available (Meta's rules).

Import licensed data: `php tools/import_content.php file.json` (or a `.csv` with the same column names; JSON is a list of `{platform, url, kind, author, title, body, posted_at, metrics:{views, watch_seconds, likes, comments, shares, downloads}}`).

Tests: `php tests/test_engage.php` (models, privacy filters, bid rules). The `tests/` folder is blocked from the web; delete it on the server if you like. In the dev login, **Load sample data** loads fictional circle and advertiser data (Trending cards marked *Sample*) and also fetches **real public trending posts** from Mastodon and Bluesky (see below). `php tests/test_open_trends.php` tests that loader against a local mock.

## Meta models (Muse) in the app

| Model | Used for | Config |
|---|---|---|
| **Muse Spark 1.3** (Meta Model API) | Tags each shared post (category, brands, products, why people engage, tone) and drafts the ad message in the offer dialog ("Suggest a message") | `META_AI_KEY`, `META_AI_TEXT_MODEL` |
| **Muse Wonder 1.0** (Meta Model API) | Writes the advertiser briefing at the top of Bid ads | `META_AI_BRIEF_MODEL` |
| **Muse Image** (Meta Model API) | "Create ad picture" button in the offer dialog | `META_AI_IMAGE_MODEL`, `META_AI_IMAGE_PER_DAY` |
| **Muse Voice Transcribe** (Meta Model API) | Transcribes the speech in shared videos so spoken brands count (needs `ffmpeg`) | `META_AI_VOICE*`, `META_AI_FFMPEG` |
| **SAM 3.1** (Meta Model API) | Finds the products Muse Spark named inside the post picture and draws boxes | `META_AI_SAM*` |
| **Muse Glimmer / Muse Spark Open** (open weights) | The same tagging job, run on your own server with SGLang or vLLM so post text never leaves it | `META_AI_LOCAL_URL`, `META_AI_LOCAL_MODEL` |

**Full explanation of every model, where it is called and what is untested: see `META-MODELS.md`.**

```php
'META_AI_KEY'        => '',                           // from dev.meta.ai (API Login). Keep it only in config.local.php, never in a repo or chat.
'META_AI_BASE_URL'   => 'https://api.meta.ai/v1',     // OpenAI-compatible; the app calls {base}/chat/completions
'META_AI_LOCAL_URL'  => 'http://127.0.0.1:8000/v1',   // optional open-weights server
'META_AI_VISION'     => false,                        // true = also send the picture (image_url) to the tagging model
'META_AI_MAX_PER_RUN'=> 15,
```
- Only posts people chose to share (and licensed imports) are ever sent. With no key, or on any API error, the keyword models keep working.
- Use the plain `muse-spark-1.3` model. The `-contributor` variants let Meta use the data to improve its products.
- Results are cached, each post is analysed once, and `cron/trends.php` does the work.
- Spark and Wonder use Chat Completions (`/v1/chat/completions`, documented at dev.meta.ai/docs/protocols/chat-completions) with `max_completion_tokens` and `reasoning_effort: low`. Image, speech and segmentation use `/v1/images/generations`, `/v1/asr/transcribe` and `/v1/responses`.
- Test: `php tests/test_meta_ai.php` (runs against a local stand-in in `tools/mock_meta.php`, no key needed).
- Real sample media: `'LIVE_MEDIA' => true` (default) makes **Load sample data** use today's Apple music and podcast charts (artwork + 30 s previews), Creative Commons photos from Openverse (creator and licence are written into each item) and trending Mastodon videos. Test: `php tests/test_live_media.php`.

## Try it without Meta

```bash
php -S 127.0.0.1:8300 tools/mock_meta.php       # fake Meta OAuth + Graph API
RECAP_DEV_LOGIN=1 RECAP_META_MOCK_BASE=http://127.0.0.1:8300 \
RECAP_META_APP_ID=app1 RECAP_META_APP_SECRET=sec1 RECAP_INSTAGRAM_APP_ID=ig1 RECAP_INSTAGRAM_APP_SECRET=sec2 \
RECAP_THREADS_APP_ID=th1 RECAP_THREADS_APP_SECRET=sec3 php -S 127.0.0.1:8301
```
Sign in with the localhost-only dev login and press **Load sample data**. Any `config.local.php` key can be set as `RECAP_<KEY>`.

## Security and privacy

- Meta access tokens are encrypted at rest (AES-256-GCM, key in `STORAGE_DIR/db/secret.key`), long-lived tokens are refreshed automatically.
- OAuth `state` is checked, POSTs need a CSRF header, extension tokens are stored only as SHA-256 hashes and can only add items.
- Deauthorize and data-deletion callbacks verify Meta's `signed_request` signature.
- Thumbnails are copied to your server (Meta CDN links expire) and served only to the owner. Remote fetches are restricted to Meta CDN hosts.
- Users can export all data and delete their account in Settings.
- There is no admin panel; user management is via the database.

## Layout
```
(root)       index.html, sw.js, manifest, api.php, oauth + callback files, legal pages, assets/, meta/ callbacks
app/         bootstrap, content model, Meta integration, engagement + ads models, demo data (private)
extension/   Chrome/Edge extension source (downloaded via extension.php)
tools/       mock_meta.php (dev only)
cron/        sync.php, trends.php
tests/       test_engage.php (private)
storage/     created at runtime (database, thumbnails, key)
```


## Troubleshooting: Connect buttons greyed out

Open `health.php`. `config_file_found` must be `true` (file name is exactly `config.local.php`, in the same folder as `index.html`) and `config_values_missing` must be empty. Keys are fixed names (`META_APP_ID`, `INSTAGRAM_APP_SECRET`, ...); only the value after `=>` is yours. Never put real secrets in a zip you share; reset them in the Meta dashboard if they leak.

## Recommended Meta app type

Use a **Consumer** app (no business portfolio attached) with the classic **Facebook Login** product. Apps set up for *Facebook Login for Business* reject classic permissions ("Invalid Scopes: email", "needs at least one supported permission"). Checklist: App domains `your-domain`, Valid OAuth Redirect URIs = the three URLs from `health.php`, privacy / terms / data-deletion URLs, then fill `config.local.php` and confirm `health.php` shows every `meta` value `true` and `config_values_missing` empty.
