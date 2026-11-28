<?php
if (!defined('UKMI_ADMIN_BOOTSTRAP')) {
	http_response_code(404);
	exit('Not found.');
}

// Kegagalan bootstrap harus tetap menghasilkan halaman yang terbaca, termasuk
// saat loader awal sudah terlanjur di-flush.
function admin_bootstrap_fail(string $message, int $status = 500): void
{
	$safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
	if (!headers_sent()) {
		http_response_code($status);
		header('Content-Type: text/html; charset=utf-8');
		echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Admin error</title></head><body style="margin:0;padding:24px;background:#0f172a;color:#e2e8f0;font-family:Segoe UI,Tahoma,sans-serif;"><p style="max-width:640px;padding:16px 18px;border:1px solid #f87171;border-radius:12px;background:rgba(248,113,113,0.12);color:#fecaca;">' . $safe . '</p></body></html>';
	} else {
		echo '<div style="margin:16px;padding:14px 16px;border:1px solid #f87171;border-radius:10px;background:rgba(248,113,113,0.12);color:#fecaca;">' . $safe . '</div>';
		echo '<script>if(window.ukmiHideLoadingOverlay)window.ukmiHideLoadingOverlay();</script>';
	}
	exit;
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/security_helper.php';
ukmi_session_start();
require_once $projectRoot . '/store.php';
$configPath = $projectRoot . '/data.json';
$defaultPath = $projectRoot . '/default.json';
$insightPath = $projectRoot . '/insights.json';

if (!is_readable($defaultPath)) {
	admin_bootstrap_fail('Berkas default.json tidak ditemukan.');
}

$defaultData = json_decode((string) file_get_contents($defaultPath), true);
if (!is_array($defaultData)) {
	admin_bootstrap_fail('Berkas default.json tidak valid.');
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
	'shortcut_cards' => is_array($defaultData['shortcut_cards'] ?? null) ? $defaultData['shortcut_cards'] : [],
	'faqs' => is_array($defaultData['faqs'] ?? null) ? $defaultData['faqs'] : [],
	'hero_cards' => is_array($defaultData['hero_cards'] ?? null) ? $defaultData['hero_cards'] : [
		[
			'type' => 'image',
			'title' => 'Logo UKMI POLMED',
			'caption' => 'Swipe kartu lain atau edit tampilannya dari admin.',
			'content' => '/logo-ukmi.png',
			'alt' => 'Logo UKMI POLMED',
		],
		[
			'type' => 'image',
			'title' => 'Highlight kegiatan',
			'caption' => 'Konten bisa diisi gambar atau embed code.',
			'content' => '/palestine.gif',
			'alt' => 'Ilustrasi highlight kegiatan UKMI POLMED',
		],
	],
	'posts' => is_array($defaultData['posts'] ?? null) ? $defaultData['posts'] : [],
	'divisions' => is_array($defaultData['divisions'] ?? null) ? $defaultData['divisions'] : [],
	'session_version' => isset($defaultData['session_version']) ? (int) $defaultData['session_version'] : 1,
];

$config = store_read_json($configPath);
if (!is_array($config)) {
	$config = $defaults;
	if (!store_save_json($configPath, $config)) {
		admin_bootstrap_fail('Konfigurasi admin tidak valid dan tidak bisa dibuat ulang.');
	}
}

if (empty($config['user']) && !empty($defaults['user'])) {
	$config['user'] = $defaults['user'];
}
if (empty($config['key']) && !empty($defaults['key'])) {
	$config['key'] = $defaults['key'];
}
if (empty($config['user']) || empty($config['key'])) {
	admin_bootstrap_fail('Konfigurasi admin tidak valid.');
}

require_once __DIR__ . '/../shortcut_migration.php';
if (is_readable($configPath)) {
	$seededShortcutCards = shortcut_ensure_seeded($configPath);
	if ($seededShortcutCards !== null) {
		$config['shortcut_cards'] = $seededShortcutCards;
	}
}

$config['logs'] = is_array($config['logs'] ?? null) ? array_values($config['logs']) : $defaults['logs'];
$config['logs_archive'] = is_array($config['logs_archive'] ?? null) ? $config['logs_archive'] : $defaults['logs_archive'];
$config['agendas'] = is_array($config['agendas'] ?? null) ? array_values($config['agendas']) : [];
$config['agenda_archive'] = is_array($config['agenda_archive'] ?? null) ? array_values($config['agenda_archive']) : [];
$config['registration'] = is_array($config['registration'] ?? null) ? $config['registration'] : $defaults['registration'];
$config['divisions'] = is_array($config['divisions'] ?? null) ? array_values($config['divisions']) : $defaults['divisions'];
$config['docs'] = is_array($config['docs'] ?? null) ? array_values($config['docs']) : $defaults['docs'];
$config['shortcut_cards'] = is_array($config['shortcut_cards'] ?? null) ? array_values($config['shortcut_cards']) : $defaults['shortcut_cards'];
$config['faqs'] = is_array($config['faqs'] ?? null) ? array_values($config['faqs']) : $defaults['faqs'];
$config['hero_cards'] = is_array($config['hero_cards'] ?? null) ? array_values($config['hero_cards']) : $defaults['hero_cards'];
$config['posts'] = is_array($config['posts'] ?? null) ? array_values($config['posts']) : $defaults['posts'];
$config['mentoring_accounts'] = is_array($config['mentoring_accounts'] ?? null) ? $config['mentoring_accounts'] : [];
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
	$avatar = trim((string) ($item['avatar'] ?? ''));
	if ($name === '' && $desc === '') continue;
	$programs = [];
	if (!empty($item['programs']) && is_array($item['programs'])) {
		foreach ($item['programs'] as $prog) {
			if (!is_array($prog)) continue;
			$pTitle = trim((string) ($prog['title'] ?? ''));
			$pDesc = trim((string) ($prog['description'] ?? ''));
			if ($pTitle === '' && $pDesc === '') continue;
			$programs[] = [
				'title' => $pTitle ?: 'Program Kerja',
				'description' => $pDesc,
			];
			if (count($programs) >= 20) break;
		}
	}
	$divisions[] = [
		'name' => $name ?: 'Divisi',
		'description' => $desc ?: 'Deskripsi divisi.',
		'avatar' => $avatar,
		'programs' => $programs,
	];
}
if (empty($divisions)) {
	$divisions = array_map(static function ($item) {
		if (!is_array($item)) return ['name' => 'Divisi', 'description' => 'Deskripsi divisi.', 'avatar' => '', 'programs' => []];
		$item['programs'] = is_array($item['programs'] ?? null) ? $item['programs'] : [];
		return $item;
	}, $divisionDefaults);
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
		'thumb' => trim((string) ($item['thumb'] ?? '')),
	];
}
if (empty($docs)) {
	$docs = $docDefaults;
}

