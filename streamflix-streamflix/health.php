<?php
// StreamFlix Healthcheck-Datei
// Wird vom Docker-Healthcheck und von Umbrel zur Verfügungstellung verwendet.
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'service' => 'streamflix']);
