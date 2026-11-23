<?php
require_once __DIR__ . '/store.php';

/**
 * Muat konfigurasi OpenRouter dari default.json dengan batas aman rate limiting.
 */
function seo_corrector_load_openrouter_config(): array
{
    $defaults = [
        'api_key' => '',
        'model' => 'auto:free',
        'only_free' => true,
        'min_interval_seconds' => 15,
        'daily_limit' => 20,
        'cache_ttl_seconds' => 86400,
    ];
    $path = __DIR__ . '/default.json';
    if (is_readable($path)) {
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && is_array($data['openrouter'] ?? null)) {
            $or = $data['openrouter'];
            $defaults['api_key'] = trim((string) ($or['api_key'] ?? ''));
            if (trim((string) ($or['model'] ?? '')) !== '') {
                $defaults['model'] = trim((string) $or['model']);
            }
            $defaults['only_free'] = array_key_exists('only_free', $or) ? (bool) $or['only_free'] : true;
            $defaults['min_interval_seconds'] = max(10, (int) ($or['min_interval_seconds'] ?? 15));
            $defaults['daily_limit'] = max(1, (int) ($or['daily_limit'] ?? 20));
            $defaults['cache_ttl_seconds'] = max(300, (int) ($or['cache_ttl_seconds'] ?? 86400));
        }
    }
    return $defaults;
}

/**
 * Bersihkan karakter tak terlihat, spasi ganda, dan newline berlebih.
 */
function seo_corrector_clean_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x{200B}\x{FEFF}\x{200C}\x{200D}\x{00A0}]/u', ' ', $text);
    $text = preg_replace('/[ \t]{2,}/', ' ', (string) $text);
    $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);
    return trim((string) $text);
}

/**
 * Bersihkan sintaks Markdown dan shortcode internal menjadi teks polos.
 */
function seo_corrector_strip_markdown(string $text): string
{
    $text = preg_replace('/\[\[(?:EMBED|GDRIVE)\d*\]\]/i', ' ', $text);
    $text = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '$1', (string) $text);
    $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', (string) $text);
    $text = preg_replace('/^\s{0,3}(?:#{1,6}|>)\s*/m', '', (string) $text);
    $text = str_replace(['**', '__', '`'], '', (string) $text);
    $text = preg_replace('/(^|\s)\*([^*\n]+)\*(\s|$|[.,;:!?])/u', '$1$2$3', (string) $text);
    $text = preg_replace('/(^|\s)_([^_\n]+)_(\s|$|[.,;:!?])/u', '$1$2$3', (string) $text);
    return seo_corrector_clean_text((string) $text);
}

/**
 * Potong teks di batas kata agar tidak terpotong di tengah kata.
 */
function seo_corrector_trim_word_boundary(string $text, int $maxLen = 155): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= $maxLen) {
        return $text;
    }
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, $maxLen, 'UTF-8') : substr($text, 0, $maxLen);
    $lastSpace = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > (int) ($maxLen * 0.5)) {
        $cut = function_exists('mb_substr') ? mb_substr($cut, 0, $lastSpace, 'UTF-8') : substr($cut, 0, $lastSpace);
    }
    return rtrim($cut, " \t\n\r\0\x0B.,;:-");
}

/**
 * Audit deterministik 4 pilar Holistic SEO untuk satu artikel blog.
 */
