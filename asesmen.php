<?php
require_once __DIR__ . '/assessments_helper.php';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$msg = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['a_id'])) {
    $aId = trim((string) $_POST['a_id']);
    $a = assessment_find($aId);
    if ($a) {
        $answers = [];
        $qs = is_array($a['questions'] ?? null) ? $a['questions'] : [];
        foreach ($qs as $i => $q) {
            $answers[$i] = isset($_POST['q' . $i]) ? (int) $_POST['q' . $i] : -1;
        }
        $score = 0;
        $total = count($qs);
        foreach ($qs as $i => $q) {
            if (($answers[$i] ?? -1) === (int) ($q['correct'] ?? -2)) $score++;
        }
        $auto = (($a['grading'] ?? 'auto') === 'auto');
        $resp = [
            'id' => bin2hex(random_bytes(6)),
            'name' => trim((string) ($_POST['r_name'] ?? '')),
            'ident' => trim((string) ($_POST['r_ident'] ?? '')),
            'answers' => $answers,
            'score' => $auto ? $score : null,
            'auto_score' => $score,
            'audited' => false,
            'audit_score' => null,
            'submitted_at' => date('c'),
        ];
        assessments_update($aId, function ($as) use ($resp) {
            if (!isset($as['responses']) || !is_array($as['responses'])) $as['responses'] = [];
            $as['responses'][] = $resp;
            return $as;
        });
        $result = ['auto' => $auto, 'score' => $score, 'total' => $total];
    }
}

$assessment = $id !== '' ? assessment_find($id) : null;
$listForSlug = (!$assessment && $slug !== '') ? assessments_for_post($slug) : [];

$host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id'));
$base = ((PHP_SAPI === 'cli-server') ? 'http://' : 'https://') . ($_SERVER['HTTP_HOST'] ?? 'ukmipolmed.my.id');
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($assessment['title'] ?? 'Asesmen'); ?> – UKMI Polmed</title>
<meta name="robots" content="noindex, follow">
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<style>
:root{--bg:#0b1120;--card:#111a2e;--text:#e5e7eb;--muted:#94a3b8;--stroke:rgba(148,163,184,.2);--accent:#38bdf8;--ok:#4ade80}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 600px at 20% -10%,#13203a,var(--bg));color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.6}
.wrap{max-width:760px;margin:0 auto;padding:24px 16px 60px}
h1{font-size:20px;margin:0 0 6px}
.muted{color:var(--muted);font-size:14px}
.card{background:var(--card);border:1px solid var(--stroke);border-radius:14px;padding:16px;margin-top:14px}
label{display:block;font-size:13px;color:var(--muted);margin:8px 0 4px}
input[type=text]{width:100%;padding:9px 11px;border-radius:9px;border:1px solid var(--stroke);background:rgba(15,23,42,.5);color:var(--text)}
button{background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#04202e;border:0;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer;margin-top:14px}
.btn{display:inline-block;text-decoration:none;color:var(--text);border:1px solid var(--stroke);border-radius:9px;padding:8px 12px;font-size:14px;margin-top:8px}
.q{margin:14px 0;padding:12px;border:1px solid var(--stroke);border-radius:10px}
.q p{margin:0 0 8px;font-weight:600}
.opt{display:block;margin:4px 0;color:var(--text);font-size:14px}
.ok{color:var(--ok)}
</style>
</head>
<body>
<div class="wrap">
  <div><a class="muted" href="/blog.php">← Blog</a></div>

  <?php if ($result !== null): ?>
    <div class="card">
      <h1>Terima kasih!</h1>
      <?php if ($result['auto']): ?>
        <p class="ok">Skor Anda: <strong><?php echo (int) $result['score']; ?>/<?php echo (int) $result['total']; ?></strong></p>
      <?php else: ?>
        <p class="muted">Jawaban terkirim. Skor akan ditetapkan setelah diaudit oleh penulis.</p>
      <?php endif; ?>
      <a class="btn" href="<?php echo $assessment ? ('/asesmen?id=' . h($assessment['id'])) : '/blog.php'; ?>">Ulangi</a>
    </div>
  <?php elseif ($assessment === null && $listForSlug): ?>
    <div class="card">
      <h1>Asesmen tersedia</h1>
      <?php foreach ($listForSlug as $as): ?>
        <a class="btn" href="/asesmen?id=<?php echo h($as['id']); ?>"><?php echo h($as['title']); ?> (<?php echo h($as['type'] ?? ''); ?>)</a>
      <?php endforeach; ?>
    </div>
  <?php elseif ($assessment === null): ?>
    <div class="card"><h1>Asesmen tidak ditemukan</h1><p class="muted">Tautan mungkin salah.</p></div>
  <?php else: ?>
    <div class="card">
      <h1><?php echo h($assessment['title']); ?></h1>
      <div class="muted">Tipe: <?php echo h($assessment['type'] ?? '-'); ?> · Penilaian: <?php echo (($assessment['grading'] ?? 'auto') === 'auto') ? 'otomatis' : 'audit manual'; ?></div>
    </div>
    <?php if (!empty($assessment['task_image'])): ?>
      <div class="card"><img src="<?php echo h($assessment['task_image']); ?>" alt="Gambar soal" style="max-width:100%;border-radius:10px;border:1px solid var(--stroke);"></div>
    <?php endif; ?>
    <form method="post" class="card">
      <input type="hidden" name="a_id" value="<?php echo h($assessment['id']); ?>">
      <label for="r_name">Nama</label>
      <input type="text" id="r_name" name="r_name" required placeholder="Nama Anda">
      <label for="r_ident">Identitas (kelas/NIM/email, opsional)</label>
      <input type="text" id="r_ident" name="r_ident" placeholder="Opsional">
      <?php foreach (($assessment['questions'] ?? []) as $i => $q): ?>
        <div class="q">
          <p><?php echo (int) $i + 1; ?>. <?php echo h($q['q'] ?? ''); ?></p>
          <?php foreach (($q['options'] ?? []) as $oi => $opt): ?>
            <label class="opt"><input type="radio" name="q<?php echo (int) $i; ?>" value="<?php echo (int) $oi; ?>"> <?php echo h($opt); ?></label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <button type="submit">Kirim jawaban</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
