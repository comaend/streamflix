<?php
// StreamFlix — Admin: Backups
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'backups';
$db = Database::get();
$dir = APP_DATA_DIR . '/backups';
@mkdir($dir, 0777, true);
$backups = [];
foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $fp = $dir . '/' . $f;
    $backups[] = ['name' => $f, 'size' => (int) filesize($fp), 'created_at' => date('Y-m-d H:i:s', filemtime($fp)), 'file_path' => $fp];
}
usort($backups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
?>
<div class="admin-card">
  <h2>Backups</h2>
  <p class="hint">Es werden nur Datenbank- und Konfigurations-Backups erstellt. Originalmediendateien werden nicht gesichert.</p>
  <div style="display:flex;gap:6px;margin-bottom:1rem;">
    <button class="btn btn-red" data-sf-action="backup_create">Neues Backup erstellen</button>
    <?php if (!empty($backups)): ?>
      <a href="api/admin.php?action=backup_download&name=<?= urlencode($backups[0]['name']) ?>" class="btn btn-outline">Letztes herunterladen</a>
    <?php else: ?>
      <a href="#" class="btn btn-outline disabled">Letztes herunterladen</a>
    <?php endif; ?>
  </div>
  <table class="admin-table">
    <thead><tr><th>Name</th><th>Typ</th><th>Größe</th><th>Erstellt</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
      <tr>
        <td><?= Security::sanitize($b['name']) ?></td>
        <td>Datenbank</td>
        <td><?= humanFilesize($b['size']) ?></td>
        <td><?= Security::sanitize($b['created_at']) ?></td>
        <td class="actions">
          <a href="api/admin.php?action=backup_download&name=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline">Herunterladen</a>
          <button class="btn btn-sm btn-danger" data-sf-action="backup_restore" data-sf-name="<?= Security::sanitize($b['name']) ?>" data-sf-confirm="Backup wirklich wiederherstellen? (Überschreibt aktuelle Datenbank)" style="padding:4px 8px;">Wiederherstellen</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (empty($backups)): <p>Keine Backups vorhanden.</p> <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
