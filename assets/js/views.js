import { h, ic, toast, modal, fmtDate } from './ui.js';
import { api, state, getTheme, setTheme } from './api.js';
import { PLAT, PLAT_ALL, KIND, platformLogo, art, title, empty, field, shelf, rail, grid, itemCard, musicLinks, guard, ago } from './parts.js';
import { openItem } from './viewer.js';

const open = (it) => openItem(it);

/* ------------------------------------------------------------ login */
export function login(root) {
  const cfg = state.cfg, anyMeta = cfg.meta.facebook;
  const em = h('input', { type: 'email', autocomplete: 'email', placeholder: 'you@example.com', required: true }), pw = h('input', { type: 'password', autocomplete: 'current-password', placeholder: 'Password', required: true }), nm = h('input', { placeholder: 'Your name', autocomplete: 'name' });
  let mode = 'in';
  const submit = h('button', { class: 'btn big green block', type: 'submit' }, 'Sign in');
  const nameField = field('Name', nm); nameField.hidden = true;
  const tabs = ['in', 'up'].map((m) => h('button', { type: 'button', class: 'authtab' + (m === 'in' ? ' on' : ''), onclick: () => { mode = m; tabs.forEach((t, i) => t.classList.toggle('on', ['in', 'up'][i] === m)); submit.textContent = m === 'in' ? 'Sign in' : 'Create account'; nameField.hidden = m === 'in'; pw.autocomplete = m === 'in' ? 'current-password' : 'new-password'; } }, m === 'in' ? 'Sign in' : 'Create account'));
  const form = h('form', { class: 'stack', onsubmit: (e) => { e.preventDefault(); guard(async () => { await api(mode === 'in' ? 'auth.login' : 'auth.register', { body: { email: em.value, password: pw.value, name: nm.value } }); location.hash = '#/'; location.reload(); }); } },
    cfg.passwordSignup ? h('div', { class: 'authtabs' }, tabs) : '', nameField, field('Email', em), field('Password', pw), submit);
  const params = new URLSearchParams((location.hash.split('?')[1]) || ''); const msg = params.get('msg');
  root.append(h('div', { class: 'login' }, h('img', { src: 'assets/img/logo.png', alt: cfg.app || 'Current Recap', class: 'biglogo' }),
    h('p', { class: 'muted', style: { textAlign: 'center' } }, 'Everything you watched, posted and joined on Facebook, Instagram and Threads, organised for later.'),
    msg ? h('div', { class: 'banner' }, msg) : '',
    h('a', { class: 'fbbtn', href: anyMeta ? 'oauth.php?p=facebook&mode=login' : null, 'aria-disabled': anyMeta ? null : 'true', title: anyMeta ? '' : 'Add your Meta app ID in config.local.php' }, h('img', { src: 'assets/logos/facebook.png', alt: '' }), 'Continue with Facebook'),
    !anyMeta ? h('p', { class: 'fine' }, 'Facebook login is not set up yet. See the README.') : '',
    cfg.passwordSignup ? h('div', { class: 'fine' }, 'or use email') : '', cfg.passwordSignup ? form : '',
    cfg.devLogin ? devForm() : '',
    h('p', { class: 'fine' }, h('a', { href: 'privacy.php' }, 'Privacy'), ' · ', h('a', { href: 'terms.php' }, 'Terms'), ' · ', h('a', { href: 'delete-data.php' }, 'Delete my data'))));
}
function devForm() {
  const e = h('input', { type: 'email', value: 'dev@example.com' });
  return h('div', { class: 'devbox' }, h('strong', {}, 'Local development login'), h('p', { class: 'muted' }, 'Only shown on localhost.'), field('Email', e),
    h('button', { class: 'btn ghost', onclick: () => guard(async () => { await api('auth.dev', { body: { email: e.value } }); location.hash = '#/'; location.reload(); }) }, 'Sign in without a password'));
}

