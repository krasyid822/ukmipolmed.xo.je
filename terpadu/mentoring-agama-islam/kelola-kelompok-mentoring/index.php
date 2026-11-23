<?php
/**
 * index.php — Formulir Input Pelaporan Mentoring Publik
 * Sesuai AGENTS.md Bagian 4A:
 * - Searchable dropdown pada semua <select>
 * - Live preview gambar & client-side compression
 * - Dynamic loading mentee berdasarkan Tim Mentoring
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
session_start();

use Mentoring\BirthdayReminder;
use Mentoring\MasterData;

$teams = MasterData::getTeamsForForm();
$masterData = MasterData::loadAll();
$todayBirthdays = BirthdayReminder::getTodayBirthdays($masterData);

$mentors = [];
$seenMentorNames = [];
foreach (($masterData['mentors'] ?? []) as $m) {
    $nama = trim((string)($m['nama_lengkap'] ?? ''));
    $status = (string)($m['status'] ?? 'Aktif');
    if ($nama === '' || ($status !== '' && $status !== 'Aktif')) {
        continue;
    }
    $key = mb_strtolower($nama);
    if (isset($seenMentorNames[$key])) {
        continue;
    }
    $seenMentorNames[$key] = true;
    $mentors[] = $nama;
}
usort($mentors, static fn(string $a, string $b): int => strcasecmp($a, $b));

$formError = match ((string)($_GET['error'] ?? '')) {
    'wajib' => 'Data pertemuan belum lengkap. Isi tempat dan tema terlebih dahulu.',
    'foto' => 'Foto dokumentasi wajib diunggah dalam format JPG/PNG/WEBP.',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulir Pelaporan Mentoring Agama Islam</title>
    <link rel="icon" type="image/jpeg" href="public/assets/img/favicon.jpeg">
    <!-- PWA Metadata (Input Laporan) -->
    <link rel="manifest" href="app-manifest-laporan.webmanifest">
    <meta name="theme-color" content="#0284c7">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Laporan MAI">
    <link rel="apple-touch-icon" href="https://laporan-mai.krasyid822.workers.dev/pwa/icon-180x180.png">
    <!-- SEO & Metadata -->
    <meta name="description" content="Formulir resmi pelaporan kegiatan mentoring Agama Islam UKMI Politeknik Negeri Medan.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/css/style.css?v=7">
    <link rel="stylesheet" href="public/assets/css/biometric.css?v=6">
    <link rel="stylesheet" href="public/assets/css/pwa.css?v=1">
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<!-- Blocker yang menutupi formulir hingga verifikasi biometrik lulus -->
<div id="biometricBlocker" class="biometric-blocker">
    <div class="biometric-blocker-backdrop"></div>
</div>

<!-- Modal verifikasi biometrik -->
<div class="biometric-modal" id="biometricModal" aria-hidden="true">
    <div class="biometric-modal-box">
        <div class="biometric-modal-header">
            <div class="biometric-modal-icon">🔐</div>
            <div class="biometric-modal-heading">
                <h3 class="biometric-modal-title">Verifikasi Akses Laporan</h3>
                <p class="biometric-modal-desc">Halaman input laporan mentoring dilindungi data biometrik. Verifikasi terlebih dahulu sebelum mengisi formulir.</p>
            </div>
        </div>

        <div class="biometric-modal-body">
            <!-- Status: tidak ada biometrik terdaftar, pilih identitas -->
            <div class="biometric-step" id="bioStateIdentities">
                <div class="biometric-step-label">Pilih Identitas</div>
                <div id="bioIdentities" class="biometric-identities"></div>
                <p class="biometric-hint" id="bioIdentitiesHint"></p>
            </div>

            <!-- Status: proses verifikasi (sensor atau pola) -->
            <div class="biometric-step" id="bioStateMethod" style="display:none;">
                <div class="biometric-step-label" id="bioMethodLabel">Verifikasi Biometrik</div>
                <div id="bioMethods"></div>
                <div id="bioWebauthnHint" class="biometric-hint" style="display:none;">Menunggu respons sensor biometrik perangkat...</div>
            </div>

            <!-- Status: mencocokkan siapa yang terverifikasi -->
            <div class="biometric-step" id="bioStateMatched" style="display:none;">
                <div class="biometric-matched">
                    <div class="biometric-matched-icon">✓</div>
                    <div class="biometric-matched-text">
                        <div style="font-weight:700;color:#f1f5f9;">Verifikasi berhasil</div>
                        <div style="font-size:0.86rem;color:var(--text-muted);" id="bioMatchedName">—</div>
                    </div>
                </div>
            </div>

            <div id="bioResult" class="biometric-result" style="display:none;"></div>
        </div>

        <div class="biometric-modal-footer">
            <button type="button" class="biometric-btn biometric-btn-back" id="bioBtnBack" style="display:none;">Kembali</button>
            <p class="biometric-hint biometric-hint-help">Belum terdaftar biometrik? Hubungi admin melalui <b>Data Master → Pengaturan</b> untuk membuat link pendaftaran sekali pakai.</p>
        </div>
    </div>
</div>

<div class="container">
    <?php if ($formError !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.5); color: #fca5a5; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($formError) ?>
        </div>
    <?php endif; ?>

    <header class="page-header">
        <h1 class="page-title">Pelaporan Mentoring Agama Islam</h1>
        <p class="page-subtitle">Unit Kegiatan Mahasiswa Islam (UKMI) Politeknik Negeri Medan</p>
    </header>

    <?= BirthdayReminder::renderBanner($todayBirthdays, 'ultah') ?>

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
            <div class="pwa-install-title">Instal Aplikasi Laporan MAI</div>
            <div class="pwa-install-desc">Pasang di layar utama untuk akses cepat &amp; pengisian laporan saat offline.</div>
        </div>
        <div class="pwa-install-actions">
            <button type="button" id="pwaBtnInstall" class="pwa-btn-mini">Instal Aplikasi</button>
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
            <li>Ketuk ikon <b>Share (Bagikan)</b> di bilah bawah/atas Safari.</li>
            <li>Gulir ke bawah lalu pilih <b>Add to Home Screen (Tambah ke Layar Utama)</b>.</li>
            <li>Ketuk <b>Add (Tambah)</b> di pojok kanan atas.</li>
        </ol>
    </div>

    <div id="offlineQueuePanel" class="pwa-queue-panel">
        <div class="pwa-queue-head">
            <div class="pwa-queue-title">Antrean Laporan Offline</div>
        </div>
        <div id="offlineQueueList" class="pwa-queue-list"></div>
    </div>

    <form action="submit" method="POST" enctype="multipart/form-data" id="reportForm">
        
        <!-- BAGIAN 1: IDENTITAS PERTEMUAN -->
        <div class="card">
            <h2 class="card-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                1. Data Pertemuan & Kelompok
            </h2>

            <div class="form-grid">
                <div class="form-group col-span-2">
                    <label for="tim_id">Pilih Kelompok Mentoring:</label>
                    <select name="tim_id" id="tim_id" class="form-control searchable" required>
                        <option value="">-- Pilih Kelompok Mentoring --</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?= htmlspecialchars($t['id']) ?>">
                                <?= htmlspecialchars($t['nama_tim']) ?> &bull; Mentor: <?= htmlspecialchars($t['mentor_nama']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="tanggal">Tanggal Pertemuan:</label>
                    <input type="date" name="tanggal" id="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label>Rentang Waktu:</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="time" name="jam_mulai" class="form-control" value="16:00" required>
                        <span>s/d</span>
                        <input type="time" name="jam_selesai" class="form-control" value="17:30" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="tempat">Tempat Pelaksanaan:</label>
                    <input type="text" name="tempat" id="tempat" class="form-control" placeholder="Contoh: Masjid Al-Ihtida Lantai 2" required>
                </div>

                <div class="form-group">
                    <label for="mentor_nama">Nama Pendamping / Mentor:</label>
                    <select name="mentor_nama" id="mentor_nama" class="form-control searchable" required>
                        <option value="">-- Pilih Pendamping / Mentor --</option>
                        <?php foreach ($mentors as $mentorNama): ?>
                            <option value="<?= htmlspecialchars($mentorNama) ?>"><?= htmlspecialchars($mentorNama) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); font-size: 0.78rem; margin-top: 4px; display: block;">
                        Otomatis terisi sesuai kelompok; dapat diganti jika mentor berhalangan / titip kelompok lain.
                    </small>
                </div>

                <div class="form-group col-span-2">
                    <label for="tema">Tema Pembahasan:</label>
                    <input type="text" name="tema" id="tema" class="form-control" placeholder="Contoh: Urgensi Menuntut Ilmu & Adab Penuntut Ilmu" required>
                </div>
            </div>
        </div>

        <!-- BAGIAN 2: SUSUNAN PETUGAS KEGIATAN -->
        <div class="card">
            <h2 class="card-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                2. Petugas Sesi Mentoring
            </h2>

            <div class="form-grid">
                <div class="form-group">
                    <label for="moderator">Moderator:</label>
                    <select name="moderator" id="moderator" class="form-control searchable member-select">
                        <option value="">-- Pilih Moderator --</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="tilawah">Tilawah Al-Qur'an:</label>
                    <input type="text" name="tilawah" id="tilawah" class="form-control" placeholder="Contoh: QS. Al-Baqarah: 1-20 / Juz 1 Hal 1-5">
                </div>

                <div class="form-group">
                    <label for="doa_petugas">Petugas Do'a:</label>
                    <select name="doa_petugas" id="doa_petugas" class="form-control searchable member-select">
                        <option value="">-- Pilih Petugas Do'a --</option>
                    </select>
                </div>

                <!-- 1 Label Baris Mewakili Tadabbur -->
                <div class="form-group col-span-2">
                    <label>Tadabbur Al-Qur'an:</label>
                    <div class="field-stack-group">
                        <div class="field-row-top">
                            <select name="tadabbur_petugas" id="tadabbur_petugas" class="form-control searchable member-select">
                                <option value="">-- Pilih Petugas Tadabbur --</option>
                            </select>
                            <input type="text" name="tadabbur_surah_ayat" id="tadabbur_surah_ayat" class="form-control" placeholder="Surah & Ayat (Contoh: QS. Al-Alaq: 1-5)">
                        </div>
                        <input type="text" name="tadabbur_inti" id="tadabbur_inti" class="form-control" placeholder="Inti pembahasan / pelajaran penting dari tadabbur...">
                    </div>
                </div>

                <!-- 1 Label Baris Mewakili Kultum -->
                <div class="form-group col-span-2">
                    <label>Kultum:</label>
                    <div class="field-stack-group">
                        <select name="kultum_petugas" id="kultum_petugas" class="form-control searchable member-select">
                            <option value="">-- Pilih Petugas Kultum --</option>
                        </select>
                        <input type="text" name="kultum_judul_inti" id="kultum_judul_inti" class="form-control" placeholder="Judul / Inti pembahasan kultum...">
                    </div>
                </div>
            </div>
        </div>

        <!-- BAGIAN 3: PRESENSI KEHADIRAN MENTEE -->
        <div class="card">
            <h2 class="card-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                3. Presensi Kehadiran Mentee
            </h2>
            <p style="font-size:0.875rem; color:var(--text-muted); margin-bottom:12px;">
                Tandai kehadiran setiap mentee. Mentee yang absen 3x berturut-turut otomatis berstatus <i>Peringatan</i>. Opsi <i>Tidak jelas kabarnya</i> hanya muncul saat mentee berstatus <i>Absen</i> dan membuat statusnya menjadi <i>Tidak Aktif</i>; status pulih ke <i>Aktif</i> saat mentee kembali Hadir/Izin.
            </p>

            <div id="attendanceContainer">
                <div style="text-align:center; padding: 20px; color: var(--text-muted);">
                    Silakan pilih Kelompok Mentoring terlebih dahulu untuk memuat daftar mentee.
                </div>
            </div>
        </div>

        <!-- BAGIAN 4: FOTO DOKUMENTASI DENGAN LIVE PREVIEW & CLIENT COMPRESSION -->
        <div class="card">
            <h2 class="card-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                4. Foto Dokumentasi Kegiatan
            </h2>

            <div class="form-group">
                <label for="dokumentasi">Unggah Foto Dokumentasi (Max 1280px, Otomatis Dikompres Client-Side):</label>
                <input type="file" name="dokumentasi" id="dokumentasi" class="form-control" accept="image/jpeg,image/png,image/webp">
                <div id="compressionInfo" class="file-info"></div>
                <div id="imagePreviewBox" class="preview-box"></div>
            </div>
        </div>

        <!-- TOMBOL KIRIM LAPORAN -->
        <button type="submit" class="submit-btn" id="btnSubmit">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
            Kirim & Publikasikan Laporan Mentoring
        </button>

    </form>
</div>

<script src="public/assets/js/searchable-select.js?v=4"></script>
<script src="public/assets/js/image-compressor.js?v=4"></script>
<script src="public/assets/js/modal.js?v=2"></script>
<script src="public/assets/js/biometric.js?v=20261122-1"></script>
<script src="public/assets/js/pwa-offline.js?v=1"></script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(regs => regs.forEach(r => r.update())).catch(() => {});
}
document.addEventListener('DOMContentLoaded', () => {
    // 1. Inisialisasi Searchable Select untuk semua select.searchable
    const searchableControllers = new Map();
    document.querySelectorAll('select.searchable').forEach(sel => {
        const ctrl = initSearchableSelect(sel);
        if (ctrl) searchableControllers.set(sel, ctrl);
    });

    // 2. Inisialisasi Image Compressor & Live Preview
    const fileInput = document.getElementById('dokumentasi');
    const previewBox = document.getElementById('imagePreviewBox');
    const compressionInfo = document.getElementById('compressionInfo');
    new ClientImageCompressor(fileInput, previewBox, compressionInfo);

    // 3. Dynamic loading saat tim dipilih
    const timSelect = document.getElementById('tim_id');
    const initialTeamOptions = Array.from(timSelect.options)
        .filter(opt => opt.value !== '')
        .map(opt => ({ value: opt.value, text: opt.textContent }));

    function filterTeamsForBiometric(allowedTeamIds) {
        if (!Array.isArray(allowedTeamIds)) return;
        const allowedSet = new Set(allowedTeamIds.map(String));
        const matched = initialTeamOptions.filter(opt => allowedSet.has(String(opt.value)));

        timSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = matched.length > 0
            ? '-- Pilih Kelompok Mentoring --'
            : '-- Tidak ada kelompok yang terhubung dengan identitas Anda --';
        timSelect.appendChild(placeholder);

        matched.forEach(opt => {
            const el = document.createElement('option');
            el.value = opt.value;
            el.textContent = opt.text;
            timSelect.appendChild(el);
        });

        if (matched.length === 1) {
            timSelect.value = matched[0].value;
            searchableControllers.get(timSelect)?.update();
            timSelect.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            timSelect.value = '';
            searchableControllers.get(timSelect)?.update();
            if (matched.length === 0) {
                attendanceContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: #f87171;">Identitas Anda belum terhubung ke kelompok mentoring mana pun.</div>';
            }
        }
    }

    const mentorSelect = document.getElementById('mentor_nama');
    const attendanceContainer = document.getElementById('attendanceContainer');
    const memberSelects = document.querySelectorAll('.member-select');

    mentorSelect.addEventListener('change', () => {
        const currentMentor = mentorSelect.value;
        memberSelects.forEach(sel => {
            const optMentor = sel.querySelector('option[data-role="mentor"]');
            if (!optMentor) return;
            const wasSelected = sel.value === optMentor.value && optMentor.value !== '';
            optMentor.value = currentMentor;
            optMentor.textContent = currentMentor ? `${currentMentor} (Mentor)` : '-- Mentor --';
            if (wasSelected) {
                sel.value = currentMentor;
            }
            searchableControllers.get(sel)?.update();
        });
    });

    // Keterangan izin hanya aktif saat Izin; "Tidak jelas kabarnya" hanya saat Absen.
    attendanceContainer.addEventListener('change', (e) => {
        const radio = e.target;
        if (!radio.matches('input[type="radio"][data-attendance-mentee]')) return;
        const menteeId = radio.dataset.attendanceMentee;
        if (!menteeId) return;

        // Sinkronkan pilihan antara tampilan desktop (kehadiran[]) dan mobile (kehadiran_mobile[])
        attendanceContainer.querySelectorAll(`input[type="radio"][data-attendance-mentee="${menteeId}"]`).forEach(other => {
            if (other !== radio) {
                other.checked = (other.value === radio.value);
            }
        });

        const isIzin = radio.value === 'Izin';
        const isAbsen = radio.value === 'Absen';
        attendanceContainer.querySelectorAll(`input[data-izin-note="${menteeId}"]`).forEach(note => {
            note.style.display = isIzin ? 'block' : 'none';
            note.disabled = !isIzin;
            if (!isIzin) note.value = '';
        });
        attendanceContainer.querySelectorAll(`[data-tidak-jelas-wrap="${menteeId}"]`).forEach(wrap => {
            wrap.style.display = isAbsen ? '' : 'none';
        });
        attendanceContainer.querySelectorAll(`input[data-tidak-jelas="${menteeId}"]`).forEach(box => {
            box.disabled = !isAbsen;
            if (!isAbsen) box.checked = false;
        });
    });

    timSelect.addEventListener('change', async (e) => {
        const teamId = e.target.value;
        if (!teamId) {
            mentorSelect.value = '';
            searchableControllers.get(mentorSelect)?.update();
            attendanceContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: var(--text-muted);">Silakan pilih Kelompok Mentoring terlebih dahulu.</div>';
            return;
        }

        try {
            if (typeof window.ukmiShowLoadingOverlay === 'function') {
                window.ukmiShowLoadingOverlay('Memuat anggota kelompok...');
            }
            let team = null;
            try {
                const res = await fetch(`api?action=get_team_members&team_id=${encodeURIComponent(teamId)}`);
                const data = await res.json();
                if (data.success) {
                    team = data.data;
                }
            } catch (netErr) {
                // Abaikan error jaringan; fallback ke cache di bawah
            }
            if (!team && window.MaiPwa) {
                team = await MaiPwa.getCachedTeamById(teamId);
            }
            if (!team) {
                Modal.error('Gagal mengambil data tim. Pastikan perangkat pernah memuat data saat online.');
                return;
            }
            const teamMentorNama = team.mentor_nama || '';
            if (teamMentorNama && !Array.from(mentorSelect.options).some(o => o.value === teamMentorNama)) {
                const extraOpt = document.createElement('option');
                extraOpt.value = teamMentorNama;
                extraOpt.textContent = teamMentorNama;
                mentorSelect.appendChild(extraOpt);
            }
            mentorSelect.value = teamMentorNama;
            searchableControllers.get(mentorSelect)?.update();

            // Populate member dropdowns (Moderator, Tadabbur, Kultum, Do'a)
            const members = team.mentees || [];
            memberSelects.forEach(sel => {
                sel.innerHTML = '<option value="">-- Pilih Anggota --</option>';
                const optMentor = document.createElement('option');
                optMentor.dataset.role = 'mentor';
                optMentor.value = teamMentorNama;
                optMentor.textContent = teamMentorNama ? `${teamMentorNama} (Mentor)` : '-- Mentor --';
                sel.appendChild(optMentor);

                members.forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m.nama_lengkap;
                    opt.textContent = m.nama_lengkap;
                    sel.appendChild(opt);
                });
            });

            // Render Presensi Kehadiran Mentee (Card Layout untuk Mobile, Table untuk Desktop)
            let html = `
                <div class="attendance-desktop">
                    <table class="attendance-table">
                        <thead>
                            <tr>
                                <th>Nama Mentee</th>
                                <th>Status Keaktifan</th>
                                <th>Presensi Hari Ini</th>
                                <th>Catatan Khusus</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            members.forEach(m => {
                const statusBadgeClass = (m.status_keaktifan === 'Peringatan') ? 'peringatan' : ((m.status_keaktifan === 'Tidak Aktif') ? 'tidak_aktif' : 'aktif');
                html += `
                    <tr>
                        <td>
                            <b>${escapeHtml(m.nama_lengkap)}</b><br>
                            <small style="color:var(--text-muted);">${escapeHtml(m.nim || '-')}${(m.kelas_tampil || m.kelas) ? ' &bull; ' + escapeHtml(m.kelas_tampil || m.kelas) : ''}</small>
                        </td>
                        <td>
                            <span class="badge-status ${statusBadgeClass}">${escapeHtml(m.status_keaktifan || 'Aktif')}</span>
                        </td>
                        <td>
                            <div class="attendance-options">
                                <label class="radio-label">
                                    <input type="radio" name="kehadiran[${m.id}]" data-attendance-mentee="${m.id}" value="Hadir" checked> Hadir
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="kehadiran[${m.id}]" data-attendance-mentee="${m.id}" value="Izin"> Izin
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="kehadiran[${m.id}]" data-attendance-mentee="${m.id}" value="Absen"> Absen
                                </label>
                            </div>
                            <input type="text" name="keterangan_izin[${m.id}][]" data-izin-note="${m.id}" class="form-control" placeholder="Keterangan izin (opsional)" style="margin-top:8px; display:none;" disabled>
                        </td>
                        <td>
                            <label class="checkbox-label" data-tidak-jelas-wrap="${m.id}" style="display:none;">
                                <input type="checkbox" name="tidak_jelas[${m.id}]" value="1" data-tidak-jelas="${m.id}" disabled> Tidak jelas kabarnya
                            </label>
                        </td>
                    </tr>
                `;
            });

            html += `</tbody></table></div>`;

            // Mobile Cards View
            html += `<div class="attendance-mobile">`;
            members.forEach(m => {
                const statusBadgeClass = (m.status_keaktifan === 'Peringatan') ? 'peringatan' : ((m.status_keaktifan === 'Tidak Aktif') ? 'tidak_aktif' : 'aktif');
                html += `
                    <div class="mentee-card-mobile">
                        <div class="mentee-card-header">
                            <div>
                                <div class="mentee-card-name">${escapeHtml(m.nama_lengkap)}</div>
                                <div class="mentee-card-sub">${escapeHtml(m.nim || '-')}${(m.kelas_tampil || m.kelas) ? ' &bull; ' + escapeHtml(m.kelas_tampil || m.kelas) : ''}</div>
                            </div>
                            <span class="badge-status ${statusBadgeClass}">${escapeHtml(m.status_keaktifan || 'Aktif')}</span>
                        </div>
                        <div class="mentee-card-body">
                            <div class="attendance-options-mobile">
                                <label class="radio-btn-pill">
                                    <input type="radio" name="kehadiran_mobile[${m.id}]" data-attendance-mentee="${m.id}" value="Hadir" checked>
                                    <span>Hadir</span>
                                </label>
                                <label class="radio-btn-pill">
                                    <input type="radio" name="kehadiran_mobile[${m.id}]" data-attendance-mentee="${m.id}" value="Izin">
                                    <span>Izin</span>
                                </label>
                                <label class="radio-btn-pill">
                                    <input type="radio" name="kehadiran_mobile[${m.id}]" data-attendance-mentee="${m.id}" value="Absen">
                                    <span>Absen</span>
                                </label>
                            </div>
                            <div style="margin-top: 10px; display:none;" data-tidak-jelas-wrap="${m.id}">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="tidak_jelas[${m.id}]" value="1" data-tidak-jelas="${m.id}" disabled> Tidak jelas kabarnya
                                </label>
                            </div>
                            <input type="text" name="keterangan_izin[${m.id}][]" data-izin-note="${m.id}" class="form-control" placeholder="Keterangan izin (opsional)" style="margin-top:8px; display:none;" disabled>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;

            attendanceContainer.innerHTML = html;

        } catch (err) {
            console.error(err);
            Modal.error('Terjadi kesalahan saat memuat data anggota tim.');
        } finally {
            if (typeof window.ukmiHideLoadingOverlay === 'function') {
                window.ukmiHideLoadingOverlay();
            }
        }
    });

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }

    // --- Biometric Verification Controller ---
    (function () {
        const blocker = document.getElementById('biometricBlocker');
        const modal = document.getElementById('biometricModal');
        const resultBox = document.getElementById('bioResult');
        const btnBack = document.getElementById('bioBtnBack');

        const stateIdentities = document.getElementById('bioStateIdentities');
        const stateMethod = document.getElementById('bioStateMethod');
        const stateMatched = document.getElementById('bioStateMatched');
        const divIdentities = document.getElementById('bioIdentities');
        const hintIdentities = document.getElementById('bioIdentitiesHint');
        const divMethods = document.getElementById('bioMethods');
        const methodLabel = document.getElementById('bioMethodLabel');
        const hintWebauthn = document.getElementById('bioWebauthnHint');
        const matchedName = document.getElementById('bioMatchedName');

        const form = document.getElementById('reportForm');

        let identities = [];
        let selectedEntity = null;
        let patActive = [];
        let bio = null;

        function show(msg, type) {
            resultBox.style.display = 'block';
            resultBox.className = 'biometric-result ' + type;
            resultBox.textContent = msg;
        }
        function hideResult() { resultBox.style.display = 'none'; }

        function showState(name) {
            stateIdentities.style.display = 'none';
            stateMethod.style.display = 'none';
            stateMatched.style.display = 'none';
            const el = document.getElementById(name);
            if (el) el.style.display = 'block';
        }

        async function loadIdentities() {
            try {
                // Proyeksi minimal tanpa data pribadi; status enroll sudah disertakan.
                const res = await fetch('api?action=biometric_identities');
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Gagal memuat identitas');
                return data.data || [];
            } catch (err) {
                show('Gagal memuat identitas: ' + err.message, 'error');
                return [];
            }
        }

        function renderIdentities(list) {
            divIdentities.innerHTML = '';
                if (list.length === 0) {
                    divIdentities.innerHTML = '<p class="biometric-hint" style="color:#f87171">Tidak ada identitas terdaftar.</p>';
                    hintIdentities.textContent = '';
                    return;
                }

            const label = document.createElement('label');
            label.className = 'biometric-hint';
            label.textContent = 'Cari dan pilih identitas Anda:';

            const select = document.createElement('select');
            select.id = 'bioIdentitySelect';
            select.className = 'form-control searchable';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Pilih identitas --';
            select.appendChild(placeholder);
            list.forEach((v, index) => {
                const opt = document.createElement('option');
                opt.value = String(index);
                opt.textContent = v.entity_name + ' — ' + v.role + (v.enrolled ? '' : ' (belum terdaftar)');
                select.appendChild(opt);
            });

            divIdentities.appendChild(label);
            divIdentities.appendChild(select);
            initSearchableSelect(select);

            select.addEventListener('change', () => {
                const v = list[Number(select.value)];
                hideResult();
                if (!v) {
                    selectedEntity = null;
                    hintIdentities.textContent = '';
                    return;
                }
                selectedEntity = v;
                if (!v.enrolled) {
                    hintIdentities.textContent = 'Identitas ini belum mendaftarkan biometrik. Minta admin membuatkan link pendaftaran biometrik.';
                    showState('bioStateIdentities');
                    return;
                }
                hintIdentities.textContent = 'Siap verifikasi sebagai ' + v.entity_name + ' (' + v.role + ')';
                btnBack.style.display = 'none';
                showState('bioStateMethod');
                renderMethodsFor(v);
            });
        }

        function renderMethodsFor(v) {
            divMethods.innerHTML = '';
            selectedEntity = v;

            if (v.method === 'webauthn') {
                methodLabel.textContent = 'Verifikasi Sidik Jari / Wajah';
                const waBtn = document.createElement('button');
                waBtn.type = 'button';
                waBtn.className = 'biometric-btn biometric-btn-webauthn';
                waBtn.textContent = 'Aktifkan Sensor (WebAuthn)';
                waBtn.disabled = typeof PublicKeyCredential === 'undefined';
                divMethods.appendChild(waBtn);
                waBtn.addEventListener('click', () => startWebauthnAuth(v));
            } else {
                methodLabel.textContent = 'Verifikasi Pola 3×3';
                patActive = [];
                const patWrap = document.createElement('div');
                patWrap.className = 'biometric-pattern';
                patWrap.innerHTML = '<div class="pattern-grid" id="bioPatternGrid"></div><div class="pattern-value" id="bioPatternValue">Belum ada pola</div><button type="button" class="biometric-btn biometric-btn-verify" id="bioBtnVerifyPattern" style="width:100%;margin-top:8px;" disabled>Verifikasi Pola</button><button type="button" class="biometric-btn-clear" id="bioBtnClearPattern">Hapus Pola</button><p class="biometric-hint">Ketuk atau seret minimal 3 titik (bisa lebih dari 3 titik), lalu tekan Verifikasi Pola.</p>';
                divMethods.appendChild(patWrap);

                const grid = document.getElementById('bioPatternGrid');
                const dots = [];
                for (let i = 1; i <= 9; i++) {
                    const d = document.createElement('div');
                    d.className = 'pattern-dot';
                    d.dataset.index = i;
                    d.innerHTML = '<div class="pattern-index">' + i + '</div>';
                    grid.appendChild(d);
                    dots.push(d);
                }
                let patDrawing = false;
                let patSubmitting = false;
                const patValue = document.getElementById('bioPatternValue');
                const patVerify = document.getElementById('bioBtnVerifyPattern');
                const patClear = document.getElementById('bioBtnClearPattern');
                function patRedraw() {
                    dots.forEach(d => d.classList.toggle('active', patActive.includes(d.dataset.index)));
                    patValue.textContent = patActive.length ? 'Pola: ' + patActive.join('') : 'Belum ada pola';
                    patVerify.disabled = patSubmitting || patActive.length < 3;
                }
                function touchDot(d) {
                    if (patSubmitting) return;
                    const i = d.dataset.index;
                    if (patActive.includes(i)) return;
                    hideResult();
                    patActive.push(i);
                    patRedraw();
                }
                function stopDraw() {
                    patDrawing = false;
                }
                grid.addEventListener('mousedown', e => { if (e.target.closest('.pattern-dot')) { patDrawing = true; touchDot(e.target.closest('.pattern-dot')); } });
                grid.addEventListener('mousemove', e => { if (!patDrawing) return; const d = document.elementFromPoint(e.clientX, e.clientY)?.closest('.pattern-dot'); if (d && d.closest('#bioPatternGrid') === grid) touchDot(d); });
                grid.addEventListener('mouseup', stopDraw);
                grid.addEventListener('mouseleave', stopDraw);
                grid.addEventListener('touchstart', e => { e.preventDefault(); const d = e.target.closest('.pattern-dot'); if (d) { patDrawing = true; touchDot(d); } }, { passive: false });
                grid.addEventListener('touchmove', e => { e.preventDefault(); if (!patDrawing) return; const d = document.elementFromPoint(e.touches[0].clientX, e.touches[0].clientY)?.closest('.pattern-dot'); if (d && d.closest('#bioPatternGrid') === grid) touchDot(d); }, { passive: false });
                grid.addEventListener('touchend', stopDraw);
                grid.addEventListener('touchcancel', stopDraw);
                patVerify.addEventListener('click', () => {
                    if (patSubmitting) return;
                    if (patActive.length < 3) {
                        show('Gambar pola minimal 3 titik', 'error');
                        return;
                    }
                    patSubmitting = true;
                    patRedraw();
                    serverAuthenticatePattern(v, () => {
                        patSubmitting = false;
                        patActive = [];
                        patRedraw();
                    });
                });
                patClear.addEventListener('click', () => { if (patSubmitting) return; patActive = []; hideResult(); patRedraw(); });
                patRedraw();
            }
        }

        async function startWebauthnAuth(v) {
            hintWebauthn.style.display = 'block';
            hintWebauthn.textContent = 'Menunggu respons sensor biometrik perangkat...';
            try {
                const data = await bio.authenticateWebauthn(v);
                if (data.success) {
                    if (window.MaiPwa && data.data) {
                        MaiPwa.saveBioOfflineGrant(data.data);
                    }
                    const who = data.data || {};
                    const name = who.entity_name || (v && v.entity_name) || '';
                    const role = who.role || (v && v.role) || '';
                    matchedName.textContent = name + ' (' + role + ')';
                    hideResult();
                    showState('bioStateMatched');
                    setTimeout(() => { unlockForm(who); }, 1200);
                    return true;
                }
                show(data.message || 'Verifikasi biometrik gagal', 'error');
                hintWebauthn.style.display = 'none';
                return false;
            } catch (err) {
                show('WebAuthn gagal: ' + err.message, 'error');
                hintWebauthn.style.display = 'none';
                return false;
            }
        }

        async function serverAuthenticatePattern(v, onFail) {
            show('Memverifikasi pola…', 'info');
            if (typeof window.ukmiShowLoadingOverlay === 'function') {
                window.ukmiShowLoadingOverlay('Memverifikasi pola biometrik...');
            }
            try {
                const res = await fetch('api?action=biometric_authenticate', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        entity_type: v.entity_type,
                        entity_id: v.entity_id,
                        method: 'pattern',
                        pattern: patActive.join('')
                    })
                });
                const data = await res.json();
                if (data.success) {
                    if (window.MaiPwa && data.data) {
                        MaiPwa.saveBioOfflineGrant(data.data);
                    }
                    const who = data.data || {};
                    const name = who.entity_name || (v && v.entity_name) || '';
                    const role = who.role || (v && v.role) || '';
                    matchedName.textContent = name + ' (' + role + ')';
                    hideResult();
                    showState('bioStateMatched');
                    setTimeout(() => { unlockForm(who); }, 1200);
                } else {
                    show(data.message || 'Verifikasi pola gagal', 'error');
                    if (onFail) onFail();
                }
            } catch (err) {
                show('Verifikasi gagal: ' + err.message, 'error');
                if (onFail) onFail();
            } finally {
                if (typeof window.ukmiHideLoadingOverlay === 'function') {
                    window.ukmiHideLoadingOverlay();
                }
            }
        }

        function unlockForm(verifiedData) {
            hideResult();
            blocker.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            // Form dikunci saat verifikasi biometrik masih berjalan. Setelah
            // verifikasi berhasil, semua kontrol form (termasuk input file
            // dokumentasi) harus diaktifkan kembali.
            if (form) {
                form.querySelectorAll('input, select, button, textarea').forEach(el => {
                    el.disabled = false;
                });
            }
            filterTeamsForBiometric(verifiedData?.allowed_team_ids);
            form.scrollIntoView({ behavior: 'smooth' });
        }

        // Kontroler ini sudah berjalan di dalam DOMContentLoaded induk,
        // jadi langsung jalankan inisialisasi (jangan daftarkan listener baru).
        (async () => {
            bio = new BiometricClient('api');
            blocker.style.display = 'block';
            modal.setAttribute('aria-hidden', 'false');

            if (form) {
                form.querySelectorAll('input, select, button, textarea').forEach(el => el.disabled = true);
            }

            const offlineGrant = window.MaiPwa ? MaiPwa.getBioOfflineGrant() : null;
            if (!navigator.onLine) {
                if (offlineGrant) {
                    showState('bioStateMatched');
                    matchedName.textContent = `${offlineGrant.entity_name || 'Mentor'} (${offlineGrant.role || 'Offline'})`;
                    show('Mode Offline aktif: menggunakan verifikasi perangkat tersimpan.', 'info');
                    setTimeout(() => { unlockForm(offlineGrant); }, 800);
                    return;
                }
                showState('bioStateIdentities');
                divIdentities.innerHTML = '<p class="biometric-hint" style="color:#f87171">Anda sedang offline dan perangkat ini belum pernah melakukan verifikasi biometrik saat online.</p>';
                hintIdentities.textContent = 'Hubungkan ke internet sekali untuk verifikasi pertama kali agar mode offline aktif.';
                return;
            }

            identities = await loadIdentities();
            if (identities.length === 0 && offlineGrant) {
                showState('bioStateMatched');
                matchedName.textContent = `${offlineGrant.entity_name || 'Mentor'} (${offlineGrant.role || 'Offline'})`;
                show('Mode Offline aktif: menggunakan verifikasi perangkat tersimpan.', 'info');
                setTimeout(() => { unlockForm(); }, 800);
                return;
            }
            const anyEnrolled = identities.some(v => v.enrolled);

            if (anyEnrolled) {
                const webauthnEnrolled = identities.find(v => v.enrolled && v.method === 'webauthn');

                if (webauthnEnrolled && typeof PublicKeyCredential !== 'undefined') {
                    showState('bioStateMethod');
                    methodLabel.textContent = 'Memindai Biometrik…';
                    divMethods.innerHTML = '<p class="biometric-hint">Silakan sentuh sensor sidik jari/wajah untuk diverifikasi.</p>';
                    const sensorOk = await startWebauthnAuth(webauthnEnrolled);
                    if (!sensorOk) {
                        // Sensor kosong/gagal -> fallback cari identitas.
                        showState('bioStateIdentities');
                        renderIdentities(identities);
                        hintIdentities.textContent = 'Sensor biometrik tidak tersedia. Silakan cari identitas dan gunakan pola 3×3.';
                    }
                } else {
                    showState('bioStateIdentities');
                    renderIdentities(identities);
                    hintIdentities.textContent = 'Pilih identitas Anda untuk melanjutkan verifikasi.';
                }
            } else {
                showState('bioStateIdentities');
                renderIdentities(identities);
                hintIdentities.textContent = 'Belum ada data biometrik terdaftar di sistem. Pilih identitas untuk melanjutkan.';
            }

            btnBack.addEventListener('click', () => {
                showState('bioStateIdentities');
                btnBack.style.display = 'none';
            });
        })();
    })();

    if (window.MaiPwa) {
        MaiPwa.init({
            mode: 'laporan',
            formId: 'reportForm',
            submitUrl: 'submit',
            swUrl: './sw.js',
            initialTeams: <?= json_encode($teams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        });
    }
});
</script>
</body>
</html>
