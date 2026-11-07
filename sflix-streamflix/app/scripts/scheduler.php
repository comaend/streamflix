<?php
// Hintergrunddienst: automatische Scans, Backups, Aufräumen. Wird von supervisord gestartet.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/scanner.php';
require_once __DIR__ . '/../lib/streaming.php';
require_once __DIR__ . '/../lib/backup.php';
set_time_limit(0);

function tick(): void {
    cleanup_streams();
    trim_cache();
    q("DELETE FROM rate_limits WHERE window_start < ?", [time() - 86400]);
    q("DELETE FROM sessions WHERE last_seen < datetime('now','-30 days')");
    q("DELETE FROM activity_logs WHERE created_at < datetime('now','-180 days')");

    if (setting('auto_scan_enabled', '1') === '1') {
        $hours = max(1, (int)setting('auto_scan_hours', '6'));
        $busy = (int)qv("SELECT COUNT(*) FROM scan_history WHERE status='running' AND heartbeat > ?", [time() - 180]);
        if (!$busy) {
            $f = q1("SELECT id FROM media_folders WHERE enabled=1 AND auto_scan=1 AND (last_scan IS NULL OR last_scan < datetime('now', ?)) ORDER BY COALESCE(last_scan,'') LIMIT 1", ["-$hours hours"]);
            if ($f) start_scan_async((int)$f['id']);
        }
    }
    if (setting('backup_auto', '1') === '1') {
        $hours = max(1, (int)setting('backup_hours', '24'));
        $last = qv("SELECT MAX(created_at) FROM backups WHERE kind='auto'");
        if (!$last || strtotime($last . ' UTC') < time() - $hours * 3600) create_backup('auto', 'Automatisches Backup');
    }
}

sleep(20);
while (true) {
    try { tick(); } catch (Throwable $e) { error_log('scheduler: ' . $e->getMessage()); }
    sleep(60);
}