function seo_corrector_audit_post(array $post): array
{
    $slug = trim((string) ($post['slug'] ?? ''));
    $title = trim((string) ($post['title'] ?? ''));
    $summary = trim((string) ($post['summary'] ?? ''));
    $body = (string) ($post['body'] ?? '');
    $plainBody = trim(preg_replace('/\s+/u', ' ', seo_corrector_strip_markdown(strip_tags($body))));
    $wordCount = $plainBody === '' ? 0 : str_word_count($plainBody);
    $h1LeakCount = preg_match_all('/^#\s+/m', $body);
    $h2Count = preg_match_all('/^##\s+/m', $body);
    $updatedAt = trim((string) ($post['updated_at'] ?? ''));

    $issues = [];
    $score = 100;

    // P0: Test/spam post & slug rusak
    if ($wordCount < 20) {
        $issues[] = ['level' => 'P0', 'pilar' => 'Content SEO', 'msg' => "Konten sangat tipis ($wordCount kata). Berisiko penalti thin content."];
        $score -= 40;
    } elseif ($wordCount < 300) {
        $issues[] = ['level' => 'P1', 'pilar' => 'Content SEO', 'msg' => "Artikel pendek ($wordCount kata). Disarankan >= 300 kata untuk AEO/GEO."];
        $score -= 15;
    }

    if (strlen($slug) < 3 || !preg_match('/^[a-z0-9-]+$/', $slug)) {
        $issues[] = ['level' => 'P0', 'pilar' => 'Technical SEO', 'msg' => "Slug '$slug' terlalu pendek atau tidak bersih."];
        $score -= 20;
    }

    $titleLen = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    if ($titleLen < 30 || $titleLen > 65) {
        $issues[] = ['level' => 'P2', 'pilar' => 'Technical SEO', 'msg' => "Panjang judul ($titleLen karakter) belum ideal (target 30-65 karakter)."];
        $score -= 5;
    }

    if ($h1LeakCount > 0) {
        $issues[] = ['level' => 'P1', 'pilar' => 'UX/SXO', 'msg' => "Ditemukan $h1LeakCount heading # (H1) di dalam body Markdown; gunakan ## (H2)."];
        $score -= 10;
    }

    if ($h2Count === 0 && $wordCount >= 20) {
        $issues[] = ['level' => 'P1', 'pilar' => 'UX/SXO & AEO', 'msg' => 'Belum ada subheading H2 (##) untuk struktur jawaban.'];
        $score -= 10;
    }

    // Cek panjang paragraf pembuka (target AEO 40-60 kata)
    if ($wordCount >= 20) {
        $paras = preg_split('/\n\s*\n/', trim($body));
        $firstPara = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($paras[0] ?? ''))));
        $firstWords = $firstPara === '' ? 0 : str_word_count($firstPara);
        if ($firstWords < 25 || $firstWords > 80) {
            $issues[] = ['level' => 'P2', 'pilar' => 'Content SEO (AEO)', 'msg' => "Paragraf pembuka $firstWords kata (target AEO ideal 40-60 kata)."];
            $score -= 5;
        }
    }

    $sumLen = function_exists('mb_strlen') ? mb_strlen($summary, 'UTF-8') : strlen($summary);
    if ($summary === '' || $sumLen < 50 || $sumLen > 170) {
        $issues[] = ['level' => 'P1', 'pilar' => 'Technical SEO', 'msg' => "Meta summary ($sumLen karakter) belum ideal (target 50-165 karakter)."];
        $score -= 10;
    }
    if ($summary !== '' && preg_match('/(\*\*|\[\[|\]\(|^\*[^*]+\*)/', $summary)) {
        $issues[] = ['level' => 'P1', 'pilar' => 'Technical SEO', 'msg' => 'Meta summary mengandung sintaks Markdown/shortcode mentah.'];
        $score -= 10;
    }

    if (preg_match_all('/!\[([^\]]*)\]\(/', $body, $imgMatches)) {
        $emptyAlt = 0;
        foreach ($imgMatches[1] as $alt) {
            if (trim($alt) === '') {
                $emptyAlt++;
            }
        }
        if ($emptyAlt > 0) {
            $issues[] = ['level' => 'P1', 'pilar' => 'UX/SXO', 'msg' => "$emptyAlt gambar markdown belum memiliki alt text."];
            $score -= 10;
        }
    }

    if (!preg_match('/\]\((\/(?:blog|faq)?|https?:\/\/ukmipolmed\.my\.id)/i', $body)) {
        $issues[] = ['level' => 'P2', 'pilar' => 'Content Silo', 'msg' => 'Belum ada internal link ke /blog, /faq, atau beranda.'];
        $score -= 5;
    }

    if ($updatedAt === '') {
        $issues[] = ['level' => 'P2', 'pilar' => 'Authority (E-E-A-T)', 'msg' => 'Tanggal updated_at belum tercatat.'];
        $score -= 5;
    }

    // Skor relevansi kata kunci domain UKMI POLMED (deteksi awal off-topic tanpa AI)
    $suspectOffTopic = false;
    if ($wordCount >= 20) {
        $hay = strtolower($title . ' ' . $plainBody);
        $domainKeywords = ['ukmi', 'polmed', 'politeknik', 'islam', 'kajian', 'mentoring', 'kampus', 'mahasiswa', 'dakwah', 'ramadan', 'tarawih', 'masjid', 'quran', 'tilawah', 'keputrian', 'kaderisasi', 'muslim'];
        $hits = 0;
        foreach ($domainKeywords as $kw) {
            if (strpos($hay, $kw) !== false) {
                $hits++;
            }
        }
        if ($hits === 0) {
            $suspectOffTopic = true;
            $issues[] = ['level' => 'P1', 'pilar' => 'Content Silo', 'msg' => 'Suspect off-topic: tidak ditemukan kata kunci domain UKMI/Polmed/Islam.'];
            $score -= 15;
        }
    }

    $score = max(0, min(100, $score));
    $needsAi = false;
    foreach ($issues as $iss) {
        if ($iss['level'] === 'P0' || $iss['level'] === 'P1') {
            $needsAi = true;
            break;
        }
    }

    return [
        'slug' => $slug,
        'title' => $title ?: 'Tanpa judul',
        'summary' => $summary,
        'word_count' => $wordCount,
        'score' => $score,
        'suspect_off_topic' => $suspectOffTopic,
        'issues' => $issues,
        'needs_ai' => $needsAi,
    ];
}

