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
	$text = str_replace(["\r\n", "\r"], "\n", (string) $text);
	$text = trim($text);
	if ($text === '') return '';

	$escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	$codeBlocks = [];

	$escaped = preg_replace_callback('/```(.*?)```/s', function ($m) use (&$codeBlocks) {
		$idx = count($codeBlocks);
		$codeBlocks[$idx] = '<pre><code>' . $m[1] . '</code></pre>';
		return "%%CODEBLOCK{$idx}%%";
	}, $escaped);

	$escaped = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codeBlocks) {
		$idx = count($codeBlocks);
		$codeBlocks[$idx] = '<code>' . $m[1] . '</code>';
		return "%%CODEBLOCK{$idx}%%";
	}, $escaped);

	$escaped = preg_replace('/^###### (.+)$/m', '<h6>$1</h6>', $escaped);
	$escaped = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $escaped);
	$escaped = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $escaped);
	$escaped = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $escaped);
	$escaped = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $escaped);
	$escaped = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $escaped);

	$escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
	$escaped = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $escaped);

	$escaped = preg_replace_callback('/\[(.+?)\]\((https?:[^)]+)\)/', function ($m) {
		$url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
		return '<a href="' . $url . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
	}, $escaped);

	$escaped = convert_lists($escaped);

	$blocks = preg_split('/\n{2,}/', trim($escaped));
	$blocks = array_map(function ($block) {
		if (preg_match('/^\s*<(ul|ol|pre|h[1-6])/i', $block)) {
			return $block;
		}
		return '<p>' . nl2br($block) . '</p>';
	}, $blocks);

	$html = implode("\n", $blocks);

	foreach ($codeBlocks as $idx => $codeHtml) {
		$html = str_replace("%%CODEBLOCK{$idx}%%", $codeHtml, $html);
	}

	return $html;
}

function apply_embeds($html, $embeds, $enabled)
{
	if (!$enabled || empty($embeds) || !is_array($embeds)) return $html;
	$remaining = $embeds;
	$output = $html;
	foreach ($embeds as $idx => $emb) {
		$token = '[[EMBED' . ($idx + 1) . ']]';
		$pos = stripos($output, $token);
		if ($pos !== false) {
			$output = substr_replace($output, '<div class="embed-box">' . $emb . '</div>', $pos, strlen($token));
			unset($remaining[$idx]);
		}
	}
	if (!empty($remaining)) {
		$output .= "\n" . implode("\n", array_map(fn($emb) => '<div class="embed-box">' . $emb . '</div>', $remaining));
	}
	return $output;
}

