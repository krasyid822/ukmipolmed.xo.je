<?php
/**
 * src/biometric.php — Layanan Verifikasi Biometrik & Pola 3x3
 * Mengelola pendaftaran, verifikasi, dan token setup sekali pakai untuk
 * mentor serta mentee perwakilan, demi mengamankan halaman input laporan
 * publik dari penyalgunaan oleh orang anonim.
 */

declare(strict_types=1);

namespace Mentoring;

class Biometric {
    private const SETUP_FILE = 'biometric_setup.json';
    private const TOKEN_TTL_MINUTES = 30;
    private const CHALLENGE_TTL_SECONDS = 300;

    public const ENTITY_TYPES = ['mentor', 'mentee'];
    public const METHODS = ['webauthn', 'pattern'];

    public static function loadMaster(): array {
        return MasterData::loadAll();
    }

    public static function getSecureDir(): string {
        return Config::getSecureDir();
    }

    public static function setupTokensFile(): string {
        return self::getSecureDir() . '/' . self::SETUP_FILE;
    }

    /**
     * Baca semua token setup (satu pakai) dari berkas aman lokal.
     */
    public static function loadSetupTokens(): array {
        $path = self::setupTokensFile();
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    public static function saveSetupTokens(array $tokens): bool {
        $path = self::setupTokensFile();
        return file_put_contents($path, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    /**
     * Buat token setup sekali pakai untuk entitas mentor/mentee.
     * Mengembalikan ['token'=>..., 'url'=>..., 'expires_at'=>...]
     */
    public static function createSetupToken(string $entityType, string $entityId): array {
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException('Jenis entitas tidak valid.');
        }

        $token = bin2hex(random_bytes(16));
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::TOKEN_TTL_MINUTES . ' minutes')
            ->format(\DateTimeInterface::ATOM);

        $tokens = self::loadSetupTokens();
        $tokens = array_values(array_filter($tokens, static function (array $t) use ($entityType, $entityId) {
            return !((string)($t['entity_type'] ?? '') === $entityType
                && (string)($t['entity_id'] ?? '') === $entityId);
        }));

        $tokens[] = [
            'token' => $token,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_at' => date('c'),
            'expires_at' => $expiresAt,
            'used' => false,
        ];
        self::saveSetupTokens($tokens);

        $baseUrl = rtrim(Config::get('app.base_url', '/'), '/');
        $url = $baseUrl . '/setup-biometric?token=' . urlencode($token);

        return ['token' => $token, 'url' => $url, 'expires_at' => $expiresAt];
    }

    /**
     * Validasi token setup tanpa menandainya terpakai.
     * Dipakai saat halaman setup dibuka, supaya link tidak mati hanya karena
     * halaman di-refresh sebelum pendaftaran benar-benar berhasil.
     * Token baru dihapus saat pendaftaran sukses (lihat setEnrolled()).
     */
    public static function validateSetupToken(string $token): ?array {
        if ($token === '') {
            return null;
        }
        $tokens = self::loadSetupTokens();
        $now = new \DateTimeImmutable();
        foreach ($tokens as $t) {
            if ((string)($t['token'] ?? '') !== $token) {
                continue;
            }
            if (!empty($t['used'])) {
                return null;
            }
            $expires = new \DateTimeImmutable($t['expires_at'] ?? '');
            if ($expires < $now) {
                return null;
            }
            return $t;
        }
        return null;
    }

    /**
     * Hapus token setup yang belum terpakai (mis. setelah admin batal).
     */
    public static function purgeSetupTokens(string $entityType, string $entityId): bool {
        $tokens = self::loadSetupTokens();
        $count = count($tokens);
        $tokens = array_values(array_filter($tokens, static function (array $t) use ($entityType, $entityId) {
            return !((string)($t['entity_type'] ?? '') === $entityType
                && (string)($t['entity_id'] ?? '') === $entityId
                && empty($t['used']));
        }));
        if (count($tokens) === $count) {
            return false;
        }
        return self::saveSetupTokens($tokens);
    }

    /**
     * Ambil record entitas (mentor/mentee) dari master data.
     */
    public static function findEntity(string $entityType, string $entityId): ?array {
        $collection = $entityType === 'mentor' ? 'mentors' : 'mentees';
        $master = self::loadMaster();

        foreach ($master[$collection] ?? [] as $row) {
            if (is_array($row) && (string)($row['id'] ?? '') === $entityId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Simpan status enroll setelah setup berhasil.
     */
    public static function setEnrolled(
        string $entityType,
        string $entityId,
        string $method,
        ?string $credentialId = null,
        ?string $publicKey = null,
        ?string $patternHash = null
    ): bool {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Metode biometrik tidak valid.');
        }

        $collection = $entityType === 'mentor' ? 'mentors' : 'mentees';

        $result = MasterData::transaction(static function (array $master) use ($collection, $entityId, $method, $credentialId, $publicKey, $patternHash): array {
            $index = null;
            foreach ($master[$collection] ?? [] as $i => $row) {
                if ((string)($row['id'] ?? '') === $entityId) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw new MasterDataException('Entitas biometrik tidak ditemukan.', 'notfound');
            }

            $master[$collection][$index]['biometric'] = [
                'enrolled' => true,
                'method' => $method,
                'credential_id' => $credentialId,
                'public_key' => $publicKey,
                'pattern_hash' => $patternHash,
                'enrolled_at' => date('c'),
            ];

            return $master;
        }, 'biometric-enroll');

        if ($result['ok']) {
            self::purgeSetupTokens($entityType, $entityId);
        }

        return (bool)$result['ok'];
    }

    /**
     * Reset enroll biometrik (admin) dan buat token setup baru.
     */
    public static function resetEnrolled(string $entityType, string $entityId): array {
        $collection = $entityType === 'mentor' ? 'mentors' : 'mentees';

        $result = MasterData::transaction(static function (array $master) use ($collection, $entityId): array {
            $index = null;
            foreach ($master[$collection] ?? [] as $i => $row) {
                if ((string)($row['id'] ?? '') === $entityId) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                throw new MasterDataException('Entitas tidak ditemukan.', 'notfound');
            }

            unset($master[$collection][$index]['biometric']);
            return $master;
        }, 'biometric-reset');

        if (!$result['ok']) {
            throw new \RuntimeException((string)($result['error'] ?? 'Gagal mereset biometrik.'));
        }

        return self::createSetupToken($entityType, $entityId);
    }

    /**
     * Status pendaftaran biometrik entitas.
     */
    public static function enrollmentStatus(string $entityType, string $entityId): array {
        $row = self::findEntity($entityType, $entityId);
        $bio = (array)($row['biometric'] ?? []);
        return [
            'enrolled' => !empty($bio['enrolled']),
            'method' => $bio['method'] ?? null,
            'enrolled_at' => $bio['enrolled_at'] ?? null,
            'entity_name' => (string)($row['nama_lengkap'] ?? $row['nama_tim'] ?? $entityId),
        ];
    }

    /**
     * Hash pola 3x3 (urutan indeks titik 1-9).
     */
    public static function hashPattern(string $pattern): string {
        return hash('sha256', $pattern);
    }

    /**
     * Verifikasi pola 3x3 terhadap hash tersimpan.
     */
    public static function verifyPattern(string $entityType, string $entityId, string $pattern): bool {
        $row = self::findEntity($entityType, $entityId);
        $bio = (array)($row['biometric'] ?? []);
        if (!empty($bio['enrolled']) && $bio['method'] === 'pattern' && !empty($bio['pattern_hash'])) {
            return hash_equals($bio['pattern_hash'], self::hashPattern($pattern));
        }
        return false;
    }

    /**
     * Verifikasi assertion WebAuthn (ES256) terhadap public key tersimpan.
     * Memeriksa tanda tangan, challenge, origin, rpIdHash, dan flag user-present
     * sehingga credential_id saja tidak cukup untuk lolos.
     */
    public static function verifyWebauthnAssertion(
        string $entityType,
        string $entityId,
        string $challenge,
        string $authenticatorDataB64,
        string $clientDataJsonB64,
        string $signatureB64,
        string $expectedOrigin
    ): bool {
        $row = self::findEntity($entityType, $entityId);
        $bio = (array)($row['biometric'] ?? []);
        if (empty($bio['enrolled']) || ($bio['method'] ?? '') !== 'webauthn' || empty($bio['public_key'])) {
            return false;
        }

        $authData = self::b64urlDecode($authenticatorDataB64);
        $clientDataJson = self::b64urlDecode($clientDataJsonB64);
        $signature = self::b64urlDecode($signatureB64);
        if ($authData === null || $clientDataJson === null || $signature === null) {
            return false;
        }

        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.get') {
            return false;
        }

        // Challenge di server & clientDataJSON sama-sama base64url; bandingkan byte.
        $expectedChallengeRaw = self::b64urlDecode($challenge);
        $gotChallengeRaw = self::b64urlDecode((string)($clientData['challenge'] ?? ''));
        if ($expectedChallengeRaw === null || $gotChallengeRaw === null
            || !hash_equals($expectedChallengeRaw, $gotChallengeRaw)) {
            return false;
        }

        if (strcasecmp(rtrim($expectedOrigin, '/'), rtrim((string)($clientData['origin'] ?? ''), '/')) !== 0) {
            return false;
        }

        $host = (string)parse_url($expectedOrigin, PHP_URL_HOST);
        $rpIdHash = hash('sha256', $host, true);
        if (strlen($authData) < 37 || !hash_equals($rpIdHash, substr($authData, 0, 32))) {
            return false;
        }

        $flags = ord($authData[32]);
        if (($flags & 0x01) !== 0x01) { // User Present wajib
            return false;
        }

        $signedData = $authData . hash('sha256', $clientDataJson, true);
        $pubPem = (string)$bio['public_key'];
        if (openssl_verify($signedData, $signature, $pubPem, OPENSSL_ALGO_SHA256) === 1) {
            return true;
        }
        // Fallback untuk kunci Ed25519 (alg: -8) yang tidak memakai hash tambahan
        return @openssl_verify($signedData, $signature, $pubPem, 0) === 1;
    }

    /**
     * Ubah koordinat EC P-256 (base64url) menjadi public key PEM (SPKI).
     */
    public static function pemFromCoordinates(string $x, string $y): ?string {
        $xBin = self::b64urlDecode($x);
        $yBin = self::b64urlDecode($y);
        if ($xBin === null || $yBin === null || strlen($xBin) !== 32 || strlen($yBin) !== 32) {
            return null;
        }
        $algorithm = self::der(0x30,
            self::der(0x06, "\x2A\x86\x48\xCE\x3D\x02\x01") . // ecPublicKey
            self::der(0x06, "\x2A\x86\x48\xCE\x3D\x03\x01\x07")   // prime256v1
        );
        $bitString = self::der(0x03, "\x00\x04" . $xBin . $yBin);
        $spki = self::der(0x30, $algorithm . $bitString);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Ubah SPKI DER (base64url, dari getPublicKey()) menjadi PEM dan validasi via OpenSSL.
     */
    public static function pemFromSpki(string $spkiB64Url): ?string {
        $der = self::b64urlDecode($spkiB64Url);
        if ($der === null || $der === '') {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }
        return $pem;
    }

    /**
     * Ubah modulus (n) & eksponen (e) RSA (base64url) menjadi PEM SPKI.
     */
    public static function pemFromRsaModExp(string $nB64Url, string $eB64Url): ?string {
        $n = self::b64urlDecode($nB64Url);
        $e = self::b64urlDecode($eB64Url);
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }
        $intN = self::der(0x02, (ord($n[0]) & 0x80) ? "\x00" . $n : $n);
        $intE = self::der(0x02, (ord($e[0]) & 0x80) ? "\x00" . $e : $e);
        $rsaPubKey = self::der(0x30, $intN . $intE);
        $algorithm = self::der(0x30,
            self::der(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01") . "\x05\x00"
        );
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaPubKey));
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        return @openssl_pkey_get_public($pem) !== false ? $pem : null;
    }

    /**
     * Ubah public key Ed25519 (32 byte base64url) menjadi PEM SPKI.
     */
    public static function pemFromEd25519(string $xB64Url): ?string {
        $x = self::b64urlDecode($xB64Url);
        if ($x === null || strlen($x) !== 32) {
            return null;
        }
        $spki = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" . $x;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        return @openssl_pkey_get_public($pem) !== false ? $pem : null;
    }

    /**
     * Ekstrak PEM public key dari authenticatorData WebAuthn (base64url).
     */
    public static function pemFromAuthData(string $authDataB64Url): ?string {
        $authData = self::b64urlDecode($authDataB64Url);
        if ($authData === null || strlen($authData) < 55) {
            return null;
        }
        if ((ord($authData[32]) & 0x40) === 0) {
            return null;
        }
        $credIdLen = (ord($authData[53]) << 8) | ord($authData[54]);
        $coseOffset = 55 + $credIdLen;
        if ($coseOffset >= strlen($authData)) {
            return null;
        }
        $offset = $coseOffset;
        $cose = self::parseCbor($authData, $offset);
        if (!is_array($cose)) {
            return null;
        }
        $kty = $cose[1] ?? null;
        $crv = $cose[-1] ?? null;
        if ($kty === 2 && $crv === 1 && is_string($cose[-2] ?? null) && is_string($cose[-3] ?? null)) {
            return self::pemFromCoordinates(self::b64urlEncode($cose[-2]), self::b64urlEncode($cose[-3]));
        }
        if ($kty === 3 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
            return self::pemFromRsaModExp(self::b64urlEncode($cose[-1]), self::b64urlEncode($cose[-2]));
        }
        if ($kty === 1 && $crv === 6 && is_string($cose[-2] ?? null)) {
            return self::pemFromEd25519(self::b64urlEncode($cose[-2]));
        }
        return null;
    }

    /**
     * Ekstrak PEM public key dari attestationObject WebAuthn (base64url).
     */
    public static function pemFromAttestationObject(string $attObjB64Url): ?string {
        $att = self::b64urlDecode($attObjB64Url);
        if ($att === null || $att === '') {
            return null;
        }
        $offset = 0;
        $decoded = self::parseCbor($att, $offset);
        if (!is_array($decoded) || !is_string($decoded['authData'] ?? null)) {
            return null;
        }
        return self::pemFromAuthData(self::b64urlEncode($decoded['authData']));
    }

    private static function parseCbor(string $bytes, int &$offset) {
        $len = strlen($bytes);
        if ($offset >= $len) {
            return null;
        }
        $initial = ord($bytes[$offset++]);
        $major = $initial >> 5;
        $additional = $initial & 0x1f;
        $valLen = self::parseCborLength($bytes, $offset, $additional);
        if ($valLen === null) {
            return null;
        }
        return match ($major) {
            0 => $valLen,
            1 => -1 - $valLen,
            2, 3 => (function () use ($bytes, &$offset, $valLen): ?string {
                $slice = substr($bytes, $offset, $valLen);
                $offset += $valLen;
                return strlen($slice) === $valLen ? $slice : null;
            })(),
            4 => (function () use ($bytes, &$offset, $valLen): array {
                $arr = [];
                for ($i = 0; $i < $valLen; $i++) {
                    $arr[] = self::parseCbor($bytes, $offset);
                }
                return $arr;
            })(),
            5 => (function () use ($bytes, &$offset, $valLen): array {
                $map = [];
                for ($i = 0; $i < $valLen; $i++) {
                    $k = self::parseCbor($bytes, $offset);
                    $v = self::parseCbor($bytes, $offset);
                    if ($k !== null) {
                        $map[$k] = $v;
                    }
                }
                return $map;
            })(),
            default => null,
        };
    }

    private static function parseCborLength(string $bytes, int &$offset, int $additional): ?int {
        if ($additional < 24) {
            return $additional;
        }
        $count = match ($additional) {
            24 => 1,
            25 => 2,
            26 => 4,
            default => 0,
        };
        if ($count === 0 || $offset + $count > strlen($bytes)) {
            return null;
        }
        $value = 0;
        for ($i = 0; $i < $count; $i++) {
            $value = ($value << 8) | ord($bytes[$offset++]);
        }
        return $value;
    }

    /**
     * Cari entitas (mentor/mentee) berdasarkan credential_id WebAuthn.
     *
     * @return array{entity_type:string, entity_id:string}|null
     */
    public static function findEntityByCredentialId(string $credentialId): ?array {
        if ($credentialId === '') {
            return null;
        }
        $master = self::loadMaster();
        foreach (['mentor' => 'mentors', 'mentee' => 'mentees'] as $type => $collection) {
            foreach ($master[$collection] ?? [] as $row) {
                $bio = (array)($row['biometric'] ?? []);
                if (!empty($bio['enrolled']) && hash_equals((string)($bio['credential_id'] ?? ''), $credentialId)) {
                    return ['entity_type' => $type, 'entity_id' => (string)($row['id'] ?? '')];
                }
            }
        }
        return null;
    }

    private static function der(int $tag, string $content): string {
        $length = strlen($content);
        if ($length < 0x80) {
            $lengthBytes = chr($length);
        } else {
            $packed = ltrim(pack('N', $length), "\x00");
            $lengthBytes = chr(0x80 | strlen($packed)) . $packed;
        }
        return chr($tag) . $lengthBytes . $content;
    }

    public static function b64urlEncode(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $value): ?string {
        if ($value === '') {
            return '';
        }
        $normalized = strtr($value, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder !== 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($normalized, true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * Buat challenge WebAuthn 32-byte bertanda tangan HMAC (4B timestamp + 12B nonce + 16B HMAC)
     * dan simpan ke sesi. Tetap valid meski sesi PHP ter-reset di hosting bersama.
     */
    public static function generateChallenge(): string {
        $tsBytes = pack('N', time());
        $nonceBytes = random_bytes(12);
        $mac = substr(hash_hmac('sha256', $tsBytes . $nonceBytes, self::offlineSecret(), true), 0, 16);
        $challenge = self::b64urlEncode($tsBytes . $nonceBytes . $mac);
        self::storeChallenge($challenge);
        return $challenge;
    }

    /**
     * Simpan challenge WebAuthn ke sesi.
     */
    public static function storeChallenge(string $challenge): void {
        self::startSession();
        $_SESSION['biometric_challenge'] = $challenge;
        $_SESSION['biometric_challenge_at'] = time();
    }

    public static function consumeChallenge(string $challenge): bool {
        if ($challenge === '') {
            return false;
        }
        self::startSession();

        // Cegah replay dalam sesi yang sama
        $used = $_SESSION['biometric_used_challenges'] ?? [];
        if (is_array($used) && in_array($challenge, $used, true)) {
            return false;
        }

        $markUsed = static function () use ($challenge, $used): void {
            unset($_SESSION['biometric_challenge'], $_SESSION['biometric_challenge_at']);
            $list = is_array($used) ? $used : [];
            $list[] = $challenge;
            if (count($list) > 20) {
                $list = array_slice($list, -20);
            }
            $_SESSION['biometric_used_challenges'] = $list;
        };

        // Jalur 1: cocok dengan sesi aktif
        if (!empty($_SESSION['biometric_challenge'])
            && hash_equals((string)$_SESSION['biometric_challenge'], $challenge)
            && (int)($_SESSION['biometric_challenge_at'] ?? 0) >= time() - self::CHALLENGE_TTL_SECONDS) {
            $markUsed();
            return true;
        }

        // Jalur 2: verifikasi HMAC 32-byte signed challenge (tahan terhadap rotasi/hilangnya sesi)
        $raw = self::b64urlDecode($challenge);
        if ($raw !== null && strlen($raw) === 32) {
            $tsBytes = substr($raw, 0, 4);
            $nonceBytes = substr($raw, 4, 12);
            $mac = substr($raw, 16, 16);
            $expectedMac = substr(hash_hmac('sha256', $tsBytes . $nonceBytes, self::offlineSecret(), true), 0, 16);
            if (hash_equals($expectedMac, $mac)) {
                $unpacked = unpack('Nts', $tsBytes);
                $ts = (int)($unpacked['ts'] ?? 0);
                $now = time();
                if ($ts <= $now + 30 && $ts >= $now - self::CHALLENGE_TTL_SECONDS) {
                    $markUsed();
                    return true;
                }
            }
        }

        return false;
    }

    public static function startSession(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * Simpan verifikasi biometrik yang lulus ke sesi (untuk enforcing submit.php).
     */
    public static function storeVerified(string $entityType, string $entityId, string $teamId): void {
        self::startSession();
        $_SESSION['biometric_verified'] = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'team_id' => $teamId,
            'verified_at' => time(),
        ];
    }

    /**
     * Cek apakah sesi saat ini telah terverifikasi.
     * Jika $teamId diisi dan tersimpan verifikasi memiliki team_id, maka tim harus cocok.
     * Jika tersimpan tanpa team_id (kosong), verifikasi berlaku untuk semua tim.
     * Mengembalikan data verifikasi atau null.
     */
    public static function checkVerified(string $teamId = ''): ?array {
        self::startSession();
        $v = $_SESSION['biometric_verified'] ?? null;
        if (!is_array($v) || empty($v['verified_at'])) {
            return null;
        }
        if ((int)($v['verified_at']) < time() - self::CHALLENGE_TTL_SECONDS) {
            return null;
        }
        $storedTeam = (string)($v['team_id'] ?? '');
        if ($storedTeam !== '' && $teamId !== '' && $storedTeam !== $teamId) {
            return null;
        }
        return $v;
    }

    public static function clearVerified(): void {
        self::startSession();
        unset($_SESSION['biometric_verified']);
    }

    /**
     * Secret key untuk menandatangani token verifikasi offline (HMAC-SHA256).
     */
    private static function offlineSecret(): string {
        $s3Secret = (string)Config::get('storage.s3.secret_key', '');
        if ($s3Secret !== '') {
            return hash('sha256', 'mai-bio-offline:' . $s3Secret, true);
        }
        $secretFile = self::getSecureDir() . '/offline_secret.key';
        if (file_exists($secretFile)) {
            $raw = trim((string)file_get_contents($secretFile));
            if ($raw !== '') {
                return hash('sha256', $raw, true);
            }
        }
        $newKey = bin2hex(random_bytes(32));
        @file_put_contents($secretFile, $newKey);
        return hash('sha256', $newKey, true);
    }

    /**
     * Buat token verifikasi offline yang berlaku 30 hari untuk perangkat ini.
     */
    public static function createOfflineToken(string $entityType, string $entityId, string $teamId = ''): string {
        $payload = [
            'et' => $entityType,
            'eid' => $entityId,
            'tid' => $teamId,
            'exp' => time() + (30 * 86400),
        ];
        $encoded = self::b64urlEncode((string)json_encode($payload));
        $sig = self::b64urlEncode(hash_hmac('sha256', $encoded, self::offlineSecret(), true));
        return $encoded . '.' . $sig;
    }

    /**
     * Verifikasi token offline dari sinkronisasi PWA.
     */
    public static function verifyOfflineToken(string $token, string $teamId = ''): ?array {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $sig] = $parts;
        $expectedSig = self::b64urlEncode(hash_hmac('sha256', $encoded, self::offlineSecret(), true));
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }
        $raw = self::b64urlDecode($encoded);
        if ($raw === null) {
            return null;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || (int)($payload['exp'] ?? 0) < time()) {
            return null;
        }
        $storedTeam = (string)($payload['tid'] ?? '');
        if ($storedTeam !== '' && $teamId !== '' && $storedTeam !== $teamId) {
            return null;
        }
        $entityType = (string)($payload['et'] ?? '');
        $entityId = (string)($payload['eid'] ?? '');
        if (!in_array($entityType, self::ENTITY_TYPES, true) || $entityId === '') {
            return null;
        }
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'team_id' => $storedTeam,
            'offline_token' => true,
        ];
    }

    /**
     * Daftar mentee yang di-delegasikan sebagai perwakilan oleh mentor.
     */
    public static function getDelegates(string $mentorId): array {
        $master = self::loadMaster();
        $delegates = [];
        foreach ($master['mentees'] ?? [] as $m) {
            if ((string)($m['mentor_id'] ?? '') === $mentorId && !empty($m['delegate_for'] ?? '')) {
                $delegates[] = $m;
            }
        }
        return $delegates;
    }

    /**
     * Tentukan siapa yang berhak memverifikasi untuk suatu tim: mentor + delegate-nya.
     */
    public static function getVerifiersForTeam(string $teamId): array {
        $master = self::loadMaster();
        $team = null;
        foreach ($master['teams'] ?? [] as $t) {
            if ((string)($t['id'] ?? '') === $teamId) {
                $team = $t;
                break;
            }
        }
        $verifiers = [];
        if ($team === null) {
            return $verifiers;
        }

        $mentorId = (string)($team['mentor_id'] ?? '');
        $mentor = null;
        foreach ($master['mentors'] ?? [] as $m) {
            if ((string)($m['id'] ?? '') === $mentorId) {
                $mentor = $m;
                break;
            }
        }
        if ($mentor !== null) {
            $verifiers[] = [
                'entity_type' => 'mentor',
                'entity_id' => $mentorId,
                'entity_name' => (string)($mentor['nama_lengkap'] ?? ''),
                'role' => 'Mentor',
            ];
        }

        foreach ($master['mentees'] ?? [] as $m) {
            if ((string)($m['mentor_id'] ?? '') === $mentorId && !empty($m['delegate_for'] ?? '')) {
                $verifiers[] = [
                    'entity_type' => 'mentee',
                    'entity_id' => (string)($m['id'] ?? ''),
                    'entity_name' => (string)($m['nama_lengkap'] ?? ''),
                    'role' => 'Mentee Perwakilan',
                ];
            }
        }

        return $verifiers;
    }

    /**
     * Set/clear mentee delegate untuk mentor (admin).
     */
    public static function setDelegate(string $mentorId, ?string $menteeId): bool {
        $result = MasterData::transaction(static function (array $master) use ($mentorId, $menteeId): ?array {
            $changed = false;

            // Hapus delegate lama untuk mentor ini
            foreach ($master['mentees'] ?? [] as $i => $m) {
                if ((string)($m['mentor_id'] ?? '') === $mentorId && !empty($m['delegate_for'] ?? '')) {
                    unset($master['mentees'][$i]['delegate_for']);
                    $changed = true;
                }
            }

            if ($menteeId !== null) {
                foreach ($master['mentees'] ?? [] as $i => $m) {
                    if ((string)($m['id'] ?? '') === $menteeId && (string)($m['mentor_id'] ?? '') === $mentorId) {
                        $master['mentees'][$i]['delegate_for'] = $mentorId;
                        $changed = true;
                        break;
                    }
                }
            }

            return $changed ? $master : null;
        }, 'biometric-delegate');

        return (bool)$result['ok'];
    }
}
