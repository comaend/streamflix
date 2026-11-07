<?php
// StreamFlix — Sicherheitsfunktionen (CSRF, XSS, Path-Traversal, Rate-Limit)
declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Security {
    public static function csrfToken(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    public static function verifyCsrf(string $token): bool {
        return isset($_SESSION[CSRF_TOKEN_NAME])
            && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }

    public static function sanitize(string $input): string {
        return htmlspecialchars((string) $input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function safeMediaPath(string $path): string {
        // Path-Traversal-Schutz: Datei muss innerhalb von MEDIA_ROOT liegen
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return '';
        }
        $mediaRoot = realpath(MEDIA_ROOT);
        if ($mediaRoot === false) {
            return '';
        }
        if ($real !== $mediaRoot && strpos($real, $mediaRoot . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        return $real;
    }

    public static function rateLimit(string $key): bool {
        $now = time();
        $window = RATE_LIMIT_WINDOW;
        $attempts = $_SESSION['rate_' . $key] ?? ['count' => 0, 'start' => $now];
        if ($now - $attempts['start'] > $window) {
            $attempts = ['count' => 0, 'start' => $now];
        }
        $attempts['count']++;
        $_SESSION['rate_' . $key] = $attempts;
        return $attempts['count'] <= RATE_LIMIT_ATTEMPTS;
    }
}
