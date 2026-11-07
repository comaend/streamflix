<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const VIDEO_EXT = ['mp4', 'mkv', 'webm', 'avi', 'mov', 'ts', 'm4v'];
const SUB_EXT = ['srt', 'vtt', 'ass', 'ssa'];
const TEXT_SUB_CODECS = ['subrip', 'srt', 'ass', 'ssa', 'webvtt', 'mov_text', 'text'];
const LANG_NAMES = [
    'de' => 'Deutsch', 'ger' => 'Deutsch', 'deu' => 'Deutsch', 'en' => 'English', 'eng' => 'English',
    'fr' => 'Français', 'fre' => 'Français', 'fra' => 'Français', 'es' => 'Español', 'spa' => 'Español',
    'it' => 'Italiano', 'ita' => 'Italiano', 'tr' => 'Türkçe', 'tur' => 'Türkçe', 'ru' => 'Русский', 'rus' => 'Русский',
    'ja' => '日本語', 'jpn' => '日本語', 'pl' => 'Polski', 'pol' => 'Polski', 'nl' => 'Nederlands', 'nld' => 'Nederlands',
];
const GENRE_MAP = [
    'action' => 'Action', 'adventure' => 'Abenteuer', 'abenteuer' => 'Abenteuer', 'science fiction' => 'Science-Fiction',
    'sci-fi' => 'Science-Fiction', 'science-fiction' => 'Science-Fiction', 'scifi' => 'Science-Fiction', 'sci-fi & fantasy' => 'Science-Fiction',
    'horror' => 'Horror', 'animation' => 'Animation', 'anime' => 'Animation', 'zeichentrick' => 'Animation',
    'documentary' => 'Dokumentation', 'dokumentation' => 'Dokumentation', 'dokumentarfilm' => 'Dokumentation', 'doku' => 'Dokumentation',
    'documentaries' => 'Dokumentation', 'dokumentationen' => 'Dokumentation',
    'comedy' => 'Komödie', 'komödie' => 'Komödie', 'drama' => 'Drama', 'thriller' => 'Thriller', 'crime' => 'Krimi', 'krimi' => 'Krimi',
    'fantasy' => 'Fantasy', 'family' => 'Familie', 'familie' => 'Familie', 'romance' => 'Romantik', 'romantik' => 'Romantik',
    'mystery' => 'Mystery', 'war' => 'Krieg', 'krieg' => 'Krieg', 'history' => 'Historie', 'music' => 'Musik', 'western' => 'Western',
];

function lang_label(?string $code): string {
    $c = strtolower((string)$code);
    return LANG_NAMES[$c] ?? ($c !== '' && $c !== 'und' ? strtoupper($c) : 'Unbekannt');
}
function norm_genre(string $g): ?string {
    $k = mb_strtolower(trim($g));
    if ($k === '') return null;
    return GENRE_MAP[$k] ?? mb_convert_case($k, MB_CASE_TITLE);
}

/* ---------- Dateinamen ---------- */
function clean_title(string $name): array {
    $year = null;
    if (preg_match('/[\s.(\[_-]((?:19|20)\d{2})(?=[\s.)\]_-]|$)/', $name, $m, PREG_OFFSET_CAPTURE)) {
        $year = (int)$m[1][0];
        $name = substr($name, 0, $m[0][1]);
    }
    $name = preg_replace('/\b(2160p|1080p|720p|480p|bluray|blu-ray|brrip|bdrip|webrip|web-dl|hdtv|x264|x265|h264|h265|hevc|dvdrip|german|multi|remux)\b.*$/i', '', $name);
    $name = trim(preg_replace('/\s+/', ' ', str_replace(['.', '_'], ' ', $name)), " -[]()");
    return [$name !== '' ? $name : 'Unbenannt', $year];
}

