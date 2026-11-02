(function () {
  'use strict';
  let minSeconds = 8;
  try {
    chrome.storage.local.get({ minSeconds: 8 }, v => { minSeconds = Math.max(3, +v.minSeconds || 8); });
    chrome.storage.onChanged.addListener(c => { if (c.minSeconds) minSeconds = Math.max(3, +c.minSeconds.newValue || 8); });
  } catch (e) { return; }

  const cleanTitle = t => (t || '').replace(/^\(\d+\+?\)\s*/, '').trim();

  const tracker = RecapTracker.createTracker({
    getUrl: () => location.href.split('#')[0],
    getTitle: () => cleanTitle(document.title),
    isActive: () => document.visibilityState === 'visible',   // tab is on screen
    minSeconds: () => minSeconds,
    send: ev => {
      try { chrome.runtime.sendMessage({ type: 'visit', platform: RecapTracker.classify(ev.url), ...ev }); }
      catch (e) { /* extension was reloaded; page needs a refresh */ }
    }
  });

  setInterval(tracker.tick, 1000);                // works for single-page navigation (no reloads) too
  addEventListener('pagehide', tracker.flush);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') tracker.flush(); });
})();
