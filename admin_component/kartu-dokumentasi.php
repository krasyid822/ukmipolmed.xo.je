<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['doc_tag'], $_POST['doc_title'], $_POST['doc_desc'], $_POST['doc_url'])) {
		$tags = (array) $_POST['doc_tag'];
		$titles = (array) $_POST['doc_title'];
		$descs = (array) $_POST['doc_desc'];
		$urls = (array) $_POST['doc_url'];

		$protected = $docDefaults;
		$protectedCount = count($protected);
		$newDocs = [];
		$limit = max(0, 10 - $protectedCount);
		foreach ($titles as $i => $titleVal) {
			if ($i < $protectedCount) continue;
			$title = trim((string) $titleVal);
			$tag = trim((string) ($tags[$i] ?? 'Dokumentasi'));
			$desc = trim((string) ($descs[$i] ?? ''));
			$url = trim((string) ($urls[$i] ?? ''));
			if ($title === '' && $desc === '' && $url === '') continue;
			$newDocs[] = [
				'tag' => $tag ?: 'Dokumentasi',
				'title' => $title ?: 'Judul dokumentasi',
				'description' => $desc ?: 'Deskripsi dokumentasi.',
				'url' => $url ?: '#',
			];
			if (count($newDocs) >= $limit) break;
		}

		$docs = array_merge($protected, $newDocs);
		$config['docs'] = $docs;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan dokumentasi.';
		} else {
			$message = 'Kartu dokumentasi diperbarui.';
			appendLog($config, $configPath, 'docs-updated', $maxLogs, $archiveLimitDays);
		}
	}
	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Kartu dokumentasi kegiatan (maks 10)</h2>
	<form method="post" class="stack-sm" id="docs-form" data-autosave-key="docs">
		<div id="doc-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
			<?php foreach ($docs as $docIdx => $doc): $isProtectedDoc = $docIdx < count($docDefaults); ?>
			<div class="doc-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Tag</label>
				<input type="text" name="doc_tag[]" value="<?php echo htmlspecialchars($doc['tag'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
				<input type="text" name="doc_title[]" value="<?php echo htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
				<textarea name="doc_desc[]" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"><?php echo htmlspecialchars($doc['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Link</label>
				<input type="text" name="doc_url[]" value="<?php echo htmlspecialchars($doc['url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="https://...">
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-doc" <?php echo ($docIdx < count($docDefaults)) ? 'disabled' : ''; ?> style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="inline-actions" style="margin-top: 6px;">
			<button type="button" id="add-doc" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah kartu</button>
			<button type="submit">Simpan dokumentasi</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Maksimal 10 kartu. Jika semua kolom dikosongkan, akan kembali ke default.</p>
	</form>
</div>
<script>
(function () {
	const docList = document.getElementById('doc-list');
	const addDoc = document.getElementById('add-doc');
	if (!docList || !addDoc) return;
	const docTemplate = () => `
		<div class="doc-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
			<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Tag</label>
			<input type="text" name="doc_tag[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
			<input type="text" name="doc_title[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
			<textarea name="doc_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"></textarea>
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Link</label>
			<input type="text" name="doc_url[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="https://...">
			<div style="text-align:right; margin-top:6px;">
				<button type="button" class="remove-doc" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
			</div>
		</div>`;
	function updateDocLimitState() {
		addDoc.disabled = docList.children.length >= 10;
		addDoc.title = addDoc.disabled ? 'Maksimal 10 kartu' : '';
	}
	function attachDocRemove() {
		docList.querySelectorAll('.remove-doc').forEach((btn) => {
			btn.onclick = () => {
				if (btn.disabled) return;
				const item = btn.closest('.doc-item');
				if (item && docList.children.length > 1) {
					item.remove();
					updateDocLimitState();
				}
			};
		});
	}
	addDoc.addEventListener('click', () => {
		if (docList.children.length >= 10) return;
		const wrapper = document.createElement('div');
		wrapper.innerHTML = docTemplate();
		docList.appendChild(wrapper.firstElementChild);
		attachDocRemove();
		updateDocLimitState();
	});
	attachDocRemove();
	updateDocLimitState();
})();
</script>
