<?php
// StreamFlix — API: Authentifizierung
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
            jsonResponse(['error' => 'Ungültiger CSRF-Token'], 403);
        }
        $user = Auth::login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($user) {
            jsonResponse(['success' => true, 'user' => $user]);
        } else {
            jsonResponse(['error' => 'Benutzername oder Passwort ungültig'], 401);
        }
        break;

    case 'register':
        if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
            jsonResponse(['error' => 'Ungültiger CSRF-Token'], 403);
        }
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM system_settings WHERE key='registration_enabled'")->fetch();
        if ($row && $row['value'] === '0') {
            jsonResponse(['error' => 'Registrierung deaktiviert'], 403);
        }
        $res = Auth::register($_POST['username'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($res['success']) {
            jsonResponse(['success' => true, 'user_id' => $res['user_id']]);
        } else {
            jsonResponse(['error' => $res['error'] ?? 'Registrierung fehlgeschlagen'], 400);
        }
        break;

    case 'me':
        apiRequireAuth();
        jsonResponse(['user' => Auth::user()]);
        break;

    case 'logout':
        Auth::logout();
        jsonResponse(['success' => true]);
        break;

    case 'csrf':
        jsonResponse(['csrf_token' => Security::csrfToken()]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
