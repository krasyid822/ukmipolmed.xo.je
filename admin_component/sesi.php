<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['logout_all'])) {
		$sessionVersion++;
		$config['session_version'] = $sessionVersion;
		appendLog($config, $configPath, 'logout-all', $maxLogs, $archiveLimitDays);
		session_destroy();
		$_SESSION = [];
		header('Location: admin.php?msg=logout-all');
		exit;
	}
	return;
}
?>
<div class="section" style="margin-top: 8px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Sesi</h2>
	<div class="inline-actions" style="align-items:center; gap:10px;">
		<a class="button-link" href="?logout=1" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148, 163, 184, 0.25);">Keluar</a>
		<form method="post" class="stack-sm" style="margin:0;">
			<input type="hidden" name="logout_all" value="1">
			<button type="submit" style="background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35);">Logout semua sesi</button>
		</form>
	</div>
	<p class="muted-text" style="margin:6px 0 0;">"Keluar" hanya mengakhiri sesi ini. "Logout semua sesi" memaksa semua browser keluar dan dicatat di log.</p>
</div>
