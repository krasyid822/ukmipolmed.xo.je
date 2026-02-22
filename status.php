<?php
// Insight + health dashboard endpoint. Accepts ?event=slug to increment counters, otherwise returns summary.

$start = microtime(true);
$storeFile = __DIR__ . '/insights.json';
$todayKey = date('Y-m-d');
$insightMaxBytes = 10 * 1024 * 1024; // fallback 10MB
$defaultConfigPath = __DIR__ . '/default.json';
if (is_readable($defaultConfigPath)) {
	$tmpCfg = json_decode((string) file_get_contents($defaultConfigPath), true);
	if (is_array($tmpCfg) && !empty($tmpCfg['insights']['max_bytes'])) {
		$insightMaxBytes = (int) $tmpCfg['insights']['max_bytes'];
	}
}
$defaultData = [
	'total' => 0,
	'events' => [],
	'history' => [],
	'updated_at' => date('c'),
];

$viewParam = $_GET['view'] ?? '';
$view = ($viewParam === 'json') ? 'json' : 'ui';

function respond(array $data, int $code = 200): void
{
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function loadInsights(string $path, array $fallback): array
{
	if (!is_readable($path)) {
		return $fallback;
	}
	$data = json_decode((string) file_get_contents($path), true);
	if (!is_array($data)) {
		return $fallback;
	}
	$events = $data['events'] ?? [];
	$history = $data['history'] ?? [];
	return [
		'total' => (int) ($data['total'] ?? 0),
		'events' => is_array($events) ? $events : [],
		'history' => is_array($history) ? $history : [],
		'updated_at' => $data['updated_at'] ?? date('c'),
	];
}

function saveInsights(string $path, array $data): bool
{
	$tmp = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	$fh = @fopen($path, 'c+');
	if (!$fh) {
		return false;
	}
	try {
		if (!flock($fh, LOCK_EX)) {
			return false;
		}
		ftruncate($fh, 0);
		fwrite($fh, $tmp);
		fflush($fh);
		flock($fh, LOCK_UN);
		return true;
	} finally {
		fclose($fh);
	}
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

function buildSummary(array $insights, string $todayKey, string $storeFile, float $start, int $insightMaxBytes): array
{
	$top = $insights['events'];
	arsort($top);
	$top = array_slice($top, 0, 10, true);

	$blogViewsBySlug = [];
	foreach ($insights['events'] as $name => $count) {
		if (strpos($name, 'blog_view_') === 0) {
			$slug = substr($name, strlen('blog_view_'));
			if ($slug !== '') {
				$blogViewsBySlug[$slug] = (int) $count;
			}
		}
	}

	$todayData = $insights['history'][$todayKey] ?? ['total' => 0, 'events' => []];
	$todayEvents = $todayData['events'] ?? [];
	arsort($todayEvents);
	$todayEvents = array_slice($todayEvents, 0, 10, true);

	$engagement = [
		'cta_clicks' => sumPrefixes($insights['events'], ['cta_']),
		'nav_interactions' => sumPrefixes($insights['events'], ['nav_']),
		'doc_opens' => sumPrefixes($insights['events'], ['doc_']),
		'agenda_views' => sumPrefixes($insights['events'], ['agenda_view']),
	];

	$conversion = [
		'form_clicks' => sumPrefixes($insights['events'], ['cta_daftar', 'cta_daftar_', 'cta_daftar-', 'form_', 'daftar_', 'daftar-']),
		'nav_daftar' => $insights['events']['nav_daftar'] ?? 0,
		'cta_daftar_hero' => $insights['events']['cta_daftar_hero'] ?? 0,
		'cta_daftar_banner' => $insights['events']['cta_daftar_banner'] ?? 0,
	];

	$sizeBytes = file_exists($storeFile) ? (int) filesize($storeFile) : 0;
	$sizeMB = round($sizeBytes / (1024 * 1024), 2);
	$limitMB = round($insightMaxBytes / (1024 * 1024), 2);

	$technical = [
		'insights_file' => [
			'exists' => file_exists($storeFile),
			'writable' => is_writable($storeFile),
			'size_bytes' => $sizeBytes,
			'size_mb' => $sizeMB,
			'size_limit_mb' => $limitMB,
		],
		'robots_txt' => file_exists(__DIR__ . '/robots.txt'),
		'sitemap_xml' => file_exists(__DIR__ . '/sitemap.xml'),
		'https' => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on',
		'runtime_ms' => round((microtime(true) - $start) * 1000, 2),
	];

	// .uploads directory usage
	$uploadsDir = __DIR__ . '/.uploads';
	$uploadsInfo = [
		'exists' => is_dir($uploadsDir),
		'file_count' => 0,
		'size_bytes' => 0,
		'size_mb' => 0,
	];
	if (is_dir($uploadsDir)) {
		$files = @scandir($uploadsDir);
		if (is_array($files)) {
			foreach ($files as $f) {
				if ($f === '.' || $f === '..') continue;
				$path = $uploadsDir . '/' . $f;
				if (is_file($path)) {
					$uploadsInfo['file_count']++;
					$uploadsInfo['size_bytes'] += (int) @filesize($path);
				}
			}
			$uploadsInfo['size_mb'] = round($uploadsInfo['size_bytes'] / (1024*1024), 2);
		}
	}

	// data.json info
	$dataJsonPath = __DIR__ . '/data.json';
	$dataJsonInfo = [
		'exists' => file_exists($dataJsonPath),
		'readable' => is_readable($dataJsonPath),
		'size_bytes' => file_exists($dataJsonPath) ? (int) filesize($dataJsonPath) : 0,
		'size_mb' => file_exists($dataJsonPath) ? round(filesize($dataJsonPath) / (1024*1024), 2) : 0,
	];

	// comments storage usage (limit 5 MB)
	$commentsInfo = [
		'used_bytes' => 0,
		'used_mb' => 0,
		'limit_bytes' => 5 * 1024 * 1024,
		'limit_mb' => round((5 * 1024 * 1024) / (1024*1024), 2),
		'remaining_bytes' => 5 * 1024 * 1024,
		'remaining_mb' => round((5 * 1024 * 1024) / (1024*1024), 2),
	];
	if (is_readable($dataJsonPath)) {
		$cfg = json_decode((string) @file_get_contents($dataJsonPath), true);
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
				$commentsInfo['remaining_bytes'] = max(0, $commentsInfo['limit_bytes'] - $used);
				$commentsInfo['remaining_mb'] = round($commentsInfo['remaining_bytes'] / (1024*1024), 3);
				$commentsInfo['percent_used'] = round(($used / $commentsInfo['limit_bytes']) * 100, 2);
			}
		}
	}

	$technical['uploads'] = $uploadsInfo;
	$technical['data_json'] = $dataJsonInfo;
	$technical['comments_storage'] = $commentsInfo;

	$orgSpecific = [
		'dokumentasi_clicks' => sumPrefixes($insights['events'], ['doc_']),
		'agenda_updates' => $insights['events']['agenda_admin_update'] ?? 0,
		'cta_daftar_clicks' => sumPrefixes($insights['events'], ['cta_daftar', 'cta_daftar_', 'cta_daftar-']),
		'blog_views_total' => array_sum($blogViewsBySlug),
		'blog_views_by_slug' => $blogViewsBySlug,
		'home_views' => sumPrefixes($insights['events'], ['home_view']),
	];

	$traffic = [
		'lifetime_total' => (int) ($insights['total'] ?? 0),
		'today_total' => (int) (($insights['history'][$todayKey]['total'] ?? 0)),
		'top_events' => $top,
		'top_today' => $todayEvents,
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
		'history_available_days' => array_keys($insights['history']),
	];
}

