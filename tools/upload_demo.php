<?php
// tools/upload_demo.php — unggah GIF demo ke S3 (sekali). Cetak URL foto.php.
require __DIR__ . '/../s3_helper.php';

$f = __DIR__ . '/../assets/demo/ukmi-polmed.gif';
if (!is_readable($f)) {
    fwrite(STDERR, "GIF tidak ditemukan: $f\n");
    exit(1);
}
$bin = file_get_contents($f);
$key = s3_prefix() . 'assets/demo/ukmi-polmed.gif';
$dbg = null;
$ok = s3_put_binary($bin, $key, 'image/gif', $dbg);
if (!$ok) {
    fwrite(STDERR, 'Upload gagal: ' . json_encode($dbg) . "\n");
    exit(1);
}
echo s3_photo_url($key) . "\n";
