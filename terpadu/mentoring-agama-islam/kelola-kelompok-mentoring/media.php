<?php
/**
 * media.php — Penyaji objek publik dari Blomp S3.
 *
 * Blomp menolak GET anonim, sehingga dokumentasi, og_meta, dan foto profil
 * tidak dapat diakses lewat URL publik langsung. Endpoint ini melakukan
 * signed GET di sisi server, tetapi HANYA untuk folder publik yang
 * diizinkan. Objek privat (laporan_json/, master_json/) tidak akan pernah
 * dilayani di sini.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\S3Client;

$key = (string)($_GET['key'] ?? '');

$allowedPrefixes = ['dokumentasi/', 'og_meta/', 'profil/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (str_starts_with($key, $prefix)) {
        $allowed = true;
        break;
    }
}

if (
    $key === ''
    || !$allowed
    || str_contains($key, '..')
    || str_contains($key, "\0")
    || str_starts_with($key, '/')
) {
    http_response_code(404);
    exit;
}

// Foto profil hanya untuk admin yang login; jangan pernah publik.
if (str_starts_with($key, 'profil/') && !AdminAuth::isAdmin()) {
    http_response_code(403);
    exit;
}

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$contentTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'json' => 'application/json',
];
if (!isset($contentTypes[$ext])) {
    http_response_code(404);
    exit;
}

$s3 = new S3Client();
$body = $s3->getObject($key);
$servedExt = $ext;

// Fallback transparan antara .webp dan .jpg untuk kompatibilitas link/data lama
if ($body === null && in_array($ext, ['webp', 'jpg', 'jpeg'], true)) {
    $altExt = ($ext === 'webp') ? 'jpg' : 'webp';
    $altKey = preg_replace('/\.[^.]+$/', '.' . $altExt, $key);
    if (is_string($altKey) && $altKey !== $key) {
        $body = $s3->getObject($altKey);
        if ($body !== null) {
            $servedExt = $altExt;
        }
    }
}

if ($body === null) {
    http_response_code(404);
    if (!str_starts_with($key, 'profil/')) {
        header('Cache-Control: public, max-age=120');
    }
    exit;
}

$isJson = ($servedExt === 'json');
$isProfile = str_starts_with($key, 'profil/');
header('Content-Type: ' . $contentTypes[$servedExt]);
header('Content-Length: ' . strlen($body));
header('X-Content-Type-Options: nosniff');
if ($isProfile) {
    header('Cache-Control: private, max-age=3600');
} else {
    header('Cache-Control: public, max-age=' . ($isJson ? 300 : 2592000));
}
echo $body;
