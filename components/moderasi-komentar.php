<?php
require_once __DIR__ . '/blog-komentar.php';

$modAdminPenName = 'Admin UKMI POLMED';
if (function_exists('author_display')) {
	$__modAdmin = author_display('');
	if (!empty($__modAdmin['pen_name'])) $modAdminPenName = (string) $__modAdmin['pen_name'];
}

if (($adminComponentMode ?? '') === 'handle') {
	if (!$loggedIn) return;

	$commentModMsg = trim((string) ($_GET['comment_msg'] ?? ''));
	if ($commentModMsg !== '') {
		$commentModMap = [
			'suspended' => 'Komentar disuspend dan disembunyikan dari pengunjung.',
			'restored' => 'Komentar dipulihkan dan tampil kembali.',
			'updated' => 'Komentar diperbarui. Badge "Diedit oleh ' . $modAdminPenName . '" akan tampil pada komentar.',
			'deleted' => 'Komentar dihapus.',
		];
		if (isset($commentModMap[$commentModMsg])) {
			$message = $commentModMap[$commentModMsg];
		} elseif ($commentModMsg === 'error') {
			$error = 'Aksi moderasi gagal. Coba lagi.';
		}
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_moderate'])) {
		$modSlug = trim((string) ($_POST['comment_post_slug'] ?? ''));
		$modId = trim((string) ($_POST['comment_id'] ?? ''));
		$modAction = trim((string) ($_POST['comment_action'] ?? ''));

		if (!comment_csrf_valid()) {
			$error = 'Sesi kedaluwarsa. Muat ulang halaman admin lalu coba lagi.';
		} elseif ($modSlug === '' || $modId === '') {
			$error = 'Data komentar tidak lengkap.';
		} else {
			if ($modAction === 'delete') {
				$res = delete_comment($modSlug, $modId);
			} elseif ($modAction === 'suspend') {
				$res = comment_set_status($modSlug, $modId, 'suspended');
			} elseif ($modAction === 'restore') {
				$res = comment_set_status($modSlug, $modId, 'approved');
			} elseif ($modAction === 'edit') {
				$res = comment_update_content(
					$modSlug,
					$modId,
					(string) ($_POST['comment_body'] ?? ''),
					(string) ($_POST['comment_sticker'] ?? ''),
					(string) ($_POST['comment_embed'] ?? ''),
					!empty($_POST['comment_sticker_clear']),
					!empty($_POST['comment_embed_clear'])
				);
			} else {
				$res = ['ok' => false, 'error' => 'Aksi tidak dikenal.'];
			}

			if (!empty($res['ok'])) {
				$flashMap = ['delete' => 'deleted', 'suspend' => 'suspended', 'restore' => 'restored', 'edit' => 'updated'];
				$flashKey = $flashMap[$modAction] ?? $modAction;
				header('Location: /admin?comment_msg=' . urlencode($flashKey));
				exit;
			}
			$error = (string) ($res['error'] ?? 'Aksi moderasi gagal.');
		}
	}
	return;
}

$modCsrf = comment_csrf_token();

// Komentar lama bisa belum punya ID. Normalisasi sekali agar aksi moderasi
// (suspend/pulihkan/edit/hapus) punya target yang valid.
$modNormalizeFailed = false;
$normalizeModComments = function (array &$list) use (&$modNormalizeFailed): void {
	foreach ($list as &$postRow) {
		if (!is_array($postRow)) continue;
		$slug = trim((string) ($postRow['slug'] ?? ''));
		if ($slug === '') continue;
		$comments = isset($postRow['comments']) && is_array($postRow['comments']) ? $postRow['comments'] : null;
		if (!is_array($comments) || empty($comments)) continue;
		if (comment_assign_missing_ids($comments)) {
			if (!comment_persist_ids($slug, $comments)) $modNormalizeFailed = true;
		}
		$postRow['comments'] = $comments;
	}
	unset($postRow);
};
$normalizeModComments($posts);
if (isset($postsArchived) && is_array($postsArchived)) {
	$normalizeModComments($postsArchived);
}

