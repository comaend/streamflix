<?php
declare(strict_types=1);
require_once __DIR__ . '/scanner.php';
require_once __DIR__ . '/system.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/accounts.php';

function can_manage(array $actor, array $target): bool {
    if ((int)$actor['id'] === (int)$target['id']) return true;
    if ($actor['role'] === 'superadmin') return true;
    return ROLE_LEVEL[$actor['role']] > ROLE_LEVEL[$target['role']];
}
function assignable_roles(array $actor): array {
    return match ($actor['role']) { 'superadmin' => ['user', 'moderator', 'admin', 'superadmin'], 'admin' => ['user', 'moderator'], default => ['user'] };
}
function load_user(int $id): array { $t = q1('SELECT * FROM users WHERE id=?', [$id]); if (!$t) fail('Benutzer nicht gefunden.', 404); return $t; }
function user_row(array $r): array {
    return ['id' => (int)$r['id'], 'username' => $r['username'], 'email' => $r['email'], 'role' => $r['role'], 'status' => $r['status'],
            'last_login' => $r['last_login'], 'created_at' => $r['created_at'], 'profiles' => (int)($r['pc'] ?? 0)];
}
function last_superadmin(int $id): bool {
    return (int)qv("SELECT COUNT(*) FROM users WHERE role='superadmin' AND status='active' AND id<>?", [$id]) === 0;
}
function str_in(array $in, string $k, int $max, ?string $default = null): ?string {
    if (!array_key_exists($k, $in)) return $default;
    $v = trim((string)$in[$k]);
    return $v === '' ? null : mb_substr($v, 0, $max);
}
function img_name(?string $v): ?string {
    if ($v === null || $v === '') return null;
    $v = basename($v);
    return preg_match('/^[A-Za-z0-9_.-]{1,80}\.(jpg|jpeg|png|webp)$/', $v) && is_file(SF_DATA . '/images/' . $v) ? $v : null;
}
function path_overlaps(string $new, ?int $exceptId = null): ?string {
    foreach (qa('SELECT id,path FROM media_folders') as $f) {
        if ($exceptId && (int)$f['id'] === $exceptId) continue;
        $a = rtrim($f['path'], '/') . '/'; $b = rtrim($new, '/') . '/';
        if (str_starts_with($a, $b) || str_starts_with($b, $a)) return $f['path'];
    }
    return null;
}
function test_folder(string $path): array {
    $res = ['path' => $path, 'exists' => false, 'readable' => false, 'inside_root' => false, 'videos' => 0, 'subdirs' => 0, 'message' => ''];
    $real = safe_media_path($path);
    if (!$real) {
        $res['message'] = is_dir($path) ? 'Der Ordner liegt außerhalb des freigegebenen Medienstamms (' . SF_MEDIA . ').' : 'Ordner nicht gefunden. Pfade müssen mit ' . SF_MEDIA . '/ beginnen.';
        return $res;
    }
    $res['path'] = $real; $res['inside_root'] = true; $res['exists'] = is_dir($real); $res['readable'] = is_readable($real);
    if (!$res['exists']) { $res['message'] = 'Kein Ordner.'; return $res; }
    if (!$res['readable']) { $res['message'] = 'Keine Leseberechtigung für diesen Ordner.'; return $res; }
    $t0 = microtime(true); $n = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            if (++$n > 50000 || microtime(true) - $t0 > 5) { $res['message'] = 'Vorschau abgebrochen (sehr großer Ordner). Der Ordner ist aber nutzbar.'; break; }
            if ($f->isDir()) $res['subdirs']++;
            elseif (in_array(strtolower($f->getExtension()), VIDEO_EXT, true)) $res['videos']++;
        }
    } catch (Throwable $e) { $res['message'] = 'Teilweise nicht lesbar: ' . $e->getMessage(); }
    if ($res['message'] === '') $res['message'] = "OK: {$res['videos']} Videodateien gefunden.";
    return $res;
}