$eventRaw = $_GET['event'] ?? $_POST['event'] ?? '';
$event = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $eventRaw);

$insights = loadInsights($storeFile, $defaultData);

if ($event !== '') {
	$currentSize = file_exists($storeFile) ? (int) filesize($storeFile) : 0;
	if ($currentSize > $insightMaxBytes) {
		respond(['ok' => false, 'error' => 'Insight store exceeds max size (10MB). Please archive or reset insights.json.'], 507);
	}

	$insights['events'][$event] = (int) ($insights['events'][$event] ?? 0) + 1;
	$insights['total'] = (int) ($insights['total'] ?? 0) + 1;
	$insights['updated_at'] = date('c');

	if (!isset($insights['history'][$todayKey])) {
		$insights['history'][$todayKey] = ['total' => 0, 'events' => []];
	}
	$insights['history'][$todayKey]['total'] = (int) ($insights['history'][$todayKey]['total'] ?? 0) + 1;
	$insights['history'][$todayKey]['events'][$event] = (int) ($insights['history'][$todayKey]['events'][$event] ?? 0) + 1;

	if (!saveInsights($storeFile, $insights)) {
		respond(['ok' => false, 'error' => 'Cannot persist insight data'], 500);
	}

	respond([
		'ok' => true,
		'event' => $event,
		'count' => $insights['events'][$event],
		'total' => $insights['total'],
		'today' => $insights['history'][$todayKey] ?? ['total' => 0, 'events' => []],
		'updated_at' => $insights['updated_at'],
	]);
}

