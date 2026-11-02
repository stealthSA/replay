import { h, ic, toast, $ } from './ui.js';
import { api, state, getTheme, setTheme, applyTheme } from './api.js';
import { KIND } from './parts.js';
import { setOnChange } from './viewer.js';
import * as V from './views.js';
import * as E from './engage.js';

const NAV = [['trending', 'Trending', 'trend', 'c-pink'], ['home', 'Home', 'home', 'c-yellow'], ['story', 'Stories', 'story', 'c-pink'], ['podcast', 'Podcasts', 'podcast', 'c-cyan'], ['discussion', 'Discussions', 'chat', 'c-purple'], ['post', 'Posts & Reels', 'post', 'c-orange'], ['music', 'Saved music', 'music', 'c-green']];
const DISC = [['circle', 'My circle suggestions', 'people', 'c-cyan'], ['bids', 'Bid ads', 'megaphone', 'c-orange']];
const TABS = [NAV[0], NAV[1], ['circle', 'Circle', 'people', 'c-cyan'], DISC[1], ['connections', 'Connect', 'link', 'c-blue']];
let bellC = null;
const app = document.getElementById('app');
let token = 0, menu = null, counts = {};
applyTheme(getTheme());
if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

async function boot() {
  try {
    const b = await api('boot'); Object.assign(state, { cfg: b, csrf: b.csrf, user: b.user });
  } catch (e) { app.replaceChildren(h('p', { class: 'fatal' }, 'Current Recap could not start: ' + e.message)); return; }
  window.addEventListener('hashchange', route);
  const sp = new URLSearchParams(location.search);   // PWA share target opens ./?title&text&url
  if (state.user && (sp.get('url') || sp.get('text'))) { history.replaceState(null, '', location.pathname + '#/share?' + sp.toString()); }
  shell(); route();
  if (state.user) api('sync', { body: { auto: 1 } }).catch(() => {});   // respects the minimum interval
}

const navLink = ([p, label, icon, c]) => h('a', { href: '#/' + p, class: 'navbtn ' + c, 'data-p': p }, ic(icon), h('span', {}, label), KIND[p] ? h('span', { class: 'count', 'data-k': p }, counts[p] ?? '') : '');

function shell() {
  const u = state.user;
  if (!u) { app.replaceChildren(h('div', { class: 'login-shell' }, h('div', { id: 'view' }))); return; }
  const search = h('form', { class: 'topsearch', role: 'search', onsubmit: (e) => { e.preventDefault(); const v = $('input', e.target).value.trim(); location.hash = '#/search' + (v ? '?q=' + encodeURIComponent(v) : ''); } },
    ic('search'), h('input', { type: 'search', placeholder: 'Search your recap', 'aria-label': 'Search your recap' }));
  const themeBtn = h('button', { class: 'ibtn', 'aria-label': 'Switch theme', title: 'Switch theme', onclick: () => { const t = setTheme(getTheme() === 'dark' ? 'light' : 'dark'); themeBtn.replaceChildren(ic(t === 'dark' ? 'sun' : 'theme')); } }, ic(getTheme() === 'dark' ? 'sun' : 'theme'));
  bellC?.stop(); bellC = E.bell();
  const me = h('button', { class: 'avatar', 'aria-label': 'Account menu', 'aria-haspopup': 'menu', onclick: (e) => { e.stopPropagation(); toggleMenu(); } }, u.picture ? h('img', { src: u.picture, alt: '', referrerpolicy: 'no-referrer' }) : (u.name || u.email)[0].toUpperCase());
  app.replaceChildren(h('div', { class: 'app' },
    h('aside', { class: 'side' }, h('div', { class: 'stripe' }), h('a', { href: '#/', class: 'brand', 'aria-label': 'Current Recap home' }, h('img', { src: 'assets/img/logo.png', alt: state.cfg.app || 'Current Recap' })),
      h('nav', { class: 'navlist', 'aria-label': 'Main' }, NAV.map(navLink), h('div', { class: 'group' }, 'Discover'), DISC.map(navLink), h('div', { class: 'group' }, 'Manage'), navLink(['connections', 'Connections', 'link', 'c-blue']), navLink(['settings', 'Settings', 'settings', 'c-green'])),
      h('div', { class: 'side-foot' }, h('div', { class: 'side-card', id: 'sidecard' }), h('div', { class: 'poweredby' }, 'Trend analysis powered by ', h('b', {}, 'Meta Model API')))),
    h('main', { class: 'main' }, h('header', { class: 'topbar' }, h('a', { href: '#/', class: 'brand-m', 'aria-label': 'Home' }, h('img', { src: 'assets/img/logo.png', alt: '' })), search, h('div', { class: 'sp' }), bellC.el, themeBtn, me), h('div', { id: 'view', tabindex: -1 })),
    h('nav', { class: 'tabs', 'aria-label': 'Main' }, TABS.map(navLink))));
  setOnChange(() => { refreshSide(); route(true); });
  refreshSide();
}

