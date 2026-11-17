<?php
session_start();
require_once __DIR__ . '/authors_helper.php';

$configPath = __DIR__ . '/data.json';
$config = [];
if (is_readable($configPath)) {
    $config = json_decode((string) @file_get_contents($configPath), true) ?: [];
}
$sessionVersion = (int) ($config['session_version'] ?? 1);
$isWriter = !empty($_SESSION['admin_logged_in'])
    && (($_SESSION['role'] ?? '') === 'writer')
    && (($_SESSION['session_version'] ?? 0) === $sessionVersion);
if (!$isWriter) {
    header('Location: admin.php');
    exit;
}

$aid = (string) ($_SESSION['author_id'] ?? '');
$data = authors_load();
$meIdx = -1;
foreach ($data['authors'] as $i => $a) {
    if ((string) ($a['id'] ?? '') === $aid) {
        $meIdx = $i;
        break;
    }
}
if ($meIdx < 0) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$msg = '';
$err = '';
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $data['authors'][$meIdx]['pen_name'] = trim((string) ($_POST['pen_name'] ?? ''));
    $data['authors'][$meIdx]['bio'] = trim((string) ($_POST['bio'] ?? ''));
    $data['authors'][$meIdx]['avatar'] = trim((string) ($_POST['avatar'] ?? ''));
    $data['authors'][$meIdx]['social'] = trim((string) ($_POST['social'] ?? ''));
    if (authors_save($data)) {
        $msg = 'Profil disimpan.';
    } else {
        $err = 'Gagal menyimpan profil.';
    }
}

$me = $data['authors'][$meIdx];
$username = (string) ($me['username'] ?? '');

$all = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];
$myPosts = [];
foreach ($all as $p) {
    if (trim((string) ($p['author_id'] ?? '')) === $aid && empty($p['archived'])) {
        $myPosts[] = $p;
    }
}
usort($myPosts, fn($x, $y) => strcmp((string) ($y['created_at'] ?? ''), (string) ($x['created_at'] ?? '')));
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel Penulis – <?php echo htmlspecialchars((string) ($me['pen_name'] ?: $username), ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<style>
:root{--bg:#0b1120;--card:#111a2e;--text:#e5e7eb;--muted:#94a3b8;--stroke:rgba(148,163,184,.2);--accent:#38bdf8}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 600px at 20% -10%,#13203a,var(--bg));color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.6}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px 60px}
h1{font-size:20px;margin:0 0 4px}
.muted{color:var(--muted);font-size:13px}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:16px;margin-top:14px}
label{display:block;font-size:13px;color:var(--muted);margin:8px 0 4px}
input,textarea{width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text)}
button{background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#04202e;border:0;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer}
.btn{display:inline-block;text-decoration:none;color:var(--text);border:1px solid var(--stroke);border-radius:9px;padding:8px 12px;font-size:14px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
.ok{color:#4ade80}.err{color:#f87171}
.pcard{display:block;text-decoration:none;color:inherit;border:1px solid var(--stroke);border-radius:12px;padding:12px 14px;margin-top:10px}
.pcard h3{margin:0 0 3px;font-size:15px}
.avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--stroke);vertical-align:middle;margin-right:10px}
</style>
</head>
<body>
<div class="wrap">
  <div class="row" style="justify-content:space-between;margin-top:0;">
    <div><h1>Panel Penulis</h1><div class="muted">@<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div></div>
    <div class="row" style="margin:0;">
      <a class="btn" href="/penulis/<?php echo rawurlencode($username); ?>" target="_blank" rel="noopener">Lihat profil</a>
      <a class="btn" href="?logout=1">Keluar</a>
    </div>
  </div>

  <?php if ($msg !== ''): ?><p class="ok"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="err"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

  <div class="card">
    <h2 style="font-size:15px;margin:0 0 6px;">Profil publik</h2>
    <form method="post">
      <input type="hidden" name="save_profile" value="1">
      <label for="pen_name">Nama pena</label>
      <input type="text" id="pen_name" name="pen_name" value="<?php echo htmlspecialchars((string) ($me['pen_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nama tampil di artikel">
      <label for="bio">Bio</label>
      <textarea id="bio" name="bio" rows="3" placeholder="Deskripsi singkat"><?php echo htmlspecialchars((string) ($me['bio'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
      <label for="avatar">Avatar (URL gambar, atau biarkan kosong = default)</label>
      <input type="text" id="avatar" name="avatar" value="<?php echo htmlspecialchars((string) ($me['avatar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg">
      <label for="social">Social media link</label>
      <input type="text" id="social" name="social" value="<?php echo htmlspecialchars((string) ($me['social'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://instagram.com/...">
      <div class="row"><button type="submit">Simpan profil</button></div>
    </form>
  </div>

  <div class="card">
    <h2 style="font-size:15px;margin:0 0 4px;">Tulisan saya (<?php echo count($myPosts); ?>)</h2>
    <?php if (empty($myPosts)): ?>
      <p class="muted">Belum ada tulisan yang diatribusikan ke akun ini.</p>
    <?php else: foreach ($myPosts as $p): ?>
      <a class="pcard" href="/blog/<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>" target="_blank" rel="noopener">
        <h3><?php echo htmlspecialchars((string) ($p['title'] ?? 'Tanpa judul'), ENT_QUOTES, 'UTF-8'); ?></h3>
        <div class="muted"><?php echo htmlspecialchars((string) (($p['created_at'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div>
      </a>
    <?php endforeach; endif; ?>
  </div>
</div>
</body>
</html>
