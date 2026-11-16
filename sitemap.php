<?php
header('Content-Type: application/xml; charset=utf-8');

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
        return (new DateTime($date))->format('Y-m-d');
    } catch (Throwable $e) {
        return date('Y-m-d');
    }
}

$today = date('Y-m-d');
$urls = [];

$urls[] = ['loc' => $baseUrl . '/', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '1.0'];
$urls[] = ['loc' => $baseUrl . '/blog.php', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'];
$urls[] = ['loc' => $baseUrl . '/blog/arsip', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'];

$configPath = __DIR__ . '/data.json';
if (is_readable($configPath)) {
    $content = @file_get_contents($configPath);
    if ($content !== false && strlen($content) > 0) {
        $config = json_decode($content, true);
        if (is_array($config) && !empty($config['posts']) && is_array($config['posts'])) {
            foreach ($config['posts'] as $p) {
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
        }
    }
}

$urls[] = ['loc' => $baseUrl . '/faq.php', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.5'];
$urls[] = ['loc' => $baseUrl . '/status.php', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '0.3'];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo '  <url>' . "\n";
    echo '    <loc>' . h($u['loc']) . '</loc>' . "\n";
    echo '    <lastmod>' . h($u['lastmod']) . '</lastmod>' . "\n";
    echo '    <changefreq>' . h($u['changefreq']) . '</changefreq>' . "\n";
    echo '    <priority>' . h($u['priority']) . '</priority>' . "\n";
    echo '  </url>' . "\n";
}
echo '</urlset>' . "\n";
