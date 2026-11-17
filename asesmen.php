<?php
require_once __DIR__ . '/assessments_helper.php';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function norm_eq($a, $b)
{
    $a = trim((string) $a);
    $b = trim((string) $b);
    if ($a === '' && $b === '') return true;
    if (is_numeric($a) && is_numeric($b)) return abs((float) $a - (float) $b) <= 0.01;
    return strcasecmp($a, $b) === 0;
}

$id = isset($_REQUEST['a_id']) ? trim((string) $_REQUEST['a_id']) : (isset($_GET['id']) ? trim((string) $_GET['id']) : '');
$slug = isset($_GET['slug']) ? trim((string) $_GET['slug']) : '';
$result = null;
$needReset = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $id !== '' ? assessment_find($id) : null;
    if ($a) {
        $action = $_POST['action'] ?? '';
        $name = trim((string) ($_POST['r_name'] ?? ''));
        $ident = trim((string) ($_POST['r_ident'] ?? ''));
        $identity = strtolower($name) . '|' . strtolower($ident);
        $attempts = 0;
        foreach (($a['responses'] ?? []) as $r) {
            if ((strtolower(trim((string) ($r['name'] ?? ''))) . '|' . strtolower(trim((string) ($r['ident'] ?? '')))) === $identity) $attempts++;
        }

        if ($action === 'reset') {
            assessments_update($id, function ($x) use ($identity) {
                $keep = [];
                foreach (($x['responses'] ?? []) as $r) {
                    $rid = strtolower(trim((string) ($r['name'] ?? ''))) . '|' . strtolower(trim((string) ($r['ident'] ?? '')));
                    if ($rid !== $identity) $keep[] = $r;
                }
                $x['responses'] = $keep;
                return $x;
            });
            header('Location: /asesmen?id=' . urlencode($id));
            exit;
        }

        $cells = is_array($a['key_cells'] ?? null) ? $a['key_cells'] : [];

        if (!empty($cells)) {
            if ($attempts >= 2) {
                $needReset = true;
            } elseif (isset($_POST['answers_json'])) {
                $ans = json_decode((string) $_POST['answers_json'], true);
                if (!is_array($ans)) $ans = [];
                $mode = $attempts === 0 ? 'pretest' : 'posttest';
                $total = count($cells);
                $score = 0;
                $stored = [];
                foreach ($cells as $c) {
                    $addr = (string) ($c['addr'] ?? '');
                    if ($addr === '') continue;
                    $val = isset($ans[$addr]) ? trim((string) $ans[$addr]) : '';
                    $stored[$addr] = $val;
                    if (norm_eq($val, $c['key'] ?? '')) $score++;
                }
                $auto = (($a['grading'] ?? 'auto') === 'auto');
                $resp = [
                    'id' => bin2hex(random_bytes(6)),
                    'name' => $name,
                    'ident' => $ident,
                    'mode' => $mode,
                    'answers' => $stored,
                    'score' => $auto ? $score : null,
                    'auto_score' => $score,
                    'audited' => false,
                    'audit_score' => null,
                    'submitted_at' => date('c'),
                ];
                assessments_update($id, function ($x) use ($resp) {
                    if (!isset($x['responses']) || !is_array($x['responses'])) $x['responses'] = [];
                    $x['responses'][] = $resp;
                    return $x;
                });
                $a = assessment_find($id);
                $result = ['mode' => $mode, 'auto' => $auto, 'score' => $score, 'total' => $total];
            } else {
                $error = 'File jawaban tidak terkirim. Coba unggah ulang.';
            }
        } else {
            // legacy: MCQ / fill-in
            $qs = is_array($a['questions'] ?? null) ? $a['questions'] : [];
            $acells = is_array($a['answer_cells'] ?? null) ? $a['answer_cells'] : [];
            $answers = [];
            $score = 0;
            if (!empty($acells)) {
                $total = count($acells);
                foreach ($acells as $i => $cell) {
                    $v = trim((string) ($_POST['f' . $i] ?? ''));
                    $answers[$i] = $v;
                    if (strcasecmp($v, trim((string) ($cell['key'] ?? ''))) === 0) $score++;
                }
            } else {
                $total = count($qs);
                foreach ($qs as $i => $q) {
                    $answers[$i] = isset($_POST['q' . $i]) ? (int) $_POST['q' . $i] : -1;
                }
                foreach ($qs as $i => $q) {
                    if (($answers[$i] ?? -1) === (int) ($q['correct'] ?? -2)) $score++;
                }
            }
            $auto = (($a['grading'] ?? 'auto') === 'auto');
            $resp = [
                'id' => bin2hex(random_bytes(6)),
                'name' => $name,
                'ident' => $ident,
                'answers' => $answers,
                'score' => $auto ? $score : null,
                'auto_score' => $score,
                'audited' => false,
                'audit_score' => null,
                'submitted_at' => date('c'),
            ];
            assessments_update($id, function ($x) use ($resp) {
                if (!isset($x['responses']) || !is_array($x['responses'])) $x['responses'] = [];
                $x['responses'][] = $resp;
                return $x;
            });
            $result = ['mode' => 'pretest', 'auto' => $auto, 'score' => $score, 'total' => $total];
        }
    }
}

