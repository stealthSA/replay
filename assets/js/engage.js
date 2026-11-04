// Trending, My circle suggestions, Bid ads and the notification bell. Same helpers as the rest of the app; no innerHTML with data.
import { h, ic, toast, modal, fmtMoney } from './ui.js';
import { api, state } from './api.js';
import { PLAT_ALL, ytEmbed, ytFrame, platformLogo, title, empty, field, guard, ago } from './parts.js';

const nf = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });
const num = (v) => (v == null ? '—' : nf.format(v));
const KINDS = { video: ['Videos', 'video'], image: ['Pictures', 'post'], text: ['Text posts', 'chat'] };
const METRICS = {
  video: [['eye', 'views', 'Views'], ['clock', 'watch_minutes', 'Watch time'], ['share', 'shares', 'Shares'], ['download', 'downloads', 'Downloads']],
  image: [['heart', 'likes', 'Likes'], ['download', 'downloads', 'Downloads'], ['chat', 'comments', 'Comments']],
  text: [['chat', 'comments', 'Comments'], ['heart', 'likes', 'Likes'], ['share', 'shares', 'Shares']],
};
const metricText = (k, v) => (v == null ? '—' : k === 'watch_minutes' ? num(v) + ' min' : num(v));
const cap = (s) => (s ? s[0].toUpperCase() + s.slice(1) : s);
const topPct = (it) => Math.max(1, 100 - (it.pct || 0));
const growth = (it) => (it.velocity >= 0.1 ? ['Rising fast', 'pink'] : it.velocity >= 0.03 ? ['Rising', 'ok'] : ['Steady', '']);
const safeUrl = (u) => (typeof u === 'string' && /^https:\/\//i.test(u) ? u : null);

/** Tiny inline chart of how a post's engagement score grew over time. */
function spark(vals, w = 92, ht = 30) {
  if (!vals || vals.length < 2) return h('span');
  const ns = 'http://www.w3.org/2000/svg', min = Math.min(...vals), max = Math.max(...vals), span = max - min || 1;
  const pts = vals.map((v, i) => `${(i / (vals.length - 1) * (w - 4) + 2).toFixed(1)},${(ht - 3 - ((v - min) / span) * (ht - 8)).toFixed(1)}`).join(' ');
  const s = document.createElementNS(ns, 'svg'); s.setAttribute('viewBox', `0 0 ${w} ${ht}`); s.setAttribute('class', 'spark'); s.setAttribute('role', 'img'); s.setAttribute('aria-label', 'Engagement over time');
  const l = document.createElementNS(ns, 'polyline'); l.setAttribute('points', pts); l.setAttribute('fill', 'none'); l.setAttribute('stroke', 'currentColor'); l.setAttribute('stroke-width', '2.2'); l.setAttribute('stroke-linecap', 'round'); l.setAttribute('stroke-linejoin', 'round');
  const e = vals.length - 1, d = document.createElementNS(ns, 'circle'); d.setAttribute('cx', (w - 2).toFixed(1)); d.setAttribute('cy', pts.split(' ')[e].split(',')[1]); d.setAttribute('r', '2.8'); d.setAttribute('fill', 'currentColor');
  s.append(l, d); return s;
}

const tabs2 = (items, cur, go) => h('div', { class: 'tabs2', role: 'tablist' }, items.map(([id, label, badge]) => h('button', { role: 'tab', 'aria-selected': id === cur ? 'true' : 'false', class: id === cur ? 'on' : '', onclick: () => go(id) }, label, badge ? h('span', { class: 'tbadge' }, badge) : '')));
const chips = (opts, cur, set) => h('div', { class: 'chips' }, opts.map(([v, l]) => h('button', { class: 'chip' + (cur === v ? ' on' : ''), onclick: () => set(v) }, l)));
const toggleRow = (label, hint, checked, onchange) => h('label', { class: 'setrow sw' }, h('div', {}, h('strong', {}, label), h('div', { class: 'muted' }, hint)), h('input', { type: 'checkbox', class: 'switch', role: 'switch', checked, onchange: (e) => onchange(e.target.checked, e.target) }));

/* --------------------------------------------------------------- post card */
function postCard(it, o = {}) {
  const [gl, gc] = growth(it), url = safeUrl(it.url), kind = KINDS[it.kind] || KINDS.text;
  const media = h('button', { class: 'tmedia', onclick: () => details(it, o), 'aria-label': 'Details: ' + (it.title || 'post') },
    h('div', { class: 'tart', 'data-h': String(it.id % 5), style: it.thumb ? { backgroundImage: `url("${it.thumb}")` } : {} }, it.thumb ? '' : ic(kind[1])),
    platformLogo(it.platform, true), o.rank ? h('span', { class: 'rank' }, '#' + o.rank) : '',
    h('span', { class: 'kbadge' }, ic(kind[1]), kind[0].replace(/s$/, '')), h('span', { class: 'toppill' }, 'Top ' + topPct(it) + '%'), it.sample ? h('span', { class: 'sampletag', title: 'Invented test data' }, 'Sample') : '');
  const body = h('div', { class: 'tbody' },
    h('button', { class: 'ttitle', onclick: () => details(it, o) }, it.title || 'Untitled post'),
    h('div', { class: 'sub' }, h('span', { class: 'ell' }, [it.author, ago(it.posted_at)].filter(Boolean).join(' · '))),
    h('div', { class: 'tmetrics' }, METRICS[it.kind].map(([icon, k, label]) => h('span', { class: 'tm' + (it.metrics[k] == null ? ' na' : ''), title: it.metrics[k] == null ? label + ': not reported by the platform' : label }, ic(icon), metricText(k, it.metrics[k])))),
    h('div', { class: 'trow' }, h('span', { class: 'tag ' + gc }, ic('bolt'), gl), spark(it.spark), o.breakout && it.breakout >= 1.3 ? h('span', { class: 'tag pink' }, '×' + it.breakout + ' usual') : ''),
    it.topics?.length ? h('div', { class: 'ttags' }, it.topics.map((t) => h('span', { class: 'ttag' }, (t.kind === 'hashtag' ? '#' : '') + t.topic))) : '',
    it.sponsor ? h('div', { class: 'sponsor' }, it.sponsor.mockup ? h('img', { class: 'adpic', src: it.sponsor.mockup, alt: 'Ad picture from ' + it.sponsor.brand }) : ic('megaphone'), h('span', {}, 'Sponsored by ', h('strong', {}, it.sponsor.brand), it.sponsor.message ? ' · ' + it.sponsor.message : '', safeUrl(it.sponsor.link) ? [' ', h('a', { href: it.sponsor.link, target: '_blank', rel: 'noopener noreferrer sponsored' }, 'Learn more')] : '')) : '',
    h('div', { class: 'tacts' }, url ? h('a', { class: 'btn ghost sm', href: url, target: '_blank', rel: 'noopener noreferrer' }, ic('external'), 'Open') : '',
      ''));   // ad offers are hidden
  return h('article', { class: 'tcard' }, media, body);
}

/** SAM 3.1 boxes over the post picture: where the products Muse Spark named were found. */
function regionsView(it) {
  const colors = ['#ff5a8a', '#2fd0ff', '#ffc933'];
  return h('div', { class: 'regions' }, h('div', { class: 'rimg', style: { backgroundImage: `url("${it.thumb}")` } },
    it.ai.regions.flatMap((r, i) => r.boxes.map((b) => h('span', { class: 'rbox', style: { left: b.x1 * 100 + '%', top: b.y1 * 100 + '%', width: (b.x2 - b.x1) * 100 + '%', height: (b.y2 - b.y1) * 100 + '%', borderColor: colors[i % 3] } }, h('i', { style: { background: colors[i % 3] } }, r.label))))),
    h('div', { class: 'muted rcap' }, 'Found in the picture by SAM 3.1: ' + it.ai.regions.map((r) => r.label).join(', ')));
}

function details(it, o = {}) {
  const [gl] = growth(it), url = safeUrl(it.url);
  const stat = (k, v, hint) => h('div', { class: 'dstat', title: hint }, h('strong', {}, v), h('span', { class: 'muted' }, k));
  const body = h('div', { class: 'stack' },
    it.media?.type === 'YOUTUBE' && ytEmbed(it.media.url) ? ytFrame(it.media.url, 'dframe') : it.media?.url && safeUrl(it.media.url) ? h('video', { class: 'dvideo', src: it.media.url, controls: true, playsinline: true, preload: 'metadata', poster: it.thumb || null, onerror: (e) => e.target.remove() }) : (it.ai?.regions?.length && it.thumb ? regionsView(it) : ''),
    h('p', {}, it.body || it.title || ''),
    it.ai?.regions?.length && it.media?.url ? regionsView(it) : '',
    it.ai?.transcript ? h('div', { class: 'ai-note' }, h('i', {}, 'Voice'), h('span', {}, 'Said in the video: “' + it.ai.transcript.slice(0, 400) + (it.ai.transcript.length > 400 ? '…' : '') + '”')) : '',
    it.ai?.why ? h('div', { class: 'ai-note' }, h('i', {}, 'AI'), h('span', {}, it.ai.why + (it.ai.sentiment && it.ai.sentiment !== 'neutral' ? ' (' + it.ai.sentiment + ' tone)' : ''))) : '',
    h('div', { class: 'dgrid' }, stat('Trend position', 'Top ' + topPct(it) + '%', 'Rank among posts people chose to share'), stat('Engagement score', String(it.engagement), 'Weighted, log-damped engagement'), stat('Growth', gl, 'How fast the score grew over the last day'), stat('Vs. their usual', '×' + it.breakout, 'Compared with the creator’s typical post')),
    h('div', { class: 'tmetrics big' }, METRICS[it.kind].map(([icon, k, label]) => h('span', { class: 'tm' + (it.metrics[k] == null ? ' na' : '') }, ic(icon), h('b', {}, metricText(k, it.metrics[k])), ' ' + label))),
    it.spark?.length > 1 ? h('div', { class: 'row' }, h('span', { class: 'muted' }, 'Engagement over time'), spark(it.spark, 200, 44)) : '',
    it.metrics.downloads == null && it.kind !== 'text' ? h('p', { class: 'muted' }, 'Downloads are not reported for this post, so the score uses the other numbers (nothing is counted as zero).') : '');
  modal((PLAT_ALL[it.platform] || '') + ' ' + (KINDS[it.kind]?.[0].replace(/s$/, '').toLowerCase() || 'post') + (it.author ? ' by ' + it.author : ''), body,
    [{ label: 'Close', cls: 'ghost' }, ...(url ? [{ label: 'Open post', cls: 'cyan', run: () => { window.open(url, '_blank', 'noopener'); return false; } }] : []), ...(o.onOffer && it.biddable ? [{ label: 'Send ad offer', cls: 'yellow', run: () => { setTimeout(() => o.onOffer(it), 0); } }] : [])]);
}

/* --------------------------------------------------------------- ad offer */
export function offerModal(it, prof, after) {
  const min = (prof.min_bid || 5000) / 100;
  const amt = h('input', { type: 'number', min, step: '1', value: Math.max(min, 100), required: true }), days = h('select', {}, [1, 3, 7, 14, 30].map((d) => h('option', { value: d, selected: d === 7 }, d + (d === 1 ? ' day' : ' days'))));
  let useMock = false; const pic = h('img', { class: 'adpic big', alt: 'Ad picture made with Muse Image', hidden: true });
  const msg = h('textarea', { rows: 3, maxlength: 280, placeholder: 'The short line shown with your ad, e.g. “Free shipping on Air Max this week”' }), link = h('input', { type: 'url', placeholder: 'https://your-site.example/offer (optional)' });
  modal('Send an ad offer', h('div', { class: 'stack' },
    h('p', { class: 'muted' }, `For ${it.author || 'this creator'}’s ${it.kind} (“${(it.title || '').slice(0, 70)}”). The creator must accept before anything appears on the post. Nothing is charged by this app.`),
    field(`Offer (${prof.currency})`, amt, `Minimum ${fmtMoney(prof.min_bid, prof.currency)}`), field('Run for', days), field('Message shown with your ad', msg), prof.ai ? h('button', { class: 'btn ghost sm', type: 'button', onclick: (e) => { const b = e.currentTarget; b.disabled = true; guard(async () => { const r = await api('bid.suggest', { body: { content_id: it.id } }); msg.value = r.message; }).finally(() => { b.disabled = false; }); } }, 'Suggest a message with Muse Spark') : '', field('Link', link),
      prof.ai ? h('div', { class: 'stack' }, h('button', { class: 'btn ghost sm', type: 'button', onclick: (e) => { const b = e.currentTarget; b.disabled = true; b.textContent = 'Creating picture…'; guard(async () => { const r = await api('bid.mockup', { body: { content_id: it.id } }); pic.src = r.url; pic.hidden = false; useMock = true; }).finally(() => { b.disabled = false; b.textContent = 'Create ad picture with Muse Image'; }); } }, 'Create ad picture with Muse Image'), pic) : ''),
    [{ label: 'Cancel', cls: 'ghost' }, { label: 'Send offer', cls: 'yellow', run: async () => {
      const ok = await guard(async () => { await api('bid.place', { body: { content_id: it.id, amount: Number(amt.value), days: Number(days.value), message: msg.value, link: link.value, use_mockup: useMock } }); toast('Offer sent. The creator has been notified.', 'ok'); return true; });
      if (ok) { after?.(); return true; } return false; } }]);
}

/* ---------------------------------------------------------------- trending */
const CATLBL = { hiphop: 'Hip hop', amapiano: 'Amapiano', motorsport: 'F1 & MotoGP' };
const T = { kind: '', platform: '', range: 'week', category: '', sort: '', topic: '', samples: '' };
export async function trending(root, params) {
  const mine = params.get('tab') === 'mine', again = () => { root.replaceChildren(); return trending(root, params); };
  const prof = await api('ads.profile'); const pf = { min_bid: prof.min_bid, currency: prof.currency, ai: prof.ai };
  const onOffer = prof.advertiser ? (it) => offerModal(it, pf, again) : null;
  root.append(title('Trending', 'Posts getting the most engagement. Videos are scored on watch time, views, shares and downloads; pictures on likes, downloads and comments; text posts on comments first, then likes and shares.'),
    h('div', { class: 'note' }, ic('info'), h('div', {}, h('strong', {}, 'Whose posts are these? '), 'People who switched on “Share my post stats”, real public trending posts from Mastodon and Bluesky (open networks that publish them), and any licensed data added by the site owner. Meta does not let any app read everyone’s posts, so Facebook, Instagram and Threads posts here come only from people using this app. Posts marked Sample are invented test data. A number a platform does not report (for example downloads) is left out of the score, not counted as zero.')),
    tabs2([['all', 'Everyone'], ['mine', 'My posts']], mine ? 'mine' : 'all', (t) => { location.hash = '#/trending' + (t === 'mine' ? '?tab=mine' : ''); }));
  if (mine) return trendingMine(root, again, pf);
  const set = (k) => (v) => { T[k] = v; again(); };
  const [d, s] = await Promise.all([api('trend.list', { params: { kind: T.kind, platform: T.platform, range: T.range, category: T.category, sort: T.sort, topic: T.topic, samples: T.samples } }), api('trend.surges')]);
  root.append(h('div', { class: 'filters' },
    chips([['', 'All types'], ...Object.entries(KINDS).map(([k, v]) => [k, v[0]])], T.kind, set('kind')),
    chips([['', 'All platforms'], ...Object.entries(PLAT_ALL)], T.platform, set('platform')),
    h('div', { class: 'row' }, chips([['', 'Real posts'], ['1', '+ Invented samples']], T.samples, set('samples')), chips([['day', '24 hours'], ['week', '7 days'], ['month', '30 days']], T.range, set('range')),
      h('select', { class: 'mini', 'aria-label': 'Category', onchange: (e) => set('category')(e.target.value) }, h('option', { value: '' }, 'All categories'), d.categories.map((c) => h('option', { value: c, selected: c === T.category }, CATLBL[c] || cap(c)))),
      h('select', { class: 'mini', 'aria-label': 'Sort', onchange: (e) => set('sort')(e.target.value) }, [['', 'Most trending'], ['surging', 'Fastest growing'], ['new', 'Newest']].map(([v, l]) => h('option', { value: v, selected: v === T.sort }, l))))));
  if (state.cfg?.refreshButton) root.append(h('div', { class: 'row' }, h('button', { class: 'btn fetch', onclick: (e) => guard(async () => {
    const b = e.currentTarget; b.disabled = true; b.textContent = 'Fetching the latest, this can take a minute…';
    try { const r = await api('trend.fetch', { body: {} });
      if (!r.ran) toast('Updated a moment ago. Try again in ' + Math.max(1, Math.ceil(r.wait / 60)) + ' min.');
      else { toast(r.saved ? 'Got ' + r.saved + ' fresh posts and videos' : 'Nothing new could be fetched' + (r.notes?.[0] ? ': ' + r.notes[0] : ''), r.saved ? 'ok' : 'bad'); again(); }
    } finally { b.disabled = false; }
  }) }, ic('bolt'), 'Get latest content'), h('span', { class: 'tiny' }, 'F1, MotoGP, amapiano, hip hop, sport, entertainment, tech, fashion')));
  if (T.topic) root.append(h('div', { class: 'row' }, h('span', { class: 'tag pink' }, 'Topic: ' + T.topic), h('button', { class: 'link', onclick: () => set('topic')('') }, 'Clear')));
  if (s.surges.length) root.append(h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, 'Rising topics')),
    h('div', { class: 'chips surges' }, s.surges.map((x) => h('button', { class: 'chip surge' + (T.topic === x.topic ? ' on' : ''), title: `${x.posts} posts by ${x.authors} creators in the last 24 h`, onclick: () => set('topic')(T.topic === x.topic ? '' : x.topic) }, ic('bolt'), (x.kind === 'hashtag' ? '#' : '') + x.topic, h('span', { class: 'x' }, '×' + x.ratio))))));
  if (!d.items.length) { root.append(empty('Nothing matches these filters yet. Turn on “Share my post stats” under My posts so your own posts can show up here.', h('a', { class: 'btn', href: '#/trending?tab=mine' }, 'My posts'))); return; }
  root.append(h('section', { class: 'sec' }, h('div', { class: 'tgrid' }, d.items.map((it, i) => postCard(it, { rank: i + 1, onOffer })))));
}

