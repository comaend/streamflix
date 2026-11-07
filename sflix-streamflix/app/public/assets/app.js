// STREAMFLIX – Oberfläche (Vanilla JS, ES Modules)
import { createPlayer } from './player.js';

const $ = (s, r = document) => r.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const root = $('#app');
const S = { csrf: '', user: null, profile: null, settings: {}, mode: 'login', manage: false };
let player = null;

export async function api(method, path, body) {
  const opt = { method, headers: { 'X-CSRF-Token': S.csrf }, credentials: 'same-origin' };
  if (body instanceof FormData) opt.body = body;
  else if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  const r = await fetch(path, opt);
  let j = {}; try { j = await r.json(); } catch (e) { /* leer */ }
  if (!r.ok) {
    if (r.status === 401 && S.user) { S.user = null; S.profile = null; await boot(); }
    const err = new Error(j.error || 'Fehler ' + r.status); err.status = r.status; throw err;
  }
  return j;
}
const toast = (m, bad) => { const t = document.createElement('div'); t.className = 'toast' + (bad ? ' bad' : ''); t.textContent = m; document.body.appendChild(t); setTimeout(() => t.remove(), 3200); };
const wrap = fn => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, true); } };

function avatar(p, big) {
  const cls = 'av' + (big ? ' big' : '');
  if (p.avatar && p.avatar.startsWith('img:')) return `<img class="${cls}" src="/img/${esc(p.avatar.slice(4))}" alt="">`;
  return `<div class="${cls} av-${esc(p.avatar || 'a1')}">${esc((p.name || '?')[0].toUpperCase())}</div>`;
}
const AVATARS = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8'];

/* ---------- Boot ---------- */
async function boot() {
  const j = await fetch('/api/session', { credentials: 'same-origin' }).then(r => r.json());
  S.csrf = j.csrf; S.user = j.user; S.profile = j.profile; S.settings = j.settings;
  document.title = S.settings.platform_name;
  route();
}

