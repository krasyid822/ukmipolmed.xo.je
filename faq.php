<?php
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function makeSlug($text)
{
    $text = strtolower((string) $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string) $text, '-');
    if ($text === '') {
        $text = 'faq-' . substr(sha1((string) microtime(true)), 0, 8);
    }
    return $text;
}

function normalizeFaqTimestamp($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }

    return date('c', $ts);
}

function formatFaqTimestamp($value)
{
    $ts = strtotime((string) $value);
    if ($ts === false) {
        return '-';
    }

    return date('d M Y H:i', $ts) . ' WIB';
}

function normalizeFaqItems($items, $fallbackTimestamp = '')
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];
    $used = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $question = trim((string) ($item['question'] ?? $item['title'] ?? $item['q'] ?? ''));
        $answer = trim((string) ($item['answer'] ?? $item['body'] ?? $item['a'] ?? ''));
        $slug = trim((string) ($item['slug'] ?? ''));
        $createdAt = normalizeFaqTimestamp($item['written_at'] ?? $item['created_at'] ?? $item['createdAt'] ?? $item['writtenAt'] ?? '');
        $updatedAt = normalizeFaqTimestamp($item['updated_at'] ?? $item['updatedAt'] ?? $item['last_updated'] ?? '');

        if ($question === '' || $answer === '') {
            continue;
        }

        if ($createdAt === '') {
            $createdAt = $fallbackTimestamp;
        }
        if ($updatedAt === '') {
            $updatedAt = $createdAt !== '' ? $createdAt : $fallbackTimestamp;
        }

        if ($slug === '') {
            $slug = makeSlug($question);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $slug = makeSlug($slug);
        }

        $base = $slug;
        $n = 2;
        while (isset($used[$slug])) {
            $slug = $base . '-' . $n;
            $n++;
        }
        $used[$slug] = true;

        $normalized[] = [
            'question' => $question,
            'answer' => $answer,
            'slug' => $slug,
            'written_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    return $normalized;
}

function mergeFaqItems($baseItems, $extraItems)
{
    $merged = [];
    $used = [];

    foreach (array_merge(is_array($baseItems) ? $baseItems : [], is_array($extraItems) ? $extraItems : []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $question = trim((string) ($item['question'] ?? ''));
        $answer = trim((string) ($item['answer'] ?? ''));
        $slug = trim((string) ($item['slug'] ?? ''));
        $writtenAt = trim((string) ($item['written_at'] ?? ''));
        $updatedAt = trim((string) ($item['updated_at'] ?? ''));

        if ($question === '' || $answer === '') {
            continue;
        }

        if ($slug === '') {
            $slug = makeSlug($question);
        }

        $baseSlug = $slug;
        $n = 2;
        while (isset($used[$slug])) {
            $slug = $baseSlug . '-' . $n;
            $n++;
        }
        $used[$slug] = true;

        $merged[] = [
            'question' => $question,
            'answer' => $answer,
            'slug' => $slug,
            'written_at' => $writtenAt,
            'updated_at' => $updatedAt,
        ];
    }

    return $merged;
}

function parseEmbeddedFaq($raw, $fallbackTimestamp = '')
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }

    // Support compact pasted format by normalizing separators and slug markers to line-based blocks.
    $raw = preg_replace('/\s+---\s+/u', "\n---\n", $raw);
    $raw = preg_replace('/\s+slug:\s*([a-z0-9-]+)\b\s*/iu', "\nslug: $1\n", $raw);
    $raw = preg_replace('/\n{3,}/', "\n\n", (string) $raw);

    $items = [];
    $blocks = preg_split('/\r?\n-{3,}\r?\n/', $raw);
    foreach ($blocks as $block) {
        $lines = preg_split('/\r?\n/', trim((string) $block));
        $question = '';
        $answerLines = [];
        $slug = '';
        $writtenAt = '';
        $updatedAt = '';

        foreach ($lines as $line) {
            $line = rtrim((string) $line);
            if ($line === '') {
                if ($question !== '') {
                    $answerLines[] = '';
                }
                continue;
            }

            if ($question === '') {
                $question = trim($line);
                continue;
            }

            if (preg_match('/^slug:\s*(.+)$/i', $line, $m)) {
                $slug = trim((string) $m[1]);
                continue;
            }
            if (preg_match('/^written_at:\s*(.+)$/i', $line, $m)) {
                $writtenAt = trim((string) $m[1]);
                continue;
            }
            if (preg_match('/^updated_at:\s*(.+)$/i', $line, $m)) {
                $updatedAt = trim((string) $m[1]);
                continue;
            }

            $answerLines[] = $line;
        }

        $answer = trim(implode("\n", $answerLines));
        if ($question !== '' && $answer !== '') {
            $items[] = [
                'question' => $question,
                'answer' => $answer,
                'slug' => $slug,
                'written_at' => $writtenAt,
                'updated_at' => $updatedAt,
            ];
        }
    }

    return normalizeFaqItems($items, $fallbackTimestamp);
}

