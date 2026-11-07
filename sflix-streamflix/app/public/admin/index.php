<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
start_session();
$u = current_user();
if (!$u || !has_role($u, 'moderator')) { header('Location: /'); exit; }
$name = htmlspecialchars(setting('platform_name', 'STREAMFLIX'), ENT_QUOTES, 'UTF-8');
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', setting('accent_color', '#e50914')) ? setting('accent_color') : '#e50914';
$v = SF_VERSION;
header('Cache-Control: no-store');
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $name ?> – Admin</title>
<link rel="stylesheet" href="/assets/app.css?v=<?= $v ?>">
<style>:root{--accent:<?= $accent ?>}</style>
</head>
<body class="admin">
<div id="admin"><div class="boot">Lade …</div></div>
<script type="module" src="/assets/admin.js?v=<?= $v ?>"></script>
</body>
</html>
