<?php
// StreamFlix — Admin-Header (Zugriffskontrolle)
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';

if (!Auth::isLoggedIn()) {
    header('Location: /login.php');
    exit;
}
$page       = $page ?? 'dashboard';
$canUsers   = Auth::can('users');
$canMedia   = Auth::can('media');
$canSettings = Auth::can('settings');
$canFolders = Auth::can('folders');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin-Center · StreamFlix</title>
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-page">
<nav class="admin-navbar">
  <div class="admin-container">
    <a href="index.php" class="admin-logo">STREAMFLIX <span class="admin-sub">Admin</span></a>
    <div class="admin-links">
      <?php if ($canMedia): ?><a href="index.php" class="admin-link <?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a><?php endif; ?>
      <?php if ($canUsers): ?><a href="users.php" class="admin-link <?= $page === 'users' ? 'active' : '' ?>">Benutzerverwaltung</a><?php endif; ?>
      <?php if ($canUsers): ?><a href="profiles.php" class="admin-link <?= $page === 'profiles' ? 'active' : '' ?>">Profile</a><?php endif; ?>
      <?php if ($canMedia): ?><a href="media.php" class="admin-link <?= $page === 'media' ? 'active' : '' ?>">Medienverwaltung</a><?php endif; ?>
      <?php if ($canFolders || $canUsers): ?><a href="folders.php" class="admin-link <?= $page === 'folders' ? 'active' : '' ?>">Medienordner</a><?php endif; ?>
      <?php if ($canMedia): ?><a href="streams.php" class="admin-link <?= $page === 'streams' ? 'active' : '' ?>">Aktive Streams</a><?php endif; ?>
      <?php if ($canSettings): ?><a href="server.php" class="admin-link <?= $page === 'server' ? 'active' : '' ?>">Server</a><?php endif; ?>
      <?php if ($canSettings): ?><a href="settings.php" class="admin-link <?= $page === 'settings' ? 'active' : '' ?>">Globale Einstellungen</a><?php endif; ?>
      <?php if ($canSettings): ?><a href="logs.php" class="admin-link <?= $page === 'logs' ? 'active' : '' ?>">Aktivitätsprotokoll</a><?php endif; ?>
      <?php if ($canSettings): ?><a href="backups.php" class="admin-link <?= $page === 'backups' ? 'active' : '' ?>">Backups</a><?php endif; ?>
    </div>
    <a href="/logout.php" class="admin-logout">Abmelden</a>
  </div>
</nav>
<div class="admin-container">
