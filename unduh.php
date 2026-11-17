<?php
// unduh.php — unduh file (Excel) dari Blomp S3 via signed GET.
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
$name = basename($key);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($res['body']));
header('Cache-Control: private, max-age=0');
echo $res['body'];
