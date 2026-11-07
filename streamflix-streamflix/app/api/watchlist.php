<?php
// StreamFlix — API: Watchlist (Meine Liste)
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/security.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
apiRequireAuth();
$pid = activeProfileId();
if (!$pid) {
    jsonResponse(['error' => 'Kein aktives Profil'], 400);
}

$db = Database::get();

switch ($action) {
    case 'list':
        $rows = $db->prepare(
            "SELECT m.id, m.title, m.year, m.poster, m.runtime, 'movie' AS type
             FROM watchlist w JOIN movies m ON w.media_type='movie' AND w.media_id=m.id
             WHERE w.profile_id = ?
             UNION ALL
             SELECT s.id, s.title, s.year, s.poster, NULL AS runtime, 'series' AS type
             FROM watchlist w JOIN series s ON w.media_type='series' AND w.media_id=s.id
             WHERE w.profile_id = ?
             ORDER BY w.added_at DESC"
        )->execute([$pid, $pid]);
        jsonResponse(['items' => $rows->fetchAll()]);
        break;

    case 'is_in':
        $type = $_GET['type'] ?? '';
        $id   = (int) ($_GET['id'] ?? 0);
        $row = $db->prepare(
            "SELECT 1 FROM watchlist WHERE profile_id=? AND media_type=? AND media_id=? LIMIT 1"
        )->execute([$pid, $type, $id])->fetch();
        jsonResponse(['in_watchlist' => (bool) $row]);
        break;

    case 'add':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $type  = $input['type'] ?? '';
        $id    = (int) ($input['id'] ?? 0);
        if (!in_array($type, ['movie', 'series', 'episode'], true)) {
            jsonResponse(['error' => 'Ungültiger Medientyp'], 400);
        }
        try {
            $db->prepare(
                "INSERT OR IGNORE INTO watchlist (user_id, profile_id, media_type, media_id, added_at)
                 VALUES (?, ?, ?, ?, datetime('now'))"
            )->execute([$_SESSION['user_id'], $pid, $type, $id]);
            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    case 'remove':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $type  = $input['type'] ?? '';
        $id    = (int) ($input['id'] ?? 0);
        $db->prepare(
            "DELETE FROM watchlist WHERE profile_id=? AND media_type=? AND media_id=?"
        )->execute([$pid, $type, $id]);
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
