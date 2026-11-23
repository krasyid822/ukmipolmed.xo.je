<?php
require_once __DIR__ . '/store.php';
// Sitemap dinamis. Dapat di-include sebagai library (define SITEMAP_LIBRARY)
// agar endpoint BI bisa menghitung isi sitemap tanpa mencetak XML.

const SITEMAP_BASE_URL = 'https://ukmipolmed.my.id';

function sitemap_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sitemap_lastmod($post)
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

function sitemap_urls($baseUrl = SITEMAP_BASE_URL, $storePath = null)
{
    $baseUrl = rtrim((string) $baseUrl, '/');
    $storePath = $storePath ?: __DIR__ . '/data.json';
    $today = date('Y-m-d');
    $urls = [];

    $urls[] = ['loc' => $baseUrl . '/', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '1.0'];
    $urls[] = ['loc' => $baseUrl . '/blog', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.8'];

    $posts = [];
    if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
        $posts = data_store_posts_index_read();
    } else {
        $config = function_exists('store_read_json') ? store_read_json($storePath) : null;
        if (is_array($config) && !empty($config['posts']) && is_array($config['posts'])) {
            $posts = $config['posts'];
        }
    }
    $activePostCount = 0;
    foreach ($posts as $p) {
        if (!is_array($p)) continue;
        $slug = trim((string) ($p['slug'] ?? ''));
        if ($slug === '' || strlen($slug) < 3 || !preg_match('/^[a-z0-9-]+$/', $slug) || !empty($p['archived'])) {
            continue;
        }
        $activePostCount++;
        $urls[] = [
            'loc' => $baseUrl . '/blog/' . $slug,
            'lastmod' => sitemap_lastmod($p),
            'changefreq' => 'monthly',
            'priority' => '0.6',
        ];
    }
    if ($activePostCount > 10) {
        $urls[] = ['loc' => $baseUrl . '/blog/arsip', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.7'];
    }

    $urls[] = ['loc' => $baseUrl . '/faq', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.5'];
    $urls[] = ['loc' => $baseUrl . '/penulis/admin', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.4'];
    if (is_readable(__DIR__ . '/authors_helper.php')) {
        require_once __DIR__ . '/authors_helper.php';
        if (function_exists('authors_all')) {
            foreach (authors_all() as $a) {
                $uname = trim((string) ($a['username'] ?? ''));
                if ($uname !== '') {
                    $urls[] = ['loc' => $baseUrl . '/penulis/' . rawurlencode($uname), 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.4'];
                }
            }
        }
    }

    return $urls;
}

function sitemap_xml(array $urls)
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        $xml .= '  <url>' . "\n";
        $xml .= '    <loc>' . sitemap_h($u['loc']) . '</loc>' . "\n";
        $xml .= '    <lastmod>' . sitemap_h($u['lastmod']) . '</lastmod>' . "\n";
        $xml .= '    <changefreq>' . sitemap_h($u['changefreq']) . '</changefreq>' . "\n";
        $xml .= '    <priority>' . sitemap_h($u['priority']) . '</priority>' . "\n";
        $xml .= '  </url>' . "\n";
    }
    $xml .= '</urlset>' . "\n";

    return $xml;
}

if (!defined('SITEMAP_LIBRARY')) {
    header('Content-Type: application/xml; charset=utf-8');
    echo sitemap_xml(sitemap_urls(SITEMAP_BASE_URL));
}
