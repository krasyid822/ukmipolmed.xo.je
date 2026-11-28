<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['logout_all'])) {
		$sessionVersion++;
		$config['session_version'] = $sessionVersion;
		appendLog($config, $configPath, 'logout-all', $maxLogs, $archiveLimitDays);
		session_destroy();
		$_SESSION = [];
		header('Location: /admin?msg=logout-all');
		exit;
	}
	return;
}
?>
<div class="section" style="margin-top: 8px;">
	<h2>Sesi</h2>
	<div class="inline-actions" style="align-items:center; gap:10px;">
		<form method="post" class="stack-sm" style="margin:0;" onsubmit="return uiConfirm(event, 'Logout semua sesi aktif di semua perangkat?');"><?php echo ukmi_csrf_field(); ?>
			<input type="hidden" name="logout_all" value="1">
			<button type="submit" class="btn-danger">Logout semua sesi</button>
		</form>
	</div>
	<p class="muted-text" style="margin:6px 0 0;">Tombol "Keluar" di kanan atas hanya mengakhiri sesi ini. "Logout semua sesi" memaksa semua browser keluar dan dicatat di log.</p>
</div>
