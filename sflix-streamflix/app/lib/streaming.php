<?php
declare(strict_types=1);

function direct_ok(array $f): bool {
    $c = (string)$f['container']; $v = (string)$f['vcodec']; $a = $f['acodec'];
    if (in_array($c, ['mp4', 'm4v', 'mov'], true)) {
        return $v === 'h264' && in_array($f['pix_fmt'], ['yuv420p', null, ''], true) && in_array($a, [null, '', 'aac', 'mp3'], true);
    }
    if ($c === 'webm') return in_array($v, ['vp8', 'vp9', 'av1'], true) && in_array($a, [null, '', 'opus', 'vorbis'], true);
    return false;
}
function video_copy_ok(array $f): bool { return $f['vcodec'] === 'h264' && in_array($f['pix_fmt'], ['yuv420p', null, ''], true); }

function decide_mode(array $f, int $audioIdx, bool $forceHls): string {
    $tracks = json_decode((string)$f['audio_tracks'], true) ?: [];
    if (!$forceHls && $audioIdx === 0 && direct_ok($f)) return 'direct';
    if (video_copy_ok($f) && (int)$f['height'] <= 1080) return 'remux';
    return 'transcode';
}

function stream_dir(string $token): string { return SF_DATA . '/cache/hls/' . $token; }

function pid_is_ffmpeg(int $pid, ?string $token = null): bool {
    if ($pid <= 1) return false;
    $c = @file_get_contents("/proc/$pid/cmdline");
    if ($c === false || !str_contains($c, 'ffmpeg')) return false;
    return $token === null || str_contains($c, $token);
}

function rm_dir(string $d): void {
    if (!is_dir($d) || !str_starts_with(realpath($d) ?: '', realpath(SF_DATA . '/cache') ?: '/nonexistent')) return;
    foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    @rmdir($d);
}

function kill_stream(array $row): void {
    if (!empty($row['ffmpeg_pid']) && pid_is_ffmpeg((int)$row['ffmpeg_pid'], $row['token'])) {
        shell_exec('kill ' . (int)$row['ffmpeg_pid'] . ' 2>/dev/null');
    }
    rm_dir(stream_dir($row['token']));
}

function cleanup_streams(): void {
    foreach (qa('SELECT * FROM active_streams WHERE last_ping < ?', [time() - 60]) as $r) {
        kill_stream($r);
        q('DELETE FROM active_streams WHERE token=?', [$r['token']]);
    }
    // verwaiste HLS-Verzeichnisse
    foreach (glob(SF_DATA . '/cache/hls/*', GLOB_ONLYDIR) ?: [] as $d) {
        $t = basename($d);
        if (!q1('SELECT 1 FROM active_streams WHERE token=?', [$t]) && filemtime($d) < time() - 300) rm_dir($d);
    }
}

