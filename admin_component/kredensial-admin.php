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
				header('Location: /admin.php?msg=cred-updated');
				exit;
			}
		}
	}

	if ($loggedIn && isset($_POST['save_yel_yel'])) {
		$config['yel_yel'] = trim((string) ($_POST['yel_yel'] ?? ''));
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan yel-yel.';
		} else {
			appendLog($config, $configPath, 'yel-yel-updated', $maxLogs, $archiveLimitDays);
			$message = 'Yel-yel UKMI disimpan.';
		}
	}

	if ($loggedIn && isset($_POST['create_author'])) {
		$uname = trim((string) ($_POST['author_username'] ?? ''));
		$pass = (string) ($_POST['author_password'] ?? '');
		if ($uname === '' || $pass === '') {
			$error = 'Username & password penulis wajib diisi.';
		} elseif (authors_find_by_username($uname)) {
			$error = 'Username sudah dipakai.';
		} else {
			$d = authors_load();
			$d['authors'][] = [
				'id' => bin2hex(random_bytes(6)),
				'username' => $uname,
				'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
				'pen_name' => trim((string) ($_POST['author_pen_name'] ?? '')) ?: $uname,
				'bio' => '',
				'avatar' => '',
				'social' => '',
				'created_at' => date('c'),
			];
			if (authors_save($d)) {
				appendLog($config, $configPath, 'author-created', $maxLogs, $archiveLimitDays);
				$message = 'Akun penulis dibuat.';
			} else {
				$error = 'Gagal menyimpan akun penulis.';
			}
		}
	}

	if ($loggedIn && isset($_POST['delete_author'])) {
		$did = trim((string) $_POST['delete_author']);
		$d = authors_load();
		$found = false;
		$d['authors'] = array_values(array_filter($d['authors'], function ($a) use ($did, &$found) {
			if ((string) ($a['id'] ?? '') === $did) {
				$found = true;
				return false;
			}
			return true;
		}));
		if ($found && authors_save($d)) {
			appendLog($config, $configPath, 'author-deleted', $maxLogs, $archiveLimitDays);
			$message = 'Akun penulis dihapus.';
		} else {
			$error = 'Gagal menghapus / akun tidak ditemukan.';
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

<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Yel-yel UKMI (bio admin)</h2>
	<form method="post" class="stack-sm">
		<input type="hidden" name="save_yel_yel" value="1">
		<textarea name="yel_yel" rows="3" placeholder="Yel-yel / tagline UKMI Polmed" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);"><?php echo htmlspecialchars((string) ($config['yel_yel'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
		<div class="inline-actions" style="margin-top:6px; align-items:center;">
			<button type="submit">Simpan yel-yel</button>
			<p class="muted-text" style="margin:0;">Dipakai sebagai bio pada profil admin di artikel blog.</p>
		</div>
	</form>
</div>

<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Akun penulis blog</h2>
	<form method="post" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
		<input type="hidden" name="create_author" value="1">
		<div><label for="author_username">Username</label><input type="text" id="author_username" name="author_username" required placeholder="penulis1" autocomplete="off"></div>
		<div><label for="author_password">Password</label><input type="text" id="author_password" name="author_password" required placeholder="kata sandi" autocomplete="off"></div>
		<div><label for="author_pen_name">Nama pena</label><input type="text" id="author_pen_name" name="author_pen_name" placeholder="Nama Pena"></div>
		<div><button type="submit">Buat akun</button></div>
	</form>
	<?php $authList = authors_load()['authors'] ?? []; ?>
	<?php if (empty($authList)): ?>
		<p class="muted-text" style="margin:10px 0 0;">Belum ada akun penulis.</p>
	<?php else: ?>
		<ul style="margin:10px 0 0; padding:0; list-style:none;">
			<?php foreach ($authList as $au): ?>
			<li style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-top:1px solid rgba(148,163,184,0.15);">
				<span><?php echo htmlspecialchars((string) (($au['pen_name'] ?? '') !== '' ? $au['pen_name'] : ($au['username'] ?? '')), ENT_QUOTES, 'UTF-8'); ?> <span class="muted-text">@<?php echo htmlspecialchars((string) ($au['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></span>
				<form method="post" style="margin:0;" onsubmit="return confirm('Hapus akun penulis ini?');">
					<input type="hidden" name="delete_author" value="<?php echo htmlspecialchars((string) ($au['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
					<button type="submit" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus</button>
				</form>
			</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
