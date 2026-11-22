<?php
/**
 * public/master/index.php — Manajemen CRUD Data Master (Mentors, Teams, Mentees)
 * Menyediakan antarmuka pengelolaan master data secara mandiri
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\Academic;
use Mentoring\AdminAuth;
use Mentoring\MasterData;
use Mentoring\Config;
use Mentoring\ProfilePhoto;
use Mentoring\ReportRepository;
use Mentoring\Watobot;
use Mentoring\BotQueue;

AdminAuth::guard();

// Kesempatan mencoba ulang antrean bot secara otomatis setiap admin
// membuka halaman master (tidak menunggu cron hosting).
BotQueue::processDue(1);
$botState = BotQueue::state();
$botQueueItems = BotQueue::items();
$botPendingCount = count($botQueueItems);
$showBotModal = !empty($botState['api_invalid']);

$perPage = (int)Config::get('app.pagination_per_page', 5);
$master = MasterData::loadAll();
$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));

$message = '';
$msgMap = [
    'mentor_added' => ['Mentor berhasil ditambahkan!', 'tab-mentors'],
    'mentor_updated' => ['Data mentor berhasil diperbarui!', 'tab-mentors'],
    'team_added' => ['Tim berhasil ditambahkan!', 'tab-teams'],
    'team_updated' => ['Data tim berhasil diperbarui!', 'tab-teams'],
    'mentee_added' => ['Mentee berhasil ditambahkan!', 'tab-mentees'],
    'mentee_updated' => ['Data mentee berhasil diperbarui!', 'tab-mentees'],
    'foto_updated' => ['Foto profil berhasil diperbarui!', ''],
    'deleted' => ['Data berhasil dihapus.', ''],
    'archived' => ['Data berhasil dipindahkan ke Arsip Purna.', 'tab-archive'],
    'report_updated' => ['Laporan berhasil diperbarui!', 'tab-laporan'],
    'report_deleted' => ['Laporan berhasil dihapus.', 'tab-laporan'],
    'report_sent' => ['Laporan berhasil dikirim ulang ke grup WhatsApp!', 'tab-laporan'],
    'report_send_failed' => ['Laporan tersimpan, tetapi pengiriman ke grup WhatsApp masuk antrean ulang.', 'tab-laporan'],
    'registrant_updated' => ['Data pendaftar berhasil diperbarui!', 'tab-pendaftar'],
    'registrant_approved' => ['Pendaftar berhasil diterima dan ditambahkan ke Daftar Mentee!', 'tab-pendaftar'],
    'registrant_deleted' => ['Data pendaftar berhasil dihapus.', 'tab-pendaftar'],
    'bot_api_saved' => ['API bot berhasil diperbarui.', ''],
];
$msgKey = $_GET['msg'] ?? '';
$activeTab = 'tab-mentors';
if (isset($msgMap[$msgKey])) {
    $message = $msgMap[$msgKey][0];
    if ($msgMap[$msgKey][1] !== '') {
        $activeTab = $msgMap[$msgKey][1];
    }
}

$errorMessage = '';
$errorKey = $_GET['error'] ?? '';
if ($errorKey === 'foto') {
    $errorMessage = 'Foto gagal diproses. Gunakan foto selfie (JPG/PNG/WEBP) maksimal 8 MB.';
} elseif ($errorKey === 'relasi') {
    $errorMessage = trim((string)($_GET['pesan'] ?? '')) ?: 'Data tidak dapat dihapus karena masih terhubung dengan data lain.';
} elseif ($errorKey === 'hapus') {
    $errorMessage = 'Data gagal dihapus.';
} elseif ($errorKey === 'notfound') {
    $errorMessage = 'Data tidak ditemukan atau sudah dihapus.';
} elseif ($errorKey === 'report_notfound') {
    $errorMessage = 'Laporan tidak ditemukan atau sudah dihapus.';
} elseif ($errorKey === 'simpan' || $errorKey === 'storage' || $errorKey === 'muat') {
    $errorMessage = trim((string)($_GET['pesan'] ?? '')) ?: 'Data gagal disimpan. Data lama tidak diubah.';
} elseif ($errorKey === 'konflik') {
    $errorMessage = 'Data master sudah berubah oleh proses lain. Muat ulang halaman lalu ulangi perubahan.';
} elseif ($errorKey === 'kosong') {
    $errorMessage = 'Penyimpanan dibatalkan karena data master terbaca kosong. Data lama tetap aman.';
} elseif ($errorKey === 'validasi') {
    $errorMessage = trim((string)($_GET['pesan'] ?? '')) ?: 'Data tidak lolos validasi skema master.';
} elseif ($errorKey === 'logo_duplikat') {
    $errorMessage = 'Logo ini sudah dipakai tim lain. Gunakan logo yang berbeda.';
} elseif ($errorKey === 'biometric_reset') {
    $errorMessage = 'Gagal mereset biometrik. Coba lagi.';
}

// Tab yang diminta setelah aksi hapus / unggah foto
$requestedTab = (string)($_GET['tab'] ?? '');
if (in_array($requestedTab, ['tab-mentors', 'tab-teams', 'tab-mentees', 'tab-export', 'tab-archive', 'tab-laporan', 'tab-pendaftar'], true)) {
    $activeTab = $requestedTab;
}

// ---- Data tab Kelola Laporan (daftar + rekap) ----
$laporanAll = ReportRepository::summaries();
$laporanBulan = (string)($_GET['laporan_bulan'] ?? '');
$laporanTim = (string)($_GET['laporan_tim'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $laporanBulan)) {
    $laporanBulan = '';
}

$laporanFiltered = array_values(array_filter($laporanAll, static function (array $s) use ($laporanBulan, $laporanTim): bool {
    $tanggal = $s['tanggal'] !== '' ? $s['tanggal'] : substr($s['created_at'], 0, 10);
    if ($laporanBulan !== '' && substr($tanggal, 0, 7) !== $laporanBulan) {
        return false;
    }
    if ($laporanTim !== '' && 'nama:' . $s['nama_tim'] !== $laporanTim) {
        return false;
    }
    return true;
}));

$laporanRecap = ReportRepository::recap($laporanFiltered);

$opsiBulan = [];
$opsiTim = [];
foreach ($laporanAll as $s) {
    $tanggal = $s['tanggal'] !== '' ? $s['tanggal'] : substr($s['created_at'], 0, 10);
    if (preg_match('/^(\d{4}-\d{2})/', $tanggal, $m)) {
        $opsiBulan[$m[1]] = true;
    }
    $opsiTim['nama:' . $s['nama_tim']] = $s['nama_tim'];
}
krsort($opsiBulan);
asort($opsiTim);

$bulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$formatBulan = static function (string $ym) use ($bulanIndo): string {
    [$y, $m] = array_pad(explode('-', $ym, 2), 2, '');
    return ($bulanIndo[(int)$m] ?? $m) . ' ' . $y;
};

/**
 * Render sel avatar (hanya tampilan; ubah foto dilakukan di halaman pengaturan).
 */