function parse_episode(string $rel): ?array {
    $file = pathinfo($rel, PATHINFO_FILENAME);
    $dirs = explode('/', trim(dirname($rel), './'));
    $dirs = array_values(array_filter($dirs, fn($d) => $d !== ''));
    $season = null; $ep = null; $showFromName = null; $title = null;
    if (preg_match('/^(.*?)[\s._-]*S(\d{1,2})[\s._-]?E(\d{1,3})(?:[\s._-]?E\d{1,3})?[\s._-]*(.*)$/i', $file, $m)) {
        $showFromName = $m[1]; $season = (int)$m[2]; $ep = (int)$m[3]; $title = $m[4];
    } elseif (preg_match('/^(.*?)[\s._-]*(\d{1,2})x(\d{2,3})[\s._-]*(.*)$/i', $file, $m)) {
        $showFromName = $m[1]; $season = (int)$m[2]; $ep = (int)$m[3]; $title = $m[4];
    }
    if ($season === null) {
        foreach (array_reverse($dirs) as $d) {
            if (preg_match('/^(?:season|staffel|s)[\s._-]*(\d{1,2})$/i', $d, $m)) { $season = (int)$m[1]; break; }
        }
        if (preg_match('/^(?:e|ep|episode|folge)?[\s._-]*(\d{1,3})(?:[\s._-]+(.*))?$/i', $file, $m)) { $ep = (int)$m[1]; $title = $m[2] ?? ''; }
    }
    if ($ep === null) return null;
    return ['season' => $season ?? 1, 'episode' => $ep, 'show' => $showFromName, 'title' => $title];
}

/* ---------- ffprobe ---------- */
function probe_file(string $path): ?array {
    $json = sh_run('ffprobe -v error -print_format json -show_format -show_streams -show_chapters ' . escapeshellarg($path), 90);
    $d = json_decode($json, true);
    if (!is_array($d) || empty($d['streams'])) return null;
    $r = ['duration' => (float)($d['format']['duration'] ?? 0), 'bitrate' => (int)($d['format']['bit_rate'] ?? 0),
          'vcodec' => null, 'vprofile' => null, 'pix_fmt' => null, 'width' => null, 'height' => null, 'acodec' => null,
          'audio' => [], 'subs' => [], 'intro' => [null, null], 'credits' => null];
    $ai = 0;
    foreach ($d['streams'] as $s) {
        $t = $s['codec_type'] ?? '';
        if ($t === 'video' && $r['vcodec'] === null && ($s['disposition']['attached_pic'] ?? 0) == 0) {
            $r['vcodec'] = $s['codec_name'] ?? null; $r['vprofile'] = $s['profile'] ?? null; $r['pix_fmt'] = $s['pix_fmt'] ?? null;
            $r['width'] = $s['width'] ?? null; $r['height'] = $s['height'] ?? null;
        } elseif ($t === 'audio') {
            if ($r['acodec'] === null) $r['acodec'] = $s['codec_name'] ?? null;
            $lang = $s['tags']['language'] ?? 'und';
            $r['audio'][] = ['i' => $ai++, 'codec' => $s['codec_name'] ?? '', 'ch' => (int)($s['channels'] ?? 2), 'lang' => $lang,
                             'label' => trim(lang_label($lang) . ' · ' . strtoupper($s['codec_name'] ?? '') . ' ' . ($s['channels'] ?? '') . 'ch'),
                             'default' => (int)($s['disposition']['default'] ?? 0)];
        } elseif ($t === 'subtitle' && in_array($s['codec_name'] ?? '', TEXT_SUB_CODECS, true)) {
            $lang = $s['tags']['language'] ?? 'und';
            $r['subs'][] = ['index' => (int)$s['index'], 'lang' => $lang, 'label' => ($s['tags']['title'] ?? '') ?: lang_label($lang)];
        }
    }
    if ($r['vcodec'] === null) return null;
    foreach ($d['chapters'] ?? [] as $c) {
        $title = (string)($c['tags']['title'] ?? '');
        if (preg_match('/\b(intro|opening|op)\b/i', $title)) { $r['intro'] = [(int)floor((float)$c['start_time']), (int)ceil((float)$c['end_time'])]; }
        if (preg_match('/\b(credits|ending|outro|ed)\b/i', $title)) { $r['credits'] = (int)floor((float)$c['start_time']); }
    }
    return $r;
}

