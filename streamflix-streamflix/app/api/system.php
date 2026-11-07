<?php
// StreamFlix — API: Systeminformationen (leichtgewichtig)
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

apiRequireAuth();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'info':
        jsonResponse([
            'php_version' => phpversion(),
            'ffmpeg'      => trim(@shell_exec('ffmpeg -version 2>/dev/null | head -n 1') ?? 'nicht verfügbar'),
            'ffprobe'     => trim(@shell_exec('ffprobe -version 2>/dev/null | head -n 1') ?? 'nicht verfügbar'),
            'hostname'    => php_uname('n'),
            'os'          => php_uname('s') . ' ' . php_uname('r'),
        ]);
        break;
    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
