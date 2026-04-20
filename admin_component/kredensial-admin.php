<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['unlock_credential_code'])) {
		$code = trim((string) $_POST['unlock_credential_code']);
		if ($code === 'superadmin') {
			$credentialUnlocked = true;
			$_SESSION['credential_unlocked'] = true;
			$message = 'Mode superadmin aktif untuk kredensial admin.';
		} else {
			$error = 'Kode superadmin salah.';
		}
	}

	if ($loggedIn && isset($_POST['update_credentials'])) {
		$newUser = trim((string) ($_POST['new_user'] ?? ''));
		$newKey = (string) ($_POST['new_key'] ?? '');
		$confirm = (string) ($_POST['new_key_confirm'] ?? '');

		if (!$credentialUnlocked) {
			$error = 'Masukkan kode superadmin sebelum mengubah user/key.';
		} elseif ($newUser === '' || $newKey === '' || $confirm === '') {
			$error = 'User, key, dan konfirmasi wajib diisi.';
		} elseif ($newKey !== $confirm) {
			$error = 'Konfirmasi key tidak sama.';
		} else {
			$config['user'] = $newUser;
			$config['key'] = $newKey;
			$sessionVersion++;
			$config['session_version'] = $sessionVersion;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal menyimpan user dan key baru.';
			} else {
				appendLog($config, $configPath, 'auth-updated', $maxLogs, $archiveLimitDays);
				session_destroy();
				$_SESSION = [];
				header('Location: admin.php?msg=cred-updated');
				exit;
			}
		}
	}

	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Kredensial admin</h2>
	<form method="post" class="stack-sm" style="margin-bottom:10px;">
		<div class="inline-actions" style="align-items:center; gap:10px;">
			<input type="text" name="unlock_credential_code" placeholder="Ketik superadmin untuk mengubah" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="off">
			<button type="submit" style="max-width:180px;">Buka</button>
		</div>
	</form>
	<form method="post" class="stack-sm" style="<?php echo $credentialUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>">
		<input type="hidden" name="update_credentials" value="1">
		<div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
			<div>
				<label for="new_user">User baru</label>
				<input type="text" id="new_user" name="new_user" required placeholder="admin" style="min-width:220px;">
			</div>
			<div>
				<label for="new_key">Key baru</label>
				<input type="password" id="new_key" name="new_key" required autocomplete="new-password" placeholder="********" style="min-width:220px;">
			</div>
			<div>
				<label for="new_key_confirm">Konfirmasi key</label>
				<input type="password" id="new_key_confirm" name="new_key_confirm" required autocomplete="new-password" placeholder="ulang key" style="min-width:220px;">
			</div>
		</div>
		<div class="inline-actions" style="margin-top:6px; align-items:center;">
			<button type="submit">Simpan user & key</button>
			<p class="muted-text" style="margin:0;">Menyimpan akan mengganti user/key, memaksa logout semua sesi, dan tercatat di log.</p>
		</div>
	</form>
</div>
