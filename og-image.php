<?php
// og-image.php — Menyajikan gambar preview social media langsung dari data.json.
// Mendukung gambar base64 (data URI) tanpa perlu file di folder .uploads/.
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

$data = null;
$cfg = __DIR__ . '/data.json';
if (is_readable($cfg)) {
    $raw = @file_get_contents($cfg);
    if ($raw !== false) {
        $data = json_decode($raw, true);
    }
}

$img = '';
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

$localPath = $img[0] === '/' ? __DIR__ . $img : __DIR__ . '/' . ltrim($img, './');
if (is_readable($localPath)) {
    $mime = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = finfo_file($fi, $localPath);
            finfo_close($fi);
            if ($detected && strpos($detected, 'image/') === 0) {
                $mime = $detected;
            }
        }
    }
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    readfile($localPath);
    exit;
}

fallback_logo();
