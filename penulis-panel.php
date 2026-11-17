<?php
session_start();
require_once __DIR__ . '/authors_helper.php';
require_once __DIR__ . '/assessments_helper.php';
require_once __DIR__ . '/s3_helper.php';

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
    header('Location: /admin.php');
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
    header('Location: /admin.php');
    exit;
}
$me = $data['authors'][$meIdx];
$username = (string) ($me['username'] ?? '');

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin.php');
    exit;
}

function wp_slugify($s)
{
    $s = strtolower(trim((string) $s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s !== '' ? $s : ('post-' . substr(bin2hex(random_bytes(3)), 0, 6));
}

function wp_load_config($path)
{
    if (!is_readable($path)) return [];
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function wp_save_config($path, $cfg)
{
    $enc = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false) return false;
    return @file_put_contents($path, $enc) !== false;
}

$msg = '';
$err = '';

// Profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $data['authors'][$meIdx]['pen_name'] = trim((string) ($_POST['pen_name'] ?? ''));
    $data['authors'][$meIdx]['bio'] = trim((string) ($_POST['bio'] ?? ''));
    $av = trim((string) ($_POST['avatar'] ?? ''));
    if (stripos($av, 'data:') === 0 && function_exists('s3_put_data_uri')) { $u = s3_put_data_uri($av); if ($u) $av = $u; }
    $data['authors'][$meIdx]['avatar'] = $av;
    $rawSoc = (string) ($_POST['socials'] ?? '');
    $socArr = [];
    foreach (preg_split('/\r?\n/', $rawSoc) as $line) {
        $line = trim($line);
        if ($line !== '') $socArr[] = $line;
    }
    $data['authors'][$meIdx]['socials'] = $socArr;
    $data['authors'][$meIdx]['social'] = $socArr[0] ?? '';
    if (authors_save($data)) {
        $me = $data['authors'][$meIdx];
        $msg = 'Profil disimpan.';
    } else {
        $err = 'Gagal menyimpan profil.';
    }
}

// Simpan postingan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_post'])) {
    $cfg = wp_load_config($configPath);
    if (!isset($cfg['posts']) || !is_array($cfg['posts'])) $cfg['posts'] = [];
    $editSlug = trim((string) ($_POST['orig_slug'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $slugIn = trim((string) ($_POST['slug'] ?? ''));
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    $image = trim((string) ($_POST['image'] ?? ''));
    if (stripos($image, 'data:') === 0 && function_exists('s3_put_data_uri')) { $u = s3_put_data_uri($image); if ($u) $image = $u; }
    if ($title === '') {
        $err = 'Judul wajib diisi.';
    } else {
        $slug = wp_slugify($slugIn !== '' ? $slugIn : $title);
        // keunikan slug
        $taken = [];
        foreach ($cfg['posts'] as $p) {
            $ps = (string) ($p['slug'] ?? '');
            if ($ps !== '' && !($editSlug !== '' && $ps === $editSlug)) $taken[$ps] = true;
        }
        if (isset($taken[$slug])) {
            $base = $slug; $n = 2;
            while (isset($taken[$slug])) { $slug = $base . '-' . $n; $n++; }
        }
        $now = date('c');
        $found = false;
        foreach ($cfg['posts'] as $i => $p) {
            if ($editSlug !== '' && (string) ($p['slug'] ?? '') === $editSlug && (string) ($p['author_id'] ?? '') === $aid) {
                $cfg['posts'][$i]['title'] = $title;
                $cfg['posts'][$i]['slug'] = $slug;
                $cfg['posts'][$i]['summary'] = $summary;
                $cfg['posts'][$i]['body'] = $body;
                $cfg['posts'][$i]['image'] = $image;
                $cfg['posts'][$i]['author_id'] = $aid;
                $cfg['posts'][$i]['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $cfg['posts'][] = [
                'title' => $title,
                'slug' => $slug,
                'summary' => $summary,
                'body' => $body,
                'image' => $image,
                'author_id' => $aid,
                'embeds' => [],
                'embed_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
                'comments' => [],
            ];
        }
        if (wp_save_config($configPath, $cfg)) {
            header('Location: /penulis-panel.php?saved=1');
            exit;
        }
        $err = 'Gagal menyimpan postingan.';
    }
}

// Hapus postingan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_post'])) {
    $delSlug = trim((string) $_POST['delete_post']);
    $cfg = wp_load_config($configPath);
    $before = count($cfg['posts'] ?? []);
    $cfg['posts'] = array_values(array_filter($cfg['posts'] ?? [], function ($p) use ($delSlug, $aid) {
        return !((string) ($p['slug'] ?? '') === $delSlug && (string) ($p['author_id'] ?? '') === $aid);
    }));
    if (count($cfg['posts']) < $before && wp_save_config($configPath, $cfg)) {
        header('Location: /penulis-panel.php?deleted=1');
        exit;
    }
    $err = 'Gagal menghapus postingan.';
}

// Buat asesmen (pretest/posttest)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assessment'])) {
    $aTitle = trim((string) ($_POST['a_title'] ?? ''));
    $aType = (($_POST['a_type'] ?? 'pretest') === 'posttest') ? 'posttest' : 'pretest';
    $aGrading = (($_POST['a_grading'] ?? 'auto') === 'manual') ? 'manual' : 'auto';
    $aPost = trim((string) ($_POST['a_post'] ?? ''));
    $rawQ = (string) ($_POST['a_questions'] ?? '');
    $questions = [];
    foreach (preg_split('/\r?\n/', $rawQ) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) continue;
        $correct = (int) array_pop($parts);
        $q = array_shift($parts);
        $options = array_values(array_filter($parts, fn($x) => $x !== ''));
        $ci = $correct - 1;
        if ($q === '' || count($options) < 2 || $ci < 0 || $ci >= count($options)) continue;
        $questions[] = ['q' => $q, 'options' => $options, 'correct' => $ci];
    }
    $ownsPost = false;
    foreach ($myPosts as $pp) { if ((string) ($pp['slug'] ?? '') === $aPost) { $ownsPost = true; break; } }
    if ($aTitle === '' || empty($questions)) {
        $err = 'Judul & minimal 1 soal valid wajib diisi.';
    } elseif ($aPost !== '' && !$ownsPost) {
        $err = 'Postingan tidak valid / bukan milik Anda.';
    } else {
        $d = assessments_load();
        $d['assessments'][] = [
            'id' => bin2hex(random_bytes(6)),
            'post_slug' => $aPost,
            'author_id' => $aid,
            'title' => $aTitle,
            'type' => $aType,
            'grading' => $aGrading,
            'questions' => $questions,
            'responses' => [],
            'created_at' => date('c'),
        ];
        if (assessments_save($d)) $msg = 'Asesmen dibuat.'; else $err = 'Gagal menyimpan asesmen.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assessment'])) {
    $delAid = trim((string) $_POST['delete_assessment']);
    $as = assessment_find($delAid);
    if ($as && (string) ($as['author_id'] ?? '') === $aid) {
        $d = assessments_load();
        $d['assessments'] = array_values(array_filter($d['assessments'], fn($x) => (string) ($x['id'] ?? '') !== $delAid));
        if (assessments_save($d)) $msg = 'Asesmen dihapus.'; else $err = 'Gagal menghapus asesmen.';
    } else {
        $err = 'Asesmen tidak ditemukan / bukan milik Anda.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['audit_aid'], $_POST['audit_rid'])) {
    $auAid = trim((string) $_POST['audit_aid']);
    $auRid = trim((string) $_POST['audit_rid']);
    $as = assessment_find($auAid);
    if ($as && (string) ($as['author_id'] ?? '') === $aid) {
        $total = count($as['questions'] ?? []);
        $auScore = max(0, min($total, (int) ($_POST['audit_score'] ?? 0)));
        assessments_update($auAid, function ($x) use ($auRid, $auScore) {
            $rs = $x['responses'] ?? [];
            foreach ($rs as $k => $r) {
                if ((string) ($r['id'] ?? '') === $auRid) { $rs[$k]['audited'] = true; $rs[$k]['audit_score'] = $auScore; }
            }
            $x['responses'] = $rs;
            return $x;
        });
        $msg = 'Skor audit disimpan.';
    } else {
        $err = 'Tidak berhak mengaudit asesmen ini.';
    }
}

if (isset($_GET['saved'])) $msg = 'Postingan disimpan.';
if (isset($_GET['deleted'])) $msg = 'Postingan dihapus.';

$config = wp_load_config($configPath);
$all = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];
$myPosts = [];
foreach ($all as $p) {
    if (trim((string) ($p['author_id'] ?? '')) === $aid) $myPosts[] = $p;
}
usort($myPosts, fn($x, $y) => strcmp((string) ($y['created_at'] ?? ''), (string) ($x['created_at'] ?? '')));

