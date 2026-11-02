'use strict';
const $app = document.getElementById('app');
const S = { cfg: null, user: null, view: 'home', plat: '', q: '', items: [], stats: null, settings: null, model: null,
            bounds: { min: 1, max: 720 }, samples: 0, admin: null, launch: null };

/* ---------- helpers ---------- */
const h = (tag, attrs = {}, ...kids) => {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v === true) el.setAttribute(k, '');
    else if (v !== false && v != null) el.setAttribute(k, v);
  }
  for (const kid of kids.flat()) if (kid != null && kid !== false) el.append(kid.nodeType ? kid : document.createTextNode(kid));
  return el;
};
const toast = (msg) => { const t = document.getElementById('toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('show'), 2600); };

async function api(action, { body, query } = {}) {
  const qs = new URLSearchParams({ action, ...(query || {}) });
  const res = await fetch('api.php?' + qs, body !== undefined
    ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Replay': '1' }, body: JSON.stringify(body) }
    : { credentials: 'same-origin' });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && S.user) { S.user = null; render(); }
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

const plat = id => S.cfg.platforms.find(p => p.id === id);
function logo(p, small = false) {
  const slot = h('div', { class: 'logo-slot' + (small ? ' sm' : '') });
  const img = h('img', { src: `assets/logos/${p.id}.svg`, alt: p.name, loading: 'lazy' });
  img.onerror = () => { img.onerror = null; slot.replaceChildren(h('div', { class: 'badge', style: `background:${p.color}` }, p.name[0])); };
  slot.append(img);
  return slot;
}
function ago(ts) {
  const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
  if (s < 60) return 'just now';
  if (s < 3600) return Math.floor(s / 60) + 'm ago';
  if (s < 86400) return Math.floor(s / 3600) + 'h ago';
  return Math.floor(s / 86400) + 'd ago';
}
function dur(sec) {
  if (sec <= 0) return 'now';
  if (sec < 3600) return Math.ceil(sec / 60) + 'm';
  if (sec < 172800) return Math.round(sec / 3600) + 'h';
  return Math.round(sec / 86400) + 'd';
}
const hoursLabel = hrs => hrs % 24 === 0 && hrs >= 48 ? (hrs / 24) + ' days' : hrs + (hrs === 1 ? ' hour' : ' hours');
const host = u => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return u; } };

/* ---------- boot ---------- */
(async function boot() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
  try {
    S.cfg = await api('config');
    S.user = (await api('me')).user;
  } catch (e) { $app.textContent = 'Could not reach the server.'; return; }
  const p = new URLSearchParams(location.search);
  if (p.get('view')) S.view = p.get('view');
  if (p.get('url') || p.get('text')) {            // Web Share Target
    const shared = (p.get('url') || (p.get('text') || '').match(/https?:\/\/\S+/)?.[0] || '');
    S.shared = { url: shared, title: p.get('title') || '' };
    history.replaceState(null, '', location.pathname);
  }
  render();
})();

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible' || !S.launch) return;
  const l = S.launch; S.launch = null;
  const secs = Math.round((Date.now() - l.t) / 1000);
  if (secs >= 5) openSaveSheet({ platform: l.platform, dwell: secs, fromLaunch: true });
});

/* ---------- render ---------- */
function render() {
  if (!S.cfg) return;
  if (!S.user) return renderLogin();
  $app.replaceChildren(header(), h('main', { class: 'wrap' }), tabs());
  const main = $app.querySelector('main');
  const views = { home: viewHome, history: viewHistory, settings: viewSettings, admin: viewAdmin };
  (views[S.view] || viewHome)(main);
  if (S.shared) { const s = S.shared; S.shared = null; openSaveSheet({ url: s.url, title: s.title }); }
}

