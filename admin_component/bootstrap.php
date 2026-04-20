<?php
session_start();

$projectRoot = dirname(__DIR__);
$configPath = $projectRoot . '/data.json';
$defaultPath = $projectRoot . '/default.json';
$insightPath = $projectRoot . '/insights.json';

if (!is_readable($defaultPath)) {
	http_response_code(500);
	exit('default.json missing.');
}

$defaultData = json_decode((string) file_get_contents($defaultPath), true);
if (!is_array($defaultData)) {
	http_response_code(500);
	exit('default.json invalid.');
}

$defaults = [
	'user' => (string) ($defaultData['user'] ?? ''),
	'key' => (string) ($defaultData['key'] ?? ''),
	'agenda' => is_array($defaultData['agenda'] ?? null) ? $defaultData['agenda'] : ['tag' => 'Agenda', 'title' => 'Agenda terdekat', 'detail' => 'Belum ada agenda.'],
	'agendas' => is_array($defaultData['agendas'] ?? null) ? $defaultData['agendas'] : [],
	'agenda_archive' => is_array($defaultData['agenda_archive'] ?? null) ? $defaultData['agenda_archive'] : [],
	'logs' => is_array($defaultData['logs'] ?? null) ? $defaultData['logs'] : [],
	'logs_archive' => is_array($defaultData['logs_archive'] ?? null) ? $defaultData['logs_archive'] : [],
	'registration' => is_array($defaultData['registration'] ?? null) ? $defaultData['registration'] : ['platform' => 'Google Form', 'url' => 'https://forms.gle/'],
	'docs' => is_array($defaultData['docs'] ?? null) ? $defaultData['docs'] : [],
	'faqs' => is_array($defaultData['faqs'] ?? null) ? $defaultData['faqs'] : [],
	'hero_cards' => is_array($defaultData['hero_cards'] ?? null) ? $defaultData['hero_cards'] : [
		[
			'type' => 'image',
			'title' => 'Logo UKMI Polmed',
			'caption' => 'Swipe kartu lain atau edit tampilannya dari admin.',
			'content' => '/logo-ukmi.png',
			'alt' => 'Logo UKMI Polmed',
		],
		[
			'type' => 'image',
			'title' => 'Highlight kegiatan',
			'caption' => 'Konten bisa diisi gambar atau embed code.',
			'content' => '/palestine.gif',
			'alt' => 'Ilustrasi highlight kegiatan UKMI Polmed',
		],
	],
	'posts' => is_array($defaultData['posts'] ?? null) ? $defaultData['posts'] : [],
	'divisions' => is_array($defaultData['divisions'] ?? null) ? $defaultData['divisions'] : [],
	'session_version' => isset($defaultData['session_version']) ? (int) $defaultData['session_version'] : 1,
	'insights' => is_array($defaultData['insights'] ?? null) ? $defaultData['insights'] : ['max_bytes' => 10 * 1024 * 1024],
];
$insightMaxBytes = isset($defaults['insights']['max_bytes']) ? (int) $defaults['insights']['max_bytes'] : (10 * 1024 * 1024);

if (!is_readable($configPath)) {
	$config = $defaults;
	$initPayload = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($initPayload === false || file_put_contents($configPath, $initPayload) === false) {
		http_response_code(500);
		exit('Admin config missing and cannot be created.');
	}
} else {
	$configRaw = (string) file_get_contents($configPath);
	$config = json_decode($configRaw, true);
	if (!is_array($config)) {
		$config = $defaults;
		$initPayload = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($initPayload === false || file_put_contents($configPath, $initPayload) === false) {
			http_response_code(500);
			exit('Admin config invalid and cannot be recreated.');
		}
	}
}

if (empty($config['user']) && !empty($defaults['user'])) {
	$config['user'] = $defaults['user'];
}
if (empty($config['key']) && !empty($defaults['key'])) {
	$config['key'] = $defaults['key'];
}
if (empty($config['user']) || empty($config['key'])) {
	http_response_code(500);
	exit('Admin config invalid.');
}

