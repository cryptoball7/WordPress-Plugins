// Admin visualizer
// Provides simple fetch of events and draws heatmap + scrollmap onto canvas.

(function () {
  if (typeof window === 'undefined') return;

  function q(sel) { return document.querySelector(sel); }

  function init() {
    const heatBtn = q('#hs-generate-heatmap');
    const scrollBtn = q('#hs-generate-scrollmap');
    const urlInput = q('#hs-target-url');
    const perPage = q('#hs-perpage');

    heatBtn.addEventListener('click', function () {
      const url = urlInput.value || '';
      fetchEvents({ event_type: 'click', url: url, per_page: Number(perPage.value) || 2000 })
        .then(events => drawHeatmap(events));
    });

    scrollBtn.addEventListener('click', function () {
      const url = urlInput.value || '';
      fetchEvents({ event_type: 'scroll', url: url, per_page: Number(perPage.value) || 2000 })
        .then(events => drawScrollmap(events));
    });
  }

  function fetchEvents(params) {
    const url = new URL(HS_ADMIN.rest_events_url);
    Object.keys(params).forEach(k => url.searchParams.set(k, params[k]));
    return fetch(url.toString(), {
      headers: { 'X-WP-Nonce': HS_ADMIN.nonce || '' }
    }).then(r => r.json());
  }

  // Simple heatmap renderer: draws gaussian-ish circles for each click
  function drawHeatmap(events) {
    const canvas = getOrCreateCanvas();
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0,0,W,H);

    // accumulate intensities in an offscreen canvas for blending
    const off = document.createElement('canvas');
    off.width = W; off.height = H;
    const offCtx = off.getContext('2d');

    // draw blurred circles
    const radius = Math.max(12, Math.min(W, H) * 0.03);
    events.forEach(ev => {
      if (!ev.x || !ev.y) return;
      // ev coordinates are page coordinates; we render in viewport-sized canvas:
      const x = ev.x;
      const y = ev.y;
      // create radial gradient circle
      const grad = offCtx.createRadialGradient(x, y, 0, x, y, radius);
      grad.addColorStop(0, 'rgba(255,0,0,0.35)');
      grad.addColorStop(0.6, 'rgba(255,0,0,0.12)');
      grad.addColorStop(1, 'rgba(255,0,0,0)');
      offCtx.fillStyle = grad;
      offCtx.beginPath();
      offCtx.arc(x, y, radius, 0, Math.PI*2);
      offCtx.fill();
    });

    // colorize: draw offscreen as image then apply color mapping
    const img = offCtx.getImageData(0,0,W,H);
    const data = img.data;
    for (let i=0;i<data.length;i+=4) {
      // alpha measures intensity
      const a = data[i+3] / 255;
      if (a <= 0) continue;
      // map alpha to heat color: weaker = yellowish, strong = red
      const r = Math.min(255, Math.floor(200 * a + 55 * (a*a)));
      const g = Math.min(255, Math.floor(80 * (1 - a)));
      const b = Math.min(255, Math.floor(30 * (1 - a)));
      // write final pixel with scaled alpha
      data[i] = r;
      data[i+1] = g;
      data[i+2] = b;
      data[i+3] = Math.min(240, Math.floor(180 * a));
    }
    ctx.putImageData(img, 0, 0);
  }

  function drawScrollmap(events) {
    // events contain scroll_percent values, aggregate by vertical band
    const canvas = getOrCreateCanvas();
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0,0,W,H);

    if (!events.length) return;

    // aggregate into 100 buckets (percent)
    const buckets = new Array(101).fill(0);
    events.forEach(ev => {
      const p = Math.max(0, Math.min(100, Math.round(ev.scroll_percent || 0)));
      buckets[p]++;
    });

    // convert bucket counts to intensities and draw horizontal overlay
    const maxCount = Math.max.apply(null, buckets);
    for (let p=0;p<=100;p++) {
      const intensity = buckets[p] / (maxCount || 1); // 0..1
      const y = Math.round((p/100) * H);
      // draw rectangle with alpha by intensity
      ctx.fillStyle = `rgba(200,0,0,${0.1 + 0.8*intensity})`;
      ctx.fillRect(0, y, W, Math.max(1, Math.round(H/100)));
    }
  }

  function getOrCreateCanvas() {
    let canvas = document.getElementById('hs-visual-canvas');
    const container = document.getElementById('hs-visual-container');
    if (!canvas) {
      // default to 1366x768 sample area — admin can change size in future.
      canvas = document.createElement('canvas');
      canvas.id = 'hs-visual-canvas';
      canvas.width = 1366;
      canvas.height = 768;
      canvas.style.width = '100%';
      canvas.style.border = '1px solid #ddd';
      canvas.style.background = '#fff';
      container.appendChild(canvas);
    }
    return canvas;
  }

  document.addEventListener('DOMContentLoaded', init);
})();
