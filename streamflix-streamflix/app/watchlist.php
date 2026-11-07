<?php
// StreamFlix — Meine Liste (Watchlist)
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/media.php';

if (!Auth::isLoggedIn()) { redirect('/login.php'); exit; }

$page      = 'watchlist';
$pageTitle = 'Meine Liste';
$profileId = activeProfileId();
if (!$profileId) { redirect('/profile.php'); exit; }

$db = Database::get();
$stmt = $db->prepare("
  SELECT m.id, m.title, m.year, m.poster, m.runtime, 'movie' AS type, w.added_at
  FROM watchlist w JOIN movies m ON w.media_type='movie' AND w.media_id=m.id
  WHERE w.profile_id = ?
  UNION ALL
  SELECT s.id, s.title, s.year, s.poster, NULL AS runtime, 'series' AS type, w.added_at
  FROM watchlist w JOIN series s ON w.media_type='series' AND w.media_id=s.id
  WHERE w.profile_id = ?
  ORDER BY w.added_at DESC
");
$stmt->execute([$profileId, $profileId]);
$items = $stmt->fetchAll();

require __DIR__ . '/templates/header.php';
?>

<section class="watchlist-page">
  <div class="page-header"><h1>Meine Liste</h1></div>
  <?php if (empty($items)): ?>
    <div class="empty-state">Deine Watchlist ist leer. Füge Filme und Serien hinzu.</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($items as $item): ?>
        <a href="watch.php?type=<?= Security::sanitize($item['type']) ?>&id=<?= (int) $item['id'] ?>" class="card-link">
          <div class="card">
            <div class="card-poster">
              <?php if ($item['poster']): ?>
                <img src="/assets/img/posters/<?= Security::sanitize($item['poster']) ?>" alt="<?= Security::sanitize($item['title']) ?>" class="poster-img">
              <?php else: ?>
                <div class="poster-placeholder">Kein Poster</div>
              <?php endif; ?>
            </div>
            <div class="card-info">
              <h3 class="card-title"><?= Security::sanitize($item['title']) ?></h3>
              <span class="card-year"><?= $item['year'] ?? '' ?></span>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
