<?php
session_start();

$configPath = __DIR__ . '/admin.json';

$defaults = [
	'agenda' => [
		'tag' => 'Agenda terdekat',
		'title' => 'Pelantikan Ketum 2026-2027',
		'detail' => 'Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI Polmed 2026-2027.'
	],
	'logs' => [],
	'logs_archive' => [],
	'session_version' => 1,
];

if (!is_readable($configPath)) {
	http_response_code(500);
	exit('Admin config missing.');
}

$config = json_decode((string) file_get_contents($configPath), true);

if (!is_array($config) || empty($config['user']) || empty($config['key'])) {
	http_response_code(500);
	exit('Admin config invalid.');
}

if (empty($config['logs']) || !is_array($config['logs'])) {
	$config['logs'] = [];
}

if (empty($config['logs_archive']) || !is_array($config['logs_archive'])) {
	$config['logs_archive'] = [];
}

$sessionVersion = isset($config['session_version']) ? (int) $config['session_version'] : 1;
if ($sessionVersion < 1) {
	$sessionVersion = 1;
	$config['session_version'] = 1;
}

$agenda = $defaults['agenda'];
if (!empty($config['agenda']) && is_array($config['agenda'])) {
	$agenda = array_merge($agenda, array_intersect_key($config['agenda'], $agenda));
}

$maxLogs = 30;
$archiveLimitDays = 60;

function archiveOldLogs(&$config, $maxLogs, $archiveLimitDays)
{
	$logs = is_array($config['logs']) ? $config['logs'] : [];
	$archive = is_array($config['logs_archive']) ? $config['logs_archive'] : [];
	$threshold = time() - 86400; // 1 day
	$kept = [];

	foreach ($logs as $entry) {
		$ts = strtotime($entry['time'] ?? '');
		if ($ts !== false && $ts < $threshold) {
			$day = date('Y-m-d', $ts);
			if (!isset($archive[$day]) || !is_array($archive[$day])) {
				$archive[$day] = [];
			}
			$archive[$day][] = $entry;
		} else {
			$kept[] = $entry;
		}
	}

	krsort($archive);
	if ($archiveLimitDays > 0 && count($archive) > $archiveLimitDays) {
		$archive = array_slice($archive, 0, $archiveLimitDays, true);
	}

	$config['logs'] = array_slice($kept, 0, $maxLogs);
	$config['logs_archive'] = $archive;
}

function appendLog(&$config, $configPath, $event, $maxLogs, $archiveLimitDays)
{
	archiveOldLogs($config, $maxLogs, $archiveLimitDays);

	$entry = [
		'time' => date('c'),
		'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
		'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 140),
		'event' => $event,
	];

	$logs = is_array($config['logs']) ? $config['logs'] : [];
	array_unshift($logs, $entry);
	$config['logs'] = array_slice($logs, 0, $maxLogs);

	$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($encoded !== false) {
		file_put_contents($configPath, $encoded);
	}
}

$error = null;
$message = null;
$flash = $_GET['msg'] ?? null;
if ($flash === 'logout-all') {
	$message = 'Semua sesi admin telah dikeluarkan.';
}

if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: admin.php');
	exit;
}

if (isset($_POST['user'], $_POST['key'])) {
	$user = (string) $_POST['user'];
	$key = (string) $_POST['key'];

	if (hash_equals($config['user'], $user) && hash_equals($config['key'], $key)) {
		appendLog($config, $configPath, 'login-success', $maxLogs, $archiveLimitDays);
		$_SESSION['admin_logged_in'] = true;
		$_SESSION['session_version'] = $sessionVersion;
		header('Location: admin.php');
		exit;
	}

	appendLog($config, $configPath, 'login-fail', $maxLogs, $archiveLimitDays);
	$error = 'User atau key salah.';
}

$loggedIn = !empty($_SESSION['admin_logged_in']) && (($_SESSION['session_version'] ?? 0) === $sessionVersion);

if (!empty($_SESSION['admin_logged_in']) && !$loggedIn) {
	session_destroy();
	$_SESSION = [];
}

if ($loggedIn && isset($_POST['logout_all'])) {
	$sessionVersion++;
	$config['session_version'] = $sessionVersion;
	appendLog($config, $configPath, 'logout-all', $maxLogs, $archiveLimitDays);
	session_destroy();
	$_SESSION = [];
	header('Location: admin.php?msg=logout-all');
	exit;
}

appendLog($config, $configPath, $loggedIn ? 'view-auth' : 'view-guest', $maxLogs, $archiveLimitDays);