/* ---------- Router ---------- */
window.addEventListener('hashchange', route);
async function route() {
  if (player) { player.destroy(); player = null; }
  document.body.style.overflow = '';
  if (!S.user) return renderAuth();
  if (S.user.must_change_password && !location.hash.startsWith('#/settings')) { location.hash = '#/settings'; return; }
  const h = location.hash.replace(/^#\/?/, ''); const [path, qs] = h.split('?'); const parts = path.split('/'); const params = new URLSearchParams(qs || '');
  if (parts[0] === 'profiles' || !S.profile) return renderProfiles();
  try {
    if (parts[0] === 'watch') return renderWatch(parts[1], +parts[2]);
    shell();
    const main = $('#main');
    switch (parts[0]) {
      case '': case 'home': return await viewHome(main);
      case 'movies': return await viewBrowse(main, 'movie');
      case 'series': return await viewBrowse(main, 'series');
      case 'mylist': return await viewMyList(main);
      case 'search': return await viewSearch(main, params);
      case 't': return await viewDetail(main, parts[1], +parts[2]);
      case 'settings': return await viewSettings(main);
      default: main.innerHTML = '<div class="empty">Seite nicht gefunden.</div>';
    }
  } catch (e) { if (e.status !== 401) $('#main') ? $('#main').innerHTML = `<div class="empty">⚠️ ${esc(e.message)}</div>` : toast(e.message, true); }
}

/* ---------- Login / Registrierung ---------- */
function renderAuth() {
  const reg = S.mode === 'register' || S.settings.needs_setup;
  const bg = S.settings.login_bg ? `style="--bgimg:url('${esc(S.settings.login_bg)}')"` : '';
  const canReg = S.settings.registration_enabled || S.settings.needs_setup;
  root.innerHTML = `<div class="auth" ${bg}><form class="box" data-form="${reg ? 'register' : 'login'}">
    <div class="brand" style="font-size:1.6rem;margin-bottom:1.5rem">${esc(S.settings.platform_name)}</div>
    <h1>${S.settings.needs_setup ? 'Administrator-Konto erstellen' : reg ? 'Registrieren' : 'Anmelden'}</h1>
    ${S.settings.maintenance ? '<div class="err">Wartungsmodus: Nur Administratoren können sich anmelden.</div>' : ''}
    ${S.settings.needs_setup ? '<p class="muted small">Das erste Konto wird automatisch Superadmin.</p>' : ''}
    <div id="msg"></div>
    <div class="field"><input type="text" name="username" placeholder="Benutzername" autocomplete="username" required></div>
    <div class="field"><input type="password" name="password" placeholder="Passwort${reg ? ' (mind. 8 Zeichen)' : ''}" autocomplete="${reg ? 'new-password' : 'current-password'}" required></div>
    <button class="btn red" style="width:100%;justify-content:center">${reg ? 'Konto erstellen' : 'Anmelden'}</button>
    ${canReg && !S.settings.needs_setup ? `<div class="switch">${reg ? 'Bereits ein Konto? <a data-act="mode" data-m="login">Anmelden</a>' : 'Neu hier? <a data-act="mode" data-m="register">Jetzt registrieren</a>'}</div>` : ''}
  </form></div>`;
}

/* ---------- Profile ---------- */
async function renderProfiles() {
  const j = await api('GET', '/api/profiles');
  const items = j.profiles.map(p => `<button class="pbtn" data-act="${S.manage ? 'editprofile' : 'pickprofile'}" data-id="${p.id}" data-pin="${p.has_pin ? 1 : 0}">
      <div style="position:relative">${avatar(p, true)}${p.has_pin ? '<span class="lock">🔒</span>' : ''}${S.manage ? '<div class="edit" style="--h:100%">✎</div>' : ''}</div><span>${esc(p.name)}${p.is_kids ? ' 🧒' : ''}</span></button>`).join('');
  const add = j.profiles.length < 6 ? `<button class="pbtn" data-act="addprofile"><div class="av big" style="background:#333;font-size:4rem">+</div><span>Profil hinzufügen</span></button>` : '';
  root.innerHTML = `<div class="whois"><div class="brand" style="font-size:2rem">${esc(S.settings.platform_name)}</div><h1>${S.manage ? 'Profile verwalten' : 'Wer schaut?'}</h1>
    <div class="profiles">${items}${S.manage ? add : ''}</div>
    <div><button class="btn grey" data-act="togglemanage">${S.manage ? 'Fertig' : 'Profile verwalten'}</button> <button class="btn dark" data-act="logout">Abmelden</button></div></div>`;
  S.profilesCache = j.profiles;
}

function modal(html, wide) {
  closeModal();
  const m = document.createElement('div'); m.className = 'modal'; m.id = 'modal';
  m.innerHTML = `<div class="m ${wide ? 'wide' : ''}">${html}</div>`;
  m.addEventListener('mousedown', e => { if (e.target === m) closeModal(); });
  document.body.appendChild(m); const f = m.querySelector('input'); if (f) f.focus();
  return m;
}
const closeModal = () => $('#modal')?.remove();

function profileForm(p) {
  p = p || { name: '', avatar: 'a1', is_kids: false, has_pin: false };
  const m = modal(`<h3>${p.id ? 'Profil bearbeiten' : 'Neues Profil'}</h3><form data-form="saveprofile" data-id="${p.id || ''}">
    <div class="avpick">${AVATARS.map(a => `<button type="button" data-act="pickavatar" data-a="${a}" class="${p.avatar === a ? 'on' : ''}">${avatar({ avatar: a, name: p.name || 'A' })}</button>`).join('')}
      <button type="button" data-act="uploadavatar" title="Eigenes Bild hochladen"><div class="av" style="background:#333">⬆</div></button></div>
    <input type="hidden" name="avatar" value="${esc(p.avatar)}">
    <div class="field"><label>Name</label><input type="text" name="name" maxlength="30" value="${esc(p.name)}" required></div>
    <label class="check"><input type="checkbox" name="is_kids" ${p.is_kids ? 'checked' : ''}> Kinderprofil (nur Titel mit Freigabe ab 0/6/12)</label>
    ${p.has_pin ? '<div class="field"><label>Aktuelle PIN (nötig zum Ändern)</label><input type="password" inputmode="numeric" maxlength="4" name="current_pin"></div>' : ''}
    <div class="field"><label>${p.has_pin ? 'Neue PIN (4 Ziffern, leer = PIN entfernen)' : 'PIN-Schutz (optional, 4 Ziffern)'}</label><input type="password" inputmode="numeric" maxlength="4" pattern="\\d{0,4}" name="pin" ${p.has_pin ? 'data-changed="0"' : ''}></div>
    <div id="msg"></div>
    <div class="acts">${p.id ? '<button type="button" class="btn danger" data-act="delprofile" data-id="' + p.id + '">Löschen</button>' : ''}<button type="button" class="btn dark" data-act="closemodal">Abbrechen</button><button class="btn red">Speichern</button></div></form>`);
  return m;
}

/* ---------- Shell ---------- */
function shell() {
  if ($('#main')) { updateNav(); return; }
  const adm = S.user.role !== 'user';
  root.innerHTML = `<nav class="nav" id="nav"><a href="#/" class="brand">${S.settings.logo ? `<img src="${esc(S.settings.logo)}" alt="" style="height:28px">` : esc(S.settings.platform_name)}</a>
    <a class="link" data-r="" href="#/">Startseite</a><a class="link" data-r="series" href="#/series">Serien</a><a class="link" data-r="movies" href="#/movies">Filme</a><a class="link opt" data-r="mylist" href="#/mylist">Meine Liste</a>
    <span class="sp"></span>
    <form class="search" id="sform" data-form="search"><input type="search" name="q" placeholder="Titel, Schauspieler, Genre …" autocomplete="off"><button class="icon" type="button" data-act="searchtoggle" aria-label="Suche">🔍</button></form>
    <div class="menu" tabindex="0">${avatar(S.profile)}<div class="dd">
      <a href="#/profiles" data-act="switchprofile">Profil wechseln</a><a href="#/settings">Einstellungen</a>${adm ? '<a href="/admin/">Admin-Center</a>' : ''}<button data-act="logout">Abmelden</button></div></div></nav>
    <main id="main" class="page"></main>`;
  window.addEventListener('scroll', () => $('#nav')?.classList.toggle('solid', scrollY > 30), { passive: true });
  updateNav();
}
function updateNav() {
  const r = location.hash.replace(/^#\/?/, '').split(/[/?]/)[0];
  document.querySelectorAll('.nav a.link').forEach(a => a.classList.toggle('on', a.dataset.r === r || (r === 'home' && a.dataset.r === '')));
}

/* ---------- Bausteine ---------- */
function cardHtml(c) {
  const img = c.backdrop && c.kind === 'episode' ? c.backdrop : (c.poster || c.backdrop);
  const href = c.kind === 'episode' ? `#/watch/episode/${c.id}` : `#/t/${c.kind}/${c.id}`;
  const wide = c.kind === 'episode' ? ' wide' : '';
  const bar = c.percent ? `<div class="bar"><i style="width:${Math.min(100, c.percent)}%"></i></div>` : '';
  return `<a class="card${wide}" href="${href}" title="${esc(c.title)}"><div class="poster">${img ? `<img loading="lazy" src="${esc(img)}" alt="">` : ''}<div class="ph" ${img ? 'style="display:none"' : ''}>${esc(c.title)}</div></div>
    <div class="info"><div class="t">${esc(c.subtitle || c.title)}</div><div class="muted">${esc(c.year || '')} ${c.age ? '· ' + esc(c.age) : ''}</div></div>${bar}</a>`;
}
function rowHtml(r) {
  return `<section class="row"><h2>${esc(r.title)}</h2><div class="rowwrap"><button class="arrow l" data-act="scroll" data-d="-1" aria-label="Zurück">‹</button><div class="strip">${r.items.map(cardHtml).join('')}</div><button class="arrow r" data-act="scroll" data-d="1" aria-label="Weiter">›</button></div></section>`;
}
document.addEventListener('error', e => { const t = e.target; if (t.tagName === 'IMG' && t.closest('.poster')) { const ph = t.parentElement?.querySelector('.ph'); t.remove(); if (ph) ph.style.display = ''; } }, true);

/* ---------- Ansichten ---------- */
async function viewHome(main) {
  const h = await api('GET', '/api/home');
  if (!h.rows.length && !h.hero) { main.innerHTML = `<div class="empty"><h2>Noch keine Medien</h2><p>${S.user.role !== 'user' ? 'Füge im <a href="/admin/" style="text-decoration:underline">Admin-Center</a> einen Medienordner hinzu und starte einen Scan.' : 'Der Administrator hat noch keine Medien eingerichtet.'}</p></div>`; return; }
  const hero = h.hero ? `<div class="hero" style="--bg:url('${esc(h.hero.backdrop)}')"><div class="in"><h1>${esc(h.hero.title)}</h1><p>${esc(h.hero.overview || '')}</p>
    <div class="btns">${h.hero.kind === 'movie' ? `<a class="btn" href="#/watch/movie/${h.hero.id}">▶ Abspielen</a>` : ''}<a class="btn grey" href="#/t/${h.hero.kind}/${h.hero.id}">ⓘ Mehr Infos</a></div></div></div>` : '<div style="height:70px"></div>';
  main.classList.remove('pad'); main.style.paddingTop = h.hero ? '0' : '';
  main.innerHTML = hero + `<div style="margin-top:-6vh;position:relative;z-index:3">${h.rows.map(rowHtml).join('')}</div>`;
}

const FILTERS = { sort: 'added', genre: '', year: '', rating: '', runtime_max: '' };
async function viewBrowse(main, kind) {
  main.style.paddingTop = ''; main.className = 'page pad';
  const g = (await api('GET', '/api/genres')).genres;
  main.innerHTML = `<h1 class="pagetitle">${kind === 'movie' ? 'Filme' : 'Serien'}</h1>${filterBar(g, kind === 'movie')}<div class="grid" id="grid"></div><div style="text-align:center;margin-top:1.5rem"><button class="btn dark hidden" id="more" data-act="more">Mehr laden</button></div>`;
  main.dataset.kind = kind; await loadGrid(true);
}
function filterBar(genres, runtime) {
  return `<form class="filters" data-form="filter"><select name="sort"><option value="added">Zuletzt hinzugefügt</option><option value="title">Titel A–Z</option><option value="year">Erscheinungsjahr</option></select>
    <select name="genre"><option value="">Alle Genres</option>${genres.map(x => `<option>${esc(x)}</option>`).join('')}</select>
    <input type="number" name="year" placeholder="Jahr" min="1900" max="2100">
    <select name="rating"><option value="">Meine Bewertung</option>${[5, 4, 3, 2].map(n => `<option value="${n}">ab ${n} ★</option>`).join('')}</select>
    ${runtime ? '<input type="number" name="runtime_max" placeholder="max. Minuten" min="1">' : ''}</form>`;
}
let page = 1, searchMode = null;
async function loadGrid(reset) {
  const main = $('#main'); const grid = $('#grid'); if (!grid) return;
  if (reset) { page = 1; grid.innerHTML = ''; }
  const f = $('form[data-form=filter]'); const p = new URLSearchParams(f ? new FormData(f) : []); [...p.keys()].forEach(k => { if (!p.get(k)) p.delete(k); });
  if (searchMode?.q) p.set('q', searchMode.q);
  p.set('page', page);
  const type = searchMode ? (searchMode.type || 'all') : main.dataset.kind;
  let j;
  if (type === 'all') j = await api('GET', '/api/search?' + p); else j = await api('GET', (type === 'movie' ? '/api/movies?' : '/api/series?') + p);
  grid.insertAdjacentHTML('beforeend', j.items.map(cardHtml).join(''));
  if (reset && !j.items.length) grid.innerHTML = '<div class="empty" style="grid-column:1/-1">Keine Titel gefunden.</div>';
  const loaded = grid.querySelectorAll('.card').length;
  $('#more')?.classList.toggle('hidden', !(type !== 'all' && loaded < j.total));
}
async function viewMyList(main) {
  main.className = 'page pad'; main.style.paddingTop = '';
  const j = await api('GET', '/api/mylist');
  main.innerHTML = `<h1 class="pagetitle">Meine Liste</h1>` + (j.items.length ? `<div class="grid">${j.items.map(cardHtml).join('')}</div>` : '<div class="empty">Deine Liste ist leer. Füge Titel über „Meine Liste“ auf der Detailseite hinzu.</div>');
}
async function viewSearch(main, params) {
  main.className = 'page pad'; main.style.paddingTop = '';
  const g = (await api('GET', '/api/genres')).genres;
  searchMode = { q: params.get('q') || '', type: params.get('type') || 'all' };
  main.innerHTML = `<h1 class="pagetitle">Suche</h1><form class="filters" data-form="searchpage"><input type="search" name="sq" placeholder="Titel, Schauspieler, Regisseur, Genre, Jahr" value="${esc(searchMode.q)}" style="min-width:280px">
    <select name="type"><option value="all">Filme &amp; Serien</option><option value="movie">Nur Filme</option><option value="series">Nur Serien</option></select><button class="btn red small">Suchen</button></form>
    ${filterBar(g, true)}<div class="grid" id="grid"></div><div style="text-align:center;margin-top:1.5rem"><button class="btn dark hidden" id="more" data-act="more">Mehr laden</button></div>`;
  $('select[name=type]').value = searchMode.type; main.dataset.kind = 'all';
  if (searchMode.q) await loadGrid(true); else $('#grid').innerHTML = '<div class="empty" style="grid-column:1/-1">Gib einen Suchbegriff ein.</div>';
}

const stars = n => `<span class="stars">${[1, 2, 3, 4, 5].map(i => `<button data-act="rate" data-v="${i}" class="${i <= n ? 'on' : ''}" aria-label="${i} Sterne">★</button>`).join('')}</span>`;
async function viewDetail(main, kind, id) {
  main.className = 'page'; main.style.paddingTop = '0';
  const d = await api('GET', `/api/item/${kind}/${id}`);
  S.detail = d;
  const meta = [d.year, d.age && `<span class="badge">${esc(d.age)}</span>`, d.runtime && d.runtime + ' Min.', d.file?.resolution, d.file?.vcodec && d.file.vcodec.toUpperCase(), kind === 'series' && d.seasons.length + ' Staffel(n)'].filter(Boolean).map(x => `<span>${typeof x === 'number' ? x : x}</span>`).join('');
  const prog = d.progress && !d.progress.completed && d.progress.position > 10;
  const playHref = kind === 'movie' ? `#/watch/movie/${id}` : d.resume_episode ? `#/watch/episode/${d.resume_episode}` : '#';
  main.innerHTML = `<div class="detail" style="--bg:url('${esc(d.backdrop || d.poster || '')}')"><div class="in">
    <h1>${esc(d.title)}</h1><div class="tags">${meta}</div>
    <div class="btns" style="display:flex;gap:.7rem;flex-wrap:wrap;margin:1rem 0"><a class="btn" href="${playHref}">▶ ${prog ? 'Fortsetzen' : 'Abspielen'}</a>
      <button class="btn grey" data-act="mylist">${d.in_list ? '✓ In Meiner Liste' : '＋ Meine Liste'}</button></div>
    <div style="margin-bottom:.8rem" id="stars" title="Deine Bewertung">${stars(d.my_rating)}</div>
    <p>${esc(d.overview || 'Keine Beschreibung vorhanden.')}</p>
    <p class="muted small">${d.genres ? 'Genres: ' + esc(d.genres) + '<br>' : ''}${d.cast ? 'Besetzung: ' + esc(d.cast) + '<br>' : ''}${d.director ? 'Regie: ' + esc(d.director) : ''}</p></div></div>`;
  if (kind === 'series') {
    const cur = d.seasons.find(s => s.episodes.some(e => e.id === d.resume_episode))?.number ?? d.seasons[0]?.number;
    main.insertAdjacentHTML('beforeend', `<div class="episodes"><div class="row2"><h2 style="margin:0">Episoden</h2><select id="seasel" data-act-change="season">${d.seasons.map(s => `<option value="${s.number}" ${s.number === cur ? 'selected' : ''}>${esc(s.title || 'Staffel ' + s.number)}</option>`).join('')}</select></div><div id="eplist"></div></div>`);
    renderEpisodes(cur);
  }
}
function renderEpisodes(num) {
  const s = S.detail.seasons.find(x => x.number == num); if (!s) return;
  $('#eplist').innerHTML = s.episodes.map(e => `<a class="ep" href="#/watch/episode/${e.id}"><div class="n">${e.number}</div><div class="st">${e.still ? `<img loading="lazy" src="${esc(e.still)}" alt="">` : ''}${e.percent ? `<div class="bar"><i style="width:${e.percent}%"></i></div>` : ''}</div>
    <div style="flex:1"><h4>${esc(e.title)} ${e.completed ? '<span class="green">✓</span>' : ''}</h4><p>${esc(e.overview || '')}</p></div><span class="muted small">${e.runtime ? e.runtime + ' Min.' : ''}</span></a>`).join('');
}

async function viewSettings(main) {
  main.className = 'page pad'; main.style.paddingTop = '';
  const [s, h] = await Promise.all([api('GET', '/api/settings'), api('GET', '/api/history')]);
  const langs = [['', 'Automatisch'], ['de', 'Deutsch'], ['en', 'English'], ['fr', 'Français'], ['es', 'Español'], ['it', 'Italiano'], ['ja', '日本語'], ['tr', 'Türkçe']];
  const opts = v => langs.map(([k, n]) => `<option value="${k}" ${k === v ? 'selected' : ''}>${n}</option>`).join('');
  main.innerHTML = `<div class="settings"><h1 class="pagetitle">Einstellungen</h1>
   ${S.user.must_change_password ? '<div class="err">Bitte lege ein neues Passwort fest, bevor du fortfährst.</div>' : ''}
   <section><h2>Profil „${esc(S.profile.name)}“: Wiedergabe</h2><form data-form="psettings">
     <div class="field"><label>Bevorzugte Audiosprache</label><select name="audio_lang">${opts(s.audio_lang)}</select></div>
     <div class="field"><label>Bevorzugte Untertitelsprache</label><select name="subtitle_lang">${opts(s.subtitle_lang)}</select></div>
     <label class="check"><input type="checkbox" name="subtitles_on" ${s.subtitles_on ? 'checked' : ''}> Untertitel standardmäßig einschalten</label>
     <label class="check"><input type="checkbox" name="autoplay_next" ${s.autoplay_next ? 'checked' : ''}> Nächste Episode automatisch abspielen</label>
     <div class="field"><label>Sprache der Oberfläche</label><select name="language"><option value="de" ${s.language === 'de' ? 'selected' : ''}>Deutsch</option><option value="en" ${s.language === 'en' ? 'selected' : ''}>English (vorbereitet)</option></select></div>
     <button class="btn red">Speichern</button></form></section>
   <section><h2>Passwort ändern</h2><form data-form="password"><div id="msg"></div>
     <div class="field"><label>Aktuelles Passwort</label><input type="password" name="current" autocomplete="current-password" required></div>
     <div class="field"><label>Neues Passwort (mind. 8 Zeichen)</label><input type="password" name="new" autocomplete="new-password" required></div><button class="btn red">Passwort ändern</button></form></section>
   <section><h2>Wiedergabeverlauf</h2>${h.items.length ? `<ul class="hist">${h.items.slice(0, 30).map(i => `<li><span>${esc(i.title)}</span><span class="muted small">${esc(i.watched_at)}</span></li>`).join('')}</ul><p><button class="btn dark small" data-act="clearhistory">Verlauf &amp; Fortschritt löschen</button></p>` : '<p class="muted">Noch nichts angesehen.</p>'}</section></div>`;
}

function renderWatch(kind, id) {
  document.body.style.overflow = 'hidden';
  const back = () => { if (history.length > 1) history.back(); else location.hash = '#/'; };
  root.innerHTML = '<div id="pr"></div>';
  player = createPlayer($('#pr'), { api, kind, id, csrf: () => S.csrf, onExit: back, onNavigate: epId => { location.replace('#/watch/episode/' + epId); } });
}

/* ---------- Aktionen ---------- */
const form = e => Object.fromEntries(new FormData(e.target));
const actions = {
  mode: t => { S.mode = t.dataset.m; renderAuth(); },
  scroll: t => { const s = t.parentElement.querySelector('.strip'); s.scrollBy({ left: +t.dataset.d * s.clientWidth * .85 }); },
  searchtoggle: t => { const f = t.closest('.search'); f.classList.toggle('open'); if (f.classList.contains('open')) f.querySelector('input').focus(); },
  logout: wrap(async () => { await api('POST', '/api/logout'); S.user = null; S.profile = null; S.manage = false; await boot(); }),
  togglemanage: () => { S.manage = !S.manage; renderProfiles(); },
  switchprofile: () => { S.manage = false; },
  pickprofile: wrap(async t => {
    const pin = t.dataset.pin === '1' ? await askPin() : '';
    if (pin === null) return;
    const j = await api('POST', `/api/profiles/${t.dataset.id}/select`, { pin });
    S.profile = j.profile; location.hash = '#/'; route();
  }),
  addprofile: () => profileForm(),
  editprofile: t => profileForm(S.profilesCache.find(p => p.id == t.dataset.id)),
  pickavatar: t => { document.querySelectorAll('.avpick button').forEach(b => b.classList.remove('on')); t.classList.add('on'); $('input[name=avatar]').value = t.dataset.a; },
  uploadavatar: () => { const i = document.createElement('input'); i.type = 'file'; i.accept = 'image/jpeg,image/png,image/webp'; i.onchange = wrap(async () => {
    const fd = new FormData(); fd.append('file', i.files[0]); fd.append('purpose', 'avatar'); const r = await api('POST', '/api/upload', fd);
    $('input[name=avatar]').value = 'img:' + r.name; document.querySelectorAll('.avpick button').forEach(b => b.classList.remove('on')); toast('Bild hochgeladen – bitte speichern.'); }); i.click(); },
  delprofile: wrap(async t => { if (!confirm('Profil samt Verlauf und Liste wirklich löschen?')) return; const pin = S.profilesCache.find(p => p.id == t.dataset.id).has_pin ? await askPin('PIN zum Löschen') : ''; if (pin === null) return;
    await api('DELETE', `/api/profiles/${t.dataset.id}`, { pin }); closeModal(); renderProfiles(); }),
  closemodal: closeModal,
  mylist: wrap(async () => { const d = S.detail; d.in_list = !d.in_list; await api('POST', '/api/mylist', { kind: d.kind, item_id: d.id, on: d.in_list }); $('[data-act=mylist]').textContent = d.in_list ? '✓ In Meiner Liste' : '＋ Meine Liste'; }),
  rate: wrap(async t => { const d = S.detail; const v = +t.dataset.v === d.my_rating ? 0 : +t.dataset.v; await api('POST', '/api/rating', { kind: d.kind, item_id: d.id, rating: v }); d.my_rating = v; $('#stars').innerHTML = stars(v); }),
  more: wrap(async () => { page++; await loadGrid(false); }),
  clearhistory: wrap(async () => { if (!confirm('Verlauf und alle Wiedergabepositionen dieses Profils löschen?')) return; await api('POST', '/api/history/clear'); toast('Verlauf gelöscht.'); route(); }),
};
function askPin(title = 'PIN eingeben') {
  return new Promise(res => {
    const m = modal(`<h3>${esc(title)}</h3><form id="pinf"><div class="field"><input type="password" inputmode="numeric" maxlength="4" pattern="\\d{4}" id="pinin" autocomplete="off" required style="text-align:center;font-size:1.6rem;letter-spacing:.5em"></div>
      <div class="acts"><button type="button" class="btn dark" id="pinc">Abbrechen</button><button class="btn red">OK</button></div></form>`);
    m.querySelector('#pinf').onsubmit = e => { e.preventDefault(); const v = m.querySelector('#pinin').value; m.remove(); res(v); };
    m.querySelector('#pinc').onclick = () => { m.remove(); res(null); };
  });
}
const submits = {
  login: async e => { const j = await api('POST', '/api/login', form(e)); S.csrf = j.csrf; await boot(); },
  register: async e => { const j = await api('POST', '/api/register', form(e)); S.csrf = j.csrf; await boot(); },
  search: e => { const q = form(e).q.trim(); if (q) location.hash = '#/search?q=' + encodeURIComponent(q); },
  searchpage: e => { const f = form(e); location.hash = `#/search?q=${encodeURIComponent(f.sq)}&type=${f.type}`; },
  filter: async () => loadGrid(true),
  saveprofile: async e => {
    const f = form(e); const id = e.target.dataset.id; const body = { name: f.name, avatar: f.avatar, is_kids: !!f.is_kids };
    if (f.pin !== '' || e.target.pin.dataset.touched) { body.pin = f.pin; body.current_pin = f.current_pin || ''; }
    if (id) await api('PUT', `/api/profiles/${id}`, body); else await api('POST', '/api/profiles', body);
    closeModal(); renderProfiles();
  },
  psettings: async e => { const f = form(e); await api('PUT', '/api/settings', { audio_lang: f.audio_lang, subtitle_lang: f.subtitle_lang, subtitles_on: !!f.subtitles_on, autoplay_next: !!f.autoplay_next, language: f.language }); toast('Gespeichert.'); },
  password: async e => { await api('POST', '/api/change-password', form(e)); S.user.must_change_password = false; toast('Passwort geändert.'); e.target.reset(); },
};
document.addEventListener('input', e => { if (e.target.name === 'pin') e.target.dataset.touched = '1'; });
document.addEventListener('click', e => { const t = e.target.closest('[data-act]'); if (t && actions[t.dataset.act]) { if (t.tagName === 'A' && t.dataset.act !== 'switchprofile') e.preventDefault(); actions[t.dataset.act](t, e); } });
document.addEventListener('change', e => {
  if (e.target.id === 'seasel') renderEpisodes(e.target.value);
  if (e.target.closest('form.filters[data-form=filter]')) loadGrid(true);
});
document.addEventListener('submit', async e => {
  const k = e.target.dataset.form; if (!k || !submits[k]) return;
  e.preventDefault();
  try { await submits[k](e); } catch (err) { const m = $('#msg', e.target.closest('.m, .box, section') || document); if (m) m.innerHTML = `<div class="err">${esc(err.message)}</div>`; else toast(err.message, true); }
});

boot();