function trim_cache(): void {
    $max = (int)setting('cache_max_mb', '2048') * 1048576;
    $files = [];
    $total = 0;
    foreach (glob(SF_DATA . '/cache/sub/*') ?: [] as $f) { $files[] = [filemtime($f), $f, filesize($f)]; $total += filesize($f); }
    foreach (glob(SF_DATA . '/cache/hls/*/*') ?: [] as $f) $total += (int)filesize($f);
    usort($files, fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($files as [$t, $f, $s]) { if ($total <= $max) break; @unlink($f); $total -= $s; }
    foreach (glob(SF_DATA . '/tmp/*') ?: [] as $f) { if (is_file($f) && filemtime($f) < time() - 86400 && !str_ends_with($f, '.lock')) @unlink($f); }
}

function cache_size(): int {
    $t = 0;
    foreach (glob(SF_DATA . '/cache/sub/*') ?: [] as $f) $t += (int)filesize($f);
    foreach (glob(SF_DATA . '/cache/hls/*/*') ?: [] as $f) $t += (int)filesize($f);
    return $t;
}

/** Startet FFmpeg-HLS. Gibt die PID zurück oder wirft eine RuntimeException. */
function start_hls(string $token, array $f, string $mode, float $start, int $audioIdx): int {
    $dir = stream_dir($token);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    foreach (glob($dir . '/*') ?: [] as $old) @unlink($old);
    $path = safe_media_path($f['path']);
    if (!$path) throw new RuntimeException('Datei nicht gefunden oder nicht erlaubt.');
    $tracks = json_decode((string)$f['audio_tracks'], true) ?: [];
    $hasAudio = count($tracks) > 0;
    $H = (int)setting('video_quality', '720');
    $maxH = $H > 0 ? $H : 2160;

    $cmd = ['nice -n 10 ffmpeg -nostdin -hide_banner -loglevel error'];
    if ($start > 1) $cmd[] = '-ss ' . round($start, 2);
    $cmd[] = '-i ' . escapeshellarg($path);
    $cmd[] = '-map 0:v:0';
    if ($hasAudio) $cmd[] = '-map 0:a:' . max(0, min($audioIdx, count($tracks) - 1));
    $cmd[] = '-sn -dn';
    if ($mode === 'remux') {
        $cmd[] = '-c:v copy';
    } else {
        $cmd[] = '-c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p -profile:v high -level 4.0';
        $cmd[] = '-vf ' . escapeshellarg("scale=-2:'min(ih,$maxH)'");
        $cmd[] = '-force_key_frames ' . escapeshellarg('expr:gte(t,n_forced*6)');
    }
    if ($hasAudio) $cmd[] = '-c:a aac -ac 2 -b:a 160k';
    $cmd[] = '-f hls -hls_time 6 -hls_list_size 0 -hls_playlist_type event -hls_flags independent_segments+temp_file';
    $cmd[] = '-hls_segment_filename ' . escapeshellarg($dir . '/s%05d.ts');
    $cmd[] = escapeshellarg($dir . '/index.m3u8');
    $full = implode(' ', $cmd);
    $pid = (int)trim((string)shell_exec('nohup ' . $full . ' > /dev/null 2> ' . escapeshellarg($dir . '/ffmpeg.log') . ' & echo $!'));
    if ($pid <= 1) throw new RuntimeException('FFmpeg konnte nicht gestartet werden.');

    // Auf das erste fertige Segment warten
    $deadline = microtime(true) + 45;
    while (microtime(true) < $deadline) {
        if (is_file($dir . '/index.m3u8') && is_file($dir . '/s00000.ts')) return $pid;
        if (!file_exists("/proc/$pid") || (file_exists("/proc/$pid/stat") && str_contains((string)@file_get_contents("/proc/$pid/stat"), ' Z '))) {
            if (is_file($dir . '/index.m3u8')) return $pid;
            $err = trim((string)@file_get_contents($dir . '/ffmpeg.log'));
            throw new RuntimeException('FFmpeg-Fehler: ' . mb_substr($err, -300));
        }
        usleep(300000);
    }
    shell_exec('kill ' . $pid . ' 2>/dev/null');
    throw new RuntimeException('Zeitüberschreitung beim Starten der Transkodierung.');
}

function stream_title(string $kind, int $id): string {
    if ($kind === 'movie') return (string)qv('SELECT title FROM movies WHERE id=?', [$id]);
    $e = q1('SELECT e.title,e.number,s.number AS sn,se.title AS st FROM episodes e JOIN seasons s ON s.id=e.season_id JOIN series se ON se.id=e.series_id WHERE e.id=?', [$id]);
    return $e ? "{$e['st']} S{$e['sn']}:E{$e['number']} {$e['title']}" : '';
}

function save_progress(int $pid, string $kind, int $id, float $pos, float $dur): void {
    $completed = $dur > 0 && ($pos / $dur) >= 0.92 ? 1 : 0;
    q("INSERT INTO watch_progress(profile_id,kind,item_id,position,duration,completed,updated_at) VALUES(?,?,?,?,?,?,datetime('now'))
       ON CONFLICT(profile_id,kind,item_id) DO UPDATE SET position=excluded.position,duration=excluded.duration,completed=excluded.completed,updated_at=excluded.updated_at",
      [$pid, $kind, $id, $pos, $dur, $completed]);
}

function ext_mime(string $c): string {
    return match ($c) { 'webm' => 'video/webm', default => 'video/mp4' };
}
