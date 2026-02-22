<?php
session_start();

$configPath = __DIR__ . '/data.json';
$defaultPath = __DIR__ . '/default.json';
$insightPath = __DIR__ . '/insights.json';

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
	'user' => $defaultData['user'] ?? '',
	'key' => $defaultData['key'] ?? '',
	'agenda' => is_array($defaultData['agenda'] ?? null) ? $defaultData['agenda'] : [],
	'agendas' => is_array($defaultData['agendas'] ?? null) ? $defaultData['agendas'] : [],
	'agenda_archive' => is_array($defaultData['agenda_archive'] ?? null) ? $defaultData['agenda_archive'] : [],
	'logs' => is_array($defaultData['logs'] ?? null) ? $defaultData['logs'] : [],
	'logs_archive' => is_array($defaultData['logs_archive'] ?? null) ? $defaultData['logs_archive'] : [],
	'registration' => is_array($defaultData['registration'] ?? null) ? $defaultData['registration'] : [],
	'docs' => is_array($defaultData['docs'] ?? null) ? $defaultData['docs'] : [],
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

if (empty($config['logs']) || !is_array($config['logs'])) {
	$config['logs'] = [];
}

if (empty($config['logs_archive']) || !is_array($config['logs_archive'])) {
	$config['logs_archive'] = [];
}

if (empty($config['agendas']) || !is_array($config['agendas'])) {
	$config['agendas'] = [];
}

if (empty($config['agenda_archive']) || !is_array($config['agenda_archive'])) {
	$config['agenda_archive'] = [];
}

if (empty($config['registration']) || !is_array($config['registration'])) {
	$config['registration'] = $defaults['registration'];
}

if (empty($config['divisions']) || !is_array($config['divisions'])) {
	$config['divisions'] = $defaults['divisions'];
}

if (empty($config['docs']) || !is_array($config['docs'])) {
	$config['docs'] = $defaults['docs'];
}

if (empty($config['posts']) || !is_array($config['posts'])) {
	$config['posts'] = $defaults['posts'];
}

$sessionVersion = isset($config['session_version']) ? (int) $config['session_version'] : 1;
if ($sessionVersion < 1) {
	$sessionVersion = 1;
	$config['session_version'] = 1;
}

// Back-compat: jika hanya ada 'agenda' tunggal, jadikan sebagai entri pertama 'agendas'.
if (!empty($config['agenda']) && is_array($config['agenda']) && empty($config['agendas'])) {
	$config['agendas'][] = array_merge($defaults['agenda'], array_intersect_key($config['agenda'], $defaults['agenda']));
}

$agendas = [];
if (!empty($config['agendas']) && is_array($config['agendas'])) {
	foreach ($config['agendas'] as $item) {
		if (!is_array($item)) continue;
		$agendas[] = array_merge($defaults['agenda'], array_intersect_key($item, $defaults['agenda']));
	}
}

$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
$editingIdx = 0;
$registrationDefaults = $defaults['registration'];
$registration = $registrationDefaults;
if (!empty($config['registration']) && is_array($config['registration'])) {
	$registration = array_merge($registrationDefaults, array_intersect_key($config['registration'], $registrationDefaults));
}
$divisionDefaults = $defaults['divisions'];
$divisions = [];
if (!empty($config['divisions']) && is_array($config['divisions'])) {
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
}
if (empty($divisions)) {
	$divisions = $divisionDefaults;
}
$divisionYearStart = (int) date('Y');
$divisionYearLabel = $divisionYearStart . '-' . ($divisionYearStart + 1);
$docDefaults = $defaults['docs'];
$docs = [];
if (!empty($config['docs']) && is_array($config['docs'])) {
	foreach ($config['docs'] as $item) {
		if (!is_array($item)) continue;
		$docs[] = [
			'tag' => trim((string) ($item['tag'] ?? 'Dokumentasi')) ?: 'Dokumentasi',
			'title' => trim((string) ($item['title'] ?? '')) ?: 'Judul dokumentasi',
			'description' => trim((string) ($item['description'] ?? '')) ?: 'Deskripsi dokumentasi.',
			'url' => trim((string) ($item['url'] ?? '')) ?: '#',
		];
	}
}
if (empty($docs)) {
	$docs = $docDefaults;
}

$maxPosts = 15; // Batasi jumlah postingan blog
$postDefaults = is_array($defaults['posts']) ? $defaults['posts'] : [];
$posts = [];
if (!empty($config['posts']) && is_array($config['posts'])) {
	foreach ($config['posts'] as $post) {
		if (!is_array($post)) continue;
		$title = trim((string) ($post['title'] ?? ''));
		$slug = trim((string) ($post['slug'] ?? ''));
		$summary = trim((string) ($post['summary'] ?? ''));
		$body = trim((string) ($post['body'] ?? ''));
		$image = trim((string) ($post['image'] ?? ''));
		$embedEnabled = !empty($post['embed_enabled']);
		$embedList = [];
		if (!empty($post['embeds']) && is_array($post['embeds'])) {
			foreach ($post['embeds'] as $emb) {
				$emb = trim((string) $emb);
				if ($emb !== '') $embedList[] = $emb;
			}
		}
		if (empty($embedList) && !empty($post['embed_html'])) {
			$legacy = trim((string) $post['embed_html']);
			if ($legacy !== '') $embedList[] = $legacy;
		}
		$created = $post['created_at'] ?? date('c');
		$updated = $post['updated_at'] ?? $created;
		$posts[] = [
			'title' => $title ?: 'Tanpa judul',
			'slug' => $slug ?: '',
			'summary' => $summary ?: '',
			'body' => $body,
			'image' => $image,
			'embed_enabled' => $embedEnabled,
			'embeds' => $embedList,
			'created_at' => $created,
			'updated_at' => $updated,
		];
	}
}
if (empty($posts)) {
	$posts = $postDefaults;
}
$posts = array_slice($posts, 0, $maxPosts);

$blogEditingIdx = -1;
$blogDraft = [
	'title' => '',
	'slug' => '',
	'summary' => '',
	'body' => '',
	'image' => '',
	'embeds' => [],
	'embed_enabled' => false,
];

$maxLogs = 30;
$archiveLimitDays = 60;
$maxAgendaArchive = 50; // Aturan: simpan maksimal 50 arsip agenda, hapus selebihnya.

function archiveOldLogs(&$config, $maxLogs, $archiveLimitDays)
{
	$logs = is_array($config['logs']) ? $config['logs'] : [];
	$archive = is_array($config['logs_archive']) ? $config['logs_archive'] : [];
	$thresholdArchive = time() - 86400; // >1 hari pindah ke arsip
	$thresholdDelete = time() - (86400 * 7); // Aturan: hapus log yang lebih lama dari 7 hari
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

	// Bersihkan arsip yang lebih lama dari 7 hari
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
	if ($cleanEvent === '') {
		return;
	}

	if (file_exists($insightPath) && (int) filesize($insightPath) > $insightMaxBytes) {
		return; // stop logging when store already exceeds cap
	}

	$todayKey = date('Y-m-d');
	$defaultPayload = [
		'total' => 0,
		'events' => [],
		'history' => [],
		'updated_at' => date('c'),
	];

	$payload = $defaultPayload;
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
	if (!$fh || $data === false) {
		return;
	}

	try {
		if (!flock($fh, LOCK_EX)) {
			return;
		}
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

if ($loggedIn && isset($_POST['logout_all'])) {
	$sessionVersion++;
	$config['session_version'] = $sessionVersion;
	appendLog($config, $configPath, 'logout-all', $maxLogs, $archiveLimitDays);
	session_destroy();
	$_SESSION = [];
	header('Location: admin.php?msg=logout-all');
	exit;
}

if ($loggedIn && isset($_POST['update_credentials'])) {
	$newUser = trim((string) ($_POST['new_user'] ?? ''));
	$newKey = (string) ($_POST['new_key'] ?? '');
	$confirm = (string) ($_POST['new_key_confirm'] ?? '');

	if (!$credentialUnlocked) {
		$error = 'Masukkan kode superadmin sebelum mengubah user/key.';
	} elseif ($newUser === '' || $newKey === '' || $confirm === '') {
		$error = 'User, key, dan konfirmasi wajib diisi.';
	} elseif ($newKey !== $confirm) {
		$error = 'Konfirmasi key tidak sama.';
	} else {
		$config['user'] = $newUser;
		$config['key'] = $newKey;
		$sessionVersion++;
		$config['session_version'] = $sessionVersion;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan user dan key baru.';
		} else {
			appendLog($config, $configPath, 'auth-updated', $maxLogs, $archiveLimitDays);
			session_destroy();
			$_SESSION = [];
			header('Location: admin.php?msg=cred-updated');
			exit;
		}
	}
}

appendLog($config, $configPath, $loggedIn ? 'view-auth' : 'view-guest', $maxLogs, $archiveLimitDays);

$agendaArchive = is_array($config['agenda_archive']) ? $config['agenda_archive'] : [];

// Arsipkan agenda aktif tertentu
if ($loggedIn && isset($_POST['archive_idx'])) {
	$idx = (int) $_POST['archive_idx'];
	if (isset($agendas[$idx])) {
		$archived = $agendas[$idx];
		$archived['archived_at'] = date('c');
		unset($agendas[$idx]);
		$agendas = array_values($agendas);
		array_unshift($agendaArchive, $archived);
		$agendaArchive = array_slice($agendaArchive, 0, $maxAgendaArchive); // Aturan: simpan maks 50 arsip agenda
		$config['agendas'] = $agendas;
		$config['agenda_archive'] = $agendaArchive;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal mengarsipkan agenda.';
		} else {
			$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
			$message = 'Agenda diarsipkan.';
			appendLog($config, $configPath, 'agenda-archived', $maxLogs, $archiveLimitDays);
			bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
		}
	}
}