/**
 * Audit seluruh artikel aktif + cek duplikasi + audit elemen non-blog situs.
 */
function seo_corrector_audit_site(array $config, array $posts): array
{
    $results = [];
    $totalScore = 0;
    $p0Count = 0;
    $p1Count = 0;
    $seenTitles = [];
    $seenSummaries = [];

    foreach ($posts as $p) {
        if (!is_array($p) || !empty($p['archived'])) {
            continue;
        }
        $audit = seo_corrector_audit_post($p);
        $tKey = strtolower(trim($audit['title']));
        if ($tKey !== '' && isset($seenTitles[$tKey])) {
            $audit['issues'][] = ['level' => 'P1', 'pilar' => 'Content SEO', 'msg' => 'Judul duplikat dengan artikel lain (/blog/' . $seenTitles[$tKey] . ').'];
            $audit['score'] = max(0, $audit['score'] - 15);
        } elseif ($tKey !== '') {
            $seenTitles[$tKey] = $audit['slug'];
        }

        $sKey = strtolower(trim($audit['summary']));
        if ($sKey !== '' && isset($seenSummaries[$sKey])) {
            $audit['issues'][] = ['level' => 'P2', 'pilar' => 'Technical SEO', 'msg' => 'Summary duplikat dengan artikel lain (/blog/' . $seenSummaries[$sKey] . ').'];
            $audit['score'] = max(0, $audit['score'] - 5);
        } elseif ($sKey !== '') {
            $seenSummaries[$sKey] = $audit['slug'];
        }

        $totalScore += $audit['score'];
        foreach ($audit['issues'] as $iss) {
            if ($iss['level'] === 'P0') $p0Count++;
            if ($iss['level'] === 'P1') $p1Count++;
        }
        $results[] = $audit;
    }

    // Audit situs non-blog: agenda, hero_cards, faqs
    $siteIssues = [];
    $agendas = (!empty($config['agendas']) && is_array($config['agendas'])) ? $config['agendas'] : [];
    foreach ($agendas as $idx => $ag) {
        if (is_array($ag) && trim((string) ($ag['start_date'] ?? '')) === '') {
            $siteIssues[] = ['level' => 'P2', 'msg' => 'Agenda #' . ($idx + 1) . ' ("' . ($ag['title'] ?? '-') . '") belum memiliki start_date untuk schema Event.'];
        }
    }
    $heroCards = (!empty($config['hero_cards']) && is_array($config['hero_cards'])) ? $config['hero_cards'] : [];
    foreach ($heroCards as $idx => $hc) {
        if (is_array($hc) && ($hc['type'] ?? '') === 'image' && trim((string) ($hc['alt'] ?? '')) === '') {
            $siteIssues[] = ['level' => 'P2', 'msg' => 'Hero card gambar #' . ($idx + 1) . ' belum memiliki alt text.'];
        }
    }
    $faqs = (!empty($config['faqs']) && is_array($config['faqs'])) ? $config['faqs'] : [];
    if (count($faqs) < 3) {
        $siteIssues[] = ['level' => 'P2', 'msg' => 'Jumlah FAQ saat ini ' . count($faqs) . ' item (disarankan minimal 3 FAQ untuk AEO).'];
    }

    $count = count($results);
    return [
        'total_active' => $count,
        'avg_score' => $count > 0 ? (int) round($totalScore / $count) : 100,
        'p0_count' => $p0Count,
        'p1_count' => $p1Count,
        'posts' => $results,
        'site_issues' => $siteIssues,
    ];
}