$rawDocsSettings = is_array($config['docs_settings'] ?? null) ? $config['docs_settings'] : [];
$speedMode = ($rawDocsSettings['speed_mode'] ?? 'preset') === 'custom' ? 'custom' : 'preset';
$speedPreset = in_array($rawDocsSettings['speed_preset'] ?? 'normal', ['slow', 'normal', 'fast'], true)
	? (string) ($rawDocsSettings['speed_preset'] ?? 'normal')
	: 'normal';
$speedCustom = max(10, min(200, (int) ($rawDocsSettings['speed_custom'] ?? 52)));
$mentoringCount = max(1, min(20, (int) ($rawDocsSettings['mentoring_count'] ?? 4)));
$docsSettings = [
	'auto_scroll' => array_key_exists('auto_scroll', $rawDocsSettings) ? (bool) $rawDocsSettings['auto_scroll'] : true,
	'speed_mode' => $speedMode,
	'speed_preset' => $speedPreset,
	'speed_custom' => $speedCustom,
	'mentoring_enabled' => !empty($rawDocsSettings['mentoring_enabled']),
	'mentoring_count' => $mentoringCount,
	'mentoring_clickable' => !empty($rawDocsSettings['mentoring_clickable']),
];
$config['docs_settings'] = $docsSettings;

