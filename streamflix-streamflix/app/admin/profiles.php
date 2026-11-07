<?php
// StreamFlix — Admin: Profilverwaltung
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'profiles';
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) { die('CSRF-Fehler'); }
    $do = $_POST['do'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($do === 'delete' && $id) {
        $db->prepare("DELETE FROM profiles WHERE id = ?")->execute([$id]);
    } elseif ($do === 'update' && $id) {
        $name = trim($_POST['name'] ?? '');
        $pin = trim($_POST['pin'] ?? '');
        $db->prepare("UPDATE profiles SET name = ?, pin = ? WHERE id = ?")->execute([$name, $pin ?: null, $id]);
    }
    header('Location: profiles.php');
    exit;
}

$profiles = $db->query("SELECT p.*, u.username FROM profiles p JOIN users u ON p.user_id = u.id ORDER BY p.id ASC")->fetchAll();
?>
<div class="admin-card">
  <h2>Profilverwaltung</h2>
  <div class="admin-table-wrap">
  <table class="admin-table">
    <thead><tr><th>ID</th><th>Profilname</th><th>Benutzer</th><th>PIN</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($profiles as $p): ?>
      <tr>
        <td><?= (int) $p['id'] ?></td>
        <td><?= Security::sanitize($p['name']) ?></td>
        <td><?= Security::sanitize($p['username']) ?></td>
        <td><?= $p['pin'] ? '★ Geschützt' : 'Kein PIN' ?></td>
        <td class="actions">
          <button class="btn btn-sm btn-outline" onclick="editProfile(<?= (int) $p['id'] ?>, <?= json_encode($p['name']) ?>, <?= json_encode($p['pin'] ?? '') ?>)">Bearbeiten</button>
          <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Profil wirklich löschen?');">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
          </form>
          <div id="edit-<?= (int) $p['id'] ?>" class="hidden" style="margin-top:6px;">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
              <input type="hidden" name="do" value="update">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input type="text" name="name" value="<?= Security::sanitize($p['name']) ?>" required placeholder="Name" style="padding:4px;margin-right:4px;">
              <input type="text" name="pin" value="<?= Security::sanitize($p['pin'] ?? '') ?>" maxlength="4" placeholder="PIN (4-stellig)" style="padding:4px;margin-right:4px;">
              <button type="submit" class="btn btn-sm btn-red">Speichern</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="margin-top:1rem;">
    <button class="btn btn-sm btn-outline" onclick="document.getElementById('add-profile').classList.toggle('hidden')">Neues Profil erstellen</button>
    <div id="add-profile" class="hidden" style="margin-top:8px;">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
        <div style="display:flex;gap:6px;align-items:flex-end;">
          <input type="hidden" name="do" value="create">
          <input type="number" name="user_id" placeholder="Benutzer-ID" required style="padding:4px;">
          <input type="text" name="name" placeholder="Profilname" required style="padding:4px;">
          <input type="text" name="pin" maxlength="4" placeholder="PIN" style="padding:4px;">
          <button type="submit" class="btn btn-sm btn-red">Erstellen</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function editProfile(id, name, pin){
  const el=document.getElementById('edit-'+id);
  el.querySelector('input[name="name"]').value=name;
  el.querySelector('input[name="pin"]').value=pin||'';
  el.classList.toggle('hidden');
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
