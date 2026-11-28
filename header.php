<?php require_once __DIR__ . '/components/brand.php'; ?>

<header class="header">
	<div class="nav" id="site-nav">
		<div class="brand">
			<div class="brand-main">
				<a class="logo-pulse" href="https://share.google/BuCPuMmJQqGoRqHQp" target="_blank" rel="noopener" aria-label="Lokasi &amp; Profil UKMI POLMED">
					<img class="logo" src="/logo-ukmi.png" alt="Logo UKMI POLMED">
				</a>
				<?php echo ukmi_brand_auto('span', 'brand-title'); ?>
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
			<a class="nav-link" href="/">Home</a>
			<a class="nav-link" href="/#program" data-random-division="1">Program</a>
			<a class="nav-link" href="/#divisi">Divisi</a>
			<a class="nav-link" href="/#dokumentasi">Dokumentasi</a>
			<a class="nav-link" href="/blog">Blog</a>
			<a class="btn primary" href="/#daftar" data-scroll-cta="cta-daftar-primary" data-insight="nav_daftar">Daftar sekarang</a>
		</div>
	</div>
</header>

<?php echo ukmi_brand_assets(); ?>

<script>
(() => {
	const THEME_KEY = 'ukmi-theme';
	const metaTheme = document.querySelector('meta[name="theme-color"]');
	const applyTheme = (theme) => {
		const t = theme === 'light' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-theme', t);
		if (metaTheme) metaTheme.setAttribute('content', t === 'light' ? '#1E824C' : '#0f172a');
		const themeBtn = document.getElementById('ukmi-theme-toggle');
		if (themeBtn) {
			themeBtn.setAttribute('aria-checked', t === 'light' ? 'true' : 'false');
			themeBtn.title = t === 'light' ? 'Beralih ke tema gelap' : 'Beralih ke tema terang';
			const thumb = themeBtn.querySelector('.theme-switch-thumb');
			const label = themeBtn.querySelector('.theme-switch-label');
			if (thumb) thumb.textContent = t === 'light' ? '☀️' : '🌙';
			if (label) label.textContent = t === 'light' ? 'Tema Terang' : 'Tema Gelap';
		}
	};
	let savedTheme = 'dark';
	try { savedTheme = localStorage.getItem(THEME_KEY) || 'dark'; } catch (_) {}
	applyTheme(savedTheme);
	const bindThemeToggle = () => {
		const themeBtn = document.getElementById('ukmi-theme-toggle');
		if (!themeBtn || themeBtn.dataset.bound) return;
		themeBtn.dataset.bound = '1';
		applyTheme(document.documentElement.getAttribute('data-theme') || savedTheme);
		themeBtn.addEventListener('click', () => {
			const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
			const commit = () => {
				try { localStorage.setItem(THEME_KEY, next); } catch (_) {}
				applyTheme(next);
			};
			const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			if (!document.startViewTransition || reduced) {
				commit();
				return;
			}
			const rect = themeBtn.getBoundingClientRect();
			const x = rect.left + rect.width / 2;
			const y = rect.top + rect.height / 2;
			const endRadius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
			document.documentElement.classList.add('is-theme-transitioning');
			const transition = document.startViewTransition(commit);
			transition.ready.then(() => {
				document.documentElement.animate(
					{ clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${endRadius}px at ${x}px ${y}px)`] },
					{ duration: 420, easing: 'cubic-bezier(0.22, 1, 0.36, 1)', pseudoElement: '::view-transition-new(root)' }
				);
			}).catch(() => {});
			transition.finished.finally(() => {
				document.documentElement.classList.remove('is-theme-transitioning');
			});
		});
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindThemeToggle);
	} else {
		bindThemeToggle();
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
			if (link.classList.contains('nav-link')) {
				actions.querySelectorAll('.nav-link.is-active').forEach((el) => el.classList.remove('is-active'));
				link.classList.add('is-active');
			}

			const scrollCta = link.getAttribute('data-scroll-cta');
			const ctaHref = link.getAttribute('href') || '';
			if (scrollCta && document.getElementById(scrollCta) && typeof window.ukmiScrollToCta === 'function') {
				event.preventDefault();
				const sectionId = ctaHref.includes('#') ? ctaHref.split('#')[1] : '';
				window.ukmiScrollToCta(scrollCta, sectionId);
				closeMenu();
				return;
			}

			if (link.hasAttribute('data-random-division') && typeof window.ukmiOpenRandomDivision === 'function') {
				event.preventDefault();
				window.ukmiOpenRandomDivision();
				closeMenu();
				return;
			}

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
						((currentPath === '' || currentPath === '/index' || currentPath === '/index.php') &&
							(targetPath === '' || targetPath === '/index' || targetPath === '/index.php'));

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
