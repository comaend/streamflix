<?php
declare(strict_types=1);

function create_backup(string $kind = 'manual', string $note = ''): array {
    $stamp = gmdate('Ymd-His');
    $name = "streamflix-$stamp-" . bin2hex(random_bytes(2)) . '.zip';
    $zipPath = SF_DATA . '/backups/' . $name;
    $tmpDb = SF_DATA . '/tmp/bk_' . bin2hex(random_bytes(4)) . '.sqlite';
    db()->exec("VACUUM INTO " . db()->quote($tmpDb));
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) throw new RuntimeException('ZIP konnte nicht erstellt werden.');
    $zip->addFile($tmpDb, 'db.sqlite');
    $cfg = [];
    foreach (qa('SELECT key,value FROM system_settings') as $r) $cfg[$r['key']] = $r['value'];
    $zip->addFromString('config.json', json_encode(['app' => SF_VERSION, 'created' => $stamp, 'settings' => $cfg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    foreach (glob(SF_DATA . '/images/*') ?: [] as $img) if (is_file($img)) $zip->addFile($img, 'images/' . basename($img));
    $zip->close();
    @unlink($tmpDb);
    $size = (int)filesize($zipPath);
    q('INSERT INTO backups(filename,size,kind,note) VALUES(?,?,?,?)', [$name, $size, $kind, $note]);
    prune_backups();
    log_activity('backup.create', "$name ($kind)");
    return ['filename' => $name, 'size' => $size];
}

function prune_backups(): void {
    $keep = max(1, (int)setting('backup_keep', '7'));
    $rows = qa("SELECT id,filename FROM backups WHERE kind='auto' ORDER BY id DESC");
    foreach (array_slice($rows, $keep) as $r) {
        @unlink(SF_DATA . '/backups/' . $r['filename']);
        q('DELETE FROM backups WHERE id=?', [$r['id']]);
    }
}

function backup_path(array $b): string {
    if (!preg_match('/^streamflix-[0-9-]+-[a-f0-9]{4}\.zip$/', $b['filename'])) throw new RuntimeException('Ungültiger Backup-Name.');
    return SF_DATA . '/backups/' . $b['filename'];
}

/** Stellt Datenbank und Bilder aus einem Backup wieder her (online, ohne Neustart). */
function restore_backup(int $id): void {
    $b = q1('SELECT * FROM backups WHERE id=?', [$id]);
    if (!$b) throw new RuntimeException('Backup nicht gefunden.');
    $path = backup_path($b);
    if (!is_file($path)) throw new RuntimeException('Backup-Datei fehlt.');
    create_backup('pre-restore', 'Automatisch vor Wiederherstellung von ' . $b['filename']);

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Backup konnte nicht geöffnet werden.');
    $tmpDb = SF_DATA . '/tmp/restore_' . bin2hex(random_bytes(4)) . '.sqlite';
    $content = $zip->getFromName('db.sqlite');
    if ($content === false) { $zip->close(); throw new RuntimeException('Backup enthält keine Datenbank.'); }
    file_put_contents($tmpDb, $content);

    // Backup-Datenbank prüfen
    $chk = new PDO('sqlite:' . $tmpDb);
    if ($chk->query("SELECT name FROM sqlite_master WHERE name='users'")->fetchColumn() === false) { $zip->close(); @unlink($tmpDb); throw new RuntimeException('Kein gültiges STREAMFLIX-Backup.'); }
    $chk = null;

    $pdo = db();
    $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($tmpDb) . ' AS bk');
    try {
        $pdo->beginTransaction();
        $skip = ['schema_version', 'sessions', 'rate_limits', 'active_streams', 'backups', 'sqlite_sequence'];
        foreach ($pdo->query("SELECT name FROM main.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (in_array($t, $skip, true)) continue;
            $has = $pdo->query("SELECT 1 FROM bk.sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
            $pdo->exec("DELETE FROM main.\"$t\"");
            if (!$has) continue;
            $mc = array_column($pdo->query("PRAGMA main.table_info(\"$t\")")->fetchAll(), 'name');
            $bc = array_column($pdo->query("PRAGMA bk.table_info(\"$t\")")->fetchAll(), 'name');
            $cols = array_intersect($mc, $bc);
            if (!$cols) continue;
            $l = implode(',', array_map(fn($c) => '"' . $c . '"', $cols));
            $pdo->exec("INSERT INTO main.\"$t\"($l) SELECT $l FROM bk.\"$t\"");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->exec('DETACH DATABASE bk');
        $pdo->exec('PRAGMA foreign_keys=ON');
        @unlink($tmpDb);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^images/([A-Za-z0-9_.-]{1,80})$#', (string)$n, $m) && !str_contains($m[1], '..')) {
            file_put_contents(SF_DATA . '/images/' . $m[1], $zip->getFromIndex($i));
        }
    }
    $zip->close();
    log_activity('backup.restore', $b['filename'], 'warn');
}