if ($loggedIn && isset($_POST['agenda_tag'], $_POST['agenda_title'], $_POST['agenda_detail'])) {
	$newAgenda = [
		'tag' => trim((string) $_POST['agenda_tag']) ?: $defaults['agenda']['tag'],
		'title' => trim((string) $_POST['agenda_title']) ?: $defaults['agenda']['title'],
		'detail' => trim((string) $_POST['agenda_detail']) ?: $defaults['agenda']['detail'],
	];

	$config['agenda'] = $newAgenda;
	$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
		$error = 'Gagal menyimpan data agenda.';
	} else {
		$agenda = $newAgenda;
		$message = 'Agenda berhasil disimpan.';
		appendLog($config, $configPath, 'agenda-updated', $maxLogs, $archiveLimitDays);
	}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Halaman Admin</title>
	<link rel="icon" type="image/png" href="logo-ukmi.png">
	<link rel="apple-touch-icon" href="logo-ukmi.png">
	<link rel="shortcut icon" href="logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<style>
		:root {
			--bg: #0f172a;
			--panel: #111827;
			--accent: #38bdf8;
			--muted: #94a3b8;
			--text: #e2e8f0;
			--danger: #f87171;
		}

		body {
			margin: 0;
			min-height: 100vh;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			background: radial-gradient(circle at 20% 20%, rgba(56, 189, 248, 0.12), transparent 35%),
						radial-gradient(circle at 80% 0%, rgba(248, 113, 113, 0.08), transparent 30%),
						var(--bg);
			color: var(--text);
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
		}

		.card {
			background: linear-gradient(145deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0));
			border: 1px solid rgba(148, 163, 184, 0.2);
			border-radius: 16px;
			width: 100%;
			max-width: 520px;
			padding: 24px;
			box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
			backdrop-filter: blur(10px);
		}

		h1 {
			margin: 0 0 12px;
			font-size: 22px;
			letter-spacing: 0.2px;
		}

		p {
			margin: 0 0 16px;
			color: var(--muted);
			line-height: 1.5;
		}

		form {
			display: flex;
			flex-direction: column;
			gap: 12px;
		}

		label {
			font-size: 14px;
			color: var(--muted);
			display: block;
			margin-bottom: 4px;
		}

		input[type="text"],
		input[type="password"],
		textarea {
			padding: 12px 14px;
			border-radius: 10px;
			border: 1px solid rgba(148, 163, 184, 0.3);
			background: rgba(15, 23, 42, 0.4);
			color: var(--text);
			font-size: 14px;
			transition: border-color 0.2s ease, box-shadow 0.2s ease;
		}

		textarea { min-width: 168px; min-height: 110px; resize: both; font-family: inherit; }

		input[type="text"]:focus,
		input[type="password"]:focus,
		textarea:focus {
			outline: none;
			border-color: var(--accent);
			box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
		}

		.actions {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			margin-top: 4px;
		}

		button,
		a.button-link {
			width: 100%;
			border: none;
			border-radius: 10px;
			padding: 12px 14px;
			background: linear-gradient(135deg, #38bdf8, #0ea5e9);
			color: #0b1727;
			font-weight: 700;
			cursor: pointer;
			text-decoration: none;
			text-align: center;
			transition: transform 0.15s ease, box-shadow 0.15s ease;
			box-shadow: 0 10px 30px rgba(14, 165, 233, 0.35);
		}

		button:hover,
		a.button-link:hover {
			transform: translateY(-1px);
			box-shadow: 0 14px 34px rgba(14, 165, 233, 0.45);
		}

		button:active,
		a.button-link:active {
			transform: translateY(0);
		}

		.error {
			margin-top: 8px;
			padding: 10px 12px;
			border-radius: 10px;
			background: rgba(248, 113, 113, 0.12);
			border: 1px solid rgba(248, 113, 113, 0.25);
			color: var(--danger);
			font-size: 14px;
		}

		.success {
			margin-top: 8px;
			padding: 10px 12px;
			border-radius: 10px;
			background: rgba(56, 189, 248, 0.12);
			border: 1px solid rgba(56, 189, 248, 0.3);
			color: var(--text);
			font-size: 14px;
		}

		.admin-box {
			padding: 18px;
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.03);
			border: 1px dashed rgba(148, 163, 184, 0.3);
			margin-top: 12px;
		}

		.brand-admin {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 10px;
		}

		.brand-admin img {
			width: 36px;
			height: 36px;
			border-radius: 50%;
			object-fit: cover;
			border: 1px solid rgba(148, 163, 184, 0.35);
		}

		.brand-admin .title {
			font-weight: 700;
			letter-spacing: 0.3px;
		}

		.section {
			margin-top: 12px;
			padding: 14px;
			border: 1px solid rgba(148, 163, 184, 0.18);
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.02);
		}

		.field-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 12px;
		}

		.field-grid .full { grid-column: 1 / -1; }

		.inline-actions {
			display: flex;
			gap: 12px;
			flex-wrap: wrap;
		}

		.inline-actions > * { flex: 1; }

		.muted-text { color: var(--muted); font-size: 13px; margin: 4px 0 0; }

		.logs {
			margin-top: 12px;
			padding: 0;
			list-style: none;
			border: 1px solid rgba(148, 163, 184, 0.2);
			border-radius: 10px;
			overflow: hidden;
		}

		.logs li {
			padding: 10px 12px;
			border-bottom: 1px solid rgba(148, 163, 184, 0.15);
			color: var(--muted);
			font-size: 13px;
			line-height: 1.4;
		}

		.logs li strong { color: var(--text); }

		.logs li:last-child { border-bottom: none; }

		.archive-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
			gap: 10px;
			margin-top: 10px;
		}

		.archive-day {
			padding: 10px 12px;
			border-radius: 10px;
			border: 1px solid rgba(148, 163, 184, 0.25);
			background: rgba(255, 255, 255, 0.04);
			color: var(--text);
			cursor: pointer;
			text-align: left;
			transition: border-color 0.2s ease, transform 0.15s ease;
		}

		.archive-day:hover { transform: translateY(-1px); border-color: var(--accent); }

		.archive-entries { display: none; margin-top: 8px; }
		.archive-entries.open { display: block; }

		@media (max-width: 480px) {
			.card { padding: 20px; }
			h1 { font-size: 20px; }
		}
	</style>
