<?php
require_once __DIR__ . '/../s3_gc_helper.php';
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
				'author_id' => $posts[$reqIdx]['author_id'] ?? '',
				'focal_x' => $posts[$reqIdx]['focal_x'] ?? 50,
				'focal_y' => $posts[$reqIdx]['focal_y'] ?? 50,
				'ig_embed_fix' => !empty($posts[$reqIdx]['ig_embed_fix']),
				'gdrive_folder' => $posts[$reqIdx]['gdrive_folder'] ?? '',
				'gdrive_view' => ($posts[$reqIdx]['gdrive_view'] ?? 'grid') === 'list' ? 'list' : 'grid',
				'gdrive_folders' => !empty($posts[$reqIdx]['gdrive_folders']) && is_array($posts[$reqIdx]['gdrive_folders']) ? $posts[$reqIdx]['gdrive_folders'] : [],
				'embeds' => !empty($posts[$reqIdx]['embeds']) && is_array($posts[$reqIdx]['embeds']) ? $posts[$reqIdx]['embeds'] : [],
				'embed_enabled' => !empty($posts[$reqIdx]['embed_enabled']),
			];
		} elseif ($reqIdx === -1) {
			$blogEditingIdx = -1;
			$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'focal_x' => 50, 'focal_y' => 50, 'ig_embed_fix' => false, 'gdrive_folder' => '', 'gdrive_view' => 'grid', 'author_id' => '', 'embeds' => [], 'embed_enabled' => false];
		}
	}

	if ($loggedIn && isset($_POST['delete_blog_idx'])) {
		$delIdx = (int) $_POST['delete_blog_idx'];
		if (isset($posts[$delIdx])) {
			$oldRefs = [];
			s3_gc_collect_refs($posts[$delIdx], $oldRefs);
			unset($posts[$delIdx]);
			$posts = array_values($posts);
			$config['posts'] = $posts;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
				$error = 'Gagal menghapus postingan.';
			} else {
				if (!empty($oldRefs)) s3_gc_prune_keys(array_keys($oldRefs));
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
			if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
				$error = 'Gagal mengarsipkan postingan.';
			} else {
				$message = 'Postingan diarsipkan.';
				appendLog($config, $configPath, 'blog-archived', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('blog_admin_update', $insightPath);
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
			if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
				$error = 'Gagal memulihkan postingan dari arsip.';
			} else {
				$message = 'Postingan dipulihkan dari arsip.';
				appendLog($config, $configPath, 'blog-restored', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('blog_admin_update', $insightPath);
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
					if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
						$error = 'Gagal memulihkan postingan dari arsip.';
					} else {
						$message = 'Postingan dipulihkan dari arsip.';
						appendLog($config, $configPath, 'blog-restored', $maxLogs, $archiveLimitDays);
						bumpInsightEvent('blog_admin_update', $insightPath);
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
		if (stripos($image, 'data:') === 0 && function_exists('s3_put_data_uri')) {
			$s3url = s3_put_data_uri($image);
			if ($s3url) { $image = $s3url; }
		}
		$authorId = trim((string) ($_POST['blog_author_id'] ?? ''));
		$focalX = isset($_POST['blog_focal_x']) ? max(0, min(100, (float) $_POST['blog_focal_x'])) : 50;
		$focalY = isset($_POST['blog_focal_y']) ? max(0, min(100, (float) $_POST['blog_focal_y'])) : 50;
		$igFix = isset($_POST['blog_ig_embed_fix']) && $_POST['blog_ig_embed_fix'] === '1';
		$gdriveIds = $_POST['blog_gdrive_id'] ?? [];
		$gdriveViews = $_POST['blog_gdrive_view'] ?? [];
		$gdriveFolders = [];
		if (is_array($gdriveIds)) {
			foreach ($gdriveIds as $gk => $gid) {
				$gid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $gid);
				if ($gid === '') continue;
				$gv = (is_array($gdriveViews) && isset($gdriveViews[$gk]) && $gdriveViews[$gk] === 'list') ? 'list' : 'grid';
				$gdriveFolders[] = ['id' => $gid, 'view' => $gv];
			}
		}
		$gdriveFolder = $gdriveFolders[0]['id'] ?? '';
		$gdriveView = $gdriveFolders[0]['view'] ?? 'grid';
		$idx = isset($_POST['blog_edit_idx']) ? (int) $_POST['blog_edit_idx'] : -1;
		$now = date('c');
		$created = ($idx >= 0 && isset($posts[$idx])) ? ($posts[$idx]['created_at'] ?? $now) : $now;
		$slug = makeSlug($slugInput !== '' ? $slugInput : $title);

		$existing = $idx >= 0 && isset($posts[$idx]) && is_array($posts[$idx]) ? $posts[$idx] : [];
		$oldRefs = [];
		if (!empty($existing)) s3_gc_collect_refs($existing, $oldRefs);
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
					'author_id' => $authorId,
					'focal_x' => $focalX,
					'focal_y' => $focalY,
					'ig_embed_fix' => $igFix,
					'gdrive_folder' => $gdriveFolder,
					'gdrive_view' => $gdriveView,
					'gdrive_folders' => $gdriveFolders,
					'embeds' => $embedList,
					'embed_enabled' => $embedEnabled,
					'created_at' => $created,
					'updated_at' => $now,
					'comments' => $preservedComments,
				];
				if ($preservedArchived) $newPost['archived'] = true;
				if ($preservedArchivedAt) $newPost['archived_at'] = $preservedArchivedAt;

				if ($idx >= 0 && isset($posts[$idx])) {
					// Pertahankan status token 0-index lama; jangan set untuk post legacy.
					if (!empty($posts[$idx]['embed_tokens_v2'])) $newPost['embed_tokens_v2'] = true;
					$posts[$idx] = $newPost;
					$blogEditingIdx = $idx;
				} else {
					$newPost['embed_tokens_v2'] = true;
					array_unshift($posts, $newPost);
					$posts = array_slice($posts, 0, $maxPosts);
					$blogEditingIdx = 0;
				}

				$config['posts'] = $posts;
				$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
				if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
					$error = 'Gagal menyimpan postingan.';
				} else {
					if (!empty($oldRefs)) s3_gc_prune_keys(array_keys($oldRefs));
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
	<form method="post" class="stack-sm" id="blog-form" data-autosave-key="blog"><?php echo ukmi_csrf_field(); ?>
		<input type="hidden" name="blog_edit_idx" id="blog_edit_idx" value="<?php echo (int) $blogEditingIdx; ?>">
		<div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
			<div>
				<label for="blog_title">Judul</label>
				<input type="text" id="blog_title" name="blog_title" value="<?php echo htmlspecialchars($blogDraft['title'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Judul postingan" required>
			</div>
			<div>
				<label for="blog_slug">Slug (opsional)</label>
				<input type="text" id="blog_slug" name="blog_slug" value="<?php echo htmlspecialchars($blogDraft['slug'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="otomatis dari judul">
				<p class="muted-text" style="margin:4px 0 0;">Dipakai di URL /blog/&lt;slug&gt;</p>
			</div>
			<div>
				<label for="blog_image">Gambar (opsional)</label>
				<input type="text" id="blog_image" name="blog_image" value="<?php echo htmlspecialchars($blogDraft['image'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg atau data:image/jpeg;base64,...">
				<input type="file" id="blog_image_file" accept="image/*" style="margin-top:6px;">
				<p class="muted-text" style="margin:4px 0 0;">Pilih file untuk diunggah (auto compress <=1MB) atau tempel URL gambar.</p>
				<input type="hidden" id="blog_focal_x" name="blog_focal_x" value="<?php echo htmlspecialchars((string) ($blogDraft['focal_x'] ?? 50), ENT_QUOTES, 'UTF-8'); ?>">
				<input type="hidden" id="blog_focal_y" name="blog_focal_y" value="<?php echo htmlspecialchars((string) ($blogDraft['focal_y'] ?? 50), ENT_QUOTES, 'UTF-8'); ?>">
				<p class="muted-text" id="blog_focal_note" style="margin:4px 0 0;">Titik fokus sampul: otomatis dari deteksi wajah saat unggah.</p>
				<div id="blog_focal_pick" style="position:relative; width:100%; max-width:420px; margin-top:8px; display:none; cursor:crosshair; border:1px solid var(--stroke); border-radius:10px; overflow:hidden;">
					<img id="blog_focal_img" alt="Pratinjau titik fokus sampul" style="display:block; width:100%; height:auto;">
					<span id="blog_focal_marker" style="position:absolute; width:18px; height:18px; border-radius:50%; border:2px solid #fff; background:rgba(56,189,248,0.75); transform:translate(-50%,-50%); pointer-events:none; box-shadow:0 0 0 2px rgba(0,0,0,0.45);"></span>
				</div>
				<p class="muted-text" style="margin:4px 0 0;">Klik pada pratinjau untuk menetapkan titik fokus sampul secara manual (berguna untuk postingan lama).</p>
			</div>
			<div>
				<label for="blog_author_id">Penulis</label>
				<select id="blog_author_id" name="blog_author_id">
					<option value="">Admin UKMI POLMED</option>
					<?php foreach ((authors_load()['authors'] ?? []) as $au): ?>
						<option value="<?php echo htmlspecialchars((string) ($au['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($blogDraft['author_id'] ?? '') === ($au['id'] ?? '')) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) (($au['pen_name'] ?? '') !== '' ? $au['pen_name'] : ($au['username'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></option>
					<?php endforeach; ?>
				</select>
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
			<label style="display:flex; align-items:center; gap:10px; margin-top:8px;">
				<span>Perbaiki layout embed Instagram (wrapper)</span>
				<label class="toggle">
					<input type="checkbox" id="blog_ig_embed_fix" name="blog_ig_embed_fix" value="1" <?php echo !empty($blogDraft['ig_embed_fix']) ? 'checked' : ''; ?>>
					<span class="slider"></span>
				</label>
			</label>
			<div style="margin-top:10px;">
				<label>Google Drive Folder (bisa lebih dari satu) — token [[GDRIVE0]], [[GDRIVE1]], ...</label>
				<div id="gdrive_list">
					<?php $__gd0 = (array) ($blogDraft['gdrive_folders'] ?? []); if (empty($__gd0) && !empty($blogDraft['gdrive_folder'])) { $__gd0 = [['id' => $blogDraft['gdrive_folder'], 'view' => $blogDraft['gdrive_view'] ?? 'grid']]; } if (empty($__gd0)) { $__gd0 = [['id' => '', 'view' => 'grid']]; } foreach ($__gd0 as $__gd): ?>
					<div class="gdrive-item" style="display:flex; gap:8px; align-items:center; margin-top:6px;">
						<input type="text" name="blog_gdrive_id[]" aria-label="Folder ID Google Drive" value="<?php echo htmlspecialchars((string) ($__gd['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Folder ID" style="flex:1;padding:6px;border-radius:8px;">
						<select name="blog_gdrive_view[]" aria-label="Tampilan Google Drive" style="padding:6px;border-radius:8px;">
							<option value="grid" <?php echo (($__gd['view'] ?? 'grid') === 'grid') ? 'selected' : ''; ?>>Grid</option>
							<option value="list" <?php echo (($__gd['view'] ?? 'grid') === 'list') ? 'selected' : ''; ?>>List</option>
						</select>
						<button type="button" class="remove-gdrive btn-danger-outline" style="border-radius:8px; padding:6px 10px;">Hapus</button>
					</div>
					<?php endforeach; ?>
				</div>
				<button type="button" id="add-gdrive" class="btn-secondary" style="margin-top:6px;">Tambah GDrive</button>
				<p class="muted-text" style="margin:6px 0 0; line-height:1.55;">
					Cara ambil Folder ID: Google Drive → klik kanan folder → <b>Share</b> → <b>General access</b> = <b>Anyone with the link</b> → <b>Copy link</b>; ambil bagian <b>FOLDER-ID</b> dari <code>https://drive.google.com/drive/folders/FOLDER-ID?usp=sharing</code>.
					Tempatkan dengan token <code>[[GDRIVE0]]</code>, <code>[[GDRIVE1]]</code>, dst; tanpa token muncul di akhir.
				</p>
			</div>
			<div id="blog_embed_wrap" style="margin-top:6px; <?php echo !empty($blogDraft['embed_enabled']) ? '' : 'display:none;'; ?>">
				<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:6px;">
					<label style="margin:0;">Daftar embed (token: [[EMBED0]], [[EMBED1]], ... — Embed 1 memakai [[EMBED0]])</label>
					<button type="button" id="add-embed" class="btn-secondary" style="max-width:180px;">Tambah embed</button>
				</div>
				<div id="blog_embed_list" class="stack-sm">
					<?php
					$embedsDraft = !empty($blogDraft['embeds']) && is_array($blogDraft['embeds']) ? $blogDraft['embeds'] : [];
					if (empty($embedsDraft)) { $embedsDraft = ['']; }
					foreach ($embedsDraft as $idx => $embHtml): ?>
					<div class="embed-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
						<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Embed <?php echo $idx + 1; ?></label>
						<textarea name="blog_embed_html[]" aria-label="Kode HTML Embed" style="min-height:120px; font-family: 'SFMono-Regular', Consolas, monospace; width:100%; resize: vertical;" placeholder="&lt;iframe ...&gt;&lt;/iframe&gt;"><?php echo htmlspecialchars($embHtml, ENT_QUOTES, 'UTF-8'); ?></textarea>
						<div style="text-align:right; margin-top:6px;">
							<button type="button" class="remove-embed btn-danger-outline" style="min-width:90px;">Hapus</button>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<p class="muted-text" style="margin:4px 0 0;">Letakkan [[EMBED0]], [[EMBED1]], ... di konten sesuai posisi (Embed 1 → [[EMBED0]]). Embed tanpa token muncul di akhir.</p>
			</div>
		</div>
		<div class="inline-actions" style="margin-top: 6px; align-items:center;">
			<button type="submit" name="blog_save" value="1" <?php echo $blogLimitReached ? 'disabled' : ''; ?>>Simpan postingan</button>
			<button type="button" id="blog_reset" class="btn-secondary">Bersihkan formulir</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Klik Edit pada daftar untuk memuat postingan ke formulir. Simpan akan overwrite entri terpilih atau menambah baru jika kosong.</p>
	</form>
	<div class="admin-box" style="margin-top:10px;">
		<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 8px;">
			<h3 style="margin:0; font-size:15px;">Daftar postingan</h3>
			<?php if (!empty($posts)): ?>
				<input type="search" id="blog_post_search" placeholder="Cari postingan..." autocomplete="off" style="max-width:320px; flex:1 1 220px; padding:8px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
			<?php endif; ?>
		</div>
		<?php if (empty($posts)): ?>
			<p class="muted-text" style="margin:0;">Belum ada postingan.</p>
		<?php else: ?>
			<p class="muted-text" id="blog_post_search_info" style="margin:0 0 6px;"></p>
			<ul class="logs" id="blog_post_list" style="margin-top:6px; max-height:260px; overflow:auto;">
				<?php foreach ($posts as $pIdx => $post): ?>
					<?php if (!empty($post['archived'])) continue; ?>
					<?php $blogSearchBlob = strtolower(trim((string) ($post['title'] ?? '') . ' ' . ($post['slug'] ?? '') . ' ' . ($post['summary'] ?? ''))); ?>
					<li class="blog-post-item" data-search="<?php echo htmlspecialchars($blogSearchBlob, ENT_QUOTES, 'UTF-8'); ?>" style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
						<div>
							<strong><?php echo htmlspecialchars($post['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
							<?php if (!empty($post['slug'])): ?><br><span style="color: var(--muted); font-size:12px;">Slug: <?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($post['summary'])): ?><br><span style="color: var(--muted); font-size:12px;">Ringkas: <?php echo htmlspecialchars($post['summary'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($post['image'])): ?><br><span style="color: var(--muted); font-size:12px;">Gambar: <?php echo htmlspecialchars(strlen($post['image']) > 120 ? substr($post['image'], 0, 120) . '...' : $post['image'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
							<?php if (!empty($post['embed_enabled'])): ?><br><span style="color: var(--muted); font-size:12px;">Embed aktif (<?php echo isset($post['embeds']) && is_array($post['embeds']) ? count($post['embeds']) : 0; ?>)</span><?php endif; ?>
							<?php if (!empty($post['created_at'])): ?><br><span style="color: var(--muted); font-size:12px;">Dibuat: <?php echo htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
						</div>
						<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
							<form method="post" style="margin:0;"><?php echo ukmi_csrf_field(); ?>
								<input type="hidden" name="blog_edit_idx" value="<?php echo $pIdx; ?>">
								<button type="submit" class="btn-secondary" style="min-width:80px;">Edit</button>
							</form>
							<?php if (!empty($post['archived'])): ?>
								<form method="post" style="margin:0;"><?php echo ukmi_csrf_field(); ?>
										<input type="hidden" name="restore_post_idx" value="<?php echo $pIdx; ?>">
										<button type="submit" style="min-width:90px; background: linear-gradient(135deg, #38bdf8, #0ea5e9); color: #0b1727; box-shadow: 0 10px 30px rgba(14,165,233,0.35); border: none;">Pulihkan</button>
									</form>
								<?php else: ?>
									<form method="post" style="margin:0;"><?php echo ukmi_csrf_field(); ?>
										<input type="hidden" name="archive_blog_idx" value="<?php echo $pIdx; ?>">
										<button type="submit" class="btn-secondary" style="min-width:90px;">Arsipkan</button>
									</form>
								<?php endif; ?>
							<form method="post" style="margin:0;" onsubmit="return uiConfirm(event, 'Hapus postingan ini?');"><?php echo ukmi_csrf_field(); ?>
								<input type="hidden" name="delete_blog_idx" value="<?php echo $pIdx; ?>">
								<button type="submit" class="btn-danger" style="min-width:90px; border: none;">Hapus</button>
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
						<form method="post" style="margin:0;"><?php echo ukmi_csrf_field(); ?>
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
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
(function () {
	const blogImageFile = document.getElementById('blog_image_file');
	const blogImageInput = document.getElementById('blog_image');
	const focalXInput = document.getElementById('blog_focal_x');
	const focalYInput = document.getElementById('blog_focal_y');
	const focalNote = document.getElementById('blog_focal_note');
	const focalPick = document.getElementById('blog_focal_pick');
	const focalImg = document.getElementById('blog_focal_img');
	const focalMarker = document.getElementById('blog_focal_marker');
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
	let faceApiReady = null;
	function ensureFaceApi() {
		if (!window.faceapi) return Promise.reject(new Error('faceapi'));
		if (!faceApiReady) {
			faceApiReady = faceapi.nets.tinyFaceDetector.loadFromUri('https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights').then(() => true);
		}
		return faceApiReady;
	}
	function imageFromDataUrl(dataUrl) {
		return new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('img')); i.src = dataUrl; });
	}
	async function autoFocalFromDataUrl(dataUrl) {
		if (!focalXInput || !focalYInput) return;
		try {
			if (focalNote) focalNote.textContent = 'Mendeteksi wajah untuk titik fokus...';
			await ensureFaceApi();
			const img = await imageFromDataUrl(dataUrl);
			const dets = await faceapi.detectAllFaces(img, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.4 }));
			const W = img.naturalWidth || img.width, H = img.naturalHeight || img.height;
			if (!dets.length) { focalXInput.value = 50; focalYInput.value = 50; if (focalNote) focalNote.textContent = 'Tidak ada wajah terdeteksi — titik fokus 50%/50%.'; return; }
			let minX = W, maxX = 0, minY = H, maxY = 0;
			dets.forEach((d) => { const b = d.box; minX = Math.min(minX, b.x); maxX = Math.max(maxX, b.x + b.width); minY = Math.min(minY, b.y); maxY = Math.max(maxY, b.y + b.height); });
			const faceCx = (minX + maxX) / 2;
			const faceH = (maxY - minY) || (H * 0.2);
			const bodyTop = minY, bodyBottom = Math.min(maxY + faceH * 2.5, H);
			const fx = Math.max(0, Math.min(100, (faceCx / W) * 100));
			const fy = Math.max(0, Math.min(100, (((bodyTop + bodyBottom) / 2) / H) * 100));
			focalXInput.value = Math.round(fx); focalYInput.value = Math.round(fy);
			if (focalNote) focalNote.textContent = 'Titik fokus sampul: ' + Math.round(fx) + '% / ' + Math.round(fy) + '% (deteksi wajah).';
		} catch (_) {
			if (focalXInput && !focalXInput.value) focalXInput.value = 50;
			if (focalYInput && !focalYInput.value) focalYInput.value = 50;
			if (focalNote) focalNote.textContent = 'Deteksi wajah tidak tersedia — titik fokus 50%/50%.';
		}
	}
	blogImageFile?.addEventListener('change', async (e) => {
		const file = e?.target?.files?.[0]; if (!file) return;
		if (!file.type || !file.type.startsWith('image/')) { uiAlert('File harus berupa gambar.'); e.target.value = ''; return; }
		try {
			const dataUrl = await compressImage(file);
			if (dataUrlBytes(dataUrl) > MAX_IMAGE_BYTES) { uiAlert('Gambar masih di atas 1MB setelah kompres. Pilih file yang lebih kecil.'); }
			else {
				if (blogImageInput) blogImageInput.value = dataUrl;
				await autoFocalFromDataUrl(dataUrl);
				renderFocalPreview();
			}
		} catch (_) { uiAlert('Gagal memproses gambar.'); }
		e.target.value = '';
	});
	function clearBlogForm() {
		if (blogEditIdxInput) blogEditIdxInput.value = -1;
		blogForm.querySelectorAll('input[type="text"], textarea').forEach((el) => { el.value = ''; });
		if (blogImageFile) blogImageFile.value = '';
		if (focalXInput) focalXInput.value = 50;
		if (focalYInput) focalYInput.value = 50;
		if (focalNote) focalNote.textContent = 'Titik fokus sampul: otomatis dari deteksi wajah saat unggah.';
		renderFocalPreview();
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
		wrapper.innerHTML = `<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Embed</label><textarea name="blog_embed_html[]" aria-label="Kode HTML Embed" style="min-height:120px; font-family: 'SFMono-Regular', Consolas, monospace; width:100%; resize: vertical;" placeholder="<iframe ...></iframe>">${String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea><div style="text-align:right; margin-top:6px;"><button type="button" class="remove-embed btn-danger-outline" style="min-width:90px;">Hapus</button></div>`;
		blogEmbedList.appendChild(wrapper);
		renumberEmbeds();
		attachEmbedRemoveHandlers();
	}
	blogEmbedAdd?.addEventListener('click', () => addEmbedItem(''));
	attachEmbedRemoveHandlers();

	var gdriveList = document.getElementById('gdrive_list');
	var gdriveAdd = document.getElementById('add-gdrive');
	function addGdriveItem() {
		if (!gdriveList) return;
		var d = document.createElement('div');
		d.className = 'gdrive-item';
		d.style = 'display:flex; gap:8px; align-items:center; margin-top:6px;';
		d.innerHTML = '<input type="text" name="blog_gdrive_id[]" aria-label="Folder ID Google Drive" placeholder="Folder ID" style="flex:1;padding:6px;border-radius:8px;"><select name="blog_gdrive_view[]" aria-label="Tampilan Google Drive" style="padding:6px;border-radius:8px;"><option value="grid">Grid</option><option value="list">List</option></select><button type="button" class="remove-gdrive btn-danger-outline" style="border-radius:8px; padding:6px 10px;">Hapus</button>';
		gdriveList.appendChild(d);
	}
	gdriveAdd?.addEventListener('click', addGdriveItem);
	gdriveList?.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.remove-gdrive');
		if (!b) return;
		var it = b.closest('.gdrive-item');
		if (it) it.remove();
	});

	function positionFocalMarker() {
		if (!focalMarker) return;
		const x = parseFloat(focalXInput?.value || '50');
		const y = parseFloat(focalYInput?.value || '50');
		focalMarker.style.left = (isNaN(x) ? 50 : x) + '%';
		focalMarker.style.top = (isNaN(y) ? 50 : y) + '%';
	}
	function renderFocalPreview() {
		if (!focalPick || !focalImg) return;
		const val = (blogImageInput?.value || '').trim();
		if (val.startsWith('data:') || /^https?:\/\//i.test(val)) {
			if (focalImg.getAttribute('src') !== val) focalImg.src = val;
			focalPick.style.display = 'block';
		} else {
			focalPick.style.display = 'none';
		}
		positionFocalMarker();
	}
	focalPick?.addEventListener('click', (e) => {
		const rect = focalPick.getBoundingClientRect();
		if (!rect.width || !rect.height) return;
		const x = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100));
		const y = Math.max(0, Math.min(100, ((e.clientY - rect.top) / rect.height) * 100));
		if (focalXInput) focalXInput.value = Math.round(x);
		if (focalYInput) focalYInput.value = Math.round(y);
		positionFocalMarker();
		if (focalNote) focalNote.textContent = 'Titik fokus sampul (manual): ' + Math.round(x) + '% / ' + Math.round(y) + '%';
	});
	focalImg?.addEventListener('load', positionFocalMarker);
	renderFocalPreview();
})();
</script>
<script>
(function () {
	const searchInput = document.getElementById('blog_post_search');
	const postList = document.getElementById('blog_post_list');
	if (!searchInput || !postList) return;
	const items = Array.from(postList.querySelectorAll('.blog-post-item'));
	const info = document.getElementById('blog_post_search_info');
	const total = items.length;
	function applyBlogSearch() {
		const q = searchInput.value.trim().toLowerCase();
		let shown = 0;
		items.forEach((li) => {
			const haystack = (li.getAttribute('data-search') || li.textContent || '').toLowerCase();
			const match = q === '' || haystack.indexOf(q) !== -1;
			li.style.display = match ? '' : 'none';
			if (match) shown++;
		});
		if (info) info.textContent = q === '' ? '' : 'Menampilkan ' + shown + ' dari ' + total + ' postingan untuk "' + searchInput.value.trim() + '".';
	}
	searchInput.addEventListener('input', applyBlogSearch);
	searchInput.addEventListener('search', applyBlogSearch);
	applyBlogSearch();
})();
</script>
