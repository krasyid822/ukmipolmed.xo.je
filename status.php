<?php
// Insight + health dashboard endpoint. Accepts ?event=slug to increment counters, otherwise returns summary.

require_once __DIR__ . '/s3_helper.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/hosting_storage_helper.php';
require_once __DIR__ . '/maintenance_guard.php';

if (!defined('SITEMAP_LIBRARY')) {
	define('SITEMAP_LIBRARY', true);
}
require_once __DIR__ . '/sitemap.php';

$start = microtime(true);
$storeFile = __DIR__ . '/insights.json';
$todayKey = date('Y-m-d');
$defaultData = [
	'total' => 0,
	'events' => [],
	'history' => [],
	'updated_at' => date('c'),
];

$viewParam = $_GET['view'] ?? '';
$view = ($viewParam === 'json') ? 'json' : 'ui';

// Retensi analytics: cegah insights.cache.json (dan payload S3-nya) tumbuh
// selamanya karena event unik/harian. Angka ini cukup untuk dashboard 14 hari.
const INSIGHTS_MAX_HISTORY_DAYS = 180;
const INSIGHTS_MAX_EVENT_KEYS = 2000;
const INSIGHTS_MAX_DAY_EVENT_KEYS = 500;

function normalizeInsights(array $events, array $history): array
{
    foreach ($events as $k => $v) $events[$k] = (int) $v;
    if (count($events) > INSIGHTS_MAX_EVENT_KEYS) {
        arsort($events);
        $events = array_slice($events, 0, INSIGHTS_MAX_EVENT_KEYS, true);
    }

    ksort($history);
    if (count($history) > INSIGHTS_MAX_HISTORY_DAYS) {
        $history = array_slice($history, -INSIGHTS_MAX_HISTORY_DAYS, null, true);
    }
    foreach ($history as $day => $row) {
        $dayEvents = is_array($row['events'] ?? null) ? $row['events'] : [];
        foreach ($dayEvents as $k => $v) $dayEvents[$k] = (int) $v;
        if (count($dayEvents) > INSIGHTS_MAX_DAY_EVENT_KEYS) {
            arsort($dayEvents);
            $dayEvents = array_slice($dayEvents, 0, INSIGHTS_MAX_DAY_EVENT_KEYS, true);
        }
        $history[$day] = [
            'total' => (int) ($row['total'] ?? 0),
            'events' => $dayEvents,
        ];
    }
    return [$events, $history];
}

