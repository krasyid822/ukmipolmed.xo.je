<?php
function e($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id'));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$baseUrl = $scheme . '://' . $host;
$canonicalUrl = $baseUrl . '/tentang-badge';
$badgeUrl = $baseUrl . '/badge-diedit-admin.svg';
$pageTitle = 'Tentang Badge “Diedit oleh Admin UKMI POLMED”';
$pageDesc = 'Penjelasan arti badge Diedit oleh Admin UKMI POLMED pada komentar blog: transparansi penyuntingan oleh admin, alasan penyuntingan, dan apa yang tidak diubah.';
?>
<!doctype html>
<html lang="id">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
	<title><?php echo e($pageTitle); ?> – UKMI POLMED</title>
	<meta name="description" content="<?php echo e($pageDesc); ?>">
	<meta name="robots" content="noindex, follow">
	<link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
	<meta property="og:locale" content="id_ID">
	<meta property="og:type" content="article">
	<meta property="og:title" content="<?php echo e($pageTitle); ?>">
	<meta property="og:description" content="<?php echo e($pageDesc); ?>">
	<meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
	<meta property="og:image" content="<?php echo e($badgeUrl); ?>">
	<meta property="og:image:alt" content="Badge Diedit oleh Admin UKMI POLMED">
	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:title" content="<?php echo e($pageTitle); ?>">
	<meta name="twitter:description" content="<?php echo e($pageDesc); ?>">
	<meta name="twitter:image" content="<?php echo e($badgeUrl); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="/logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/logo-ukmi.png">
	<link rel="apple-touch-icon" sizes="180x180" href="/logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
	<style>
		:root {
			--bg: #070b15;
			--card: #0d1524;
			--stroke: rgba(255, 255, 255, 0.09);
			--text: #f2f6ff;
			--muted: #9fb0c7;
			--accent: #c5ff6a;
			--accent-2: #6cf7c5;
			--gold: #e6c15b;
		}
		* { box-sizing: border-box; }
		html { scroll-behavior: smooth; }
		body {
			margin: 0;
			min-height: 100vh;
			background:
				radial-gradient(circle at 15% -10%, rgba(197, 255, 106, 0.12), transparent 34%),
				radial-gradient(circle at 85% 0%, rgba(108, 247, 197, 0.08), transparent 32%),
				var(--bg);
			color: var(--text);
			font-family: 'Manrope', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
			line-height: 1.7;
		}
		.badge-wrap {
			max-width: 820px;
			margin: 0 auto;
			padding: 34px 20px 72px;
		}
		.breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 18px; }
		.breadcrumb a { color: var(--accent); text-decoration: none; }
		.breadcrumb a:hover { text-decoration: underline; }
		.hero {
			display: grid;
			grid-template-columns: minmax(0, 1fr) 190px;
			gap: 28px;
			align-items: center;
			padding: 26px;
			border: 1px solid var(--stroke);
			border-radius: 22px;
			background: linear-gradient(145deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
			box-shadow: 0 24px 70px rgba(0, 0, 0, 0.38);
		}
		.kicker {
			display: inline-block;
			margin-bottom: 10px;
			padding: 3px 10px;
			border-radius: 999px;
			border: 1px solid rgba(230, 193, 91, 0.4);
			background: rgba(230, 193, 91, 0.1);
			color: var(--gold);
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
		}
		h1 {
			margin: 0 0 10px;
			font-family: 'Space Grotesk', 'Manrope', sans-serif;
			font-size: clamp(26px, 4vw, 38px);
			line-height: 1.15;
			letter-spacing: -0.01em;
		}
		.lead { margin: 0; color: var(--muted); font-size: 16px; }
		.badge-img {
			display: block;
			width: 100%;
			height: auto;
			border-radius: 50%;
			filter: drop-shadow(0 12px 28px rgba(0, 0, 0, 0.45));
		}
		.section {
			margin-top: 22px;
			padding: 22px 24px;
			border: 1px solid var(--stroke);
			border-radius: 18px;
			background: rgba(13, 21, 36, 0.72);
		}
		h2 {
			margin: 0 0 10px;
			font-family: 'Space Grotesk', 'Manrope', sans-serif;
			font-size: 20px;
			letter-spacing: -0.01em;
		}
		p { margin: 0 0 12px; }
		p:last-child { margin-bottom: 0; }
		ul { margin: 0; padding-left: 22px; }
		li { margin: 6px 0; }
		li::marker { color: var(--accent); }
		.note {
			border-left: 3px solid var(--accent);
			padding: 12px 16px;
			border-radius: 0 12px 12px 0;
			background: rgba(197, 255, 106, 0.07);
			color: #e7f7cf;
		}
		.actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-height: 44px;
			padding: 10px 18px;
			border-radius: 12px;
			border: 1px solid rgba(255, 255, 255, 0.14);
			background: rgba(255, 255, 255, 0.05);
			color: var(--text);
			text-decoration: none;
			font-weight: 700;
			font-size: 14px;
		}
		.btn.primary {
			border: none;
			background: linear-gradient(135deg, var(--accent), var(--accent-2));
			color: #07120b;
		}
		.btn:hover { border-color: rgba(255, 255, 255, 0.3); }
		.footer-note { margin-top: 30px; color: var(--muted); font-size: 13px; text-align: center; }
		@media (max-width: 640px) {
			.hero { grid-template-columns: 1fr; text-align: center; }
			.badge-col { max-width: 150px; margin: 0 auto; order: -1; }
			.section { padding: 18px; }
		}
	</style>
	<script type="application/ld+json">
	<?php echo json_encode([
		'@context' => 'https://schema.org',
		'@type' => 'AboutPage',
		'name' => $pageTitle,
		'description' => $pageDesc,
		'url' => $canonicalUrl,
		'image' => $badgeUrl,
		'isPartOf' => [
			'@type' => 'WebSite',
			'name' => 'UKMI Politeknik Negeri Medan',
			'url' => $baseUrl . '/',
		],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
	</script>
</head>
<body>
	<?php require __DIR__ . '/components/loading-overlay.php'; ?>
	<?php include __DIR__ . '/header.php'; ?>
	<main class="badge-wrap">
		<nav class="breadcrumb" aria-label="Breadcrumb">
			<a href="/">Beranda</a> · <a href="/blog">Blog</a> · <span>Tentang Badge</span>
		</nav>

		<section class="hero">
			<div>
				<span class="kicker">Transparansi Moderasi</span>
				<h1>Tentang Badge “Diedit oleh Admin UKMI POLMED”</h1>
				<p class="lead">Badge ini menandai komentar pengunjung yang telah disunting oleh admin UKMI POLMED. Tujuannya bukan mengubah pendapat pengunjung, melainkan menjaga keterbacaan, keamanan, dan ketertiban ruang diskusi blog.</p>
			</div>
			<div class="badge-col">
				<img class="badge-img" src="/badge-diedit-admin.svg" alt="Badge resmi Diedit oleh Admin UKMI POLMED" width="190" height="190">
			</div>
		</section>

		<section class="section">
			<h2>Apa arti badge ini?</h2>
			<p>Jika sebuah komentar menampilkan badge ini, berarti isi komentar tersebut pernah diperiksa dan disunting oleh admin. Badge muncul otomatis setelah penyuntingan disimpan, sehingga pengunjung tahu bahwa teks yang tampil mungkin tidak 100% sama dengan kiriman awal.</p>
			<p>Badge tidak berarti komentar tersebut salah, buruk, atau berasal dari admin. Komentar tetap milik pengunjung yang mengirimnya.</p>
		</section>

		<section class="section">
			<h2>Kapan admin menyunting komentar?</h2>
			<ul>
				<li>Memperbaiki salah ketik, tanda baca, atau pemformatan yang merusak tampilan.</li>
				<li>Menghapus tautan spam, promosi tidak relevan, atau kode berbahaya.</li>
				<li>Menyensor data pribadi seperti nomor telepon, alamat, atau informasi sensitif.</li>
				<li>Merapikan stiker atau embed HTML yang tidak tampil dengan benar atau melanggar aturan.</li>
				<li>Menyesuaikan konten agar tetap sopan dan sesuai nilai dakwah kampus.</li>
			</ul>
		</section>

		<section class="section">
			<h2>Yang tidak dilakukan admin</h2>
			<p>Admin tidak mengubah makna atau pendapat komentar, tidak memalsukan identitas pengunjung, dan tidak menjadikan komentar seolah-olah berasal dari pihak lain. Jika sebuah komentar dinilai melanggar aturan berat, admin dapat menyembunyikannya (suspend) atau menghapusnya, bukan menyuntingnya menjadi pendapat yang berbeda.</p>
			<p class="note">Punya pertanyaan tentang komentar yang disunting? Hubungi admin UKMI POLMED melalui Instagram <a href="https://www.instagram.com/ukmipolmed/" target="_blank" rel="noopener" style="color:var(--accent);">@ukmipolmed</a> dengan menyertakan tautan postingan terkait.</p>
		</section>

		<div class="actions">
			<a class="btn primary" href="/blog">Kembali ke Blog</a>
			<a class="btn" href="/faq">Baca FAQ UKMI POLMED</a>
		</div>

		<p class="footer-note">Halaman ini adalah bagian dari komitmen transparansi moderasi UKMI Politeknik Negeri Medan.</p>
	</main>
</body>
</html>
