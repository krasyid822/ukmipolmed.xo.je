<?php
require_once __DIR__ . '/authors_helper.php';
require_once __DIR__ . '/assessments_helper.php';
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/seo_corrector_helper.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_sticker') {
	require __DIR__ . '/components/blog-komentar.php';
}
$configPath = __DIR__ . '/data.json';
$defaultPath = __DIR__ . '/default.json';

$defaults = ['posts' => []];
if (is_readable($defaultPath)) {
	$tmp = json_decode((string) file_get_contents($defaultPath), true);
	if (is_array($tmp) && !empty($tmp['posts']) && is_array($tmp['posts'])) {
		$defaults['posts'] = $tmp['posts'];
	}
}

$filterSlug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$isArsip = !empty($_GET['arsip']);

$sharded = function_exists('data_store_is_sharded') && data_store_is_sharded();
if ($sharded) {
	$config = array_merge(data_store_meta_read(), ['posts' => data_store_posts_index_read()]);
	if ($filterSlug !== '') {
		$one = data_store_post_read($filterSlug, true);
		$config['posts'] = is_array($one) ? [$one] : [];
	} elseif ($query !== '') {
		// Pencarian menyentuh body, jadi hanya di sini full posts dimuat.
		$full = data_store_read_all();
		$config['posts'] = is_array($full['posts'] ?? null) ? $full['posts'] : [];
	}
} else {
	$config = store_read_json($configPath);
}

$posts = [];
if (is_array($config) && !empty($config['posts']) && is_array($config['posts'])) {
	$posts = $config['posts'];
} else {
	$posts = $defaults['posts'];
}

// Hanya gunakan postingan aktif (tanpa flag archived)
$activePosts = [];
foreach ($posts as $p) {
    if (empty($p['archived'])) $activePosts[] = $p;
}
$posts = array_values($activePosts);

function e($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function convert_lists($text)
{
	$lines = explode("\n", $text);
	$out = [];
	$mode = null;

	$closeList = function () use (&$out, &$mode) {
		if ($mode === 'ul') $out[] = '</ul>';
		if ($mode === 'ol') $out[] = '</ol>';
		$mode = null;
	};

	foreach ($lines as $line) {
		if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
			if ($mode !== 'ul') {
				$closeList();
				$out[] = '<ul>';
				$mode = 'ul';
			}
			$out[] = '<li>' . $m[1] . '</li>';
			continue;
		}
		if (preg_match('/^\s*\d+[\.)]\s+(.*)$/', $line, $m)) {
			if ($mode !== 'ol') {
				$closeList();
				$out[] = '<ol>';
				$mode = 'ol';
			}
			$out[] = '<li>' . $m[1] . '</li>';
			continue;
		}

		if (trim($line) === '') {
			$closeList();
			$out[] = '';
		} else {
			$closeList();
			$out[] = $line;
		}
	}

	$closeList();
	return implode("\n", $out);
}

function render_markdown($text)
{
	static $pd = null;
	if ($pd === null) {
		require_once __DIR__ . '/lib/Parsedown.php';
		$pd = new Parsedown();
		$pd->setSafeMode(true);
	}
	$text = str_replace(["\r\n", "\r"], "\n", (string) $text);
	$text = preg_replace('/[\x{200B}\x{FEFF}\x{200C}\x{200D}\x{00A0}]/u', '', $text);
	return $pd->text($text);
}

function markdown_to_plain($text)
{
	$html = render_markdown($text);
	$html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
	$plain = strip_tags($html);
	$plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$plain = preg_replace('/\s+/u', ' ', $plain);
	return trim((string) $plain);
}

function render_gdrive($folder, $view)
{
	$folder = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $folder);
	if ($folder === '') return '';
	$view = ($view === 'list') ? 'list' : 'grid';
	$src = 'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folder) . '#' . $view;
	return '<div class="gdrive-embed-wrapper"><iframe src="' . htmlspecialchars($src, ENT_QUOTES) . '" style="width:100%;height:600px;border:0" loading="lazy" allowfullscreen></iframe></div>';
}

$baseHost = $_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id';
if (PHP_SAPI === 'cli-server') {
	$baseUrl = 'http://' . $baseHost;
} else {
	$baseUrl = 'https://' . preg_replace('/:\d+$/', '', (string) $baseHost);
}

// Data URI legacy tidak diunggah saat render: read path tidak boleh menulis
// ke S3 berulang kali. Gunakan migrate_s3.php untuk memindahkannya sekali.