async function trendingMine(root, again, pf) {
  const [pr, d] = await Promise.all([api('prefs.get'), api('trend.list', { params: { own: '1' } })]);
  root.append(sharePanel(pr.prefs, again), h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, 'How your posts are doing')),
    d.items.length ? h('div', { class: 'tgrid' }, d.items.map((it) => postCard(it, { breakout: true }))) : empty('No posts with engagement numbers yet. Connect Instagram, Threads or Facebook and sync. Instagram likes and comments come through with the basic permission; views, shares and watch time need ENGAGEMENT_INSIGHTS (see the README).', h('a', { class: 'btn', href: '#/connections' }, ic('link'), 'Connections'))));
}

function sharePanel(p, again) {
  const save = (k) => (v, el) => { el.disabled = true; guard(async () => { await api('prefs.save', { body: { [k]: v } }); }).then(again); };
  return h('section', { class: 'panel stack' }, h('h3', {}, 'Who can see how my posts perform?'),
    h('p', { class: 'muted' }, 'Your numbers are private until you choose. You can change this at any time and your posts leave Trending straight away.'),
    toggleRow('My circle', 'People you and I both accepted in My circle can see which of my posts are taking off.', p.share_circle, save('share_circle')),
    toggleRow('Everyone: appear in Trending', 'My post stats can appear in Trending for all signed-in users.', p.share_pool, save('share_pool'))
  );
}

