<?php
header('X-Robots-Tag: noindex, nofollow, noarchive');
require_once __DIR__ . '/security_helper.php';
require_once __DIR__ . '/store.php';

ukmi_session_start();
$configPath = __DIR__ . '/data.json';
$config = store_read_json($configPath);
$sessionVersion = is_array($config) && isset($config['session_version']) ? max(1, (int) $config['session_version']) : 1;
$loggedIn = !empty($_SESSION['admin_logged_in'])
    && (($_SESSION['session_version'] ?? 0) === $sessionVersion)
    && (($_SESSION['role'] ?? '') === 'admin');
if (!$loggedIn) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Akses ditolak</title></head><body><h1>Akses ditolak</h1><p>Halaman ini hanya untuk admin yang sudah login.</p></body></html>';
    exit;
}

$defaultPath = __DIR__ . '/default.json';
$defaults = ['fb_app_id' => ''];
if (is_readable($defaultPath)) {
    $tmp = json_decode((string) file_get_contents($defaultPath), true);
    if (is_array($tmp)) $defaults = array_merge($defaults, $tmp);
}

$fbAppId = null;
if (is_array($config) && !empty($config['fb_app_id'])) {
    $fbAppId = trim((string) $config['fb_app_id']);
} elseif (!empty($defaults['fb_app_id'])) {
    $fbAppId = trim((string) $defaults['fb_app_id']);
}

$baseUrl = 'https://ukmipolmed.my.id';
$redirectUri = $baseUrl . '/oauth';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// If callback from Facebook
if (isset($_GET['code']) || isset($_GET['error'])) {
    $code = $_GET['code'] ?? null;
    $error = $_GET['error'] ?? null;
    $state = $_GET['state'] ?? null;
    $saved = $_SESSION['fb_oauth_state'] ?? null;
    // Basic state check (if present)
    $stateOk = ($state !== null && $saved !== null && hash_equals($saved, $state));
    ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>OAuth Callback</title></head><body>
<h2>OAuth Callback</h2>
<?php if ($error): ?>
    <p>Facebook returned an error: <?php echo h($error); ?></p>
<?php else: ?>
    <p>Received code: <code><?php echo h($code); ?></code></p>
    <p>State: <?php echo h($state); ?> (<?php echo $stateOk ? 'ok' : 'mismatch'; ?>)</p>
    <p>Next: exchange this code for an access token on your server using the App Secret.</p>
<?php endif; ?>
<p><a href="/blog">Kembali ke blog</a></p>
</body></html>
<?php
    exit;
}

// Start flow page
$state = bin2hex(random_bytes(12));
$_SESSION['fb_oauth_state'] = $state;

$fbAuthUrl = null;
if (!empty($fbAppId)) {
    $params = http_build_query([
        'client_id' => $fbAppId,
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'scope' => 'email',
        'response_type' => 'code',
    ]);
    $fbAuthUrl = 'https://www.facebook.com/v16.0/dialog/oauth?' . $params;
}

?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><title>OAuth Redirect</title></head><body>
<h1>OAuth Redirect Endpoint</h1>
<p>Gunakan URL berikut sebagai Redirect URI di pengaturan Facebook App:</p>
<pre><?php echo h($redirectUri); ?></pre>

<?php if ($fbAuthUrl): ?>
    <p>Untuk menguji alur OAuth, buka tautan ini (akan mengarahkan ke Facebook):</p>
    <p><a href="<?php echo h($fbAuthUrl); ?>">Mulai login dengan Facebook</a></p>
<?php else: ?>
    <p>Tidak ada `fb_app_id` di <code>data.json</code> atau <code>default.json</code>. Tambahkan <code>"fb_app_id": "YOUR_APP_ID"</code> lalu refresh.</p>
<?php endif; ?>

<p>Setelah Facebook mengarahkan kembali ke URL ini, Anda akan melihat parameter <code>code</code> (yang perlu ditukar menjadi access token oleh server).</p>
<p><a href="/blog">Kembali ke blog</a></p>
</body></html>