/* ------------------------------------------------------------ home */
const homeQ = { range: 'month', platform: '' };
export async function home(root) {
  const d = await api('home', { params: { range: homeQ.range, platform: homeQ.platform } });
  const first = (state.user.name || '').split('@')[0].split(' ')[0] || 'there';
  const hr = new Date().getHours(), greet = hr < 12 ? 'Good morning' : hr < 18 ? 'Good afternoon' : 'Good evening';
  const RL = { day: 'Today', week: 'This week', month: 'This month', all: 'All time' };
  const total = Object.values(d.counts).reduce((a, b) => a + b, 0);
  const again = () => { const v = root.parentNode; root.replaceChildren(); return home(root); };
  root.append(
    h('div', { class: 'hero recap' }, h('div', { class: 'kicker' }, 'Your recap · ' + RL[homeQ.range]), h('h2', {}, `${greet}, ${first}`),
      h('p', {}, total ? `${total} thing${total === 1 ? '' : 's'} caught up across Facebook, Instagram, Threads and more.` : 'Nothing here yet. Connect an account to start your recap.'),
      h('div', { class: 'nums' }, Object.entries(KIND).map(([k, v]) => h('a', { class: 'num', href: '#/' + k }, ic(v.icon), h('strong', {}, d.counts[k]), h('span', {}, v.label)))),
      h('div', { class: 'row' }, d.connections ? h('button', { class: 'btn yellow', onclick: (e) => syncAll(e.currentTarget, again) }, ic('sync'), 'Sync now') : h('a', { class: 'btn yellow', href: '#/connections' }, ic('link'), 'Connect accounts'),
        d.musicLater ? h('a', { class: 'btn', href: '#/music' }, ic('music'), `${d.musicLater} song${d.musicLater === 1 ? '' : 's'} to listen to`) : '')),
    h('div', { class: 'plats' }, h('button', { class: 'pchip all' + (homeQ.platform ? '' : ' on'), onclick: () => { homeQ.platform = ''; again(); } }, 'All platforms'),
      Object.keys(PLAT_ALL).filter((p) => PLAT[p] || d.byPlatform[p]).map((p) => h('button', { class: 'pchip' + (homeQ.platform === p ? ' on' : ''), onclick: () => { homeQ.platform = p; again(); } }, platformLogo(p, true), PLAT_ALL[p], h('span', { class: 'n' }, d.byPlatform[p] || 0))),
      h('div', { class: 'range', role: 'group', 'aria-label': 'Time range' }, Object.entries(RL).map(([k, l]) => h('button', { class: homeQ.range === k ? 'on' : '', onclick: () => { homeQ.range = k; again(); } }, l)))));
  if (d.music.length) root.append(shelf('Songs to listen to later', rail(d.music.map((m) => h('a', { class: 'card', href: '#/music' }, h('div', { class: 'imgwrap' }, art({ id: m.id, title: m.title, thumb: m.thumb, kind: 'story' }), platformLogo(m.platform || 'instagram', true)),
    h('div', {}, h('div', { class: 't' }, m.title), h('div', { class: 'sub' }, h('span', { class: 'ell' }, m.artist || 'Unknown artist')))))), ['#/music', 'See all']));
  let any = false;
  for (const [k, v] of Object.entries(KIND)) { const list = d.rails[k]; if (!list.length) continue; any = true; root.append(shelf(v.label, rail(list.map((it) => itemCard(it, open, list)), k === 'story' ? 'tallrail' : ''), ['#/' + k, 'Show all'])); }
  if (!any) root.append(empty(d.total ? 'Nothing in this time range or platform.' : 'Your recap fills up as you connect accounts, use the browser extension or share links to the app.', h('a', { class: 'btn', href: '#/connections' }, ic('link'), 'Set up'),
    state.cfg.demoData ? h('button', { class: 'btn ghost', onclick: () => guard(async () => { toast('Loading sample data, real trending posts, music and pictures…'); const r = await api('demo.load', { body: {} }); toast(`Loaded ${r.added} sample items` + (r.media ? `, ${r.media} with real pictures, song artwork and videos` : '') + (r.real ? ` and ${r.real} real posts and videos on F1, sport, music, entertainment, fashion, tech and cars` : r.real_notes?.length ? '. Real trending posts could not be fetched (' + r.real_notes[0] + ')' : ''), r.real || !r.real_notes?.length ? 'ok' : 'bad'); again(); }) }, 'Load sample data') : ''));
}
async function syncAll(btn, again) {
  btn.disabled = true; btn.lastChild.textContent = 'Syncing…';
  await guard(async () => { const r = await api('sync', { body: {} }); const er = (r.errors || [])[0]; toast(er || `Synced, ${r.new} new`, er ? 'bad' : 'ok'); });
  again();
}

