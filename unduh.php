<?php
// unduh.php — unduh file (Excel) dari Blomp S3 via signed GET.
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/security_helper.php';

$key = isset($_GET['key']) ? (string) $_GET['key'] : '';
$allowedPrefix = s3_prefix() . 'assessments/';
$ext = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));
if (
    $key === ''
    || $allowedPrefix === ''
    || strpos($key, "\0") !== false
    || strpos($key, '..') !== false
    || strpos($key, $allowedPrefix) !== 0
    || !in_array($ext, ['xlsx', 'xls'], true)
) {
    http_response_code(404);
    exit;
}
if (ukmi_rate_limit('unduh', 30, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    exit;
}
$res = s3_get_object($key);
if (($res['code'] ?? 0) !== 200 || ($res['body'] ?? null) === null) {
    http_response_code(404);
    exit;
}
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($key));
if ($name === '' || $name === '.' || $name === '..') {
    $name = 'asesmen.' . $ext;
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($res['body']));
header('Cache-Control: private, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $res['body'];
