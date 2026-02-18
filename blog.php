<?php
$configPath = __DIR__ . '/data.json';
$defaultPath = __DIR__ . '/default.json';

$defaults = ['posts' => []];
if (is_readable($defaultPath)) {
	$tmp = json_decode((string) file_get_contents($defaultPath), true);
	if (is_array($tmp) && !empty($tmp['posts']) && is_array($tmp['posts'])) {
		$defaults['posts'] = $tmp['posts'];
	}
}

$config = null;
if (is_readable($configPath)) {
	$config = json_decode((string) file_get_contents($configPath), true);
}

$posts = [];
if (is_array($config) && !empty($config['posts']) && is_array($config['posts'])) {
	$posts = $config['posts'];
} else {
	$posts = $defaults['posts'];
}

$posts = array_map(function ($post) {
	$title = trim((string) ($post['title'] ?? ''));
	$slug = trim((string) ($post['slug'] ?? ''));
	$summary = trim((string) ($post['summary'] ?? ''));
	$body = (string) ($post['body'] ?? '');
	$image = trim((string) ($post['image'] ?? ''));
	$created = $post['created_at'] ?? '';
	$updated = $post['updated_at'] ?? $created;
	return [
		'title' => $title ?: 'Tanpa judul',
		'slug' => $slug,
		'summary' => $summary,
		'body' => $body,
		'image' => $image,
		'created_at' => $created,
		'updated_at' => $updated,
	];
}, $posts);

$posts = array_slice($posts, 0, 10);

usort($posts, function ($a, $b) {
	return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

$filterSlug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
if ($filterSlug !== '') {
	$posts = array_values(array_filter($posts, fn($p) => ($p['slug'] ?? '') === $filterSlug));
}

function e($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Blog UKMI Polmed</title>
	<link rel="icon" type="image/png" href="logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<style>
		:root {
			--bg: #0b1020;
			--card: rgba(255, 255, 255, 0.04);
			--stroke: rgba(255, 255, 255, 0.08);
			--text: #e9eef7;
			--muted: #c3cddc;
			--accent: #38bdf8;
		}

		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			min-height: 100vh;
			background: radial-gradient(circle at 25% 20%, rgba(56, 189, 248, 0.14), transparent 35%),
				radial-gradient(circle at 80% 0%, rgba(248, 113, 113, 0.12), transparent 32%),
				var(--bg);
			color: var(--text);
			padding: 32px 18px 40px;
		}

		.container {
			max-width: 1120px;
			margin: 0 auto;
			width: 100%;
		}

		h1 { margin: 0 0 8px; font-size: 28px; }
		p.lead { margin: 0 0 20px; color: var(--muted); }

			.grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
			gap: 16px;
		}

			.card {
				background: var(--card);
				border: 1px solid var(--stroke);
				border-radius: 14px;
				padding: 16px 18px;
				box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
				transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
			}

			.card:hover {
				transform: translateY(-3px);
				border-color: rgba(56, 189, 248, 0.5);
				box-shadow: 0 22px 46px rgba(0, 0, 0, 0.4);
			}

			.card h3 { margin: 0 0 6px; font-size: 18px; }
			.card .meta { color: var(--muted); font-size: 13px; margin-bottom: 8px; }
			.card .summary { color: var(--text); font-size: 14px; line-height: 1.5; }

		.article {
			background: var(--card);
			border: 1px solid var(--stroke);
			border-radius: 14px;
			padding: 20px;
			box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
			margin-top: 16px;
		}

		.article h2 { margin: 0 0 8px; font-size: 24px; }
		.article .meta { color: var(--muted); font-size: 13px; margin-bottom: 12px; }
		.article .body { color: var(--text); font-size: 15px; line-height: 1.7; white-space: pre-line; }
	</style>
</head>
<body>
	<div class="container">
		<h1>Blog UKMI Polmed</h1>
		<p class="lead">Catatan kegiatan, rilis, dan tulisan terbaru.</p>
		<?php if (empty($posts)): ?>
			<p class="lead">Belum ada postingan.</p>
		<?php elseif ($filterSlug !== '' && count($posts) === 1): ?>
			<div class="article">
				<h2><?php echo e($posts[0]['title']); ?></h2>
				<div class="meta">Dipublikasikan: <?php echo e($posts[0]['created_at'] ?: '-'); ?><?php if (!empty($posts[0]['updated_at'])): ?> · Diubah: <?php echo e($posts[0]['updated_at']); ?><?php endif; ?></div>
				<?php if (!empty($posts[0]['image'])): ?>
					<div style="margin:10px 0 14px;"><img src="<?php echo e($posts[0]['image']); ?>" alt="Gambar <?php echo e($posts[0]['title']); ?>" style="width:100%; max-height:420px; object-fit:cover; border-radius:12px; border:1px solid var(--stroke);"></div>
				<?php endif; ?>
				<div class="body"><?php echo nl2br(e($posts[0]['body'])); ?></div>
			</div>
		<?php else: ?>
			<div class="grid">
				<?php foreach ($posts as $post): ?>
					<a class="card" href="?slug=<?php echo urlencode($post['slug'] ?? ''); ?>" style="text-decoration:none; color:inherit; display:block;">
						<?php if (!empty($post['image'])): ?>
							<div style="margin:-6px -6px 10px; overflow:hidden; border-radius:10px; border:1px solid var(--stroke);">
								<img src="<?php echo e($post['image']); ?>" alt="Gambar <?php echo e($post['title']); ?>" style="width:100%; height:180px; object-fit:cover; display:block;">
							</div>
						<?php endif; ?>
						<h3><?php echo e($post['title']); ?></h3>
						<div class="meta">Dipublikasikan: <?php echo e($post['created_at'] ?: '-'); ?></div>
						<div class="summary"><?php echo e($post['summary'] ?: substr(strip_tags($post['body']), 0, 180)); ?></div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</body>
</html>