$posts = array_map(function ($post) use ($baseUrl) {
	$title = trim((string) ($post['title'] ?? ''));
	$slug = trim((string) ($post['slug'] ?? ''));
	$summary = trim((string) ($post['summary'] ?? ''));
	$body = (string) ($post['body'] ?? '');
	$imageData = '';
	$image = trim((string) ($post['image'] ?? ''));
	if ($image !== '') {
		if (stripos($image, 'data:') === 0) {
			// Render apa adanya; migrasi media dilakukan sekali via migrate_s3.
			$imageData = $image;
		} elseif ($image[0] === '/') {
			$image = $baseUrl . $image;
		}
	}
	$embedHtml = (string) ($post['embed_html'] ?? '');
	$embedEnabled = !empty($post['embed_enabled']);
	$embedList = [];
	if (!empty($post['embeds']) && is_array($post['embeds'])) {
		foreach ($post['embeds'] as $emb) {
			$emb = trim((string) $emb);
			if ($emb !== '') $embedList[] = $emb;
		}
	}
	if (empty($embedList) && $embedHtml !== '') {
		$embedList[] = $embedHtml;
	}
	$created = $post['created_at'] ?? '';
	$updated = $post['updated_at'] ?? $created;
	$bodyHtml = render_markdown($body);
	if ($summary !== '') {
		$summaryText = markdown_to_plain($summary);
	} else {
		$summaryText = markdown_to_plain($body);
		if (function_exists('mb_substr')) {
			$summaryText = mb_substr($summaryText, 0, 180, 'UTF-8');
		} else {
			$summaryText = substr($summaryText, 0, 180);
		}
		$summaryText = trim($summaryText);
	}
	return [
		'title' => $title ?: 'Tanpa judul',
		'slug' => $slug,
		'summary' => $summaryText,
		'body' => $body,
		'body_html' => $bodyHtml,
		'image' => $image,
		'image_data' => $imageData,
		'author_id' => trim((string) ($post['author_id'] ?? '')),
		'focal_x' => isset($post['focal_x']) ? max(0, min(100, (float) $post['focal_x'])) : 50,
		'focal_y' => isset($post['focal_y']) ? max(0, min(100, (float) $post['focal_y'])) : 50,
		'ig_embed_fix' => !empty($post['ig_embed_fix']),
		'gdrive_folder' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($post['gdrive_folder'] ?? '')),
		'gdrive_view' => (($post['gdrive_view'] ?? 'grid') === 'list') ? 'list' : 'grid',
		'embeds' => $embedList,
		'embed_enabled' => $embedEnabled,
		'comments' => isset($post['comments']) && is_array($post['comments']) ? $post['comments'] : [],
		'created_at' => $created,
		'updated_at' => $updated,
	];
}, $posts);

// Keep a master list of processed posts so we can search across all posts
$allPosts = $posts;

// Query dan slug sudah dibaca di awal request.
// Select posts to show:
if ($filterSlug !== '') {
	// Viewing a single post by slug (search ignored)
	$posts = array_values(array_filter($allPosts, fn($p) => ($p['slug'] ?? '') === $filterSlug));
} elseif ($query !== '') {
	// Search across title, summary and raw body
	$posts = array_values(array_filter($allPosts, function ($p) use ($query) {
		$hay = (string) ($p['title'] ?? '') . ' ' . (string) ($p['summary'] ?? '') . ' ' . (string) ($p['body'] ?? '');
		return stripos($hay, $query) !== false;
	}));
} else {
	// Default: show most recent 10 (preserve original behaviour)
	$posts = $isArsip ? $allPosts : array_slice($allPosts, 0, 10);
}

