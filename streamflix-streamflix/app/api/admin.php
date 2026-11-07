<?php
// StreamFlix — API: Admin-Control-Center
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/media.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
apiRequireAuth();

function requirePerm(string $action): void {
    if (!Auth::can($action)) {
        jsonResponse(['error' => 'Unzureichende Administrator-Berechtigung'], 403);
    }
}

$db = Database::get();

switch ($action) {
    // ------------------------------------------------------------- Dashboard
    case 'stats':
        requirePerm('media');
        jsonResponse(['stats' => Media::stats()]);
        break;

    // ------------------------------------------------------------- Benutzer
    case 'users':
        requirePerm('users');
        $users = $db->query("SELECT id, username, email, role, is_active, last_login, created_at FROM users ORDER BY id ASC")->fetchAll();
        foreach ($users as &$u) {
            $u['profile_count'] = (int) $db->prepare("SELECT COUNT(*) FROM profiles WHERE user_id=?")->execute([$u['id']])->fetchColumn();
        }
        jsonResponse(['users' => $users]);
        break;

    case 'user_create':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($input['username'] ?? '');
        $email    = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $role     = in_array($input['role'] ?? 'user', ['superadmin','admin','moderator','user'], true) ? $input['role'] : 'user';
        if (strlen($username) < 3 || strlen($password) < 6) {
            jsonResponse(['error' => 'Ungültige Eingaben'], 400);
        }
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)")->execute([$username, $email, $hash, $role]);
            $uid = (int) $db->lastInsertId();
            $db->prepare("INSERT INTO profiles (user_id, name) VALUES (?, ?)")->execute([$uid, $username . "'s Profil"]);
            Auth::logActivity('user_create', 'user', $uid, "Benutzer $username erstellt (Rolle: $role)");
            jsonResponse(['success' => true, 'user_id' => $uid]);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    case 'user_update':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id    = (int) ($input['id'] ?? 0);
        $role  = $input['role'] ?? null;
        $active= $input['is_active'] ?? null;
        if ($id <= 0) { jsonResponse(['error' => 'Ungültige ID'], 400); }
        $sets = []; $params = [];
        if ($role !== null && in_array($role, ['superadmin','admin','moderator','user'], true)) { $sets[] = 'role = ?'; $params[] = $role; }
        if ($active !== null) { $sets[] = 'is_active = ?'; $params[] = (int) $active; }
        if (empty($sets)) { jsonResponse(['error' => 'Keine Änderungen'], 400); }
        $params[] = $id;
        $db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        Auth::logActivity('user_update', 'user', $id, 'Benutzerdaten geändert');
        jsonResponse(['success' => true]);
        break;

    case 'user_delete':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0 || $id === (int) $_SESSION['user_id']) { jsonResponse(['error' => 'Nicht zulässig'], 400); }
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        Auth::logActivity('user_delete', 'user', $id, 'Benutzer gelöscht');
        jsonResponse(['success' => true]);
        break;

    case 'user_lock':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
        Auth::logActivity('user_lock', 'user', $id, 'Benutzer gesperrt');
        jsonResponse(['success' => true]);
        break;

    case 'user_unlock':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$id]);
        Auth::logActivity('user_unlock', 'user', $id, 'Benutzer entsperrt');
        jsonResponse(['success' => true]);
        break;

    case 'user_password_reset':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id     = (int) ($input['id'] ?? 0);
        $newPw  = $input['new_password'] ?? '';
        if ($id <= 0 || strlen($newPw) < 6) { jsonResponse(['error' => 'Ungültig'], 400); }
        Auth::changePassword($id, $newPw);
        Auth::logActivity('password_reset', 'user', $id, 'Passwort durch Admin zurückgesetzt');
        jsonResponse(['success' => true]);
        break;

    // ------------------------------------------------------------- Profile
    case 'profiles':
        requirePerm('users');
        $rows = $db->query("SELECT p.*, u.username FROM profiles p JOIN users u ON p.user_id = u.id ORDER BY p.id ASC")->fetchAll();
        jsonResponse(['profiles' => $rows]);
        break;

    case 'profile_create':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($input['user_id'] ?? 0);
        $name   = trim($input['name'] ?? '');
        if ($userId <= 0 || strlen($name) < 1) { jsonResponse(['error' => 'Ungültig'], 400); }
        $db->prepare("INSERT INTO profiles (user_id, name) VALUES (?, ?)")->execute([$userId, $name]);
        Auth::logActivity('profile_create', 'profile', (int) $db->lastInsertId(), 'Profil erstellt');
        jsonResponse(['success' => true]);
        break;

    case 'profile_update':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $pin = trim($input['pin'] ?? '');
        if ($id <= 0) { jsonResponse(['error' => 'Ungültig'], 400); }
        $db->prepare("UPDATE profiles SET name = ?, pin = ? WHERE id = ?")->execute([$name, $pin ?: null, $id]);
        Auth::logActivity('profile_update', 'profile', $id, 'Profil geändert');
        jsonResponse(['success' => true]);
        break;

    case 'profile_delete':
        requirePerm('users');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $db->prepare("DELETE FROM profiles WHERE id = ?")->execute([$id]);
        Auth::logActivity('profile_delete', 'profile', $id, 'Profil gelöscht');
        jsonResponse(['success' => true]);
        break;

    // ------------------------------------------------------------- Medien (Admin)
    case 'movies':
        requirePerm('media');
        $items = Media::listMovies(1, 1000);
        jsonResponse(['items' => $items]);
        break;

    case 'series':
        requirePerm('media');
        $items = Media::listSeries(1, 1000);
        jsonResponse(['items' => $items]);
        break;

    // ------------------------------------------------------------- Medienordner
    case 'folders':
        requirePerm('folders');
        $rows = $db->query("SELECT * FROM media_folders ORDER BY id ASC")->fetchAll();
        jsonResponse(['folders' => $rows]);
        break;

    case 'folder_add':
        requirePerm('folders');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['name'] ?? '');
        $path = trim($input['path'] ?? '');
        $type = $input['type'] ?? 'mixed';
        if (!$name || !$path || !in_array($type, ['movies','series','mixed'], true)) {
            jsonResponse(['error' => 'Ungültige Ordnerdaten'], 400);
        }
        try {
            $db->prepare("INSERT INTO media_folders (name, path, type, is_active) VALUES (?, ?, ?, 1)")->execute([$name, $path, $type]);
            Auth::logActivity('folder_add', 'media_folder', (int) $db->lastInsertId(), "Medienordner $name ($path)");
            jsonResponse(['success' => true, 'folder_id' => (int) $db->lastInsertId()]);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    case 'folder_update':
        requirePerm('folders');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id    = (int) ($input['id'] ?? 0);
        $name  = trim($input['name'] ?? '');
        $path  = trim($input['path'] ?? '');
        $type  = $input['type'] ?? 'mixed';
        $active= isset($input['is_active']) ? (int) $input['is_active'] : 1;
        if ($id <= 0 || !$name || !$path) { jsonResponse(['error' => 'Ungültig'], 400); }
        $db->prepare("UPDATE media_folders SET name=?, path=?, type=?, is_active=? WHERE id=?")->execute([$name, $path, $type, $active, $id]);
        jsonResponse(['success' => true]);
        break;

    case 'folder_delete':
        requirePerm('folders');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $db->prepare("DELETE FROM media_folders WHERE id = ?")->execute([$id]);
        Auth::logActivity('folder_delete', 'media_folder', $id, 'Medienordner gelöscht');
        jsonResponse(['success' => true]);
        break;

    case 'folder_scan':
        requirePerm('folders');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $folder = $db->prepare("SELECT * FROM media_folders WHERE id = ?")->execute([$id])->fetch();
        if (!$folder) { jsonResponse(['error' => 'Ordner nicht gefunden'], 404); }
        $scanId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO scan_history (media_folder_id, status, started_at) VALUES (?, 'running', datetime('now'))")->execute([$id]);
        $scanId = (int) $db->lastInsertId();
        // Scanner im Hintergrund starten (nicht blockierend)
        $scanner = __DIR__ . '/../../scripts/scanner.php';
        $cmd = escapeshellcmd('php ' . escapeshellarg($scanner) . ' --folder-id=' . (int) $folder['id'] . ' --scan-id=' . $scanId);
        @exec($cmd . ' > /dev/null 2>&1 &');
        Auth::logActivity('folder_scan', 'media_folder', $id, "Scan gestartet für $folder[name]");
        jsonResponse(['success' => true, 'scan_id' => $scanId]);
        break;

    // ------------------------------------------------------------- Aktive Streams
    case 'streams':
        requirePerm('media');
        $rows = $db->query("SELECT s.*, u.username, p.name AS profile_name FROM active_streams s JOIN users u ON s.user_id=u.id JOIN profiles p ON s.profile_id=p.id ORDER BY s.start_time DESC")->fetchAll();
        jsonResponse(['streams' => $rows]);
        break;

    case 'stream_stop':
        requirePerm('media');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = (int) ($input['id'] ?? 0);
        $db->prepare("DELETE FROM active_streams WHERE id = ?")->execute([$id]);
        Auth::logActivity('stream_stop', 'active_stream', $id, 'Stream durch Admin beendet');
        jsonResponse(['success' => true]);
        break;

    // ------------------------------------------------------------- Server
    case 'server':
        requirePerm('settings');
        jsonResponse(['server' => serverStats()]);
        break;

    // ------------------------------------------------------------- Einstellungen
    case 'settings':
        requirePerm('settings');
        $rows = $db->query("SELECT key, value FROM system_settings")->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[$r['key']] = $r['value']; }
        jsonResponse(['settings' => $out]);
        break;

    case 'settings_set':
        requirePerm('settings');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $key = trim($input['key'] ?? '');
        $val = $input['value'] ?? '';
        if ($key === '') { jsonResponse(['error' => 'Ungültiger Schlüssel'], 400); }
        $db->prepare("INSERT INTO system_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$key, $val]);
        Auth::logActivity('setting_set', 'system_setting', 0, "Einstellung $key geändert");
        jsonResponse(['success' => true]);
        break;

    // ------------------------------------------------------------- Aktivitätsprotokoll
    case 'logs':
        requirePerm('logs');
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $filter = $_GET['filter'] ?? '';
        $sql = "SELECT al.*, u.username FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id";
        $params = [];
        if ($filter !== '') {
            $sql .= " WHERE al.action LIKE ? OR al.details LIKE ?";
            $params[] = "%$filter%";
            $params[] = "%$filter%";
        }
        $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $rows = $db->prepare($sql)->execute($params);
        jsonResponse(['logs' => $rows->fetchAll(), 'page' => $page, 'limit' => $limit]);
        break;

    // ------------------------------------------------------------- Backups
    case 'backups':
        requirePerm('backups');
        $dir = APP_DATA_DIR . '/backups';
        @mkdir($dir, 0777, true);
        $files = [];
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $fp = $dir . '/' . $f;
            $files[] = ['name' => $f, 'size' => (int) filesize($fp), 'created_at' => date('Y-m-d H:i:s', filemtime($fp)), 'file_path' => $fp];
        }
        usort($files, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        jsonResponse(['backups' => $files]);
        break;

    case 'backup_create':
        requirePerm('backups');
        $dir = APP_DATA_DIR . '/backups';
        @mkdir($dir, 0777, true);
        $name = 'backup_' . date('Ymd_His') . '.sqlite';
        $dest = $dir . '/' . $name;
        if (!copy(DB_PATH, $dest)) {
            jsonResponse(['error' => 'Backup konnte nicht erstellt werden'], 500);
        }
        $size = (int) filesize($dest);
        $db->prepare("INSERT INTO backups (name, type, file_path, size, created_at) VALUES (?, 'database', ?, ?, datetime('now'))")->execute([$name, $dest, $size]);
        Auth::logActivity('backup_create', 'backup', (int) $db->lastInsertId(), 'Datenbank-Backup erstellt');
        jsonResponse(['success' => true, 'file' => $name, 'size' => $size]);
        break;

    case 'backup_download':
        requirePerm('backups');
        $id   = (int) ($_GET['id'] ?? 0);
        $name = $_GET['name'] ?? '';
        if ($id > 0) {
            $row = $db->prepare("SELECT * FROM backups WHERE id = ?")->execute([$id])->fetch();
        } elseif ($name !== '') {
            $row = $db->prepare("SELECT * FROM backups WHERE name = ? ORDER BY created_at DESC LIMIT 1")->execute([$name])->fetch();
        } else {
            jsonResponse(['error' => 'Kein Backup angegeben'], 400);
        }
        if (!$row) { jsonResponse(['error' => 'Backup nicht gefunden'], 404); }
        $file = $row['file_path'];
        if (!file_exists($file)) { jsonResponse(['error' => 'Datei nicht gefunden'], 404); }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;

    case 'backup_restore':
        requirePerm('backups');
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int) ($input['id'] ?? 0);
        $name = $input['name'] ?? '';
        if ($id > 0) {
            $row = $db->prepare("SELECT * FROM backups WHERE id = ?")->execute([$id])->fetch();
        } elseif ($name !== '') {
            $row = $db->prepare("SELECT * FROM backups WHERE name = ? ORDER BY created_at DESC LIMIT 1")->execute([$name])->fetch();
        } else {
            jsonResponse(['error' => 'Kein Backup angegeben'], 400);
        }
        if (!$row) { jsonResponse(['error' => 'Backup nicht gefunden'], 404); }
        if (!copy($row['file_path'], DB_PATH)) {
            jsonResponse(['error' => 'Wiederherstellung fehlgeschlagen'], 500);
        }
        Auth::logActivity('backup_restore', 'backup', (int) $row['id'], 'Datenbank-Backup wiederhergestellt');
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}