/**
 * Perbaikan deterministik aman (selaras dengan mode sharded data_store & single-file).
 */
function seo_corrector_autofix_safe(string $configPath, array &$config): array
{
    $isSharded = function_exists('data_store_is_sharded') && data_store_is_sharded();
    $fullStore = $isSharded ? data_store_read_all() : $config;
    $posts = (!empty($fullStore['posts']) && is_array($fullStore['posts'])) ? $fullStore['posts'] : [];

    $archivedSlugs = [];
    $fixedH1 = [];
    $fixedAlt = [];
    $cleanedText = [];
    $fixedSummaries = [];
    $fixedUpdatedAt = [];
    $changed = false;

    foreach ($posts as $idx => $p) {
        if (!is_array($p) || !empty($p['archived'])) {
            continue;
        }
        $slug = (string) ($p['slug'] ?? $idx);
        $title = (string) ($p['title'] ?? 'Artikel UKMI POLMED');
        $body = (string) ($p['body'] ?? '');
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
        $words = $plain === '' ? 0 : str_word_count($plain);

        if ($words < 20) {
            $posts[$idx]['archived'] = true;
            $posts[$idx]['updated_at'] = date('c');
            $archivedSlugs[] = $slug;
            $changed = true;
            continue;
        }

        $postChanged = false;

        // 1. Bersihkan karakter tak terlihat & spasi berlebih
        $cleanBody = seo_corrector_clean_text($body);
        $cleanTitle = seo_corrector_clean_text($title);
        if ($cleanBody !== $body || $cleanTitle !== $title) {
            $body = $cleanBody;
            $title = $cleanTitle;
            $posts[$idx]['body'] = $body;
            $posts[$idx]['title'] = $title;
            $cleanedText[] = $slug;
            $postChanged = true;
        }

        // 2. Ubah heading # (H1) di body Markdown menjadi ## (H2)
        $bodyNoH1 = preg_replace('/^#\s+(.+)$/m', '## $1', $body, -1, $h1Count);
        if ($h1Count > 0 && is_string($bodyNoH1)) {
            $body = $bodyNoH1;
            $posts[$idx]['body'] = $body;
            $fixedH1[] = $slug;
            $postChanged = true;
        }

        // 3. Isi alt text gambar markdown yang kosong
        $safeAltTitle = str_replace(['[', ']'], '', $title);
        $bodyWithAlt = preg_replace('/!\[\s*\]\(([^)]+)\)/', '![Dokumentasi ' . $safeAltTitle . ']($1)', $body, -1, $altCount);
        if ($altCount > 0 && is_string($bodyWithAlt)) {
            $body = $bodyWithAlt;
            $posts[$idx]['body'] = $body;
            $fixedAlt[] = $slug;
            $postChanged = true;
        }

        // 4. Rapikan summary di batas kata
        $plainAfter = trim(preg_replace('/\s+/u', ' ', seo_corrector_strip_markdown(strip_tags($body))));
        $summary = seo_corrector_clean_text((string) ($p['summary'] ?? ''));
        $sumLen = function_exists('mb_strlen') ? mb_strlen($summary, 'UTF-8') : strlen($summary);
        $hasRawMd = $summary !== '' && preg_match('/(\*\*|\[\[|\]\(|(^|\s)\*[^*\s])/', $summary);
        if ($summary === '' || $sumLen > 165 || $hasRawMd) {
            $newSum = seo_corrector_trim_word_boundary($plainAfter, 155);
            if ($newSum !== '' && $newSum !== (string) ($p['summary'] ?? '')) {
                $posts[$idx]['summary'] = $newSum;
                $fixedSummaries[] = $slug;
                $postChanged = true;
            }
        } elseif ($summary !== (string) ($p['summary'] ?? '')) {
            $posts[$idx]['summary'] = $summary;
            $cleanedText[] = $slug;
            $postChanged = true;
        }

        // 5. Isi updated_at yang kosong
        if (trim((string) ($p['updated_at'] ?? '')) === '') {
            $posts[$idx]['updated_at'] = trim((string) ($p['created_at'] ?? '')) ?: date('c');
            $fixedUpdatedAt[] = $slug;
            $postChanged = true;
        } elseif ($postChanged) {
            $posts[$idx]['updated_at'] = date('c');
        }

        if ($postChanged) {
            $changed = true;
        }
    }

    if ($changed) {
        $fullStore['posts'] = $posts;
        if ($isSharded && function_exists('data_store_write_full')) {
            if (!data_store_write_full($fullStore)) {
                return ['ok' => false, 'error' => 'Gagal menyimpan perubahan auto-fix ke data_store sharded.'];
            }
            $config['posts'] = $posts;
        } else {
            $config['posts'] = $posts;
            $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
                return ['ok' => false, 'error' => 'Gagal menyimpan perubahan auto-fix ke store.'];
            }
        }
    }

    return [
        'ok' => true,
        'changed' => $changed,
        'archived_slugs' => $archivedSlugs,
        'fixed_h1' => $fixedH1,
        'fixed_alt' => $fixedAlt,
        'cleaned_text' => array_values(array_unique($cleanedText)),
        'fixed_summaries' => $fixedSummaries,
        'fixed_updated_at' => $fixedUpdatedAt,
    ];
}

