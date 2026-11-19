<?php
// gz_restore.php — Fallback deploy saat FTP throttling.
// Menerima daftar target (relatif docroot). Untuk tiap <target>.gz yang ada:
// dekompres -> tulis ke <target>.tmp -> rename ke <target> (atomik) -> hapus .gz.
// Aman dari 0-byte: target hanya diganti bila decode+write+rename sukses.
header('Content-Type: text/plain; charset=utf-8');

$key = defined('GZ_RESTORE_KEY') ? GZ_RESTORE_KEY : 'UKMIrestore2026';
if (($_REQUEST['k'] ?? '') !== $key) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$targets = [];
$raw = (string) ($_REQUEST['targets'] ?? '');
if ($raw !== '') {
    foreach (explode(',', $raw) as $t) {
        $t = trim($t);
        if ($t === '' || strpos($t, '..') !== false) continue;
        $targets[] = $t;
    }
}
if (empty($targets)) {
    $targets = [
        '.htaccess', 'index.php', 'blog.php', 'status.php', 'admin.php', 'faq.php',
        'sitemap.php', 'sitemap.xml', 'og-image.php', 'penulis.php', 'penulis-panel.php',
        'authors_helper.php', 'error.php', 'header.php', 'oauth.php',
        'tentang-badge.php', 'badge-diedit-admin.svg',
        'components/bootstrap.php', 'components/brand.php', 'components/pengelola-blog.php',
        'components/kredensial-admin.php', 'components/beranda-styles.php',
        'components/beranda-main.php', 'components/beranda-scripts.php',
        'components/beranda-adhan.php', 'components/blog-komentar.php',
        'components/blog-styles.php', 'components/blog-scripts.php',
        'components/faq-styles.php', 'components/faq-scripts.php',
        'components/status-styles.php', 'components/status-scripts.php',
        'components/penulis-panel-styles.php', 'components/penulis-panel-scripts.php',
        'components/loading-overlay.php', 'components/soft-save.php',
    ];
}

$out = [];
foreach ($targets as $rel) {
    $gz = __DIR__ . '/' . $rel . '.gz';
    $target = __DIR__ . '/' . $rel;
    $tmp = $target . '.tmp';
    if (!is_readable($gz)) {
        $out[] = 'MISS ' . $rel;
        continue;
    }
    $bin = file_get_contents($gz);
    $data = function_exists('gzdecode') ? @gzdecode($bin) : false;
    if ($data === false) {
        $data = @gzuncompress($bin);
    }
    if ($data === false) {
        $out[] = 'DECODE-FAIL ' . $rel;
        continue;
    }
    $m = @file_put_contents($tmp, $data);
    if ($m === false || $m !== strlen($data)) {
        @unlink($tmp);
        $out[] = 'WRITE-FAIL ' . $rel;
        continue;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        $out[] = 'RENAME-FAIL ' . $rel;
        continue;
    }
    @unlink($gz);
    $out[] = 'OK ' . $rel . ' = ' . $m;
}
echo implode("\n", $out);