/* ------------------------------------------------------ my circle suggestions */
export async function circle(root, params) {
  const tab = params.get('tab') === 'circle' ? 'circle' : 'suggest', again = () => { root.replaceChildren(); return circle(root, params); };
  const [st, sg] = await Promise.all([api('circle.state'), tab === 'suggest' ? api('circle.suggest') : Promise.resolve(null)]);
  root.append(title('My circle suggestions', 'Posts from people in your circle that are doing much better than usual for them.'),
    tabs2([['suggest', 'Suggestions'], ['circle', 'My circle', st.incoming.length || null]], tab, (t) => { location.hash = '#/circle' + (t === 'circle' ? '?tab=circle' : ''); }));
  if (tab === 'suggest') {
    if (!st.members.length) { root.append(empty('Your circle is empty. Invite people you know who also use this app. Once they accept, their posts that take off will show up here.', h('a', { class: 'btn', href: '#/circle?tab=circle' }, ic('people'), 'Build my circle'))); return; }
    if (!sg.items.length) { root.append(empty('No one in your circle has a post that is beating their usual right now. Check back soon, you will also get a notification when one takes off.')); return; }
    root.append(h('div', { class: 'tgrid' }, sg.items.map((it) => postCard({ ...it, author: it.owner || it.author }, { breakout: true }))));
    return;
  }
  const email = h('input', { type: 'email', placeholder: 'friend@example.com', required: true, autocomplete: 'off' });
  const who = (x) => h('div', { class: 'acct' }, x.picture ? h('img', { src: x.picture, alt: '', referrerpolicy: 'no-referrer' }) : h('span', { class: 'ini' }, (x.name || '?')[0].toUpperCase()), h('div', { class: 'grow' }, h('div', { class: 'ell' }, x.name || x.email), x.email ? h('div', { class: 'muted ell' }, x.email) : ''));
  root.append(h('form', { class: 'conn', onsubmit: (e) => { e.preventDefault(); guard(async () => { const r = await api('circle.invite', { body: { email: email.value } }); toast(r.message, 'ok'); email.value = ''; }).then(again); } },
    h('h3', {}, 'Invite someone'), h('p', { class: 'muted' }, 'Both of you must accept. Members of your circle can see how your posts perform (you can switch that off under Trending → My posts).'),
    h('div', { class: 'row' }, h('div', { style: { flex: 1, minWidth: '220px' } }, email), h('button', { class: 'btn green', type: 'submit' }, ic('plus'), 'Send invite'))));
  if (st.incoming.length) root.append(h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, 'Invitations for you')),
    h('div', { class: 'stack' }, st.incoming.map((x) => h('div', { class: 'row between conn' }, who(x), h('div', { class: 'row' }, h('button', { class: 'btn green sm', onclick: () => guard(() => api('circle.respond', { body: { user_id: x.id, accept: true } })).then(again) }, 'Accept'), h('button', { class: 'btn ghost sm', onclick: () => guard(() => api('circle.respond', { body: { user_id: x.id, accept: false } })).then(again) }, 'Decline')))))));
  root.append(h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, `In my circle (${st.members.length})`)),
    st.members.length ? h('div', { class: 'stack' }, st.members.map((x) => h('div', { class: 'row between conn' }, who(x), h('button', { class: 'btn ghost sm', onclick: () => modal('Remove ' + x.name + '?', h('p', {}, 'You will stop seeing each other’s post performance.'), [{ label: 'Cancel', cls: 'ghost' }, { label: 'Remove', cls: 'danger', run: async () => { await guard(() => api('circle.remove', { body: { user_id: x.id } })); again(); } }]) }, 'Remove')))) : h('p', { class: 'muted' }, 'No one yet.')));
  if (st.outgoing.length) root.append(h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, 'Waiting for a reply')), h('div', { class: 'stack' }, st.outgoing.map((x) => h('div', { class: 'row between conn' }, who(x), h('button', { class: 'btn ghost sm', onclick: () => guard(() => api('circle.remove', { body: { user_id: x.id } })).then(again) }, 'Cancel'))))));
}

