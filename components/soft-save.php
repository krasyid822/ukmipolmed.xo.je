<?php
if (!defined('UKMI_SOFT_SAVE')) {
	define('UKMI_SOFT_SAVE', true);
	?>
<style>
.ukmi-save-indicator {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	z-index: 2147482000;
	padding: 6px 14px;
	text-align: center;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.02em;
	color: #06121f;
	background: linear-gradient(90deg, #7dd3fc, #38bdf8);
	box-shadow: 0 6px 18px rgba(0, 0, 0, 0.28);
	transform: translateY(-100%);
	transition: transform 0.18s ease;
	pointer-events: none;
}
.ukmi-save-indicator.is-on { transform: translateY(0); }
</style>
<script>
(function () {
	if (window.__ukmiSoftSaveBound) return;
	window.__ukmiSoftSaveBound = true;

	var nativeSubmit = HTMLFormElement.prototype.submit;
	var busy = false;
	var indicator = null;

	function showIndicator() {
		if (!indicator) {
			indicator = document.createElement('div');
			indicator.className = 'ukmi-save-indicator';
			indicator.setAttribute('role', 'status');
			indicator.setAttribute('aria-live', 'polite');
			indicator.textContent = 'Menyimpan perubahan...';
			(document.body || document.documentElement).appendChild(indicator);
		}
		requestAnimationFrame(function () { indicator.classList.add('is-on'); });
	}

	function hideIndicator() {
		if (!indicator) return;
		indicator.classList.remove('is-on');
		var el = indicator;
		indicator = null;
		setTimeout(function () { if (el && el.parentNode) el.parentNode.removeChild(el); }, 220);
	}

	function isHtmlResponse(res) {
		var type = (res.headers.get('content-type') || '').toLowerCase();
		return type === '' || type.indexOf('text/html') !== -1;
	}

	function runScripts(root) {
		var scripts = Array.prototype.slice.call(root.querySelectorAll('script'));
		scripts.forEach(function (old) {
			if (old.dataset.ukmiRerun === '1') return;
			// Script eksternal sudah dimuat pada load awal. Jangan disisipkan
			// ulang: pemuatan asinkron bisa bentrok dengan script inline yang
			// langsung jalan setelah swap.
			if (old.src) return;
			var type = (old.getAttribute('type') || '').toLowerCase();
			if (type && type !== 'text/javascript' && type !== 'module' && type !== 'application/javascript') return;
			if (!old.textContent.trim()) return;
			var s = document.createElement('script');
			for (var i = 0; i < old.attributes.length; i++) {
				s.setAttribute(old.attributes[i].name, old.attributes[i].value);
			}
			s.dataset.ukmiRerun = '1';
			if (old.src) s.src = old.src;
			s.textContent = old.textContent;
			old.parentNode.replaceChild(s, old);
		});
	}

	function swapBody(html, finalUrl) {
		var doc = new DOMParser().parseFromString(html, 'text/html');
		if (!doc || !doc.body) {
			window.location.href = finalUrl || window.location.href;
			return;
		}
		var y = window.scrollY;
		document.body.className = doc.body.className;
		if (doc.body.id) document.body.id = doc.body.id;
		document.body.innerHTML = doc.body.innerHTML;
		if (doc.title) document.title = doc.title;
		var overlay = document.getElementById('ukmi-loading-overlay');
		if (overlay) overlay.remove();
		runScripts(document.body);
		if (window.history && window.history.replaceState) {
			try { window.history.replaceState(null, '', finalUrl || window.location.href); } catch (e) {}
		}
		window.scrollTo(0, y);
		document.dispatchEvent(new CustomEvent('ukmi:softswap'));
	}

	function softSubmit(form, submitter) {
		if (!form || form.tagName !== 'FORM') {
			if (form) nativeSubmit.call(form);
			return;
		}
		if (form.hasAttribute('data-soft-save-off') || form.hasAttribute('download')) {
			nativeSubmit.call(form);
			return;
		}
		var method = (form.getAttribute('method') || 'get').toLowerCase();
		var target = (form.getAttribute('target') || '').toLowerCase();
		if (method !== 'post' || (target && target !== '_self')) {
			nativeSubmit.call(form);
			return;
		}
		if (busy) return;
		busy = true;

		// Implicit submit / form.submit() bisa tidak membawa submitter.
		// Pakai tombol submit default agar field pemicu (mis. save_faqs,
		// blog_save, save_shortcut_cards) tetap terkirim.
		if (!submitter) {
			submitter = form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');
		}

		var action = form.getAttribute('action') || window.location.href;
		var fd;
		try {
			fd = new FormData(form);
			if (submitter && submitter.name) fd.append(submitter.name, submitter.value || '');
		} catch (e) {
			busy = false;
			nativeSubmit.call(form);
			return;
		}

		var button = submitter || form.querySelector('button[type="submit"], input[type="submit"]');
		var wasDisabled = button ? button.disabled : null;
		if (button) button.disabled = true;
		showIndicator();

		var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
		var abortTimer = null;
		if (controller) {
			abortTimer = setTimeout(function () {
				try { controller.abort(); } catch (e) {}
			}, 20000);
		}

		fetch(action, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'fetch' },
			signal: controller ? controller.signal : undefined
		})
			.then(function (res) {
				if (!isHtmlResponse(res)) {
					window.location.href = res.url || action;
					return null;
				}
				var finalUrl = res.url || action;
				try {
					var nextPath = new URL(finalUrl, window.location.href).pathname;
					if (nextPath !== window.location.pathname) {
						window.location.href = finalUrl;
						return null;
					}
				} catch (e) {}
				return res.text().then(function (html) { return { html: html, url: finalUrl }; });
			})
			.then(function (payload) {
				if (!payload) return;
				swapBody(payload.html, payload.url);
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					if (window.uiAlert) {
						window.uiAlert('Waktu simpan habis. Perubahan belum terkonfirmasi; periksa data lalu simpan lagi.');
					}
					return;
				}
				nativeSubmit.call(form);
			})
			.finally(function () {
				if (abortTimer) clearTimeout(abortTimer);
				busy = false;
				if (button) button.disabled = wasDisabled;
				hideIndicator();
			});
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || form.tagName !== 'FORM') return;
		if (event.defaultPrevented) return;
		event.preventDefault();
		softSubmit(form, event.submitter || null);
	});

	HTMLFormElement.prototype.submit = function () {
		softSubmit(this, null);
	};

	window.ukmiSoftSubmit = softSubmit;
})();
</script>
	<?php
}