$summary = buildSummary($insights, $todayKey, $storeFile, $start, $insightMaxBytes);

if ($view === 'ui') {
	// Simple BI-style dashboard UI.
	header('Content-Type: text/html; charset=utf-8');
	?><!doctype html>
	<html lang="id">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>UKMI Polmed · BI Dashboard</title>
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
		<style>
			:root {
				--bg: #050814;
				--panel: rgba(255,255,255,0.06);
				--stroke: rgba(255,255,255,0.12);
				--text: #e8edf7;
				--muted: #c4cede;
				--accent: #6cf7c5;
				--accent-2: #7cc9ff;
				--shadow: 0 18px 45px rgba(0,0,0,0.35);
			}
			* { box-sizing: border-box; }
			body {
				margin: 0;
				min-height: 100vh;
				font-family: 'Manrope','Space Grotesk',system-ui,sans-serif;
				background: radial-gradient(circle at 20% 20%, rgba(108,247,197,0.16), transparent 36%),
					radial-gradient(circle at 82% 10%, rgba(124,201,255,0.22), transparent 40%),
					linear-gradient(140deg, #050814, #0c1224 55%, #090f1f 100%);
				color: var(--text);
			}
			header {
				padding: 24px 20px;
				border-bottom: 1px solid var(--stroke);
				position: sticky;
				top: 0;
				backdrop-filter: blur(14px);
				background: rgba(5, 8, 20, 0.75);
				z-index: 2;
			}
			h1 { margin: 0; font-size: clamp(24px,4vw,32px); letter-spacing: 0.2px; }
			main { max-width: 1180px; margin: 0 auto; padding: 24px 20px 40px; }
			.grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); }
			.panel {
				background: var(--panel);
				border: 1px solid var(--stroke);
				border-radius: 16px;
				padding: 16px;
				box-shadow: var(--shadow);
			}
			.panel h3 { margin: 0 0 10px; font-size: 16px; letter-spacing: 0.2px; }
			.kv { display: grid; gap: 8px; }
			.kv div { display: flex; justify-content: space-between; gap: 10px; font-variant-numeric: tabular-nums; }
			.badge { padding: 4px 8px; border-radius: 10px; background: rgba(255,255,255,0.08); border: 1px solid var(--stroke); font-size: 12px; }
			.pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius: 999px; background: rgba(108,247,197,0.12); color: var(--accent); border:1px solid rgba(108,247,197,0.18); font-weight:700; }
			.cards-2 { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(300px,1fr)); }
			.table { width: 100%; border-collapse: collapse; }
			.table th, .table td { text-align: left; padding: 8px 6px; border-bottom: 1px solid rgba(255,255,255,0.07); }
			.table th { color: var(--muted); font-weight: 600; }
			small { color: var(--muted); }
			.hero-metrics { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); margin-top: 14px; }
			.metric {
				padding: 14px;
				border-radius: 14px;
				background: linear-gradient(145deg, rgba(108,247,197,0.16), rgba(124,201,255,0.1));
				border: 1px solid rgba(255,255,255,0.16);
				box-shadow: var(--shadow);
			}
			.metric strong { display:block; font-size:22px; }
			.metric span { color: var(--muted); font-size: 13px; }
			@media (max-width: 640px) {
				header { padding: 16px 14px; }
				main { padding: 18px 14px 32px; }
				.hero-metrics { grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: 10px; }
				.metric { padding: 12px; }
				.metric strong { font-size: 19px; }
			}
		</style>
	</head>
	<body>
		<header>
			<h1>BI Dashboard · UKMI Polmed</h1>
			<div class="hero-metrics">
				<div class="metric">
					<strong id="m-total">-</strong>
					<span>Total event lifetime</span>
				</div>
				<div class="metric">
					<strong id="m-today">-</strong>
					<span>Event hari ini</span>
				</div>
				<div class="metric">
					<strong id="m-cta">-</strong>
					<span>CTR: klik CTA</span>
				</div>
				<div class="metric">
					<strong id="m-runtime">-</strong>
					<span>Runtime status</span>
				</div>
			</div>
		</header>
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
			<div class="panel" style="margin-top:14px;">
				<h3>Top Events</h3>
				<table class="table" id="tbl-top">
					<thead><tr><th>Nama</th><th>Hit</th></tr></thead>
					<tbody></tbody>
				</table>
			</div>
		</main>
		<script>
		const fmt = (n) => typeof n === 'number' ? n.toLocaleString('id-ID') : '-';
		const fmtSize = (n) => {
			if (typeof n !== 'number') return '-';
			if (n >= 1024 * 1024) return (n / (1024*1024)).toFixed(2) + ' MB';
			if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
			return n + ' B';
		};
		const kvFill = (id, rows) => {
			const el = document.getElementById(id);
			if (!el) return;
			el.innerHTML = rows.map(r => `<div><span>${r[0]}</span><strong>${r[1]}</strong></div>`).join('');
		};
		const fillTable = (id, rows) => {
			const tb = document.querySelector(`#${id} tbody`);
			if (!tb) return;
			tb.innerHTML = rows.map(r => `<tr><td>${r[0]}</td><td>${r[1]}</td></tr>`).join('');
		};
		const loadData = async () => {
			let data = null;
			try {
				const res = await fetch('status.php?view=json');
				data = await res.json();
			} catch (e) {
				console.error('Load failed', e);
				return;
			}
			document.getElementById('m-total').textContent = fmt(data.traffic?.lifetime_total);
			document.getElementById('m-today').textContent = fmt(data.traffic?.today_total);
			document.getElementById('m-cta').textContent = fmt(data.engagement?.cta_clicks);
			document.getElementById('m-runtime').textContent = (data.technical?.runtime_ms || '-') + ' ms';

			kvFill('kv-traffic', [
				['Lifetime events', fmt(data.traffic?.lifetime_total)],
				['Hari ini', fmt(data.traffic?.today_total)],
			]);
			kvFill('kv-engagement', [
				['CTA clicks', fmt(data.engagement?.cta_clicks)],
				['Nav interactions', fmt(data.engagement?.nav_interactions)],
				['Dokumentasi dibuka', fmt(data.engagement?.doc_opens)],
				['Agenda views', fmt(data.engagement?.agenda_views)],
			]);
			kvFill('kv-conv', [
				['Form clicks (total)', fmt(data.conversion?.form_clicks)],
				['Nav daftar', fmt(data.conversion?.nav_daftar)],
				['CTA daftar (hero)', fmt(data.conversion?.cta_daftar_hero)],
				['CTA daftar (banner)', fmt(data.conversion?.cta_daftar_banner)],
			]);
			kvFill('kv-tech', [
				['insights.json writable', data.technical?.insights_file?.writable ? 'Ya' : 'Tidak'],
				['insights.json size', fmtSize(data.technical?.insights_file?.size_bytes) + ' / ' + (data.technical?.insights_file?.size_limit_mb ?? '-') + ' MB'],
				['.uploads exists', data.technical?.uploads?.exists ? 'Ada' : 'Tidak'],
				['.uploads files', (data.technical?.uploads?.file_count ?? '-')],
				['.uploads size', fmtSize(data.technical?.uploads?.size_bytes)],
				['data.json exists', data.technical?.data_json?.exists ? 'Ada' : 'Tidak'],
				['data.json size', fmtSize(data.technical?.data_json?.size_bytes)],
				['Komentar terpakai', fmtSize(data.technical?.comments_storage?.used_bytes) + ' / ' + (data.technical?.comments_storage?.limit_mb ?? '-') + ' MB'],
				['robots.txt', data.technical?.robots_txt ? 'Ada' : 'Tidak'],
				['sitemap.xml', data.technical?.sitemap_xml ? 'Ada' : 'Tidak'],
				['HTTPS', data.technical?.https ? 'Aktif' : 'Belum'],
				['Runtime', (data.technical?.runtime_ms || '-') + ' ms'],
			]);
			kvFill('kv-org', [
				['Klik dokumentasi', fmt(data.org_specific?.dokumentasi_clicks)],
				['Klik tombol daftar', fmt(data.org_specific?.cta_daftar_clicks)],
				['Pembaruan agenda', fmt(data.org_specific?.agenda_updates)],
				['Blog dibuka (total)', fmt(data.org_specific?.blog_views_total)],
				['Kunjungan halaman utama', fmt(data.org_specific?.home_views)],
			]);

			const rows = Object.entries(data.traffic?.top_events || {}).map(([k,v]) => [k, fmt(v)]);
			fillTable('tbl-top', rows);
		};
		loadData();
		setInterval(loadData, 8000);
		</script>
	</body>
	</html><?php
	exit;
}

respond($summary);
