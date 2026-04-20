<?php
if (($adminComponentMode ?? '') === 'handle') {
	appendLog($config, $configPath, $loggedIn ? 'view-auth' : 'view-guest', $maxLogs, $archiveLimitDays);
	return;
}
?>
<div class="admin-box" style="margin-top:10px;">
	<h2 style="margin:0 0 10px; font-size:16px;">Log akses terbaru</h2>
	<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:center;">
		<label style="font-size:13px; color: var(--muted);">Event
			<select id="log-filter-event" style="margin-left:6px; padding:8px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<option value="">Semua</option>
				<option value="login-success">login-success</option>
				<option value="login-fail">login-fail</option>
				<option value="auth-updated">auth-updated</option>
				<option value="view-auth">view-auth</option>
				<option value="view-guest">view-guest</option>
				<option value="agenda-updated">agenda-updated</option>
				<option value="agenda-deleted">agenda-deleted</option>
				<option value="agenda-restored">agenda-restored</option>
				<option value="agenda-reordered">agenda-reordered</option>
				<option value="registration-updated">registration-updated</option>
				<option value="hero-cards-updated">hero-cards-updated</option>
				<option value="divisions-updated">divisions-updated</option>
				<option value="docs-updated">docs-updated</option>
				<option value="blog-updated">blog-updated</option>
				<option value="blog-deleted">blog-deleted</option>
				<option value="logout-all">logout-all</option>
			</select>
		</label>
		<label style="font-size:13px; color: var(--muted);">Cari
			<input id="log-filter-text" type="text" placeholder="IP/UA/keyword" style="margin-left:6px; padding:8px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); width: 200px;">
		</label>
	</div>
	<?php $recentLogs = array_slice($config['logs'], 0, 10); ?>
	<?php if (empty($recentLogs)): ?>
		<p style="margin:0; color: var(--muted);">Belum ada log akses.</p>
	<?php else: ?>
		<ul class="logs log-list">
			<?php foreach ($recentLogs as $log): ?>
				<li class="log-item" data-event="<?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>" data-text="<?php echo htmlspecialchars(($log['event'] ?? '-') . ' ' . ($log['time'] ?? '-') . ' ' . ($log['ip'] ?? '-') . ' ' . ($log['ua'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>">
					<strong><?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
					<br><?php echo htmlspecialchars($log['time'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
					<br><?php echo htmlspecialchars($log['ip'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
					<br><span style="opacity:0.8;">UA: <?php echo htmlspecialchars($log['ua'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<hr style="border: 0; border-top: 1px solid rgba(148,163,184,0.25); margin: 12px 0;">
	<h3 style="margin:0 0 8px; font-size:15px;">Arsip (>1 hari)</h3>
	<?php
	$archiveDates = array_keys($config['logs_archive']);
	rsort($archiveDates);
	$archiveDates = array_slice($archiveDates, 0, 12);
	?>
	<?php if (empty($archiveDates)): ?>
		<p style="margin:0; color: var(--muted);">Belum ada arsip.</p>
	<?php else: ?>
		<div class="archive-grid">
			<?php foreach ($archiveDates as $idx => $day): $targetId = 'archive-'.$idx; $dayEntries = $config['logs_archive'][$day]; ?>
				<button type="button" class="archive-day" data-target="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>">
					<strong><?php echo htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?></strong><br>
					<small style="color: var(--muted);">Log: <?php echo count($dayEntries); ?></small>
				</button>
				<div class="archive-entries" id="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>">
					<ul class="logs log-list" style="margin-top:6px;">
						<?php foreach ($dayEntries as $log): ?>
							<li class="log-item" data-event="<?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>" data-text="<?php echo htmlspecialchars(($log['event'] ?? '-') . ' ' . ($log['time'] ?? '-') . ' ' . ($log['ip'] ?? '-') . ' ' . ($log['ua'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>">
								<strong><?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
								<br><?php echo htmlspecialchars($log['time'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
								<br><?php echo htmlspecialchars($log['ip'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
								<br><span style="opacity:0.8;">UA: <?php echo htmlspecialchars($log['ua'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<script>
(function () {
	const archiveButtons = document.querySelectorAll('.archive-day');
	archiveButtons.forEach((btn) => {
		btn.addEventListener('click', () => {
			const targetId = btn.getAttribute('data-target');
			const target = document.getElementById(targetId);
			if (!target) return;
			target.classList.toggle('open');
		});
	});

	const filterEvent = document.getElementById('log-filter-event');
	const filterText = document.getElementById('log-filter-text');
	function applyLogFilter() {
		const evt = (filterEvent?.value || '').toLowerCase();
		const q = (filterText?.value || '').toLowerCase();
		document.querySelectorAll('.log-list .log-item').forEach((item) => {
			const itemEvent = (item.getAttribute('data-event') || '').toLowerCase();
			const itemText = (item.getAttribute('data-text') || '').toLowerCase();
			const matchEvent = !evt || itemEvent === evt;
			const matchText = !q || itemText.includes(q);
			item.style.display = (matchEvent && matchText) ? '' : 'none';
		});
	}
	filterEvent?.addEventListener('change', applyLogFilter);
	filterText?.addEventListener('input', applyLogFilter);
	applyLogFilter();
})();
</script>
