<?php
$agendaDefaults = [
    'tag' => 'Agenda terdekat',
    'title' => 'Pelantikan Ketum 2026-2027',
    'detail' => 'Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI Polmed 2026-2027.',
];

$registrationDefaults = [
    'platform' => 'Google Form',
    'url' => 'https://forms.gle/e4e6egXHiRnyQVPV7',
];
$divisionDefaults = [
    ['name' => 'Kaderisasi', 'description' => 'Merancang alur pembinaan anggota baru dan pelatihan berjenjang.'],
    ['name' => 'Mentoring Agama Islam', 'description' => 'Fokus mentoring iman-ilmu, kajian tematik, dan pendampingan rohani.'],
    ['name' => 'Pembinaan Tilawatil Quran', 'description' => 'Kelas tahsin-tahfizh, halaqah rutin, dan penguatan literasi Quran.'],
    ['name' => 'Syiar Media', 'description' => 'Mengelola konten digital, desain, foto-video, dan siaran kampanye kebaikan.'],
    ['name' => 'Keputrian', 'description' => 'Program khusus muslimah: kelas, support system, dan kepemimpinan perempuan.'],
    ['name' => 'Ekonomi', 'description' => 'Inisiatif kewirausahaan, fundrising program, dan pengelolaan dana kegiatan.'],
];
$docDefaults = [
    ['tag' => 'Highlight', 'title' => 'Arsip IG UKMI', 'description' => 'Galeri kegiatan 2016-2025 dalam format slide dan grid. Cocok untuk stalking suasana UKMI.', 'url' => 'https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/'],
    ['tag' => 'PPI 2024', 'title' => 'Dokumentasi PPI 2024', 'description' => 'Index foto/video PPI 2024 lengkap dengan navigasi slide & grid view.', 'url' => 'https://krasyid822.github.io/ukmipolmed/dokumentasi-ppi-2024/'],
];

$agendas = [];
$configPath = __DIR__ . '/data.json';
$defaultPath = __DIR__ . '/default.json';

if (is_readable($defaultPath)) {
    $defaultData = json_decode((string) file_get_contents($defaultPath), true);
    if (is_array($defaultData)) {
        $agendaDefaults = array_merge($agendaDefaults, array_intersect_key($defaultData['agenda'] ?? [], $agendaDefaults));
        $registrationDefaults = array_merge($registrationDefaults, array_intersect_key($defaultData['registration'] ?? [], $registrationDefaults));
        if (!empty($defaultData['divisions']) && is_array($defaultData['divisions'])) {
            $divisionDefaults = $defaultData['divisions'];
        }
        if (!empty($defaultData['docs']) && is_array($defaultData['docs'])) {
            $docDefaults = $defaultData['docs'];
        }
    }
}

if (is_readable($configPath)) {
    $config = json_decode((string) file_get_contents($configPath), true);
    if (is_array($config)) {
        if (!empty($config['agendas']) && is_array($config['agendas'])) {
            foreach ($config['agendas'] as $item) {
                if (!is_array($item)) continue;
                $agendas[] = array_merge($agendaDefaults, array_intersect_key($item, $agendaDefaults));
            }
        } elseif (!empty($config['agenda']) && is_array($config['agenda'])) {
            $agendas[] = array_merge($agendaDefaults, array_intersect_key($config['agenda'], $agendaDefaults));
        }
    }
}

if (empty($agendas)) {
    $agendas[] = $agendaDefaults;
}

$registration = $registrationDefaults;
if (isset($config) && is_array($config) && !empty($config['registration']) && is_array($config['registration'])) {
    $registration = array_merge($registrationDefaults, array_intersect_key($config['registration'], $registrationDefaults));
    if (empty(trim((string) $registration['url'] ?? ''))) {
        $registration['url'] = $registrationDefaults['url'];
    }
}

