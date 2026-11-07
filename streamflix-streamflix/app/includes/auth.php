<?php
// StreamFlix — Authentifizierung & Autorisierung
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

class Auth {
    public static function login(string $usernameOrEmail, string $password): ?array {
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1"
        );
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['role']      = $user['role'];
        $_SESSION['logged_in'] = true;

        $db->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")
           ->execute([(int) $user['id']]);
        self::logActivity('login', 'user', (int) $user['id'], 'Erfolgreicher Login');
        return [
            'id'    => (int) $user['id'],
            'username' => $user['username'],
            'role'  => $user['role'],
        ];
    }

    public static function logout(): void {
        $db = Database::get();
        if (!empty($_SESSION['user_id'])) {
            self::logActivity('logout', 'user', (int) $_SESSION['user_id'], 'Logout');
            $db->prepare("DELETE FROM sessions WHERE user_id = ?")
               ->execute([(int) $_SESSION['user_id']]);
        }
        session_destroy();
    }

    public static function isLoggedIn(): bool {
        return !empty($_SESSION['logged_in']);
    }

    public static function user(): ?array {
        if (!self::isLoggedIn()) {
            return null;
        }
        return [
            'id'       => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role'     => $_SESSION['role'],
        ];
    }

    public static function hasRole(string $role): bool {
        $u = self::user();
        return $u !== null && $u['role'] === $role;
    }

    public static function can(string $action): bool {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'superadmin') {
            return true;
        }
        $perms = [
            'admin'     => ['users', 'media', 'settings', 'streams', 'logs', 'backups', 'folders'],
            'moderator' => ['users', 'media', 'folders'],
            'user'      => [],
        ];
        return in_array($action, $perms[$u['role']] ?? [], true);
    }

    public static function register(string $username, string $email, string $password): array {
        $db = Database::get();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')"
            );
            $stmt->execute([$username, $email, $hash]);
            $userId = (int) $db->lastInsertId();
            $db->prepare(
                "INSERT INTO profiles (user_id, name) VALUES (?, ?)"
            )->execute([$userId, htmlspecialchars($username, ENT_QUOTES) . "'s Profil"]);
            $db->commit();
            self::logActivity('register', 'user', $userId, 'Neuer Benutzer registriert');
            return ['success' => true, 'user_id' => $userId];
        } catch (Throwable $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function changePassword(int $userId, string $newPassword): bool {
        $db = Database::get();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        return (bool) $db->prepare(
            "UPDATE users SET password_hash = ? WHERE id = ?"
        )->execute([$hash, $userId]);
    }

    public static function logActivity(string $action, string $entityType = '', int $entityId = 0, string $details = ''): void {
        if (!self::isLoggedIn()) {
            return;
        }
        $db = Database::get();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $db->prepare(
            "INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([(int) $_SESSION['user_id'], $action, $entityType, $entityId, $details, $ip]);
    }
}