/**
 * Ambil artikel terkait berbasis kemiripan kata kunci judul (Content Silo).
 */
function seo_corrector_related_posts(array $currentPost, array $allPosts, int $limit = 3): array
{
    $curSlug = trim((string) ($currentPost['slug'] ?? ''));
    $stop = ['dan', 'yang', 'untuk', 'dari', 'atau', 'pada', 'dengan', 'di', 'ke', 'ini', 'itu', 'oleh', 'agar', 'saat', 'ukmi', 'polmed'];
    $tokenize = function (string $text) use ($stop): array {
        $words = preg_split('/[^a-z0-9]+/i', strtolower($text));
        $tokens = [];
        foreach ($words as $w) {
            if (strlen($w) >= 3 && !in_array($w, $stop, true)) {
                $tokens[$w] = true;
            }
        }
        return array_keys($tokens);
    };

    $curTokens = $tokenize((string) ($currentPost['title'] ?? ''));
    $scored = [];
    foreach ($allPosts as $p) {
        if (!is_array($p) || !empty($p['archived'])) {
            continue;
        }
        $slug = trim((string) ($p['slug'] ?? ''));
        if ($slug === '' || $slug === $curSlug) {
            continue;
        }
        $pTokens = $tokenize((string) ($p['title'] ?? ''));
        $overlap = count(array_intersect($curTokens, $pTokens));
        $scored[] = ['score' => $overlap, 'date' => (string) ($p['created_at'] ?? ''), 'post' => $p];
    }

    usort($scored, function ($a, $b) {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        return strcmp($b['date'], $a['date']);
    });

    return array_map(fn($row) => $row['post'], array_slice($scored, 0, $limit));
}

function seo_corrector_rate_path(): string
{
    return __DIR__ . '/seo-cache/openrouter-rate.json';
}

function seo_corrector_read_rate_state(): array
{
    $path = seo_corrector_rate_path();
    $today = date('Y-m-d');
    $default = ['date' => $today, 'count_today' => 0, 'last_call_at' => 0, 'retry_after' => 0];
    if (!is_readable($path)) {
        return $default;
    }
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        return $default;
    }
    if (($data['date'] ?? '') !== $today) {
        $data['date'] = $today;
        $data['count_today'] = 0;
    }
    return array_merge($default, $data);
}

