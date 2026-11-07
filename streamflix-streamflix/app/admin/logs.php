<?php
// StreamFlix — Admin: Aktivitätsprotokoll
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'logs';
$db = Database::get();
$pageNum = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$filter = $_GET['filter'] ?? '';
$sql = "SELECT al.*, u.username FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id";
$params = [];
if ($filter !== '') {
    $sql .= " WHERE al.action LIKE ? OR al.details LIKE ? OR al.ip LIKE ?";
    $params = ["%$filter%", "%$filter%", "%$filter%"];
}
$sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = ($pageNum - 1) * $limit;
$logs = $db->prepare($sql)->execute($params)->fetchAll();
$total = (int) $db->prepare("SELECT COUNT(*) FROM activity_logs" . ($filter !== '' ? " WHERE action LIKE ? OR details LIKE ?" : ""))->execute($filter !== '' ? ["%$filter%","%$filter%"] : [])->fetchColumn();
$pages = max(1, (int) ceil($total / $limit));
?>
<div class="admin-card">
  <h2>Aktivitätsprotokoll</h2>
  <form method="GET" style="display:flex;gap:6px;margin-bottom:1rem;flex-wrap:wrap;">
    <input type="text" name="filter" placeholder="Aktion, Details oder IP durchsuchen..." value="<?= Security::sanitize($filter) ?>" style="padding:6px;flex:1;min-width:200px;">
    <button type="submit" class="btn btn-red">Suchen</button>
    <a href="logs.php" class="btn btn-outline">Zurücksetzen</a>
  </form>
  <table class="admin-table">
    <thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Entität</th><th>ID</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= Security::sanitize($l['created_at']) ?></td>
        <td><?= Security::sanitize($l['username'] ?? 'System') ?></td>
        <td><?= Security::sanitize($l['action']) ?></td>
        <td><?= Security::sanitize($l['entity_type'] ?? '-') ?></td>
        <td><?= (int) $l['entity_id'] ?></td>
        <td><?= Security::sanitize($l['details'] ?? '-') ?></td>
        <td><?= Security::sanitize($l['ip'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="pagination">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="logs.php?page=<?= $p ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?>" class="page-link <?= $p === $pageNum ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
