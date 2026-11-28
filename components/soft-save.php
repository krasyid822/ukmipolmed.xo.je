<?php
$__ukmiErrText = !empty($error) ? (string) $error : (!empty($err) ? (string) $err : '');
$__ukmiMsgText = !empty($message) ? (string) $message : (!empty($msg) ? (string) $msg : '');
$__ukmiNotifyText = $__ukmiErrText !== '' ? $__ukmiErrText : $__ukmiMsgText;
$__ukmiNotifyKind = $__ukmiErrText !== '' ? 'danger' : 'success';
if ($__ukmiNotifyText !== ''):
?>
<div id="ukmi-toast-payload" hidden data-message="<?php echo htmlspecialchars($__ukmiNotifyText, ENT_QUOTES, 'UTF-8'); ?>" data-kind="<?php echo $__ukmiNotifyKind; ?>"></div>
<?php
endif;
if (!defined('UKMI_SOFT_SAVE')) {
	define('UKMI_SOFT_SAVE', true);
	?>
<style>
.ukmi-save-indicator {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	z-index: 2147483500;
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
.ukmi-save-indicator.is-notify { pointer-events: auto; cursor: pointer; padding: 10px 16px; font-size: 13px; }
.ukmi-save-indicator.is-success {
	background: linear-gradient(90deg, #4ade80, #22c55e);
	color: #052e16;
}
.ukmi-save-indicator.is-danger {
	background: linear-gradient(90deg, #f87171, #ef4444);
	color: #fff;
}
</style>
<script>
(function () {
	if (window.__ukmiSoftSaveBound) return;
	window.__ukmiSoftSaveBound = true;

	var nativeSubmit = HTMLFormElement.prototype.submit;
	var busy = false;
	var indicator = null;
	var indicatorMode = null;
	var notifyTimer = null;

	function ensureIndicator() {
		if (indicator && indicator.isConnected) return indicator;
		indicator = document.createElement('div');
		indicator.className = 'ukmi-save-indicator';
		indicator.setAttribute('role', 'status');
		indicator.setAttribute('aria-live', 'polite');
		indicator.addEventListener('click', function () {
			if (indicatorMode === 'notify') hideIndicator(true);
		});
		(document.body || document.documentElement).appendChild(indicator);
		return indicator;
	}

	window.ukmiNotify = function (text, kind, duration) {
		if (!text) return;
		if (notifyTimer) {
			clearTimeout(notifyTimer);
			notifyTimer = null;
		}
		indicatorMode = 'notify';
		var el = ensureIndicator();
		el.classList.remove('is-success', 'is-danger');
		el.classList.add('is-notify', kind === 'danger' ? 'is-danger' : 'is-success');
		el.textContent = String(text);
		requestAnimationFrame(function () { el.classList.add('is-on'); });
		var wait = typeof duration === 'number' ? duration : (kind === 'danger' ? 8000 : 4500);
		notifyTimer = setTimeout(function () {
			hideIndicator(true);
		}, wait);
	};
	window.showAdminToast = window.ukmiNotify;

	function consumeNotifyPayload() {
		var payload = document.getElementById('ukmi-toast-payload');
		if (!payload) return;
		var msg = payload.getAttribute('data-message');
		var kind = payload.getAttribute('data-kind') || 'success';
		if (payload.parentNode) payload.parentNode.removeChild(payload);
		if (msg) window.ukmiNotify(msg, kind);
	}

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
		if (notifyTimer) {
			clearTimeout(notifyTimer);
			notifyTimer = null;
		}
		indicatorMode = 'progress';
		var el = ensureIndicator();
		el.classList.remove('is-success', 'is-danger', 'is-notify');
		el.textContent = label || 'Menyimpan perubahan...';
		requestAnimationFrame(function () { el.classList.add('is-on'); });
	}

	function hideIndicator(force) {
		if (!indicator) return;
		if (!force && indicatorMode === 'notify') return;
		if (notifyTimer) {
			clearTimeout(notifyTimer);
			notifyTimer = null;
		}
		indicatorMode = null;
		var el = indicator;
		el.classList.remove('is-on');
		setTimeout(function () {
			if (indicator === el && !el.classList.contains('is-on')) {
				if (el.parentNode) el.parentNode.removeChild(el);
				indicator = null;
			}
		}, 220);
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

	function syncCsrfTokens(doc) {
		if (!doc) return;
		var newCsrf = doc.querySelector('meta[name="csrf-token"]');
		if (!newCsrf) return;
		var token = newCsrf.getAttribute('content') || '';
		var curCsrf = document.querySelector('meta[name="csrf-token"]');
		if (curCsrf) {
			curCsrf.setAttribute('content', token);
		}
		if (token) {
			document.querySelectorAll('input[name="csrf_token"]').forEach(function (inp) {
				inp.value = token;
			});
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
		indicator = null;
		if (doc.title) document.title = doc.title;
		syncCsrfTokens(doc);
		var overlay = document.getElementById('ukmi-loading-overlay');
		if (overlay) overlay.remove();
		consumeNotifyPayload();
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
			if (!fd.has('csrf_token')) {
				var csrfMeta = document.querySelector('meta[name="csrf-token"]');
				if (csrfMeta && csrfMeta.getAttribute('content')) {
					fd.append('csrf_token', csrfMeta.getAttribute('content'));
				}
			}
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
				var doc = new DOMParser().parseFromString(payload.html, 'text/html');
				if (!doc || !doc.body || !doc.querySelector('meta[name="csrf-token"]')) {
					window.ukmiNotify('Sesi server/hosting telah kedaluwarsa. Data yang Anda input tetap dipertahankan—buka halaman ini di tab baru lalu klik Simpan lagi.', 'danger', 10000);
					form.dispatchEvent(new CustomEvent('ukmi:softsave-failed', { bubbles: true }));
					return;
				}
				var isUnlockForm = !!form.querySelector('input[name="unlock_credential_code"], input[name="unlock_division_code"], input[name="unlock_insights_code"]');
				var isAuthTransition = !!(form.querySelector('input[name="logout_all"], input[name="recover_credentials"]') || (form.querySelector('input[name="user"]') && form.querySelector('input[name="key"]')));
				var toastEl = doc.getElementById('ukmi-toast-payload');
				var toastMsg = toastEl ? (toastEl.getAttribute('data-message') || '') : '';
				var toastKind = toastEl ? (toastEl.getAttribute('data-kind') || 'success') : '';

				if (document.body.classList.contains('is-auth') && doc.body.classList.contains('is-guest') && !isAuthTransition) {
					syncCsrfTokens(doc);
					window.ukmiNotify(toastMsg || 'Sesi admin telah berakhir. Data yang Anda input tetap dipertahankan—silakan login ulang di tab baru lalu simpan lagi.', 'danger', 9000);
					form.dispatchEvent(new CustomEvent('ukmi:softsave-failed', { bubbles: true }));
					return;
				}

				if (toastKind === 'danger' && !isAuthTransition) {
					syncCsrfTokens(doc);
					window.ukmiNotify(toastMsg || 'Gagal menyimpan perubahan.', 'danger', 8000);
					form.dispatchEvent(new CustomEvent('ukmi:softsave-failed', { bubbles: true }));
					return;
				}

				if (isUnlockForm && doc.body.classList.contains('is-auth') && toastKind !== 'danger') {
					syncCsrfTokens(doc);
					var nextForm = form.nextElementSibling;
					if (nextForm && nextForm.tagName === 'FORM') {
						nextForm.removeAttribute('inert');
						nextForm.style.opacity = '';
						nextForm.style.pointerEvents = '';
					}
					var codeInput = form.querySelector('input[name="unlock_credential_code"], input[name="unlock_division_code"], input[name="unlock_insights_code"]');
					if (codeInput) codeInput.value = '';
					window.ukmiNotify(toastMsg || 'Mode superadmin aktif.', 'success');
					return;
				}

				form.dispatchEvent(new CustomEvent('ukmi:softsave-success', {
					bubbles: true,
					detail: { autosaveKey: form.getAttribute('data-autosave-key') || '' }
				}));
				swapBody(payload.html, payload.url);
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					window.ukmiNotify('Waktu simpan habis. Perubahan belum terkonfirmasi; periksa data lalu simpan lagi.', 'danger', 7000);
					return;
				}
				nativeSubmit.call(form);
			})
			.finally(function () {
				if (abortTimer) clearTimeout(abortTimer);
				busy = false;
				if (button) button.disabled = wasDisabled;
				hideIndicator(false);
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
	consumeNotifyPayload();
})();
</script>
	<?php
}
?>