$renderAvatar = function (string $entity, string $id, ?string $path, string $name): string {
    $url = ProfilePhoto::urlFor($path);
    $trimmed = trim($name);
    $initial = strtoupper(substr($trimmed !== '' ? $trimmed : '?', 0, 1));
    $inner = $url
        ? '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="Foto ' . htmlspecialchars($name, ENT_QUOTES) . '">'
        : '<span class="avatar-initial">' . htmlspecialchars($initial) . '</span>';

    return '<td><span class="avatar-wrap" style="cursor: default;">' . $inner . '</span></td>';
};

/**
 * Render kolom aksi: satu tombol Pengaturan (ubah biodata, foto, hapus, arsip
 * semuanya tersedia di subhalaman pengaturan).
 */
$renderActions = function (string $entity, string $editSlug, string $id) use ($basePath): string {
    $settingsType = $entity === 'team' ? 'tim' : $entity;
    $settingsUrl = $basePath . 'pengaturan?tipe=' . urlencode($settingsType) . '&id=' . urlencode($id);

    return '<td><a class="row-action settings" href="' . htmlspecialchars($settingsUrl, ENT_QUOTES) . '">&#9881; Pengaturan</a></td>';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Data Master Mentoring MAI</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(mentoring_base_path(dirname(__DIR__))) ?>public/assets/css/style.css">
    <style>
        .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--card-border); padding-bottom: 12px; }
        .tab-btn { background: none; border: none; color: var(--text-muted); font-size: 1rem; font-weight: 600; padding: 8px 16px; border-radius: 8px; cursor: pointer; }
        .tab-btn.active { background: var(--primary); color: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
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

        /* Search input bar on table cards */
        .table-search-bar {
            margin-bottom: 14px;
            display: flex;
            align-items: center;
        }
        .table-search-input {
            width: 100%;
            max-width: 340px;
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px 12px;
            color: #f8fafc;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .table-search-input:focus {
            border-color: var(--primary);
        }

        /* Aksi baris (ubah / hapus) */
        .row-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .row-action {
            display: inline-block;
            white-space: nowrap;
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid #334155;
            background: #1e293b;
            color: #cbd5e1;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }
        .row-action.edit:hover,
        .row-action.settings:hover { border-color: #38bdf8; color: #38bdf8; }
        .row-action.delete {
            background: rgba(239, 68, 68, 0.12);
            border-color: #7f1d1d;
            color: #fca5a5;
        }
        .row-action.delete:hover { background: rgba(239, 68, 68, 0.25); border-color: #ef4444; }

        /* Kolom aksi cukup selebar tombolnya agar tidak turun baris */
        .attendance-table th:last-child,
        .attendance-table td:last-child {
            width: 1%;
            white-space: nowrap;
        }

        .tab-actions { gap: 10px; flex-wrap: wrap; }
        .export-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .export-actions .btn-action { padding: 9px 14px; min-height: 40px; font-size: 0.85rem; }

        /* Tab Kelola Laporan */
        .laporan-filter {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 18px;
        }
        .filter-field { display: flex; flex-direction: column; gap: 6px; flex: 1 1 210px; min-width: 0; }
        .filter-field label { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; }
        .filter-field select { min-height: 42px; }
        .laporan-filter .btn-action { width: auto; min-height: 42px; padding: 9px 18px; }
        .laporan-filter .btn-cancel { min-height: 42px; display: inline-flex; align-items: center; }

        .recap-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 18px; }
        .stat-box { background: #0b1120; border: 1px solid #334155; border-radius: 12px; padding: 14px; }
        .stat-box .stat-label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-box .stat-value { margin-top: 4px; font-size: 1.45rem; font-weight: 700; color: #f1f5f9; line-height: 1.2; }
        .stat-box .stat-value small { font-size: 0.78rem; font-weight: 600; color: #64748b; }
        .recap-cta { display: flex; justify-content: flex-end; }
        .recap-cta .btn-action { width: auto; min-height: 44px; padding: 10px 18px; font-size: 0.9rem; }
        .row-action.manage { background: rgba(2, 132, 199, 0.14); border-color: #075985; color: #7dd3fc; }
        .row-action.manage:hover { background: rgba(2, 132, 199, 0.28); border-color: #38bdf8; color: #e0f2fe; }

        /* Status & modal bot WhatsApp */
        .bot-status-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 0.88rem;
            background: rgba(2, 132, 199, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.35);
            color: #7dd3fc;
        }
        .bot-status-bar.invalid {
            background: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.45);
            color: #fca5a5;
        }
        .bot-status-bar .bot-status-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .flash-message { transition: opacity 0.35s ease, transform 0.35s ease; }
        .flash-message.hide { opacity: 0; transform: translateY(-4px); pointer-events: none; }
        .modal.bot-modal {
            max-width: 560px;
            max-height: calc(100vh - 32px);
            overflow-y: auto;
        }
        .bot-modal .modal-title,
        .bot-modal .modal-message { text-align: left; }
        .bot-modal .modal-message { margin-bottom: 14px; }
        .bot-modal .modal-icon { margin: 0 0 14px; }
        .bot-queue-list {
            list-style: none;
            margin: 0 0 16px;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 150px;
            overflow-y: auto;
        }
        .bot-queue-list li {
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 0.8rem;
            color: #cbd5e1;
            line-height: 1.45;
        }
        .bot-queue-list .queue-error { color: #f87171; display: block; margin-top: 2px; }
        .bot-modal .form-group { margin-bottom: 12px; text-align: left; }
        .bot-modal .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #cbd5e1; margin-bottom: 5px; }
        .bot-modal .form-control {
            width: 100%;
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 9px 11px;
            color: #f8fafc;
            font-family: inherit;
            font-size: 0.86rem;
            outline: none;
        }
        .bot-modal .form-control:focus { border-color: #38bdf8; }
        .bot-modal .modal-actions { justify-content: flex-end; flex-wrap: wrap; }
        .bot-modal .modal-btn { min-width: 0; padding: 9px 16px; }
        .bot-hint { font-size: 0.76rem; color: #94a3b8; margin-top: 4px; line-height: 1.45; }
        .bot-form-message { font-size: 0.82rem; margin-bottom: 10px; display: none; line-height: 1.45; }
        .bot-form-message.info { display: block; color: #7dd3fc; }
        .bot-form-message.error { display: block; color: #f87171; }
        .bot-form-message.success { display: block; color: #34d399; }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Kelola Data Master Mentoring</h1>
        <p class="page-subtitle">Manajemen Biodata Mentor, Tim Mentoring, dan Mentee</p>
        <div style="margin-top: 10px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
            <a href="<?= htmlspecialchars(mentoring_base_path(dirname(__DIR__))) ?>" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem;">&larr; Kembali ke Form Pelaporan</a>
            <a href="<?= htmlspecialchars($basePath) ?>backup" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem;">Backup Master</a>
            <a href="<?= htmlspecialchars($basePath) ?>restore" style="color: #38bdf8; text-decoration: none; font-size: 0.9rem;">Pulihkan / Rollback</a>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="flash-message" data-flash style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 12px; border-radius: 8px; margin-bottom: 16px;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="flash-message" data-flash style="background: rgba(239, 68, 68, 0.15); border: 1px solid #ef4444; color: #fca5a5; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem;">
            <?= htmlspecialchars($errorMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($botPendingCount > 0 || !empty($botState['api_invalid'])): ?>
        <div class="bot-status-bar <?= !empty($botState['api_invalid']) ? 'invalid' : '' ?>">
            <span>
                <?php if (!empty($botState['api_invalid'])): ?>
                    <b>API bot kedaluwarsa.</b>
                <?php else: ?>
                    <b>Pengiriman bot tertunda.</b>
                <?php endif; ?>
                <?= (int)$botPendingCount ?> pesan menunggu antrean.
            </span>
            <span class="bot-status-actions">
                <button type="button" class="row-action manage" onclick="openBotModal()">Kelola Bot</button>
            </span>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn <?= $activeTab === 'tab-mentors' ? 'active' : '' ?>" onclick="switchTab('tab-mentors', this)">Daftar Mentor (<?= count($master['mentors']) ?>)</button>
        <button class="tab-btn <?= $activeTab === 'tab-teams' ? 'active' : '' ?>" onclick="switchTab('tab-teams', this)">Daftar Tim (<?= count($master['teams']) ?>)</button>
        <button class="tab-btn <?= $activeTab === 'tab-mentees' ? 'active' : '' ?>" onclick="switchTab('tab-mentees', this)">Daftar Mentee (<?= count($master['mentees']) ?>)</button>
        <button class="tab-btn <?= $activeTab === 'tab-export' ? 'active' : '' ?>" onclick="switchTab('tab-export', this)">Ekspor Excel</button>
        <button class="tab-btn <?= $activeTab === 'tab-archive' ? 'active' : '' ?>" onclick="switchTab('tab-archive', this)">Arsip Purna (<?= count($master['archived_mentors'] ?? []) + count($master['archived_mentees'] ?? []) ?>)</button>
        <button class="tab-btn <?= $activeTab === 'tab-laporan' ? 'active' : '' ?>" onclick="switchTab('tab-laporan', this)">Kelola Laporan (<?= count($laporanAll) ?>)</button>
        <button class="tab-btn <?= $activeTab === 'tab-pendaftar' ? 'active' : '' ?>" onclick="switchTab('tab-pendaftar', this)">Pendaftar (<?= count($master['registrants'] ?? []) ?>)</button>
    </div>

    <!-- TAB 1: MENTOR -->
    <div id="tab-mentors" class="tab-content <?= $activeTab === 'tab-mentors' ? 'active' : '' ?>">
        <div class="tab-actions">
            <a href="<?= htmlspecialchars($basePath) ?>tambah-mentor" class="btn-action">+ Tambah Mentor</a>
        </div>
        <div class="card card-paginated" data-page-size="<?= $perPage ?>">
            <h3 class="card-title">Daftar Mentor Aktif</h3>
            <div class="table-search-bar">
                <input type="text" class="table-search-input" placeholder="🔍 Cari nama, ID, WA, kesibukan...">
            </div>
            <table class="attendance-table">
                <thead>
                    <tr><th>Foto</th><th>Nama</th><th>WhatsApp</th><th>Status</th><th>Kesibukan</th><th>Aksi</th></tr>
                </thead>
                <tbody class="paginated-tbody">
                    <?php foreach ($master['mentors'] as $m): ?>
                        <tr>
                            <?= $renderAvatar('mentor', $m['id'], $m['foto_profil'] ?? null, $m['nama_lengkap']) ?>
                            <td><b><?= htmlspecialchars($m['nama_lengkap']) ?></b><br><small><?= htmlspecialchars($m['id']) ?></small></td>
                            <td><?= htmlspecialchars($m['nomor_whatsapp']) ?></td>
                            <td><span class="badge-status aktif"><?= htmlspecialchars($m['status']) ?></span></td>
                            <td><?= htmlspecialchars($m['status_kesibukan']) ?></td>
                            <?= $renderActions('mentor', 'mentor', $m['id']) ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 2: TEAMS -->
    <div id="tab-teams" class="tab-content <?= $activeTab === 'tab-teams' ? 'active' : '' ?>">
        <div class="tab-actions">
            <a href="<?= htmlspecialchars($basePath) ?>tambah-tim" class="btn-action">+ Tambah Tim</a>
        </div>
        <div class="card card-paginated" data-page-size="<?= $perPage ?>">
            <h3 class="card-title">Daftar Tim Mentoring</h3>
            <div class="table-search-bar">
                <input type="text" class="table-search-input" placeholder="🔍 Cari nama tim, ID mentor...">
            </div>
            <table class="attendance-table">
                <thead>
                    <tr><th>Foto</th><th>Nama Tim</th><th>Mentor ID</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody class="paginated-tbody">
                    <?php foreach ($master['teams'] as $t): ?>
                        <tr>
                            <?= $renderAvatar('team', $t['id'], $t['foto_profil'] ?? null, $t['nama_tim']) ?>
                            <td><b><?= htmlspecialchars($t['nama_tim']) ?></b></td>
                            <td><?= htmlspecialchars($t['mentor_id']) ?></td>
                            <td><span class="badge-status aktif"><?= htmlspecialchars($t['status']) ?></span></td>
                            <?= $renderActions('team', 'tim', $t['id']) ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 3: MENTEES -->
    <div id="tab-mentees" class="tab-content <?= $activeTab === 'tab-mentees' ? 'active' : '' ?>">
        <div class="tab-actions">
            <a href="<?= htmlspecialchars($basePath) ?>tambah-mentee" class="btn-action">+ Tambah Mentee</a>
        </div>
        <div class="card card-paginated" data-page-size="<?= $perPage ?>">
            <h3 class="card-title">Daftar Mentee &amp; Tracking Keaktifan</h3>
            <div class="table-search-bar">
                <input type="text" class="table-search-input" placeholder="🔍 Cari nama mentee, NIM, kelas, tim...">
            </div>
            <table class="attendance-table">
                <thead>
                    <tr><th>Foto</th><th>Nama Mentee</th><th>NIM & Kelas</th><th>Tim ID</th><th>Status Keaktifan</th><th>Absen Beruntun</th><th>Aksi</th></tr>
                </thead>
                <tbody class="paginated-tbody">
                    <?php foreach ($master['mentees'] as $me): ?>
                        <?php 
                            $statusBadgeClass = ($me['status_keaktifan'] === 'Peringatan') ? 'peringatan' : (($me['status_keaktifan'] === 'Tidak Aktif') ? 'tidak_aktif' : 'aktif');
                        ?>
                        <tr>
                            <?= $renderAvatar('mentee', $me['id'], $me['foto_profil'] ?? null, $me['nama_lengkap']) ?>
                            <td><b><?= htmlspecialchars($me['nama_lengkap']) ?></b></td>
                            <?php $kelasTampil = Academic::formatClass($me['kelas'] ?? '', $me['nim'] ?? ''); ?>
                            <td><?= htmlspecialchars($me['nim'] ?? '-') ?><?= $kelasTampil !== '' ? ' • ' . htmlspecialchars($kelasTampil) : '' ?></td>
                            <td><?= htmlspecialchars($me['tim_id'] ?? '-') ?></td>
                            <td><span class="badge-status <?= $statusBadgeClass ?>"><?= htmlspecialchars($me['status_keaktifan'] ?? 'Aktif') ?></span></td>
                            <td><?= (int)($me['consecutive_absent'] ?? 0) ?>x</td>
                            <?= $renderActions('mentee', 'mentee', $me['id']) ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 5: ARSIP PURNA -->
    <div id="tab-archive" class="tab-content <?= $activeTab === 'tab-archive' ? 'active' : '' ?>">
        <div class="card">
            <h3 class="card-title">Arsip Purna — Mentor &amp; Mentee yang Telah Pergi</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0 0 16px;">
                Tempat khusus data mentor dan mentee yang sudah dikeluarkan dari UKMI. Data di sini
                tidak lagi muncul pada daftar aktif, tetapi riwayat biodatanya tetap tersimpan.
            </p>
            <?php
            $archivedRows = [];
            foreach ($master['archived_mentors'] ?? [] as $row) {
                $archivedRows[] = ['jenis' => 'Mentor', 'row' => $row];
            }
            foreach ($master['archived_mentees'] ?? [] as $row) {
                $archivedRows[] = ['jenis' => 'Mentee', 'row' => $row];
            }
            usort($archivedRows, static fn(array $a, array $b): int =>
                strcmp((string)($b['row']['archived_at'] ?? ''), (string)($a['row']['archived_at'] ?? ''))
            );
            ?>
            <?php if ($archivedRows === []): ?>
                <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                    Belum ada data di Arsip Purna.
                </div>
            <?php else: ?>
                <div class="table-search-bar">
                    <input type="text" class="table-search-input" id="archive-search" placeholder="🔍 Cari nama, jenis, atau alasan...">
                </div>
                <table class="attendance-table">
                    <thead>
                        <tr><th>Foto</th><th>Nama</th><th>Jenis</th><th>Alasan</th><th>Catatan</th><th>Diarsipkan</th></tr>
                    </thead>
                    <tbody id="archive-tbody">
                        <?php foreach ($archivedRows as $entry): ?>
                            <?php $row = $entry['row']; $nama = $row['nama_lengkap'] ?? $row['nama_tim'] ?? '-'; ?>
                            <tr>
                                <?= $renderAvatar($entry['jenis'], $row['id'] ?? '', $row['foto_profil'] ?? null, (string)$nama) ?>
                                <td><b><?= htmlspecialchars((string)$nama) ?></b><br><small><?= htmlspecialchars((string)($row['id'] ?? '')) ?></small></td>
                                <td><span class="badge-status <?= $entry['jenis'] === 'Mentor' ? 'aktif' : 'peringatan' ?>"><?= htmlspecialchars($entry['jenis']) ?></span></td>
                                <td><?= htmlspecialchars((string)($row['archive_reason'] ?? 'Dikeluarkan dari UKMI')) ?></td>
                                <td><?= htmlspecialchars((string)($row['archive_note'] ?? '')) !== '' ? htmlspecialchars((string)$row['archive_note']) : '-' ?></td>
                                <td>
                                    <?php
                                    $archivedAt = (string)($row['archived_at'] ?? '');
                                    $ts = $archivedAt !== '' ? strtotime($archivedAt) : false;
                                    echo htmlspecialchars($ts ? date('d M Y', $ts) : '-');
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 6: KELOLA LAPORAN -->
    <div id="tab-laporan" class="tab-content <?= $activeTab === 'tab-laporan' ? 'active' : '' ?>">
        <div class="card">
            <h3 class="card-title">Rekap Laporan Mentoring</h3>
            <form class="laporan-filter" method="get" action="" id="laporan-filter-form">
                <input type="hidden" name="tab" value="tab-laporan">
                <div class="filter-field">
                    <label for="laporan-bulan">Periode</label>
                    <select name="laporan_bulan" id="laporan-bulan" data-searchable>
                        <option value="">Semua Periode</option>
                        <?php foreach (array_keys($opsiBulan) as $ym): ?>
                            <option value="<?= htmlspecialchars($ym) ?>" <?= $laporanBulan === $ym ? 'selected' : '' ?>><?= htmlspecialchars($formatBulan($ym)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="laporan-tim">Tim</label>
                    <select name="laporan_tim" id="laporan-tim" data-searchable>
                        <option value="">Semua Tim</option>
                        <?php foreach ($opsiTim as $key => $nama): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $laporanTim === $key ? 'selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <a class="btn-cancel" href="<?= htmlspecialchars($basePath) ?>?tab=tab-laporan">Reset</a>
            </form>

            <div class="recap-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Laporan</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_laporan'] ?> <small>laporan</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Hadir</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_hadir'] ?> <small>kehadiran</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Izin</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_izin'] ?> <small>izin</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Absen</div>
                    <div class="stat-value"><?= (int)$laporanRecap['total_absen'] ?> <small>absen</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Tingkat Kehadiran</div>
                    <div class="stat-value"><?= htmlspecialchars((string)ReportRepository::attendanceRate((int)$laporanRecap['total_hadir'], (int)$laporanRecap['total_izin'], (int)$laporanRecap['total_absen'])) ?>%</div>
                </div>
            </div>

            <?php
            $rekapQuery = array_filter([
                'laporan_bulan' => $laporanBulan,
                'laporan_tim' => $laporanTim,
            ], static fn(string $value): bool => $value !== '');
            $rekapUrl = $basePath . 'rekap-laporan' . ($rekapQuery !== [] ? '?' . http_build_query($rekapQuery) : '');
            ?>
            <div class="recap-cta">
                <a href="<?= htmlspecialchars($rekapUrl, ENT_QUOTES) ?>" class="btn-action">Lihat Rekap</a>
            </div>
        </div>

        <div class="card card-paginated" data-page-size="<?= $perPage ?>">
            <h3 class="card-title">Daftar Laporan Masuk</h3>
            <div class="table-search-bar">
                <input type="text" class="table-search-input" placeholder="🔍 Cari tim, tema, tanggal, atau ID laporan...">
            </div>
            <table class="attendance-table">
                <thead>
                    <tr><th>Tim</th><th>Tanggal</th><th>Tema</th><th>Kehadiran</th><th>Aksi</th></tr>
                </thead>
                <tbody class="paginated-tbody">
                    <?php if ($laporanRecap['rows'] === []): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                Belum ada laporan masuk pada filter ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($laporanRecap['rows'] as $row): ?>
                            <?php
                                $rowTanggal = (string)($row['tanggal'] !== '' ? $row['tanggal'] : substr((string)$row['created_at'], 0, 10));
                                $rowTanggalText = $rowTanggal !== '' ? Watobot::formatTanggalIndonesia($rowTanggal) : '-';
                            ?>
                            <tr>
                                <td><b><?= htmlspecialchars((string)$row['nama_tim']) ?></b><br><small><?= htmlspecialchars((string)$row['id']) ?></small></td>
                                <td><?= htmlspecialchars($rowTanggalText) ?></td>
                                <td><?= htmlspecialchars((string)($row['tema'] !== '' ? $row['tema'] : '-')) ?></td>
                                <td>
                                    <span class="badge-status aktif">H <?= (int)$row['hadir'] ?></span>
                                    <span class="badge-status peringatan">I <?= (int)$row['izin'] ?></span>
                                    <span class="badge-status tidak_aktif">A <?= (int)$row['absen'] ?></span>
                                </td>
                                <td>
                                    <a class="row-action manage" href="<?= htmlspecialchars($basePath) ?>laporan?id=<?= urlencode((string)$row['id']) ?>">&#9881; Kelola</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 7: PENDAFTAR -->
    <div id="tab-pendaftar" class="tab-content <?= $activeTab === 'tab-pendaftar' ? 'active' : '' ?>">
        <?php
        $registrants = array_values(array_filter($master['registrants'] ?? [], 'is_array'));
        usort($registrants, static fn(array $a, array $b): int =>
            strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''))
        );
        $regStats = ['total' => count($registrants), 'Menunggu' => 0, 'Disetujui' => 0, 'Cadangan' => 0, 'Ditolak' => 0, 'ikhwan' => 0, 'akhwat' => 0];
        foreach ($registrants as $reg) {
            $st = (string)($reg['status_pendaftaran'] ?? 'Menunggu');
            if (isset($regStats[$st])) {
                $regStats[$st]++;
            }
            if (($reg['gender'] ?? '') === 'Laki-laki') {
                $regStats['ikhwan']++;
            } elseif (($reg['gender'] ?? '') === 'Perempuan') {
                $regStats['akhwat']++;
            }
        }
        $publicDaftarUrl = mentoring_request_origin() . $appBase . 'daftar';
        ?>
        <div class="tab-actions" style="justify-content: space-between; align-items: center;">
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="<?= htmlspecialchars($appBase) ?>daftar" target="_blank" rel="noopener noreferrer" class="btn-action secondary">Buka Form Pendaftaran &nearr;</a>
                <button type="button" class="btn-action secondary" onclick="copyDaftarLink(<?= htmlspecialchars(json_encode($publicDaftarUrl), ENT_QUOTES) ?>, this)">Salin Link Pendaftaran</button>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title">Ringkasan Pendaftar (Waiting List)</h3>
            <div class="recap-grid" style="margin-bottom: 0;">
                <div class="stat-box">
                    <div class="stat-label">Total Pendaftar</div>
                    <div class="stat-value"><?= (int)$regStats['total'] ?> <small>(<?= (int)$regStats['ikhwan'] ?> I / <?= (int)$regStats['akhwat'] ?> A)</small></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Menunggu</div>
                    <div class="stat-value" style="color: #fbbf24;"><?= (int)$regStats['Menunggu'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Cadangan</div>
                    <div class="stat-value" style="color: #7dd3fc;"><?= (int)$regStats['Cadangan'] ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Disetujui</div>
                    <div class="stat-value" style="color: #34d399;"><?= (int)$regStats['Disetujui'] ?></div>
                </div>
            </div>
        </div>

        <div class="card card-paginated" data-page-size="<?= $perPage ?>">
            <h3 class="card-title">Daftar Calon Mentee</h3>
            <div class="table-search-bar">
                <input type="text" class="table-search-input" placeholder="🔍 Cari nama, NIM, kelas, WA, mentor, atau status...">
            </div>
            <table class="attendance-table">
                <thead>
                    <tr><th>Foto</th><th>Nama &amp; Gender</th><th>NIM &amp; Kelas</th><th>WhatsApp</th><th>Preferensi Mentor</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody class="paginated-tbody">
                    <?php if ($registrants === []): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                Belum ada pendaftar yang masuk. Bagikan link form pendaftaran publik kepada calon peserta.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registrants as $reg): ?>
                            <?php
                                $regId = (string)($reg['id'] ?? '');
                                $regStatus = (string)($reg['status_pendaftaran'] ?? 'Menunggu');
                                $regBadgeClass = match ($regStatus) {
                                    'Disetujui' => 'aktif',
                                    'Ditolak' => 'tidak_aktif',
                                    default => 'peringatan',
                                };
                                $regKelas = Academic::formatClass($reg['kelas'] ?? '', $reg['nim'] ?? '');
                                $regCreated = !empty($reg['created_at']) ? date('d M Y', strtotime((string)$reg['created_at'])) : '-';
                                $prefMentor = trim((string)($reg['preferred_mentor_nama'] ?? ''));
                            ?>
                            <tr>
                                <?= $renderAvatar('pendaftar', $regId, $reg['foto_profil'] ?? null, (string)($reg['nama_lengkap'] ?? '')) ?>
                                <td>
                                    <b><?= htmlspecialchars((string)($reg['nama_lengkap'] ?? '-')) ?></b>
                                    <br><small><?= htmlspecialchars((string)($reg['gender'] ?? '-')) ?> &bull; <?= htmlspecialchars($regCreated) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string)($reg['nim'] ?? '-')) ?>
                                    <?= $regKelas !== '' ? '<br><small>' . htmlspecialchars($regKelas) . ' (' . htmlspecialchars((string)($reg['jadwal_kuliah'] ?? '-')) . ')</small>' : '' ?>
                                </td>
                                <td><?= htmlspecialchars((string)($reg['nomor_whatsapp'] ?? '-')) ?></td>
                                <td><?= $prefMentor !== '' ? htmlspecialchars($prefMentor) : '<span style="color:var(--text-muted);">Bebas / Divisi</span>' ?></td>
                                <td><span class="badge-status <?= $regBadgeClass ?>"><?= htmlspecialchars($regStatus) ?></span></td>
                                <td>
                                    <a class="row-action manage" href="<?= htmlspecialchars($basePath) ?>pendaftar?id=<?= urlencode($regId) ?>">&#9881; Kelola</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 4: EKSPOR EXCEL -->
    <div id="tab-export" class="tab-content <?= $activeTab === 'tab-export' ? 'active' : '' ?>">
        <div class="card">
            <h3 class="card-title">Ekspor Data Mentee ke Excel</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0 0 16px;">
                Unduh data mentee dalam format <b>.xlsx</b> siap cetak. Setiap tim memakai warna baris
                yang berbeda agar mudah dibaca.
            </p>
            <div class="export-actions">
                <a href="<?= htmlspecialchars($basePath) ?>export?scope=ikhwan" class="btn-action secondary">Ekspor Ikhwan</a>
                <a href="<?= htmlspecialchars($basePath) ?>export?scope=akhwat" class="btn-action secondary">Ekspor Akhwat</a>
                <a href="<?= htmlspecialchars($basePath) ?>export?scope=semua" class="btn-action secondary">Ekspor Gabungan</a>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: API BOT KEDALUWARSA / ANTREAN PENGIRIMAN -->
<div class="modal-overlay <?= $showBotModal ? 'active' : '' ?>" id="bot-modal" aria-hidden="<?= $showBotModal ? 'false' : 'true' ?>">
    <div class="modal bot-modal" role="dialog" aria-modal="true" aria-labelledby="bot-modal-title">
        <div class="modal-icon warning">&#9888;</div>
        <h3 class="modal-title" id="bot-modal-title">
            <?= !empty($botState['api_invalid']) ? 'API Bot Kedaluwarsa' : 'Antrean Pengiriman Bot' ?>
        </h3>
        <p class="modal-message">
            <?php if (!empty($botState['api_invalid'])): ?>
                API Watobot menolak pengiriman sehingga <b><?= (int)$botPendingCount ?> laporan</b>
                ditahan di antrean. Masukkan API key terbaru agar laporan dapat dikirim ulang.
                Proses submit tetap berjalan; tidak ada laporan yang hilang.
            <?php else: ?>
                Terdapat <b><?= (int)$botPendingCount ?> pesan</b> dalam antrean yang menunggu jadwal
                pengiriman ulang otomatis.
            <?php endif; ?>
        </p>

        <?php if (!empty($botState['last_error'])): ?>
            <p class="bot-hint" style="margin-bottom: 12px; color: #fca5a5;">
                Pesan terakhir dari API: <?= htmlspecialchars((string)$botState['last_error']) ?>
            </p>
        <?php endif; ?>

        <?php if ($botQueueItems !== []): ?>
            <ul class="bot-queue-list">
                <?php foreach (array_slice($botQueueItems, 0, 5) as $item): ?>
                    <li>
                        <b><?= htmlspecialchars((string)($item['meta']['nama_tim'] ?? $item['report_id'] ?? '-')) ?></b>
                        &bull; <?= htmlspecialchars((string)($item['report_id'] ?? '-')) ?>
                        &bull; percobaan ke-<?= (int)($item['attempts'] ?? 0) ?>
                        <?php if (!empty($item['last_error'])): ?>
                            <span class="queue-error"><?= htmlspecialchars((string)$item['last_error']) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($botPendingCount > 5): ?>
                <p class="bot-hint" style="margin-top: -8px;">&hellip; dan <?= (int)($botPendingCount - 5) ?> pesan lainnya.</p>
            <?php endif; ?>
        <?php endif; ?>

        <div class="bot-form-message" id="bot-form-message"></div>

        <form id="bot-api-form" autocomplete="off">
            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 5px;">
                    <label for="bot-api-key" style="margin-bottom: 0;">API Key Watobot Baru</label>
                    <a href="https://watobot.xyz/dashboard/" target="_blank" rel="noopener noreferrer" style="color: #38bdf8; font-size: 0.78rem; text-decoration: none;">Buka Dasbor Watobot &nearr;</a>
                </div>
                <input type="password" class="form-control" id="bot-api-key" name="api_key"
                       placeholder="Tempel API key terbaru di sini" required>
                <div class="bot-hint">API Watobot dapat kedaluwarsa setiap jam. Ambil kunci baru di dasbor Watobot, lalu tempel di sini. Kunci diuji ke antrean terlebih dahulu; bila ditolak, kunci lama tetap dipakai dan pesan tidak hilang.</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn secondary" onclick="closeBotModal()">Tutup</button>
                <?php if ($botPendingCount > 0): ?>
                    <button type="button" class="modal-btn secondary" id="bot-retry-btn" onclick="processBotQueue(this)">Kirim Sekarang</button>
                <?php endif; ?>
                <button type="submit" class="modal-btn primary" id="bot-save-btn">Simpan API</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/searchable-select.js"></script>
<script src="<?= htmlspecialchars($appBase) ?>public/assets/js/modal.js"></script>
<script>
const BOT_API_SAVE_URL = <?= json_encode($appBase . 'api?action=bot_save_api') ?>;
const BOT_API_PROCESS_URL = <?= json_encode($appBase . 'api?action=bot_process_queue') ?>;

function openBotModal() {
    const modal = document.getElementById('bot-modal');
    if (!modal) return;
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeBotModal() {
    const modal = document.getElementById('bot-modal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

function showBotFormMessage(text, kind) {
    const el = document.getElementById('bot-form-message');
    if (!el) return;
    el.textContent = text || '';
    el.className = 'bot-form-message' + (kind ? ' ' + kind : '');
}

function reloadMaster() {
    // Notifikasi sukses sudah ditampilkan lewat Modal; jangan bawa param
    // `msg` ke URL agar banner tidak muncul/menempel setelah reload.
    const url = new URL(window.location.href);
    url.searchParams.delete('msg');
    url.searchParams.delete('error');
    url.hash = '';
    window.location.replace(url.toString());
}

async function saveBotApi(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const keyInput = document.getElementById('bot-api-key');
    const saveBtn = document.getElementById('bot-save-btn');
    const apiKey = (keyInput.value || '').trim();

    if (!apiKey) {
        showBotFormMessage('API key baru wajib diisi.', 'error');
        keyInput.focus();
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = 'Menyimpan...';
    showBotFormMessage('Menyimpan kunci dan mencoba mengirim antrean...', 'info');
    if (typeof window.ukmiShowLoadingOverlay === 'function') {
        window.ukmiShowLoadingOverlay('Menyimpan API key & memproses antrean bot...');
    }

    try {
        const response = await fetch(BOT_API_SAVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ api_key: apiKey })
        });
        const data = await response.json();

        if (!response.ok || !data.success) {
            showBotFormMessage(data.message || 'Gagal menyimpan API key.', 'error');
            if (data.data && data.data.key_rejected) {
                keyInput.select();
            }
            return;
        }

        const state = (data.data && data.data.state) || {};
        const pending = (data.data && data.data.pending_count) || 0;
        if (state.api_invalid) {
            showBotFormMessage('API key tersimpan, tetapi API masih menolak. ' + pending + ' pesan tetap dalam antrean.', 'error');
            return;
        }

        if (typeof window.Modal !== 'undefined') {
            await window.Modal.success(
                data.message || ('API key disimpan' + (pending > 0 ? ', ' + pending + ' pesan masih dijadwalkan ulang.' : '.')),
                'API Bot Diperbarui'
            );
        }
        reloadMaster();
    } catch (error) {
        showBotFormMessage('Tidak dapat menghubungi server. Periksa koneksi lalu coba lagi.', 'error');
    } finally {
        if (typeof window.ukmiHideLoadingOverlay === 'function') {
            window.ukmiHideLoadingOverlay();
        }
        saveBtn.disabled = false;
        saveBtn.textContent = 'Simpan API';
    }
}

async function processBotQueue(btn) {
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Mengirim...';
    }
    showBotFormMessage('Mencoba mengirim seluruh antrean...', 'info');
    if (typeof window.ukmiShowLoadingOverlay === 'function') {
        window.ukmiShowLoadingOverlay('Mengirim antrean pesan WhatsApp...');
    }

    try {
        const response = await fetch(BOT_API_PROCESS_URL, { method: 'POST' });
        const data = await response.json();
        if (!response.ok || !data.success) {
            showBotFormMessage(data.message || 'Gagal memproses antrean.', 'error');
            return;
        }

        const pending = (data.data && data.data.pending_count) || 0;
        const sent = (data.data && data.data.sent) || 0;
        const state = (data.data && data.data.state) || {};

        if (pending === 0) {
            if (typeof window.Modal !== 'undefined') {
                await window.Modal.success(sent + ' pesan berhasil dikirim.', 'Antrean Selesai');
            }
            reloadMaster();
            return;
        }

        if (state.api_invalid) {
            showBotFormMessage('API masih kedaluwarsa. Masukkan API key baru di atas. ' + pending + ' pesan tetap menunggu.', 'error');
        } else {
            showBotFormMessage(sent + ' terkirim, ' + pending + ' masih menunggu jadwal berikutnya.', 'error');
        }
    } catch (error) {
        showBotFormMessage('Tidak dapat menghubungi server. Periksa koneksi lalu coba lagi.', 'error');
    } finally {
        if (typeof window.ukmiHideLoadingOverlay === 'function') {
            window.ukmiHideLoadingOverlay();
        }
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Kirim Sekarang';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const botForm = document.getElementById('bot-api-form');
    if (botForm) botForm.addEventListener('submit', saveBotApi);

    const botModal = document.getElementById('bot-modal');
    if (botModal) {
        botModal.addEventListener('click', (event) => {
            if (event.target === botModal) closeBotModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && botModal.classList.contains('active')) closeBotModal();
        });
    }

    // Hapus param msg/error dari URL agar banner tidak muncul lagi saat refresh,
    // lalu sembunyikan banner otomatis setelah beberapa detik.
    const cleanUrl = new URL(window.location.href);
    let urlChanged = false;
    ['msg', 'error'].forEach((key) => {
        if (cleanUrl.searchParams.has(key)) {
            cleanUrl.searchParams.delete(key);
            urlChanged = true;
        }
    });
    if (urlChanged && history.replaceState) {
        history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
    }

    document.querySelectorAll('[data-flash]').forEach((el) => {
        setTimeout(() => {
            el.classList.add('hide');
            setTimeout(() => el.remove(), 400);
        }, 5000);
    });
});

function copyDaftarLink(url, btn) {
    const done = () => {
        const old = btn.textContent;
        btn.textContent = 'Link Disalin ✓';
        setTimeout(() => { btn.textContent = old; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url).then(done).catch(() => {});
    } else {
        const tmp = document.createElement('input');
        tmp.value = url;
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(tmp);
        done();
    }
}

function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
    if (history.replaceState) history.replaceState(null, '', '#' + tabId);
}

// Inisialisasi Pencarian & Pagination untuk semua card yang memiliki judul "Daftar"
document.addEventListener('DOMContentLoaded', () => {
    // Searchable select untuk filter rekap laporan
    document.querySelectorAll('select[data-searchable]').forEach(select => initSearchableSelect(select));

    // Filter Kelola Laporan langsung diterapkan saat pilihan berubah (tanpa tombol Terapkan).
    const laporanFilterForm = document.getElementById('laporan-filter-form');
    if (laporanFilterForm) {
        laporanFilterForm.addEventListener('change', () => laporanFilterForm.submit());
    }

    // Buka tab sesuai hash (mis. kembali dari subhalaman pengaturan)
    const hashTab = location.hash.replace('#', '');
    if (hashTab) {
        const target = document.getElementById(hashTab);
        if (target && target.classList.contains('tab-content')) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            target.classList.add('active');
            const btn = document.querySelector('.tab-btn[onclick*="' + hashTab + '"]');
            if (btn) btn.classList.add('active');
        }
    }

    document.querySelectorAll('.card-paginated').forEach(card => {
        const tbody = card.querySelector('.paginated-tbody');
        const searchInput = card.querySelector('.table-search-input');
        if (!tbody) return;

        const allRows = Array.from(tbody.querySelectorAll('tr'));
        let filteredRows = [...allRows];
        const pageSize = parseInt(card.dataset.pageSize, 10) || 5;
        let totalPages = Math.ceil(filteredRows.length / pageSize) || 1;
        let currentPage = 1;

        // Container pagination
        const paginationContainer = document.createElement('div');
        paginationContainer.className = 'pagination-container';

        const infoSpan = document.createElement('div');
        infoSpan.className = 'pagination-info';

        const controlsDiv = document.createElement('div');
        controlsDiv.className = 'pagination-controls';

        paginationContainer.appendChild(infoSpan);
        paginationContainer.appendChild(controlsDiv);
        card.appendChild(paginationContainer);

        function updatePagination() {
            totalPages = Math.ceil(filteredRows.length / pageSize) || 1;
            if (currentPage > totalPages) currentPage = 1;
            renderPage(currentPage);
        }

        function renderPage(page) {
            currentPage = page;
            const start = (page - 1) * pageSize;
            const end = start + pageSize;

            allRows.forEach(row => row.style.display = 'none');
            filteredRows.forEach((row, idx) => {
                row.style.display = (idx >= start && idx < end) ? '' : 'none';
            });

            const total = filteredRows.length;
            const showingStart = total === 0 ? 0 : start + 1;
            const showingEnd = Math.min(end, total);
            infoSpan.textContent = `Menampilkan ${showingStart}-${showingEnd} dari ${total} data (Halaman ${currentPage}/${totalPages})`;

            renderControls();
        }

        function renderControls() {
            controlsDiv.innerHTML = '';

            // Tombol Prev
            const prevBtn = document.createElement('button');
            prevBtn.className = 'page-nav-btn';
            prevBtn.textContent = '« Prev';
            prevBtn.disabled = (currentPage === 1);
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) renderPage(currentPage - 1);
            });
            controlsDiv.appendChild(prevBtn);

            // Tombol nomor halaman
            for (let i = 1; i <= totalPages; i++) {
                const numBtn = document.createElement('button');
                numBtn.className = 'page-nav-btn' + (i === currentPage ? ' active' : '');
                numBtn.textContent = i;
                numBtn.addEventListener('click', () => renderPage(i));
                controlsDiv.appendChild(numBtn);
            }

            // Tombol Next
            const nextBtn = document.createElement('button');
            nextBtn.className = 'page-nav-btn';
            nextBtn.textContent = 'Next »';
            nextBtn.disabled = (currentPage === totalPages || totalPages === 0);
            nextBtn.addEventListener('click', () => {
                if (currentPage < totalPages) renderPage(currentPage + 1);
            });
            controlsDiv.appendChild(nextBtn);
        }

        // Event listener kolom pencarian
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const keyword = e.target.value.toLowerCase().trim();
                filteredRows = allRows.filter(row => {
                    const text = row.innerText.toLowerCase();
                    return text.includes(keyword);
                });
                updatePagination();
            });
        }

        updatePagination();
    });

    // Pencarian sederhana pada Arsip Purna
    const archiveInput = document.getElementById('archive-search');
    const archiveBody = document.getElementById('archive-tbody');
    if (archiveInput && archiveBody) {
        const archiveRows = Array.from(archiveBody.querySelectorAll('tr'));
        archiveInput.addEventListener('input', () => {
            const keyword = archiveInput.value.toLowerCase().trim();
            archiveRows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(keyword) ? '' : 'none';
            });
        });
    }
});
</script>

</body>
</html>
