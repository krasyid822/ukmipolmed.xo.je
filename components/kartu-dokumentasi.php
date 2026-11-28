<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['doc_tag'], $_POST['doc_title'], $_POST['doc_desc'], $_POST['doc_url'])) {
		$tags = (array) $_POST['doc_tag'];
		$titles = (array) $_POST['doc_title'];
		$descs = (array) $_POST['doc_desc'];
		$urls = (array) $_POST['doc_url'];
		$thumbs = (array) ($_POST['doc_thumb'] ?? []);

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
			$thumb = trim((string) ($thumbs[$i] ?? ''));
			if ($title === '' && $desc === '' && $url === '') continue;
			$newDocs[] = [
				'tag' => $tag ?: 'Dokumentasi',
				'title' => $title ?: 'Judul dokumentasi',
				'description' => $desc ?: 'Deskripsi dokumentasi.',
				'url' => $url ?: '#',
				'thumb' => $thumb,
			];
			if (count($newDocs) >= $limit) break;
		}

		$docs = array_merge($protected, $newDocs);
		$config['docs'] = $docs;

		$postedSpeedMode = (($_POST['doc_speed_mode'] ?? 'preset') === 'custom') ? 'custom' : 'preset';
		$postedSpeedPreset = in_array($_POST['doc_speed_preset'] ?? 'normal', ['slow', 'normal', 'fast'], true)
			? (string) $_POST['doc_speed_preset']
			: 'normal';
		$postedSpeedCustom = max(10, min(200, (int) ($_POST['doc_speed_custom'] ?? 52)));
		$postedMentoringCount = max(1, min(20, (int) ($_POST['doc_mentoring_count'] ?? 4)));
		$docsSettings = [
			'auto_scroll' => isset($_POST['doc_auto_scroll']),
			'speed_mode' => $postedSpeedMode,
			'speed_preset' => $postedSpeedPreset,
			'speed_custom' => $postedSpeedCustom,
			'mentoring_enabled' => isset($_POST['doc_mentoring_enabled']),
			'mentoring_count' => $postedMentoringCount,
			'mentoring_clickable' => isset($_POST['doc_mentoring_clickable']),
		];
		$config['docs_settings'] = $docsSettings;

		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
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
	<form method="post" class="stack-sm" id="docs-form" data-autosave-key="docs"><?php echo ukmi_csrf_field(); ?>
		<?php
		$dsAutoScroll = !isset($docsSettings) || !empty($docsSettings['auto_scroll']);
		$dsSpeedMode = ($docsSettings['speed_mode'] ?? 'preset') === 'custom' ? 'custom' : 'preset';
		$dsSpeedPreset = in_array($docsSettings['speed_preset'] ?? 'normal', ['slow', 'normal', 'fast'], true) ? $docsSettings['speed_preset'] : 'normal';
		$dsSpeedCustom = max(10, min(200, (int) ($docsSettings['speed_custom'] ?? 52)));
		$dsMentoringEnabled = !empty($docsSettings['mentoring_enabled']);
		$dsMentoringCount = max(1, min(20, (int) ($docsSettings['mentoring_count'] ?? 4)));
		$dsMentoringClickable = !empty($docsSettings['mentoring_clickable']);
		?>
		<div style="border:1px solid rgba(148,163,184,0.25); padding:12px; border-radius:10px; background: rgba(255,255,255,0.02); margin-bottom:10px; display:grid; gap:10px;">
			<div style="font-size:14px; font-weight:600;">Pengaturan gulir &amp; dokumentasi mentoring</div>
			<label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
				<input type="checkbox" name="doc_auto_scroll" value="1" <?php echo $dsAutoScroll ? 'checked' : ''; ?>>
				<span>Aktifkan gulir otomatis horizontal (looping)</span>
			</label>
			<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
				<div>
					<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Mode kecepatan gulir</label>
					<select name="doc_speed_mode" id="doc_speed_mode" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
						<option value="preset" <?php echo $dsSpeedMode === 'preset' ? 'selected' : ''; ?>>Preset (default)</option>
						<option value="custom" <?php echo $dsSpeedMode === 'custom' ? 'selected' : ''; ?>>Input angka (px/detik)</option>
					</select>
				</div>
				<div id="doc-speed-preset-wrap">
					<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Preset kecepatan</label>
					<select name="doc_speed_preset" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
						<option value="slow" <?php echo $dsSpeedPreset === 'slow' ? 'selected' : ''; ?>>Lambat (28 px/s)</option>
						<option value="normal" <?php echo $dsSpeedPreset === 'normal' ? 'selected' : ''; ?>>Sedang (52 px/s)</option>
						<option value="fast" <?php echo $dsSpeedPreset === 'fast' ? 'selected' : ''; ?>>Cepat (85 px/s)</option>
					</select>
				</div>
				<div id="doc-speed-custom-wrap">
					<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Kecepatan kustom (10–200 px/s)</label>
					<input type="number" name="doc_speed_custom" min="10" max="200" value="<?php echo (int) $dsSpeedCustom; ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				</div>
			</div>
			<hr style="border:0; border-top:1px solid rgba(148,163,184,0.18); margin:2px 0;">
			<label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
				<input type="checkbox" name="doc_mentoring_enabled" id="doc_mentoring_enabled" value="1" <?php echo $dsMentoringEnabled ? 'checked' : ''; ?>>
				<span>Tarik foto dokumentasi acak dari projek laporan mentoring</span>
			</label>
			<div id="doc-mentoring-options" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px; align-items:end;">
				<div>
					<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Jumlah foto mentoring (1–20)</label>
					<input type="number" name="doc_mentoring_count" min="1" max="20" value="<?php echo (int) $dsMentoringCount; ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				</div>
				<label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; padding-bottom:8px;">
					<input type="checkbox" name="doc_mentoring_clickable" value="1" <?php echo $dsMentoringClickable ? 'checked' : ''; ?>>
					<span>Izinkan kartu mentoring diklik ke detail laporan (view.php)</span>
				</label>
			</div>
		</div>
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
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Thumbnail (opsional)</label>
				<input type="text" name="doc_thumb[]" value="<?php echo htmlspecialchars($doc['thumb'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Kosongkan untuk otomatis dari link tujuan">
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
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Thumbnail (opsional)</label>
			<input type="text" name="doc_thumb[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Kosongkan untuk otomatis dari link tujuan">
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

	const speedModeEl = document.getElementById('doc_speed_mode');
	const speedPresetWrap = document.getElementById('doc-speed-preset-wrap');
	const speedCustomWrap = document.getElementById('doc-speed-custom-wrap');
	function syncSpeedModeUI() {
		if (!speedModeEl || !speedPresetWrap || !speedCustomWrap) return;
		const isCustom = speedModeEl.value === 'custom';
		speedPresetWrap.style.display = isCustom ? 'none' : '';
		speedCustomWrap.style.display = isCustom ? '' : 'none';
	}
	if (speedModeEl) {
		speedModeEl.addEventListener('change', syncSpeedModeUI);
		syncSpeedModeUI();
	}

	const mentoringToggleEl = document.getElementById('doc_mentoring_enabled');
	const mentoringOptsEl = document.getElementById('doc-mentoring-options');
	function syncMentoringUI() {
		if (!mentoringToggleEl || !mentoringOptsEl) return;
		mentoringOptsEl.style.opacity = mentoringToggleEl.checked ? '1' : '0.5';
		mentoringOptsEl.querySelectorAll('input').forEach((inp) => {
			inp.disabled = !mentoringToggleEl.checked;
		});
	}
	if (mentoringToggleEl) {
		mentoringToggleEl.addEventListener('change', syncMentoringUI);
		syncMentoringUI();
	}
})();
</script>