$collectComments = function (array $list, string $postTitle, string $postSlug, int $depth = 0) use (&$collectComments): array {
	$rows = [];
	foreach ($list as $c) {
		if (!is_array($c)) continue;
		$rows[] = [
			'comment' => $c,
			'post_title' => $postTitle,
			'post_slug' => $postSlug,
			'depth' => $depth,
		];
		if (!empty($c['replies']) && is_array($c['replies'])) {
			$rows = array_merge($rows, $collectComments($c['replies'], $postTitle, $postSlug, $depth + 1));
		}
	}
	return $rows;
};

$modRows = [];
$modArchived = (isset($postsArchived) && is_array($postsArchived)) ? $postsArchived : [];
$modAllPosts = array_merge($posts, $modArchived);
foreach ($modAllPosts as $p) {
	if (!is_array($p)) continue;
	$pTitle = trim((string) ($p['title'] ?? 'Tanpa judul'));
	$pSlug = trim((string) ($p['slug'] ?? ''));
	$pComments = !empty($p['comments']) && is_array($p['comments']) ? $p['comments'] : [];
	if (empty($pComments)) continue;
	$modRows = array_merge($modRows, $collectComments($pComments, $pTitle, $pSlug));
}

$modCounts = ['approved' => 0, 'suspended' => 0, 'edited' => 0];
foreach ($modRows as $row) {
	$st = comment_status($row['comment']);
	if (isset($modCounts[$st])) $modCounts[$st]++;
	if (comment_is_edited_by_admin($row['comment'])) $modCounts['edited']++;
}

