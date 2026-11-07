<?php
// StreamFlix — Profil wechseln
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (!Auth::isLoggedIn()) { redirect('/login.php'); exit; }
$pid = (int) ($_GET['id'] ?? 0);
$ok = false;
foreach (userProfiles((int) $_SESSION['user_id']) as $p) { if ((int) $p['id'] === $pid) { $ok = true; break; } }
if ($ok) { setActiveProfile($pid); }
redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');
exit;