function header() {
  const av = S.user.picture
    ? h('img', { class: 'avatar', src: S.user.picture, alt: '', referrerpolicy: 'no-referrer', onclick: () => go('settings') })
    : h('div', { class: 'avatar', onclick: () => go('settings') }, (S.user.name || '?')[0].toUpperCase());
  return h('header', { class: 'top' }, h('div', { class: 'top-in' }, h('img', { class: 'logo', src: 'assets/logo.png', alt: 'Current – Stay Current' }), h('div', { class: 'sp' }), av));
}
function tabs() {
  const t = [['home', '🌐', 'Browse'], ['history', '🕘', 'History'], ['settings', '⚙️', 'Settings']];
  if (S.user.role === 'admin') t.push(['admin', '🛡️', 'Admin']);
  return h('nav', { class: 'tabs' }, h('div', { class: 'tabs-in' }, t.map(([id, e, label]) =>
    h('button', { class: 'tab' + (S.view === id ? ' on' : ''), onclick: () => go(id) }, h('span', { class: 'e' }, e), label))));
}
function go(v) { S.view = v; render(); window.scrollTo(0, 0); }

/* ---------- Firebase Authentication (Google provider) ---------- */
const FB_VERSION = '10.14.1';
let fbPromise = null;
function firebase() {
  if (!fbPromise) fbPromise = (async () => {
    const base = `https://www.gstatic.com/firebasejs/${FB_VERSION}/`;
    const [{ initializeApp }, A] = await Promise.all([import(base + 'firebase-app.js'), import(base + 'firebase-auth.js')]);
    return { auth: A.getAuth(initializeApp(S.cfg.firebase)), A };
  })();
  return fbPromise;
}
async function finishLogin(fbUser) {
  const idToken = await fbUser.getIdToken(true);
  S.user = (await api('login_firebase', { body: { idToken } })).user;
  S.view = 'home';
  render();
}
async function firebaseSignIn() {
  const { auth, A } = await firebase();
  const provider = new A.GoogleAuthProvider();
  provider.setCustomParameters({ prompt: 'select_account' });
  let cred;
  try { cred = await A.signInWithPopup(auth, provider); }
  catch (e) {
    if (['auth/popup-blocked', 'auth/operation-not-supported-in-this-environment'].includes(e.code)) { await A.signInWithRedirect(auth, provider); return; }
    if (['auth/popup-closed-by-user', 'auth/cancelled-popup-request'].includes(e.code)) return;
    throw e;
  }
  await finishLogin(cred.user);
}
function friendlyAuthError(e) {
  if (e.code === 'auth/unauthorized-domain') return 'Add this domain under Firebase → Authentication → Settings → Authorized domains';
  if (/dynamically imported|Failed to fetch/i.test(e.message || '')) return 'Could not load Google sign-in – check your connection and try again';
  if (e.code === 'auth/network-request-failed') return 'Network problem – check your connection';
  return e.message || 'Sign-in failed';
}

/* ---------- login ---------- */
function renderLogin() {
  const box = h('div', { class: 'login' }, h('div', {},
    h('img', { src: 'assets/logo.png', alt: 'Current – Stay Current' }),
    h('p', {}, 'Sign in to browse Meta platforms and keep a smart history that cleans itself up.')));
  const slot = h('div', { style: 'display:flex;justify-content:center;min-height:44px' });
  box.firstChild.append(slot);
  if (S.cfg.firebase) {
    const btn = h('button', { class: 'btn', onclick: async () => {
      btn.disabled = true;
      try { await firebaseSignIn(); } catch (e) { toast(friendlyAuthError(e)); }
      btn.disabled = false;
    } }, 'Continue with Google');
    slot.append(btn);
    firebase().then(async ({ auth, A }) => {            // finish a redirect-based sign-in, if one was started
      const r = await A.getRedirectResult(auth);
      if (r && !S.user) await finishLogin(r.user);
    }).catch(e => toast(friendlyAuthError(e)));
  } else {
    slot.append(h('div', { class: 'note' }, 'Sign-in is not configured yet. Add your Firebase web config to config.local.php.'));
  }
  if (S.cfg.dev_login) {
    const email = h('input', { type: 'email', placeholder: 'test email (local only)', style: 'margin-top:14px' });
    box.firstChild.append(email, h('button', { class: 'btn green sm', style: 'margin-top:10px', onclick: async () => {
      try { S.user = (await api('login_dev', { body: { email: email.value } })).user; render(); } catch (e) { toast(e.message); } } }, 'Dev sign-in'));
  }
  $app.replaceChildren(box);
}