function seo_corrector_write_rate_state(array $state): void
{
    $dir = __DIR__ . '/seo-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = @fopen(seo_corrector_rate_path(), 'c+');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($state, JSON_PRETTY_PRINT));
            fflush($fp);
            @flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

/**
 * Ambil daftar model gratis OpenRouter dari API publik dan cache selama 24 jam.
 */
function seo_corrector_get_free_models_cached(int $ttl = 86400): array
{
    $cacheFile = __DIR__ . '/seo-cache/openrouter-free-models.json';
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && (time() - (int) ($cached['cached_at'] ?? 0)) < $ttl && !empty($cached['models'])) {
            return $cached['models'];
        }
    }

    $resp = '';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $resp = (string) curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
        $resp = (string) @file_get_contents('https://openrouter.ai/api/v1/models', false, $ctx);
    }

    $freeModels = [];
    $decoded = json_decode($resp, true);
    foreach (($decoded['data'] ?? []) as $m) {
        if (!is_array($m) || empty($m['id'])) {
            continue;
        }
        $id = (string) $m['id'];
        $pPrompt = (string) ($m['pricing']['prompt'] ?? '');
        $pComp = (string) ($m['pricing']['completion'] ?? '');
        if (substr($id, -5) === ':free' || ($pPrompt === '0' && $pComp === '0')) {
            $freeModels[] = $id;
        }
    }

    if (!empty($freeModels)) {
        if (!is_dir(__DIR__ . '/seo-cache')) {
            @mkdir(__DIR__ . '/seo-cache', 0755, true);
        }
        @file_put_contents($cacheFile, json_encode(['cached_at' => time(), 'models' => $freeModels], JSON_PRETTY_PRINT));
    }

    return $freeModels;
}

/**
 * Pastikan model yang dipakai otomatis & hanya model gratis (free).
 */
function seo_corrector_resolve_model(array $cfg): string
{
    $model = trim((string) ($cfg['model'] ?? 'auto:free'));
    $onlyFree = !array_key_exists('only_free', $cfg) || !empty($cfg['only_free']);

    if ($model === '' || $model === 'auto:free' || $model === 'openrouter/free') {
        return 'openrouter/free';
    }
    if ($onlyFree && substr($model, -5) !== ':free') {
        return 'openrouter/free';
    }
    return $model;
}

function seo_corrector_call_openrouter(string $apiKey, string $model, string $prompt): array
{
    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.3,
    ]);

    $httpCode = 0;
    $respBody = '';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://ukmipolmed.my.id/',
                'X-Title: UKMI POLMED Holistic SEO Corrector',
            ],
        ]);
        $respBody = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\nHTTP-Referer: https://ukmipolmed.my.id/\r\n",
                'content' => $payload,
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);
        $respBody = (string) @file_get_contents('https://openrouter.ai/api/v1/chat/completions', false, $ctx);
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    return ['http_code' => $httpCode, 'body' => $respBody];
}

/**
 * Ekstrak objek JSON terluar dari respons teks AI (menghapus markdown fence atau teks pengantar).
 */
