import { h, ic, toast } from './ui.js';

export const PLAT = { facebook: 'Facebook', instagram: 'Instagram', threads: 'Threads' };
/** Trending also shows real public posts from open networks. */
export const PLAT_ALL = { ...PLAT, youtube: 'YouTube', mastodon: 'Mastodon', bluesky: 'Bluesky', applemusic: 'Apple Music', applepodcasts: 'Apple Podcasts' };
/** YouTube's privacy-friendly embedded player is the only iframe the app will ever create. */
export const ytEmbed = (u) => (typeof u === 'string' && /^https:\/\/www\.youtube-nocookie\.com\/embed\/[\w-]{6,20}$/.test(u) ? u : null);
export const ytFrame = (u, cls = '') => h('iframe', { class: 'ytframe ' + cls, src: ytEmbed(u) + '?rel=0&modestbranding=1', title: 'Video player', allow: 'accelerometer; encrypted-media; picture-in-picture; fullscreen', allowfullscreen: true, loading: 'lazy', referrerpolicy: 'strict-origin-when-cross-origin' });
export const KIND = {
  story: { label: 'Stories', one: 'Story', icon: 'story', c: 'c-pink', blurb: 'Stories you posted or viewed' },
  podcast: { label: 'Podcasts', one: 'Podcast', icon: 'podcast', c: 'c-cyan', blurb: 'Episodes and shows you shared or opened' },
  discussion: { label: 'Discussions', one: 'Discussion', icon: 'chat', c: 'c-purple', blurb: 'Threads and conversations you joined' },
  post: { label: 'Posts & Reels', one: 'Post', icon: 'post', c: 'c-orange', blurb: 'Everything else you interacted with' },
};
export const guard = async (fn) => { try { return await fn(); } catch (e) { toast(e.offline ? 'You are offline' : e.message, 'bad'); } };
export const title = (t, sub) => h('div', { class: 'head' }, h('h1', { class: 'title' }, t), sub ? h('p', { class: 'muted' }, sub) : '');
export const empty = (msg, ...act) => h('div', { class: 'empty' }, h('p', {}, msg), ...act);
export const field = (label, input, hint) => h('label', { class: 'field' }, h('span', {}, label), input, hint ? h('small', { class: 'muted' }, hint) : '');
export const shelf = (t, body, more) => h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, t), more ? h('a', { href: more[0] }, more[1]) : ''), body);
export const rail = (cards, cls = '') => h('div', { class: 'rail ' + cls }, cards);
export const grid = (cards, cls = '') => h('div', { class: 'grid ' + cls }, cards);

export const platformLogo = (p, sm) => h('span', { class: 'plat' + (sm ? ' sm' : ''), title: PLAT_ALL[p] || p }, h('img', { src: `assets/logos/${p}.png`, alt: PLAT_ALL[p] || p }));

export function ago(ts) {
  const s = Math.max(0, Date.now() / 1000 - ts);
  if (s < 3600) return Math.max(1, Math.round(s / 60)) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  if (s < 86400 * 14) return Math.round(s / 86400) + ' days ago';
  return new Date(ts * 1000).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Artwork: cached thumbnail when we have one, otherwise a coloured tile with the category icon. */
export const art = (it, cls = '') => h('div', { class: 'art ' + cls, 'data-h': String(((it.id || 0) + (it.title || '').length) % 5), style: it.thumb ? { backgroundImage: `url("${it.thumb}")` } : {} }, it.thumb ? '' : ic((KIND[it.kind] || KIND.post).icon));

export const itemTitle = (it) => it.title || (it.author ? `${PLAT_ALL[it.platform]} ${KIND[it.kind]?.one.toLowerCase() || 'post'} by ${it.author}` : `${PLAT_ALL[it.platform]} ${KIND[it.kind]?.one.toLowerCase() || 'post'}`);

/** Card used in every rail and grid. `open(it, list)` shows the viewer. */
export const itemCard = (it, open, list = [it]) => h('button', { class: 'card', onclick: () => open(it, list) },
  h('div', { class: 'imgwrap' + (it.kind === 'story' ? ' tall' : '') }, art(it), platformLogo(it.platform, true),
    it.pinned ? h('span', { class: 'pin', title: 'Pinned' }, ic('pin')) : '',
    it.music ? h('span', { class: 'mchip' + (it.saved_song ? ' saved' : ''), title: it.saved_song ? 'Song saved for later' : 'Has music' }, ic(it.saved_song ? 'heart' : 'music'), h('span', { class: 'ell' }, it.music.title)) : ''),
  h('div', {}, h('div', { class: 't' }, itemTitle(it)), h('div', { class: 'sub' }, h('span', { class: 'ell' }, (it.author ? it.author + ' · ' : '') + ago(it.at)))));

export const musicLinks = (m) => {
  const q = encodeURIComponent([m.title, m.artist].filter(Boolean).join(' '));
  return [
    h('a', { class: 'lbtn sp', target: '_blank', rel: 'noopener noreferrer', href: 'https://open.spotify.com/search/' + q }, ic('play'), 'Spotify'),
    h('a', { class: 'lbtn ym', target: '_blank', rel: 'noopener noreferrer', href: 'https://music.youtube.com/search?q=' + q }, ic('play'), 'YouTube Music'),
    h('a', { class: 'lbtn am', target: '_blank', rel: 'noopener noreferrer', href: 'https://music.apple.com/search?term=' + q }, ic('play'), 'Apple Music'),
  ];
};
