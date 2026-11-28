<?php
$code = isset($_GET['code']) ? trim($_GET['code'], "\"' ") : '';
if (preg_match('/\((\d{3})\)\s*$/u', $code, $statusMatch)) {
	$statusCode = (int) $statusMatch[1];
	if ($statusCode >= 400 && $statusCode <= 599 && !headers_sent()) {
		http_response_code($statusCode);
	}
}
$errorText = $code === '' ? 'Ngak tau kenapa nih :(' : htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errorTitle = 'Terjadi Kesalahan';
$errorDesc = 'Halaman yang kamu cari tidak tersedia atau terjadi kesalahan. Kembali ke beranda UKMI POLMED untuk info open recruitment, agenda, dan dokumentasi kegiatan.';

$canonicalHost = preg_replace('/:\\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.xo.je'));
$canonicalBase = 'https://' . $canonicalHost;
$canonicalUrl = $canonicalBase . '/';
$logoAbsoluteUrl = $canonicalBase . '/logo-ukmi.png';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
	<title><?php echo e($errorTitle); ?> – UKMI POLMED</title>
	<meta name="description" content="<?php echo e($errorDesc); ?>">
	<meta name="robots" content="noindex, follow">
	<link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="/logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/logo-ukmi.png">
	<link rel="apple-touch-icon" sizes="180x180" href="/logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<meta property="og:locale" content="id_ID">
	<meta property="og:type" content="website">
	<meta property="og:title" content="<?php echo e($errorTitle); ?> – UKMI POLMED">
	<meta property="og:description" content="<?php echo e($errorDesc); ?>">
	<meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
	<meta property="og:image" content="<?php echo e($logoAbsoluteUrl); ?>">
	<meta property="og:site_name" content="UKMI POLMED">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/style.css">
</head>
<body class="page-error">
	<h1 class="sr-only"><?php echo e($errorTitle); ?> – UKMI POLMED</h1>
	<div class="loading-screen">
		<div class="media-wrap">
			<div class="media">
				<img class="gif-frame" src="/palestine.gif" alt="Loading animation" width="420" height="420">
				<img class="logo" src="/logo-ukmi.png" alt="Logo UKMI POLMED" width="170" height="170">
			</div>
		</div>

		<div class="overlay-card">
			<p class="status">Terjadi kesalahan... !</p>
			<p class="hint"><?php echo $errorText; ?></p>
			<p class="hint"><a href="/">← Kembali ke Beranda</a> · <a href="/blog">Blog</a></p>
			<div class="social-row">
				<a href="https://www.instagram.com/ukmipolmed/" target="_blank" rel="noopener">Instagram</a>
				<a href="https://www.youtube.com/@UKMIPolmedTV" target="_blank" rel="noopener">YouTube</a>
				<a href="https://web.facebook.com/ukmipolmedmedan/" target="_blank" rel="noopener">Facebook</a>
				<a href="https://x.com/ukmipolmed" target="_blank" rel="noopener">X</a>
				<a href="https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan" target="_blank" rel="noopener">LinkedIn</a>
			</div>
		</div>
	</div>
</body>
</html>