$config['logs'] = is_array($config['logs'] ?? null) ? array_values($config['logs']) : $defaults['logs'];
$config['logs_archive'] = is_array($config['logs_archive'] ?? null) ? $config['logs_archive'] : $defaults['logs_archive'];
$config['agendas'] = is_array($config['agendas'] ?? null) ? array_values($config['agendas']) : [];
$config['agenda_archive'] = is_array($config['agenda_archive'] ?? null) ? array_values($config['agenda_archive']) : [];
$config['registration'] = is_array($config['registration'] ?? null) ? $config['registration'] : $defaults['registration'];
$config['divisions'] = is_array($config['divisions'] ?? null) ? array_values($config['divisions']) : $defaults['divisions'];
$config['docs'] = is_array($config['docs'] ?? null) ? array_values($config['docs']) : $defaults['docs'];
$config['faqs'] = is_array($config['faqs'] ?? null) ? array_values($config['faqs']) : $defaults['faqs'];
$config['hero_cards'] = is_array($config['hero_cards'] ?? null) ? array_values($config['hero_cards']) : $defaults['hero_cards'];
$config['posts'] = is_array($config['posts'] ?? null) ? array_values($config['posts']) : $defaults['posts'];
$config['session_version'] = isset($config['session_version']) ? max(1, (int) $config['session_version']) : 1;
$sessionVersion = (int) $config['session_version'];

$agendas = [];
foreach ($config['agendas'] as $item) {
	if (!is_array($item)) continue;
	$agendas[] = [
		'tag' => trim((string) ($item['tag'] ?? $defaults['agenda']['tag'])),
		'title' => trim((string) ($item['title'] ?? $defaults['agenda']['title'])),
		'detail' => trim((string) ($item['detail'] ?? $defaults['agenda']['detail'])),
	];
}
if (empty($agendas) && !empty($config['agenda']) && is_array($config['agenda'])) {
	$agendas[] = [
		'tag' => trim((string) ($config['agenda']['tag'] ?? $defaults['agenda']['tag'])),
		'title' => trim((string) ($config['agenda']['title'] ?? $defaults['agenda']['title'])),
		'detail' => trim((string) ($config['agenda']['detail'] ?? $defaults['agenda']['detail'])),
	];
}
if (empty($agendas)) {
	$agendas = [$defaults['agenda']];
}
$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
$editingIdx = 0;

$registrationDefaults = $defaults['registration'];
$registration = array_merge($registrationDefaults, is_array($config['registration']) ? $config['registration'] : []);

$divisionDefaults = $defaults['divisions'];
$divisions = [];
foreach ($config['divisions'] as $item) {
	if (!is_array($item)) continue;
	$name = trim((string) ($item['name'] ?? ''));
	$desc = trim((string) ($item['description'] ?? ''));
	if ($name === '' && $desc === '') continue;
	$divisions[] = [
		'name' => $name ?: 'Divisi',
		'description' => $desc ?: 'Deskripsi divisi.',
	];
}
if (empty($divisions)) {
	$divisions = $divisionDefaults;
}
$divisionYearStart = (int) date('Y');
$divisionYearLabel = $divisionYearStart . '-' . ($divisionYearStart + 1);

$docDefaults = $defaults['docs'];
$docs = [];
foreach ($config['docs'] as $item) {
	if (!is_array($item)) continue;
	$docs[] = [
		'tag' => trim((string) ($item['tag'] ?? 'Dokumentasi')) ?: 'Dokumentasi',
		'title' => trim((string) ($item['title'] ?? '')) ?: 'Judul dokumentasi',
		'description' => trim((string) ($item['description'] ?? '')) ?: 'Deskripsi dokumentasi.',
		'url' => trim((string) ($item['url'] ?? '')) ?: '#',
	];
}
if (empty($docs)) {
	$docs = $docDefaults;
}

$faqs = [];
foreach ($config['faqs'] as $item) {
	if (!is_array($item)) continue;
	$question = trim((string) ($item['question'] ?? $item['title'] ?? ''));
	$answer = trim((string) ($item['answer'] ?? $item['body'] ?? ''));
	$slug = trim((string) ($item['slug'] ?? ''));
	$writtenAt = trim((string) ($item['written_at'] ?? $item['created_at'] ?? ''));
	$updatedAt = trim((string) ($item['updated_at'] ?? ''));
	if ($question === '' && $answer === '') continue;
	if ($slug === '') {
		$slug = makeSlug($question !== '' ? $question : $answer);
	}
	$faqs[] = [
		'question' => $question ?: 'Pertanyaan',
		'answer' => $answer ?: 'Jawaban',
		'slug' => $slug,
		'written_at' => $writtenAt ?: date('c'),
		'updated_at' => $updatedAt ?: ($writtenAt ?: date('c')),
	];
}

function normalizeAdminFaqSlug($text)
{
	$text = trim((string) $text);
	if ($text === '') {
		$text = 'faq-' . substr(md5((string) microtime(true)), 0, 8);
	}
	$text = makeSlug($text);
	return $text !== '' ? $text : ('faq-' . substr(md5((string) microtime(true)), 0, 8));
}

function normalizeAdminFaqTimestamp($value)
{
	$value = trim((string) $value);
	if ($value === '') {
		return date('c');
	}
	$ts = strtotime($value);
	return $ts === false ? date('c') : date('c', $ts);
}

