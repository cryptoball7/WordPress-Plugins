// Basic visitor recorder: session id stored in localStorage, samples mouse moves (throttled),
// records clicks and scroll depth periodically. Posts to REST endpoint.
// Lightweight by design; change sampling values in the "config" below.

(function () {
  if (!window.HS_RECORDER || !HS_RECORDER.rest_url) return;

  const config = {
    moveThrottleMs: 200,     // report pointer move every 200ms
    scrollThrottleMs: 1000,  // report scroll depth every 1000ms
    batchSize: 20,           // optional batching (not used in this basic release)
    maxEventPerMinute: 800,  // safety
  };

  function nowISO() {
    return new Date().toISOString();
  }

  function getSessionId() {
    try {
      let id = localStorage.getItem('hs_session');
      if (!id) {
        id = 'hs_' + Math.random().toString(36).substr(2, 12);
        localStorage.setItem('hs_session', id);
      }
      return id;
    } catch (e) {
      return 'hs_' + Math.random().toString(36).substr(2, 12);
    }
  }

  const sessionId = getSessionId();
  let lastMove = 0;
  let lastScroll = 0;
  let eventsSent = 0;
  let eventsThisMinute = 0;
  setInterval(() => eventsThisMinute = 0, 60000);

  function sendEvent(payload) {
    if (eventsThisMinute > config.maxEventPerMinute) return;
    eventsThisMinute++;

    fetch(HS_RECORDER.rest_url, {
      method: 'POST',
      credentials: 'omit',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': HS_RECORDER.nonce || ''
      },
      body: JSON.stringify(payload)
    }).catch(function (err) {
      // swallow errors; don't retry aggressively
      // console.debug('hs send error', err);
    });
  }

  // pointer move
  window.addEventListener('mousemove', function (e) {
    const t = Date.now();
    if (t - lastMove < config.moveThrottleMs) return;
    lastMove = t;

    const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    const vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);

    sendEvent({
      session_id: sessionId,
      event_type: 'move',
      page_url: location.pathname + location.search,
      x: Math.round(e.pageX),
      y: Math.round(e.pageY),
      viewport_w: vw,
      viewport_h: vh,
      timestamp: nowISO()
    });
  }, { passive: true });

  // clicks
  window.addEventListener('click', function (e) {
    const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    const vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
    sendEvent({
      session_id: sessionId,
      event_type: 'click',
      page_url: location.pathname + location.search,
      x: Math.round(e.pageX),
      y: Math.round(e.pageY),
      viewport_w: vw,
      viewport_h: vh,
      timestamp: nowISO()
    });
  }, { passive: true });

  // scroll / scroll depth (%)
  window.addEventListener('scroll', function () {
    const t = Date.now();
    if (t - lastScroll < config.scrollThrottleMs) return;
    lastScroll = t;

    const doc = document.documentElement;
    const body = document.body;
    const scrollTop = (window.pageYOffset || doc.scrollTop) - (doc.clientTop || 0);
    const height = Math.max(body.scrollHeight, doc.scrollHeight, body.offsetHeight, doc.offsetHeight, body.clientHeight, doc.clientHeight);
    const viewport = window.innerHeight;
    const maxScroll = Math.max(0, height - viewport);
    const percent = maxScroll > 0 ? (scrollTop / maxScroll) * 100 : 0;

    sendEvent({
      session_id: sessionId,
      event_type: 'scroll',
      page_url: location.pathname + location.search,
      scroll_percent: parseFloat(percent.toFixed(2)),
      viewport_w: window.innerWidth,
      viewport_h: window.innerHeight,
      timestamp: nowISO()
    });
  }, { passive: true });

  // graceful: send viewport on load
  window.addEventListener('load', function () {
    sendEvent({
      session_id: sessionId,
      event_type: 'viewport',
      page_url: location.pathname + location.search,
      viewport_w: window.innerWidth,
      viewport_h: window.innerHeight,
      timestamp: nowISO()
    });
  }, { passive: true });

})();
