<?php
/**
 * master/rekap-laporan.php — Subhalaman Rekap Laporan per Tim
 * Filter periode & tim mendukung banyak pilihan sekaligus, ditambah filter
 * gender (Ikhwan/Akhwat/Gabungan). Filter otomatis diterapkan saat diubah.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;
use Mentoring\ReportRepository;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$listUrl = $basePath . '?tab=tab-laporan';
$pageUrl = $basePath . 'rekap-laporan';

$toArray = static function ($value): array {
    if (!is_array($value)) {
        $value = $value === null ? [] : [$value];
    }
    $result = [];
    foreach ($value as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $result[] = $item;
        }
    }
    return array_values(array_unique($result));
};

$summaries = ReportRepository::summaries();
$teamGenders = ReportRepository::teamGenderMap();
$roleGenderLabel = AdminAuth::allowedGenderLabel();
if ($roleGenderLabel !== null) {
    $summaries = array_values(array_filter($summaries, static function (array $s) use ($roleGenderLabel, $teamGenders): bool {
        $gender = $teamGenders['tim:' . $s['tim_id']] ?? $teamGenders['nama:' . $s['nama_tim']] ?? '';
        return $gender === $roleGenderLabel;
    }));
}
$laporanBulan = array_values(array_filter(
    $toArray($_GET['laporan_bulan'] ?? []),
    static fn(string $ym): bool => (bool)preg_match('/^\d{4}-\d{2}$/', $ym)
));
$laporanTim = $toArray($_GET['laporan_tim'] ?? []);
$laporanGender = (string)($_GET['laporan_gender'] ?? '');
if (!in_array($laporanGender, ['Ikhwan', 'Akhwat'], true)) {
    $laporanGender = '';
}
if ($roleGenderLabel !== null) {
    $laporanGender = $roleGenderLabel;
}

$filtered = array_values(array_filter($summaries, static function (array $s) use ($laporanBulan, $laporanTim, $laporanGender, $teamGenders): bool {
    $tanggal = $s['tanggal'] !== '' ? $s['tanggal'] : substr($s['created_at'], 0, 10);

    if ($laporanBulan !== [] && !in_array(substr($tanggal, 0, 7), $laporanBulan, true)) {
        return false;
    }
    if ($laporanTim !== [] && !in_array('nama:' . $s['nama_tim'], $laporanTim, true)) {
        return false;
    }
    if ($laporanGender !== '') {
        $gender = $teamGenders['tim:' . $s['tim_id']] ?? $teamGenders['nama:' . $s['nama_tim']] ?? '';
        if ($gender !== $laporanGender) {
            return false;
        }
    }

    return true;
}));

$recap = ReportRepository::recap($filtered);

$opsiBulan = [];
$opsiTim = [];
foreach ($summaries as $s) {
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

$periodeLabel = 'Semua Periode';
if (count($laporanBulan) === 1) {
    $periodeLabel = $formatBulan($laporanBulan[0]);
} elseif (count($laporanBulan) > 1) {
    $periodeLabel = count($laporanBulan) . ' periode dipilih';
}

$timLabel = 'Semua Tim';
if (count($laporanTim) === 1) {
    $timLabel = (string)($opsiTim[$laporanTim[0]] ?? $laporanTim[0]);
} elseif (count($laporanTim) > 1) {
    $timLabel = count($laporanTim) . ' tim dipilih';
}

$genderLabel = $laporanGender !== '' ? $laporanGender : 'Gabungan (Ikhwan & Akhwat)';

$exportQuery = [];
if ($laporanBulan !== []) {
    $exportQuery['laporan_bulan'] = $laporanBulan;
}
if ($laporanTim !== []) {
    $exportQuery['laporan_tim'] = $laporanTim;
}
if ($laporanGender !== '') {
    $exportQuery['laporan_gender'] = $laporanGender;
}
$exportPdfUrl = $basePath . 'ekspor-pdf' . ($exportQuery !== [] ? '?' . http_build_query($exportQuery) : '');

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Laporan per Tim — Mentoring MAI</title>
    <link rel="icon" type="image/jpeg" href="../public/assets/img/favicon.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
    <style>
        .laporan-filter {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 18px;
        }
        .filter-field { display: flex; flex-direction: column; gap: 6px; flex: 1 1 210px; min-width: 0; }
        .filter-field > label { font-size: 0.78rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; }
        .filter-field select { min-height: 42px; }
        .laporan-filter .btn-cancel { min-height: 42px; display: inline-flex; align-items: center; }

        /* Multi-select ringkas untuk periode & tim */
        .multi-select { position: relative; }
        .multi-select summary {
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-height: 42px;
            padding: 10px 12px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 0.925rem;
            cursor: pointer;
            user-select: none;
        }
        .multi-select summary::-webkit-details-marker { display: none; }
        .multi-select summary:hover,
        .multi-select[open] summary { border-color: var(--primary); }
        .multi-value { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding-right: 6px; }
        .multi-arrow { color: var(--text-muted); font-size: 0.8rem; flex-shrink: 0; }
        .multi-panel {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 120;
            padding: 8px;
            background: #0b1120;
            border: 1px solid #334155;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
        }
        .multi-search {
            width: 100%;
            padding: 9px 10px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 6px;
            color: var(--text-main);
            font-size: 0.875rem;
            font-family: inherit;
            outline: none;
        }
        .multi-search:focus { border-color: var(--primary); }
        .multi-options { max-height: 210px; overflow-y: auto; margin-top: 6px; }
        .multi-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 6px;
            font-size: 0.875rem;
            color: #cbd5e1;
            cursor: pointer;
        }
        .multi-option:hover { background: rgba(2, 132, 199, 0.2); color: #fff; }
        .multi-option input { accent-color: var(--primary); }
        .multi-actions {
            display: flex;
            gap: 10px;
            margin-top: 6px;
            padding-top: 8px;
            border-top: 1px solid #334155;
        }
        .multi-actions button {
            padding: 3px 0;
            background: none;
            border: none;
            color: #38bdf8;
            font-family: inherit;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
        }
        .multi-actions button:hover { color: #e0f2fe; }

        .export-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .export-row .btn-action { width: auto; min-height: 42px; padding: 9px 18px; }
        .export-note { font-size: 0.78rem; color: var(--text-muted); flex: 1 1 280px; line-height: 1.5; }
        .recap-summary { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .recap-chip { background: #0b1120; border: 1px solid #334155; border-radius: 999px; padding: 5px 12px; font-size: 0.8rem; color: #cbd5e1; }
        .recap-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .rate-cell { min-width: 110px; }
        .rate-bar { height: 6px; background: #1e293b; border-radius: 999px; overflow: hidden; margin-top: 5px; }
        .rate-bar span { display: block; height: 100%; background: #10b981; border-radius: 999px; }
        .empty-state { padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.9rem; }
    </style>
</head>
<body>
<?php mentoring_loading_overlay(); ?>

<div class="container" style="max-width: 960px;">
    <header class="page-header">
        <h1 class="page-title">Rekap Laporan per Tim</h1>
        <p class="page-subtitle">Agregat laporan dan kehadiran setiap tim mentoring</p>
        <div class="subpage-back">
            <a href="<?= $h($listUrl) ?>">&larr; Kembali ke Kelola Laporan</a>
        </div>
    </header>

    <div class="card">
        <h3 class="card-title">Filter Rekap</h3>
        <form class="laporan-filter" method="get" action="" id="rekap-filter-form">
            <div class="filter-field">
                <label>Periode</label>
                <details class="multi-select" data-multi-select data-placeholder="Semua Periode">
                    <summary>
                        <span class="multi-value"><?= $h($periodeLabel) ?></span>
                        <span class="multi-arrow">&#9662;</span>
                    </summary>
                    <div class="multi-panel">
                        <input type="text" class="multi-search" placeholder="Cari periode...">
                        <div class="multi-options">
                            <?php foreach (array_keys($opsiBulan) as $ym): ?>
                                <label class="multi-option">
                                    <input type="checkbox" name="laporan_bulan[]" value="<?= $h($ym) ?>" <?= in_array($ym, $laporanBulan, true) ? 'checked' : '' ?>>
                                    <span><?= $h($formatBulan($ym)) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="multi-actions">
                            <button type="button" data-multi-all>Pilih semua</button>
                            <button type="button" data-multi-clear>Kosongkan</button>
                        </div>
                    </div>
                </details>
            </div>

            <div class="filter-field">
                <label>Tim</label>
                <details class="multi-select" data-multi-select data-placeholder="Semua Tim">
                    <summary>
                        <span class="multi-value"><?= $h($timLabel) ?></span>
                        <span class="multi-arrow">&#9662;</span>
                    </summary>
                    <div class="multi-panel">
                        <input type="text" class="multi-search" placeholder="Cari tim...">
                        <div class="multi-options">
                            <?php foreach ($opsiTim as $key => $nama): ?>
                                <label class="multi-option">
                                    <input type="checkbox" name="laporan_tim[]" value="<?= $h($key) ?>" <?= in_array($key, $laporanTim, true) ? 'checked' : '' ?>>
                                    <span><?= $h($nama) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="multi-actions">
                            <button type="button" data-multi-all>Pilih semua</button>
                            <button type="button" data-multi-clear>Kosongkan</button>
                        </div>
                    </div>
                </details>
            </div>

            <div class="filter-field">
                <label for="laporan-gender">Gender</label>
                <select name="laporan_gender" id="laporan-gender" data-searchable>
                    <?php if ($roleGenderLabel === null): ?>
                        <option value="" <?= $laporanGender === '' ? 'selected' : '' ?>>Gabungan (Semua)</option>
                        <option value="Ikhwan" <?= $laporanGender === 'Ikhwan' ? 'selected' : '' ?>>Ikhwan</option>
                        <option value="Akhwat" <?= $laporanGender === 'Akhwat' ? 'selected' : '' ?>>Akhwat</option>
                    <?php else: ?>
                        <option value="<?= $h($roleGenderLabel) ?>" selected><?= $h($roleGenderLabel) ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <a class="btn-cancel" href="<?= $h($pageUrl) ?>">Reset</a>
        </form>

        <div class="export-row">
            <a class="btn-action" href="<?= $h($exportPdfUrl) ?>">Ekspor PDF</a>
            <span class="export-note">
                PDF memuat seluruh rincian laporan individual pada filter ini. Foto dokumentasi
                dikompres ulang otomatis agar ukuran file tetap wajar.
            </span>
        </div>

        <div class="recap-summary">
            <span class="recap-chip">Periode: <?= $h($periodeLabel) ?></span>
            <span class="recap-chip">Tim: <?= $h($timLabel) ?></span>
            <span class="recap-chip">Gender: <?= $h($genderLabel) ?></span>
            <span class="recap-chip">Total: <?= (int)$recap['total_laporan'] ?> laporan</span>
        </div>

        <?php if ($recap['teams'] === []): ?>
            <div class="empty-state">Belum ada laporan pada filter ini.</div>
        <?php else: ?>
            <div class="recap-table-wrap">
                <table class="attendance-table">
                    <thead>
                        <tr><th>Tim</th><th>Laporan</th><th>Hadir</th><th>Izin</th><th>Absen</th><th>Kehadiran</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recap['teams'] as $teamRecap): ?>
                            <?php $rate = ReportRepository::attendanceRate((int)$teamRecap['hadir'], (int)$teamRecap['izin'], (int)$teamRecap['absen']); ?>
                            <tr>
                                <td><b><?= $h($teamRecap['nama_tim']) ?></b></td>
                                <td><?= (int)$teamRecap['laporan'] ?>x</td>
                                <td><?= (int)$teamRecap['hadir'] ?></td>
                                <td><?= (int)$teamRecap['izin'] ?></td>
                                <td><?= (int)$teamRecap['absen'] ?></td>
                                <td class="rate-cell">
                                    <?= $h((string)$rate) ?>%
                                    <div class="rate-bar"><span style="width: <?= $h((string)$rate) ?>%;"></span></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= $h($appBase) ?>public/assets/js/searchable-select.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('rekap-filter-form');
    if (!form) return;

    let submitTimer = null;
    const scheduleSubmit = () => {
        clearTimeout(submitTimer);
        submitTimer = setTimeout(() => {
            try {
                sessionStorage.setItem('rekapOpenPanel', openPanelIndex());
            } catch (error) {
                // Abaikan bila penyimpanan sesi diblokir browser.
            }
            form.submit();
        }, 350);
    };

    const multiSelects = Array.from(form.querySelectorAll('[data-multi-select]'));
    const openPanelIndex = () => multiSelects.findIndex(details => details.open);

    const updateSummary = (details) => {
        const checked = Array.from(details.querySelectorAll('input[type="checkbox"]:checked'));
        const valueEl = details.querySelector('.multi-value');
        if (!valueEl) return;
        if (checked.length === 0) {
            valueEl.textContent = details.dataset.placeholder || 'Semua';
        } else if (checked.length === 1) {
            valueEl.textContent = checked[0].parentElement.querySelector('span').textContent;
        } else {
            valueEl.textContent = checked.length + ' dipilih';
        }
    };

    multiSelects.forEach((details) => {
        updateSummary(details);

        const search = details.querySelector('.multi-search');
        if (search) {
            search.addEventListener('input', () => {
                const keyword = search.value.toLowerCase().trim();
                details.querySelectorAll('.multi-option').forEach(option => {
                    option.style.display = option.textContent.toLowerCase().includes(keyword) ? '' : 'none';
                });
            });
        }

        details.querySelectorAll('[data-multi-all]').forEach(button => {
            button.addEventListener('click', () => {
                details.querySelectorAll('.multi-option').forEach(option => {
                    if (option.style.display !== 'none') {
                        option.querySelector('input[type="checkbox"]').checked = true;
                    }
                });
                updateSummary(details);
                scheduleSubmit();
            });
        });

        details.querySelectorAll('[data-multi-clear]').forEach(button => {
            button.addEventListener('click', () => {
                details.querySelectorAll('input[type="checkbox"]').forEach(box => { box.checked = false; });
                updateSummary(details);
                scheduleSubmit();
            });
        });
    });

    // Filter otomatis saat pilihan berubah (checkbox/select), tanpa tombol Terapkan.
    form.addEventListener('change', (event) => {
        if (event.target.matches('input[type="checkbox"], select')) {
            const details = event.target.closest('[data-multi-select]');
            if (details) updateSummary(details);
            scheduleSubmit();
        }
    });

    // Buka kembali panel multi-select setelah halaman dimuat ulang karena auto-submit.
    let reopenIndex = null;
    try {
        reopenIndex = sessionStorage.getItem('rekapOpenPanel');
        sessionStorage.removeItem('rekapOpenPanel');
    } catch (error) {
        // Abaikan bila penyimpanan sesi diblokir browser.
    }
    if (reopenIndex !== null && reopenIndex !== '-1' && multiSelects[Number(reopenIndex)]) {
        multiSelects[Number(reopenIndex)].open = true;
    }

    document.querySelectorAll('select[data-searchable]').forEach(select => {
        if (typeof initSearchableSelect === 'function') initSearchableSelect(select);
    });

    document.addEventListener('click', (event) => {
        multiSelects.forEach(details => {
            if (details.open && !details.contains(event.target)) {
                details.open = false;
            }
        });
    });
});
</script>

</body>
</html>
