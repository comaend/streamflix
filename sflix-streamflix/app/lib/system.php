<?php
declare(strict_types=1);

function cpu_times(): array {
    $l = explode(' ', preg_replace('/\s+/', ' ', trim((string)strtok((string)@file_get_contents('/proc/stat'), "\n"))));
    $v = array_map('intval', array_slice($l, 1));
    return [array_sum($v), ($v[3] ?? 0) + ($v[4] ?? 0)];
}
function server_stats(): array {
    [$t1, $i1] = cpu_times(); usleep(250000); [$t2, $i2] = cpu_times();
    $cpu = $t2 > $t1 ? round((1 - ($i2 - $i1) / ($t2 - $t1)) * 100, 1) : 0.0;
    $mem = [];
    foreach (explode("\n", (string)@file_get_contents('/proc/meminfo')) as $l) if (preg_match('/^(\w+):\s+(\d+)/', $l, $m)) $mem[$m[1]] = (int)$m[2] * 1024;
    $total = $mem['MemTotal'] ?? 0; $avail = $mem['MemAvailable'] ?? 0;
    $temp = null;
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $f) { $v = (int)@file_get_contents($f); if ($v > 0) { $temp = round($v / 1000, 1); break; } }
    $up = (float)strtok((string)@file_get_contents('/proc/uptime'), ' ');
    $rx = $tx = 0;
    foreach (array_slice(explode("\n", (string)@file_get_contents('/proc/net/dev')), 2) as $l) {
        if (!preg_match('/^\s*([^:]+):\s*(\d+)(?:\s+\d+){7}\s+(\d+)/', $l, $m) || trim($m[1]) === 'lo') continue;
        $rx += (int)$m[2]; $tx += (int)$m[3];
    }
    $root = realpath(SF_MEDIA) ?: SF_MEDIA;
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];
    return ['cpu' => $cpu, 'load' => array_map(fn($x) => round($x, 2), $load), 'ram_total' => $total, 'ram_used' => max(0, $total - $avail), 'temp' => $temp,
            'uptime' => (int)$up, 'net_rx' => $rx, 'net_tx' => $tx,
            'data_total' => (int)@disk_total_space(SF_DATA), 'data_free' => (int)@disk_free_space(SF_DATA),
            'media_total' => (int)@disk_total_space($root), 'media_free' => (int)@disk_free_space($root),
            'cache' => cache_size(), 'ts' => time()];
}
function server_info(): array {
    $ff = trim((string)strtok(sh_run('ffmpeg -version', 5), "\n"));
    $qc = 'unbekannt';
    try { $qc = (string)qv('PRAGMA quick_check'); } catch (Throwable $e) { $qc = 'Fehler: ' . $e->getMessage(); }
    return ['php' => PHP_VERSION, 'ffmpeg' => $ff ?: 'nicht gefunden', 'sqlite' => (string)qv('SELECT sqlite_version()'),
            'db_status' => $qc, 'db_size' => (int)@filesize(SF_DATA . '/streamflix.db'), 'schema' => (int)qv('SELECT MAX(version) FROM schema_version'),
            'app' => SF_VERSION, 'arch' => php_uname('m'), 'media_root' => SF_MEDIA, 'media_root_ok' => is_dir(SF_MEDIA) && is_readable(SF_MEDIA)];
}
