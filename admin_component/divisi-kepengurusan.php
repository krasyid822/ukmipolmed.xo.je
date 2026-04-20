<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['unlock_division_code'])) {
		$code = trim((string) $_POST['unlock_division_code']);
		if ($code === 'superadmin') {
			$divisionUnlocked = true;
			$_SESSION['division_unlocked'] = true;
			$message = 'Mode superadmin aktif untuk divisi.';
		} else {
			$error = 'Kode superadmin salah.';
		}
	}

	if ($loggedIn && isset($_POST['division_name'], $_POST['division_desc'])) {
		if (!$divisionUnlocked) {
			$error = 'Masukkan kode superadmin sebelum mengubah divisi.';
		} else {
			$names = (array) $_POST['division_name'];
			$descs = (array) $_POST['division_desc'];
			$newDivisions = [];
			foreach ($names as $i => $nameVal) {
				$name = trim((string) $nameVal);
				$desc = trim((string) ($descs[$i] ?? ''));
				if ($name === '' && $desc === '') continue;
				$newDivisions[] = [
					'name' => $name ?: 'Divisi',
					'description' => $desc ?: 'Deskripsi divisi.',
				];
			}
			if (empty($newDivisions)) {
				$newDivisions = $divisionDefaults;
			}
			$divisions = $newDivisions;
			$config['divisions'] = $divisions;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal menyimpan divisi.';
			} else {
				$message = 'Divisi diperbarui.';
				appendLog($config, $configPath, 'divisions-updated', $maxLogs, $archiveLimitDays);
			}
		}
	}

	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Divisi kepengurusan <?php echo htmlspecialchars($divisionYearLabel, ENT_QUOTES, 'UTF-8'); ?></h2>
	<form method="post" class="stack-sm">
		<div class="inline-actions" style="align-items:center;">
			<input type="text" name="unlock_division_code" placeholder="Ketik superadmin untuk mengubah" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="off">
			<button type="submit" style="max-width:180px;">Buka</button>
		</div>
	</form>
	<form method="post" class="stack-sm" id="divisions-form" data-autosave-key="divisions" style="margin-top:10px; <?php echo $divisionUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>">
		<div id="division-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
			<?php foreach ($divisions as $idx => $div): ?>
			<div class="division-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Nama divisi</label>
				<input type="text" name="division_name[]" value="<?php echo htmlspecialchars($div['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
				<textarea name="division_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"><?php echo htmlspecialchars($div['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-division" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="inline-actions" style="margin-top: 6px;">
			<button type="button" id="add-division" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah divisi</button>
			<button type="submit">Simpan divisi</button>
		</div>
		<p class="muted-text" style="margin:4px 0 0;">Urutan di sini akan ditampilkan di halaman utama. Tahun kepengurusan otomatis: <?php echo htmlspecialchars($divisionYearLabel, ENT_QUOTES, 'UTF-8'); ?>.</p>
	</form>
</div>
<script>
(function () {
	const divisionList = document.getElementById('division-list');
	const addDivision = document.getElementById('add-division');
	if (!divisionList || !addDivision) return;
	const templateHtml = () => `
		<div class="division-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
			<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Nama divisi</label>
			<input type="text" name="division_name[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
			<textarea name="division_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"></textarea>
			<div style="text-align:right; margin-top:6px;">
				<button type="button" class="remove-division" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
			</div>
		</div>`;
	function attachRemoveHandlers() {
		divisionList.querySelectorAll('.remove-division').forEach((btn) => {
			btn.onclick = () => {
				const item = btn.closest('.division-item');
				if (item && divisionList.children.length > 1) item.remove();
			};
		});
	}
	addDivision.addEventListener('click', () => {
		const wrapper = document.createElement('div');
		wrapper.innerHTML = templateHtml();
		divisionList.appendChild(wrapper.firstElementChild);
		attachRemoveHandlers();
	});
	attachRemoveHandlers();
})();
</script>
