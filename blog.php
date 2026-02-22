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

$baseHost = $_SERVER['HTTP_HOST'] ?? 'ukmipolmed.xo.je';
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $baseHost;

function save_data_uri_image($img, $baseUrl)
{
	if (stripos($img, 'data:') !== 0) return null;
	if (!preg_match('#^data:(image/(?:png|jpe?g|gif|webp))(;charset=[^;,]+)?;base64,(.*)$#i', $img, $m)) return null;
	$mime = strtolower($m[1]);
	$b64 = $m[3] ?? '';
	$data = base64_decode($b64, true);
	if ($data === false) return null;
	$maxSize = 2 * 1024 * 1024;
	if (strlen($data) > $maxSize) return null;
	$ext = $mime === 'image/jpeg' ? 'jpg' : (strpos($mime, 'png') !== false ? 'png' : (strpos($mime, 'gif') !== false ? 'gif' : 'webp'));
	$hash = sha1($data);
	$uploadDir = __DIR__ . '/.uploads';
	if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
	$fileName = $hash . '.' . $ext;
	$filePath = $uploadDir . '/' . $fileName;

	// If file exists and already large enough, return immediately
	if (file_exists($filePath)) {
		if (function_exists('getimagesize')) {
			$info = @getimagesize($filePath);
			if ($info && isset($info[0]) && $info[0] >= 400) {
				return $baseUrl . '/.uploads/' . $fileName;
			}
		}
		// otherwise we'll attempt to reprocess/overwrite it below
	}

	$written = false;
	if (function_exists('imagecreatefromstring')) {
		$im = @imagecreatefromstring($data);
	} else {
		$im = false;
	}

		// If GD created an image resource, attempt resize/save using GD
		if ($im !== false) {
			$w = imagesx($im);
			$h = imagesy($im);
			if ($w < 400) {
				$nw = 400;
				$nh = (int) round($h * ($nw / $w));
				$dst = imagecreatetruecolor($nw, $nh);
				if (in_array($ext, ['png', 'webp'])) {
					imagealphablending($dst, false);
					imagesavealpha($dst, true);
					$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
					imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
				} elseif ($ext === 'gif') {
					$trans_index = imagecolortransparent($im);
					if ($trans_index >= 0) {
						$trans_col = imagecolorsforindex($im, $trans_index);
						$ti = imagecolorallocate($dst, $trans_col['red'], $trans_col['green'], $trans_col['blue']);
						imagefill($dst, 0, 0, $ti);
						imagecolortransparent($dst, $ti);
					}
				}
				imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
				imagedestroy($im);
				$im = $dst;
			}

			// If webp not supported, we'll save as PNG instead
			$saveExt = $ext;
			if ($saveExt === 'webp' && !function_exists('imagewebp')) {
				$saveExt = 'png';
				$filePath = $uploadDir . '/' . $hash . '.png';
				$fileName = $hash . '.png';
			}

			if ($im !== false) {
				switch ($saveExt) {
					case 'jpg': @imagejpeg($im, $filePath, 90); $written = file_exists($filePath); break;
					case 'png': @imagepng($im, $filePath); $written = file_exists($filePath); break;
					case 'gif': @imagegif($im, $filePath); $written = file_exists($filePath); break;
					case 'webp': @imagewebp($im, $filePath, 80); $written = file_exists($filePath); break;
				}
				imagedestroy($im);
			}
		}

		// Fallback: write raw data if GD processing failed
		if (!$written) {
			@file_put_contents($filePath, $data);
		}


	if (file_exists($filePath)) {
		return $baseUrl . '/.uploads/' . $fileName;
	}
	return null;

}

/**
 * Ensure an existing image file is at least $minWidth wide by upscaling with GD.
 * Returns true if the file exists after processing (and was written), false otherwise.
 */
