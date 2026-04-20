<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && (isset($_POST['registration_platform']) || isset($_POST['registration_reset']))) {
		$platforms = ['Google Form', 'Website', 'WhatsApp', 'Telegram', 'Typeform', 'Microsoft Forms', 'Notion', 'Custom'];
		if (isset($_POST['registration_reset'])) {
			$registration = $registrationDefaults;
		} else {
			$platform = trim((string) ($_POST['registration_platform'] ?? ''));
			if (!in_array($platform, $platforms, true)) {
				$platform = 'Custom';
			}
			$url = trim((string) ($_POST['registration_url'] ?? ''));
			if ($url === '') {
				$url = $registrationDefaults['url'];
			}
			$registration = [
				'platform' => $platform,
				'url' => $url,
			];
		}

		$config['registration'] = $registration;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan link pendaftaran.';
		} else {
			$message = isset($_POST['registration_reset']) ? 'Link pendaftaran direset ke default.' : 'Link pendaftaran diperbarui.';
			appendLog($config, $configPath, 'registration-updated', $maxLogs, $archiveLimitDays);
		}
	}
	return;
}
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:16px;">Link form pendaftaran</h2>
	<form method="post" class="stack-sm" id="registration-form" data-autosave-key="registration">
		<div class="field-grid">
			<div>
				<label for="registration_platform">Platform</label>
				<select id="registration_platform" name="registration_platform" style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
					<?php
					$platformOptions = ['Google Form', 'Website', 'WhatsApp', 'Telegram', 'Typeform', 'Microsoft Forms', 'Notion', 'Custom'];
					foreach ($platformOptions as $opt):
						$selected = ($registration['platform'] === $opt) ? 'selected' : '';
					?>
					<option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="full">
				<label for="registration_url">Link form</label>
				<input type="text" id="registration_url" name="registration_url" value="<?php echo htmlspecialchars($registration['url'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://forms.gle/..." style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<p class="muted-text" style="margin:4px 0 0;">Link akan dipakai di tombol "Daftar sekarang" di halaman utama. Kosongkan untuk kembali ke default.</p>
			</div>
		</div>
		<div class="inline-actions" style="margin-top: 6px;">
			<button type="submit" name="registration_save" value="1">Simpan link daftar</button>
			<button type="submit" name="registration_reset" value="1" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Reset ke default</button>
			<button type="button" id="registration_fill_template" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tempelkan template</button>
		</div>
	</form>
</div>
<script>
(function () {
	const regPlatform = document.getElementById('registration_platform');
	const regUrl = document.getElementById('registration_url');
	const regFill = document.getElementById('registration_fill_template');
	const regTemplates = {
		'Google Form': 'https://forms.gle/xxxxxxxxxxxxxxxxx',
		'Website': 'https://domainkamu.com/daftar',
		'WhatsApp': 'https://wa.me/62XXXXXXXXXX?text=Halo%2C%20saya%20mau%20daftar%20UKMI',
		'Telegram': 'https://t.me/username_ukmi',
		'Typeform': 'https://yourorg.typeform.com/formname',
		'Microsoft Forms': 'https://forms.office.com/r/XXXXXXXX',
		'Notion': 'https://www.notion.so/your-form-page',
		'Custom': 'https://'
	};
	function applyRegPlaceholder() {
		if (!regPlatform || !regUrl) return;
		const tpl = regTemplates[regPlatform.value] || 'https://';
		regUrl.placeholder = tpl;
	}
	applyRegPlaceholder();
	regPlatform?.addEventListener('change', applyRegPlaceholder);
	regFill?.addEventListener('click', () => {
		if (!regPlatform || !regUrl) return;
		const tpl = regTemplates[regPlatform.value] || '';
		if (tpl) regUrl.value = tpl;
	});
})();
</script>
