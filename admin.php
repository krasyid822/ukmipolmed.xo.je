<?php
define('UKMI_ADMIN_BOOTSTRAP', true);

require_once __DIR__ . '/security_helper.php';
ukmi_session_start();

// Validasi murah sebelum output agar kegagalan konfigurasi awal tidak terjadi
// setelah header terkirim.
$__adminDefaultReady = false;
if (is_readable(__DIR__ . '/default.json')) {
	$__adminDefaultRaw = @file_get_contents(__DIR__ . '/default.json');
	$__adminDefaultReady = $__adminDefaultRaw !== false && is_array(json_decode($__adminDefaultRaw, true));
}
unset($__adminDefaultRaw);

$__adminSessionRole = (string) ($_SESSION['role'] ?? '');
$__adminNonPanelRole = in_array($__adminSessionRole, ['writer', 'mentoring_ikhwan', 'mentoring_akhwat'], true);
$__adminSessionAuth = !empty($_SESSION['admin_logged_in']) && !$__adminNonPanelRole;

// Tampilkan loader lebih awal untuk GET agar terlihat saat bootstrap S3
// masih berjalan. Redirect (logout / penulis / mentoring) tetap diproses sebelum output.
$__adminEarlyLoad = ($_SERVER['REQUEST_METHOD'] === 'GET')
	&& empty($_GET['logout'])
	&& !$__adminNonPanelRole
	&& $__adminDefaultReady;
if (!$__adminEarlyLoad) {
	require __DIR__ . '/components/bootstrap.php';
}

$__adminBodyClass = $__adminEarlyLoad
	? ($__adminSessionAuth ? 'is-auth' : 'is-guest')
	: ($loggedIn ? 'is-auth' : 'is-guest');
