<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['unlock_credential_code'])) {
		$code = trim((string) $_POST['unlock_credential_code']);
		if (ukmi_verify_admin_key((string) ($config['key'] ?? ''), $code)) {
			$credentialUnlocked = true;
			$_SESSION['credential_unlocked'] = true;
			$message = 'Mode superadmin aktif untuk kredensial admin.';
		} else {
			$error = 'Key admin saat ini salah.';
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
			$config['key'] = password_hash($newKey, PASSWORD_DEFAULT);
			$sessionVersion++;
			$config['session_version'] = $sessionVersion;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
				$error = 'Gagal menyimpan user dan key baru.';
			} else {
				appendLog($config, $configPath, 'auth-updated', $maxLogs, $archiveLimitDays);
				session_destroy();
				$_SESSION = [];
				header('Location: /admin?msg=cred-updated');
				exit;
			}
		}
	}

	if ($loggedIn && isset($_POST['save_yel_yel'])) {
		$config['yel_yel'] = trim((string) ($_POST['yel_yel'] ?? ''));
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
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
				'features' => array_values(array_merge(
					isset($_POST['feat_assessment']) ? ['assessment'] : [],
					isset($_POST['feat_embed']) ? ['embed'] : []
				)),
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

	if ($loggedIn && isset($_POST['edit_author'])) {
		$eid = trim((string) $_POST['edit_author']);
		$nu = trim((string) ($_POST['edit_username'] ?? ''));
		$d = authors_load();
		$idx = -1;
		foreach ($d['authors'] as $i => $a) {
			if ((string) ($a['id'] ?? '') === $eid) { $idx = $i; break; }
		}
		if ($nu === '') {
			$error = 'Username wajib diisi.';
		} elseif ($idx < 0) {
			$error = 'Akun tidak ditemukan.';
		} else {
			$dup = false;
			foreach ($d['authors'] as $j => $b) {
				if ($j !== $idx && strcasecmp((string) ($b['username'] ?? ''), $nu) === 0) { $dup = true; break; }
			}
			if ($dup) {
				$error = 'Username sudah dipakai.';
			} else {
				$d['authors'][$idx]['username'] = $nu;
				$d['authors'][$idx]['pen_name'] = trim((string) ($_POST['edit_pen_name'] ?? '')) ?: $nu;
				$np = (string) ($_POST['edit_password'] ?? '');
				if ($np !== '') { $d['authors'][$idx]['password_hash'] = password_hash($np, PASSWORD_DEFAULT); }
				$d['authors'][$idx]['features'] = array_values(array_merge(
					isset($_POST['edit_feat_assessment']) ? ['assessment'] : [],
					isset($_POST['edit_feat_embed']) ? ['embed'] : []
				));
				if (authors_save($d)) {
					appendLog($config, $configPath, 'author-updated', $maxLogs, $archiveLimitDays);
					$message = 'Akun penulis diperbarui.';
				} else {
					$error = 'Gagal menyimpan perubahan akun.';
				}
			}
		}
	}

	if ($loggedIn && isset($_POST['save_mentoring_account'])) {
		$scope = strtolower(trim((string) ($_POST['mentoring_scope'] ?? '')));
		$uname = trim((string) ($_POST['mentoring_username'] ?? ''));
		$pass = (string) ($_POST['mentoring_password'] ?? '');
		$accounts = is_array($config['mentoring_accounts'] ?? null) ? $config['mentoring_accounts'] : [];
		$otherScope = $scope === 'ikhwan' ? 'akhwat' : 'ikhwan';
		$existing = is_array($accounts[$scope] ?? null) ? $accounts[$scope] : [];

		if (!in_array($scope, ['ikhwan', 'akhwat'], true)) {
			$error = 'Scope akun mentoring tidak valid.';
		} elseif ($uname === '') {
			$error = 'Username akun mentoring wajib diisi.';
		} elseif (strcasecmp($uname, (string) ($config['user'] ?? '')) === 0) {
			$error = 'Username tidak boleh sama dengan username admin.';
		} elseif (!empty($accounts[$otherScope]['username']) && strcasecmp($uname, (string) $accounts[$otherScope]['username']) === 0) {
			$error = 'Username sudah dipakai oleh akun mentoring ' . $otherScope . '.';
		} elseif (authors_find_by_username($uname)) {
			$error = 'Username sudah dipakai oleh akun penulis.';
		} elseif ($pass === '' && empty($existing['password_hash'])) {
			$error = 'Password wajib diisi saat membuat akun mentoring baru.';
		} else {
			$hash = $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : (string) $existing['password_hash'];
			$accounts[$scope] = [
				'username' => $uname,
				'password_hash' => $hash,
				'updated_at' => date('c'),
			];
			$config['mentoring_accounts'] = $accounts;
			if (!store_save_json($configPath, $config)) {
				$error = 'Gagal menyimpan kredensial mentoring ' . $scope . '.';
			} else {
				appendLog($config, $configPath, 'mentoring-cred-updated-' . $scope, $maxLogs, $archiveLimitDays);
				$message = 'Kredensial akses mentoring ' . ucfirst($scope) . ' disimpan.';
			}
		}
	}

	if ($loggedIn && isset($_POST['delete_mentoring_account'])) {
		$scope = strtolower(trim((string) ($_POST['mentoring_scope'] ?? '')));
		$accounts = is_array($config['mentoring_accounts'] ?? null) ? $config['mentoring_accounts'] : [];
		if (in_array($scope, ['ikhwan', 'akhwat'], true) && isset($accounts[$scope])) {
			unset($accounts[$scope]);
			$config['mentoring_accounts'] = $accounts;
			if (!store_save_json($configPath, $config)) {
				$error = 'Gagal menghapus kredensial mentoring ' . $scope . '.';
			} else {
				appendLog($config, $configPath, 'mentoring-cred-deleted-' . $scope, $maxLogs, $archiveLimitDays);
				$message = 'Kredensial akses mentoring ' . ucfirst($scope) . ' dihapus.';
			}
		} else {
			$error = 'Akun mentoring tidak ditemukan.';
		}
	}

	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Kredensial admin</h2>
	<form method="post" class="stack-sm" style="margin-bottom:10px;"><?php echo ukmi_csrf_field(); ?>
		<div class="inline-actions" style="align-items:center; gap:10px;">
			<input type="password" name="unlock_credential_code" placeholder="Masukkan key admin saat ini untuk mengubah" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="current-password">
			<button type="submit" style="max-width:180px;">Buka</button>
		</div>
	</form>
	<form method="post" class="stack-sm" style="<?php echo $credentialUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?><?php echo ukmi_csrf_field(); ?>">
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
	<?php $hasRecoveryKey = function_exists('admin_recovery_key') && admin_recovery_key($defaultData ?? []) !== ''; ?>
	<p class="muted-text" style="margin:10px 0 0; font-size:12px;">
		Pemulihan darurat: <?php echo $hasRecoveryKey ? '<span style="color:#38bdf8;">Master Key aktif di <code>default.json</code></span> (telah digunakan <strong>' . (int) ($recoveryCount ?? 0) . ' kali</strong>; dapat dipakai di halaman login &rarr; <em>Lupa kredensial / Pemulihan darurat?</em> untuk reset ke <code>default.json</code>).' : '<span style="color:#f87171;">Master Key belum diatur</span> (tambahkan <code>recovery_key</code> di <code>default.json</code>).'; ?>
	</p>
</div>

<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Yel-yel UKMI (bio admin)</h2>
	<form method="post" class="stack-sm"><?php echo ukmi_csrf_field(); ?>
		<input type="hidden" name="save_yel_yel" value="1">
		<textarea name="yel_yel" rows="3" placeholder="Yel-yel / tagline UKMI POLMED" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);"><?php echo htmlspecialchars((string) ($config['yel_yel'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
		<div class="inline-actions" style="margin-top:6px; align-items:center;">
			<button type="submit">Simpan yel-yel</button>
			<p class="muted-text" style="margin:0;">Dipakai sebagai bio pada profil admin di artikel blog.</p>
		</div>
	</form>
