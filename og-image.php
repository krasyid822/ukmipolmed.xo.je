<?php
require_once __DIR__ . '/store.php';
// og-image.php — Menyajikan gambar preview social media langsung dari data.json.
// Mendukung gambar base64 (data URI) dan URL S3/foto tanpa file lokal.
$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';

function fallback_logo()
{
    $logo = __DIR__ . '/logo-ukmi.png';
    if (is_readable($logo)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($logo);
    } else {
        http_response_code(404);
    }
    exit;
}

if ($slug === '') {
    fallback_logo();
}

$img = '';
if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
    foreach (data_store_posts_index_read() as $p) {
        if (isset($p['slug']) && (string) $p['slug'] === $slug) {
            $img = trim((string) ($p['image'] ?? ''));
            break;
        }
    }
} else {
    $data = store_read_json(__DIR__ . '/data.json');
    if (is_array($data)) {
        $lists = [];
        if (!empty($data['posts']) && is_array($data['posts'])) {
            $lists[] = $data['posts'];
        }
        if (!empty($data['posts_archived']) && is_array($data['posts_archived'])) {
            $lists[] = $data['posts_archived'];
        }
        foreach ($lists as $list) {
            foreach ($list as $p) {
                if (isset($p['slug']) && (string) $p['slug'] === $slug) {
                    $img = trim((string) ($p['image'] ?? ''));
                    break 2;
                }
            }
        }
    }
}

if ($img === '') {
    fallback_logo();
}

if (stripos($img, 'data:') === 0) {
    if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.*)$#is', $img, $m)) {
        $mime = strtolower($m[1]);
        $bin = base64_decode($m[2], true);
        if ($bin !== false && $bin !== '') {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($bin));
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            if ($mime === 'image/svg+xml') {
                header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
            }
            echo $bin;
            exit;
        }
    }
    fallback_logo();
}

if (preg_match('#^https?://#i', $img)) {
    header('Location: ' . $img, true, 302);
    exit;
}

if (strpos($img, "\0") === false && strpos($img, '..') === false) {
    $rootReal = realpath(__DIR__);
    $candidate = $img[0] === '/' ? __DIR__ . $img : __DIR__ . '/' . ltrim($img, './');
    $localReal = realpath($candidate);
    $ext = strtolower((string) pathinfo((string) $localReal, PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if ($rootReal !== false && $localReal !== false && strpos($localReal, $rootReal . DIRECTORY_SEPARATOR) === 0 && is_readable($localReal) && in_array($ext, $allowedExts, true)) {
        $mime = $ext === 'svg' ? 'image/svg+xml' : '';
        if ($mime === '' && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = finfo_file($fi, $localReal);
                finfo_close($fi);
                if ($detected && strpos($detected, 'image/') === 0) {
                    $mime = $detected;
                }
            }
        }
        if ($mime !== '') {
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            if ($mime === 'image/svg+xml') {
                header("Content-Security-Policy: sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'");
            }
            readfile($localReal);
            exit;
        }
    }
}

fallback_logo();
