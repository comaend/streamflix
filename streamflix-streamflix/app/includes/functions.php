<?php
// StreamFlix — Hilfsfunktionen
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function db(): PDO {
    return Database::get();
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiRequireAuth(): void {
    if (!Auth::isLoggedIn()) {
        jsonResponse(['error' => 'Nicht authentifiziert'], 401);
    }
}

function apiRequirePermission(string $action): void {
    if (!Auth::can($action)) {
        jsonResponse(['error' => 'Unzureichende Berechtigung'], 403);
    }
}

function redirect(string $url): void {
    header('Location: ' . $url, true, 302);
    exit;
}

function activeProfileId(): ?int {
    return !empty($_SESSION['active_profile_id']) ? (int) $_SESSION['active_profile_id'] : null;
}

function setActiveProfile(int $profileId): void {
    $_SESSION['active_profile_id'] = $profileId;
}

function getProfile(int $profileId): ?array {
    $db = Database::get();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE id = ?");
    $stmt->execute([$profileId]);
    return $stmt->fetch();
}

function userProfiles(int $userId): array {
    $db = Database::get();
    $stmt = $db->prepare("SELECT * FROM profiles WHERE user_id = ? ORDER BY id ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function formatRuntime(int $minutes): string {
    $h = (int) floor($minutes / 60);
    $m = $minutes % 60;
    return $h > 0 ? "$h Std. $m Min." : "$m Min.";
}

function formatSeconds(int $seconds): string {
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
}

function humanFilesize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
    return number_format($bytes / pow(1024, $pow), 2, ',', '.') . ' ' . $units[$pow];
}