/* ---------- NFO & Bilder ---------- */
function read_nfo(string $file): ?SimpleXMLElement {
    if (!is_file($file) || filesize($file) > 300000) return null;
    $x = @simplexml_load_string((string)file_get_contents($file), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    return $x ?: null;
}
function nfo_data(?SimpleXMLElement $x): array {
    if (!$x) return [];
    $genres = [];
    foreach ($x->genre ?? [] as $g) { foreach (preg_split('/[\/,]/', (string)$g) as $one) { $n = norm_genre($one); if ($n) $genres[] = $n; } }
    $cast = [];
    foreach ($x->actor ?? [] as $a) { $n = trim((string)$a->name); if ($n !== '') $cast[] = $n; }
    $runtime = (int)$x->runtime;
    return ['title' => trim((string)$x->title), 'overview' => trim((string)($x->plot ?: $x->outline)),
            'year' => (int)$x->year ?: null, 'age' => trim((string)$x->mpaa) ?: null, 'director' => trim((string)$x->director) ?: null,
            'genres' => $genres, 'cast' => implode(', ', array_slice($cast, 0, 12)), 'runtime' => $runtime ?: null];
}
function import_image(string $src): ?string {
    $rp = safe_media_path($src);
    if (!$rp || !is_file($rp) || filesize($rp) > 12 * 1048576) return null;
    $ext = strtolower(pathinfo($rp, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) return null;
    $name = substr(sha1($rp . filemtime($rp)), 0, 20) . '.' . $ext;
    $dst = SF_DATA . '/images/' . $name;
    if (!is_file($dst) && !@copy($rp, $dst)) return null;
    return $name;
}
function find_image(string $dir, array $bases): ?string {
    foreach ($bases as $b) foreach (['jpg', 'jpeg', 'png', 'webp'] as $e) {
        $f = $dir . '/' . $b . '.' . $e;
        if (is_file($f)) return import_image($f);
    }
    return null;
}
function make_thumb(string $video, float $duration, string $outName, string $vf): ?string {
    $dst = SF_DATA . '/images/' . $outName;
    if (is_file($dst)) return $outName;
    $t = $duration > 60 ? min($duration * 0.15, 900) : max($duration * 0.3, 0);
    sh_run('nice -n 15 ffmpeg -nostdin -y -loglevel error -ss ' . (float)$t . ' -i ' . escapeshellarg($video) .
           ' -frames:v 1 -vf ' . escapeshellarg($vf) . ' -q:v 5 ' . escapeshellarg($dst), 60);
    return is_file($dst) && filesize($dst) > 0 ? $outName : null;
}
const VF_POSTER = 'scale=-2:600,crop=400:600';
const VF_BACKDROP = 'scale=1280:-2';
const VF_STILL = 'scale=480:-2';

/* ---------- Genres ---------- */
function set_genres(string $table, string $col, int $id, array $names): void {
    q("DELETE FROM $table WHERE $col=?", [$id]);
    foreach (array_unique($names) as $n) {
        $n = norm_genre($n); if (!$n) continue;
        q('INSERT OR IGNORE INTO genres(name) VALUES(?)', [$n]);
        $gid = (int)qv('SELECT id FROM genres WHERE name=?', [$n]);
        q("INSERT OR IGNORE INTO $table($col,genre_id) VALUES(?,?)", [$id, $gid]);
    }
}
function add_genres(string $table, string $col, int $id, array $names): void {
    foreach (array_unique($names) as $n) {
        $n = norm_genre($n); if (!$n) continue;
        q('INSERT OR IGNORE INTO genres(name) VALUES(?)', [$n]);
        $gid = (int)qv('SELECT id FROM genres WHERE name=?', [$n]);
        q("INSERT OR IGNORE INTO $table($col,genre_id) VALUES(?,?)", [$id, $gid]);
    }
}
function path_genres(string $folderPath, string $rel): array {
    $parts = array_merge([basename($folderPath)], explode('/', dirname($rel)));
    $g = [];
    foreach ($parts as $p) {
        $k = mb_strtolower(trim($p));
        if (in_array($k, ['anime', 'animation', 'zeichentrick', 'documentaries', 'documentary', 'dokumentationen', 'dokumentation', 'doku'], true)) $g[] = GENRE_MAP[$k];
    }
    return $g;
}

/* ---------- Medien-Datei speichern ---------- */
function store_media_file(array $folder, string $path, array $pr, string $kind, int $itemId, int $size, int $mtime): int {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $existing = q1('SELECT id FROM media_files WHERE path=?', [$path]);
    $vals = [$size, $mtime, $pr['duration'], $pr['width'], $pr['height'], $ext, $pr['vcodec'], $pr['vprofile'], $pr['pix_fmt'], $pr['acodec'],
             $pr['bitrate'], json_encode($pr['audio']), $pr['intro'][0], $pr['intro'][1], $pr['credits']];
    if ($existing) {
        q('UPDATE media_files SET size=?,mtime=?,duration=?,width=?,height=?,container=?,vcodec=?,vprofile=?,pix_fmt=?,acodec=?,bitrate=?,audio_tracks=?,intro_start=?,intro_end=?,credits_start=?,seen=1 WHERE id=?',
          array_merge($vals, [$existing['id']]));
        $id = (int)$existing['id'];
    } else {
        q('INSERT INTO media_files(size,mtime,duration,width,height,container,vcodec,vprofile,pix_fmt,acodec,bitrate,audio_tracks,intro_start,intro_end,credits_start,path,folder_id,movie_id,episode_id,seen) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)',
          array_merge($vals, [$path, $folder['id'], $kind === 'movie' ? $itemId : null, $kind === 'episode' ? $itemId : null]));
        $id = (int)db()->lastInsertId();
    }
    // Untertitel neu aufbauen
    q('DELETE FROM subtitles WHERE media_file_id=?', [$id]);
    foreach ($pr['subs'] as $s) {
        q("INSERT INTO subtitles(media_file_id,lang,label,kind,stream_index) VALUES(?,?,?,'embedded',?)", [$id, $s['lang'], $s['label'], $s['index']]);
    }
    $dir = dirname($path); $base = pathinfo($path, PATHINFO_FILENAME);
    foreach ([$dir, $dir . '/Subs', $dir . '/Subtitles', $dir . '/subs'] as $sd) {
        if (!is_dir($sd)) continue;
        foreach (scandir($sd) ?: [] as $f) {
            $e = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (!in_array($e, SUB_EXT, true) || !str_starts_with($f, $base)) continue;
            $rest = substr(pathinfo($f, PATHINFO_FILENAME), strlen($base));
            $lang = 'und';
            if (preg_match('/[._-]([a-z]{2,3})(?:[._-]|$)/i', $rest, $m)) $lang = strtolower($m[1]);
            q("INSERT INTO subtitles(media_file_id,lang,label,kind,path) VALUES(?,?,?,'external',?)", [$id, $lang, lang_label($lang), $sd . '/' . $f]);
        }
    }
    return $id;
}

/* ---------- Film / Episode anlegen ---------- */
function handle_movie(array $folder, string $path, string $rel, array $pr, int $size, int $mtime): bool {
    $row = q1('SELECT movie_id FROM media_files WHERE path=?', [$path]);
    $isNew = !$row || !$row['movie_id'];
    $dir = dirname($path); $base = pathinfo($path, PATHINFO_FILENAME);
    if ($isNew) {
        [$title, $year] = clean_title($base);
        $nfo = nfo_data(read_nfo($dir . '/' . $base . '.nfo') ?: read_nfo($dir . '/movie.nfo'));
        $title = $nfo['title'] ?? '' ?: $title;
        q('INSERT INTO movies(title,overview,year,runtime,age_rating,director,cast_list,folder_id) VALUES(?,?,?,?,?,?,?,?)',
          [$title, $nfo['overview'] ?? null, ($nfo['year'] ?? null) ?: $year, ($nfo['runtime'] ?? null) ?: (int)round($pr['duration'] / 60),
           $nfo['age'] ?? null, $nfo['director'] ?? null, $nfo['cast'] ?? null, $folder['id']]);
        $mid = (int)db()->lastInsertId();
        add_genres('movie_genres', 'movie_id', $mid, array_merge($nfo['genres'] ?? [], path_genres($folder['path'], $rel)));
        $poster = find_image($dir, [$base . '-poster', $base, 'poster', 'folder', 'cover']);
        $back = find_image($dir, [$base . '-fanart', $base . '-backdrop', 'fanart', 'backdrop', 'background']);
        if (!$poster) $poster = make_thumb($path, $pr['duration'], "gen_m{$mid}_p.jpg", VF_POSTER);
        if (!$back) $back = make_thumb($path, $pr['duration'], "gen_m{$mid}_b.jpg", VF_BACKDROP);
        q('UPDATE movies SET poster=?,backdrop=? WHERE id=?', [$poster, $back, $mid]);
    } else { $mid = (int)$row['movie_id']; }
    store_media_file($folder, $path, $pr, 'movie', $mid, $size, $mtime);
    return $isNew;
}

function handle_episode(array $folder, string $path, string $rel, array $pr, int $size, int $mtime): bool {
    $row = q1('SELECT episode_id FROM media_files WHERE path=?', [$path]);
    $isNew = !$row || !$row['episode_id'];
    if (!$isNew) { store_media_file($folder, $path, $pr, 'episode', (int)$row['episode_id'], $size, $mtime); return false; }

    $info = parse_episode($rel);
    $parts = explode('/', $rel);
    $showDir = count($parts) > 1 ? $folder['path'] . '/' . $parts[0] : null;
    $showName = count($parts) > 1 ? $parts[0] : ($info['show'] ?? '');
    [$showName, $showYear] = clean_title($showName !== '' ? $showName : basename($folder['path']));
    $key = $folder['id'] . '|' . mb_strtolower($showName);
    $sid = qv('SELECT id FROM series WHERE dir_key=?', [$key]);
    if (!$sid) {
        $nfo = $showDir ? nfo_data(read_nfo($showDir . '/tvshow.nfo')) : [];
        q('INSERT INTO series(title,overview,year,age_rating,cast_list,folder_id,dir_key) VALUES(?,?,?,?,?,?,?)',
          [($nfo['title'] ?? '') ?: $showName, $nfo['overview'] ?? null, ($nfo['year'] ?? null) ?: $showYear, $nfo['age'] ?? null, $nfo['cast'] ?? null, $folder['id'], $key]);
        $sid = (int)db()->lastInsertId();
        add_genres('series_genres', 'series_id', $sid, array_merge($nfo['genres'] ?? [], path_genres($folder['path'], $rel)));
        if ($showDir) {
            $p = find_image($showDir, ['poster', 'folder', 'cover', 'show']);
            $b = find_image($showDir, ['fanart', 'backdrop', 'background']);
            q('UPDATE series SET poster=?,backdrop=? WHERE id=?', [$p, $b, $sid]);
        }
    }
    $sid = (int)$sid;
    $seasonNo = $info['season'] ?? 1;
    if ($info === null) {
        $epNo = (int)qv('SELECT COALESCE(MAX(e.number),0)+1 FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE s.series_id=? AND s.number=1', [$sid]);
        $info = ['season' => 1, 'episode' => $epNo, 'title' => clean_title(pathinfo($rel, PATHINFO_FILENAME))[0]];
    }
    q('INSERT OR IGNORE INTO seasons(series_id,number,title) VALUES(?,?,?)', [$sid, $seasonNo, $seasonNo === 0 ? 'Specials' : 'Staffel ' . $seasonNo]);
    $seasonId = (int)qv('SELECT id FROM seasons WHERE series_id=? AND number=?', [$sid, $seasonNo]);
    $eid = qv('SELECT id FROM episodes WHERE season_id=? AND number=?', [$seasonId, $info['episode']]);
    if (!$eid) {
        $base = pathinfo($path, PATHINFO_FILENAME);
        $nfo = nfo_data(read_nfo(dirname($path) . '/' . $base . '.nfo'));
        $title = ($nfo['title'] ?? '') ?: trim(str_replace(['.', '_'], ' ', (string)($info['title'] ?? '')));
        $title = preg_replace('/\b(2160p|1080p|720p|480p|web-?dl|bluray|x26[45]|h26[45]|hevc).*$/i', '', $title);
        q('INSERT INTO episodes(series_id,season_id,number,title,overview,runtime) VALUES(?,?,?,?,?,?)',
          [$sid, $seasonId, $info['episode'], trim($title) ?: 'Episode ' . $info['episode'], $nfo['overview'] ?? null, (int)round($pr['duration'] / 60)]);
        $eid = (int)db()->lastInsertId();
        $still = find_image(dirname($path), [$base . '-thumb', $base]) ?: make_thumb($path, $pr['duration'], "gen_e{$eid}.jpg", VF_STILL);
        q('UPDATE episodes SET still=? WHERE id=?', [$still, $eid]);
        // Serienbild aus erster Episode erzeugen, falls keines vorhanden
        $s = q1('SELECT poster,backdrop FROM series WHERE id=?', [$sid]);
        if (!$s['poster']) q('UPDATE series SET poster=? WHERE id=?', [make_thumb($path, $pr['duration'], "gen_s{$sid}_p.jpg", VF_POSTER), $sid]);
        if (!$s['backdrop']) q('UPDATE series SET backdrop=? WHERE id=?', [make_thumb($path, $pr['duration'], "gen_s{$sid}_b.jpg", VF_BACKDROP), $sid]);
    }
    store_media_file($folder, $path, $pr, 'episode', (int)$eid, $size, $mtime);
    return true;
}

/* ---------- Aufräumen ---------- */
function cleanup_orphans(): void {
    q('DELETE FROM episodes WHERE id NOT IN (SELECT episode_id FROM media_files WHERE episode_id IS NOT NULL)');
    q('DELETE FROM seasons WHERE id NOT IN (SELECT season_id FROM episodes)');
    q('DELETE FROM series WHERE id NOT IN (SELECT series_id FROM episodes)');
    q('DELETE FROM movies WHERE id NOT IN (SELECT movie_id FROM media_files WHERE movie_id IS NOT NULL)');
    q("DELETE FROM watch_progress WHERE (kind='movie' AND item_id NOT IN (SELECT id FROM movies)) OR (kind='episode' AND item_id NOT IN (SELECT id FROM episodes))");
    q("DELETE FROM watch_history WHERE (kind='movie' AND item_id NOT IN (SELECT id FROM movies)) OR (kind='episode' AND item_id NOT IN (SELECT id FROM episodes))");
    q("DELETE FROM watchlist WHERE (kind='movie' AND item_id NOT IN (SELECT id FROM movies)) OR (kind='series' AND item_id NOT IN (SELECT id FROM series))");
    q("DELETE FROM ratings WHERE (kind='movie' AND item_id NOT IN (SELECT id FROM movies)) OR (kind='series' AND item_id NOT IN (SELECT id FROM series))");
    q('DELETE FROM genres WHERE id NOT IN (SELECT genre_id FROM movie_genres) AND id NOT IN (SELECT genre_id FROM series_genres)');
}

/* ---------- Scan ---------- */
function run_scan(int $folderId, int $historyId): void {
    $folder = q1('SELECT * FROM media_folders WHERE id=?', [$folderId]);
    $errors = [];
    $upd = function (array $f) use ($historyId) {
        $sets = []; $vals = [];
        foreach ($f as $k => $v) { $sets[] = "$k=?"; $vals[] = $v; }
        $vals[] = $historyId;
        q('UPDATE scan_history SET ' . implode(',', $sets) . ' WHERE id=?', $vals);
    };
    if (!$folder) { $upd(['status' => 'error', 'errors' => json_encode(['Ordner nicht gefunden']), 'finished_at' => gmdate('Y-m-d H:i:s')]); return; }
    $root = safe_media_path($folder['path']);
    if (!$root || !is_dir($root) || !is_readable($root)) {
        $upd(['status' => 'error', 'errors' => json_encode(['Ordner nicht lesbar oder nicht vorhanden (Laufwerk getrennt?). Es wurde nichts gelöscht.']), 'finished_at' => gmdate('Y-m-d H:i:s')]);
        return;
    }
    $folder['path'] = $root;
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn($cur) => $cur->getFilename()[0] !== '.' && !$cur->isLink()),
        RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $name = $f->getFilename();
        if (!in_array(strtolower($f->getExtension()), VIDEO_EXT, true)) continue;
        if (preg_match('/(^|[ ._-])sample([ ._-]|\.)/i', $name)) continue;
        $files[] = $f->getPathname();
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    $upd(['total' => count($files), 'heartbeat' => time()]);
    q('UPDATE media_files SET seen=0 WHERE folder_id=?', [$folderId]);

    $added = $updated = $i = 0;
    foreach ($files as $path) {
        $i++;
        try {
            $size = (int)filesize($path); $mtime = (int)filemtime($path);
            $ex = q1('SELECT id,size,mtime FROM media_files WHERE path=?', [$path]);
            if ($ex && (int)$ex['size'] === $size && (int)$ex['mtime'] === $mtime) {
                q('UPDATE media_files SET seen=1 WHERE id=?', [$ex['id']]);
            } else {
                $pr = probe_file($path);
                if (!$pr) { $errors[] = 'Nicht lesbar (ffprobe): ' . substr($path, strlen($root) + 1); }
                else {
                    $rel = substr($path, strlen($root) + 1);
                    // Kein Transaktions-Wrapper: Thumbnails (ffmpeg) sollen die DB nicht blockieren.
                    // Halb angelegte Einträge entfernt cleanup_orphans() am Scan-Ende.
                    $new = $folder['type'] === 'movies' ? handle_movie($folder, $path, $rel, $pr, $size, $mtime)
                                                        : handle_episode($folder, $path, $rel, $pr, $size, $mtime);
                    $new ? $added++ : $updated++;
                }
            }
        } catch (Throwable $e) {
            $errors[] = substr($path, strlen($root) + 1) . ': ' . $e->getMessage();
        }
        if ($i % 3 === 0 || $i === count($files)) $upd(['processed' => $i, 'added' => $added, 'updated' => $updated, 'heartbeat' => time(), 'errors' => json_encode(array_slice($errors, 0, 50))]);
    }
    $removed = (int)qv('SELECT COUNT(*) FROM media_files WHERE folder_id=? AND seen=0', [$folderId]);
    q('DELETE FROM media_files WHERE folder_id=? AND seen=0', [$folderId]);
    cleanup_orphans();
    q("UPDATE media_folders SET last_scan=datetime('now') WHERE id=?", [$folderId]);
    $upd(['status' => 'done', 'processed' => count($files), 'added' => $added, 'updated' => $updated, 'removed' => $removed,
          'errors' => json_encode(array_slice($errors, 0, 50)), 'finished_at' => gmdate('Y-m-d H:i:s'), 'heartbeat' => time()]);
    log_activity('scan.done', "{$folder['path']}: +$added neu, $updated aktualisiert, -$removed entfernt, " . count($errors) . ' Fehler');
}

/** Startet einen Scan im Hintergrund. Gibt die History-ID zurück. */
function start_scan_async(int $folderId): int {
    $busy = qv("SELECT COUNT(*) FROM scan_history WHERE status='running' AND heartbeat > ?", [time() - 180]);
    if ($busy) fail('Es läuft bereits ein Scan.', 409);
    $f = q1('SELECT path FROM media_folders WHERE id=?', [$folderId]);
    if (!$f) fail('Ordner nicht gefunden.', 404);
    q("UPDATE scan_history SET status='error' WHERE status='running'");
    q("INSERT INTO scan_history(folder_id,folder_path,status,heartbeat) VALUES(?,?,'running',?)", [$folderId, $f['path'], time()]);
    $hid = (int)db()->lastInsertId();
    $cmd = 'nice -n 10 php ' . escapeshellarg(SF_ROOT . '/scripts/scan.php') . ' ' . (int)$folderId . ' ' . $hid . ' > /dev/null 2>&1 &';
    exec($cmd);
    return $hid;
}
