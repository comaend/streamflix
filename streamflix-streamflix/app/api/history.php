<?php
// StreamFlix — API: Wiedergabeverlauf
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
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        $rows = $db->prepare(
            "SELECT wh.*, m.title AS movie_title, s.title AS series_title
             FROM watch_history wh
             LEFT JOIN movies m ON wh.media_type='movie' AND wh.media_id=m.id
             LEFT JOIN series s ON wh.media_type='series' AND wh.media_id=s.id
             WHERE wh.profile_id = ?
             ORDER BY wh.watched_at DESC LIMIT ?"
        )->execute([$pid, $limit]);
        jsonResponse(['items' => $rows->fetchAll()]);
        break;

    case 'clear':
        $db->prepare("DELETE FROM watch_history WHERE profile_id=?")->execute([$pid]);
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
