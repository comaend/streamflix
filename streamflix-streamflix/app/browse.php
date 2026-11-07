<?php
// StreamFlix — Durchsuchen (Filme/Serien mit Filter)
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/media.php';

$page     = 'browse';
$pageTitle = 'Durchsuchen';
$type     = $_GET['type'] ?? 'movies';
$genre    = $_GET['genre'] ?? null;
$search   = $_GET['search'] ?? null;
$pageNum  = max(1, (int) ($_GET['page'] ?? 1));
$limit    = 24;

if ($type === 'series') {
    $items   = Media::listSeries($pageNum, $limit, $genre, $search);
    $total   = Media::countSeries($genre, $search);
} else {
    $type    = 'movies';
    $items   = Media::listMovies($pageNum, $limit, $genre, $search);
    $total   = Media::countMovies($genre, $search);
}
$pages = max(1, (int) ceil($total / $limit));
$genres = Media::listGenres();
require __DIR__ . '/templates/header.php';
?>

<section class="browse-page">
  <div class="page-header">
    <h1><?= $type === 'series' ? 'Serien' : 'Filme' ?></h1>
    <form class="browse-filters" action="" method="GET">
      <input type="hidden" name="type" value="<?= Security::sanitize($type) ?>">
      <select name="genre" class="filter-select">
        <option value="">Alle Genres</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= Security::sanitize($g['slug']) ?>" <?= $genre === $g['slug'] ? 'selected' : '' ?>><?= Security::sanitize($g['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="search" placeholder="Titel suchen..." value="<?= Security::sanitize($search ?? '') ?>">
      <button type="submit" class="btn btn-red">Filtern</button>
    </form>
  </div>
  <?php if (empty($items)): ?>
    <div class="empty-state">Keine Inhalte gefunden.</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($items as $item): ?>
        <a href="watch.php?type=<?= $type === 'series' ? 'series' : 'movie' ?>&id=<?= (int) $item['id'] ?>" class="card-link">
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
    <div class="pagination">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?type=<?= Security::sanitize($type) ?>&<?= $genre ? 'genre=' . Security::sanitize($genre) . '&' : '' ?><?= $search ? 'search=' . urlencode($search) . '&' : '' ?>page=<?= $p ?>" class="page-link <?= $p === $pageNum ? 'active' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