// Hapus agenda dari daftar aktif
if ($loggedIn && isset($_POST['delete_idx'])) {
	$idx = (int) $_POST['delete_idx'];
	if (isset($agendas[$idx])) {
		unset($agendas[$idx]);
		$agendas = array_values($agendas);
		$config['agendas'] = $agendas;
		$config['agenda'] = $agendas[0] ?? $defaults['agenda']; // kompatibilitas
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menghapus agenda.';
		} else {
			$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
			$editingIdx = 0;
			$message = 'Agenda dihapus.';
			appendLog($config, $configPath, 'agenda-deleted', $maxLogs, $archiveLimitDays);
		}
	}
}

// Geser urutan agenda aktif (naik/turun)
if ($loggedIn && isset($_POST['move_idx'], $_POST['move_dir'])) {
	$idx = (int) $_POST['move_idx'];
	$dir = $_POST['move_dir'] === 'down' ? 'down' : 'up';
	$maxIdx = count($agendas) - 1;
	if ($idx >= 0 && $idx <= $maxIdx) {
		$target = ($dir === 'up') ? $idx - 1 : $idx + 1;
		if ($target >= 0 && $target <= $maxIdx) {
			$tmp = $agendas[$idx];
			$agendas[$idx] = $agendas[$target];
			$agendas[$target] = $tmp;
			$agendas = array_values($agendas);
			$config['agendas'] = $agendas;
			$config['agenda'] = $agendas[0] ?? $defaults['agenda'];
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal mengubah urutan agenda.';
			} else {
				$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
				$editingIdx = 0;
				$message = 'Urutan agenda diperbarui.';
				appendLog($config, $configPath, 'agenda-reordered', $maxLogs, $archiveLimitDays);
				bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
			}
		}
	}
}

// Perbarui link pendaftaran
if ($loggedIn && (isset($_POST['registration_platform']) || isset($_POST['registration_reset']))) {
	$platforms = ['Google Form', 'Website', 'WhatsApp', 'Telegram', 'Typeform', 'Microsoft Forms', 'Notion', 'Custom'];
	if (isset($_POST['registration_reset'])) {
		$registration = $registrationDefaults;
	} else {
		$platform = trim((string) ($_POST['registration_platform'] ?? ''));
		if (!in_array($platform, $platforms, true)) {
			$platform = 'Custom';
		}
		$url = trim((string) ($_POST['registration_url'] ?? ''));
		if ($url === '') {
			$url = $registrationDefaults['url'];
		}
		$registration = [
			'platform' => $platform,
			'url' => $url,
		];
	}

	$config['registration'] = $registration;
	$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
		$error = 'Gagal menyimpan link pendaftaran.';
	} else {
		$message = isset($_POST['registration_reset']) ? 'Link pendaftaran direset ke default.' : 'Link pendaftaran diperbarui.';
		appendLog($config, $configPath, 'registration-updated', $maxLogs, $archiveLimitDays);
	}
}

// Kembalikan agenda dari arsip ke daftar aktif
if ($loggedIn && isset($_POST['restore_archive_idx'])) {
	$idx = (int) $_POST['restore_archive_idx'];
	if (isset($agendaArchive[$idx])) {
		$restored = $agendaArchive[$idx];
		unset($restored['archived_at']);
		unset($agendaArchive[$idx]);
		$agendaArchive = array_values($agendaArchive);
		array_unshift($agendas, $restored);
		$config['agendas'] = $agendas;
		$config['agenda_archive'] = $agendaArchive;
		$config['agenda'] = $agendas[0] ?? $defaults['agenda']; // kompatibilitas single agenda
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal memulihkan agenda arsip.';
		} else {
			$primaryAgenda = $agendas[0] ?? $defaults['agenda'];
			$message = 'Agenda dikembalikan dari arsip.';
			appendLog($config, $configPath, 'agenda-restored', $maxLogs, $archiveLimitDays);
			bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
		}
	}
}

// Muat agenda tertentu ke form untuk diedit
if ($loggedIn && isset($_POST['edit_idx'])) {
	$idx = (int) $_POST['edit_idx'];
	if (isset($agendas[$idx])) {
		$editingIdx = $idx;
		$primaryAgenda = $agendas[$idx];
	}
}

// Tambah/perbarui agenda aktif
if ($loggedIn && isset($_POST['agenda_tag'], $_POST['agenda_title'], $_POST['agenda_detail'])) {
	$mode = $_POST['agenda_mode'] ?? 'update';
	$editIdx = isset($_POST['agenda_edit_idx']) ? max(0, (int) $_POST['agenda_edit_idx']) : 0;
	$newAgenda = [
		'tag' => trim((string) $_POST['agenda_tag']) ?: $defaults['agenda']['tag'],
		'title' => trim((string) $_POST['agenda_title']) ?: $defaults['agenda']['title'],
		'detail' => trim((string) $_POST['agenda_detail']) ?: $defaults['agenda']['detail'],
	];

	if ($mode === 'new' || empty($agendas)) {
		array_unshift($agendas, $newAgenda);
		$editingIdx = 0;
	} else {
		if (isset($agendas[$editIdx])) {
			$agendas[$editIdx] = $newAgenda;
			$editingIdx = $editIdx;
		} else {
			$agendas[0] = $newAgenda;
			$editingIdx = 0;
		}
	}

	$config['agendas'] = $agendas;
	$config['agenda'] = $agendas[0] ?? $newAgenda; // simpan juga untuk kompatibilitas
	$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
		$error = 'Gagal menyimpan data agenda.';
	} else {
		$primaryAgenda = $agendas[$editingIdx] ?? ($agendas[0] ?? $newAgenda);
		$editingIdx = 0; // reset setelah simpan
		$message = ($mode === 'new') ? 'Agenda baru ditambahkan.' : 'Agenda diperbarui.';
		appendLog($config, $configPath, 'agenda-updated', $maxLogs, $archiveLimitDays);
		bumpInsightEvent('agenda_admin_update', $insightPath, $insightMaxBytes);
	}
}

// Kode superadmin untuk mengelola divisi
if ($loggedIn && isset($_POST['unlock_division_code'])) {
	$code = trim((string) $_POST['unlock_division_code']);
	if ($code === 'superadmin') {
		$divisionUnlocked = true;
		$_SESSION['division_unlocked'] = true; // expire on next page load
		$message = 'Mode superadmin aktif untuk divisi.';
	} else {
		$error = 'Kode superadmin salah.';
	}
}

// Kode superadmin untuk mengubah kredensial admin
if ($loggedIn && isset($_POST['unlock_credential_code'])) {
	$code = trim((string) $_POST['unlock_credential_code']);
	if ($code === 'superadmin') {
		$credentialUnlocked = true;
		$_SESSION['credential_unlocked'] = true; // expire on next page load
		$message = 'Mode superadmin aktif untuk kredensial admin.';
	} else {
		$error = 'Kode superadmin salah.';
	}
}

// Kode superadmin untuk mengelola insight store
if ($loggedIn && isset($_POST['unlock_insights_code'])) {
	$code = trim((string) $_POST['unlock_insights_code']);
	if ($code === 'superadmin') {
		$insightsUnlocked = true;
		$_SESSION['insights_unlocked'] = true; // expire on next page load
		$message = 'Mode superadmin aktif untuk reset insight.';
	} else {
		$error = 'Kode superadmin salah.';
	}
}

// Perbarui divisi
if ($loggedIn && isset($_POST['division_name'], $_POST['division_desc'])) {
	if (!$divisionUnlocked) {
		$error = 'Masukkan kode superadmin sebelum mengubah divisi.';
	} else {
		$names = (array) $_POST['division_name'];
		$descs = (array) $_POST['division_desc'];
		$newDivisions = [];
		foreach ($names as $i => $nameVal) {
			$name = trim((string) $nameVal);
			$desc = trim((string) ($descs[$i] ?? ''));
			if ($name === '' && $desc === '') continue;
			$newDivisions[] = [
				'name' => $name ?: 'Divisi',
				'description' => $desc ?: 'Deskripsi divisi.',
			];
		}
		if (empty($newDivisions)) {
			$newDivisions = $divisionDefaults;
		}
		$divisions = $newDivisions;
		$config['divisions'] = $divisions;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menyimpan divisi.';
		} else {
			$message = 'Divisi diperbarui.';
			appendLog($config, $configPath, 'divisions-updated', $maxLogs, $archiveLimitDays);
		}
	}
}

// Reset insight store (superadmin + size threshold)
if ($loggedIn && isset($_POST['reset_insights'])) {
	if (!$insightsUnlocked) {
		$error = 'Masukkan kode superadmin sebelum mereset insight.';
	} else {
		$newData = [
			'total' => 0,
			'events' => [],
			'history' => [],
			'updated_at' => date('c'),
		];
		$encoded = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($insightPath, $encoded) === false) {
			$error = 'Gagal mereset insights.json.';
		} else {
			$message = 'insights.json berhasil dikosongkan.';
			appendLog($config, $configPath, 'insights-reset', $maxLogs, $archiveLimitDays);
		}
	}
}

// Perbarui kartu dokumentasi (maks 10) dengan 2 kartu default yang tidak bisa dihapus
if ($loggedIn && isset($_POST['doc_tag'], $_POST['doc_title'], $_POST['doc_desc'], $_POST['doc_url'])) {
	$tags = (array) $_POST['doc_tag'];
	$titles = (array) $_POST['doc_title'];
	$descs = (array) $_POST['doc_desc'];
	$urls = (array) $_POST['doc_url'];

	$protected = $docDefaults; // dua kartu wajib
	$protectedCount = count($protected);
	$newDocs = [];
	$limit = max(0, 10 - $protectedCount);
	foreach ($titles as $i => $titleVal) {
		// Abaikan dua kartu pertama (default) meski disubmit
		if ($i < $protectedCount) continue;
		$title = trim((string) $titleVal);
		$tag = trim((string) ($tags[$i] ?? 'Dokumentasi'));
		$desc = trim((string) ($descs[$i] ?? ''));
		$url = trim((string) ($urls[$i] ?? ''));
		if ($title === '' && $desc === '' && $url === '') continue;
		$newDocs[] = [
			'tag' => $tag ?: 'Dokumentasi',
			'title' => $title ?: 'Judul dokumentasi',
			'description' => $desc ?: 'Deskripsi dokumentasi.',
			'url' => $url ?: '#',
		];
		if (count($newDocs) >= $limit) break;
	}

	$docs = array_merge($protected, $newDocs);
	$config['docs'] = $docs;
	$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
		$error = 'Gagal menyimpan dokumentasi.';
	} else {
		$message = 'Kartu dokumentasi diperbarui.';
		appendLog($config, $configPath, 'docs-updated', $maxLogs, $archiveLimitDays);
	}
}

