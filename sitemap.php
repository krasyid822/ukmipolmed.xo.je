<?php
ini_set('memory_limit', '256M');
header('Content-Type: application/xml; charset=utf-8');

$configPath = __DIR__ . '/data.json';
$defaultPath = __DIR__ . '/default.json';

$posts = [];
if (is_readable($configPath)) {
    $content = @file_get_contents($configPath);
    if ($content !== false) {
        $config = json_decode($content, true);
        if (is_array($config) && !empty($config['posts']) && is_array($config['posts'])) {
            $posts = $config['posts'];
        }
    }
}
if (empty($posts) && is_readable($defaultPath)) {
    $content = @file_get_contents($defaultPath);
    if ($content !== false) {
        $tmp = json_decode($content, true);
        if (is_array($tmp) && !empty($tmp['posts']) && is_array($tmp['posts'])) {
            $posts = $tmp['posts'];
        }
    }
}

$baseUrl = 'https://ukmipolmed.my.id';

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lastmod($post)
{
    $date = $post['updated_at'] ?? $post['created_at'] ?? '';
    if ($date === '') {
        return date('Y-m-d');
    }
    try {
        $dt = new DateTime($date);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return date('Y-m-d');
    }
}

$today = date('Y-m-d');
$urls = [];

$urls[] = ['loc' => $baseUrl . '/', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '1.0'];
$urls[] = ['loc' => $baseUrl . '/blog.php', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'];
$urls[] = ['loc' => $baseUrl . '/blog/arsip', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'];

foreach ($posts as $p) {
    $slug = trim((string) ($p['slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    if (!empty($p['archived'])) {
        continue;
    }
    $urls[] = [
        'loc' => $baseUrl . '/blog/' . $slug,
        'lastmod' => lastmod($p),
        'changefreq' => 'monthly',
        'priority' => '0.6',
    ];
}

$urls[] = ['loc' => $baseUrl . '/faq.php', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.5'];
$urls[] = ['loc' => $baseUrl . '/status.php', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '0.3'];
?>
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?php echo h($u['loc']); ?></loc>
    <lastmod><?php echo h($u['lastmod']); ?></lastmod>
    <changefreq><?php echo h($u['changefreq']); ?></changefreq>
    <priority><?php echo h($u['priority']); ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