$shortcutCards = [];
foreach ($config['shortcut_cards'] as $card) {
	if (!is_array($card)) continue;
	$slides = [];
	foreach (($card['slides'] ?? []) as $slide) {
		if (!is_array($slide)) continue;
		$tag = trim((string) ($slide['tag'] ?? ''));
		$title = trim((string) ($slide['title'] ?? ''));
		$detail = trim((string) ($slide['detail'] ?? ''));
		if ($tag === '' && $title === '' && $detail === '') continue;
		$slides[] = [
			'tag' => $tag,
			'title' => $title,
			'detail' => $detail,
		];
		if (count($slides) >= 8) break;
	}
	if (empty($slides)) continue;
	$shortcutCards[] = ['slides' => $slides];
	if (count($shortcutCards) >= 8) break;
}
$config['shortcut_cards'] = $shortcutCards;

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
$protectedDefaultHeroCard = [
	'type' => 'default',
	'title' => 'Kartu bawaan beranda',
	'caption' => 'Kartu ini menampilkan ringkasan utama UKMI dan tidak bisa dihapus.',
	'content' => '__default__',
	'alt' => '',
	'locked' => true,
];
$hasDefaultHeroCard = false;
foreach ($config['hero_cards'] as $item) {
	if (!is_array($item)) continue;
	$type = strtolower(trim((string) ($item['type'] ?? 'image')));
	if (!in_array($type, ['image', 'embed', 'default'], true)) {
		$type = 'image';
	}
	if ($type === 'default') {
		if ($hasDefaultHeroCard) continue;
		$heroCards[] = $protectedDefaultHeroCard;
		$hasDefaultHeroCard = true;
		continue;
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
		'locked' => false,
	];
}
if (!$hasDefaultHeroCard) {
	array_unshift($heroCards, $protectedDefaultHeroCard);
}
if (count($heroCards) === 1) {
	foreach ($defaults['hero_cards'] as $item) {
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
			'locked' => false,
		];
	}
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
		'author_id' => trim((string) ($post['author_id'] ?? '')),
		'focal_x' => isset($post['focal_x']) ? max(0, min(100, (float) $post['focal_x'])) : 50,
		'focal_y' => isset($post['focal_y']) ? max(0, min(100, (float) $post['focal_y'])) : 50,
		'ig_embed_fix' => !empty($post['ig_embed_fix']),
		'gdrive_folder' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($post['gdrive_folder'] ?? '')),
		'gdrive_view' => (($post['gdrive_view'] ?? 'grid') === 'list') ? 'list' : 'grid',
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
$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'focal_x' => 50, 'focal_y' => 50, 'ig_embed_fix' => false, 'gdrive_folder' => '', 'gdrive_view' => 'grid', 'embeds' => [], 'embed_enabled' => false];

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
	store_save_json($configPath, $config);
}

