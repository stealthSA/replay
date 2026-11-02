# Current – Stay Current

A progressive web app (PHP 8.1+ · SQLite · vanilla JS) styled on the Current badge. It lets you open Meta's platforms,
keeps a per-platform history of what you interacted with, and automatically clears content you never come back to
(default **24 hours**, adjustable), using a small per-user machine-learning model to spare things you're likely to revisit.

## Requirements
PHP 8.1+ with `pdo_sqlite` and `curl`, served over **HTTPS** (required for PWA install, service worker and Google sign-in).

## Setup
1. Upload the folder to your host (shared hosting is fine).
2. **Firebase:** in the [Firebase console](https://console.firebase.google.com) create/open a project, then
   - *Build → Authentication → Sign-in method* → enable **Google**;
   - *Authentication → Settings → Authorized domains* → add your site's domain;
   - *Project settings → Your apps* → add a **Web app** and copy its config.
   Then `cp config.local.example.php config.local.php` and fill in `FIREBASE_API_KEY`, `FIREBASE_PROJECT_ID`,
   `FIREBASE_AUTH_DOMAIN`, `FIREBASE_APP_ID`. (These web-config values are public by design; no service-account key is needed.)
3. Make `data/` writable by PHP. Better: set `DATA_DIR` to a folder **outside** the web root.
4. Optional but recommended – cron every 15 min so cleanup runs even when nobody is using the app:
   `*/15 * * * * php /path/to/replay/cron/cleanup.php`
   (Without cron, each user's history is also cleaned when they use the app.)
5. Sign in with `softwarefanatics@gmail.com` – that account is automatically the **admin**.

**nginx:** `.htaccess` is ignored, so add: `location ~ ^/(data|lib|cron)/ { deny all; }` and
`location ~ ^/config.*\.php$ { deny all; }`.

## Features
- **Firebase Authentication with Google sign-in.** The browser signs in with the Firebase JS SDK; the PHP server then verifies the
  Firebase ID token itself (RS256 signature against Google's published keys, audience = your project, issuer, expiry, verified email,
  Google provider) and starts its own session. Users and history stay in SQLite.
- **Admin** (`softwarefanatics@gmail.com`): users list with enable/disable, global retention limits & default,
  run cleanup for everyone, cleanup log.
- **Meta platforms:** Facebook, Instagram, Messenger, WhatsApp, Threads, Meta AI, Meta Quest.
- **History** filtered by platform, searchable, with pin, delete and countdown to auto-clean.
- **Auto-clean with a configurable range:** default 24 h; each user picks any value between the admin's min and max
  (default 1 hour – 30 days) via slider, number box or presets.
- **Machine learning:** see below.
- **PWA:** installable, offline app shell, and a **Share Target** – on Android/Chrome you can Share a post from a
  Meta app straight into Current.

## How content gets into the history (important)
Facebook, Instagram, etc. send headers that forbid embedding in another site, and browsers do not let one site read
what you do inside another. So Current **opens platforms in their own tab** and cannot see what you view there.
History is built from:
1. returning from a platform → Current asks you to paste/save the link of what you viewed (with time spent);
2. **Add to history** (paste a link; platform and type – reel/video/post/story/chat – are detected automatically);
3. the phone's **Share → Current** menu.
Opening a saved item again from History counts as a **rewatch**.

## The machine-learning cleanup
- Rule: an item not opened again within the retention window is a deletion candidate. Rewatched (2+ opens) and pinned items are never auto-deleted.
- Model: logistic regression written in pure PHP, trained **per user, on the server, only on that user's history**.
  Each matured item becomes a training example (label: was it rewatched?) using platform, content type, hour of day,
  weekend and time spent. These examples are kept as numbers only, so the model keeps learning after the content is deleted.
- Decision: if the predicted chance of a rewatch ≥ the user's threshold (slider "Delete more ↔ Keep more"), the item gets
  one more window instead of being deleted. Until 20 outcomes exist, plain time-based cleanup is used.

## Platform logos
Meta's logos are trademarks and aren't bundled. Download the official assets from Meta's brand resources and drop them in
`assets/logos/` named `facebook.svg`, `instagram.svg`, `messenger.svg`, `whatsapp.svg`, `threads.svg`, `meta_ai.svg`,
`meta_quest.svg`. They are picked up automatically; until then, a coloured badge with the initial is shown.

## Layout
```
index.php  manifest.json  sw.js  api.php
assets/    app.css app.js logo.png logos/
lib/       db.php auth.php ml.php platforms.php
cron/      cleanup.php
data/      replay.sqlite (created on first run)
```

## Notes
- Time-of-day features use UTC (server time); this is fine for patterns but not localised.
- `DEV_LOGIN` exists for local testing only and only works from 127.0.0.1 / ::1. Leave it off in production.
- This is an independent app; it is not affiliated with or endorsed by Meta.
