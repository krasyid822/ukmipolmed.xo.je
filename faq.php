<?php
// Parse embedded posts inside this file between markers:
// <!-- EMBED_POSTS_START -->  and  <!-- EMBED_POSTS_END -->
// Each post block is separated by a line containing three or more dashes (---)
// Block format: first non-empty line = title. Optional line starting with "slug:" sets slug.

$posts = [];
$self = @file_get_contents(__FILE__);
$start = '<!-- EMBED_POSTS_START -->';
$end = '<!-- EMBED_POSTS_END -->';
$embedded = '';
if ($self !== false) {
    $p1 = strpos($self, $start);
    $p2 = strpos($self, $end);
    if ($p1 !== false && $p2 !== false && $p2 > $p1) {
        $embedded = trim(substr($self, $p1 + strlen($start), $p2 - ($p1 + strlen($start))));
    }
}

if ($embedded !== '') {
    $blocks = preg_split('/\r?\n-{3,}\r?\n/', $embedded);
    foreach ($blocks as $blk) {
        $lines = preg_split('/\r?\n/', trim($blk));
        $title = '';
        $slug = '';
        $bodyLines = [];
        foreach ($lines as $i => $ln) {
            $ln = rtrim($ln);
            if (trim($ln) === '') {
                if ($title !== '') $bodyLines[] = '';
                continue;
            }
            if ($title === '') { $title = trim($ln); continue; }
            if (preg_match('/^slug:\s*(.+)$/i', $ln, $m)) { $slug = trim($m[1]); continue; }
            $bodyLines[] = $ln;
        }
        $body = implode("\n", $bodyLines);
        if ($title !== '') $posts[] = ['title' => $title, 'slug' => $slug, 'body' => $body];
    }
}

function makeSlug($text)
{
    $text = strtolower((string) $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string) $text, '-');
    if ($text === '') {
        $text = 'post-' . substr(md5((string) microtime(true)), 0, 8);
    }
    return $text;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ - UKMI POLMED</title>

    <style>
        .sidebar {
        width: 200px;
        position: fixed;
        height: 100%;
        background-color: #f1f1f1;
        overflow-y: auto;
        }

        .sidebar a {
        display: block;
        padding: 12px 16px;
        text-decoration: none;
        color: inherit;
        }

        .content {
        margin-left: 220px; /* leave space for sidebar */
        padding: 16px;
        }

        @media screen and (max-width: 700px) {
        .sidebar { width: 100%; height: auto; position: relative; }
        div.content { margin-left: 0; }
        }

    </style>
</head>
<body>
    <div class="sidebar">
        <a class="active" href="/">Home</a>
        <a href="/blog.php">Blog</a>
        <?php foreach ($posts as $idx => $p):
            $title = trim((string) ($p['title'] ?? 'Tanpa judul'));
            $slug = trim((string) ($p['slug'] ?? ''));
            if ($slug === '') $slug = makeSlug($title);
            $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        ?>
            <a href="#<?php echo urlencode($slug); ?>" data-slug="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" data-idx="<?php echo $idx; ?>"><?php echo $safeTitle; ?></a>
        <?php endforeach; ?>
    </div>

    <div class="content">
        <h1 id="faq-title">FAQ</h1>
        <div id="faq-body">
            <p><b><i>..jangan remehkan kekhawatiran..</i></b> Halaman ini dibuat untuk itu. Mulailah menavigasi. Pilih judul di menu samping untuk melihat isi.</p>
        </div>

        <?php foreach ($posts as $p):
            $title = htmlspecialchars(trim((string) ($p['title'] ?? '')), ENT_QUOTES, 'UTF-8');
            $slug = trim((string) ($p['slug'] ?? ''));
            if ($slug === '') $slug = makeSlug($title);
            $body = isset($p['body']) ? htmlspecialchars($p['body'], ENT_QUOTES, 'UTF-8') : '';
            $htmlBody = nl2br($body);
        ?>
            <div class="post-content" id="post-<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" style="display:none;">
                <h2><?php echo $title; ?></h2>
                <div class="post-body"><?php echo $htmlBody; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<!-- EMBED_POSTS_START -->
Panduan Pendaftaran
slug: panduan-pendaftaran

Langkah 1: Isi form pendaftaran.
Langkah 2: Verifikasi melalui email.

---
Cara Bergabung
slug: cara-bergabung

Datang ke rapat rekrutmen setiap Senin.
Bawa fotokopi KTM dan berkas pendukung.
<!-- EMBED_POSTS_END -->

    <script>
        (function(){
            const links = document.querySelectorAll('.sidebar a[data-slug]');
            const showPost = (slug) => {
                if (!slug) return;
                document.querySelectorAll('.post-content').forEach(el => el.style.display = 'none');
                const el = document.getElementById('post-' + slug);
                if (el) {
                    el.style.display = '';
                    const h = el.querySelector('h2')?.innerText || '';
                    const b = el.querySelector('.post-body')?.innerHTML || '';
                    document.getElementById('faq-title').innerText = h || 'FAQ';
                    document.getElementById('faq-body').innerHTML = b || '';
                }
                links.forEach(a => a.classList.toggle('active', a.getAttribute('data-slug') === slug));
            };

            links.forEach(a => {
                a.addEventListener('click', function(e){
                    e.preventDefault();
                    const slug = this.getAttribute('data-slug');
                    if (!slug) return;
                    history.replaceState(null, '', '#' + encodeURIComponent(slug));
                    showPost(slug);
                });
            });

            const hash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
            if (hash) {
                showPost(hash);
            } else if (links.length) {
                const first = links[0].getAttribute('data-slug');
                if (first) showPost(first);
            }
        })();
    </script>

</body>
</html>