function ensure_image_min_width($filePath, $minWidth = 400)
{
	if (!file_exists($filePath)) return false;
	if (!function_exists('getimagesize')) return false;
	$info = @getimagesize($filePath);
	if (!$info || !isset($info[0])) return false;
	$w = $info[0];
	$h = $info[1] ?? 0;
	if ($w >= $minWidth) return true;
	if (!function_exists('imagecreatefromstring')) return false;

	$data = @file_get_contents($filePath);
	if ($data === false) return false;
	$im = @imagecreatefromstring($data);
	if ($im === false) return false;

	$nw = $minWidth;
	$nh = $h > 0 ? (int) round($h * ($nw / $w)) : $nw;
	$dst = imagecreatetruecolor($nw, $nh);

	$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
	if (in_array($ext, ['png', 'webp'])) {
		imagealphablending($dst, false);
		imagesavealpha($dst, true);
		$transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
		imagefilledrectangle($dst, 0, 0, $nw, $nh, $transparent);
	} elseif ($ext === 'gif') {
		$trans_index = imagecolortransparent($im);
		if ($trans_index >= 0) {
			$trans_col = imagecolorsforindex($im, $trans_index);
			$ti = imagecolorallocate($dst, $trans_col['red'], $trans_col['green'], $trans_col['blue']);
			imagefill($dst, 0, 0, $ti);
			imagecolortransparent($dst, $ti);
		}
	}

	imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
	imagedestroy($im);

	$written = false;
	switch ($ext) {
		case 'jpg': case 'jpeg': @imagejpeg($dst, $filePath, 90); $written = file_exists($filePath); break;
		case 'png': @imagepng($dst, $filePath); $written = file_exists($filePath); break;
		case 'gif': @imagegif($dst, $filePath); $written = file_exists($filePath); break;
		case 'webp':
			if (function_exists('imagewebp')) {
				@imagewebp($dst, $filePath, 80);
			} else {
				@imagepng($dst, $filePath);
			}
			$written = file_exists($filePath);
			break;
		default:
			@imagepng($dst, $filePath);
			$written = file_exists($filePath);
	}
	imagedestroy($dst);
	return $written;
}

/**
 * Scan posts for references to files under .uploads and remove unreferenced files
 * older than $maxAge seconds. This helps free disk for unused uploaded images.
 */
function cleanup_uploads(array $posts, $maxAge = 86400)
{
	$uploadDir = __DIR__ . '/.uploads';
	if (!is_dir($uploadDir)) return false;

	$used = [];
	foreach ($posts as $post) {
		// image field
		$img = trim((string) ($post['image'] ?? ''));
		if ($img !== '') {
			$p = parse_url($img, PHP_URL_PATH);
			if ($p !== null && stripos($p, '/.uploads/') !== false) {
				$used[] = basename($p);
			}
		}
		// body_html and embeds may contain .uploads links
		$html = (string) ($post['body_html'] ?? '');
		if ($html !== '') {
			if (preg_match_all('#/\.uploads/([^"\'\s>]+)#i', $html, $m)) {
				foreach ($m[1] as $b) $used[] = $b;
			}
		}
		if (!empty($post['embeds']) && is_array($post['embeds'])) {
			foreach ($post['embeds'] as $emb) {
				if (preg_match_all('#/\.uploads/([^"\'\s>]+)#i', (string) $emb, $m2)) {
					foreach ($m2[1] as $b) $used[] = $b;
				}
			}
		}
	}

	$used = array_filter(array_unique($used));
	$now = time();
	$deleted = 0;
	$reclaimed = 0;

	$it = @scandir($uploadDir);
	if (!is_array($it)) return false;
	foreach ($it as $entry) {
		if ($entry === '.' || $entry === '..') continue;
		$path = $uploadDir . '/' . $entry;
		if (!is_file($path)) continue;
		// if used, keep
		if (in_array($entry, $used, true)) continue;
		$mtime = filemtime($path) ?: 0;
		if ($mtime > 0 && ($now - $mtime) < $maxAge) continue; // too new
		$size = filesize($path) ?: 0;
		if (@unlink($path)) {
			$deleted++;
			$reclaimed += $size;
		}
	}

	return ['deleted' => $deleted, 'reclaimed_bytes' => $reclaimed];
}

