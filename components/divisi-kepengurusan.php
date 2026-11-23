<?php
require_once __DIR__ . '/../s3_gc_helper.php';
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['unlock_division_code'])) {
		$code = trim((string) $_POST['unlock_division_code']);
		if (ukmi_verify_admin_key((string) ($config['key'] ?? ''), $code)) {
			$divisionUnlocked = true;
			$_SESSION['division_unlocked'] = true;
			$message = 'Mode superadmin aktif untuk divisi.';
		} else {
			$error = 'Key admin saat ini salah.';
		}
	}

	if ($loggedIn && isset($_POST['division_name'], $_POST['division_desc'])) {
		if (!$divisionUnlocked) {
			$error = 'Masukkan kode superadmin sebelum mengubah divisi.';
		} else {
			$names = (array) $_POST['division_name'];
			$descs = (array) $_POST['division_desc'];
			$avatars = (array) ($_POST['division_avatar'] ?? []);
			$oldRefs = [];
			if (!empty($divisions) && is_array($divisions)) {
				s3_gc_collect_refs($divisions, $oldRefs);
			}
			$newDivisions = [];
			foreach ($names as $i => $nameVal) {
				$name = trim((string) $nameVal);
				$desc = trim((string) ($descs[$i] ?? ''));
				$avatar = trim((string) ($avatars[$i] ?? ''));
				if (stripos($avatar, 'data:') === 0 && function_exists('s3_put_data_uri')) {
					$s3url = s3_put_data_uri($avatar);
					if ($s3url) $avatar = $s3url;
				}
				if (function_exists('s3_to_relative_url')) {
					$avatar = s3_to_relative_url($avatar);
				}
				if ($name === '' && $desc === '') continue;
				$newDivisions[] = [
					'name' => $name ?: 'Divisi',
					'description' => $desc ?: 'Deskripsi divisi.',
					'avatar' => $avatar,
				];
			}
			if (empty($newDivisions)) {
				$newDivisions = $divisionDefaults;
			}
			$divisions = $newDivisions;
			$config['divisions'] = $divisions;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
				$error = 'Gagal menyimpan divisi.';
			} else {
				if (!empty($oldRefs)) s3_gc_prune_keys(array_keys($oldRefs));
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
	<form method="post" class="stack-sm"><?php echo ukmi_csrf_field(); ?>
		<div class="inline-actions" style="align-items:center;">
			<input type="password" name="unlock_division_code" placeholder="Masukkan key admin saat ini untuk mengubah" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="current-password">
			<button type="submit" style="max-width:180px;">Buka</button>
		</div>
	</form>
	<form method="post" class="stack-sm" id="divisions-form" data-autosave-key="divisions" style="margin-top:10px; <?php echo $divisionUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?><?php echo ukmi_csrf_field(); ?>">
		<div id="division-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
			<?php foreach ($divisions as $idx => $div): ?>
			<div class="division-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Nama divisi</label>
				<input type="text" name="division_name[]" value="<?php echo htmlspecialchars($div['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
				<textarea name="division_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"><?php echo htmlspecialchars($div['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
				<?php $divisionAvatar = function_exists('s3_to_relative_url') ? s3_to_relative_url($div['avatar'] ?? '') : trim((string) ($div['avatar'] ?? '')); ?>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Avatar divisi (SVG)</label>
				<input type="hidden" name="division_avatar[]" value="<?php echo htmlspecialchars($divisionAvatar, ENT_QUOTES, 'UTF-8'); ?>">
				<input type="file" class="division-avatar-file" accept=".svg,image/svg+xml" style="margin-bottom:6px;">
				<div class="division-avatar-preview" style="display:<?php echo $divisionAvatar !== '' ? 'flex' : 'none'; ?>; align-items:center; gap:8px;">
					<img src="<?php echo htmlspecialchars($divisionAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="Preview avatar <?php echo htmlspecialchars($div['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:48px; height:48px; border-radius:50%; object-fit:cover; background:rgba(255,255,255,0.08); border:1px solid rgba(148,163,184,0.3);">
					<button type="button" class="clear-division-avatar" style="width:auto; min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus avatar</button>
				</div>
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
			<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Avatar divisi (SVG)</label>
			<input type="hidden" name="division_avatar[]" value="">
			<input type="file" class="division-avatar-file" accept=".svg,image/svg+xml" style="margin-bottom:6px;">
			<div class="division-avatar-preview" style="display:none; align-items:center; gap:8px;">
				<img src="" alt="Preview avatar" style="width:48px; height:48px; border-radius:50%; object-fit:cover; background:rgba(255,255,255,0.08); border:1px solid rgba(148,163,184,0.3);">
				<button type="button" class="clear-division-avatar" style="width:auto; min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus avatar</button>
			</div>
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
	function attachAvatarHandlers() {
		const MAX_SVG_BYTES = 512 * 1024;
		divisionList.querySelectorAll('.division-item').forEach((item) => {
			const fileInput = item.querySelector('.division-avatar-file');
			const hidden = item.querySelector('input[name="division_avatar[]"]');
			const preview = item.querySelector('.division-avatar-preview');
			const previewImg = preview?.querySelector('img');
			const clearBtn = item.querySelector('.clear-division-avatar');
			if (!fileInput || !hidden) return;
			fileInput.onchange = () => {
				const file = fileInput.files && fileInput.files[0];
				if (!file) return;
				const isSvg = file.type === 'image/svg+xml' || /\.svg$/i.test(file.name || '');
				if (!isSvg) {
					uiAlert('Avatar harus berupa file SVG.');
					fileInput.value = '';
					return;
				}
				if (file.size > MAX_SVG_BYTES) {
					uiAlert('Ukuran SVG maksimal 512 KB.');
					fileInput.value = '';
					return;
				}
				const reader = new FileReader();
				reader.onload = () => {
					hidden.value = String(reader.result || '');
					if (previewImg) previewImg.src = hidden.value;
					if (preview) preview.style.display = 'flex';
				};
				reader.onerror = () => uiAlert('Gagal membaca file SVG.');
				reader.readAsDataURL(file);
				fileInput.value = '';
			};
			if (clearBtn) {
				clearBtn.onclick = () => {
					hidden.value = '';
					if (previewImg) previewImg.removeAttribute('src');
					if (preview) preview.style.display = 'none';
				};
			}
		});
	}
	addDivision.addEventListener('click', () => {
		const wrapper = document.createElement('div');
		wrapper.innerHTML = templateHtml();
		divisionList.appendChild(wrapper.firstElementChild);
		attachRemoveHandlers();
		attachAvatarHandlers();
	});
	attachRemoveHandlers();
	attachAvatarHandlers();
})();
</script>
