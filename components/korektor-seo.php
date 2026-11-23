<?php
require_once __DIR__ . '/../seo_corrector_helper.php';

if (($adminComponentMode ?? '') === 'handle') {
    if ($loggedIn && isset($_POST['seo_autofix_safe'])) {
        $res = seo_corrector_autofix_safe($configPath, $config);
        if (!empty($res['ok'])) {
            if (!empty($res['changed'])) {
                $archCount = count($res['archived_slugs'] ?? []);
                $h1Count = count($res['fixed_h1'] ?? []);
                $altCount = count($res['fixed_alt'] ?? []);
                $cleanCount = count($res['cleaned_text'] ?? []);
                $sumCount = count($res['fixed_summaries'] ?? []);
                $updCount = count($res['fixed_updated_at'] ?? []);
                $message = "Auto-Fix Holistic SEO selesai: {$archCount} arsip, {$h1Count} H1->H2, {$altCount} alt gambar, {$cleanCount} teks dibersihkan, {$sumCount} summary, {$updCount} updated_at.";
                appendLog($config, $configPath, 'seo-autofix-safe', $maxLogs, $archiveLimitDays);
            } else {
                $message = 'Auto-Fix Holistic SEO: tidak ada masalah P0/summary yang perlu diperbaiki.';
            }
        } else {
            $error = $res['error'] ?? 'Gagal menjalankan Auto-Fix Holistic SEO.';
        }
    }

    if ($loggedIn && !empty($_POST['seo_ai_analyze_slug'])) {
        $targetSlug = trim((string) $_POST['seo_ai_analyze_slug']);
        $allPosts = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];
        if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
            $__fullAi = data_store_read_all();
            if (is_array($__fullAi['posts'] ?? null)) {
                $allPosts = $__fullAi['posts'];
            }
        }
        $targetPost = null;
        foreach ($allPosts as $p) {
            if (is_array($p) && ($p['slug'] ?? '') === $targetSlug) {
                $targetPost = $p;
                break;
            }
        }
        if ($targetPost === null && function_exists('data_store_is_sharded') && data_store_is_sharded()) {
            $targetPost = data_store_post_read($targetSlug, true);
        }
        if (is_array($targetPost)) {
            $aiRes = seo_corrector_ai_suggest($targetPost, !empty($_POST['seo_ai_force']), $allPosts);
            if (!empty($aiRes['ok'])) {
                $GLOBALS['__seoAiLastResult'] = ['slug' => $targetSlug, 'res' => $aiRes];
                $message = 'Analisis AI Holistic SEO selesai untuk ' . $targetSlug . (!empty($aiRes['from_cache']) ? ' (dari cache).' : '.');
            } else {
                $error = $aiRes['error'] ?? 'Gagal menganalisis artikel dengan OpenRouter.';
            }
        } else {
            $error = 'Artikel tidak ditemukan untuk analisis AI.';
        }
    }

    if ($loggedIn && !empty($_POST['seo_ai_apply_slug'])) {
        $applySlug = trim((string) $_POST['seo_ai_apply_slug']);
        $applyMode = trim((string) ($_POST['seo_ai_apply_mode'] ?? ''));
        $applyRes = seo_corrector_apply_ai_fix($configPath, $config, $applySlug, [
            'mode' => $applyMode,
            'title' => (string) ($_POST['ai_title'] ?? ''),
            'summary' => (string) ($_POST['ai_summary'] ?? ''),
            'body' => (string) ($_POST['ai_body'] ?? ''),
        ]);
        if (!empty($applyRes['ok'])) {
            appendLog($config, $configPath, 'seo-ai-' . $applyMode . ':' . $applySlug, $maxLogs, $archiveLimitDays);
            $message = 'Perubahan AI (' . $applyMode . ') berhasil diterapkan ke /blog/' . $applySlug . '.';
        } else {
            $error = $applyRes['error'] ?? 'Gagal menerapkan perubahan AI.';
        }
    }
    return;
}

