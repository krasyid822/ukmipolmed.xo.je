<?php
/**
 * master/laporan.php — Kelola Satu Laporan Mentoring (per laporan)
 * Admin dapat melihat rincian, memperbarui isi laporan, mengirim ulang ke
 * grup WhatsApp, dan menghapus laporan beserta objek S3-nya.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\Config;
use Mentoring\MasterData;
use Mentoring\ReportRepository;
use Mentoring\Storage;
use Mentoring\Watobot;
use Mentoring\BotQueue;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$listUrl = $basePath . '?tab=tab-laporan';
$reportId = trim((string)($_POST['id'] ?? $_GET['id'] ?? ''));

if ($reportId === '' || !Storage::findPointer('laporan', $reportId)) {
    header('Location: ' . $basePath . '?error=report_notfound&tab=tab-laporan', true, 303);
    exit;
}

$report = ReportRepository::load($reportId);
if ($report === null) {
    header('Location: ' . $basePath . '?error=report_notfound&tab=tab-laporan', true, 303);
    exit;
}

$roleGenderLabel = AdminAuth::allowedGenderLabel();
if ($roleGenderLabel !== null) {
    $teamGenders = ReportRepository::teamGenderMap();
    $reportGender = $teamGenders['tim:' . ($report['tim_id'] ?? '')]
        ?? $teamGenders['nama:' . ($report['nama_tim'] ?? '')]
        ?? '';
    if ($reportGender !== $roleGenderLabel) {
        header('Location: ' . $basePath . '?error=report_notfound&tab=tab-laporan', true, 303);
        exit;
    }
}

/**
 * Cari tim dari master data berdasarkan tim_id laporan, atau nama tim sebagai fallback.
 */
$teams = MasterData::getTeamsWithDetails();
$team = null;
foreach ($teams as $candidate) {
    if (($report['tim_id'] ?? '') !== '' && $candidate['id'] === $report['tim_id']) {
        $team = $candidate;
        break;
    }
}
if ($team === null) {
    foreach ($teams as $candidate) {
        if (($candidate['nama_tim'] ?? '') === ($report['nama_tim'] ?? '')) {
            $team = $candidate;
            break;
        }
    }
}

/**
 * Daftar anggota untuk presensi: mentee tim saat ini + nama yang sudah tercatat
 * di laporan (agar mentee lama/keluar tetap bisa dikelola).
 */
$attendanceMembers = [];
$seenNames = [];
$reportIzinNotes = is_array($report['izin_keterangan'] ?? null) ? $report['izin_keterangan'] : [];
$reportIzinNotesByKey = [];
foreach ($reportIzinNotes as $noteName => $noteValue) {
    $noteKey = mb_strtolower(trim((string)$noteName));
    if ($noteKey !== '') {
        $reportIzinNotesByKey[$noteKey] = trim((string)$noteValue);
    }
}
$addMember = static function (string $key, string $name, string $meta, string $current) use (&$attendanceMembers, &$seenNames, $reportIzinNotesByKey): void {
    $normalized = mb_strtolower(trim($name));
    if ($normalized === '' || isset($seenNames[$normalized])) {
        return;
    }
    $seenNames[$normalized] = true;
    $attendanceMembers[] = [
        'key' => $key,
        'name' => $name,
        'meta' => $meta,
        'current' => $current,
        'note' => $reportIzinNotesByKey[$normalized] ?? '',
    ];
};

foreach ($team['mentees'] ?? [] as $mentee) {
    $menteeName = (string)($mentee['nama_lengkap'] ?? '');
    $current = 'Tidak tercatat';
    foreach (['hadir', 'izin', 'absen'] as $statusKey) {
        foreach (($report[$statusKey] ?? []) as $name) {
            if (mb_strtolower(trim((string)$name)) === mb_strtolower(trim($menteeName))) {
                $current = ucfirst($statusKey);
                break 2;
            }
        }
    }
    $meta = trim((string)($mentee['nim'] ?? '') . ' • ' . (string)($mentee['kelas_tampil'] ?? $mentee['kelas'] ?? ''), ' •');
    $addMember('m:' . (string)($mentee['id'] ?? $menteeName), $menteeName, $meta, $current);
}

