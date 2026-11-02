/*
 * Current Recap – shared tracking logic (runs as a content script; also unit-tested in Node).
 * Decides which Meta URLs are "content" worth saving, and turns seconds-on-page into log/dwell events.
 * Messenger, WhatsApp and Meta AI are deliberately NOT handled: private conversations are never tracked.
 */
(function (root) {
  'use strict';

  function hostOf(u) { return u.hostname.toLowerCase().replace(/^(www|m|web|mobile|l)\./, ''); }

  /** @returns {string|null} platform id if the URL is a piece of content, else null. */
  function classify(url) {
    let u;
    try { u = new URL(url); } catch (e) { return null; }
    if (u.protocol !== 'https:') return null;
    const host = hostOf(u), p = u.pathname, q = u.searchParams;

    if (host === 'facebook.com' || host.endsWith('.facebook.com')) {
      if (/^\/reel\/\d+/.test(p)) return 'facebook';
      if (/^\/watch\/(live\/)?$/.test(p) && q.get('v')) return 'facebook';
      if (/\/videos\/(?:[^/]+\/)?\d+/.test(p)) return 'facebook';
      if (/^\/[^/]+\/posts\/[\w-]+/.test(p)) return 'facebook';
      if (/^\/groups\/[^/]+\/(posts|permalink)\/\d+/.test(p)) return 'facebook';
      if (/^\/(permalink|story)\.php$/.test(p) && q.get('story_fbid')) return 'facebook';
      if (/^\/photo(\.php)?\/?$/.test(p) && q.get('fbid')) return 'facebook';
      if (/^\/stories\/[^/]+\/[^/]+/.test(p)) return 'facebook';
      if (/^\/share\/[prv]\/[\w-]+/.test(p)) return 'facebook';
      return null;
    }
    if (host === 'instagram.com' || host.endsWith('.instagram.com')) {
      if (/^\/(?:[^/]+\/)?(p|reel|tv)\/[\w-]+/.test(p)) return 'instagram';
      if (/^\/reels\/[\w-]+/.test(p) && !/^\/reels\/audio\//.test(p)) return 'instagram';
      if (/^\/stories\/[^/]+\/\d+/.test(p)) return 'instagram';
      return null;
    }
    if (host === 'threads.net' || host === 'threads.com' || host.endsWith('.threads.com') || host.endsWith('.threads.net')) {
      if (/^\/@[^/]+\/post\/[\w-]+/.test(p)) return 'threads';
      return null;
    }
    return null;
  }

  /**
   * opts: getUrl(), getTitle(), isActive(), minSeconds(), send(event)
   * Call tick() once per second. Events: {kind:'log', url, title, seconds} once the page has been
   * actively viewed for minSeconds, then {kind:'dwell', url, seconds} batches while it stays open.
   */
  function createTracker(opts) {
    let st = { url: null, ok: false, secs: 0, logged: false, pending: 0 };
    function flush() {
      if (st.logged && st.pending > 0) { opts.send({ kind: 'dwell', url: st.url, seconds: st.pending }); st.pending = 0; }
    }
    function tick() {
      const url = opts.getUrl();
      if (url !== st.url) { flush(); st = { url, ok: !!classify(url), secs: 0, logged: false, pending: 0 }; }
      if (!st.ok || !opts.isActive()) return;
      st.secs++;
      if (!st.logged) {
        if (st.secs >= opts.minSeconds()) {
          st.logged = true;
          opts.send({ kind: 'log', url: st.url, title: opts.getTitle(), seconds: st.secs });
        }
      } else if (++st.pending >= 30) flush();
    }
    return { tick, flush, state: () => st };
  }

  const api = { classify, createTracker };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.RecapTracker = api;
})(typeof self !== 'undefined' ? self : this);
