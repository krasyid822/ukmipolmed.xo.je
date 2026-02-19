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

.brand {
	display: flex;
	align-items: center;
	gap: 10px;
	font-weight: 700;
	letter-spacing: 0.4px;
}

.logo {
	width: 34px;
	height: 34px;
	border-radius: 50%;
	object-fit: cover;
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
	padding: 10px 12px;
	cursor: pointer;
	font-weight: 700;
}

@media (max-width: 640px) {
	.nav { padding: 14px 16px; }
	.nav-actions { width: 100%; display: none; flex-direction: column; }
	.nav.is-open .nav-actions { display: flex; }
	.nav-actions a { width: 100%; justify-content: center; }
	.menu-toggle { display: inline-flex; }
	.brand { width: 100%; justify-content: space-between; }
}
</style>

<header class="header">
	<div class="nav" id="site-nav">
		<div class="brand">
			<div style="display:flex; align-items:center; gap:10px;">
				<img class="logo" src="logo-ukmi.png" alt="Logo UKMI Polmed">
				<span class="pulse-dot"></span>
				<span>UKMI Polmed</span>
			</div>
			<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="nav-actions">Menu</button>
		</div>
		<div class="nav-actions" id="nav-actions">
			<a class="btn ghost" href="index.php#program">Program</a>
			<a class="btn ghost" href="index.php#divisi">Divisi</a>
			<a class="btn ghost" href="index.php#dokumentasi">Dokumentasi</a>
			<a class="btn ghost" href="blog.php">Blog</a>
			<a class="btn primary" href="index.php#daftar">Daftar sekarang</a>
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

	if (toggle && nav && actions) {
		toggle.addEventListener('click', () => {
			const isOpen = nav.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', String(isOpen));
		});
	}

	const headerEl = document.querySelector('.header');
	if (headerEl) {
		let lastY = window.scrollY;
		window.addEventListener('scroll', () => {
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
