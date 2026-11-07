<?php
// StreamFlix — Login
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

$page      = '';
$pageTitle = 'Anmelden';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültige CSRF-Token.';
    } elseif (!Security::rateLimit('login')) {
        $error = 'Zu viele Versuche. Bitte später erneut versuchen.';
    } else {
        $user = Auth::login($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($user) {
            redirect('/index.php');
        } else {
            $error = 'Benutzername oder Passwort ungültig.';
        }
    }
}
require __DIR__ . '/templates/header.php';
?>

<section class="auth-page">
  <div class="auth-card">
    <div class="auth-header">
      <a href="index.php" class="nav-logo">STREAMFLIX</a>
      <h2>Willkommen zurück</h2>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= Security::sanitize($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="" novalidate>
      <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
      <div class="form-group">
        <label>Benutzername oder E-Mail</label>
        <input type="text" name="username" required autocomplete="username">
      </div>
      <div class="form-group">
        <label>Passwort</label>
        <input type="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-red btn-block">Anmelden</button>
    </form>
    <p class="auth-footer">Noch kein Konto? <a href="register.php">Kostenlos registrieren</a></p>
  </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
