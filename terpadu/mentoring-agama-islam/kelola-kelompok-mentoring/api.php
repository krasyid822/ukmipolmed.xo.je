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
            echo json_encode(['success' => false, 'message' => 'Tim tidak ditemukan'], 404);
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
            echo json_encode(['success' => false, 'message' => 'Jenis entitas tidak valid'], 400);
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
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid'], 405);
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
            echo json_encode(['success' => false, 'message' => 'Parameter tidak valid'], 400);
            break;
        }
        // Pendaftaran hanya boleh lewat link setup sekali pakai yang valid.
        $setupToken = Biometric::validateSetupToken((string)($input['token'] ?? ''));
        if ($setupToken === null
            || (string)($setupToken['entity_type'] ?? '') !== $entityType
            || (string)($setupToken['entity_id'] ?? '') !== $entityId) {
            echo json_encode(['success' => false, 'message' => 'Link pendaftaran tidak valid atau sudah kedaluwarsa'], 403);
            break;
        }
        if ($method === 'webauthn') {
            if (!Biometric::consumeChallenge((string)($input['challenge'] ?? ''))) {
                echo json_encode(['success' => false, 'message' => 'Challenge tidak valid atau kedaluwarsa'], 400);
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
                echo json_encode(['success' => false, 'message' => 'Public key biometrik tidak valid'], 400);
                break;
            }
            $credId = trim((string)($input['credential_id'] ?? '')) ?: null;
            $ok = Biometric::setEnrolled($entityType, $entityId, 'webauthn', $credId, $pem);
        } else {
            $pattern = (string)($input['pattern'] ?? '');
            if (strlen($pattern) < 3) {
                echo json_encode(['success' => false, 'message' => 'Pola minimal 3 titik'], 400);
                break;
            }
            $ok = Biometric::setEnrolled($entityType, $entityId, 'pattern', null, null, Biometric::hashPattern($pattern));
        }
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Berhasil didaftarkan' : 'Gagal menyimpan']);
        break;
    }

    case 'biometric_authenticate': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid'], 405);
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
            echo json_encode(['success' => false, 'message' => 'Parameter tidak valid'], 400);
            break;
        }
        $ok = false;
        if ($method === 'webauthn') {
            if (!Biometric::consumeChallenge((string)($input['challenge'] ?? ''))) {
                echo json_encode(['success' => false, 'message' => 'Challenge tidak valid atau kedaluwarsa'], 400);
                break;
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
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

        if ($ok) {
            $teamId = (string)($input['team_id'] ?? '');
            Biometric::storeVerified($entityType, $entityId, $teamId);
            $entity = Biometric::findEntity($entityType, $entityId) ?? [];
            $offlineToken = Biometric::createOfflineToken($entityType, $entityId, $teamId);
            echo json_encode([
                'success' => true,
                'message' => 'Verifikasi lulus',
                'data' => [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'entity_name' => (string)($entity['nama_lengkap'] ?? ''),
                    'role' => $entityType === 'mentor' ? 'Mentor' : 'Mentee Perwakilan',
                    'offline_token' => $offlineToken,
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
        $state = BotQueue::state();
        $items = array_map(static function (array $item): array {
            return [
                'id' => (string)($item['id'] ?? ''),
                'report_id' => (string)($item['report_id'] ?? ''),
                'attempts' => (int)($item['attempts'] ?? 0),
                'last_error' => (string)($item['last_error'] ?? ''),
                'next_attempt_at' => (int)($item['next_attempt_at'] ?? 0),
                'nama_tim' => (string)($item['meta']['nama_tim'] ?? ''),
            ];
        }, BotQueue::items());

        echo json_encode([
            'success' => true,
            'data' => [
                'state' => $state,
                'pending_count' => count($items),
                'items' => $items,
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
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid'], 405);
            break;
        }

        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $apiKey = trim((string)($input['api_key'] ?? ''));
        // Hanya API key yang dapat diubah dari UI. URL API dan ID grup
        // dikunci dan hanya dapat diubah manual di storage_secure/default.json.

        if ($apiKey === '') {
            echo json_encode(['success' => false, 'message' => 'API key baru wajib diisi'], 400);
            break;
        }

        // Uji kunci baru terhadap antrean yang ada SEBELUM menimpa kunci lama.
        // Dengan begitu kunci lama yang masih sah tidak hilang karena salah ketik.
        $pendingBefore = BotQueue::pendingCount();
        $results = $pendingBefore > 0
            ? BotQueue::processDue(10, true, ['api_key' => $apiKey])
            : [];
        $tested = $results !== [];
        $sent = count(array_filter($results, static fn(array $r): bool => !empty($r['success'])));
        $failed = count($results) - $sent;
        $stateAfterTest = BotQueue::state();

        // Kunci hanya ditolak bila SETIAP hasil percobaan menyatakan kunci tidak
        // sah. Kegagalan jaringan/timeout (api_invalid=false) bukan alasan
        // menolak kunci yang benar.
        $allRejected = $tested && $sent === 0 && $results !== []
            && count(array_filter($results, static fn(array $r): bool => empty($r['api_invalid']))) === 0;

        // Kunci ditolak: jangan simpan, pertahankan kunci lama yang masih tersimpan.
        if ($allRejected) {
            $httpCode = (int)($stateAfterTest['last_http_code'] ?? 0);
            $reason = (string)($stateAfterTest['last_error'] ?? 'API key ditolak');
            BotQueue::markApiInvalid(
                'Kunci baru ditolak; kunci lama dipertahankan. ' . $reason,
                $httpCode
            );
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'API key baru ditolak oleh server. Kunci lama tetap dipakai — periksa kembali kunci Anda lalu coba lagi.',
                'data' => [
                    'key_rejected' => true,
                    'sent' => 0,
                    'failed' => $failed,
                    'pending_count' => BotQueue::pendingCount(),
                    'state' => BotQueue::state(),
                    'results' => $results,
                ],
            ]);
            break;
        }

        if (Config::set('watobot.api_key', $apiKey) === false) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan konfigurasi. File default.json tidak dapat ditulis.'], 500);
            break;
        }
        if ($sent > 0 || !$tested) {
            // Kunci terbukti bekerja (ada pesan terkirim) atau belum ada antrean
            // untuk diuji. Bersihkan status kedaluwarsa.
            BotQueue::markApiOk();
        } else {
            // Kunci belum terbukti salah, tetapi API tidak dapat dihubungi
            // (timeout/jaringan). Jangan pertahankan status "kedaluwarsa"
            // yang berasal dari kunci lama.
            $lastResult = $results[count($results) - 1];
            BotQueue::markKeyUnverified(
                (string)($lastResult['message'] ?? 'API bot tidak dapat dihubungi'),
                (int)($lastResult['http_code'] ?? 0)
            );
        }

        if (!$tested) {
            $message = 'API key disimpan. Belum ada pesan di antrean untuk menguji kunci ini; kunci akan diverifikasi saat pengiriman berikutnya.';
        } elseif ($sent > 0 && $failed === 0) {
            $message = "API key disimpan. {$sent} pesan berhasil dikirim.";
        } elseif ($sent > 0) {
            $message = "API key disimpan. {$sent} pesan terkirim, {$failed} pesan masih menunggu percobaan ulang.";
        } else {
            $message = 'API key disimpan, tetapi API belum dapat dihubungi saat ini. Antrean akan dicoba ulang otomatis.';
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => [
                'sent' => $sent,
                'failed' => $failed,
                'pending_count' => BotQueue::pendingCount(),
                'state' => BotQueue::state(),
                'results' => $results,
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
            echo json_encode(['success' => false, 'message' => 'Metode tidak valid'], 405);
            break;
        }

        $results = BotQueue::processDue(10, true);
        $sent = count(array_filter($results, static fn(array $r): bool => !empty($r['success'])));

        echo json_encode([
            'success' => true,
            'message' => $results === [] ? 'Tidak ada pesan tertunda.' : "{$sent} pesan berhasil dikirim.",
            'data' => [
                'sent' => $sent,
                'results' => $results,
                'pending_count' => BotQueue::pendingCount(),
                'state' => BotQueue::state(),
            ],
        ]);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Aksi tidak valid'], 400);
        break;
}
exit;
