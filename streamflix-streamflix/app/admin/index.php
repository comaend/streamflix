<?php
// StreamFlix — Admin Dashboard
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'dashboard';
$db = Database::get();
$stats = Media::stats();
$logs  = $db->query("SELECT al.*, u.username FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 10")->fetchAll();
$streams = $db->query("SELECT s.*, u.username, p.name AS profile_name FROM active_streams s JOIN users u ON s.user_id=u.id JOIN profiles p ON s.profile_id=p.id ORDER BY s.start_time DESC LIMIT 5")->fetchAll();
?>
<div class="admin-card">
  <h2>Dashboard</h2>
  <div class="stats-grid">
    <div class="stat"><div class="stat-value"><?= $stats['movies'] ?></div><div class="stat-label">Filme</div></div>
    <div class="stat"><div class="stat-value"><?= $stats['series'] ?></div><div class="stat-label">Serien</div></div>
    <div class="stat"><div class="stat-value"><?= $stats['episodes'] ?></div><div class="stat-label">Episoden</div></div>
    <div class="stat"><div class="stat-value"><?= $stats['users'] ?></div><div class="stat-label">Benutzer</div></div>
    <div class="stat"><div class="stat-value"><?= $stats['profiles'] ?></div><div class="stat-label">Profile</div></div>
    <div class="stat"><div class="stat-value"><?= $stats['active'] ?></div><div class="stat-label">Aktive Streams</div></div>
  </div>
</div>

<div class="admin-row">
  <div class="admin-card" style="flex:2;">
    <h3>Letzte Aktivitäten</h3>
    <table class="admin-table">
      <thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td><?= Security::sanitize($l['created_at']) ?></td>
          <td><?= Security::sanitize($l['username'] ?? 'System') ?></td>
          <td><?= Security::sanitize($l['action']) ?></td>
          <td><?= Security::sanitize($l['details'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="admin-card" style="flex:1;">
    <h3>Aktive Streams</h3>
    <?php if (empty($streams)): <p>Keine aktiven Streams.</p> <?php else: ?>
    <table class="admin-table">
      <thead><tr><th>Benutzer</th><th>Profil</th><th>Medien</th><th>Gestartet</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($streams as $s): ?>
        <tr>
          <td><?= Security::sanitize($s['username']) ?></td>
          <td><?= Security::sanitize($s['profile_name']) ?></td>
          <td><?= Security::sanitize($s['media_type'] . '#' . $s['media_id']) ?></td>
          <td><?= Security::sanitize($s['start_time']) ?></td>
          <td>
            <button class="btn btn-sm btn-danger" data-sf-action="stream_stop" data-sf-id="<?= (int) $s['id'] ?>" data-sf-confirm="Stream wirklich beenden?" style="padding:4px 8px;font-size:12px;">Beenden</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <div style="margin-top:1rem;"><a href="streams.php" class="btn btn-sm btn-outline">Alle Streams anzeigen</a></div>
  </div>
</div>
<div class="admin-card" style="margin-top:1rem;">
  <h3>Schnellaktionen</h3>
  <div class="btn-group">
    <a href="folders.php" class="btn btn-red">Medienordner verwalten</a>
    <a href="settings.php" class="btn btn-outline">Globale Einstellungen</a>
    <a href="backups.php" class="btn btn-outline">Backups</a>
    <button class="btn btn-red" data-sf-action="backup_create">Datenbank-Backup erstellen</button>
  </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
