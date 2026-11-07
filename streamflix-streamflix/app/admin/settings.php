<?php
// StreamFlix — Admin: Globale Einstellungen
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'settings';
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) { die('CSRF-Fehler'); }
    foreach ($_POST as $key => $val) {
        if (in_array($key, ['csrf_token', 'do'], true)) continue;
        $db->prepare("INSERT INTO system_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
           ->execute([$key, $val]);
    }
    header('Location: settings.php?saved=1');
    exit;
}
$saved = isset($_GET['saved']);
$rows = $db->query("SELECT key, value FROM system_settings")->fetchAll();
$settings = [];
foreach ($rows as $r) { $settings[$r['key']] = $r['value']; }
?>
<div class="admin-card">
  <h2>Globale Einstellungen</h2>
  <?php if ($saved): <div class="alert alert-success">Einstellungen gespeichert.</div> <?php endif; ?>
  <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;">
    <input type="hidden" name="csrf_token" value="<?= Security::sanitize(Security::csrfToken()) ?>">
    <div class="form-group">
      <label>Plattformname</label>
      <input type="text" name="platform_name" value="<?= Security::sanitize($settings['platform_name'] ?? 'StreamFlix') ?>">
    </div>
    <div class="form-group">
      <label>Registrierung aktivieren</label>
      <select name="registration_enabled">
        <option value="1" <?= ($settings['registration_enabled'] ?? '1')==='1'?'selected':'' ?>>Ja</option>
        <option value="0" <?= ($settings['registration_enabled'] ?? '1')==='0'?'selected':'' ?>>Nein</option>
      </select>
    </div>
    <div class="form-group">
      <label>Maximale Benutzerzahl</label>
      <input type="number" name="max_users" value="<?= Security::sanitize($settings['max_users'] ?? '50') ?>">
    </div>
    <div class="form-group">
      <label>Max. gleichzeitige Streams</label>
      <input type="number" name="max_concurrent_streams" value="<?= Security::sanitize($settings['max_concurrent_streams'] ?? '5') ?>">
    </div>
    <div class="form-group">
      <label>Standardsprache</label>
      <input type="text" name="default_language" value="<?= Security::sanitize($settings['default_language'] ?? 'de') ?>">
    </div>
    <div class="form-group">
      <label>Standarduntertitel</label>
      <input type="text" name="default_subtitle" value="<?= Security::sanitize($settings['default_subtitle'] ?? 'de') ?>">
    </div>
    <div class="form-group">
      <label>Videoqualität</label>
      <select name="video_quality">
        <option value="auto" <?= ($settings['video_quality'] ?? 'auto')==='auto'?'selected':'' ?>>Auto</option>
        <option value="720p" <?= ($settings['video_quality'] ?? 'auto')==='720p'?'selected':'' ?>>720p</option>
        <option value="1080p" <?= ($settings['video_quality'] ?? 'auto')==='1080p'?'selected':'' ?>>1080p</option>
      </select>
    </div>
    <div class="form-group">
      <label>Cache-Größe (MB)</label>
      <input type="number" name="cache_size" value="<?= Security::sanitize($settings['cache_size'] ?? '512') ?>">
    </div>
    <div class="form-group">
      <label>Automatische Bibliotheks-Scans</label>
      <select name="auto_scan">
        <option value="1" <?= ($settings['auto_scan'] ?? '1')==='1'?'selected':'' ?>>Ja</option>
        <option value="0" <?= ($settings['auto_scan'] ?? '1')==='0'?'selected':'' ?>>Nein</option>
      </select>
    </div>
    <div class="form-group">
      <label>Wartungsmodus</label>
      <select name="maintenance_mode">
        <option value="0" <?= ($settings['maintenance_mode'] ?? '0')==='0'?'selected':'' ?>>Aus</option>
        <option value="1" <?= ($settings['maintenance_mode'] ?? '0')==='1'?'selected':'' ?>>An</option>
      </select>
    </div>
    <div class="form-group" style="grid-column:1/-1;">
      <button type="submit" class="btn btn-red">Einstellungen speichern</button>
    </div>
  </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
