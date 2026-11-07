<?php
// StreamFlix — Startseite (Hero + Karussells)
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/media.php';

$page      = 'home';
$pageTitle = 'Startseite';
$isLoggedIn = Auth::isLoggedIn();
$profileId = activeProfileId();

$hero   = Media::getMovie(1); // Platzhalter-Hero, falls vorhanden
$movies = Media::listMovies(1, 10);
$series = Media::listSeries(1, 10);
$genres = Media::listGenres();

require __DIR__ . '/templates/header.php';
?>

<section class="hero">
  <div class="hero-content">
    <h1 class="hero-title"><?= $hero ? Security::sanitize($hero['title']) : 'StreamFlix' ?></h1>
    <p class="hero-description"><?= $hero ? Security::sanitize($hero['description'] ?? '') : 'Deine eigene Streaming-Plattform für lokale Filme und Serien.' ?></p>
    <div class="hero-actions">
      <?php if ($hero && $profileId): ?>
        <a href="watch.php?type=movie&id=<?= (int) $hero['id'] ?>" class="btn btn-red btn-large">Wiedergeben</a>
        <a href="watch.php?type=movie&id=<?= (int) $hero['id'] ?>" class="btn btn-outline btn-large">Info</a>
      <?php elseif ($isLoggedIn): ?>
        <a href="browse.php?type=movies" class="btn btn-red btn-large">Filme durchstöbern</a>
      <?php else: ?>
        <a href="register.php" class="btn btn-red btn-large">Jetzt registrieren</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($hero && $hero['backdrop']): ?>
    <div class="hero-bg" style="background-image:url('/assets/img/backdrops/<?= Security::sanitize($hero['backdrop']) ?>')"></div>
  <?php else: ?>
    <div class="hero-bg"></div>
  <?php endif; ?>
</section>

<?php
function section(string $title, array $items, string $viewAllLink = '#'): void {
    if (empty($items)) { return; }
    ?>
    <section class="row-section">
      <div class="row-header">
        <h2 class="row-title"><?= Security::sanitize($title) ?></h2>
        <a href="<?= Security::sanitize($viewAllLink) ?>" class="row-more">Alle anzeigen</a>
      </div>
      <div class="carousel">
        <button class="carousel-nav prev">&#10094;</button>
        <div class="carousel-track">
          <?php foreach ($items as $item): ?>
            <a href="watch.php?type=<?= Security::sanitize($item['type'] ?? 'movie') ?>&id=<?= (int) $item['id'] ?>" class="card-link">
              <div class="card">
                <div class="card-poster">
                  <?php $poster = $item['poster'] ?? null; ?>
                  <?php if ($poster): ?>
                    <img src="/assets/img/posters/<?= Security::sanitize($poster) ?>" alt="<?= Security::sanitize($item['title'] ?? '') ?>" class="poster-img">
                  <?php else: ?>
                    <div class="poster-placeholder">Kein Poster</div>
                  <?php endif; ?>
                </div>
                <div class="card-info">
                  <h3 class="card-title"><?= Security::sanitize($item['title'] ?? '') ?></h3>
                  <span class="card-year"><?= $item['year'] ?? '' ?></span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <button class="carousel-nav next">&#10095;</button>
      </div>
    </section>
    <?php
}
?>

<?php if ($movies): section('Beliebte Filme', $movies, 'browse.php?type=movies'); endif; ?>
<?php if ($series): section('Beliebte Serien', $series, 'browse.php?type=series'); endif; ?>
<?php if ($isLoggedIn && $profileId): ?>
  <?php
  $db = Database::get();
  $wl = $db->prepare("SELECT m.*, 'movie' AS type FROM watchlist w JOIN movies m ON w.media_type='movie' AND w.media_id=m.id WHERE w.profile_id=? UNION ALL SELECT s.*, 'series' AS type FROM watchlist w JOIN series s ON w.media_type='series' AND w.media_id=s.id WHERE w.profile_id=? ORDER BY added_at DESC LIMIT 10");
  $wl->execute([$profileId, $profileId]);
  $watchlist = $wl->fetchAll();
  if ($watchlist): section('Meine Liste', $watchlist, 'watchlist.php'); endif;
  ?>
<?php endif; ?>

<section class="row-section">
  <div class="row-header"><h2 class="row-title">Genres</h2></div>
  <div class="carousel genres-list">
    <?php foreach ($genres as $g): ?>
      <a href="browse.php?type=movies&genre=<?= Security::sanitize($g['slug']) ?>" class="genre-chip"><?= Security::sanitize($g['name']) ?></a>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/templates/footer.php'; ?>
