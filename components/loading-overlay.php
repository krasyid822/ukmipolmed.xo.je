<?php
if (!defined('UKMI_LOADING_OVERLAY')) {
	define('UKMI_LOADING_OVERLAY', true);
	?>
<div class="ukmi-loading-overlay is-visible" id="ukmi-loading-overlay" role="status" aria-live="polite" aria-busy="true">
	<div class="ukmi-loading-media">
		<img class="ukmi-loading-gif" src="/palestine.gif" alt="Animasi pemuatan" width="260" height="260">
		<img class="ukmi-loading-logo" src="/logo-ukmi.png" alt="Logo UKMI POLMED" width="110" height="110">
	</div>
	<div class="ukmi-loading-card">
		<span id="ukmi-loading-text">Memuat konten, mohon tunggu...</span>
		<div class="ukmi-loading-track" aria-hidden="true"><span></span></div>
	</div>
</div>
<script>
(function () {
	var el = document.getElementById('ukmi-loading-overlay');
	if (!el) return;
	var textEl = document.getElementById('ukmi-loading-text');
	var defaultText = 'Memuat konten, mohon tunggu...';
	var minVisible = 120;
	var shownAt = Date.now();
	var done = false;
	var hideTimer = null;
	var limitTimer = null;

	function clearTimers() {
		if (hideTimer) {
			clearTimeout(hideTimer);
			hideTimer = null;
		}
		if (limitTimer) {
			clearTimeout(limitTimer);
			limitTimer = null;
		}
	}

	function ensureAttached() {
		if (!el.parentNode && document.body) {
			document.body.appendChild(el);
		}
	}

	function showNow(customText) {
		clearTimers();
		ensureAttached();
		done = false;
		if (textEl) {
			textEl.textContent = customText || defaultText;
		}
		el.setAttribute('aria-busy', 'true');
		el.classList.remove('is-hidden');
		el.classList.add('is-visible');
		shownAt = Date.now();
		limitTimer = setTimeout(function () {
			if (!done) hide();
		}, 15000);
	}

	limitTimer = setTimeout(function () {
		if (done) return;
		if (document.body && document.body.classList.contains('page-admin')) {
			hide();
			if (typeof window.ukmiNotify === 'function') {
				window.ukmiNotify('Pemuatan berjalan lambat, silakan coba lagi jika data belum tampil.', 'danger', 7000);
			}
			return;
		}
		done = true;
		clearTimers();
		var code = 'Haduh. Koneksi bre melambat! Coba ganti posisi (408)';
		window.location.href = '/error?code=' + encodeURIComponent(code);
	}, 15000);

	function hide() {
		if (done) return;
		done = true;
		clearTimers();
		var wait = 0;
		if (el && el.classList.contains('is-visible')) {
			wait = Math.max(0, minVisible - (Date.now() - shownAt));
		}
		hideTimer = setTimeout(function () {
			if (!el) return;
			el.setAttribute('aria-busy', 'false');
			el.classList.remove('is-visible');
			el.classList.add('is-hidden');
		}, wait);
	}

	window.ukmiShowLoadingOverlay = showNow;
	window.ukmiHideLoadingOverlay = hide;

	if (document.readyState === 'complete') {
		hide();
	} else {
		window.addEventListener('load', hide);
	}

	if (!window.__ukmiLoadingOverlayListenersBound) {
		window.__ukmiLoadingOverlayListenersBound = true;

		window.addEventListener('pageshow', function (event) {
			if (event.persisted && typeof window.ukmiHideLoadingOverlay === 'function') {
				window.ukmiHideLoadingOverlay();
			}
		});

		document.addEventListener('submit', function (event) {
			var form = event.target;
			if (!form || form.tagName !== 'FORM') return;
			if (event.defaultPrevented) return;
			if (form.hasAttribute('data-no-loading')) return;
			if (form.target && form.target !== '_self') return;
			if (typeof window.ukmiShowLoadingOverlay === 'function') {
				window.ukmiShowLoadingOverlay('Memproses permintaan, mohon tunggu...');
			}
		});

		document.addEventListener('click', function (event) {
			if (event.defaultPrevented || event.button !== 0) return;
			if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
			var target = event.target;
			var link = target && target.closest ? target.closest('a[href]') : null;
			if (!link) return;
			if (link.hasAttribute('download') || link.hasAttribute('data-no-loading')) return;
			var linkTarget = link.getAttribute('target');
			if (linkTarget && linkTarget !== '_self') return;
			var rawHref = (link.getAttribute('href') || '').trim();
			if (!rawHref || rawHref.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(rawHref)) return;
			try {
				var nextUrl = new URL(link.href, window.location.href);
				if (nextUrl.origin !== window.location.origin) return;
				if (nextUrl.pathname === window.location.pathname && nextUrl.search === window.location.search && nextUrl.hash !== '') {
					return;
				}
			} catch (e) {
				return;
			}
			if (typeof window.ukmiShowLoadingOverlay === 'function') {
				window.ukmiShowLoadingOverlay('Memuat halaman, mohon tunggu...');
			}
		});
	}
})();
</script>
	<?php
}
