<?php
// StreamFlix — Profilverwaltung (angemeldeter Benutzer)
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

if (!Auth::isLoggedIn()) { redirect('/login.php'); exit; }

$page     = 'profile';
$pageTitle = 'Mein Profil';
$userId   = (int) $_SESSION['user_id'];
$profiles = userProfiles($userId);
$profileId = activeProfileId();
$active   = $profileId ? getProfile($profileId) : null;

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültige CSRF-Token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'set_profile') {
            $pid = (int) ($_POST['profile_id'] ?? 0);
            $ok = false;
            foreach ($profiles as $p) { if ((int) $p['id'] === $pid) { $ok = true; break; } }
            if ($ok) { setActiveProfile($pid); $success = 'Profil gewechselt.'; } else { $error = 'Ungültiges Profil.'; }
        } elseif ($action === 'add_profile') {
            $name = trim($_POST['name'] ?? '');
            $pin = trim($_POST['pin'] ?? '');
            if (strlen($name) < 1) { $error = 'Profilname erforderlich.'; }
            else {
                $db = Database::get();
                $db->prepare("INSERT INTO profiles (user_id, name, pin) VALUES (?, ?, ?)")->execute([$userId, $name, $pin ?: null]);
                setActiveProfile((int) $db->lastInsertId());
                $success = 'Profil erstellt.';
            }
        }
    }
}
require __DIR__ . '/templates/header.php';
?>

<section class="profile-page">
  <div class="page-header"><h1>Profile</h1></div>
  <?php if ($error): ?><div class="alert alert-error"><?= Security::sanitize($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= Security::sanitize($success) ?></div><?php endif; ?>

  <div class="profile-section">
    <h2>Aktives Profil</h2>
    <?php if ($active): ?>
      <div class="profile-card">
        <div class="profile-avatar"><?= strtoupper(substr(Security::sanitize($active['name']),0,1)) ?></div>
        <div class="profile-info">
          <h3><?= Security::sanitize($active['name']) ?></h3>
          <span><?= $active['pin'] ? 'PIN-geschützt' : 'Kein PIN' ?></span>
        </div>
      </div>
    <?php endif; ?>
    <form method="POST" class="profile-switch-form" style="margin-top:1rem;">
      <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
      <input type="hidden" name="action" value="set_profile">
      <select name="profile_id" class="filter-select">
        <?php foreach ($profiles as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= ($profileId === (int) $p['id']) ? 'selected' : '' ?>><?= Security::sanitize($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-red">Profil laden</button>
    </form>
  </div>

  <div class="profile-section">
    <h2>Neues Profil erstellen</h2>
    <form method="POST" class="profile-add-form">
      <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
      <input type="hidden" name="action" value="add_profile">
      <div class="form-group"><label>Profilname</label><input type="text" name="name" required></div>
      <div class="form-group"><label>PIN (optional, 4-stellig)</label><input type="text" name="pin" maxlength="4" pattern="\d{4}"></div>
      <button type="submit" class="btn btn-red">Profil erstellen</button>
    </form>
  </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
