// STREAMFLIX Videoplayer
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = t => { t = Math.max(0, Math.floor(t || 0)); const h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = t % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0'); };

export function createPlayer(root, { api, kind, id, csrf, onExit, onNavigate }) {
  let info = null, hls = null, token = null, offset = 0, pingT = null, idleT = null, destroyed = false, curSub = null, introDone = false;
  const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];
  root.innerHTML = `<div class="player" id="pl">
    <video id="pv" playsinline></video><div class="spin" id="pspin"></div>
    <div class="ui" id="pui">
      <div class="ptop"><button class="back" id="pback" title="Zurück">←</button><span id="ptitle"></span></div>
      <div class="pbot">
        <div class="seek"><span class="tm" id="ptime">0:00</span><input type="range" id="pseek" min="0" max="1000" value="0"><span class="tm" id="pdur">0:00</span></div>
        <div class="ctrls">
          <button class="cbtn" id="pprev" title="Vorherige Episode">⏮</button>
          <button class="cbtn" id="pplay" title="Wiedergabe/Pause (Leertaste)">▶</button>
          <button class="cbtn" id="pback10" title="10 Sekunden zurück">⟲10</button>
          <button class="cbtn" id="pfwd10" title="10 Sekunden vor">10⟳</button>
          <button class="cbtn" id="pmute" title="Ton (M)">🔊</button><input type="range" class="vol" id="pvol" min="0" max="1" step="0.05" value="1">
          <button class="cbtn" id="pnext" title="Nächste Episode (N)">⏭</button>
          <span class="sp"></span>
          <div class="pmenu" id="mauto"><button class="cbtn txt" id="bauto">Autoplay: an</button></div>
          <div class="pmenu" id="mspeed"><button class="cbtn txt" data-m="mspeed">1×</button><div class="pop" id="lspeed"></div></div>
          <div class="pmenu" id="maudio"><button class="cbtn txt" data-m="maudio">Audio</button><div class="pop" id="laudio"></div></div>
          <div class="pmenu" id="msub"><button class="cbtn txt" data-m="msub">Untertitel</button><div class="pop" id="lsub"></div></div>
          <button class="cbtn" id="pfs" title="Vollbild (F)">⛶</button>
        </div>
      </div>
    </div>
    <button class="skip hidden" id="pskip"></button>
    <div class="pmsg hidden" id="pmsg"></div>
  </div>`;
  const $ = s => root.querySelector(s);
  const pl = $('#pl'), v = $('#pv'), spin = $('#pspin');
  const cur = () => offset + (v.currentTime || 0);
  const dur = () => (info && info.duration) || v.duration || 0;
  const showMsg = (html, back = true) => { const m = $('#pmsg'); m.innerHTML = `<div>${html}${back ? '<p><button class="btn" id="pmback">Zurück</button></p>' : ''}</div>`; m.classList.remove('hidden'); const b = m.querySelector('#pmback'); if (b) b.onclick = () => exit(); spin.classList.add('hidden'); };

  async function start(opts = {}) {
    spin.classList.remove('hidden'); $('#pmsg').classList.add('hidden');
    const old = token; token = null;
    if (hls) { hls.destroy(); hls = null; }
    v.removeAttribute('src'); v.load();
    let r;
    try { r = await api('POST', '/api/stream/start', { kind, id, audio: opts.audio, start: opts.start, old_token: old }); }
    catch (e) { return showMsg('⚠️ ' + esc(e.message)); }
    if (destroyed) { api('POST', '/api/stream/stop', { token: r.token }).catch(() => {}); return; }
    info = r; token = r.token; offset = r.offset || 0;
    $('#ptitle').textContent = r.title;
    $('#pdur').textContent = fmt(r.duration);
    $('#pprev').classList.toggle('hidden', !r.prev); $('#pnext').classList.toggle('hidden', !r.next);
    $('#bauto').textContent = 'Autoplay: ' + (autoplay ? 'an' : 'aus'); $('#mauto').classList.toggle('hidden', !r.next);
    buildMenus();
    const startAt = r.mode === 'direct' ? (r.start || 0) : 0;
    if (r.mode === 'direct') {
      v.src = r.url;
      v.addEventListener('loadedmetadata', () => { if (startAt > 0) v.currentTime = startAt; v.play().catch(() => {}); }, { once: true });
    } else if (window.Hls && Hls.isSupported()) {
      hls = new Hls({ autoStartLoad: false, maxBufferLength: 40, backBufferLength: 20, enableWorker: true, manifestLoadingMaxRetry: 3 });
      hls.loadSource(r.url); hls.attachMedia(v);
      hls.on(Hls.Events.MANIFEST_PARSED, () => { hls.startLoad(0); v.play().catch(() => {}); });
      hls.on(Hls.Events.ERROR, (_, d) => {
        if (!d.fatal) return;
        if (d.type === Hls.ErrorTypes.NETWORK_ERROR) hls.startLoad(); else if (d.type === Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError(); else showMsg('⚠️ Wiedergabefehler (' + esc(d.details) + ')');
      });
    } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
      v.src = r.url; v.play().catch(() => {});
    } else return showMsg('⚠️ Dieser Browser kann den Stream nicht abspielen.');
    setSub(opts.keepSub ? curSub : (r.default_subtitle ?? null), true);
    introDone = false;
    clearInterval(pingT); pingT = setInterval(ping, 10000);
  }

  async function ping() {
    if (!token || v.paused && !v.ended && false) return;
    try {
      const j = await api('POST', '/api/stream/ping', { token, position: cur(), duration: dur() });
      if (j.killed && !destroyed) { v.pause(); token = null; showMsg('⛔ Die Wiedergabe wurde beendet (Administrator oder Sitzung abgelaufen).'); }
    } catch (e) { /* Netzwerkproblem: nächster Ping versucht es erneut */ }
  }

  function setSub(subId, silent) {
    curSub = subId;
    v.querySelectorAll('track').forEach(t => t.remove());
    if (subId) {
      const s = info.subtitles.find(x => x.id === subId); if (!s) return;
      const t = document.createElement('track'); t.kind = 'subtitles'; t.label = s.label; t.srclang = s.lang || 'und'; t.default = true;
      t.src = `/stream.php?a=sub&id=${subId}&o=${Math.floor(offset)}`;
      v.appendChild(t); t.addEventListener('load', () => { t.track.mode = 'showing'; }); setTimeout(() => { if (t.track) t.track.mode = 'showing'; }, 300);
    }
    if (!silent) buildMenus();
  }

  function buildMenus() {
    $('#lspeed').innerHTML = SPEEDS.map(s => `<button data-speed="${s}" class="${v.playbackRate === s ? 'on' : ''}">${s}×</button>`).join('');
    const a = info.audio || [];
    $('#maudio').classList.toggle('hidden', a.length < 2);
    $('#laudio').innerHTML = a.map(t => `<button data-audio="${t.i}" class="${t.i === info.audio_index ? 'on' : ''}">${esc(t.label)}</button>`).join('');
    const s = info.subtitles || [];
    $('#msub').classList.toggle('hidden', !s.length);
    $('#lsub').innerHTML = `<button data-sub="0" class="${!curSub ? 'on' : ''}">Aus</button>` + s.map(t => `<button data-sub="${t.id}" class="${curSub === t.id ? 'on' : ''}">${esc(t.label)}</button>`).join('');
  }

  async function seekTo(t) {
    t = Math.max(0, Math.min(t, Math.max(0, dur() - 1)));
    if (!info || info.mode === 'direct') { v.currentTime = t; return; }
    const local = t - offset;
    const seekable = v.seekable.length ? v.seekable.end(v.seekable.length - 1) : 0;
    if (local >= 0 && local < seekable - 2) { v.currentTime = local; return; }
    await start({ audio: info.audio_index, start: t, keepSub: true }); // neu transkodieren ab Zielposition
  }

  let autoplay = true;
  function updateUI() {
    const d = dur(), t = cur();
    if (d > 0 && document.activeElement !== $('#pseek')) $('#pseek').value = Math.round(t / d * 1000);
    $('#ptime').textContent = fmt(t);
    $('#pplay').textContent = v.paused ? '▶' : '⏸';
    $('#pmute').textContent = v.muted || v.volume === 0 ? '🔇' : '🔊';
    // Intro / Abspann
    const sk = $('#pskip'); let label = null, act = null;
    if (info && info.intro && t >= info.intro[0] && t < info.intro[1] - 1) { label = 'Intro überspringen'; act = () => seekTo(info.intro[1]); }
    else if (info && info.credits && t >= info.credits && d - t > 2) {
      if (info.next) { label = 'Nächste Folge ▶'; act = () => go(info.next.id); } else { label = 'Abspann überspringen'; act = () => seekTo(d - 1); }
    }
    sk.classList.toggle('hidden', !label); if (label) { sk.textContent = label; sk.onclick = act; }
  }
  function go(epId) { cleanupNetwork(true); onNavigate(epId); }

  function wake() { pl.classList.remove('idle'); clearTimeout(idleT); idleT = setTimeout(() => { if (!v.paused) pl.classList.add('idle'); }, 3000); }
  function toggle() { v.paused ? v.play().catch(() => {}) : v.pause(); }
  function fs() { document.fullscreenElement ? document.exitFullscreen() : pl.requestFullscreen?.().catch(() => {}); }
  function exit() { cleanupNetwork(true); onExit(); }

  function cleanupNetwork(final) {
    clearInterval(pingT);
    if (token) { fetch('/api/stream/stop', { method: 'POST', keepalive: true, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ token, position: cur(), duration: dur() }) }).catch(() => {}); token = null; }
    if (hls) { hls.destroy(); hls = null; }
    if (final) { v.pause(); v.removeAttribute('src'); v.load(); }
  }

  // Ereignisse
  v.addEventListener('timeupdate', updateUI); v.addEventListener('play', updateUI); v.addEventListener('pause', () => { updateUI(); pl.classList.remove('idle'); });
  v.addEventListener('waiting', () => spin.classList.remove('hidden')); v.addEventListener('playing', () => spin.classList.add('hidden')); v.addEventListener('canplay', () => spin.classList.add('hidden'));
  v.addEventListener('ratechange', () => { $('[data-m=mspeed]').textContent = v.playbackRate + '×'; });
  v.addEventListener('error', () => { if (v.error && token) showMsg('⚠️ Wiedergabefehler. Das Format wird vom Browser nicht unterstützt.'); });
  v.addEventListener('ended', async () => {
    await ping();
    if (info && info.next && autoplay && info.autoplay_next) go(info.next.id); else exit();
  });
  v.addEventListener('click', toggle); v.addEventListener('dblclick', fs);
  pl.addEventListener('mousemove', wake); pl.addEventListener('touchstart', wake, { passive: true });
  $('#pplay').onclick = toggle; $('#pback').onclick = exit; $('#pfs').onclick = fs;
  $('#pback10').onclick = () => seekTo(cur() - 10); $('#pfwd10').onclick = () => seekTo(cur() + 10);
  $('#pmute').onclick = () => { v.muted = !v.muted; updateUI(); };
  $('#pvol').oninput = e => { v.volume = +e.target.value; v.muted = false; };
  $('#pseek').onchange = e => seekTo(dur() * (+e.target.value) / 1000);
  $('#pseek').oninput = e => { $('#ptime').textContent = fmt(dur() * (+e.target.value) / 1000); };
  $('#pprev').onclick = () => info.prev && go(info.prev.id); $('#pnext').onclick = () => info.next && go(info.next.id);
  $('#bauto').onclick = () => { autoplay = !autoplay; $('#bauto').textContent = 'Autoplay: ' + (autoplay ? 'an' : 'aus'); };
  pl.addEventListener('click', e => {
    const m = e.target.closest('[data-m]');
    pl.querySelectorAll('.pmenu').forEach(p => { if (!m || p.id !== m.dataset.m) p.classList.remove('open'); });
    if (m) { $('#' + m.dataset.m).classList.toggle('open'); return; }
    const b = e.target.closest('.pop button'); if (!b) return;
    pl.querySelectorAll('.pmenu').forEach(p => p.classList.remove('open'));
    if (b.dataset.speed) { v.playbackRate = +b.dataset.speed; buildMenus(); }
    else if (b.dataset.audio !== undefined) { const a = +b.dataset.audio; if (a !== info.audio_index) start({ audio: a, start: cur(), keepSub: true }); }
    else if (b.dataset.sub !== undefined) setSub(+b.dataset.sub || null);
  });
  const onKey = e => {
    if (e.target.matches('input[type=text],textarea')) return;
    const k = e.key;
    if (k === ' ' || k === 'k') { e.preventDefault(); toggle(); }
    else if (k === 'ArrowRight') seekTo(cur() + 10); else if (k === 'ArrowLeft') seekTo(cur() - 10);
    else if (k === 'ArrowUp') { v.volume = Math.min(1, v.volume + .1); e.preventDefault(); } else if (k === 'ArrowDown') { v.volume = Math.max(0, v.volume - .1); e.preventDefault(); }
    else if (k === 'f') fs(); else if (k === 'm') { v.muted = !v.muted; } else if (k === 'n' && info?.next) go(info.next.id);
    else if (k === 'Escape' && !document.fullscreenElement) exit();
    wake();
  };
  const onHide = () => { if (token) cleanupNetwork(false); };
  document.addEventListener('keydown', onKey); window.addEventListener('pagehide', onHide);

  start(); wake();
  return { destroy() { destroyed = true; document.removeEventListener('keydown', onKey); window.removeEventListener('pagehide', onHide); cleanupNetwork(true); if (document.fullscreenElement) document.exitFullscreen().catch(() => {}); root.innerHTML = ''; } };
}
