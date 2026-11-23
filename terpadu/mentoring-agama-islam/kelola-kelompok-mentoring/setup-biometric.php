<?php
/**
 * setup-biometric.php — Halaman Pendaftaran Biometrik Satu Pakai
 * Diakses lewat link sekali pakai yang dihasilkan admin melalui
 * Data Master → Pengaturan (mentor atau mentee perwakilan).
 * Token habis digunakan setelah setup berhasil (link hilang).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mentoring\Biometric;
use Mentoring\Config;

$appBase = mentoring_request_app_base('setup-biometric');
if ($appBase === null) {
    $appBase = mentoring_base_path(__DIR__);
}
$appBase = rtrim($appBase, '/') . '/';
$basePath = $appBase;

$token = trim((string)($_GET['token'] ?? ''));
$setup = Biometric::validateSetupToken($token);

if ($setup === null) {
    http_response_code(400);
    $h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Link Tidak Valid</title>
        <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css?v=4">
    </head>
    <body>
    <?php mentoring_loading_overlay(); ?>
    <div class="container" style="max-width: 480px; margin: 60px auto;">
        <div class="card" style="border-color: #7f1d1d;">
            <h3 class="card-title" style="color: #f87171;">Link Tidak Valid</h3>
            <p style="color: var(--text-muted);">Link pendaftaran biometrik tidak valid, sudah kedaluwarsa, atau sudah digunakan.</p>
            <a href="<?= $h($appBase) ?>" class="btn-action">Kembali ke Form Pelaporan</a>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$entityType = (string)($setup['entity_type'] ?? '');
$entityId = (string)($setup['entity_id'] ?? '');
$entity = Biometric::enrollmentStatus($entityType, $entityId);

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Biometrik — <?= $h($entity['entity_name']) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= $h($appBase) ?>public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css?v=4">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/biometric.css?v=6">
    <style>
        .setup-page { max-width: 480px; margin: 32px auto; }
        .setup-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 16px; padding: 26px; }
        .setup-badge { display: inline-flex; gap: 8px; align-items: center; padding: 6px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; background: rgba(2, 132, 199, 0.12); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); margin-bottom: 14px; }
        .pattern-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0; aspect-ratio: 1; }
        .pattern-dot { width: 100%; aspect-ratio: 1; border-radius: 50%; background: #0b1120; border: 2px solid #334155; position: relative; cursor: pointer; transition: all 0.15s; }
        .pattern-dot.active { background: #38bdf8; border-color: #38bdf8; box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.25); }
        .pattern-dot.active path { stroke: #ffffff; }
        .pattern-trail { position: absolute; inset: 0; pointer-events: none; border-radius: 50%; stroke: #38bdf8; stroke-width: 3; fill: none; opacity: 0; transition: opacity 0.15s; }
        .pattern-trail.draw { opacity: 1; }
        .pattern-index { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); pointer-events: none; z-index: 2; }
        .pattern-dot.active .pattern-index { color: #ffffff; }
        .webauthn-btn { width: 100%; padding: 14px; border-radius: 10px; border: 2px dashed #334155; background: #0b1120; color: #cbd5e1; font-size: 0.9rem; font-weight: 600; font-family: inherit; cursor: pointer; margin-bottom: 10px; transition: all 0.2s; }
        .webauthn-btn:not(:disabled):hover { border-color: #38bdf8; color: #38bdf8; background: rgba(56, 189, 248, 0.08); }
        .webauthn-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .method-label { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin: 18px 0 8px; display: block; }
        .pattern-value { font-family: 'JetBrains Mono', monospace; font-size: 0.85rem; color: #38bdf8; word-break: break-all; }
        .hint { font-size: 0.82rem; color: var(--text-muted); margin-top: 8px; }
        .expires { font-size: 0.78rem; color: #94a3b8; margin-top: 12px; }

        @media (max-width: 480px) {
            .setup-page { max-width: 100%; margin: 16px auto; padding: 0 10px; }
            .setup-card { padding: 16px; border-radius: 12px; }
            .setup-badge { font-size: 0.75rem; padding: 5px 10px; margin-bottom: 10px; }
            .pattern-grid { gap: 8px; max-width: 180px; margin: 10px 0; }
            .pattern-dot { min-width: 44px; min-height: 44px; }
            .webauthn-btn { padding: 12px; font-size: 0.88rem; margin-bottom: 8px; }
            .method-label { margin: 14px 0 7px; font-size: 0.75rem; }
            .pattern-value { font-size: 0.8rem; }
            .hint { font-size: 0.78rem; margin-top: 6px; }
        }
        @media (max-width: 360px) {
            .pattern-grid { max-width: 150px; gap: 7px; }
            .pattern-dot { min-width: 40px; min-height: 40px; }
            .webauthn-btn { font-size: 0.85rem; }
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container">
    <div class="setup-page">
        <header class="page-header">
            <h1 class="page-title">Pendaftaran Biometrik</h1>
            <p class="page-subtitle">Buat data biometrik untuk <b><?= $h($entity['entity_name']) ?></b> (<?= $h(ucfirst($entityType)) ?>)</p>
            <div class="subpage-back">
                <a href="<?= $h($basePath) ?>">&larr; Kembali ke Form Pelaporan</a>
            </div>
        </header>

        <span class="setup-badge">Link sekali pakai • berlaku hingga <?= $h(date('d M Y H:i', strtotime($setup['expires_at']))) ?> WIB</span>

        <div class="card setup-card">
            <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 18px;">
                Pilih cara pendaftaran data biometrik Anda. Gunakan sidik jari/wajah jika sensor tersedia,
                atau pola 3×3 sebagai cara konvensional.
            </p>

            <div class="method-group">
                <span class="method-label">Biometrik Perangkat</span>
                <button type="button" class="webauthn-btn" id="btnWebauthn" disabled>
                    <span id="webauthn-label">Memeriksa sensor biometrik...</span>
                </button>
                <p class="hint">Menggunakan fingerprint atau wajah (WebAuthn). Dihasilkan oleh perangkat Anda.</p>
            </div>

            <div class="method-group">
                <span class="method-label">Pola 3×3 (Konvensional)</span>
                <div class="pattern-grid" id="patternGrid"></div>
                <div class="pattern-value" id="patternValue">Belum ada pola</div>
                <button type="button" class="submit-btn" id="btnClearPattern" style="padding: 8px 14px; font-size: 0.82rem;">Hapus Pola</button>
                <p class="hint">Gambar pola dengan menggabungkan minimal 3 titik. Pola ini akan dijadikan kunci verifikasi.</p>
            </div>

            <div style="margin-top: 22px;">
                <button type="button" class="submit-btn" id="btnDaftar" disabled>
                    Daftarkan Biometrik
                </button>
            </div>

            <div id="setupResult" style="margin-top: 14px; display: none;"></div>
        </div>
    </div>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/biometric.js?v=20261122-1"></script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(regs => regs.forEach(r => r.update())).catch(() => {});
}
if ('caches' in window) {
    caches.keys().then(keys => keys.forEach(k => {
        if ((k.startsWith('app-shell-') || k.startsWith('runtime-')) && !k.endsWith('mai-pwa-v20261122-1')) {
            caches.delete(k);
        }
    })).catch(() => {});
}
document.addEventListener('DOMContentLoaded', () => {
    const entityType = <?= json_encode($entityType) ?>;
    const entityId = <?= json_encode($entityId) ?>;
    const token = <?= json_encode($token) ?>;

    const bio = new BiometricClient('<?= $h($appBase) ?>api');

    // Cek ketersediaan WebAuthn
    const btnWebauthn = document.getElementById('btnWebauthn');
    const webauthnLabel = document.getElementById('webauthn-label');
    if (typeof PublicKeyCredential !== 'undefined' && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
        PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then(ok => {
            if (ok) {
                btnWebauthn.disabled = false;
                webauthnLabel.textContent = 'Sidik Jari / Wajah (tersedia)';
            } else {
                webauthnLabel.textContent = 'Sensor biometrik tidak tersedia';
            }
        }).catch(() => {
            webauthnLabel.textContent = 'Sensor biometrik tidak tersedia';
        });
    } else {
        webauthnLabel.textContent = 'Biometrik perangkat tidak didukung';
    }

    btnWebauthn.addEventListener('click', async () => {
        btnWebauthn.disabled = true;
        if (typeof window.ukmiShowLoadingOverlay === 'function') {
            window.ukmiShowLoadingOverlay('Mendaftarkan data biometrik...');
        }
        try {
            const res = await bio.registerWebauthn(entityType, entityId, token);
            if (res.success) {
                showResult('success', 'Biometrik perangkat berhasil didaftarkan.');
                setTimeout(() => { window.location.href = '<?= $h($basePath) ?>'; }, 1200);
            } else {
                btnWebauthn.disabled = false;
                showResult('error', res.message || 'Pendaftaran biometrik gagal.');
            }
        } catch (err) {
            btnWebauthn.disabled = false;
            showResult('error', 'Terjadi kesalahan: ' + err.message);
        } finally {
            if (typeof window.ukmiHideLoadingOverlay === 'function') {
                window.ukmiHideLoadingOverlay();
            }
        }
    });

    // Pola 3x3
    const grid = document.getElementById('patternGrid');
    const valueEl = document.getElementById('patternValue');
    const btnClear = document.getElementById('btnClearPattern');
    const btnDaftar = document.getElementById('btnDaftar');
    const result = document.getElementById('setupResult');

    const dots = [];
    for (let i = 1; i <= 9; i++) {
        const dot = document.createElement('div');
        dot.className = 'pattern-dot';
        dot.dataset.index = i;
        dot.innerHTML = '<svg class="pattern-trail" id="trail-' + i + '"></svg><div class="pattern-index">' + i + '</div>';
        grid.appendChild(dot);
        dots.push(dot);
    }

    let active = [];
    let drawing = false;

    function redraw() {
        dots.forEach(d => {
            const activeIdx = active.includes(d.dataset.index);
            d.classList.toggle('active', activeIdx);
        });
        valueEl.textContent = active.length ? 'Pola: ' + active.join('') : 'Belum ada pola';
        btnDaftar.disabled = active.length < 3;
    }

    function startDraw(e) {
        if (e.target.closest('.pattern-dot')) {
            drawing = true;
            touchDot(e.target.closest('.pattern-dot'));
        }
    }

    function moveDraw(e) {
        if (!drawing) return;
        const pt = e.touches && e.touches[0] ? e.touches[0] : e;
        const dot = document.elementFromPoint(pt.clientX, pt.clientY)?.closest('.pattern-dot');
        if (dot && dot.closest('#patternGrid') === grid) {
            touchDot(dot);
        }
    }

    function endDraw() { drawing = false; }

    function touchDot(dot) {
        const idx = dot.dataset.index;
        if (active.includes(idx)) return;
        active.push(idx);
        redraw();
    }

    grid.addEventListener('mousedown', startDraw);
    grid.addEventListener('mousemove', moveDraw);
    grid.addEventListener('mouseup', endDraw);
    grid.addEventListener('mouseleave', endDraw);
    grid.addEventListener('touchstart', (e) => { e.preventDefault(); startDraw(e); }, { passive: false });
    grid.addEventListener('touchmove', (e) => { e.preventDefault(); moveDraw(e); }, { passive: false });
    grid.addEventListener('touchend', endDraw);
    grid.addEventListener('touchcancel', endDraw);

    btnClear.addEventListener('click', () => {
        active = [];
        redraw();
    });

    btnDaftar.addEventListener('click', async () => {
        const pattern = active.join('');
        if (pattern.length < 3) return;
        btnDaftar.disabled = true;
        if (typeof window.ukmiShowLoadingOverlay === 'function') {
            window.ukmiShowLoadingOverlay('Mendaftarkan pola biometrik...');
        }
        try {
            const res = await bio.registerPattern(entityType, entityId, pattern, token);
            if (res.success) {
                showResult('success', 'Pola 3×3 berhasil didaftarkan.');
                setTimeout(() => { window.location.href = '<?= $h($basePath) ?>'; }, 1200);
            } else {
                btnDaftar.disabled = false;
                showResult('error', res.message || 'Pendaftaran pola gagal.');
            }
        } catch (err) {
            btnDaftar.disabled = false;
            showResult('error', 'Terjadi kesalahan: ' + err.message);
        } finally {
            if (typeof window.ukmiHideLoadingOverlay === 'function') {
                window.ukmiHideLoadingOverlay();
            }
        }
    });

    function showResult(type, message) {
        result.style.display = 'block';
        if (type === 'success') {
            result.className = '';
            result.style.background = 'rgba(16, 185, 129, 0.12)';
            result.style.border = '1px solid rgba(16, 185, 129, 0.3)';
            result.style.color = '#34d399';
            result.style.padding = '12px';
            result.style.borderRadius = '8px';
        } else {
            result.className = '';
            result.style.background = 'rgba(239, 68, 68, 0.15)';
            result.style.border = '1px solid #ef4444';
            result.style.color = '#fca5a5';
            result.style.padding = '12px';
            result.style.borderRadius = '8px';
        }
        result.innerHTML = message;
    }
});
</script>

</body>
</html>
