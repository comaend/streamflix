// STREAMFLIX Admin-Center
const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const root = $('#admin');
const S = { csrf: '', user: null, tab: 'dashboard', timers: [], hist: { cpu: [], ram: [], rx: 0, tx: 0, t: 0, rate: [] } };
const LV = { user: 1, moderator: 2, admin: 3, superadmin: 4 };
const can = r => LV[S.user.role] >= LV[r];
const bytes = b => { b = +b || 0; const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (b >= 1024 && i < 4) { b /= 1024; i++; } return b.toFixed(i ? 1 : 0) + ' ' + u[i]; };
const dur = s => { s = +s || 0; const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60); return (d ? d + ' d ' : '') + h + ' h ' + m + ' min'; };
const ts = s => s ? esc(String(s).replace('T', ' ').slice(0, 19)) : '–';

async function api(method, path, body) {
  const o = { method, headers: { 'X-CSRF-Token': S.csrf }, credentials: 'same-origin' };
  if (body instanceof FormData) o.body = body; else if (body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); }
  const r = await fetch(path, o); let j = {}; try { j = await r.json(); } catch (e) { /* leer */ }
  if (r.status === 401) location.href = '/';
  if (!r.ok) throw new Error(j.error || 'Fehler ' + r.status);
  return j;
}
const toast = (m, bad) => { const t = document.createElement('div'); t.className = 'toast' + (bad ? ' bad' : ''); t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 3500); };
const wrap = fn => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, true); } };
function modal(html, wide) { $('#modal')?.remove(); const m = document.createElement('div'); m.className = 'modal'; m.id = 'modal'; m.innerHTML = `<div class="m ${wide ? 'wide' : ''}">${html}</div>`; m.addEventListener('mousedown', e => { if (e.target === m) m.remove(); }); document.body.appendChild(m); return m; }
const closeModal = () => $('#modal')?.remove();
const clearTimers = () => { S.timers.forEach(clearInterval); S.timers = []; };

const TABS = [['dashboard', 'Dashboard', 'moderator'], ['users', 'Benutzer', 'moderator'], ['profiles', 'Profile', 'moderator'], ['media', 'Medien', 'moderator'], ['folders', 'Medienordner', 'admin'],
  ['streams', 'Aktive Streams', 'moderator'], ['server', 'Server', 'admin'], ['settings', 'Einstellungen', 'admin'], ['logs', 'Protokoll', 'admin'], ['backups', 'Backups', 'admin']];

async function init() {
  const j = await fetch('/api/session', { credentials: 'same-origin' }).then(r => r.json());
  S.csrf = j.csrf; S.user = j.user;
  if (!S.user || !can('moderator')) { location.href = '/'; return; }
  root.innerHTML = `<div class="alay"><aside class="side"><a class="brand" href="/">${esc(j.settings.platform_name)}</a>
    ${TABS.filter(t => can(t[2])).map(t => `<a data-tab="${t[0]}">${t[1]}</a>`).join('')}
    <a href="/" style="margin-top:1rem">← Zur App</a></aside><main class="amain" id="am"></main></div>`;
  window.addEventListener('hashchange', go); go();
}
function go() {
  const t = location.hash.replace('#', '') || 'dashboard';
  S.tab = TABS.find(x => x[0] === t && can(x[2])) ? t : 'dashboard';
  clearTimers();
  document.querySelectorAll('.side a[data-tab]').forEach(a => a.classList.toggle('on', a.dataset.tab === S.tab));
  const fn = views[S.tab]; $('#am').innerHTML = '<p class="muted">Lade …</p>';
  fn($('#am')).catch(e => { $('#am').innerHTML = `<div class="err">${esc(e.message)}</div>`; });
}
document.addEventListener('click', e => { const a = e.target.closest('.side a[data-tab]'); if (a) location.hash = a.dataset.tab; });

