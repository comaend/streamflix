<?php
// StreamFlix — API: Bewertungen
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
    case 'get':
        $type = $_GET['type'] ?? '';
        $id   = (int) ($_GET['id'] ?? 0);
        $row = $db->prepare(
            "SELECT rating FROM ratings WHERE profile_id=? AND media_type=? AND media_id=? LIMIT 1"
        )->execute([$pid, $type, $id])->fetch();
        jsonResponse(['rating' => $row ? (int) $row['rating'] : 0]);
        break;

    case 'set':
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $type  = $input['type'] ?? '';
        $id    = (int) ($input['id'] ?? 0);
        $rate  = (int) ($input['rating'] ?? 0);
        if (!in_array($type, ['movie', 'series', 'episode'], true) || $rate < 1 || $rate > 5) {
            jsonResponse(['error' => 'Ungültige Bewertung'], 400);
        }
        try {
            $db->prepare(
                "INSERT INTO ratings (user_id, profile_id, media_type, media_id, rating, created_at)
                 VALUES (?, ?, ?, ?, ?, datetime('now'))
                 ON CONFLICT(user_id, profile_id, media_type, media_id) DO UPDATE SET
                   rating=excluded.rating, created_at=datetime('now')"
            )->execute([$_SESSION['user_id'], $pid, $type, $id, $rate]);
            Auth::logActivity('rate', $type, $id, "Bewertung $rate vergeben");
            jsonResponse(['success' => true]);
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