$posts = array_map(function ($post) {
	$title = trim((string) ($post['title'] ?? ''));
	$slug = trim((string) ($post['slug'] ?? ''));
	$summary = trim((string) ($post['summary'] ?? ''));
	$body = (string) ($post['body'] ?? '');
	$image = trim((string) ($post['image'] ?? ''));
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
	$summaryText = $summary ?: substr(trim(strip_tags($bodyHtml)), 0, 180);
	return [
		'title' => $title ?: 'Tanpa judul',
		'slug' => $slug,
		'summary' => $summaryText,
		'body' => $body,
		'body_html' => $bodyHtml,
		'image' => $image,
		'embeds' => $embedList,
		'embed_enabled' => $embedEnabled,
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
			--accent-2: #7cc9ff;
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
			padding: 0 0 40px;
		}

		.container {
			max-width: 1120px;
			margin: 0 auto;
			padding: 32px 18px 0;
			width: 100%;
		}

		h1 { margin: 0 0 8px; font-size: 28px; }
		p.lead { margin: 0 0 20px; color: var(--muted); }

			.grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
			gap: 16px;
		}

			.btn {
				display: inline-flex;
				align-items: center;
				gap: 10px;
				padding: 10px 14px;
				border-radius: 12px;
				border: 1px solid var(--stroke);
				text-decoration: none;
				color: var(--text);
				font-weight: 600;
				transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
				backdrop-filter: blur(6px);
			}

			.btn.primary {
				background: linear-gradient(120deg, var(--accent), var(--accent-2));
				color: #061028;
				box-shadow: 0 12px 32px rgba(108, 247, 197, 0.25);
				border: none;
			}

			.btn.ghost { background: rgba(255, 255, 255, 0.07); }
			.btn:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(0,0,0,0.35); }

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
			.card .summary { color: var(--text); font-size: 14px; line-height: 1.5; word-break: break-word; overflow-wrap: anywhere; white-space: normal; }

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
		.article .body { color: var(--text); font-size: 15px; line-height: 1.7; white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
		.article .body * { word-break: break-word; overflow-wrap: anywhere; }
		.article .body pre { background: rgba(255,255,255,0.04); border: 1px solid var(--stroke); padding: 10px; border-radius: 10px; overflow-x: auto; }
		.article .body code { background: rgba(255,255,255,0.04); padding: 2px 4px; border-radius: 6px; border: 1px solid var(--stroke); }
		.article .body p { margin: 0 0 12px; }
		.article .body ul, .article .body ol { margin: 0 0 12px 18px; }
		.embed-box { margin: 14px 0 0; padding: 14px; border: 1px solid var(--stroke); border-radius: 12px; background: rgba(255,255,255,0.03); }
		.embed-box iframe { width: 100%; }

		.loading-overlay {
			position: fixed;
			inset: 0;
			display: grid;
			place-items: center;
			background: radial-gradient(circle at 20% 20%, rgba(197, 255, 106, 0.18), transparent 32%),
				radial-gradient(circle at 80% 30%, rgba(255, 255, 255, 0.15), transparent 36%),
				linear-gradient(135deg, rgba(0, 0, 0, 0.75), rgba(0, 0, 0, 0.9));
			z-index: 9999;
			transition: opacity 0.5s ease, visibility 0.5s ease;
		}

		.loading-overlay.fade-out {
			opacity: 0;
			visibility: hidden;
		}

		.loading-stack {
			position: relative;
			width: min(380px, 70vw);
		}

		.loading-gif {
			display: block;
			width: 100%;
			height: auto;
			border-radius: 16px;
			background: #111;
			filter: drop-shadow(0 10px 28px rgba(0, 0, 0, 0.4));
			object-fit: cover;
		}

		.loading-logo {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 42%;
			max-width: 150px;
			filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.5));
		}

		.loading-card {
			margin-top: 12px;
			padding: 14px 16px 16px;
			border-radius: 14px;
			background: rgba(0, 0, 0, 0.55);
			border: 1px solid rgba(255, 255, 255, 0.08);
			backdrop-filter: blur(12px);
			color: #f6f8fb;
			text-align: center;
			font-family: 'Manrope', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
		}

		.loading-card .progress-track {
			margin-top: 10px;
			width: 100%;
			height: 10px;
			border-radius: 999px;
			background: rgba(255, 255, 255, 0.12);
			overflow: hidden;
		}

		.loading-card .progress-bar {
			position: relative;
			display: block;
			height: 100%;
			width: 38%;
			background: linear-gradient(90deg, rgba(197, 255, 106, 0.3), #c5ff6a);
			border-radius: inherit;
			animation: loading-pulse 1.6s ease-in-out infinite;
		}

		@keyframes loading-pulse {
			0% { left: -45%; width: 32%; }
			50% { left: 30%; width: 45%; }
			100% { left: 100%; width: 32%; }
		}
	</style>
</head>
<body>
	<div class="loading-overlay" id="page-loading" aria-live="polite">
		<div class="loading-stack">
			<img class="loading-gif" src="palestine.gif" alt="Animasi pemuatan">
			<img class="loading-logo" src="logo-ukmi.png" alt="Logo UKMI">
		</div>
		<div class="loading-card">
			<div>Memuat konten, mohon tunggu...</div>
			<div class="progress-track" aria-hidden="true"><span class="progress-bar"></span></div>
		</div>
	</div>
		<?php include __DIR__ . '/header.php'; ?>
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
				<div class="body"><?php echo apply_embeds($posts[0]['body_html'], $posts[0]['embeds'] ?? [], !empty($posts[0]['embed_enabled'])); ?></div>
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
						<div class="summary"><?php echo e($post['summary']); ?></div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<script>
		const loadingEl = document.getElementById('page-loading');
		window.addEventListener('load', () => {
			if (!loadingEl) return;
			loadingEl.classList.add('fade-out');
			setTimeout(() => loadingEl.remove(), 600);
		});
	</script>
</body>
</html>
