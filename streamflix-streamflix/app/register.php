<?php
// StreamFlix — Registrierung
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';

$page      = '';
$pageTitle = 'Registrieren';
$error = '';
$success = '';

$regEnabled = true;
$db = Database::get();
$row = $db->prepare("SELECT value FROM system_settings WHERE key='registration_enabled'")->fetch();
if ($row && $row['value'] === '0') {
    $regEnabled = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$regEnabled) {
        $error = 'Registrierung ist derzeit deaktiviert.';
    } elseif (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültige CSRF-Token.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (strlen($username) < 3 || strlen($password) < 6) {
            $error = 'Benutzername (min. 3) und Passwort (min. 6) sind erforderlich.';
        } else {
            $res = Auth::register($username, $email, $password);
            if ($res['success']) {
                $success = 'Konto erstellt. Bitte melde dich an.';
            } else {
                $error = $res['error'] ?? 'Registrierung fehlgeschlagen.';
            }
        }
    }
}
require __DIR__ . '/templates/header.php';
?>

<section class="auth-page">
  <div class="auth-card">
    <div class="auth-header">
      <a href="index.php" class="nav-logo">STREAMFLIX</a>
      <h2>Konto erstellen</h2>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= Security::sanitize($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= Security::sanitize($success) ?></div>
    <?php endif; ?>
    <?php if (!$regEnabled): ?>
      <div class="alert alert-error">Registrierung ist derzeit deaktiviert.</div>
    <?php endif; ?>
    <?php if ($regEnabled): ?>
    <form method="POST" action="" novalidate>
      <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
      <div class="form-group">
        <label>Benutzername</label>
        <input type="text" name="username" required autocomplete="username" minlength="3">
      </div>
      <div class="form-group">
        <label>E-Mail-Adresse</label>
        <input type="email" name="email" required autocomplete="email">
      </div>
      <div class="form-group">
        <label>Passwort</label>
        <input type="password" name="password" required autocomplete="new-password" minlength="6">
      </div>
      <button type="submit" class="btn btn-red btn-block">Konto erstellen</button>
    </form>
    <?php endif; ?>
    <p class="auth-footer">Bereits registriert? <a href="login.php">Jetzt anmelden</a></p>
  </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
