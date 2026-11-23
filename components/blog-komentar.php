<?php
require_once __DIR__ . '/../store.php';
require_once __DIR__ . '/../s3_gc_helper.php';

// ---- Comments: allow posting comments to data.json (best-effort)
// Batas komentar mengikuti sisa kapasitas S3 (tanpa plafon statis).
// Keep delete permission cookie alive for 30 days (persists across browser restarts)
const PENDING_COMMENTS_TTL = 30 * 24 * 60 * 60;

// Status moderasi: approved (tampil) atau suspended (disembunyikan admin).
// Komentar lama tanpa status, serta status lama lainnya, tetap tampil.
function comment_status($comment): string
{
	if (!is_array($comment)) return 'approved';
	$status = strtolower(trim((string) ($comment['status'] ?? 'approved')));
	return in_array($status, ['suspended', 'hidden'], true) ? 'suspended' : 'approved';
}

function comment_is_approved($comment): bool
{
	return comment_status($comment) === 'approved';
}

function comment_is_edited_by_admin($comment): bool
{
	return is_array($comment) && !empty($comment['edited_by_admin']);
}

// Hanya tampilkan komentar yang tidak disuspend; balasan dari komentar yang
// disuspend juga ikut disembunyikan agar percakapan tidak terpotong aneh.
function comment_filter_visible(array $comments): array
{
	$visible = [];
	foreach ($comments as $comment) {
		if (!is_array($comment) || !comment_is_approved($comment)) continue;
		if (!empty($comment['replies']) && is_array($comment['replies'])) {
			$comment['replies'] = comment_filter_visible($comment['replies']);
		}
		$visible[] = $comment;
	}
	return $visible;
}

// Pastikan semua komentar (termasuk balasan) punya ID unik. Dipakai untuk
// komentar lama yang dibuat sebelum fitur ID ada, agar bisa dimoderasi.
function comment_assign_missing_ids(array &$comments): bool
{
	$changed = false;
	$walk = function (array &$list) use (&$walk, &$changed) {
		foreach ($list as &$c) {
			if (!is_array($c)) continue;
			$id = (isset($c['id']) && is_scalar($c['id'])) ? trim((string) $c['id']) : '';
			if ($id === '') {
				$c['id'] = uniqid('', true);
				$changed = true;
			}
			if (empty($c['replies']) || !is_array($c['replies'])) {
				$c['replies'] = [];
			} else {
				$walk($c['replies']);
			}
		}
		unset($c);
	};
	$walk($comments);
	return $changed;
}

// Simpan hasil normalisasi ID untuk satu slug (mode tunggal maupun pecahan).
function comment_persist_ids(string $slug, array $comments): bool
{
	$cfgPath = dirname(__DIR__) . '/data.json';

	if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
		$lockHandle = store_lock_acquire() ? true : null;
		$ok = data_store_comments_write($slug, $comments, false)
			&& data_store_index_update_comments($slug, $comments, false);
		if ($lockHandle) store_lock_release();
		if ($ok && function_exists('store_memo_forget')) store_memo_forget($cfgPath);
		return $ok;
	}

	$data = store_read_json($cfgPath);
	if (!is_array($data)) return false;

	$found = false;
	foreach (['posts', 'posts_archived'] as $section) {
		if (empty($data[$section]) || !is_array($data[$section])) continue;
		foreach ($data[$section] as &$p) {
			if (!is_array($p) || (string) ($p['slug'] ?? '') !== $slug) continue;
			$p['comments'] = $comments;
			$found = true;
			break;
		}
		unset($p);
		if ($found) break;
	}
	if (!$found) return false;

	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($payload === false) return false;
	return store_save_raw($cfgPath, $payload);
}

// Bersihkan objek stiker yang tidak lagi dirujuk. Aman untuk objek berbasis
// hash konten karena s3_gc_prune_keys memeriksa seluruh referensi runtime.
function comment_gc_sticker_keys(array $keys): void
{
	if (empty($keys) || !function_exists('s3_gc_prune_keys')) return;
	$filtered = [];
	foreach ($keys as $key) {
		$key = comment_sticker_key((string) $key);
		if ($key !== '') $filtered[] = $key;
	}
	if (empty($filtered)) return;
	try {
		s3_gc_prune_keys($filtered);
	} catch (Throwable $e) {
		// Best-effort: kegagalan bersih-bersih tidak boleh menggagalkan aksi utama.
	}
}