function bumpInsightEvent(string $event, string $insightPath): void
{
	$cleanEvent = preg_replace('/[^a-zA-Z0-9_-]/', '', $event);
	if ($cleanEvent === '') return;
	// Batas mengikuti sisa kapasitas S3 (dibaca dari cache, tanpa request jaringan).
	$todayKey = date('Y-m-d');
	$payload = ['total' => 0, 'events' => [], 'history' => [], 'updated_at' => date('c')];
	$tmp = store_read_json($insightPath);
	if (is_array($tmp)) {
		$payload['total'] = (int) ($tmp['total'] ?? 0);
		$payload['events'] = is_array($tmp['events'] ?? null) ? $tmp['events'] : [];
		$payload['history'] = is_array($tmp['history'] ?? null) ? $tmp['history'] : [];
		$payload['updated_at'] = $tmp['updated_at'] ?? date('c');
	}
	$payload['events'][$cleanEvent] = (int) ($payload['events'][$cleanEvent] ?? 0) + 1;
	$payload['total'] = (int) ($payload['total'] ?? 0) + 1;
	$payload['updated_at'] = date('c');
	if (!isset($payload['history'][$todayKey])) {
		$payload['history'][$todayKey] = ['total' => 0, 'events' => []];
	}
	$payload['history'][$todayKey]['total'] = (int) ($payload['history'][$todayKey]['total'] ?? 0) + 1;
	$payload['history'][$todayKey]['events'][$cleanEvent] = (int) ($payload['history'][$todayKey]['events'][$cleanEvent] ?? 0) + 1;
	store_save_json($insightPath, $payload);
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

function admin_recovery_key(array $defaultData): string
{
	return trim((string) ($defaultData['recovery_key'] ?? ''));
}

function admin_mentoring_master_url(string $projectRoot): string
{
	if (is_dir($projectRoot . '/terpadu/mai/mentoring/master')) {
		return '/terpadu/mai/mentoring/master/';
	}
	if (is_dir($projectRoot . '/terpadu/mentoring-agama-islam/kelola-kelompok-mentoring/master')) {
		return '/terpadu/mentoring-agama-islam/kelola-kelompok-mentoring/master/';
	}
	return '/terpadu/mai/mentoring/master/';
}

$defaultRecoveryCount = isset($defaultData['recovery_count']) ? max(0, (int) $defaultData['recovery_count']) : 0;
$configRecoveryCount = isset($config['recovery_count']) ? max(0, (int) $config['recovery_count']) : 0;
$recoveryCount = max($defaultRecoveryCount, $configRecoveryCount);
if ($recoveryCount !== $defaultRecoveryCount && is_writable($defaultPath)) {
	$defaultData['recovery_count'] = $recoveryCount;
	$tmpDefault = $defaultPath . '.tmp';
	$encodedDefault = json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encodedDefault !== false && @file_put_contents($tmpDefault, $encodedDefault, LOCK_EX) !== false) {
		@rename($tmpDefault, $defaultPath);
	}
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
if ($flash === 'cred-recovered') {
	$message = 'Kredensial admin berhasil dipulihkan ke bawaan (default.json) dan semua sesi aktif telah dikeluarkan. Silakan login.';
}

if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: /admin');
	exit;
}

require_once __DIR__ . '/../authors_helper.php';
require_once __DIR__ . '/../s3_helper.php';

$csrfOk = ($_SERVER['REQUEST_METHOD'] !== 'POST') || ukmi_csrf_valid();
if (!$csrfOk) {
	$error = 'Sesi kedaluwarsa atau token tidak valid. Muat ulang halaman lalu coba lagi.';
}

if ($csrfOk && isset($_POST['recover_credentials'], $_POST['recovery_key'])) {
	if (ukmi_rate_limit('auth', 10, 600)) {
		$error = 'Terlalu banyak percobaan. Coba lagi dalam beberapa menit.';
	} else {
	$inputRecoveryKey = trim((string) $_POST['recovery_key']);
	$masterRecoveryKey = admin_recovery_key($defaultData);

	if ($masterRecoveryKey === '') {
		$error = 'Kunci pemulihan belum dikonfigurasi di default.json.';
	} elseif ($inputRecoveryKey === '' || !hash_equals($masterRecoveryKey, $inputRecoveryKey)) {
		appendLog($config, $configPath, 'auth-recovery-fail', $maxLogs, $archiveLimitDays);
		$error = 'Kunci pemulihan master salah.';
	} elseif ($defaults['user'] === '' || $defaults['key'] === '') {
		$error = 'Kredensial bawaan di default.json tidak lengkap.';
	} else {
		$recoveryCount++;
		$config['user'] = $defaults['user'];
		$config['key'] = $defaults['key'];
		$sessionVersion++;
		$config['session_version'] = $sessionVersion;
		$config['recovery_count'] = $recoveryCount;

		if (!store_save_json($configPath, $config)) {
			$error = 'Gagal menyimpan pemulihan kredensial ke penyimpanan.';
		} else {
			$defaultData['recovery_count'] = $recoveryCount;
			$tmpDefault = $defaultPath . '.tmp';
			$encodedDefault = json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($encodedDefault !== false && @file_put_contents($tmpDefault, $encodedDefault, LOCK_EX) !== false) {
				@rename($tmpDefault, $defaultPath);
			}
			appendLog($config, $configPath, 'auth-recovered', $maxLogs, $archiveLimitDays);
			session_destroy();
			$_SESSION = [];
			header('Location: /admin?msg=cred-recovered');
			exit;
		}
	}
	}
}