$faqFallbackTimestamp = date('c', @filemtime(__FILE__) ?: time());
$faqItems = [];

$defaultPath = __DIR__ . '/default.json';
if (is_readable($defaultPath)) {
    $defaultData = json_decode((string) file_get_contents($defaultPath), true);
    if (is_array($defaultData) && !empty($defaultData['faqs'])) {
        $faqItems = mergeFaqItems($faqItems, normalizeFaqItems($defaultData['faqs'], $faqFallbackTimestamp));
    }
}

$configPath = __DIR__ . '/data.json';
if (is_readable($configPath)) {
    $configData = json_decode((string) file_get_contents($configPath), true);
    if (is_array($configData) && !empty($configData['faqs'])) {
        $faqItems = mergeFaqItems($faqItems, normalizeFaqItems($configData['faqs'], $faqFallbackTimestamp));
    }
}

if (empty($faqItems)) {
    $self = @file_get_contents(__FILE__);
    if ($self !== false) {
        $start = '<!-- EMBED_FAQ_' . 'START -->';
        $end = '<!-- EMBED_FAQ_' . 'END -->';
        $p1 = strrpos($self, $start);
        $p2 = strrpos($self, $end);
        if ($p1 !== false && $p2 !== false && $p2 > $p1) {
            $embedded = trim(substr($self, $p1 + strlen($start), $p2 - ($p1 + strlen($start))));
            $faqItems = parseEmbeddedFaq($embedded, $faqFallbackTimestamp);
        }
    }
}

$canonicalHost = preg_replace('/:\\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.xo.je'));
$canonicalBase = 'https://' . $canonicalHost;
$indexUrl = $canonicalBase . '/index.php';
$faqUrl = $canonicalBase . '/faq.php';
$faqIdUrl = $faqUrl . '#faq';

$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'name' => 'FAQ UKMI Polmed',
    'description' => 'Kumpulan pertanyaan dan jawaban resmi seputar UKMI Polmed, mulai dari AD/ART, STKO, GBHK, hingga informasi organisasi yang sering ditanyakan.',
    'url' => $faqUrl,
    'inLanguage' => 'id-ID',
    'mainEntity' => [],
];

foreach ($faqItems as $item) {
    $faqSchema['mainEntity'][] = [
        '@type' => 'Question',
        'name' => $item['question'],
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => $item['answer'],
        ],
    ];
}

$faqJsonLd = json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        [
            '@type' => 'ListItem',
            'position' => 1,
            'name' => 'Beranda',
            'item' => $indexUrl,
        ],
        [
            '@type' => 'ListItem',
            'position' => 2,
            'name' => 'FAQ UKMI Polmed',
            'item' => $faqUrl,
        ],
    ],
];

$webPageSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'FAQ UKMI Polmed',
    'url' => $faqUrl,
    'description' => 'Kumpulan pertanyaan dan jawaban resmi seputar UKMI Polmed, AD/ART, STKO, GBHK, dan struktur organisasi.',
    'inLanguage' => 'id-ID',
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => 'UKMI Polmed',
        'url' => $indexUrl,
    ],
    'mainEntity' => [
        '@type' => 'FAQPage',
        '@id' => $faqIdUrl,
    ],
];