$editing = null;
$editSlug = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($editSlug !== '') {
    foreach ($myPosts as $p) {
        if ((string) ($p['slug'] ?? '') === $editSlug) { $editing = $p; break; }
    }
}
$showForm = ($editing !== null) || isset($_GET['new']);
$myAssessments = assessments_by_author($aid);
$respAid = isset($_GET['responses']) ? trim((string) $_GET['responses']) : '';
$respAs = $respAid !== '' ? assessment_find($respAid) : null;
if ($respAs && (string) ($respAs['author_id'] ?? '') !== $aid) $respAs = null;
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
.wrap{max-width:820px;margin:0 auto;padding:24px 16px 60px}
h1{font-size:20px;margin:0 0 4px}
.muted{color:var(--muted);font-size:13px}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:16px;margin-top:14px}
label{display:block;font-size:13px;color:var(--muted);margin:8px 0 4px}
input[type=text],textarea{width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text)}
button{background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#04202e;border:0;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer}
.btn{display:inline-block;text-decoration:none;color:var(--text);border:1px solid var(--stroke);border-radius:9px;padding:8px 12px;font-size:14px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
.ok{color:#4ade80}.err{color:#f87171}
.pcard{display:block;text-decoration:none;color:inherit;border:1px solid var(--stroke);border-radius:12px;padding:12px 14px;margin-top:10px}
.pcard h3{margin:0 0 3px;font-size:15px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.danger{background:rgba(255,255,255,0.06);color:var(--text);border:1px solid rgba(148,163,184,0.25);box-shadow:none}
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

  <?php if ($showForm): ?>
    <div class="card">
      <h2 style="font-size:15px;margin:0 0 6px;"><?php echo $editing ? 'Edit postingan' : 'Postingan baru'; ?></h2>
      <form method="post">
        <input type="hidden" name="save_post" value="1">
        <input type="hidden" name="orig_slug" value="<?php echo htmlspecialchars((string) ($editing['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="title">Judul</label>
        <input type="text" id="title" name="title" required value="<?php echo htmlspecialchars((string) ($editing['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <label for="slug">Slug (opsional)</label>
        <input type="text" id="slug" name="slug" value="<?php echo htmlspecialchars((string) ($editing['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="otomatis dari judul">
        <label for="summary">Ringkas</label>
        <textarea id="summary" name="summary" rows="2"><?php echo htmlspecialchars((string) ($editing['summary'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <label for="body">Konten (Markdown: #, **tebal**, *miring*, [tautan](url))</label>
        <textarea id="body" name="body" rows="10"><?php echo htmlspecialchars((string) ($editing['body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <label for="image">Gambar sampul (URL https://... atau data:image/...;base64,...)</label>
        <input type="text" id="image" name="image" value="<?php echo htmlspecialchars((string) ($editing['image'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg">
        <div class="row">
          <button type="submit">Simpan</button>
          <a class="btn danger" href="penulis-panel.php">Batal</a>
        </div>
      </form>
    </div>
  <?php else: ?>
    <?php if ($respAs): ?>
      <div class="card">
        <div class="row" style="justify-content:space-between;margin-top:0;">
          <h2 style="font-size:15px;margin:0;">Responden – <?php echo htmlspecialchars((string) $respAs['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <a class="btn" href="penulis-panel.php">Kembali</a>
        </div>
        <?php $rs = $respAs['responses'] ?? []; $qTotal = count($respAs['questions'] ?? []); ?>
        <?php if (empty($rs)): ?>
          <p class="muted">Belum ada responden.</p>
        <?php else: foreach ($rs as $r): ?>
          <div class="pcard">
            <h3><?php echo htmlspecialchars((string) (($r['name'] ?? '') !== '' ? $r['name'] : '(tanpa nama)'), ENT_QUOTES, 'UTF-8'); ?> <span class="muted"><?php echo htmlspecialchars((string) ($r['ident'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span></h3>
            <?php $sc = !empty($r['audited']) ? $r['audit_score'] : ($r['score'] ?? null); ?>
            <div class="muted">Skor: <?php echo $sc === null ? 'menunggu audit' : ((int) $sc . '/' . $qTotal); ?> · <?php echo htmlspecialchars((string) ($r['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if (($respAs['grading'] ?? 'auto') === 'manual'): ?>
              <form method="post" class="row" style="margin-top:8px;">
                <input type="hidden" name="audit_aid" value="<?php echo htmlspecialchars((string) $respAs['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="audit_rid" value="<?php echo htmlspecialchars((string) ($r['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="text" name="audit_score" value="<?php echo $sc === null ? '' : (int) $sc; ?>" placeholder="skor 0-<?php echo (int) $qTotal; ?>" style="max-width:140px;">
                <button type="submit" class="danger">Simpan skor</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php endif; ?>
    <div class="card">
      <div class="row" style="justify-content:space-between;margin-top:0;">
        <h2 style="font-size:15px;margin:0;">Tulisan saya (<?php echo count($myPosts); ?>)</h2>
        <a class="btn" href="?new=1">+ Tulis baru</a>
      </div>
      <?php if (empty($myPosts)): ?>
        <p class="muted">Belum ada tulisan. Klik "Tulis baru".</p>
      <?php else: foreach ($myPosts as $p): ?>
        <div class="pcard">
          <h3><?php echo htmlspecialchars((string) ($p['title'] ?? 'Tanpa judul'), ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="muted"><?php echo htmlspecialchars((string) (($p['created_at'] ?? '') ?: '-'), ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($p['archived']) ? ' · (arsip)' : ''; ?></div>
          <div class="row" style="margin-top:8px;">
            <a class="btn" href="/blog/<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>" target="_blank" rel="noopener">Lihat</a>
            <a class="btn" href="?edit=<?php echo rawurlencode((string) ($p['slug'] ?? '')); ?>">Edit</a>
            <form method="post" style="margin:0;" onsubmit="return confirm('Hapus postingan ini?');">
              <input type="hidden" name="delete_post" value="<?php echo htmlspecialchars((string) ($p['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="danger">Hapus</button>
            </form>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card">
      <h2 style="font-size:15px;margin:0 0 6px;">Pretest/Posttest (<?php echo count($myAssessments); ?>)</h2>
      <?php if (empty($myAssessments)): ?>
        <p class="muted">Belum ada asesmen.</p>
      <?php else: foreach ($myAssessments as $as): ?>
        <div class="pcard">
          <h3><?php echo htmlspecialchars((string) $as['title'], ENT_QUOTES, 'UTF-8'); ?> <span class="muted">(<?php echo htmlspecialchars((string) ($as['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</span></h3>
          <div class="muted">Post: <?php echo htmlspecialchars((string) (($as['post_slug'] ?? '') !== '' ? $as['post_slug'] : '-'), ENT_QUOTES, 'UTF-8'); ?> · <?php echo count($as['questions'] ?? []); ?> soal · <?php echo count($as['responses'] ?? []); ?> responden</div>
          <div class="row" style="margin-top:8px;">
            <a class="btn" href="?responses=<?php echo rawurlencode((string) $as['id']); ?>">Lihat responden</a>
            <a class="btn" href="/asesmen?id=<?php echo rawurlencode((string) $as['id']); ?>" target="_blank" rel="noopener">Buka</a>
            <form method="post" style="margin:0;" onsubmit="return confirm('Hapus asesmen ini?');">
              <input type="hidden" name="delete_assessment" value="<?php echo htmlspecialchars((string) $as['id'], ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="danger">Hapus</button>
            </form>
          </div>
        </div>
      <?php endforeach; endif; ?>

      <h3 style="font-size:14px;margin:18px 0 6px;color:var(--muted);">Buat asesmen baru</h3>
      <form method="post">
        <input type="hidden" name="create_assessment" value="1">
        <div class="grid2">
          <div><label for="a_title">Judul</label><input type="text" id="a_title" name="a_title" required placeholder="Pretest Materi X"></div>
          <div><label for="a_type">Tipe</label>
            <select id="a_type" name="a_type" style="width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);">
              <option value="pretest">Pretest</option><option value="posttest">Posttest</option>
            </select>
          </div>
          <div><label for="a_grading">Penilaian</label>
            <select id="a_grading" name="a_grading" style="width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);">
              <option value="auto">Otomatis</option><option value="manual">Audit manual</option>
            </select>
          </div>
          <div><label for="a_post">Postingan terkait</label>
            <select id="a_post" name="a_post" style="width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text);">
              <option value="">(tanpa postingan)</option>
              <?php foreach ($myPosts as $pp): ?>
                <option value="<?php echo htmlspecialchars((string) ($pp['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($pp['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <label for="a_questions">Daftar soal — satu soal per baris, format: <code>Pertanyaan | OpsiA | OpsiB | OpsiC | OpsiD | jawaban(1-4)</code></label>
        <textarea id="a_questions" name="a_questions" rows="6" placeholder="Ibu kota Indonesia? | Jakarta | Bandung | Surabaya | Medan | 1"></textarea>
        <div class="row"><button type="submit">Buat asesmen</button></div>
      </form>
    </div>

    <div class="card">
      <h2 style="font-size:15px;margin:0 0 6px;">Profil publik</h2>
      <form method="post">
        <input type="hidden" name="save_profile" value="1">
        <div class="grid2">
          <div><label for="pen_name">Nama pena</label><input type="text" id="pen_name" name="pen_name" value="<?php echo htmlspecialchars((string) ($me['pen_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
          <div><label for="avatar">Avatar URL</label><input type="text" id="avatar" name="avatar" value="<?php echo htmlspecialchars((string) ($me['avatar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://...jpg"></div>
        </div>
        <label for="bio">Bio</label>
        <textarea id="bio" name="bio" rows="2"><?php echo htmlspecialchars((string) ($me['bio'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <?php $meSocials = (!empty($me['socials']) && is_array($me['socials'])) ? $me['socials'] : (!empty($me['social']) ? [$me['social']] : []); ?>
        <label for="socials">Social media link (satu per baris)</label>
        <textarea id="socials" name="socials" rows="3" placeholder="https://instagram.com/akun&#10;https://youtube.com/@akun"><?php echo htmlspecialchars(implode("\n", $meSocials), ENT_QUOTES, 'UTF-8'); ?></textarea>
        <div class="row"><button type="submit">Simpan profil</button></div>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
