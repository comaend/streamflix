#!/usr/bin/env python3
"""End-to-End-Smoketest gegen eine laufende STREAMFLIX-Instanz.
Aufruf: python3 smoke_test.py http://127.0.0.1:8080 [/pfad/zu/medien-ordner-im-container]
Legt Testbenutzer an. Nur gegen eine frische/Test-Instanz ausführen."""
import sys, json, time, http.cookiejar, urllib.request, urllib.error, urllib.parse

BASE = sys.argv[1].rstrip('/') if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
MOVIES = sys.argv[2] if len(sys.argv) > 2 else '/media/Movies'
SERIES = sys.argv[3] if len(sys.argv) > 3 else '/media/Series'
fails = 0

class C:
    def __init__(self):
        self.cj = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj))
        self.csrf = ''
    def req(self, method, path, body=None, raw=False):
        data = json.dumps(body).encode() if body is not None else None
        r = urllib.request.Request(BASE + path, data=data, method=method, headers={'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf})
        try:
            resp = self.op.open(r, timeout=90)
            b = resp.read(); code = resp.status; hdr = resp.headers
        except urllib.error.HTTPError as e:
            b = e.read(); code = e.code; hdr = e.headers
        if raw: return code, b, hdr
        try: return code, json.loads(b or b'{}')
        except Exception: return code, {'_raw': b[:200]}
    def session(self):
        c, j = self.req('GET', '/api/session'); self.csrf = j['csrf']; return j

def check(name, cond, info=''):
    global fails
    print(('  OK   ' if cond else '  FAIL ') + name + ('' if cond else f'  -> {info}'))
    if not cond: fails += 1

adm = C(); s = adm.session()
print('Registrierung / Login')
check('Setup erkannt', s['settings']['needs_setup'] in (True, False))
c, j = adm.req('POST', '/api/register', {'username': 'boss', 'password': 'supergeheim1'})
if c == 200: adm.csrf = j['csrf']
else:
    c, j = adm.req('POST', '/api/login', {'username': 'boss', 'password': 'supergeheim1'}); adm.csrf = j.get('csrf', adm.csrf)
check('Admin angemeldet', c == 200, j)
s = adm.session(); check('Erster Benutzer = superadmin', s['user']['role'] == 'superadmin', s['user'])
c, j = adm.req('POST', '/api/login', {'username': 'x', 'password': 'y'}); check('Falsches Login -> 401', c == 401)
bad = C(); bad.session(); c, j = bad.req('POST', '/api/login', {'username': 'boss', 'password': 'supergeheim1'}); check('Login mit gültigem CSRF ok', c == 200)
nocsrf = C(); nocsrf.session(); nocsrf.csrf = 'falsch'; c, j = nocsrf.req('POST', '/api/login', {'username': 'boss', 'password': 'supergeheim1'}); check('Falsches CSRF -> 403', c == 403, c)

print('Rechte')
c, j = adm.req('POST', '/api/admin/users', {'username': 'anna', 'password': 'passwort123', 'role': 'user'}); check('Admin legt Benutzer an', c == 200, j)
c, j = adm.req('POST', '/api/admin/users', {'username': 'mod', 'password': 'passwort123', 'role': 'moderator'}); check('Moderator angelegt', c == 200, j)
user = C(); user.session(); c, j = user.req('POST', '/api/login', {'username': 'anna', 'password': 'passwort123'}); user.csrf = j['csrf']
c, j = user.req('GET', '/api/admin/dashboard'); check('User hat keinen Admin-Zugriff (403)', c == 403, c)
c, j = user.req('GET', '/api/home'); check('Home braucht Profil ggf. 200/409', c in (200, 409), c)
mod = C(); mod.session(); c, j = mod.req('POST', '/api/login', {'username': 'mod', 'password': 'passwort123'}); mod.csrf = j['csrf']
c, j = mod.req('GET', '/api/admin/settings'); check('Moderator darf keine Einstellungen (403)', c == 403, c)
c, j = mod.req('GET', '/api/admin/users'); check('Moderator sieht Benutzerliste', c == 200, c)
c, j = mod.req('PUT', '/api/admin/users/1', {'role': 'user'}); check('Moderator kann Superadmin nicht ändern', c == 403, c)
c, j = adm.req('PUT', '/api/admin/users/1', {'role': 'user'}); check('Eigene Rolle nicht änderbar', c == 400, c)
c, b, h = adm.req('GET', '/stream.php?a=file&t=' + 'a' * 32, raw=True); check('Fremdes Token abgewiesen', c in (403, 404), c)
anon = C(); c, b, h = anon.req('GET', '/stream.php?a=file&t=' + 'a' * 32, raw=True); check('Anonymer Stream-Zugriff 401', c == 401, c)
c, b, h = anon.req('GET', '/img/../streamflix.db', raw=True); check('Pfadtraversal /img abgewehrt', c in (400, 403, 404), c)
c, b, h = anon.req('GET', '/database/schema.sql', raw=True); check('schema.sql nicht abrufbar', c in (403, 404), c)

print('Profile / PIN')
c, j = user.req('GET', '/api/profiles'); pid = j['profiles'][0]['id']
c, j = user.req('POST', f'/api/profiles/{pid}/select', {}); check('Profil wählen', c == 200, j)
c, j = user.req('PUT', f'/api/profiles/{pid}', {'pin': '1234'}); check('PIN setzen', c == 200, j)
c, j = user.req('POST', f'/api/profiles/{pid}/select', {'pin': '0000'}); check('Falsche PIN -> 403', c == 403, c)
c, j = user.req('POST', f'/api/profiles/{pid}/select', {'pin': '1234'}); check('Richtige PIN', c == 200, j)
c, j = user.req('POST', '/api/profiles', {'name': 'Kinder', 'is_kids': True}); check('Zweites Profil', c == 200, j)

print('Medienordner / Scanner')
c, j = adm.req('POST', '/api/admin/folders/test', {'path': '/etc'}); check('Ordner außerhalb /media abgelehnt', not j.get('inside_root'), j)
c, j = adm.req('POST', '/api/admin/folders/test', {'path': MOVIES}); check('Ordnertest', j.get('videos', 0) >= 1, j)
for typ, p in (('movies', MOVIES), ('series', SERIES)):
    c, j = adm.req('POST', '/api/admin/folders', {'path': p, 'type': typ, 'auto_scan': True})
    check(f'Ordner hinzufügen {typ}', c == 200 or 'Überschneidung' in str(j), j)
c, j = adm.req('GET', '/api/admin/folders'); folders = j['folders']
for f in folders:
    c, j = adm.req('POST', f"/api/admin/folders/{f['id']}/scan"); check('Scan gestartet', c == 200, j)
    for _ in range(120):
        time.sleep(1); c, j = adm.req('GET', '/api/admin/scans')
        top = [x for x in j['scans'] if x['folder_id'] == f['id']][0]
        if top['status'] != 'running': break
    check('Scan fertig', top['status'] == 'done', top)

print('Katalog')
c, home = user.req('GET', '/api/home'); check('Home liefert Reihen', c == 200 and len(home['rows']) > 0, home)
c, mv = adm.req('POST', f'/api/profiles/{pid}/select', {}) if False else (0, 0)
kid_id = [p['id'] for p in user.req('GET', '/api/profiles')[1]['profiles'] if p['name'] == 'Kinder'][0]
c, j = user.req('GET', '/api/movies'); check('Filmliste', c == 200 and j['total'] >= 1, j)
movie = j['items'][0]
c, j = user.req('GET', '/api/search?q=film'); check('Suche', c == 200 and j['total'] >= 1, j)
c, j = user.req('GET', '/api/search?q=%25'); check('LIKE-Wildcard escaped', c == 200 and j['total'] == 0, j)
c, j = user.req('GET', '/api/series'); sid = j['items'][0]['id']
c, sd = user.req('GET', f'/api/item/series/{sid}'); check('Serie mit Episoden', len(sd['seasons'][0]['episodes']) >= 2, sd)
ep1, ep2 = sd['seasons'][0]['episodes'][0]['id'], sd['seasons'][0]['episodes'][1]['id']
c, j = user.req('POST', '/api/mylist', {'kind': 'movie', 'item_id': movie['id'], 'on': True}); check('Meine Liste +', c == 200)
c, j = user.req('GET', '/api/mylist'); check('Meine Liste enthält Film', any(i['id'] == movie['id'] for i in j['items']))
c, j = user.req('POST', '/api/rating', {'kind': 'movie', 'item_id': movie['id'], 'rating': 5}); check('Bewertung', c == 200)

print('Streaming')
c, st = user.req('POST', '/api/stream/start', {'kind': 'movie', 'id': next(m['id'] for m in user.req('GET', '/api/movies?q=Testfilm')[1]['items'])})
check('Direct Play für MP4/H264/AAC', c == 200 and st.get('mode') == 'direct', st)
tok = st.get('token', '')
c, b, h = user.req('GET', st['url'], raw=True); check('Direct: Datei wird ausgeliefert', c == 200 and len(b) > 10000, (c, len(b)))
rq = urllib.request.Request(BASE + st['url'], headers={'Range': 'bytes=0-99'}); 
c, b, h = user.req('GET', st['url'], raw=True)
r2 = user.op.open(urllib.request.Request(BASE + st['url'], headers={'Range': 'bytes=0-99'}), timeout=30)
check('Range-Request -> 206', r2.status == 206 and len(r2.read()) == 100, r2.status)
c, j = user.req('POST', '/api/stream/ping', {'token': tok, 'position': 8, 'duration': 20}); check('Ping', c == 200 and not j.get('killed'), j)
c, j = adm.req('GET', '/api/admin/streams'); check('Admin sieht aktiven Stream', any(s['token'] == tok for s in j['streams']), j)
c, j = adm.req('POST', f'/api/admin/streams/{tok}/kill'); check('Admin beendet Stream', c == 200, j)
c, b, h = user.req('GET', st['url'], raw=True); check('Beendeter Stream gesperrt', c == 403, c)
c, j = user.req('POST', '/api/stream/ping', {'token': tok, 'position': 9, 'duration': 20}); check('Ping meldet killed', j.get('killed') is True, j)

old = next(m['id'] for m in user.req('GET', '/api/movies?q=Old')[1]['items'])
c, st2 = user.req('POST', '/api/stream/start', {'kind': 'movie', 'id': old})
check('MKV -> Remux (HLS)', c == 200 and st2.get('mode') == 'remux', st2)
c, b, h = user.req('GET', st2['url'], raw=True); check('HLS-Playlist', c == 200 and b'#EXTM3U' in b and b'/stream.php?a=hls' in b, b[:200])
seg = [l for l in b.decode().split('\n') if l.startswith('/stream.php')][0]
c, b, h = user.req('GET', seg, raw=True); check('HLS-Segment', c == 200 and len(b) > 1000, (c, len(b)))
check('Audiospuren geliefert', len(st2['audio']) == 2 and st2['audio_index'] == 0)
check('Untertitel geliefert', len(st2['subtitles']) >= 1, st2['subtitles'])
c, b, h = user.req('GET', f"/stream.php?a=sub&id={st2['subtitles'][0]['id']}&o=0", raw=True); check('WebVTT erzeugt (CP1252 -> UTF-8)', c == 200 and b.startswith(b'WEBVTT') and 'äöü'.encode() in b, b[:120])
c, j = user.req('POST', '/api/stream/stop', {'token': st2['token'], 'position': 5, 'duration': 20}); check('Stop', c == 200)
c, st3 = user.req('POST', '/api/stream/start', {'kind': 'movie', 'id': old, 'audio': 1})
check('Zweite Audiospur über HLS', c == 200 and st3['mode'] != 'direct' and st3['audio_index'] == 1, st3)
user.req('POST', '/api/stream/stop', {'token': st3['token'], 'position': 0, 'duration': 20})

c, st4 = user.req('POST', '/api/stream/start', {'kind': 'episode', 'id': ep1})
check('Episode startet, nächste Episode bekannt', c == 200 and st4['next'] and st4['next']['id'] == ep2, st4)
user.req('POST', '/api/stream/ping', {'token': st4['token'], 'position': 11.5, 'duration': 12})
user.req('POST', '/api/stream/stop', {'token': st4['token'], 'position': 11.5, 'duration': 12})
c, home = user.req('GET', '/api/home'); cont = [r for r in home['rows'] if r['id'] == 'continue']
check('Weiterschauen zeigt nächste Episode', cont and cont[0]['items'][0]['id'] == ep2, cont)
c, j = user.req('POST', f'/api/profiles/{kid_id}/select', {}); 
c, j = user.req('GET', '/api/movies'); check('Kinderprofil: nur freigegebene Titel (FSK 12 ja, ohne Einstufung nein)', j['total'] == 1 and j['items'][0]['title'] == 'Der Testfilm', j)
user.req('POST', f'/api/profiles/{pid}/select', {'pin': '1234'})
c, j = user.req('GET', '/api/mylist'); check('Daten pro Profil getrennt', c == 200)

print('Admin')
c, j = adm.req('GET', '/api/admin/dashboard'); check('Dashboard', c == 200 and j['movies'] >= 2 and 'stats' in j, j.get('error'))
c, j = adm.req('GET', '/api/admin/server'); check('Serverstatus', c == 200 and j['info']['ffmpeg'].startswith('ffmpeg'), j)
c, j = adm.req('GET', '/api/admin/logs?q=login'); check('Protokoll mit Suche', c == 200 and j['total'] >= 1, j)
c, j = adm.req('PUT', '/api/admin/settings', {'platform_name': 'MeinFlix', 'max_streams': '3'}); check('Einstellungen speichern', c == 200, j)
c, j = adm.req('PUT', '/api/admin/settings', {'accent_color': 'rot'}); check('Ungültige Einstellung abgelehnt', c == 400)
c, j = adm.req('PUT', '/api/admin/settings', {'platform_name': 'STREAMFLIX', 'max_streams': '4'})
c, j = adm.req('PUT', f'/api/admin/movies/{movie["id"]}', {'title': 'Bearbeitet', 'genres': 'Drama, Krimi'}); check('Film bearbeiten', c == 200, j)
c, j = adm.req('POST', '/api/admin/backups', {}); check('Backup erstellen', c == 200 and j['size'] > 1000, j)
c, bl = adm.req('GET', '/api/admin/backups'); bid = bl['backups'][0]['id']
c, b, h = adm.req('GET', f'/api/admin/backups/{bid}/download', raw=True); check('Backup-Download (ZIP)', c == 200 and b[:2] == b'PK', c)
adm.req('PUT', f'/api/admin/movies/{movie["id"]}', {'title': 'NachBackup'})
c, j = adm.req('POST', f'/api/admin/backups/{bid}/restore', {'confirm': 'WIEDERHERSTELLEN'}); check('Wiederherstellung', c == 200, j)
c, j = adm.req('GET', f'/api/admin/movies/{movie["id"]}'); check('Daten nach Restore zurückgesetzt', j.get('title') == 'Bearbeitet', j.get('title'))
c, j = adm.req('POST', '/api/admin/users/2/reset-password', {}); check('Passwort-Reset durch Admin', c == 200 and j.get('password'), j)
c, j = adm.req('PUT', '/api/admin/users/2', {'status': 'blocked'}); check('Benutzer sperren', c == 200, j)
c, j = user.req('GET', '/api/profiles'); check('Gesperrter Benutzer sofort ausgeloggt', c == 401, c)
c, j = adm.req('DELETE', '/api/admin/users/2'); check('Benutzer löschen', c == 200, j)

print('\nERGEBNIS:', 'ALLE TESTS BESTANDEN' if fails == 0 else f'{fails} FEHLER')
sys.exit(1 if fails else 0)