/* ------------------------------------------------------------ category page */
const catQ = {};
export async function category(root, kind, params) {
  const K = KIND[kind]; const q = catQ[kind] ||= { platform: '', sort: '', range: '', music: false, offset: 0 };
  const query = params.get('q') || '';
  const list = []; let total = 0;
  const body = h('div'), more = h('div', { class: 'more' });
  async function load(reset) {
    if (reset) { q.offset = 0; list.length = 0; body.replaceChildren(); }
    const r = await api('items.list', { params: { kind: kind || '', platform: q.platform, sort: q.sort, range: q.range, music: q.music ? 1 : '', q: query, limit: 36, offset: q.offset } });
    total = r.total; list.push(...r.items); q.offset += r.items.length;
    if (!body.firstChild || reset) body.replaceChildren(list.length ? grid(list.map((it) => itemCard(it, open, list)), kind === 'story' ? 'tallgrid' : '') : empty(query ? 'Nothing matches your search.' : `No ${K ? K.label.toLowerCase() : 'items'} here yet.`, query ? '' : h('a', { class: 'btn', href: '#/connections' }, 'Connect accounts')));
    else body.firstChild.append(...r.items.map((it) => itemCard(it, open, list)));
    more.replaceChildren(list.length < total ? h('button', { class: 'btn ghost', onclick: () => guard(() => load(false)) }, `Show more (${total - list.length})`) : '');
  }
  const sel = (opts, key) => h('select', { onchange: (e) => { q[key] = e.target.value; guard(() => load(true)); } }, opts.map(([v, l]) => h('option', { value: v, selected: q[key] === v }, l)));
  root.append(title(query ? `Search: “${query}”` : K.label, query ? '' : K.blurb),
    h('div', { class: 'toolbar2' }, sel([['', 'All platforms'], ...Object.entries(PLAT_ALL)], 'platform'), sel([['', 'Any time'], ['day', 'Today'], ['week', 'This week'], ['month', 'This month']], 'range'), sel([['', 'Newest'], ['oldest', 'Oldest'], ['views', 'Most opened']], 'sort'),
      h('button', { class: 'pchip' + (q.music ? ' on' : ''), style: { paddingLeft: '14px' }, onclick: (e) => { q.music = !q.music; e.currentTarget.classList.toggle('on', q.music); guard(() => load(true)); } }, ic('music'), 'With music')), body, more);
  await load(true);
}
export const search = (root, params) => category(root, '', params);

/* ------------------------------------------------------------ saved music */
let musicTab = 'later';
export async function music(root) {
  const r = await api('music.list', { params: { status: musicTab } });
  const again = () => { root.replaceChildren(); return music(root); };
  const t = h('input', { placeholder: 'Song title', maxlength: 120 }), a = h('input', { placeholder: 'Artist', maxlength: 120 });
  const add = h('div', { class: 'devbox', hidden: true }, h('div', { class: 'form2' }, field('Song', t), field('Artist', a)), h('div', { class: 'row' }, h('button', { class: 'btn green sm', onclick: () => guard(async () => { await api('music.save', { body: { title: t.value, artist: a.value } }); toast('Saved', 'ok'); again(); }) }, ic('check'), 'Save song')));
  root.append(title('Saved music', 'Songs you heard in stories and want to listen to later.'),
    h('div', { class: 'toolbar2' }, h('div', { class: 'tabs2' }, ['later', 'listened'].map((s) => h('button', { class: musicTab === s ? 'on' : '', onclick: () => { musicTab = s; again(); } }, (s === 'later' ? 'To listen' : 'Listened') + ` (${r.counts[s]})`))),
      h('button', { class: 'btn ghost sm', onclick: () => { add.hidden = !add.hidden; t.focus(); } }, ic('plus'), 'Add a song')), add,
    r.songs.length ? h('div', { class: 'songs' }, r.songs.map((s) => h('div', { class: 'song' + (s.status === 'listened' ? ' done' : '') }, art({ id: s.id, title: s.title, thumb: s.thumb, kind: 'story' }),
      h('div', { style: { minWidth: 0 } }, h('div', { class: 't ell' }, s.title), h('div', { class: 's ell' }, s.artist || 'Unknown artist'),
        h('div', { class: 'from' }, s.platform ? platformLogo(s.platform, true) : '', h('span', { class: 'ell' }, (s.story ? 'From “' + s.story + '” · ' : '') + 'saved ' + ago(s.saved_at)))),
      h('div', { class: 'acts' }, musicLinks(s), h('button', { class: 'ibtn', title: s.status === 'later' ? 'Mark as listened' : 'Move back to To listen', onclick: () => guard(async () => { await api('music.update', { body: { id: s.id, status: s.status === 'later' ? 'listened' : 'later' } }); again(); }) }, ic('check')),
        s.item_id ? h('button', { class: 'ibtn', title: 'Open the story', onclick: () => open({ id: s.item_id }) }, ic('story')) : '',
        h('button', { class: 'ibtn', title: 'Remove', onclick: () => guard(async () => { await api('music.delete', { body: { id: s.id } }); again(); }) }, ic('trash')))))) : empty(musicTab === 'later' ? 'No songs saved yet. Open a story that has music and choose “Save for listening later”.' : 'Nothing marked as listened yet.'));
}

