<?php
// StreamFlix — Videoplayer-Seite
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/streaming.php';

apiRequireAuth(); // Redirect if not logged in -> handled below for page

if (!Auth::isLoggedIn()) {
    redirect('/login.php');
    exit;
}

$page     = 'watch';
$pageTitle = 'Wiedergabe';
$profileId = activeProfileId();
if (!$profileId) {
    redirect('/profile.php');
    exit;
}

$type    = $_GET['type'] ?? 'movie';
$id      = (int) ($_GET['id'] ?? 0);
$episode = null;
$media   = null;
$file    = '';
$subtitles = [];

if ($type === 'movie') {
    $media = Media::getMovie($id);
    $mf = Media::mediaFile('movie', $id);
    $file = $mf['file_path'] ?? '';
    $subtitles = $db = Database::get()->prepare("SELECT * FROM subtitles WHERE media_type='movie' AND media_id=?")->execute([$id])->fetchAll();
} elseif ($type === 'episode') {
    $episode = $db = Database::get()->prepare("SELECT e.*, s.season_number, s.series_id, se.title AS series_title FROM episodes e JOIN seasons s ON e.season_id=s.id WHERE e.id=?")->execute([$id])->fetch();
    if ($episode) {
        $file = $episode['file_path'] ?? '';
        $media = ['id' => $episode['series_id'], 'title' => $episode['series_title'], 'type' => 'episode'];
        $subtitles = Database::get()->prepare("SELECT * FROM subtitles WHERE media_type='episode' AND media_id=?")->execute([$id])->fetchAll();
    }
}

// Fortschritt laden
$progress = 0;
$duration = 0;
$stmt = Database::get()->prepare("SELECT position, duration FROM watch_progress WHERE user_id=? AND profile_id=? AND media_type=? AND media_id=?");
$stmt->execute([$_SESSION['user_id'], $profileId, $type, $id]);
$row = $stmt->fetch();
if ($row) { $progress = (int) $row['position']; $duration = (int) $row['duration']; }

require __DIR__ . '/templates/header.php';
?>

<section class="player-page">
  <?php if (!$media || !$file): ?>
    <div class="alert alert-error">Medien nicht gefunden oder keine Datei verknüpft.</div>
    <a href="index.php" class="btn btn-red">Zurück zur Startseite</a>
  <?php else: ?>
    <div class="player-header">
      <div class="player-title">
        <h1><?= Security::sanitize($media['title'] ?? '') ?></h1>
        <?php if ($episode): ?>
          <span class="badge">Staffel <?= htmlspecialchars($episode['season_number']) ?> · Episode <?= htmlspecialchars($episode['episode_number']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="player-wrap">
      <video id="video" class="video-el" preload="metadata" poster="<?= $media['poster'] ? '/assets/img/posters/' . Security::sanitize($media['poster']) : '' ?>"></video>
      <div class="player-controls" id="controls">
        <div class="controls-row">
          <button id="playBtn" class="ctrl">▶</button>
          <div class="progress-wrap">
            <div class="progress-bar">
              <div class="progress-loaded" id="loadedBar"></div>
              <div class="progress-played" id="playedBar"></div>
              <div class="progress-handle" id="handle"></div>
            </div>
            <div class="time"> <span id="curTime">0:00</span> / <span id="durTime">0:00</span> </div>
          </div>
          <div class="ctrl-group">
            <button id="volBtn" class="ctrl">🔊</button>
            <input type="range" id="volume" min="0" max="1" step="0.1" value="1" class="ctrl-slider">
            <button id="speedBtn" class="ctrl">1×</button>
            <button id="fullscreenBtn" class="ctrl">⛶</button>
          </div>
        </div>
        <div class="controls-row tracks">
          <button id="subtitleBtn" class="ctrl small">Untertitel</button>
          <button id="audioBtn" class="ctrl small">Audio</button>
          <button id="skipIntroBtn" class="ctrl small hidden" style="color:#e50914;">Intro überspringen</button>
        </div>
      </div>
    </div>
    <div class="player-actions">
      <?php
      $inWatchlist = false;
      $stmt = Database::get()->prepare("SELECT 1 FROM watchlist WHERE profile_id=? AND media_type=? AND media_id=? LIMIT 1");
      $stmt->execute([$profileId, $type, $id]);
      if ($stmt->fetch()) { $inWatchlist = true; }
      ?>
      <button class="btn <?= $inWatchlist ? 'btn-red' : 'btn-outline' ?>" data-wl data-wl-type="<?= Security::sanitize($type) ?>" data-wl-id="<?= $id ?>">
        <?= $inWatchlist ? '✓ Aus Liste entfernen' : '+ Zur Liste hinzufügen' ?>
      </button>
      <?php if ($progress > 0 && $progress < ($duration - 60)): ?>
        <a href="watch.php?type=<?= $type ?>&id=<?= $id ?>&resume=1" class="btn btn-red">Fortsetzen bei <?= formatSeconds($progress) ?></a>
      <?php endif; ?>
      <a href="index.php" class="btn btn-outline">Zurück</a>
    </div>
  <?php endif; ?>
</section>

<script src="/assets/js/player.js"></script>
<script>
  const PLAYER_CONFIG = {
    type: '<?= Security::sanitize($type) ?>',
    id: <?= $id ?>,
    file: <?= json_encode($file) ?>,
    progress: <?= $progress ?>,
    subtitles: <?= json_encode($subtitles) ?>,
    csrf: '<?= Security::sanitize(Security::csrfToken()) ?>'
  };
</script>
<?php require __DIR__ . '/templates/footer.php'; ?>
