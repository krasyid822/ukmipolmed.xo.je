<?php
require_once __DIR__ . '/authors_helper.php';

$u = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
$isAdmin = (strcasecmp($u, 'admin') === 0 || $u === '');

$configPath = __DIR__ . '/data.json';
$config = store_read_json($configPath);
if (!is_array($config)) $config = [];
$yelYel = (string) ($config['yel_yel'] ?? '');
$all = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];

if ($isAdmin) {
    $author = author_display('', $yelYel);
    $uid = '';
} else {
    $a = authors_find_by_username($u);
    if (!$a) {
        http_response_code(404);
        $author = null;
        $uid = '';
    } else {
        $author = author_display($a['id'] ?? '', $yelYel);
        $uid = (string) ($a['id'] ?? '');
    }
}

$posts = [];
if ($author !== null) {
    if ($isAdmin) {
        foreach ($all as $p) {
            if (trim((string) ($p['author_id'] ?? '')) === '' && empty($p['archived'])) $posts[] = $p;
        }
    } else {
        foreach ($all as $p) {
            if ($uid !== '' && trim((string) ($p['author_id'] ?? '')) === $uid && empty($p['archived'])) $posts[] = $p;
        }
    }
    usort($posts, fn($x, $y) => strcmp((string) ($y['created_at'] ?? ''), (string) ($x['created_at'] ?? '')));
}

$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id'));
$base = (PHP_SAPI === 'cli-server') ? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000')) : 'https://ukmipolmed.my.id';
$canonicalUrl = $base . ($isAdmin ? '/penulis/admin' : '/penulis/' . rawurlencode($u));
$title = $author ? ($author['pen_name'] . ' – Penulis Blog UKMI POLMED') : 'Penulis tidak ditemukan';
$metaDesc = $author
    ? (trim((string) ($author['bio'] ?? '')) !== ''
        ? mb_substr(preg_replace('/\s+/u', ' ', trim((string) $author['bio'])), 0, 155, 'UTF-8')
        : 'Profil dan daftar tulisan ' . $author['pen_name'] . ' di Blog UKMI Politeknik Negeri Medan.')
    : 'Profil penulis tidak tersedia.';
$metaRobots = $author ? 'index, follow' : 'noindex, follow';
$metaImage = $author ? $author['avatar'] : '/logo-ukmi.png';
$absMetaImage = (strpos($metaImage, 'http') === 0) ? $metaImage : ($base . $metaImage);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="robots" content="<?php echo $metaRobots; ?>">
<link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($author !== null): ?>
<link rel="alternate" hreflang="id" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
<meta property="og:locale" content="id_ID">
<meta property="og:site_name" content="UKMI POLMED">
<meta property="og:title" content="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:type" content="profile">
<meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:image" content="<?php echo htmlspecialchars($absMetaImage, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="twitter:description" content="<?php echo htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="twitter:image" content="<?php echo htmlspecialchars($absMetaImage, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($author !== null): ?>
<script type="application/ld+json"><?php echo json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'mainEntity' => [
        '@type' => 'Person',
        'name' => $author['pen_name'],
        'description' => $metaDesc,
        'image' => $absMetaImage,
        'url' => $canonicalUrl,
        'sameAs' => array_values(array_filter((array) ($author['socials'] ?? []), fn($s) => preg_match('#^https?://#i', (string) $s))),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
<script type="application/ld+json"><?php echo json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => $base . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $base . '/blog'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $author['pen_name'], 'item' => $canonicalUrl],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?></script>
<?php endif; ?>
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<link rel="stylesheet" href="/style.css">
</head>
<body class="page-penulis">
<?php require __DIR__ . '/components/loading-overlay.php'; ?>
<div class="wrap">
  <div class="top"><a href="/">← Beranda</a><span style="color:var(--muted)">·</span><a href="/blog">Blog</a></div>
  <?php if ($author === null): ?>
    <div class="card"><h1>Penulis tidak ditemukan</h1><p class="bio">Profil penulis tidak tersedia.</p></div>
  <?php else: ?>
    <div class="card">
      <div class="profile">
        <img class="avatar" src="<?php echo htmlspecialchars($author['avatar'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar <?php echo htmlspecialchars($author['pen_name'], ENT_QUOTES, 'UTF-8'); ?>">
        <div>
          <h1><?php echo htmlspecialchars($author['pen_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
          <?php if (($author['bio'] ?? '') !== ''): ?><p class="bio"><?php echo nl2br(htmlspecialchars($author['bio'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
          <?php if (!empty($author['socials'])): ?>
            <div class="social" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
              <?php foreach ($author['socials'] as $s): if (!preg_match('#^https?://#i', (string) $s)) continue; $pf = social_platform($s); ?>
                <a href="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener nofollow" title="<?php echo htmlspecialchars($pf, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($pf, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;border:1px solid var(--stroke);color:var(--text);text-decoration:none;">
                  <?php echo social_icon_svg($pf); ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <h2 style="font-size:16px;margin:24px 0 10px;color:var(--muted);">Tulisan (<?php echo count($posts); ?>)</h2>
    <div class="posts">
      <?php if (empty($posts)): ?>
        <p class="empty">Belum ada tulisan.</p>
      <?php else: foreach ($posts as $p): ?>
        <a class="pcard" href="/blog/<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>">
          <h3><?php echo htmlspecialchars((string) ($p['title'] ?? 'Tanpa judul'), ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="m"><?php echo htmlspecialchars((string) (($p['created_at'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div>
        </a>
      <?php endforeach; endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