function respond(array $data, int $code = 200): void
{
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function loadInsights(string $path, array $fallback): array
{
	$data = store_read_json($path);
	if (!is_array($data)) {
		return $fallback;
	}
	$events = $data['events'] ?? [];
	$history = $data['history'] ?? [];
	[$events, $history] = normalizeInsights(
		is_array($events) ? $events : [],
		is_array($history) ? $history : []
	);
	return [
		'total' => (int) ($data['total'] ?? 0),
		'events' => $events,
		'history' => $history,
		'updated_at' => $data['updated_at'] ?? date('c'),
	];
}

function saveInsights(string $path, array $data): bool
{
	return store_save_json($path, $data);
}

function sumPrefixes(array $events, array $prefixes): int
{
	$total = 0;
	foreach ($events as $name => $count) {
		foreach ($prefixes as $prefix) {
			if (strpos($name, $prefix) === 0) {
				$total += (int) $count;
				break;
			}
		}
	}
	return $total;
}

function buildSummary(array $insights, string $todayKey, string $storeFile, float $start, bool $forceHostingScan = false): array
{
	$top = $insights['events'];
	arsort($top);
	$top = array_slice($top, 0, 10, true);

	$historyDays = array_keys($insights['history']);
	sort($historyDays);
	$recentHistory = [];
	foreach (array_slice($historyDays, -14) as $day) {
		$dayData = $insights['history'][$day] ?? ['total' => 0, 'events' => []];
		$recentHistory[] = [
			'date' => $day,
			'total' => (int) ($dayData['total'] ?? 0),
			'events' => is_array($dayData['events'] ?? null) ? $dayData['events'] : [],
		];
	}

	$blogViewsBySlug = [];
	foreach ($insights['events'] as $name => $count) {
		if (strpos($name, 'blog_view_') === 0) {
			$slug = substr($name, strlen('blog_view_'));
			if ($slug !== '') {
				$blogViewsBySlug[$slug] = (int) $count;
			}
		}
	}

	$ctaDaftarTotal = sumPrefixes($insights['events'], ['cta_daftar', 'cta_daftar_', 'cta_daftar-']);
	$engagement = [
		// CTA non-daftar saja; klik daftar dihitung di panel Conversion agar tidak dobel.
		'cta_clicks' => max(0, sumPrefixes($insights['events'], ['cta_']) - $ctaDaftarTotal),
		'doc_opens' => sumPrefixes($insights['events'], ['doc_']),
		'agenda_views' => sumPrefixes($insights['events'], ['agenda_view']),
	];

	$conversion = [
		'form_clicks' => sumPrefixes($insights['events'], ['cta_daftar', 'cta_daftar_', 'cta_daftar-', 'form_', 'daftar_', 'daftar-']),
		'nav_daftar' => $insights['events']['nav_daftar'] ?? 0,
		'cta_daftar_hero' => $insights['events']['cta_daftar_hero'] ?? 0,
		'cta_daftar_banner' => $insights['events']['cta_daftar_banner'] ?? 0,
	];

	$insightCache = store_cache_actual_file('insights');
	$sizeBytes = store_cache_size('insights');
	$sizeMB = round($sizeBytes / (1024 * 1024), 2);

	// Satu kolam kapasitas: seluruh data runtime mengikuti sisa kuota S3.
	$storagePool = s3_storage_pool(true);

	$sitemapScript = __DIR__ . '/sitemap.php';
	$robotsPath = __DIR__ . '/robots.txt';
	$sitemapUrls = function_exists('sitemap_urls') ? sitemap_urls() : [];
	$sitemapLastmod = '';
	$sitemapPostCount = 0;
	foreach ($sitemapUrls as $u) {
		if (($u['lastmod'] ?? '') > $sitemapLastmod) {
			$sitemapLastmod = $u['lastmod'];
		}
		$loc = rtrim((string) ($u['loc'] ?? ''), '/');
		if (strpos($loc, '/blog/') !== false && substr($loc, -6) !== '/arsip') {
			$sitemapPostCount++;
		}
	}
	$robotsContent = is_readable($robotsPath) ? (string) @file_get_contents($robotsPath) : '';
	$sitemapInfo = [
		'type' => 'dynamic',
		'endpoint' => '/sitemap.xml',
		'script_exists' => file_exists($sitemapScript),
		'script_updated_at' => file_exists($sitemapScript) ? date('c', (int) filemtime($sitemapScript)) : null,
		'url_count' => count($sitemapUrls),
		'blog_post_count' => $sitemapPostCount,
		'lastmod_newest' => $sitemapLastmod !== '' ? $sitemapLastmod : null,
		'legacy_static_xml' => file_exists(__DIR__ . '/sitemap.xml'),
		'declared_in_robots' => (bool) preg_match('~^\s*Sitemap:\s*\S*sitemap\.xml\s*$~mi', $robotsContent),
	];

	$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
		|| (isset($_SERVER['HTTP_CF_VISITOR']) && stripos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false);

	$technical = [
		'insights_file' => [
			'exists' => is_readable(store_cache_actual_file('insights')) || file_exists($storeFile),
			'storage' => 's3',
			'writable' => s3_available(),
			'pointer_exists' => file_exists($storeFile),
			'cache_file' => basename($insightCache),
			'cache_compressed' => substr($insightCache, -3) === '.gz',
			'size_bytes' => $sizeBytes,
			'size_mb' => $sizeMB,
		],
		'robots_txt' => file_exists($robotsPath),
		'sitemap' => $sitemapInfo,
		'https' => $isHttps,
		'runtime_ms' => round((microtime(true) - $start) * 1000, 2),
	];

	// data.json lokal hanyalah pointer; payload sebenarnya di S3 (prefix data/).
	$dataJsonPath = __DIR__ . '/data.json';
	$sharded = function_exists('data_store_is_sharded') && data_store_is_sharded();
	$dataCache = $sharded ? '' : store_cache_actual_file('data');
	$dataPayloadBytes = $sharded ? data_store_cache_size() : store_cache_size('data');
	$dataJsonInfo = [
		'exists' => file_exists($dataJsonPath),
		'readable' => is_readable($dataJsonPath),
		'role' => 'pointer',
		'payload_source' => 's3',
		'sharded' => $sharded,
		'payload_key' => $sharded
			? data_store_key('manifest.json')
			: (function_exists('store_key') ? store_key('data') : null),
		'pointer_bytes' => file_exists($dataJsonPath) ? (int) filesize($dataJsonPath) : 0,
		'cache_file' => $dataCache !== '' ? basename($dataCache) : '',
		'cache_compressed' => $dataCache !== '' && substr($dataCache, -3) === '.gz',
		'size_bytes' => $dataPayloadBytes,
		'size_mb' => round($dataPayloadBytes / (1024*1024), 2),
	];

	// Pemakaian komentar dibaca dari index pecahan, tanpa memuat semua artikel.
	$commentsInfo = [
		'used_bytes' => 0,
		'used_mb' => 0,
	];
	if ($sharded) {
		$used = 0;
		foreach (data_store_posts_index_read() as $row) {
			$used += max(0, (int) ($row['comments_bytes'] ?? 0));
		}
		$commentsInfo['used_bytes'] = $used;
		$commentsInfo['used_mb'] = round($used / (1024*1024), 3);
	} else {
		$cfg = store_read_json($dataJsonPath);
		if (is_array($cfg) && !empty($cfg['posts']) && is_array($cfg['posts'])) {
			$all = [];
			foreach ($cfg['posts'] as $p) {
				$all[] = $p['comments'] ?? [];
			}
			$enc = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($enc !== false) {
				$used = strlen($enc);
				$commentsInfo['used_bytes'] = $used;
				$commentsInfo['used_mb'] = round($used / (1024*1024), 3);
			}
		}
	}

	$uploadsStats = function_exists('s3_uploads_stats') ? s3_uploads_stats() : null;
	$uploadsBytes = $uploadsStats ? (int) $uploadsStats['bytes_used'] : 0;

	$technical['data_json'] = $dataJsonInfo;
	$technical['uploads'] = [
		'storage' => 's3',
		'prefix' => function_exists('s3_prefix') ? s3_prefix() . 'uploads/' : 'uploads/',
		'tracked' => $uploadsStats !== null,
		'file_count' => $uploadsStats ? (int) $uploadsStats['objects'] : 0,
		'size_bytes' => $uploadsBytes,
		'size_mb' => round($uploadsBytes / (1024 * 1024), 2),
		'updated_at' => $uploadsStats['updated_at'] ?? null,
	];
	$technical['comments_storage'] = $commentsInfo;
	if (function_exists('assessments_store_is_sharded') && assessments_store_is_sharded()) {
		$aidx = assessments_store_index_read();
		$responses = 0;
		foreach ($aidx as $a) $responses += max(0, (int) ($a['response_count'] ?? 0));
		$technical['assessments_storage'] = [
			'storage' => 's3',
			'sharded' => true,
			'assessments' => count($aidx),
			'responses' => $responses,
			'cache_bytes' => assessments_store_cache_size(),
		];
	}
	$technical['storage_model'] = [
		'data_json_role' => 'pointer',
		'data_json_location' => 's3',
		'data_prefix' => function_exists('s3_prefix') ? s3_prefix() . 'data/' : 'data/',
		'local_cache' => SEO_CACHE_DIR_NAME . '/',
		'local_cache_max_bytes' => function_exists('seo_cache_max_bytes') ? seo_cache_max_bytes() : null,
		'local_cache_max_file_bytes' => function_exists('seo_cache_max_file_bytes') ? seo_cache_max_file_bytes() : null,
		'local_cache_max_files' => function_exists('seo_cache_config') ? (int) (seo_cache_config()['max_files'] ?? 0) : null,
		'media_source' => 's3',
		'media_limit_source' => 's3_remaining',
		'code_layout' => is_dir(__DIR__ . '/components') ? 'components' : 'flat',
	];
	$technical['storage_pool'] = $storagePool;
	$technical['s3_storage'] = $storagePool ? [
		'bytes_used' => $storagePool['used_bytes'],
		'objects' => $storagePool['objects'],
		'updated_at' => $storagePool['updated_at'],
		'limit_bytes' => $storagePool['limit_bytes'],
		'remaining_bytes' => $storagePool['remaining_bytes'],
		'percent_used' => $storagePool['percent_used'],
	] : null;
	if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
	$isAdminSession = !empty($_SESSION['admin_logged_in']) && ($_SESSION['role'] ?? '') === 'admin';
	$technical['hosting_storage'] = ($isAdminSession && function_exists('hosting_storage_pool'))
		? hosting_storage_pool($forceHostingScan)
		: null;

	$orgSpecific = [
		'agenda_updates' => ($insights['events']['agenda_admin_update'] ?? 0) + ($insights['events']['blog_admin_update'] ?? 0),
		'blog_views_total' => array_sum($blogViewsBySlug),
		'blog_views_by_slug' => $blogViewsBySlug,
		'home_views' => sumPrefixes($insights['events'], ['home_view']),
	];

	$traffic = [
		'lifetime_total' => (int) ($insights['total'] ?? 0),
		'today_total' => (int) (($insights['history'][$todayKey]['total'] ?? 0)),
		'top_events' => $top,
	];

	return [
		'ok' => true,
		'meta' => [
			'generated_at' => date('c'),
			'php_version' => PHP_VERSION,
		],
		'traffic' => $traffic,
		'engagement' => $engagement,
		'conversion' => $conversion,
		'technical' => $technical,
		'org_specific' => $orgSpecific,
		'recent_history' => $recentHistory,
		'history_available_days' => array_keys($insights['history']),
	];
}

$eventRaw = $_GET['event'] ?? $_POST['event'] ?? '';
$event = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $eventRaw);