/* ------------------------------------------------------------------ bid ads */
const STATUS = { pending: ['Waiting', 'warn'], accepted: ['Live', 'live'], declined: ['Declined', 'bad'], outbid: ['Not chosen', ''], withdrawn: ['Withdrawn', ''], expired: ['Expired', ''], ended: ['Finished', 'ok'] };
const bidTag = (s) => h('span', { class: 'tag ' + (STATUS[s]?.[1] || '') }, STATUS[s]?.[0] || s);

export async function bids(root, params) {
  const prof = await api('ads.profile'); const adv = prof.advertiser;
  const tab = params.get('tab') || (adv ? 'opps' : 'profile'), again = () => { root.replaceChildren(); return bids(root, params); };
  const go = (t) => { location.hash = '#/bids?tab=' + t; };
  const offers = await api('bid.offers'); const waiting = offers.offers.filter((o) => o.status === 'pending').length;
  root.append(title('Bid ads', 'Advertisers hear about high-engagement posts as they take off, then send the creator an ad offer. Creators approve every offer.'),
    tabs2([['opps', 'Opportunities'], ['mine', 'My offers'], ['for-me', 'Offers for me', waiting || null], ['profile', adv ? 'My advertiser profile' : 'Become an advertiser']], tab, go));
  const pf = { min_bid: prof.min_bid, currency: prof.currency, ai: prof.ai };
  if (tab === 'profile') return profileForm(root, prof, again);
  if (tab === 'for-me') return offersForMe(root, offers, again);
  if (!adv) { root.append(empty('Set up an advertiser profile to get notified when content about your brand or topics gets a lot of engagement.', h('button', { class: 'btn yellow', onclick: () => go('profile') }, ic('megaphone'), 'Set up profile'))); return; }
  if (tab === 'mine') {
    const d = await api('bid.mine');
    root.append(d.bids.length ? h('div', { class: 'stack' }, d.bids.map((b) => h('div', { class: 'conn' }, h('div', { class: 'row between' }, h('strong', { class: 'ell' }, (b.content_title || 'Post') + (b.content_author ? ' · ' + b.content_author : '')), bidTag(b.status)),
      h('div', { class: 'row' }, h('span', { class: 'tag prem' }, fmtMoney(b.amount_cents, b.currency)), h('span', { class: 'muted' }, `${b.days} day${b.days === 1 ? '' : 's'} · sent ${ago(b.created_at)}` + (b.status === 'accepted' && b.ends_at ? ` · runs until ${new Date(b.ends_at * 1000).toLocaleDateString()}` : ''))),
      h('p', { class: 'muted' }, b.message), b.status === 'pending' ? h('div', { class: 'row end' }, h('button', { class: 'btn ghost sm', onclick: () => guard(() => api('bid.withdraw', { body: { id: b.id } })).then(again) }, 'Withdraw')) : ''))) : empty('You have not sent any offers yet. Open the Opportunities tab.'));
    return;
  }
  const d = await api('ads.opps');
  if (d.briefing) root.append(h('div', { class: 'ai-note', style: { marginBottom: '12px' } }, h('i', {}, 'Muse Wonder'), h('span', {}, d.briefing)));
  if (d.surges.length) root.append(h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, 'Your topics are rising')), h('div', { class: 'chips surges' }, d.surges.map((x) => h('span', { class: 'chip surge on', title: `${x.posts} posts by ${x.authors} creators` }, ic('bolt'), (x.kind === 'hashtag' ? '#' : '') + x.topic, h('span', { class: 'x' }, '×' + x.ratio))))));
  root.append(h('section', { class: 'sec' }, h('div', { class: 'shelf-h' }, h('h2', {}, 'High-engagement posts for ' + adv.brand)),
    d.items.length ? h('div', { class: 'tgrid' }, d.items.map((it) => h('div', { class: 'oppwrap' }, h('div', { class: 'why' }, ic('bolt'), it.why), postCard(it, { onOffer: it.my_bid ? null : (x) => offerModal(x, pf, again) }), it.my_bid ? h('div', { class: 'tag ok', style: { margin: '0 12px 12px' } }, it.my_bid === 'pending' ? 'Offer sent, waiting for the creator' : 'Your ad is live') : '')))
      : empty(`Nothing matches right now. We check every few minutes and will notify you (bell icon) when a post about ${[...adv.keywords, ...adv.categories].slice(0, 3).join(', ') || 'your topics'} takes off. Only creators who turned on “Open to ad offers” appear here.`)));
}