/* ---------- Diagramme ---------- */
const spark = (arr, max = 100) => { if (arr.length < 2) return '<svg class="spark"></svg>'; const w = 300, h = 70; const pts = arr.map((v, i) => `${(i / (arr.length - 1) * w).toFixed(1)},${(h - Math.min(v, max) / max * h).toFixed(1)}`).join(' ');
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none"><polyline fill="none" stroke="var(--accent)" stroke-width="2" points="${pts}"/></svg>`; };
const gauge = (p, label) => `<div style="margin:.5rem 0"><div style="display:flex;justify-content:space-between" class="small"><span>${label}</span><span>${p.toFixed(0)} %</span></div><div class="gauge"><i style="width:${Math.min(100, p)}%"></i></div></div>`;
function lastDays(rows, n = 14) { const m = {}; rows.forEach(r => m[r.d] = r.c); const out = []; for (let i = n - 1; i >= 0; i--) { const d = new Date(Date.now() - i * 864e5).toISOString().slice(0, 10); out.push([d, m[d] || 0]); } return out; }
const bars = rows => { const mx = Math.max(1, ...rows.map(r => r[1])); return `<div class="bars">${rows.map(r => `<div style="height:${r[1] / mx * 100}%" data-t="${r[0].slice(5)}: ${r[1]}"></div>`).join('')}</div>`; };
function pushStats(st) {
  const h = S.hist; h.cpu.push(st.cpu); h.ram.push(st.ram_total ? st.ram_used / st.ram_total * 100 : 0);
  if (h.t) { const dt = st.ts - h.t || 1; h.rate.push(((st.net_rx - h.rx) + (st.net_tx - h.tx)) / dt); }
  h.rx = st.net_rx; h.tx = st.net_tx; h.t = st.ts; ['cpu', 'ram', 'rate'].forEach(k => { if (h[k].length > 60) h[k].shift(); });
}
function liveHtml(st) {
  const ru = st.ram_total ? st.ram_used / st.ram_total * 100 : 0, dp = st.data_total ? (1 - st.data_free / st.data_total) * 100 : 0, mp = st.media_total ? (1 - st.media_free / st.media_total) * 100 : 0;
  const r = S.hist.rate.at(-1) || 0, mx = Math.max(1, ...S.hist.rate);
  return `<div class="two"><div class="panel"><h2>CPU ${st.cpu}% · Last ${st.load.join(' / ')}</h2>${spark(S.hist.cpu)}</div>
    <div class="panel"><h2>RAM ${bytes(st.ram_used)} / ${bytes(st.ram_total)}</h2>${spark(S.hist.ram)}</div>
    <div class="panel"><h2>Netzwerk ${bytes(r)}/s</h2>${spark(S.hist.rate, mx)}<div class="muted small">Gesamt ⬇ ${bytes(st.net_rx)} · ⬆ ${bytes(st.net_tx)} (seit Container-Start)</div></div>
    <div class="panel"><h2>Speicher</h2>${gauge(dp, 'App-Daten (' + bytes(st.data_free) + ' frei)')}${gauge(mp, 'Medien-Laufwerk (' + bytes(st.media_free) + ' frei von ' + bytes(st.media_total) + ')')}
      <div class="muted small">Cache: ${bytes(st.cache)} · Temperatur: ${st.temp !== null ? st.temp + ' °C' : 'nicht verfügbar'} · Uptime: ${dur(st.uptime)}</div></div></div>`;
}

/* ---------- Ansichten ---------- */
const views = {};
views.dashboard = async m => {
  const d = await api('GET', '/api/admin/dashboard'); pushStats(d.stats);
  const stat = (n, l) => `<div class="stat"><b>${n}</b><span>${l}</span></div>`;
  m.innerHTML = `<h1>Dashboard</h1><div class="cards">${stat(d.movies, 'Filme')}${stat(d.series, 'Serien')}${stat(d.episodes, 'Episoden')}${stat(d.users, 'Benutzer')}${stat(d.active_users, 'Aktive Benutzer (15 Min.)')}${stat(d.active_profiles, 'Aktive Profile')}${stat(d.active_streams, 'Aktive Streams')}${stat(bytes(d.library_bytes), 'Bibliotheksgröße')}</div>
   <div id="live">${liveHtml(d.stats)}</div>
   <div class="two"><div class="panel"><h2>Wiedergaben pro Tag (14 Tage)</h2>${bars(lastDays(d.streams_by_day))}</div><div class="panel"><h2>Logins pro Tag (14 Tage)</h2>${bars(lastDays(d.logins_by_day))}</div>
   <div class="panel"><h2>Genres</h2>${d.top_genres.length ? d.top_genres.map(g => `<div class="small" style="display:flex;justify-content:space-between"><span>${esc(g.name)}</span><span>${g.c}</span></div>`).join('') : '<span class="muted">Noch keine Genres.</span>'}</div></div>`;
  S.timers.push(setInterval(wrap(async () => { const j = await api('GET', '/api/admin/dashboard'); pushStats(j.stats); if ($('#live')) $('#live').innerHTML = liveHtml(j.stats); }), 8000));
};

views.users = async m => {
  const j = await api('GET', '/api/admin/users'); S.assignable = j.assignable;
  m.innerHTML = `<h1>Benutzer</h1><div class="row2"><button class="btn red small" data-act="newuser">＋ Benutzer erstellen</button></div><div class="panel tw"><table><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Profile</th><th>Letzter Login</th><th></th></tr>
   ${j.users.map(u => `<tr><td>${esc(u.username)}</td><td>${esc(u.email || '–')}</td><td><span class="pill">${esc(u.role)}</span></td><td><span class="pill ${u.status === 'active' ? 'ok' : 'bad'}">${u.status === 'active' ? 'aktiv' : 'gesperrt'}</span></td><td>${u.profiles}</td><td>${ts(u.last_login)}</td>
   <td style="white-space:nowrap"><button class="btn dark small" data-act="edituser" data-id="${u.id}">Bearbeiten</button> <button class="btn dark small" data-act="useract" data-id="${u.id}">Aktivität</button></td></tr>`).join('')}</table></div>`;
  S.users = j.users;
};
views.profiles = async m => {
  const j = await api('GET', '/api/admin/profiles');
  m.innerHTML = `<h1>Profile</h1><div class="row2"><button class="btn red small" data-act="newprofile">＋ Profil erstellen</button></div><div class="panel tw"><table><tr><th>Konto</th><th>Profil</th><th>Bild</th><th>Kinderprofil</th><th>PIN</th><th></th></tr>
   ${j.profiles.map(p => `<tr><td>${esc(p.username)}</td><td>${esc(p.name)}</td><td>${esc(p.avatar.startsWith('img:') ? 'eigenes Bild' : p.avatar)}</td><td>${p.is_kids ? 'ja' : '–'}</td><td>${p.has_pin ? '🔒 gesetzt' : '–'}</td>
   <td>${p.manageable ? `<button class="btn dark small" data-act="editprofile" data-id="${p.id}">Bearbeiten</button>` : ''}</td></tr>`).join('')}</table></div>`;
  S.aprofiles = j.profiles;
};

let mediaKind = 'movie', mediaQ = '', mediaPage = 1;
views.media = async m => {
  const j = await api('GET', `/api/admin/media?kind=${mediaKind}&q=${encodeURIComponent(mediaQ)}&page=${mediaPage}&per=30&sort=title`);
  const pages = Math.max(1, Math.ceil(j.total / j.per));
  m.innerHTML = `<h1>Medienverwaltung</h1><div class="tabs"><button data-act="mkind" data-k="movie" class="${mediaKind === 'movie' ? 'on' : ''}">Filme</button><button data-act="mkind" data-k="series" class="${mediaKind === 'series' ? 'on' : ''}">Serien</button></div>
   <form class="row2" data-form="mediasearch"><input type="search" name="q" placeholder="Suchen …" value="${esc(mediaQ)}"><button class="btn dark small">Suchen</button></form>
   <div class="panel tw"><table><tr><th>Titel</th><th>Jahr</th><th>Freigabe</th><th>Genres</th><th></th></tr>${j.items.map(i => `<tr><td>${esc(i.title)}</td><td>${esc(i.year || '–')}</td><td>${esc(i.age || '–')}</td><td>${esc(i.genres || '–')}</td>
   <td><button class="btn dark small" data-act="editmedia" data-id="${i.id}">Bearbeiten</button></td></tr>`).join('') || '<tr><td colspan="5" class="muted">Keine Einträge.</td></tr>'}</table></div>
   <div class="row2"><button class="btn dark small" data-act="mpage" data-d="-1" ${mediaPage <= 1 ? 'disabled' : ''}>‹</button><span class="muted">Seite ${mediaPage} / ${pages} (${j.total})</span><button class="btn dark small" data-act="mpage" data-d="1" ${mediaPage >= pages ? 'disabled' : ''}>›</button></div>`;
};

views.folders = async m => {
  const j = await api('GET', '/api/admin/folders');
  const stat = f => { const l = f.last; if (!l) return '<span class="muted">Noch nie gescannt</span>'; if (l.status === 'running') return `<span class="pill warn">läuft ${l.processed}/${l.total}</span>`;
    const errs = JSON.parse(l.errors || '[]'); return `<span class="pill ${l.status === 'done' ? 'ok' : 'bad'}">${l.status === 'done' ? 'fertig' : 'Fehler'}</span> <span class="small">+${l.added} ~${l.updated} −${l.removed}</span>${errs.length ? ` <a class="small" style="cursor:pointer;text-decoration:underline" data-act="scanerr" data-id="${f.id}">${errs.length} Meldung(en)</a>` : ''}`; };
  S.folders = j.folders;
  m.innerHTML = `<h1>Medienordner</h1><div class="panel"><p class="small muted">Der Container sieht ausschließlich den Medienstamm <b>${esc(j.media_root)}</b> (schreibgeschützt). Alle Ordner müssen darunter liegen. Zusätzliche Festplatten funktionieren, sobald sie im Umbrel-Speicher sichtbar sind (siehe README).</p>
   <div class="row2"><button class="btn red small" data-act="addfolder">＋ Ordner hinzufügen</button><button class="btn dark small" data-act="scanall">Alle jetzt scannen</button></div></div>
   <div class="panel tw"><table><tr><th>Pfad</th><th>Typ</th><th>Dateien</th><th>Zugriff</th><th>Auto-Scan</th><th>Letzter Scan</th><th>Status</th><th></th></tr>
   ${j.folders.map(f => `<tr><td><b>${esc(f.label || '')}</b><br><span class="small muted">${esc(f.path)}</span></td><td>${f.type === 'movies' ? 'Filme' : 'Serien'}</td><td>${f.files}</td><td><span class="pill ${f.accessible ? 'ok' : 'bad'}">${f.accessible ? 'lesbar' : 'nicht lesbar'}</span></td><td>${f.auto_scan ? 'ja' : 'nein'}</td><td>${ts(f.last_scan)}</td><td>${stat(f)}</td>
   <td style="white-space:nowrap"><button class="btn dark small" data-act="scanfolder" data-id="${f.id}">Scannen</button> <button class="btn dark small" data-act="editfolder" data-id="${f.id}">Bearbeiten</button></td></tr>`).join('') || '<tr><td colspan="8" class="muted">Noch kein Medienordner eingerichtet.</td></tr>'}</table></div>`;
  if (j.folders.some(f => f.last?.status === 'running')) S.timers.push(setInterval(() => { if (!$('#modal')) views.folders(m).catch(() => {}); }, 3000));
};

views.streams = async m => {
  const draw = async () => {
    const j = await api('GET', '/api/admin/streams');
    m.innerHTML = `<h1>Aktive Streams</h1><div class="panel tw"><table><tr><th>Benutzer</th><th>Profil</th><th>Titel</th><th>Modus</th><th>Gestartet</th><th>Position</th><th>IP</th><th>Bitrate*</th><th></th></tr>
    ${j.streams.map(s => `<tr><td>${esc(s.username)}</td><td>${esc(s.profile)}</td><td>${esc(s.title)}</td><td><span class="pill ${s.mode === 'direct' ? 'ok' : s.mode === 'remux' ? 'warn' : 'bad'}">${{ direct: 'Direct Play', remux: 'Remux (HLS)', transcode: 'Transcode' }[s.mode]}</span></td><td>${ts(s.started_at)}</td><td>${Math.floor(s.position / 60)}:${String(Math.floor(s.position % 60)).padStart(2, '0')}</td><td>${esc(s.ip)}</td><td>${s.bitrate ? (s.bitrate / 1e6).toFixed(1) + ' Mbit/s' : '–'}</td>
    <td>${can('admin') ? `<button class="btn danger small" data-act="killstream" data-t="${s.token}">Beenden</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="9" class="muted">Gerade streamt niemand.</td></tr>'}</table></div><p class="muted small">* nominelle Bitrate der Quelldatei. Direct Play wird von nginx ausgeliefert, daher gibt es keine exakte Live-Übertragungsrate pro Stream. Die gesamte Netzwerklast siehst du unter Dashboard/Server.</p>`;
  };
  await draw(); S.timers.push(setInterval(() => draw().catch(() => {}), 5000));
};

views.server = async m => {
  const j = await api('GET', '/api/admin/server'); pushStats(j.stats); const i = j.info;
  const row = (k, v) => `<tr><td>${k}</td><td>${v}</td></tr>`;
  m.innerHTML = `<h1>Server</h1><div id="live">${liveHtml(j.stats)}</div><div class="panel tw"><h2>System</h2><table>${row('App-Version', esc(i.app))}${row('PHP', esc(i.php))}${row('FFmpeg', esc(i.ffmpeg))}${row('SQLite', esc(i.sqlite))}${row('Architektur', esc(i.arch))}
   ${row('Datenbank', `<span class="pill ${i.db_status === 'ok' ? 'ok' : 'bad'}">${esc(i.db_status)}</span> · ${bytes(i.db_size)} · Schema v${i.schema}`)}${row('Medienstamm', `${esc(i.media_root)} <span class="pill ${i.media_root_ok ? 'ok' : 'bad'}">${i.media_root_ok ? 'lesbar' : 'nicht lesbar'}</span>`)}${row('Uptime', dur(j.stats.uptime))}${row('Temperatur', j.stats.temp !== null ? j.stats.temp + ' °C' : 'nicht verfügbar')}</table></div>`;
  S.timers.push(setInterval(wrap(async () => { const x = await api('GET', '/api/admin/server'); pushStats(x.stats); if ($('#live')) $('#live').innerHTML = liveHtml(x.stats); }), 6000));
};

const SETS = [
  ['platform_name', 'Plattformname', 'text'], ['accent_color', 'Akzentfarbe', 'color'], ['registration_enabled', 'Registrierung erlauben', 'bool'], ['max_users', 'Maximale Benutzerzahl (0 = unbegrenzt)', 'number'],
  ['max_streams', 'Maximale gleichzeitige Streams (0 = unbegrenzt)', 'number'], ['max_transcodes', 'Maximale gleichzeitige Transkodierungen (Pi: 1 empfohlen)', 'number'],
  ['default_language', 'Standardsprache', ['de', 'en']], ['default_subtitle', 'Standard-Untertitel (off oder Sprachcode, z. B. de)', 'text'],
  ['video_quality', 'Max. Auflösung beim Transkodieren (0 = Original)', ['480', '720', '1080', '0']], ['cache_max_mb', 'Cache-Größe (MB)', 'number'],
  ['auto_scan_enabled', 'Automatische Bibliotheks-Scans', 'bool'], ['auto_scan_hours', 'Scan-Intervall (Stunden)', 'number'], ['maintenance_mode', 'Wartungsmodus (nur Moderatoren+)', 'bool'],
  ['backup_auto', 'Automatische Backups', 'bool'], ['backup_hours', 'Backup-Intervall (Stunden)', 'number'], ['backup_keep', 'Anzahl aufbewahrter Auto-Backups', 'number'],
];
views.settings = async m => {
  const { settings: s } = await api('GET', '/api/admin/settings');
  const f = ([k, l, t]) => t === 'bool' ? `<label class="check"><input type="checkbox" name="${k}" ${s[k] === '1' ? 'checked' : ''}> ${l}</label>`
    : Array.isArray(t) ? `<div class="field"><label>${l}</label><select name="${k}">${t.map(o => `<option ${s[k] === o ? 'selected' : ''}>${o}</option>`).join('')}</select></div>`
    : `<div class="field"><label>${l}</label><input type="${t === 'color' ? 'color' : t === 'number' ? 'number' : 'text'}" name="${k}" value="${esc(s[k])}"></div>`;
  m.innerHTML = `<h1>Globale Einstellungen</h1><form class="panel" data-form="settings" style="max-width:640px">${SETS.map(f).join('')}
   <div class="field"><label>Logo (PNG/JPG/WebP)</label><input type="file" id="logofile" accept="image/*"> <span class="small muted">${s.logo ? 'Aktuell: ' + esc(s.logo) + ' ' : ''}${s.logo ? '<a style="cursor:pointer;text-decoration:underline" data-act="rmimg" data-k="logo">entfernen</a>' : ''}</span></div>
   <div class="field"><label>Login-Hintergrund</label><input type="file" id="bgfile" accept="image/*"> <span class="small muted">${s.login_bg ? '<a style="cursor:pointer;text-decoration:underline" data-act="rmimg" data-k="login_bg">entfernen</a>' : ''}</span></div>
   <button class="btn red">Speichern</button></form>`;
};

let logQ = '', logLevel = '', logPage = 1;
views.logs = async m => {
  const j = await api('GET', `/api/admin/logs?q=${encodeURIComponent(logQ)}&level=${logLevel}&page=${logPage}`);
  const pages = Math.max(1, Math.ceil(j.total / j.per));
  m.innerHTML = `<h1>Aktivitätsprotokoll</h1><form class="row2" data-form="logfilter"><input type="search" name="q" placeholder="Suche (Benutzer, IP, Aktion, Details)" value="${esc(logQ)}" style="min-width:280px"><select name="level"><option value="">Alle Stufen</option><option value="info">Info</option><option value="warn">Warnung</option><option value="error">Fehler</option></select><button class="btn dark small">Filtern</button></form>
   <div class="panel tw"><table><tr><th>Zeit (UTC)</th><th>Stufe</th><th>Benutzer</th><th>Aktion</th><th>Details</th><th>IP</th></tr>${j.logs.map(l => `<tr class="log-${l.level}"><td>${ts(l.created_at)}</td><td>${l.level}</td><td>${esc(l.username || '–')}</td><td>${esc(l.action)}</td><td>${esc(l.detail || '')}</td><td>${esc(l.ip || '')}</td></tr>`).join('') || '<tr><td colspan="6" class="muted">Keine Einträge.</td></tr>'}</table></div>
   <div class="row2"><button class="btn dark small" data-act="lpage" data-d="-1" ${logPage <= 1 ? 'disabled' : ''}>‹</button><span class="muted">Seite ${logPage} / ${pages} (${j.total} Einträge)</span><button class="btn dark small" data-act="lpage" data-d="1" ${logPage >= pages ? 'disabled' : ''}>›</button></div>`;
  $('select[name=level]').value = logLevel;
};

views.backups = async m => {
  const j = await api('GET', '/api/admin/backups');
  m.innerHTML = `<h1>Backups</h1><div class="panel"><p class="small muted">Gesichert werden Datenbank (Benutzer, Profile, Verläufe, Metadaten, Einstellungen) und lokale Bilder. Deine Originalmedien werden <b>nicht</b> gesichert. Zeitplan: siehe Einstellungen.</p><div class="row2"><button class="btn red small" data-act="mkbackup">Backup jetzt erstellen</button></div></div>
   <div class="panel tw"><table><tr><th>Datei</th><th>Art</th><th>Größe</th><th>Erstellt (UTC)</th><th></th></tr>${j.backups.map(b => `<tr><td>${esc(b.filename)}</td><td>${esc(b.kind)}</td><td>${bytes(b.size)}</td><td>${ts(b.created_at)}</td>
   <td style="white-space:nowrap"><a class="btn dark small" href="/api/admin/backups/${b.id}/download">Download</a> ${can('superadmin') ? `<button class="btn dark small" data-act="restore" data-id="${b.id}">Wiederherstellen</button>` : ''} <button class="btn danger small" data-act="delbackup" data-id="${b.id}">Löschen</button></td></tr>`).join('') || '<tr><td colspan="5" class="muted">Noch keine Backups.</td></tr>'}</table></div>`;
};

/* ---------- Dialoge & Aktionen ---------- */
const roleSel = (cur, list) => `<select name="role">${list.map(r => `<option ${r === cur ? 'selected' : ''}>${r}</option>`).join('')}</select>`;
const actions = {
  newuser: () => modal(`<h3>Benutzer erstellen</h3><form data-form="newuser"><div id="msg"></div><div class="field"><label>Benutzername</label><input type="text" name="username" required></div><div class="field"><label>Start-Passwort (mind. 8 Zeichen; Benutzer muss es ändern)</label><input type="text" name="password" required></div>
    <div class="field"><label>E-Mail (optional)</label><input type="email" name="email"></div><div class="field"><label>Rolle</label>${roleSel('user', S.assignable)}</div><div class="acts"><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Erstellen</button></div></form>`),
  edituser: t => { const u = S.users.find(x => x.id == t.dataset.id); const rl = [...new Set([u.role, ...S.assignable])];
    modal(`<h3>${esc(u.username)}</h3><form data-form="edituser" data-id="${u.id}"><div id="msg"></div><div class="field"><label>E-Mail</label><input type="email" name="email" value="${esc(u.email || '')}"></div><div class="field"><label>Rolle</label>${roleSel(u.role, rl)}</div>
    <div class="field"><label>Status</label><select name="status"><option value="active" ${u.status === 'active' ? 'selected' : ''}>aktiv</option><option value="blocked" ${u.status === 'blocked' ? 'selected' : ''}>gesperrt</option></select></div>
    <div class="acts"><button type="button" class="btn dark" data-act="resetpw" data-id="${u.id}">Passwort zurücksetzen</button><button type="button" class="btn danger" data-act="deluser" data-id="${u.id}">Löschen</button><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Speichern</button></div></form>`); },
  resetpw: wrap(async t => { const p = prompt('Neues Passwort (leer lassen = zufälliges Passwort erzeugen):', ''); if (p === null) return; const j = await api('POST', `/api/admin/users/${t.dataset.id}/reset-password`, { password: p });
    closeModal(); modal(`<h3>Passwort zurückgesetzt</h3><p>${j.password ? `Neues Passwort (nur jetzt sichtbar):<br><b style="font-size:1.3rem;user-select:all">${esc(j.password)}</b>` : 'Das Passwort wurde gesetzt.'}</p><p class="muted small">Der Benutzer muss es beim nächsten Login ändern.</p><div class="acts"><button class="btn red" data-act="close">OK</button></div>`); go(); }),
  deluser: wrap(async t => { if (!confirm('Benutzer inklusive aller Profile unwiderruflich löschen?')) return; await api('DELETE', `/api/admin/users/${t.dataset.id}`); closeModal(); toast('Gelöscht.'); go(); }),
  useract: wrap(async t => { const j = await api('GET', `/api/admin/users/${t.dataset.id}/activity`);
    modal(`<h3>Aktivität</h3><h4>Zuletzt angesehen</h4><table>${j.history.map(h => `<tr><td>${esc(h.title)}</td><td>${esc(h.profile)}</td><td>${ts(h.watched_at)}</td></tr>`).join('') || '<tr><td class="muted">–</td></tr>'}</table><h4>Ereignisse</h4><div class="tw"><table>${j.logs.map(l => `<tr><td>${ts(l.created_at)}</td><td>${esc(l.action)}</td><td>${esc(l.detail || '')}</td><td>${esc(l.ip || '')}</td></tr>`).join('')}</table></div><div class="acts"><button class="btn red" data-act="close">Schließen</button></div>`, true); }),
  close: closeModal,
  newprofile: wrap(async () => { const u = (await api('GET', '/api/admin/users')).users; modal(`<h3>Profil erstellen</h3><form data-form="newprofile"><div id="msg"></div><div class="field"><label>Konto</label><select name="user_id">${u.map(x => `<option value="${x.id}">${esc(x.username)}</option>`).join('')}</select></div><div class="field"><label>Name</label><input type="text" name="name" required maxlength="30"></div>
    <div class="field"><label>PIN (optional, 4 Ziffern)</label><input type="text" name="pin" maxlength="4" pattern="\\d{0,4}"></div><label class="check"><input type="checkbox" name="is_kids"> Kinderprofil</label><div class="acts"><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Erstellen</button></div></form>`); }),
  editprofile: t => { const p = S.aprofiles.find(x => x.id == t.dataset.id);
    modal(`<h3>${esc(p.username)} / ${esc(p.name)}</h3><form data-form="editprofile" data-id="${p.id}"><div id="msg"></div><div class="field"><label>Name</label><input type="text" name="name" value="${esc(p.name)}" required maxlength="30"></div>
    <div class="field"><label>Profilbild</label><select name="avatar">${['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8'].map(a => `<option ${a === p.avatar ? 'selected' : ''}>${a}</option>`).join('')}${p.avatar.startsWith('img:') ? `<option selected>${esc(p.avatar)}</option>` : ''}</select>
      <input type="file" id="avfile" accept="image/*"><span class="small muted">Eigenes Bild hochladen (ersetzt Auswahl)</span></div>
    <div class="field"><label>Neue PIN (4 Ziffern)</label><input type="text" name="pin" maxlength="4" pattern="\\d{0,4}" placeholder="${p.has_pin ? 'unverändert' : 'keine'}"></div><label class="check"><input type="checkbox" name="clearpin"> PIN entfernen</label><label class="check"><input type="checkbox" name="is_kids" ${p.is_kids ? 'checked' : ''}> Kinderprofil</label>
    <div class="acts"><button type="button" class="btn danger" data-act="delprofile" data-id="${p.id}">Löschen</button><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Speichern</button></div></form>`); },
  delprofile: wrap(async t => { if (!confirm('Profil unwiderruflich löschen?')) return; await api('DELETE', `/api/admin/profiles/${t.dataset.id}`); closeModal(); go(); }),
  mkind: t => { mediaKind = t.dataset.k; mediaPage = 1; go(); },
  mpage: t => { mediaPage += +t.dataset.d; go(); }, lpage: t => { logPage += +t.dataset.d; go(); },
  editmedia: wrap(async t => {
    const id = t.dataset.id;
    if (mediaKind === 'movie') { const d = await api('GET', `/api/admin/movies/${id}`);
      modal(`<h3>Film bearbeiten</h3><form data-form="editmovie" data-id="${id}"><div id="msg"></div>${mediaFields(d, false)}<div class="muted small">${d.files.map(f => esc(f.path)).join('<br>')}</div><div class="acts"><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Speichern</button></div></form>`, true); }
    else { const d = await api('GET', `/api/admin/series/${id}`);
      modal(`<h3>Serie bearbeiten</h3><form data-form="editseries" data-id="${id}"><div id="msg"></div>${mediaFields(d, true)}<div class="acts"><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Speichern</button></div></form>
        <h4>Staffeln &amp; Episoden</h4>${d.seasons.map(s => `<details><summary>${esc(s.title || 'Staffel ' + s.number)} (${s.episodes.length})</summary><div style="padding:.4rem 0">${s.episodes.map(e => `<div class="row2"><span class="muted">E${e.number}</span><span style="flex:1">${esc(e.title)}</span><button class="btn dark small" data-act="editep" data-id="${e.id}" data-title="${esc(e.title)}" data-ov="${esc(e.overview || '')}">Bearbeiten</button></div>`).join('')}
        <button class="btn dark small" data-act="editseason" data-id="${s.id}" data-title="${esc(s.title || '')}">Staffel umbenennen</button></div></details>`).join('')}`, true); } }),
  editep: wrap(async t => { const title = prompt('Episodentitel:', t.dataset.title); if (title === null) return; const ov = prompt('Beschreibung:', t.dataset.ov); if (ov === null) return; await api('PUT', `/api/admin/episodes/${t.dataset.id}`, { title, overview: ov }); toast('Gespeichert.'); }),
  editseason: wrap(async t => { const title = prompt('Staffelname:', t.dataset.title); if (title === null) return; await api('PUT', `/api/admin/seasons/${t.dataset.id}`, { title }); toast('Gespeichert.'); }),
  addfolder: () => folderForm(), editfolder: t => folderForm(S.folders.find(f => f.id == t.dataset.id)),
  browse: wrap(async t => { await browseTo(t.dataset.p); }), pickdir: t => { $('#modal input[name=path]').value = t.dataset.p; $('#browser').innerHTML = ''; },
  testfolder: wrap(async () => { const j = await api('POST', '/api/admin/folders/test', { path: $('#modal input[name=path]').value }); $('#ftest').innerHTML = `<div class="${j.inside_root && j.exists && j.readable ? 'stat' : 'err'}">${esc(j.message)}</div>`; }),
  delfolder: wrap(async t => { if (!confirm('Ordner aus STREAMFLIX entfernen? Die Dateien auf der Festplatte bleiben unverändert; Bibliothekseinträge und Verläufe dazu werden gelöscht.')) return; await api('DELETE', `/api/admin/folders/${t.dataset.id}`); closeModal(); go(); }),
  scanfolder: wrap(async t => { await api('POST', `/api/admin/folders/${t.dataset.id}/scan`); toast('Scan gestartet.'); setTimeout(go, 800); }),
  scanall: wrap(async () => { for (const f of S.folders.filter(x => x.enabled)) { await api('POST', `/api/admin/folders/${f.id}/scan`); for (let i = 0; i < 600; i++) { await new Promise(r => setTimeout(r, 1500)); const s = (await api('GET', '/api/admin/scans')).scans[0]; if (s.status !== 'running') break; } } toast('Alle Scans abgeschlossen.'); go(); }),
  scanerr: t => { const f = S.folders.find(x => x.id == t.dataset.id); modal(`<h3>Scan-Meldungen</h3><div class="tw"><table>${JSON.parse(f.last.errors || '[]').map(e => `<tr><td>${esc(e)}</td></tr>`).join('')}</table></div><div class="acts"><button class="btn red" data-act="close">Schließen</button></div>`, true); },
  killstream: wrap(async t => { if (!confirm('Stream beenden?')) return; await api('POST', `/api/admin/streams/${t.dataset.t}/kill`); toast('Stream beendet.'); go(); }),
  rmimg: wrap(async t => { await api('PUT', '/api/admin/settings', { [t.dataset.k]: '' }); go(); }),
  mkbackup: wrap(async () => { const j = await api('POST', '/api/admin/backups', {}); toast('Backup erstellt: ' + j.filename); go(); }),
  delbackup: wrap(async t => { if (!confirm('Backup löschen?')) return; await api('DELETE', `/api/admin/backups/${t.dataset.id}`); go(); }),
  restore: wrap(async t => { if (prompt('ACHTUNG: Alle aktuellen Daten werden durch das Backup ersetzt (vorher wird automatisch ein Sicherungs-Backup angelegt).\nZur Bestätigung WIEDERHERSTELLEN eingeben:') !== 'WIEDERHERSTELLEN') return;
    await api('POST', `/api/admin/backups/${t.dataset.id}/restore`, { confirm: 'WIEDERHERSTELLEN' }); toast('Wiederhergestellt. Bitte neu anmelden.'); setTimeout(() => location.href = '/', 1500); }),
};
function mediaFields(d, isSeries) {
  const i = (n, l, t = 'text') => `<div class="field"><label>${l}</label><input type="${t}" name="${n}" value="${esc(d[n] ?? '')}"></div>`;
  return `${i('title', 'Titel')}<div class="field"><label>Beschreibung</label><textarea name="overview">${esc(d.overview || '')}</textarea></div><div class="two">${i('year', 'Jahr', 'number')}${i('age_rating', 'Altersfreigabe (z. B. 12)')}${isSeries ? '' : i('runtime', 'Laufzeit (Min.)', 'number')}</div>
   ${i('genres', 'Genres (kommagetrennt)')}${isSeries ? '' : i('director', 'Regie')}${i('cast_list', 'Besetzung (kommagetrennt)')}
   ${isSeries ? `<div class="two">${i('intro_start', 'Intro Start (Sek.)', 'number')}${i('intro_end', 'Intro Ende (Sek.)', 'number')}${i('credits_offset', 'Abspann: Sekunden vor Ende', 'number')}</div>` : i('credits_offset', 'Abspann: Sekunden vor Ende (optional)', 'number')}
   <input type="hidden" name="poster" value="${esc(d.poster || '')}"><input type="hidden" name="backdrop" value="${esc(d.backdrop || '')}">
   <div class="two"><div class="field"><label>Poster ${d.poster ? `<img src="/img/${esc(d.poster)}" style="height:80px;margin-top:.3rem">` : ''}</label><input type="file" data-up="poster" accept="image/*"></div>
   <div class="field"><label>Hintergrundbild ${d.backdrop ? `<img src="/img/${esc(d.backdrop)}" style="height:80px;margin-top:.3rem">` : ''}</label><input type="file" data-up="backdrop" accept="image/*"></div></div>`;
}
function folderForm(f) {
  f = f || { path: '', label: '', type: 'movies', auto_scan: 1, enabled: 1 };
  modal(`<h3>${f.id ? 'Medienordner bearbeiten' : 'Medienordner hinzufügen'}</h3><form data-form="folder" data-id="${f.id || ''}"><div id="msg"></div>
   <div class="field"><label>Pfad im Container (beginnt mit /media/)</label><div class="row2" style="margin:0"><input type="text" name="path" value="${esc(f.path)}" style="flex:1" required><button type="button" class="btn dark small" data-act="browse" data-p="">Durchsuchen</button></div></div><div id="browser" class="dirs"></div>
   <div class="field"><label>Anzeigename</label><input type="text" name="label" value="${esc(f.label || '')}"></div>
   <div class="field"><label>Ordnertyp</label><select name="type"><option value="movies" ${f.type === 'movies' ? 'selected' : ''}>Filme (jede Datei = ein Film)</option><option value="series" ${f.type === 'series' ? 'selected' : ''}>Serien (Serie/Staffel/Episode)</option></select></div>
   <label class="check"><input type="checkbox" name="auto_scan" ${f.auto_scan ? 'checked' : ''}> Automatisch scannen</label>${f.id ? `<label class="check"><input type="checkbox" name="enabled" ${f.enabled ? 'checked' : ''}> Aktiv</label>` : ''}
   <div id="ftest"></div><div class="acts">${f.id ? `<button type="button" class="btn danger" data-act="delfolder" data-id="${f.id}">Entfernen</button>` : ''}<button type="button" class="btn dark" data-act="testfolder">Ordner testen</button><button type="button" class="btn dark" data-act="close">Abbrechen</button><button class="btn red">Speichern</button></div></form>`);
  if (!f.id) browseTo('');
}
async function browseTo(p) {
  const j = await api('GET', '/api/admin/browse' + (p ? '?path=' + encodeURIComponent(p) : ''));
  $('#browser').innerHTML = `<div class="panel" style="padding:.5rem"><div class="small muted">${esc(j.path)} <a data-act="pickdir" data-p="${esc(j.path)}" style="color:#fff;text-decoration:underline;cursor:pointer">← diesen Ordner wählen</a></div>
   ${j.parent ? `<a data-act="browse" data-p="${esc(j.parent)}">⬆ ..</a>` : ''}${j.dirs.map(d => `<a data-act="browse" data-p="${esc(j.path + '/' + d)}">📁 ${esc(d)}</a>`).join('') || '<span class="muted small">Keine Unterordner.</span>'}</div>`;
}

document.addEventListener('click', e => { const t = e.target.closest('[data-act]'); if (t && actions[t.dataset.act]) { e.preventDefault(); actions[t.dataset.act](t, e); } });
const fd = e => Object.fromEntries(new FormData(e.target));
async function upload(file, purpose) { const f = new FormData(); f.append('file', file); f.append('purpose', purpose); return (await api('POST', '/api/upload', f)).name; }
document.addEventListener('change', wrap(async e => {
  const t = e.target;
  if (t.dataset.up && t.files[0]) { const n = await upload(t.files[0], t.dataset.up); t.form.elements[t.dataset.up].value = n; toast('Bild hochgeladen – bitte speichern.'); }
  if (t.id === 'avfile' && t.files[0]) { const n = await upload(t.files[0], 'avatar'); const sel = t.form.elements.avatar; sel.insertAdjacentHTML('beforeend', `<option selected>img:${n}</option>`); toast('Bild hochgeladen – bitte speichern.'); }
}));
const submits = {
  newuser: async e => { await api('POST', '/api/admin/users', fd(e)); closeModal(); toast('Benutzer erstellt.'); go(); },
  edituser: async e => { await api('PUT', `/api/admin/users/${e.target.dataset.id}`, fd(e)); closeModal(); toast('Gespeichert.'); go(); },
  newprofile: async e => { const f = fd(e); await api('POST', '/api/admin/profiles', { user_id: +f.user_id, name: f.name, pin: f.pin, is_kids: !!f.is_kids }); closeModal(); go(); },
  editprofile: async e => { const f = fd(e); const b = { name: f.name, avatar: f.avatar, is_kids: !!f.is_kids }; if (f.clearpin) b.pin = ''; else if (f.pin) b.pin = f.pin; await api('PUT', `/api/admin/profiles/${e.target.dataset.id}`, b); closeModal(); go(); },
  mediasearch: e => { mediaQ = fd(e).q; mediaPage = 1; go(); },
  logfilter: e => { const f = fd(e); logQ = f.q; logLevel = f.level; logPage = 1; go(); },
  editmovie: async e => { await api('PUT', `/api/admin/movies/${e.target.dataset.id}`, fd(e)); closeModal(); toast('Gespeichert.'); go(); },
  editseries: async e => { await api('PUT', `/api/admin/series/${e.target.dataset.id}`, fd(e)); closeModal(); toast('Gespeichert.'); go(); },
  folder: async e => { const f = fd(e); const id = e.target.dataset.id; const b = { path: f.path, label: f.label, type: f.type, auto_scan: f.auto_scan ? 1 : 0 }; if (id) b.enabled = f.enabled ? 1 : 0;
    const r = id ? await api('PUT', `/api/admin/folders/${id}`, b) : await api('POST', '/api/admin/folders', b); closeModal();
    if (!id || r.rescan_recommended) { if (confirm('Jetzt einen Scan für diesen Ordner starten?')) await api('POST', `/api/admin/folders/${id || r.id}/scan`); } go(); },
  settings: async e => {
    const b = {}; for (const [k, , t] of SETS) { const el = e.target.elements[k]; b[k] = t === 'bool' ? el.checked : el.value; }
    if ($('#logofile').files[0]) b.logo = await upload($('#logofile').files[0], 'logo');
    if ($('#bgfile').files[0]) b.login_bg = await upload($('#bgfile').files[0], 'bg');
    await api('PUT', '/api/admin/settings', b); toast('Einstellungen gespeichert.'); go();
  },
};
document.addEventListener('submit', async e => {
  const k = e.target.dataset.form; if (!k || !submits[k]) return; e.preventDefault();
  try { await submits[k](e); } catch (err) { const m = $('#msg', e.target.closest('.m') || e.target); if (m) m.innerHTML = `<div class="err">${esc(err.message)}</div>`; else toast(err.message, true); }
});
init();