$heroCards = [];
foreach ($config['hero_cards'] as $item) {
	if (!is_array($item)) continue;
	$type = strtolower(trim((string) ($item['type'] ?? 'image')));
	if (!in_array($type, ['image', 'embed'], true)) {
		$type = 'image';
	}
	$title = trim((string) ($item['title'] ?? ''));
	$caption = trim((string) ($item['caption'] ?? ''));
	$content = trim((string) ($item['content'] ?? ''));
	$alt = trim((string) ($item['alt'] ?? ''));
	if ($title === '' && $caption === '' && $content === '') continue;
	if ($content === '') continue;
	$heroCards[] = [
		'type' => $type,
		'title' => $title,
		'caption' => $caption,
		'content' => $content,
		'alt' => $alt,
	];
}
if (empty($heroCards)) {
	$heroCards = $defaults['hero_cards'];
}

$maxPosts = 100;
$posts = [];
foreach ($config['posts'] as $post) {
	if (!is_array($post)) continue;
	$embedList = [];
	if (!empty($post['embeds']) && is_array($post['embeds'])) {
		foreach ($post['embeds'] as $emb) {
			$emb = trim((string) $emb);
			if ($emb !== '') $embedList[] = $emb;
		}
	}
	$posts[] = [
		'title' => trim((string) ($post['title'] ?? 'Tanpa judul')) ?: 'Tanpa judul',
		'slug' => trim((string) ($post['slug'] ?? '')),
		'summary' => trim((string) ($post['summary'] ?? '')),
		'body' => (string) ($post['body'] ?? ''),
		'image' => trim((string) ($post['image'] ?? '')),
		'embed_enabled' => !empty($post['embed_enabled']),
		'embeds' => $embedList,
		'created_at' => $post['created_at'] ?? date('c'),
		'updated_at' => $post['updated_at'] ?? ($post['created_at'] ?? date('c')),
		'comments' => (!empty($post['comments']) && is_array($post['comments'])) ? $post['comments'] : [],
		'archived' => !empty($post['archived']),
		'archived_at' => $post['archived_at'] ?? null,
	];
}
if (empty($posts)) {
	$posts = $defaults['posts'];
}
$posts = array_slice($posts, 0, $maxPosts);
$postsArchived = is_array($config['posts_archived'] ?? null) ? array_values($config['posts_archived']) : [];

$blogEditingIdx = -1;
$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'embeds' => [], 'embed_enabled' => false];

$maxLogs = 30;
$archiveLimitDays = 60;
$maxAgendaArchive = 50;
$maxPostArchive = 200;

function archiveOldLogs(&$config, $maxLogs, $archiveLimitDays)
{
	$logs = is_array($config['logs']) ? $config['logs'] : [];
	$archive = is_array($config['logs_archive']) ? $config['logs_archive'] : [];
	$thresholdArchive = time() - 86400;
	$thresholdDelete = time() - (86400 * 7);
	$kept = [];
	foreach ($logs as $entry) {
		$ts = strtotime($entry['time'] ?? '');
		if ($ts !== false && $ts < $thresholdArchive) {
			$day = date('Y-m-d', $ts);
			if (!isset($archive[$day]) || !is_array($archive[$day])) {
				$archive[$day] = [];
			}
			$archive[$day][] = $entry;
		} else {
			$kept[] = $entry;
		}
	}
	foreach ($archive as $day => $entries) {
		$dayTs = strtotime($day);
		if ($dayTs !== false && $dayTs < $thresholdDelete) {
			unset($archive[$day]);
		}
	}
	krsort($archive);
	if ($archiveLimitDays > 0 && count($archive) > $archiveLimitDays) {
		$archive = array_slice($archive, 0, $archiveLimitDays, true);
	}
	$config['logs'] = array_slice($kept, 0, $maxLogs);
	$config['logs_archive'] = $archive;
}

function appendLog(&$config, $configPath, $event, $maxLogs, $archiveLimitDays)
{
	archiveOldLogs($config, $maxLogs, $archiveLimitDays);
	$entry = [
		'time' => date('c'),
		'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
		'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 140),
		'event' => $event,
	];
	$logs = is_array($config['logs']) ? $config['logs'] : [];
	array_unshift($logs, $entry);
	$config['logs'] = array_slice($logs, 0, $maxLogs);
	$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($encoded !== false) {
		file_put_contents($configPath, $encoded);
	}
}