</div>

<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Akun penulis blog</h2>
	<form method="post" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;"><?php echo ukmi_csrf_field(); ?>
		<input type="hidden" name="create_author" value="1">
		<div><label for="author_username">Username</label><input type="text" id="author_username" name="author_username" required placeholder="penulis1" autocomplete="off"></div>
		<div><label for="author_password">Password</label><input type="text" id="author_password" name="author_password" required placeholder="kata sandi" autocomplete="off"></div>
		<div><label for="author_pen_name">Nama pena</label><input type="text" id="author_pen_name" name="author_pen_name" placeholder="Nama Pena"></div>
		<div style="grid-column:1/-1; display:flex; gap:16px; flex-wrap:wrap;">
			<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" name="feat_assessment" value="1"> Asesmen Excel</label>
			<label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" name="feat_embed" value="1"> Embed Master (GDrive folder + perbaikan IG)</label>
		</div>
		<div><button type="submit">Buat akun</button></div>
	</form>
	<?php $authList = authors_load()['authors'] ?? []; ?>
	<?php if (empty($authList)): ?>
		<p class="muted-text" style="margin:10px 0 0;">Belum ada akun penulis.</p>
	<?php else: ?>
		<ul style="margin:10px 0 0; padding:0; list-style:none;">
			<?php foreach ($authList as $au): ?>
			<li style="padding:8px 0; border-top:1px solid rgba(148,163,184,0.15);">
				<div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
					<span><?php echo htmlspecialchars((string) (($au['pen_name'] ?? '') !== '' ? $au['pen_name'] : ($au['username'] ?? '')), ENT_QUOTES, 'UTF-8'); ?> <span class="muted-text">@<?php echo htmlspecialchars((string) ($au['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></span>
					<form method="post" style="margin:0;" onsubmit="return uiConfirm(event, 'Hapus akun penulis ini?');"><?php echo ukmi_csrf_field(); ?>
						<input type="hidden" name="delete_author" value="<?php echo htmlspecialchars((string) ($au['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
						<button type="submit" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus</button>
					</form>
				</div>
				<details style="margin-top:6px;">
					<summary style="cursor:pointer;color:var(--accent);font-size:13px;">Edit</summary>
					<form method="post" class="stack-sm" style="margin-top:8px;"><?php echo ukmi_csrf_field(); ?>
						<input type="hidden" name="edit_author" value="<?php echo htmlspecialchars((string) ($au['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
						<div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px,1fr));">
							<div><label>Username</label><input type="text" name="edit_username" value="<?php echo htmlspecialchars((string) ($au['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></div>
							<div><label>Nama pena</label><input type="text" name="edit_pen_name" value="<?php echo htmlspecialchars((string) ($au['pen_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
							<div><label>Password baru (kosongkan = tetap)</label><input type="text" name="edit_password" placeholder="opsional"></div>
						</div>
						<?php $f = is_array($au['features'] ?? null) ? $au['features'] : null; ?>
						<div style="display:flex; gap:16px; flex-wrap:wrap; margin-top:8px;">
							<label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="edit_feat_assessment" value="1" <?php echo ($f === null || in_array('assessment', $f, true)) ? 'checked' : ''; ?>> Asesmen Excel</label>
							<label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="edit_feat_embed" value="1" <?php echo ($f === null || in_array('embed', $f, true)) ? 'checked' : ''; ?>> Embed Master</label>
						</div>
						<div><button type="submit">Simpan perubahan</button></div>
					</form>
				</details>
			</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>

<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Akses Pengelola Mentoring (Ikhwan &amp; Akhwat)</h2>
	<p class="muted-text" style="margin:0 0 12px; font-size:12px;">
		Akun ini login melalui halaman <code>/login</code> yang sama, tetapi hanya dapat mengakses data master mentoring sesuai gendernya dan tidak dapat mengakses panel admin utama, backup, maupun rollback.
	</p>
	<?php
	$mentoringAccounts = is_array($config['mentoring_accounts'] ?? null) ? $config['mentoring_accounts'] : [];
	$mentoringScopes = [
		'ikhwan' => ['label' => 'Akses Mentoring Ikhwan', 'hint' => 'Hanya mengelola mentor, tim, mentee, laporan, dan pendaftar Ikhwan (Laki-laki).'],
		'akhwat' => ['label' => 'Akses Mentoring Akhwat', 'hint' => 'Hanya mengelola mentor, tim, mentee, laporan, dan pendaftar Akhwat (Perempuan).'],
	];
	?>
	<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
		<?php foreach ($mentoringScopes as $mScope => $mMeta): ?>
			<?php
			$mAcc = is_array($mentoringAccounts[$mScope] ?? null) ? $mentoringAccounts[$mScope] : null;
			$mHasAcc = $mAcc !== null && !empty($mAcc['username']) && !empty($mAcc['password_hash']);
			?>
			<div style="padding:12px; border-radius:10px; border:1px solid rgba(148,163,184,0.2); background:rgba(15,23,42,0.35);">
				<div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:6px;">
					<strong style="font-size:13px;"><?php echo htmlspecialchars($mMeta['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
					<span style="font-size:11px; padding:2px 8px; border-radius:999px; background:<?php echo $mHasAcc ? 'rgba(16,185,129,0.18)' : 'rgba(148,163,184,0.15)'; ?>; color:<?php echo $mHasAcc ? '#34d399' : '#94a3b8'; ?>;">
						<?php echo $mHasAcc ? 'Aktif' : 'Belum diatur'; ?>
					</span>
				</div>
				<p class="muted-text" style="margin:0 0 10px; font-size:12px;"><?php echo htmlspecialchars($mMeta['hint'], ENT_QUOTES, 'UTF-8'); ?></p>
				<form method="post" class="stack-sm"><?php echo ukmi_csrf_field(); ?>
					<input type="hidden" name="save_mentoring_account" value="1">
					<input type="hidden" name="mentoring_scope" value="<?php echo htmlspecialchars($mScope, ENT_QUOTES, 'UTF-8'); ?>">
					<div>
						<label>Username</label>
						<input type="text" name="mentoring_username" required autocomplete="off" placeholder="mentoring_<?php echo htmlspecialchars($mScope, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) ($mAcc['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
					</div>
					<div>
						<label>Password <?php echo $mHasAcc ? '(kosongkan jika tetap)' : ''; ?></label>
						<input type="password" name="mentoring_password" <?php echo $mHasAcc ? '' : 'required'; ?> autocomplete="new-password" placeholder="<?php echo $mHasAcc ? '********' : 'kata sandi'; ?>">
					</div>
					<div style="display:flex; gap:8px; align-items:center;">
						<button type="submit"><?php echo $mHasAcc ? 'Perbarui' : 'Aktifkan'; ?></button>
					</div>
				</form>
				<?php if ($mHasAcc): ?>
				<form method="post" style="margin-top:8px;" onsubmit="return uiConfirm(event, 'Nonaktifkan dan hapus akun <?php echo htmlspecialchars($mMeta['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo ukmi_csrf_field(); ?>?');">
					<input type="hidden" name="delete_mentoring_account" value="1">
					<input type="hidden" name="mentoring_scope" value="<?php echo htmlspecialchars($mScope, ENT_QUOTES, 'UTF-8'); ?>">
					<button type="submit" style="background: rgba(239,68,68,0.12); color: #fca5a5; box-shadow: none; border: 1px solid rgba(239,68,68,0.35);">Hapus Akun</button>
				</form>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>
