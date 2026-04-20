<style>
header.header {
	position: sticky;
	top: 0;
	z-index: 10;
	backdrop-filter: blur(12px);
	background: linear-gradient(180deg, rgba(7, 11, 26, 0.9), rgba(7, 11, 26, 0.65));
	border-bottom: 1px solid rgba(255, 255, 255, 0.1);
	transition: transform 0.25s ease;
}

.header.hide { transform: translateY(-100%); }

.nav {
	max-width: 1080px;
	margin: 0 auto;
	padding: 16px 24px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

@media (min-width: 641px) {
	.nav {
		max-width: none;
		padding-left: 18px;
		padding-right: 18px;
	}
}

.brand {
	display: flex;
	align-items: center;
	gap: 10px;
	font-weight: 700;
	letter-spacing: 0.4px;
}

.brand-main {
	display: flex;
	align-items: center;
	gap: 10px;
}

.brand-title {
	font-weight: 900;
}

.logo {
	width: 34px;
	height: 34px;
	border-radius: 50%;
	object-fit: contain;
	aspect-ratio: 1 / 1;
	border: 1px solid rgba(255, 255, 255, 0.1);
}

.pulse-dot {
	width: 14px;
	height: 14px;
	border-radius: 50%;
	background: linear-gradient(135deg, var(--accent), var(--accent-2));
	box-shadow: 0 0 0 0 rgba(108, 247, 197, 0.4);
	animation: pulse 2.2s ease-out infinite;
}

@keyframes pulse {
	0% { box-shadow: 0 0 0 0 rgba(108, 247, 197, 0.4); }
	70% { box-shadow: 0 0 0 18px rgba(108, 247, 197, 0); }
	100% { box-shadow: 0 0 0 0 rgba(108, 247, 197, 0); }
}

.nav-actions {
	display: flex;
	gap: 12px;
	align-items: center;
}

.menu-toggle {
	display: none;
	background: rgba(255, 255, 255, 0.07);
	border: 1px solid rgba(255, 255, 255, 0.1);
	color: var(--text);
	border-radius: 12px;
	padding: 12px 16px;
	cursor: pointer;
	font-weight: 700;
	font-size: 15px;
	min-height: 48px;
	min-width: 48px;
	align-items: center;
	gap: 8px;
	-webkit-tap-highlight-color: transparent;
}

.menu-toggle:hover {
	background: rgba(255, 255, 255, 0.1);
}

@media (max-width: 640px) {
	.nav { padding: 12px 16px; }
	.brand {
		width: 100%;
		display: flex;
		justify-content: space-between;
		align-items: center;
	}
	.nav-actions {
		width: 100%;
		display: flex;
		flex-direction: column;
		gap: 8px;
		overflow: hidden;
		max-height: 0;
		opacity: 0;
		transform: translateY(-8px);
		pointer-events: none;
		transition: max-height 300ms ease, opacity 220ms ease, transform 260ms ease;
	}
	.nav.is-open .nav-actions {
		max-height: 420px;
		opacity: 1;
		transform: translateY(0);
		pointer-events: auto;
	}
	.nav-actions a {
		width: 100%;
		justify-content: center;
		min-height: 48px;
		font-size: 15px;
		opacity: 0;
		transform: translateY(-6px);
		transition: transform 220ms ease, opacity 180ms ease;
	}
	.nav.is-open .nav-actions a {
		opacity: 1;
		transform: translateY(0);
	}
	.nav.is-open .nav-actions a:nth-child(1) { transition-delay: 40ms; }
	.nav.is-open .nav-actions a:nth-child(2) { transition-delay: 70ms; }
	.nav.is-open .nav-actions a:nth-child(3) { transition-delay: 100ms; }
	.nav.is-open .nav-actions a:nth-child(4) { transition-delay: 130ms; }
	.nav.is-open .nav-actions a:nth-child(5) { transition-delay: 160ms; }
	.nav.is-open .nav-actions a:nth-child(6) { transition-delay: 190ms; }
	.menu-toggle {
		display: inline-flex;
	}
}

@media (prefers-reduced-motion: reduce) {
	.nav-actions,
	.nav-actions a {
		transition: none !important;
		animation: none !important;
	}
}
</style>

<header class="header">
	<div class="nav" id="site-nav">
		<div class="brand">
			<div class="brand-main">
				<img class="logo" src="logo-ukmi.png" alt="Logo UKMI Polmed">
				<span class="pulse-dot"></span>
				<span class="brand-title">UKMI Polmed</span>
			</div>
			<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="nav-actions">☰ Menu</button>
			<script>
			(() => {
				const btn = document.querySelector('.menu-toggle');
				if (!btn) return;
				const update = () => {
					const isOpen = btn.getAttribute('aria-expanded') === 'true';
					btn.textContent = isOpen ? '✖ Tutup' : '☰ Menu';
				};
				update();
				new MutationObserver(update).observe(btn, { attributes: true, attributeFilter: ['aria-expanded'] });
			})();
			</script>
		</div>
		<div class="nav-actions" id="nav-actions">
			<a class="btn ghost" href="index.php">Home</a>
			<a class="btn ghost" href="index.php#program">Program</a>
			<a class="btn ghost" href="index.php#divisi">Divisi</a>
			<a class="btn ghost" href="index.php#dokumentasi">Dokumentasi</a>
			<a class="btn ghost" href="blog.php">Blog</a>
			<a class="btn primary" href="index.php#daftar" data-insight="nav_daftar">Daftar sekarang</a>
		</div>
	</div>
</header>

<script>
(() => {
	const dot = document.querySelector('.pulse-dot');
	if (dot) {
		dot.style.cursor = 'pointer';
		dot.addEventListener('click', () => {
			window.open('https://share.google/BuCPuMmJQqGoRqHQp', '_blank', 'noopener');
		});
	}

	const nav = document.getElementById('site-nav');
	const toggle = document.querySelector('.menu-toggle');
	const actions = document.getElementById('nav-actions');
	const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	const closeMenu = () => {
		if (!nav || !toggle) return;
		nav.classList.remove('is-open');
		toggle.setAttribute('aria-expanded', 'false');
	};

	if (toggle && nav && actions) {
		toggle.addEventListener('click', () => {
			const isOpen = nav.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', String(isOpen));
		});

		actions.addEventListener('click', (event) => {
			const link = event.target.closest('a[href]');
			if (!link) return;

			const rawHref = link.getAttribute('href') || '';
			if (rawHref.startsWith('#')) {
				event.preventDefault();
				const target = document.querySelector(rawHref);
				if (target) {
					const headerOffset = document.querySelector('.header')?.offsetHeight || 0;
					const y = target.getBoundingClientRect().top + window.scrollY - headerOffset - 8;
					window.scrollTo({ top: Math.max(0, y), behavior: prefersReducedMotion ? 'auto' : 'smooth' });
					history.replaceState(null, '', rawHref);
				}
				closeMenu();
				return;
			}

			if (rawHref.includes('#')) {
				try {
					const url = new URL(rawHref, window.location.href);
					const currentPath = window.location.pathname.replace(/\/$/, '');
					const targetPath = url.pathname.replace(/\/$/, '');
					const isSamePath = currentPath === targetPath ||
						(currentPath === '' && targetPath === '/index.php') ||
						(currentPath === '/index.php' && targetPath === '');

					if (isSamePath && url.hash) {
						event.preventDefault();
						const target = document.querySelector(url.hash);
						if (target) {
							const headerOffset = document.querySelector('.header')?.offsetHeight || 0;
							const y = target.getBoundingClientRect().top + window.scrollY - headerOffset - 8;
							window.scrollTo({ top: Math.max(0, y), behavior: prefersReducedMotion ? 'auto' : 'smooth' });
							history.replaceState(null, '', url.hash);
						}
						closeMenu();
					}
				} catch (_) {
					// Keep default navigation when href cannot be parsed.
				}
			}

			closeMenu();
		});
	}

	const headerEl = document.querySelector('.header');
	if (headerEl) {
		let lastY = window.scrollY;
		window.addEventListener('scroll', () => {
			if (nav && nav.classList.contains('is-open')) return; // keep header visible while menu expanded
			const currentY = window.scrollY;
			const scrollingUp = currentY < lastY;
			if (scrollingUp || currentY < 10) {
				headerEl.classList.remove('hide');
			} else {
				headerEl.classList.add('hide');
			}
			lastY = currentY;
		});
	}
})();
</script>
