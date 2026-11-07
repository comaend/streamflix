<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/catalog.php';
require_once __DIR__ . '/../lib/streaming.php';
require_once __DIR__ . '/../lib/accounts.php';

try {
    api_main();
} catch (Throwable $e) {
    error_log('API-Fehler: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    fail('Interner Serverfehler.', 500);
}

function public_settings(): array {
    return ['platform_name' => setting('platform_name', 'STREAMFLIX'), 'accent_color' => setting('accent_color', '#e50914'),
            'registration_enabled' => setting('registration_enabled', '1') === '1', 'maintenance' => setting('maintenance_mode', '0') === '1',
            'login_bg' => img(setting('login_bg') ?: null), 'logo' => img(setting('logo') ?: null),
            'needs_setup' => (int)qv('SELECT COUNT(*) FROM users') === 0];
}

function touch_session(array $u): void {
    $now = time();
    if (($_SESSION['seen_t'] ?? 0) > $now - 60) return;
    $_SESSION['seen_t'] = $now;
    $sid = hash('sha256', session_id());
    q("INSERT INTO sessions(id,user_id,profile_id,ip,user_agent) VALUES(?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET last_seen=datetime('now'),profile_id=excluded.profile_id,ip=excluded.ip",
      [$sid, $u['id'], $_SESSION['pid'] ?? null, client_ip(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
}

function user_payload(array $u): array {
    return ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role'], 'must_change_password' => (bool)$u['must_change_password']];
}

function profile_row(array $p): array {
    return ['id' => (int)$p['id'], 'name' => $p['name'], 'avatar' => $p['avatar'], 'is_kids' => (bool)$p['is_kids'], 'has_pin' => $p['pin_hash'] !== null && $p['pin_hash'] !== ''];
}

function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    unset($_SESSION['pid'], $_SESSION['seen_t']);
    q("UPDATE users SET last_login=datetime('now') WHERE id=?", [$u['id']]);
    $profiles = qa('SELECT * FROM profiles WHERE user_id=?', [$u['id']]);
    if (count($profiles) === 1 && !$profiles[0]['pin_hash']) $_SESSION['pid'] = (int)$profiles[0]['id'];
}

function save_upload(string $purpose): array {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Upload fehlgeschlagen.');
    $f = $_FILES['file'];
    if ($f['size'] > 8 * 1048576) fail('Datei zu groß (max. 8 MB).');
    $info = @getimagesize($f['tmp_name']);
    $ext = match ($info[2] ?? 0) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null };
    if (!$ext) fail('Nur JPG, PNG oder WebP erlaubt.');
    if ($purpose === 'avatar' && ($info[0] > 1024 || $info[1] > 1024)) fail('Profilbilder dürfen maximal 1024×1024 Pixel groß sein.');
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], SF_DATA . '/images/' . $name)) fail('Speichern fehlgeschlagen.', 500);
    return ['name' => $name, 'url' => '/img/' . $name];
}