/**
 * Server-Systemstatistiken auslesen (Linux /proc).
 */
function serverStats(): array {
    $stats = [];
    // CPU
    $stat = @file_get_contents('/proc/stat');
    if ($stat && preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $m)) {
        $total = array_sum(array_slice($m, 1));
        $idle  = (int) $m[4];
        $stats['cpu_load'] = $total > 0 ? round((1 - $idle / $total) * 100, 1) : 0;
    }
    // RAM
    $mem = @file_get_contents('/proc/meminfo');
    if ($mem) {
        preg_match('/MemTotal:\s+(\d+)/', $mem, $mt);
        preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
        $total = (int) ($mt[1] ?? 0); // KB
        $avail = (int) ($ma[1] ?? 0);
        $stats['ram_total'] = $total;
        $stats['ram_available'] = $avail;
        $stats['ram_used'] = $total - $avail;
        $stats['ram_percent'] = $total > 0 ? round((1 - $avail / $total) * 100, 1) : 0;
    }
    // Temperatur (Raspberry Pi)
    $temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
    if ($temp !== false) {
        $stats['temperature'] = round((int) trim($temp) / 1000, 1);
    }
    // Festplatte
    $disk = @disk_free_space('/');
    $totalDisk = @disk_total_space('/');
    if ($disk !== false) {
        $stats['disk_free'] = $disk;
        $stats['disk_total'] = $totalDisk;
        $stats['disk_percent'] = $totalDisk > 0 ? round((1 - $disk / $totalDisk) * 100, 1) : 0;
    }
    // Uptime
    $up = @file_get_contents('/proc/uptime');
    if ($up) {
        $stats['uptime_seconds'] = (float) explode(' ', trim($up))[0];
    }
    // Software
    $stats['php_version'] = phpversion();
    $stats['ffmpeg_version'] = trim(@shell_exec('ffmpeg -version 2>/dev/null | head -n 1') ?? 'nicht verfügbar');
    $stats['hostname'] = php_uname('n');
    return $stats;
}