function profileForm(root, prof, again) {
  const a = prof.advertiser || { brand: '', website: '', keywords: [], categories: [], kinds: [], platforms: [], min_pct: 85, email_alerts: false };
  const brand = h('input', { value: a.brand, placeholder: 'e.g. Nike South Africa', maxlength: 80, required: true }), site = h('input', { type: 'url', value: a.website || '', placeholder: 'https://…' });
  const kw = h('textarea', { rows: 3, placeholder: 'nike sneakers, air max, rcp exhaust, running shoes' }); kw.value = a.keywords.join(', ');
  const pick = (name, options, cur) => { const set = new Set(cur); return h('div', { class: 'chips' }, options.map(([v, l]) => h('button', { type: 'button', class: 'chip' + (set.has(v) ? ' on' : ''), 'data-v': v, onclick: (e) => { set.has(v) ? set.delete(v) : set.add(v); e.currentTarget.classList.toggle('on'); } }, l)).concat([Object.assign(h('span', { hidden: true }), { get value() { return [...set]; } })])); };
  const cats = pick('c', prof.categories.map((c) => [c, CATLBL[c] || cap(c)]), a.categories), kinds = pick('k', Object.entries(KINDS).map(([k, v]) => [k, v[0]]), a.kinds), plats = pick('p', Object.entries(PLAT_ALL), a.platforms);
  const val = (el) => el.lastChild.value;
  const min = h('select', {}, [[95, 'Top 5%: only the biggest'], [90, 'Top 10%'], [85, 'Top 15%'], [75, 'Top 25%'], [60, 'Top 40%: more alerts']].map(([v, l]) => h('option', { value: v, selected: v === a.min_pct }, l)));
  const mail = h('input', { type: 'checkbox', checked: a.email_alerts });
  root.append(h('form', { class: 'panel stack', onsubmit: (e) => { e.preventDefault(); guard(async () => { await api('ads.save', { body: { brand: brand.value, website: site.value, keywords: kw.value, categories: val(cats), kinds: val(kinds), platforms: val(plats), min_pct: Number(min.value), email_alerts: mail.checked } }); toast('Profile saved. We will notify you when matching content takes off.', 'ok'); }).then(() => { location.hash = '#/bids?tab=opps'; }); } },
    h('h3', {}, a.brand ? 'Advertiser profile' : 'Become an advertiser'), h('p', { class: 'muted' }, 'Tell us what to listen for. When a post about your products or topics gets a lot of engagement, or a topic starts rising, you get a notification and can send the creator an ad offer.'),
    field('Brand name', brand), field('Website', site), field('Keywords and product ranges', kw, 'Separate with commas. Matches the post text and its hashtags, e.g. “nike sneakers”, “rcp exhaust”.'),
    h('div', { class: 'field' }, h('span', {}, 'Categories'), cats), h('div', { class: 'field' }, h('span', {}, 'Content types (none selected = all)'), kinds), h('div', { class: 'field' }, h('span', {}, 'Platforms (none selected = all)'), plats),
    field('Notify me when a matching post is in the', min), h('label', { class: 'row' }, mail, h('span', {}, 'Also e-mail me (when the site owner has e-mail alerts switched on)')),
    h('div', { class: 'row end' }, h('button', { class: 'btn yellow', type: 'submit' }, ic('check'), 'Save profile'))));
}

