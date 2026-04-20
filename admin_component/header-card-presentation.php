<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && (isset($_POST['save_hero_cards']) || isset($_POST['hero_card_type']) || isset($_POST['hero_card_content']))) {
		$types = (array) ($_POST['hero_card_type'] ?? []);
		$titles = (array) ($_POST['hero_card_title'] ?? []);
		$captions = (array) ($_POST['hero_card_caption'] ?? []);
		$contents = (array) ($_POST['hero_card_content'] ?? []);
		$alts = (array) ($_POST['hero_card_alt'] ?? []);
		$newHeroCards = [];
		$heroLimit = 8;
		foreach ($contents as $i => $contentVal) {
			$type = strtolower(trim((string) ($types[$i] ?? 'image')));
			if (!in_array($type, ['image', 'embed'], true)) {
				$type = 'image';
			}
			$title = trim((string) ($titles[$i] ?? ''));
			$caption = trim((string) ($captions[$i] ?? ''));
			$content = trim((string) $contentVal);
			$alt = trim((string) ($alts[$i] ?? ''));
			if ($title === '' && $caption === '' && $content === '') continue;
			if ($content === '') continue;
			$newHeroCards[] = [
				'type' => $type,
				'title' => $title,
				'caption' => $caption,
				'content' => $content,
				'alt' => $alt,
			];
			if (count($newHeroCards) >= $heroLimit) break;
		}

		$config['hero_cards'] = $newHeroCards;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan header card presentation.';
		} else {
			$heroCards = $newHeroCards;
			$message = 'Header card presentation diperbarui.';
			appendLog($config, $configPath, 'hero-cards-updated', $maxLogs, $archiveLimitDays);
		}
	}
	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Header card presentation</h2>
	<p class="muted-text" style="margin:0 0 10px;">Kartu ini tampil di section hero beranda. Bisa digeser swipe ke kanan/kiri atau dipindah lewat tombol panah.</p>
	<form method="post" class="stack-sm" id="hero-cards-form">
		<div id="hero-card-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:10px;">
			<?php foreach ($heroCards as $heroIdx => $heroCard): ?>
			<div class="hero-card-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label class="hero-card-index" style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Kartu <?php echo $heroIdx + 1; ?></label>
				<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Tipe</label>
				<select name="hero_card_type[]" style="width:40%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
					<option value="image" <?php echo (($heroCard['type'] ?? 'image') === 'image') ? 'selected' : ''; ?>>Image</option>
					<option value="embed" <?php echo (($heroCard['type'] ?? 'image') === 'embed') ? 'selected' : ''; ?>>HTML Embed</option>
				</select>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
				<input type="text" name="hero_card_title[]" value="<?php echo htmlspecialchars($heroCard['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Judul kartu">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Caption</label>
				<textarea name="hero_card_caption[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;" placeholder="Keterangan singkat"><?php echo htmlspecialchars($heroCard['caption'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Content</label>
				<input type="file" class="hero-image-file" accept="image/*" style="margin-top:6px;">
				<textarea name="hero_card_content[]" style="width:100%; min-height:120px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical; font-family: 'SFMono-Regular', Consolas, monospace;" placeholder="URL gambar atau HTML embed"><?php echo htmlspecialchars($heroCard['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Alt text</label>
				<input type="text" name="hero_card_alt[]" value="<?php echo htmlspecialchars($heroCard['alt'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Alt gambar jika tipe image">
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-hero-card" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="inline-actions" style="margin-top: 6px;">
			<button type="button" id="add-hero-card" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah kartu</button>
			<button type="submit" name="save_hero_cards" value="1">Simpan header card presentation</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Jenis Image memakai URL gambar atau pilih file untuk diunggah (auto compress <=1MB). Jenis Embed menerima HTML embed seperti iframe, Instagram embed, atau video player.</p>
	</form>
</div>
<script>
(function () {
	const heroCardList = document.getElementById('hero-card-list');
	const addHeroCard = document.getElementById('add-hero-card');
	if (!heroCardList) return;
	const HERO_CARD_LIMIT = 8;
	const MAX_IMAGE_BYTES = 1000000;

	function dataUrlBytes(dataUrl) {
		const comma = dataUrl.indexOf(',');
		if (comma === -1) return dataUrl.length;
		const base64 = dataUrl.slice(comma + 1);
		return Math.ceil((base64.length * 3) / 4);
	}
	function escapeHtml(value) {
		return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}
	function loadImageFromFile(file) {
		return new Promise((resolve, reject) => {
			const reader = new FileReader();
			const img = new Image();
			reader.onload = () => {
				img.onload = () => resolve(img);
				img.onerror = () => reject(new Error('Gagal memuat gambar'));
				img.src = reader.result;
			};
			reader.onerror = () => reject(new Error('Gagal membaca file'));
			reader.readAsDataURL(file);
		});
	}
	async function compressImage(file) {
		const img = await loadImageFromFile(file);
		let width = img.naturalWidth || img.width;
		let height = img.naturalHeight || img.height;
		const canvas = document.createElement('canvas');
		const ctx = canvas.getContext('2d');
		const maxDim = 1400;
		if (width > maxDim || height > maxDim) {
			const scale = Math.min(maxDim / width, maxDim / height);
			width = Math.max(1, Math.round(width * scale));
			height = Math.max(1, Math.round(height * scale));
		}
		let quality = 0.9;
		let dataUrl = '';
		for (let i = 0; i < 6; i++) {
			canvas.width = width;
			canvas.height = height;
			ctx.clearRect(0, 0, width, height);
			ctx.drawImage(img, 0, 0, width, height);
			dataUrl = canvas.toDataURL('image/jpeg', quality);
			if (dataUrlBytes(dataUrl) <= MAX_IMAGE_BYTES) return dataUrl;
			width = Math.max(1, Math.round(width * 0.9));
			height = Math.max(1, Math.round(height * 0.9));
			quality = quality * 0.82;
		}
		return dataUrl;
	}

	function heroCardTemplate(type = 'image', title = '', caption = '', content = '', alt = '') {
		return `
			<div class="hero-card-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label class="hero-card-index" style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Kartu</label>
				<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Tipe</label>
				<select name="hero_card_type[]" style="width:30%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
					<option value="image" ${type === 'image' ? 'selected' : ''}>Image</option>
					<option value="embed" ${type === 'embed' ? 'selected' : ''}>HTML Embed</option>
				</select>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
				<input type="text" name="hero_card_title[]" value="${escapeHtml(title)}" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Judul kartu">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Caption</label>
				<textarea name="hero_card_caption[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;" placeholder="Keterangan singkat">${escapeHtml(caption)}</textarea>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Content</label>
				<input type="file" class="hero-image-file" accept="image/*" style="margin-top:6px;">
				<textarea name="hero_card_content[]" style="width:100%; min-height:120px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical; font-family: 'SFMono-Regular', Consolas, monospace;" placeholder="URL gambar atau HTML embed">${escapeHtml(content)}</textarea>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Alt text</label>
				<input type="text" name="hero_card_alt[]" value="${escapeHtml(alt)}" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="Alt gambar jika tipe image">
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-hero-card" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>`;
	}
	function renumberHeroCards() {
		Array.from(heroCardList.querySelectorAll('.hero-card-index')).forEach((label, idx) => {
			label.textContent = `Kartu ${idx + 1}`;
		});
	}
	function updateHeroCardButtons() {
		if (!addHeroCard) return;
		addHeroCard.disabled = heroCardList.children.length >= HERO_CARD_LIMIT;
		addHeroCard.title = addHeroCard.disabled ? 'Maksimal 8 kartu' : '';
	}
	function updateHeroFileVisibilityForItem(item) {
		const sel = item?.querySelector('select[name="hero_card_type[]"]');
		const file = item?.querySelector('.hero-image-file');
		if (!file) return;
		file.style.display = (sel && sel.value === 'embed') ? 'none' : '';
	}
	function attachHeroTypeChangeHandlers() {
		heroCardList.querySelectorAll('select[name="hero_card_type[]"]').forEach((sel) => {
			sel.onchange = () => updateHeroFileVisibilityForItem(sel.closest('.hero-card-item'));
		});
		heroCardList.querySelectorAll('.hero-card-item').forEach((item) => updateHeroFileVisibilityForItem(item));
	}
	function attachHeroImageFileHandlers() {
		heroCardList.querySelectorAll('.hero-image-file').forEach((input) => {
			input.onchange = async (e) => {
				const file = e?.target?.files?.[0];
				if (!file) return;
				if (!file.type || !file.type.startsWith('image/')) {
					alert('File harus berupa gambar.');
					e.target.value = '';
					return;
				}
				try {
					const dataUrl = await compressImage(file);
					if (dataUrlBytes(dataUrl) > MAX_IMAGE_BYTES) {
						alert('Gambar masih di atas 1MB setelah kompres. Pilih file yang lebih kecil.');
					} else {
						const card = input.closest('.hero-card-item');
						const textarea = card?.querySelector('textarea[name="hero_card_content[]"]');
						if (textarea) textarea.value = dataUrl;
					}
				} catch (_) {
					alert('Gagal memproses gambar.');
				}
				e.target.value = '';
			};
		});
	}
	function attachHeroCardRemoveHandlers() {
		heroCardList.querySelectorAll('.remove-hero-card').forEach((btn) => {
			btn.onclick = () => {
				btn.closest('.hero-card-item')?.remove();
				renumberHeroCards();
				updateHeroCardButtons();
			};
		});
	}
	addHeroCard?.addEventListener('click', () => {
		if (heroCardList.children.length >= HERO_CARD_LIMIT) return;
		const wrapper = document.createElement('div');
		wrapper.innerHTML = heroCardTemplate();
		heroCardList.appendChild(wrapper.firstElementChild);
		renumberHeroCards();
		attachHeroCardRemoveHandlers();
		attachHeroImageFileHandlers();
		attachHeroTypeChangeHandlers();
		updateHeroCardButtons();
	});

	attachHeroCardRemoveHandlers();
	renumberHeroCards();
	attachHeroImageFileHandlers();
	attachHeroTypeChangeHandlers();
	updateHeroCardButtons();
})();
</script>
