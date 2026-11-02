'use strict';
const DEFAULTS = { server: '', token: '', enabled: true, saved: 0, last: null, error: null };
const META_URL = /^https:\/\/([\w-]+\.)*(facebook|instagram|threads)\.(com|net)\//;

const badge = text => { try { chrome.action.setBadgeText({ text }); chrome.action.setBadgeBackgroundColor({ color: '#ff2f9e' }); } catch (e) {} };
const endpoint = (server, action) => new URL('api.php?a=' + action, server.endsWith('/') ? server : server + '/').href;

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (msg && msg.type === 'visit') { handleVisit(msg).then(() => sendResponse({ ok: true }), () => sendResponse({ ok: false })); return true; }
});

async function handleVisit(m) {
  const c = await chrome.storage.local.get(DEFAULTS);
  if (!c.enabled || !c.token || !c.server) return;       // paused or not set up
  if (!META_URL.test(m.url || '')) return;               // defence in depth: only ever send Meta content URLs
  if (m.kind !== 'log') return;   // only the first qualifying view is sent; dwell batches are not stored
  const isLog = true;
  try {
    const res = await fetch(endpoint(c.server, 'ingest'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Recap-Token': c.token },
      body: JSON.stringify({ url: m.url, title: m.title || '', dwell: m.seconds })
    });
    if (res.status === 401 || res.status === 403) {
      await chrome.storage.local.set({ error: 'Token rejected – create a new one in Current Recap → Connections.' });
      return badge('!');
    }
    if (!res.ok && !(isLog && res.status === 400)) throw new Error('HTTP ' + res.status);
    if (isLog && res.ok) {
      const d = await res.json();
      const cur = await chrome.storage.local.get(DEFAULTS);
      await chrome.storage.local.set({ saved: cur.saved + 1, error: null,
        last: { title: m.title || m.url, platform: m.platform, ts: Date.now(), rewatch: d.new === false } });
      badge('');
    }
  } catch (e) {
    await chrome.storage.local.set({ error: 'Could not reach your Current Recap server.' });
    badge('!');
  }
}
