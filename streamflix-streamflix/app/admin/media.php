<?php
// StreamFlix — Admin: Medienverwaltung
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'media';
$db = Database::get();
$movies = $db->query("SELECT * FROM movies ORDER BY created_at DESC LIMIT 100")->fetchAll();
$series = $db->query("SELECT * FROM series ORDER BY created_at DESC LIMIT 100")->fetchAll();
?>
<div class="admin-card">
  <h2>Medienverwaltung</h2>
  <div class="admin-tabs">
    <a href="#" class="tab active" onclick="showTab('movies')">Filme (<?= count($movies) ?>)</a>
    <a href="#" class="tab" onclick="showTab('series')">Serien (<?= count($series) ?>)</a>
  </div>
  <div id="tab-movies" class="tab-content">
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Titel</th><th>Jahr</th><th>Laufzeit</th><th>Rating</th><th>Poster</th></tr></thead>
      <tbody>
      <?php foreach ($movies as $m): ?>
        <tr>
          <td><?= (int) $m['id'] ?></td>
          <td><?= Security::sanitize($m['title']) ?></td>
          <td><?= Security::sanitize($m['year'] ?? '') ?></td>
          <td><?= $m['runtime'] ? $m['runtime'] . ' Min.' : '-' ?></td>
          <td><?= Security::sanitize($m['rating'] ?? '') ?></td>
          <td><?= $m['poster'] ? '<img src="/assets/img/posters/'.Security::sanitize($m['poster']).'" class="thumb">' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div id="tab-series" class="tab-content hidden">
    <table class="admin-table">
      <thead><tr><th>ID</th><th>Titel</th><th>Jahr</th><th>Poster</th></tr></thead>
      <tbody>
      <?php foreach ($series as $s): ?>
        <tr>
          <td><?= (int) $s['id'] ?></td>
          <td><?= Security::sanitize($s['title']) ?></td>
          <td><?= Security::sanitize($s['year'] ?? '') ?></td>
          <td><?= $s['poster'] ? '<img src="/assets/img/posters/'.Security::sanitize($s['poster']).'" class="thumb">' : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
function showTab(name){
  document.querySelectorAll('.tab-content').forEach(el=>el.classList.add('hidden'));
  document.querySelectorAll('.tab').forEach(el=>el.classList.remove('active'));
  document.getElementById('tab-'+name).classList.remove('hidden');
  event.target.classList.add('active');
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
