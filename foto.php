<?php
// foto.php — proxy gambar dari Blomp S3 (signed GET), karena Blomp menolak GET anonim.
require_once __DIR__ . '/s3_helper.php';

$key = isset($_GET['key']) ? (string) $_GET['key'] : '';
if ($key === '' || strpos($key, '..') !== false || stripos($key, s3_prefix()) !== 0) {
    http_response_code(404);
    exit;
}

$res = s3_get_object($key);
if (($res['code'] ?? 0) !== 200 || ($res['body'] ?? null) === null) {
    http_response_code(404);
    exit;
}
$body = $res['body'];

$ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
$map = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
];
$mime = $map[$ext] ?? 'application/octet-stream';
if (!isset($map[$ext]) && function_exists('finfo_open') && $body !== '') {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $d = finfo_buffer($fi, $body);
        finfo_close($fi);
        if ($d && strpos($d, 'image/') === 0) $mime = $d;
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=2592000');
header('X-Content-Type-Options: nosniff');
echo $body;
