import { h, ic, toast, modal } from './ui.js';
import { api } from './api.js';
import { PLAT_ALL, KIND, platformLogo, art, itemTitle, ago, musicLinks, guard, field, ytEmbed, ytFrame } from './parts.js';

let onChange = () => {};
export const setOnChange = (fn) => { onChange = fn; };

/** Story / item viewer. The music box is the "save for listening later" feature. */
export async function openItem(it0) {
  let it = it0;
  try { it = (await api('item.get', { params: { id: it0.id } })).item; } catch {}
  const wrap = h('div', { class: 'modal-wrap', onclick: (e) => { if (e.target === wrap) close(); } });
  const esc = (e) => { if (e.key === 'Escape') close(); };
  const close = () => { wrap.remove(); document.removeEventListener('keydown', esc); document.body.classList.remove('noscroll'); };
  const box = h('div', { class: 'modal wide', role: 'dialog', 'aria-modal': 'true', 'aria-label': itemTitle(it) });
  wrap.append(box); document.body.append(wrap); document.addEventListener('keydown', esc); document.body.classList.add('noscroll');

  function stage() {
    const s = h('div', { class: 'stage' + (it.thumb || it.media_url ? '' : ' plain') });
    const mt = String(it.media_type || '').toUpperCase(), vid = mt === 'VIDEO' && it.media_url, aud = mt === 'AUDIO' && it.media_url;
    if (mt === 'YOUTUBE' && ytEmbed(it.media_url)) s.append(ytFrame(it.media_url, 'stage-frame'));
    else if (vid) s.append(h('video', { src: it.media_url, controls: true, playsinline: true, poster: it.thumb || null, preload: 'metadata', onerror: (e) => { e.target.replaceWith(art(it)); } }));
    else if (it.thumb) { s.append(h('img', { src: it.thumb, alt: '' })); if (aud) s.append(h('audio', { class: 'prev', src: it.media_url, controls: true, preload: 'none', 'aria-label': '30-second preview of ' + (it.music?.title || 'the song') })); }
    else s.append(art(it));
    s.append(h('button', { class: 'ibtn x', 'aria-label': 'Close', onclick: close }, ic('close')));
    return s;
  }

  function musicBox() {
    const m = it.music;
    if (!m) {
      const t = h('input', { placeholder: 'Song title', maxlength: 120 }), a = h('input', { placeholder: 'Artist (optional)', maxlength: 120 });
      const form = h('div', { class: 'editor', hidden: true }, t, a, h('button', { class: 'btn green sm', onclick: () => guard(async () => {
        if (!t.value.trim()) return toast('Enter the song title', 'bad');
        const r = await api('item.update', { body: { id: it.id, music_title: t.value.trim(), music_artist: a.value.trim() } }); it = r.item; render(); onChange();
      }) }, ic('check'), 'Add song'));
      return h('div', { class: 'musicbox empty' }, h('div', { class: 'mh' }, h('span', { class: 'disc' }, ic('music')), h('div', {}, h('div', { class: 'mt' }, 'No music detected'),
        h('div', { class: 'tiny' }, 'Heard a song in this story? Add it so you can save it.'))), h('div', { class: 'row' }, h('button', { class: 'btn ghost sm', onclick: () => { form.hidden = !form.hidden; t.focus(); } }, ic('plus'), 'Add song')), form);
    }
    const saved = it.saved && it.saved.status === 'later', listened = it.saved && it.saved.status === 'listened';
    const toggle = h('button', { class: 'btn big ' + (saved ? 'ghost' : 'pink'), onclick: () => guard(async () => {
      if (saved) { await api('music.delete', { body: { id: it.saved.id } }); toast('Removed from Saved music'); }
      else { const r = await api('music.save', { body: { item_id: it.id } }); toast(r.existing ? 'Already in your saved music' : 'Saved for listening later', 'ok'); }
      it = (await api('item.get', { params: { id: it.id } })).item; render(); onChange();
    }) }, ic(saved ? 'check' : 'heart'), saved ? 'Saved for later' : 'Save for listening later');
    const edit = h('button', { class: 'btn ghost sm', onclick: () => {
      const t = h('input', { value: m.title, maxlength: 120 }), a = h('input', { value: m.artist || '', maxlength: 120 });
      modal('Fix the song details', h('div', { class: 'stack' }, field('Title', t), field('Artist', a)), [{ label: 'Cancel' }, { label: 'Save', cls: 'green', run: async () => {
        if (!t.value.trim()) { toast('Enter the song title', 'bad'); return false; }
        const ok = await guard(async () => { const r = await api('item.update', { body: { id: it.id, music_title: t.value.trim(), music_artist: a.value.trim() } }); it = r.item; return true; });
        if (!ok) return false; it = (await api('item.get', { params: { id: it.id } })).item; render(); onChange();
      } }]);
    } }, ic('edit'), 'Not right?');
    return h('div', { class: 'musicbox' }, h('div', { class: 'mh' }, h('span', { class: 'disc' }, ic('music')), h('div', { style: { minWidth: 0 } }, h('div', { class: 'mt' }, m.title), h('div', { class: 'ma' }, m.artist || 'Unknown artist'),
      h('div', { class: 'tiny' }, m.source === 'user' ? 'Added by you' : 'Detected from the caption, check it is right'))),
      h('div', { class: 'row' }, toggle, listened ? h('span', { class: 'tag ok' }, 'Listened') : '', edit), h('div', { class: 'row' }, musicLinks(m)));
  }

  function info() {
    const kinds = h('select', { 'aria-label': 'Category', onchange: (e) => guard(async () => { const r = await api('item.update', { body: { id: it.id, kind: e.target.value } }); it = { ...it, kind: r.item.kind }; toast('Moved to ' + KIND[it.kind].label); onChange(); render(); }) },
      Object.entries(KIND).map(([k, v]) => h('option', { value: k, selected: k === it.kind }, v.one)));
    return h('div', { class: 'info' },
      h('div', { class: 'meta' }, platformLogo(it.platform), h('span', {}, PLAT_ALL[it.platform] || it.platform), '·', h('span', {}, KIND[it.kind].one), '·', h('span', {}, (it.interaction === 'posted' ? 'You posted ' : it.interaction === 'replied' ? 'You replied ' : it.interaction === 'shared' ? 'You shared ' : 'You viewed ') + ago(it.at))),
      h('div', { class: 'vt' }, itemTitle(it)),
      it.author ? h('div', { class: 'muted' }, 'by ' + it.author) : '',
      it.body && it.body !== it.title ? h('div', { class: 'body' }, it.body) : '',
      it.kind === 'story' || it.music ? musicBox() : '',
      h('div', { class: 'actions' },
        it.url ? h('a', { class: 'btn', href: it.url, target: '_blank', rel: 'noopener noreferrer' }, ic('external'), 'Open original') : '',
        h('button', { class: 'btn ghost', onclick: () => guard(async () => { const r = await api('item.update', { body: { id: it.id, pinned: !it.pinned } }); it = { ...it, pinned: r.item.pinned }; render(); onChange(); }) }, ic('pin'), it.pinned ? 'Unpin' : 'Pin'),
        kinds,
        h('button', { class: 'btn ghost', onclick: () => modal('Remove from your recap?', h('p', {}, 'This only removes it from Current Recap. The original stays on ' + (PLAT_ALL[it.platform] || it.platform) + '.'), [{ label: 'Cancel' }, { label: 'Remove', cls: 'danger', run: async () => { const ok = await guard(async () => { await api('item.delete', { body: { id: it.id } }); return true; }); if (!ok) return false; close(); onChange(); toast('Removed'); } }]) }, ic('trash'), 'Remove')));
  }
  function render() { box.replaceChildren(h('div', { class: 'viewer' }, stage(), info())); }
  render();
}
