<?php
/**
 * master/panduan-grup-wa.php — Panduan Mengambil ID Grup WhatsApp via Watobot API
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Mentoring\AdminAuth;

AdminAuth::guard();

$basePath = mentoring_base_path(__DIR__);
$appBase = mentoring_base_path(dirname(__DIR__));
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panduan Ambil ID Grup WhatsApp — Mentoring MAI</title>
    <link rel="stylesheet" href="<?= $h($appBase) ?>public/assets/css/style.css">
    <style>
        .guide-container { max-width: 820px; margin: 0 auto; padding: 24px 16px 48px; }
        .guide-header { margin-bottom: 20px; }
        .guide-header a { color: #38bdf8; text-decoration: none; font-size: 0.9rem; display: inline-block; margin-bottom: 8px; }
        .guide-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 14px; padding: 20px; margin-bottom: 16px; }
        .guide-card h2 { font-size: 1.05rem; margin: 0 0 10px; color: #f8fafc; }
        .guide-card p, .guide-card li { color: #cbd5e1; font-size: 0.9rem; line-height: 1.55; }
        .guide-card ol { margin: 8px 0 0 18px; padding: 0; }
        .guide-card li { margin-bottom: 6px; }
        .endpoint-pill {
            display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px;
            border-radius: 8px; background: rgba(56, 189, 248, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.3); color: #7dd3fc;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.84rem; margin: 6px 0 10px;
        }
        .api-helper-input {
            width: 100%; padding: 10px 12px; border-radius: 8px;
            border: 1px solid var(--card-border); background: #0f172a;
            color: #f8fafc; font-size: 0.88rem; margin-top: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .cmd-block {
            background: #090d16; border: 1px solid #334155; border-radius: 10px;
            padding: 12px; margin-top: 10px;
        }
        .cmd-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; margin-bottom: 8px;
        }
        .cmd-title { font-size: 0.82rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; }
        .copy-btn {
            padding: 4px 10px; border-radius: 6px; border: 1px solid #38bdf8;
            background: rgba(56, 189, 248, 0.14); color: #7dd3fc;
            font-size: 0.78rem; font-weight: 600; cursor: pointer;
        }
        .copy-btn:hover { background: rgba(56, 189, 248, 0.25); }
        .cmd-pre {
            margin: 0; white-space: pre-wrap; word-break: break-all;
            color: #e2e8f0; font-size: 0.82rem; line-height: 1.5;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .note-box {
            margin-top: 10px; padding: 10px 12px; border-radius: 8px;
            background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35);
            color: #a7f3d0; font-size: 0.84rem; line-height: 1.5;
        }
        .json-paste-area {
            width: 100%; padding: 10px 12px; border-radius: 8px;
            border: 1px solid var(--card-border); background: #090d16;
            color: #e2e8f0; font-size: 0.82rem; line-height: 1.45;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            resize: vertical; margin-top: 8px;
        }
        .parse-status { font-size: 0.82rem; margin-top: 8px; color: #94a3b8; }
        .parse-status.error { color: #fca5a5; }
        .parse-status.ok { color: #34d399; }
        .group-search-wrap { margin-top: 12px; }
        .group-result-list {
            margin-top: 10px; max-height: 280px; overflow-y: auto;
            display: flex; flex-direction: column; gap: 8px;
        }
        .group-result-item {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 10px 12px; border-radius: 8px;
            background: #0f172a; border: 1px solid #334155;
        }
        .group-result-info { min-width: 0; }
        .group-result-name { font-size: 0.88rem; font-weight: 700; color: #f8fafc; word-break: break-word; }
        .group-result-id {
            font-size: 0.78rem; color: #7dd3fc; margin-top: 2px; word-break: break-all;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
    </style>
</head>
<body>
<div class="guide-container">
    <header class="guide-header">
        <a href="<?= $h($basePath) ?>?tab=tab-laporan">&larr; Kembali ke Kelola Laporan</a>
        <h1 class="page-title" style="margin: 0;">Panduan Ambil ID Grup WhatsApp</h1>
        <p class="page-subtitle" style="margin: 4px 0 0;">Daftar grup WhatsApp dari akun Watobot yang terhubung</p>
    </header>

    <div class="guide-card">
        <h2>1. Siapkan API Key Watobot</h2>
        <ol>
            <li>Buka <a href="https://watobot.xyz/dashboard/" target="_blank" rel="noopener noreferrer" style="color:#38bdf8;">Dasbor Watobot &nearr;</a> dan login menggunakan nomor pengirim yang sesuai (Ikhwan atau Akhwat).</li>
            <li>Salin <b>API Key</b> dari dasbor tersebut.</li>
            <li>Endpoint Watobot yang digunakan:
                <div class="endpoint-pill">GET /api/whatsapp/groups</div>
            </li>
        </ol>
        <label for="helper-api-key" style="display:block; margin-top:12px; font-size:0.82rem; color:#cbd5e1; font-weight:600;">
            Opsional: Tempel API Key di sini untuk mengisi perintah otomatis (hanya di browser Anda)
        </label>
        <input type="text" id="helper-api-key" class="api-helper-input" placeholder="Tempel API Key Watobot di sini..." autocomplete="off">
    </div>

    <div class="guide-card">
        <h2>2. Jalankan Perintah di Terminal</h2>
        <p>Pilih perintah sesuai perangkat yang Anda gunakan:</p>

        <div class="cmd-block">
            <div class="cmd-head">
                <span class="cmd-title">Windows (CMD / Command Prompt)</span>
                <button type="button" class="copy-btn" onclick="copyCmd('cmd-win', this)">Salin Perintah</button>
            </div>
            <pre class="cmd-pre" id="cmd-win"></pre>
        </div>

        <div class="cmd-block">
            <div class="cmd-head">
                <span class="cmd-title">Linux / macOS Terminal</span>
                <button type="button" class="copy-btn" onclick="copyCmd('cmd-linux', this)">Salin Perintah</button>
            </div>
            <pre class="cmd-pre" id="cmd-linux"></pre>
        </div>

        <div class="cmd-block">
            <div class="cmd-head">
                <span class="cmd-title">Android (Termux)</span>
                <button type="button" class="copy-btn" onclick="copyCmd('cmd-termux', this)">Salin Perintah</button>
            </div>
            <pre class="cmd-pre" id="cmd-termux"></pre>
        </div>
    </div>

    <div class="guide-card">
        <h2>3. Salin ID Grup dari Hasil JSON</h2>
        <p>Tempel hasil respons JSON dari terminal ke kotak di bawah ini untuk mencari ID grup berdasarkan namanya:</p>
        <textarea id="json-response-input" class="json-paste-area" rows="5" placeholder='Tempel hasil JSON dari terminal di sini (mis. {"data": [{"id": "120363...@g.us", "name": "Mentor Akhwat"}]})'></textarea>
        <div id="parse-status" class="parse-status"></div>

        <div id="group-search-wrap" class="group-search-wrap" style="display: none;">
            <input type="text" id="group-search-input" class="api-helper-input" placeholder="🔍 Ketik nama grup untuk mencari ID..." autocomplete="off">
            <div id="group-result-list" class="group-result-list"></div>
        </div>

        <div class="note-box">
            Contoh format ID Grup: <code>120363271558091003@g.us</code><br>
            Tempel ID tersebut ke kolom <b>ID Grup WhatsApp</b> pada modal <b>Pengaturan Bot</b> di tab <b>Kelola Laporan</b>.
        </div>
    </div>
</div>

<script>
function buildCommands(apiKey) {
    const key = (apiKey || '').trim() || 'ISI_API_KEY_ANDA';
    const url = 'https://api.watobot.xyz/api/whatsapp/groups';
    document.getElementById('cmd-win').textContent =
        `curl.exe -s -X GET "${url}" -H "x-api-key: ${key}" -H "Content-Type: application/json"`;
    document.getElementById('cmd-linux').textContent =
        `curl -s -X GET "${url}" -H "x-api-key: ${key}" -H "Content-Type: application/json"`;
    document.getElementById('cmd-termux').textContent =
        `pkg install curl -y && curl -s -X GET "${url}" -H "x-api-key: ${key}" -H "Content-Type: application/json"`;
}

async function copyCmd(id, btn) {
    const text = document.getElementById(id)?.textContent || '';
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
        const old = btn.textContent;
        btn.textContent = 'Tersalin!';
        setTimeout(() => { btn.textContent = old; }, 1600);
    } catch (e) {
        window.prompt('Salin perintah berikut:', text);
    }
}

document.getElementById('helper-api-key').addEventListener('input', (e) => {
    buildCommands(e.target.value);
});
buildCommands('');

let parsedGroups = [];

function extractGroupArray(parsed) {
    if (Array.isArray(parsed)) return parsed;
    if (!parsed || typeof parsed !== 'object') return [];
    if (Array.isArray(parsed.data)) return parsed.data;
    if (Array.isArray(parsed.groups)) return parsed.groups;
    if (parsed.data && Array.isArray(parsed.data.groups)) return parsed.data.groups;
    if (Array.isArray(parsed.results)) return parsed.results;
    return [];
}

function renderGroupResults(query) {
    const listEl = document.getElementById('group-result-list');
    const q = (query || '').trim().toLowerCase();
    const filtered = q
        ? parsedGroups.filter((g) => g.name.toLowerCase().includes(q) || g.id.toLowerCase().includes(q))
        : parsedGroups;

    listEl.innerHTML = '';
    if (filtered.length === 0) {
        const empty = document.createElement('div');
        empty.style.cssText = 'color:#94a3b8; font-size:0.84rem; padding:8px 0;';
        empty.textContent = 'Tidak ada grup yang cocok dengan pencarian.';
        listEl.appendChild(empty);
        return;
    }

    filtered.forEach((g) => {
        const item = document.createElement('div');
        item.className = 'group-result-item';

        const info = document.createElement('div');
        info.className = 'group-result-info';
        const nameEl = document.createElement('div');
        nameEl.className = 'group-result-name';
        nameEl.textContent = g.name;
        const idEl = document.createElement('div');
        idEl.className = 'group-result-id';
        idEl.textContent = g.id;
        info.appendChild(nameEl);
        info.appendChild(idEl);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'copy-btn';
        btn.textContent = 'Salin ID';
        btn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(g.id);
                btn.textContent = 'Tersalin!';
                setTimeout(() => { btn.textContent = 'Salin ID'; }, 1600);
            } catch (err) {
                window.prompt('Salin ID grup berikut:', g.id);
            }
        });

        item.appendChild(info);
        item.appendChild(btn);
        listEl.appendChild(item);
    });
}

function mapGroupObjects(arr) {
    return arr
        .filter((item) => item && typeof item === 'object')
        .map((item) => ({
            id: String(item.id || item.jid || item.group_id || item.chatId || '').trim(),
            name: String(item.name || item.subject || item.title || item.group_name || '(Tanpa Nama)').trim(),
        }))
        .filter((g) => g.id !== '');
}

function parseGroupsWithAutoCorrect(raw) {
    const cleaned = (raw || '')
        .replace(/\u001b\[[0-9;]*m/g, '')
        .replace(/[\u200B-\u200D\uFEFF]/g, '')
        .trim();

    // Tahap 1: JSON standar
    try {
        const groups = mapGroupObjects(extractGroupArray(JSON.parse(cleaned)));
        if (groups.length > 0) return { groups, corrected: false };
    } catch (e) {}

    // Tahap 2: Buang teks prompt/log sebelum '{' atau '[' pertama dan setelah '}' atau ']' terakhir
    const startIdx = cleaned.search(/[\{\[]/);
    const endIdx = Math.max(cleaned.lastIndexOf('}'), cleaned.lastIndexOf(']'));
    const sliced = (startIdx !== -1 && endIdx > startIdx) ? cleaned.slice(startIdx, endIdx + 1) : cleaned;

    if (sliced !== cleaned) {
        try {
            const groups = mapGroupObjects(extractGroupArray(JSON.parse(sliced)));
            if (groups.length > 0) return { groups, corrected: true };
        } catch (e) {}
    }

    // Tahap 3: Gabungkan line-wrap terminal, perbaiki koma hilang antar field/objek, & hapus trailing comma
    const unwrapped = sliced
        .replace(/\r?\n/g, '')
        .replace(/"\s*"?(id|name|subject|title|jid|group_id|chatId|group_name)"\s*:/gi, '","$1":')
        .replace(/\}\s*\{/g, '},{')
        .replace(/,\s*([}\]])/g, '$1');
    try {
        const groups = mapGroupObjects(extractGroupArray(JSON.parse(unwrapped)));
        if (groups.length > 0) return { groups, corrected: true };
    } catch (e) {}

    // Tahap 4 (Fallback): Ekstrak tiap objek {...} yang mengandung @g.us
    const compact = cleaned.replace(/\r?\n/g, ' ');
    const seen = new Set();
    const fallbackGroups = [];
    const objRegex = /\{[^{}]*@g\.us[^{}]*\}/g;
    let objMatch;
    while ((objMatch = objRegex.exec(compact)) !== null) {
        const block = objMatch[0];
        const idMatch = block.match(/"?(?:id|jid|group_id|chatId)"?\s*:\s*"([^"]+@g\.us)"/i);
        if (!idMatch) continue;
        const id = idMatch[1].trim();
        if (seen.has(id)) continue;
        seen.add(id);

        const nameMatch = block.match(/"?(?:name|subject|title|group_name)"?\s*:\s*"([^"]*)"/i);
        fallbackGroups.push({
            id,
            name: nameMatch && nameMatch[1].trim() !== '' ? nameMatch[1].trim() : '(Tanpa Nama)',
        });
    }

    if (fallbackGroups.length > 0) {
        return { groups: fallbackGroups, corrected: true };
    }

    return { groups: [], corrected: false };
}

document.getElementById('json-response-input').addEventListener('input', (e) => {
    const raw = (e.target.value || '').trim();
    const statusEl = document.getElementById('parse-status');
    const searchWrap = document.getElementById('group-search-wrap');
    const searchInput = document.getElementById('group-search-input');

    if (!raw) {
        parsedGroups = [];
        statusEl.textContent = '';
        statusEl.className = 'parse-status';
        searchWrap.style.display = 'none';
        return;
    }

    const result = parseGroupsWithAutoCorrect(raw);
    parsedGroups = result.groups;

    if (parsedGroups.length === 0) {
        statusEl.textContent = 'Daftar grup tidak ditemukan. Pastikan respons terminal mengandung ID grup (@g.us).';
        statusEl.className = 'parse-status error';
        searchWrap.style.display = 'none';
        return;
    }

    statusEl.textContent = result.corrected
        ? `Ditemukan ${parsedGroups.length} grup (format otomatis diperbaiki).`
        : `Ditemukan ${parsedGroups.length} grup.`;
    statusEl.className = 'parse-status ok';
    searchWrap.style.display = 'block';
    renderGroupResults(searchInput.value);
});

document.getElementById('group-search-input').addEventListener('input', (e) => {
    renderGroupResults(e.target.value);
});
</script>
</body>
</html>
