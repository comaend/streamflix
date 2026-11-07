<?php
// StreamFlix — Admin: Medienordner
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'folders';
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) { die('CSRF-Fehler'); }
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $db->prepare("INSERT INTO media_folders (name, path, type, is_active) VALUES (?, ?, ?, 1)")
           ->execute([trim($_POST['name'] ?? ''), trim($_POST['path'] ?? ''), $_POST['type'] ?? 'mixed']);
    } elseif ($do === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare("UPDATE media_folders SET name=?, path=?, type=?, is_active=? WHERE id=?")
           ->execute([trim($_POST['name'] ?? ''), trim($_POST['path'] ?? ''), $_POST['type'] ?? 'mixed', (int) ($_POST['is_active'] ?? 1), $id]);
    } elseif ($do === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM media_folders WHERE id = ?")->execute([$id]);
    } elseif ($do === 'scan') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare("INSERT INTO scan_history (media_folder_id, status, started_at) VALUES (?, 'running', datetime('now'))")->execute([$id]);
    }
    header('Location: folders.php');
    exit;
}

$folders = $db->query("SELECT * FROM media_folders ORDER BY id ASC")->fetchAll();
$scans   = $db->query("SELECT * FROM scan_history ORDER BY started_at DESC LIMIT 20")->fetchAll();
?>
<div class="admin-card">
  <h2>Medienordner</h2>
  <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:1rem;">
    <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
    <input type="hidden" name="do" value="add">
    <input type="text" name="name" placeholder="Name (z. B. Filme)" required style="padding:6px;">
    <input type="text" name="path" placeholder="Pfad (z. B. /media/Movies)" required style="padding:6px;">
    <select name="type" style="padding:6px;">
      <option value="mixed">Gemischt</option>
      <option value="movies">Filme</option>
      <option value="series">Serien</option>
    </select>
    <button type="submit" class="btn btn-red">Hinzufügen</button>
  </form>
  <table class="admin-table">
    <thead><tr><th>ID</th><th>Name</th><th>Pfad</th><th>Typ</th><th>Aktiv</th><th>Letzter Scan</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($folders as $f): ?>
      <tr>
        <td><?= (int) $f['id'] ?></td>
        <td><?= Security::sanitize($f['name']) ?></td>
        <td><?= Security::sanitize($f['path']) ?></td>
        <td><?= Security::sanitize($f['type']) ?></td>
        <td><?= $f['is_active'] ? 'Ja' : 'Nein' ?></td>
        <td><?= Security::sanitize($f['last_scan'] ?? '-') ?></td>
        <td class="actions">
          <button class="btn btn-sm btn-outline" data-sf-action="folder_scan" data-sf-id="<?= (int) $f['id'] ?>" data-sf-confirm="Scan für '<?= Security::sanitize($f['name']) ?>' starten?" style="padding:4px 8px;">Scannen</button>
          <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Ordner wirklich entfernen? (Nur Verknüpfung, keine Mediendaten)');">
            <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Entfernen</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="admin-card" style="margin-top:1rem;">
  <h3>Letzte Scans</h3>
  <table class="admin-table">
    <thead><tr><th>Ordner-ID</th><th>Status</th><th>Dateien</th><th>Filme</th><th>Serien</th><th>Gestartet</th><th>Fehler</th></tr></thead>
    <tbody>
    <?php foreach ($scans as $s): ?>
      <tr>
        <td><?= (int) $s['media_folder_id'] ?></td>
        <td><?= Security::sanitize($s['status']) ?></td>
        <td><?= (int) $s['files_found'] ?></td>
        <td><?= (int) $s['movies_added'] ?></td>
        <td><?= (int) $s['series_added'] ?></td>
        <td><?= Security::sanitize($s['started_at']) ?></td>
        <td><?= Security::sanitize($s['error'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
