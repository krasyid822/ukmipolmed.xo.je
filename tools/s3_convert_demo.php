<?php
// tools/s3_convert_demo.php — ambil GIF demo dari S3, konversi ke WebP animasi,
// upload WebP, hapus GIF lama, cetak URL + ukuran.
require __DIR__ . '/../s3_helper.php';

$keyGif = s3_prefix() . 'assets/demo/ukmi-polmed.gif';
$g = s3_get_object($keyGif);
if (($g['code'] ?? 0) !== 200 || ($g['body'] ?? null) === null) {
    fwrite(STDERR, "Gagal ambil GIF dari S3 (code {$g['code']})\n");
    exit(1);
}
$src = '/tmp/demo_src.gif';
$out = '/tmp/demo.webp';
file_put_contents($src, $g['body']);

$cmd = 'ffmpeg -y -i ' . escapeshellarg($src)
    . ' -vf "fps=15,scale=800:-1:flags=lanczos" -c:v libwebp -lossless 0 -q:v 60 -loop 0 -an '
    . escapeshellarg($out) . ' 2>&1';
shell_exec($cmd);

if (!is_file($out) || filesize($out) === 0) {
    fwrite(STDERR, "Konversi ffmpeg gagal (libwebp tidak tersedia?)\n");
    exit(1);
}
$webp = file_get_contents($out);
$keyWebp = s3_prefix() . 'assets/demo/ukmi-polmed.webp';
$dbg = null;
if (!s3_put_binary($webp, $keyWebp, 'image/webp', $dbg)) {
    fwrite(STDERR, 'Upload WebP gagal: ' . json_encode($dbg) . "\n");
    exit(1);
}
s3_delete_object($keyGif);

echo 'gif_bytes=' . strlen($g['body']) . ' webp_bytes=' . strlen($webp) . "\n";
echo s3_photo_url($keyWebp) . "\n";
