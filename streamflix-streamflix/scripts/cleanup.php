#!/usr/bin/env php
<?php
// StreamFlix — Cleanup
// Löscht temporäre Transkodierungsdateien und alte Backups (über Behalte-Limit).
declare(strict_types=1);
require_once __DIR__ . '/../app/includes/config.php';
require_once __DIR__ . '/../app/includes/db.php';

$tempDirs = [APP_DATA_DIR . '/transcodes', APP_DATA_DIR . '/tmp', '/tmp/streamflix'];
foreach ($tempDirs as $d) {
    if (is_dir($d)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { @unlink($f->getPathname()); }
        @rmdir($d);
        fwrite(STDOUT, "Aufgeräumt: $d\n");
    }
}

// Alte Backups über Behalte-Limit löschen
$db = Database::get();
$keep = (int) ($db->prepare("SELECT value FROM system_settings WHERE key='backup_keep'")->fetchColumn() ?? 5);
$backups = $db->query("SELECT id, file_path FROM backups ORDER BY created_at DESC")->fetchAll();
$i = 0;
foreach ($backups as $b) {
    $i++;
    if ($i > $keep) {
        if (file_exists($b['file_path'])) { @unlink($b['file_path']); }
        $db->prepare("DELETE FROM backups WHERE id = ?")->execute([$b['id']]);
        fwrite(STDOUT, "Altes Backup entfernt: {$b['file_path']}\n");
    }
}
fwrite(STDOUT, "Cleanup abgeschlossen.\n");