$extraIndex = 0;
foreach (['hadir', 'izin', 'absen'] as $statusKey) {
    foreach (($report[$statusKey] ?? []) as $name) {
        $normalized = mb_strtolower(trim((string)$name));
        if ($normalized === '' || isset($seenNames[$normalized])) {
            continue;
        }
        $addMember('x:' . $extraIndex, (string)$name, 'Di luar daftar tim saat ini', ucfirst($statusKey));
        $extraIndex++;
    }
}

$splitNames = static function (string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $names = [];
    foreach ($lines as $line) {
        $name = trim($line);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
};

$detailUrl = static function (string $msg = '', string $error = '') use ($basePath, $reportId): string {
    $url = $basePath . 'laporan?id=' . urlencode($reportId);
    if ($msg !== '') {
        $url .= '&msg=' . urlencode($msg);
    }
    if ($error !== '') {
        $url .= '&error=' . urlencode($error);
    }
    return $url;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrfToken()) {
        http_response_code(403);
        die('Token CSRF tidak valid atau sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        ReportRepository::delete($reportId);
        BotQueue::removeByReportId($reportId);
        header('Location: ' . $basePath . '?msg=report_deleted&tab=tab-laporan', true, 303);
        exit;
    }

    if ($action === 'resend') {
        $botChannel = Watobot::resolveChannelForTeam((string)($report['tim_id'] ?? ''), (string)($report['nama_tim'] ?? ''));
        if (!Watobot::isEnabled($botChannel)) {
            header('Location: ' . $detailUrl('', 'bot_disabled'), true, 303);
            exit;
        }
        $message = Watobot::buildMessageText($report);
        $result = Watobot::sendReport($message, [], $botChannel);
        if (empty($result['success'])) {
            BotQueue::enqueue($reportId, $message, [
                'nama_tim' => (string)($report['nama_tim'] ?? ''),
                'tanggal' => (string)($report['tanggal'] ?? ''),
            ], $botChannel);
            if (!empty($result['api_invalid'])) {
                BotQueue::markApiInvalid(
                    (string)($result['failure_reason'] ?? 'API bot kedaluwarsa'),
                    (int)($result['http_code'] ?? 0),
                    $botChannel
                );
            }
        }
        header('Location: ' . $detailUrl($result['success'] ? 'sent' : 'send_queued'), true, 303);
        exit;
    }

    if ($action === 'update') {
        $tanggal = trim((string)($_POST['tanggal'] ?? ''));
        $tanggalValid = DateTime::createFromFormat('Y-m-d', $tanggal);
        if (!$tanggalValid || $tanggalValid->format('Y-m-d') !== $tanggal) {
            header('Location: ' . $detailUrl('', 'tanggal'), true, 303);
            exit;
        }

        // Kehadiran dibentuk ulang dari pilihan admin per mentee.
        $postedAttendance = $_POST['kehadiran'] ?? [];
        $postedIzinNotes = $_POST['izin_keterangan'] ?? [];
        $hadir = [];
        $izin = [];
        $absen = [];
        $izinKeterangan = [];
        foreach ($attendanceMembers as $member) {
            $status = (string)($postedAttendance[$member['key']] ?? 'Tidak tercatat');
            if ($status === 'Hadir') {
                $hadir[] = $member['name'];
            } elseif ($status === 'Izin') {
                $izin[] = $member['name'];
                $note = trim((string)($postedIzinNotes[$member['key']] ?? ''));
                if ($note !== '') {
                    $izinKeterangan[$member['name']] = $note;
                }
            } elseif ($status === 'Absen') {
                $absen[] = $member['name'];
            }
        }

        $report['tanggal'] = $tanggal;
        $report['jam_mulai'] = trim((string)($_POST['jam_mulai'] ?? '')) ?: '16:00';
        $report['jam_selesai'] = trim((string)($_POST['jam_selesai'] ?? '')) ?: '17:30';
        $report['tempat'] = trim((string)($_POST['tempat'] ?? ''));
        $report['tema'] = trim((string)($_POST['tema'] ?? ''));
        $report['mentor'] = trim((string)($_POST['mentor'] ?? ''));
        $report['moderator'] = trim((string)($_POST['moderator'] ?? ''));
        $report['tilawah'] = trim((string)($_POST['tilawah'] ?? ''));
        $report['tadabbur_petugas'] = trim((string)($_POST['tadabbur_petugas'] ?? ''));
        $report['tadabbur_surah_ayat'] = trim((string)($_POST['tadabbur_surah_ayat'] ?? ''));
        $report['tadabbur_inti'] = trim((string)($_POST['tadabbur_inti'] ?? ''));
        $report['kultum_petugas'] = trim((string)($_POST['kultum_petugas'] ?? ''));
        $report['kultum_judul_inti'] = trim((string)($_POST['kultum_judul_inti'] ?? ''));
        $report['doa_petugas'] = trim((string)($_POST['doa_petugas'] ?? ''));
        $report['hadir'] = $hadir;
        $report['izin'] = $izin;
        $report['izin_keterangan'] = $izinKeterangan;
        $report['absen'] = $absen;
        $report['updated_at'] = date('c');

        if (!ReportRepository::save($report)) {
            header('Location: ' . $detailUrl('', 'simpan'), true, 303);
            exit;
        }

        // Kirim ulang ke WhatsApp untuk transparansi setiap kali isi laporan diperbarui (jika bot aktif)
        $botChannel = Watobot::resolveChannelForTeam((string)($report['tim_id'] ?? ''), (string)($report['nama_tim'] ?? ''));
        if (!Watobot::isEnabled($botChannel)) {
            header('Location: ' . $detailUrl('updated'), true, 303);
            exit;
        }
        $updatedMessage = Watobot::buildMessageText($report);
        $botResult = Watobot::sendReport($updatedMessage, [], $botChannel);

        if (empty($botResult['success'])) {
            BotQueue::enqueue($reportId, $updatedMessage, [
                'nama_tim' => (string)($report['nama_tim'] ?? ''),
                'tanggal' => (string)($report['tanggal'] ?? ''),
            ], $botChannel);
            if (!empty($botResult['api_invalid'])) {
                BotQueue::markApiInvalid(
                    (string)($botResult['failure_reason'] ?? 'API bot kedaluwarsa'),
                    (int)($botResult['http_code'] ?? 0),
                    $botChannel
                );
            }
        }

        $msg = $botResult['success'] ? 'updated_sent' : 'updated_queued';
        header('Location: ' . $detailUrl($msg), true, 303);
        exit;
    }
}

$pointer = Storage::findPointer('laporan', $reportId) ?? [];
$dokRelativePath = (string)($pointer['dokumentasi_path'] ?? '');
$dokUrl = $dokRelativePath !== '' ? $appBase . 'media?key=' . rawurlencode($dokRelativePath) : '';

$botText = Watobot::buildMessageText($report);

$messages = [
    'updated' => 'Perubahan laporan berhasil disimpan dan file laporan di storage sudah diperbarui.',
    'updated' => 'Perubahan laporan berhasil disimpan (bot WhatsApp sedang dimatikan).',
    'updated_sent' => 'Perubahan laporan berhasil disimpan dan dikirim ulang ke grup WhatsApp.',
    'updated_queued' => 'Perubahan laporan berhasil disimpan. Pengiriman ke grup WhatsApp sedang dalam antrean dan akan dicoba ulang otomatis.',
    'sent' => 'Laporan berhasil dikirim ulang ke grup WhatsApp.',
    'send_queued' => 'Laporan gagal dikirim saat ini, tetapi sudah masuk antrean dan akan dicoba ulang otomatis.',
];
$errors = [
    'tanggal' => 'Tanggal laporan tidak valid. Gunakan format tanggal yang benar.',
    'simpan' => 'Laporan gagal disimpan ke storage. Silakan coba lagi.',
    'bot_disabled' => 'Fitur bot WhatsApp untuk kategori tim ini sedang dimatikan. Aktifkan kembali di tab Kelola Laporan.',
];

$message = $messages[(string)($_GET['msg'] ?? '')] ?? '';
$errorMessage = $errors[(string)($_GET['error'] ?? '')] ?? '';

// Opsi nama untuk petugas: mentor + mentee + nilai yang sudah tersimpan.
$uniqueNames = static function (array $names): array {
    $result = [];
    $seen = [];
    foreach ($names as $name) {
        $name = trim((string)$name);
        $key = mb_strtolower($name);
        if ($name === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $name;
    }
    return $result;
};

$memberNames = [];
foreach ($team['mentees'] ?? [] as $mentee) {
    $memberNames[] = (string)($mentee['nama_lengkap'] ?? '');
}
$personnelOptions = $uniqueNames(array_merge(
    [(string)($team['mentor_nama'] ?? '')],
    $memberNames,
    [
        (string)($report['moderator'] ?? ''),
        (string)($report['tadabbur_petugas'] ?? ''),
        (string)($report['kultum_petugas'] ?? ''),
        (string)($report['doa_petugas'] ?? ''),
    ]
));

$mentorOptions = [];
foreach ((MasterData::loadAll()['mentors'] ?? []) as $mentor) {
    $mentorOptions[] = (string)($mentor['nama_lengkap'] ?? '');
}
$mentorOptions = $uniqueNames(array_merge($mentorOptions, [(string)($report['mentor'] ?? '')]));

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
$reportTanggal = (string)($report['tanggal'] ?? '');
$tanggalText = $reportTanggal !== '' ? Watobot::formatTanggalIndonesia($reportTanggal) : '-';
$attendanceRate = ReportRepository::attendanceRate(
    count($report['hadir'] ?? []),
    count($report['izin'] ?? []),
    count($report['absen'] ?? [])
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Laporan — <?= $h($report['nama_tim'] ?? 'Mentoring') ?></title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
    <style>
        .laporan-photo { width: 100%; height: 300px; background: #090d16; border-radius: 12px; overflow: hidden; margin-bottom: 16px; }
        .laporan-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .laporan-photo-empty { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--text-muted); font-size: 0.9rem; }
        .detail-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .detail-chip { background: #0b1120; border: 1px solid #334155; border-radius: 999px; padding: 5px 12px; font-size: 0.8rem; color: #cbd5e1; }
        .report-box {
            background: #0b1120; border: 1px solid #1e293b; border-radius: 12px; padding: 18px;
            font-family: 'JetBrains Mono', monospace; font-size: 0.84rem; line-height: 1.65;
            white-space: pre-wrap; color: #e2e8f0; overflow-x: auto; margin-bottom: 16px;
        }
        .actions-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .actions-row form { margin: 0; }
        .btn-link {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 16px; border-radius: 10px; border: 1px solid #334155; background: #1e293b;
            color: #cbd5e1; font-size: 0.88rem; font-weight: 600; font-family: inherit;
            text-decoration: none; cursor: pointer; min-height: 42px;
        }
        .btn-link:hover { border-color: #38bdf8; color: #e0f2fe; }
        .btn-link.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-link.primary:hover { background: var(--primary-hover); }
        .btn-link.danger { background: rgba(239, 68, 68, 0.12); border-color: #7f1d1d; color: #fca5a5; }
        .btn-link.danger:hover { background: rgba(239, 68, 68, 0.25); border-color: #ef4444; color: #fecaca; }
        .attendance-editor-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin-top: 4px; }
        .attendance-editor { width: 100%; min-width: 780px; border-collapse: collapse; }
        .attendance-editor th, .attendance-editor td { padding: 10px 12px; border-bottom: 1px solid #334155; text-align: left; }
        .attendance-editor th { background: #0b1120; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .attendance-editor .member-meta { display: block; font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; }
        .status-options { display: flex; gap: 12px; flex-wrap: wrap; }
        .status-options label { display: inline-flex; align-items: center; gap: 5px; font-size: 0.82rem; cursor: pointer; white-space: nowrap; }
        .copy-toast {
            display: none; position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: #10b981; color: #fff; padding: 10px 20px; border-radius: 999px;
            font-weight: 600; font-size: 0.9rem; z-index: 1000;
        }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Kelola Laporan Mentoring</h1>
        <p class="page-subtitle"><?= $h($report['nama_tim'] ?? 'Tanpa Tim') ?> &bull; <?= $h($tanggalText) ?> &bull; <?= $h($reportId) ?></p>
        <div class="subpage-back">
            <a href="<?= $h($listUrl) ?>">&larr; Kembali ke Kelola Laporan</a>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= $h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= $h($errorMessage) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($dokUrl !== ''): ?>
            <div class="laporan-photo">
                <img src="<?= $h($dokUrl) ?>" alt="Dokumentasi <?= $h($report['nama_tim'] ?? '') ?>" onerror="this.parentElement.innerHTML='<div class=&quot;laporan-photo-empty&quot;>Dokumentasi tidak dapat dimuat.</div>'">
            </div>
        <?php else: ?>
            <div class="laporan-photo"><div class="laporan-photo-empty">Belum ada dokumentasi.</div></div>
        <?php endif; ?>

        <div class="detail-meta">
            <span class="detail-chip">Hadir: <?= count($report['hadir'] ?? []) ?></span>
            <span class="detail-chip">Izin: <?= count($report['izin'] ?? []) ?></span>
            <span class="detail-chip">Absen: <?= count($report['absen'] ?? []) ?></span>
            <span class="detail-chip">Kehadiran: <?= $h((string)$attendanceRate) ?>%</span>
        </div>

        <h3 class="card-title">Pratinjau Teks WhatsApp</h3>
        <div class="report-box" id="reportText"><?= $h($botText) ?></div>

        <div class="actions-row">
            <button type="button" class="btn-link" onclick="copyReport()">Salin Teks</button>
            <a class="btn-link" href="<?= $h($appBase) ?>view?id=<?= urlencode($reportId) ?>" target="_blank" rel="noopener">Lihat Halaman Publik</a>
            <form method="POST" id="formResend">
            <?= AdminAuth::csrfField() ?>
                <input type="hidden" name="id" value="<?= $h($reportId) ?>">
                <input type="hidden" name="action" value="resend">
                <button type="button" class="btn-link primary" onclick="confirmSubmit(this.closest('form'), 'Kirim ulang laporan ini ke grup WhatsApp?')">Kirim Ulang ke WhatsApp</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">Perbarui Isi Laporan</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Pilih nama petugas dan status kehadiran langsung dari data tim. Tidak perlu mengetik ulang nama.
        </p>
        <form method="POST">
            <?= AdminAuth::csrfField() ?>
            <input type="hidden" name="id" value="<?= $h($reportId) ?>">
            <input type="hidden" name="action" value="update">

            <div class="form-grid">
                <div class="form-group">
                    <label>Nama Tim:</label>
                    <input type="text" class="form-control" value="<?= $h($report['nama_tim'] ?? '') ?>" disabled>
                </div>
                <div class="form-group">
                    <label>Mentor Pendamping:</label>
                    <select name="mentor" class="form-control" data-searchable>
                        <?php if ($mentorOptions === []): ?>
                            <option value="">-- Belum ada data mentor --</option>
                        <?php else: ?>
                            <?php foreach ($mentorOptions as $option): ?>
                                <option value="<?= $h($option) ?>" <?= ($report['mentor'] ?? '') === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tanggal: <span style="color:#f87171">*</span></label>
                    <input type="date" name="tanggal" class="form-control" required value="<?= $h($reportTanggal) ?>">
                </div>
                <div class="form-group">
                    <label>Tempat:</label>
                    <input type="text" name="tempat" class="form-control" value="<?= $h($report['tempat'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Jam Mulai:</label>
                    <input type="time" name="jam_mulai" class="form-control" value="<?= $h($report['jam_mulai'] ?? '16:00') ?>">
                </div>
                <div class="form-group">
                    <label>Jam Selesai:</label>
                    <input type="time" name="jam_selesai" class="form-control" value="<?= $h($report['jam_selesai'] ?? '17:30') ?>">
                </div>
                <div class="form-group col-span-2">
                    <label>Tema:</label>
                    <input type="text" name="tema" class="form-control" value="<?= $h($report['tema'] ?? '') ?>">
                </div>
            </div>

            <div class="form-section-label">Petugas &amp; Materi</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Moderator:</label>
                    <select name="moderator" class="form-control" data-searchable>
                        <option value="">-- Pilih Anggota --</option>
                        <?php foreach ($personnelOptions as $option): ?>
                            <option value="<?= $h($option) ?>" <?= ($report['moderator'] ?? '') === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tilawah (progres kelompok):</label>
                    <input type="text" name="tilawah" class="form-control" value="<?= $h($report['tilawah'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Petugas Tadabbur:</label>
                    <select name="tadabbur_petugas" class="form-control" data-searchable>
                        <option value="">-- Pilih Anggota --</option>
                        <?php foreach ($personnelOptions as $option): ?>
                            <option value="<?= $h($option) ?>" <?= ($report['tadabbur_petugas'] ?? '') === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Surah &amp; Ayat Tadabbur:</label>
                    <input type="text" name="tadabbur_surah_ayat" class="form-control" value="<?= $h($report['tadabbur_surah_ayat'] ?? '') ?>">
                </div>
                <div class="form-group col-span-2">
                    <label>Inti Tadabbur:</label>
                    <input type="text" name="tadabbur_inti" class="form-control" value="<?= $h($report['tadabbur_inti'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Petugas Kultum:</label>
                    <select name="kultum_petugas" class="form-control" data-searchable>
                        <option value="">-- Pilih Anggota --</option>
                        <?php foreach ($personnelOptions as $option): ?>
                            <option value="<?= $h($option) ?>" <?= ($report['kultum_petugas'] ?? '') === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Judul / Inti Kultum:</label>
                    <input type="text" name="kultum_judul_inti" class="form-control" value="<?= $h($report['kultum_judul_inti'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Petugas Do'a:</label>
                    <select name="doa_petugas" class="form-control" data-searchable>
                        <option value="">-- Pilih Anggota --</option>
                        <?php foreach ($personnelOptions as $option): ?>
                            <option value="<?= $h($option) ?>" <?= ($report['doa_petugas'] ?? '') === $option ? 'selected' : '' ?>><?= $h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-section-label">Presensi Kehadiran</div>
            <?php if ($attendanceMembers === []): ?>
                <div style="padding: 16px; text-align: center; color: var(--text-muted); font-size: 0.88rem;">
                    Belum ada anggota tim yang bisa ditampilkan.
                </div>
            <?php else: ?>
                <div class="attendance-editor-wrap">
                    <table class="attendance-editor">
                        <thead>
                            <tr><th>Nama Mentee</th><th>Status Presensi</th><th>Keterangan Izin</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendanceMembers as $member): ?>
                                <tr>
                                    <td>
                                        <b><?= $h($member['name']) ?></b>
                                        <?php if ($member['meta'] !== ''): ?>
                                            <span class="member-meta"><?= $h($member['meta']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="status-options">
                                            <?php foreach (['Hadir', 'Izin', 'Absen', 'Tidak tercatat'] as $status): ?>
                                                <label>
                                                    <input type="radio" name="kehadiran[<?= $h($member['key']) ?>]" value="<?= $h($status) ?>" <?= $member['current'] === $status ? 'checked' : '' ?>>
                                                    <?= $h($status) ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" name="izin_keterangan[<?= $h($member['key']) ?>]" class="form-control" value="<?= $h($member['note']) ?>" placeholder="Opsional, mis. sakit">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Simpan Perubahan</button>
                <a href="<?= $h($listUrl) ?>" class="btn-cancel">Batal</a>
            </div>
        </form>
    </div>

    <div class="card" style="border-color: #7f1d1d;">
        <h3 class="card-title" style="color: #f87171;">Hapus Laporan</h3>
        <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 14px;">
            Menghapus laporan akan menghapus data terenkripsi, dokumentasi, dan metadata OG
            dari storage. Tindakan ini tidak dapat dibatalkan.
        </p>
        <form method="POST">
            <?= AdminAuth::csrfField() ?>
            <input type="hidden" name="id" value="<?= $h($reportId) ?>">
            <input type="hidden" name="action" value="delete">
            <button type="button" class="btn-link danger" onclick="confirmSubmit(this.closest('form'), 'Hapus laporan ini secara permanen? Tindakan tidak dapat dibatalkan.')">Hapus Laporan Permanen</button>
        </form>
    </div>
</div>

<div class="copy-toast" id="toast">✓ Teks laporan berhasil disalin!</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
<script src="<?= $h($appBase) ?>public/assets/js/modal.js?v=2"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('select[data-searchable]').forEach(select => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(select);
    });
});

function copyReport() {
    const text = document.getElementById('reportText').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.getElementById('toast');
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 2500);
    }).catch(() => Modal.error('Gagal menyalin teks secara otomatis. Silakan salin manual.'));
}

async function confirmSubmit(form, message) {
    if (await Modal.confirm(message)) {
        if (typeof window.ukmiShowLoadingOverlay === 'function') {
            window.ukmiShowLoadingOverlay('Memproses laporan, mohon tunggu...');
        }
        form.submit();
    }
}
</script>

</body>
</html>
