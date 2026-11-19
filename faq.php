<?php
require_once __DIR__ . '/store.php';
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
$configData = (function_exists('data_store_is_sharded') && data_store_is_sharded())
    ? ['faqs' => data_store_faqs_read()]
    : store_read_json($configPath);
if (is_array($configData) && !empty($configData['faqs'])) {
    $faqItems = mergeFaqItems($faqItems, normalizeFaqItems($configData['faqs'], $faqFallbackTimestamp));
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
$indexUrl = $canonicalBase . '/';
$faqUrl = $canonicalBase . '/faq';
$faqIdUrl = $faqUrl . '#faq';

$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'name' => 'FAQ UKMI POLMED',
    'description' => 'Kumpulan pertanyaan dan jawaban resmi seputar UKMI POLMED, mulai dari AD/ART, STKO, GBHK, hingga informasi organisasi yang sering ditanyakan.',
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
            'name' => 'FAQ UKMI POLMED',
            'item' => $faqUrl,
        ],
    ],
];

$webPageSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'FAQ UKMI POLMED',
    'url' => $faqUrl,
    'description' => 'Kumpulan pertanyaan dan jawaban resmi seputar UKMI POLMED, AD/ART, STKO, GBHK, dan struktur organisasi.',
    'inLanguage' => 'id-ID',
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => 'UKMI POLMED',
        'url' => $indexUrl,
    ],
    'mainEntity' => [
        '@type' => 'FAQPage',
        '@id' => $faqIdUrl,
    ],
];

$pageTitle = 'FAQ UKMI POLMED: AD/ART, STKO, GBHK, dan Info Organisasi';
$pageDescription = 'Cari jawaban resmi seputar UKMI POLMED, termasuk AD/ART, STKO, GBHK, struktur divisi, dan informasi organisasi yang paling sering ditanyakan.';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo e($pageTitle); ?></title>
    <meta name="description" content="<?php echo e($pageDescription); ?>">
    <meta name="keywords" content="FAQ UKMI POLMED, AD/ART UKMI POLMED, STKO UKMI POLMED, GBHK UKMI POLMED, organisasi UKMI, pertanyaan UKMI POLMED">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="<?php echo e($faqUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="UKMI POLMED">
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
    <?php require __DIR__ . '/components/faq-styles.php'; ?>
</head>
<body>
    <?php require __DIR__ . '/components/loading-overlay.php'; ?>
    <div class="wrap">
        <div class="topbar">
            <a href="/">Kembali ke beranda</a>
            <a href="/blog">Buka blog</a>
        </div>

        <div class="panel layout">
            <aside class="sidebar">
                <h1>FAQ UKMI POLMED</h1>
                <p>Pertanyaan dan jawaban resmi seputar UKMI POLMED, termasuk AD/ART, STKO, GBHK, struktur divisi, dan informasi organisasi yang paling sering dicari.</p>
                <p style="margin-top:-6px; color: var(--muted);">Pilih pertanyaan di bawah untuk melihat jawaban, atau cari kata kunci tertentu.</p>
                <input id="faq-search" class="search" type="search" placeholder="Cari pertanyaan atau jawaban..." autocomplete="off">
                <div class="faq-nav" id="faq-nav" aria-label="Daftar pertanyaan FAQ">
                    <?php foreach ($faqItems as $item): ?>
                        <a class="faq-link" href="#<?php echo e($item['slug']); ?>" data-slug="<?php echo e($item['slug']); ?>" data-search="<?php echo e(strtolower($item['question'] . ' ' . $item['answer'])); ?>"><?php echo e($item['question']); ?></a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <main class="content" id="faq-content" aria-label="Isi FAQ UKMI POLMED">
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

    <?php require __DIR__ . '/components/faq-scripts.php'; ?>
</body>
</html>