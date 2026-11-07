<?php
// StreamFlix — Konto (Passwort ändern)
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';

if (!Auth::isLoggedIn()) { redirect('/login.php'); exit; }

$page     = 'account';
$pageTitle = 'Konto';
$userId   = (int) $_SESSION['user_id'];
$error = ''; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültige CSRF-Token.';
    } else {
        $old     = $_POST['old_password'] ?? '';
        $new1    = $_POST['new_password'] ?? '';
        $new2    = $_POST['new_password2'] ?? '';
        $db = Database::get();
        $user = $db->prepare("SELECT password_hash FROM users WHERE id=?")->execute([$userId])->fetch();
        if (!$user || !password_verify($old, $user['password_hash'])) {
            $error = 'Altes Passwort ist falsch.';
        } elseif ($new1 !== $new2 || strlen($new1) < 6) {
            $error = 'Neues Passwort stimmt nicht überein oder ist zu kurz.';
        } else {
            Auth::changePassword($userId, $new1);
            $success = 'Passwort wurde geändert.';
        }
    }
}
require __DIR__ . '/templates/header.php';
?>

<section class="account-page">
  <div class="page-header"><h1>Kontoeinstellungen</h1></div>
  <?php if ($error): ?><div class="alert alert-error"><?= Security::sanitize($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= Security::sanitize($success) ?></div><?php endif; ?>
  <form method="POST" class="account-form">
    <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
    <div class="form-group"><label>Derzeitiges Passwort</label><input type="password" name="old_password" required></div>
    <div class="form-group"><label>Neues Passwort</label><input type="password" name="new_password" required minlength="6"></div>
    <div class="form-group"><label>Neues Passwort wiederholen</label><input type="password" name="new_password2" required minlength="6"></div>
    <button type="submit" class="btn btn-red">Passwort ändern</button>
  </form>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