$insights = loadInsights($storeFile, $defaultData);

if ($event !== '') {
	$insights['events'][$event] = (int) ($insights['events'][$event] ?? 0) + 1;
	$insights['total'] = (int) ($insights['total'] ?? 0) + 1;
	$insights['updated_at'] = date('c');

	if (!isset($insights['history'][$todayKey])) {
		$insights['history'][$todayKey] = ['total' => 0, 'events' => []];
	}
	$insights['history'][$todayKey]['total'] = (int) ($insights['history'][$todayKey]['total'] ?? 0) + 1;
	$insights['history'][$todayKey]['events'][$event] = (int) ($insights['history'][$todayKey]['events'][$event] ?? 0) + 1;
	$eventCount = (int) $insights['events'][$event];
	[$insights['events'], $insights['history']] = normalizeInsights($insights['events'], $insights['history']);

	if (!saveInsights($storeFile, $insights)) {
		respond(['ok' => false, 'error' => 'Cannot persist insight data'], 500);
	}

	respond([
		'ok' => true,
		'event' => $event,
		'count' => $eventCount,
		'total' => $insights['total'],
		'today' => $insights['history'][$todayKey] ?? ['total' => 0, 'events' => []],
		'updated_at' => $insights['updated_at'],
	]);
}

$forceHostingScan = !empty($_GET['storage_refresh'])
	&& function_exists('maintenance_authorized')
	&& maintenance_authorized();
