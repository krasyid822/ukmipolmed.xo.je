<?php
/**
 * api.php — Endpoint AJAX untuk Data Tim, Mentee, dan Master Data
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Mentoring\MasterData;
use Mentoring\Biometric;
use Mentoring\Config;
use Mentoring\AdminAuth;
use Mentoring\BotQueue;
use Mentoring\Watobot;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

Biometric::startSession();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_teams':
        $teams = MasterData::getTeamsForForm();
        echo json_encode(['success' => true, 'data' => $teams]);
        break;

    case 'get_team_members':
        $teamId = $_GET['team_id'] ?? '';
        $teams = MasterData::getTeamsForForm();
        $target = null;
        foreach ($teams as $t) {
            if ($t['id'] === $teamId) {
                $target = $t;
                break;
            }
        }
        if ($target) {
            echo json_encode(['success' => true, 'data' => $target]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Tim tidak ditemukan']);
        }
        break;

    case 'biometric_identities': {
        // Proyeksi minimal untuk modal verifikasi: tanpa data pribadi mentee/mentor.
        $master = MasterData::loadAll();
        $list = [];
        foreach ($master['mentors'] ?? [] as $m) {
            $bio = (array)($m['biometric'] ?? []);
            $list[] = [
                'entity_type' => 'mentor',
                'entity_id' => (string)($m['id'] ?? ''),
                'entity_name' => (string)($m['nama_lengkap'] ?? ''),
                'role' => 'Mentor',
                'enrolled' => !empty($bio['enrolled']),
                'method' => $bio['method'] ?? null,
            ];
        }
        foreach ($master['mentees'] ?? [] as $m) {
            if (empty($m['delegate_for'])) {
                continue;
            }
            $bio = (array)($m['biometric'] ?? []);
            $list[] = [
                'entity_type' => 'mentee',
                'entity_id' => (string)($m['id'] ?? ''),
                'entity_name' => (string)($m['nama_lengkap'] ?? ''),
                'role' => 'Mentee Perwakilan',
                'enrolled' => !empty($bio['enrolled']),
                'method' => $bio['method'] ?? null,
            ];
        }
        echo json_encode(['success' => true, 'data' => $list]);
        break;
    }

    // --- Biometrik ---
    case 'biometric_status': {
        $entityType = (string)($_GET['entity_type'] ?? '');
        $entityId = (string)($_GET['entity_id'] ?? '');
        if (!in_array($entityType, Biometric::ENTITY_TYPES, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Jenis entitas tidak valid']);
            break;
        }
        echo json_encode(['success' => true, 'data' => Biometric::enrollmentStatus($entityType, $entityId)]);
        break;
    }

    case 'biometric_challenge': {
        $challenge = Biometric::generateChallenge();
        echo json_encode(['success' => true, 'challenge' => $challenge]);
        break;
    }

    case 'biometric_register': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
            break;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $entityType = (string)($input['entity_type'] ?? '');
        $entityId = (string)($input['entity_id'] ?? '');
        $method = (string)($input['method'] ?? '');
        if (($entityType === '' || $entityId === '') && !empty($input['credential_id'])) {
            $matched = Biometric::findEntityByCredentialId((string)$input['credential_id']);
            if ($matched !== null) {
                $entityType = $matched['entity_type'];
                $entityId = $matched['entity_id'];
            }
        }
        if (!in_array($entityType, Biometric::ENTITY_TYPES, true) || !in_array($method, Biometric::METHODS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Parameter tidak valid']);
            break;
        }
        // Pendaftaran hanya boleh lewat link setup sekali pakai yang valid.
        $setupToken = Biometric::validateSetupToken((string)($input['token'] ?? ''));
        if ($setupToken === null
            || (string)($setupToken['entity_type'] ?? '') !== $entityType
            || (string)($setupToken['entity_id'] ?? '') !== $entityId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Link pendaftaran tidak valid atau sudah kedaluwarsa']);
            break;
        }
        if ($method === 'webauthn') {
            if (!Biometric::consumeChallenge((string)($input['challenge'] ?? ''))) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Challenge tidak valid atau kedaluwarsa']);
                break;
            }
            $pem = null;
            if (!empty($input['public_key_spki'])) {
                $pem = Biometric::pemFromSpki((string)$input['public_key_spki']);
            }
            if ($pem === null && !empty($input['public_key_x']) && !empty($input['public_key_y'])) {
                $pem = Biometric::pemFromCoordinates(
                    (string)$input['public_key_x'],
                    (string)$input['public_key_y']
                );
            }
            if ($pem === null && !empty($input['rsa_n']) && !empty($input['rsa_e'])) {
                $pem = Biometric::pemFromRsaModExp(
                    (string)$input['rsa_n'],
                    (string)$input['rsa_e']
                );
            }
            if ($pem === null && !empty($input['okp_x'])) {
                $pem = Biometric::pemFromEd25519((string)$input['okp_x']);
            }
            if ($pem === null && !empty($input['authenticator_data'])) {
                $pem = Biometric::pemFromAuthData((string)$input['authenticator_data']);
            }
            if ($pem === null && !empty($input['attestation_object'])) {
                $pem = Biometric::pemFromAttestationObject((string)$input['attestation_object']);
            }
            if ($pem === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Public key biometrik tidak valid']);
                break;
            }
            $credId = trim((string)($input['credential_id'] ?? '')) ?: null;
            $ok = Biometric::setEnrolled($entityType, $entityId, 'webauthn', $credId, $pem);
        } else {
            $pattern = (string)($input['pattern'] ?? '');
            if (strlen($pattern) < 3) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Pola minimal 3 titik']);
                break;
            }
            $ok = Biometric::setEnrolled($entityType, $entityId, 'pattern', null, null, Biometric::hashPattern($pattern));
        }
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Berhasil didaftarkan' : 'Gagal menyimpan']);
        break;
    }

    case 'biometric_authenticate': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
            break;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $entityType = (string)($input['entity_type'] ?? '');
        $entityId = (string)($input['entity_id'] ?? '');
        $method = (string)($input['method'] ?? '');
        if (($entityType === '' || $entityId === '') && !empty($input['credential_id'])) {
            $matched = Biometric::findEntityByCredentialId((string)$input['credential_id']);
            if ($matched !== null) {
                $entityType = $matched['entity_type'];
                $entityId = $matched['entity_id'];
            }
        }
        if (!in_array($entityType, Biometric::ENTITY_TYPES, true) || !in_array($method, Biometric::METHODS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Parameter tidak valid']);
            break;
        }
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rateLimit = Biometric::checkRateLimit($entityType, $entityId, $clientIp);
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . ceil($rateLimit['retry_after'] / 60) . ' menit.',
            ]);
            break;
        }
        $ok = false;
        if ($method === 'webauthn') {
            if (!Biometric::consumeChallenge((string)($input['challenge'] ?? ''))) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Challenge tidak valid atau kedaluwarsa']);
                break;
            }
            $baseParsed = parse_url((string)Config::get('app.base_url', ''));
            if (is_array($baseParsed) && !empty($baseParsed['scheme']) && !empty($baseParsed['host'])) {
                $origin = $baseParsed['scheme'] . '://' . $baseParsed['host']
                    . (isset($baseParsed['port']) ? ':' . $baseParsed['port'] : '');
            } else {
                $origin = mentoring_request_origin();
            }
            $ok = Biometric::verifyWebauthnAssertion(
                $entityType,
                $entityId,
                (string)($input['challenge'] ?? ''),
                (string)($input['authenticator_data'] ?? ''),
                (string)($input['client_data_json'] ?? ''),
                (string)($input['signature'] ?? ''),
                $origin
            );
        } else {
            $ok = Biometric::verifyPattern($entityType, $entityId, (string)($input['pattern'] ?? ''));
        }
        Biometric::recordAuthAttempt($entityType, $entityId, $clientIp, $ok);

        if ($ok) {
            $teamId = (string)($input['team_id'] ?? '');
            Biometric::storeVerified($entityType, $entityId, $teamId);
            $entity = Biometric::findEntity($entityType, $entityId) ?? [];
            $offlineToken = Biometric::createOfflineToken($entityType, $entityId, $teamId);
            $allowedTeamIds = Biometric::getAllowedTeamIds($entityType, $entityId);
            echo json_encode([
                'success' => true,
                'message' => 'Verifikasi lulus',
                'data' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'entity_name' => (string)($entity['nama_lengkap'] ?? ''),
                    'role' => $entityType === 'mentor' ? 'Mentor' : 'Mentee Perwakilan',
                    'offline_token' => $offlineToken,
                    'allowed_team_ids' => $allowedTeamIds,
                ],
            ]);
            break;
        }

        echo json_encode(['success' => false, 'message' => 'Verifikasi gagal']);
        break;
    }

    case 'team_verifiers': {
        $teamId = (string)($_GET['team_id'] ?? '');
        echo json_encode(['success' => true, 'data' => Biometric::getVerifiersForTeam($teamId)]);
        break;
    }

    // --- Antrean Bot WhatsApp (khusus admin) ---
    case 'bot_status': {
        if (!AdminAuth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
            break;
        }
        $allowedChannels = AdminAuth::isSuperAdmin()
            ? ['ikhwan', 'akhwat']
            : [(AdminAuth::allowedExportScope() === 'akhwat' ? 'akhwat' : 'ikhwan')];

        $channelsData = [];
        foreach ($allowedChannels as $ch) {
            $cfg = Watobot::channelConfig($ch);
            $chItems = BotQueue::items($ch);
            $channelsData[$ch] = [
                'channel' => $ch,
                'label' => $cfg['label'],
                'enabled' => $cfg['enabled'],
                'has_api_key' => $cfg['api_key'] !== '',
                'group_id' => $cfg['group_id'],
                'state' => BotQueue::state($ch),
                'pending_count' => count($chItems),
            ];
        }

        $primaryCh = $allowedChannels[0];
        echo json_encode([
            'success' => true,
            'data' => [
                'allowed_channels' => $allowedChannels,
                'channels' => $channelsData,
                'state' => $channelsData[$primaryCh]['state'],
                'pending_count' => array_sum(array_column($channelsData, 'pending_count')),
            ],
        ]);
        break;
    }

    case 'bot_save_api': {
        if (!AdminAuth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
            break;
        }

        $allowedChannels = AdminAuth::isSuperAdmin()
            ? ['ikhwan', 'akhwat']
            : [(AdminAuth::allowedExportScope() === 'akhwat' ? 'akhwat' : 'ikhwan')];

        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!AdminAuth::verifyCsrfToken(isset($input['_csrf']) ? (string)$input['_csrf'] : null)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.']);
            break;
        }

        $requestedChannel = trim((string)($input['channel'] ?? $allowedChannels[0]));
        $channel = Watobot::normalizeChannel($requestedChannel);
        if (!in_array($channel, $allowedChannels, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki akses untuk mengubah bot ' . Watobot::channelLabel($channel)]);
            break;
        }

        $apiKey = trim((string)($input['api_key'] ?? ''));
        $groupIdProvided = array_key_exists('group_id', $input);
        $groupId = $groupIdProvided ? trim((string)$input['group_id']) : null;
        $currentCfg = Watobot::channelConfig($channel);

        if ($apiKey === '' && (!$groupIdProvided || $groupId === '')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Isi API key baru atau ID grup WhatsApp terlebih dahulu']);
            break;
        }

        $effectiveApiKey = $apiKey !== '' ? $apiKey : $currentCfg['api_key'];
        $effectiveGroupId = $groupIdProvided && $groupId !== '' ? $groupId : $currentCfg['group_id'];

        // Uji kunci baru terhadap antrean channel terkait SEBELUM menimpa kunci lama.
        $pendingBefore = BotQueue::pendingCount($channel);
        $results = ($apiKey !== '' && $pendingBefore > 0 && $currentCfg['enabled'] && $effectiveGroupId !== '')
            ? BotQueue::processDue(10, true, ['api_key' => $effectiveApiKey, 'group_id' => $effectiveGroupId], $channel)
            : [];
        $tested = $results !== [];
        $sent = count(array_filter($results, static fn(array $r): bool => !empty($r['success'])));
        $failed = count($results) - $sent;
        $stateAfterTest = BotQueue::state($channel);

        $allRejected = $tested && $sent === 0 && $results !== []
            && count(array_filter($results, static fn(array $r): bool => empty($r['api_invalid']))) === 0;

        if ($allRejected) {
            $httpCode = (int)($stateAfterTest['last_http_code'] ?? 0);
            $reason = (string)($stateAfterTest['last_error'] ?? 'API key ditolak');
            BotQueue::markApiInvalid(
                'Kunci baru ditolak; kunci lama dipertahankan. ' . $reason,
                $httpCode,
                $channel
            );
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'API key baru ditolak oleh server. Kunci lama tetap dipakai — periksa kembali kunci Anda lalu coba lagi.',
                'data' => [
                    'channel' => $channel,
                    'key_rejected' => true,
                    'sent' => 0,
                    'failed' => $failed,
                    'pending_count' => BotQueue::pendingCount($channel),
                    'state' => BotQueue::state($channel),
                    'results' => $results,
                ],
            ]);
            break;
        }

        $savedOk = true;
        if ($apiKey !== '') {
            $savedOk = $savedOk && Config::set('watobot.channels.' . $channel . '.api_key', $apiKey);
            if ($channel === 'ikhwan') {
                $savedOk = $savedOk && Config::set('watobot.api_key', $apiKey);
            }
        }
        if ($groupIdProvided && $groupId !== '') {
            $savedOk = $savedOk && Config::set('watobot.channels.' . $channel . '.group_id', $groupId);
            if ($channel === 'ikhwan') {
                $savedOk = $savedOk && Config::set('watobot.group_id', $groupId);
            }
        }

        if (!$savedOk) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan konfigurasi. File default.json tidak dapat ditulis.']);
            break;
        }

        $label = Watobot::channelLabel($channel);
        if ($apiKey !== '') {
            if ($sent > 0 || !$tested) {
                BotQueue::markApiOk($channel);
            } else {
                $lastResult = $results[count($results) - 1];
                BotQueue::markKeyUnverified(
                    (string)($lastResult['message'] ?? 'API bot tidak dapat dihubungi'),
                    (int)($lastResult['http_code'] ?? 0),
                    $channel
                );
            }
        }

        if ($apiKey === '') {
            $message = "ID grup WhatsApp bot {$label} berhasil disimpan.";
        } elseif (!$tested) {
            $message = "Pengaturan bot {$label} disimpan. Belum ada pesan di antrean untuk menguji kunci ini.";
        } elseif ($sent > 0 && $failed === 0) {
            $message = "Pengaturan bot {$label} disimpan. {$sent} pesan berhasil dikirim.";
        } elseif ($sent > 0) {
            $message = "Pengaturan bot {$label} disimpan. {$sent} pesan terkirim, {$failed} pesan masih menunggu percobaan ulang.";
        } else {
            $message = "Pengaturan bot {$label} disimpan, tetapi API belum dapat dihubungi saat ini. Antrean akan dicoba ulang otomatis.";
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => [
                'channel' => $channel,
                'sent' => $sent,
                'failed' => $failed,
                'pending_count' => BotQueue::pendingCount($channel),
                'state' => BotQueue::state($channel),
                'results' => $results,
            ],
        ]);
        break;
    }

    case 'bot_toggle': {
        if (!AdminAuth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
            break;
        }

        $allowedChannels = AdminAuth::isSuperAdmin()
            ? ['ikhwan', 'akhwat']
            : [(AdminAuth::allowedExportScope() === 'akhwat' ? 'akhwat' : 'ikhwan')];

        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!AdminAuth::verifyCsrfToken(isset($input['_csrf']) ? (string)$input['_csrf'] : null)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.']);
            break;
        }

        $channel = Watobot::normalizeChannel((string)($input['channel'] ?? $allowedChannels[0]));
        if (!in_array($channel, $allowedChannels, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak untuk channel ini']);
            break;
        }

        $enabled = !empty($input['enabled']);
        if (Config::set('watobot.channels.' . $channel . '.enabled', $enabled) === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan status bot ke default.json']);
            break;
        }

        $label = Watobot::channelLabel($channel);
        echo json_encode([
            'success' => true,
            'message' => $enabled
                ? "Fitur bot {$label} berhasil diaktifkan."
                : "Fitur bot {$label} berhasil dimatikan.",
            'data' => [
                'channel' => $channel,
                'enabled' => $enabled,
            ],
        ]);
        break;
    }

    case 'bot_process_queue': {
        if (!AdminAuth::isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid']);
            break;
        }

        $allowedChannels = AdminAuth::isSuperAdmin()
            ? ['ikhwan', 'akhwat']
            : [(AdminAuth::allowedExportScope() === 'akhwat' ? 'akhwat' : 'ikhwan')];

        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!AdminAuth::verifyCsrfToken(isset($input['_csrf']) ? (string)$input['_csrf'] : null)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.']);
            break;
        }
        $requestedChannel = trim((string)($input['channel'] ?? ''));
        $targetChannels = $requestedChannel !== ''
            ? [Watobot::normalizeChannel($requestedChannel)]
            : $allowedChannels;

        $results = [];
        foreach ($targetChannels as $ch) {
            if (!in_array($ch, $allowedChannels, true)) {
                continue;
            }
            if (!Watobot::isEnabled($ch)) {
                continue;
            }
            $results = array_merge($results, BotQueue::processDue(10, true, [], $ch));
        }

        $sent = count(array_filter($results, static fn(array $r): bool => !empty($r['success'])));
        $pendingCount = 0;
        foreach ($targetChannels as $ch) {
            if (in_array($ch, $allowedChannels, true)) {
                $pendingCount += BotQueue::pendingCount($ch);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => $results === [] ? 'Tidak ada pesan tertunda.' : "{$sent} pesan berhasil dikirim.",
            'data' => [
                'sent' => $sent,
                'results' => $results,
                'pending_count' => $pendingCount,
                'state' => BotQueue::state($targetChannels[0] ?? 'ikhwan'),
            ],
        ]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Aksi tidak valid']);
        break;
}
exit;