function offersForMe(root, d, again) {
  const p = d.prefs;
  if (!p.open_to_ads) root.append(h('div', { class: 'note' }, ic('info'), h('div', {}, h('strong', {}, 'You are not open to ad offers. '), 'Turn it on to let advertisers send you offers for posts that take off. You approve each one.', ' ', h('button', { class: 'link', onclick: () => guard(() => api('prefs.save', { body: { open_to_ads: true } })).then(again) }, 'Turn on'))));
  if (!d.offers.length) { root.append(empty('No offers yet. When an advertiser wants to run an ad on one of your high-engagement posts, it appears here and in your notifications.')); return; }
  root.append(h('div', { class: 'stack', style: { marginTop: '14px' } }, d.offers.map((b) => h('div', { class: 'conn' }, h('div', { class: 'row between' }, h('strong', {}, b.brand), bidTag(b.status)),
    h('div', { class: 'row' }, h('span', { class: 'tag prem' }, fmtMoney(b.amount_cents, b.currency)), h('span', { class: 'muted' }, `${b.days} day${b.days === 1 ? '' : 's'} on “${(b.content_title || 'your post').slice(0, 60)}”`)),
    h('p', {}, '“' + b.message + '”'), safeUrl(b.link) ? h('a', { class: 'link', href: b.link, target: '_blank', rel: 'noopener noreferrer nofollow' }, b.link) : '',
    b.status === 'pending' ? h('div', { class: 'row end' }, h('button', { class: 'btn ghost sm', onclick: () => guard(() => api('bid.respond', { body: { id: b.id, accept: false } })).then(again) }, 'Decline'), h('button', { class: 'btn green sm', onclick: () => modal('Accept this offer?', h('p', {}, `${b.brand}’s message and link will appear on your post for ${b.days} day${b.days === 1 ? '' : 's'}. Other waiting offers on this post are declined. Payment is arranged between you and the advertiser; this app does not process money.`), [{ label: 'Not now', cls: 'ghost' }, { label: 'Accept', cls: 'green', run: async () => { const ok = await guard(async () => { await api('bid.respond', { body: { id: b.id, accept: true } }); toast('Offer accepted. The ad is live.', 'ok'); return true; }); if (ok) { again(); return true; } return false; } }]) }, 'Accept')) : (b.ends_at ? h('p', { class: 'muted' }, 'Runs until ' + new Date(b.ends_at * 1000).toLocaleDateString()) : '')))));
}

