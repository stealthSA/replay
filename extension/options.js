const $ = id => document.getElementById(id);
chrome.storage.local.get({ server: '', token: '', minSeconds: 8 }, v => {
  $('server').value = v.server; $('token').value = v.token; $('min').value = v.minSeconds;
});

$('save').onclick = async () => {
  const status = $('status');
  status.textContent = 'Connecting…';
  let server = $('server').value.trim();
  const token = $('token').value.trim();
  try {
    if (!/^https?:\/\//i.test(server)) throw new Error('Enter the full address, starting with https://');
    if (!server.endsWith('/')) server += '/';
    const granted = await chrome.permissions.request({ origins: [new URL(server).origin + '/*'] });
    if (!granted) { status.textContent = 'Permission to contact your server was declined.'; return; }
    const res = await fetch(new URL('api.php?a=ping', server), { headers: { 'X-Recap-Token': token } });
    const d = await res.json().catch(() => ({}));
    if (!res.ok || !d.user) { status.textContent = 'Token not accepted' + (d.error ? ': ' + d.error : '.'); return; }
    await chrome.storage.local.set({ server, token, enabled: true, error: null,
      minSeconds: Math.max(3, Math.min(60, +$('min').value || 8)) });
    status.textContent = '✔ Connected as ' + d.user.email + '. Autosave is on.';
  } catch (e) {
    status.textContent = e.message && !/fetch/i.test(e.message) ? e.message : 'Could not reach that address – check it and try again.';
  }
};
