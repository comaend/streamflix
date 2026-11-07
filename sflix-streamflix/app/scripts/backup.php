<?php
// Manuelles Backup: php /app/scripts/backup.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/backup.php';
$r = create_backup('manual', 'CLI');
echo "Backup erstellt: {$r['filename']} (" . human_bytes((float)$r['size']) . ")\n";