/* ------------------------------------------------------------ connections */
const WHAT = {
  facebook: 'Your Facebook login. Posts need extra Meta approval, so this mainly gives you sign-in.',
  instagram: 'Your own posts, reels and stories (professional accounts only) with captions and music credits.',
  threads: 'Your threads and the replies you have written.',
};
export async function connections(root, params) {
  const [c, tk] = await Promise.all([api('connections.list'), api('token.list')]);
  const again = () => { root.replaceChildren(); return connections(root, params); };
  const by = {}; c.connections.forEach((x) => (by[x.platform] ||= []).push(x));
  const card = (p) => {
    const list = by[p] || [], on = state.cfg.meta[p];
    return h('div', { class: 'conn' }, h('div', { class: 'ch' }, platformLogo(p), h('div', {}, h('h3', {}, PLAT[p]), h('div', { class: 'what' }, WHAT[p]))),
      list.map((x) => h('div', { class: 'acct' }, x.picture ? h('img', { src: x.picture, alt: '', referrerpolicy: 'no-referrer' }) : '', h('div', { style: { minWidth: 0, flex: 1 } }, h('div', { class: 'ell' }, x.name || x.handle || 'Account'),
        h('div', { class: 'muted ell' }, x.last_sync_at ? `Synced ${ago(x.last_sync_at)} · ${x.last_sync_count} new` : 'Not synced yet'), x.error ? h('div', { class: 'err' }, x.error) : ''),
        x.status !== 'ok' && x.status !== 'active' ? h('span', { class: 'tag bad' }, 'Reconnect') : '',
        h('button', { class: 'ibtn', title: 'Sync', onclick: (e) => { e.currentTarget.disabled = true; guard(async () => { const r = await api('sync', { body: { id: x.id } }); toast(r.error || `Synced, ${r.synced} item${r.synced === 1 ? '' : 's'} checked`, r.error ? 'bad' : 'ok'); }).then(again); } }, ic('sync')),
        h('button', { class: 'ibtn', title: 'Disconnect', onclick: () => modal('Disconnect ' + PLAT[p] + '?', h('p', {}, 'We stop syncing and delete the stored access token. Your saved recap items stay unless you tick the box.'), [{ label: 'Cancel' }, { label: 'Disconnect', cls: 'danger', run: async () => { const ok = await guard(async () => { await api('connection.disconnect', { body: { id: x.id } }); return true; }); if (ok) again(); else return false; } }]) }, ic('close')))),
      h('a', { class: 'btn ' + (list.length ? 'ghost' : 'green') + ' sm', href: on ? `oauth.php?p=${p}&mode=connect` : null, 'aria-disabled': on ? null : 'true', title: on ? '' : 'Not configured: add the app ID in config.local.php', style: on ? {} : { opacity: .5, pointerEvents: 'none' } }, ic('link'), list.length ? 'Connect another' : 'Connect ' + PLAT[p]));
  };
  const tokenOut = h('div');
  root.append(title('Connections', 'Choose what feeds your recap.'),
    h('div', { class: 'note' }, ic('info'), h('div', {}, h('strong', {}, 'What Meta allows. '), 'Meta’s official APIs only return your own posts, stories and threads. What you watched or opened is not available to any app, so it is collected with the browser extension, the share button on your phone, or by pasting a link below. Messenger and WhatsApp are never read.')),
    h('div', { class: 'conns', style: { marginTop: '14px' } }, Object.keys(PLAT).map(card)),
    shelf('Add what you viewed', h('div', { class: 'stack' }, addLink(again),
      h('div', { class: 'conn' }, h('h3', {}, 'Browser extension'), h('ol', { class: 'steps' }, h('li', {}, h('b', {}, 'Download'), ' and unzip the extension, then load it in Chrome or Edge via ', h('code', {}, 'chrome://extensions'), ' → Developer mode → Load unpacked.'), h('li', {}, 'Create a token below and paste it into the extension with this site’s address.'), h('li', {}, 'Stories, reels and podcast links you open on Facebook, Instagram and Threads are added to your recap.')),
        h('div', { class: 'row' }, h('a', { class: 'btn ghost sm', href: 'extension.php', download: '' }, ic('download'), 'Download extension'), h('button', { class: 'btn green sm', onclick: () => guard(async () => { const r = await api('token.create', { body: { label: 'My browser' } }); tokenOut.replaceChildren(h('div', { class: 'note' }, ic('key'), h('div', {}, h('strong', {}, 'Copy now, it is shown once'), h('div', { class: 'code' }, r.token), h('div', { class: 'muted' }, 'Server: ' + r.server)))); }) }, ic('key'), 'Create token')), tokenOut,
        tk.tokens.map((t) => h('div', { class: 'row' }, h('span', { class: 'ell' }, `${t.label} · ${t.last_used_at ? 'used ' + ago(t.last_used_at) : 'never used'}`), h('button', { class: 'btn ghost sm', onclick: () => guard(async () => { await api('token.revoke', { body: { id: t.id } }); again(); }) }, 'Revoke')))),
      h('div', { class: 'conn' }, h('h3', {}, 'Share from your phone'), h('p', { class: 'muted' }, 'Install this app to your home screen. “Current Recap” then appears in the Share sheet of Facebook, Instagram and Threads, so you can send any story or post straight to your recap.')))));
}
function addLink(again) {
  const u = h('input', { type: 'url', placeholder: 'https://www.instagram.com/stories/…', required: true });
  return h('form', { class: 'conn', onsubmit: (e) => { e.preventDefault(); guard(async () => { const r = await api('item.add', { body: { url: u.value } }); toast(r.new ? 'Added to your recap' : 'Already in your recap', 'ok'); u.value = ''; open(r.item); }); } },
    h('h3', {}, 'Paste a link'), h('div', { class: 'row' }, h('div', { style: { flex: 1, minWidth: '200px' } }, u), h('button', { class: 'btn green', type: 'submit' }, ic('plus'), 'Add')));
}

