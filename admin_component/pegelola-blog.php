<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['blog_edit_idx'])) {
		$reqIdx = (int) $_POST['blog_edit_idx'];
		if (isset($posts[$reqIdx])) {
			$blogEditingIdx = $reqIdx;
			$blogDraft = [
				'title' => $posts[$reqIdx]['title'] ?? '',
				'slug' => $posts[$reqIdx]['slug'] ?? '',
				'summary' => $posts[$reqIdx]['summary'] ?? '',
				'body' => $posts[$reqIdx]['body'] ?? '',
				'image' => $posts[$reqIdx]['image'] ?? '',
				'embeds' => !empty($posts[$reqIdx]['embeds']) && is_array($posts[$reqIdx]['embeds']) ? $posts[$reqIdx]['embeds'] : [],
				'embed_enabled' => !empty($posts[$reqIdx]['embed_enabled']),
			];
		} elseif ($reqIdx === -1) {
			$blogEditingIdx = -1;
			$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'embeds' => [], 'embed_enabled' => false];
		}
	}

	if ($loggedIn && isset($_POST['delete_blog_idx'])) {
		$delIdx = (int) $_POST['delete_blog_idx'];
		if (isset($posts[$delIdx])) {
			unset($posts[$delIdx]);
			$posts = array_values($posts);
			$config['posts'] = $posts;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal menghapus postingan.';
			} else {
				$message = 'Postingan dihapus.';
				appendLog($config, $configPath, 'blog-deleted', $maxLogs, $archiveLimitDays);
			}
		}
	}

	if ($loggedIn && isset($_POST['archive_blog_idx'])) {
		$aIdx = (int) $_POST['archive_blog_idx'];
		if (isset($posts[$aIdx])) {
			$archivedItem = $posts[$aIdx];
			$archivedItem['archived'] = true;
			$archivedItem['archived_at'] = date('c');
			$postsArchived = is_array($config['posts_archived'] ?? null) ? array_values($config['posts_archived']) : [];
			$postsArchived = array_slice($postsArchived, 0, $maxPostArchive - 1);
			array_unshift($postsArchived, $archivedItem);
			unset($posts[$aIdx]);
			$posts = array_values($posts);
			$config['posts'] = $posts;
			$config['posts_archived'] = $postsArchived;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal mengarsipkan postingan.';
			} else {
				$message = 'Postingan diarsipkan.';
				appendLog($config, $configPath, 'blog-archived', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('blog_admin_update', $insightPath, $insightMaxBytes);
			}
		}
	}

	if ($loggedIn && isset($_POST['restore_post_idx'])) {
		$rIdx = (int) $_POST['restore_post_idx'];
		$rSlug = trim((string) ($_POST['restore_post_slug'] ?? ''));
		$postsArchived = is_array($config['posts_archived'] ?? null) ? array_values($config['posts_archived']) : [];
		if (isset($postsArchived[$rIdx])) {
			$restoreItem = $postsArchived[$rIdx];
			unset($restoreItem['archived'], $restoreItem['archived_at']);
			array_splice($postsArchived, $rIdx, 1);
			array_unshift($posts, $restoreItem);
			$config['posts'] = array_slice(array_values($posts), 0, $maxPosts);
			$config['posts_archived'] = array_values($postsArchived);
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal memulihkan postingan dari arsip.';
			} else {
				$message = 'Postingan dipulihkan dari arsip.';
				appendLog($config, $configPath, 'blog-restored', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('blog_admin_update', $insightPath, $insightMaxBytes);
			}
		} elseif ($rSlug !== '') {
			foreach ($postsArchived as $i => $p) {
				if (isset($p['slug']) && (string) $p['slug'] === $rSlug) {
					$restoreItem = $p;
					unset($restoreItem['archived'], $restoreItem['archived_at']);
					array_splice($postsArchived, $i, 1);
					array_unshift($posts, $restoreItem);
					$config['posts'] = array_slice(array_values($posts), 0, $maxPosts);
					$config['posts_archived'] = array_values($postsArchived);
					$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
					if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
						$error = 'Gagal memulihkan postingan dari arsip.';
					} else {
						$message = 'Postingan dipulihkan dari arsip.';
						appendLog($config, $configPath, 'blog-restored', $maxLogs, $archiveLimitDays);
						bumpInsightEvent('blog_admin_update', $insightPath, $insightMaxBytes);
					}
					break;
				}
			}
		} else {
			$error = 'Postingan tidak ditemukan atau sudah aktif.';
		}
	}

	if ($loggedIn && isset($_POST['blog_save'])) {
		$title = trim((string) ($_POST['blog_title'] ?? ''));
		$slugInput = trim((string) ($_POST['blog_slug'] ?? ''));
		$summary = trim((string) ($_POST['blog_summary'] ?? ''));
		$body = trim((string) ($_POST['blog_body'] ?? ''));
		$embedEnabled = isset($_POST['blog_embed_enabled']) && $_POST['blog_embed_enabled'] === '1';
		$embedInputs = isset($_POST['blog_embed_html']) ? (array) $_POST['blog_embed_html'] : [];
		$embedList = [];
		foreach ($embedInputs as $emb) {
			$emb = trim((string) $emb);
			if ($emb !== '') $embedList[] = $emb;
		}
		$image = trim((string) ($_POST['blog_image'] ?? ''));
		$idx = isset($_POST['blog_edit_idx']) ? (int) $_POST['blog_edit_idx'] : -1;
		$now = date('c');
		$created = ($idx >= 0 && isset($posts[$idx])) ? ($posts[$idx]['created_at'] ?? $now) : $now;
		$slug = makeSlug($slugInput !== '' ? $slugInput : $title);

		$existing = $idx >= 0 && isset($posts[$idx]) && is_array($posts[$idx]) ? $posts[$idx] : [];
		$preservedComments = !empty($existing['comments']) && is_array($existing['comments']) ? $existing['comments'] : [];
		$preservedArchived = !empty($existing['archived']);
		$preservedArchivedAt = $existing['archived_at'] ?? null;

		$totalCountDuringSave = count($posts);
		$exceedsLimit = ($idx < 0 && $totalCountDuringSave >= $maxPosts);
		if ($exceedsLimit) {
			$error = 'Penyimpanan postingan penuh (maks ' . $maxPosts . '). Hapus posting lama sebelum menambah baru.';
			$blogDraft = ['title' => $title, 'slug' => $slugInput, 'summary' => $summary, 'body' => $body, 'image' => $image, 'embeds' => $embedList, 'embed_enabled' => $embedEnabled];
			$blogEditingIdx = -1;
		} else {
			$normTitle = strtolower($title);
			$normSlug = strtolower($slug);
			$duplicateFound = false;
			foreach ($posts as $i => $p) {
				$existingTitle = strtolower(trim((string) ($p['title'] ?? '')));
				$existingSlug = strtolower(trim((string) ($p['slug'] ?? '')));
				if ($i !== $idx && ($existingTitle === $normTitle || $existingSlug === $normSlug)) {
					$error = 'Judul atau slug sudah dipakai. Gunakan yang lain.';
					$duplicateFound = true;
					$blogDraft = ['title' => $title, 'slug' => $slugInput, 'summary' => $summary, 'body' => $body, 'image' => $image, 'embeds' => $embedList, 'embed_enabled' => $embedEnabled];
					$blogEditingIdx = $idx;
					break;
				}
			}

			if (!$duplicateFound) {
				if ($summary === '') {
					$tmpSummary = preg_replace('/\s+/', ' ', strip_tags($body));
					$summary = substr($tmpSummary, 0, 180);
					if (strlen($tmpSummary) > 180) $summary .= '...';
					if ($summary === '' && $embedEnabled && !empty($embedList)) $summary = 'Konten tersemat tersedia.';
				}
				$newPost = [
					'title' => $title ?: 'Tanpa judul',
					'slug' => $slug,
					'summary' => $summary,
					'body' => $body,
					'image' => $image,
					'embeds' => $embedList,
					'embed_enabled' => $embedEnabled,
					'created_at' => $created,
					'updated_at' => $now,
					'comments' => $preservedComments,
				];
				if ($preservedArchived) $newPost['archived'] = true;
				if ($preservedArchivedAt) $newPost['archived_at'] = $preservedArchivedAt;

				if ($idx >= 0 && isset($posts[$idx])) {
					$posts[$idx] = $newPost;
					$blogEditingIdx = $idx;
				} else {
					array_unshift($posts, $newPost);
					$posts = array_slice($posts, 0, $maxPosts);
					$blogEditingIdx = 0;
				}

				$config['posts'] = $posts;
				$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
				if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
					$error = 'Gagal menyimpan postingan.';
				} else {
					$message = 'Postingan disimpan.';
					appendLog($config, $configPath, 'blog-updated', $maxLogs, $archiveLimitDays);
					$blogEditingIdx = -1;
					$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'embeds' => [], 'embed_enabled' => false];
				}
			}
		}
	}

	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Blog</h2>
	<?php $totalCount = count($posts); $activeCount = count(array_filter($posts, function($p){ return empty($p['archived']); })); $blogLimitReached = ($totalCount >= $maxPosts && $blogEditingIdx === -1); ?>
	<p class="muted-text" style="margin:0 0 10px; <?php echo $blogLimitReached ? 'color:#f87171;' : ''; ?>">Maksimal <?php echo $maxPosts; ?> postingan tersimpan. Saat ini tersimpan: <?php echo $totalCount; ?> (aktif: <?php echo $activeCount; ?>)<?php echo $blogLimitReached ? ' · Hapus posting lama sebelum menambah baru.' : ''; ?></p>
	<form method="post" class="stack-sm" id="blog-form" data-autosave-key="blog">
		<input type="hidden" name="blog_edit_idx" id="blog_edit_idx" value="<?php echo (int) $blogEditingIdx; ?>">
		<div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
			<div>
				<label for="blog_title">Judul</label>
				<input type="text" id="blog_title" name="blog_title" value="<?php echo htmlspecialchars($blogDraft['title'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Judul postingan" required>
			</div>
			<div>
				<label for="blog_slug">Slug (opsional)</label>
				<input type="text" id="blog_slug" name="blog_slug" value="<?php echo htmlspecialchars($blogDraft['slug'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="otomatis dari judul">
				<p class="muted-text" style="margin:4px 0 0;">Dipakai di URL blog.php?slug=...</p>
			</div>
			<div>
				<label for="blog_image">Gambar (opsional)</label>
				<input type="text" id="blog_image" name="blog_image" value="<?php echo htmlspecialchars($blogDraft['image'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg atau data:image/jpeg;base64,...">
				<input type="file" id="blog_image_file" accept="image/*" style="margin-top:6px;">
				<p class="muted-text" style="margin:4px 0 0;">Pilih file untuk diunggah (auto compress <=1MB) atau tempel URL gambar.</p>
			</div>
			<div class="full">
				<label for="blog_summary">Ringkas</label>
				<textarea id="blog_summary" name="blog_summary" style="min-height:90px;"><?php echo htmlspecialchars($blogDraft['summary'], ENT_QUOTES, 'UTF-8'); ?></textarea>
				<p class="muted-text" style="margin:4px 0 0;">Kosongkan agar diringkas otomatis (180 karakter).</p>
			</div>
			<div class="full">
				<label for="blog_body">Konten</label>
				<textarea id="blog_body" name="blog_body" style="min-height:180px; resize: both;"><?php echo htmlspecialchars($blogDraft['body'], ENT_QUOTES, 'UTF-8'); ?></textarea>
				<p class="muted-text" style="margin:4px 0 0;">Mendukung Markdown sederhana: judul (#), tebal (**bold**), miring (*italic*), kode (`inline`, ```blok```), dan tautan [teks](https://...).</p>
			</div>
		</div>
		<div class="full" style="margin-top:6px;">
			<label style="display:flex; align-items:center; gap:10px;">
				<span>Mode embed HTML</span>
				<label class="toggle">
					<input type="checkbox" id="blog_embed_enabled" name="blog_embed_enabled" value="1" <?php echo !empty($blogDraft['embed_enabled']) ? 'checked' : ''; ?>>
					<span class="slider"></span>
				</label>
			</label>
			<div id="blog_embed_wrap" style="margin-top:6px; <?php echo !empty($blogDraft['embed_enabled']) ? '' : 'display:none;'; ?>">
				<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:6px;">
					<label style="margin:0;">Daftar embed (gunakan [[EMBED1]], [[EMBED2]], ... di konten untuk posisi)</label>
					<button type="button" id="add-embed" style="max-width:180px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah embed</button>
				</div>
				<div id="blog_embed_list" class="stack-sm">
					<?php
					$embedsDraft = !empty($blogDraft['embeds']) && is_array($blogDraft['embeds']) ? $blogDraft['embeds'] : [];
					if (empty($embedsDraft)) { $embedsDraft = ['']; }
					foreach ($embedsDraft as $idx => $embHtml): ?>
					<div class="embed-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
						<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Embed <?php echo $idx + 1; ?></label>
						<textarea name="blog_embed_html[]" style="min-height:120px; font-family: 'SFMono-Regular', Consolas, monospace; width:100%; resize: vertical;" placeholder="&lt;iframe ...&gt;&lt;/iframe&gt;"><?php echo htmlspecialchars($embHtml, ENT_QUOTES, 'UTF-8'); ?></textarea>
						<div style="text-align:right; margin-top:6px;">
							<button type="button" class="remove-embed" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus</button>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<p class="muted-text" style="margin:4px 0 0;">Letakkan token [[EMBED1]], [[EMBED2]], ... di konten untuk menentukan posisi. Embed yang tidak ditandai akan muncul di akhir.</p>
			</div>
		</div>
		</div>
		<div class="inline-actions" style="margin-top: 6px; align-items:center;">
			<button type="submit" name="blog_save" value="1" <?php echo $blogLimitReached ? 'disabled' : ''; ?>>Simpan postingan</button>
			<button type="button" id="blog_reset" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Bersihkan formulir</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Klik Edit pada daftar untuk memuat postingan ke formulir. Simpan akan overwrite entri terpilih atau menambah baru jika kosong.</p>
	</form>
	<div class="admin-box" style="margin-top:10px;">
		<h3 style="margin:0 0 8px; font-size:15px;">Daftar postingan</h3>
		<?php if (empty($posts)): ?>
			<p class="muted-text" style="margin:0;">Belum ada postingan.</p>
		<?php else: ?>
			<ul class="logs" style="margin-top:6px; max-height:260px; overflow:auto;">
				<?php foreach ($posts as $pIdx => $post): ?>
					<?php if (!empty($post['archived'])) continue; ?>
					<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
						<div>
							<strong><?php echo htmlspecialchars($post['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<?php if (!empty($post['slug'])): ?><br><span style="color: var(--muted); font-size:12px;">Slug: <?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($post['summary'])): ?><br><span style="color: var(--muted); font-size:12px;">Ringkas: <?php echo htmlspecialchars($post['summary'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($post['image'])): ?><br><span style="color: var(--muted); font-size:12px;">Gambar: <?php echo htmlspecialchars(strlen($post['image']) > 120 ? substr($post['image'], 0, 120) . '...' : $post['image'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($post['embed_enabled'])): ?><br><span style="color: var(--muted); font-size:12px;">Embed aktif (<?php echo isset($post['embeds']) && is_array($post['embeds']) ? count($post['embeds']) : 0; ?>)</span><?php endif; ?>
							<?php if (!empty($post['created_at'])): ?><br><span style="color: var(--muted); font-size:12px;">Dibuat: <?php echo htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
						</div>
						<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
							<form method="post" style="margin:0;">
								<input type="hidden" name="blog_edit_idx" value="<?php echo $pIdx; ?>">
								<button type="submit" style="min-width:80px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Edit</button>
							</form>
							<form method="post" style="margin:0;">
								<?php if (!empty($post['archived'])): ?>
									<form method="post" style="margin:0;">
										<input type="hidden" name="restore_post_idx" value="<?php echo $pIdx; ?>">
										<button type="submit" style="min-width:90px; background: linear-gradient(135deg, #38bdf8, #0ea5e9); color: #0b1727; box-shadow: 0 10px 30px rgba(14,165,233,0.35); border: none;">Pulihkan</button>
									</form>
								<?php else: ?>
									<form method="post" style="margin:0;">
										<input type="hidden" name="archive_blog_idx" value="<?php echo $pIdx; ?>">
										<button type="submit" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Arsipkan</button>
									</form>
								<?php endif; ?>
							<form method="post" style="margin:0;" onsubmit="return confirm('Hapus postingan ini?');">
								<input type="hidden" name="delete_blog_idx" value="<?php echo $pIdx; ?>">
								<button type="submit" style="min-width:90px; background: linear-gradient(135deg, #f87171, #ef4444); color: #0b1727; box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35); border: none;">Hapus</button>
							</form>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="admin-box" style="margin-top:10px;">
		<h3 style="margin:0 0 8px; font-size:15px;">Arsip postingan</h3>
		<?php
		$archivedEntries = is_array($config['posts_archived'] ?? null) ? array_values($config['posts_archived']) : [];
		?>
		<?php if (empty($archivedEntries)): ?>
			<p class="muted-text" style="margin:0;">Belum ada arsip postingan.</p>
		<?php else: ?>
			<ul class="logs" style="margin-top:6px; max-height:220px; overflow:auto;">
				<?php foreach ($archivedEntries as $idx => $item): ?>
					<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
						<div>
							<strong><?php echo htmlspecialchars($item['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<?php if (!empty($item['slug'])): ?><br><span style="color: var(--muted); font-size:12px;">Slug: <?php echo htmlspecialchars($item['slug'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($item['archived_at'])): ?><br><span style="font-size:12px; color: var(--muted);">Diarsipkan: <?php echo htmlspecialchars($item['archived_at'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
						</div>
						<form method="post" style="margin:0;">
							<input type="hidden" name="restore_post_idx" value="<?php echo $idx; ?>">
							<?php if (!empty($item['slug'])): ?>
								<input type="hidden" name="restore_post_slug" value="<?php echo htmlspecialchars($item['slug'], ENT_QUOTES, 'UTF-8'); ?>">
							<?php endif; ?>
							<button type="submit" style="min-width:110px; background: linear-gradient(135deg, #22c55e, #16a34a); color: #06250f; box-shadow: 0 10px 24px rgba(34, 197, 94, 0.35); border: none;">Pulihkan</button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="muted-text" style="margin:6px 0 0;">Menampilkan <?php echo count($archivedEntries); ?> arsip. Arsip ditandai pada daftar posting.</p>
		<?php endif; ?>
	</div>
</div>
<script>
(function () {
	const blogImageFile = document.getElementById('blog_image_file');
	const blogImageInput = document.getElementById('blog_image');
	const blogForm = document.getElementById('blog-form');
	const blogResetBtn = document.getElementById('blog_reset');
	const blogEditIdxInput = document.getElementById('blog_edit_idx');
	const blogEmbedToggle = document.getElementById('blog_embed_enabled');
	const blogEmbedWrap = document.getElementById('blog_embed_wrap');
	const blogEmbedList = document.getElementById('blog_embed_list');
	const blogEmbedAdd = document.getElementById('add-embed');
	if (!blogForm) return;
	const MAX_IMAGE_BYTES = 1000000;
	const dataUrlBytes = (dataUrl) => { const comma = dataUrl.indexOf(','); const base64 = comma === -1 ? dataUrl : dataUrl.slice(comma + 1); return Math.ceil((base64.length * 3) / 4); };
	const loadImageFromFile = (file) => new Promise((resolve, reject) => {
		const reader = new FileReader(); const img = new Image();
		reader.onload = () => { img.onload = () => resolve(img); img.onerror = () => reject(new Error('load')); img.src = reader.result; };
		reader.onerror = () => reject(new Error('read'));
		reader.readAsDataURL(file);
	});
	async function compressImage(file) {
		const img = await loadImageFromFile(file);
		let width = img.naturalWidth || img.width;
		let height = img.naturalHeight || img.height;
		const canvas = document.createElement('canvas');
		const ctx = canvas.getContext('2d');
		const maxDim = 1400;
		if (width > maxDim || height > maxDim) { const s = Math.min(maxDim / width, maxDim / height); width = Math.max(1, Math.round(width * s)); height = Math.max(1, Math.round(height * s)); }
		let quality = 0.9; let dataUrl = '';
		for (let i = 0; i < 6; i++) {
			canvas.width = width; canvas.height = height; ctx.clearRect(0, 0, width, height); ctx.drawImage(img, 0, 0, width, height);
			dataUrl = canvas.toDataURL('image/jpeg', quality);
			if (dataUrlBytes(dataUrl) <= MAX_IMAGE_BYTES) return dataUrl;
			width = Math.max(1, Math.round(width * 0.9)); height = Math.max(1, Math.round(height * 0.9)); quality *= 0.82;
		}
		return dataUrl;
	}
	blogImageFile?.addEventListener('change', async (e) => {
		const file = e?.target?.files?.[0]; if (!file) return;
		if (!file.type || !file.type.startsWith('image/')) { alert('File harus berupa gambar.'); e.target.value = ''; return; }
		try {
			const dataUrl = await compressImage(file);
			if (dataUrlBytes(dataUrl) > MAX_IMAGE_BYTES) alert('Gambar masih di atas 1MB setelah kompres. Pilih file yang lebih kecil.');
			else if (blogImageInput) blogImageInput.value = dataUrl;
		} catch (_) { alert('Gagal memproses gambar.'); }
		e.target.value = '';
	});
	function clearBlogForm() {
		if (blogEditIdxInput) blogEditIdxInput.value = -1;
		blogForm.querySelectorAll('input[type="text"], textarea').forEach((el) => { el.value = ''; });
		if (blogImageFile) blogImageFile.value = '';
		if (blogEmbedToggle) blogEmbedToggle.checked = false;
		syncEmbedVisibility();
		if (blogEmbedList) { blogEmbedList.innerHTML = ''; addEmbedItem(''); }
	}
	blogResetBtn?.addEventListener('click', clearBlogForm);
	function syncEmbedVisibility() { if (blogEmbedWrap && blogEmbedToggle) blogEmbedWrap.style.display = blogEmbedToggle.checked ? '' : 'none'; }
	blogEmbedToggle?.addEventListener('change', syncEmbedVisibility);
	syncEmbedVisibility();
	function renumberEmbeds() { blogEmbedList?.querySelectorAll('.embed-item label').forEach((label, idx) => { label.textContent = `Embed ${idx + 1}`; }); }
	function attachEmbedRemoveHandlers() {
		blogEmbedList?.querySelectorAll('.remove-embed').forEach((btn) => {
			btn.onclick = () => {
				const item = btn.closest('.embed-item');
				if (item && blogEmbedList.children.length > 1) { item.remove(); renumberEmbeds(); }
			};
		});
	}
	function addEmbedItem(value = '') {
		if (!blogEmbedList) return;
		const wrapper = document.createElement('div');
		wrapper.className = 'embed-item';
		wrapper.style = 'border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);';
		wrapper.innerHTML = `<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Embed</label><textarea name="blog_embed_html[]" style="min-height:120px; font-family: 'SFMono-Regular', Consolas, monospace; width:100%; resize: vertical;" placeholder="<iframe ...></iframe>">${String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea><div style="text-align:right; margin-top:6px;"><button type="button" class="remove-embed" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus</button></div>`;
		blogEmbedList.appendChild(wrapper);
		renumberEmbeds();
		attachEmbedRemoveHandlers();
	}
	blogEmbedAdd?.addEventListener('click', () => addEmbedItem(''));
	attachEmbedRemoveHandlers();
})();
</script>