</head>
<body>
	<div class="card">
		<div class="brand-admin">
			<img src="logo-ukmi.png" alt="Logo UKMI Polmed">
			<div class="title">UKMI Polmed · Admin</div>
		</div>
		<?php if ($loggedIn): ?>
			<h1>Panel Admin</h1>
			<p>Kelola kartu "Agenda terdekat" yang muncul di halaman utama.</p>
			<?php if ($message): ?>
				<div class="success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<?php if ($error): ?>
				<div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<div class="section">
				<h2 style="margin:0 0 8px; font-size:16px;">Agenda terdekat</h2>
				<form method="post" class="stack-sm">
					<div class="field-grid">
						<div>
							<label for="agenda_tag">Tag</label>
							<input type="text" id="agenda_tag" name="agenda_tag" value="<?php echo htmlspecialchars($agenda['tag'], ENT_QUOTES, 'UTF-8'); ?>" required>
						</div>
						<div>
							<label for="agenda_title">Judul</label>
							<input type="text" id="agenda_title" name="agenda_title" value="<?php echo htmlspecialchars($agenda['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
						</div>
						<div class="full">
							<label for="agenda_detail">Detail</label>
							<textarea id="agenda_detail" name="agenda_detail" required><?php echo htmlspecialchars($agenda['detail'], ENT_QUOTES, 'UTF-8'); ?></textarea>
							<p class="muted-text">Contoh: Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI Polmed 2026-2027.</p>
						</div>
					</div>
					<div class="inline-actions" style="margin-top: 4px;">
						<button type="submit">Simpan agenda</button>
						<a class="button-link" href="?logout=1" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148, 163, 184, 0.3);">Keluar</a>
					</div>
				</form>
			</div>
			<div class="section" style="margin-top: 10px;">
				<h2 style="margin:0 0 8px; font-size:15px;">Sesi</h2>
				<form method="post" class="stack-sm">
					<input type="hidden" name="logout_all" value="1">
					<button type="submit" style="background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35);">Logout semua sesi</button>
					<p class="muted-text">Memaksa semua sesi admin keluar di semua browser dan dicatat di log.</p>
				</form>
			</div>
			<div class="admin-box">
				<h2 style="margin:0 0 10px; font-size:16px;">Log akses terbaru</h2>
				<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:center;">
					<label style="font-size:13px; color: var(--muted);">Event
						<select id="log-filter-event" style="margin-left:6px; padding:8px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
							<option value="">Semua</option>
							<option value="login-success">login-success</option>
							<option value="login-fail">login-fail</option>
							<option value="view-auth">view-auth</option>
							<option value="view-guest">view-guest</option>
							<option value="agenda-updated">agenda-updated</option>
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
		<?php else: ?>
			<h1>Login Admin</h1>
			<p>Masukkan user dan key untuk mengakses halaman admin.</p>
			<?php if ($error): ?>
				<div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<form method="post">
				<div>
					<label for="user">User</label>
					<input type="text" id="user" name="user" required autocomplete="username">
				</div>
				<div>
					<label for="key">Key</label>
					<input type="password" id="key" name="key" required autocomplete="current-password">
				</div>
				<div class="actions">
					<button type="submit">Masuk</button>
				</div>
			</form>
		<?php endif; ?>
	</div>
	<script>
		const archiveButtons = document.querySelectorAll('.archive-day');
		archiveButtons.forEach(btn => {
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
			document.querySelectorAll('.log-list .log-item').forEach(item => {
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
	</script>
</body>
</html>