usort($modRows, function ($a, $b) {
	return strcmp((string) ($b['comment']['created_at'] ?? ''), (string) ($a['comment']['created_at'] ?? ''));
});
?>
<div class="section" style="margin-top:10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Moderasi Komentar</h2>
	<p class="muted-text" style="margin:0 0 10px;">
		<?php echo (int) count($modRows); ?> komentar ·
		<?php echo (int) $modCounts['approved']; ?> tampil ·
		<strong style="color:#f87171;"><?php echo (int) $modCounts['suspended']; ?> disuspend</strong> ·
		<?php echo (int) $modCounts['edited']; ?> pernah diedit admin
	</p>
	<?php if ($modNormalizeFailed): ?>
	<p style="margin:0 0 10px; color:#f87171; font-size:13px;">Sebagian komentar lama gagal dinormalisasi. Muat ulang halaman; jika masih gagal, periksa koneksi penyimpanan.</p>
	<?php endif; ?>
	<p class="muted-text" style="margin:0 0 10px;">Suspend komentar bermasalah agar tidak terlihat pengunjung. Komentar yang diedit admin akan menampilkan badge "Diedit oleh <?php echo htmlspecialchars($modAdminPenName, ENT_QUOTES, 'UTF-8'); ?>" di blog.</p>
	<?php if (!empty($modRows)): ?>
	<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
		<input type="search" id="comment_mod_search" placeholder="Cari nama, isi komentar, atau judul postingan..." aria-label="Cari komentar" autocomplete="off" style="flex:1 1 260px; min-width:200px; padding:9px 11px; border-radius:9px; border:1px solid rgba(148,163,184,0.3); background:rgba(15,23,42,0.4); color:var(--text);">
		<select id="comment_mod_status" aria-label="Filter status komentar" style="padding:9px 11px; border-radius:9px; border:1px solid rgba(148,163,184,0.3); background:rgba(15,23,42,0.4); color:var(--text);">
			<option value="">Semua status</option>
			<option value="approved">Tampil</option>
			<option value="suspended">Disuspend</option>
		</select>
	</div>
	<p class="muted-text" id="comment_mod_info" style="margin:0 0 6px;"></p>
	<ul class="logs" id="comment_mod_list" style="margin-top:0; max-height:560px; overflow:auto;">
		<?php foreach ($modRows as $modRowIdx => $row): ?>
			<?php
			$c = $row['comment'];
			$st = comment_status($c);
			$cId = (string) ($c['id'] ?? '');
			$editKey = 'comment-edit-' . substr(sha1($row['post_slug'] . '|' . $cId . '|' . $modRowIdx), 0, 12);
			$bodyText = trim((string) ($c['body'] ?? ''));
			if ($bodyText === '') {
				if (!empty($c['sticker'])) $bodyText = '[stiker]';
				elseif (!empty($c['embed'])) $bodyText = '[embed]';
			}
			$isSuspended = $st === 'suspended';
			$isEdited = comment_is_edited_by_admin($c);
			$rowSticker = comment_sticker_key($c['sticker'] ?? '');
			$rowEmbed = (string) ($c['embed'] ?? '');
			$blobRaw = $row['post_title'] . ' ' . (string) ($c['name'] ?? '') . ' ' . $bodyText . ' ' . $rowSticker . ' ' . $rowEmbed;
			$blob = function_exists('mb_strtolower') ? mb_strtolower($blobRaw, 'UTF-8') : strtolower($blobRaw);
			?>
			<li class="comment-mod-item" data-status="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($blob, ENT_QUOTES, 'UTF-8'); ?>" style="padding:12px; <?php echo $isSuspended ? 'background:rgba(248,113,113,0.06);' : ''; ?>">
				<div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start;">
					<div style="min-width:0; flex:1 1 320px;">
						<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
							<strong><?php echo htmlspecialchars((string) ($c['name'] ?? '(tanpa nama)'), ENT_QUOTES, 'UTF-8'); ?></strong>
							<?php if ($isSuspended): ?>
								<span style="font-size:11px; padding:1px 8px; border-radius:999px; border:1px solid #f87171; color:#f87171;">Disuspend</span>
							<?php endif; ?>
							<?php if ($isEdited): ?>
								<span title="<?php echo htmlspecialchars((string) ($c['edited_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="font-size:11px; padding:1px 8px; border-radius:999px; border:1px solid #38bdf8; color:#38bdf8;">Diedit oleh <?php echo htmlspecialchars($modAdminPenName, ENT_QUOTES, 'UTF-8'); ?></span>
							<?php endif; ?>
							<?php if (!empty($row['depth'])): ?><span class="muted-text" style="font-size:11px;">balasan level <?php echo (int) $row['depth']; ?></span><?php endif; ?>
						</div>
						<div class="muted-text" style="margin-top:2px; font-size:12px;">
							<?php echo htmlspecialchars((string) ($c['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>
							· pada <?php if ($row['post_slug'] !== ''): ?><a href="/blog/<?php echo rawurlencode($row['post_slug']); ?>" target="_blank" rel="noopener" style="color:var(--accent);"><?php echo htmlspecialchars($row['post_title'], ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><?php echo htmlspecialchars($row['post_title'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
						</div>
						<p style="margin:6px 0 0; color:var(--text); font-size:13px; line-height:1.5; word-break:break-word;"><?php echo nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')); ?></p>
						<?php if ($rowSticker !== '' || $rowEmbed !== ''): ?>
						<div style="margin-top:8px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start;">
							<?php if ($rowSticker !== ''): ?>
							<div>
								<div class="muted-text" style="font-size:11px; margin-bottom:2px;">Stiker</div>
								<img src="<?php echo htmlspecialchars(s3_to_relative_url(s3_photo_url($rowSticker)), ENT_QUOTES, 'UTF-8'); ?>" alt="Stiker komentar" style="display:block; max-width:96px; max-height:96px; border-radius:8px; border:1px solid rgba(148,163,184,0.3);">
							</div>
							<?php endif; ?>
							<?php if ($rowEmbed !== ''): ?>
							<div style="min-width:0; flex:1 1 220px;">
								<div class="muted-text" style="font-size:11px; margin-bottom:2px;">Embed HTML</div>
								<pre style="margin:0; max-height:120px; overflow:auto; padding:6px 8px; border-radius:8px; border:1px solid rgba(148,163,184,0.25); background:rgba(15,23,42,0.45); font-size:11px; white-space:pre-wrap; word-break:break-word;"><?php echo htmlspecialchars($rowEmbed, ENT_QUOTES, 'UTF-8'); ?></pre>
							</div>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</div>
					<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
						<?php if ($isSuspended): ?>
						<form method="post" style="margin:0;"><?php echo ukmi_csrf_field(); ?>
							<input type="hidden" name="comment_moderate" value="1">
							<input type="hidden" name="comment_csrf" value="<?php echo htmlspecialchars($modCsrf, ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($row['post_slug'], ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_id" value="<?php echo htmlspecialchars($cId, ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_action" value="restore">
							<button type="submit" style="min-width:86px; background:linear-gradient(135deg,#4ade80,#22c55e); color:#052e16; box-shadow:none; border:none;">Pulihkan</button>
						</form>
						<?php else: ?>
						<form method="post" style="margin:0;"><?php echo ukmi_csrf_field(); ?>
							<input type="hidden" name="comment_moderate" value="1">
							<input type="hidden" name="comment_csrf" value="<?php echo htmlspecialchars($modCsrf, ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($row['post_slug'], ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_id" value="<?php echo htmlspecialchars($cId, ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_action" value="suspend">
							<button type="submit" style="min-width:86px; background:rgba(248,113,113,0.15); color:#f87171; box-shadow:none; border:1px solid rgba(248,113,113,0.35);">Suspend</button>
						</form>
						<?php endif; ?>
						<button type="button" class="comment-mod-edit-toggle btn-secondary" data-target="<?php echo htmlspecialchars($editKey, ENT_QUOTES, 'UTF-8'); ?>" style="min-width:74px;">Edit</button>
						<form method="post" style="margin:0;" onsubmit="return uiConfirm(event, 'Hapus komentar ini beserta balasannya?');"><?php echo ukmi_csrf_field(); ?>
							<input type="hidden" name="comment_moderate" value="1">
							<input type="hidden" name="comment_csrf" value="<?php echo htmlspecialchars($modCsrf, ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($row['post_slug'], ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_id" value="<?php echo htmlspecialchars($cId, ENT_QUOTES, 'UTF-8'); ?>">
							<input type="hidden" name="comment_action" value="delete">
							<button type="submit" class="btn-danger-outline" style="min-width:74px;">Hapus</button>
						</form>
					</div>
				</div>
				<form method="post" id="<?php echo htmlspecialchars($editKey, ENT_QUOTES, 'UTF-8'); ?>" data-initial-sticker="<?php echo htmlspecialchars($rowSticker, ENT_QUOTES, 'UTF-8'); ?>" data-initial-preview="<?php echo $rowSticker !== '' ? htmlspecialchars(s3_to_relative_url(s3_photo_url($rowSticker)), ENT_QUOTES, 'UTF-8') : ''; ?>" style="display:none; margin-top:10px; border-top:1px dashed rgba(148,163,184,0.25); padding-top:10px;"><?php echo ukmi_csrf_field(); ?>
					<input type="hidden" name="comment_moderate" value="1">
					<input type="hidden" name="comment_csrf" value="<?php echo htmlspecialchars($modCsrf, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="comment_post_slug" value="<?php echo htmlspecialchars($row['post_slug'], ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="comment_id" value="<?php echo htmlspecialchars($cId, ENT_QUOTES, 'UTF-8'); ?>">
					<input type="hidden" name="comment_action" value="edit">
					<label for="<?php echo htmlspecialchars($editKey, ENT_QUOTES, 'UTF-8'); ?>-body" style="font-size:12px;">Isi komentar</label>
					<textarea id="<?php echo htmlspecialchars($editKey, ENT_QUOTES, 'UTF-8'); ?>-body" name="comment_body" rows="3" style="width:100%; margin-top:4px;"><?php echo htmlspecialchars((string) ($c['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
					<div style="display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); margin-top:10px;">
						<div>
							<label style="font-size:12px;">Stiker</label>
							<input type="hidden" name="comment_sticker" class="comment-mod-sticker-key" value="<?php echo htmlspecialchars($rowSticker, ENT_QUOTES, 'UTF-8'); ?>">
							<div class="comment-mod-sticker-preview" style="margin-top:4px;<?php echo $rowSticker !== '' ? '' : 'display:none;'; ?>">
								<img src="<?php echo $rowSticker !== '' ? htmlspecialchars(s3_to_relative_url(s3_photo_url($rowSticker)), ENT_QUOTES, 'UTF-8') : ''; ?>" alt="Pratinjau stiker" style="display:block; max-width:120px; max-height:120px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background:rgba(15,23,42,0.3);">
							</div>
							<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:6px;">
								<label class="comment-mod-sticker-btn" style="margin:0; cursor:pointer; display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background:rgba(15,23,42,0.45); color:var(--text); font-size:12px;">
									<input type="file" class="comment-mod-sticker-file" accept="image/gif,image/png,image/webp,image/jpeg" style="display:none;">
									Pilih / unggah stiker
								</label>
								<?php if ($rowSticker !== ''): ?>
								<label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--muted);">
									<input type="checkbox" name="comment_sticker_clear" value="1"> Hapus stiker
								</label>
								<?php endif; ?>
							</div>
							<div class="muted-text comment-mod-sticker-status" style="font-size:11px; margin-top:4px;">Maksimal 2 MB (GIF/PNG/WebP/JPEG).</div>
						</div>
						<div>
							<label style="font-size:12px;">Embed HTML</label>
							<textarea name="comment_embed" rows="4" placeholder="&lt;iframe src=&quot;https://...&quot;&gt;&lt;/iframe&gt;" style="width:100%; margin-top:4px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12px;"><?php echo htmlspecialchars($rowEmbed, ENT_QUOTES, 'UTF-8'); ?></textarea>
							<?php if ($rowEmbed !== ''): ?>
							<label style="display:inline-flex; align-items:center; gap:6px; margin-top:4px; font-size:12px; color:var(--muted);">
								<input type="checkbox" name="comment_embed_clear" value="1"> Hapus embed
							</label>
							<?php endif; ?>
						</div>
					</div>
					<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:6px;">
						<button type="submit" style="min-width:120px;">Simpan edit</button>
						<button type="button" class="comment-mod-edit-cancel btn-secondary" data-target="<?php echo htmlspecialchars($editKey, ENT_QUOTES, 'UTF-8'); ?>" style="min-width:74px;">Batal</button>
						<span class="muted-text" style="font-size:12px;">Menyimpan edit akan menampilkan badge "Diedit oleh <?php echo htmlspecialchars($modAdminPenName, ENT_QUOTES, 'UTF-8'); ?>" pada komentar.</span>
					</div>
				</form>
			</li>
		<?php endforeach; ?>
	</ul>
	<script>
	(function () {
		var search = document.getElementById('comment_mod_search');
		var status = document.getElementById('comment_mod_status');
		var info = document.getElementById('comment_mod_info');
		var items = Array.prototype.slice.call(document.querySelectorAll('.comment-mod-item'));
		function apply() {
			var q = (search && search.value || '').toLocaleLowerCase().trim();
			var st = status ? status.value : '';
			var shown = 0;
			items.forEach(function (el) {
				var hay = (el.getAttribute('data-search') || '').toLocaleLowerCase();
				var ok = (!q || hay.indexOf(q) >= 0) && (!st || el.getAttribute('data-status') === st);
				el.style.display = ok ? '' : 'none';
				if (ok) shown++;
			});
			if (info) info.textContent = 'Menampilkan ' + shown + ' dari ' + items.length + ' komentar.';
		}
		if (search) search.addEventListener('input', apply);
		if (status) status.addEventListener('change', apply);
		document.querySelectorAll('.comment-mod-edit-toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var form = document.getElementById(btn.getAttribute('data-target'));
				if (!form) return;
				var open = form.style.display !== 'none';
				form.style.display = open ? 'none' : '';
				if (!open) {
					var ta = form.querySelector('textarea');
					if (ta) ta.focus();
				}
			});
		});
		document.querySelectorAll('.comment-mod-edit-cancel').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var form = document.getElementById(btn.getAttribute('data-target'));
				if (!form) return;
				form.reset();
				var initKey = form.getAttribute('data-initial-sticker') || '';
				var initUrl = form.getAttribute('data-initial-preview') || '';
				var keyInput = form.querySelector('.comment-mod-sticker-key');
				var preview = form.querySelector('.comment-mod-sticker-preview');
				var img = preview ? preview.querySelector('img') : null;
				var status = form.querySelector('.comment-mod-sticker-status');
				if (keyInput) keyInput.value = initKey;
				if (preview && img) {
					if (initKey && initUrl) {
						img.src = initUrl;
						preview.style.display = '';
					} else {
						img.removeAttribute('src');
						preview.style.display = 'none';
					}
				}
				if (status) status.textContent = 'Maksimal 2 MB (GIF/PNG/WebP/JPEG).';
				form.style.display = 'none';
			});
		});
		document.querySelectorAll('.comment-mod-sticker-file').forEach(function (input) {
			input.addEventListener('change', function () {
				var file = input.files && input.files[0];
				input.value = '';
				if (!file) return;
				var form = input.closest('form');
				if (!form) return;
				var status = form.querySelector('.comment-mod-sticker-status');
				var keyInput = form.querySelector('.comment-mod-sticker-key');
				var preview = form.querySelector('.comment-mod-sticker-preview');
				var img = preview ? preview.querySelector('img') : null;
				var csrf = form.querySelector('input[name="comment_csrf"]');
				var slug = form.querySelector('input[name="comment_post_slug"]');
				if (file.size > 2 * 1024 * 1024) {
					if (status) status.textContent = 'Ukuran stiker maksimal 2 MB.';
					return;
				}
				var fd = new FormData();
				fd.append('action', 'upload_sticker');
				fd.append('comment_csrf', csrf ? csrf.value : '');
				fd.append('comment_post_slug', slug ? slug.value : '');
				fd.append('sticker', file, file.name || 'sticker.gif');
				input.disabled = true;
				if (status) status.textContent = 'Mengunggah stiker...';
				fetch(window.location.pathname + window.location.search, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'fetch' }
				})
					.then(function (res) {
						return res.json().catch(function () { throw new Error('Respons server tidak valid.'); });
					})
					.then(function (data) {
						if (!data || !data.ok) throw new Error((data && data.error) || 'Gagal mengunggah stiker.');
						if (keyInput) keyInput.value = data.key || '';
						if (preview && img && data.url) {
							img.src = data.url;
							preview.style.display = '';
						}
						var clear = form.querySelector('input[name="comment_sticker_clear"]');
						if (clear) clear.checked = false;
						if (status) status.textContent = 'Stiker siap disimpan.';
					})
					.catch(function (err) {
						if (status) status.textContent = (err && err.message) || 'Gagal mengunggah stiker.';
					})
					.finally(function () {
						input.disabled = false;
					});
			});
		});
		apply();
	})();
	</script>
	<?php else: ?>
		<p class="muted-text" style="margin:0;">Belum ada komentar untuk dimoderasi.</p>
	<?php endif; ?>
</div>
