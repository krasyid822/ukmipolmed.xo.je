	<script>
		const loadingEl = document.getElementById('page-loading');
		const blogSlug = <?php echo json_encode($filterSlug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

		window.addEventListener('load', () => {
			if (loadingEl) {
				loadingEl.classList.add('fade-out');
				setTimeout(() => loadingEl.remove(), 600);
			}
			trackBlogView();
			loadBlogViews();
		});

		// Keyboard komentar: emoji, stiker GIF S3, dan embed HTML
		(function(){
			const EMOJI = ['😀','😁','😂','🤣','😊','😍','🥰','😘','😎','🤔','😅','😇','🙂','😉','😢','😭','😡','🥺','😴','🤗','😱','🤩','😋','😌','👍','👎','👏','🙏','🤲','🤝','💪','🫶','❤️','🧡','💛','💚','💙','💜','🔥','🎉','✨','💯','🌟','🌙','🕌','🕋','📖','☪️','🤲🏻','🌸','🍃'];
			function el(tag, cls, html) {
				const n = document.createElement(tag);
				if (cls) n.className = cls;
				if (html !== undefined) n.innerHTML = html;
				return n;
			}

			function initForm(form) {
				if (form.dataset.ckReady === '1') return;
				const body = form.querySelector('textarea[name="comment_body"]');
				if (!body) return;
				form.dataset.ckReady = '1';

				const hidden = el('input');
				hidden.type = 'hidden';
				hidden.name = 'comment_sticker';
				hidden.value = '';
				form.appendChild(hidden);

				const kb = el('div', 'comment-keyboard');
				const toolbar = el('div', 'ck-toolbar');
				const btnEmoji = el('button', 'ck-btn', '😊 Emoji');
				btnEmoji.type = 'button';
				btnEmoji.setAttribute('aria-expanded', 'false');
				const btnGif = el('button', 'ck-btn', '🖼️ Stiker GIF');
				btnGif.type = 'button';
				btnGif.setAttribute('aria-expanded', 'false');
				const btnHtml = el('button', 'ck-btn', '🔗 Embed HTML');
				btnHtml.type = 'button';
				btnHtml.setAttribute('aria-expanded', 'false');
				toolbar.appendChild(btnEmoji);
				toolbar.appendChild(btnGif);
				toolbar.appendChild(btnHtml);

				const panel = el('div', 'ck-panel');
				panel.hidden = true;

				const emojiPanel = el('div');
				emojiPanel.hidden = true;
				const eg = el('div', 'ck-emoji-grid');
				EMOJI.forEach(function(ch){
					const b = el('button', 'ck-emoji', ch);
					b.type = 'button';
					b.setAttribute('aria-label', 'Sisipkan emoji ' + ch);
					b.addEventListener('click', function(){ insertText(body, ch); });
					eg.appendChild(b);
				});
				emojiPanel.appendChild(eg);

				const gifPanel = el('div');
				gifPanel.hidden = true;
				const hint = el('p', 'ck-hint', 'Unggah atau tempel stiker GIF/PNG/WebP dari clipboard (maks 2 MB). Stiker disimpan di S3 dan tampil di komentar.');
				const pasteZone = el('div', 'ck-paste', 'Klik di sini lalu tekan Ctrl+V untuk menempel GIF dari clipboard');
				pasteZone.contentEditable = 'true';
				pasteZone.spellcheck = false;
				pasteZone.setAttribute('role', 'textbox');
				pasteZone.setAttribute('aria-label', 'Area tempel GIF dari clipboard');
				const pasteBtn = el('button', 'ck-btn', '📋 Tempel dari Clipboard');
				pasteBtn.type = 'button';
				const uploadBtn = el('button', 'ck-btn', '📁 Pilih GIF');
				uploadBtn.type = 'button';
				const pasteActions = el('div', 'ck-paste-actions');
				const fileInput = el('input');
				fileInput.type = 'file';
				fileInput.accept = 'image/gif,image/png,image/webp,image/jpeg';
				fileInput.hidden = true;
				const errEl = el('div', 'ck-error');

				uploadBtn.addEventListener('click', function(){ fileInput.click(); });
				fileInput.addEventListener('change', function(){
					const file = fileInput.files && fileInput.files[0];
					fileInput.value = '';
					if (!file) return;
					uploadSticker(form, file, uploadBtn, errEl);
				});

				function clipboardImageFile(items) {
					if (!items) return null;
					for (let i = 0; i < items.length; i++) {
						const it = items[i];
						if (it && it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
							const f = it.getAsFile();
							if (f) return f;
						}
					}
					return null;
				}

				function clipboardImageUrl(dt) {
					let text = '';
					try { text = (dt.getData('text/plain') || '').trim(); } catch (e) {}
					if (!text) return '';
					if (/^(https?:\/\/|data:image\/)/i.test(text) && /(\.gif|\.png|\.jpe?g|\.webp)([?#]|$)/i.test(text)) return text;
					return '';
				}

				function useImageUrl(url) {
					embedInput.value = '<img src="' + url.replace(/"/g, '&quot;') + '" alt="GIF dari clipboard">';
					panel.hidden = false;
					toggle('html');
					errEl.textContent = '';
				}

				pasteZone.addEventListener('paste', function(ev){
					const dt = ev.clipboardData;
					ev.preventDefault();
					if (!dt) {
						errEl.textContent = 'Clipboard tidak dapat dibaca.';
						return;
					}
					const file = clipboardImageFile(dt.items);
					if (file) {
						errEl.textContent = '';
						uploadSticker(form, file, uploadBtn, errEl);
						return;
					}
					const url = clipboardImageUrl(dt);
					if (url) { useImageUrl(url); return; }
					errEl.textContent = 'Clipboard tidak berisi gambar/GIF atau URL gambar.';
				});

				form.addEventListener('paste', function(ev){
					if (ev.target === pasteZone) return;
					const dt = ev.clipboardData;
					if (!dt) return;
					const file = clipboardImageFile(dt.items);
					if (!file) return;
					ev.preventDefault();
					errEl.textContent = '';
					uploadSticker(form, file, uploadBtn, errEl);
				});

				pasteBtn.addEventListener('click', function(){
					errEl.textContent = '';
					if (!navigator.clipboard || !navigator.clipboard.read) {
						errEl.textContent = 'Browser ini tidak mendukung baca clipboard otomatis. Gunakan Ctrl+V di area tempel.';
						pasteZone.focus();
						return;
					}
					const oldLabel = pasteBtn.textContent;
					pasteBtn.disabled = true;
					pasteBtn.textContent = 'Membaca...';
					navigator.clipboard.read().then(function(items){
						for (let i = 0; i < items.length; i++) {
							const type = (items[i].types || []).find(function(t){ return t.indexOf('image/') === 0; });
							if (!type) continue;
							return items[i].getType(type).then(function(blob){
								const ext = type === 'image/gif' ? 'gif' : (type === 'image/png' ? 'png' : (type === 'image/webp' ? 'webp' : 'jpg'));
								const file = new File([blob], 'clipboard.' + ext, { type: type });
								uploadSticker(form, file, uploadBtn, errEl);
							});
						}
						throw new Error('Clipboard tidak berisi gambar/GIF.');
					}).catch(function(e){
						errEl.textContent = (e && e.name === 'NotAllowedError')
							? 'Izin clipboard ditolak. Tempel manual dengan Ctrl+V.'
							: ((e && e.message) || 'Gagal membaca clipboard.');
					}).finally(function(){
						pasteBtn.disabled = false;
						pasteBtn.textContent = oldLabel;
					});
				});

				gifPanel.appendChild(hint);
				gifPanel.appendChild(pasteZone);
				pasteActions.appendChild(pasteBtn);
				pasteActions.appendChild(uploadBtn);
				gifPanel.appendChild(pasteActions);
				gifPanel.appendChild(fileInput);
				gifPanel.appendChild(errEl);

				const htmlPanel = el('div');
				htmlPanel.hidden = true;
				const htmlHint = el('p', 'ck-hint', 'Tempel kode embed HTML (iframe, gambar, video, atau blockquote). Hanya tag aman yang ditampilkan dan disimpan bersama komentar.');
				const embedInput = el('textarea', 'ck-embed-input');
				embedInput.name = 'comment_embed';
				embedInput.rows = 4;
				embedInput.placeholder = '<iframe src="https://..." ...></iframe>';
				htmlPanel.appendChild(htmlHint);
				htmlPanel.appendChild(embedInput);

				panel.appendChild(emojiPanel);
				panel.appendChild(gifPanel);
				panel.appendChild(htmlPanel);

				const preview = el('div', 'ck-preview', '<img alt="Pratinjau stiker" src=""><button type="button" class="ck-clear">Hapus stiker</button>');
				preview.querySelector('.ck-clear').addEventListener('click', function(){
					hidden.value = '';
					preview.classList.remove('is-on');
					preview.querySelector('img').removeAttribute('src');
				});

				kb.appendChild(toolbar);
				kb.appendChild(panel);
				kb.appendChild(preview);
				if (body.parentNode) body.parentNode.insertBefore(kb, body.nextSibling); else form.appendChild(kb);

				const panels = {
					emoji: { panel: emojiPanel, btn: btnEmoji },
					gif: { panel: gifPanel, btn: btnGif },
					html: { panel: htmlPanel, btn: btnHtml }
				};
				function toggle(which) {
					const target = panels[which];
					const wasOpen = !target.panel.hidden;
					Object.keys(panels).forEach(function(k){
						panels[k].panel.hidden = true;
						panels[k].btn.setAttribute('aria-expanded', 'false');
					});
					if (wasOpen) {
						panel.hidden = true;
					} else {
						target.panel.hidden = false;
						panel.hidden = false;
						target.btn.setAttribute('aria-expanded', 'true');
					}
				}
				btnEmoji.addEventListener('click', function(){ toggle('emoji'); });
				btnGif.addEventListener('click', function(){ toggle('gif'); });
				btnHtml.addEventListener('click', function(){ toggle('html'); });

				form.__ckSetSticker = function(key, url) {
					hidden.value = key || '';
					const img = preview.querySelector('img');
					if (key && url) {
						img.src = url;
						preview.classList.add('is-on');
					} else {
						img.removeAttribute('src');
						preview.classList.remove('is-on');
					}
				};

				form.addEventListener('submit', function(ev){
					if (body.value.trim() === '' && hidden.value === '' && embedInput.value.trim() === '') {
						ev.preventDefault();
						const msg = 'Tulis komentar, pilih stiker, atau tempel embed HTML terlebih dahulu.';
						if (window.uiAlert) window.uiAlert(msg); else alert(msg);
						body.focus();
					}
				});
			}

			function insertText(ta, text) {
				const start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
				const end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;
				ta.value = ta.value.slice(0, start) + text + ta.value.slice(end);
				const pos = start + text.length;
				try { ta.setSelectionRange(pos, pos); } catch (e) {}
				ta.dispatchEvent(new Event('input', { bubbles: true }));
				ta.focus();
			}

			function uploadSticker(form, file, btn, errEl) {
				errEl.textContent = '';
				if (file.size > 2 * 1024 * 1024) {
					errEl.textContent = 'Ukuran stiker maksimal 2 MB.';
					return;
				}
				const slugInput = form.querySelector('input[name="comment_post_slug"]');
				const csrfInput = form.querySelector('input[name="comment_csrf"]');
				const fd = new FormData();
				fd.append('action', 'upload_sticker');
				fd.append('comment_csrf', csrfInput ? csrfInput.value : '');
				fd.append('comment_post_slug', slugInput ? slugInput.value : '');
				fd.append('sticker', file, file.name || 'sticker.gif');
				btn.disabled = true;
				const oldLabel = btn.textContent;
				btn.textContent = 'Mengunggah...';
				fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json().catch(function(){ return { ok:false, error:'Respons server tidak valid.' }; }); })
					.then(function(data){
						if (!data || !data.ok) throw new Error((data && data.error) || 'Gagal mengunggah stiker.');
						if (typeof form.__ckSetSticker === 'function') form.__ckSetSticker(data.key, data.url);
					})
					.catch(function(e){ errEl.textContent = e.message || 'Gagal mengunggah stiker.'; })
					.finally(function(){ btn.disabled = false; btn.textContent = oldLabel; });
			}

			function boot() {
				document.querySelectorAll('form[data-comment-form]').forEach(initForm);
			}
			window.__ukmiBootComments = boot;
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', boot);
			} else {
				boot();
			}
		})();

		// Reply form toggles
		(function(){
			document.addEventListener('click', function(e){
				const btn = e.target.closest && e.target.closest('.reply-btn');
				if (!btn) return;
				e.preventDefault();
				const pid = btn.getAttribute('data-parent-id');
				if (!pid) return;
				const form = document.querySelector('.reply-form[data-parent="' + pid + '"]');
				if (!form) return;
				form.style.display = form.style.display === 'none' ? 'block' : 'none';
			});
		})();

		// Show delete buttons only for pending comment IDs stored in persistent cookie
		(function(){
			function parsePending() {
				const m = document.cookie.match(/(?:^|; )pending_comments=([^;]+)/);
				if (!m) return [];
				try { return decodeURIComponent(m[1]).split(',').map(s => s.trim()).filter(Boolean); } catch (e) { return []; }
			}
			function applyPendingDelete() {
				const pend = parsePending();
				if (pend.length === 0) return;
				document.querySelectorAll('form.delete-comment-form').forEach(f => {
					const idInput = f.querySelector('input[name="comment_id"]');
					if (!idInput) return;
					const id = (idInput.value || '').trim();
					if (id && pend.indexOf(id) !== -1) {
						const btn = f.querySelector('button');
						if (btn) btn.style.display = 'inline-flex';
					}
				});
			}
			window.__ukmiApplyPendingDelete = applyPendingDelete;
			applyPendingDelete();
		})();

		// Kirim komentar/hapus komentar tanpa memuat ulang seluruh halaman.
		(function(){
			const nativeSubmit = HTMLFormElement.prototype.submit;
			const isCommentForm = function (form) {
				return !!(form && form.matches && form.matches('form[data-comment-form], form.delete-comment-form'));
			};

			function refreshCard(html) {
				const doc = new DOMParser().parseFromString(html, 'text/html');
				const current = document.getElementById('comment-card');
				const fresh = doc.getElementById('comment-card');
				if (!current || !fresh) {
					window.location.reload();
					return;
				}
				const y = window.scrollY;
				current.replaceWith(fresh);
				if (typeof window.__ukmiBootComments === 'function') window.__ukmiBootComments();
				if (typeof window.__ukmiApplyPendingDelete === 'function') window.__ukmiApplyPendingDelete();
				window.scrollTo(0, y);
			}

			function submitCommentForm(form) {
				if (form.dataset.ajaxBusy === '1') return;
				form.dataset.ajaxBusy = '1';
				const button = form.querySelector('button[type="submit"]');
				if (button) button.disabled = true;
				fetch(form.getAttribute('action') || window.location.href, {
					method: 'POST',
					body: new FormData(form),
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'fetch' }
				})
					.then(function (res) {
						if (!res.ok) throw new Error('HTTP ' + res.status);
						return res.text();
					})
					.then(refreshCard)
					.catch(function () {
						form.dataset.ajaxBusy = '';
						if (button) button.disabled = false;
						nativeSubmit.call(form);
					});
			}

			document.addEventListener('submit', function (event) {
				const form = event.target;
				if (!isCommentForm(form)) return;
				if (event.defaultPrevented) return;
				event.preventDefault();
				submitCommentForm(form);
			});

			// Kompatibel dengan uiConfirm yang memanggil form.submit() langsung.
			HTMLFormElement.prototype.submit = function () {
				if (isCommentForm(this)) {
					submitCommentForm(this);
					return;
				}
				nativeSubmit.call(this);
			};
		})();

		function sendInsight(name) {
			if (!name) return;
			fetch('/status?event=' + encodeURIComponent(name), {
				method: 'POST',
				keepalive: true,
			}).catch(() => {});
		}

		function trackBlogView() {
			if (!blogSlug) return;
			const slugEvent = String(blogSlug).replace(/[^a-zA-Z0-9_-]/g, '');
			if (slugEvent) {
				sendInsight('blog_view_' + slugEvent);
			}
		}

		async function loadBlogViews() {
			const inlineEl = document.getElementById('blog-views-inline');
			if (!inlineEl) return;
			try {
				const res = await fetch('/status?view=json');
				const data = await res.json();
				const bySlug = data?.org_specific?.blog_views_by_slug || {};
				const views = blogSlug && bySlug[blogSlug];
				if (typeof views === 'number') {
					inlineEl.textContent = views.toLocaleString('id-ID');
				}
			} catch (e) {
				console.warn('Load blog views failed', e);
			}
		}
	</script>