function comments_storage_limit(): array
{
	$pool = function_exists('s3_storage_pool') ? s3_storage_pool(false) : null;
	if ($pool) {
		return [
			'limit_bytes' => max(0, (int) $pool['remaining_bytes']),
			'limit_mode' => 'dynamic_s3',
		];
	}
	// Kapasitas S3 diketahui ada tetapi cache pemakaian belum tersedia:
	// pakai batas konservatif, jangan menganggap tak terbatas.
	$quota = function_exists('s3_quota_bytes') ? (int) s3_quota_bytes() : 0;
	if ($quota > 0) {
		return ['limit_bytes' => 5 * 1024 * 1024, 'limit_mode' => 'fallback_static'];
	}
	return ['limit_bytes' => 0, 'limit_mode' => 'unlimited'];
}

function comments_used_bytes(): int
{
	// Mode pecahan: jumlahkan metadata di posts/index.json, tanpa memuat
	// seluruh artikel/komentar.
	if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
		$res = data_store_shard_read_ex(DATA_STORE_INDEX_REL, []);
		$status = (string) ($res['status'] ?? 'error');
		if ($status !== 'ok' && $status !== 'missing') return 0;
		$rows = is_array($res['data'] ?? null) ? $res['data'] : [];
		$used = 0;
		foreach ($rows as $row) {
			if (is_array($row)) $used += max(0, (int) ($row['comments_bytes'] ?? 0));
		}
		return $used;
	}
	$data = store_read_json(dirname(__DIR__) . '/data.json');
	if (!is_array($data) || empty($data['posts']) || !is_array($data['posts'])) return 0;
	$all = [];
	foreach ($data['posts'] as $p) {
		$all[] = $p['comments'] ?? [];
	}
	$enc = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	return $enc === false ? 0 : strlen($enc);
}

function get_comments_storage_info(): array
{
	$used = comments_used_bytes();
	$limitInfo = comments_storage_limit();
	$limit = (int) $limitInfo['limit_bytes'];
	return [
		'used_bytes' => $used,
		'limit_bytes' => $limit,
		'used_mb' => round($used / (1024*1024), 3),
		'remaining_bytes' => $limit > 0 ? max(0, $limit - $used) : null,
		'remaining_mb' => $limit > 0 ? round(max(0, $limit - $used) / (1024*1024), 3) : null,
		'percent_used' => $limit > 0 ? round(($used / $limit) * 100, 2) : null,
		'limit_mode' => $limitInfo['limit_mode'],
	];
}
// Hanya izinkan kunci stiker dari prefix uploads/ milik bucket proyek.
function comment_sticker_key($value): string
{
	$value = trim((string) $value);
	if ($value === '' || !function_exists('s3_prefix')) return '';
	$prefix = s3_prefix();
	if ($prefix === '') return '';
	if (preg_match('#' . preg_quote($prefix, '#') . 'uploads/[A-Za-z0-9._-]+#', $value, $m)) {
		return $m[0];
	}
	return '';
}

function comment_embed_allowed_tags(): array
{
	return [
		'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'frameborder', 'title', 'loading', 'referrerpolicy', 'class'],
		'blockquote' => ['cite', 'class', 'data-instgrm-permalink', 'data-instgrm-version'],
		'img' => ['src', 'alt', 'width', 'height', 'loading', 'class'],
		'video' => ['src', 'poster', 'width', 'height', 'controls', 'loop', 'muted', 'playsinline', 'preload', 'class'],
		'audio' => ['src', 'controls', 'loop', 'preload', 'class'],
		'source' => ['src', 'type'],
		'a' => ['href', 'target', 'rel', 'class'],
		'div' => ['class'],
		'span' => ['class'],
		'p' => ['class'],
		'figure' => ['class'],
		'figcaption' => ['class'],
		'br' => [],
	];
}

// Host embed yang diizinkan untuk iframe. Tanpa allowlist, komentar publik
// bisa menyematkan iframe dari situs mana pun (phishing/clickjacking).
function comment_embed_iframe_hosts(): array
{
	return [
		'youtube.com', 'www.youtube.com', 'm.youtube.com',
		'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be',
		'player.vimeo.com', 'vimeo.com',
		'instagram.com', 'www.instagram.com',
		'drive.google.com', 'docs.google.com', 'maps.google.com', 'www.google.com',
		'open.spotify.com', 'w.soundcloud.com', 'soundcloud.com',
		'facebook.com', 'www.facebook.com', 'web.facebook.com',
		'twitter.com', 'www.twitter.com', 'platform.twitter.com', 'x.com', 'www.x.com',
		'tiktok.com', 'www.tiktok.com',
	];
}

function comment_embed_host_ok(string $host): bool
{
	$host = strtolower(trim($host));
	if ($host === '') return false;
	foreach (comment_embed_iframe_hosts() as $allowed) {
		if ($host === $allowed) return true;
		$suffix = '.' . $allowed;
		if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) return true;
	}
	return false;
}

