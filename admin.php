<?php
require __DIR__ . '/admin_component/bootstrap.php';
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
	<script>
		(function() {
			const SCROLL_KEY = 'admin_scroll_y';
			const saved = sessionStorage.getItem(SCROLL_KEY);
			if (saved) {
				history.scrollRestoration = 'manual';
				const y = parseInt(saved, 10);
				if (!Number.isNaN(y)) {
					window.__ADMIN_SAVED_SCROLL__ = y;
				}
			}
		})();
	</script>
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
			align-items: flex-start;
			justify-content: center;
			padding: 24px;
		}

		body.is-auth { padding: 32px 32px 40px; }

		.card {
			background: linear-gradient(145deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0));
			border: 1px solid rgba(148, 163, 184, 0.2);
			border-radius: 16px;
			width: min(100%, 520px);
			padding: 24px;
			box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
			backdrop-filter: blur(10px);
		}

		body.is-auth .card {
			width: min(100%, 1240px);
			max-width: 96vw;
			padding: 28px 30px;
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
			padding: 10px 0.1cm;
			border-radius: 10px;
			border: 1px solid rgba(148, 163, 184, 0.3);
			background: rgba(15, 23, 42, 0.4);
			color: var(--text);
			font-size: 14px;
			transition: border-color 0.2s ease, box-shadow 0.2s ease;
			box-sizing: border-box;
		}

		textarea { width: 100%; min-width: 0; min-height: 110px; resize: both; font-family: inherit; }

		/* Override inline paddings to keep columns within card */
		input[type="text"],
		input[type="password"],
		textarea,
		select,
		input[type="url"] {
			padding: 10px 0.1cm !important;
			box-sizing: border-box;
		}

		.field-grid > * { min-width: 0; }

		/* Prevent overflow/overlap in documentation cards on desktop */
		.doc-item,
		.doc-item * { min-width: 0; }
		.doc-item input[type="text"],
		.doc-item textarea { width: 100%; }

		/* Prevent overflow/overlap in division cards on desktop */
		.division-item,
		.division-item * { min-width: 0; }
		.division-item input[type="text"],
		.division-item textarea { width: 100%; }

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

		.toast-wrap {
			position: fixed;
			right: 18px;
			bottom: 18px;
			display: flex;
			flex-direction: column;
			gap: 8px;
			max-width: 340px;
			z-index: 30;
			pointer-events: none;
		}

		.toast {
			pointer-events: auto;
			padding: 12px 14px;
			border-radius: 12px;
			background: rgba(17, 24, 39, 0.95);
			border: 1px solid rgba(148, 163, 184, 0.35);
			color: var(--text);
			box-shadow: 0 18px 36px rgba(0, 0, 0, 0.35);
			transform: translateY(10px);
			opacity: 0;
			transition: transform 0.25s ease, opacity 0.25s ease;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
		}

		.toast.show {
			transform: translateY(0);
			opacity: 1;
		}

		.toast.success { border-color: rgba(56, 189, 248, 0.6); }
		.toast.danger { border-color: rgba(248, 113, 113, 0.7); }

		.toast button.toast-close {
			background: transparent;
			color: var(--text);
			border: none;
			cursor: pointer;
			padding: 6px 8px;
			border-radius: 8px;
		}

		button:active,
		a.button-link:active {
			transform: translateY(0);
		}

		.toggle {
			position: relative;
			display: inline-block;
			width: 48px;
			height: 26px;
		}

		.toggle input { opacity: 0; width: 0; height: 0; }

		.slider {
			position: absolute;
			cursor: pointer;
			top: 0; left: 0; right: 0; bottom: 0;
			background: rgba(148, 163, 184, 0.35);
			transition: .2s;
			border-radius: 26px;
		}

		.slider:before {
			position: absolute;
			content: '';
			height: 20px;
			width: 20px;
			left: 3px;
			bottom: 3px;
			background: #0b1727;
			transition: .2s;
			border-radius: 50%;
			box-shadow: 0 4px 10px rgba(0,0,0,0.25);
		}

		input:checked + .slider {
			background: linear-gradient(135deg, #38bdf8, #0ea5e9);
		}

		input:checked + .slider:before {
			transform: translateX(22px);
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

		body.is-auth .field-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }

		@media (min-width: 1280px) {
			body.is-auth .card { width: min(100%, 1400px); }
			body.is-auth .field-grid { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
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

		.feature-visibility-panel {
			display: grid;
			gap: 10px;
		}

		.feature-visibility-head {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
		}

		.feature-collapse-btn {
			width: 38px;
			height: 38px;
			padding: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			background: rgba(255,255,255,0.08);
			color: var(--text);
			box-shadow: none;
			border: 1px solid rgba(148,163,184,0.25);
			border-radius: 10px;
			font-size: 18px;
			line-height: 1;
		}

		.feature-collapse-btn .icon {
			display: inline-block;
			font-weight: 700;
		}

		.sr-only {
			position: absolute;
			width: 1px;
			height: 1px;
			padding: 0;
			margin: -1px;
			overflow: hidden;
			clip: rect(0, 0, 0, 0);
			white-space: nowrap;
			border: 0;
		}

		.feature-visibility-content {
			display: grid;
			gap: 10px;
		}

		.feature-visibility-panel.is-collapsed .feature-visibility-content {
			display: none;
		}

		.feature-toggle-actions {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
		}

		.feature-toggle-actions button {
			max-width: 180px;
			background: rgba(255,255,255,0.08);
			color: var(--text);
			box-shadow: none;
			border: 1px solid rgba(148,163,184,0.25);
		}

		.feature-toggle-list {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
			gap: 8px;
		}

		.feature-toggle-item {
			display: flex;
			align-items: center;
			gap: 8px;
			padding: 8px 10px;
			border-radius: 10px;
			border: 1px solid rgba(148,163,184,0.2);
			background: rgba(255,255,255,0.02);
			font-size: 13px;
			color: var(--text);
		}

		.admin-feature-block.is-hidden {
			display: none;
		}

		@media (max-width: 480px) {
			.card { padding: 20px; }
			h1 { font-size: 20px; }
		}
	</style>
</head>
<body class="<?php echo $loggedIn ? 'is-auth' : 'is-guest'; ?>">
	<?php if ($message || $error): ?>
	<div class="toast-wrap" id="toast-wrap">
		<?php if ($message): ?>
			<div class="toast success" data-kind="success">
				<span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
				<button type="button" class="toast-close" aria-label="Tutup">×</button>
			</div>
		<?php endif; ?>
		<?php if ($error): ?>
			<div class="toast danger" data-kind="danger">
				<span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
				<button type="button" class="toast-close" aria-label="Tutup">×</button>
			</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<div class="card">
		<div style="margin-top:8px; float:right;">
			<a href="https://github.com/krasyid822/ukmipolmed.xo.je" target="_blank" rel="noopener" style="color:inherit; text-decoration:underline; font-size:13px;" title="Sisi belakang aplikasi ini!"><svg aria-hidden="true" focusable="false" class="octicon octicon-mark-github" viewBox="0 0 24 24" width="32" height="32" fill="currentColor" display="inline-block" overflow="visible" style="vertical-align:text-bottom"><path d="M12 1C5.923 1 1 5.923 1 12c0 4.867 3.149 8.979 7.521 10.436.55.096.756-.233.756-.522 0-.262-.013-1.128-.013-2.049-2.764.509-3.479-.674-3.699-1.292-.124-.317-.66-1.293-1.127-1.554-.385-.207-.936-.715-.014-.729.866-.014 1.485.797 1.691 1.128.99 1.663 2.571 1.196 3.204.907.096-.715.385-1.196.701-1.471-2.448-.275-5.005-1.224-5.005-5.432 0-1.196.426-2.186 1.128-2.956-.111-.275-.496-1.402.11-2.915 0 0 .921-.288 3.024 1.128a10.193 10.193 0 0 1 2.75-.371c.936 0 1.871.123 2.75.371 2.104-1.43 3.025-1.128 3.025-1.128.605 1.513.221 2.64.111 2.915.701.77 1.127 1.747 1.127 2.956 0 4.222-2.571 5.157-5.019 5.432.399.344.743 1.004.743 2.035 0 1.471-.014 2.654-.014 3.025 0 .289.206.632.756.522C19.851 20.979 23 16.854 23 12c0-6.077-4.922-11-11-11Z"></path></svg></a>
		</div>
		<div class="brand-admin">
			<img src="logo-ukmi.png" alt="Logo UKMI Polmed">
			<div class="title">UKMI Polmed · Admin</div>
		</div>
		<?php if ($loggedIn): ?>
			<h1>Panel Admin</h1>
			<p>Kelola, Anda adalah admin.</p>
			<div class="section feature-visibility-panel" id="feature-visibility-panel">
				<div class="feature-visibility-head">
					<h2 style="margin:0; font-size:16px;">Tampilan fitur admin</h2>
					<button type="button" class="feature-collapse-btn" id="feature-collapse-toggle" aria-label="Collapse panel" title="Collapse panel">
						<span class="icon" id="feature-collapse-icon" aria-hidden="true">▾</span>
						<span class="sr-only">Toggle panel</span>
					</button>
				</div>
				<div class="feature-visibility-content" id="feature-visibility-content">
					<p class="muted-text" style="margin:0;">Centang untuk tampilkan fitur. Hilangkan centang untuk sembunyikan fiturnya dari halaman ini.</p>
					<div class="feature-toggle-actions">
						<button type="button" id="feature-show-all">Tampilkan semua</button>
						<button type="button" id="feature-hide-all">Sembunyikan semua</button>
					</div>
					<div class="feature-toggle-list" id="feature-toggle-list"></div>
				</div>
			</div>
			<div class="admin-feature-block" data-feature="insight-storage" data-feature-label="Penyimpanan Insight">
				<?php require __DIR__ . '/admin_component/penyimpanan-insight.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="session" data-feature-label="Sesi">
				<?php require __DIR__ . '/admin_component/sesi.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="credential" data-feature-label="Kredensial Admin">
				<?php require __DIR__ . '/admin_component/kredensial-admin.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="log" data-feature-label="Log Akses">
				<?php require __DIR__ . '/admin_component/log.php'; ?>
			</div>
			<?php if ($message): ?>
				<div class="success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<?php if ($error): ?>
				<div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<div class="admin-feature-block" data-feature="agenda" data-feature-label="Agenda Terdekat">
				<?php require __DIR__ . '/admin_component/agenda-terdekat.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="registration" data-feature-label="Link Pendaftaran">
				<?php require __DIR__ . '/admin_component/link-pendaftaran.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="hero-cards" data-feature-label="Header Card Presentation">
				<?php require __DIR__ . '/admin_component/header-card-presentation.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="divisions" data-feature-label="Divisi Kepengurusan">
				<?php require __DIR__ . '/admin_component/divisi-kepengurusan.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="docs" data-feature-label="Kartu Dokumentasi">
				<?php require __DIR__ . '/admin_component/kartu-dokumentasi.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="faq" data-feature-label="Pengelola FAQ">
				<?php require __DIR__ . '/admin_component/pengelola-faq.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="blog" data-feature-label="Pengelola Blog">
				<?php require __DIR__ . '/admin_component/pegelola-blog.php'; ?>
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

		const toastWrap = document.getElementById('toast-wrap');
		if (toastWrap) {
			const toasts = Array.from(toastWrap.querySelectorAll('.toast'));
			toasts.forEach((toast, idx) => {
				requestAnimationFrame(() => toast.classList.add('show'));
				const closer = toast.querySelector('.toast-close');
				const hide = () => {
					toast.classList.remove('show');
					setTimeout(() => toast.remove(), 220);
				};
				closer?.addEventListener('click', hide);
				setTimeout(hide, 5200 + (idx * 200));
			});
		}

		const SCROLL_KEY = 'admin_scroll_y';
		const savedScroll = window.__ADMIN_SAVED_SCROLL__;
		if (typeof savedScroll === 'number' && Number.isFinite(savedScroll)) {
			const restore = () => window.scrollTo(0, savedScroll);
			requestAnimationFrame(restore);
			setTimeout(restore, 0);
			setTimeout(restore, 120);
			setTimeout(restore, 260);
			sessionStorage.removeItem(SCROLL_KEY);
		}

		document.querySelectorAll('form').forEach(form => {
			form.addEventListener('submit', () => {
				sessionStorage.setItem(SCROLL_KEY, String(window.scrollY));
			});
		});

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

		(function enableAutosave() {
			if (!document.body.classList.contains('is-auth')) return;
			const PREFIX = 'admin_autosave_';
			const forms = document.querySelectorAll('form[data-autosave-key]');

			function serialize(form) {
				const data = {};
				form.querySelectorAll('input, textarea, select').forEach(el => {
					if (!el.name) return;
					const type = (el.type || '').toLowerCase();
					if (['submit', 'button', 'file', 'password', 'hidden'].includes(type)) return;
					if (type === 'checkbox' || type === 'radio') {
						data[el.name] = !!el.checked;
					} else {
						data[el.name] = el.value;
					}
				});
				return data;
			}

			function restore(form, data) {
				form.querySelectorAll('input, textarea, select').forEach(el => {
					if (!el.name) return;
					const type = (el.type || '').toLowerCase();
					if (['submit', 'button', 'file', 'password', 'hidden'].includes(type)) return;
					if (!(el.name in data)) return;
					const val = data[el.name];
					if (type === 'checkbox' || type === 'radio') {
						el.checked = !!val;
					} else if (typeof val === 'string') {
						el.value = val;
					}
				});
			}

			forms.forEach(form => {
				const key = form.getAttribute('data-autosave-key');
				if (!key) return;
				const storageKey = PREFIX + key;
				let saveTimer = null;

				const triggerSave = () => {
					clearTimeout(saveTimer);
					saveTimer = setTimeout(() => {
						try { localStorage.setItem(storageKey, JSON.stringify(serialize(form))); } catch (_) {}
					}, 240);
				};

				const raw = localStorage.getItem(storageKey);
				if (raw) {
					try {
						const data = JSON.parse(raw);
						const hasExisting = Array.from(form.querySelectorAll('input, textarea, select')).some(el => {
							const type = (el.type || '').toLowerCase();
							if (['submit', 'button', 'file', 'password', 'hidden'].includes(type)) return false;
							if (!el.name) return false;
							if (type === 'checkbox' || type === 'radio') return el.checked;
							return !!el.value;
						});
						if (!hasExisting && data && typeof data === 'object') {
							restore(form, data);
						}
					} catch (_) {}
				}

				form.addEventListener('input', triggerSave);
				form.addEventListener('change', triggerSave);
				form.addEventListener('submit', () => localStorage.removeItem(storageKey));
			});
		})();

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

		(function enableFeatureVisibilityToggle() {
			if (!document.body.classList.contains('is-auth')) return;
			const STORAGE_KEY = 'admin_feature_visibility_v1';
			const STORAGE_COLLAPSE_KEY = 'admin_feature_visibility_panel_collapsed_v1';
			const blocks = Array.from(document.querySelectorAll('.admin-feature-block[data-feature]'));
			const panel = document.getElementById('feature-visibility-panel');
			const collapseBtn = document.getElementById('feature-collapse-toggle');
			const collapseIcon = document.getElementById('feature-collapse-icon');
			const list = document.getElementById('feature-toggle-list');
			const showAllBtn = document.getElementById('feature-show-all');
			const hideAllBtn = document.getElementById('feature-hide-all');
			if (!blocks.length || !list) return;

			const readState = () => {
				try {
					const raw = localStorage.getItem(STORAGE_KEY);
					return raw ? JSON.parse(raw) : {};
				} catch (_) {
					return {};
				}
			};

			const writeState = (state) => {
				try {
					localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
				} catch (_) {}
			};

			const state = readState();

			function setPanelCollapsed(collapsed) {
				if (!panel) return;
				panel.classList.toggle('is-collapsed', !!collapsed);
				if (collapseBtn) {
					const label = collapsed ? 'Expand panel' : 'Collapse panel';
					collapseBtn.setAttribute('aria-label', label);
					collapseBtn.setAttribute('title', label);
				}
				if (collapseIcon) {
					collapseIcon.textContent = collapsed ? '▸' : '▾';
				}
				try {
					localStorage.setItem(STORAGE_COLLAPSE_KEY, collapsed ? '1' : '0');
				} catch (_) {}
			}

			let collapsed = false;
			try {
				collapsed = localStorage.getItem(STORAGE_COLLAPSE_KEY) === '1';
			} catch (_) {}
			setPanelCollapsed(collapsed);
			collapseBtn?.addEventListener('click', () => {
				collapsed = !collapsed;
				setPanelCollapsed(collapsed);
			});

			function setVisibility(feature, visible) {
				const block = blocks.find((b) => b.dataset.feature === feature);
				if (!block) return;
				block.classList.toggle('is-hidden', !visible);
				state[feature] = !!visible;
				writeState(state);
			}

			blocks.forEach((block) => {
				const feature = block.dataset.feature;
				const label = block.dataset.featureLabel || feature;
				const checked = (feature in state) ? !!state[feature] : true;
				block.classList.toggle('is-hidden', !checked);

				const row = document.createElement('label');
				row.className = 'feature-toggle-item';
				const checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.checked = checked;
				checkbox.dataset.feature = feature;
				const text = document.createElement('span');
				text.textContent = label;
				row.appendChild(checkbox);
				row.appendChild(text);
				list.appendChild(row);

				checkbox.addEventListener('change', () => {
					setVisibility(feature, checkbox.checked);
				});
			});

			showAllBtn?.addEventListener('click', () => {
				list.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
					cb.checked = true;
					setVisibility(cb.dataset.feature, true);
				});
			});

			hideAllBtn?.addEventListener('click', () => {
				list.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
					cb.checked = false;
					setVisibility(cb.dataset.feature, false);
				});
			});
		})();
	</script>
</body>
</html>