/* ---------- home / browse ---------- */
async function viewHome(main) {
  main.append(h('div', { class: 'card' },
    h('h2', {}, 'Browse Meta platforms'),
    h('p', { class: 'sub' }, 'Tap a platform to open it. Meta blocks embedding, so it opens in its own tab — come back and Current will offer to save what you looked at.'),
    h('div', { class: 'grid' }, S.cfg.platforms.map(p => h('div', { class: 'plat', onclick: () => launch(p) }, logo(p), h('div', { class: 'nm' }, p.name))))));
  main.append(h('div', { class: 'card' },
    h('h2', {}, 'Save a link'),
    h('p', { class: 'sub' }, 'Paste a post, reel, video or chat link. You can also use your phone’s Share menu → Current once the app is installed.'),
    h('button', { class: 'btn green', onclick: () => openSaveSheet({}) }, '＋ Add to history')));
  const recent = h('div', { class: 'card' }, h('h2', {}, 'Continue where you left off'), h('div', { class: 'empty' }, 'Loading…'));
  main.append(recent);
  try {
    const { items } = await api('history', { query: { limit: 5 } });
    recent.lastChild.replaceWith(items.length ? h('div', {}, items.map(itemRow)) : h('div', { class: 'empty' }, 'Nothing yet — your history will show up here.'));
  } catch (e) { toast(e.message); }
}

function launch(p) {
  S.launch = { platform: p.id, t: Date.now() };
  window.open(p.url, '_blank', 'noopener');
}

function openSaveSheet({ url = '', title = '', platform = '', dwell = 0, fromLaunch = false }) {
  const u = h('input', { type: 'url', placeholder: 'https://…', value: url });
  const t = h('input', { type: 'text', placeholder: 'Title (optional)', value: title });
  const close = () => bg.remove();
  const bg = h('div', { class: 'sheet-bg', onclick: e => { if (e.target === bg) close(); } }, h('div', { class: 'sheet' },
    h('h2', {}, fromLaunch ? `Welcome back from ${plat(platform).name}` : 'Add to history'),
    h('p', { class: 'sub' }, fromLaunch ? `You were away ${Math.round(dwell / 60) || '<1'} min. Paste a link to what you viewed to save it.` : 'Paste a link from any Meta platform.'),
    u, h('div', { style: 'height:8px' }), t, h('div', { style: 'height:12px' }),
    h('div', { class: 'row wrap-r' },
      h('button', { class: 'btn sm ghost', onclick: async () => { try { u.value = await navigator.clipboard.readText(); } catch { toast('Clipboard blocked – paste manually'); } } }, '📋 Paste'),
      h('div', { class: 'grow' }),
      h('button', { class: 'btn sm ghost', onclick: close }, 'Skip'),
      h('button', { class: 'btn sm green', onclick: async () => {
        try { const r = await api('log', { body: { url: u.value, title: t.value, platform: platform || undefined, dwell } });
          toast(r.rewatch ? `Rewatch counted (${r.open_count}×) – this one is kept` : 'Saved to history'); close(); if (S.view !== 'settings') render(); }
        catch (e) { toast(e.message); } } }, 'Save'))));
  document.body.append(bg);
  if (!url) setTimeout(() => u.focus(), 50);
}