function comment_embed_url_ok(string $tag, string $attr, string $value): bool
{
	$value = trim($value);
	if ($value === '') return false;
	if ($attr === 'src' && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $value)) {
		return $tag === 'img';
	}
	$absolute = false;
	if (strpos($value, '//') === 0) {
		$value = 'https:' . $value;
		$absolute = true;
	} elseif (preg_match('#^https?://#i', $value)) {
		$absolute = true;
	} elseif (preg_match('#^/#', $value)) {
		// Path same-origin. Iframe hanya boleh embed host yang diizinkan.
		return $tag !== 'iframe';
	} else {
		return false;
	}
	if (!$absolute) return false;
	$parts = parse_url($value);
	if (!is_array($parts) || empty($parts['host'])) return false;
	if ($tag === 'iframe') {
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		return $scheme === 'https' && comment_embed_host_ok((string) $parts['host']);
	}
	return true;
}

// Pastikan tautan target="_blank" tidak bisa membajak window.opener.
function comment_embed_rel_safe(DOMElement $node): void
{
	if (strtolower($node->tagName) !== 'a') return;
	if (strtolower($node->getAttribute('target')) !== '_blank') return;
	$rel = preg_split('/\s+/', strtolower(trim($node->getAttribute('rel'))));
	$rel = is_array($rel) ? array_filter($rel) : [];
	foreach (['noopener', 'noreferrer'] as $token) {
		if (!in_array($token, $rel, true)) $rel[] = $token;
	}
	$node->setAttribute('rel', implode(' ', array_unique($rel)));
}

function comment_embed_clean_node(DOMNode $node, array $allowed): void
{
	if ($node instanceof DOMElement) {
		$tag = strtolower($node->tagName);
		if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math'], true)) {
			if ($node->parentNode) $node->parentNode->removeChild($node);
			return;
		}
		if (!isset($allowed[$tag])) {
			$parent = $node->parentNode;
			if ($parent) {
				$moved = [];
				while ($node->firstChild) {
					$moved[] = $node->firstChild;
					$parent->insertBefore($node->firstChild, $node);
				}
				$parent->removeChild($node);
				foreach ($moved as $child) comment_embed_clean_node($child, $allowed);
			}
			return;
		}
		$attrs = [];
		foreach ($node->attributes as $attr) $attrs[] = $attr->name;
		foreach ($attrs as $name) {
			$lname = strtolower($name);
			if (!in_array($lname, $allowed[$tag], true)) {
				$node->removeAttribute($name);
				continue;
			}
			if (in_array($lname, ['src', 'href', 'poster', 'cite'], true)) {
				if (!comment_embed_url_ok($tag, $lname, $node->getAttribute($name))) $node->removeAttribute($name);
			}
		}
		if ($tag === 'a') comment_embed_rel_safe($node);
		if (in_array($tag, ['iframe', 'img'], true) && !$node->hasAttribute('src')) {
			if ($node->parentNode) $node->parentNode->removeChild($node);
			return;
		}
	}
	foreach (iterator_to_array($node->childNodes) as $child) {
		comment_embed_clean_node($child, $allowed);
	}
}

function comment_embed_sanitize($html): string
{
	$html = trim((string) $html);
	if ($html === '') return '';
	if (function_exists('mb_substr')) {
		$html = mb_substr($html, 0, 6000, 'UTF-8');
	} else {
		$html = substr($html, 0, 6000);
	}
	if (!class_exists('DOMDocument')) {
		// Fail-closed: sanitizer berbasis regex tidak cukup aman untuk HTML
		// publik (srcdoc/style/formaction/xlink:href lolos). Tanpa DOM, embed
		// ditolak sepenuhnya agar tidak ada stored XSS.
		return '';
	}
	$allowed = comment_embed_allowed_tags();
	$doc = new DOMDocument('1.0', 'UTF-8');
	$prev = libxml_use_internal_errors(true);
	$doc->loadHTML(
		'<?xml encoding="UTF-8"><div id="comment-embed-root">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
	);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	$root = $doc->getElementById('comment-embed-root');
	if (!$root) {
		foreach ($doc->childNodes as $childNode) {
			if ($childNode->nodeType === XML_PI_NODE) continue;
			$root = $childNode;
			break;
		}
	}
	if (!$root) return '';
	comment_embed_clean_node($root, $allowed);
	$out = '';
	foreach (iterator_to_array($root->childNodes) as $child) {
		$out .= $doc->saveHTML($child);
	}
	return trim($out);
}

