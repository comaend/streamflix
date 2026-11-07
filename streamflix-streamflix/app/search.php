<?php
// StreamFlix — Suche
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/media.php';

$page      = 'search';
$pageTitle = 'Suche';
$q = trim($_GET['q'] ?? '');
$results = [];
if ($q !== '') {
    $results = Media::search($q);
}
require __DIR__ . '/templates/header.php';
?>

<section class="search-page">
  <div class="page-header">
    <h1>Suche</h1>
    <form class="search-form" action="" method="GET">
      <input type="text" name="q" value="<?= Security::sanitize($q) ?>" placeholder="Filme, Serien, Schauspieler, Genres..." autocomplete="off">
      <button type="submit" class="btn btn-red">Suchen</button>
    </form>
  </div>
  <?php if ($q !== ''): ?>
    <div class="search-results">
      <?php if (!empty($results['movies'])): ?>
        <div class="result-group"><h3>Filme</h3>
          <div class="grid">
            <?php foreach ($results['movies'] as $m): ?>
              <a href="watch.php?type=movie&id=<?= (int) $m['id'] ?>" class="card-link">
                <div class="card">
                  <div class="card-poster"><?php if ($m['poster']): ?><img src="/assets/img/posters/<?= Security::sanitize($m['poster']) ?>" class="poster-img"><?php else: ?><div class="poster-placeholder">-</div><?php endif; ?></div>
                  <div class="card-info"><h3 class="card-title"><?= Security::sanitize($m['title']) ?></h3><span class="card-year"><?= $m['year'] ?? '' ?></span></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($results['series'])): ?>
        <div class="result-group"><h3>Serien</h3>
          <div class="grid">
            <?php foreach ($results['series'] as $s): ?>
              <a href="watch.php?type=series&id=<?= (int) $s['id'] ?>" class="card-link">
                <div class="card">
                  <div class="card-poster"><?php if ($s['poster']): ?><img src="/assets/img/posters/<?= Security::sanitize($s['poster']) ?>" class="poster-img"><?php else: ?><div class="poster-placeholder">-</div><?php endif; ?></div>
                  <div class="card-info"><h3 class="card-title"><?= Security::sanitize($s['title']) ?></h3><span class="card-year"><?= $s['year'] ?? '' ?></span></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if (empty($results['movies']) && empty($results['series'])): ?>
        <div class="empty-state">Keine Ergebnisse für "<?= Security::sanitize($q) ?>" gefunden.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
