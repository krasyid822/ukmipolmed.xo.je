<?php
$code = isset($_GET['code']) ? trim($_GET['code'], "\"' ") : '';
$errorText = $code === '' ? 'Ngak tau kenapa nih :(' : htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errorTitle = 'Terjadi Kesalahan';
$errorDesc = 'Halaman yang kamu cari tidak tersedia atau terjadi kesalahan. Kembali ke beranda UKMI Polmed untuk info open recruitment, agenda, dan dokumentasi kegiatan.';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
	<title><?php echo e($errorTitle); ?> – UKMI Polmed</title>
	<meta name="description" content="<?php echo e($errorDesc); ?>">
	<meta name="robots" content="noindex, follow">
	<link rel="canonical" href="https://ukmipolmed.xo.je/">
	<link rel="icon" type="image/png" sizes="192x192" href="logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="32x32" href="logo-ukmi.png">
	<link rel="apple-touch-icon" sizes="180x180" href="logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<meta property="og:locale" content="id_ID">
	<meta property="og:type" content="website">
	<meta property="og:title" content="<?php echo e($errorTitle); ?> – UKMI Polmed">
	<meta property="og:description" content="<?php echo e($errorDesc); ?>">
	<meta property="og:url" content="https://ukmipolmed.xo.je/">
	<meta property="og:image" content="https://ukmipolmed.xo.je/logo-ukmi.png">
	<meta property="og:site_name" content="UKMI Polmed">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
	<style>
		:root {
			--bg: #0b0c0f;
			--glass: rgba(0, 0, 0, 0.55);
			--accent: #c5ff6a;
			--muted: #9fb0c7;
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			background: var(--bg);
			color: #f6f8fb;
			font-family: 'Manrope', sans-serif;
			display: flex;
			align-items: center;
			justify-content: center;
			overflow: hidden;
			-webkit-text-size-adjust: 100%;
		}

		.loading-screen {
			position: relative;
			width: 100%;
			height: 100vh;
			overflow: hidden;
		}

		.loading-screen::before {
			content: '';
			position: absolute;
			inset: 0;
			background: radial-gradient(circle at 20% 20%, rgba(197, 255, 106, 0.18), transparent 32%),
						radial-gradient(circle at 80% 30%, rgba(255, 255, 255, 0.15), transparent 36%),
						linear-gradient(135deg, rgba(0, 0, 0, 0.62), rgba(0, 0, 0, 0.8));
			z-index: 1;
		}

		.media-wrap {
			position: absolute;
			inset: 0;
			display: grid;
			place-items: center;
			padding: 32px;
			z-index: 2;
		}

		.media {
			position: relative;
			width: min(420px, 70vw);
			filter: drop-shadow(0 10px 28px rgba(0, 0, 0, 0.4));
		}

		.gif-frame {
			display: block;
			width: 100%;
			height: auto;
			border-radius: 18px;
			background: #11111100;
			object-fit: cover;
		}

		.logo {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 40%;
			height: auto;
			max-width: 170px;
			max-height: 170px;
			filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.45));
		}

		.overlay-card {
			position: absolute;
			left: 50%;
			bottom: 8vh;
			transform: translateX(-50%);
			padding: 14px 18px 18px;
			border-radius: 14px;
			background: rgb(51 19 19 / 55%);
			backdrop-filter: blur(12px);
			border: 1px solid rgb(255 0 0);
			width: min(360px, 82vw);
			z-index: 3;
		}

		/* Visually hidden H1 for SEO — preserves original UI */
		.sr-only {
			position: absolute;
			width: 1px;
			height: 1px;
			padding: 0;
			margin: -1px;
			overflow: hidden;
			clip: rect(0, 0, 0, 0);
			white-space: nowrap;
			border: 0;
		}

		.status {
			margin: 0 0 10px;
			font-size: 1.05rem;
			letter-spacing: 0.01em;
			font-weight: 600;
		}

		.hint {
			margin: 10px 0 0;
			font-size: 0.9rem;
			color: var(--muted);
		}

		.hint a {
			color: var(--accent);
			text-decoration: none;
		}

		.hint a:hover {
			text-decoration: underline;
		}

		.social-row {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin-top: 12px;
		}

		.social-row a {
			color: var(--muted);
			text-decoration: none;
			font-size: 0.8rem;
			padding: 6px 10px;
			border-radius: 8px;
			border: 1px solid rgba(255, 255, 255, 0.1);
			background: rgba(255, 255, 255, 0.05);
			min-height: 44px;
			display: inline-flex;
			align-items: center;
		}

		.social-row a:hover {
			color: var(--accent);
			border-color: rgba(197, 255, 106, 0.3);
		}

		@media (max-width: 640px) {
			.overlay-card { bottom: 6vh; }
			.status { font-size: 1rem; }
			.hint { font-size: 0.85rem; }
		}
	</style>
</head>
<body>
	<h1 class="sr-only"><?php echo e($errorTitle); ?> – UKMI Polmed</h1>
	<div class="loading-screen">
		<div class="media-wrap">
			<div class="media">
				<img class="gif-frame" src="palestine.gif" alt="Loading animation" width="420" height="420">
				<img class="logo" src="logo-ukmi.png" alt="Logo UKMI Polmed" width="170" height="170">
			</div>
		</div>

		<div class="overlay-card">
			<p class="status">Terjadi kesalahan... !</p>
			<p class="hint"><?php echo $errorText; ?></p>
			<p class="hint"><a href="/">← Kembali ke Beranda</a> · <a href="/blog.php">Blog</a></p>
			<div class="social-row">
				<a href="https://www.instagram.com/ukmipolmed/" target="_blank" rel="noopener">Instagram</a>
				<a href="https://www.youtube.com/c/UKMIPolmedTV" target="_blank" rel="noopener">YouTube</a>
				<a href="https://web.facebook.com/ukmipolmedmedan/" target="_blank" rel="noopener">Facebook</a>
				<a href="https://x.com/ukmipolmed" target="_blank" rel="noopener">X</a>
				<a href="https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan" target="_blank" rel="noopener">LinkedIn</a>
			</div>
		</div>
	</div>
</body>
</html>
