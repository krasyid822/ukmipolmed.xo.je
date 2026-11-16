<?php
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['k'] ?? '') !== 'UKMIrestore2026') {
    http_response_code(403);
    echo 'forbidden';
    exit;
}
$gz = __DIR__ . '/blog.php.gz';
if (!is_readable($gz)) {
    echo 'missing blog.php.gz';
    exit;
}
$raw = file_get_contents($gz);
$data = function_exists('gzdecode') ? @gzdecode($raw) : false;
if ($data === false) {
    $data = @gzuncompress($raw);
}
if ($data === false) {
    echo 'decode failed';
    exit;
}
$n = file_put_contents(__DIR__ . '/blog.php', $data);
echo $n === false ? 'write failed' : ('OK restored ' . $n . ' bytes');
