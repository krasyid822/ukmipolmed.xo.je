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

	function resolveActionLabel(form, submitter) {
		var explicit = (submitter && submitter.getAttribute('data-progress-label')) || (form && form.getAttribute('data-progress-label'));
		if (explicit) return explicit;
		if (!form) return 'Menyimpan perubahan...';

		if (form.querySelector('input[name="recover_credentials"]')) return 'Memulihkan kredensial...';
		if (form.querySelector('input[name="user"]') && form.querySelector('input[name="key"]')) return 'Memproses login...';
		if (form.querySelector('input[name="unlock_credential_code"], input[name="unlock_division_code"], input[name="unlock_insights_code"]')) return 'Membuka akses superadmin...';
		if (form.querySelector('input[name="logout_all"]')) return 'Mengeluarkan semua sesi...';
		if (form.querySelector('input[name="reset_insights"]')) return 'Mengosongkan insight...';
		if (form.querySelector('input[name="create_author"]')) return 'Membuat akun penulis...';
		if (form.querySelector('input[name^="delete_"]') || (submitter && submitter.name && submitter.name.indexOf('delete') === 0)) return 'Menghapus data...';
		if (form.querySelector('input[name^="archive_"]')) return 'Mengarsipkan data...';
		if (form.querySelector('input[name^="restore_"]')) return 'Memulihkan dari arsip...';
		if (form.querySelector('input[name="move_idx"]')) return 'Mengubah urutan...';
		if (form.querySelector('input[name="edit_idx"]') || (form.querySelector('input[name="blog_edit_idx"]') && form.id !== 'blog-form' && !(submitter && submitter.name === 'blog_save'))) return 'Memuat data ke formulir...';

		if (form.querySelector('input[name="comment_moderate"]')) {
			var actionInput = form.querySelector('input[name="comment_action"]');
			var act = actionInput ? actionInput.value : '';
			if (act === 'delete') return 'Menghapus komentar...';
			if (act === 'suspend') return 'Menangguhkan komentar...';
			if (act === 'restore') return 'Memulihkan komentar...';
			if (act === 'edit') return 'Menyimpan edit komentar...';
		}
		if (form.hasAttribute('data-comment-form')) return 'Mengirim komentar...';

		var btnText = submitter ? (submitter.textContent || submitter.value || '').trim().toLowerCase() : '';
		if (btnText.indexOf('hapus') === 0) return 'Menghapus data...';
		if (btnText.indexOf('arsipkan') === 0) return 'Mengarsipkan data...';
		if (btnText.indexOf('pulihkan') === 0) return 'Memulihkan data...';
		if (btnText.indexOf('masuk') === 0) return 'Memproses login...';
		if (btnText.indexOf('buka') === 0) return 'Membuka akses...';
		if (btnText.indexOf('reset') === 0) return 'Mereset ke default...';

		return 'Menyimpan perubahan...';
	}

	function showIndicator(label) {
		if (!indicator) {
			indicator = document.createElement('div');
			indicator.className = 'ukmi-save-indicator';
			indicator.setAttribute('role', 'status');
			indicator.setAttribute('aria-live', 'polite');
			(document.body || document.documentElement).appendChild(indicator);
		}
		indicator.textContent = label || 'Menyimpan perubahan...';
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

	function stripFlashParams(rawUrl) {
		try {
			var u = new URL(rawUrl || window.location.href, window.location.href);
			['comment_msg', 'msg', 'saved', 'deleted'].forEach(function (k) {
				u.searchParams.delete(k);
			});
			return u.pathname + u.search + u.hash;
		} catch (e) {
			return rawUrl || window.location.href;
		}
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
		try { sessionStorage.removeItem('admin_scroll_y'); } catch (e) {}
		if (window.history && window.history.replaceState) {
			try { window.history.replaceState(null, '', stripFlashParams(finalUrl || window.location.href)); } catch (e) {}
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

		if (!submitter && form.__ukmiLastSubmitter) {
			submitter = form.__ukmiLastSubmitter;
		}
		try { delete form.__ukmiLastSubmitter; } catch (e) { form.__ukmiLastSubmitter = null; }

		// Implicit submit / form.submit() bisa tidak membawa submitter.
		// Pakai tombol submit default agar field pemicu (mis. save_faqs,
		// blog_save, save_shortcut_cards) tetap terkirim.
		if (!submitter) {
			submitter = form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');
		}

		var action = form.getAttribute('action') || stripFlashParams(window.location.href);
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
		showIndicator(resolveActionLabel(form, submitter));

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
