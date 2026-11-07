<?php
// Manuelle Bereinigung: php /app/scripts/cleanup.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/scanner.php';
require_once __DIR__ . '/../lib/streaming.php';
cleanup_streams(); trim_cache(); cleanup_orphans();
echo "Bereinigung abgeschlossen.\n";
