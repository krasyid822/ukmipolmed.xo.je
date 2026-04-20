<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['unlock_insights_code'])) {
		$code = trim((string) $_POST['unlock_insights_code']);
		if ($code === 'superadmin') {
			$insightsUnlocked = true;
			$_SESSION['insights_unlocked'] = true;
			$message = 'Mode superadmin aktif untuk reset insight.';
		} else {
			$error = 'Kode superadmin salah.';
		}
	}

	if ($loggedIn && isset($_POST['reset_insights'])) {
		if (!$insightsUnlocked) {
			$error = 'Masukkan kode superadmin sebelum mereset insight.';
		} else {
			$newData = [
				'total' => 0,
				'events' => [],
				'history' => [],
				'updated_at' => date('c'),
			];
			$encoded = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($insightPath, $encoded) === false) {
				$error = 'Gagal mereset insights.json.';
			} else {
				$message = 'insights.json berhasil dikosongkan.';
				appendLog($config, $configPath, 'insights-reset', $maxLogs, $archiveLimitDays);
			}
		}
	}

	return;
}

$insightSizeBytes = file_exists($insightPath) ? (int) filesize($insightPath) : 0;
$insightSizeMB = round($insightSizeBytes / (1024 * 1024), 2);
$insightLimitMB = round($insightMaxBytes / (1024 * 1024), 2);
?>
<div class="section" style="margin-top: 10px;">
	<h2 style="margin:0 0 8px; font-size:15px;">Penyimpanan insight</h2>
	<p class="muted-text" style="margin:0 0 8px;">Ukuran saat ini: <?php echo htmlspecialchars(number_format($insightSizeMB, 2), ENT_QUOTES, 'UTF-8'); ?> MB / <?php echo htmlspecialchars($insightLimitMB, ENT_QUOTES, 'UTF-8'); ?> MB.</p>
	<form method="post" class="stack-sm" style="margin-bottom:8px;">
		<div class="inline-actions" style="align-items:center; gap:10px;">
			<input type="text" name="unlock_insights_code" placeholder="Ketik superadmin untuk reset" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="off">
			<button type="submit" style="max-width:180px;">Buka</button>
		</div>
	</form>
	<form method="post" class="stack-sm" style="<?php echo $insightsUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>">
		<input type="hidden" name="reset_insights" value="1">
		<p class="muted-text" style="margin:0 0 6px;">Sebaiknya hanya bersihkan jika terjadi pergantian kepengurusan.</p>
		<button type="submit" style="background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35);">Kosongkan insights.json</button>
	</form>
</div>
