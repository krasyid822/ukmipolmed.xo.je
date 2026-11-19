<?php
// migrate_store.php — migrasi JSON runtime ke S3 (prefix data/) dan ubah file
// lokal menjadi pointer kecil. Aman dijalankan berulang (idempoten).
// Proteksi ?k=. Tidak ada salinan .backups/; payload asli tetap di S3.

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/maintenance_guard.php';

maintenance_require_json();
if (!s3_available()) {
    echo json_encode(['ok' => false, 'reason' => 's3_unavailable']);
    exit;
}

$targets = $_REQUEST['targets'] ?? null;
if (is_string($targets) && $targets !== '') {
    $targets = array_filter(array_map('trim', explode(',', $targets)));
} else {
    $targets = ['data', 'insights', 'authors', 'assessments'];
}

$report = [];
foreach ($targets as $name) {
    $name = basename((string) $name, '.json');
    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) continue;
    $path = __DIR__ . '/' . $name . '.json';
    if (!file_exists($path)) {
        $report[$name] = ['ok' => true, 'skipped' => true, 'reason' => 'missing'];
        continue;
    }
    $report[$name] = store_migrate_file($path);
}

echo json_encode([
    'ok' => true,
    'prefix' => s3_prefix() . 'data/',
    'files' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
