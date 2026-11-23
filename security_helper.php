<?php
// security_helper.php — utilitas sesi, CSRF, rate limit, dan verifikasi key admin.

if (!function_exists('ukmi_is_https')) {
	function ukmi_is_https(): bool
	{
		if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
			return true;
		}
		if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
			return true;
		}
		return false;
	}
}

if (!function_exists('ukmi_session_start')) {
	function ukmi_session_start(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			return;
		}
		if (headers_sent()) {
			return;
		}
		@session_set_cookie_params([
			'lifetime' => 0,
			'path' => '/',
			'secure' => ukmi_is_https(),
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		@session_start();
	}
}

if (!function_exists('ukmi_csrf_token')) {
	function ukmi_csrf_token(): string
	{
		ukmi_session_start();
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return '';
		}
		if (empty($_SESSION['ukmi_csrf_token']) || !is_string($_SESSION['ukmi_csrf_token'])) {
			try {
				$_SESSION['ukmi_csrf_token'] = bin2hex(random_bytes(32));
			} catch (Throwable $e) {
				$_SESSION['ukmi_csrf_token'] = hash('sha256', uniqid('', true) . (string) mt_rand());
			}
		}
		return (string) $_SESSION['ukmi_csrf_token'];
	}
}

if (!function_exists('ukmi_csrf_field')) {
	function ukmi_csrf_field(): string
	{
		$token = ukmi_csrf_token();
		return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
	}
}

if (!function_exists('ukmi_csrf_valid')) {
	function ukmi_csrf_valid(): bool
	{
		ukmi_session_start();
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return false;
		}
		$known = (string) ($_SESSION['ukmi_csrf_token'] ?? '');
		if ($known === '') {
			return false;
		}
		$sent = (string) ($_POST['csrf_token'] ?? '');
		if ($sent === '' && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
			$sent = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
		}
		return $sent !== '' && hash_equals($known, $sent);
	}
}

if (!function_exists('ukmi_client_ip')) {
	function ukmi_client_ip(): string
	{
		$candidates = [
			$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
			$_SERVER['REMOTE_ADDR'] ?? '',
		];
		foreach ($candidates as $candidate) {
			$ip = trim((string) $candidate);
			if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
				return $ip;
			}
		}
		return 'unknown';
	}
}

if (!function_exists('ukmi_rate_limit')) {
	function ukmi_rate_limit(string $bucket, int $maxAttempts, int $windowSeconds): bool
	{
		if (PHP_SAPI === 'cli') {
			return false;
		}
		if (!function_exists('seo_cache_path')) {
			return false;
		}
		$cleanBucket = preg_replace('/[^a-zA-Z0-9_-]/', '', $bucket);
		if ($cleanBucket === '') {
			$cleanBucket = 'default';
		}
		$ip = ukmi_client_ip();
		$file = seo_cache_path('rate-' . $cleanBucket . '-' . sha1($ip) . '.json');
		$now = time();
		$fh = @fopen($file, 'c+');
		if (!$fh) {
			return false;
		}
		if (!@flock($fh, LOCK_EX)) {
			@fclose($fh);
			return false;
		}
		$raw = (string) @stream_get_contents($fh);
		$stamps = json_decode($raw, true);
		if (!is_array($stamps)) {
			$stamps = [];
		}
		$stamps = array_values(array_filter($stamps, function ($t) use ($now, $windowSeconds) {
			return is_int($t) && $t > ($now - $windowSeconds);
		}));
		if (count($stamps) >= $maxAttempts) {
			@flock($fh, LOCK_UN);
			@fclose($fh);
			return true;
		}
		$stamps[] = $now;
		@ftruncate($fh, 0);
		@rewind($fh);
		@fwrite($fh, json_encode($stamps, JSON_UNESCAPED_SLASHES));
		@fflush($fh);
		@flock($fh, LOCK_UN);
		@fclose($fh);
		if (function_exists('seo_cache_gc')) {
			seo_cache_gc(basename($file));
		}
		return false;
	}
}

if (!function_exists('ukmi_is_password_hashed')) {
	function ukmi_is_password_hashed(string $stored): bool
	{
		$info = password_get_info($stored);
		return isset($info['algo']) && $info['algo'] !== null && $info['algo'] !== 0;
	}
}

if (!function_exists('ukmi_verify_admin_key')) {
	function ukmi_verify_admin_key(string $stored, string $provided): bool
	{
		if ($stored === '' || $provided === '') {
			return false;
		}
		if (ukmi_is_password_hashed($stored)) {
			return password_verify($provided, $stored);
		}
		return hash_equals($stored, $provided);
	}
}