function comment_append_new(array &$comments, ?string $parentId, array $new): bool
{
	if ($parentId === null || $parentId === '') {
		$comments[] = $new;
		return true;
	}
	foreach ($comments as &$c) {
		if (!is_array($c)) continue;
		if (isset($c['id']) && (string) $c['id'] === $parentId) {
			if (empty($c['replies']) || !is_array($c['replies'])) $c['replies'] = [];
			$c['replies'][] = $new;
			return true;
		}
		if (!empty($c['replies']) && is_array($c['replies'])) {
			if (comment_append_new($c['replies'], $parentId, $new)) {
				return true;
			}
		}
	}
	unset($c);
	return false;
}

function save_comment(string $slug, string $name, string $body, ?string $parentId = null, ?string $clientToken = null, ?string $sticker = null, ?string $embed = null): array
{
	$cfgPath = dirname(__DIR__) . '/data.json';
	$name = trim((string) $name);
	$body = trim((string) $body);
	$stickerKey = comment_sticker_key($sticker);
	$embedHtml = comment_embed_sanitize($embed);
	if ($slug === '' || $name === '' || ($body === '' && $stickerKey === '' && $embedHtml === '')) {
		return ['ok' => false, 'error' => 'Nama, komentar, stiker, atau embed HTML diperlukan.'];
	}
	if (strlen($name) > 120) {
		return ['ok' => false, 'error' => 'Nama terlalu panjang.'];
	}
	if (strlen($body) > 20000) {
		return ['ok' => false, 'error' => 'Komentar terlalu panjang.'];
	}
	if (strlen($embedHtml) > 6000) {
		return ['ok' => false, 'error' => 'Embed HTML terlalu panjang.'];
	}

	$id = uniqid('', true);
	$new = [
		'id' => $id,
		'name' => $name,
		'body' => $body,
		'created_at' => date('c'),
		'replies' => [],
		'client_token' => $clientToken ?? null,
	];
	if ($stickerKey !== '') $new['sticker'] = $stickerKey;
	if ($embedHtml !== '') $new['embed'] = $embedHtml;

	$newJson = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$newBytes = $newJson === false ? 0 : strlen($newJson);
	$limitInfo = comments_storage_limit();
	$storageLimit = (int) $limitInfo['limit_bytes'];
	$effectiveRemaining = $storageLimit;
	if (($limitInfo['limit_mode'] ?? '') === 'fallback_static' && $storageLimit > 0) {
		$effectiveRemaining = max(0, $storageLimit - comments_used_bytes());
	}
	$quotaError = 'Sisa kapasitas penyimpanan tidak cukup untuk komentar baru. Hapus beberapa komentar atau hubungi admin.';

	$lockHandle = store_lock_acquire() ? true : null;
	$release = function () use (&$lockHandle) {
		if ($lockHandle) {
			store_lock_release();
			$lockHandle = null;
		}
	};

	// Mode pecahan: baca/tulis hanya shard komentar + satu baris index, di
	// bawah kunci global. Penulisan shard memakai lock=false agar tidak
	// mengunci ulang file lock yang sama (self-deadlock).
	if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
		$postRes = data_store_post_read_ex($slug, false);
		if (($postRes['status'] ?? '') !== 'ok' || !is_array($postRes['post'] ?? null)) {
			$release();
			return ['ok' => false, 'error' => 'Postingan tidak ditemukan'];
		}
		$cres = data_store_comments_read_ex($slug);
		if (!in_array((string) ($cres['status'] ?? 'error'), ['ok', 'missing'], true)) {
			$release();
			return ['ok' => false, 'error' => 'Gagal membaca komentar dari S3'];
		}
		if ($storageLimit > 0 && $newBytes > $effectiveRemaining) {
			$release();
			return ['ok' => false, 'error' => $quotaError];
		}
		$comments = is_array($cres['comments'] ?? null) ? $cres['comments'] : [];
		$prevComments = $comments;
		if (!comment_append_new($comments, $parentId, $new)) {
			$release();
			return ['ok' => false, 'error' => 'Parent comment tidak ditemukan'];
		}
		if (!data_store_comments_write($slug, $comments, false)) {
			$release();
			return ['ok' => false, 'error' => 'Gagal menyimpan komentar ke S3'];
		}
		if (!data_store_index_update_comments($slug, $comments, false)) {
			// Kembalikan shard komentar agar tidak ada komentar tanpa index.
			@data_store_comments_write($slug, $prevComments, false);
			$release();
			return ['ok' => false, 'error' => 'Gagal memperbarui index komentar'];
		}
		$release();
		if (function_exists('store_memo_forget')) store_memo_forget($cfgPath);
		return ['ok' => true, 'id' => $id];
	}

	$data = store_read_json($cfgPath);
	if (!is_array($data)) $data = [];
	if (empty($data['posts']) || !is_array($data['posts'])) $data['posts'] = [];

	if ($storageLimit > 0 && $newBytes > $effectiveRemaining) {
		$release();
		return ['ok' => false, 'error' => $quotaError];
	}

	$found = false;
	foreach ($data['posts'] as &$p) {
		if (isset($p['slug']) && (string) $p['slug'] === $slug) {
			if (empty($p['comments']) || !is_array($p['comments'])) $p['comments'] = [];
			if (!comment_append_new($p['comments'], $parentId, $new)) {
				$release();
				return ['ok' => false, 'error' => 'Parent comment tidak ditemukan'];
			}
			$found = true;
			break;
		}
	}
	unset($p);
	if (!$found) {
		$release();
		return ['ok' => false, 'error' => 'Postingan tidak ditemukan'];
	}

	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($payload === false) {
		$release();
		return ['ok' => false, 'error' => 'Gagal serialisasi data'];
	}

	$ok = store_save_raw($cfgPath, $payload, false);
	$release();
	if (!$ok) return ['ok' => false, 'error' => 'Gagal menyimpan data ke S3'];
	return ['ok' => true, 'id' => $id];
}

