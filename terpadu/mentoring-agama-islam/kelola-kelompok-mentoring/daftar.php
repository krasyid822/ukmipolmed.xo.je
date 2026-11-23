<?php
/**
 * daftar.php — Halaman Publik Form Pendaftaran Mentoring Agama Islam (MAI)
 *
 * Menyediakan form pendaftaran calon mentee dengan:
 * - Showcase Biodata Mentor (terfilter otomatis sesuai gender pendaftar)
 * - Pilihan mentor preferensi (keputusan akhir tetap oleh Divisi Mentoring)
 * - Foto selfie dengan live preview & kompresi persegi otomatis
 * - Penyimpanan terenkripsi & terkompresi ke koleksi `registrants` di MasterData
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mentoring\Academic;
use Mentoring\Config;
use Mentoring\MasterData;
use Mentoring\MasterSchema;
use Mentoring\ProfilePhoto;

$mentorPerPage = Config::mentorShowcasePerPage();

$appBase = mentoring_request_app_base('daftar');
if ($appBase === null) {
    $appBase = mentoring_base_path(__DIR__);
}
$appBase = rtrim($appBase, '/') . '/';

$showcaseMentors = MasterData::getMentorsForShowcase();
$mentorNameById = [];
foreach ($showcaseMentors as $m) {
    $mentorNameById[$m['id']] = $m['nama_lengkap'];
}

$formError = '';
$isAjax = (($_POST['_ajax'] ?? '') === '1')
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $namaLengkap = MasterSchema::normalizeName((string)($_POST['nama_lengkap'] ?? ''));
    $gender = trim((string)($_POST['gender'] ?? ''));
    $nomorWaRaw = trim((string)($_POST['nomor_whatsapp'] ?? ''));
    $nomorWa = MasterSchema::normalizePhone($nomorWaRaw);
    $nim = trim((string)($_POST['nim'] ?? ''));
    $kelasRaw = trim((string)($_POST['kelas'] ?? ''));
    $kelas = Academic::normalizeClass($kelasRaw);
    $jadwalKuliah = trim((string)($_POST['jadwal_kuliah'] ?? ''));
    $pengalaman = trim((string)($_POST['pengalaman_mentoring'] ?? ''));

    // Field opsional
    $email = trim((string)($_POST['email'] ?? ''));
    $mediaLainRaw = trim((string)($_POST['media_komunikasi_lain'] ?? ''));
    $mediaLainList = array_values(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', $mediaLainRaw) ?: []),
        static fn(string $line): bool => $line !== ''
    ));
    $alasanDaftar = trim((string)($_POST['alasan_daftar'] ?? ''));
    $preferredMentorId = trim((string)($_POST['preferred_mentor_id'] ?? ''));
    $preferredMentorNama = $preferredMentorId !== '' ? ($mentorNameById[$preferredMentorId] ?? '') : '';
    $tempatLahir = MasterSchema::normalizeName((string)($_POST['tempat_lahir'] ?? ''));
    $tanggalLahir = trim((string)($_POST['tanggal_lahir'] ?? ''));
    $alamatSekarang = MasterSchema::normalizeName((string)($_POST['alamat_sekarang'] ?? ''));
    $alamatOrangTua = MasterSchema::normalizeName((string)($_POST['alamat_orang_tua'] ?? ''));
    $kegiatanUtama = trim((string)($_POST['kegiatan_utama'] ?? ''));

    if (
        $namaLengkap === ''
        || !in_array($gender, ['Laki-laki', 'Perempuan'], true)
        || $nomorWa === ''
        || $nim === ''
        || $kelas === ''
        || !in_array($jadwalKuliah, ['Pagi', 'Sore', 'Tidak Menentu'], true)
        || !in_array($pengalaman, MasterSchema::MENTORING_EXPERIENCE, true)
    ) {
        $formError = 'Mohon lengkapi seluruh kolom bertanda wajib (*) dengan benar.';
    } elseif (!preg_match('/^62\d{8,15}$/', $nomorWa)) {
        $formError = 'Format Nomor WhatsApp tidak valid. Gunakan nomor aktif Indonesia (contoh: 081234567890 atau 6281234567890).';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formError = 'Format alamat email tidak valid.';
    } elseif ($tanggalLahir !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalLahir)) {
        $formError = 'Format tanggal lahir tidak valid.';
    } else {
        $newId = 'reg-' . gmdate('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $fotoPath = null;

        if (!empty($_FILES['foto_profil']['name'])) {
            try {
                $fotoPath = ProfilePhoto::storeUpload('pendaftar', $newId, $_FILES['foto_profil']);
            } catch (\Throwable $e) {
                $formError = 'Foto selfie gagal diproses: ' . $e->getMessage();
            }
        }

        if ($formError === '') {
            $record = [
                'id' => $newId,
                'foto_profil' => $fotoPath,
                'nama_lengkap' => $namaLengkap,
                'gender' => $gender,
                'nomor_whatsapp' => $nomorWa,
                'email' => $email,
                'nim' => $nim,
                'kelas' => $kelas,
                'jadwal_kuliah' => $jadwalKuliah,
                'media_komunikasi_lain' => $mediaLainRaw,
                'media_komunikasi_list' => $mediaLainList,
                'alasan_daftar' => $alasanDaftar,
                'preferred_mentor_id' => $preferredMentorId,
                'preferred_mentor_nama' => $preferredMentorNama,
                'tempat_lahir' => $tempatLahir,
                'tanggal_lahir' => $tanggalLahir,
                'alamat_sekarang' => $alamatSekarang,
                'alamat_orang_tua' => $alamatOrangTua,
                'kegiatan_utama' => $kegiatanUtama,
                'pengalaman_mentoring' => $pengalaman,
                'status_pendaftaran' => 'Menunggu',
                'catatan_admin' => '',
                'assigned_tim_id' => '',
                'assigned_mentee_id' => '',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];

            $result = MasterData::transaction(static function (array $master) use ($record): array {
                if (!isset($master['registrants']) || !is_array($master['registrants'])) {
                    $master['registrants'] = [];
                }
                $master['registrants'][] = $record;
                return $master;
            }, 'daftar-publik');

            if ($result['ok']) {
                $redirectUrl = $appBase . 'daftar?sukses=1&id=' . urlencode($newId) . '&nama=' . urlencode($namaLengkap);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => true,
                        'registration_id' => $newId,
                        'nama_lengkap' => $namaLengkap,
                        'redirect_url' => $redirectUrl,
                        'message' => 'Pendaftaran berhasil dikirim!',
                    ]);
                    exit;
                }
                header('Location: ' . $redirectUrl, true, 303);
                exit;
            }

            $formError = (string)($result['error'] ?? 'Pendaftaran gagal disimpan. Silakan coba lagi.');
        }
    }

    if ($isAjax && $formError !== '') {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $formError,
        ]);
        exit;
    }
}

$isSuccess = (($_GET['sukses'] ?? '') === '1');
$successId = trim((string)($_GET['id'] ?? ''));
$successNama = trim((string)($_GET['nama'] ?? ''));

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES);
$old = static fn(string $key, string $default = ''): string => $h($_POST[$key] ?? $default);
$selectedGender = (string)($_POST['gender'] ?? 'Laki-laki');
if (!in_array($selectedGender, ['Laki-laki', 'Perempuan'], true)) {
    $selectedGender = 'Laki-laki';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Mentoring Agama Islam — UKMI Polmed</title>
    <link rel="icon" type="image/jpeg" href="<?= $h($appBase) ?>public/assets/img/favicon.jpeg">
    <!-- PWA Metadata (Form Pendaftaran) -->
    <link rel="manifest" href="https://laporan-mai.krasyid822.workers.dev/pwa/manifest-daftar.webmanifest">
    <meta name="theme-color" content="#0284c7">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Daftar MAI">
    <link rel="apple-touch-icon" href="https://laporan-mai.krasyid822.workers.dev/pwa/icon-daftar-192x192.png">
    <meta name="description" content="Formulir pendaftaran resmi peserta Mentoring Agama Islam (MAI) UKMI Politeknik Negeri Medan.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css?v=5">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/pwa.css?v=1">
    <style>
        .req-badge {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            background: rgba(239, 68, 68, 0.16);
            color: #fca5a5;
            margin-left: 6px;
            vertical-align: middle;
        }
        .opt-badge {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.16);
            color: #94a3b8;
            margin-left: 6px;
            vertical-align: middle;
        }
        .gender-pills {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .gender-pill {
            position: relative;
            cursor: pointer;
        }
        .gender-pill input {
            position: absolute;
            opacity: 0;
        }
        .gender-pill span {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1px solid #334155;
            background: #0b1120;
            color: #cbd5e1;
            font-weight: 600;
            font-size: 0.92rem;
            transition: all 0.2s;
        }
        .gender-pill input:checked + span {
            background: rgba(2, 132, 199, 0.2);
            border-color: var(--primary);
            color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.2);
        }
        .mentor-showcase-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 10px;
            margin-bottom: 16px;
        }
        .mentor-card {
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            cursor: pointer;
            transition: border-color 0.2s, transform 0.15s;
        }
        .mentor-card:hover {
            border-color: #475569;
        }
        .mentor-card.selected {
            border-color: #38bdf8;
            background: rgba(2, 132, 199, 0.1);
        }
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
            margin-bottom: 16px;
            padding-top: 12px;
            border-top: 1px solid #334155;
            flex-wrap: wrap;
            gap: 10px;
        }
        .pagination-info {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .pagination-controls {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: nowrap;
            max-width: 100%;
            overflow: hidden;
        }
        .page-nav-btn {
            background: #1e293b;
            border: 1px solid #334155;
            color: #cbd5e1;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .page-nav-btn:hover:not(:disabled) {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .page-nav-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .page-nav-btn.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 600;
        }
        .page-nav-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            padding: 6px 6px;
            font-size: 0.85rem;
            font-weight: 600;
            user-select: none;
            flex-shrink: 0;
        }
        .mentor-card-head {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mentor-avatar-initial {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(2, 132, 199, 0.2);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #38bdf8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .mentor-card-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #f8fafc;
            line-height: 1.3;
        }
        .mentor-card-sub {
            font-size: 0.78rem;
            color: #94a3b8;
        }
        .mentor-card-meta {
            font-size: 0.8rem;
            color: #cbd5e1;
            line-height: 1.45;
        }
        .info-banner {
            background: rgba(2, 132, 199, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #7dd3fc;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 0.85rem;
            line-height: 1.5;
            margin-bottom: 14px;
        }
        .success-card {
            text-align: center;
            padding: 32px 24px;
        }
        .success-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 16px;
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container">
    <header class="page-header">
        <h1 class="page-title">Pendaftaran Mentoring Agama Islam</h1>
        <p class="page-subtitle">Unit Kegiatan Mahasiswa Islam (UKMI) Politeknik Negeri Medan</p>
        <div class="subpage-back">
            <a href="/">&larr; Kembali ke Halaman Utama</a>
        </div>
    </header>

    <!-- PWA Status, Install Banner, iOS Guide & Offline Queue -->
    <div id="pwaStatusBar" class="pwa-status-bar">
        <div class="pwa-status-left">
            <span class="pwa-dot"></span>
            <span id="pwaStatusText">Memeriksa koneksi...</span>
        </div>
        <div class="pwa-status-actions">
            <button type="button" id="pwaBtnSyncNow" class="pwa-btn-mini success" style="display:none;">Sinkronkan Sekarang</button>
        </div>
    </div>

    <div id="pwaInstallBanner" class="pwa-install-banner">
        <div class="pwa-install-info">
            <div class="pwa-install-title">Instal Form Pendaftaran MAI</div>
            <div class="pwa-install-desc">Pasang di layar utama agar tetap bisa mendaftar saat offline.</div>
        </div>
        <div class="pwa-install-actions">
            <button type="button" id="pwaBtnInstall" class="pwa-btn-mini">Instal</button>
            <button type="button" id="pwaBtnDismissInstall" class="pwa-btn-mini danger">Nanti</button>
        </div>
    </div>

    <div id="pwaIosGuide" class="pwa-ios-guide">
        <div class="pwa-ios-header">
            <div class="pwa-ios-title">Pasang Aplikasi di iPhone / iPad (Safari)</div>
            <button type="button" id="pwaBtnDismissIos" class="pwa-btn-mini danger">Tutup</button>
        </div>
        <p class="pwa-ios-note">
            "Yes, Progressive Web Apps (PWAs) work on iOS, but they are installed manually via the Safari Share menu using the Add to Home Screen option, as iOS does not support automatic install prompts."
        </p>
        <ol class="pwa-ios-steps">
            <li>Ketuk ikon <b>Share (Bagikan)</b> di Safari.</li>
            <li>Pilih <b>Add to Home Screen (Tambah ke Layar Utama)</b>.</li>
            <li>Ketuk <b>Add (Tambah)</b>.</li>
        </ol>
    </div>

    <div id="offlineQueuePanel" class="pwa-queue-panel">
        <div class="pwa-queue-head">
            <div class="pwa-queue-title">Antrean Pendaftaran Offline</div>
        </div>
        <div id="offlineQueueList" class="pwa-queue-list"></div>
    </div>

    <?php if ($isSuccess): ?>
        <div class="card success-card">
            <div class="success-icon">✓</div>
            <h2 style="font-size: 1.35rem; color: #f8fafc; margin-bottom: 8px;">Pendaftaran Berhasil Dikirim!</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; max-width: 540px; margin: 0 auto 18px; line-height: 1.6;">
                Jazakumullahu khairan<?= $successNama !== '' ? ', <b>' . $h($successNama) . '</b>' : '' ?>.
                Data pendaftaran Anda telah masuk ke <b>daftar tunggu (waiting list)</b> Divisi Mentoring UKMI Polmed.
            </p>
            <?php if ($successId !== ''): ?>
                <div style="display: inline-block; background: #0b1120; border: 1px solid #334155; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; color: #7dd3fc; margin-bottom: 20px;">
                    Kode Pendaftaran: <b><?= $h($successId) ?></b>
                </div>
            <?php endif; ?>
            <p style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 22px;">
                Admin / Divisi Mentoring akan menghubungi Anda melalui WhatsApp setelah penempatan kelompok ditetapkan.
            </p>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="<?= $h($appBase) ?>daftar" class="btn-action" style="width: auto; padding: 10px 20px;">Daftar Peserta Lain</a>
                <a href="/" class="btn-cancel">Ke Halaman Utama</a>
            </div>
        </div>
        <script>
            try { localStorage.removeItem('mai_daftar_draft_v1'); } catch (e) {}
        </script>
    <?php else: ?>

        <?php if ($formError !== ''): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.9rem;">
                <?= $h($formError) ?>
            </div>
        <?php endif; ?>

        <div id="draftRestoreBanner" style="display:none; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35); color: #6ee7b7; padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 0.85rem; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
            <span>Progres pengisian terakhir Anda dipulihkan otomatis.</span>
            <button type="button" id="btnResetDraft" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.45); color: #fca5a5; padding: 5px 11px; border-radius: 7px; font-size: 0.78rem; font-weight: 600; cursor: pointer; font-family: inherit;">Reset Isian</button>
        </div>

        <form method="POST" enctype="multipart/form-data" id="daftarForm">
            <!-- BAGIAN 1: IDENTITAS & KONTAK UTAMA (WAJIB) -->
            <div class="card">
                <h2 class="card-title">1. Identitas &amp; Akademik Utama</h2>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">
                    Cukup isi data inti di bawah ini agar proses pendaftaran cepat. Kolom bertanda <span style="color:#f87171">*</span> wajib diisi.
                </p>

                <div class="form-group">
                    <label>Foto Selfie <span class="opt-badge">Opsional</span></label>
                    <div class="photo-upload-box">
                        <div class="photo-preview" id="reg-photo-preview"></div>
                        <div class="photo-fields">
                            <input type="file" name="foto_profil" id="foto_profil" accept="image/*" capture="user"
                                   class="form-control" data-profile-photo
                                   data-preview="reg-photo-preview" data-info="reg-photo-info">
                            <div class="photo-hint" id="reg-photo-info">
                                Unggah foto selfie yang memperlihatkan wajah dengan jelas. Otomatis dipotong persegi &amp; dikompres di perangkat Anda.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Gender <span style="color:#f87171">*</span></label>
                    <div class="gender-pills">
                        <label class="gender-pill">
                            <input type="radio" name="gender" value="Laki-laki" <?= $selectedGender === 'Laki-laki' ? 'checked' : '' ?> required>
                            <span>Laki-laki (Ikhwan)</span>
                        </label>
                        <label class="gender-pill">
                            <input type="radio" name="gender" value="Perempuan" <?= $selectedGender === 'Perempuan' ? 'checked' : '' ?> required>
                            <span>Perempuan (Akhwat)</span>
                        </label>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="nama_lengkap">Nama Lengkap <span style="color:#f87171">*</span></label>
                        <input type="text" name="nama_lengkap" id="nama_lengkap" class="form-control" required placeholder="Contoh: Muhammad Rizky" value="<?= $old('nama_lengkap') ?>">
                    </div>

                    <div class="form-group">
                        <label for="nomor_whatsapp">Nomor WhatsApp Aktif <span style="color:#f87171">*</span></label>
                        <input type="tel" name="nomor_whatsapp" id="nomor_whatsapp" class="form-control" required placeholder="081234567890" value="<?= $old('nomor_whatsapp') ?>">
                    </div>

                    <div class="form-group">
                        <label for="nim">NIM <span style="color:#f87171">*</span></label>
                        <input type="text" name="nim" id="nim" class="form-control" required placeholder="2405181010" value="<?= $old('nim') ?>">
                        <div class="photo-hint" id="nim-hint">Semester dihitung otomatis dari dua digit awal NIM.</div>
                    </div>

                    <div class="form-group">
                        <label for="kelas">Kelas <span style="color:#f87171">*</span></label>
                        <input type="text" name="kelas" id="kelas" class="form-control" required placeholder="Contoh: TRPL-A" value="<?= $old('kelas') ?>">
                        <div class="photo-hint">Cukup program studi + huruf kelas (mis. <b>TRPL-A</b>).</div>
                    </div>

                    <div class="form-group">
                        <label for="jadwal_kuliah">Sesi Kuliah <span style="color:#f87171">*</span></label>
                        <select name="jadwal_kuliah" id="jadwal_kuliah" class="form-control" required>
                            <?php foreach (['Pagi', 'Sore', 'Tidak Menentu'] as $sesi): ?>
                                <option value="<?= $h($sesi) ?>" <?= ($_POST['jadwal_kuliah'] ?? 'Tidak Menentu') === $sesi ? 'selected' : '' ?>><?= $h($sesi) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="pengalaman_mentoring">Pengalaman Mentoring / Latar Belakang <span style="color:#f87171">*</span></label>
                        <select name="pengalaman_mentoring" id="pengalaman_mentoring" class="form-control" required>
                            <?php foreach (MasterSchema::MENTORING_EXPERIENCE as $exp): ?>
                                <option value="<?= $h($exp) ?>" <?= ($_POST['pengalaman_mentoring'] ?? '') === $exp ? 'selected' : '' ?>><?= $h($exp) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- BAGIAN 2: SHOWCASE BIODATA MENTOR & PREFERENSI -->
            <div class="card">
                <h2 class="card-title">2. Kenali Mentor &amp; Pilihan Pendamping <span class="opt-badge">Opsional</span></h2>
                <div class="info-banner">
                    Daftar mentor di bawah ini menyesuaikan <b>gender</b> yang Anda pilih. Anda boleh memilih mentor yang diminati, namun <b>keputusan akhir penempatan kelompok tetap ditetapkan oleh Divisi Mentoring</b> sesuai kuota dan jadwal.
                </div>

                <div id="mentorShowcaseEmpty" style="display:none; padding: 16px; text-align: center; color: var(--text-muted); background: #0b1120; border: 1px dashed #334155; border-radius: 10px; margin-bottom: 14px; font-size: 0.88rem;">
                    Belum ada profil mentor aktif untuk gender ini. Anda tetap dapat melanjutkan pendaftaran.
                </div>

                <div class="mentor-showcase-grid" id="mentorShowcaseGrid"></div>

                <div id="mentorShowcasePagination" class="pagination-container" style="display:none;">
                    <div id="mentorPaginationInfo" class="pagination-info"></div>
                    <div id="mentorPaginationControls" class="pagination-controls"></div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="preferred_mentor_id">Tertarik dengan Mentor Mana?</label>
                    <select name="preferred_mentor_id" id="preferred_mentor_id" class="form-control searchable">
                        <option value="">-- Bebas / Dipilihkan oleh Divisi Mentoring --</option>
                    </select>
                </div>
            </div>

            <!-- BAGIAN 3: MOTIVASI & MEDIA KOMUNIKASI TAMBAHAN (OPSIONAL) -->
            <div class="card">
                <h2 class="card-title">3. Motivasi &amp; Kontak Tambahan <span class="opt-badge">Opsional</span></h2>
                <div class="form-grid">
                    <div class="form-group col-span-2">
                        <label for="alasan_daftar">Alasan Mendaftar Mentoring</label>
                        <textarea name="alasan_daftar" id="alasan_daftar" class="form-control" rows="2" placeholder="Ceritakan singkat harapan atau alasan Anda mengikuti mentoring..."><?= $old('alasan_daftar') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" name="email" id="email" class="form-control" placeholder="nama@email.com" value="<?= $old('email') ?>">
                    </div>

                    <div class="form-group">
                        <label for="kegiatan_utama">Kegiatan yang Paling Banyak Menghabiskan Waktu Sekarang</label>
                        <input type="text" name="kegiatan_utama" id="kegiatan_utama" class="form-control" placeholder="Contoh: Kuliah, kerja part-time, organisasi, dll." value="<?= $old('kegiatan_utama') ?>">
                    </div>

                    <div class="form-group col-span-2" style="margin-bottom: 0;">
                        <label for="media_komunikasi_lain">Media Komunikasi Lain (Discord, Instagram, Telegram, dll.)</label>
                        <textarea name="media_komunikasi_lain" id="media_komunikasi_lain" class="form-control" rows="3" placeholder="Pisahkan dengan baris baru untuk tiap platform, contoh:&#10;Discord: username#1234&#10;Instagram: @username&#10;Telegram: @username"><?= $old('media_komunikasi_lain') ?></textarea>
                        <div class="photo-hint">Bisa diisi lebih dari satu platform; pisahkan dengan menekan <b>Enter (baris baru)</b>.</div>
                    </div>
                </div>
            </div>

            <!-- BAGIAN 4: BIODATA TAMBAHAN & DOMISILI (OPSIONAL) -->
            <div class="card">
                <h2 class="card-title">4. Biodata Tambahan &amp; Domisili <span class="opt-badge">Opsional</span></h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="tempat_lahir">Tempat Lahir</label>
                        <input type="text" name="tempat_lahir" id="tempat_lahir" class="form-control" placeholder="Medan" value="<?= $old('tempat_lahir') ?>">
                    </div>

                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" id="tanggal_lahir" class="form-control" value="<?= $old('tanggal_lahir') ?>">
                    </div>

                    <div class="form-group">
                        <label for="alamat_sekarang">Alamat Tinggal Sekarang</label>
                        <input type="text" name="alamat_sekarang" id="alamat_sekarang" class="form-control" placeholder="Jl. Contoh No. 10, Medan" value="<?= $old('alamat_sekarang') ?>">
                    </div>

                    <div class="form-group">
                        <label for="alamat_orang_tua">Alamat Orang Tua</label>
                        <input type="text" name="alamat_orang_tua" id="alamat_orang_tua" class="form-control" placeholder="Alamat asal / orang tua" value="<?= $old('alamat_orang_tua') ?>">
                    </div>
                </div>
            </div>

            <button type="submit" class="submit-btn">Kirim Pendaftaran Mentoring</button>
        </form>
    <?php endif; ?>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
<script src="<?= $h($appBase) ?>public/assets/js/profile-photo.js"></script>
<script src="<?= $h($appBase) ?>public/assets/js/modal.js?v=2"></script>
<script src="<?= $h($appBase) ?>public/assets/js/pwa-offline.js?v=1"></script>
<script>
const SHOWCASE_MENTORS = <?= json_encode($showcaseMentors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const INITIAL_PREFERRED_MENTOR = <?= json_encode((string)($_POST['preferred_mentor_id'] ?? '')) ?>;
const MENTOR_PAGE_SIZE = <?= (int)$mentorPerPage ?>;
let currentMentorPage = 1;
let currentFilteredMentors = [];

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

function buildMentorPaginationItems(current, total, maxSlots) {
    if (total <= maxSlots) {
        return Array.from({ length: total }, (_, idx) => idx + 1);
    }
    if (maxSlots <= 1) {
        return [current];
    }
    if (maxSlots === 2) {
        return current > 1 ? ['...', current] : [current, '...'];
    }
    if (maxSlots === 3) {
        if (current === 1 || current === total) {
            return [1, '...', total];
        }
        return ['...', current, '...'];
    }
    if (maxSlots === 4) {
        if (current <= 2) return [1, 2, '...', total];
        if (current >= total - 1) return [1, '...', total - 1, total];
        return [1, '...', current, '...'];
    }

    if (current <= maxSlots - 2) {
        const items = [];
        for (let i = 1; i <= maxSlots - 2; i++) items.push(i);
        items.push('...', total);
        return items;
    }
    if (current >= total - (maxSlots - 3)) {
        const items = [1, '...'];
        for (let i = total - (maxSlots - 3); i <= total; i++) items.push(i);
        return items;
    }

    const innerCount = maxSlots - 4;
    const half = Math.floor(innerCount / 2);
    let start = current - half;
    let end = start + innerCount - 1;
    if (start < 2) {
        start = 2;
        end = start + innerCount - 1;
    }
    if (end > total - 1) {
        end = total - 1;
        start = end - innerCount + 1;
    }
    const items = [1, '...'];
    for (let i = start; i <= end; i++) items.push(i);
    items.push('...', total);
    return items;
}

function renderMentorPage(page) {
    const grid = document.getElementById('mentorShowcaseGrid');
    const paginationContainer = document.getElementById('mentorShowcasePagination');
    const infoSpan = document.getElementById('mentorPaginationInfo');
    const controlsDiv = document.getElementById('mentorPaginationControls');
    if (!grid || !paginationContainer || !infoSpan || !controlsDiv) return;

    const total = currentFilteredMentors.length;
    if (total === 0) {
        paginationContainer.style.display = 'none';
        return;
    }

    const totalPages = Math.ceil(total / MENTOR_PAGE_SIZE) || 1;
    currentMentorPage = Math.max(1, Math.min(page, totalPages));

    const start = (currentMentorPage - 1) * MENTOR_PAGE_SIZE;
    const end = start + MENTOR_PAGE_SIZE;

    const cards = Array.from(grid.querySelectorAll('.mentor-card'));
    cards.forEach((card, idx) => {
        card.style.display = (idx >= start && idx < end) ? '' : 'none';
    });

    const showingStart = start + 1;
    const showingEnd = Math.min(end, total);
    infoSpan.textContent = `Menampilkan ${showingStart}-${showingEnd} dari ${total} mentor (Halaman ${currentMentorPage}/${totalPages})`;

    paginationContainer.style.display = 'flex';

    const containerWidth = paginationContainer.clientWidth;
    const infoWidth = infoSpan.offsetWidth || 0;
    let availableWidth = containerWidth;
    if (containerWidth <= 0) {
        availableWidth = Math.max(200, (grid.clientWidth || window.innerWidth) - 48);
    } else if (containerWidth - infoWidth - 16 >= 320) {
        availableWidth = containerWidth - infoWidth - 16;
    }

    function renderMentorControlsWithSlots(maxSlots) {
        controlsDiv.innerHTML = '';

        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'page-nav-btn';
        prevBtn.textContent = '« Prev';
        prevBtn.disabled = (currentMentorPage === 1);
        prevBtn.addEventListener('click', () => {
            if (currentMentorPage > 1) renderMentorPage(currentMentorPage - 1);
        });
        controlsDiv.appendChild(prevBtn);

        const items = buildMentorPaginationItems(currentMentorPage, totalPages, maxSlots);
        items.forEach(item => {
            if (item === '...') {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'page-nav-ellipsis';
                ellipsis.textContent = '...';
                ellipsis.setAttribute('aria-hidden', 'true');
                controlsDiv.appendChild(ellipsis);
            } else {
                const numBtn = document.createElement('button');
                numBtn.type = 'button';
                numBtn.className = 'page-nav-btn' + (item === currentMentorPage ? ' active' : '');
                numBtn.textContent = item;
                numBtn.addEventListener('click', () => renderMentorPage(item));
                controlsDiv.appendChild(numBtn);
            }
        });

        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.className = 'page-nav-btn';
        nextBtn.textContent = 'Next »';
        nextBtn.disabled = (currentMentorPage === totalPages || totalPages === 0);
        nextBtn.addEventListener('click', () => {
            if (currentMentorPage < totalPages) renderMentorPage(currentMentorPage + 1);
        });
        controlsDiv.appendChild(nextBtn);
    }

    let maxSlots = Math.max(1, Math.min(totalPages, Math.floor((availableWidth - 148) / 42)));
    renderMentorControlsWithSlots(maxSlots);

    while (availableWidth > 0 && controlsDiv.scrollWidth > availableWidth && maxSlots > 1) {
        maxSlots -= 1;
        renderMentorControlsWithSlots(maxSlots);
    }
}

let mentorPaginationResizeTimer = null;
window.addEventListener('resize', () => {
    clearTimeout(mentorPaginationResizeTimer);
    mentorPaginationResizeTimer = setTimeout(() => {
        if (currentFilteredMentors.length > 0) {
            renderMentorPage(currentMentorPage);
        }
    }, 120);
});

function renderMentorShowcase(gender, keepSelection = false) {
    const grid = document.getElementById('mentorShowcaseGrid');
    const emptyBox = document.getElementById('mentorShowcaseEmpty');
    const paginationContainer = document.getElementById('mentorShowcasePagination');
    const select = document.getElementById('preferred_mentor_id');
    if (!grid || !select) return;

    const filtered = SHOWCASE_MENTORS.filter(m => m.gender === gender);
    currentFilteredMentors = filtered;
    const currentValue = keepSelection ? (INITIAL_PREFERRED_MENTOR || select.value) : '';

    // Perbarui opsi <select>
    select.innerHTML = '<option value="">-- Bebas / Dipilihkan oleh Divisi Mentoring --</option>';
    filtered.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.nama_lengkap + (m.ringkasan_kesibukan ? ' (' + m.ringkasan_kesibukan + ')' : '');
        if (currentValue && currentValue === m.id) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });

    if (filtered.length === 0) {
        grid.innerHTML = '';
        if (paginationContainer) paginationContainer.style.display = 'none';
        if (emptyBox) emptyBox.style.display = 'block';
        return;
    }

    if (emptyBox) emptyBox.style.display = 'none';
    grid.innerHTML = '';

    filtered.forEach(m => {
        const initial = (m.nama_lengkap || '?').trim().charAt(0).toUpperCase();
        const isSelected = select.value === m.id;
        const card = document.createElement('div');
        card.className = 'mentor-card' + (isSelected ? ' selected' : '');
        card.dataset.mentorId = m.id;

        const timInfo = m.nama_tim_list && m.nama_tim_list.length > 0
            ? 'Membina: ' + m.nama_tim_list.join(', ')
            : 'Siap membina kelompok baru';

        card.innerHTML = `
            <div class="mentor-card-head">
                <div class="mentor-avatar-initial">${escapeHtml(initial)}</div>
                <div>
                    <div class="mentor-card-name">${escapeHtml(m.nama_lengkap)}</div>
                    <div class="mentor-card-sub">${escapeHtml(m.status_kesibukan)}${m.ringkasan_kesibukan ? ' • ' + escapeHtml(m.ringkasan_kesibukan) : ''}</div>
                </div>
            </div>
            <div class="mentor-card-meta">
                <div>Amanah: <b>${escapeHtml(m.amanah_organisasi || 'Tidak Ada')}</b></div>
                <div style="color:#94a3b8;margin-top:2px;">${escapeHtml(timInfo)}</div>
            </div>
        `;

        card.addEventListener('click', () => {
            const newVal = select.value === m.id ? '' : m.id;
            select.value = newVal;
            const wrapper = select.nextElementSibling;
            if (wrapper && wrapper.classList.contains('searchable-select-wrapper')) {
                const txt = wrapper.querySelector('.trigger-text');
                if (txt && select.options[select.selectedIndex]) {
                    txt.textContent = select.options[select.selectedIndex].text;
                }
            }
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });

        grid.appendChild(card);
    });

    let initialPage = 1;
    if (select.value) {
        const idx = filtered.findIndex(m => m.id === select.value);
        if (idx !== -1) {
            initialPage = Math.floor(idx / MENTOR_PAGE_SIZE) + 1;
        }
    }
    renderMentorPage(initialPage);
}

function highlightSelectedMentorCard(selectedId, jumpToPage = true) {
    document.querySelectorAll('.mentor-card').forEach(card => {
        const match = card.dataset.mentorId === selectedId && selectedId !== '';
        card.classList.toggle('selected', match);
    });

    if (jumpToPage && selectedId !== '') {
        const idx = currentFilteredMentors.findIndex(m => m.id === selectedId);
        if (idx !== -1) {
            const targetPage = Math.floor(idx / MENTOR_PAGE_SIZE) + 1;
            if (targetPage !== currentMentorPage) {
                renderMentorPage(targetPage);
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('preferred_mentor_id');
    if (select && typeof initSearchableSelect === 'function') {
        initSearchableSelect(select);
        select.addEventListener('change', () => highlightSelectedMentorCard(select.value));
    }

    document.querySelectorAll('select.form-control:not(#preferred_mentor_id)').forEach(el => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(el);
    });

    const checkedGender = document.querySelector('input[name="gender"]:checked');
    renderMentorShowcase(checkedGender ? checkedGender.value : 'Laki-laki', true);

    document.querySelectorAll('input[name="gender"]').forEach(radio => {
        radio.addEventListener('change', () => {
            renderMentorShowcase(radio.value, false);
        });
    });

    // Auto-capitalize tiap awal kata untuk nama, tempat lahir, dan alamat
    function bindTitleCase(inputId) {
        const el = document.getElementById(inputId);
        if (!el) return;
        const toTitleCase = (val) => val.toLowerCase().replace(/(^|[\s\-'.,\/()])(\S)/g, (_, sep, ch) => sep + ch.toUpperCase());
        el.addEventListener('input', () => {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const formatted = toTitleCase(el.value);
            if (el.value !== formatted) {
                el.value = formatted;
                if (start !== null && end !== null) {
                    el.setSelectionRange(start, end);
                }
            }
        });
        el.addEventListener('blur', () => {
            el.value = toTitleCase(el.value).replace(/\s+/g, ' ').trim();
        });
    }
    bindTitleCase('nama_lengkap');
    bindTitleCase('tempat_lahir');
    bindTitleCase('alamat_sekarang');
    bindTitleCase('alamat_orang_tua');

    // Live preview semester dari NIM
    const nimInput = document.getElementById('nim');
    const nimHint = document.getElementById('nim-hint');
    if (nimInput && nimHint) {
        nimInput.addEventListener('input', () => {
            const digits = nimInput.value.replace(/\D+/g, '');
            if (digits.length >= 2) {
                const stambuk = 2000 + parseInt(digits.slice(0, 2), 10);
                const now = new Date();
                const year = now.getFullYear();
                const month = now.getMonth() + 1;
                if (stambuk <= year + 1) {
                    const acadYear = month >= 9 ? year : year - 1;
                    const period = (month >= 9 || month <= 2) ? 1 : 2;
                    const sem = Math.max(1, (acadYear - stambuk) * 2 + period);
                    nimHint.innerHTML = `Terdeteksi stambuk <b>${stambuk}</b> (Semester ${sem}).`;
                    return;
                }
            }
            nimHint.textContent = 'Semester dihitung otomatis dari dua digit awal NIM.';
        });
    }

    // Auto-save & Auto-restore progres form di localStorage
    const DRAFT_KEY = 'mai_daftar_draft_v1';
    const IS_POST_ERROR = <?= json_encode($_SERVER['REQUEST_METHOD'] === 'POST' && $formError !== '') ?>;
    const formEl = document.getElementById('daftarForm');
    const DRAFT_FIELDS = [
        'nama_lengkap', 'nomor_whatsapp', 'nim', 'kelas', 'jadwal_kuliah',
        'pengalaman_mentoring', 'preferred_mentor_id', 'alasan_daftar', 'email',
        'kegiatan_utama', 'media_komunikasi_lain', 'tempat_lahir', 'tanggal_lahir',
        'alamat_sekarang', 'alamat_orang_tua'
    ];

    function readDraft() {
        try {
            const raw = localStorage.getItem(DRAFT_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writeDraft(extra = {}) {
        if (!formEl) return;
        const current = readDraft() || {};
        const genderRadio = formEl.querySelector('input[name="gender"]:checked');
        if (genderRadio) current.gender = genderRadio.value;
        DRAFT_FIELDS.forEach(name => {
            const el = formEl.elements[name];
            if (el) current[name] = el.value;
        });
        Object.assign(current, extra);
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(current));
        } catch (e) {}
    }

    function restoreDraft() {
        const draft = readDraft();
        if (!draft || !formEl) return;

        let hasData = false;
        if (draft.gender === 'Laki-laki' || draft.gender === 'Perempuan') {
            const r = formEl.querySelector(`input[name="gender"][value="${draft.gender}"]`);
            if (r) {
                r.checked = true;
                renderMentorShowcase(draft.gender, false);
            }
        }

        DRAFT_FIELDS.forEach(name => {
            if (typeof draft[name] === 'string' && draft[name] !== '') {
                const el = formEl.elements[name];
                if (el) {
                    el.value = draft[name];
                    if (el.tagName === 'SELECT') {
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                        const wrapper = el.nextElementSibling;
                        if (wrapper && wrapper.classList.contains('searchable-select-wrapper')) {
                            const txt = wrapper.querySelector('.trigger-text');
                            if (txt && el.options[el.selectedIndex]) {
                                txt.textContent = el.options[el.selectedIndex].text;
                            }
                        }
                    }
                    hasData = true;
                }
            }
        });

        if (nimInput && nimInput.value) {
            nimInput.dispatchEvent(new Event('input'));
        }

        if (typeof draft.foto_data_url === 'string' && draft.foto_data_url.startsWith('data:image/')) {
            fetch(draft.foto_data_url)
                .then(res => res.blob())
                .then(blob => {
                    const file = new File([blob], 'foto-profil.webp', { type: 'image/webp', lastModified: Date.now() });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    const fileInput = document.getElementById('foto_profil');
                    if (fileInput) fileInput.files = dt.files;

                    const preview = document.getElementById('reg-photo-preview');
                    const info = document.getElementById('reg-photo-info');
                    if (preview) {
                        preview.innerHTML = '';
                        const img = document.createElement('img');
                        img.src = draft.foto_data_url;
                        img.alt = 'Pratinjau foto profil';
                        preview.appendChild(img);
                        preview.style.display = 'block';
                    }
                    if (info) {
                        info.innerHTML = '<span style="color:#34d399;font-weight:600;">✓ Foto selfie sebelumnya dipulihkan.</span>';
                    }
                })
                .catch(() => {});
            hasData = true;
        }

        const banner = document.getElementById('draftRestoreBanner');
        if (hasData && banner) {
            banner.style.display = 'flex';
        }
    }

    if (!IS_POST_ERROR) {
        restoreDraft();
    }

    if (formEl) {
        formEl.addEventListener('input', (e) => {
            if (e.target && e.target.id === 'foto_profil') return;
            writeDraft();
        });
        formEl.addEventListener('change', (e) => {
            if (e.target && e.target.id === 'foto_profil') {
                setTimeout(() => {
                    const file = e.target.files && e.target.files[0];
                    if (!file) {
                        writeDraft({ foto_data_url: '' });
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = () => {
                        if (typeof reader.result === 'string') {
                            writeDraft({ foto_data_url: reader.result });
                        }
                    };
                    reader.readAsDataURL(file);
                }, 400);
                return;
            }
            writeDraft();
        });
    }

    const btnResetDraft = document.getElementById('btnResetDraft');
    if (btnResetDraft) {
        btnResetDraft.addEventListener('click', () => {
            try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
            window.location.href = window.location.pathname;
        });
    }

    if (window.MaiPwa) {
        MaiPwa.init({
            mode: 'daftar',
            formId: 'daftarForm',
            submitUrl: '<?= $h($appBase) ?>daftar',
            swUrl: '<?= $h($appBase) ?>sw.js'
        });
    }
});
</script>
</body>
</html>