async function refreshSide() {
  try {
    const d = await api('home', { params: { range: 'all' } }); counts = d.counts;
    document.querySelectorAll('.count[data-k]').forEach((el) => { el.textContent = counts[el.dataset.k] || ''; });
    const sc = $('#sidecard'); if (!sc) return;
    sc.replaceChildren(d.connections ? h('strong', {}, `${d.total} in your recap`) : h('strong', {}, 'Start your recap'),
      h('span', { class: 'muted' }, d.connections ? `${d.musicLater} song${d.musicLater === 1 ? '' : 's'} saved to listen later.` : 'Connect Facebook, Instagram or Threads.'),
      d.connections ? h('a', { class: 'btn yellow sm', href: '#/music' }, ic('music'), 'Saved music') : h('a', { class: 'btn yellow sm', href: '#/connections' }, ic('link'), 'Connect'));
  } catch {}
}

function toggleMenu() {
  if (menu) return closeMenu();
  const u = state.user;
  const item = (icon, label, run, href) => h(href ? 'a' : 'button', { class: 'navbtn', href, onclick: () => { closeMenu(); run?.(); } }, ic(icon), h('span', {}, label));
  menu = h('div', { class: 'menu', role: 'menu' }, h('div', { class: 'who' }, h('strong', { class: 'ell' }, u.name || u.email), h('span', { class: 'muted ell' }, u.email)), h('hr'),
    item('link', 'Connections', null, '#/connections'), item('settings', 'Settings', null, '#/settings'),
    item(getTheme() === 'dark' ? 'sun' : 'theme', getTheme() === 'dark' ? 'Switch to light mode' : 'Switch to dark mode', () => { setTheme(getTheme() === 'dark' ? 'light' : 'dark'); shell(); route(true); }), h('hr'),
    item('logout', 'Sign out', async () => { await api('auth.logout', { body: {} }).catch(() => {}); location.hash = '#/'; location.reload(); }));
  document.body.append(menu);
  setTimeout(() => { document.addEventListener('click', closeMenu, { once: true }); document.addEventListener('keydown', escMenu); }, 0);
}
const escMenu = (e) => { if (e.key === 'Escape') closeMenu(); };
function closeMenu() { menu?.remove(); menu = null; document.removeEventListener('keydown', escMenu); }

async function route(keepScroll) {
  const my = ++token; closeMenu();
  const view = $('#view'); view.replaceChildren();
  if (!state.user) { V.login(view); return; }
  const [path, qs] = (location.hash.slice(2) || 'trending').split('?'); const params = new URLSearchParams(qs || ''); const seg = (path || 'trending').split('/');   // Trending is the landing page
  document.querySelectorAll('.navbtn[data-p]').forEach((a) => a.classList.toggle('on', a.dataset.p === seg[0]));
  if (keepScroll !== true) { $('.main')?.scrollTo(0, 0); window.scrollTo(0, 0); }
  const msg = params.get('msg'); if (msg) { toast(msg, params.get('ok') ? 'ok' : 'bad'); }
  try {
    const k = seg[0];
    if (k === 'home') await V.home(view);
    else if (KIND[k]) await V.category(view, k, params);
    else if (k === 'search') await V.search(view, params);
    else if (k === 'trending') await E.trending(view, params);
    else if (k === 'circle') await E.circle(view, params);
    else if (k === 'bids') await E.bids(view, params);
    else if (k === 'music') await V.music(view);
    else if (k === 'connections') await V.connections(view, params);
    else if (k === 'settings') await V.settings(view);
    else if (k === 'share') { await shareTarget(params); return; }
    else view.append(h('p', {}, 'That page does not exist. '), h('a', { class: 'link', href: '#/home' }, 'Go home'));
    if (my === token) refreshSide();
  } catch (e) {
    if (my !== token) return;
    if (e.status === 401) { state.user = null; shell(); route(); return; }
    view.replaceChildren(h('div', { class: 'empty' }, h('p', {}, e.message), h('a', { class: 'btn', href: '#/home' }, 'Go home')));
  }
}

/** PWA share target: manifest sends ?title&text&url to index.html#/share via the service worker redirect. */
async function shareTarget(params) {
  const raw = params.get('url') || (params.get('text') || '').match(/https?:\/\/\S+/)?.[0] || '';
  location.hash = '#/home';
  if (!raw) { toast('Nothing to add: no link was shared', 'bad'); return; }
  try { const r = await api('item.add', { body: { url: raw, title: params.get('title') || undefined, via: 'share' } }); toast(r.new ? 'Added to your recap' : 'Already in your recap', 'ok'); route(); refreshSide(); }
  catch (e) { toast(e.message, 'bad'); }
}
boot();