usort($posts, function ($a, $b) {
	return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

require __DIR__ . '/components/blog-komentar.php';

// Process comment submission (POST)
$comment_error = null;
$comment_success = null;

// Handle delete comment action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {
	$dslug = trim((string) ($_POST['comment_post_slug'] ?? ''));
	$cid = trim((string) ($_POST['comment_id'] ?? ''));
	if (!comment_csrf_valid()) {
		$comment_error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
	} elseif ($dslug !== '' && $cid !== '') {
			// only allow deletion if this comment id is in user's pending_comments cookie (persistent)
			$allowed = false;
			if (!empty($_COOKIE['pending_comments'])) {
				$cur = array_filter(array_map('trim', explode(',', (string) $_COOKIE['pending_comments'])));
				if (in_array($cid, $cur, true)) $allowed = true;
			}
			if (!$allowed) {
				$comment_error = 'Anda tidak berhak menghapus komentar ini';
			} else {
				$del = delete_comment($dslug, $cid);
		if ($del['ok']) {
					// remove id from pending_comments cookie
					if (!empty($_COOKIE['pending_comments'])) {
						$cur = array_filter(array_map('trim', explode(',', (string) $_COOKIE['pending_comments'])));
						$cur = array_values(array_filter($cur, fn($x) => $x !== $cid));
						if (empty($cur)) {
							comment_pending_cookie('', time() - 3600);
						} else {
							comment_pending_cookie(implode(',', $cur), time() + PENDING_COMMENTS_TTL);
						}
					}
			$loc = $_SERVER['REQUEST_URI'] ?: ('/blog/' . urlencode($dslug));
			header('Location: ' . $loc);
			exit;
		} else {
			$comment_error = $del['error'] ?? 'Gagal menghapus komentar';
		}
			}
	} else {
		$comment_error = 'Slug atau id komentar tidak lengkap';
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_post_slug']) && !isset($_POST['action'])) {
	$cslug = trim((string) ($_POST['comment_post_slug'] ?? ''));
	if (!comment_csrf_valid()) {
		$comment_error = 'Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
		$cslug = '';
	}
	if ($cslug !== '') {
		$cname = trim((string) ($_POST['comment_name'] ?? ''));
		$cbody = trim((string) ($_POST['comment_body'] ?? ''));
		$cparent = trim((string) ($_POST['comment_parent_id'] ?? ''));
		$csticker = trim((string) ($_POST['comment_sticker'] ?? ''));
		$cembed = trim((string) ($_POST['comment_embed'] ?? ''));
		$client_token = trim((string) ($_POST['client_token'] ?? ''));
		// Ensure existing comments have IDs so replies can match parents
		@normalize_comment_ids($cslug);
		$res = save_comment($cslug, $cname, $cbody, $cparent !== '' ? $cparent : null, $client_token ?? null, $csticker !== '' ? $csticker : null, $cembed !== '' ? $cembed : null);
		if ($res['ok']) {
			// Redirect to avoid duplicate submits. Simpan ID komentar baru di
			// cookie agar pembuatnya bisa menghapus.
			$newId = $res['id'] ?? '';
			if ($newId !== '') {
				$cur = [];
				if (!empty($_COOKIE['pending_comments'])) {
					$cur = array_filter(array_map('trim', explode(',', (string) $_COOKIE['pending_comments'])));
				}
				if (!in_array($newId, $cur, true)) $cur[] = $newId;
				comment_pending_cookie(implode(',', $cur), time() + PENDING_COMMENTS_TTL);
			}

			$loc = $_SERVER['REQUEST_URI'] ?: ('/blog/' . urlencode($cslug));
			header('Location: ' . $loc);
			exit;
		}
		$comment_error = $res['error'] ?? 'Gagal menyimpan komentar';
	}
}

$baseHost = $_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id';
if (PHP_SAPI === 'cli-server') {
	$publicBase = 'http://' . $baseHost;
	$baseUrl = $publicBase;
} else {
	$publicHost = preg_replace('/:\d+$/', '', (string) $baseHost);
	$publicBase = 'https://' . $publicHost;
	$baseUrl = $publicBase;
}

// Arsip halaman penuh (semua postingan) untuk crawlability
$canonicalSlug = $filterSlug !== '' && count($posts) === 1 ? $posts[0]['slug'] ?? '' : '';

$metaTitle = 'Blog UKMI POLMED – Catatan Kegiatan & Tulisan Terbaru';
$metaDesc = 'Baca catatan kegiatan, berita rilis, dan tulisan inspiratif dari UKMI Politeknik Negeri Medan. Update terbaru seputar dakwah kampus, mentoring, dan event.';
$metaImage = $publicBase . '/logo-ukmi.png';
$ogImageType = '';
if ($isArsip) {
	$metaUrl = $publicBase . '/blog/arsip';
	$metaType = 'website';
} elseif ($canonicalSlug !== '') {
	$metaUrl = $publicBase . '/blog/' . $canonicalSlug;
	$metaType = 'website';
} else {
	$metaUrl = $publicBase . '/blog';
	$metaType = 'website';
}

// If we're showing the list (not a single post), prefer first post image for og:image
if ($filterSlug === '' && !empty($posts) && is_array($posts[0])) {
	$firstImg = trim((string) ($posts[0]['image'] ?? ''));
	$firstSlug = trim((string) ($posts[0]['slug'] ?? ''));
	if ($firstImg !== '' && $firstSlug !== '') {
		$metaImage = $publicBase . '/og-image?slug=' . urlencode($firstSlug);
	}
}

// Optional Facebook App ID (can be set in data.json or default.json as "fb_app_id")
$fbAppId = null;
if (is_array($config) && !empty($config['fb_app_id'])) {
	$fbAppId = trim((string) $config['fb_app_id']);
} elseif (!empty($defaults['fb_app_id'])) {
	$fbAppId = trim((string) $defaults['fb_app_id']);
}

$isNotFound = ($filterSlug !== '' && empty($posts));
$isSearch = ($query !== '');
if ($isNotFound && !headers_sent()) {
	http_response_code(404);
}
$metaRobots = ($isNotFound || $isSearch)
	? 'noindex, follow'
	: 'index, follow, max-snippet:-1, max-image-preview:large';

require_once __DIR__ . '/seo_corrector_helper.php';
$postAuthor = null;
$postWordCount = 0;
if ($filterSlug !== '' && count($posts) === 1) {
    $postMeta = $posts[0];
	$postAuthor = author_display($postMeta['author_id'] ?? '', (string) ($config['yel_yel'] ?? ''));
	$metaTitle = trim((string) ($postMeta['title'] ?? 'Blog UKMI POLMED'));
	$cleanBodyText = seo_corrector_strip_markdown(strip_tags((string) ($postMeta['body'] ?? '')));
	$postWordCount = $cleanBodyText === '' ? 0 : str_word_count($cleanBodyText);
	if ($postWordCount < 20 || strlen(trim((string) ($postMeta['slug'] ?? ''))) < 3) {
		$metaRobots = 'noindex, follow';
	}
	$rawDesc = trim((string) ($postMeta['summary'] ?? ''));
	if ($rawDesc === '') {
		$rawDesc = $cleanBodyText;
	}
	$cleanDesc = seo_corrector_strip_markdown(strip_tags($rawDesc));
	$metaDesc = $cleanDesc !== '' ? seo_corrector_trim_word_boundary($cleanDesc, 155) : $metaDesc;
	if (!empty($postMeta['image']) && is_string($postMeta['image'])) {
		$img = trim($postMeta['image']);
		$imgSlug = trim((string) ($postMeta['slug'] ?? ''));
		if ($imgSlug !== '') {
			$metaImage = $publicBase . '/og-image?slug=' . urlencode($imgSlug);
		}
		if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,#i', $img, $mm)) {
			$ogImageType = strtolower($mm[1]);
		} elseif (preg_match('~\.(jpe?g|png|gif|webp)(?:[?#]|$)~i', $img, $me)) {
			$ext = strtolower($me[1]);
			$ogImageType = in_array($ext, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/' . $ext;
		}
	}
	$metaType = 'article';
} elseif ($isNotFound) {
	$metaTitle = 'Artikel Tidak Ditemukan – Blog UKMI POLMED';
}

?>
<!doctype html>
<html lang="id">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
	<link rel="alternate" hreflang="id" href="<?php echo e($metaUrl); ?>">
	<link rel="alternate" hreflang="x-default" href="<?php echo e($metaUrl); ?>">
	<title><?php echo e($metaTitle); ?></title>
	<link rel="icon" type="image/png" sizes="192x192" href="/logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="32x32" href="/logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="16x16" href="/logo-ukmi.png">
	<link rel="apple-touch-icon" sizes="180x180" href="/logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<script src="/components/ui-modal.js"></script>
	<meta name="description" content="<?php echo e($metaDesc); ?>">
	<meta name="keywords" content="blog UKMI POLMED, kegiatan UKMI POLMED, berita dakwah kampus Medan, mentoring Islam POLMED">
	<meta name="robots" content="<?php echo e($metaRobots); ?>">
	<link rel="canonical" href="<?php echo e($metaUrl); ?>">
	<meta property="og:locale" content="id_ID">
	<meta property="og:site_name" content="UKMI POLMED">
	<?php if (!empty($fbAppId)): ?>
	<meta property="fb:app_id" content="<?php echo e($fbAppId); ?>">
	<?php endif; ?>
	<meta property="og:title" content="<?php echo e($metaTitle); ?>">
	<meta property="og:description" content="<?php echo e($metaDesc); ?>">
	<meta property="og:type" content="<?php echo e($metaType); ?>">
	<meta property="og:url" content="<?php echo e($metaUrl); ?>">
	<meta property="og:image" content="<?php echo e($metaImage); ?>">
	<?php if (!empty($ogImageType)): ?>
	<meta property="og:image:type" content="<?php echo e($ogImageType); ?>">
	<?php endif; ?>
	<meta property="og:image:alt" content="<?php echo e($metaTitle); ?>">
	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:title" content="<?php echo e($metaTitle); ?>">
	<meta name="twitter:description" content="<?php echo e($metaDesc); ?>">
	<meta name="twitter:image" content="<?php echo e($metaImage); ?>">
	<meta name="twitter:image:alt" content="<?php echo e($metaTitle); ?>">
	<?php if ($filterSlug !== '' && count($posts) === 1): ?>
	<script type="application/ld+json">
	<?php echo json_encode([
		'@context' => 'https://schema.org',
		'@type' => 'Article',
		'headline' => $posts[0]['title'] ?? 'Blog UKMI POLMED',
		'description' => $metaDesc,
		'image' => $metaImage,
		'inLanguage' => 'id-ID',
		'wordCount' => $postWordCount,
		'datePublished' => $posts[0]['created_at'] ?? '',
		'dateModified' => $posts[0]['updated_at'] ?? $posts[0]['created_at'] ?? '',
		'author' => (($posts[0]['author_id'] ?? '') !== '' && is_array($postAuthor))
			? ['@type' => 'Person', 'name' => $postAuthor['pen_name'], 'url' => $publicBase . $postAuthor['url']]
			: ['@type' => 'Organization', 'name' => $postAuthor['pen_name'] ?? 'UKMI POLMED', 'url' => $publicBase . '/penulis/admin'],
		'publisher' => ['@type' => 'Organization', 'name' => 'UKMI POLMED', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://ukmipolmed.my.id/logo-ukmi.png']],
		'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $metaUrl],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT); ?>
	</script>
	<script type="application/ld+json">
	<?php echo json_encode([
		'@context' => 'https://schema.org',
		'@type' => 'BreadcrumbList',
		'itemListElement' => [
			['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => $publicBase . '/'],
			['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $publicBase . '/blog'],
			['@type' => 'ListItem', 'position' => 3, 'name' => $posts[0]['title'] ?? 'Artikel', 'item' => $metaUrl],
		],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT); ?>
	</script>
	<?php else: ?>
	<script type="application/ld+json">
	<?php echo json_encode([
		'@context' => 'https://schema.org',
		'@type' => 'Blog',
		'name' => 'Blog UKMI POLMED',
		'description' => 'Catatan kegiatan, berita rilis, dan tulisan terbaru UKMI Politeknik Negeri Medan.',
		'url' => $publicBase . '/blog',
		'publisher' => ['@type' => 'Organization', 'name' => 'UKMI POLMED', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://ukmipolmed.my.id/logo-ukmi.png']],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRETTY_PRINT); ?>
	</script>
	<?php endif; ?>
	<link rel="stylesheet" href="/style.css">
</head>
<body class="page-blog">
	<?php require __DIR__ . '/components/loading-overlay.php'; ?>
		<?php include __DIR__ . '/header.php'; ?>
	<div class="container">
		<?php if ($filterSlug !== '' && count($posts) === 1): ?>
			<p class="blog-brand" style="font-size:1.1rem;font-weight:700;margin:0 0 6px;"><a href="/blog">Blog UKMI POLMED</a></p>
		<?php else: ?>
			<h1><a href="/blog">Blog UKMI POLMED</a></h1>
		<?php endif; ?>
		<p class="lead">Baca berita, catatan kegiatan, dan tulisan inspiratif dari UKMI Politeknik Negeri Medan.</p>
		<?php if ($filterSlug === ''): ?>
			<form method="get" action="/blog" class="search-form" style="margin:12px 0 18px;display:flex;gap:8px;align-items:center;">
				<input type="search" name="q" value="<?php echo e($query ?? ''); ?>" placeholder="Cari judul, ringkasan, atau isi..." style="flex:1;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);">
				<button type="submit" class="btn ghost">Cari</button>
				<?php if (!empty($query)): ?>
					<a href="/blog" class="btn">Reset</a>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<?php if (empty($posts)): ?>
			<?php if (!empty($query)): ?>
				<p class="lead">Tidak ditemukan hasil untuk "<?php echo e($query); ?>".</p>
			<?php else: ?>
				<p class="lead">Belum ada postingan. Salah URL. Atau mungkin telah dihapus.</p>
			<?php endif; ?>
		<?php elseif ($filterSlug !== '' && count($posts) === 1): ?>
			<div class="article">
				<h1><?php echo e($posts[0]['title']); ?></h1>
					<div class="meta">Dipublikasikan: <?php echo e($posts[0]['created_at'] ?: '-'); ?><?php if (!empty($posts[0]['updated_at'])): ?> · Diubah: <?php echo e($posts[0]['updated_at']); ?><?php endif; ?> · Views: <span id="blog-views-inline">-</span></div>
				<?php $__author = author_display($posts[0]['author_id'] ?? '', (string) ($config['yel_yel'] ?? '')); ?>
				<div class="byline" style="display:flex;align-items:center;gap:10px;margin:6px 0 12px;">
					<a href="<?php echo e($__author['url']); ?>" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;">
						<img src="<?php echo e($__author['avatar']); ?>" alt="Avatar <?php echo e($__author['pen_name']); ?>" style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid var(--stroke);">
						<span style="font-size:14px;color:var(--muted);">oleh <strong style="color:var(--text);"><?php echo e($__author['pen_name']); ?></strong></span>
					</a>
				</div>
				<?php $articleImage = !empty($posts[0]['image_data']) ? $posts[0]['image_data'] : ($posts[0]['image'] ?? ''); ?>
				<?php if ($articleImage !== ''): ?>
					<div style="margin:10px 0 14px;"><img src="<?php echo e($articleImage); ?>" alt="Gambar <?php echo e($posts[0]['title']); ?>" style="width:100%; max-height:420px; object-fit:cover; object-position: <?php echo (int) ($posts[0]['focal_x'] ?? 50); ?>% <?php echo (int) ($posts[0]['focal_y'] ?? 50); ?>%; border-radius:12px; border:1px solid var(--stroke);"></div>
				<?php endif; ?>
				<?php
				$igFix = !empty($posts[0]['ig_embed_fix']);
				$aBody = (string) ($posts[0]['body_html'] ?? '');
				if (empty($posts[0]['embed_tokens_v2']) && strpos($aBody, '[[EMBED0]]') === false) {
					$aBody = preg_replace_callback('/\[\[EMBED(\d+)\]\]/', function ($m) { return '[[EMBED' . ((int) $m[1] - 1) . ']]'; }, $aBody);
				}
				$aBody = str_replace('[[GDRIVE]]', '[[GDRIVE0]]', $aBody);
				$embeds = (is_array($posts[0]['embeds'] ?? null)) ? $posts[0]['embeds'] : [];
				$gds = [];
				if (!empty($posts[0]['gdrive_folders']) && is_array($posts[0]['gdrive_folders'])) {
					foreach ($posts[0]['gdrive_folders'] as $g) { if (is_array($g)) $gds[] = $g; }
				} elseif (!empty($posts[0]['gdrive_folder'])) {
					$gds[] = ['id' => $posts[0]['gdrive_folder'], 'view' => $posts[0]['gdrive_view'] ?? 'grid'];
				}
				$placedE = [];
				foreach ($embeds as $i => $emb) {
					$emb = comment_embed_sanitize(trim((string) $emb));
					if ($emb === '') continue;
					$token = '[[EMBED' . $i . ']]';
					if (strpos($aBody, $token) !== false) {
						$wrap = '<div class="embed-box' . ($igFix ? ' instagram-embed-wrapper' : '') . '">' . $emb . '</div>';
						$aBody = str_replace($token, $wrap, $aBody);
						$placedE[$i] = true;
					}
				}
				$placedG = [];
				foreach ($gds as $j => $g) {
					$html = render_gdrive((string) ($g['id'] ?? ''), (string) ($g['view'] ?? 'grid'));
					if ($html === '') continue;
					$token = '[[GDRIVE' . $j . ']]';
					if (strpos($aBody, $token) !== false) { $aBody = str_replace($token, $html, $aBody); $placedG[$j] = true; }
				}
				foreach ($embeds as $i => $emb) {
					$emb = comment_embed_sanitize(trim((string) $emb));
					if ($emb === '' || !empty($placedE[$i])) continue;
					$aBody .= "\n" . '<div class="embed-box' . ($igFix ? ' instagram-embed-wrapper' : '') . '">' . $emb . '</div>';
				}
				foreach ($gds as $j => $g) {
					if (!empty($placedG[$j])) continue;
					$html = render_gdrive((string) ($g['id'] ?? ''), (string) ($g['view'] ?? 'grid'));
					if ($html !== '') $aBody .= "\n" . $html;
				}
				$aBody = preg_replace('/\[\[(EMBED|GDRIVE)\d+\]\]/', '', $aBody);
				?>
				<div class="body"><?php echo $aBody; ?></div>
				<?php
				$relatedPool = $sharded ? data_store_posts_index_read() : $allPosts;
				$relatedPosts = seo_corrector_related_posts($posts[0], is_array($relatedPool) ? $relatedPool : [], 3);
				?>
				<aside class="related-posts" aria-label="Artikel Terkait" style="margin-top:24px;padding:16px;border-radius:14px;border:1px solid var(--stroke);background:rgba(255,255,255,0.025);">
					<h2 style="margin:0 0 10px;font-size:16px;">Artikel Terkait &amp; Jelajahi UKMI POLMED</h2>
					<?php if (!empty($relatedPosts)): ?>
					<ul style="margin:0 0 10px;padding-left:18px;display:flex;flex-direction:column;gap:6px;">
						<?php foreach ($relatedPosts as $rp): ?>
						<li><a href="/blog/<?php echo rawurlencode((string) ($rp['slug'] ?? '')); ?>"><?php echo e($rp['title'] ?? 'Artikel UKMI POLMED'); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
					<div style="font-size:13px;color:var(--muted);display:flex;flex-wrap:wrap;gap:12px;">
						<a href="/faq">FAQ UKMI POLMED</a>
						<span>·</span>
						<a href="/">Info &amp; Pendaftaran UKMI POLMED</a>
					</div>
				</aside>

			</div>

			<?php
			$workerBase = rtrim(trim((string) ($config['worker_redirector_url'] ?? 'https://laporan-mai.krasyid822.workers.dev')), '/');
			$shareUrl = ($canonicalSlug !== '' && $workerBase !== '')
				? ($workerBase . '/blog/' . rawurlencode($canonicalSlug))
				: $metaUrl;
			$shareText = $metaTitle;
			?>
			<div class="share-bar" style="margin-top:16px;padding:14px 16px;border:1px solid var(--stroke);border-radius:14px;background:rgba(255,255,255,0.03);display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
				<strong style="font-size:14px;color:var(--muted);">Bagikan:</strong>
				<a class="btn ghost share-btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?php echo urlencode($shareText . ' ' . $shareUrl); ?>" data-insight="share_wa" aria-label="Bagikan ke WhatsApp" title="Bagikan ke WhatsApp">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.79 14.01c-.24.68-1.41 1.3-1.95 1.38-.5.07-1.13.1-1.82-.12-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.79-4.17-4.94-4.36-.14-.2-1.18-1.57-1.18-2.99 0-1.42.74-2.12 1.01-2.41.26-.29.58-.36.77-.36.19 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.82 2.01.89 2.15.07.15.12.32.02.51-.1.2-.15.32-.29.49-.15.17-.31.38-.44.51-.14.15-.3.31-.13.6.17.29.75 1.24 1.61 2.01 1.11.99 2.04 1.3 2.33 1.45.29.14.46.12.63-.07.17-.2.73-.85.92-1.14.19-.29.39-.24.65-.15.27.1 1.69.8 1.98.94.29.15.48.22.55.34.07.12.07.7-.17 1.38z"/></svg>
				</a>
				<a class="btn ghost share-btn" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($shareUrl); ?>" data-insight="share_fb" aria-label="Bagikan ke Facebook" title="Bagikan ke Facebook">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 22v-8h2.7l.4-3H13.5V9.1c0-.87.24-1.46 1.5-1.46H16.6V4.9c-.28-.04-1.24-.12-2.36-.12-2.34 0-3.94 1.43-3.94 4.05V11H7.7v3h2.6v8h3.2z"/></svg>
				</a>
				<a class="btn ghost share-btn" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?php echo urlencode($shareText); ?>&amp;url=<?php echo urlencode($shareUrl); ?>" data-insight="share_x" aria-label="Bagikan ke X" title="Bagikan ke X">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
				</a>
				<a class="btn ghost share-btn" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?php echo urlencode($shareUrl); ?>&amp;text=<?php echo urlencode($shareText); ?>" data-insight="share_tg" aria-label="Bagikan ke Telegram" title="Bagikan ke Telegram">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
				</a>
				<a class="btn ghost share-btn" target="_blank" rel="noopener" href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($shareUrl); ?>" data-insight="share_li" aria-label="Bagikan ke LinkedIn" title="Bagikan ke LinkedIn">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/></svg>
				</a>
				<button type="button" class="btn ghost share-btn js-copy-share" data-url="<?php echo e($shareUrl); ?>" data-insight="share_copy" aria-label="Salin tautan" title="Salin tautan">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>
				</button>
			</div>
			<script>
			if (!window.__ukmiShareCopyBound) {
				window.__ukmiShareCopyBound = true;
				document.addEventListener('click', function (e) {
					var b = e.target.closest && e.target.closest('.js-copy-share');
					if (!b) return;
					e.preventDefault();
					var u = b.getAttribute('data-url') || location.href;
					var done = function () {
						if (!b.dataset.origHtml) {
							b.dataset.origHtml = b.innerHTML;
							b.dataset.origTitle = b.getAttribute('title') || 'Salin tautan';
							b.dataset.origLabel = b.getAttribute('aria-label') || 'Salin tautan';
						}
						b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
						b.classList.add('is-copied');
						b.setAttribute('title', 'Tersalin!');
						b.setAttribute('aria-label', 'Tersalin!');
						clearTimeout(b._copyTimer);
						b._copyTimer = setTimeout(function () {
							b.innerHTML = b.dataset.origHtml;
							b.classList.remove('is-copied');
							b.setAttribute('title', b.dataset.origTitle);
							b.setAttribute('aria-label', b.dataset.origLabel);
						}, 2000);
					};
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(u).then(done).catch(function () { uiPrompt('Salin tautan:', u); });
					} else {
						uiPrompt('Salin tautan:', u);
					}
				});
			}
			</script>

			<?php $__assess = assessments_for_post($posts[0]['slug'] ?? ''); ?>
			<?php foreach ($__assess as $as): ?>
				<div style="margin-top:16px;padding:16px;border:1px solid var(--stroke);border-radius:14px;background:rgba(255,255,255,0.03);">
					<h3 style="margin:0 0 10px;font-size:17px;"><?php echo e((string) ($as['title'] ?? 'Asesmen')); ?></h3>
					<a class="btn primary" href="/asesmen?id=<?php echo urlencode((string) ($as['id'] ?? '')); ?>">Kerjakan asesmen</a>
				</div>
			<?php endforeach; ?>

			<?php
			// Separate comments UI into its own card below the article
			$postSlug = $posts[0]['slug'] ?? '';
			$commentCsrf = comment_csrf_token();
			$allComments = !empty($posts[0]['comments']) && is_array($posts[0]['comments']) ? $posts[0]['comments'] : [];
			$comments = comment_filter_visible($allComments);
			?>
			<div class="card" id="comment-card" style="margin-top:18px;">
				<h3>Komentar (<?php echo count($comments); ?>)</h3>
				<?php if (!empty($comment_error)): ?><div style="color:#f87171;"><?php echo e($comment_error); ?></div><?php endif; ?>
				<?php if (!empty($comments)): ?>
					<?php
					// show remaining comment storage in this card
					$cs = get_comments_storage_info();
					?>
					<div style="margin-bottom:8px;color:var(--muted);font-size:13px;">Sisa penyimpanan komentar: <?php echo $cs['remaining_mb'] === null ? 'mengikuti kapasitas S3' : htmlspecialchars($cs['remaining_mb'] . ' MB', ENT_QUOTES, 'UTF-8'); ?> (<?php echo $cs['percent_used'] === null ? 'batas dinamis' : htmlspecialchars($cs['percent_used'] . ' % terpakai', ENT_QUOTES, 'UTF-8'); ?>)</div>
					<?php
					function render_comment_tree($c, $postSlug, $commentCsrf) {
						$__edited = comment_is_edited_by_admin($c);
						?>
						<li id="comment-<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="comment-item<?php echo $__edited ? ' has-edited-badge' : ''; ?>" style="position:relative;margin-bottom:10px;padding:10px;<?php echo $__edited ? 'padding-bottom:48px;' : ''; ?>border-radius:8px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.03);">
							<strong><?php echo htmlspecialchars($c['name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<?php if ($__edited): ?>
								<?php $__adminPenName = author_display('')['pen_name']; ?>
								<a class="comment-edited-badge" href="/tentang-badge" target="_blank" rel="noopener" title="Komentar ini disunting oleh <?php echo e($__adminPenName); ?>. Klik untuk penjelasan badge.">
									<img src="/badge-diedit-admin.svg" alt="Badge Diedit oleh <?php echo e($__adminPenName); ?>" width="42" height="42" loading="lazy">
								</a>
							<?php endif; ?>
							<div style="font-size:13px;color:var(--muted);margin-bottom:6px;">
								<?php echo htmlspecialchars($c['created_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
								<span style="margin-left:10px;">
									<a href="#" class="reply-btn" data-parent-id="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="font-size:13px;color:var(--accent);">Balas</a>
								</span>
								<span style="margin-left:8px;">
									<form method="post" class="delete-comment-form" style="display:inline;">
										<input type="hidden" name="action" value="delete_comment">
										<input type="hidden" name="comment_csrf" value="<?php echo e($commentCsrf); ?>">
										<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($postSlug, ENT_QUOTES, 'UTF-8'); ?>">
										<input type="hidden" name="comment_id" value="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
										<button type="submit" class="delete-btn" onclick="return uiConfirm(event, 'Hapus komentar ini?')"><u>Hapus</u></button>
									</form>
								</span>
							</div>
							<div style="white-space:pre-line;margin-bottom:8px;">
								<?php echo htmlspecialchars(trim((string) ($c['body'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
							</div>
							<?php $cSticker = comment_sticker_key($c['sticker'] ?? ''); ?>
							<?php if ($cSticker !== ''): ?>
								<div class="comment-sticker-wrap">
									<img class="comment-sticker" src="<?php echo e(s3_to_relative_url(s3_photo_url($cSticker))); ?>" alt="Stiker komentar dari <?php echo e($c['name'] ?? 'pengunjung'); ?>" loading="lazy">
								</div>
							<?php endif; ?>
							<?php $cEmbed = comment_embed_sanitize($c['embed'] ?? ''); ?>
							<?php if ($cEmbed !== ''): ?>
								<div class="comment-embed"><?php echo $cEmbed; ?></div>
							<?php endif; ?>
							<div class="reply-form" data-parent="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="display:none;">
								<form method="post" data-comment-form>
									<input type="hidden" name="comment_csrf" value="<?php echo e($commentCsrf); ?>">
									<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($postSlug, ENT_QUOTES, 'UTF-8'); ?>">
									<input type="hidden" name="comment_parent_id" value="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
									<div style="margin-bottom:6px;"><input type="text" name="comment_name" required placeholder="Nama" style="width:100%;padding:6px;border-radius:6px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></div>
									<div style="margin-bottom:6px;"><textarea name="comment_body" rows="3" data-comment-body placeholder="Balasan Anda" style="width:100%;padding:6px;border-radius:6px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></textarea></div>
									<div><button type="submit" class="btn primary">Kirim Balasan</button></div>
								</form>
							</div>
							<?php if (!empty($c['replies']) && is_array($c['replies'])): ?>
								<ul class="comment-replies" style="padding-left:12px;margin-top:10px;">
									<?php foreach ($c['replies'] as $r): ?>
										<?php render_comment_tree($r, $postSlug, $commentCsrf); ?>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
						<?php
					}
					?>
					<ul class="comment-list" style="padding:0;margin:8px 0 14px;">
						<?php foreach ($comments as $c): ?>
							<?php render_comment_tree($c, $postSlug, $commentCsrf); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p style="color:var(--muted);">Belum ada komentar. Jadilah yang pertama!</p>
				<?php endif; ?>

				<form method="post" data-comment-form style="margin-top:12px;">
					<input type="hidden" name="comment_csrf" value="<?php echo e($commentCsrf); ?>">
					<input type="hidden" name="comment_post_slug" value="<?php echo e($postSlug); ?>">
					<div style="margin-bottom:8px;"><label>Nama</label><br><input type="text" name="comment_name" required style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></div>
					<div style="margin-bottom:8px;"><label>Komentar</label><br><textarea name="comment_body" rows="4" data-comment-body style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></textarea></div>
					<div><button type="submit" class="btn primary">Kirim Komentar</button></div>
				</form>
			</div>
		<?php elseif ($isArsip): ?>
			<h2 style="margin:24px 0 12px;font-size:20px;color:var(--text);">Arsip Semua Postingan</h2>
			<div class="grid">
				<?php foreach ($posts as $post): ?>
					<a class="card" href="/blog/<?php echo urlencode($post['slug'] ?? ''); ?>" style="text-decoration:none; color:inherit; display:block;">
						<?php if (!empty($post['image'])): ?>
							<div style="margin:-6px -6px 10px; overflow:hidden; border-radius:10px; border:1px solid var(--stroke);">
								<img src="<?php echo e($post['image']); ?>" alt="Gambar <?php echo e($post['title']); ?>" loading="lazy" decoding="async" style="width:100%; height:180px; object-fit:cover; display:block; object-position: <?php echo (int) ($post['focal_x'] ?? 50); ?>% <?php echo (int) ($post['focal_y'] ?? 50); ?>%;">
							</div>
						<?php endif; ?>
						<h3><?php echo e($post['title']); ?></h3>
						<div class="meta">Dipublikasikan: <?php echo e($post['created_at'] ?: '-'); ?></div>
						<div class="summary"><?php echo e($post['summary']); ?></div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php else: ?>
			<div class="grid">
				<?php foreach ($posts as $post): ?>
					<a class="card" href="/blog/<?php echo urlencode($post['slug'] ?? ''); ?>" style="text-decoration:none; color:inherit; display:block;">
						<?php if (!empty($post['image'])): ?>
							<div style="margin:-6px -6px 10px; overflow:hidden; border-radius:10px; border:1px solid var(--stroke);">
								<img src="<?php echo e($post['image']); ?>" alt="Gambar <?php echo e($post['title']); ?>" loading="lazy" decoding="async" style="width:100%; height:180px; object-fit:cover; display:block; object-position: <?php echo (int) ($post['focal_x'] ?? 50); ?>% <?php echo (int) ($post['focal_y'] ?? 50); ?>%;">
							</div>
						<?php endif; ?>
						<h3><?php echo e($post['title']); ?></h3>
						<div class="meta">Dipublikasikan: <?php echo e($post['created_at'] ?: '-'); ?></div>
						<div class="summary"><?php echo e($post['summary']); ?></div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php require __DIR__ . '/components/blog-scripts.php'; ?>
</body>
</html>
