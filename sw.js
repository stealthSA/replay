// Shell cache + share-target redirect. API and thumbnails are never cached (they are per-user).
const V = 'recap-v14', SHELL = ['./', 'assets/css/app.css', 'assets/js/app.js', 'assets/js/api.js', 'assets/js/ui.js', 'assets/js/parts.js', 'assets/js/views.js', 'assets/js/viewer.js', 'assets/js/engage.js', 'assets/img/logo.png'];
self.addEventListener('install', (e) => { e.waitUntil(caches.open(V).then((c) => c.addAll(SHELL).catch(() => {})).then(() => self.skipWaiting())); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== V).map((k) => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', (e) => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) return;
  if (/\.php$/.test(u.pathname)) return;
  e.respondWith(fetch(e.request).then((r) => { if (r.ok && /\.(css|js|png)$/.test(u.pathname)) { const c = r.clone(); caches.open(V).then((x) => x.put(e.request, c)); } return r; }).catch(() => caches.match(e.request).then((m) => m || caches.match('./'))));
});
