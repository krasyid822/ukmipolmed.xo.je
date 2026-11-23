<?php
require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/store.php';
// Beranda berubah lewat admin; pakai cache singkat + revalidasi agar tetap segar.
if (!headers_sent()) {
    header('Cache-Control: public, max-age=60, must-revalidate');
}

$agendaDefaults = [
    'tag' => 'Agenda terdekat',
    'title' => 'Pelantikan Ketum 2026-2027',
    'detail' => 'Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI POLMED 2026-2027.',
    'start_date' => '',
    'end_date' => '',
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
$shortcutCardDefaults = [];
$heroCardDefaults = [
    ['type' => 'image', 'title' => 'Logo UKMI POLMED', 'caption' => 'Swipe kartu lain atau edit tampilannya dari admin.', 'content' => '/logo-ukmi.png', 'alt' => 'Logo UKMI POLMED'],
    ['type' => 'image', 'title' => 'Highlight kegiatan', 'caption' => 'Konten bisa diisi gambar atau embed code.', 'content' => '/palestine.gif', 'alt' => 'Ilustrasi highlight kegiatan UKMI POLMED'],
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
        if (!empty($defaultData['shortcut_cards']) && is_array($defaultData['shortcut_cards'])) {
            $shortcutCardDefaults = $defaultData['shortcut_cards'];
        }
    }
}

$config = (function_exists('data_store_is_sharded') && data_store_is_sharded())
    ? array_merge(data_store_meta_read(), data_store_site_read())
    : store_read_json($configPath);
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

if (empty($agendas)) {
    $agendas[] = $agendaDefaults;
}

$seededShortcutCards = null;
require_once __DIR__ . '/shortcut_migration.php';
$seededShortcutCards = shortcut_ensure_seeded($configPath);

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
            'avatar' => trim((string) ($item['avatar'] ?? '')),
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
            'thumb' => trim((string) ($item['thumb'] ?? '')),
        ];
        if (count($tmpDocs) >= 10) break;
    }
    if (!empty($tmpDocs)) {
        $docs = $tmpDocs;
    }
}

$shortcutCards = [];
$shortcutSource = (isset($config) && is_array($config) && array_key_exists('shortcut_cards', $config) && is_array($config['shortcut_cards']))
    ? $config['shortcut_cards']
    : ($seededShortcutCards ?? $shortcutCardDefaults);
foreach ($shortcutSource as $cardItem) {
    if (!is_array($cardItem)) continue;
    $slides = [];
    foreach (($cardItem['slides'] ?? []) as $slideItem) {
        if (!is_array($slideItem)) continue;
        $tag = trim((string) ($slideItem['tag'] ?? ''));
        $title = trim((string) ($slideItem['title'] ?? ''));
        $detail = trim((string) ($slideItem['detail'] ?? ''));
        if ($tag === '' && $title === '' && $detail === '') continue;
        $slides[] = ['tag' => $tag, 'title' => $title, 'detail' => $detail];
        if (count($slides) >= 8) break;
    }
    if (empty($slides)) continue;
    $shortcutCards[] = ['slides' => $slides];
    if (count($shortcutCards) >= 8) break;
}

function doc_thumbnail_url(array $doc): string
{
    $thumb = trim((string) ($doc['thumb'] ?? ''));
    if ($thumb !== '') {
        return $thumb;
    }
    $url = trim((string) ($doc['url'] ?? ''));
    if ($url === '' || $url === '#') {
        return '';
    }
    return 'https://s0.wp.com/mshots/v1/' . rawurlencode($url) . '?w=800&h=500';
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

$canonicalHost = preg_replace('/:\\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id'));
$canonicalBase = 'https://' . $canonicalHost;
$canonicalPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($canonicalPath === '/index.php' || $canonicalPath === '/index') {
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
    <link rel="icon" type="image/png" sizes="192x192" href="/logo-ukmi.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/logo-ukmi.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/logo-ukmi.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/logo-ukmi.png">
    <link rel="shortcut icon" href="/logo-ukmi.png">
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
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => $canonicalBase . '/blog?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
            'url' => $homeUrl,
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $logoAbsoluteUrl,
            ],
            'sameAs' => [
                'https://www.instagram.com/ukmipolmed/',
                'https://www.youtube.com/c/UKMIPolmedTV',
                'https://web.facebook.com/ukmipolmedmedan/',
                'https://x.com/ukmipolmed',
                'https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan',
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
        $rawStart = trim((string) ($agendaItem['start_date'] ?? ''));
        $startTs = $rawStart !== '' ? strtotime($rawStart) : false;
        if ($startTs === false) {
            continue;
        }
        $rawEnd = trim((string) ($agendaItem['end_date'] ?? ''));
        $endTs = $rawEnd !== '' ? strtotime($rawEnd) : false;
        if ($endTs === false || $endTs <= $startTs) {
            $endTs = $startTs + 7200;
        }
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
            'startDate' => date('c', $startTs),
            'endDate' => date('c', $endTs),
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'IDR',
                'url' => $registration['url'] ?? $homeUrl,
                'availability' => 'https://schema.org/InStock',
                'validFrom' => date('c', $startTs),
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
    <?php require __DIR__ . '/components/beranda-styles.php'; ?>
</head>
<body>
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-WS2XLWDK"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <?php require __DIR__ . '/components/loading-overlay.php'; ?>
    <div class="glow" aria-hidden="true"></div>
    <?php include __DIR__ . '/header.php'; ?>

    <?php require __DIR__ . '/components/beranda-main.php'; ?>

    <?php require __DIR__ . '/components/beranda-scripts.php'; ?>
    <?php require __DIR__ . '/components/beranda-adhan.php'; ?>
</body>
</html>