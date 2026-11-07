<?php
declare(strict_types=1);

define('SF_DATA', rtrim(getenv('SF_DATA') ?: '/data', '/'));
define('SF_MEDIA', rtrim(getenv('SF_MEDIA') ?: '/media', '/'));
define('SF_ROOT', dirname(__DIR__));
define('SF_VERSION', '1.0.0');

foreach (['', '/sessions', '/cache', '/cache/hls', '/cache/sub', '/backups', '/images', '/tmp'] as $d) {
    if (!is_dir(SF_DATA . $d)) { @mkdir(SF_DATA . $d, 0750, true); }
}

/* ---------- Datenbank ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . SF_DATA . '/streamflix.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=8000; PRAGMA foreign_keys=ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $lock = fopen(SF_DATA . '/migrate.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_version'")->fetch();
        if (!$has) {
            $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
            $pdo->beginTransaction();
            $pdo->exec(file_get_contents(SF_ROOT . '/database/schema.sql'));
            $pdo->exec('INSERT INTO schema_version(version) VALUES (1)');
            $pdo->commit();
        }
        $cur = (int)$pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn();
        $files = glob(SF_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $v = (int)basename($f);
            if ($v > $cur) {
                $pdo->beginTransaction();
                try {
                    $pdo->exec(file_get_contents($f));
                    $pdo->exec("INSERT INTO schema_version(version) VALUES ($v)");
                    $pdo->commit();
                } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            }
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function qa(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function q1(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function qv(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

/* ---------- Einstellungen ---------- */
function setting(string $k, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (qa('SELECT key,value FROM system_settings') as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache[$k] ?? $default;
}
function set_setting(string $k, string $v): void {
    q('INSERT INTO system_settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$k, $v]);
}

/* ---------- HTTP-Helfer ---------- */
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function out($data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

function input(): array {
    static $i = null;
    if ($i === null) {
        $raw = file_get_contents('php://input');
        $i = json_decode($raw ?: '[]', true);
        if (!is_array($i)) $i = [];
    }
    return $i;
}

/* ---------- Sessions & Auth ---------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('SFSESS');
    session_save_path(SF_DATA . '/sessions');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '2592000');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '200');
    session_set_cookie_params([
        'lifetime' => 2592000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function check_csrf(): void {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) fail('Ungültiges CSRF-Token. Bitte Seite neu laden.', 403);
}

const ROLE_LEVEL = ['user' => 1, 'moderator' => 2, 'admin' => 3, 'superadmin' => 4];

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $row = q1('SELECT id,username,email,role,status,must_change_password FROM users WHERE id=?', [$_SESSION['uid']]);
        if ($row && $row['status'] === 'active') $u = $row;
        else { $_SESSION = []; }
    }
    return $u;
}
function require_login(): array {
    $u = current_user();
    if (!$u) fail('Nicht angemeldet.', 401);
    return $u;
}
function has_role(array $u, string $min): bool { return ROLE_LEVEL[$u['role']] >= ROLE_LEVEL[$min]; }
function require_role(string $min): array {
    $u = require_login();
    if (!has_role($u, $min)) fail('Keine Berechtigung.', 403);
    return $u;
}
function current_profile_id(): int {
    $u = require_login();
    $pid = (int)($_SESSION['pid'] ?? 0);
    if (!$pid || !q1('SELECT 1 FROM profiles WHERE id=? AND user_id=?', [$pid, $u['id']])) fail('Kein Profil ausgewählt.', 409);
    return $pid;
}

function log_activity(string $action, string $detail = '', string $level = 'info', ?array $user = null): void {
    try {
        $u = $user ?? (function_exists('current_user') && session_status() === PHP_SESSION_ACTIVE ? current_user() : null);
        q('INSERT INTO activity_logs(user_id,username,action,detail,ip,level) VALUES(?,?,?,?,?,?)',
          [$u['id'] ?? null, $u['username'] ?? null, $action, mb_substr($detail, 0, 1000), client_ip(), $level]);
    } catch (Throwable $e) { error_log('log_activity: ' . $e->getMessage()); }
}

function rate_limit(string $key, int $max, int $window): void {
    $now = time();
    $r = q1('SELECT count,window_start FROM rate_limits WHERE key=?', [$key]);
    if (!$r || $r['window_start'] < $now - $window) {
        q('INSERT INTO rate_limits(key,count,window_start) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=1,window_start=excluded.window_start', [$key, $now]);
        return;
    }
    if ($r['count'] >= $max) fail('Zu viele Versuche. Bitte später erneut versuchen.', 429);
    q('UPDATE rate_limits SET count=count+1 WHERE key=?', [$key]);
}
function rate_reset(string $key): void { q('DELETE FROM rate_limits WHERE key=?', [$key]); }

function valid_password(string $p): ?string {
    if (mb_strlen($p) < 8) return 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    if (mb_strlen($p) > 200) return 'Das Passwort ist zu lang.';
    return null;
}

/* ---------- Pfade ---------- */
/** Liefert den realen Pfad nur, wenn er innerhalb des Medienstamms liegt. */
function safe_media_path(string $path): ?string {
    $rp = realpath($path);
    if ($rp === false) return null;
    $root = realpath(SF_MEDIA);
    if ($root === false) return null;
    if ($rp === $root || str_starts_with($rp, $root . DIRECTORY_SEPARATOR)) return $rp;
    return null;
}

function human_bytes(float $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}

function sh_run(string $cmd, int $timeout = 30): string {
    $out = shell_exec('timeout ' . (int)$timeout . ' ' . $cmd . ' 2>/dev/null');
    return $out === null ? '' : $out;
}
