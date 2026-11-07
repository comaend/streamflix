<?php
// StreamFlix — Admin: Serverstatistiken
declare(strict_types=1);
require_once __DIR__ . '/header.php';
$page = 'server';
$server = [];
$stat = @file_get_contents('/proc/stat');
if ($stat && preg_match('/^cpu\s+([\d\s]+)/', $stat, $m)) {
    $parts = array_filter(explode(' ', trim($m[1])));
    $total = array_sum($parts);
    $idle  = (int) $parts[3] ?? 0;
    $server['cpu'] = $total > 0 ? round((1 - $idle / $total) * 100, 1) : 0;
}
$mem = @file_get_contents('/proc/meminfo');
if ($mem) {
    preg_match('/MemTotal:\s+(\d+)/', $mem, $mt);
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
    $t = (int) ($mt[1] ?? 0); $a = (int) ($ma[1] ?? 0);
    $server['ram_total'] = $t; $server['ram_available'] = $a; $server['ram_used'] = $t - $a;
    $server['ram_percent'] = $t > 0 ? round((1 - $a / $t) * 100, 1) : 0;
}
$temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
$server['temperature'] = $temp !== false ? round((int) trim($temp) / 1000, 1) . ' °C' : 'n/a';
$server['disk_free'] = @disk_free_space('/') ?? 0;
$server['disk_total'] = @disk_total_space('/') ?? 0;
$server['disk_percent'] = $server['disk_total'] > 0 ? round((1 - $server['disk_free'] / $server['disk_total']) * 100, 1) : 0;
$up = @file_get_contents('/proc/uptime');
$server['uptime'] = $up !== false ? (float) explode(' ', trim($up))[0] : 0;
$server['php_version'] = phpversion();
$server['ffmpeg'] = trim(@shell_exec('ffmpeg -version 2>/dev/null | head -n 1') ?? 'nicht verfügbar');
$server['hostname'] = php_uname('n');
?>
<div class="admin-card">
  <h2>Serververwaltung</h2>
  <div class="stats-grid">
    <div class="stat"><div class="stat-value"><?= $server['cpu'] ?>%</div><div class="stat-label">CPU-Auslastung</div></div>
    <div class="stat"><div class="stat-value"><?= $server['ram_percent'] ?>%</div><div class="stat-label">RAM-Verbrauch</div></div>
    <div class="stat"><div class="stat-value"><?= Security::sanitize($server['temperature']) ?></div><div class="stat-label">Temperatur</div></div>
    <div class="stat"><div class="stat-value"><?= $server['disk_percent'] ?>%</div><div class="stat-label">Festplatten-Belegung</div></div>
  </div>
  <table class="admin-table">
    <thead><tr><th>Kennzahl</th><th>Wert</th></tr></thead>
    <tbody>
      <tr><td>Hostname</td><td><?= Security::sanitize($server['hostname']) ?></td></tr>
      <tr><td>PHP-Version</td><td><?= Security::sanitize($server['php_version']) ?></td></tr>
      <tr><td>FFmpeg</td><td><?= Security::sanitize($server['ffmpeg']) ?></td></tr>
      <tr><td>RAM gesamt</td><td><?= round(($server['ram_total'] ?? 0)/1024, 1) ?> MB</td></tr>
      <tr><td>RAM verfügbar</td><td><?= round(($server['ram_available'] ?? 0)/1024, 1) ?> MB</td></tr>
      <tr><td>Festplatte frei</td><td><?= round(($server['disk_free'] ?? 0)/(1024**3), 2) ?> GB von <?= round(($server['disk_total'] ?? 0)/(1024**3), 2) ?> GB</td></tr>
      <tr><td>Uptime</td><td><?= round($server['uptime'] / 3600, 1) ?> Stunden</td></tr>
    </tbody>
  </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