function bumpInsightEvent(string $event, string $insightPath, int $insightMaxBytes): void
{
	$cleanEvent = preg_replace('/[^a-zA-Z0-9_-]/', '', $event);
	if ($cleanEvent === '') return;
	if (file_exists($insightPath) && (int) filesize($insightPath) > $insightMaxBytes) return;
	$todayKey = date('Y-m-d');
	$payload = ['total' => 0, 'events' => [], 'history' => [], 'updated_at' => date('c')];
	if (is_readable($insightPath)) {
		$tmp = json_decode((string) file_get_contents($insightPath), true);
		if (is_array($tmp)) {
			$payload['total'] = (int) ($tmp['total'] ?? 0);
			$payload['events'] = is_array($tmp['events'] ?? null) ? $tmp['events'] : [];
			$payload['history'] = is_array($tmp['history'] ?? null) ? $tmp['history'] : [];
			$payload['updated_at'] = $tmp['updated_at'] ?? date('c');
		}
	}
	$payload['events'][$cleanEvent] = (int) ($payload['events'][$cleanEvent] ?? 0) + 1;
	$payload['total'] = (int) ($payload['total'] ?? 0) + 1;
	$payload['updated_at'] = date('c');
	if (!isset($payload['history'][$todayKey])) {
		$payload['history'][$todayKey] = ['total' => 0, 'events' => []];
	}
	$payload['history'][$todayKey]['total'] = (int) ($payload['history'][$todayKey]['total'] ?? 0) + 1;
	$payload['history'][$todayKey]['events'][$cleanEvent] = (int) ($payload['history'][$todayKey]['events'][$cleanEvent] ?? 0) + 1;
	$data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	$fh = @fopen($insightPath, 'c+');
	if (!$fh || $data === false) return;
	try {
		if (!flock($fh, LOCK_EX)) return;
		ftruncate($fh, 0);
		fwrite($fh, $data);
		fflush($fh);
		flock($fh, LOCK_UN);
	} finally {
		fclose($fh);
	}
}

function makeSlug($text)
{
	$text = strtolower((string) $text);
	$text = preg_replace('/[^a-z0-9]+/', '-', $text);
	$text = trim((string) $text, '-');
	if ($text === '') {
		$text = 'post-' . substr(md5((string) microtime(true)), 0, 8);
	}
	return $text;
}

$error = null;
$message = null;
$flash = $_GET['msg'] ?? null;
if ($flash === 'logout-all') {
	$message = 'Semua sesi admin telah dikeluarkan.';
}
if ($flash === 'cred-updated') {
	$message = 'User dan key diperbarui. Silakan login ulang dengan kredensial baru.';
}

if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: admin.php');
	exit;
}

if (isset($_POST['user'], $_POST['key'])) {
	$user = (string) $_POST['user'];
	$key = (string) $_POST['key'];
	if (hash_equals($config['user'], $user) && hash_equals($config['key'], $key)) {
		appendLog($config, $configPath, 'login-success', $maxLogs, $archiveLimitDays);
		$_SESSION['admin_logged_in'] = true;
		$_SESSION['session_version'] = $sessionVersion;
		header('Location: admin.php');
		exit;
	}
	appendLog($config, $configPath, 'login-fail', $maxLogs, $archiveLimitDays);
	$error = 'User atau key salah.';
}

$loggedIn = !empty($_SESSION['admin_logged_in']) && (($_SESSION['session_version'] ?? 0) === $sessionVersion);

$divisionUnlocked = false;
if (!empty($_SESSION['division_unlocked'])) {
	$divisionUnlocked = true;
	unset($_SESSION['division_unlocked']);
}

$insightsUnlocked = false;
if (!empty($_SESSION['insights_unlocked'])) {
	$insightsUnlocked = true;
	unset($_SESSION['insights_unlocked']);
}

$credentialUnlocked = false;
if (!empty($_SESSION['credential_unlocked'])) {
	$credentialUnlocked = true;
	unset($_SESSION['credential_unlocked']);
}

if (!empty($_SESSION['admin_logged_in']) && !$loggedIn) {
	session_destroy();
	$_SESSION = [];
}

$adminComponentMode = 'handle';
require __DIR__ . '/agenda-terdekat.php';
require __DIR__ . '/link-pendaftaran.php';
require __DIR__ . '/header-card-presentation.php';
require __DIR__ . '/divisi-kepengurusan.php';
require __DIR__ . '/kartu-dokumentasi.php';
require __DIR__ . '/pengelola-faq.php';
require __DIR__ . '/pegelola-blog.php';
require __DIR__ . '/log.php';
require __DIR__ . '/penyimpanan-insight.php';
require __DIR__ . '/sesi.php';
require __DIR__ . '/kredensial-admin.php';
unset($adminComponentMode);