/* ---------- history ---------- */
function itemRow(it) {
  const p = plat(it.platform) || { id: it.platform, name: it.platform, color: '#888' };
  const left = it.expires_at ? dur(it.expires_at - Math.floor(Date.now() / 1000)) : null;
  const tagTxt = it.status === 'pinned' ? '📌 Pinned' : it.status === 'rewatched' ? `🔁 Rewatched ${it.open_count}×` : `⏳ Clears in ${left}`;
  return h('div', { class: 'item' }, logo(p, true),
    h('div', { class: 'grow' },
      h('div', { class: 'ttl', onclick: () => reopen(it) }, it.title || host(it.url)),
      h('div', { class: 'meta' }, h('span', { class: 'tag ' + it.status }, tagTxt), h('span', { class: 'tag type' }, it.content_type), ago(it.last_opened_at) + ' · ' + host(it.url))),
    h('button', { class: 'icon-btn' + (it.pinned ? ' on' : ''), title: 'Pin (never auto-delete)', onclick: async () => { await api('pin', { body: { id: it.id, pinned: !it.pinned } }); render(); } }, '📌'),
    h('button', { class: 'icon-btn', title: 'Delete', onclick: async () => { await api('delete', { body: { id: it.id } }); toast('Deleted'); render(); } }, '🗑️'));
}
async function reopen(it) {
  window.open(it.url, '_blank', 'noopener');
  try { const r = await api('log', { body: { url: it.url } }); toast(`Rewatch counted (${r.open_count}×) – kept`); setTimeout(render, 400); } catch (e) { toast(e.message); }
}

async function viewHistory(main) {
  const list = h('div', { class: 'card' });
  const chips = h('div', { class: 'chips' });
  const search = h('input', { type: 'search', placeholder: 'Search history…', value: S.q });
  let timer; search.oninput = () => { clearTimeout(timer); timer = setTimeout(() => { S.q = search.value; load(); }, 250); };
  main.append(h('div', { class: 'card' }, h('h2', {}, 'History'), search, h('div', { style: 'height:10px' }), chips), list);

  async function load() {
    const [hist, stats] = await Promise.all([
      api('history', { query: { platform: S.plat, q: S.q, limit: 100 } }), api('stats')]);
    S.stats = stats;
    chips.replaceChildren(
      h('button', { class: 'chip all' + (!S.plat ? ' on' : ''), onclick: () => { S.plat = ''; load(); } }, 'All ', h('span', { class: 'n' }, stats.total)),
      ...S.cfg.platforms.map(p => h('button', { class: 'chip' + (S.plat === p.id ? ' on' : ''), onclick: () => { S.plat = p.id; load(); } },
        logo(p, true), p.name, h('span', { class: 'n' }, stats.by_platform[p.id]?.count || 0))));
    list.replaceChildren(
      h('div', { class: 'stats' },
        h('div', { class: 'stat' }, h('b', {}, stats.total), h('span', {}, 'saved')),
        h('div', { class: 'stat' }, h('b', {}, stats.expiring_6h), h('span', {}, 'clear in 6h')),
        h('div', { class: 'stat' }, h('b', {}, stats.auto_cleaned), h('span', {}, 'auto-cleaned'))),
      h('div', { style: 'height:8px' }),
      hist.items.length ? hist.items.map(itemRow) : h('div', { class: 'empty' }, 'No history here yet.'),
      hist.items.length ? h('div', { style: 'text-align:center;margin-top:12px' }, h('button', { class: 'btn sm pink', onclick: async () => {
        if (!confirm(`Clear ${S.plat ? plat(S.plat).name : 'all'} history? Pinned items are kept.`)) return;
        const r = await api('clear', { body: { platform: S.plat || undefined } }); toast(`Cleared ${r.deleted} items`); load(); } }, 'Clear shown platform')) : '');
  }
  load().catch(e => toast(e.message));
}

