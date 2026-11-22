<?php
/**
 * src/loading_overlay.php — Komponen Loading Overlay
 * Diadaptasi dari components/loading-overlay.php proyek utama dengan logo proyek ini.
 */

if (!defined('UKMI_LOADING_OVERLAY')) {
    define('UKMI_LOADING_OVERLAY', true);
    $assetBase = mentoring_base_path(dirname(__DIR__));
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
    font-family: 'Plus Jakarta Sans', 'Manrope', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
.ukmi-loading-media picture.ukmi-loading-picture-bg {
    display: block;
    width: 100%;
}
.ukmi-loading-media img.ukmi-loading-gif {
    display: block;
    width: 100%;
    height: auto;
    border-radius: 18px;
    background: #111;
}
.ukmi-loading-media picture.ukmi-loading-picture-logo {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 38%;
    max-width: 110px;
    max-height: 110px;
    aspect-ratio: 1 / 1;
    display: block;
}
.ukmi-loading-media img.ukmi-loading-logo {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.45);
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
        <img class="ukmi-loading-gif" src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>public/assets/img/palestine.gif?v=2" alt="Animasi pemuatan" width="260" height="260">
        <picture class="ukmi-loading-picture-logo">
            <source srcset="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>public/assets/img/favicon.webp?v=2" type="image/webp">
            <img class="ukmi-loading-logo" src="<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>public/assets/img/favicon.jpeg?v=2" alt="Logo Mentoring MAI" width="110" height="110" onerror="this.onerror=null;var p=this.parentNode;if(p){var s=p.querySelectorAll('source');for(var i=0;i<s.length;i++){p.removeChild(s[i]);}}this.src='<?= htmlspecialchars($assetBase, ENT_QUOTES) ?>public/assets/img/favicon.jpeg?v=2';">
        </picture>
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
    var showDelay = 120;
    var minVisible = 420;
    var shownAt = 0;
    var done = false;
    var showTimer = null;
    var hideTimer = null;
    var limitTimer = null;

    function clearTimers() {
        if (showTimer) { clearTimeout(showTimer); showTimer = null; }
        if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
        if (limitTimer) { clearTimeout(limitTimer); limitTimer = null; }
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

    function showInitial() {
        if (done || !el) return;
        el.classList.add('is-visible');
        shownAt = Date.now();
    }

    showTimer = setTimeout(showInitial, showDelay);
    limitTimer = setTimeout(function () {
        if (!done) hide();
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

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            hide();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') return;
        if (event.defaultPrevented) return;
        if (form.hasAttribute('data-no-loading')) return;
        if (form.target && form.target !== '_self') return;
        showNow('Memproses permintaan, mohon tunggu...');
    });
})();
</script>
    <?php
}
