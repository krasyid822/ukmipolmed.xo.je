<?php
/**
 * view.php — Halaman Web Laporan Utuh
 * Sesuai AGENTS.md Bagian 4B:
 * Menampilkan foto dokumentasi di bagian paling atas, diikuti kartu laporan persis format pesan WhatsApp,
 * lengkap dengan tombol Salin Teks Laporan.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mentoring\Config;
use Mentoring\Crypto;
use Mentoring\S3Client;
use Mentoring\Storage;
use Mentoring\Watobot;

$id = trim($_GET['id'] ?? '');
if (empty($id)) {
    die("ID Laporan tidak ditentukan.");
}

$pointer = Storage::findPointer('laporan', $id);
if (!$pointer) {
    die("Laporan tidak ditemukan di registry lokal.");
}

// Unduh dan dekripsi data laporan
$s3 = new S3Client();
$encryptedCiphertext = $s3->getObject($pointer['s3_path']);
if (!$encryptedCiphertext) {
    die("Gagal mengambil file laporan dari storage.");
}

$report = Crypto::decryptData(
    $encryptedCiphertext,
    $pointer['crypto']['key'],
    $pointer['crypto']['iv'],
    $pointer['crypto']['tag'],
    true
);

if (!$report) {
    die("Gagal mendekripsi data laporan.");
}

// Foto dokumentasi disajikan lewat media.php (signed GET; Blomp menolak GET anonim).
// URL absolut diperlukan agar og:image valid saat halaman dibagikan langsung.
$dokRelativePath = (string)($pointer['dokumentasi_path'] ?? '');
$dokVersion = (string)($pointer['updated_at'] ?? '');
$absBase = mentoring_absolute_app_base('view');
$dokUrl = $dokRelativePath !== ''
    ? $absBase . 'media?key=' . rawurlencode($dokRelativePath) . ($dokVersion !== '' ? '&v=' . rawurlencode($dokVersion) : '')
    : '';

$botText = Watobot::buildMessageText($report);
$sentStatus = isset($_GET['sent']) ? (bool)$_GET['sent'] : null;
$queuedStatus = !empty($_GET['queued']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Mentoring - <?= htmlspecialchars($report['nama_tim']) ?></title>
    <link rel="icon" type="image/jpeg" href="public/assets/img/favicon.jpeg">
    <!-- SEO & Open Graph Meta Tags -->
    <meta name="description" content="Laporan kegiatan mentoring kelompok <?= htmlspecialchars($report['nama_tim']) ?> dengan tema <?= htmlspecialchars($report['tema']) ?>.">
    <meta property="og:title" content="Laporan Mentoring - <?= htmlspecialchars($report['nama_tim']) ?>">
    <meta property="og:description" content="Tema: <?= htmlspecialchars($report['tema']) ?> | <?= htmlspecialchars($report['tempat']) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($dokUrl) ?>">
    <meta property="og:type" content="article">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --card-border: #334155;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-green: #10b981;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            padding: 24px 16px;
            display: flex;
            justify-content: center;
        }
        .container {
            width: 100%;
            max-width: 720px;
        }
        .header-card {
            text-align: center;
            margin-bottom: 24px;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 0.825rem;
            font-weight: 600;
            background: rgba(2, 132, 199, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
            margin-bottom: 12px;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            margin-bottom: 24px;
        }
        .dok-img-wrapper {
            width: 100%;
            height: 380px;
            background: #090d16;
            position: relative;
            overflow: hidden;
        }
        .dok-img-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .dok-caption {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 16px;
            background: linear-gradient(to top, rgba(15, 23, 42, 0.95), transparent);
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .content-body {
            padding: 24px;
        }
        .title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #f1f5f9;
        }
        .meta-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-bottom: 20px;
        }
        .report-box {
            background: #0b1120;
            border: 1px solid #1e293b;
            border-radius: 12px;
            padding: 20px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.875rem;
            line-height: 1.7;
            white-space: pre-wrap;
            color: #e2e8f0;
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .btn-group {
            display: flex;
            gap: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            flex: 1;
        }
        .btn-primary {
            background: var(--primary);
            color: #ffffff;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        .btn-secondary {
            background: #334155;
            color: #f1f5f9;
        }
        .btn-secondary:hover {
            background: #475569;
        }
        .copy-toast {
            display: none;
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: #10b981;
            color: white;
            padding: 10px 20px;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 1000;
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container">
    <div class="header-card">
        <h1 class="title">Laporan Pertemuan Mentoring</h1>
        <p class="meta-subtitle"><?= htmlspecialchars($report['nama_tim']) ?> &bull; <?= htmlspecialchars($report['tanggal']) ?></p>
    </div>

    <?php if ($sentStatus === true): ?>
        <div class="alert-success">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            Laporan berhasil disimpan dan pesan telah dikirimkan ke grup WhatsApp via bot!
        </div>
    <?php elseif ($queuedStatus): ?>
        <div style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.5); color: #fbbf24; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 0.9rem; line-height: 1.55;">
            Laporan berhasil disimpan. Pengiriman ke grup WhatsApp sedang dalam antrean karena API bot belum tersedia, dan akan dicoba ulang otomatis saat API membaik.
        </div>
    <?php endif; ?>

    <div class="card">
        <!-- 1. Foto Dokumentasi di Paling Atas Sesuai Bagian 4B -->
        <div class="dok-img-wrapper">
            <img src="<?= htmlspecialchars($dokUrl) ?>" alt="Dokumentasi Mentoring <?= htmlspecialchars($report['nama_tim']) ?>" loading="lazy">
            <div class="dok-caption">
                📷 Dokumentasi Kegiatan &bull; <?= htmlspecialchars($report['tempat']) ?>
            </div>
        </div>

        <!-- 2. Rincian Laporan Teks Persis Format Pesan WhatsApp -->
        <div class="content-body">
            <div class="report-box" id="reportText"><?= htmlspecialchars($botText) ?></div>

            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="copyReport()">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    Salin Teks Laporan
                </button>
                <a href="./" class="btn btn-secondary">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path></svg>
                    Buat Laporan Baru
                </a>
            </div>
        </div>
    </div>
</div>

<div class="copy-toast" id="toast">✓ Teks laporan berhasil disalin ke clipboard!</div>

<script src="public/assets/js/modal.js?v=2"></script>
<script>
function copyReport() {
    const text = document.getElementById('reportText').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.getElementById('toast');
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 2500);
    }).catch(err => {
        Modal.error('Gagal menyalin teks secara otomatis. Silakan salin manual.');
    });
}
</script>

</body>
</html>
