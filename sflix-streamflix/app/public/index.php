<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
$name = htmlspecialchars(setting('platform_name', 'STREAMFLIX'), ENT_QUOTES, 'UTF-8');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', setting('accent_color', '#e50914')) ? setting('accent_color') : '#e50914';
$v = SF_VERSION;
header('Cache-Control: no-store');
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#141414">
<title><?= $name ?></title>
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<style>:root{--accent:<?= $accent ?>}</style>
</head>
<body>
<div id="app"><div class="boot">Lade …</div></div>
<script src="/assets/hls.min.js?v=<?= $v ?>"></script>
<script type="module" src="/assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
