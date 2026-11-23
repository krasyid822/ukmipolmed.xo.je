<?php
// foto.php (dilayani sebagai /foto) — proxy gambar dari Blomp S3 (signed GET), karena Blomp menolak GET anonim.
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/security_helper.php';

$key = isset($_GET['key']) ? (string) $_GET['key'] : '';
$allowedPrefixes = [s3_prefix() . 'uploads/', s3_prefix() . 'assets/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if ($prefix !== '' && strpos($key, $prefix) === 0) { $allowed = true; break; }
}
$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$map = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
];
if ($key === '' || strpos($key, '..') !== false || !$allowed || !isset($map[$ext])) {
    http_response_code(404);
    exit;
}

if (ukmi_rate_limit('foto', 120, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    exit;
}

$res = s3_get_object($key);
if (($res['code'] ?? 0) !== 200 || ($res['body'] ?? null) === null) {
    http_response_code(404);
    header('Cache-Control: public, max-age=120');
    exit;
}
$body = $res['body'];

$mime = $map[$ext];
$isAsset = strpos($key, s3_prefix() . 'assets/') === 0;

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($body));
// uploads/ berbasis hash konten (aman di-cache lama); assets/ bisa berubah.
header('Cache-Control: public, max-age=' . ($isAsset ? 3600 : 2592000));
header('X-Content-Type-Options: nosniff');
if ($mime === 'image/svg+xml') {
    // SVG same-origin bisa mengeksekusi script; sandbox mematikannya.
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
    header('X-Content-Type-Options: nosniff');
}
echo $body;
