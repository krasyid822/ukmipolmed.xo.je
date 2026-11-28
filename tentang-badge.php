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
	<link rel="stylesheet" href="/style.css">
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
<body class="page-badge">
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
