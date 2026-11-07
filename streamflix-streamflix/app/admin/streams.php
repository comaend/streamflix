<?php
// StreamFlix — Admin: Aktive Streams
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'streams';
$db = Database::get();
$streams = $db->query("SELECT s.*, u.username, p.name AS profile_name FROM active_streams s JOIN users u ON s.user_id=u.id JOIN profiles p ON s.profile_id=p.id ORDER BY s.start_time DESC")->fetchAll();
?>
<div class="admin-card">
  <h2>Aktive Streams</h2>
  <?php if (empty($streams)): ?>
    <p>Keine aktiven Streams.</p>
  <?php else: ?>
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Benutzer</th><th>Profil</th><th>Medientyp</th><th>Media-ID</th><th>Position</th><th>Modus</th><th>IP</th><th>Gestartet</th><th>Aktion</th></tr></thead>
      <tbody>
      <?php foreach ($streams as $s): ?>
        <tr>
          <td><?= (int) $s['id'] ?></td>
          <td><?= Security::sanitize($s['username']) ?></td>
          <td><?= Security::sanitize($s['profile_name']) ?></td>
          <td><?= Security::sanitize($s['media_type']) ?></td>
          <td><?= (int) $s['media_id'] ?></td>
          <td><?= $s['position'] ?> Sek.</td>
          <td><?= Security::sanitize($s['mode']) ?></td>
          <td><?= Security::sanitize($s['ip'] ?? '-') ?></td>
          <td><?= Security::sanitize($s['start_time']) ?></td>
          <td>
            <button class="btn btn-sm btn-danger" data-sf-action="stream_stop" data-sf-id="<?= (int) $s['id'] ?>" data-sf-confirm="Stream beenden?" style="padding:4px 8px;">Beenden</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