$posts = array_map(function ($post) use ($baseUrl) {
	$title = trim((string) ($post['title'] ?? ''));
	$slug = trim((string) ($post['slug'] ?? ''));
	$summary = trim((string) ($post['summary'] ?? ''));
	$body = (string) ($post['body'] ?? '');
	$image = trim((string) ($post['image'] ?? ''));
	if ($image !== '') {
		if (stripos($image, 'data:') === 0) {
			$saved = save_data_uri_image($image, $baseUrl);
			if ($saved) $image = $saved;
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

// ---- Comments: allow posting comments to data.json (best-effort)
// Global comments storage limit (5 MB)
const COMMENTS_MAX_BYTES = 5 * 1024 * 1024;

function get_comments_storage_info(): array
{
	$cfgPath = __DIR__ . '/data.json';
	$used = 0;
	if (is_readable($cfgPath)) {
		$data = json_decode((string) @file_get_contents($cfgPath), true);
		if (is_array($data) && !empty($data['posts']) && is_array($data['posts'])) {
			$all = [];
			foreach ($data['posts'] as $p) {
				$all[] = $p['comments'] ?? [];
			}
			$enc = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($enc !== false) $used = strlen($enc);
		}
	}
	$limit = COMMENTS_MAX_BYTES;
	return [
		'used_bytes' => $used,
		'limit_bytes' => $limit,
		'used_mb' => round($used / (1024*1024), 3),
		'remaining_bytes' => max(0, $limit - $used),
		'remaining_mb' => round(max(0, $limit - $used) / (1024*1024), 3),
		'percent_used' => $limit > 0 ? round(($used / $limit) * 100, 2) : 0,
	];
}
function save_comment(string $slug, string $name, string $body, ?string $parentId = null, ?string $clientToken = null): array
{
	$cfgPath = __DIR__ . '/data.json';
	$name = trim((string) $name);
	$body = trim((string) $body);
	if ($slug === '' || $name === '' || $body === '') {
		return ['ok' => false, 'error' => 'Nama, komentar, dan slug diperlukan.'];
	}

	$data = [];
	if (is_readable($cfgPath)) {
		$tmp = json_decode((string) @file_get_contents($cfgPath), true);
		if (is_array($tmp)) $data = $tmp;
	}
	if (!is_array($data)) $data = [];
	if (empty($data['posts']) || !is_array($data['posts'])) $data['posts'] = [];

	$found = false;
	foreach ($data['posts'] as &$p) {
		if (isset($p['slug']) && (string) $p['slug'] === $slug) {
			if (empty($p['comments']) || !is_array($p['comments'])) $p['comments'] = [];
			// generate id for the new comment
			$id = uniqid('', true);
			$new = [
				'id' => $id,
				'name' => $name,
				'body' => $body,
				'created_at' => date('c'),
				'replies' => [],
				'client_token' => $clientToken ?? null,
			];

			// enforce global comments storage limit
			$cfgAll = $data; // current full data
			// compute current comments usage
			$currentComments = [];
			if (!empty($cfgAll['posts']) && is_array($cfgAll['posts'])) {
				foreach ($cfgAll['posts'] as $pp) {
					$currentComments[] = $pp['comments'] ?? [];
				}
			}
			$curEnc = json_encode($currentComments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$curBytes = $curEnc === false ? 0 : strlen($curEnc);
			$newBytes = strlen(json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
			if ($curBytes + $newBytes > COMMENTS_MAX_BYTES) {
				return ['ok' => false, 'error' => 'Batas penyimpanan komentar tercapai. Hapus beberapa komentar atau hubungi admin.'];
			}

			if ($parentId !== null && $parentId !== '') {
				// try to find parent comment by id and append to its replies
				$appended = false;
				foreach ($p['comments'] as &$c) {
					if (isset($c['id']) && (string) $c['id'] === $parentId) {
						if (empty($c['replies']) || !is_array($c['replies'])) $c['replies'] = [];
						$c['replies'][] = $new;
						$appended = true;
						break;
					}
					// also check one level deep replies
					if (!empty($c['replies']) && is_array($c['replies'])) {
						foreach ($c['replies'] as &$r) {
							if (isset($r['id']) && (string) $r['id'] === $parentId) {
								if (empty($r['replies']) || !is_array($r['replies'])) $r['replies'] = [];
								$r['replies'][] = $new;
								$appended = true;
								break 2;
							}
						}
						unset($r);
					}
				}
				unset($c);
				if (!$appended) {
					return ['ok' => false, 'error' => 'Parent comment tidak ditemukan'];
				}
			} else {
				// top-level comment
				$p['comments'][] = $new;
			}

			$found = true;
			break;
		}
	}
	unset($p);
	if (!$found) return ['ok' => false, 'error' => 'Postingan tidak ditemukan'];

	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($payload === false) return ['ok' => false, 'error' => 'Gagal serialisasi data'];

	$fh = @fopen($cfgPath, 'c+');
	if (!$fh) return ['ok' => false, 'error' => 'Gagal membuka data.json untuk ditulis'];
	try {
		if (!flock($fh, LOCK_EX)) return ['ok' => false, 'error' => 'Gagal mengunci file'];
		ftruncate($fh, 0);
		fwrite($fh, $payload);
		fflush($fh);
		flock($fh, LOCK_UN);
	} finally {
		fclose($fh);
	}
	return ['ok' => true, 'id' => $id ?? null];
}

/**
 * Ensure existing comments have persistent IDs so replies can target them.
 * Returns true if file was modified.
 */
function normalize_comment_ids(string $slug): bool
{
	$cfgPath = __DIR__ . '/data.json';
	if (!is_readable($cfgPath)) return false;
	$data = json_decode((string) @file_get_contents($cfgPath), true);
	if (!is_array($data) || empty($data['posts']) || !is_array($data['posts'])) return false;

	$modified = false;
	foreach ($data['posts'] as &$p) {
		if (!isset($p['slug']) || (string) $p['slug'] !== $slug) continue;
		if (empty($p['comments']) || !is_array($p['comments'])) break;

		$walk = function (&$list) use (&$walk, &$modified) {
			foreach ($list as &$c) {
				if (empty($c['id'])) {
					$c['id'] = uniqid('', true);
					$modified = true;
				}
				if (empty($c['replies']) || !is_array($c['replies'])) {
					$c['replies'] = [];
				} else {
					$walk($c['replies']);
				}
			}
			unset($c);
		};

		$walk($p['comments']);
		break;
	}
	unset($p);

	if ($modified) {
		$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($payload === false) return false;
		$fh = @fopen($cfgPath, 'c+');
		if (!$fh) return false;
		try {
			if (!flock($fh, LOCK_EX)) return false;
			ftruncate($fh, 0);
			fwrite($fh, $payload);
			fflush($fh);
			flock($fh, LOCK_UN);
		} finally {
			fclose($fh);
		}
	}

	return $modified;
}

/**
 * Remove a comment (and its subtree) by id for a given post slug.
 * Returns ['ok'=>true] on success or ['ok'=>false,'error'=>msg].
 */
function delete_comment(string $slug, string $commentId): array
{
	$cfgPath = __DIR__ . '/data.json';
	if (!is_readable($cfgPath)) return ['ok' => false, 'error' => 'data.json tidak dapat dibaca'];
	$data = json_decode((string) @file_get_contents($cfgPath), true);
	if (!is_array($data) || empty($data['posts']) || !is_array($data['posts'])) return ['ok' => false, 'error' => 'Data posting tidak ditemukan'];

	$found = false;
	foreach ($data['posts'] as &$p) {
		if (!isset($p['slug']) || (string) $p['slug'] !== $slug) continue;
		if (empty($p['comments']) || !is_array($p['comments'])) break;

		$remove = function (&$list, $id) use (&$remove) {
			foreach ($list as $idx => &$c) {
				if (isset($c['id']) && (string) $c['id'] === $id) {
					array_splice($list, $idx, 1);
					return true;
				}
				if (!empty($c['replies']) && is_array($c['replies'])) {
					if ($remove($c['replies'], $id)) {
						return true;
					}
				}
			}
			unset($c);
			return false;
		};

		if ($remove($p['comments'], $commentId)) {
			$found = true;
		}
		break;
	}
	unset($p);

	if (!$found) return ['ok' => false, 'error' => 'Komentar tidak ditemukan'];

	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($payload === false) return ['ok' => false, 'error' => 'Gagal serialisasi data'];

	$fh = @fopen($cfgPath, 'c+');
	if (!$fh) return ['ok' => false, 'error' => 'Gagal membuka data.json untuk ditulis'];
	try {
		if (!flock($fh, LOCK_EX)) return ['ok' => false, 'error' => 'Gagal mengunci file'];
		ftruncate($fh, 0);
		fwrite($fh, $payload);
		fflush($fh);
		flock($fh, LOCK_UN);
	} finally {
		fclose($fh);
	}

	return ['ok' => true];
}

// Process comment submission (POST)
$comment_error = null;
$comment_success = null;

// Handle delete comment action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {
	$dslug = trim((string) ($_POST['comment_post_slug'] ?? ''));
	$cid = trim((string) ($_POST['comment_id'] ?? ''));
	if ($dslug !== '' && $cid !== '') {
			// only allow deletion if this comment id is in user's pending_comments cookie (session-scoped)
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
						setcookie('pending_comments', implode(',', $cur), 0, '/');
					}
			$loc = $_SERVER['REQUEST_URI'] ?: ('/blog.php?slug=' . urlencode($dslug));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_post_slug'])) {
	$cslug = trim((string) ($_POST['comment_post_slug'] ?? ''));
	$cname = trim((string) ($_POST['comment_name'] ?? ''));
	$cbody = trim((string) ($_POST['comment_body'] ?? ''));
	$cparent = trim((string) ($_POST['comment_parent_id'] ?? ''));
		$client_token = trim((string) ($_POST['client_token'] ?? ''));
	// Ensure existing comments have IDs so replies can match parents
	if ($cslug !== '') {
		@normalize_comment_ids($cslug);
	}
		$res = save_comment($cslug, $cname, $cbody, $cparent !== '' ? $cparent : null, $client_token ?? null);
	if ($res['ok']) {
		// Redirect to avoid duplicate submits
			// store newly created comment id in a session cookie so the creator can delete it
			$newId = $res['id'] ?? '';
			if ($newId !== '') {
				$cur = [];
				if (!empty($_COOKIE['pending_comments'])) {
					$cur = array_filter(array_map('trim', explode(',', (string) $_COOKIE['pending_comments'])));
				}
				if (!in_array($newId, $cur, true)) $cur[] = $newId;
				setcookie('pending_comments', implode(',', $cur), 0, '/');
			}

			$loc = $_SERVER['REQUEST_URI'] ?: ('/blog.php?slug=' . urlencode($cslug));
			header('Location: ' . $loc);
			exit;
	} else {
		$comment_error = $res['error'] ?? 'Gagal menyimpan komentar';
	}
}

$baseHost = $_SERVER['HTTP_HOST'] ?? 'ukmipolmed.xo.je';
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') ? 'https' : 'http';
$baseUrl = $scheme . '://' . $baseHost;

$metaTitle = 'Blog UKMI Polmed – Catatan Kegiatan & Tulisan Terbaru';
$metaDesc = 'Baca catatan kegiatan, berita rilis, dan tulisan inspiratif dari UKMI Politeknik Negeri Medan. Update terbaru seputar dakwah kampus, mentoring, dan event.';
$metaImage = $baseUrl . '/logo-ukmi.png';
$metaUrl = $baseUrl . '/blog.php' . ($filterSlug !== '' ? ('?slug=' . urlencode($filterSlug)) : '');
$metaType = 'website';

// If we're showing the list (not a single post), prefer first post image for og:image
if ($filterSlug === '' && !empty($posts) && is_array($posts[0])) {
	$firstImg = trim((string) ($posts[0]['image'] ?? ''));
	if ($firstImg !== '') {
		if (stripos($firstImg, 'data:') === 0) {
			$saved = save_data_uri_image($firstImg, $baseUrl);
			if ($saved) $metaImage = $saved;
		} else {
			$sch = parse_url($firstImg, PHP_URL_SCHEME);
			if ($sch && in_array(strtolower($sch), ['http','https']) && filter_var($firstImg, FILTER_VALIDATE_URL)) {
				$metaImage = $firstImg;
			} elseif ($firstImg[0] === '/') {
				$metaImage = $baseUrl . $firstImg;
			}
		}
	}
}

// Optional Facebook App ID (can be set in data.json or default.json as "fb_app_id")
$fbAppId = null;
if (is_array($config) && !empty($config['fb_app_id'])) {
	$fbAppId = trim((string) $config['fb_app_id']);
} elseif (!empty($defaults['fb_app_id'])) {
	$fbAppId = trim((string) $defaults['fb_app_id']);
}

if ($filterSlug !== '' && count($posts) === 1) {
    $postMeta = $posts[0];
	$metaTitle = trim((string) ($postMeta['title'] ?? 'Blog UKMI Polmed'));
	$metaDesc = trim((string) ($postMeta['summary'] ?? $metaDesc));
	if (!empty($postMeta['image']) && is_string($postMeta['image'])) {
		$img = trim($postMeta['image']);
		if (stripos($img, 'data:') === 0) {
			$saved = save_data_uri_image($img, $baseUrl);
			if ($saved) {
				$metaImage = $saved;
			}
		} else {
			$scheme = parse_url($img, PHP_URL_SCHEME);
			if ($scheme && in_array(strtolower($scheme), ['http', 'https']) && filter_var($img, FILTER_VALIDATE_URL)) {
				$metaImage = $img;
			}
		}
	}
	$metaType = 'article';
}

// Attempt to cleanup unreferenced uploads older than 1 day (best-effort, silent)
@ignore_user_abort(true);
try {
	@set_time_limit(5);
	@cleanup_uploads($posts, 86400);
} catch (Exception $_) {
	// ignore
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
	<link rel="icon" type="image/png" sizes="192x192" href="logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="32x32" href="logo-ukmi.png">
	<link rel="icon" type="image/png" sizes="16x16" href="logo-ukmi.png">
	<link rel="apple-touch-icon" sizes="180x180" href="logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<meta name="description" content="<?php echo e($metaDesc); ?>">
	<meta name="keywords" content="blog UKMI Polmed, kegiatan UKMI Polmed, berita dakwah kampus Medan, mentoring Islam Polmed">
	<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
	<link rel="canonical" href="<?php echo e($metaUrl); ?>">
	<meta property="og:locale" content="id_ID">
	<meta property="og:site_name" content="UKMI Polmed">
	<?php if (!empty($fbAppId)): ?>
	<meta property="fb:app_id" content="<?php echo e($fbAppId); ?>">
	<?php endif; ?>
	<meta property="og:title" content="<?php echo e($metaTitle); ?>">
	<meta property="og:description" content="<?php echo e($metaDesc); ?>">
	<meta property="og:type" content="<?php echo e($metaType); ?>">
	<meta property="og:url" content="<?php echo e($metaUrl); ?>">
	<meta property="og:image" content="<?php echo e($metaImage); ?>">
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
		'headline' => $posts[0]['title'] ?? 'Blog UKMI Polmed',
		'description' => $posts[0]['summary'] ?? $metaDesc,
		'image' => $metaImage,
		'datePublished' => $posts[0]['created_at'] ?? '',
		'dateModified' => $posts[0]['updated_at'] ?? $posts[0]['created_at'] ?? '',
		'author' => ['@type' => 'Organization', 'name' => 'UKMI Polmed', 'url' => 'https://ukmipolmed.xo.je/'],
		'publisher' => ['@type' => 'Organization', 'name' => 'UKMI Polmed', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://ukmipolmed.xo.je/logo-ukmi.png']],
		'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $metaUrl],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
	</script>
	<?php else: ?>
	<script type="application/ld+json">
	<?php echo json_encode([
		'@context' => 'https://schema.org',
		'@type' => 'Blog',
		'name' => 'Blog UKMI Polmed',
		'description' => 'Catatan kegiatan, berita rilis, dan tulisan terbaru UKMI Politeknik Negeri Medan.',
		'url' => $baseUrl . '/blog.php',
		'publisher' => ['@type' => 'Organization', 'name' => 'UKMI Polmed', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://ukmipolmed.xo.je/logo-ukmi.png']],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>
	</script>
	<?php endif; ?>
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
				padding: 12px 16px;
				border-radius: 12px;
				border: 1px solid var(--stroke);
				text-decoration: none;
				color: var(--text);
				font-weight: 600;
				font-size: 15px;
				min-height: 48px;
				transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
				backdrop-filter: blur(6px);
				-webkit-tap-highlight-color: transparent;
			}

			.btn.primary {
				background: linear-gradient(120deg, var(--accent), var(--accent-2));
				color: #061028;
				box-shadow: 0 12px 32px rgba(108, 247, 197, 0.25);
				border: none;
			}

			/* Comment UI tweaks */
			.comment-list, .comment-replies { list-style: none; padding: 0; margin: 0 0 14px; }
			.comment-list li { text-align: left; margin-bottom:10px; }
			.comment-list .reply-form { margin-top:8px; border-left:2px solid rgba(255,255,255,0.03); padding-left:10px; }
			.comment-list .reply-form .btn { min-height: auto; padding: 8px 12px; font-size: 13px; border-radius: 8px; }
			.comment-list .reply-btn { font-size:13px; color:var(--accent); }
			.delete-comment-form button { display: none; }
			/* Make delete button look like the reply link when visible */
			.delete-btn {
				background: none;
				border: none;
				color: var(--accent);
				font-size: 13px;
				padding: 0 6px;
				cursor: pointer;
			}
			.delete-btn:hover { text-decoration: underline; }

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
			background: #11111100;
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
		<h1>Blog UKMI Polmed – Catatan Kegiatan & Tulisan Terbaru</h1>
		<p class="lead">Baca berita, catatan kegiatan, dan tulisan inspiratif dari UKMI Politeknik Negeri Medan.</p>
		<?php if (empty($posts)): ?>
			<p class="lead">Belum ada postingan. Salah URL. Atau mungkin telah dihapus.</p>
		<?php elseif ($filterSlug !== '' && count($posts) === 1): ?>
			<div class="article">
				<h2><?php echo e($posts[0]['title']); ?></h2>
					<div class="meta">Dipublikasikan: <?php echo e($posts[0]['created_at'] ?: '-'); ?><?php if (!empty($posts[0]['updated_at'])): ?> · Diubah: <?php echo e($posts[0]['updated_at']); ?><?php endif; ?> · Views: <span id="blog-views-inline">-</span></div>
				<?php if (!empty($posts[0]['image'])): ?>
					<div style="margin:10px 0 14px;"><img src="<?php echo e($posts[0]['image']); ?>" alt="Gambar <?php echo e($posts[0]['title']); ?>" style="width:100%; max-height:420px; object-fit:cover; border-radius:12px; border:1px solid var(--stroke);"></div>
				<?php endif; ?>
				<div class="body"><?php echo apply_embeds($posts[0]['body_html'], $posts[0]['embeds'] ?? [], !empty($posts[0]['embed_enabled'])); ?></div>

			</div>

			<?php
			// Separate comments UI into its own card below the article
			$postSlug = $posts[0]['slug'] ?? '';
			$comments = [];
			if (is_readable($configPath)) {
				$cfg = json_decode((string) @file_get_contents($configPath), true);
				if (is_array($cfg) && !empty($cfg['posts']) && is_array($cfg['posts'])) {
					foreach ($cfg['posts'] as $pp) {
						if (isset($pp['slug']) && (string) $pp['slug'] === $postSlug) {
							if (!empty($pp['comments']) && is_array($pp['comments'])) $comments = $pp['comments'];
							break;
						}
					}
				}
			}
			?>
			<div class="card" style="margin-top:18px;">
				<h3>Komentar (<?php echo count($comments); ?>)</h3>
				<?php if (!empty($comment_error)): ?><div style="color:#f87171;"><?php echo e($comment_error); ?></div><?php endif; ?>
				<?php if (!empty($comments)): ?>
					<?php
					// show remaining comment storage in this card
					$cs = get_comments_storage_info();
					?>
					<div style="margin-bottom:8px;color:var(--muted);font-size:13px;">Sisa penyimpanan komentar: <?php echo htmlspecialchars($cs['remaining_mb'] . ' MB', ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($cs['percent_used'] . ' % terpakai', ENT_QUOTES, 'UTF-8'); ?>)</div>
					<?php
					function render_comment_tree($c, $postSlug) {
						?>
						<li id="comment-<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="margin-bottom:10px;padding:10px;border-radius:8px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.03);">
							<strong><?php echo htmlspecialchars($c['name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<div style="font-size:13px;color:var(--muted);margin-bottom:6px;">
								<?php echo htmlspecialchars($c['created_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
								<span style="margin-left:10px;">
									<a href="#" class="reply-btn" data-parent-id="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="font-size:13px;color:var(--accent);">Balas</a>
								</span>
								<span style="margin-left:8px;">
									<form method="post" class="delete-comment-form" style="display:inline;">
										<input type="hidden" name="action" value="delete_comment">
										<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($postSlug, ENT_QUOTES, 'UTF-8'); ?>">
										<input type="hidden" name="comment_id" value="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
										<button type="submit" class="delete-btn" onclick="return confirm('Hapus komentar ini?')"><u>Hapus</u></button>
									</form>
								</span>
							</div>
							<div style="white-space:pre-line;margin-bottom:8px;">
								<?php echo htmlspecialchars(trim((string) ($c['body'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
							</div>
							<div class="reply-form" data-parent="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="display:none;">
								<form method="post">
									<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($postSlug, ENT_QUOTES, 'UTF-8'); ?>">
									<input type="hidden" name="comment_parent_id" value="<?php echo htmlspecialchars($c['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
									<div style="margin-bottom:6px;"><input type="text" name="comment_name" required placeholder="Nama" style="width:100%;padding:6px;border-radius:6px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></div>
									<div style="margin-bottom:6px;"><textarea name="comment_body" rows="3" required placeholder="Balasan Anda" style="width:100%;padding:6px;border-radius:6px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></textarea></div>
									<div><button type="submit" class="btn primary">Kirim Balasan</button></div>
								</form>
							</div>
							<?php if (!empty($c['replies']) && is_array($c['replies'])): ?>
								<ul class="comment-replies" style="padding-left:12px;margin-top:10px;">
									<?php foreach ($c['replies'] as $r): ?>
										<?php render_comment_tree($r, $postSlug); ?>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</li>
						<?php
					}
					?>
					<ul class="comment-list" style="padding:0;margin:8px 0 14px;">
						<?php foreach ($comments as $c): ?>
							<?php render_comment_tree($c, $postSlug); ?>
						<?php endforeach; ?>
					</ul>
				<?php else: ?>
					<p style="color:var(--muted);">Belum ada komentar. Jadilah yang pertama!</p>
				<?php endif; ?>

				<form method="post" style="margin-top:12px;">
					<input type="hidden" name="comment_post_slug" value="<?php echo e($postSlug); ?>">
					<div style="margin-bottom:8px;"><label>Nama</label><br><input type="text" name="comment_name" required style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></div>
					<div style="margin-bottom:8px;"><label>Komentar</label><br><textarea name="comment_body" rows="4" required style="width:100%;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);"></textarea></div>
					<div><button type="submit" class="btn primary">Kirim Komentar</button></div>
				</form>
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
		const blogSlug = <?php echo json_encode($filterSlug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

		window.addEventListener('load', () => {
			if (loadingEl) {
				loadingEl.classList.add('fade-out');
				setTimeout(() => loadingEl.remove(), 600);
			}
			trackBlogView();
			loadBlogViews();
		});

		// Reply form toggles
		(function(){
			document.addEventListener('click', function(e){
				const btn = e.target.closest && e.target.closest('.reply-btn');
				if (!btn) return;
				e.preventDefault();
				const pid = btn.getAttribute('data-parent-id');
				if (!pid) return;
				const form = document.querySelector('.reply-form[data-parent="' + pid + '"]');
				if (!form) return;
				form.style.display = form.style.display === 'none' ? 'block' : 'none';
			});
		})();

		// Show delete buttons only for pending comment IDs stored in session cookie
		(function(){
			function parsePending() {
				const m = document.cookie.match(/(?:^|; )pending_comments=([^;]+)/);
				if (!m) return [];
				try { return decodeURIComponent(m[1]).split(',').map(s => s.trim()).filter(Boolean); } catch (e) { return []; }
			}
			const pend = parsePending();
			if (pend.length === 0) return;
			document.querySelectorAll('form.delete-comment-form').forEach(f => {
				const idInput = f.querySelector('input[name="comment_id"]');
				if (!idInput) return;
				const id = (idInput.value || '').trim();
				if (id && pend.indexOf(id) !== -1) {
					const btn = f.querySelector('button');
					if (btn) btn.style.display = 'inline-flex';
				}
			});
		})();

		function sendInsight(name) {
			if (!name) return;
			fetch('status.php?event=' + encodeURIComponent(name), {
				method: 'POST',
				keepalive: true,
			}).catch(() => {});
		}

		function trackBlogView() {
			if (!blogSlug) return;
			const slugEvent = String(blogSlug).replace(/[^a-zA-Z0-9_-]/g, '');
			if (slugEvent) {
				sendInsight('blog_view_' + slugEvent);
			}
		}

		async function loadBlogViews() {
			const inlineEl = document.getElementById('blog-views-inline');
			if (!inlineEl) return;
			try {
				const res = await fetch('status.php?view=json');
				const data = await res.json();
				const bySlug = data?.org_specific?.blog_views_by_slug || {};
				const views = blogSlug && bySlug[blogSlug];
				if (typeof views === 'number') {
					inlineEl.textContent = views.toLocaleString('id-ID');
				}
			} catch (e) {
				console.warn('Load blog views failed', e);
			}
		}
	</script>
</body>
</html>