/* ------------------------------------------------------------ notification bell */
const DEST = { opportunity: '#/bids?tab=opps', surge: '#/bids?tab=opps', circle: '#/circle', circle_invite: '#/circle?tab=circle', bid_received: '#/bids?tab=for-me', bid_accepted: '#/bids?tab=mine', bid_declined: '#/bids?tab=mine' };
export function bell() {
  const dot = h('span', { class: 'bdot', hidden: true }); let panel = null, timer = null;
  const btn = h('button', { class: 'ibtn bell', 'aria-label': 'Notifications', 'aria-haspopup': 'dialog', onclick: (e) => { e.stopPropagation(); panel ? close() : open(); } }, ic('bell'), dot);
  const hideN = (n) => n.type.startsWith('bid') || n.type === 'opportunity' || n.type === 'surge';   // advertiser / bid notifications are hidden
  async function refresh() { try { const d = await api('notif.list'); d.items = d.items.filter((n) => !hideN(n)); d.unread = d.items.filter((n) => !n.read).length; dot.textContent = d.unread > 9 ? '9+' : String(d.unread); dot.hidden = !d.unread; return d; } catch { return null; } }
  function close() { panel?.remove(); panel = null; document.removeEventListener('click', away); document.removeEventListener('keydown', esc); }
  const away = (e) => { if (panel && !panel.contains(e.target)) close(); }; const esc = (e) => { if (e.key === 'Escape') close(); };
  async function open() {
    const d = await refresh(); if (!d) return;
    panel = h('div', { class: 'menu notif', role: 'dialog', 'aria-label': 'Notifications' }, h('div', { class: 'row between nhead' }, h('strong', {}, 'Notifications'), d.unread ? h('button', { class: 'link', onclick: async () => { await api('notif.read', { body: {} }); close(); refresh(); } }, 'Mark all read') : ''),
      d.items.length ? d.items.map((n) => h('button', { class: 'nitem' + (n.read ? '' : ' unread'), onclick: async () => { close(); if (!n.read) { await api('notif.read', { body: { ids: [n.id] } }).catch(() => {}); refresh(); } location.hash = DEST[n.type] || '#/'; } },
        h('span', { class: 'nic' }, ic(n.type.startsWith('bid') ? 'megaphone' : n.type.startsWith('circle') ? 'people' : n.type === 'surge' ? 'bolt' : 'trend')), h('span', { class: 'grow' }, h('strong', { class: 'block' }, n.title), n.body ? h('span', { class: 'muted block' }, n.body) : '', h('span', { class: 'muted small' }, ago(n.at))))) : h('p', { class: 'muted', style: { padding: '12px' } }, 'Nothing yet. Alerts about rising posts and ad offers appear here.'));
    document.body.append(panel); setTimeout(() => { document.addEventListener('click', away); document.addEventListener('keydown', esc); }, 0);
  }
  refresh(); timer = setInterval(() => { if (!document.hidden) refresh(); }, 60000);
  return { el: btn, refresh, stop: () => { clearInterval(timer); close(); } };
}