$__seoPosts = (!empty($config['posts']) && is_array($config['posts'])) ? $config['posts'] : [];
if (function_exists('data_store_is_sharded') && data_store_is_sharded()) {
    $__full = data_store_read_all();
    if (is_array($__full['posts'] ?? null)) {
        $__seoPosts = $__full['posts'];
    }
}
$__seoAudit = seo_corrector_audit_site($config, $__seoPosts);
$__orCfg = seo_corrector_load_openrouter_config();
$__orRate = seo_corrector_read_rate_state();
$__orRemaining = max(0, (int) $__orCfg['daily_limit'] - (int) $__orRate['count_today']);
$__aiLast = $GLOBALS['__seoAiLastResult'] ?? null;
?>
<section class="card" id="korektor-seo">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
            <h2 style="margin:0 0 4px;">Korektor Holistic SEO Otomatis</h2>
            <p class="muted" style="margin:0;font-size:13px;">
                Skor rata-rata: <strong><?php echo (int) $__seoAudit['avg_score']; ?>/100</strong> ·
                P0: <strong><?php echo (int) $__seoAudit['p0_count']; ?></strong> ·
                P1: <strong><?php echo (int) $__seoAudit['p1_count']; ?></strong> ·
                OpenRouter: <strong><?php echo $__orCfg['api_key'] !== '' ? 'Aktif' : 'Belum diisi di default.json'; ?></strong>
                (Sisa kuota hari ini: <?php echo (int) $__orRemaining; ?>/<?php echo (int) $__orCfg['daily_limit']; ?>)
            </p>
        </div>
        <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ukmi_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" name="seo_autofix_safe" value="1" class="btn">Jalankan Auto-Fix Aman</button>
        </form>
    </div>

    <?php if (is_array($__aiLast) && !empty($__aiLast['res']['ok'])): $d = $__aiLast['res']['data'] ?? []; ?>
        <div style="margin-top:12px;padding:14px;border-radius:10px;border:1px solid rgba(56,189,248,.35);background:rgba(56,189,248,.08);">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                <div>
                    <strong>Saran Agent AI (/blog/<?php echo htmlspecialchars($__aiLast['slug'], ENT_QUOTES, 'UTF-8'); ?>)</strong>
                    <?php if (!empty($d['is_off_topic'])): ?><span style="color:#f87171;margin-left:8px;font-weight:600;">[Off-Topic]</span><?php endif; ?>
                    <?php if (!empty($d['editorial_action'])): ?><span class="muted" style="margin-left:8px;font-size:12px;">Rekomendasi: <strong><?php echo htmlspecialchars((string) $d['editorial_action'], ENT_QUOTES, 'UTF-8'); ?></strong></span><?php endif; ?>
                </div>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ukmi_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="seo_ai_analyze_slug" value="<?php echo htmlspecialchars($__aiLast['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="seo_ai_force" value="1">
                    <button type="submit" class="btn ghost" style="padding:4px 10px;font-size:12px;">Analisis Ulang (Refresh AI)</button>
                </form>
            </div>
            <?php if (!empty($d['off_topic_reason'])): ?>
                <div style="font-size:13px;margin-bottom:8px;"><strong>Alasan Relevansi:</strong> <?php echo htmlspecialchars((string) $d['off_topic_reason'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" style="display:grid;gap:8px;margin-top:8px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ukmi_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="seo_ai_apply_slug" value="<?php echo htmlspecialchars($__aiLast['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                <label style="font-size:12px;">Judul (30-65 karakter):
                    <input type="text" name="ai_title" value="<?php echo htmlspecialchars((string) ($d['suggested_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;margin-top:2px;">
                </label>
                <label style="font-size:12px;">Meta Description (maks 155 karakter):
                    <input type="text" name="ai_summary" value="<?php echo htmlspecialchars((string) ($d['suggested_meta_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;margin-top:2px;">
                </label>
                <label style="font-size:12px;">Naskah Markdown Lengkap (AEO/GEO + H2 + Internal Link):
                    <textarea name="ai_body" rows="8" style="width:100%;margin-top:2px;font-family:monospace;font-size:12px;"><?php echo htmlspecialchars((string) ($d['suggested_body'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <button type="submit" name="seo_ai_apply_mode" value="apply_meta" class="btn ghost">Terapkan Title &amp; Meta</button>
                    <button type="submit" name="seo_ai_apply_mode" value="apply_all" class="btn">Terapkan Semua (Title + Meta + Body)</button>
                    <?php if (!empty($d['is_off_topic']) || ($d['editorial_action'] ?? '') === 'archive'): ?>
                        <button type="submit" name="seo_ai_apply_mode" value="archive" class="btn ghost" style="color:#f87171;border-color:rgba(248,113,113,.45);">Arsipkan Artikel (Off-Topic)</button>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!empty($d['aeo_opening_paragraph'])): ?>
                <div style="margin-top:10px;font-size:13px;"><strong>Paragraf Pembuka AEO:</strong> <?php echo htmlspecialchars((string) $d['aeo_opening_paragraph'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (!empty($d['suggested_h2_outline']) && is_array($d['suggested_h2_outline'])): ?>
                <div style="margin-top:8px;font-size:13px;"><strong>Outline H2:</strong> <?php echo htmlspecialchars(implode(' | ', $d['suggested_h2_outline']), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (!empty($d['suggested_internal_links']) && is_array($d['suggested_internal_links'])): ?>
                <div style="margin-top:8px;font-size:13px;">
                    <strong>Saran Internal Link:</strong>
                    <ul style="margin:4px 0 0;padding-left:18px;">
                        <?php foreach ($d['suggested_internal_links'] as $il): if (!is_array($il)) continue; ?>
                            <li><code><?php echo htmlspecialchars((string) ($il['anchor'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code> &rarr; <code><?php echo htmlspecialchars((string) ($il['url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code> (<?php echo htmlspecialchars((string) ($il['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($d['content_improvement_tips']) && is_array($d['content_improvement_tips'])): ?>
                <div style="margin-top:8px;font-size:13px;">
                    <strong>Tips Konten:</strong>
                    <ul style="margin:4px 0 0;padding-left:18px;">
                        <?php foreach ($d['content_improvement_tips'] as $tip): ?>
                            <li><?php echo htmlspecialchars((string) $tip, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($d['eeat_checklist']) && is_array($d['eeat_checklist'])): ?>
                <div style="margin-top:8px;font-size:13px;color:#fbbf24;">
                    <strong>Checklist Verifikasi Manual (E-E-A-T / Off-Page):</strong>
                    <ul style="margin:4px 0 0;padding-left:18px;">
                        <?php foreach ($d['eeat_checklist'] as $chk): ?>
                            <li><?php echo htmlspecialchars((string) $chk, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($__seoAudit['site_issues'])): ?>
        <div style="margin-top:12px;padding:10px 12px;border-radius:10px;border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.08);font-size:13px;">
            <strong>Audit Elemen Situs Non-Blog:</strong>
            <ul style="margin:6px 0 0;padding-left:18px;">
                <?php foreach ($__seoAudit['site_issues'] as $si): ?>
                    <li>[<?php echo htmlspecialchars($si['level'], ENT_QUOTES, 'UTF-8'); ?>] <?php echo htmlspecialchars($si['msg'], ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div style="margin-top:12px;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.2);">
                    <th style="padding:8px 6px;">Artikel</th>
                    <th style="padding:8px 6px;">Skor</th>
                    <th style="padding:8px 6px;">Temuan</th>
                    <th style="padding:8px 6px;">Aksi AI</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($__seoAudit['posts'] as $row): ?>
                <tr style="border-bottom:1px solid rgba(148,163,184,.12);">
                    <td style="padding:8px 6px;">
                        <strong><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                        <small class="muted">/blog/<?php echo htmlspecialchars($row['slug'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int) $row['word_count']; ?> kata)</small>
                    </td>
                    <td style="padding:8px 6px;"><strong><?php echo (int) $row['score']; ?></strong></td>
                    <td style="padding:8px 6px;">
                        <?php if (empty($row['issues'])): ?>
                            <span style="color:#4ade80;">Optimal</span>
                        <?php else: foreach ($row['issues'] as $iss): ?>
                            <div>[<?php echo htmlspecialchars($iss['level'], ENT_QUOTES, 'UTF-8'); ?>] <?php echo htmlspecialchars($iss['msg'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endforeach; endif; ?>
                    </td>
                    <td style="padding:8px 6px;">
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ukmi_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="seo_ai_analyze_slug" value="<?php echo htmlspecialchars($row['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn ghost" style="padding:4px 8px;font-size:12px;">Analisis AI</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
