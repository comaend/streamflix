<?php
declare(strict_types=1);

const KIDS_RATINGS = ['0', '6', '12', 'FSK 0', 'FSK 6', 'FSK 12', 'FSK0', 'FSK6', 'FSK12', 'G', 'PG', 'U', 'TV-Y', 'TV-Y7', 'TV-G', 'TV-PG'];
const HOME_GENRES = ['Action', 'Abenteuer', 'Science-Fiction', 'Horror', 'Animation', 'Dokumentation'];

function img(?string $n): ?string { return $n ? '/img/' . rawurlencode($n) : null; }

function is_kids(int $pid): bool { return (bool)qv('SELECT is_kids FROM profiles WHERE id=?', [$pid]); }
function kids_sql(int $pid, string $col = 'age_rating'): string {
    if (!is_kids($pid)) return '';
    return " AND $col IN ('" . implode("','", KIDS_RATINGS) . "')";
}

function card(array $r, string $kind): array {
    return ['kind' => $kind, 'id' => (int)$r['id'], 'title' => $r['title'], 'year' => $r['year'] ?? null, 'age' => $r['age_rating'] ?? null,
            'poster' => img($r['poster'] ?? null), 'backdrop' => img($r['backdrop'] ?? null), 'overview' => $r['overview'] ?? null,
            'runtime' => $r['runtime'] ?? null, 'genres' => $r['genres'] ?? null];
}

const M_COLS = "m.id,m.title,m.year,m.age_rating,m.poster,m.backdrop,m.overview,m.runtime,
  (SELECT group_concat(g.name,', ') FROM movie_genres mg JOIN genres g ON g.id=mg.genre_id WHERE mg.movie_id=m.id) AS genres";
const S_COLS = "s.id,s.title,s.year,s.age_rating,s.poster,s.backdrop,s.overview,NULL AS runtime,
  (SELECT group_concat(g.name,', ') FROM series_genres sg JOIN genres g ON g.id=sg.genre_id WHERE sg.series_id=s.id) AS genres";

