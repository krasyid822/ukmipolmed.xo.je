// Komponen modal UI pengganti alert()/confirm()/prompt() bawaan browser.
// Dimuat sekali; menyediakan window.uiAlert, window.uiConfirm, window.uiPrompt.
(function () {
  'use strict';
  if (window.uiAlert) return;

  var STYLE_ID = 'ui-modal-style';

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '.uim-overlay{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;padding:20px;' +
      'background:rgba(2,6,23,.65);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);' +
      'opacity:0;transition:opacity .18s ease;}' +
      '.uim-overlay.uim-open{opacity:1;}' +
      '.uim-box{width:100%;max-width:420px;background:#111827;color:#e2e8f0;border:1px solid rgba(148,163,184,.25);' +
      'border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.5);padding:22px 22px 18px;' +
      'font-family:\'Segoe UI\',Tahoma,Geneva,Verdana,sans-serif;transform:translateY(10px) scale(.98);' +
      'transition:transform .18s ease;}' +
      '.uim-overlay.uim-open .uim-box{transform:translateY(0) scale(1);}' +
      '.uim-title{margin:0 0 8px;font-size:16px;font-weight:700;color:#f8fafc;}' +
      '.uim-message{margin:0;font-size:14px;line-height:1.55;color:#cbd5e1;word-break:break-word;white-space:pre-line;}' +
      '.uim-input{width:100%;box-sizing:border-box;margin-top:14px;padding:10px 12px;border-radius:10px;' +
      'border:1px solid rgba(148,163,184,.3);background:rgba(15,23,42,.7);color:#e2e8f0;font-size:14px;font-family:inherit;}' +
      '.uim-input:focus{outline:none;border-color:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.2);}' +
      '.uim-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;}' +
      '.uim-btn{appearance:none;border:1px solid rgba(148,163,184,.3);background:rgba(148,163,184,.08);color:#e2e8f0;' +
      'font-size:14px;font-weight:600;font-family:inherit;padding:9px 16px;border-radius:10px;cursor:pointer;' +
      'transition:background .15s ease,border-color .15s ease;}' +
      '.uim-btn:hover{background:rgba(148,163,184,.16);}' +
      '.uim-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(56,189,248,.35);}' +
      '.uim-btn.uim-primary{background:#38bdf8;border-color:#38bdf8;color:#0f172a;}' +
      '.uim-btn.uim-primary:hover{background:#7dd3fc;border-color:#7dd3fc;}' +
      '.uim-btn.uim-danger{background:#f87171;border-color:#f87171;color:#0f172a;}' +
      '.uim-btn.uim-danger:hover{background:#fca5a5;border-color:#fca5a5;}' +
      '[data-theme="light"] .uim-overlay{background:rgba(34,40,37,.45);}' +
      '[data-theme="light"] .uim-box{background:#FFFFFF;color:#222825;border-color:rgba(34,40,37,.14);box-shadow:0 24px 60px rgba(34,40,37,.18);}' +
      '[data-theme="light"] .uim-title{color:#1E824C;}' +
      '[data-theme="light"] .uim-message{color:#222825;}' +
      '[data-theme="light"] .uim-input{border-color:rgba(34,40,37,.2);background:#FAF9F6;color:#222825;}' +
      '[data-theme="light"] .uim-input:focus{border-color:#1E824C;box-shadow:0 0 0 3px rgba(30,130,76,.18);}' +
      '[data-theme="light"] .uim-btn{border-color:rgba(34,40,37,.18);background:#FAF9F6;color:#222825;}' +
      '[data-theme="light"] .uim-btn:hover{background:#D8EFE2;}' +
      '[data-theme="light"] .uim-btn.uim-primary{background:#1E824C;border-color:#1E824C;color:#FFFFFF;}' +
      '[data-theme="light"] .uim-btn.uim-primary:hover{background:#16653A;border-color:#16653A;}' +
      '[data-theme="light"] .uim-btn.uim-danger{background:#DC2626;border-color:#DC2626;color:#FFFFFF;}' +
      '[data-theme="light"] .uim-btn.uim-danger:hover{background:#B91C1C;border-color:#B91C1C;}' +
      '@media (max-width:480px){.uim-box{padding:18px 16px 14px}.uim-actions{gap:8px}.uim-btn{flex:1;padding:10px 12px}}';
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = css;
    (document.head || document.documentElement).appendChild(style);
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function openModal(opts) {
    ensureStyle();
    var options = opts || {};
    return new Promise(function (resolve) {
      var previousFocus = document.activeElement;
      var overlay = document.createElement('div');
      overlay.className = 'uim-overlay';
      overlay.setAttribute('role', 'presentation');

      var box = document.createElement('div');
      box.className = 'uim-box';
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');

      if (options.title) {
        var title = document.createElement('h3');
        title.className = 'uim-title';
        title.textContent = options.title;
        box.appendChild(title);
      }

      var message = document.createElement('p');
      message.className = 'uim-message';
      message.textContent = options.message == null ? '' : String(options.message);
      box.appendChild(message);

      var input = null;
      if (options.type === 'prompt') {
        input = document.createElement('input');
        input.type = 'text';
        input.className = 'uim-input';
        input.value = options.value == null ? '' : String(options.value);
        box.appendChild(input);
      }

      var actions = document.createElement('div');
      actions.className = 'uim-actions';

      var cancelBtn = null;
      if (options.type !== 'alert') {
        cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'uim-btn';
        cancelBtn.textContent = options.cancelText || 'Batal';
        actions.appendChild(cancelBtn);
      }

      var okBtn = document.createElement('button');
      okBtn.type = 'button';
      okBtn.className = 'uim-btn ' + (options.type === 'alert' ? 'uim-primary' : (options.danger ? 'uim-danger' : 'uim-primary'));
      okBtn.textContent = options.okText || 'OK';
      actions.appendChild(okBtn);
      box.appendChild(actions);
      overlay.appendChild(box);

      var lastActive = null;
      var closed = false;

      function close(result) {
        if (closed) return;
        closed = true;
        document.removeEventListener('keydown', onKeydown, true);
        overlay.classList.remove('uim-open');
        window.setTimeout(function () {
          if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
          if (previousFocus && typeof previousFocus.focus === 'function') {
            try { previousFocus.focus(); } catch (e) { /* noop */ }
          }
        }, 180);
        resolve(result);
      }

      function onKeydown(event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          close(options.type === 'alert' ? true : (options.type === 'prompt' ? null : false));
        } else if (event.key === 'Enter' && !event.shiftKey) {
          var isTextarea = event.target && event.target.tagName === 'TEXTAREA';
          if (!isTextarea) {
            event.preventDefault();
            okBtn.click();
          }
        } else if (event.key === 'Tab') {
          var focusables = [cancelBtn, okBtn, input].filter(Boolean);
          if (!focusables.length) return;
          var idx = focusables.indexOf(document.activeElement);
          if (event.shiftKey) {
            lastActive = idx <= 0 ? focusables[focusables.length - 1] : focusables[idx - 1];
          } else {
            lastActive = idx === -1 || idx === focusables.length - 1 ? focusables[0] : focusables[idx + 1];
          }
          event.preventDefault();
          lastActive.focus();
        }
      }

      okBtn.addEventListener('click', function () {
        if (options.type === 'prompt') close(input ? input.value : '');
        else close(true);
      });
      if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
          close(options.type === 'prompt' ? null : false);
        });
      }
      overlay.addEventListener('mousedown', function (event) {
        if (event.target === overlay) close(options.type === 'alert' ? true : (options.type === 'prompt' ? null : false));
      });

      document.body.appendChild(overlay);
      document.addEventListener('keydown', onKeydown, true);
      window.requestAnimationFrame(function () { overlay.classList.add('uim-open'); });

      if (input) {
        input.focus();
        input.select();
      } else {
        okBtn.focus();
      }
    });
  }

  window.uiAlert = function (message, opts) {
    var o = Object.assign({}, opts || {}, { message: message, type: 'alert' });
    return openModal(o);
  };

  // Pengganti confirm(): pakai pada onsubmit/onclick dengan `return uiConfirm(event, '...')`.
  window.uiConfirm = function (event, message, opts) {
    if (event && typeof event.preventDefault === 'function') event.preventDefault();
    var target = event ? (event.currentTarget || event.target) : null;
    var submitter = (event && event.submitter) ? event.submitter : ((target && target.closest) ? target.closest('button[type="submit"], input[type="submit"]') : null);
    var form = null;
    if (target && target.closest) form = target.closest('form');
    else if (target && target.tagName === 'FORM') form = target;
    var o = Object.assign({}, opts || {}, { message: message, type: 'confirm' });
    if (o.danger === undefined) o.danger = true;
    openModal(o).then(function (ok) {
      if (!ok || !form) return;
      if (typeof window.ukmiSoftSubmit === 'function' && (!form.matches || !form.matches('form[data-comment-form], form.delete-comment-form'))) {
        window.ukmiSoftSubmit(form, submitter);
        return;
      }
      if (submitter) form.__ukmiLastSubmitter = submitter;
      try {
        HTMLFormElement.prototype.submit.call(form);
      } catch (e) {
        form.submit();
      }
    });
    return false;
  };

  window.uiPrompt = function (message, value, opts) {
    var o = Object.assign({}, opts || {}, { message: message, value: value, type: 'prompt' });
    return openModal(o);
  };
})();
