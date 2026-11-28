<?php
if (($adminComponentMode ?? '') === 'handle') {
	if ($loggedIn && isset($_POST['unlock_insights_code'])) {
		$code = trim((string) $_POST['unlock_insights_code']);
		if (ukmi_verify_admin_key((string) ($config['key'] ?? ''), $code)) {
			$insightsUnlocked = true;
			$_SESSION['insights_unlocked'] = true;
			$message = 'Mode superadmin aktif untuk reset insight.';
		} else {
			$error = 'Key admin saat ini salah.';
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
			if ($encoded === false || store_save_raw($insightPath, $encoded) === false) {
				$error = 'Gagal mereset insights.json.';
			} else {
				$message = 'insights.json berhasil dikosongkan.';
				appendLog($config, $configPath, 'insights-reset', $maxLogs, $archiveLimitDays);
			}
		}
	}

	return;
}

$insightCache = function_exists('store_cache_actual_file') ? store_cache_actual_file('insights') : '';
$insightSizeBytes = function_exists('store_cache_size') ? store_cache_size('insights') : (($insightCache && is_readable($insightCache)) ? (int) filesize($insightCache) : (file_exists($insightPath) ? (int) filesize($insightPath) : 0));
$insightSizeMB = round($insightSizeBytes / (1024 * 1024), 2);
$storagePool = function_exists('s3_storage_pool') ? s3_storage_pool(false) : null;
$storageRemainingMB = $storagePool ? round((int) $storagePool['remaining_bytes'] / (1024 * 1024), 2) : null;
?>
<div class="section" style="margin-top: 10px;">
	<h2>Penyimpanan insight</h2>
	<p class="muted-text" style="margin:0 0 8px;">Ukuran saat ini: <?php echo htmlspecialchars(number_format($insightSizeMB, 2), ENT_QUOTES, 'UTF-8'); ?> MB. Batas mengikuti sisa kapasitas S3: <?php echo $storageRemainingMB === null ? 'tidak diketahui' : htmlspecialchars(number_format($storageRemainingMB, 2), ENT_QUOTES, 'UTF-8') . ' MB'; ?>.</p>
	<form method="post" class="stack-sm" style="margin-bottom:8px;"><?php echo ukmi_csrf_field(); ?>
		<div class="inline-actions" style="align-items:center; gap:10px;">
			<label for="unlock_insights_code" class="sr-only">Key admin saat ini</label>
			<input type="password" id="unlock_insights_code" name="unlock_insights_code" placeholder="Masukkan key admin saat ini untuk reset" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="current-password">
			<button type="submit" style="max-width:180px;">Buka</button>
		</div>
	</form>
	<form method="post" class="stack-sm" style="<?php echo $insightsUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>" <?php echo $insightsUnlocked ? '' : 'inert'; ?> onsubmit="return uiConfirm(event, 'Kosongkan seluruh data insights.json? Aksi ini tidak dapat dibatalkan.');"><?php echo ukmi_csrf_field(); ?>
		<input type="hidden" name="reset_insights" value="1">
		<p class="muted-text" style="margin:0 0 6px;">Sebaiknya hanya bersihkan jika terjadi pergantian kepengurusan.</p>
		<button type="submit" class="btn-danger">Kosongkan insights.json</button>
	</form>
</div>
