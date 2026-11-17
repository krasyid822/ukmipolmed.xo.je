<?php
require_once __DIR__ . '/authors_helper.php';

$u = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
$isAdmin = (strcasecmp($u, 'admin') === 0 || $u === '');

$configPath = __DIR__ . '/data.json';
$config = [];
if (is_readable($configPath)) {
    $config = json_decode((string) @file_get_contents($configPath), true) ?: [];
}
$yelYel = (string) ($config['yel_yel'] ?? '');
$all = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];

if ($isAdmin) {
    $author = author_display('', $yelYel);
    $uid = '';
} else {
    $a = authors_find_by_username($u);
    if (!$a) {
        http_response_code(404);
        $author = null;
        $uid = '';
    } else {
        $author = author_display($a['id'] ?? '', $yelYel);
        $uid = (string) ($a['id'] ?? '');
    }
}

$posts = [];
if ($author !== null) {
    if ($isAdmin) {
        foreach ($all as $p) {
            if (trim((string) ($p['author_id'] ?? '')) === '' && empty($p['archived'])) $posts[] = $p;
        }
    } else {
        foreach ($all as $p) {
            if ($uid !== '' && trim((string) ($p['author_id'] ?? '')) === $uid && empty($p['archived'])) $posts[] = $p;
        }
    }
    usort($posts, fn($x, $y) => strcmp((string) ($y['created_at'] ?? ''), (string) ($x['created_at'] ?? '')));
}

$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id'));
$base = ((PHP_SAPI === 'cli-server') ? 'http://' : 'https://') . ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id');
$title = $author ? ($author['pen_name'] . ' – Penulis Blog UKMI Polmed') : 'Penulis tidak ditemukan';
$metaImage = $author ? $author['avatar'] : '/logo-ukmi.png';
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<meta name="robots" content="index, follow">
<meta property="og:title" content="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
<meta property="og:type" content="profile">
<meta property="og:image" content="<?php echo htmlspecialchars($metaImage, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<style>
:root{--bg:#0b1120;--card:#111a2e;--text:#e5e7eb;--muted:#94a3b8;--stroke:rgba(148,163,184,.2);--accent:#38bdf8}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 600px at 20% -10%,#13203a,var(--bg));color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.6}
.wrap{max-width:820px;margin:0 auto;padding:28px 18px 60px}
.top{display:flex;align-items:center;gap:10px;margin-bottom:22px}
.top a{color:var(--muted);text-decoration:none;font-size:14px}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:16px;padding:20px}
.profile{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.avatar{width:96px;height:96px;border-radius:50%;object-fit:cover;border:2px solid var(--stroke)}
h1{margin:0;font-size:22px}
.bio{color:var(--muted);margin:6px 0 0;font-size:15px}
.social a{display:inline-block;margin-top:8px;color:var(--accent);text-decoration:none;font-size:14px}
.posts{margin-top:22px;display:flex;flex-direction:column;gap:12px}
.pcard{display:block;background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:14px 16px;text-decoration:none;color:inherit}
.pcard h3{margin:0 0 4px;font-size:16px}
.pcard .m{color:var(--muted);font-size:13px}
.empty{color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="/">← Beranda</a><span style="color:var(--muted)">·</span><a href="/blog.php">Blog</a></div>
  <?php if ($author === null): ?>
    <div class="card"><h1>Penulis tidak ditemukan</h1><p class="bio">Profil penulis tidak tersedia.</p></div>
  <?php else: ?>
    <div class="card">
      <div class="profile">
        <img class="avatar" src="<?php echo htmlspecialchars($author['avatar'], ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar <?php echo htmlspecialchars($author['pen_name'], ENT_QUOTES, 'UTF-8'); ?>">
        <div>
          <h1><?php echo htmlspecialchars($author['pen_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
          <?php if (($author['bio'] ?? '') !== ''): ?><p class="bio"><?php echo nl2br(htmlspecialchars($author['bio'], ENT_QUOTES, 'UTF-8')); ?></p><?php endif; ?>
          <?php if (!empty($author['socials'])): ?>
            <div class="social" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
              <?php foreach ($author['socials'] as $s): $pf = social_platform($s); ?>
                <a href="<?php echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener nofollow" title="<?php echo htmlspecialchars($pf, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($pf, ENT_QUOTES, 'UTF-8'); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;border:1px solid var(--stroke);color:var(--text);text-decoration:none;">
                  <?php echo social_icon_svg($pf); ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <h2 style="font-size:16px;margin:24px 0 10px;color:var(--muted);">Tulisan (<?php echo count($posts); ?>)</h2>
    <div class="posts">
      <?php if (empty($posts)): ?>
        <p class="empty">Belum ada tulisan.</p>
      <?php else: foreach ($posts as $p): ?>
        <a class="pcard" href="/blog/<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>">
          <h3><?php echo htmlspecialchars((string) ($p['title'] ?? 'Tanpa judul'), ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="m"><?php echo htmlspecialchars((string) (($p['created_at'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8'); ?></div>
        </a>
      <?php endforeach; endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