/* ---------- settings ---------- */
async function viewSettings(main) {
  const d = await api('settings');
  S.settings = d.settings; S.bounds = d.bounds; S.model = d.model; S.samples = d.samples;
  const st = S.settings, b = S.bounds;
  let hours = st.retention_hours;

  const label = h('b', {});
  const slider = h('input', { type: 'range', min: b.min, max: Math.min(b.max, 168), step: 1, value: Math.min(hours, 168) });
  const num = h('input', { type: 'number', min: b.min, max: b.max, value: hours, style: 'max-width:110px' });
  const unit = h('select', { style: 'max-width:110px' }, h('option', { value: 1 }, 'hours'), h('option', { value: 24 }, 'days'));
  if (hours % 24 === 0 && hours >= 48) { unit.value = 24; num.value = hours / 24; }
  const presets = [1, 6, 12, 24, 72, 168, 720].filter(x => x >= b.min && x <= b.max);
  const presetRow = h('div', { class: 'row wrap-r', style: 'margin-top:10px' });
  const sync = () => {
    hours = Math.max(b.min, Math.min(b.max, hours));
    label.textContent = hoursLabel(hours);
    slider.value = Math.min(hours, +slider.max);
    presetRow.replaceChildren(...presets.map(x => h('button', { class: 'preset' + (x === hours ? ' on' : ''), onclick: () => { hours = x; setNum(); sync(); } }, hoursLabel(x).replace(' hours', 'h').replace(' hour', 'h').replace(' days', 'd'))));
  };
  const setNum = () => { if (hours % 24 === 0 && hours >= 48) { unit.value = 24; num.value = hours / 24; } else { unit.value = 1; num.value = hours; } };
  slider.oninput = () => { hours = +slider.value; setNum(); sync(); };
  num.oninput = () => { hours = Math.round((+num.value || 1) * +unit.value); sync(); };
  unit.onchange = num.oninput;
  sync();

  const auto = h('input', { type: 'checkbox', checked: !!st.auto_clean });
  const ml = h('input', { type: 'checkbox', checked: !!st.ml_enabled });
  const thr = h('input', { type: 'range', min: 0.2, max: 0.8, step: 0.05, value: st.ml_threshold });
  const save = async () => {
    try { const r = await api('settings', { body: { retention_hours: hours, auto_clean: auto.checked, ml_enabled: ml.checked, ml_threshold: +thr.value } });
      S.settings = r.settings; toast('Settings saved'); } catch (e) { toast(e.message); }
  };

  main.append(
    h('div', { class: 'card' }, h('h2', {}, 'Auto-clean'),
      h('p', { class: 'sub' }, 'Content you haven’t opened again is removed after this long. Anything you rewatch or pin is kept.'),
      h('div', { class: 'sw' }, h('span', {}, 'Delete non-rewatched content'), auto),
      h('div', { class: 'row', style: 'justify-content:space-between;margin-top:6px' }, h('span', {}, 'Keep for'), label),
      slider,
      h('div', { class: 'row', style: 'margin-top:6px' }, num, unit),
      presetRow,
      h('div', { class: 'note' }, `Allowed range: ${hoursLabel(b.min)} – ${hoursLabel(b.max)} (default 24 hours).`)),
    h('div', { class: 'card' }, h('h2', {}, '🧠 Smart cleanup (machine learning)'),
      h('p', { class: 'sub' }, 'Current learns, on this server and only from your own history, which kinds of content you tend to revisit. Items it predicts you’ll come back to get a reprieve instead of being deleted.'),
      h('div', { class: 'sw' }, h('span', {}, 'Use machine learning'), ml),
      h('div', { class: 'row', style: 'justify-content:space-between' }, h('span', { class: 'sub' }, 'Delete more'), h('span', { class: 'sub' }, 'Keep more')),
      thr,
      h('div', { class: 'note' }, S.model
        ? `Model trained on ${S.model.samples} outcomes · ${Math.round(S.model.accuracy * 100)}% accurate on your history · updated ${ago(S.model.trained_at)}.`
        : `Learning… ${S.samples}/${S.cfg.ml_min_samples} outcomes collected. Until then, simple time-based cleanup is used.`)),
    h('div', { class: 'row wrap-r' },
      h('button', { class: 'btn green grow', onclick: save }, 'Save settings'),
      h('button', { class: 'btn orange grow', onclick: async () => { await save(); const r = await api('cleanup', { body: {} });
        toast(`Cleaned ${r.deleted}, kept ${r.kept_by_ml} (${r.mode})`); render(); } }, 'Save & clean now')),
    h('div', { class: 'card' }, h('h2', {}, 'Account'),
      h('p', { class: 'sub' }, `${S.user.name || ''} · ${S.user.email}${S.user.role === 'admin' ? ' · admin' : ''}`),
      h('button', { class: 'btn pink sm', onclick: async () => { await api('logout', { body: {} }); if (fbPromise) fbPromise.then(({ auth, A }) => A.signOut(auth)).catch(() => {}); S.user = null; S.view = 'home'; render(); } }, 'Sign out')));
}

