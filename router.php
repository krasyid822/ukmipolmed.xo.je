<?php
// Router untuk PHP built-in server (lihat localhost.sh).
// Meniru rewrite di .htaccess: /blog/<slug>, /blog/arsip, /admin/, /sitemap.xml.

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rawurldecode($uri ?: '/');

if (preg_match('#^/blog/arsip/?$#', $uri)) {
    $_GET['arsip'] = '1';
    require __DIR__ . '/blog.php';
    return true;
}
if (preg_match('#^/blog/([^/]+)/?$#', $uri, $m)) {
    $_GET['slug'] = rawurldecode($m[1]);
    require __DIR__ . '/blog.php';
    return true;
}
if (preg_match('#^/admin/?$#', $uri)) {
    require __DIR__ . '/admin.php';
    return true;
}
if (preg_match('#^/login/?$#', $uri)) {
    require __DIR__ . '/admin.php';
    return true;
}
if (preg_match('#^/menulis/?$#', $uri)) {
    require __DIR__ . '/penulis-panel.php';
    return true;
}
if (preg_match('#^/asesmen/?$#', $uri)) {
    require __DIR__ . '/asesmen.php';
    return true;
}
if (preg_match('#^/penulis/?$#', $uri)) {
    $_GET['u'] = 'admin';
    require __DIR__ . '/penulis.php';
    return true;
}
if (preg_match('#^/penulis/([^/]+)/?$#', $uri, $m)) {
    $_GET['u'] = rawurldecode($m[1]);
    require __DIR__ . '/penulis.php';
    return true;
}
if ($uri === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// Hilangkan ekstensi .php: /index -> /, *.php -> URL tanpa ekstensi.
if (preg_match('#^/index(?:\.php)?/?$#', $uri)) {
    header('Location: /', true, 301);
    return true;
}
if (preg_match('#^/([A-Za-z0-9_-]+)\.php$#', $uri, $m)) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: /' . $m[1] . ($qs !== '' ? '?' . $qs : ''), true, 301);
    return true;
}

// Petakan URL tanpa ekstensi ke file .php yang sesuai.
if (preg_match('#^/([A-Za-z0-9_-]+)/?$#', $uri, $m) && is_file(__DIR__ . '/' . $m[1] . '.php')) {
    require __DIR__ . '/' . $m[1] . '.php';
    return true;
}

$path = __DIR__ . $uri;
if (is_file($path)) {
    return false;
}

// Folder modul di subdirektori (mis. /terpadu/.../master/) → index.php.
if (is_dir($path)) {
    $index = rtrim($path, '/') . '/index.php';
    if (is_file($index)) {
        if (!str_ends_with($uri, '/')) {
            header('Location: ' . $uri . '/', true, 301);
            return true;
        }
        require $index;
        return true;
    }
}

// URL bersih di subdirektori (mis. /terpadu/.../master/tambah-mentor).
if (is_file($path . '.php')) {
    require $path . '.php';
    return true;
}

http_response_code(404);
$_GET['code'] = 'Apekah tak de siape siape (404)';
require __DIR__ . '/error.php';
return true;
