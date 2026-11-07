<?php
// StreamFlix — API: Wiedergabe (Stream, Fortschritt)
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/streaming.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'stream':
        apiRequireAuth();
        $type = $_GET['type'] ?? 'movie';
        $id   = (int) ($_GET['id'] ?? 0);
        $file = '';
        $mime = 'video/mp4';
        if ($type === 'movie') {
            $mf = Media::mediaFile('movie', $id);
            $file = $mf['file_path'] ?? '';
        } elseif ($type === 'episode') {
            $db = Database::get();
            $ep = $db->prepare("SELECT file_path FROM episodes WHERE id=?")->execute([$id])->fetch();
            $file = $ep['file_path'] ?? '';
            $mime = 'video/mp4';
        }
        $real = Security::safeMediaPath($file);
        if ($real === '') {
            http_response_code(404);
            echo json_encode(['error' => 'Datei nicht gefunden oder nicht lesbar']);
            exit;
        }
        Streaming::serveFile($real, $mime);
        break;

    case 'progress':
        apiRequireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['error' => 'POST erforderlich'], 405);
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $type   = $input['type'] ?? '';
        $id     = (int) ($input['id'] ?? 0);
        $pos    = (int) ($input['position'] ?? 0);
        $dur    = (int) ($input['duration'] ?? 0);
        $pid    = activeProfileId();
        if (!$pid || !in_array($type, ['movie', 'series', 'episode'], true)) {
            jsonResponse(['error' => 'Ungültige Daten'], 400);
        }
        $db = Database::get();
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO watch_progress (user_id, profile_id, media_type, media_id, position, duration, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(user_id, profile_id, media_type, media_id) DO UPDATE SET
                   position=excluded.position, duration=excluded.duration, updated_at=datetime('now')"
            )->execute([$_SESSION['user_id'], $pid, $type, $id, $pos, $dur]);
            // In Historie aufnehmen (wenn fast zu Ende)
            if ($dur > 0 && $pos >= $dur - 30) {
                $db->prepare(
                    "INSERT INTO watch_history (user_id, profile_id, media_type, media_id, position, duration, watched_at)
                     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))"
                )->execute([$_SESSION['user_id'], $pid, $type, $id, $pos, $dur]);
            }
            $db->commit();
            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            $db->rollBack();
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    case 'progress_get':
        apiRequireAuth();
        $type = $_GET['type'] ?? '';
        $id   = (int) ($_GET['id'] ?? 0);
        $pid  = activeProfileId();
        if (!$pid) { jsonResponse(['error' => 'Kein Profil'], 400); }
        $db = Database::get();
        $row = $db->prepare(
            "SELECT position, duration FROM watch_progress WHERE user_id=? AND profile_id=? AND media_type=? AND media_id=?"
        )->execute([$_SESSION['user_id'], $pid, $type, $id])->fetch();
        jsonResponse(['position' => $row ? (int) $row['position'] : 0, 'duration' => $row ? (int) $row['duration'] : 0]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