$pageTitle = 'FAQ UKMI Polmed: AD/ART, STKO, GBHK, dan Info Organisasi';
$pageDescription = 'Cari jawaban resmi seputar UKMI Polmed, termasuk AD/ART, STKO, GBHK, struktur divisi, dan informasi organisasi yang paling sering ditanyakan.';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo e($pageTitle); ?></title>
    <meta name="description" content="<?php echo e($pageDescription); ?>">
    <meta name="keywords" content="FAQ UKMI Polmed, AD/ART UKMI Polmed, STKO UKMI Polmed, GBHK UKMI Polmed, organisasi UKMI, pertanyaan UKMI Polmed">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="<?php echo e($faqUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="UKMI Polmed">
    <meta property="og:title" content="<?php echo e($pageTitle); ?>">
    <meta property="og:description" content="<?php echo e($pageDescription); ?>">
    <meta property="og:url" content="<?php echo e($faqUrl); ?>">
    <meta property="og:locale" content="id_ID">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo e($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo e($pageDescription); ?>">
    <?php if ($faqJsonLd !== false): ?>
    <script type="application/ld+json"><?php echo $faqJsonLd; ?></script>
    <?php endif; ?>
    <script type="application/ld+json"><?php echo json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <script type="application/ld+json"><?php echo json_encode($webPageSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <style>
        :root {
            --bg: #f5f6f8;
            --panel: #ffffff;
            --panel-soft: #f0f4ff;
            --line: #dce3f0;
            --text: #172132;
            --muted: #52627a;
            --accent: #0b5fff;
            --accent-soft: #dbe7ff;
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #eef3ff 0%, var(--bg) 260px);
            color: var(--text);
            min-height: 100vh;
        }

        .wrap {
            max-width: none;
            margin: 0 auto;
            padding: 0;
        }

        .topbar {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 0;
            padding: 14px 16px;
        }

        .topbar a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 0;
            box-shadow: 0 8px 24px rgba(17, 35, 64, 0.08);
            overflow: hidden;
        }

        .layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            min-height: calc(100vh - 60px);
        }

        .sidebar {
            border-right: 1px solid var(--line);
            background: var(--panel-soft);
            padding: 16px;
            min-height: inherit;
            display: flex;
            flex-direction: column;
        }

        .sidebar h1 {
            margin: 0 0 8px;
            font-size: 20px;
        }

        .sidebar p {
            margin: 0 0 14px;
            font-size: 14px;
            color: var(--muted);
            line-height: 1.5;
        }

        .search {
            width: 100%;
            border: 1px solid #c9d6f0;
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 14px;
            margin-bottom: 12px;
            background: #fff;
        }

        .faq-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 0 1 auto;
            min-height: 180px;
            max-height: clamp(220px, calc(100vh - 260px), 760px);
            overflow: auto;
            padding-right: 4px;
        }

        .faq-link {
            display: block;
            border: 1px solid #cfd9f2;
            border-radius: 10px;
            padding: 10px 11px;
            text-decoration: none;
            color: #1e2a3f;
            background: #fff;
            line-height: 1.4;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
            touch-action: manipulation;
        }

        .faq-link.active {
            background: var(--accent-soft);
            border-color: #96b5ff;
            color: #11367f;
            font-weight: 600;
        }

        .faq-link.hidden,
        .faq-item.hidden {
            display: none;
        }

        .content {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .empty {
            padding: 14px;
            border: 1px dashed #bfd0f8;
            border-radius: 10px;
            color: #385188;
            background: #f5f9ff;
            font-size: 14px;
        }

        .faq-item {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            background: #fff;
            display: none;
        }

        .faq-item.active {
            display: block;
        }

        .faq-item h2 {
            margin: 0 0 10px;
            font-size: 23px;
            line-height: 1.28;
        }

        .faq-meta {
            margin-bottom: 10px;
            font-size: 12px;
            color: var(--muted);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .faq-answer {
            color: var(--text);
            line-height: 1.7;
            font-size: 16px;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .sidebar {
                border-right: 0;
                border-bottom: 1px solid var(--line);
                min-height: auto;
            }

            .faq-nav {
                max-height: 240px;
            }

            .faq-item h2 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <a href="index.php">Kembali ke beranda</a>
            <a href="blog.php">Buka blog</a>
        </div>

        <div class="panel layout">
            <aside class="sidebar">
                <h1>FAQ UKMI Polmed</h1>
                <p>Pertanyaan dan jawaban resmi seputar UKMI Polmed, termasuk AD/ART, STKO, GBHK, struktur divisi, dan informasi organisasi yang paling sering dicari.</p>
                <p style="margin-top:-6px; color: var(--muted);">Pilih pertanyaan di bawah untuk melihat jawaban, atau cari kata kunci tertentu.</p>
                <input id="faq-search" class="search" type="search" placeholder="Cari pertanyaan atau jawaban..." autocomplete="off">
                <div class="faq-nav" id="faq-nav" aria-label="Daftar pertanyaan FAQ">
                    <?php foreach ($faqItems as $item): ?>
                        <a class="faq-link" href="#<?php echo e($item['slug']); ?>" data-slug="<?php echo e($item['slug']); ?>" data-search="<?php echo e(strtolower($item['question'] . ' ' . $item['answer'])); ?>"><?php echo e($item['question']); ?></a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <main class="content" id="faq-content" aria-label="Isi FAQ UKMI Polmed">
                <div class="empty" id="empty-state" style="display:none;">Tidak ada FAQ yang cocok dengan pencarianmu.</div>
                <?php foreach ($faqItems as $item): ?>
                    <article class="faq-item" id="faq-item-<?php echo e($item['slug']); ?>" data-slug="<?php echo e($item['slug']); ?>" data-search="<?php echo e(strtolower($item['question'] . ' ' . $item['answer'])); ?>">
                        <h2 id="<?php echo e($item['slug']); ?>"><?php echo e($item['question']); ?></h2>
                        <div class="faq-meta">
                            <span>Ditulis: <?php echo e(formatFaqTimestamp($item['written_at'] ?? '')); ?></span>
                            <span>Diupdate: <?php echo e(formatFaqTimestamp($item['updated_at'] ?? '')); ?></span>
                        </div>
                        <div class="faq-answer"><?php echo nl2br(e($item['answer'])); ?></div>
                    </article>
                <?php endforeach; ?>
            </main>
        </div>
    </div>

    <script>
    (function () {
        const links = Array.from(document.querySelectorAll('.faq-link'));
        const items = Array.from(document.querySelectorAll('.faq-item'));
        const searchInput = document.getElementById('faq-search');
        const emptyState = document.getElementById('empty-state');

        const getItem = (slug) => document.getElementById('faq-item-' + slug);
        const nav = document.getElementById('faq-nav');

        const visibleLinks = () => links.filter((link) => !link.classList.contains('hidden'));
        const visibleItems = () => items.filter((item) => !item.classList.contains('hidden'));

        const activate = (slug, updateHash = true) => {
            if (!slug) return;
            const target = getItem(slug);
            if (!target || target.classList.contains('hidden')) return;

            items.forEach((item) => item.classList.toggle('active', item.dataset.slug === slug));
            links.forEach((link) => link.classList.toggle('active', link.dataset.slug === slug));

            const activeLink = links.find((link) => link.dataset.slug === slug);
            if (activeLink) {
                activeLink.scrollIntoView({ block: 'nearest' });
            }
            target.scrollIntoView({ block: 'start', behavior: 'smooth' });

            if (updateHash) {
                history.replaceState(null, '', '#' + encodeURIComponent(slug));
            }
        };

        const filterFaq = (query) => {
            const q = (query || '').toLowerCase().trim();
            links.forEach((link) => {
                const searchable = (link.dataset.search || '').toLowerCase();
                const match = q === '' || searchable.indexOf(q) !== -1;
                link.classList.toggle('hidden', !match);
            });

            items.forEach((item) => {
                const searchable = (item.dataset.search || '').toLowerCase();
                const match = q === '' || searchable.indexOf(q) !== -1;
                item.classList.toggle('hidden', !match);
            });

            const hasVisible = visibleItems().length > 0;
            emptyState.style.display = hasVisible ? 'none' : 'block';

            const currentActive = document.querySelector('.faq-item.active:not(.hidden)');
            if (!currentActive && hasVisible) {
                const first = visibleLinks()[0];
                if (first) activate(first.dataset.slug, false);
            }
        };

        if (nav) {
            nav.addEventListener('click', (event) => {
                const link = event.target.closest('.faq-link');
                if (!link || !nav.contains(link) || link.classList.contains('hidden')) return;
                event.preventDefault();
                activate(link.dataset.slug, true);
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                filterFaq(searchInput.value);
            });
        }

        const hash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
        filterFaq('');
        if (hash && getItem(hash)) {
            activate(hash, false);
        } else if (links.length > 0) {
            activate(links[0].dataset.slug, false);
        }
    })();
    </script>
</body>
</html>