$divisions = $divisionDefaults;
if (isset($config) && is_array($config) && !empty($config['divisions']) && is_array($config['divisions'])) {
    $tmp = [];
    foreach ($config['divisions'] as $item) {
        if (!is_array($item)) continue;
        $name = trim((string) ($item['name'] ?? ''));
        $desc = trim((string) ($item['description'] ?? ''));
        if ($name === '' && $desc === '') continue;
        $tmp[] = [
            'name' => $name ?: 'Divisi',
            'description' => $desc ?: 'Deskripsi divisi.',
        ];
    }
    if (!empty($tmp)) {
        $divisions = $tmp;
    }
}
$divisionCount = count($divisions);
$divisionNames = array_map(fn($d) => $d['name'], $divisions);
$divisionYearStart = (int) date('Y');
$divisionYearLabel = $divisionYearStart . '-' . ($divisionYearStart + 1);
$docs = $docDefaults;
if (isset($config) && is_array($config) && !empty($config['docs']) && is_array($config['docs'])) {
    $tmpDocs = [];
    foreach ($config['docs'] as $item) {
        if (!is_array($item)) continue;
        $tag = trim((string) ($item['tag'] ?? 'Dokumentasi')) ?: 'Dokumentasi';
        $title = trim((string) ($item['title'] ?? '')) ?: 'Judul dokumentasi';
        $desc = trim((string) ($item['description'] ?? '')) ?: 'Deskripsi dokumentasi.';
        $url = trim((string) ($item['url'] ?? '')) ?: '#';
        $tmpDocs[] = [
            'tag' => $tag,
            'title' => $title,
            'description' => $desc,
            'url' => $url,
        ];
        if (count($tmpDocs) >= 10) break;
    }
    if (!empty($tmpDocs)) {
        $docs = $tmpDocs;
    }
}