function api_main(): void {
    if (preg_match('#^/api/health/?$#', (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) out(['ok' => true]);
    start_session();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $path = preg_replace('#^api/?#', '', $path);
    $ids = [];
    $norm = preg_replace_callback('#/([a-f0-9]{32})(?=/|$)|/(\d+)(?=/|$)#', function ($m) use (&$ids) { $ids[] = ($m[1] ?? '') !== '' ? $m[1] : (int)$m[2]; return '/{id}'; }, '/' . $path);
    $key = $method . ' ' . ltrim($norm, '/');

    if ($key === 'GET health') out(['ok' => true]);
    check_csrf();

    $public = ['GET session', 'POST login', 'POST register', 'POST logout'];
    $u = current_user();
    if ($u) {
        touch_session($u);
        if (setting('maintenance_mode', '0') === '1' && !has_role($u, 'moderator') && !in_array($key, $public, true)) fail('Wartungsmodus aktiv. Bitte später erneut versuchen.', 503);
    }

    if (str_starts_with($path, 'admin/')) {
        require_once __DIR__ . '/../lib/admin_api.php';
        admin_route($key, $ids, $method);
        fail('Unbekannte Route.', 404);
    }

    switch ($key) {
        case 'GET session':
            $pid = $u ? (int)($_SESSION['pid'] ?? 0) : 0;
            out(['csrf' => $_SESSION['csrf'], 'user' => $u ? user_payload($u) : null, 'profile_id' => $pid ?: null,
                 'profile' => $pid ? profile_row(q1('SELECT * FROM profiles WHERE id=?', [$pid]) ?: ['id' => 0, 'name' => '', 'avatar' => 'a1', 'is_kids' => 0, 'pin_hash' => null]) : null,
                 'settings' => public_settings(), 'version' => SF_VERSION]);

        case 'POST login':
            $in = input(); $name = trim((string)($in['username'] ?? '')); $pw = (string)($in['password'] ?? '');
            rate_limit('login:ip:' . client_ip(), 15, 300);
            rate_limit('login:u:' . mb_strtolower($name), 8, 300);
            $row = q1('SELECT * FROM users WHERE username=?', [$name]);
            $ok = password_verify($pw, $row['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuT3nQ1fG0g1m7bZb1b5z0y8s5m3kq3Wa');
            if (!$row || !$ok) { log_activity('login.failed', "Benutzer: $name", 'warn', ['id' => null, 'username' => $name]); fail('Benutzername oder Passwort falsch.', 401); }
            if ($row['status'] !== 'active') { log_activity('login.blocked', "Benutzer: $name", 'warn', $row); fail('Dieses Konto ist gesperrt.', 403); }
            if (setting('maintenance_mode', '0') === '1' && ROLE_LEVEL[$row['role']] < 2) fail('Wartungsmodus aktiv. Bitte später erneut versuchen.', 503);
            rate_reset('login:u:' . mb_strtolower($name));
            login_user($row);
            log_activity('login', '', 'info', $row);
            out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => user_payload($row), 'profile_id' => $_SESSION['pid'] ?? null]);

        case 'POST register':
            rate_limit('reg:' . client_ip(), 6, 3600);
            $in = input(); $name = trim((string)($in['username'] ?? '')); $pw = (string)($in['password'] ?? '');
            $first = (int)qv('SELECT COUNT(*) FROM users') === 0;
            if (!$first) {
                if (setting('registration_enabled', '1') !== '1') fail('Die Registrierung ist deaktiviert.', 403);
                $max = (int)setting('max_users', '0');
                if ($max > 0 && (int)qv('SELECT COUNT(*) FROM users') >= $max) fail('Die maximale Benutzerzahl ist erreicht.', 403);
            }
            if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $name)) fail('Benutzername: 3–32 Zeichen (Buchstaben, Ziffern, _ . -).');
            if ($e = valid_password($pw)) fail($e);
            if (q1('SELECT 1 FROM users WHERE username=?', [$name])) fail('Dieser Benutzername ist bereits vergeben.', 409);
            $email = trim((string)($in['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Ungültige E-Mail-Adresse.');
            db()->beginTransaction();
            q('INSERT INTO users(username,email,password_hash,role) VALUES(?,?,?,?)', [$name, $email ?: null, password_hash($pw, PASSWORD_DEFAULT), $first ? 'superadmin' : 'user']);
            $uid = (int)db()->lastInsertId();
            q('INSERT INTO user_settings(user_id,language) VALUES(?,?)', [$uid, setting('default_language', 'de')]);
            create_profile($uid, $name);
            db()->commit();
            $row = q1('SELECT * FROM users WHERE id=?', [$uid]);
            login_user($row);
            log_activity('register', $first ? 'Erster Benutzer (Superadmin)' : '', 'info', $row);
            out(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => user_payload($row), 'profile_id' => $_SESSION['pid'] ?? null]);

        case 'POST logout':
            if ($u) { q('DELETE FROM sessions WHERE id=?', [hash('sha256', session_id())]); log_activity('logout', '', 'info', $u); }
            $_SESSION = []; session_destroy();
            out(['ok' => true]);

        case 'POST change-password':
            $u = require_login(); $in = input();
            rate_limit('pw:' . $u['id'], 6, 600);
            $row = q1('SELECT password_hash FROM users WHERE id=?', [$u['id']]);
            if (!password_verify((string)($in['current'] ?? ''), $row['password_hash'])) fail('Aktuelles Passwort ist falsch.', 403);
            if ($e = valid_password((string)($in['new'] ?? ''))) fail($e);
            q('UPDATE users SET password_hash=?,must_change_password=0 WHERE id=?', [password_hash((string)$in['new'], PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            log_activity('password.change', '', 'info', $u);
            out(['ok' => true]);

        case 'GET profiles':
            $u = require_login();
            out(['profiles' => array_map('profile_row', qa('SELECT * FROM profiles WHERE user_id=? ORDER BY id', [$u['id']])), 'current' => $_SESSION['pid'] ?? null]);

        case 'POST profiles':
            $u = require_login(); $in = input();
            if ((int)qv('SELECT COUNT(*) FROM profiles WHERE user_id=?', [$u['id']]) >= 6) fail('Maximal 6 Profile pro Konto.');
            $id = create_profile((int)$u['id'], (string)($in['name'] ?? ''), (string)($in['avatar'] ?? 'a1'), !empty($in['is_kids']));
            if (isset($in['pin']) && $in['pin'] !== '') set_pin($id, (string)$in['pin']);
            out(['ok' => true, 'id' => $id]);

        case 'PUT profiles/{id}':
        case 'DELETE profiles/{id}':
        case 'POST profiles/{id}/select':
            $u = require_login(); $pid = (int)$ids[0]; $in = input();
            $p = q1('SELECT * FROM profiles WHERE id=? AND user_id=?', [$pid, $u['id']]);
            if (!$p) fail('Profil nicht gefunden.', 404);
            if ($method === 'POST') {
                if ($p['pin_hash']) {
                    rate_limit('pin:' . $pid, 6, 300);
                    if (!password_verify((string)($in['pin'] ?? ''), $p['pin_hash'])) fail('Falsche PIN.', 403);
                    rate_reset('pin:' . $pid);
                }
                $_SESSION['pid'] = $pid; unset($_SESSION['seen_t']);
                out(['ok' => true, 'profile' => profile_row($p)]);
            }
            if ($method === 'DELETE') {
                if ((int)qv('SELECT COUNT(*) FROM profiles WHERE user_id=?', [$u['id']]) <= 1) fail('Das letzte Profil kann nicht gelöscht werden.');
                if ($p['pin_hash'] && !password_verify((string)($in['pin'] ?? ''), $p['pin_hash'])) fail('Zum Löschen wird die PIN benötigt.', 403);
                q('DELETE FROM profiles WHERE id=?', [$pid]);
                if (($_SESSION['pid'] ?? 0) === $pid) unset($_SESSION['pid']);
                out(['ok' => true]);
            }
            if ($p['pin_hash'] && array_key_exists('pin', $in) && !password_verify((string)($in['current_pin'] ?? ''), $p['pin_hash'])) fail('Aktuelle PIN erforderlich.', 403);
            $name = trim((string)($in['name'] ?? $p['name']));
            if ($name === '' || mb_strlen($name) > 30) fail('Profilname muss 1–30 Zeichen lang sein.');
            $av = (string)($in['avatar'] ?? $p['avatar']);
            if (!valid_avatar($av)) fail('Ungültiges Profilbild.');
            q('UPDATE profiles SET name=?,avatar=?,is_kids=? WHERE id=?', [$name, $av, array_key_exists('is_kids', $in) ? (int)!empty($in['is_kids']) : $p['is_kids'], $pid]);
            if (array_key_exists('pin', $in)) set_pin($pid, (string)$in['pin']);
            out(['ok' => true]);

        case 'GET settings':
            $pid = current_profile_id(); $u = require_login();
            $s = q1('SELECT * FROM profile_settings WHERE profile_id=?', [$pid]) ?: ['audio_lang' => '', 'subtitle_lang' => '', 'subtitles_on' => 0, 'autoplay_next' => 1];
            out(['audio_lang' => $s['audio_lang'], 'subtitle_lang' => $s['subtitle_lang'], 'subtitles_on' => (bool)$s['subtitles_on'], 'autoplay_next' => (bool)$s['autoplay_next'],
                 'language' => qv('SELECT language FROM user_settings WHERE user_id=?', [$u['id']]) ?: 'de']);

        case 'PUT settings':
            $pid = current_profile_id(); $u = require_login(); $in = input();
            $lang = static fn($v) => preg_match('/^[a-z]{0,3}$/i', (string)$v) ? strtolower((string)$v) : '';
            q('INSERT INTO profile_settings(profile_id,audio_lang,subtitle_lang,subtitles_on,autoplay_next) VALUES(?,?,?,?,?)
               ON CONFLICT(profile_id) DO UPDATE SET audio_lang=excluded.audio_lang,subtitle_lang=excluded.subtitle_lang,subtitles_on=excluded.subtitles_on,autoplay_next=excluded.autoplay_next',
              [$pid, $lang($in['audio_lang'] ?? ''), $lang($in['subtitle_lang'] ?? ''), (int)!empty($in['subtitles_on']), (int)!empty($in['autoplay_next'])]);
            q('INSERT INTO user_settings(user_id,language) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET language=excluded.language', [$u['id'], in_array($in['language'] ?? 'de', ['de', 'en'], true) ? $in['language'] : 'de']);
            out(['ok' => true]);

        case 'GET home': out(home(current_profile_id()));
        case 'GET movies': out(browse(current_profile_id(), 'movie', $_GET));
        case 'GET series': out(browse(current_profile_id(), 'series', $_GET));
        case 'GET genres': require_login(); out(['genres' => all_genres()]);
        case 'GET search':
            $pid = current_profile_id();
            $f = $_GET; $f['per'] = 60;
            $m = browse($pid, 'movie', $f); $s = browse($pid, 'series', $f);
            out(['items' => array_merge($m['items'], $s['items']), 'total' => $m['total'] + $s['total']]);
        case 'GET mylist': out(['items' => my_list(current_profile_id())]);

        case 'GET item/movie/{id}':
        case 'GET item/series/{id}':
            $pid = current_profile_id();
            $d = item_detail($pid, str_contains($key, 'movie') ? 'movie' : 'series', (int)$ids[0]);
            if (!$d) fail('Nicht gefunden.', 404);
            out($d);

        case 'POST mylist':
            $pid = current_profile_id(); $in = input();
            $kind = $in['kind'] ?? ''; $id = (int)($in['item_id'] ?? 0);
            if (!in_array($kind, ['movie', 'series'], true) || !$id) fail('Ungültige Anfrage.');
            if (!empty($in['on'])) q('INSERT OR IGNORE INTO watchlist(profile_id,kind,item_id) VALUES(?,?,?)', [$pid, $kind, $id]);
            else q('DELETE FROM watchlist WHERE profile_id=? AND kind=? AND item_id=?', [$pid, $kind, $id]);
            out(['ok' => true]);

        case 'POST rating':
            $pid = current_profile_id(); $in = input();
            $kind = $in['kind'] ?? ''; $id = (int)($in['item_id'] ?? 0); $r = (int)($in['rating'] ?? 0);
            if (!in_array($kind, ['movie', 'series'], true) || !$id || $r < 0 || $r > 5) fail('Ungültige Anfrage.');
            if ($r === 0) q('DELETE FROM ratings WHERE profile_id=? AND kind=? AND item_id=?', [$pid, $kind, $id]);
            else q("INSERT INTO ratings(profile_id,kind,item_id,rating) VALUES(?,?,?,?) ON CONFLICT(profile_id,kind,item_id) DO UPDATE SET rating=excluded.rating,updated_at=datetime('now')", [$pid, $kind, $id, $r]);
            out(['ok' => true]);

        case 'GET history':
            $pid = current_profile_id(); $out = [];
            foreach (qa('SELECT id,kind,item_id,watched_at FROM watch_history WHERE profile_id=? ORDER BY watched_at DESC,id DESC LIMIT 100', [$pid]) as $h) {
                $t = stream_title($h['kind'], (int)$h['item_id']);
                if ($t !== '') $out[] = ['id' => (int)$h['id'], 'kind' => $h['kind'], 'item_id' => (int)$h['item_id'], 'title' => $t, 'watched_at' => $h['watched_at']];
            }
            out(['items' => $out]);

        case 'POST history/clear':
            $pid = current_profile_id();
            q('DELETE FROM watch_history WHERE profile_id=?', [$pid]); q('DELETE FROM watch_progress WHERE profile_id=?', [$pid]);
            out(['ok' => true]);

        case 'POST upload':
            $u = require_login();
            $purpose = (string)($_POST['purpose'] ?? '');
            if (!in_array($purpose, ['avatar', 'poster', 'backdrop', 'logo', 'bg'], true)) fail('Ungültiger Zweck.');
            if ($purpose !== 'avatar' && !has_role($u, in_array($purpose, ['logo', 'bg'], true) ? 'admin' : 'moderator')) fail('Keine Berechtigung.', 403);
            out(save_upload($purpose));

        case 'POST stream/start': stream_start($u ?: require_login()); // no break: exits
        case 'POST stream/ping':
            $u = require_login(); $in = input();
            $row = q1('SELECT * FROM active_streams WHERE token=? AND user_id=?', [(string)($in['token'] ?? ''), $u['id']]);
            if (!$row) out(['killed' => true]);
            if ($row['killed']) { kill_stream($row); q('DELETE FROM active_streams WHERE token=?', [$row['token']]); out(['killed' => true]); }
            $pos = max(0.0, (float)($in['position'] ?? 0)); $dur = max(0.0, (float)($in['duration'] ?? 0));
            q('UPDATE active_streams SET last_ping=?,position=? WHERE token=?', [time(), $pos, $row['token']]);
            if ($pos > 5) save_progress((int)$row['profile_id'], $row['kind'], (int)$row['item_id'], $pos, $dur);
            if (random_int(1, 12) === 1) cleanup_streams();
            out(['ok' => true]);

        case 'POST stream/stop':
            $u = require_login(); $in = input();
            $row = q1('SELECT * FROM active_streams WHERE token=? AND user_id=?', [(string)($in['token'] ?? ''), $u['id']]);
            if ($row) {
                $pos = (float)($in['position'] ?? 0); $dur = (float)($in['duration'] ?? 0);
                if ($pos > 5) save_progress((int)$row['profile_id'], $row['kind'], (int)$row['item_id'], $pos, $dur);
                kill_stream($row); q('DELETE FROM active_streams WHERE token=?', [$row['token']]);
            }
            out(['ok' => true]);
    }
    fail('Unbekannte Route.', 404);
}

function stream_start(array $u): never {
    $pid = current_profile_id(); $in = input();
    $kind = $in['kind'] ?? ''; $id = (int)($in['id'] ?? 0);
    if (!in_array($kind, ['movie', 'episode'], true) || !$id) fail('Ungültige Anfrage.');
    cleanup_streams();
    if (!empty($in['old_token'])) {
        $old = q1('SELECT * FROM active_streams WHERE token=? AND user_id=?', [(string)$in['old_token'], $u['id']]);
        if ($old) { kill_stream($old); q('DELETE FROM active_streams WHERE token=?', [$old['token']]); }
    }
    $f = $kind === 'movie' ? q1('SELECT * FROM media_files WHERE movie_id=? ORDER BY id LIMIT 1', [$id]) : q1('SELECT * FROM media_files WHERE episode_id=? ORDER BY id LIMIT 1', [$id]);
    if (!$f) fail('Mediendatei nicht gefunden.', 404);
    if (!safe_media_path($f['path'])) fail('Die Datei ist nicht verfügbar (Laufwerk getrennt?).', 404);
    $age = $kind === 'movie' ? qv('SELECT age_rating FROM movies WHERE id=?', [$id]) : qv('SELECT se.age_rating FROM episodes e JOIN series se ON se.id=e.series_id WHERE e.id=?', [$id]);
    if (!allowed_age($pid, $age)) fail('Dieser Titel ist für dieses Profil nicht freigegeben.', 403);

    $maxS = (int)setting('max_streams', '4');
    if ($maxS > 0 && (int)qv('SELECT COUNT(*) FROM active_streams WHERE killed=0') >= $maxS) fail('Die maximale Anzahl gleichzeitiger Streams ist erreicht.', 429);

    $ps = q1('SELECT * FROM profile_settings WHERE profile_id=?', [$pid]) ?: ['audio_lang' => '', 'subtitle_lang' => '', 'subtitles_on' => 0, 'autoplay_next' => 1];
    $tracks = json_decode((string)$f['audio_tracks'], true) ?: [];
    $audio = isset($in['audio']) ? (int)$in['audio'] : 0;
    if (!isset($in['audio']) && $ps['audio_lang'] !== '') {
        foreach ($tracks as $t) if (in_array(strtolower($t['lang']), lang_aliases($ps['audio_lang']), true)) { $audio = (int)$t['i']; break; }
    }
    $mode = decide_mode($f, $audio, !empty($in['force_hls']));
    if ($mode === 'transcode' && (int)qv("SELECT COUNT(*) FROM active_streams WHERE mode='transcode' AND killed=0") >= max(1, (int)setting('max_transcodes', '1')))
        fail('Die maximale Anzahl gleichzeitiger Transkodierungen ist erreicht. Bitte später erneut versuchen.', 429);

    $prog = q1('SELECT position,duration,completed FROM watch_progress WHERE profile_id=? AND kind=? AND item_id=?', [$pid, $kind, $id]);
    $dur = (float)$f['duration'];
    $start = isset($in['start']) ? max(0.0, (float)$in['start']) : (($prog && !$prog['completed'] && $prog['position'] > 10 && $prog['position'] < $dur - 30) ? (float)$prog['position'] : 0.0);

    $token = bin2hex(random_bytes(16));
    $title = stream_title($kind, $id);
    q('INSERT INTO active_streams(token,user_id,profile_id,kind,item_id,media_file_id,mode,ip,title,bitrate,last_ping,position) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
      [$token, $u['id'], $pid, $kind, $id, $f['id'], $mode, client_ip(), $title, $f['bitrate'], time(), $start]);
    $offset = 0.0;
    if ($mode !== 'direct') {
        try {
            $pidFf = start_hls($token, $f, $mode, $start, $audio);
            q('UPDATE active_streams SET ffmpeg_pid=? WHERE token=?', [$pidFf, $token]);
            $offset = $start;
        } catch (Throwable $e) {
            q('DELETE FROM active_streams WHERE token=?', [$token]);
            rm_dir(stream_dir($token));
            log_activity('stream.error', "$title: " . $e->getMessage(), 'error', $u);
            fail($e->getMessage(), 500);
        }
    }
    // Verlauf (nicht mehrfach innerhalb von 6 Stunden)
    if (!q1("SELECT 1 FROM watch_history WHERE profile_id=? AND kind=? AND item_id=? AND watched_at > datetime('now','-6 hours')", [$pid, $kind, $id]))
        q('INSERT INTO watch_history(profile_id,kind,item_id) VALUES(?,?,?)', [$pid, $kind, $id]);
    log_activity('stream.start', "$title ($mode)", 'info', $u);

    $subs = [];
    $wantSub = $ps['subtitles_on'] ? $ps['subtitle_lang'] : (setting('default_subtitle', 'off') !== 'off' ? setting('default_subtitle') : '');
    $defaultSub = null;
    foreach (qa('SELECT id,lang,label,kind FROM subtitles WHERE media_file_id=? ORDER BY kind DESC,id', [$f['id']]) as $s) {
        $subs[] = ['id' => (int)$s['id'], 'label' => $s['label'] . ($s['kind'] === 'external' ? ' (extern)' : ''), 'lang' => $s['lang']];
        if ($defaultSub === null && $wantSub !== '' && in_array(strtolower((string)$s['lang']), lang_aliases($wantSub), true)) $defaultSub = (int)$s['id'];
    }
    // Intro/Abspann
    $intro = null; $credits = null;
    if ($kind === 'episode') {
        $se = q1('SELECT se.intro_start,se.intro_end,se.credits_offset,se.id AS sid FROM episodes e JOIN series se ON se.id=e.series_id WHERE e.id=?', [$id]);
        if ($f['intro_end']) $intro = [(int)$f['intro_start'], (int)$f['intro_end']];
        elseif ($se && $se['intro_end']) $intro = [(int)$se['intro_start'], (int)$se['intro_end']];
        $credits = $f['credits_start'] ? (int)$f['credits_start'] : ($dur > 0 ? (int)max(0, $dur - (int)($se['credits_offset'] ?? 30)) : null);
    } else {
        $co = (int)(qv('SELECT credits_offset FROM movies WHERE id=?', [$id]) ?? 0);
        $credits = $f['credits_start'] ? (int)$f['credits_start'] : ($co > 0 && $dur > 0 ? (int)($dur - $co) : null);
    }
    $next = $prev = null;
    if ($kind === 'episode') {
        $n = next_episode($id); $p = prev_episode($id);
        $next = $n ? ['id' => (int)$n['id']] : null; $prev = $p ? ['id' => (int)$p['id']] : null;
    }
    out(['token' => $token, 'mode' => $mode, 'title' => $title, 'duration' => $dur, 'start' => $mode === 'direct' ? $start : 0, 'offset' => $offset,
         'url' => $mode === 'direct' ? "/stream.php?a=file&t=$token" : "/stream.php?a=hls&t=$token&f=index.m3u8",
         'audio' => $tracks, 'audio_index' => $audio, 'subtitles' => $subs, 'default_subtitle' => $defaultSub,
         'intro' => $intro, 'credits' => $credits, 'next' => $next, 'prev' => $prev, 'autoplay_next' => (bool)$ps['autoplay_next'],
         'kind' => $kind, 'id' => $id, 'series_id' => $kind === 'episode' ? (int)qv('SELECT series_id FROM episodes WHERE id=?', [$id]) : null]);
}

function lang_aliases(string $l): array {
    $l = strtolower($l);
    $map = ['de' => ['de', 'ger', 'deu'], 'en' => ['en', 'eng'], 'fr' => ['fr', 'fre', 'fra'], 'es' => ['es', 'spa'], 'it' => ['it', 'ita'], 'ja' => ['ja', 'jpn'], 'tr' => ['tr', 'tur']];
    foreach ($map as $set) if (in_array($l, $set, true)) return $set;
    return [$l];
}
