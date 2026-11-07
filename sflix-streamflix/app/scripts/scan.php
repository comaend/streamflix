<?php
// Aufruf: php scan.php <folder_id> <history_id>
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/scanner.php';
set_time_limit(0);
$folderId = (int)($argv[1] ?? 0);
$hid = (int)($argv[2] ?? 0);
$lock = fopen(SF_DATA . '/tmp/scan.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    q("UPDATE scan_history SET status='error', errors=?, finished_at=datetime('now') WHERE id=?", [json_encode(['Ein anderer Scan läuft bereits.']), $hid]);
    exit(1);
}
try {
    run_scan($folderId, $hid);
} catch (Throwable $e) {
    q("UPDATE scan_history SET status='error', errors=?, finished_at=datetime('now') WHERE id=?", [json_encode([$e->getMessage()]), $hid]);
    log_activity('scan.error', $e->getMessage(), 'error');
}