if ($csrfOk && isset($_POST['user'], $_POST['key'])) {
	if (ukmi_rate_limit('auth', 10, 600)) {
		$error = 'Terlalu banyak percobaan login. Coba lagi dalam beberapa menit.';
	} else {
	$user = (string) $_POST['user'];
	$key = (string) $_POST['key'];
	if (hash_equals($config['user'], $user) && ukmi_verify_admin_key($config['key'], $key)) {
		if (!ukmi_is_password_hashed($config['key'])) {
			$config['key'] = password_hash($key, PASSWORD_DEFAULT);
		}
		appendLog($config, $configPath, 'login-success', $maxLogs, $archiveLimitDays);
		session_regenerate_id(true);
		$_SESSION['admin_logged_in'] = true;
		$_SESSION['session_version'] = $sessionVersion;
		$_SESSION['role'] = 'admin';
		unset($_SESSION['author_id']);
		header('Location: /admin');
		exit;
	}
	$writer = authors_find_by_username($user);
	if ($writer && !empty($writer['password_hash']) && password_verify($key, (string) $writer['password_hash'])) {
		appendLog($config, $configPath, 'login-success', $maxLogs, $archiveLimitDays);
		session_regenerate_id(true);
		$_SESSION['admin_logged_in'] = true;
		$_SESSION['session_version'] = $sessionVersion;
		$_SESSION['role'] = 'writer';
		$_SESSION['author_id'] = $writer['id'] ?? '';
		header('Location: /penulis-panel');
		exit;
	}
	foreach (['ikhwan', 'akhwat'] as $__mScope) {
		$__mAcc = $config['mentoring_accounts'][$__mScope] ?? null;
		if (
			is_array($__mAcc)
			&& !empty($__mAcc['username'])
			&& !empty($__mAcc['password_hash'])
			&& strcasecmp((string) $__mAcc['username'], $user) === 0
			&& password_verify($key, (string) $__mAcc['password_hash'])
		) {
			appendLog($config, $configPath, 'login-success-mentoring-' . $__mScope, $maxLogs, $archiveLimitDays);
			session_regenerate_id(true);
			$_SESSION['admin_logged_in'] = true;
			$_SESSION['session_version'] = $sessionVersion;
			$_SESSION['role'] = 'mentoring_' . $__mScope;
			unset($_SESSION['author_id']);
			header('Location: ' . admin_mentoring_master_url($projectRoot));
			exit;
		}
	}
	appendLog($config, $configPath, 'login-fail', $maxLogs, $archiveLimitDays);
	$error = 'User atau key salah.';
	}
}

$loggedIn = !empty($_SESSION['admin_logged_in']) && (($_SESSION['session_version'] ?? 0) === $sessionVersion);
$currentRole = $loggedIn ? ($_SESSION['role'] ?? 'admin') : '';
$currentAuthorId = $loggedIn && $currentRole === 'writer' ? ($_SESSION['author_id'] ?? '') : '';

if ($loggedIn && $currentRole === 'writer') {
	header('Location: /penulis-panel');
	exit;
}

if ($loggedIn && in_array($currentRole, ['mentoring_ikhwan', 'mentoring_akhwat'], true)) {
	header('Location: ' . admin_mentoring_master_url($projectRoot));
	exit;
}

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

if ($csrfOk) {
$adminComponentMode = 'handle';
require __DIR__ . '/agenda-terdekat.php';
require __DIR__ . '/link-pendaftaran.php';
require __DIR__ . '/header-card-presentation.php';
require __DIR__ . '/shortcut-cards.php';
require __DIR__ . '/divisi-kepengurusan.php';
require __DIR__ . '/kartu-dokumentasi.php';
require __DIR__ . '/pengelola-faq.php';
require __DIR__ . '/pengelola-blog.php';
require __DIR__ . '/moderasi-komentar.php';
require __DIR__ . '/korektor-seo.php';
require __DIR__ . '/log.php';
require __DIR__ . '/penyimpanan-insight.php';
require __DIR__ . '/sesi.php';
require __DIR__ . '/kredensial-admin.php';
unset($adminComponentMode);
}