$firstAgenda = $agendas[0];

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UKMI Polmed | Open Recruitment <?php echo e($divisionYearLabel); ?>, Agenda, dan Dokumentasi</title>
    <meta name="description" content="Open recruitment UKMI Polmed <?php echo e($divisionYearLabel); ?>: mentoring, tilawah, syiar media, keputrian, ekonomi, dan aksi sosial. Form daftar, agenda terbaru, serta dokumentasi kegiatan tersedia di satu halaman.">
    <meta name="keywords" content="UKMI Polmed, open recruitment UKMI Polmed, organisasi Islam Polmed, mentoring kampus, UKM Islami Politeknik Negeri Medan">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="http://ukmipolmed.xo.je/">
    <meta property="og:locale" content="id_ID">
    <meta property="og:type" content="website">
    <meta property="og:title" content="UKMI Polmed | Open Recruitment <?php echo e($divisionYearLabel); ?>">
    <meta property="og:description" content="Semua shortcut untuk gabung UKMI Polmed: form pendaftaran, agenda terbaru, dokumentasi kegiatan, dan daftar divisi kepengurusan.">
    <meta property="og:url" content="http://ukmipolmed.xo.je/">
    <meta property="og:image" content="http://ukmipolmed.xo.je/logo-ukmi.png">
    <meta property="og:site_name" content="UKMI Polmed">
    <link rel="icon" type="image/png" href="logo-ukmi.png">
    <link rel="apple-touch-icon" href="logo-ukmi.png">
    <link rel="shortcut icon" href="logo-ukmi.png">
    <meta name="theme-color" content="#0f172a">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="UKMI Polmed | Open Recruitment <?php echo e($divisionYearLabel); ?>">
    <meta name="twitter:description" content="Form pendaftaran, agenda terbaru, dokumentasi kegiatan, dan divisi UKMI Polmed <?php echo e($divisionYearLabel); ?>.">
    <meta name="twitter:image" content="http://ukmipolmed.xo.je/logo-ukmi.png">
    <?php
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'Bagaimana cara daftar UKMI Polmed?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Klik tombol Daftar sekarang atau isi form pendaftaran di bagian Jalur cepat daftar.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Apa yang akan saya dapatkan di UKMI Polmed?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Mentoring iman-ilmu, jaringan lintas angkatan, serta pengalaman event, media, dan kewirausahaan.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Apakah terbuka untuk semua jurusan?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Ya, UKMI Polmed terbuka untuk semua jurusan dan angkatan baru.',
                ],
            ],
        ],
    ];
    $faqJson = json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ?>
    <script type="application/ld+json"><?php echo $faqJson; ?></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #070b1a;
            --card: rgba(255, 255, 255, 0.06);
            --stroke: rgba(255, 255, 255, 0.1);
            --text: #e9eef7;
            --muted: #c7d1e1;
            --accent: #6cf7c5;
            --accent-2: #7cc9ff;
            --shadow: 0 18px 45px rgba(0, 0, 0, 0.45);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', 'Space Grotesk', system-ui, sans-serif;
            background: radial-gradient(circle at 20% 20%, rgba(108, 247, 197, 0.2), transparent 35%),
                        radial-gradient(circle at 80% 0%, rgba(124, 201, 255, 0.25), transparent 40%),
                        linear-gradient(145deg, #050814, #0e1427 55%, #0a0f20 100%);
            color: var(--text);
            overflow-x: hidden;
        }

        .glow {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(108, 247, 197, 0.13), transparent 35%),
                        radial-gradient(circle at 80% 20%, rgba(124, 201, 255, 0.12), transparent 30%);
            filter: blur(50px);
            pointer-events: none;
            z-index: 0;
        }

        main {
            position: relative;
            max-width: 1080px;
            margin: 0 auto;
            padding: 40px 24px 80px;
            z-index: 1;
        }

        .hero {
            margin-top: 24px;
            padding: 26px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
            border: 1px solid var(--stroke);
            border-radius: 20px;
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(108, 247, 197, 0.12);
            color: var(--accent);
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        h1 {
            margin: 12px 0 8px;
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
            font-size: clamp(28px, 5vw, 40px);
            line-height: 1.2;
        }

        .lead {
            margin: 0 0 18px;
            color: var(--muted);
            line-height: 1.6;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 14px;
            border: 1px solid var(--stroke);
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            backdrop-filter: blur(6px);
        }

        .btn.primary {
            background: linear-gradient(120deg, var(--accent), var(--accent-2));
            color: #061028;
            box-shadow: 0 12px 32px rgba(108, 247, 197, 0.25);
            border: none;
        }

        .btn.ghost {
            background: rgba(255, 255, 255, 0.07);
        }

        .btn:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(0,0,0,0.35); }

        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
        }

        .metric-card {
            padding: 14px;
            border-radius: 14px;
            background: var(--card);
            border: 1px solid var(--stroke);
        }

        .metric-card strong { display: block; font-size: 18px; margin-bottom: 4px; }
        .metric-card span { color: var(--muted); font-size: 13px; }

        section {
            margin-top: 40px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            letter-spacing: 0.3px;
            margin: 0 0 12px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--stroke);
            color: var(--accent);
            font-weight: 700;
        }

        .grid {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .card {
            padding: 18px;
            border-radius: 16px;
            border: 1px solid var(--stroke);
            background: var(--card);
            box-shadow: var(--shadow);
        }

        .card h3 { margin: 0 0 8px; font-size: 17px; }
        .card p { margin: 0; color: var(--muted); line-height: 1.5; font-size: 14px; }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            background: rgba(108, 247, 197, 0.12);
            color: var(--accent);
            border: 1px solid rgba(108, 247, 197, 0.2);
            margin-bottom: 8px;
        }

        .agenda-rotator {
            position: relative;
            perspective: 1200px;
            overflow: hidden;
            isolation: isolate;
        }
        .agenda-swap {
            position: relative;
            z-index: 1;
            transform-origin: center;
            transition: transform 0.55s cubic-bezier(0.4, 0.2, 0.15, 1), opacity 0.35s ease;
        }
        .agenda-swap.flipping {
            transform: rotateY(90deg) translateZ(0);
            opacity: 0;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .step {
            padding: 14px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            border: 1px dashed var(--stroke);
        }

        .cta-banner {
            margin-top: 32px;
            padding: 22px;
            border-radius: 18px;
            background: linear-gradient(120deg, rgba(108, 247, 197, 0.16), rgba(124, 201, 255, 0.14));
            border: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            flex-direction: column;
            gap: 10px;
            box-shadow: var(--shadow);
        }

        footer {
            margin-top: 32px;
            padding: 16px 0 32px;
            color: var(--muted);
            font-size: 14px;
        }

        .socials { display: flex; gap: 12px; margin-top: 6px; }

        .socials a {
            color: var(--text);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 12px;
            border: 1px solid var(--stroke);
            background: rgba(255, 255, 255, 0.05);
        }

        @media (max-width: 640px) {
            main { padding: 28px 16px 64px; }
            .hero { padding: 20px; }
        }

        .loading-overlay {
            position: fixed;
            inset: 0;
            display: grid;
            place-items: center;
            background: radial-gradient(circle at 20% 20%, rgba(197, 255, 106, 0.18), transparent 32%),
                        radial-gradient(circle at 80% 30%, rgba(255, 255, 255, 0.15), transparent 36%),
                        linear-gradient(135deg, rgba(0, 0, 0, 0.75), rgba(0, 0, 0, 0.9));
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }

        .loading-overlay.fade-out {
            opacity: 0;
            visibility: hidden;
        }

        .loading-stack {
            position: relative;
            width: min(420px, 72vw);
        }

        .loading-gif {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 18px;
            background: #11111100;
            filter: drop-shadow(0 10px 28px rgba(0, 0, 0, 0.4));
            object-fit: cover;
        }

        .loading-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40%;
            max-width: 170px;
            filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.5));
        }

        .loading-card {
            margin-top: 12px;
            padding: 14px 16px 16px;
            border-radius: 14px;
            background: rgba(0, 0, 0, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            color: #f6f8fb;
            text-align: center;
            font-family: 'Manrope', 'Space Grotesk', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .loading-card .progress-track {
            margin-top: 10px;
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            overflow: hidden;
        }

        .loading-card .progress-bar {
            position: relative;
            display: block;
            height: 100%;
            width: 38%;
            background: linear-gradient(90deg, rgba(197, 255, 106, 0.3), #c5ff6a);
            border-radius: inherit;
            animation: loading-pulse 1.6s ease-in-out infinite;
        }

        @keyframes loading-pulse {
            0% { left: -45%; width: 32%; }
            50% { left: 30%; width: 45%; }
            100% { left: 100%; width: 32%; }
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="page-loading" aria-live="polite">
        <div class="loading-stack">
            <img class="loading-gif" src="palestine.gif" alt="Animasi pemuatan">
            <img class="loading-logo" src="logo-ukmi.png" alt="Logo UKMI">
        </div>
        <div class="loading-card">
            <div>Memuat konten, mohon tunggu...</div>
            <div class="progress-track" aria-hidden="true"><span class="progress-bar"></span></div>
        </div>
    </div>
    <div class="glow" aria-hidden="true"></div>
    <?php include __DIR__ . '/header.php'; ?>

    <main>
        <section class="hero">
            <div>
                <div class="eyebrow">Open Recruitment · <span id="current-year"></span></div>
                <script>document.getElementById('current-year').textContent = new Date().getFullYear();</script>
                <h1>Jadi bagian keluarga UKMI. Bergerak bareng, bertumbuh bareng.</h1>
                <p class="lead">Halaman shortcut untuk mahasiswa baru Polmed yang mau nyemplung ke organisasi UKMI: cek agenda, kelas skill, jalur daftar tercepat, dan dokumentasi kegiatan.</p>
                <div class="cta-row">
                    <a class="btn primary" href="<?php echo e($registration['url']); ?>" target="_blank" rel="noopener">Daftar sekarang</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Lihat arsip IG kami</a>
                </div>
            </div>
            <div>
                <div class="metrics">
                    <div class="metric-card">
                        <strong>∞+ kegiatan</strong>
                        <span>Mentoring, sosial, dan kreatif setiap minggunya.</span>
                    </div>
                    <div class="metric-card">
                        <strong><?php echo e($divisionCount); ?> divisi</strong>
                        <span><?php echo e(implode(' · ', $divisionNames)); ?>.</span>
                    </div>
                    <div class="metric-card">
                        <strong>Terbuka</strong>
                        <span>Untuk semua jurusan & angkatan baru.</span>
                    </div>
                </div>
            </div>
        </section>

        <section id="shortcut">
            <h2 class="section-title"><span class="pill">★</span>Shortcut paling dicari</h2>
            <div class="grid">
                <div class="card agenda-rotator">
                    <div class="tag agenda-swap" id="agenda-tag"><?php echo e($firstAgenda['tag']); ?></div>
                    <h3 class="agenda-swap" id="agenda-title"><?php echo e($firstAgenda['title']); ?></h3>
                    <p class="agenda-swap" id="agenda-detail"><?php echo e($firstAgenda['detail']); ?></p>
                </div>
                <div class="card">
                    <div class="tag">Skill Class</div>
                    <h3>Desain & konten</h3>
                    <p>Belajar Canva, editing, dan social media playbook UKMI. Cocok buat kamu yang kreatif.</p>
                </div>
                <div class="card">
                    <div class="tag">Mentoring</div>
                    <h3>Circle kecil</h3>
                    <p>Ruang ngobrol hangat bareng kakak tingkat tentang kampus, iman, dan karier.</p>
                </div>
                <div class="card">
                    <div class="tag">Social impact</div>
                    <h3>Aksi nyata</h3>
                    <p>Program bakti sosial, peduli lingkungan, dan kolaborasi lintas UKM.</p>
                </div>
            </div>
        </section>

        <section id="program">
            <h2 class="section-title"><span class="pill">→</span>Kenapa harus gabung UKMI Polmed?</h2>
            <div class="grid">
                <div class="card">
                    <h3>Teman seperjuangan</h3>
                    <p>Lingkungan suportif untuk kuliah, organisasi, dan tumbuh bareng di kampus.</p>
                </div>
                <div class="card">
                    <h3>Portofolio nyata</h3>
                    <p>Handle event, media sosial, fundraising, dan project digital yang bisa kamu pajang.</p>
                </div>
                <div class="card">
                    <h3>Jaringan luas</h3>
                    <p>Terhubung dengan alumni, komunitas dakwah kampus, dan relasi eksternal.</p>
                </div>
                <div class="card">
                    <h3>Mentoring karier</h3>
                    <p>Belajar langsung dari kakak tingkat yang sudah magang, studi lanjut, atau kerja.</p>
                </div>
            </div>
        </section>

        <section id="divisi">
            <h2 class="section-title"><span class="pill">◎</span>Divisi kepengurusan <?php echo e($divisionYearLabel); ?></h2>
            <div class="grid">
                <?php foreach ($divisions as $div): ?>
                    <div class="card"><h3><?php echo e($div['name']); ?></h3><p><?php echo e($div['description']); ?></p></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section id="daftar">
            <h2 class="section-title"><span class="pill">⚡</span>Jalur cepat daftar</h2>
            <p class="lead">Pilih cara paling nyaman buat kamu. Kalau sudah punya link resmi, silahkan jalankan dengan baik.</p>
            <div class="steps">
                <div class="step">
                    <strong>Ikuti mentoring mingguan</strong>
                    <p>Gabung sesi mentoring, amati bagaimana UKMI berjalan, dan terlibat secara bertahap.</p>
                </div>
                <div class="step">
                    <strong>Tunggu proses seleksi</strong>
                    <p>Panitia akan memantau peserta mentoring; jika lolos, kamu akan dihubungi dan diundang untuk wawancara.</p>
                </div>
                <div class="step">
                    <strong>Isi form sebelum wawancara</strong>
                    <p>Sebelum wawancara kemungkinan diminta mengisi form data diri, divisi yang diincar, dan informasi pendukung lainnya.</p>
                </div>
            </div>

            <div class="cta-banner">
                <strong>Siap gas? Klik sekali untuk daftar.</strong>
                <div class="cta-row">
                    <a class="btn primary" href="<?php echo e($registration['url']); ?>" target="_blank" rel="noopener">Buka form pendaftaran</a>
                    <a class="btn ghost" href="https://instagram.com/ukmipolmed" target="_blank" rel="noopener">Tanya via DM IG</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Lihat dokumentasi kegiatan</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/what-they-said/" target="_blank" rel="noopener">Testimoni</a>
                </div>
                <small style="color: var(--muted);">Form utama mengarah ke Google Forms mentoring UKMI. Link cadangan tersedia di halaman testimoni.</small>
            </div>
        </section>

        <section id="dokumentasi">
            <h2 class="section-title"><span class="pill">📸</span>Dokumentasi kegiatan</h2>
            <div class="grid">
                <?php foreach ($docs as $doc): ?>
                    <div class="card">
                        <div class="tag"><?php echo e($doc['tag']); ?></div>
                        <h3><?php echo e($doc['title']); ?></h3>
                        <p><?php echo e($doc['description']); ?></p>
                        <div class="cta-row" style="margin-top:12px;">
                            <a class="btn primary" href="<?php echo e($doc['url']); ?>" target="_blank" rel="noopener">Buka</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <footer>
            <div>UKMI Politeknik Negeri Medan · Bersama bertumbuh dalam kebaikan.</div>
            <div class="socials">
                <a href="https://instagram.com/ukmipolmed" target="_blank" rel="noopener">Instagram</a>
                <a href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Arsip IG</a>
                <a href="https://krasyid822.github.io/ukmipolmed/dokumentasi-ppi-2024/">PPI 2024</a>
                <a href="#daftar">Daftar</a>
            </div>
        </footer>
    </main>

    <script>
        const loadingEl = document.getElementById('page-loading');
        window.addEventListener('load', () => {
            if (!loadingEl) return;
            loadingEl.classList.add('fade-out');
            setTimeout(() => loadingEl.remove(), 600);
        });

        // Smooth scroll for in-page anchors
        document.querySelectorAll('a[href^="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                const targetId = link.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    window.scrollTo({
                        top: target.offsetTop - 60,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Structured data for SEO
        const orgSchema = {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            name: 'UKMI Polmed',
            url: './',
            logo: 'logo-ukmi.png',
            sameAs: [
                'https://instagram.com/ukmipolmed',
                'https://forms.gle/e4e6egXHiRnyQVPV7'
            ],
            contactPoint: [{
                '@type': 'ContactPoint',
                contactType: 'student affairs',
                url: 'https://instagram.com/ukmipolmed'
            }]
        };
        const ld = document.createElement('script');
        ld.type = 'application/ld+json';
        ld.textContent = JSON.stringify(orgSchema);
        document.head.appendChild(ld);

        // Rotasi agenda pada kartu shortcut (satu kartu, bergantian jika lebih dari satu entri).
        const agendas = <?php echo json_encode($agendas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const tagEl = document.getElementById('agenda-tag');
        const titleEl = document.getElementById('agenda-title');
        const detailEl = document.getElementById('agenda-detail');

        let agendaIndex = 0;
        function swapAgenda(next) {
            if (!tagEl || !titleEl || !detailEl) return;
            [tagEl, titleEl, detailEl].forEach(el => el.classList.add('flipping'));
            setTimeout(() => {
                tagEl.textContent = next.tag || '';
                titleEl.textContent = next.title || '';
                detailEl.textContent = next.detail || '';
                [tagEl, titleEl, detailEl].forEach(el => el.classList.remove('flipping'));
            }, 230);
        }

        if (agendas.length > 1) {
            setInterval(() => {
                agendaIndex = (agendaIndex + 1) % agendas.length;
                swapAgenda(agendas[agendaIndex]);
            }, 5200);
        }

    </script>
</body>
</html>