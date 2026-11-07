<?php
// StreamFlix — Header-Template (Netflix-ähnliche Navigation)
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

$page        = $page ?? 'home';
$pageTitle   = $pageTitle ?? 'StreamFlix';
$isLoggedIn  = Auth::isLoggedIn();
$profileId   = activeProfileId();
$profile     = $profileId ? getProfile($profileId) : null;
$user        = Auth::user();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= Security::sanitize($pageTitle) ?> | StreamFlix</title>
<meta name="theme-color" content="#141414">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="/assets/css/player.css">
<?php if (isset($extraCss)): ?>
    <link rel="stylesheet" href="/assets/css/<?= Security::sanitize($extraCss) ?>">
<?php endif; ?>
</head>
<body>
<nav class="navbar">
  <div class="nav-container">
    <a href="index.php" class="nav-logo">STREAMFLIX</a>
    <div class="nav-links">
      <a href="index.php" class="nav-link <?= $page === 'home' ? 'active' : '' ?>">Startseite</a>
      <a href="browse.php?type=movies" class="nav-link <?= $page === 'movies' ? 'active' : '' ?>">Filme</a>
      <a href="browse.php?type=series" class="nav-link <?= $page === 'series' ? 'active' : '' ?>">Serien</a>
      <a href="watchlist.php" class="nav-link <?= $page === 'watchlist' ? 'active' : '' ?>">Meine Liste</a>
      <a href="search.php" class="nav-link <?= $page === 'search' ? 'active' : '' ?>">Suche</a>
    </div>
    <div class="nav-right">
      <?php if ($isLoggedIn): ?>
        <div class="profile-switcher" id="profileSwitcher">
          <span class="profile-label"><?= Security::sanitize($profile ? $profile['name'] : 'Profil') ?></span>
          <button class="btn-profile" id="profileMenuBtn" aria-label="Profilmenü">▼</button>
          <div class="profile-dropdown hidden" id="profileDropdown">
            <?php foreach (userProfiles((int) $_SESSION['user_id']) as $p): ?>
              <a href="switch_profile.php?id=<?= (int) $p['id'] ?>" class="dropdown-item"><?= Security::sanitize($p['name']) ?></a>
            <?php endforeach; ?>
            <div class="dropdown-divider"></div>
            <a href="profile.php" class="dropdown-item">Profil bearbeiten</a>
            <?php if (Auth::can('users') || Auth::can('media')): ?>
              <a href="admin/" class="dropdown-item">Admin-Center</a>
            <?php endif; ?>
            <a href="account.php" class="dropdown-item">Konto</a>
            <a href="logout.php" class="dropdown-item dropdown-danger">Abmelden</a>
          </div>
        </div>
      <?php else: ?>
        <a href="login.php" class="btn-login">Anmelden</a>
        <a href="register.php" class="btn-register">Kostenlos registrieren</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main class="container">
