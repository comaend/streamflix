<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

function deny(int $code = 403): never { http_response_code($code); header('Content-Type: text/plain'); echo $code === 404 ? 'Not found' : 'Forbidden'; exit; }

function accel(string $uriPrefix, string $relative): string {
    return $uriPrefix . implode('/', array_map('rawurlencode', explode('/', $relative)));
}

start_session();
$u = current_user();
if (!$u) deny(401);
$a = (string)($_GET['a'] ?? '');
$token = (string)($_GET['t'] ?? '');

function stream_row(array $u, string $token): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) deny(404);
    $r = q1('SELECT * FROM active_streams WHERE token=? AND user_id=?', [$token, $u['id']]);
    if (!$r || $r['killed']) deny(403);
    return $r;
}

session_write_close(); // Sitzung nicht für die Dauer des Downloads sperren

if ($a === 'file') {
    $r = stream_row($u, $token);
    $f = q1('SELECT path,container FROM media_files WHERE id=?', [$r['media_file_id']]);
    $real = $f ? safe_media_path($f['path']) : null;
    if (!$real || !is_file($real)) deny(404);
    $rel = substr($real, strlen(realpath(SF_MEDIA)) + 1);
    header('Content-Type: ' . (($f['container'] ?? '') === 'webm' ? 'video/webm' : 'video/mp4'));
    header('Cache-Control: private, no-store');
    header('X-Accel-Redirect: ' . accel('/_media/', $rel));
    exit;
}

if ($a === 'hls') {
    $r = stream_row($u, $token);
    $file = (string)($_GET['f'] ?? '');
    if (!preg_match('/^(index\.m3u8|s\d{5}\.ts)$/', $file)) deny(404);
    $dir = SF_DATA . '/cache/hls/' . $token;
    $p = $dir . '/' . $file;
    if (!is_file($p)) deny(404);
    header('Cache-Control: no-store');
    if ($file === 'index.m3u8') {
        $lines = file($p, FILE_IGNORE_NEW_LINES) ?: [];
        $o = [];
        foreach ($lines as $i => $l) {
            if ($l === '#EXTM3U') { $o[] = $l; $o[] = '#EXT-X-START:TIME-OFFSET=0,PRECISE=YES'; continue; }
            if (preg_match('/^s\d{5}\.ts$/', $l)) $l = "/stream.php?a=hls&t=$token&f=$l";
            $o[] = $l;
        }
        header('Content-Type: application/vnd.apple.mpegurl');
        echo implode("\n", $o) . "\n";
        exit;
    }
    header('Content-Type: video/mp2t');
    header('X-Accel-Redirect: ' . accel('/_hls/', $token . '/' . $file));
    exit;
}

if ($a === 'sub') {
    $id = (int)($_GET['id'] ?? 0);
    $offset = max(0, min(86400, (int)($_GET['o'] ?? 0)));
    $s = q1('SELECT s.*,m.path AS vpath FROM subtitles s JOIN media_files m ON m.id=s.media_file_id WHERE s.id=?', [$id]);
    if (!$s) deny(404);
    $cache = SF_DATA . "/cache/sub/{$id}_{$offset}.vtt";
    if (!is_file($cache)) {
        $tmp = $cache . '.' . getmypid() . '.tmp';
        $ss = $offset > 0 ? '-ss ' . $offset . ' ' : '';
        if ($s['kind'] === 'external') {
            $src = safe_media_path((string)$s['path']);
            if (!$src) deny(404);
            $raw = (string)file_get_contents($src, false, null, 0, 400000);
            $enc = mb_check_encoding($raw, 'UTF-8') ? '' : '-sub_charenc CP1252 ';
            $cmd = 'ffmpeg -nostdin -y -loglevel error ' . $enc . $ss . '-i ' . escapeshellarg($src) . ' -f webvtt ' . escapeshellarg($tmp);
        } else {
            $src = safe_media_path($s['vpath']);
            if (!$src) deny(404);
            $cmd = 'ffmpeg -nostdin -y -loglevel error ' . $ss . '-i ' . escapeshellarg($src) . ' -map 0:' . (int)$s['stream_index'] . ' -f webvtt ' . escapeshellarg($tmp);
        }
        shell_exec('timeout 120 nice -n 10 ' . $cmd . ' 2>/dev/null');
        if (!is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); deny(404); }
        rename($tmp, $cache);
    }
    header('Content-Type: text/vtt; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    readfile($cache);
    exit;
}

deny(404);