function seo_corrector_extract_json(string $content): ?array
{
    $clean = trim($content);
    $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $clean);
    $clean = trim((string) $clean);

    $decoded = json_decode($clean, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($clean, '{');
    $end = strrpos($clean, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $slice = substr($clean, $start, $end - $start + 1);
        $decoded = json_decode($slice, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}

/**
 * Dapatkan saran Holistic SEO dari Agent AI OpenRouter dengan caching & rate-limit guard.
 */
function seo_corrector_ai_suggest(array $post, bool $forceRefresh = false, array $allPosts = []): array
{
    $cfg = seo_corrector_load_openrouter_config();
    $slug = trim((string) ($post['slug'] ?? 'unknown'));
    $updatedAt = trim((string) ($post['updated_at'] ?? ''));
    $body = (string) ($post['body'] ?? '');
    $cacheKey = sha1('v2|' . $slug . '|' . $updatedAt . '|' . $body);
    $cacheFile = __DIR__ . '/seo-cache/seo-ai-' . $cacheKey . '.json';

    if (!$forceRefresh && is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && (time() - (int) ($cached['cached_at'] ?? 0)) < $cfg['cache_ttl_seconds']) {
            $cached['from_cache'] = true;
            return $cached;
        }
    }

    if ($cfg['api_key'] === '') {
        return [
            'ok' => false,
            'error' => 'API key OpenRouter belum diisi di default.json (openrouter.api_key).',
        ];
    }

    $now = time();
    $rate = seo_corrector_read_rate_state();
    if ((int) $rate['retry_after'] > $now) {
        $wait = (int) $rate['retry_after'] - $now;
        return ['ok' => false, 'error' => "Sedang backoff rate limit OpenRouter. Coba lagi dalam {$wait} detik."];
    }
    if ((int) $rate['last_call_at'] > 0 && ($now - (int) $rate['last_call_at']) < $cfg['min_interval_seconds']) {
        $wait = $cfg['min_interval_seconds'] - ($now - (int) $rate['last_call_at']);
        return ['ok' => false, 'error' => "Cooldown aktif untuk mencegah rate limit. Tunggu {$wait} detik."];
    }
    if ((int) $rate['count_today'] >= $cfg['daily_limit']) {
        return ['ok' => false, 'error' => "Batas harian OpenRouter ({$cfg['daily_limit']} panggilan/hari) telah tercapai."];
    }

    $internalTargets = ["- /blog (Daftar Artikel UKMI POLMED)", "- /faq (FAQ & Informasi UKMI POLMED)"];
    foreach ($allPosts as $ap) {
        if (!is_array($ap) || !empty($ap['archived'])) {
            continue;
        }
        $apSlug = trim((string) ($ap['slug'] ?? ''));
        $apTitle = trim((string) ($ap['title'] ?? ''));
        if ($apSlug !== '' && $apSlug !== $slug && $apTitle !== '') {
            $internalTargets[] = "- /blog/{$apSlug} — {$apTitle}";
            if (count($internalTargets) >= 14) {
                break;
            }
        }
    }

    $prompt = "Anda adalah pakar Holistic SEO (Technical, Content AEO/GEO, Authority E-E-A-T, UX/SXO) untuk situs organisasi dakwah kampus UKMI Politeknik Negeri Medan (ukmipolmed.my.id).\n"
        . "Aturan ketat:\n"
        . "1. DILARANG membuat thin content, fluff, atau keyword stuffing.\n"
        . "2. DILARANG mengarang fakta, tanggal, nama tokoh, kutipan narasumber, atau angka statistik baru.\n"
        . "3. WAJIB mempertahankan seluruh URL gambar Markdown ![...](...) dan shortcode [[EMBED...]] / [[GDRIVE...]] yang sudah ada, namun perbaiki alt text gambar agar spesifik dan deskriptif.\n"
        . "4. Kembalikan HANYA JSON valid tanpa markdown fence dengan key berikut:\n"
        . "   - is_off_topic (boolean): true jika topik di luar dakwah kampus/UKMI/Polmed/mahasiswa muslim\n"
        . "   - off_topic_reason (string): alasan singkat penilaian relevansi\n"
        . "   - editorial_action (string): pilih salah satu \"keep\", \"rewrite\", atau \"archive\"\n"
        . "   - suggested_title (string): 30-65 karakter dengan long-tail keyword alami\n"
        . "   - suggested_meta_description (string): maks 155 karakter, persuasif & akurat\n"
        . "   - aeo_opening_paragraph (string): 40-60 kata direct answer untuk Featured Snippets / AI Overviews\n"
        . "   - suggested_h2_outline (array of strings): daftar subheading ## berbentuk pertanyaan natural (TOFU/MOFU/BOFU)\n"
        . "   - suggested_internal_links (array of objects {anchor, url, reason}): tautan internal kontekstual dari daftar target tersedia\n"
        . "   - suggested_body (string): naskah Markdown lengkap hasil perbaikan (paragraf pembuka AEO, struktur ## H2, ekspansi >= 300 kata bila pendek tanpa fluff, internal link kontekstual, alt text spesifik)\n"
        . "   - eeat_checklist (array of strings): hal yang tetap perlu verifikasi manual editor (foto asli, kutipan pengurus, start_date agenda)\n"
        . "   - content_improvement_tips (array of strings)\n\n"
        . "Target internal link yang tersedia:\n" . implode("\n", $internalTargets) . "\n\n"
        . "Judul: " . ($post['title'] ?? '') . "\n"
        . "Slug: " . $slug . "\n"
        . "Isi Markdown:\n" . mb_substr($body, 0, 4500, 'UTF-8');

    $usedModel = seo_corrector_resolve_model($cfg);
    $call = seo_corrector_call_openrouter($cfg['api_key'], $usedModel, $prompt);
    $httpCode = $call['http_code'];
    $respBody = $call['body'];

    if (($httpCode === 400 || $httpCode === 404) && $usedModel === 'openrouter/free') {
        $freeList = seo_corrector_get_free_models_cached($cfg['cache_ttl_seconds']);
        if (!empty($freeList[0])) {
            $usedModel = $freeList[0];
            $call = seo_corrector_call_openrouter($cfg['api_key'], $usedModel, $prompt);
            $httpCode = $call['http_code'];
            $respBody = $call['body'];
        }
    }

    if ($httpCode === 429 || $httpCode >= 500 || $httpCode === 0) {
        $rate['retry_after'] = time() + 60;
        seo_corrector_write_rate_state($rate);
        return ['ok' => false, 'error' => "OpenRouter mengembalikan HTTP {$httpCode}. Backoff 60 detik diaktifkan."];
    }

    $decoded = json_decode($respBody, true);
    $content = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
    $aiJson = seo_corrector_extract_json($content);

    $rate['last_call_at'] = time();
    $rate['count_today'] = (int) $rate['count_today'] + 1;
    $rate['retry_after'] = 0;
    seo_corrector_write_rate_state($rate);

    $result = [
        'ok' => true,
        'cached_at' => time(),
        'from_cache' => false,
        'model' => (string) ($decoded['model'] ?? $usedModel),
        'data' => is_array($aiJson) ? $aiJson : ['raw_text' => $content],
    ];
    if (!is_dir(__DIR__ . '/seo-cache')) {
        @mkdir(__DIR__ . '/seo-cache', 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $result;
}

/**
 * Terapkan hasil perbaikan AI (meta, body, atau arsip) ke store secara aman.
 */
function seo_corrector_apply_ai_fix(string $configPath, array &$config, string $slug, array $patch): array
{
    $mode = trim((string) ($patch['mode'] ?? ''));
    if (!in_array($mode, ['archive', 'apply_meta', 'apply_body', 'apply_all'], true)) {
        return ['ok' => false, 'error' => 'Mode penerapan AI tidak valid.'];
    }

    $isSharded = function_exists('data_store_is_sharded') && data_store_is_sharded();
    $fullStore = $isSharded ? data_store_read_all() : $config;
    $posts = (!empty($fullStore['posts']) && is_array($fullStore['posts'])) ? $fullStore['posts'] : [];

    $targetIdx = null;
    foreach ($posts as $idx => $p) {
        if (is_array($p) && (string) ($p['slug'] ?? '') === $slug) {
            $targetIdx = $idx;
            break;
        }
    }
    if ($targetIdx === null) {
        return ['ok' => false, 'error' => "Artikel dengan slug '{$slug}' tidak ditemukan."];
    }

    $origBody = (string) ($posts[$targetIdx]['body'] ?? '');
    if ($mode === 'archive') {
        $posts[$targetIdx]['archived'] = true;
        $posts[$targetIdx]['updated_at'] = date('c');
    } else {
        if ($mode === 'apply_meta' || $mode === 'apply_all') {
            $newTitle = seo_corrector_clean_text((string) ($patch['title'] ?? ''));
            $newSummary = seo_corrector_trim_word_boundary(seo_corrector_clean_text((string) ($patch['summary'] ?? '')), 165);
            if ($newTitle !== '') {
                $posts[$targetIdx]['title'] = $newTitle;
            }
            if ($newSummary !== '') {
                $posts[$targetIdx]['summary'] = $newSummary;
            }
        }
        if ($mode === 'apply_body' || $mode === 'apply_all') {
            $newBody = seo_corrector_clean_text((string) ($patch['body'] ?? ''));
            if ($newBody === '') {
                return ['ok' => false, 'error' => 'Body artikel hasil AI tidak boleh kosong.'];
            }
            $newBody = preg_replace('/^#\s+(.+)$/m', '## $1', $newBody);
            if (preg_match_all('/\[\[(?:EMBED|GDRIVE)\d*\]\]/i', $origBody, $scMatches)) {
                foreach ($scMatches[0] as $sc) {
                    if (stripos($newBody, $sc) === false) {
                        $newBody .= "\n\n" . $sc;
                    }
                }
            }
            $posts[$targetIdx]['body'] = $newBody;
        }
        $posts[$targetIdx]['updated_at'] = date('c');
    }

    $fullStore['posts'] = $posts;
    if ($isSharded && function_exists('data_store_write_full')) {
        if (!data_store_write_full($fullStore)) {
            return ['ok' => false, 'error' => 'Gagal menyimpan perubahan AI ke data_store sharded.'];
        }
        $config['posts'] = $posts;
    } else {
        $config['posts'] = $posts;
        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || store_save_raw($configPath, $encoded) === false) {
            return ['ok' => false, 'error' => 'Gagal menyimpan perubahan AI ke store.'];
        }
    }

    return ['ok' => true, 'mode' => $mode, 'slug' => $slug];
}
