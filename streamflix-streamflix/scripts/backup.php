#!/usr/bin/env php
<?php
// StreamFlix — Backup (Datenbank + Konfiguration)
// Originalmediendateien werden nicht gesichert.
declare(strict_types=1);
require_once __DIR__ . '/../app/includes/config.php';
require_once __DIR__ . '/../app/includes/db.php';

$backupDir = APP_DATA_DIR . '/backups';
@mkdir($backupDir, 0777, true);
$name = 'backup_' . date('Ymd_His') . '.sqlite';
$dest = $backupDir . '/' . $name;
if (!copy(DB_PATH, $dest)) {
    fwrite(STDERR, "Backup konnte nicht erstellt werden.\n");
    exit(1);
}
$size = (int) filesize($dest);
$db = Database::get();
$db->prepare("INSERT INTO backups (name, type, file_path, size, created_at) VALUES (?, 'database', ?, ?, datetime('now'))")
   ->execute([$name, $dest, $size]);
fwrite(STDOUT, "Backup erstellt: $dest ($size Bytes)\n");