/**
 * Ensure existing comments have persistent IDs so replies can target them.
 * Returns true if file was modified.
 */
function normalize_comment_ids(string $slug): bool
{
	$cfgPath = dirname(__DIR__) . '/data.json';

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

	if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
		$lockHandle = store_lock_acquire() ? true : null;
		$cres = data_store_comments_read_ex($slug);
		if (!in_array((string) ($cres['status'] ?? 'error'), ['ok', 'missing'], true)) {
			if ($lockHandle) { store_lock_release(); $lockHandle = null; }
			return false;
		}
		$comments = is_array($cres['comments'] ?? null) ? $cres['comments'] : [];
		$modified = false;
		$walk($comments);
		$ok = true;
		if ($modified) {
			$ok = data_store_comments_write($slug, $comments, false) && data_store_index_update_comments($slug, $comments, false);
		}
		if ($lockHandle) { store_lock_release(); $lockHandle = null; }
		return $modified && $ok;
	}

	$data = store_read_json($cfgPath);
	if (!is_array($data) || empty($data['posts']) || !is_array($data['posts'])) return false;

	$modified = false;
	foreach ($data['posts'] as &$p) {
		if (!isset($p['slug']) || (string) $p['slug'] !== $slug) continue;
		if (empty($p['comments']) || !is_array($p['comments'])) break;
		$walk($p['comments']);
		break;
	}
	unset($p);

	if ($modified) {
		$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($payload === false) return false;
		if (!store_save_raw($cfgPath, $payload)) return false;
	}

	return $modified;
}

/**
 * Remove a comment (and its subtree) by id for a given post slug.
 * Returns ['ok'=>true] on success or ['ok'=>false,'error'=>msg].
 */
