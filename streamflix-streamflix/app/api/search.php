<?php
// StreamFlix — API: Suche
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
header('Content-Type: application/json; charset=utf-8');

apiRequireAuth();
$q = $_GET['q'] ?? '';
jsonResponse(['results' => Media::search($q)]);