$assessment = $id !== '' ? assessment_find($id) : null;
$listForSlug = (!$assessment && $slug !== '') ? assessments_for_post($slug) : [];
$isFileMode = $assessment && !empty($assessment['key_cells']);
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($assessment['title'] ?? 'Asesmen'); ?> – UKMI Polmed</title>
<meta name="robots" content="noindex, follow">
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
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
input[type=file]{margin-top:6px;color:var(--text)}
button{background:linear-gradient(135deg,#38bdf8,#0ea5e9);color:#04202e;border:0;border-radius:9px;padding:9px 16px;font-weight:600;cursor:pointer;margin-top:14px}
.btn{display:inline-block;text-decoration:none;color:var(--text);border:1px solid var(--stroke);border-radius:9px;padding:8px 12px;font-size:14px;margin-top:8px}
.btn.danger{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.4)}
.q{margin:14px 0;padding:12px;border:1px solid var(--stroke);border-radius:10px}
.ok{color:var(--ok)} .err{color:#f87171}
</style>
</head>
<body>
<div class="wrap">
  <div><a class="muted" href="/blog.php">← Blog</a></div>

  <?php if ($needReset): ?>
    <div class="card">
      <h1>Sudah menyelesaikan posttest</h1>
      <p class="muted">Kamu sudah mengerjakan pretest &amp; posttest. Ingin mengulang dari awal (reset progres) atau batal?</p>
      <form method="post" style="display:inline;">
        <input type="hidden" name="a_id" value="<?php echo h($id); ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="r_name" value="<?php echo h($_POST['r_name'] ?? ''); ?>">
        <input type="hidden" name="r_ident" value="<?php echo h($_POST['r_ident'] ?? ''); ?>">
        <button type="submit" class="btn danger">Reset (mulai pretest lagi)</button>
      </form>
      <a class="btn" href="/asesmen?id=<?php echo h($id); ?>">Batal</a>
    </div>
  <?php elseif ($result !== null): ?>
    <div class="card">
      <h1>Terima kasih!</h1>
      <p class="muted">Mode: <strong><?php echo h($result['mode']); ?></strong></p>
      <?php if ($result['auto']): ?>
        <p class="ok">Skor: <strong><?php echo (int) $result['score']; ?>/<?php echo (int) $result['total']; ?></strong></p>
      <?php else: ?>
        <p class="muted">Terkirim. Skor menunggu audit penulis.</p>
      <?php endif; ?>
      <a class="btn" href="/asesmen?id=<?php echo h($id); ?>">Selesai</a>
    </div>
  <?php elseif ($assessment === null && $listForSlug): ?>
    <div class="card">
      <h1>Asesmen tersedia</h1>
      <?php foreach ($listForSlug as $as): ?>
        <a class="btn" href="/asesmen?id=<?php echo h($as['id']); ?>"><?php echo h($as['title']); ?> (<?php echo h($as['type'] ?? ''); ?>)</a>
      <?php endforeach; ?>
    </div>
  <?php elseif ($assessment === null): ?>
    <div class="card"><h1>Asesmen tidak ditemukan</h1></div>
  <?php else: ?>
    <div class="card">
      <h1><?php echo h($assessment['title']); ?></h1>
      <div class="muted">Tipe: <?php echo h($assessment['type'] ?? '-'); ?> · Penilaian: <?php echo (($assessment['grading'] ?? 'auto') === 'auto') ? 'otomatis' : 'audit manual'; ?></div>
    </div>

    <?php if ($isFileMode): ?>
      <?php $fileKey = (string) ($assessment['file_key'] ?? ''); ?>
      <div class="card">
        <p class="muted">Unduh file tugas (bersih), kerjakan di Excel, lalu unggah kembali di bawah.</p>
        <?php if ($fileKey !== ''): ?>
          <a class="btn" href="/unduh.php?key=<?php echo urlencode($fileKey); ?>">⬇ Download file Excel</a>
        <?php endif; ?>
      </div>
      <form method="post" class="card" id="file-form">
        <input type="hidden" name="a_id" value="<?php echo h($assessment['id']); ?>">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="answers_json" id="answers_json" value="">
        <label for="r_name">Nama</label>
        <input type="text" id="r_name" name="r_name" required placeholder="Nama Anda">
        <label for="r_ident">Identitas (NIM/kelas/email)</label>
        <input type="text" id="r_ident" name="r_ident" required placeholder="Identitas unik">
        <label for="r_file">Upload file Excel jawaban (.xlsx)</label>
        <input type="file" id="r_file" accept=".xlsx" required>
        <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
        <div><button type="submit">Kirim jawaban</button></div>
      </form>
      <script>
      (function () {
        var cells = <?php echo json_encode(array_map(function ($c) { return ['s' => (string) ($c['s'] ?? ''), 'addr' => (string) ($c['addr'] ?? '')]; }, $assessment['key_cells'])); ?>;
        var form = document.getElementById('file-form');
        if (!form) return;
        form.addEventListener('submit', async function (e) {
          var inp = document.getElementById('r_file');
          var f = inp && inp.files && inp.files[0];
          if (!f) { e.preventDefault(); alert('Pilih file Excel jawaban.'); return; }
          if (typeof ExcelJS === 'undefined') { e.preventDefault(); alert('Library ExcelJS belum termuat.'); return; }
          e.preventDefault();
          try {
            var wb = new ExcelJS.Workbook();
            await wb.xlsx.load(await f.arrayBuffer());
            var out = {};
            cells.forEach(function (kc) {
              var ws = wb.getWorksheet(kc.s) || wb.worksheets[0];
              var c = ws ? ws.getCell(kc.addr) : null;
              var v = c ? c.value : null;
              if (v != null && typeof v === 'object') { v = (v.result != null ? v.result : (v.formula ? '=' + v.formula : (v.richText ? v.richText.map(function (t) { return t.text; }).join('') : ''))); }
              out[kc.addr] = (v == null ? '' : String(v)).trim();
            });
            document.getElementById('answers_json').value = JSON.stringify(out);
            form.submit();
          } catch (err) { alert('Gagal membaca file: ' + err); }
        });
      })();
      </script>
    <?php else: ?>
      <form method="post" class="card">
        <input type="hidden" name="a_id" value="<?php echo h($assessment['id']); ?>">
        <input type="hidden" name="r_name" value="<?php echo h($_POST['r_name'] ?? ''); ?>">
        <label for="r_name">Nama</label>
        <input type="text" id="r_name" name="r_name" required>
        <label for="r_ident">Identitas (opsional)</label>
        <input type="text" id="r_ident" name="r_ident">
        <?php foreach (($assessment['questions'] ?? []) as $i => $q): ?>
          <div class="q">
            <p><?php echo (int) $i + 1; ?>. <?php echo h($q['q'] ?? ''); ?></p>
            <?php foreach (($q['options'] ?? []) as $oi => $opt): ?>
              <label><input type="radio" name="q<?php echo (int) $i; ?>" value="<?php echo (int) $oi; ?>"> <?php echo h($opt); ?></label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <button type="submit">Kirim jawaban</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