/* ---------- admin ---------- */
async function viewAdmin(main) {
  if (S.user.role !== 'admin') return go('home');
  const d = await api('admin_overview');
  const b = d.bounds;
  const min = h('input', { type: 'number', min: 1, value: b.min }), max = h('input', { type: 'number', min: 1, value: b.max }), def = h('input', { type: 'number', min: 1, value: b.default });
  main.append(
    h('div', { class: 'card' }, h('h2', {}, 'Admin overview'),
      h('div', { class: 'stats' },
        h('div', { class: 'stat' }, h('b', {}, d.totals.users), h('span', {}, 'users')),
        h('div', { class: 'stat' }, h('b', {}, d.totals.items), h('span', {}, 'items stored')),
        h('div', { class: 'stat' }, h('b', {}, d.totals.cleaned), h('span', {}, 'auto-cleaned'))),
      h('div', { style: 'height:12px' }),
      h('button', { class: 'btn orange sm', onclick: async () => { const r = await api('admin_cleanup_all', { body: {} }); toast(`Deleted ${r.deleted}, ML kept ${r.kept_by_ml}`); render(); } }, 'Run cleanup for all users')),
    h('div', { class: 'card' }, h('h2', {}, 'Retention range (hours)'),
      h('p', { class: 'sub' }, 'Limits what users can choose. Default applies to new accounts.'),
      h('div', { class: 'row' }, h('div', { class: 'grow' }, h('div', { class: 'sub' }, 'Min'), min), h('div', { class: 'grow' }, h('div', { class: 'sub' }, 'Default'), def), h('div', { class: 'grow' }, h('div', { class: 'sub' }, 'Max'), max)),
      h('div', { style: 'height:12px' }),
      h('button', { class: 'btn green sm', onclick: async () => { try { await api('admin_settings', { body: { min: +min.value, max: +max.value, default: +def.value } }); toast('Saved'); } catch (e) { toast(e.message); } } }, 'Save range')),
    h('div', { class: 'card' }, h('h2', {}, 'Users'),
      h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ['User', 'Role', 'Items', 'Last login', ''].map(x => h('th', {}, x)))),
        h('tbody', {}, d.users.map(u => h('tr', {}, h('td', {}, u.name || '', h('div', { class: 'meta' }, u.email)), h('td', {}, u.role), h('td', {}, u.items), h('td', {}, u.last_login ? ago(u.last_login) : '–'),
          h('td', {}, u.id === S.user.id ? '' : h('button', { class: 'btn sm ' + (u.disabled ? 'green' : 'pink'), onclick: async () => { await api('admin_toggle_user', { body: { id: u.id, disabled: !u.disabled } }); render(); } }, u.disabled ? 'Enable' : 'Disable')))))))),
    h('div', { class: 'card' }, h('h2', {}, 'Recent cleanups'),
      d.log.length ? h('table', {}, h('tbody', {}, d.log.map(l => h('tr', {}, h('td', {}, ago(l.run_at)), h('td', {}, l.email || '—'), h('td', {}, `${l.deleted} deleted`), h('td', {}, l.kept_by_ml ? `${l.kept_by_ml} kept` : ''), h('td', {}, l.mode)))))
        : h('div', { class: 'empty' }, 'No cleanups yet.')));
}