function delete_comment(string $slug, string $commentId): array
{
	$cfgPath = dirname(__DIR__) . '/data.json';
	$removedRefs = [];

	$remove = function (&$list, $id) use (&$remove, &$removedRefs) {
		foreach ($list as $idx => &$c) {
			if (isset($c['id']) && (string) $c['id'] === $id) {
				if (function_exists('s3_gc_collect_refs')) {
					s3_gc_collect_refs($list[$idx], $removedRefs);
				}
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

	if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
		$lockHandle = store_lock_acquire() ? true : null;
		$cres = data_store_comments_read_ex($slug);
		if (!in_array((string) ($cres['status'] ?? 'error'), ['ok', 'missing'], true)) {
			if ($lockHandle) { store_lock_release(); $lockHandle = null; }
			return ['ok' => false, 'error' => 'Gagal membaca komentar dari S3'];
		}
		$comments = is_array($cres['comments'] ?? null) ? $cres['comments'] : [];
		$prevComments = $comments;
		$found = $remove($comments, $commentId);
		$ok = true;
		if ($found) {
			$ok = data_store_comments_write($slug, $comments, false)
				&& data_store_index_update_comments($slug, $comments, false);
			if (!$ok) {
				@data_store_comments_write($slug, $prevComments, false);
			}
		}
		if ($lockHandle) { store_lock_release(); $lockHandle = null; }
		if (!$found) return ['ok' => false, 'error' => 'Komentar tidak ditemukan'];
		if (!$ok) return ['ok' => false, 'error' => 'Gagal menyimpan data ke S3'];
		if (function_exists('store_memo_forget')) store_memo_forget($cfgPath);
		comment_gc_sticker_keys(array_keys($removedRefs));
		return ['ok' => true];
	}

	$data = store_read_json($cfgPath);
	if (!is_array($data)) return ['ok' => false, 'error' => 'Data posting tidak ditemukan'];

	$found = false;
	foreach (['posts', 'posts_archived'] as $section) {
		if (empty($data[$section]) || !is_array($data[$section])) continue;
		foreach ($data[$section] as &$p) {
			if (!isset($p['slug']) || (string) $p['slug'] !== $slug) continue;
			if (!empty($p['comments']) && is_array($p['comments']) && $remove($p['comments'], $commentId)) {
				$found = true;
			}
			break;
		}
		unset($p);
		if ($found) break;
	}

	if (!$found) return ['ok' => false, 'error' => 'Komentar tidak ditemukan'];

	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($payload === false) return ['ok' => false, 'error' => 'Gagal serialisasi data'];

	if (!store_save_raw($cfgPath, $payload)) return ['ok' => false, 'error' => 'Gagal menyimpan data ke S3'];

	comment_gc_sticker_keys(array_keys($removedRefs));
	return ['ok' => true];
}

/**
 * Terapkan perubahan pada satu komentar (berdasarkan id) untuk sebuah post.
 * Menangani mode data tunggal maupun shard S3.
 */
function comment_moderate_apply(string $slug, string $commentId, callable $mutator): array
{
	$cfgPath = dirname(__DIR__) . '/data.json';

	$apply = function (&$list, $commentId, $mutator) use (&$apply) {
		foreach ($list as &$c) {
			if (isset($c['id']) && (string) $c['id'] === $commentId) {
				return (bool) $mutator($c);
			}
			if (!empty($c['replies']) && is_array($c['replies'])) {
				if ($apply($c['replies'], $commentId, $mutator)) return true;
			}
		}
		unset($c);
		return false;
	};

	if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
		$lockHandle = store_lock_acquire() ? true : null;
		$cres = data_store_comments_read_ex($slug);
		if (!in_array((string) ($cres['status'] ?? 'error'), ['ok', 'missing'], true)) {
			if ($lockHandle) { store_lock_release(); $lockHandle = null; }
			return ['ok' => false, 'error' => 'Gagal membaca komentar dari S3'];
		}
		$comments = is_array($cres['comments'] ?? null) ? $cres['comments'] : [];
		$prevComments = $comments;
		$found = $apply($comments, $commentId, $mutator);
		$ok = true;
		if ($found) {
			$ok = data_store_comments_write($slug, $comments, false)
				&& data_store_index_update_comments($slug, $comments, false);
			if (!$ok) {
				@data_store_comments_write($slug, $prevComments, false);
			}
		}
		if ($lockHandle) { store_lock_release(); $lockHandle = null; }
		if (!$found) return ['ok' => false, 'error' => 'Komentar tidak ditemukan'];
		if (!$ok) return ['ok' => false, 'error' => 'Gagal menyimpan data ke S3'];
		return ['ok' => true];
	}

	$data = store_read_json($cfgPath);
	if (!is_array($data)) return ['ok' => false, 'error' => 'Data posting tidak ditemukan'];

	$found = false;
	foreach (['posts', 'posts_archived'] as $section) {
		if (empty($data[$section]) || !is_array($data[$section])) continue;
		foreach ($data[$section] as &$p) {
			if (!isset($p['slug']) || (string) $p['slug'] !== $slug) continue;
			if (!empty($p['comments']) && is_array($p['comments']) && $apply($p['comments'], $commentId, $mutator)) {
				$found = true;
			}
			break;
		}
		unset($p);
		if ($found) break;
	}

	if (!$found) return ['ok' => false, 'error' => 'Komentar tidak ditemukan'];

	$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($payload === false) return ['ok' => false, 'error' => 'Gagal serialisasi data'];
	if (!store_save_raw($cfgPath, $payload)) return ['ok' => false, 'error' => 'Gagal menyimpan data ke S3'];

	return ['ok' => true];
}

function comment_set_status(string $slug, string $commentId, string $status): array
{
	$status = strtolower(trim($status));
	if (!in_array($status, ['approved', 'suspended'], true)) {
		return ['ok' => false, 'error' => 'Status komentar tidak valid'];
	}
	return comment_moderate_apply($slug, $commentId, function (array &$c) use ($status) {
		$c['status'] = $status;
		$c['moderated_at'] = date('c');
		return true;
	});
}

function comment_update_body(string $slug, string $commentId, string $body): array
{
	$body = trim((string) $body);
	if (strlen($body) > 20000) return ['ok' => false, 'error' => 'Komentar terlalu panjang.'];
	$invalid = false;
	$res = comment_moderate_apply($slug, $commentId, function (array &$c) use ($body, &$invalid) {
		if ($body === '' && empty($c['sticker']) && empty($c['embed'])) {
			$invalid = true;
			return false;
		}
		$c['body'] = $body;
		$c['edited_by_admin'] = true;
		$c['edited_at'] = date('c');
		return true;
	});
	if ($invalid) return ['ok' => false, 'error' => 'Isi komentar tidak boleh kosong.'];
	return $res;
}

// Edit isi komentar sekaligus stiker dan embed dari panel moderasi.
function comment_update_content(
	string $slug,
	string $commentId,
	string $body,
	?string $sticker = null,
	?string $embed = null,
	bool $clearSticker = false,
	bool $clearEmbed = false
): array {
	$body = trim((string) $body);
	if (strlen($body) > 20000) return ['ok' => false, 'error' => 'Komentar terlalu panjang.'];
	$stickerKey = $clearSticker ? '' : comment_sticker_key((string) $sticker);
	$embedHtml = $clearEmbed ? '' : comment_embed_sanitize((string) $embed);
	if (strlen($embedHtml) > 6000) return ['ok' => false, 'error' => 'Embed HTML terlalu panjang.'];
	$invalid = false;
	$oldSticker = '';
	$res = comment_moderate_apply($slug, $commentId, function (array &$c) use ($body, $stickerKey, $embedHtml, &$invalid, &$oldSticker) {
		if ($body === '' && $stickerKey === '' && $embedHtml === '') {
			$invalid = true;
			return false;
		}
		$oldSticker = comment_sticker_key($c['sticker'] ?? '');
		$c['body'] = $body;
		if ($stickerKey === '') {
			unset($c['sticker']);
		} else {
			$c['sticker'] = $stickerKey;
		}
		if ($embedHtml === '') {
			unset($c['embed']);
		} else {
			$c['embed'] = $embedHtml;
		}
		$c['edited_by_admin'] = true;
		$c['edited_at'] = date('c');
		return true;
	});
	if ($invalid) return ['ok' => false, 'error' => 'Isi komentar, stiker, atau embed tidak boleh kosong semua.'];
	if (!empty($res['ok']) && $oldSticker !== '' && $oldSticker !== $stickerKey) {
		if (function_exists('data_store_is_sharded') && data_store_is_sharded() && function_exists('store_memo_forget')) {
			store_memo_forget(dirname(__DIR__) . '/data.json');
		}
		comment_gc_sticker_keys([$oldSticker]);
	}
	return $res;
}

// IP klien: pakai header Cloudflare bila ada (situs di belakang CF), jika
// tidak REMOTE_ADDR. Nilai non-IP diabaikan.
function comment_client_ip(): string
{
	$candidates = [
		$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
		$_SERVER['REMOTE_ADDR'] ?? '',
	];
	foreach ($candidates as $candidate) {
		$ip = trim((string) $candidate);
		if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
	}
	return '';
}

// Batasi unggahan stiker per IP agar endpoint publik tidak dipakai sebagai
// hosting gambar gratis. Fail-closed: bila batas tidak bisa ditegakkan,
// unggahan ditolak (bukan diizinkan).
function comment_upload_throttled(): bool
{
	$ip = comment_client_ip();
	if ($ip === '') $ip = 'unknown';
	if (!function_exists('seo_cache_path')) return true;
	$file = seo_cache_path('sticker-rate-' . sha1($ip) . '.json');
	$now = time();
	$window = 600; // 10 menit
	$max = 12;
	$fh = @fopen($file, 'c+');
	if (!$fh) return true;
	if (!@flock($fh, LOCK_EX)) {
		@fclose($fh);
		return true;
	}
	$raw = (string) @stream_get_contents($fh);
	$stamps = json_decode($raw, true);
	if (!is_array($stamps)) $stamps = [];
	$stamps = array_values(array_filter($stamps, function ($t) use ($now, $window) {
		return is_int($t) && $t > $now - $window;
	}));
	if (count($stamps) >= $max) {
		@flock($fh, LOCK_UN);
		@fclose($fh);
		return true;
	}
	$stamps[] = $now;
	@ftruncate($fh, 0);
	@rewind($fh);
	@fwrite($fh, json_encode($stamps, JSON_UNESCAPED_SLASHES));
	@fflush($fh);
	@flock($fh, LOCK_UN);
	@fclose($fh);
	if (function_exists('seo_cache_gc')) seo_cache_gc(basename($file));
	return false;
}

// ---- CSRF token komentar (form publik, mencegah spam lintas situs) ----
function comment_session_start(): void
{
	if (session_status() === PHP_SESSION_ACTIVE) return;
	if (headers_sent()) return;
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
	@session_set_cookie_params([
		'lifetime' => 0,
		'path' => '/',
		'secure' => $secure,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
	@session_start();
}

function comment_csrf_token(): string
{
	comment_session_start();
	if (session_status() !== PHP_SESSION_ACTIVE) return '';
	if (empty($_SESSION['comment_csrf']) || !is_string($_SESSION['comment_csrf'])) {
		try {
			$_SESSION['comment_csrf'] = bin2hex(random_bytes(32));
		} catch (Exception $e) {
			$_SESSION['comment_csrf'] = sha1(uniqid('', true) . (string) mt_rand());
		}
	}
	return (string) $_SESSION['comment_csrf'];
}

function comment_csrf_valid(): bool
{
	comment_session_start();
	if (session_status() !== PHP_SESSION_ACTIVE) return false;
	$sent = (string) ($_POST['comment_csrf'] ?? '');
	$known = (string) ($_SESSION['comment_csrf'] ?? '');
	return $sent !== '' && $known !== '' && hash_equals($known, $sent);
}

// Simpan daftar ID komentar milik pengunjung dengan atribut cookie yang aman.
function comment_pending_cookie(string $value, int $expires): void
{
	if (headers_sent()) return;
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
	setcookie('pending_comments', $value, [
		'expires' => $expires,
		'path' => '/',
		'secure' => $secure,
		'httponly' => false,
		'samesite' => 'Lax',
	]);
}

// ---- Upload stiker GIF komentar ke S3 (dipanggil via AJAX dari keyboard komentar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_sticker') {
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');

	$fail = function (string $msg, int $code = 400) {
		http_response_code($code);
		echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
		exit;
	};

	if (!comment_csrf_valid()) $fail('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.', 403);
	$isAdminUploader = !empty($_SESSION['admin_logged_in']);
	if (!$isAdminUploader && comment_upload_throttled()) $fail('Terlalu banyak unggahan stiker. Coba lagi beberapa menit lagi.', 429);
	if (!s3_available()) $fail('Penyimpanan S3 tidak tersedia.', 503);
	$uslug = trim((string) ($_POST['comment_post_slug'] ?? ''));
	if ($uslug === '') $fail('Slug postingan tidak lengkap.');

	$file = $_FILES['sticker'] ?? null;
	if (!is_array($file)) $fail('Berkas stiker tidak ditemukan.');
	$err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
	if ($err !== UPLOAD_ERR_OK) {
		$msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
			? 'Ukuran stiker melebihi batas unggah server.'
			: 'Gagal mengunggah stiker (kode ' . $err . ').';
		$fail($msg);
	}
	$tmp = (string) ($file['tmp_name'] ?? '');
	$size = (int) ($file['size'] ?? 0);
	$maxBytes = 2 * 1024 * 1024; // 2 MB
	if ($tmp === '' || !is_uploaded_file($tmp)) $fail('Berkas stiker tidak valid.');
	if ($size <= 0 || $size > $maxBytes) $fail('Ukuran stiker maksimal 2 MB.');

	$bin = @file_get_contents($tmp);
	if (!is_string($bin) || $bin === '') $fail('Berkas stiker kosong.');

	$mime = '';
	if (class_exists('finfo')) {
		$fi = new finfo(FILEINFO_MIME_TYPE);
		$mime = (string) $fi->file($tmp);
	}
	if ($mime === '' && function_exists('getimagesize')) {
		$gi = @getimagesize($tmp);
		$mime = is_array($gi) ? (string) ($gi['mime'] ?? '') : '';
	}
	$mime = strtolower($mime);
	$allowed = ['image/gif' => 'gif', 'image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg'];
	if (!isset($allowed[$mime])) $fail('Format stiker harus GIF, PNG, WebP, atau JPEG.');
	// Validasi struktur gambar untuk SEMUA format, termasuk GIF (jangan ada bypass).
	if (function_exists('getimagesize')) {
		$gi = @getimagesize($tmp);
		if (!is_array($gi) || empty($gi[0]) || empty($gi[1])) {
			$fail('Berkas stiker bukan gambar yang valid.');
		}
		$detected = strtolower((string) ($gi['mime'] ?? ''));
		if ($detected !== '' && !isset($allowed[$detected])) {
			$fail('Isi berkas tidak cocok dengan format gambar.');
		}
	}

	$key = s3_prefix() . 'uploads/' . sha1($bin) . '.' . $allowed[$mime];
	$debug = null;
	$url = s3_put_binary($bin, $key, $mime, $debug, true);
	if ($url === null) $fail('Gagal menyimpan stiker ke S3.', 502);

	echo json_encode([
		'ok' => true,
		'key' => $key,
		'url' => s3_to_relative_url(s3_photo_url($key)),
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