// Pilih blog untuk diedit
if ($loggedIn && isset($_POST['blog_edit_idx'])) {
	$reqIdx = (int) $_POST['blog_edit_idx'];
	if (isset($posts[$reqIdx])) {
		$blogEditingIdx = $reqIdx;
		$blogDraft = [
			'title' => $posts[$reqIdx]['title'] ?? '',
			'slug' => $posts[$reqIdx]['slug'] ?? '',
			'summary' => $posts[$reqIdx]['summary'] ?? '',
			'body' => $posts[$reqIdx]['body'] ?? '',
			'image' => $posts[$reqIdx]['image'] ?? '',
			'embeds' => !empty($posts[$reqIdx]['embeds']) && is_array($posts[$reqIdx]['embeds']) ? $posts[$reqIdx]['embeds'] : [],
			'embed_enabled' => !empty($posts[$reqIdx]['embed_enabled']),
		];
	} elseif ($reqIdx === -1) {
		$blogEditingIdx = -1;
		$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'embeds' => [], 'embed_enabled' => false];
	}
}

// Hapus blog
if ($loggedIn && isset($_POST['delete_blog_idx'])) {
	$delIdx = (int) $_POST['delete_blog_idx'];
	if (isset($posts[$delIdx])) {
		unset($posts[$delIdx]);
		$posts = array_values($posts);
		$config['posts'] = $posts;
		$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
			$error = 'Gagal menghapus postingan.';
		} else {
			$message = 'Postingan dihapus.';
			appendLog($config, $configPath, 'blog-deleted', $maxLogs, $archiveLimitDays);
		}
	}
}

// Simpan blog baru atau edit
if ($loggedIn && isset($_POST['blog_save'])) {
	$title = trim((string) ($_POST['blog_title'] ?? ''));
	$slugInput = trim((string) ($_POST['blog_slug'] ?? ''));
	$summary = trim((string) ($_POST['blog_summary'] ?? ''));
	$body = trim((string) ($_POST['blog_body'] ?? ''));
	$embedEnabled = isset($_POST['blog_embed_enabled']) && $_POST['blog_embed_enabled'] === '1';
	$embedInputs = isset($_POST['blog_embed_html']) ? (array) $_POST['blog_embed_html'] : [];
	$embedList = [];
	foreach ($embedInputs as $emb) {
		$emb = trim((string) $emb);
		if ($emb !== '') {
			$embedList[] = $emb;
		}
	}
	$image = trim((string) ($_POST['blog_image'] ?? ''));
	$idx = isset($_POST['blog_edit_idx']) ? (int) $_POST['blog_edit_idx'] : -1;
	$now = date('c');
	$created = ($idx >= 0 && isset($posts[$idx])) ? ($posts[$idx]['created_at'] ?? $now) : $now;
	$slug = makeSlug($slugInput !== '' ? $slugInput : $title);

	$exceedsLimit = ($idx < 0 && count($posts) >= $maxPosts);
	if ($exceedsLimit) {
		$error = 'Maksimal ' . $maxPosts . ' postingan. Hapus salah satu sebelum menambah baru.';
		$blogDraft = [
			'title' => $title,
			'slug' => $slugInput,
			'summary' => $summary,
			'body' => $body,
			'image' => $image,
			'embeds' => $embedList,
			'embed_enabled' => $embedEnabled,
		];
		$blogEditingIdx = -1;
	} else {
		// Tolak duplikasi judul atau slug (case-insensitive), abaikan entri yang sedang diedit.
		$normTitle = strtolower($title);
		$normSlug = strtolower($slug);
		$duplicateFound = false;
		foreach ($posts as $i => $p) {
			$existingTitle = strtolower(trim((string) ($p['title'] ?? '')));
			$existingSlug = strtolower(trim((string) ($p['slug'] ?? '')));
			if ($i !== $idx && ($existingTitle === $normTitle || $existingSlug === $normSlug)) {
				$error = 'Judul atau slug sudah dipakai. Gunakan yang lain.';
				$duplicateFound = true;
				$blogDraft = [
					'title' => $title,
					'slug' => $slugInput,
					'summary' => $summary,
					'body' => $body,
					'image' => $image,
					'embeds' => $embedList,
					'embed_enabled' => $embedEnabled,
				];
				$blogEditingIdx = $idx;
				break;
			}
		}

		if (!$duplicateFound) {
			if ($summary === '') {
				$tmpSummary = strip_tags($body);
				$tmpSummary = preg_replace('/\s+/', ' ', $tmpSummary);
				$summary = substr($tmpSummary, 0, 180);
				if (strlen($tmpSummary) > 180) {
					$summary .= '...';
				}
				if ($summary === '' && $embedEnabled && !empty($embedList)) {
					$summary = 'Konten tersemat tersedia.';
				}
			}
			$newPost = [
				'title' => $title ?: 'Tanpa judul',
				'slug' => $slug,
				'summary' => $summary,
				'body' => $body,
				'image' => $image,
				'embeds' => $embedList,
				'embed_enabled' => $embedEnabled,
				'created_at' => $created,
				'updated_at' => $now,
			];

			if ($idx >= 0 && isset($posts[$idx])) {
				$posts[$idx] = $newPost;
				$blogEditingIdx = $idx;
			} else {
				array_unshift($posts, $newPost);
				$posts = array_slice($posts, 0, $maxPosts);
				$blogEditingIdx = 0;
			}

			$config['posts'] = $posts;
			$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
			if ($encoded === false || file_put_contents($configPath, $encoded) === false) {
				$error = 'Gagal menyimpan postingan.';
			} else {
				$message = 'Postingan disimpan.';
				appendLog($config, $configPath, 'blog-updated', $maxLogs, $archiveLimitDays);
				$blogEditingIdx = -1;
				$blogDraft = ['title' => '', 'slug' => '', 'summary' => '', 'body' => '', 'image' => '', 'embeds' => [], 'embed_enabled' => false];
			}
		}
	}
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Halaman Admin</title>
	<link rel="icon" type="image/png" href="logo-ukmi.png">
	<link rel="apple-touch-icon" href="logo-ukmi.png">
	<link rel="shortcut icon" href="logo-ukmi.png">
	<meta name="theme-color" content="#0f172a">
	<script>
		(function() {
			const SCROLL_KEY = 'admin_scroll_y';
			const saved = sessionStorage.getItem(SCROLL_KEY);
			if (saved) {
				history.scrollRestoration = 'manual';
				const y = parseInt(saved, 10);
				if (!Number.isNaN(y)) {
					window.__ADMIN_SAVED_SCROLL__ = y;
				}
			}
		})();
	</script>
	<style>
		:root {
			--bg: #0f172a;
			--panel: #111827;
			--accent: #38bdf8;
			--muted: #94a3b8;
			--text: #e2e8f0;
			--danger: #f87171;
		}

		body {
			margin: 0;
			min-height: 100vh;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			background: radial-gradient(circle at 20% 20%, rgba(56, 189, 248, 0.12), transparent 35%),
						radial-gradient(circle at 80% 0%, rgba(248, 113, 113, 0.08), transparent 30%),
						var(--bg);
			color: var(--text);
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 24px;
		}

		body.is-auth { padding: 32px 32px 40px; }

		.card {
			background: linear-gradient(145deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0));
			border: 1px solid rgba(148, 163, 184, 0.2);
			border-radius: 16px;
			width: min(100%, 520px);
			padding: 24px;
			box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
			backdrop-filter: blur(10px);
		}

		body.is-auth .card {
			width: min(100%, 1240px);
			max-width: 96vw;
			padding: 28px 30px;
		}

		h1 {
			margin: 0 0 12px;
			font-size: 22px;
			letter-spacing: 0.2px;
		}

		p {
			margin: 0 0 16px;
			color: var(--muted);
			line-height: 1.5;
		}

		form {
			display: flex;
			flex-direction: column;
			gap: 12px;
		}

		label {
			font-size: 14px;
			color: var(--muted);
			display: block;
			margin-bottom: 4px;
		}

		input[type="text"],
		input[type="password"],
		textarea {
			padding: 10px 0.1cm;
			border-radius: 10px;
			border: 1px solid rgba(148, 163, 184, 0.3);
			background: rgba(15, 23, 42, 0.4);
			color: var(--text);
			font-size: 14px;
			transition: border-color 0.2s ease, box-shadow 0.2s ease;
			box-sizing: border-box;
		}

		textarea { width: 100%; min-width: 0; min-height: 110px; resize: both; font-family: inherit; }

		/* Override inline paddings to keep columns within card */
		input[type="text"],
		input[type="password"],
		textarea,
		select,
		input[type="url"] {
			padding: 10px 0.1cm !important;
			box-sizing: border-box;
		}

		.field-grid > * { min-width: 0; }

		/* Prevent overflow/overlap in documentation cards on desktop */
		.doc-item,
		.doc-item * { min-width: 0; }
		.doc-item input[type="text"],
		.doc-item textarea { width: 100%; }

		/* Prevent overflow/overlap in division cards on desktop */
		.division-item,
		.division-item * { min-width: 0; }
		.division-item input[type="text"],
		.division-item textarea { width: 100%; }

		input[type="text"]:focus,
		input[type="password"]:focus,
		textarea:focus {
			outline: none;
			border-color: var(--accent);
			box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
		}

		.actions {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 12px;
			margin-top: 4px;
		}

		button,
		a.button-link {
			width: 100%;
			border: none;
			border-radius: 10px;
			padding: 12px 14px;
			background: linear-gradient(135deg, #38bdf8, #0ea5e9);
			color: #0b1727;
			font-weight: 700;
			cursor: pointer;
			text-decoration: none;
			text-align: center;
			transition: transform 0.15s ease, box-shadow 0.15s ease;
			box-shadow: 0 10px 30px rgba(14, 165, 233, 0.35);
		}

		button:hover,
		a.button-link:hover {
			transform: translateY(-1px);
			box-shadow: 0 14px 34px rgba(14, 165, 233, 0.45);
		}

		.toast-wrap {
			position: fixed;
			right: 18px;
			bottom: 18px;
			display: flex;
			flex-direction: column;
			gap: 8px;
			max-width: 340px;
			z-index: 30;
			pointer-events: none;
		}

		.toast {
			pointer-events: auto;
			padding: 12px 14px;
			border-radius: 12px;
			background: rgba(17, 24, 39, 0.95);
			border: 1px solid rgba(148, 163, 184, 0.35);
			color: var(--text);
			box-shadow: 0 18px 36px rgba(0, 0, 0, 0.35);
			transform: translateY(10px);
			opacity: 0;
			transition: transform 0.25s ease, opacity 0.25s ease;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 10px;
		}

		.toast.show {
			transform: translateY(0);
			opacity: 1;
		}

		.toast.success { border-color: rgba(56, 189, 248, 0.6); }
		.toast.danger { border-color: rgba(248, 113, 113, 0.7); }

		.toast button.toast-close {
			background: transparent;
			color: var(--text);
			border: none;
			cursor: pointer;
			padding: 6px 8px;
			border-radius: 8px;
		}

		button:active,
		a.button-link:active {
			transform: translateY(0);
		}

		.toggle {
			position: relative;
			display: inline-block;
			width: 48px;
			height: 26px;
		}

		.toggle input { opacity: 0; width: 0; height: 0; }

		.slider {
			position: absolute;
			cursor: pointer;
			top: 0; left: 0; right: 0; bottom: 0;
			background: rgba(148, 163, 184, 0.35);
			transition: .2s;
			border-radius: 26px;
		}

		.slider:before {
			position: absolute;
			content: '';
			height: 20px;
			width: 20px;
			left: 3px;
			bottom: 3px;
			background: #0b1727;
			transition: .2s;
			border-radius: 50%;
			box-shadow: 0 4px 10px rgba(0,0,0,0.25);
		}

		input:checked + .slider {
			background: linear-gradient(135deg, #38bdf8, #0ea5e9);
		}

		input:checked + .slider:before {
			transform: translateX(22px);
		}

		.error {
			margin-top: 8px;
			padding: 10px 12px;
			border-radius: 10px;
			background: rgba(248, 113, 113, 0.12);
			border: 1px solid rgba(248, 113, 113, 0.25);
			color: var(--danger);
			font-size: 14px;
		}

		.success {
			margin-top: 8px;
			padding: 10px 12px;
			border-radius: 10px;
			background: rgba(56, 189, 248, 0.12);
			border: 1px solid rgba(56, 189, 248, 0.3);
			color: var(--text);
			font-size: 14px;
		}

		.admin-box {
			padding: 18px;
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.03);
			border: 1px dashed rgba(148, 163, 184, 0.3);
			margin-top: 12px;
		}

		.brand-admin {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 10px;
		}

		.brand-admin img {
			width: 36px;
			height: 36px;
			border-radius: 50%;
			object-fit: cover;
			border: 1px solid rgba(148, 163, 184, 0.35);
		}

		.brand-admin .title {
			font-weight: 700;
			letter-spacing: 0.3px;
		}

		.section {
			margin-top: 12px;
			padding: 14px;
			border: 1px solid rgba(148, 163, 184, 0.18);
			border-radius: 12px;
			background: rgba(255, 255, 255, 0.02);
		}

		.field-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 12px;
		}

		body.is-auth .field-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }

		@media (min-width: 1280px) {
			body.is-auth .card { width: min(100%, 1400px); }
			body.is-auth .field-grid { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
		}

		.field-grid .full { grid-column: 1 / -1; }

		.inline-actions {
			display: flex;
			gap: 12px;
			flex-wrap: wrap;
		}

		.inline-actions > * { flex: 1; }

		.muted-text { color: var(--muted); font-size: 13px; margin: 4px 0 0; }

		.logs {
			margin-top: 12px;
			padding: 0;
			list-style: none;
			border: 1px solid rgba(148, 163, 184, 0.2);
			border-radius: 10px;
			overflow: hidden;
		}

		.logs li {
			padding: 10px 12px;
			border-bottom: 1px solid rgba(148, 163, 184, 0.15);
			color: var(--muted);
			font-size: 13px;
			line-height: 1.4;
		}

		.logs li strong { color: var(--text); }

		.logs li:last-child { border-bottom: none; }

		.archive-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
			gap: 10px;
			margin-top: 10px;
		}

		.archive-day {
			padding: 10px 12px;
			border-radius: 10px;
			border: 1px solid rgba(148, 163, 184, 0.25);
			background: rgba(255, 255, 255, 0.04);
			color: var(--text);
			cursor: pointer;
			text-align: left;
			transition: border-color 0.2s ease, transform 0.15s ease;
		}

		.archive-day:hover { transform: translateY(-1px); border-color: var(--accent); }

		.archive-entries { display: none; margin-top: 8px; }
		.archive-entries.open { display: block; }

		@media (max-width: 480px) {
			.card { padding: 20px; }
			h1 { font-size: 20px; }
		}
	</style>