?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title>Halaman Admin</title>
	<link rel="icon" type="image/png" href="/logo-ukmi.png">
	<link rel="apple-touch-icon" href="/logo-ukmi.png">
	<link rel="shortcut icon" href="/logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<meta name="csrf-token" content="<?php echo htmlspecialchars(ukmi_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
	<script src="/components/ui-modal.js"></script>
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
	<link rel="stylesheet" href="/style.css">
</head>
<body class="page-admin <?php echo $__adminBodyClass; ?>">
	<?php
	if ($__adminEarlyLoad) {
		require __DIR__ . '/components/loading-overlay.php';
		while (ob_get_level() > 0) { @ob_end_flush(); }
		@flush();
		require __DIR__ . '/components/bootstrap.php';
		echo '<script>document.body.className = ' . json_encode('page-admin ' . ($loggedIn ? 'is-auth' : 'is-guest')) . ';</script>';
	} else {
		require __DIR__ . '/components/loading-overlay.php';
	}
	?>
	<div class="admin-main">
		<div class="admin-header">
			<div class="brand-admin" style="margin-bottom:0;">
				<img src="/logo-ukmi.png" alt="Logo UKMI POLMED">
				<div class="title">UKMI POLMED · Admin</div>
			</div>
			<div class="admin-header-actions">
				<?php if ($loggedIn): ?>
				<a class="button-link" href="?logout=1" style="width:auto; padding:8px 14px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148, 163, 184, 0.25);">Keluar</a>
				<?php endif; ?>
				<a href="https://github.com/krasyid822/ukmipolmed.xo.je" target="_blank" rel="noopener" style="color:inherit; text-decoration:underline; font-size:13px;" title="Sisi belakang aplikasi ini!"><svg aria-hidden="true" focusable="false" class="octicon octicon-mark-github" viewBox="0 0 24 24" width="32" height="32" fill="currentColor" display="inline-block" overflow="visible" style="vertical-align:text-bottom"><path d="M12 1C5.923 1 1 5.923 1 12c0 4.867 3.149 8.979 7.521 10.436.55.096.756-.233.756-.522 0-.262-.013-1.128-.013-2.049-2.764.509-3.479-.674-3.699-1.292-.124-.317-.66-1.293-1.127-1.554-.385-.207-.936-.715-.014-.729.866-.014 1.485.797 1.691 1.128.99 1.663 2.571 1.196 3.204.907.096-.715.385-1.196.701-1.471-2.448-.275-5.005-1.224-5.005-5.432 0-1.196.426-2.186 1.128-2.956-.111-.275-.496-1.402.11-2.915 0 0 .921-.288 3.024 1.128a10.193 10.193 0 0 1 2.75-.371c.936 0 1.871.123 2.75.371 2.104-1.43 3.025-1.128 3.025-1.128.605 1.513.221 2.64.111 2.915.701.77 1.127 1.747 1.127 2.956 0 4.222-2.571 5.157-5.019 5.432.399.344.743 1.004.743 2.035 0 1.471-.014 2.654-.014 3.025 0 .289.206.632.756.522C19.851 20.979 23 16.854 23 12c0-6.077-4.922-11-11-11Z"></path></svg></a>
			</div>
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
				<?php require __DIR__ . '/components/penyimpanan-insight.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="session" data-feature-label="Sesi">
				<?php require __DIR__ . '/components/sesi.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="credential" data-feature-label="Kredensial Admin">
				<?php require __DIR__ . '/components/kredensial-admin.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="log" data-feature-label="Log Akses">
				<?php require __DIR__ . '/components/log.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="registration" data-feature-label="Link Pendaftaran">
				<?php require __DIR__ . '/components/link-pendaftaran.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="hero-cards" data-feature-label="Header Card Presentation">
				<?php require __DIR__ . '/components/header-card-presentation.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="shortcut-cards" data-feature-label="Kartu Baca Ini">
				<?php require __DIR__ . '/components/shortcut-cards.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="divisions" data-feature-label="Divisi Kepengurusan">
				<?php require __DIR__ . '/components/divisi-kepengurusan.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="docs" data-feature-label="Kartu Dokumentasi">
				<?php require __DIR__ . '/components/kartu-dokumentasi.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="faq" data-feature-label="Pengelola FAQ">
				<?php require __DIR__ . '/components/pengelola-faq.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="blog" data-feature-label="Pengelola Blog">
				<?php require __DIR__ . '/components/pengelola-blog.php'; ?>
			</div>
			<div class="admin-feature-block" data-feature="comment-moderation" data-feature-label="Moderasi Komentar">
				<?php require __DIR__ . '/components/moderasi-komentar.php'; ?>
				<?php require __DIR__ . '/components/korektor-seo.php'; ?>
			</div>
		<?php else: ?>
			<h1>Login Admin</h1>
			<p>Masukkan user dan key untuk mengakses halaman admin.</p>
			<form method="post"><?php echo ukmi_csrf_field(); ?>
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
			<details class="admin-box" style="margin-top: 16px;">
				<summary style="cursor:pointer; color:var(--accent); font-size:14px; font-weight:600;">Lupa kredensial / Pemulihan darurat?</summary>
				<p class="muted-text" style="margin:10px 0 8px;">Masukkan <strong>Master Key</strong> (diatur terpusat di <code>default.json</code>) untuk mereset User &amp; Key kembali ke nilai bawaan (<code>default.json</code>) dan mengeluarkan seluruh sesi aktif.</p>
				<p class="muted-text" style="margin:0 0 12px; font-size:13px;">Status: Master key telah digunakan <strong><?php echo (int) ($recoveryCount ?? 0); ?> kali</strong>.</p>
				<form method="post"><?php echo ukmi_csrf_field(); ?>
					<input type="hidden" name="recover_credentials" value="1">
					<div>
						<label for="recovery_key">Recovery Key Master</label>
						<input type="password" id="recovery_key" name="recovery_key" required autocomplete="off" placeholder="Masukkan recovery key server">
					</div>
					<div class="actions">
						<button type="submit" style="background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35);">Reset Kredensial ke Default</button>
					</div>
				</form>
			</details>
		<?php endif; ?>
	</div>
	<script>
	(function () {
		const archiveButtons = document.querySelectorAll('.archive-day');
		archiveButtons.forEach(btn => {
			btn.addEventListener('click', () => {
				const targetId = btn.getAttribute('data-target');
				const target = document.getElementById(targetId);
				if (!target) return;
				target.classList.toggle('open');
			});
		});

		const SCROLL_KEY = 'admin_scroll_y';
		const savedScroll = window.__ADMIN_SAVED_SCROLL__;
		if (typeof savedScroll === 'number' && Number.isFinite(savedScroll)) {
			const restore = () => window.scrollTo(0, savedScroll);
			requestAnimationFrame(restore);
			setTimeout(restore, 0);
			setTimeout(restore, 120);
			setTimeout(restore, 260);
			sessionStorage.removeItem(SCROLL_KEY);
			window.__ADMIN_SAVED_SCROLL__ = null;
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
	})();
	</script>
	<?php require __DIR__ . '/components/soft-save.php'; ?>
</body>
</html>
