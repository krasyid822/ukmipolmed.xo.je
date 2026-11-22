<?php
/**
 * src/s3_client.php — Klien Blomp S3-compatible (SigV4, path-style).
 *
 * Implementasi disinkronkan dengan s3_helper.php proyek utama:
 * - endpoint/region/bucket/kredensial berasal dari storage.s3 di default.json,
 * - setiap segmen key di-encode (rawurlencode),
 * - isolasi prefix: <prefix utama>/<sub_prefix> (mis. ukmipolmed/terpadu/mai/mentoring/).
 *
 * Catatan: Blomp menolak GET anonim. Objek publik (dokumentasi/og_meta/profil)
 * disajikan melalui media.php yang melakukan signed GET di sisi server.
 */

declare(strict_types=1);

namespace Mentoring;

class S3Client {
    private string $region;
    private string $endpoint;
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $prefix;

    public function __construct(?array $customConfig = null) {
        $s3Cfg = $customConfig ?? Config::getS3();
        $this->region = (string)($s3Cfg['region'] ?? 'us-east-1');
        $this->endpoint = rtrim((string)($s3Cfg['endpoint'] ?? 'https://storage.blomp.com'), '/');
        $this->accessKey = (string)($s3Cfg['access_key'] ?? $s3Cfg['credentials']['key'] ?? '');
        $this->secretKey = (string)($s3Cfg['secret_key'] ?? $s3Cfg['credentials']['secret'] ?? '');
        $this->bucket = (string)($s3Cfg['bucket'] ?? '');

        $rootPrefix = trim((string)($s3Cfg['prefix'] ?? 'ukmipolmed/terpadu/'), '/');
        $subPrefix = trim((string)Config::get('storage.sub_prefix', 'mai/mentoring/'), '/');
        $parts = array_filter([$rootPrefix, $subPrefix], static fn(string $p): bool => $p !== '');
        $this->prefix = implode('/', $parts) . '/';
    }

    public function getPrefix(): string {
        return $this->prefix;
    }

    public function getFullKey(string $relativeKey): string {
        return $this->prefix . ltrim($relativeKey, '/');
    }

    /**
     * Upload objek ke Blomp S3.
     */
    public function putObject(string $relativeKey, string $body, string $contentType = 'application/octet-stream', bool $isPublic = false): bool {
        $fullKey = $this->getFullKey($relativeKey);
        $headers = ['content-type' => $contentType];
        if ($isPublic) {
            $headers['x-amz-acl'] = 'public-read';
        }

        $res = $this->request('PUT', $fullKey, $headers, $body);
        return $res['code'] >= 200 && $res['code'] < 300;
    }

    /**
     * Download objek dari Blomp S3 (signed GET). Null bila gagal.
     */
    public function getObject(string $relativeKey): ?string {
        $res = $this->request('GET', $this->getFullKey($relativeKey), [], '');
        if ($res['code'] >= 200 && $res['code'] < 300) {
            return $res['body'];
        }
        return null;
    }

    /**
     * Hapus objek dari Blomp S3 (signed DELETE).
     */
    public function deleteObject(string $relativeKey): bool {
        $res = $this->request('DELETE', $this->getFullKey($relativeKey), [], '');
        return ($res['code'] >= 200 && $res['code'] < 300) || $res['code'] === 404;
    }

    /**
     * Kirim request SigV4 path-style.
     *
     * @param array<string,string> $headers Header tambahan (lowercase key).
     * @return array{code:int,body:?string,error:string}
     */
    private function request(string $method, string $fullKey, array $headers, string $payload): array {
        $parsed = parse_url($this->endpoint);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? null;
        if ($host === '' || $this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
            return ['code' => 0, 'body' => null, 'error' => 'Konfigurasi S3 tidak lengkap'];
        }
        $hostHeader = $port ? ($host . ':' . $port) : $host;

        // Canonical URI: /bucket/key, tiap segmen di-encode.
        $segments = explode('/', trim($this->bucket . '/' . $fullKey, '/'));
        $encoded = array_map(static fn(string $seg): string => rawurlencode($seg), $segments);
        $canonicalUri = '/' . implode('/', $encoded);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $payload);

        $signHeaders = $headers;
        $signHeaders['host'] = $hostHeader;
        $signHeaders['x-amz-content-sha256'] = $payloadHash;
        $signHeaders['x-amz-date'] = $amzDate;
        ksort($signHeaders);

        $canonicalHeaders = '';
        foreach ($signHeaders as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim((string)$value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($signHeaders));
        $canonicalRequest = $method . "\n" . $canonicalUri . "\n\n"
            . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        $curlHeaders[] = 'x-amz-content-sha256: ' . $payloadHash;
        $curlHeaders[] = 'x-amz-date: ' . $amzDate;
        $curlHeaders[] = 'Authorization: ' . $authorization;

        $url = $scheme . '://' . $hostHeader . $canonicalUri;
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $method === 'PUT' ? 120 : 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string)curl_error($ch);
        curl_close($ch);

        return [
            'code' => $code,
            'body' => is_string($body) ? $body : null,
            'error' => $error,
        ];
    }
}