</head>
<body class="<?php echo $loggedIn ? 'is-auth' : 'is-guest'; ?>">
	<?php if ($message || $error): ?>
	<div class="toast-wrap" id="toast-wrap">
		<?php if ($message): ?>
			<div class="toast success" data-kind="success">
				<span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
				<button type="button" class="toast-close" aria-label="Tutup">×</button>
			</div>
		<?php endif; ?>
		<?php if ($error): ?>
			<div class="toast danger" data-kind="danger">
				<span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
				<button type="button" class="toast-close" aria-label="Tutup">×</button>
			</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<div class="card">
		<div style="margin-top:8px; float:right;">
			<a href="https://github.com/krasyid822/ukmipolmed.xo.je" target="_blank" rel="noopener" style="color:inherit; text-decoration:underline; font-size:13px;" title="Sisi belakang aplikasi ini!"><svg aria-hidden="true" focusable="false" class="octicon octicon-mark-github" viewBox="0 0 24 24" width="32" height="32" fill="currentColor" display="inline-block" overflow="visible" style="vertical-align:text-bottom"><path d="M12 1C5.923 1 1 5.923 1 12c0 4.867 3.149 8.979 7.521 10.436.55.096.756-.233.756-.522 0-.262-.013-1.128-.013-2.049-2.764.509-3.479-.674-3.699-1.292-.124-.317-.66-1.293-1.127-1.554-.385-.207-.936-.715-.014-.729.866-.014 1.485.797 1.691 1.128.99 1.663 2.571 1.196 3.204.907.096-.715.385-1.196.701-1.471-2.448-.275-5.005-1.224-5.005-5.432 0-1.196.426-2.186 1.128-2.956-.111-.275-.496-1.402.11-2.915 0 0 .921-.288 3.024 1.128a10.193 10.193 0 0 1 2.75-.371c.936 0 1.871.123 2.75.371 2.104-1.43 3.025-1.128 3.025-1.128.605 1.513.221 2.64.111 2.915.701.77 1.127 1.747 1.127 2.956 0 4.222-2.571 5.157-5.019 5.432.399.344.743 1.004.743 2.035 0 1.471-.014 2.654-.014 3.025 0 .289.206.632.756.522C19.851 20.979 23 16.854 23 12c0-6.077-4.922-11-11-11Z"></path></svg></a>
		</div>
		<div class="brand-admin">
			<img src="logo-ukmi.png" alt="Logo UKMI Polmed">
			<div class="title">UKMI Polmed · Admin</div>
		</div>
		<?php if ($loggedIn): ?>
			<h1>Panel Admin</h1>
			<p>Kelola, Anda adalah admin.</p>
			<?php
				$insightSizeBytes = file_exists($insightPath) ? (int) filesize($insightPath) : 0;
				$insightSizeMB = round($insightSizeBytes / (1024 * 1024), 2);
				$insightLimitMB = round($insightMaxBytes / (1024 * 1024), 2);
			?>
			<div class="section" style="margin-top: 10px;">
				<h2 style="margin:0 0 8px; font-size:15px;">Penyimpanan insight</h2>
				<p class="muted-text" style="margin:0 0 8px;">Ukuran saat ini: <?php echo htmlspecialchars(number_format($insightSizeMB, 2), ENT_QUOTES, 'UTF-8'); ?> MB / <?php echo htmlspecialchars($insightLimitMB, ENT_QUOTES, 'UTF-8'); ?> MB.</p>
				<form method="post" class="stack-sm" style="margin-bottom:8px;">
					<div class="inline-actions" style="align-items:center; gap:10px;">
						<input type="text" name="unlock_insights_code" placeholder="Ketik superadmin untuk reset" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="off">
						<button type="submit" style="max-width:180px;">Buka</button>
					</div>
				</form>
				<form method="post" class="stack-sm" style="<?php echo $insightsUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>">
					<input type="hidden" name="reset_insights" value="1">
					<p class="muted-text" style="margin:0 0 6px;">Sebaiknya hanya bersihkan jika terjadi pergantian kepengurusan.</p>
					<button type="submit" style="background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35);">Kosongkan insights.json</button>
				</form>
			</div>
			<div class="section" style="margin-top: 8px;">
				<h2 style="margin:0 0 8px; font-size:15px;">Sesi</h2>
				<div class="inline-actions" style="align-items:center; gap:10px;">
					<a class="button-link" href="?logout=1" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148, 163, 184, 0.25);">Keluar</a>
					<form method="post" class="stack-sm" style="margin:0;">
						<input type="hidden" name="logout_all" value="1">
						<button type="submit" style="background: linear-gradient(135deg, #f87171, #ef4444); box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35);">Logout semua sesi</button>
					</form>
				</div>
				<p class="muted-text" style="margin:6px 0 0;">"Keluar" hanya mengakhiri sesi ini. "Logout semua sesi" memaksa semua browser keluar dan dicatat di log.</p>
			</div>
			<div class="section" style="margin-top: 10px;">
				<h2 style="margin:0 0 8px; font-size:15px;">Kredensial admin</h2>
				<form method="post" class="stack-sm" style="margin-bottom:10px;">
					<div class="inline-actions" style="align-items:center; gap:10px;">
						<input type="text" name="unlock_credential_code" placeholder="Ketik superadmin untuk mengubah" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="off">
						<button type="submit" style="max-width:180px;">Buka</button>
					</div>
				</form>
				<form method="post" class="stack-sm" style="<?php echo $credentialUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>">
					<input type="hidden" name="update_credentials" value="1">
					<div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
						<div>
							<label for="new_user">User baru</label>
							<input type="text" id="new_user" name="new_user" required placeholder="admin" style="min-width:220px;">
						</div>
						<div>
							<label for="new_key">Key baru</label>
							<input type="password" id="new_key" name="new_key" required autocomplete="new-password" placeholder="********" style="min-width:220px;">
						</div>
						<div>
							<label for="new_key_confirm">Konfirmasi key</label>
							<input type="password" id="new_key_confirm" name="new_key_confirm" required autocomplete="new-password" placeholder="ulang key" style="min-width:220px;">
						</div>
					</div>
					<div class="inline-actions" style="margin-top:6px; align-items:center;">
						<button type="submit">Simpan user & key</button>
						<p class="muted-text" style="margin:0;">Menyimpan akan mengganti user/key, memaksa logout semua sesi, dan tercatat di log.</p>
					</div>
				</form>
			</div>
			<div class="admin-box" style="margin-top:10px;">
				<h2 style="margin:0 0 10px; font-size:16px;">Log akses terbaru</h2>
				<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:center;">
					<label style="font-size:13px; color: var(--muted);">Event
						<select id="log-filter-event" style="margin-left:6px; padding:8px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
							<option value="">Semua</option>
							<option value="login-success">login-success</option>
							<option value="login-fail">login-fail</option>
							<option value="auth-updated">auth-updated</option>
							<option value="view-auth">view-auth</option>
							<option value="view-guest">view-guest</option>
							<option value="agenda-updated">agenda-updated</option>
							<option value="agenda-deleted">agenda-deleted</option>
							<option value="agenda-restored">agenda-restored</option>
							<option value="agenda-reordered">agenda-reordered</option>
							<option value="registration-updated">registration-updated</option>
							<option value="divisions-updated">divisions-updated</option>
							<option value="docs-updated">docs-updated</option>
							<option value="blog-updated">blog-updated</option>
							<option value="blog-deleted">blog-deleted</option>
							<option value="logout-all">logout-all</option>
						</select>
					</label>
					<label style="font-size:13px; color: var(--muted);">Cari
						<input id="log-filter-text" type="text" placeholder="IP/UA/keyword" style="margin-left:6px; padding:8px 10px; border-radius:8px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); width: 200px;">
					</label>
				</div>
				<?php $recentLogs = array_slice($config['logs'], 0, 10); ?>
				<?php if (empty($recentLogs)): ?>
					<p style="margin:0; color: var(--muted);">Belum ada log akses.</p>
				<?php else: ?>
					<ul class="logs log-list">
						<?php foreach ($recentLogs as $log): ?>
							<li class="log-item" data-event="<?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>" data-text="<?php echo htmlspecialchars(($log['event'] ?? '-') . ' ' . ($log['time'] ?? '-') . ' ' . ($log['ip'] ?? '-') . ' ' . ($log['ua'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>">
								<strong><?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
								<br><?php echo htmlspecialchars($log['time'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
								<br><?php echo htmlspecialchars($log['ip'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
								<br><span style="opacity:0.8;">UA: <?php echo htmlspecialchars($log['ua'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<hr style="border: 0; border-top: 1px solid rgba(148,163,184,0.25); margin: 12px 0;">
				<h3 style="margin:0 0 8px; font-size:15px;">Arsip (>1 hari)</h3>
				<?php
				$archiveDates = array_keys($config['logs_archive']);
				rsort($archiveDates);
				$archiveDates = array_slice($archiveDates, 0, 12);
				?>
				<?php if (empty($archiveDates)): ?>
					<p style="margin:0; color: var(--muted);">Belum ada arsip.</p>
				<?php else: ?>
					<div class="archive-grid">
						<?php foreach ($archiveDates as $idx => $day): $targetId = 'archive-'.$idx; $dayEntries = $config['logs_archive'][$day]; ?>
							<button type="button" class="archive-day" data-target="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>">
								<strong><?php echo htmlspecialchars($day, ENT_QUOTES, 'UTF-8'); ?></strong><br>
								<small style="color: var(--muted);">Log: <?php echo count($dayEntries); ?></small>
							</button>
							<div class="archive-entries" id="<?php echo htmlspecialchars($targetId, ENT_QUOTES, 'UTF-8'); ?>">
								<ul class="logs log-list" style="margin-top:6px;">
									<?php foreach ($dayEntries as $log): ?>
										<li class="log-item" data-event="<?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>" data-text="<?php echo htmlspecialchars(($log['event'] ?? '-') . ' ' . ($log['time'] ?? '-') . ' ' . ($log['ip'] ?? '-') . ' ' . ($log['ua'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>">
											<strong><?php echo htmlspecialchars($log['event'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
											<br><?php echo htmlspecialchars($log['time'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
											<br><?php echo htmlspecialchars($log['ip'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
											<br><span style="opacity:0.8;">UA: <?php echo htmlspecialchars($log['ua'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ($message): ?>
				<div class="success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<?php if ($error): ?>
				<div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<div class="section">
				<h2 style="margin:0 0 8px; font-size:16px;">Agenda terdekat</h2>
				<form method="post" class="stack-sm" id="agenda-form" data-autosave-key="agenda">
					<input type="hidden" name="agenda_edit_idx" value="<?php echo (int) $editingIdx; ?>">
					<div class="field-grid">
						<div>
							<label for="agenda_tag">Tag</label>
							<input type="text" id="agenda_tag" name="agenda_tag" style="min-width:260px;" value="<?php echo htmlspecialchars($primaryAgenda['tag'], ENT_QUOTES, 'UTF-8'); ?>" required>
						</div>
						<div>
							<label for="agenda_title">Judul</label>
							<input type="text" id="agenda_title" name="agenda_title" style="min-width:260px;" value="<?php echo htmlspecialchars($primaryAgenda['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
						</div>
						<div class="full">
							<label for="agenda_detail">Detail</label>
							<textarea id="agenda_detail" name="agenda_detail" required><?php echo htmlspecialchars($primaryAgenda['detail'], ENT_QUOTES, 'UTF-8'); ?></textarea>
							<p class="muted-text">Contoh: Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI Polmed 2026-2027.</p>
						</div>
					</div>
						<div class="inline-actions" style="align-items:center;">
							<label style="margin:0; display:flex; gap:6px; align-items:center; color: var(--muted); font-size:13px;">
								<input type="radio" name="agenda_mode" value="update" <?php echo (!isset($_POST['agenda_mode']) || ($_POST['agenda_mode'] ?? '') !== 'new') ? 'checked' : ''; ?>> Perbarui agenda dipilih
							</label>
							<label style="margin:0; display:flex; gap:6px; align-items:center; color: var(--muted); font-size:13px;">
								<input type="radio" name="agenda_mode" value="new" <?php echo (($_POST['agenda_mode'] ?? '') === 'new') ? 'checked' : ''; ?>> Simpan sebagai agenda baru
							</label>
						</div>
						<p class="muted-text" style="margin:4px 0 0;">Saat ini mengedit agenda ke-<?php echo (int) $editingIdx + 1; ?> (klik Edit di daftar untuk memilih).</p>
					<div class="inline-actions" style="margin-top: 4px;">
						<button type="submit">Simpan agenda</button>
					</div>
				</form>
						<div class="admin-box" style="margin-top:10px;">
							<h3 style="margin:0 0 8px; font-size:15px;">Agenda aktif (diputar bergantian)</h3>
							<?php if (empty($agendas)): ?>
								<p class="muted-text" style="margin:0;">Belum ada agenda aktif.</p>
							<?php else: ?>
								<ul class="logs" style="margin-top:6px;">
									<?php foreach ($agendas as $idx => $item): ?>
										<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
											<div>
												<strong><?php echo htmlspecialchars($item['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
												<br><span style="color: var(--muted); font-size:12px;"><?php echo htmlspecialchars($item['tag'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
												<br><span style="color: var(--muted); font-size:12px;">Detail: <?php echo htmlspecialchars($item['detail'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
												<?php if ($idx === 0): ?><br><span style="font-size:12px; color: var(--accent);">(utama)</span><?php endif; ?>
											</div>
											<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
												<form method="post" style="margin:0;">
													<input type="hidden" name="edit_idx" value="<?php echo $idx; ?>">
													<button type="submit" style="min-width:80px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Edit</button>
												</form>
												<form method="post" style="margin:0;">
													<input type="hidden" name="archive_idx" value="<?php echo $idx; ?>">
													<button type="submit" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Arsipkan</button>
												</form>
												<form method="post" style="margin:0;" onsubmit="return confirm('Hapus agenda ini?');">
													<input type="hidden" name="delete_idx" value="<?php echo $idx; ?>">
													<button type="submit" style="min-width:90px; background: linear-gradient(135deg, #f87171, #ef4444); color: #0b1727; box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35); border: none;">Hapus</button>
												</form>
												<div style="display:flex; gap:4px;">
													<form method="post" style="margin:0;">
														<input type="hidden" name="move_idx" value="<?php echo $idx; ?>">
														<input type="hidden" name="move_dir" value="up">
														<button type="submit" title="Naikkan urutan" style="min-width:40px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);" <?php echo $idx === 0 ? 'disabled' : ''; ?>>↑</button>
													</form>
													<form method="post" style="margin:0;">
														<input type="hidden" name="move_idx" value="<?php echo $idx; ?>">
														<input type="hidden" name="move_dir" value="down">
														<button type="submit" title="Turunkan urutan" style="min-width:40px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);" <?php echo ($idx === count($agendas)-1) ? 'disabled' : ''; ?>>↓</button>
													</form>
												</div>
											</div>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
						<div class="admin-box" style="margin-top:8px;">
							<h3 style="margin:0 0 8px; font-size:15px;">Arsip agenda</h3>
							<?php if (empty($agendaArchive)): ?>
								<p class="muted-text" style="margin:0;">Belum ada arsip agenda.</p>
							<?php else: ?>
								<ul class="logs" style="margin-top:6px; max-height:220px; overflow:auto;">
									<?php foreach ($agendaArchive as $idx => $item): ?>
										<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
											<div>
												<strong><?php echo htmlspecialchars($item['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
												<br><span style="color: var(--muted); font-size:12px;"><?php echo htmlspecialchars($item['tag'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
												<br><span style="color: var(--muted); font-size:12px;">Detail: <?php echo htmlspecialchars($item['detail'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
												<?php if (!empty($item['archived_at'])): ?><br><span style="font-size:12px; color: var(--muted);">Diarsipkan: <?php echo htmlspecialchars($item['archived_at'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
											</div>
										<form method="post" style="margin:0;">
											<input type="hidden" name="restore_archive_idx" value="<?php echo $idx; ?>">
											<button type="submit" style="min-width:110px; background: linear-gradient(135deg, #38bdf8, #0ea5e9); color: #0b1727; box-shadow: 0 10px 30px rgba(14, 165, 233, 0.35);">Pulihkan</button>
										</form>
									</li>
									<?php endforeach; ?>
								</ul>
								<p class="muted-text" style="margin:6px 0 0;">Menampilkan sampai 50 arsip terbaru. Mengarsipkan agenda baru akan memotong yang paling lama.</p>
							<?php endif; ?>
						</div>
			</div>
				<div class="section" style="margin-top: 10px;">
					<h2 style="margin:0 0 8px; font-size:16px;">Link form pendaftaran</h2>
					<form method="post" class="stack-sm" id="registration-form" data-autosave-key="registration">
						<div class="field-grid">
							<div>
								<label for="registration_platform">Platform</label>
								<select id="registration_platform" name="registration_platform" style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
									<?php
									$platformOptions = ['Google Form', 'Website', 'WhatsApp', 'Telegram', 'Typeform', 'Microsoft Forms', 'Notion', 'Custom'];
									foreach ($platformOptions as $opt):
										$selected = ($registration['platform'] === $opt) ? 'selected' : '';
									?>
									<option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="full">
								<label for="registration_url">Link form</label>
								<input type="text" id="registration_url" name="registration_url" value="<?php echo htmlspecialchars($registration['url'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://forms.gle/..." style="width:100%; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
								<p class="muted-text" style="margin:4px 0 0;">Link akan dipakai di tombol "Daftar sekarang" di halaman utama. Kosongkan untuk kembali ke default.</p>
							</div>
						</div>
						<div class="inline-actions" style="margin-top: 6px;">
							<button type="submit" name="registration_save" value="1">Simpan link daftar</button>
							<button type="submit" name="registration_reset" value="1" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Reset ke default</button>
							<button type="button" id="registration_fill_template" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tempelkan template</button>
						</div>
					</form>
				</div>
				<div class="section" style="margin-top: 10px;">
					<h2 style="margin:0 0 8px; font-size:16px;">Divisi kepengurusan <?php echo htmlspecialchars($divisionYearLabel, ENT_QUOTES, 'UTF-8'); ?></h2>
					<form method="post" class="stack-sm">
						<div class="inline-actions" style="align-items:center;">
							<input type="text" name="unlock_division_code" placeholder="Ketik superadmin untuk mengubah" style="flex:1; padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" autocomplete="off">
							<button type="submit" style="max-width:180px;">Buka</button>
						</div>
					</form>
					<form method="post" class="stack-sm" id="divisions-form" data-autosave-key="divisions" style="margin-top:10px; <?php echo $divisionUnlocked ? '' : 'opacity:0.6; pointer-events:none;'; ?>">
						<div id="division-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
							<?php foreach ($divisions as $idx => $div): ?>
							<div class="division-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
								<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Nama divisi</label>
								<input type="text" name="division_name[]" value="<?php echo htmlspecialchars($div['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
								<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
								<textarea name="division_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"><?php echo htmlspecialchars($div['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
								<div style="text-align:right; margin-top:6px;">
									<button type="button" class="remove-division" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
						<div class="inline-actions" style="margin-top: 6px;">
							<button type="button" id="add-division" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah divisi</button>
							<button type="submit">Simpan divisi</button>
						</div>
						<p class="muted-text" style="margin:4px 0 0;">Urutan di sini akan ditampilkan di halaman utama. Tahun kepengurusan otomatis: <?php echo htmlspecialchars($divisionYearLabel, ENT_QUOTES, 'UTF-8'); ?>.</p>
					</form>
				</div>
				<div class="section" style="margin-top: 10px;">
					<h2 style="margin:0 0 8px; font-size:16px;">Kartu dokumentasi kegiatan (maks 10)</h2>
					<form method="post" class="stack-sm" id="docs-form" data-autosave-key="docs">
						<div id="doc-list" class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:10px;">
							<?php foreach ($docs as $docIdx => $doc): $isProtectedDoc = $docIdx < count($docDefaults); ?>
							<div class="doc-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
								<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Tag</label>
								<input type="text" name="doc_tag[]" value="<?php echo htmlspecialchars($doc['tag'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
								<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
								<input type="text" name="doc_title[]" value="<?php echo htmlspecialchars($doc['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
								<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
								<textarea name="doc_desc[]" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"><?php echo htmlspecialchars($doc['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
								<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Link</label>
								<input type="text" name="doc_url[]" value="<?php echo htmlspecialchars($doc['url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isProtectedDoc ? 'readonly' : ''; ?> style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="https://...">
								<div style="text-align:right; margin-top:6px;">
									<button type="button" class="remove-doc" <?php echo ($docIdx < count($docDefaults)) ? 'disabled' : ''; ?> style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
						<div class="inline-actions" style="margin-top: 6px;">
							<button type="button" id="add-doc" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah kartu</button>
							<button type="submit">Simpan dokumentasi</button>
						</div>
						<p class="muted-text" style="margin:4px 0 0;">Maksimal 10 kartu. Jika semua kolom dikosongkan, akan kembali ke default.</p>
					</form>
				</div>
				<div class="section" style="margin-top: 10px;">
					<h2 style="margin:0 0 8px; font-size:16px;">Blog</h2>
					<?php $postCount = count($posts); $blogLimitReached = ($postCount >= $maxPosts && $blogEditingIdx === -1); ?>
					<p class="muted-text" style="margin:0 0 10px; <?php echo $blogLimitReached ? 'color:#f87171;' : ''; ?>">Maksimal <?php echo $maxPosts; ?> postingan. Saat ini: <?php echo $postCount; ?><?php echo $blogLimitReached ? ' · Hapus satu postingan dulu sebelum menambah baru.' : ''; ?></p>
					<form method="post" class="stack-sm" id="blog-form" data-autosave-key="blog">
						<input type="hidden" name="blog_edit_idx" id="blog_edit_idx" value="<?php echo (int) $blogEditingIdx; ?>">
						<div class="field-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
							<div>
								<label for="blog_title">Judul</label>
								<input type="text" id="blog_title" name="blog_title" value="<?php echo htmlspecialchars($blogDraft['title'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Judul postingan" required>
							</div>
							<div>
								<label for="blog_slug">Slug (opsional)</label>
								<input type="text" id="blog_slug" name="blog_slug" value="<?php echo htmlspecialchars($blogDraft['slug'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="otomatis dari judul">
								<p class="muted-text" style="margin:4px 0 0;">Dipakai di URL blog.php?slug=...</p>
							</div>
							<div>
								<label for="blog_image">Gambar (opsional)</label>
								<input type="text" id="blog_image" name="blog_image" value="<?php echo htmlspecialchars($blogDraft['image'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg atau data:image/jpeg;base64,...">
								<input type="file" id="blog_image_file" accept="image/*" style="margin-top:6px;">
								<p class="muted-text" style="margin:4px 0 0;">Pilih file untuk diunggah (auto compress <=1MB) atau tempel URL gambar.</p>
							</div>
							<div class="full">
								<label for="blog_summary">Ringkas</label>
								<textarea id="blog_summary" name="blog_summary" style="min-height:90px;"><?php echo htmlspecialchars($blogDraft['summary'], ENT_QUOTES, 'UTF-8'); ?></textarea>
								<p class="muted-text" style="margin:4px 0 0;">Kosongkan agar diringkas otomatis (180 karakter).</p>
							</div>
							<div class="full">
								<label for="blog_body">Konten</label>
								<textarea id="blog_body" name="blog_body" style="min-height:180px; resize: both;"><?php echo htmlspecialchars($blogDraft['body'], ENT_QUOTES, 'UTF-8'); ?></textarea>
								<p class="muted-text" style="margin:4px 0 0;">Mendukung Markdown sederhana: judul (#), tebal (**bold**), miring (*italic*), kode (`inline`, ```blok```), dan tautan [teks](https://...).</p>
							</div>
						</div>
						<div class="full" style="margin-top:6px;">
							<label style="display:flex; align-items:center; gap:10px;">
								<span>Mode embed HTML</span>
								<label class="toggle">
									<input type="checkbox" id="blog_embed_enabled" name="blog_embed_enabled" value="1" <?php echo !empty($blogDraft['embed_enabled']) ? 'checked' : ''; ?>>
									<span class="slider"></span>
								</label>
							</label>
							<div id="blog_embed_wrap" style="margin-top:6px; <?php echo !empty($blogDraft['embed_enabled']) ? '' : 'display:none;'; ?>">
								<div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:6px;">
									<label style="margin:0;">Daftar embed (gunakan [[EMBED1]], [[EMBED2]], ... di konten untuk posisi)</label>
									<button type="button" id="add-embed" style="max-width:180px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Tambah embed</button>
								</div>
								<div id="blog_embed_list" class="stack-sm">
									<?php
									$embedsDraft = !empty($blogDraft['embeds']) && is_array($blogDraft['embeds']) ? $blogDraft['embeds'] : [];
									if (empty($embedsDraft)) { $embedsDraft = ['']; }
									foreach ($embedsDraft as $idx => $embHtml): ?>
									<div class="embed-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
										<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Embed <?php echo $idx + 1; ?></label>
										<textarea name="blog_embed_html[]" style="min-height:120px; font-family: 'SFMono-Regular', Consolas, monospace; width:100%; resize: vertical;" placeholder="&lt;iframe ...&gt;&lt;/iframe&gt;"><?php echo htmlspecialchars($embHtml, ENT_QUOTES, 'UTF-8'); ?></textarea>
										<div style="text-align:right; margin-top:6px;">
											<button type="button" class="remove-embed" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus</button>
										</div>
									</div>
									<?php endforeach; ?>
								</div>
								<p class="muted-text" style="margin:4px 0 0;">Letakkan token [[EMBED1]], [[EMBED2]], ... di konten untuk menentukan posisi. Embed yang tidak ditandai akan muncul di akhir.</p>
							</div>
						</div>
						</div>
						<div class="inline-actions" style="margin-top: 6px; align-items:center;">
							<button type="submit" name="blog_save" value="1" <?php echo $blogLimitReached ? 'disabled' : ''; ?>>Simpan postingan</button>
							<button type="button" id="blog_reset" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Bersihkan formulir</button>
						</div>
						<p class="muted-text" style="margin:4px 0 0;">Klik Edit pada daftar untuk memuat postingan ke formulir. Simpan akan overwrite entri terpilih atau menambah baru jika kosong.</p>
					</form>
					<div class="admin-box" style="margin-top:10px;">
						<h3 style="margin:0 0 8px; font-size:15px;">Daftar postingan</h3>
						<?php if (empty($posts)): ?>
							<p class="muted-text" style="margin:0;">Belum ada postingan.</p>
						<?php else: ?>
							<ul class="logs" style="margin-top:6px; max-height:260px; overflow:auto;">
								<?php foreach ($posts as $pIdx => $post): ?>
									<li style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">
										<div>
											<strong><?php echo htmlspecialchars($post['title'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></strong>
											<?php if (!empty($post['slug'])): ?><br><span style="color: var(--muted); font-size:12px;">Slug: <?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
											<?php if (!empty($post['summary'])): ?><br><span style="color: var(--muted); font-size:12px;">Ringkas: <?php echo htmlspecialchars($post['summary'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
											<?php if (!empty($post['image'])): ?><br><span style="color: var(--muted); font-size:12px;">Gambar: <?php echo htmlspecialchars(strlen($post['image']) > 120 ? substr($post['image'], 0, 120) . '...' : $post['image'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
											<?php if (!empty($post['embed_enabled'])): ?><br><span style="color: var(--muted); font-size:12px;">Embed aktif (<?php echo isset($post['embeds']) && is_array($post['embeds']) ? count($post['embeds']) : 0; ?>)</span><?php endif; ?>
											<?php if (!empty($post['created_at'])): ?><br><span style="color: var(--muted); font-size:12px;">Dibuat: <?php echo htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
										</div>
										<div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
											<form method="post" style="margin:0;">
												<input type="hidden" name="blog_edit_idx" value="<?php echo $pIdx; ?>">
												<button type="submit" style="min-width:80px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Edit</button>
											</form>
											<form method="post" style="margin:0;" onsubmit="return confirm('Hapus postingan ini?');">
												<input type="hidden" name="delete_blog_idx" value="<?php echo $pIdx; ?>">
												<button type="submit" style="min-width:90px; background: linear-gradient(135deg, #f87171, #ef4444); color: #0b1727; box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35); border: none;">Hapus</button>
											</form>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
		<?php else: ?>
			<h1>Login Admin</h1>
			<p>Masukkan user dan key untuk mengakses halaman admin.</p>
			<?php if ($error): ?>
				<div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
			<?php endif; ?>
			<form method="post">
				<div>
					<label for="user">User</label>
					<input type="text" id="user" name="user" required autocomplete="username">
				</div>
				<div>
					<label for="key">Key</label>
					<input type="password" id="key" name="key" required autocomplete="current-password">
				</div>
				<div class="actions">
					<button type="submit">Masuk</button>
				</div>
			</form>
		<?php endif; ?>
	</div>
	<script>
		const archiveButtons = document.querySelectorAll('.archive-day');
		archiveButtons.forEach(btn => {
			btn.addEventListener('click', () => {
				const targetId = btn.getAttribute('data-target');
				const target = document.getElementById(targetId);
				if (!target) return;
				target.classList.toggle('open');
			});
		});

		// Toast notifications
		const toastWrap = document.getElementById('toast-wrap');
		if (toastWrap) {
			const toasts = Array.from(toastWrap.querySelectorAll('.toast'));
			toasts.forEach((toast, idx) => {
				requestAnimationFrame(() => toast.classList.add('show'));
				const closer = toast.querySelector('.toast-close');
				const hide = () => {
					toast.classList.remove('show');
					setTimeout(() => toast.remove(), 220);
				};
				closer?.addEventListener('click', hide);
				setTimeout(hide, 5200 + (idx * 200));
			});
		}

		// Pertahankan posisi scroll setelah submit agar tidak lompat ke atas (fast restore for mobile).
		const SCROLL_KEY = 'admin_scroll_y';
		const savedScroll = window.__ADMIN_SAVED_SCROLL__;
		if (typeof savedScroll === 'number' && Number.isFinite(savedScroll)) {
			const restore = () => window.scrollTo(0, savedScroll);
			requestAnimationFrame(restore);
			setTimeout(restore, 0);
			setTimeout(restore, 120);
			setTimeout(restore, 260);
			sessionStorage.removeItem(SCROLL_KEY);
		}

		document.querySelectorAll('form').forEach(form => {
			form.addEventListener('submit', () => {
				sessionStorage.setItem(SCROLL_KEY, String(window.scrollY));
			});
		});

		// Template link pendaftaran per platform
		const regPlatform = document.getElementById('registration_platform');
		const regUrl = document.getElementById('registration_url');
		const regFill = document.getElementById('registration_fill_template');
		const regTemplates = {
			'Google Form': 'https://forms.gle/xxxxxxxxxxxxxxxxx',
			'Website': 'https://domainkamu.com/daftar',
			'WhatsApp': 'https://wa.me/62XXXXXXXXXX?text=Halo%2C%20saya%20mau%20daftar%20UKMI',
			'Telegram': 'https://t.me/username_ukmi',
			'Typeform': 'https://yourorg.typeform.com/formname',
			'Microsoft Forms': 'https://forms.office.com/r/XXXXXXXX',
			'Notion': 'https://www.notion.so/your-form-page',
			'Custom': 'https://'
		};

		function applyRegPlaceholder() {
			if (!regPlatform || !regUrl) return;
			const tpl = regTemplates[regPlatform.value] || 'https://';
			regUrl.placeholder = tpl;
		}
		applyRegPlaceholder();
		regPlatform?.addEventListener('change', applyRegPlaceholder);
		regFill?.addEventListener('click', () => {
			if (!regPlatform || !regUrl) return;
			const tpl = regTemplates[regPlatform.value] || '';
			if (tpl) regUrl.value = tpl;
		});

		// Divisi: tambah/hapus baris
		const divisionList = document.getElementById('division-list');
		const addDivision = document.getElementById('add-division');
		const templateHtml = () => `
			<div class="division-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Nama divisi</label>
				<input type="text" name="division_name[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
				<textarea name="division_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"></textarea>
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-division" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>`;
		addDivision?.addEventListener('click', () => {
			if (!divisionList) return;
			const wrapper = document.createElement('div');
			wrapper.innerHTML = templateHtml();
			divisionList.appendChild(wrapper.firstElementChild);
			attachRemoveHandlers();
		});

		function attachRemoveHandlers() {
			document.querySelectorAll('.remove-division').forEach(btn => {
				btn.onclick = () => {
					const item = btn.closest('.division-item');
					if (item && divisionList && divisionList.children.length > 1) {
						item.remove();
					}
				};
			});
		}
		attachRemoveHandlers();

		// Dokumentasi: tambah/hapus baris, batasi 10
		const docList = document.getElementById('doc-list');
		const addDoc = document.getElementById('add-doc');
		const docTemplate = () => `
			<div class="doc-item" style="border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);">
				<label style="display:block; font-size:13px; color: var(--muted); margin-bottom:4px;">Tag</label>
				<input type="text" name="doc_tag[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Judul</label>
				<input type="text" name="doc_title[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);">
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Deskripsi</label>
				<textarea name="doc_desc[]" style="width:100%; min-height:72px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text); resize: vertical;"></textarea>
				<label style="display:block; font-size:13px; color: var(--muted); margin:8px 0 4px;">Link</label>
				<input type="text" name="doc_url[]" value="" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.3); background: rgba(15,23,42,0.4); color: var(--text);" placeholder="https://...">
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-doc" style="background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25); min-width:70px;">Hapus</button>
				</div>
			</div>`;

		function updateDocLimitState() {
			if (!docList || !addDoc) return;
			addDoc.disabled = docList.children.length >= 10;
			if (addDoc.disabled) {
				addDoc.title = 'Maksimal 10 kartu';
			} else {
				addDoc.title = '';
			}
		}

		addDoc?.addEventListener('click', () => {
			if (!docList || docList.children.length >= 10) return;
			const wrapper = document.createElement('div');
			wrapper.innerHTML = docTemplate();
			docList.appendChild(wrapper.firstElementChild);
			attachDocRemove();
			updateDocLimitState();
		});

		function attachDocRemove() {
			document.querySelectorAll('.remove-doc').forEach(btn => {
				btn.onclick = () => {
					const item = btn.closest('.doc-item');
					if (btn.disabled) return;
					if (item && docList && docList.children.length > 1) {
						item.remove();
						updateDocLimitState();
					}
				};
			});
		}
		attachDocRemove();
		updateDocLimitState();

		// Blog: file picker with compression (<=1MB) and reset helper
		const blogImageFile = document.getElementById('blog_image_file');
		const blogImageInput = document.getElementById('blog_image');
		const blogForm = document.getElementById('blog-form');
		const blogResetBtn = document.getElementById('blog_reset');
		const blogEditIdxInput = document.getElementById('blog_edit_idx');
		const blogEmbedToggle = document.getElementById('blog_embed_enabled');
		const blogEmbedWrap = document.getElementById('blog_embed_wrap');
		const blogEmbedList = document.getElementById('blog_embed_list');
		const blogEmbedAdd = document.getElementById('add-embed');
		const MAX_IMAGE_BYTES = 1000000;

		function dataUrlBytes(dataUrl) {
			const comma = dataUrl.indexOf(',');
			if (comma === -1) return dataUrl.length;
			const base64 = dataUrl.slice(comma + 1);
			return Math.ceil((base64.length * 3) / 4);
		}

		function loadImageFromFile(file) {
			return new Promise((resolve, reject) => {
				const reader = new FileReader();
				const img = new Image();
				reader.onload = () => {
					img.onload = () => resolve(img);
					img.onerror = () => reject(new Error('Gagal memuat gambar'));
					img.src = reader.result;
				};
				reader.onerror = () => reject(new Error('Gagal membaca file'));
				reader.readAsDataURL(file);
			});
		}

		async function compressImage(file) {
			const img = await loadImageFromFile(file);
			let width = img.naturalWidth || img.width;
			let height = img.naturalHeight || img.height;
			const canvas = document.createElement('canvas');
			const ctx = canvas.getContext('2d');
			const maxDim = 1400;
			if (width > maxDim || height > maxDim) {
				const scale = Math.min(maxDim / width, maxDim / height);
				width = Math.max(1, Math.round(width * scale));
				height = Math.max(1, Math.round(height * scale));
			}
			let quality = 0.9;
			let dataUrl = '';
			for (let i = 0; i < 6; i++) {
				canvas.width = width;
				canvas.height = height;
				ctx.clearRect(0, 0, width, height);
				ctx.drawImage(img, 0, 0, width, height);
				dataUrl = canvas.toDataURL('image/jpeg', quality);
				if (dataUrlBytes(dataUrl) <= MAX_IMAGE_BYTES) return dataUrl;
				width = Math.max(1, Math.round(width * 0.9));
				height = Math.max(1, Math.round(height * 0.9));
				quality = quality * 0.82;
			}
			return dataUrl;
		}

		blogImageFile?.addEventListener('change', async (e) => {
			const file = e.target.files?.[0];
			if (!file) return;
			if (!file.type.startsWith('image/')) {
				alert('File harus berupa gambar.');
				e.target.value = '';
				return;
			}
			try {
				const dataUrl = await compressImage(file);
				if (!dataUrl) throw new Error('Gagal kompres');
				const size = dataUrlBytes(dataUrl);
				if (size > MAX_IMAGE_BYTES) {
					alert('Gambar masih di atas 1MB setelah kompres. Pilih file yang lebih kecil.');
				} else if (blogImageInput) {
					blogImageInput.value = dataUrl;
				}
			} catch (err) {
				console.error(err);
				alert('Gagal memproses gambar.');
			} finally {
				e.target.value = '';
			}
		});

		function clearBlogForm() {
			if (blogEditIdxInput) blogEditIdxInput.value = -1;
			if (!blogForm) return;
			blogForm.querySelectorAll('input[type="text"], textarea').forEach(el => { el.value = ''; });
			if (blogImageFile) blogImageFile.value = '';
			if (blogEmbedToggle) blogEmbedToggle.checked = false;
			syncEmbedVisibility();
			if (blogEmbedList) {
				blogEmbedList.innerHTML = '';
				addEmbedItem('');
			}
		}

		blogResetBtn?.addEventListener('click', clearBlogForm);

		function syncEmbedVisibility() {
			if (!blogEmbedWrap || !blogEmbedToggle) return;
			blogEmbedWrap.style.display = blogEmbedToggle.checked ? '' : 'none';
		}
		blogEmbedToggle?.addEventListener('change', syncEmbedVisibility);
		syncEmbedVisibility();

		function renumberEmbeds() {
			if (!blogEmbedList) return;
			Array.from(blogEmbedList.querySelectorAll('.embed-item label')).forEach((label, idx) => {
				label.textContent = `Embed ${idx + 1}`;
			});
		}

		function attachEmbedRemoveHandlers() {
			if (!blogEmbedList) return;
			blogEmbedList.querySelectorAll('.remove-embed').forEach(btn => {
				btn.onclick = () => {
					const item = btn.closest('.embed-item');
					if (item && blogEmbedList.children.length > 1) {
						item.remove();
						renumberEmbeds();
					}
				};
			});
		}

		function addEmbedItem(value = '') {
			if (!blogEmbedList) return;
			const wrapper = document.createElement('div');
			wrapper.className = 'embed-item';
			wrapper.style = 'border:1px solid rgba(148,163,184,0.25); padding:10px; border-radius:10px; background: rgba(255,255,255,0.02);';
			wrapper.innerHTML = `
				<label style="display:block; font-size:13px; color: var(--muted); margin:0 0 4px;">Embed</label>
				<textarea name="blog_embed_html[]" style="min-height:120px; font-family: 'SFMono-Regular', Consolas, monospace; width:100%; resize: vertical;" placeholder="<iframe ...></iframe>">${value.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea>
				<div style="text-align:right; margin-top:6px;">
					<button type="button" class="remove-embed" style="min-width:90px; background: rgba(255,255,255,0.08); color: var(--text); box-shadow: none; border: 1px solid rgba(148,163,184,0.25);">Hapus</button>
				</div>`;
			blogEmbedList.appendChild(wrapper);
			renumberEmbeds();
			attachEmbedRemoveHandlers();
		}

		blogEmbedAdd?.addEventListener('click', () => addEmbedItem(''));
		attachEmbedRemoveHandlers();

		// Autosave draft untuk mencegah kehilangan progres saat submit gagal (mis. koneksi putus).
		(function enableAutosave() {
			if (!document.body.classList.contains('is-auth')) return;
			const PREFIX = 'admin_autosave_';
			const forms = document.querySelectorAll('form[data-autosave-key]');

			function serialize(form) {
				const data = {};
				form.querySelectorAll('input, textarea, select').forEach(el => {
					if (!el.name) return;
					const type = (el.type || '').toLowerCase();
					if (['submit', 'button', 'file', 'password', 'hidden'].includes(type)) return;
					if (type === 'checkbox' || type === 'radio') {
						data[el.name] = !!el.checked;
					} else {
						data[el.name] = el.value;
					}
				});
				return data;
			}

			function restore(form, data) {
				form.querySelectorAll('input, textarea, select').forEach(el => {
					if (!el.name) return;
					const type = (el.type || '').toLowerCase();
					if (['submit', 'button', 'file', 'password', 'hidden'].includes(type)) return;
					if (!(el.name in data)) return;
					const val = data[el.name];
					if (type === 'checkbox' || type === 'radio') {
						el.checked = !!val;
					} else if (typeof val === 'string') {
						el.value = val;
					}
				});
			}

			forms.forEach(form => {
				const key = form.getAttribute('data-autosave-key');
				if (!key) return;
				const storageKey = PREFIX + key;
				let saveTimer = null;

				const triggerSave = () => {
					clearTimeout(saveTimer);
					saveTimer = setTimeout(() => {
						try {
							localStorage.setItem(storageKey, JSON.stringify(serialize(form)));
						} catch (_) {}
					}, 240);
				};

				const raw = localStorage.getItem(storageKey);
				if (raw) {
					try {
						const data = JSON.parse(raw);
						const hasExisting = Array.from(form.querySelectorAll('input, textarea, select')).some(el => {
							const type = (el.type || '').toLowerCase();
							if (['submit', 'button', 'file', 'password', 'hidden'].includes(type)) return false;
							if (!el.name) return false;
							if (type === 'checkbox' || type === 'radio') return el.checked;
							return !!el.value;
						});
						if (!hasExisting && data && typeof data === 'object') {
							restore(form, data);
						}
					} catch (_) {}
				}

				form.addEventListener('input', triggerSave);
				form.addEventListener('change', triggerSave);
				form.addEventListener('submit', () => {
					localStorage.removeItem(storageKey);
				});
			});
		})();

		const filterEvent = document.getElementById('log-filter-event');
		const filterText = document.getElementById('log-filter-text');

		function applyLogFilter() {
			const evt = (filterEvent?.value || '').toLowerCase();
			const q = (filterText?.value || '').toLowerCase();
			document.querySelectorAll('.log-list .log-item').forEach(item => {
				const itemEvent = (item.getAttribute('data-event') || '').toLowerCase();
				const itemText = (item.getAttribute('data-text') || '').toLowerCase();
				const matchEvent = !evt || itemEvent === evt;
				const matchText = !q || itemText.includes(q);
				item.style.display = (matchEvent && matchText) ? '' : 'none';
			});
		}

		filterEvent?.addEventListener('change', applyLogFilter);
		filterText?.addEventListener('input', applyLogFilter);
		applyLogFilter();
	</script>
</body>
</html>