function like_escape(string $s): string { return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%'; }

/** Filterbare, paginierte Liste für Filme oder Serien. */
function browse(int $pid, string $kind, array $f): array {
    $isM = $kind === 'movie';
    $t = $isM ? 'movies m' : 'series s'; $a = $isM ? 'm' : 's';
    $where = ['1=1']; $p = [];
    if (!empty($f['genre'])) {
        $where[] = $isM ? 'EXISTS(SELECT 1 FROM movie_genres mg JOIN genres g ON g.id=mg.genre_id WHERE mg.movie_id=m.id AND g.name=?)'
                        : 'EXISTS(SELECT 1 FROM series_genres sg JOIN genres g ON g.id=sg.genre_id WHERE sg.series_id=s.id AND g.name=?)';
        $p[] = $f['genre'];
    }
    if (!empty($f['year'])) { $where[] = "$a.year=?"; $p[] = (int)$f['year']; }
    if (!empty($f['year_from'])) { $where[] = "$a.year>=?"; $p[] = (int)$f['year_from']; }
    if (!empty($f['year_to'])) { $where[] = "$a.year<=?"; $p[] = (int)$f['year_to']; }
    if ($isM && !empty($f['runtime_max'])) { $where[] = 'm.runtime<=?'; $p[] = (int)$f['runtime_max']; }
    if ($isM && !empty($f['runtime_min'])) { $where[] = 'm.runtime>=?'; $p[] = (int)$f['runtime_min']; }
    if (!empty($f['rating'])) {
        $where[] = "EXISTS(SELECT 1 FROM ratings r WHERE r.profile_id=? AND r.kind=? AND r.item_id=$a.id AND r.rating>=?)";
        array_push($p, $pid, $kind, (int)$f['rating']);
    }
    if (!empty($f['q'])) {
        $l = like_escape((string)$f['q']);
        $gTbl = $isM ? 'movie_genres x' : 'series_genres x'; $gCol = $isM ? 'x.movie_id' : 'x.series_id';
        $dir = $isM ? " OR $a.director LIKE ? ESCAPE '\\'" : '';
        $where[] = "($a.title LIKE ? ESCAPE '\\' OR $a.cast_list LIKE ? ESCAPE '\\'$dir OR CAST($a.year AS TEXT)=? OR EXISTS(SELECT 1 FROM $gTbl JOIN genres g ON g.id=x.genre_id WHERE $gCol=$a.id AND g.name LIKE ? ESCAPE '\\'))";
        array_push($p, $l, $l); if ($isM) $p[] = $l; array_push($p, trim((string)$f['q']), $l);
    }
    $kc = kids_sql($pid, "$a.age_rating");
    if ($kc !== '') $where[] = substr($kc, 5);
    $order = match ($f['sort'] ?? 'added') { 'title' => "$a.title COLLATE NOCASE ASC", 'year' => "$a.year DESC", default => "$a.added_at DESC, $a.id DESC" };
    $per = min(100, max(1, (int)($f['per'] ?? 40)));
    $page = max(1, (int)($f['page'] ?? 1));
    $w = implode(' AND ', $where);
    $total = (int)qv("SELECT COUNT(*) FROM $t WHERE $w", $p);
    $cols = $isM ? M_COLS : S_COLS;
    $rows = qa("SELECT $cols FROM $t WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    return ['items' => array_map(fn($r) => card($r, $kind), $rows), 'total' => $total, 'page' => $page, 'per' => $per];
}

function by_genre(int $pid, string $genre, int $limit = 20): array {
    $k = kids_sql($pid, 'm.age_rating'); $k2 = kids_sql($pid, 's.age_rating');
    $m = qa('SELECT ' . M_COLS . " FROM movies m JOIN movie_genres mg ON mg.movie_id=m.id JOIN genres g ON g.id=mg.genre_id WHERE g.name=? $k ORDER BY m.added_at DESC LIMIT $limit", [$genre]);
    $s = qa('SELECT ' . S_COLS . " FROM series s JOIN series_genres sg ON sg.series_id=s.id JOIN genres g ON g.id=sg.genre_id WHERE g.name=? $k2 ORDER BY s.added_at DESC LIMIT $limit", [$genre]);
    return array_slice(array_merge(array_map(fn($r) => card($r, 'movie'), $m), array_map(fn($r) => card($r, 'series'), $s)), 0, $limit);
}

/** Weiterschauen: angefangene Titel + nächste Episode abgeschlossener Folgen. */
function continue_watching(int $pid): array {
    $rows = qa("SELECT * FROM watch_progress WHERE profile_id=? ORDER BY updated_at DESC LIMIT 60", [$pid]);
    $items = []; $seenSeries = [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'movie') {
            $m = q1('SELECT ' . M_COLS . ' FROM movies m WHERE m.id=?', [$r['item_id']]);
            if (!$m || $r['completed'] || $r['position'] < 10 || !allowed_age($pid, $m['age_rating'])) continue;
            $c = card($m, 'movie'); $c['resume'] = (float)$r['position']; $c['percent'] = $r['duration'] > 0 ? round($r['position'] / $r['duration'] * 100) : 0;
            $items[] = $c;
        } else {
            $e = q1('SELECT e.id,e.series_id,e.number,e.title,e.still,s.number AS snum,se.title AS stitle,se.poster,se.backdrop,se.age_rating FROM episodes e JOIN seasons s ON s.id=e.season_id JOIN series se ON se.id=e.series_id WHERE e.id=?', [$r['item_id']]);
            if (!$e || isset($seenSeries[$e['series_id']]) || !allowed_age($pid, $e['age_rating'])) continue;
            $seenSeries[$e['series_id']] = true;
            $target = $e; $resume = (float)$r['position']; $pct = $r['duration'] > 0 ? round($r['position'] / $r['duration'] * 100) : 0;
            if ($r['completed']) {
                $target = next_episode((int)$r['item_id']);
                if (!$target) continue;
                $target = q1('SELECT e.id,e.series_id,e.number,e.title,e.still,s.number AS snum,se.title AS stitle,se.poster,se.backdrop FROM episodes e JOIN seasons s ON s.id=e.season_id JOIN series se ON se.id=e.series_id WHERE e.id=?', [$target['id']]);
                $resume = 0; $pct = 0;
            } elseif ($r['position'] < 10) continue;
            $items[] = ['kind' => 'episode', 'id' => (int)$target['id'], 'series_id' => (int)$target['series_id'], 'title' => $target['stitle'],
                        'subtitle' => 'S' . $target['snum'] . ':E' . $target['number'] . ' · ' . $target['title'],
                        'poster' => img($target['poster']), 'backdrop' => img($target['still'] ?: $target['backdrop']), 'resume' => $resume, 'percent' => $pct];
        }
        if (count($items) >= 20) break;
    }
    return $items;
}
function allowed_age(int $pid, ?string $age): bool { return !is_kids($pid) || in_array((string)$age, KIDS_RATINGS, true); }

function next_episode(int $episodeId): ?array {
    $e = q1('SELECT e.series_id,e.number,s.number AS snum FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE e.id=?', [$episodeId]);
    if (!$e) return null;
    $n = q1('SELECT e.id FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE e.series_id=? AND (s.number>? OR (s.number=? AND e.number>?)) ORDER BY s.number,e.number LIMIT 1',
            [$e['series_id'], $e['snum'], $e['snum'], $e['number']]);
    return $n;
}
function prev_episode(int $episodeId): ?array {
    $e = q1('SELECT e.series_id,e.number,s.number AS snum FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE e.id=?', [$episodeId]);
    if (!$e) return null;
    return q1('SELECT e.id FROM episodes e JOIN seasons s ON s.id=e.season_id WHERE e.series_id=? AND (s.number<? OR (s.number=? AND e.number<?)) ORDER BY s.number DESC,e.number DESC LIMIT 1',
              [$e['series_id'], $e['snum'], $e['snum'], $e['number']]);
}

function my_list(int $pid): array {
    $out = [];
    foreach (qa('SELECT kind,item_id FROM watchlist WHERE profile_id=? ORDER BY added_at DESC', [$pid]) as $w) {
        $r = $w['kind'] === 'movie' ? q1('SELECT ' . M_COLS . ' FROM movies m WHERE m.id=?', [$w['item_id']]) : q1('SELECT ' . S_COLS . ' FROM series s WHERE s.id=?', [$w['item_id']]);
        if ($r && allowed_age($pid, $r['age_rating'])) $out[] = card($r, $w['kind']);
    }
    return $out;
}

/** Empfehlungen: Genre-Gewichte aus Bewertungen und Verlauf, bereits gesehene Titel ausgeschlossen. */
function recommendations(int $pid, int $limit = 20): array {
    $w = [];
    $add = function (string $kind, int $id, float $weight) use (&$w) {
        $t = $kind === 'movie' ? 'movie_genres' : 'series_genres'; $c = $kind === 'movie' ? 'movie_id' : 'series_id';
        foreach (qa("SELECT genre_id FROM $t WHERE $c=?", [$id]) as $g) $w[$g['genre_id']] = ($w[$g['genre_id']] ?? 0) + $weight;
    };
    foreach (qa('SELECT kind,item_id,rating FROM ratings WHERE profile_id=?', [$pid]) as $r) $add($r['kind'], (int)$r['item_id'], ($r['rating'] - 2.5));
    foreach (qa("SELECT kind,item_id FROM watch_history WHERE profile_id=? ORDER BY watched_at DESC LIMIT 100", [$pid]) as $h) {
        if ($h['kind'] === 'movie') $add('movie', (int)$h['item_id'], 1);
        else { $sid = qv('SELECT series_id FROM episodes WHERE id=?', [$h['item_id']]); if ($sid) $add('series', (int)$sid, 0.5); }
    }
    $w = array_filter($w, fn($v) => $v > 0);
    if (!$w) return [];
    arsort($w);
    $top = array_slice(array_keys($w), 0, 5);
    $in = implode(',', array_map('intval', $top));
    $seenM = array_column(qa("SELECT DISTINCT item_id FROM watch_history WHERE profile_id=? AND kind='movie'", [$pid]), 'item_id');
    $seenS = array_column(qa("SELECT DISTINCT e.series_id AS item_id FROM watch_history h JOIN episodes e ON e.id=h.item_id WHERE h.profile_id=? AND h.kind='episode'", [$pid]), 'item_id');
    $cand = [];
    foreach (qa('SELECT ' . M_COLS . ", (SELECT SUM(CASE WHEN mg.genre_id IN ($in) THEN 1 ELSE 0 END) FROM movie_genres mg WHERE mg.movie_id=m.id) AS sc FROM movies m WHERE sc>0 " . kids_sql($pid, 'm.age_rating') . ' ORDER BY sc DESC, m.added_at DESC LIMIT 60') as $r)
        if (!in_array($r['id'], $seenM)) $cand[] = [$r['sc'], card($r, 'movie')];
    foreach (qa('SELECT ' . S_COLS . ", (SELECT SUM(CASE WHEN sg.genre_id IN ($in) THEN 1 ELSE 0 END) FROM series_genres sg WHERE sg.series_id=s.id) AS sc FROM series s WHERE sc>0 " . kids_sql($pid, 's.age_rating') . ' ORDER BY sc DESC, s.added_at DESC LIMIT 60') as $r)
        if (!in_array($r['id'], $seenS)) $cand[] = [$r['sc'], card($r, 'series')];
    usort($cand, fn($a, $b) => $b[0] <=> $a[0]);
    return array_slice(array_column($cand, 1), 0, $limit);
}

function popular(int $pid, string $kind, int $limit = 20): array {
    if ($kind === 'movie') {
        $rows = qa('SELECT ' . M_COLS . ", (SELECT COUNT(*) FROM watch_history h WHERE h.kind='movie' AND h.item_id=m.id) AS c FROM movies m WHERE 1=1 " . kids_sql($pid, 'm.age_rating') . " ORDER BY c DESC, m.added_at DESC LIMIT $limit");
    } else {
        $rows = qa('SELECT ' . S_COLS . ", (SELECT COUNT(*) FROM watch_history h JOIN episodes e ON e.id=h.item_id WHERE h.kind='episode' AND e.series_id=s.id) AS c FROM series s WHERE 1=1 " . kids_sql($pid, 's.age_rating') . " ORDER BY c DESC, s.added_at DESC LIMIT $limit");
    }
    return array_map(fn($r) => card($r, $kind), $rows);
}

function newest(int $pid, int $limit = 20): array {
    $m = qa('SELECT ' . M_COLS . ',m.added_at AS ad FROM movies m WHERE 1=1 ' . kids_sql($pid, 'm.age_rating') . " ORDER BY m.added_at DESC LIMIT $limit");
    $s = qa('SELECT ' . S_COLS . ',s.added_at AS ad FROM series s WHERE 1=1 ' . kids_sql($pid, 's.age_rating') . " ORDER BY s.added_at DESC LIMIT $limit");
    $all = array_merge(array_map(fn($r) => [$r['ad'], card($r, 'movie')], $m), array_map(fn($r) => [$r['ad'], card($r, 'series')], $s));
    usort($all, fn($a, $b) => strcmp($b[0], $a[0]));
    return array_slice(array_column($all, 1), 0, $limit);
}

function home(int $pid): array {
    $rows = [];
    $add = function (string $id, string $title, array $items) use (&$rows) { if ($items) $rows[] = ['id' => $id, 'title' => $title, 'items' => $items]; };
    $add('continue', 'Weiterschauen', continue_watching($pid));
    $add('mylist', 'Meine Liste', my_list($pid));
    $add('recs', 'Empfohlen für dich', recommendations($pid));
    $add('pop_movies', 'Beliebte Filme', popular($pid, 'movie'));
    $add('pop_series', 'Beliebte Serien', popular($pid, 'series'));
    $add('new', 'Neu hinzugefügt', newest($pid));
    foreach (HOME_GENRES as $g) $add('g_' . $g, $g, by_genre($pid, $g));
    // Hero: stabil pro Tag, nur Titel mit Hintergrundbild
    $pool = array_values(array_filter(newest($pid, 30), fn($c) => !empty($c['backdrop'])));
    $hero = $pool ? $pool[crc32(gmdate('Y-m-d') . $pid) % count($pool)] : null;
    return ['hero' => $hero, 'rows' => $rows];
}

function item_detail(int $pid, string $kind, int $id): ?array {
    if ($kind === 'movie') {
        $m = q1('SELECT ' . M_COLS . ',m.director,m.cast_list FROM movies m WHERE m.id=?', [$id]);
        if (!$m || !allowed_age($pid, $m['age_rating'])) return null;
        $c = card($m, 'movie'); $c['director'] = $m['director']; $c['cast'] = $m['cast_list'];
        $f = q1('SELECT width,height,vcodec,acodec,size,duration FROM media_files WHERE movie_id=? ORDER BY id LIMIT 1', [$id]);
        $c['file'] = $f ? ['resolution' => $f['height'] ? $f['width'] . '×' . $f['height'] : null, 'vcodec' => $f['vcodec'], 'acodec' => $f['acodec'], 'size' => human_bytes((float)$f['size'])] : null;
        $pr = q1("SELECT position,duration,completed FROM watch_progress WHERE profile_id=? AND kind='movie' AND item_id=?", [$pid, $id]);
        $c['progress'] = $pr;
    } else {
        $s = q1('SELECT ' . S_COLS . ',s.cast_list,s.intro_start,s.intro_end,s.credits_offset FROM series s WHERE s.id=?', [$id]);
        if (!$s || !allowed_age($pid, $s['age_rating'])) return null;
        $c = card($s, 'series'); $c['cast'] = $s['cast_list'];
        $c['seasons'] = [];
        $prog = [];
        foreach (qa("SELECT item_id,position,duration,completed FROM watch_progress WHERE profile_id=? AND kind='episode' AND item_id IN (SELECT id FROM episodes WHERE series_id=?)", [$pid, $id]) as $p) $prog[$p['item_id']] = $p;
        $resume = null; $last = null;
        foreach (qa('SELECT * FROM seasons WHERE series_id=? ORDER BY number', [$id]) as $se) {
            $eps = [];
            foreach (qa('SELECT e.id,e.number,e.title,e.overview,e.runtime,e.still FROM episodes e WHERE e.season_id=? ORDER BY e.number', [$se['id']]) as $e) {
                $p = $prog[$e['id']] ?? null;
                $eps[] = ['id' => (int)$e['id'], 'number' => (int)$e['number'], 'title' => $e['title'], 'overview' => $e['overview'], 'runtime' => $e['runtime'],
                          'still' => img($e['still']), 'percent' => $p && $p['duration'] > 0 ? round($p['position'] / $p['duration'] * 100) : 0, 'completed' => (bool)($p['completed'] ?? 0)];
                if ($resume === null && (!$p || !$p['completed'])) $resume = (int)$e['id'];
            }
            $c['seasons'][] = ['id' => (int)$se['id'], 'number' => (int)$se['number'], 'title' => $se['title'], 'episodes' => $eps];
        }
        $c['resume_episode'] = $resume ?? ($c['seasons'][0]['episodes'][0]['id'] ?? null);
    }
    $c['in_list'] = (bool)q1('SELECT 1 FROM watchlist WHERE profile_id=? AND kind=? AND item_id=?', [$pid, $kind, $id]);
    $c['my_rating'] = (int)(qv('SELECT rating FROM ratings WHERE profile_id=? AND kind=? AND item_id=?', [$pid, $kind, $id]) ?? 0);
    return $c;
}

function all_genres(): array { return array_column(qa('SELECT name FROM genres ORDER BY name COLLATE NOCASE'), 'name'); }
