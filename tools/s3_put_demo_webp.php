<?php
// tools/s3_put_demo_webp.php — unggah WebP demo hasil konversi, hapus GIF lama.
require __DIR__ . '/../s3_helper.php';

$src = '/tmp/demo_pil.webp';
if (!is_readable($src)) {
    fwrite(STDERR, "webp tidak ada\n");
    exit(1);
}
$bin = file_get_contents($src);
$key = s3_prefix() . 'assets/demo/ukmi-polmed.webp';
$dbg = null;
if (!s3_put_binary($bin, $key, 'image/webp', $dbg)) {
    fwrite(STDERR, 'Upload gagal: ' . json_encode($dbg) . "\n");
    exit(1);
}
s3_delete_object(s3_prefix() . 'assets/demo/ukmi-polmed.gif');
echo 'webp_bytes=' . strlen($bin) . "\n";
echo s3_photo_url($key) . "\n";