function admin_route(string $key, array $ids, string $method): void {
    $u = require_role('moderator');
    $need = function (string $role) use ($u) { if (!has_role($u, $role)) fail('Keine Berechtigung.', 403); };
    $in = input();

    switch ($key) {
        /* ---------- Dashboard / Server ---------- */
        case 'GET admin/dashboard':
            cleanup_streams();
            $c = fn(string $s, array $p = []) => (int)qv($s, $p);
            $days = qa("SELECT date(created_at) d, COUNT(*) c FROM activity_logs WHERE action='stream.start' AND created_at > datetime('now','-13 days') GROUP BY d");
            $logins = qa("SELECT date(created_at) d, COUNT(*) c FROM activity_logs WHERE action='login' AND created_at > datetime('now','-13 days') GROUP BY d");
            $topGenres = qa('SELECT g.name, (SELECT COUNT(*) FROM movie_genres WHERE genre_id=g.id)+(SELECT COUNT(*) FROM series_genres WHERE genre_id=g.id) c FROM genres g ORDER BY c DESC LIMIT 8');
            out(['movies' => $c('SELECT COUNT(*) FROM movies'), 'series' => $c('SELECT COUNT(*) FROM series'), 'episodes' => $c('SELECT COUNT(*) FROM episodes'),
                 'users' => $c('SELECT COUNT(*) FROM users'), 'profiles' => $c('SELECT COUNT(*) FROM profiles'),
                 'active_users' => $c("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE last_seen > datetime('now','-15 minutes')"),
                 'active_profiles' => $c("SELECT COUNT(DISTINCT profile_id) FROM sessions WHERE profile_id IS NOT NULL AND last_seen > datetime('now','-15 minutes')"),
                 'active_streams' => $c('SELECT COUNT(*) FROM active_streams WHERE killed=0'),
                 'library_bytes' => $c('SELECT COALESCE(SUM(size),0) FROM media_files'),
                 'streams_by_day' => $days, 'logins_by_day' => $logins, 'top_genres' => $topGenres, 'stats' => server_stats()]);
        case 'GET admin/server':
            $need('admin'); out(['stats' => server_stats(), 'info' => server_info()]);

        /* ---------- Benutzer ---------- */
        case 'GET admin/users':
            $rows = qa("SELECT u.*, (SELECT COUNT(*) FROM profiles p WHERE p.user_id=u.id) pc FROM users u ORDER BY u.id");
            out(['users' => array_map('user_row', $rows), 'assignable' => assignable_roles($u)]);

        case 'POST admin/users':
            $name = trim((string)($in['username'] ?? '')); $pw = (string)($in['password'] ?? ''); $role = (string)($in['role'] ?? 'user');
            if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $name)) fail('Benutzername: 3–32 Zeichen (Buchstaben, Ziffern, _ . -).');
            if ($e = valid_password($pw)) fail($e);
            if (!in_array($role, assignable_roles($u), true)) fail('Diese Rolle darfst du nicht vergeben.', 403);
            if (q1('SELECT 1 FROM users WHERE username=?', [$name])) fail('Benutzername bereits vergeben.', 409);
            db()->beginTransaction();
            q('INSERT INTO users(username,email,password_hash,role,must_change_password) VALUES(?,?,?,?,1)', [$name, str_in($in, 'email', 120), password_hash($pw, PASSWORD_DEFAULT), $role]);
            $id = (int)db()->lastInsertId();
            q('INSERT INTO user_settings(user_id,language) VALUES(?,?)', [$id, setting('default_language', 'de')]);
            create_profile($id, $name);
            db()->commit();
            log_activity('admin.user.create', "$name ($role)");
            out(['ok' => true, 'id' => $id]);

        case 'PUT admin/users/{id}':
            $t = load_user((int)$ids[0]);
            if (!can_manage($u, $t)) fail('Keine Berechtigung für diesen Benutzer.', 403);
            $role = (string)($in['role'] ?? $t['role']); $status = (string)($in['status'] ?? $t['status']);
            if ($role !== $t['role']) {
                if ((int)$t['id'] === (int)$u['id']) fail('Du kannst deine eigene Rolle nicht ändern.');
                if (!in_array($role, assignable_roles($u), true)) fail('Diese Rolle darfst du nicht vergeben.', 403);
                if ($t['role'] === 'superadmin' && last_superadmin((int)$t['id'])) fail('Der letzte Superadmin kann nicht herabgestuft werden.');
            }
            if ($status !== $t['status']) {
                if ((int)$t['id'] === (int)$u['id']) fail('Du kannst dich nicht selbst sperren.');
                if (!in_array($status, ['active', 'blocked'], true)) fail('Ungültiger Status.');
                if ($t['role'] === 'superadmin' && $status === 'blocked' && last_superadmin((int)$t['id'])) fail('Der letzte Superadmin kann nicht gesperrt werden.');
            }
            $email = str_in($in, 'email', 120, $t['email']);
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Ungültige E-Mail-Adresse.');
            q('UPDATE users SET role=?,status=?,email=? WHERE id=?', [$role, $status, $email, $t['id']]);
            if ($status === 'blocked') {
                q('DELETE FROM sessions WHERE user_id=?', [$t['id']]);
                foreach (qa('SELECT * FROM active_streams WHERE user_id=?', [$t['id']]) as $s) { kill_stream($s); }
                q('DELETE FROM active_streams WHERE user_id=?', [$t['id']]);
            }
            log_activity('admin.user.update', "{$t['username']}: Rolle $role, Status $status");
            out(['ok' => true]);

        case 'POST admin/users/{id}/reset-password':
            $t = load_user((int)$ids[0]);
            if (!can_manage($u, $t)) fail('Keine Berechtigung für diesen Benutzer.', 403);
            $pw = (string)($in['password'] ?? '');
            $generated = false;
            if ($pw === '') { $pw = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12); $generated = true; }
            if ($e = valid_password($pw)) fail($e);
            q('UPDATE users SET password_hash=?,must_change_password=1 WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $t['id']]);
            q('DELETE FROM sessions WHERE user_id=?', [$t['id']]);
            log_activity('admin.user.reset-password', $t['username']);
            out(['ok' => true, 'password' => $generated ? $pw : null]);

        case 'DELETE admin/users/{id}':
            $t = load_user((int)$ids[0]);
            if ((int)$t['id'] === (int)$u['id']) fail('Du kannst dich nicht selbst löschen.');
            if (!can_manage($u, $t)) fail('Keine Berechtigung für diesen Benutzer.', 403);
            if ($t['role'] === 'superadmin' && last_superadmin((int)$t['id'])) fail('Der letzte Superadmin kann nicht gelöscht werden.');
            foreach (qa('SELECT * FROM active_streams WHERE user_id=?', [$t['id']]) as $s) kill_stream($s);
            q('DELETE FROM users WHERE id=?', [$t['id']]);
            log_activity('admin.user.delete', $t['username'], 'warn');
            out(['ok' => true]);

        case 'GET admin/users/{id}/activity':
            $t = load_user((int)$ids[0]);
            $logs = qa('SELECT action,detail,ip,level,created_at FROM activity_logs WHERE user_id=? ORDER BY id DESC LIMIT 100', [$t['id']]);
            $hist = [];
            foreach (qa('SELECT h.kind,h.item_id,h.watched_at,p.name AS profile FROM watch_history h JOIN profiles p ON p.id=h.profile_id WHERE p.user_id=? ORDER BY h.id DESC LIMIT 30', [$t['id']]) as $h)
                $hist[] = ['title' => stream_title($h['kind'], (int)$h['item_id']), 'profile' => $h['profile'], 'watched_at' => $h['watched_at']];
            out(['logs' => $logs, 'history' => $hist]);

        /* ---------- Profile ---------- */
        case 'GET admin/profiles':
            $rows = qa('SELECT p.*,u.username,u.role FROM profiles p JOIN users u ON u.id=p.user_id ORDER BY u.username,p.id');
            out(['profiles' => array_map(fn($p) => ['id' => (int)$p['id'], 'user_id' => (int)$p['user_id'], 'username' => $p['username'], 'name' => $p['name'], 'avatar' => $p['avatar'],
                                                     'is_kids' => (bool)$p['is_kids'], 'has_pin' => !empty($p['pin_hash']), 'manageable' => can_manage($u, ['id' => $p['user_id'], 'role' => $p['role']])], $rows)]);

        case 'POST admin/profiles':
            $t = load_user((int)($in['user_id'] ?? 0));
            if (!can_manage($u, $t)) fail('Keine Berechtigung für diesen Benutzer.', 403);
            if ((int)qv('SELECT COUNT(*) FROM profiles WHERE user_id=?', [$t['id']]) >= 6) fail('Maximal 6 Profile pro Konto.');
            $pid = create_profile((int)$t['id'], (string)($in['name'] ?? ''), (string)($in['avatar'] ?? 'a1'), !empty($in['is_kids']));
            if (isset($in['pin']) && $in['pin'] !== '') set_pin($pid, (string)$in['pin']);
            log_activity('admin.profile.create', "{$t['username']}/" . trim((string)($in['name'] ?? '')));
            out(['ok' => true, 'id' => $pid]);

        case 'PUT admin/profiles/{id}':
        case 'DELETE admin/profiles/{id}':
            $p = q1('SELECT * FROM profiles WHERE id=?', [(int)$ids[0]]);
            if (!$p) fail('Profil nicht gefunden.', 404);
            $t = load_user((int)$p['user_id']);
            if (!can_manage($u, $t)) fail('Keine Berechtigung für diesen Benutzer.', 403);
            if ($method === 'DELETE') {
                if ((int)qv('SELECT COUNT(*) FROM profiles WHERE user_id=?', [$t['id']]) <= 1) fail('Das letzte Profil eines Kontos kann nicht gelöscht werden.');
                q('DELETE FROM profiles WHERE id=?', [$p['id']]);
                log_activity('admin.profile.delete', "{$t['username']}/{$p['name']}", 'warn');
                out(['ok' => true]);
            }
            $name = trim((string)($in['name'] ?? $p['name'])); $av = (string)($in['avatar'] ?? $p['avatar']);
            if ($name === '' || mb_strlen($name) > 30) fail('Profilname muss 1–30 Zeichen lang sein.');
            if (!preg_match('/^(a[1-8]|img:[a-f0-9]{24}\.(jpg|png|webp))$/', $av)) fail('Ungültiges Profilbild.');
            q('UPDATE profiles SET name=?,avatar=?,is_kids=? WHERE id=?', [$name, $av, (int)!empty($in['is_kids'] ?? $p['is_kids']), $p['id']]);
            if (array_key_exists('pin', $in)) {
                $pin = (string)$in['pin'];
                if ($pin !== '' && !preg_match('/^\d{4}$/', $pin)) fail('Die PIN muss aus genau 4 Ziffern bestehen.');
                q('UPDATE profiles SET pin_hash=? WHERE id=?', [$pin === '' ? null : password_hash($pin, PASSWORD_DEFAULT), $p['id']]);
            }
            log_activity('admin.profile.update', "{$t['username']}/$name");
            out(['ok' => true]);

        /* ---------- Medien ---------- */
        case 'GET admin/media':
            $kind = ($_GET['kind'] ?? 'movie') === 'series' ? 'series' : 'movie';
            $r = browse(0, $kind, $_GET + ['per' => 30]);
            out($r);

        case 'GET admin/movies/{id}':
            $m = q1('SELECT * FROM movies WHERE id=?', [$ids[0]]);
            if (!$m) fail('Nicht gefunden.', 404);
            $m['genres'] = implode(', ', array_column(qa('SELECT g.name FROM movie_genres mg JOIN genres g ON g.id=mg.genre_id WHERE mg.movie_id=?', [$m['id']]), 'name'));
            $m['files'] = qa('SELECT path,size,width,height,vcodec,acodec,container FROM media_files WHERE movie_id=?', [$m['id']]);
            out($m);

        case 'PUT admin/movies/{id}':
            $m = q1('SELECT * FROM movies WHERE id=?', [$ids[0]]);
            if (!$m) fail('Nicht gefunden.', 404);
            $title = str_in($in, 'title', 200, $m['title']);
            if (!$title) fail('Titel darf nicht leer sein.');
            q('UPDATE movies SET title=?,overview=?,year=?,runtime=?,age_rating=?,director=?,cast_list=?,poster=?,backdrop=?,credits_offset=? WHERE id=?',
              [$title, str_in($in, 'overview', 4000, $m['overview']), isset($in['year']) && $in['year'] !== '' ? (int)$in['year'] : null, isset($in['runtime']) && $in['runtime'] !== '' ? (int)$in['runtime'] : null,
               str_in($in, 'age_rating', 12, $m['age_rating']), str_in($in, 'director', 120, $m['director']), str_in($in, 'cast_list', 600, $m['cast_list']),
               array_key_exists('poster', $in) ? img_name((string)$in['poster']) : $m['poster'], array_key_exists('backdrop', $in) ? img_name((string)$in['backdrop']) : $m['backdrop'],
               isset($in['credits_offset']) && $in['credits_offset'] !== '' ? (int)$in['credits_offset'] : null, $m['id']]);
            if (isset($in['genres'])) set_genres('movie_genres', 'movie_id', (int)$m['id'], preg_split('/\s*,\s*/', (string)$in['genres'], -1, PREG_SPLIT_NO_EMPTY));
            log_activity('admin.media.update', "Film: $title");
            out(['ok' => true]);

        case 'GET admin/series/{id}':
            $s = q1('SELECT * FROM series WHERE id=?', [$ids[0]]);
            if (!$s) fail('Nicht gefunden.', 404);
            $s['genres'] = implode(', ', array_column(qa('SELECT g.name FROM series_genres sg JOIN genres g ON g.id=sg.genre_id WHERE sg.series_id=?', [$s['id']]), 'name'));
            $s['seasons'] = [];
            foreach (qa('SELECT * FROM seasons WHERE series_id=? ORDER BY number', [$s['id']]) as $se) {
                $se['episodes'] = qa('SELECT id,number,title,overview,runtime FROM episodes WHERE season_id=? ORDER BY number', [$se['id']]);
                $s['seasons'][] = $se;
            }
            out($s);

        case 'PUT admin/series/{id}':
            $s = q1('SELECT * FROM series WHERE id=?', [$ids[0]]);
            if (!$s) fail('Nicht gefunden.', 404);
            $title = str_in($in, 'title', 200, $s['title']);
            if (!$title) fail('Titel darf nicht leer sein.');
            $n = fn($k, $d) => isset($in[$k]) && $in[$k] !== '' ? (int)$in[$k] : (array_key_exists($k, $in) ? null : $d);
            q('UPDATE series SET title=?,overview=?,year=?,age_rating=?,cast_list=?,poster=?,backdrop=?,intro_start=?,intro_end=?,credits_offset=? WHERE id=?',
              [$title, str_in($in, 'overview', 4000, $s['overview']), $n('year', $s['year']), str_in($in, 'age_rating', 12, $s['age_rating']), str_in($in, 'cast_list', 600, $s['cast_list']),
               array_key_exists('poster', $in) ? img_name((string)$in['poster']) : $s['poster'], array_key_exists('backdrop', $in) ? img_name((string)$in['backdrop']) : $s['backdrop'],
               $n('intro_start', $s['intro_start']), $n('intro_end', $s['intro_end']), $n('credits_offset', $s['credits_offset']) ?? 30, $s['id']]);
            if (isset($in['genres'])) set_genres('series_genres', 'series_id', (int)$s['id'], preg_split('/\s*,\s*/', (string)$in['genres'], -1, PREG_SPLIT_NO_EMPTY));
            log_activity('admin.media.update', "Serie: $title");
            out(['ok' => true]);

        case 'PUT admin/seasons/{id}':
            $se = q1('SELECT * FROM seasons WHERE id=?', [$ids[0]]);
            if (!$se) fail('Nicht gefunden.', 404);
            q('UPDATE seasons SET title=?,overview=? WHERE id=?', [str_in($in, 'title', 100, $se['title']), str_in($in, 'overview', 2000, $se['overview']), $se['id']]);
            out(['ok' => true]);

        case 'PUT admin/episodes/{id}':
            $e = q1('SELECT * FROM episodes WHERE id=?', [$ids[0]]);
            if (!$e) fail('Nicht gefunden.', 404);
            q('UPDATE episodes SET title=?,overview=?,runtime=? WHERE id=?', [str_in($in, 'title', 200, $e['title']), str_in($in, 'overview', 2000, $e['overview']), isset($in['runtime']) && $in['runtime'] !== '' ? (int)$in['runtime'] : $e['runtime'], $e['id']]);
            out(['ok' => true]);

        /* ---------- Medienordner ---------- */
        case 'GET admin/folders':
            $need('admin');
            $rows = qa('SELECT f.*, (SELECT COUNT(*) FROM media_files m WHERE m.folder_id=f.id) files FROM media_folders f ORDER BY f.id');
            foreach ($rows as &$r) {
                $r['accessible'] = (bool)(safe_media_path($r['path']) && is_readable($r['path']));
                $r['last'] = q1('SELECT id,status,total,processed,added,updated,removed,errors,started_at,finished_at,heartbeat FROM scan_history WHERE folder_id=? ORDER BY id DESC LIMIT 1', [$r['id']]);
            }
            out(['folders' => $rows, 'media_root' => SF_MEDIA]);

        case 'POST admin/folders/test': $need('admin'); out(test_folder((string)($in['path'] ?? '')));

        case 'GET admin/browse':
            $need('admin');
            $p = safe_media_path((string)($_GET['path'] ?? SF_MEDIA));
            if (!$p || !is_dir($p)) fail('Pfad nicht verfügbar.', 404);
            $dirs = [];
            foreach (scandir($p) ?: [] as $d) if ($d[0] !== '.' && is_dir("$p/$d")) $dirs[] = $d;
            natcasesort($dirs);
            $root = realpath(SF_MEDIA);
            out(['path' => $p, 'parent' => $p === $root ? null : dirname($p), 'dirs' => array_values($dirs)]);

        case 'POST admin/folders':
            $need('admin');
            $t = test_folder((string)($in['path'] ?? ''));
            if (!$t['inside_root'] || !$t['exists'] || !$t['readable']) fail($t['message']);
            $type = in_array($in['type'] ?? '', ['movies', 'series'], true) ? $in['type'] : fail('Typ muss „movies“ oder „series“ sein.');
            if ($t['path'] === realpath(SF_MEDIA)) fail('Bitte einen Unterordner wählen, nicht den gesamten Medienstamm.');
            if ($o = path_overlaps($t['path'])) fail("Überschneidung mit bestehendem Ordner: $o");
            q('INSERT INTO media_folders(path,label,type,auto_scan) VALUES(?,?,?,?)', [$t['path'], str_in($in, 'label', 60, basename($t['path'])), $type, (int)!empty($in['auto_scan'])]);
            $id = (int)db()->lastInsertId();
            log_activity('admin.folder.add', "{$t['path']} ($type)");
            out(['ok' => true, 'id' => $id, 'test' => $t]);

        case 'PUT admin/folders/{id}':
            $need('admin');
            $f = q1('SELECT * FROM media_folders WHERE id=?', [$ids[0]]);
            if (!$f) fail('Ordner nicht gefunden.', 404);
            $path = $f['path'];
            if (isset($in['path']) && $in['path'] !== $f['path']) {
                $t = test_folder((string)$in['path']);
                if (!$t['inside_root'] || !$t['exists'] || !$t['readable']) fail($t['message']);
                if ($o = path_overlaps($t['path'], (int)$f['id'])) fail("Überschneidung mit bestehendem Ordner: $o");
                $path = $t['path'];
            }
            $type = in_array($in['type'] ?? $f['type'], ['movies', 'series'], true) ? ($in['type'] ?? $f['type']) : $f['type'];
            q('UPDATE media_folders SET path=?,label=?,type=?,auto_scan=?,enabled=? WHERE id=?',
              [$path, str_in($in, 'label', 60, $f['label']), $type, (int)($in['auto_scan'] ?? $f['auto_scan']), (int)($in['enabled'] ?? $f['enabled']), $f['id']]);
            log_activity('admin.folder.update', $path);
            out(['ok' => true, 'rescan_recommended' => $path !== $f['path'] || $type !== $f['type']]);

        case 'DELETE admin/folders/{id}':
            $need('admin');
            $f = q1('SELECT * FROM media_folders WHERE id=?', [$ids[0]]);
            if (!$f) fail('Ordner nicht gefunden.', 404);
            if (qv("SELECT COUNT(*) FROM scan_history WHERE folder_id=? AND status='running' AND heartbeat > ?", [$f['id'], time() - 180])) fail('Für diesen Ordner läuft gerade ein Scan.', 409);
            foreach (qa('SELECT s.* FROM active_streams s JOIN media_files m ON m.id=s.media_file_id WHERE m.folder_id=?', [$f['id']]) as $s) { kill_stream($s); }
            q('DELETE FROM media_folders WHERE id=?', [$f['id']]);
            cleanup_orphans();
            log_activity('admin.folder.delete', $f['path'] . ' (Dateien bleiben unverändert)', 'warn');
            out(['ok' => true]);

        case 'POST admin/folders/{id}/scan':
            $need('admin');
            $hid = start_scan_async((int)$ids[0]);
            log_activity('admin.scan.start', (string)qv('SELECT path FROM media_folders WHERE id=?', [$ids[0]]));
            out(['ok' => true, 'history_id' => $hid]);

        case 'GET admin/scans':
            $need('admin');
            q("UPDATE scan_history SET status='error', errors=COALESCE(NULLIF(errors,''),'[\"Scan abgebrochen (keine Aktivität).\"]'), finished_at=datetime('now') WHERE status='running' AND heartbeat < ?", [time() - 300]);
            out(['scans' => qa('SELECT * FROM scan_history ORDER BY id DESC LIMIT 25')]);

        /* ---------- Streams ---------- */
        case 'GET admin/streams':
            cleanup_streams();
            $rows = qa("SELECT s.token,s.mode,s.ip,s.title,s.bitrate,s.started_at,s.position,s.kind,u.username,p.name AS profile FROM active_streams s JOIN users u ON u.id=s.user_id JOIN profiles p ON p.id=s.profile_id WHERE s.killed=0 ORDER BY s.started_at");
            out(['streams' => $rows]);

        case 'POST admin/streams/{id}/kill':
            $need('admin');
            $s = q1('SELECT * FROM active_streams WHERE token=?', [$ids[0]]);
            if (!$s) fail('Stream nicht gefunden.', 404);
            q('UPDATE active_streams SET killed=1 WHERE token=?', [$s['token']]);
            kill_stream($s);
            log_activity('admin.stream.kill', (string)$s['title'], 'warn');
            out(['ok' => true]);

        /* ---------- Einstellungen ---------- */
        case 'GET admin/settings':
            $need('admin');
            $o = []; foreach (qa('SELECT key,value FROM system_settings') as $r) $o[$r['key']] = $r['value'];
            out(['settings' => $o]);

        case 'PUT admin/settings':
            $need('admin');
            $rules = [
                'platform_name' => fn($v) => mb_strlen($v) >= 1 && mb_strlen($v) <= 40, 'accent_color' => fn($v) => (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $v),
                'registration_enabled' => fn($v) => in_array($v, ['0', '1'], true), 'max_users' => fn($v) => ctype_digit($v) && (int)$v <= 10000,
                'max_streams' => fn($v) => ctype_digit($v) && (int)$v <= 64, 'max_transcodes' => fn($v) => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 4,
                'default_language' => fn($v) => in_array($v, ['de', 'en'], true), 'default_subtitle' => fn($v) => (bool)preg_match('/^(off|[a-z]{2,3})$/', $v),
                'video_quality' => fn($v) => in_array($v, ['0', '480', '720', '1080'], true), 'cache_max_mb' => fn($v) => ctype_digit($v) && (int)$v >= 128 && (int)$v <= 200000,
                'auto_scan_enabled' => fn($v) => in_array($v, ['0', '1'], true), 'auto_scan_hours' => fn($v) => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 168,
                'maintenance_mode' => fn($v) => in_array($v, ['0', '1'], true), 'backup_auto' => fn($v) => in_array($v, ['0', '1'], true),
                'backup_hours' => fn($v) => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 720, 'backup_keep' => fn($v) => ctype_digit($v) && (int)$v >= 1 && (int)$v <= 60,
                'logo' => fn($v) => $v === '' || img_name($v) !== null, 'login_bg' => fn($v) => $v === '' || img_name($v) !== null,
            ];
            $changed = [];
            foreach ($in as $k => $v) {
                if (!isset($rules[$k])) continue;
                $v = is_bool($v) ? ($v ? '1' : '0') : trim((string)$v);
                if (!$rules[$k]($v)) fail("Ungültiger Wert für „{$k}“.");
                set_setting($k, $v); $changed[] = $k;
            }
            log_activity('admin.settings', implode(', ', $changed));
            out(['ok' => true]);

        /* ---------- Protokoll ---------- */
        case 'GET admin/logs':
            $need('admin');
            $w = ['1=1']; $p = [];
            if (!empty($_GET['level']) && in_array($_GET['level'], ['info', 'warn', 'error'], true)) { $w[] = 'level=?'; $p[] = $_GET['level']; }
            if (!empty($_GET['action'])) { $w[] = 'action LIKE ?'; $p[] = $_GET['action'] . '%'; }
            if (!empty($_GET['q'])) { $l = like_escape((string)$_GET['q']); $w[] = "(detail LIKE ? ESCAPE '\\' OR username LIKE ? ESCAPE '\\' OR ip LIKE ? ESCAPE '\\' OR action LIKE ? ESCAPE '\\')"; array_push($p, $l, $l, $l, $l); }
            $per = 50; $page = max(1, (int)($_GET['page'] ?? 1)); $ws = implode(' AND ', $w);
            $total = (int)qv("SELECT COUNT(*) FROM activity_logs WHERE $ws", $p);
            out(['logs' => qa("SELECT * FROM activity_logs WHERE $ws ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p), 'total' => $total, 'page' => $page, 'per' => $per]);

        /* ---------- Backups ---------- */
        case 'GET admin/backups': $need('admin'); out(['backups' => qa('SELECT * FROM backups ORDER BY id DESC')]);
        case 'POST admin/backups': $need('admin'); out(['ok' => true] + create_backup('manual', (string)($in['note'] ?? '')));
        case 'GET admin/backups/{id}/download':
            $need('admin');
            $b = q1('SELECT * FROM backups WHERE id=?', [$ids[0]]);
            if (!$b) fail('Backup nicht gefunden.', 404);
            $p = backup_path($b);
            if (!is_file($p)) fail('Datei fehlt.', 404);
            log_activity('backup.download', $b['filename']);
            header('Content-Type: application/zip'); header('Content-Length: ' . filesize($p));
            header('Content-Disposition: attachment; filename="' . $b['filename'] . '"');
            readfile($p); exit;
        case 'POST admin/backups/{id}/restore':
            $need('superadmin');
            if (($in['confirm'] ?? '') !== 'WIEDERHERSTELLEN') fail('Bestätigung fehlt.');
            try { restore_backup((int)$ids[0]); } catch (Throwable $e) { fail('Wiederherstellung fehlgeschlagen: ' . $e->getMessage(), 500); }
            out(['ok' => true]);
        case 'DELETE admin/backups/{id}':
            $need('admin');
            $b = q1('SELECT * FROM backups WHERE id=?', [$ids[0]]);
            if (!$b) fail('Backup nicht gefunden.', 404);
            @unlink(backup_path($b)); q('DELETE FROM backups WHERE id=?', [$b['id']]);
            log_activity('backup.delete', $b['filename'], 'warn');
            out(['ok' => true]);
    }
}
