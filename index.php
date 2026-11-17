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
$heroCardDefaults = [
    ['type' => 'image', 'title' => 'Logo UKMI Polmed', 'caption' => 'Swipe kartu lain atau edit tampilannya dari admin.', 'content' => '/logo-ukmi.png', 'alt' => 'Logo UKMI Polmed'],
    ['type' => 'image', 'title' => 'Highlight kegiatan', 'caption' => 'Konten bisa diisi gambar atau embed code.', 'content' => '/palestine.gif', 'alt' => 'Ilustrasi highlight kegiatan UKMI Polmed'],
];
$protectedDefaultHeroCard = [
    'type' => 'default',
    'title' => 'Kartu bawaan beranda',
    'caption' => 'Kartu ini menampilkan ringkasan utama UKMI dan tidak bisa dihapus.',
    'content' => '__default__',
    'alt' => '',
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
        if (!empty($defaultData['hero_cards']) && is_array($defaultData['hero_cards'])) {
            $heroCardDefaults = $defaultData['hero_cards'];
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

$heroCards = $heroCardDefaults;
// If config explicitly contains the key `hero_cards`, use it (even if empty) to override defaults.
if (isset($config) && is_array($config) && array_key_exists('hero_cards', $config) && is_array($config['hero_cards'])) {
    $tmpHeroCards = [];
    $hasDefaultCard = false;
    foreach ($config['hero_cards'] as $item) {
        if (!is_array($item)) continue;
        $type = strtolower(trim((string) ($item['type'] ?? 'image')));
        if (!in_array($type, ['image', 'embed', 'default'], true)) {
            $type = 'image';
        }
        if ($type === 'default') {
            if ($hasDefaultCard) continue;
            $tmpHeroCards[] = $protectedDefaultHeroCard;
            $hasDefaultCard = true;
            if (count($tmpHeroCards) >= 8) break;
            continue;
        }
        $title = trim((string) ($item['title'] ?? ''));
        $caption = trim((string) ($item['caption'] ?? ''));
        $content = trim((string) ($item['content'] ?? ''));
        $alt = trim((string) ($item['alt'] ?? ''));
        if ($title === '' && $caption === '' && $content === '') continue;
        if ($content === '') continue;
        $tmpHeroCards[] = [
            'type' => $type,
            'title' => $title,
            'caption' => $caption,
            'content' => $content,
            'alt' => $alt,
        ];
        if (count($tmpHeroCards) >= 8) break;
    }
    // override defaults even with an empty list
    $heroCards = $tmpHeroCards;
}

// Ensure built-in hero-copy card exists exactly once in the ordered list.
$normalizedHeroCards = [];
$hasDefaultCard = false;
foreach ($heroCards as $item) {
    if (!is_array($item)) continue;
    $type = strtolower(trim((string) ($item['type'] ?? 'image')));
    if ($type === 'default') {
        if ($hasDefaultCard) continue;
        $normalizedHeroCards[] = $protectedDefaultHeroCard;
        $hasDefaultCard = true;
        continue;
    }
    if (!in_array($type, ['image', 'embed'], true)) {
        continue;
    }
    $title = trim((string) ($item['title'] ?? ''));
    $caption = trim((string) ($item['caption'] ?? ''));
    $content = trim((string) ($item['content'] ?? ''));
    $alt = trim((string) ($item['alt'] ?? ''));
    if ($content === '') continue;
    $normalizedHeroCards[] = [
        'type' => $type,
        'title' => $title,
        'caption' => $caption,
        'content' => $content,
        'alt' => $alt,
    ];
}
if (!$hasDefaultCard) {
    array_unshift($normalizedHeroCards, $protectedDefaultHeroCard);
}
$heroCards = array_slice($normalizedHeroCards, 0, 8);

$firstAgenda = $agendas[0];

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$canonicalHost = preg_replace('/:\\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.xo.je'));
$canonicalBase = 'https://' . $canonicalHost;
$canonicalPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($canonicalPath === '/index.php') {
    $canonicalPath = '/';
}
$canonicalUrl = $canonicalBase . $canonicalPath;
$homeUrl = $canonicalBase . '/';
$logoAbsoluteUrl = $canonicalBase . '/logo-ukmi.png';
?>
<!doctype html>
<html lang="id">
<head>
    <!-- Google Tag Manager (deferred for INP) -->
    <script>
    window.addEventListener('load', function() {
        setTimeout(function() {
            (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
            new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','GTM-WS2XLWDK');
        }, 2000);
    });
    </script>
    <!-- End Google Tag Manager -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <link rel="alternate" hreflang="id" href="<?php echo e($canonicalUrl); ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo e($canonicalUrl); ?>">
    <title>UKMI Politeknik Negeri Medan</title>
    <meta name="description" content="Gabung Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan <?php echo e($divisionYearLabel); ?> — mentoring, tilawah, media, keputrian & ekonomi. Daftar, cek agenda, dan lihat dokumentasi.">
    <meta name="keywords" content="Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan, UKMI Politeknik Negeri Medan, open recruitment Unit Kegiatan Mahasiswa Islam POLMED <?php echo e($divisionYearLabel); ?>, organisasi Islam Politeknik Negeri Medan, mentoring kampus Politeknik Negeri Medan, pendaftaran Unit Kegiatan Mahasiswa Islam, kegiatan dakwah kampus Medan">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="<?php echo e($canonicalUrl); ?>">
    <meta property="og:locale" content="id_ID">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan – Open Recruitment <?php echo e($divisionYearLabel); ?>">
    <meta property="og:description" content="<?php echo e($divisionCount); ?> divisi: mentoring, tilawah, media, keputrian & ekonomi. Daftar, cek agenda, dan lihat dokumentasi.">
    <meta property="og:url" content="<?php echo e($canonicalUrl); ?>">
    <meta property="og:image" content="<?php echo e($logoAbsoluteUrl); ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:alt" content="Logo UKMI Politeknik Negeri Medan">
    <meta property="og:site_name" content="Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan">
    <link rel="icon" type="image/png" sizes="192x192" href="logo-ukmi.png">
    <link rel="icon" type="image/png" sizes="32x32" href="logo-ukmi.png">
    <link rel="icon" type="image/png" sizes="16x16" href="logo-ukmi.png">
    <link rel="apple-touch-icon" sizes="180x180" href="logo-ukmi.png">
    <link rel="shortcut icon" href="logo-ukmi.png">
    <meta name="theme-color" content="#0f172a">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan – Open Recruitment <?php echo e($divisionYearLabel); ?>">
    <meta name="twitter:description" content="Gabung Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan <?php echo e($divisionYearLabel); ?> — mentoring, tilawah, media, keputrian & ekonomi. Daftar dan cek agenda serta dokumentasi.">
    <meta name="twitter:image" content="<?php echo e($logoAbsoluteUrl); ?>">
    <meta name="twitter:image:alt" content="Logo UKMI Politeknik Negeri Medan">
    <?php
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'Bagaimana cara daftar Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Klik tombol Daftar sekarang atau isi form pendaftaran di bagian Jalur cepat daftar.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Apa yang akan saya dapatkan di Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan?',
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
                    'text' => 'Ya, Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan terbuka untuk semua jurusan dan angkatan baru.',
                ],
            ],
        ],
    ];
    $faqJson = json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ?>
    <script type="application/ld+json"><?php echo $faqJson; ?></script>
    <?php
    $websiteSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
        'url' => $homeUrl,
        'description' => 'Website resmi Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan – organisasi kerohanian Islam mahasiswa.',
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoAbsoluteUrl,
            ],
        ],
    ];
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Beranda',
                'item' => $homeUrl,
            ],
        ],
    ];
    $eventSchemaItems = [];
    foreach ($agendas as $agendaItem) {
        $eventSchemaItems[] = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $agendaItem['title'] ?? 'Agenda Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
            'description' => $agendaItem['detail'] ?? '',
            'organizer' => [
                '@type' => 'Organization',
                'name' => 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
                'url' => $homeUrl,
            ],
            'performer' => [
                '@type' => 'Organization',
                'name' => 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
                'url' => $homeUrl,
            ],
            'image' => $logoAbsoluteUrl,
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'eventStatus' => 'https://schema.org/EventScheduled',
            'startDate' => date('c'),
            'endDate' => date('c', strtotime('+2 hours')),
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'IDR',
                'url' => $registration['url'] ?? $homeUrl,
                'availability' => 'https://schema.org/InStock',
                'validFrom' => date('c'),
            ],
            'location' => [
                '@type' => 'Place',
                'name' => 'Politeknik Negeri Medan',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => 'Jl. Almamater No.1 Kampus USU',
                    'addressLocality' => 'Medan',
                    'addressRegion' => 'Sumatera Utara',
                    'addressCountry' => 'ID'
                ]
            ],
        ];
    }
    ?>
    <script type="application/ld+json"><?php echo json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
    <script type="application/ld+json"><?php echo json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
    <?php foreach ($eventSchemaItems as $evSchema): ?>
    <script type="application/ld+json"><?php echo json_encode($evSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
    <?php endforeach; ?>
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
            padding: 26px 24px 80px;
            z-index: 1;
        }

        @media (min-width: 641px) {
            main {
                max-width: none;
                padding-left: 22px;
                padding-right: 22px;
            }

            .hero-rotator {
                max-width: 980px;
                margin-left: auto;
                margin-right: auto;
            }

            .hero-carousel {
                padding-left: clamp(24px, 4vw, 40px);
                padding-right: clamp(24px, 4vw, 40px);
                scroll-padding-left: clamp(24px, 4vw, 40px);
                scroll-padding-right: clamp(24px, 4vw, 40px);
            }

            .hero-slide {
                flex: 0 0 min(900px, calc(100% - clamp(88px, 12vw, 160px)));
                max-width: min(900px, calc(100% - clamp(88px, 12vw, 160px)));
            }
        }

        .hero {
            margin-top: 24px;
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            display: block;
            position: relative;
            transition: min-height 260ms ease;
            /* keep overflow visible so edge controls are not clipped */
            overflow: visible;
        }

        .hero-title-wrap {
            margin-top: 6px;
            margin-bottom: 10px;
        }

        .hero-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .hero-rotator {
            position: relative;
        }

        .hero-carousel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .hero-carousel-head h2 {
            margin: 4px 0 0;
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
            font-size: 18px;
            line-height: 1.25;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(124, 201, 255, 0.12);
            color: var(--accent-2);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .hero-controls {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .hero-control {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 1px solid var(--stroke);
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            font-size: 24px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, opacity 0.2s ease;
        }

        .hero-control:hover { transform: translateY(-1px); background: rgba(255, 255, 255, 0.14); }
        .hero-control:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }

        /* Edge controls positioned outside the hero box */
        .hero-control.hero-control-left,
        .hero-control.hero-control-right {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 3;
        }

        .hero-control.hero-control-left { left: -10px; }
        .hero-control.hero-control-right { right: -10px; }

        @media (max-width: 640px) {
            .hero-control.hero-control-left { left: 12px; }
            .hero-control.hero-control-right { right: 12px; }
        }

        .hero-carousel {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            gap: 12px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y pinch-zoom;
            overscroll-behavior-x: contain;
            padding-bottom: 2px;
            align-items: flex-start; /* allow slides to size independently */
            cursor: grab;
            transition: height 260ms ease;
        }

        .hero-carousel:active {
            cursor: grabbing;
        }

        .hero-carousel::-webkit-scrollbar { display: none; }

        .hero-slide {
            scroll-snap-align: start;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.03);
            min-width: 0;
            padding: 18px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            align-items: start; /* don't stretch children to match tallest slide */
            flex: 0 0 100%; /* each slide takes full carousel viewport width */
            max-width: 100%;
        }

        .hero-slide-collage {
            grid-template-columns: 1fr;
            align-items: start;
            padding: 14px;
            gap: 10px;
        }

        .hero-copy {
            display: grid;
            gap: 14px;
            align-content: start;
        }

        .hero-copy .lead { margin-bottom: 4px; }

        .hero-copy .metrics { margin-top: 2px; }

        .hero-visual {
            display: grid;
            gap: 12px;
            align-content: start;
        }

        .hero-collage {
            display: grid;
            gap: 8px;
        }

        .hero-visual-frame {
            position: relative;
            /* default fallback ratio; will be overridden by JS per-content */
            aspect-ratio: 16 / 11;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(108, 247, 197, 0.08), rgba(124, 201, 255, 0.08));
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .hero-visual-frame img,
        .hero-visual-frame iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            object-fit: contain;
            background: transparent;
        }

        /* iframe aspect handled by container; keep iframe layout natural */
        .hero-visual-frame iframe { aspect-ratio: auto; }

        .hero-visual-copy {
            padding: 0 4px;
        }

        .hero-visual-copy strong {
            display: block;
            margin-bottom: 4px;
            font-size: 15px;
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
        }

        .hero-visual-copy p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .hero-default-note {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
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

        .footer-now {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 10px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(108, 247, 197, 0.12);
            color: var(--accent);
            border: 1px solid rgba(108, 247, 197, 0.2);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            margin: 10px 0 0;
            max-width: 100%;
        }

        .footer-now .fs-list {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px 10px;
        }

        .footer-now .fs-item {
            display: inline-flex;
            align-items: baseline;
            gap: 4px;
            white-space: nowrap;
            color: var(--muted);
            font-weight: 600;
            transition: color .2s ease, background .2s ease;
        }

        .footer-now .fs-item .fs-name {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            opacity: .75;
        }

        .footer-now .fs-item .fs-time {
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }

        .footer-now .fs-item.is-active {
            color: #04160f;
            background: var(--accent);
            border-radius: 999px;
            padding: 2px 9px;
        }

        .footer-now .fs-item.is-active .fs-name,
        .footer-now .fs-item.is-active .fs-time { color: #04160f; opacity: 1; }

        .footer-now .fs-next {
            color: var(--accent);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            opacity: .9;
        }

        @media (max-width: 640px) {
            .footer-now { width: 100%; justify-content: center; }
            .footer-now .fs-list { justify-content: center; }
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
            font-size: 15px;
            min-height: 48px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            backdrop-filter: blur(6px);
            -webkit-tap-highlight-color: transparent;
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
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--stroke);
            background: rgba(255, 255, 255, 0.05);
            font-size: 14px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 1 1 160px;
        }

        @media (max-width: 640px) {
            main { padding: 20px 16px 64px; }
            .hero { padding: 0; }
            .hero-slide { padding: 14px; gap: 14px; grid-template-columns: 1fr; }
            .hero-carousel-head { flex-direction: column; }
            .hero-controls { align-self: flex-start; }
            .hero-control.hero-control-left,
            .hero-control.hero-control-right { display: none; }
            .cta-row { width: 100%; }
            .cta-row .btn { width: 100%; justify-content: center; }
            .metrics { grid-template-columns: 1fr; }
            .socials { flex-wrap: wrap; gap: 10px; }
            .socials a { flex: 1 1 48%; }
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
            max-height: 170px;
            aspect-ratio: 1 / 1;
            object-fit: contain;
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
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-WS2XLWDK"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <div class="loading-overlay" id="page-loading" aria-live="polite">
        <div class="loading-stack">
            <img class="loading-gif" src="palestine.gif" alt="Animasi bendera Palestina saat halaman dimuat" fetchpriority="high" width="420" height="420">
            <img class="loading-logo" src="logo-ukmi.png" alt="Logo Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" width="170" height="170">
        </div>
        <div class="loading-card">
            <div>Memuat konten, mohon tunggu...</div>
            <div class="progress-track" aria-hidden="true"><span class="progress-bar"></span></div>
        </div>
    </div>
    <div class="glow" aria-hidden="true"></div>
    <?php include __DIR__ . '/header.php'; ?>

    <main>
        <div class="hero-title-wrap">
            <h2 class="section-title"><span class="pill">◆</span>Portofolio UKMI</h2>
            <p class="hero-subtitle">Geser untuk melihat kolase lain.</p>
        </div>
        <section class="hero">
            <div class="hero-rotator">
                <div class="hero-carousel" id="hero-carousel" aria-label="Header card presentation">
                    <?php foreach ($heroCards as $card): ?>
                        <?php if (($card['type'] ?? '') === 'default'): ?>
                            <article class="hero-slide hero-slide-default">
                                <div class="hero-copy">
                                    <h1>Unit Kegiatan Mahasiswa Islam Polmed <?php echo e($divisionYearLabel); ?></h1>
                                    <p class="lead">Kamu mahasiswa baru Politeknik Negeri Medan? Beruntung, Kamu bisa temukan informasi open recruitment yang terdiri dari <?php echo e($divisionCount); ?> divisi, agenda kegiatan, kelas skill, jalur daftar tercepat, dan dokumentasi UKMI Polmed di sini.</p>
                                    <div class="cta-row">
                                        <a class="btn primary" href="<?php echo e($registration['url']); ?>" target="_blank" rel="noopener" data-insight="cta_daftar_hero">Daftar sekarang</a>
                                        <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/" data-insight="cta_arsip_hero">Lihat arsip IG kami</a>
                                        <a class="btn ghost" href="/blog.php" data-insight="cta_arsip_hero">Lihat Blog UKMI</a>
                                    </div>
                                    <div class="metrics">
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
                                <!-- visual removed from default hero card as requested -->
                            </article>
                        <?php else: ?>
                            <article class="hero-slide hero-slide-collage">
                                <div class="hero-collage">
                                    <div class="hero-visual-frame<?php echo (($card['type'] ?? 'image') === 'embed') ? ' hero-embed' : ''; ?>">
                                        <?php if (($card['type'] ?? 'image') === 'embed'): ?>
                                            <?php echo $card['content']; ?>
                                        <?php else: ?>
                                            <img src="<?php echo e($card['content']); ?>" alt="<?php echo e($card['alt'] !== '' ? $card['alt'] : ($card['title'] ?? 'Visual kegiatan Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan')); ?>" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                    </div>
                                    <div class="hero-visual-copy">
                                        <?php if (!empty($card['title'])): ?><strong><?php echo e($card['title']); ?></strong><?php endif; ?>
                                        <?php if (!empty($card['caption'])): ?><p><?php echo e($card['caption']); ?></p><?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="hero-control hero-control-left" id="hero-prev" aria-label="Kartu sebelumnya">‹</button>
            <button type="button" class="hero-control hero-control-right" id="hero-next" aria-label="Kartu berikutnya">›</button>
        </section>

        <section id="shortcut">
            <h2 class="section-title"><span class="pill">★</span>Baca ini..</h2>
            <div class="grid">
                <div class="card agenda-rotator">
                    <div class="tag agenda-swap" id="agenda-tag"><?php echo e($firstAgenda['tag']); ?></div>
                    <h3 class="agenda-swap" id="agenda-title"><?php echo e($firstAgenda['title']); ?></h3>
                    <p class="agenda-swap" id="agenda-detail"><?php echo e($firstAgenda['detail']); ?></p>
                </div>
                <div class="card">
                    <div class="tag">Skill Class</div>
                    <h3>Desain & konten</h3>
                    <p>Belajar desain, editing, dan social media playbook UKMI. Cocok buat kamu yang kreatif.</p>
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
                    <strong>1. Ikuti mentoring mingguan</strong>
                    <p>Gabung sesi mentoring, amati bagaimana UKMI berjalan, dan terlibat secara bertahap.</p>
                </div>
                <div class="step">
                    <strong>2. Tunggu proses seleksi</strong>
                    <p>Panitia akan memantau peserta mentoring; jika lolos, kamu akan dihubungi dan diundang untuk wawancara.</p>
                </div>
                <div class="step">
                    <strong>3. Isi form sebelum wawancara</strong>
                    <p>Sebelum wawancara kemungkinan diminta mengisi form data diri, divisi yang diincar, dan informasi pendukung lainnya.</p>
                </div>
            </div>

            <div class="cta-banner">
                <strong>Siap gas? Klik sekali untuk daftar.</strong>
                <div class="cta-row">
                    <a class="btn primary" href="<?php echo e($registration['url']); ?>" target="_blank" rel="noopener" data-insight="cta_daftar_banner">Buka form pendaftaran</a>
                    <a class="btn ghost" href="https://www.instagram.com/ukmipolmed/" target="_blank" rel="noopener" data-insight="cta_dm_ig">Tanya via DM IG</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/" data-insight="cta_dokumentasi">Lihat dokumentasi kegiatan</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/what-they-said/" target="_blank" rel="noopener" data-insight="cta_testimoni">Testimoni</a>
                </div>
                <!-- <small style="color: var(--muted);">Form utama mengarah ke Google Forms mentoring UKMI. Link cadangan tersedia di halaman testimoni.</small> -->
            </div>
        </section>

        <section id="dokumentasi">
            <h2 class="section-title"><span class="pill">📸</span>Dokumentasi kegiatan</h2>
            <div class="grid">
                <?php foreach ($docs as $idx => $doc): ?>
                    <div class="card">
                        <div class="tag"><?php echo e($doc['tag']); ?></div>
                        <h3><?php echo e($doc['title']); ?></h3>
                        <p><?php echo e($doc['description']); ?></p>
                        <div class="cta-row" style="margin-top:12px;">
                            <a class="btn primary" href="<?php echo e($doc['url']); ?>" target="_blank" rel="noopener" data-insight="doc_<?php echo e($idx); ?>">Buka</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <footer>
            <div>UKMI Politeknik Negeri Medan · <?php echo e(($config['yel_yel'] ?? '') !== '' ? $config['yel_yel'] : 'Bersama bertumbuh dalam kebaikan.'); ?></div>
            <div class="footer-now" id="footer-sholat"
                 aria-label="Jadwal waktu sholat Masjid Baitul Ilmi Polmed, metode Kemenag/NU">
                <span class="fs-list">
                    <span class="fs-item" data-prayer="fajr"><span class="fs-name">Subuh</span> <span class="fs-time" data-time="fajr">--:--</span></span>
                    <span class="fs-item" data-prayer="dhuhr"><span class="fs-name">Dzuhur</span> <span class="fs-time" data-time="dhuhr">--:--</span></span>
                    <span class="fs-item" data-prayer="asr"><span class="fs-name">Ashar</span> <span class="fs-time" data-time="asr">--:--</span></span>
                    <span class="fs-item" data-prayer="maghrib"><span class="fs-name">Maghrib</span> <span class="fs-time" data-time="maghrib">--:--</span></span>
                    <span class="fs-item" data-prayer="isha"><span class="fs-name">Isya</span> <span class="fs-time" data-time="isha">--:--</span></span>
                </span>
                <span class="fs-next" id="fs-next" hidden></span>
            </div>
            <nav aria-label="Media sosial UKMI Polmed" style="margin-top:8px;">
                <div class="socials">
                    <a href="https://www.instagram.com/ukmipolmed/" target="_blank" rel="noopener"><img alt="Ikon Instagram Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="/foto.php?key=ukmipolmed%2Fuploads%2F460e25a7e6dce46da1c7467a4f89fa575e36b598.webp" style="width: 24px; height: 24px; margin-right: 5px;">Instagram</a>
                    <a href="https://www.youtube.com/c/UKMIPolmedTV" target="_blank" rel="noopener"><img alt="Ikon YouTube Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="/foto.php?key=ukmipolmed%2Fuploads%2F17342d1f7b206c79f21e40eb6411c86ed82c8d5e.svg" style="width: 24px; height: 24px; margin-right: 5px;">YouTube</a>
                    <a href="https://web.facebook.com/ukmipolmedmedan/" target="_blank" rel="noopener"><img alt="Ikon Facebook Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36' height='40' width='40'%3E%3Cpath fill='%230866FF' d='M20.181 35.87C29.094 34.791 36 27.202 36 18c0-9.941-8.059-18-18-18S0 8.059 0 18c0 8.442 5.811 15.526 13.652 17.471L14 34h5.5l.681 1.87Z'/%3E%3Cpath fill='%23FFFFFF' d='M13.651 35.471v-11.97H9.936V18h3.715v-2.37c0-6.127 2.772-8.964 8.784-8.964 1.138 0 3.103.223 3.91.446v4.983c-.425-.043-1.167-.065-2.081-.065-2.952 0-4.09 1.116-4.09 4.025V18h5.883l-1.008 5.5h-4.867v12.37a18.183 18.183 0 0 1-6.53-.399Z'/%3E%3C/svg%3E" style="width: 24px; height: 24px; margin-right: 5px;">Facebook</a>
                    <a href="https://x.com/ukmipolmed" target="_blank" rel="noopener"><img alt="Ikon X atau Twitter Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg%3E%3Cpath fill='%23000000' d='M21.742 21.75l-7.563-11.179 7.056-8.321h-2.456l-5.691 6.714-4.54-6.714H2.359l7.29 10.776L2.25 21.75h2.456l6.035-7.118 4.818 7.118h6.191-.008zM7.739 3.818L18.81 20.182h-2.447L5.29 3.818h2.447z'/%3E%3C/g%3E%3C/svg%3E" style="width: 24px; height: 24px; margin-right: 5px;">X (Twitter)</a>
                    <a href="https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan" target="_blank" rel="noopener"><img alt="Ikon LinkedIn Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 34 34'%3E%3Cpath fill='%230a66c2' d='M34 2.5v29a2.5 2.5 0 0 1-2.5 2.5h-29A2.5 2.5 0 0 1 0 31.5v-29A2.5 2.5 0 0 1 2.5 0h29A2.5 2.5 0 0 1 34 2.5M10 13H5v16h5zm.45-5.5a2.88 2.88 0 0 0-2.86-2.9H7.5a2.9 2.9 0 0 0 0 5.8 2.88 2.88 0 0 0 2.95-2.81zM29 19.28c0-4.81-3.06-6.68-6.1-6.68a5.7 5.7 0 0 0-5.06 2.58h-.14V13H13v16h5v-8.51a3.32 3.32 0 0 1 3-3.58h.19c1.59 0 2.77 1 2.77 3.52V29h5z'/%3E%3C/svg%3E" style="width: 24px; height: 24px; margin-right: 5px;">LinkedIn</a>
                </div>
            </nav>
            <nav aria-label="Navigasi situs" style="margin-top:10px;">
                <div class="socials">
                    <a href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Arsip IG</a>
                    <a href="https://krasyid822.github.io/ukmipolmed/dokumentasi-ppi-2024/">PPI 2024</a>
                    <a href="#daftar">Daftar</a>
                    <a href="blog.php">Blog</a>
                    <a href="faq.php">FAQ</a>
                    <a href="status.php">System Status</a>
                </div>
            </nav>
            <nav aria-label="Sumber terkait" style="margin-top:10px;">
                <p style="font-size:13px; color:var(--muted); margin:0 0 6px;">Sumber & referensi terkait:</p>
                <div class="socials">
                    <a href="https://polmed.ac.id" target="_blank" rel="noopener"><img alt="Logo Politeknik Negeri Medan" src="/foto.php?key=ukmipolmed%2Fuploads%2F12e3c93596fc1fa6c8bba0bed8db27220fa3e687.webp" style="width: 24px; height: 24px; margin-right: 5px;">Politeknik Negeri Medan</a>
                   <!-- <a href="https://ldii.or.id" target="_blank" rel="noopener"><img alt="Logo Lembaga Dakwah Islam Indonesia" src="/foto.php?key=ukmipolmed%2Fuploads%2F022395e1d85db34370ea4e49065537d777917bc6.webp" style="width: 24px; height: 24px; margin-right: 5px;">LDII</a> -->
                    <a href="https://masjidbaitulilmi.polmed.ac.id/" target="_blank" rel="noopener"><img alt="Logo Masjid Baitul Ilmi Politeknik Negeri Medan" src="/foto.php?key=ukmipolmed%2Fuploads%2F9502a6d477496e78d3a72eacdb15ae5b3e1f66bf.webp" style="width: 24px; height: 24px; margin-right: 5px;">Masjid Baitul Ilmi</a>
                    <a href="https://kemenag.go.id" target="_blank" rel="noopener"><img alt="Logo Kementerian Agama Republik Indonesia" src="/foto.php?key=ukmipolmed%2Fuploads%2Fd82408ad770460776fcf2e54f292ca311139136c.webp" style="width: 24px; height: 24px; margin-right: 5px;">Kemenag RI</a>
                    <a href="https://nu.or.id" target="_blank" rel="noopener"><img alt="Logo Nahdlatul Ulama" src="/foto.php?key=ukmipolmed%2Fuploads%2F09921d5c00a1469f5d4b11dc99d7befeaa72be58.webp" style="width: 24px; height: 24px; margin-right: 5px;">NU Online</a>
                    <a href="https://muhammadiyah.or.id/" target="_blank" rel="noopener"><img alt="Logo Muhammadiyah" src="/foto.php?key=ukmipolmed%2Fuploads%2F5d6e20dc098110351e0878817bdf856a881dc4dd.webp" style="width: 24px; height: 24px; margin-right: 5px;">Muhammadiyah</a>
                    <a href="https://id.wikipedia.org/wiki/Ikhwanul_Muslimin" target="_blank" rel="noopener"><img alt="Ilustrasi referensi Ikhwanul Muslimin dari Wikipedia" src="/foto.php?key=ukmipolmed%2Fuploads%2F35e13df26de983d583d101c6680abff5bc9d882d.webp" style="width: 24px; height: 24px; margin-right: 5px;">Wiki: Ikhwanul Muslimin</a>

                    <!-- <a href="" target="_blank" rel="noopener"><img src="" style="width: 24px; height: 24px; margin-right: 5px;"></a> -->
                </div>
            </nav>
        </footer>
    </main>

    <script>
        const loadingEl = document.getElementById('page-loading');
        window.addEventListener('load', () => {
            if (!loadingEl) return;
            loadingEl.classList.add('fade-out');
            setTimeout(() => loadingEl.remove(), 600);
        });

        // Smooth scroll for in-page anchors (passive listener for INP)
        document.querySelectorAll('a[href^="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                const targetId = link.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Structured data for SEO
        const orgSchema = {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            name: 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
            alternateName: 'UKMI Politeknik Negeri Medan',
            url: <?php echo json_encode($homeUrl, JSON_UNESCAPED_SLASHES); ?>,
            logo: <?php echo json_encode($logoAbsoluteUrl, JSON_UNESCAPED_SLASHES); ?>,
            sameAs: [
                'https://www.instagram.com/ukmipolmed/',
                'https://www.youtube.com/c/UKMIPolmedTV',
                'https://web.facebook.com/ukmipolmedmedan/',
                'https://x.com/ukmipolmed',
                'https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan',
                'https://forms.gle/e4e6egXHiRnyQVPV7'
            ],
            contactPoint: [{
                '@type': 'ContactPoint',
                contactType: 'student affairs',
                url: 'https://www.instagram.com/ukmipolmed/'
            }],
            parentOrganization: {
                '@type': 'CollegeOrUniversity',
                name: 'Politeknik Negeri Medan',
                url: 'https://polmed.ac.id'
            }
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
            sendInsight('agenda_view');
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

        const heroCarousel = document.getElementById('hero-carousel');
        const heroPrev = document.getElementById('hero-prev');
        const heroNext = document.getElementById('hero-next');
        const heroEl = document.querySelector('.hero');

        function updateHeroNav() {
            if (!heroCarousel) return;
            const maxScroll = heroCarousel.scrollWidth - heroCarousel.clientWidth;
            const current = Math.max(0, heroCarousel.scrollLeft);
            heroPrev && (heroPrev.disabled = current <= 4);
            heroNext && (heroNext.disabled = current >= (maxScroll - 4));
        }

        function scrollHero(direction) {
            if (!heroCarousel) return;
            const distance = Math.max(heroCarousel.clientWidth, 280);
            heroCarousel.scrollBy({ left: direction * distance, behavior: 'smooth' });
        }

        heroPrev?.addEventListener('click', () => scrollHero(-1));
        heroNext?.addEventListener('click', () => scrollHero(1));
        heroCarousel?.addEventListener('scroll', () => window.requestAnimationFrame(() => { updateHeroNav(); updateHeroHeight(); }), { passive: true });
        window.addEventListener('resize', () => { updateHeroNav(); adaptHeroFrames(); updateHeroHeight(); });
        updateHeroNav();
        // set initial hero height to the active slide
        updateHeroHeight();

        // Make hero visual frames adapt to their actual content (image/iframe).
        function adaptHeroFrames() {
            const frames = document.querySelectorAll('.hero-visual-frame');
            if (!frames || frames.length === 0) return;
            frames.forEach(frame => {
                const img = frame.querySelector('img');
                const iframe = frame.querySelector('iframe');

                if (img) {
                    const applyImgRatio = () => {
                        const w = img.naturalWidth || img.width || 16;
                        const h = img.naturalHeight || img.height || 9;
                        if (w && h) {
                            frame.style.setProperty('aspect-ratio', `${w} / ${h}`);
                        }
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'contain';
                        window.requestAnimationFrame(updateHeroHeight);
                    };
                    if (img.complete) applyImgRatio();
                    else img.addEventListener('load', applyImgRatio, { once: true });
                } else if (iframe) {
                    // try to infer aspect from attributes or data-aspect, otherwise fallback to 16/9
                    const aw = parseInt(iframe.getAttribute('width') || iframe.width || '', 10) || 0;
                    const ah = parseInt(iframe.getAttribute('height') || iframe.height || '', 10) || 0;
                    if (aw > 0 && ah > 0) {
                        frame.style.setProperty('aspect-ratio', `${aw} / ${ah}`);
                    } else if (iframe.dataset && iframe.dataset.aspect) {
                        frame.style.setProperty('aspect-ratio', iframe.dataset.aspect);
                    } else {
                        frame.style.setProperty('aspect-ratio', '16 / 9');
                    }
                    iframe.style.width = '100%';
                    iframe.style.height = '100%';
                    iframe.style.display = 'block';
                    window.requestAnimationFrame(updateHeroHeight);
                } else {
                    // no media -> remove explicit ratio to let layout flow
                    frame.style.removeProperty('aspect-ratio');
                    window.requestAnimationFrame(updateHeroHeight);
                }
            });
            window.requestAnimationFrame(() => {
                updateHeroNav();
                updateHeroHeight();
            });
        }

        // run once and on resize to adapt frames to content
        adaptHeroFrames();
        window.addEventListener('resize', adaptHeroFrames);

        // adjust the .hero section height to match the currently visible slide
        function updateHeroHeight() {
            if (!heroCarousel || !heroEl) return;
            const slides = heroCarousel.querySelectorAll('.hero-slide');
            if (!slides || slides.length === 0) return;
            const carouselRect = heroCarousel.getBoundingClientRect();
            const centerX = carouselRect.left + (carouselRect.width / 2);
            let active = slides[0];
            let minDist = Infinity;
            slides.forEach(slide => {
                const rect = slide.getBoundingClientRect();
                if (rect.left <= centerX && rect.right >= centerX) {
                    active = slide;
                    minDist = 0;
                    return;
                }
                const mid = (rect.left + rect.right) / 2;
                const d = Math.abs(mid - centerX);
                if (d < minDist) { minDist = d; active = slide; }
            });

            const slideHeight = active.offsetHeight || active.getBoundingClientRect().height || 0;
            const style = window.getComputedStyle(heroEl);
            const pt = parseFloat(style.paddingTop) || 0;
            const pb = parseFloat(style.paddingBottom) || 0;
            const desired = Math.round(slideHeight);
            if (desired > 0) {
                heroCarousel.style.height = desired + 'px';
                heroEl.style.minHeight = Math.round(desired + pt + pb) + 'px';
            }
        }

        // Insight tracking for key interactions (deferred to avoid blocking main thread).
        function sendInsight(name) {
            if (!name) return;
            if ('requestIdleCallback' in window) {
                requestIdleCallback(() => {
                    fetch('status.php?event=' + encodeURIComponent(name), {
                        method: 'POST',
                        keepalive: true,
                    }).catch(() => {});
                });
            } else {
                setTimeout(() => {
                    fetch('status.php?event=' + encodeURIComponent(name), {
                        method: 'POST',
                        keepalive: true,
                    }).catch(() => {});
                }, 100);
            }
        }

        window.addEventListener('load', () => {
            sendInsight('home_view');
            sendInsight('agenda_view');
        });

        document.querySelectorAll('[data-insight]').forEach(el => {
            el.addEventListener('click', () => sendInsight(el.getAttribute('data-insight')));
        });

    </script>
    <script src="js/adhan.umd.min.js"></script>
    <script>
    (function () {
      var LAT = 3.563484742344483, LNG = 98.65425015257077; // Masjid Baitul Ilmi Polmed (sekretariat UKMI)
      var ORDER = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];
      var root = document.getElementById('footer-sholat');
      if (!root) return;
      var times = null, dayKey = '', timer = null;

      function pad(n) { return (n < 10 ? '0' : '') + n; }
      function fmt(d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }

      function buildTimes(now) {
        if (!window.adhan) return false;
        var coords = new window.adhan.Coordinates(LAT, LNG);
        var p = window.adhan.CalculationMethod.Singapore(); // Kemenag/NU
        // ihtiyati +2 menit (default al-waqt)
        p.adjustments.fajr = p.adjustments.dhuhr = p.adjustments.asr = p.adjustments.maghrib = p.adjustments.isha = 2;
        times = new window.adhan.PrayerTimes(coords, now, p);
        dayKey = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
        return true;
      }

      function renderTimes() {
        ORDER.forEach(function (k) {
          var el = root.querySelector('[data-time="' + k + '"]');
          if (el && times && times[k] instanceof Date) el.textContent = fmt(times[k]);
        });
      }

      function tick() {
        var now = new Date();
        var k = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
        if (!times || k !== dayKey) { if (!buildTimes(now)) return; renderTimes(); }

        // cari sholat berikutnya hari ini
        var nextKey = null, nextTime = null;
        for (var i = 0; i < ORDER.length; i++) {
          var t = times[ORDER[i]];
          if (t && t > now) { nextKey = ORDER[i]; nextTime = t; break; }
        }
        // setelah Isya -> Subuh esok hari
        if (!nextTime && window.adhan) {
          var tomorrow = new Date(now.getTime() + 86400000);
          var coords = new window.adhan.Coordinates(LAT, LNG);
          var p2 = window.adhan.CalculationMethod.Singapore();
          p2.adjustments.fajr = 2;
          nextKey = 'fajr';
          nextTime = new window.adhan.PrayerTimes(coords, tomorrow, p2).fajr;
        }

        // highlight sholat berikutnya
        root.querySelectorAll('.fs-item').forEach(function (el) {
          el.classList.toggle('is-active', el.getAttribute('data-prayer') === nextKey);
        });

        // countdown ke sholat berikutnya
        var nextEl = document.getElementById('fs-next');
        if (nextEl && nextTime) {
          var diff = nextTime - now;
          if (diff < 0) diff = 0;
          var h = Math.floor(diff / 3600000);
          var m = Math.floor((diff % 3600000) / 60000);
          var s = Math.floor((diff % 60000) / 1000);
          var label = root.querySelector('[data-prayer="' + nextKey + '"] .fs-name');
          nextEl.hidden = false;
          nextEl.textContent = '→ ' + (label ? label.textContent : '') + ' ' + pad(h) + ':' + pad(m) + ':' + pad(s);
        }
      }

      var adhanTries = 0;
      function start() {
        if (!window.adhan) {
          if (++adhanTries > 20) return; // menyerah setelah ~6 detik jika library gagal dimuat
          setTimeout(start, 300);
          return;
        }
        tick();
        if (!timer) timer = setInterval(tick, 1000);
      }

      start();
    })();
    </script>
</body>
</html>