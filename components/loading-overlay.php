<?php
if (!defined('UKMI_LOADING_OVERLAY')) {
	define('UKMI_LOADING_OVERLAY', true);
	?>
<style>
.ukmi-loading-overlay {
	position: fixed;
	inset: 0;
	z-index: 2147483000;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 18px;
	padding: 24px;
	background: radial-gradient(circle at 20% 20%, rgba(197, 255, 106, 0.14), transparent 34%),
		radial-gradient(circle at 80% 25%, rgba(255, 255, 255, 0.1), transparent 36%),
		rgba(6, 9, 16, 0.92);
	backdrop-filter: blur(14px);
	-webkit-backdrop-filter: blur(14px);
	color: #f6f8fb;
	font-family: 'Manrope', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
	opacity: 0;
	visibility: hidden;
	transition: opacity 0.35s ease, visibility 0.35s ease;
}
.ukmi-loading-overlay.is-visible {
	opacity: 1;
	visibility: visible;
}
.ukmi-loading-overlay.is-hidden {
	opacity: 0;
	visibility: hidden;
	pointer-events: none;
}
.ukmi-loading-media {
	position: relative;
	width: min(260px, 62vw);
	display: grid;
	place-items: center;
	filter: drop-shadow(0 12px 30px rgba(0, 0, 0, 0.45));
}
.ukmi-loading-media img.ukmi-loading-gif {
	display: block;
	width: 100%;
	height: auto;
	border-radius: 18px;
	background: #111;
}
.ukmi-loading-media img.ukmi-loading-logo {
	position: absolute;
	width: 38%;
	max-width: 110px;
}
.ukmi-loading-card {
	width: min(320px, 82vw);
	padding: 14px 16px 16px;
	border-radius: 14px;
	background: rgba(0, 0, 0, 0.55);
	border: 1px solid rgba(255, 255, 255, 0.08);
	text-align: center;
	font-size: 14px;
	font-weight: 600;
	letter-spacing: 0.01em;
}
.ukmi-loading-track {
	position: relative;
	width: 100%;
	height: 8px;
	margin-top: 10px;
	border-radius: 999px;
	background: rgba(255, 255, 255, 0.12);
	overflow: hidden;
}
.ukmi-loading-track span {
	position: absolute;
	inset: 0;
	width: 36%;
	border-radius: inherit;
	background: linear-gradient(90deg, rgba(197, 255, 106, 0.3), #c5ff6a);
	animation: ukmi-loading-pulse 1.5s ease-in-out infinite;
}
@keyframes ukmi-loading-pulse {
	0% { left: -42%; width: 32%; }
	50% { left: 30%; width: 44%; }
	100% { left: 100%; width: 32%; }
}
@media (prefers-reduced-motion: reduce) {
	.ukmi-loading-track span { animation: none; left: 32%; }
}
</style>
<div class="ukmi-loading-overlay" id="ukmi-loading-overlay" role="status" aria-live="polite" aria-busy="true">
	<div class="ukmi-loading-media">
		<img class="ukmi-loading-gif" src="/palestine.gif" alt="Animasi pemuatan" width="260" height="260">
		<img class="ukmi-loading-logo" src="/logo-ukmi.png" alt="Logo UKMI POLMED" width="110" height="110">
	</div>
	<div class="ukmi-loading-card">
		Memuat konten, mohon tunggu...
		<div class="ukmi-loading-track" aria-hidden="true"><span></span></div>
	</div>
</div>
<script>
(function () {
	var el = document.getElementById('ukmi-loading-overlay');
	if (!el) return;
	var showDelay = 120;
	var minVisible = 420;
	var shownAt = 0;
	var done = false;
	function show() {
		if (done || !el) return;
		el.classList.add('is-visible');
		shownAt = Date.now();
	}
	var showTimer = setTimeout(show, showDelay);
	function hide() {
		if (done) return;
		done = true;
		clearTimeout(showTimer);
		clearTimeout(limitTimer);
		var wait = 0;
		if (el && el.classList.contains('is-visible')) {
			wait = Math.max(0, minVisible - (Date.now() - shownAt));
		}
		setTimeout(function () {
			if (!el) return;
			el.setAttribute('aria-busy', 'false');
			el.classList.remove('is-visible');
			el.classList.add('is-hidden');
			setTimeout(function () {
				if (el && el.parentNode) el.parentNode.removeChild(el);
			}, 380);
		}, wait);
	}
	var limitTimer = setTimeout(function () {
		if (done) return;
		done = true;
		clearTimeout(showTimer);
		var code = 'Haduh. Koneksi bre melambat! Coba ganti posisi (408)';
		window.location.replace('/error?code=' + encodeURIComponent(code));
	}, 15000);
	window.ukmiHideLoadingOverlay = hide;
	if (document.readyState === 'complete') {
		hide();
	} else {
		window.addEventListener('load', hide);
	}
})();
</script>
	<?php
}
