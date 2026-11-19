<?php
// zend.php — phpinfo() hanya untuk admin yang sudah login.
// Sesi diperiksa sama seperti admin.php (components/bootstrap.php):
// harus `admin_logged_in`, `session_version` cocok, dan role `admin`.

declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive');

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

require_once __DIR__ . '/store.php';

$config = store_read_json(__DIR__ . '/data.json');
$sessionVersion = isset($config['session_version']) ? max(1, (int) $config['session_version']) : 1;
$loggedIn = !empty($_SESSION['admin_logged_in'])
	&& (($_SESSION['session_version'] ?? 0) === $sessionVersion)
	&& (($_SESSION['role'] ?? '') === 'admin');

if (!$loggedIn) {
	http_response_code(403);
	header('Content-Type: text/html; charset=utf-8');
	echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
		. '<meta name="robots" content="noindex,nofollow"><title>Akses ditolak</title></head>'
		. '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:12vh auto;padding:0 20px;">'
		. '<h1 style="font-size:20px;margin:0 0 8px;">Akses ditolak</h1>'
		. '<p style="color:#555;line-height:1.6;">Halaman ini hanya untuk admin yang sudah login. '
		. 'Silakan <a href="/admin">masuk sebagai admin</a> terlebih dahulu.</p>'
		. '</body></html>';
	exit;
}

phpinfo();
