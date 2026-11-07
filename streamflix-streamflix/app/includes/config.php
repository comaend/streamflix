<?php
// StreamFlix — Zentrale Konfiguration
declare(strict_types=1);

// Pfade
define('APP_ROOT', __DIR__ . '/..');
define('APP_DATA_DIR', getenv('APP_DATA_DIR') ?: (APP_ROOT . '/data'));
define('MEDIA_ROOT', getenv('MEDIA_ROOT') ?: '/media');
define('DB_PATH', APP_DATA_DIR . '/streamflix.sqlite');

// Sicherheit & Session
define('SESSION_LIFETIME', 86400 * 7); // 7 Tage
define('CSRF_TOKEN_NAME', 'streamflix_csrf');
define('RATE_LIMIT_ATTEMPTS', 5);
define('RATE_LIMIT_WINDOW', 300); // Sekunden

// Fehlerberichterstattung (Produktion: aus)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Session starten (sicher, cookie-basiert)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}
