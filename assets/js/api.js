export const state = { user: null, csrf: '', cfg: {} };

export async function api(action, { params = {}, body } = {}) {
  const qs = new URLSearchParams({ a: action, ...params });
  const opt = { credentials: 'same-origin', headers: {} };
  if (body !== undefined) { opt.method = 'POST'; opt.headers['X-CSRF'] = state.csrf; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  let r;
  try { r = await fetch('api.php?' + qs, opt); } catch { const e = new Error('You are offline'); e.offline = true; throw e; }
  const j = await r.json().catch(() => ({ error: `The server sent an unexpected reply (HTTP ${r.status}). Open /health.php to see what is missing.` }));
  if (!r.ok) { const e = new Error(j.error || 'Request failed'); e.status = r.status; throw e; }
  return j;
}

// Light is the default. Dark (blue) is opt-in and remembered on this device.
export const getTheme = () => { try { const t = localStorage.getItem('recap.theme2'); return t === 'dark' ? 'dark' : 'light'; } catch { return 'light'; } };
export function applyTheme(t) {
  document.documentElement.dataset.theme = t === 'light' ? 'light' : 'dark';
  document.querySelector('meta[name=theme-color]')?.setAttribute('content', t === 'light' ? '#ffffff' : '#0a2f78');
}
export function setTheme(t) {
  t = t === 'dark' ? 'dark' : 'light';
  try { localStorage.setItem('recap.theme2', t); } catch {}
  applyTheme(t);
  if (state.user) api('settings.save', { body: { theme: t } }).catch(() => {});
  return t;
}
