<?php
require_once __DIR__ . '/assessments_helper.php';
require_once __DIR__ . '/security_helper.php';
ukmi_session_start();

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
$submitToken = trim((string) ($_POST['submission_token'] ?? ''));
$formToken = bin2hex(random_bytes(8));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ukmi_csrf_valid()) {
        $error = 'Sesi kedaluwarsa atau token tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif (ukmi_rate_limit('asesmen', 20, 600)) {
        $error = 'Terlalu banyak pengiriman dalam waktu singkat. Coba lagi beberapa menit lagi.';
    } else {
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
            $isAdminOrWriter = !empty($_SESSION['admin_logged_in']) && in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'writer'], true);
            $isOwned = !empty($_SESSION['asesmen_owned'][$id][$identity]);
            if (!$isAdminOrWriter && !$isOwned) {
                $error = 'Reset hanya dapat dilakukan dari sesi yang sama atau oleh admin/penulis.';
            } else {
                assessments_update($id, function ($x) use ($identity) {
                    $keep = [];
                    foreach (($x['responses'] ?? []) as $r) {
                        $rid = strtolower(trim((string) ($r['name'] ?? ''))) . '|' . strtolower(trim((string) ($r['ident'] ?? '')));
                        if ($rid !== $identity) $keep[] = $r;
                    }
                    $x['responses'] = $keep;
                    return $x;
                });
                unset($_SESSION['asesmen_owned'][$id][$identity]);
                header('Location: /asesmen?id=' . urlencode($id));
                exit;
            }
        }

        $cells = is_array($a['key_cells'] ?? null) ? $a['key_cells'] : [];

        if (!empty($cells)) {
            if ($attempts >= 2) {
                $needReset = true;
            } elseif (isset($_POST['answers_json'])) {
                $rawAnswers = (string) $_POST['answers_json'];
                $rawFormulas = (string) ($_POST['formulas_json'] ?? '');
                if (strlen($rawAnswers) > 65536 || strlen($rawFormulas) > 65536) {
                    $error = 'Ukuran data jawaban melebihi batas yang diizinkan.';
                } else {
                $ans = json_decode($rawAnswers, true);
                if (!is_array($ans)) $ans = [];
                $formulas = json_decode($rawFormulas, true);
                if (!is_array($formulas)) $formulas = [];
                $mode = $attempts === 0 ? 'pretest' : 'posttest';
                $total = 0;
                $score = 0;
                $manualCount = 0;
                $manualList = [];
                $stored = [];
                $details = [];
                foreach ($cells as $c) {
                    $addr = (string) ($c['addr'] ?? '');
                    if ($addr === '') continue;
                    $total++;
                    $sheet = (string) ($c['s'] ?? '');
                    $mapKey = $sheet . '|' . $addr;
                    $label = ($sheet !== '' ? $sheet . '!' : '') . $addr;
                    $raw = $ans[$mapKey] ?? ($ans[$addr] ?? '');
                    $val = trim((string) $raw);
                    $stored[$mapKey] = $val;
                    $key = (string) ($c['key'] ?? '');
                    $ok = norm_eq($val, $key);
                    if ($ok) $score++;
                    $hasFormula = !empty($formulas[$mapKey]) || !empty($formulas[$addr]);
                    $manual = ($val !== '' && is_numeric($val) && !$hasFormula);
                    if ($manual) {
                        $manualCount++;
                        $manualList[] = $label;
                    }
                    $details[] = [
                        'label' => $label,
                        'given' => $val,
                        'ok' => $ok,
                        'manual' => $manual,
                    ];
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
                    'manual_count' => $manualCount,
                    'manual_answers' => $manualList,
                    'token' => $submitToken,
                    'submitted_at' => date('c'),
                ];
                $appended = false;
                assessments_update($id, function ($x) use ($resp, $submitToken, &$appended) {
                    if (!isset($x['responses']) || !is_array($x['responses'])) $x['responses'] = [];
                    if ($submitToken !== '') {
                        foreach ($x['responses'] as $er) {
                            if ((string) ($er['token'] ?? '') === $submitToken) return $x;
                        }
                    }
                    $x['responses'][] = $resp;
                    $appended = true;
                    return $x;
                });
                $a = assessment_find($id);
                if (!$appended && $submitToken !== '') {
                    foreach (($a['responses'] ?? []) as $er) {
                        if ((string) ($er['token'] ?? '') === $submitToken) {
                            $mode = (string) ($er['mode'] ?? $mode);
                            $score = (int) ($er['auto_score'] ?? $score);
                            break;
                        }
                    }
                }
                $_SESSION['asesmen_owned'][$id][$identity] = true;
                $result = ['mode' => $mode, 'auto' => $auto, 'score' => $score, 'total' => $total, 'details' => $details, 'manual' => $manualCount];
                }
            } else {
                $error = 'File jawaban tidak terkirim. Coba unggah ulang.';
            }
        } else {
            // legacy: MCQ / fill-in
            $qs = is_array($a['questions'] ?? null) ? $a['questions'] : [];
            $acells = is_array($a['answer_cells'] ?? null) ? $a['answer_cells'] : [];
            $answers = [];
            $score = 0;
            $details = [];
            if (!empty($acells)) {
                $total = count($acells);
                foreach ($acells as $i => $cell) {
                    $v = trim((string) ($_POST['f' . $i] ?? ''));
                    $answers[$i] = $v;
                    $key = trim((string) ($cell['key'] ?? ''));
                    $ok = strcasecmp($v, $key) === 0;
                    if ($ok) $score++;
                    $details[] = ['label' => 'Isian ' . ((int) $i + 1), 'given' => $v, 'ok' => $ok];
                }
            } else {
                $total = count($qs);
                foreach ($qs as $i => $q) {
                    $answers[$i] = isset($_POST['q' . $i]) ? (int) $_POST['q' . $i] : -1;
                }
                foreach ($qs as $i => $q) {
                    $given = (int) ($answers[$i] ?? -1);
                    $correct = (int) ($q['correct'] ?? -2);
                    $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
                    $ok = $given === $correct;
                    if ($ok) $score++;
                    $details[] = [
                        'label' => (string) ($q['q'] ?? ('Soal ' . ((int) $i + 1))),
                        'given' => isset($opts[$given]) ? (string) $opts[$given] : '',
                        'ok' => $ok,
                    ];
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
                'token' => $submitToken,
                'submitted_at' => date('c'),
            ];
            assessments_update($id, function ($x) use ($resp, $submitToken) {
                if (!isset($x['responses']) || !is_array($x['responses'])) $x['responses'] = [];
                if ($submitToken !== '') {
                    foreach ($x['responses'] as $er) {
                        if ((string) ($er['token'] ?? '') === $submitToken) return $x;
                    }
                }
                $x['responses'][] = $resp;
                return $x;
            });
            $_SESSION['asesmen_owned'][$id][$identity] = true;
            $result = ['mode' => 'pretest', 'auto' => $auto, 'score' => $score, 'total' => $total, 'details' => $details];
        }
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
<title><?php echo h($assessment['title'] ?? 'Asesmen'); ?> – UKMI POLMED</title>
<meta name="robots" content="noindex, follow">
<link rel="icon" type="image/png" href="/logo-ukmi.png">
<script src="/components/ui-modal.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
<link rel="stylesheet" href="/style.css">
</head>
<body class="page-asesmen">
<?php require __DIR__ . '/components/loading-overlay.php'; ?>
<div class="wrap">
  <div><a class="muted" href="/blog">← Blog</a></div>

  <?php if ($needReset): ?>
    <div class="card">
      <h1>Sudah menyelesaikan posttest</h1>
      <p class="muted">Kamu sudah mengerjakan pretest &amp; posttest. Ingin mengulang dari awal (reset progres) atau batal?</p>
      <form method="post" style="display:inline;">
        <?php echo ukmi_csrf_field(); ?>
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
        <?php $details = is_array($result['details'] ?? null) ? $result['details'] : []; ?>
        <?php $wrongCount = count(array_filter($details, fn($d) => empty($d['ok']))); ?>
        <?php $manualCount = (int) ($result['manual'] ?? 0); ?>
        <?php if (!empty($details)): ?>
          <h2 class="review-title">Rincian jawaban</h2>
          <?php if ($wrongCount === 0 && $manualCount === 0): ?>
            <p class="ok">Semua jawaban benar.</p>
          <?php else: ?>
            <?php if ($wrongCount > 0): ?>
              <p class="muted">Ada <strong><?php echo $wrongCount; ?></strong> jawaban yang masih salah:</p>
            <?php endif; ?>
            <?php if ($manualCount > 0): ?>
              <p class="err">Terdeteksi <strong><?php echo $manualCount; ?></strong> jawaban berupa angka yang diketik manual (bukan hasil rumus). Jawaban yang benar tetap dihitung.</p>
            <?php endif; ?>
            <ul class="review">
              <?php foreach ($details as $d): ?>
                <li class="<?php echo !empty($d['ok']) ? 'ok' : 'err'; ?>">
                  <strong><?php echo h($d['label']); ?></strong>
                  <?php if (!empty($d['manual'])): ?><span class="tag-manual">diketik manual</span><?php endif; ?>
                  <?php if (!empty($d['ok'])): ?>
                    — benar
                  <?php else: ?>
                    — jawabanmu: <em><?php echo h(($d['given'] ?? '') !== '' ? $d['given'] : '(kosong)'); ?></em>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
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
          <a class="btn" href="/unduh?key=<?php echo urlencode($fileKey); ?>">⬇ Download file Excel</a>
        <?php endif; ?>
      </div>
      <form method="post" class="card" id="file-form">
        <?php echo ukmi_csrf_field(); ?>
        <input type="hidden" name="a_id" value="<?php echo h($assessment['id']); ?>">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="answers_json" id="answers_json" value="">
        <input type="hidden" name="formulas_json" id="formulas_json" value="">
        <input type="hidden" name="submission_token" value="<?php echo h($formToken); ?>">
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
          if (!f) { e.preventDefault(); uiAlert('Pilih file Excel jawaban.'); return; }
          if (typeof ExcelJS === 'undefined') { e.preventDefault(); uiAlert('Library ExcelJS belum termuat.'); return; }
          e.preventDefault();
          if (form.dataset.busy === '1') return;
          form.dataset.busy = '1';
          var btn = form.querySelector('button[type=submit]');
          if (btn) btn.disabled = true;
          try {
            var wb = new ExcelJS.Workbook();
            await wb.xlsx.load(await f.arrayBuffer());
            var out = {};
            var fout = {};
            cells.forEach(function (kc) {
              var ws = wb.getWorksheet(kc.s) || wb.worksheets[0];
              var c = ws ? ws.getCell(kc.addr) : null;
              var v = c ? c.value : null;
              var hasFormula = false;
              if (v != null && typeof v === 'object') {
                hasFormula = !!(v.formula || v.sharedFormula);
                v = (v.result != null ? v.result : (v.formula ? '=' + v.formula : (v.sharedFormula ? '=' + v.sharedFormula : (v.richText ? v.richText.map(function (t) { return t.text; }).join('') : ''))));
              }
              out[kc.s + '|' + kc.addr] = (v == null ? '' : String(v)).trim();
              fout[kc.s + '|' + kc.addr] = hasFormula;
            });
            document.getElementById('answers_json').value = JSON.stringify(out);
            document.getElementById('formulas_json').value = JSON.stringify(fout);
            form.submit();
          } catch (err) { form.dataset.busy = ''; if (btn) btn.disabled = false; uiAlert('Gagal membaca file: ' + err); }
        });
      })();
      </script>
    <?php else: ?>
      <form method="post" class="card" onsubmit="var b=this.querySelector('button[type=submit]');if(b){if(b.disabled)return false;b.disabled=true;}">
        <?php echo ukmi_csrf_field(); ?>
        <input type="hidden" name="a_id" value="<?php echo h($assessment['id']); ?>">
        <input type="hidden" name="submission_token" value="<?php echo h($formToken); ?>">
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