$summary = buildSummary($insights, $todayKey, $storeFile, $start, $forceHostingScan);

if ($view === 'ui') {
	// Simple BI-style dashboard UI.
	header('Content-Type: text/html; charset=utf-8');
	header('X-Robots-Tag: noindex, nofollow');
	?><!doctype html>
	<html lang="id">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="robots" content="noindex, nofollow">
		<title>UKMI POLMED · BI Dashboard</title>
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
		<link rel="stylesheet" href="/style.css">
	</head>
	<body class="page-status">
		<?php require __DIR__ . '/components/loading-overlay.php'; ?>
		<main>
			<div class="cards-2">
				<div class="panel">
					<h3><span class="pill">Traffic</span> Lifetime & Harian</h3>
					<div class="kv" id="kv-traffic"></div>
				</div>
				<div class="panel">
					<h3><span class="pill">Engagement</span> Interaksi</h3>
					<div class="kv" id="kv-engagement"></div>
				</div>
			</div>
			<div class="cards-2" style="margin-top:14px;">
				<div class="panel">
					<h3><span class="pill">Conversion</span> Klik Daftar</h3>
					<div class="kv" id="kv-conv"></div>
				</div>
				<div class="panel">
					<h3><span class="pill">Tech/UX</span> Kesehatan</h3>
					<div class="kv" id="kv-tech"></div>
				</div>
			</div>
			<div class="panel" style="margin-top:14px;">
				<h3><span class="pill">Org</span> Insight UKMI</h3>
				<div class="kv" id="kv-org"></div>
			</div>
			<div class="chart-grid" style="margin-top:14px;">
				<div class="chart-box">
					<h3><span class="pill">Trend</span> Total Harian 14 Hari</h3>
					<div class="chart-wrap"><canvas id="chart-trend"></canvas></div>
					<div class="chart-note">Grafik garis menunjukkan volume event per hari dari history yang tersimpan.</div>
				</div>
			</div>
			<div class="panel" style="margin-top:14px;">
				<h3>Top Events</h3>
				<table class="table" id="tbl-top">
					<thead><tr><th>Nama</th><th>Hit</th></tr></thead>
					<tbody></tbody>
				</table>
			</div>
		</main>
		<?php require __DIR__ . '/components/status-scripts.php'; ?>
	</body>
	</html><?php
	exit;
}

respond($summary);