/* ------------------------------------------------------------ settings */
export async function settings(root) {
  const th = getTheme();
  const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Theme' }, [['dark', 'Dark', 'theme'], ['light', 'Light', 'sun']].map(([v, l, i]) => h('button', { class: th === v ? 'on' : '', onclick: (e) => { setTheme(v); seg.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b === e.currentTarget)); } }, ic(i), l)));
  const cf = h('input', { placeholder: 'Type DELETE' });
  root.append(title('Settings'), h('div', { class: 'panel' },
    h('div', { class: 'setrow' }, h('div', {}, h('strong', {}, 'Appearance'), h('div', { class: 'muted' }, 'Dark is the default.')), seg),
    h('div', { class: 'setrow' }, h('div', {}, h('strong', {}, 'Your data'), h('div', { class: 'muted' }, 'Download everything we hold about you as JSON.')), h('a', { class: 'btn ghost sm', href: 'api.php?a=account.export' }, ic('download'), 'Export')),
    h('div', { class: 'setrow' }, h('div', {}, h('strong', {}, 'Delete account'), h('div', { class: 'muted' }, 'Removes your recap, saved music, tokens and Meta connections.')),
      h('div', { class: 'row' }, cf, h('button', { class: 'btn danger sm', onclick: () => guard(async () => { await api('account.delete', { body: { confirm: cf.value } }); location.hash = '#/'; location.reload(); }) }, ic('trash'), 'Delete')))));
}
