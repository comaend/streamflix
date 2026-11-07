<?php
// StreamFlix — Admin: Benutzerverwaltung
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'users';
$db = Database::get();

// Mutationen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) { die('CSRF-Fehler'); }
    $do = $_POST['do'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($do === 'delete' && $id && $id !== (int) $_SESSION['user_id']) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    } elseif ($do === 'lock' && $id) {
        $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
    } elseif ($do === 'unlock' && $id) {
        $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$id]);
    } elseif ($do === 'role' && $id) {
        $role = $_POST['role'] ?? 'user';
        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
    } elseif ($do === 'password_reset' && $id) {
        $pw = $_POST['new_password'] ?? 'StreamFlix2024!';
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
    }
    header('Location: users.php');
    exit;
}

$users = $db->query("SELECT id, username, email, role, is_active, last_login, created_at FROM users ORDER BY id ASC")->fetchAll();
?>
<div class="admin-card">
  <h2>Benutzerverwaltung</h2>
  <div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th>ID</th><th>Benutzername</th><th>E-Mail</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th>Erstellt</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int) $u['id'] ?></td>
        <td><?= Security::sanitize($u['username']) ?></td>
        <td><?= Security::sanitize($u['email']) ?></td>
        <td>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
            <input type="hidden" name="do" value="role">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <select name="role" class="filter-select" onchange="this.form.submit()" style="padding:4px;">
              <option value="user" <?= $u['role']==='user'?'selected':'' ?>>User</option>
              <option value="moderator" <?= $u['role']==='moderator'?'selected':'' ?>>Moderator</option>
              <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
              <option value="superadmin" <?= $u['role']==='superadmin'?'selected':'' ?>>Superadmin</option>
            </select>
          </form>
        </td>
        <td><?= $u['is_active'] ? '<span style="color:#46d36a">● Aktiv</span>' : '<span style="color:#e50914">● Gesperrt</span>' ?></td>
        <td><?= Security::sanitize($u['last_login'] ?? '-') ?></td>
        <td><?= Security::sanitize($u['created_at']) ?></td>
        <td class="actions">
          <?php if ((int) $u['id'] !== (int) $_SESSION['user_id']): ?>
            <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Benutzer wirklich löschen?');">
              <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
            </form>
            <?php if ($u['is_active']): ?>
              <form method="POST" style="display:inline;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
                <input type="hidden" name="do" value="lock">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline">Sperren</button>
              </form>
            <?php else: ?>
              <form method="POST" style="display:inline;margin:0;">
                <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
                <input type="hidden" name="do" value="unlock">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline">Entsperren</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline" onclick="showReset(<?= (int) $u['id'] ?>)">Passwort zurücksetzen</button>
          <div id="reset-<?= (int) $u['id'] ?>" class="hidden" style="margin-top:4px;">
            <form method="POST" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
              <input type="hidden" name="do" value="password_reset">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="password" name="new_password" placeholder="Neues Passwort" required style="padding:4px;margin-right:4px;">
              <button type="submit" class="btn btn-sm btn-red">Setzen</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<script>
function showReset(id){ const el=document.getElementById('reset-'+id); el.classList.toggle('hidden'); }
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
