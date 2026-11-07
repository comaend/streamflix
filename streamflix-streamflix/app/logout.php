<?php
// StreamFlix — Logout
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
Auth::logout();
header('Location: /login.php');
exit;
