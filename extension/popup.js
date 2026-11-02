const $ = id => document.getElementById(id);
const render = v => {
  $('on').checked = v.enabled;
  $('state').textContent = !v.token ? 'Not connected yet' : v.enabled ? 'Autosave is on' : 'Autosave is paused';
  $('count').textContent = v.saved + ' item' + (v.saved === 1 ? '' : 's') + ' saved so far';
  $('last').textContent = v.last ? 'Last: ' + (v.last.title || '').slice(0, 60) + (v.last.rewatch ? ' (rewatch)' : '') : 'Nothing saved yet';
  $('err').textContent = v.error || '';
};
const load = () => chrome.storage.local.get({ server: '', token: '', enabled: true, saved: 0, last: null, error: null }, v => {
  render(v);
  $('open').onclick = e => { e.preventDefault(); if (v.server) chrome.tabs.create({ url: v.server }); else chrome.runtime.openOptionsPage(); };
});
$('on').onchange = () => { chrome.storage.local.set({ enabled: $('on').checked }, load); try { chrome.action.setBadgeText({ text: '' }); } catch (e) {} };
$('setup').onclick = e => { e.preventDefault(); chrome.runtime.openOptionsPage(); };
load();
