<?php
$agendaDefaults = [
    'tag' => 'Agenda terdekat',
    'title' => 'Pelantikan Ketum 2026-2027',
    'detail' => 'Minggu, 25 Februari · Namira School. MUBES, Ihsan Taufiq, dipilih sebagai Ketua Umum UKMI Polmed 2026-2027.',
];

$agenda = $agendaDefaults;
$configPath = __DIR__ . '/admin.json';

if (is_readable($configPath)) {
    $config = json_decode((string) file_get_contents($configPath), true);
    if (is_array($config) && !empty($config['agenda']) && is_array($config['agenda'])) {
        $agenda = array_merge($agendaDefaults, array_intersect_key($config['agenda'], $agendaDefaults));
    }
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shortcut UKMI Polmed | Open Recruitment 2026</title>
    <meta name="description" content="Gabung UKMI Polmed 2026: mentoring, tilawah, syiar media, keputrian, ekonomi, dan aksi sosial. Form pendaftaran, dokumentasi, dan kontak cepat di satu halaman.">
    <meta name="keywords" content="UKMI Polmed, open recruitment UKMI, organisasi Islam Polmed, mentoring kampus, UKM Islami Politeknik Negeri Medan">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="./">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Shortcut UKMI Polmed | Open Recruitment 2026">
    <meta property="og:description" content="Semua shortcut untuk gabung UKMI Polmed: form daftar, dokumentasi kegiatan, dan divisi kepengurusan 2026-2027.">
    <meta property="og:url" content="./">
    <meta property="og:image" content="logo-ukmi.png">
    <meta property="og:site_name" content="UKMI Polmed">
    <link rel="icon" type="image/png" href="logo-ukmi.png">
    <link rel="apple-touch-icon" href="logo-ukmi.png">
    <link rel="shortcut icon" href="logo-ukmi.png">
    <meta name="theme-color" content="#0f172a">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Shortcut UKMI Polmed | Open Recruitment 2026">
    <meta name="twitter:description" content="Form daftar, dokumentasi kegiatan, dan divisi UKMI Polmed 2026-2027 dalam satu halaman.">
    <meta name="twitter:image" content="logo-ukmi.png">
    <?php
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            [
                '@type' => 'Question',
                'name' => 'Bagaimana cara daftar UKMI Polmed?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Klik tombol Daftar sekarang atau isi form pendaftaran di bagian Jalur cepat daftar.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Apa yang akan saya dapatkan di UKMI Polmed?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Mentoring iman-ilmu, jaringan lintas angkatan, serta pengalaman event, media, dan kewirausahaan.',
                ],
            ],
            [
                '@type' => 'Question',
                'name' => 'Apakah terbuka untuk semua jurusan?',
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => 'Ya, UKMI Polmed terbuka untuk semua jurusan dan angkatan baru.',
                ],
            ],
        ],
    ];
    $faqJson = json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    ?>
    <script type="application/ld+json"><?php echo $faqJson; ?></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #070b1a;
            --card: rgba(255, 255, 255, 0.06);
            --stroke: rgba(255, 255, 255, 0.1);
            --text: #e9eef7;
            --muted: #c7d1e1;
            --accent: #6cf7c5;
            --accent-2: #7cc9ff;
            --shadow: 0 18px 45px rgba(0, 0, 0, 0.45);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', 'Space Grotesk', system-ui, sans-serif;
            background: radial-gradient(circle at 20% 20%, rgba(108, 247, 197, 0.2), transparent 35%),
                        radial-gradient(circle at 80% 0%, rgba(124, 201, 255, 0.25), transparent 40%),
                        linear-gradient(145deg, #050814, #0e1427 55%, #0a0f20 100%);
            color: var(--text);
            overflow-x: hidden;
        }

        .glow {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(108, 247, 197, 0.13), transparent 35%),
                        radial-gradient(circle at 80% 20%, rgba(124, 201, 255, 0.12), transparent 30%);
            filter: blur(50px);
            pointer-events: none;
            z-index: 0;
        }

        header.header {
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(12px);
            background: linear-gradient(180deg, rgba(7, 11, 26, 0.9), rgba(7, 11, 26, 0.65));
            border-bottom: 1px solid var(--stroke);
            transition: transform 0.25s ease;
        }

        .header.hide {
            transform: translateY(-100%);
        }

        .nav {
            max-width: 1080px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            letter-spacing: 0.4px;
        }

        .logo {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--stroke);
        }

        .pulse-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            box-shadow: 0 0 0 0 rgba(108, 247, 197, 0.4);
            animation: pulse 2.2s ease-out infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(108, 247, 197, 0.4); }
            70% { box-shadow: 0 0 0 18px rgba(108, 247, 197, 0); }
            100% { box-shadow: 0 0 0 0 rgba(108, 247, 197, 0); }
        }

        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .menu-toggle {
            display: none;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid var(--stroke);
            color: var(--text);
            border-radius: 12px;
            padding: 10px 12px;
            cursor: pointer;
            font-weight: 700;
        }

        main {
            position: relative;
            max-width: 1080px;
            margin: 0 auto;
            padding: 40px 24px 80px;
            z-index: 1;
        }

        .hero {
            margin-top: 24px;
            padding: 26px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.03));
            border: 1px solid var(--stroke);
            border-radius: 20px;
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(108, 247, 197, 0.12);
            color: var(--accent);
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        h1 {
            margin: 12px 0 8px;
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
            font-size: clamp(28px, 5vw, 40px);
            line-height: 1.2;
        }

        .lead {
            margin: 0 0 18px;
            color: var(--muted);
            line-height: 1.6;
        }

        .cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 18px;
            border-radius: 14px;
            border: 1px solid var(--stroke);
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            backdrop-filter: blur(6px);
        }

        .btn.primary {
            background: linear-gradient(120deg, var(--accent), var(--accent-2));
            color: #061028;
            box-shadow: 0 12px 32px rgba(108, 247, 197, 0.25);
            border: none;
        }

        .btn.ghost {
            background: rgba(255, 255, 255, 0.07);
        }

        .btn:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(0,0,0,0.35); }

        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
        }

        .metric-card {
            padding: 14px;
            border-radius: 14px;
            background: var(--card);
            border: 1px solid var(--stroke);
        }

        .metric-card strong { display: block; font-size: 18px; margin-bottom: 4px; }
        .metric-card span { color: var(--muted); font-size: 13px; }

        section {
            margin-top: 40px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            letter-spacing: 0.3px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--stroke);
            color: var(--accent);
            font-weight: 700;
        }

        .grid {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .card {
            padding: 18px;
            border-radius: 16px;
            border: 1px solid var(--stroke);
            background: var(--card);
            box-shadow: var(--shadow);
        }

        .card h3 { margin: 0 0 8px; font-size: 17px; }
        .card p { margin: 0; color: var(--muted); line-height: 1.5; font-size: 14px; }

        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            background: rgba(108, 247, 197, 0.12);
            color: var(--accent);
            border: 1px solid rgba(108, 247, 197, 0.2);
            margin-bottom: 8px;
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .step {
            padding: 14px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 14px;
            border: 1px dashed var(--stroke);
        }

        .cta-banner {
            margin-top: 32px;
            padding: 22px;
            border-radius: 18px;
            background: linear-gradient(120deg, rgba(108, 247, 197, 0.16), rgba(124, 201, 255, 0.14));
            border: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            flex-direction: column;
            gap: 10px;
            box-shadow: var(--shadow);
        }

        footer {
            margin-top: 32px;
            padding: 16px 0 32px;
            color: var(--muted);
            font-size: 14px;
        }

        .socials { display: flex; gap: 12px; margin-top: 6px; }

        .socials a {
            color: var(--text);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 12px;
            border: 1px solid var(--stroke);
            background: rgba(255, 255, 255, 0.05);
        }

        @media (max-width: 640px) {
            .nav { padding: 14px 16px; }
            main { padding: 28px 16px 64px; }
            .hero { padding: 20px; }
            .nav-actions { width: 100%; display: none; flex-direction: column; }
            .nav.is-open .nav-actions { display: flex; }
            .nav-actions a { width: 100%; justify-content: center; }
            .menu-toggle { display: inline-flex; }
            .brand { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>
    <div class="glow" aria-hidden="true"></div>
    <header class="header">
        <div class="nav" id="site-nav">
            <div class="brand">
                <div style="display:flex; align-items:center; gap:10px;">
                    <img class="logo" src="logo-ukmi.png" alt="Logo UKMI Polmed">
                    <span class="pulse-dot"></span>
                    <script>
                    (function(){
                        const dot = document.querySelector('.pulse-dot');
                        if (!dot) return;
                        dot.style.cursor = 'pointer';
                        dot.addEventListener('click', () => {
                            window.open('https://share.google/BuCPuMmJQqGoRqHQp', '_blank', 'noopener');
                        });
                    })();
                    </script>
                    <span>UKMI Polmed</span>
                </div>
                <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="nav-actions">Menu</button>
            </div>
            <div class="nav-actions" id="nav-actions">
                <a class="btn ghost" href="#program">Program</a>
                <a class="btn ghost" href="#divisi">Divisi</a>
                <a class="btn ghost" href="#dokumentasi">Dokumentasi</a>
                <a class="btn primary" href="#daftar">Daftar</a>
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <div>
                <div class="eyebrow">Open Recruitment · <span id="current-year"></span></div>
                <script>document.getElementById('current-year').textContent = new Date().getFullYear();</script>
                <h1>Jadi bagian keluarga UKMI. Bergerak bareng, bertumbuh bareng.</h1>
                <p class="lead">Halaman shortcut untuk mahasiswa baru Polmed yang mau nyemplung ke organisasi UKMI: cek agenda, kelas skill, jalur daftar tercepat, dan dokumentasi kegiatan.</p>
                <div class="cta-row">
                    <a class="btn primary" href="https://forms.gle/e4e6egXHiRnyQVPV7" target="_blank" rel="noopener">Daftar sekarang</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Lihat arsip IG kami</a>
                </div>
            </div>
            <div>
                <div class="metrics">
                    <div class="metric-card">
                        <strong>∞+ kegiatan</strong>
                        <span>Mentoring, sosial, dan kreatif setiap minggunya.</span>
                    </div>
                    <div class="metric-card">
                        <strong>6 divisi</strong>
                        <span>Kaderisasi · Mentring Agama Islam · Pembinaan Tilawatil Quran · Syiar Media · Keputrian · Ekonomi.</span>
                    </div>
                    <div class="metric-card">
                        <strong>Terbuka</strong>
                        <span>Untuk semua jurusan & angkatan baru.</span>
                    </div>
                </div>
            </div>
        </section>

        <section id="shortcut">
            <div class="section-title"><span class="pill">★</span>Shortcut paling dicari</div>
            <div class="grid">
                <div class="card">
                    <div class="tag"><?php echo e($agenda['tag']); ?></div>
                    <h3><?php echo e($agenda['title']); ?></h3>
                    <p><?php echo e($agenda['detail']); ?></p>
                </div>
                <div class="card">
                    <div class="tag">Skill Class</div>
                    <h3>Desain & konten</h3>
                    <p>Belajar Canva, editing, dan social media playbook UKMI. Cocok buat kamu yang kreatif.</p>
                </div>
                <div class="card">
                    <div class="tag">Mentoring</div>
                    <h3>Circle kecil</h3>
                    <p>Ruang ngobrol hangat bareng kakak tingkat tentang kampus, iman, dan karier.</p>
                </div>
                <div class="card">
                    <div class="tag">Social impact</div>
                    <h3>Aksi nyata</h3>
                    <p>Program bakti sosial, peduli lingkungan, dan kolaborasi lintas UKM.</p>
                </div>
            </div>
        </section>

        <section id="program">
            <div class="section-title"><span class="pill">→</span>Kenapa harus gabung?</div>
            <div class="grid">
                <div class="card">
                    <h3>Teman seperjuangan</h3>
                    <p>Lingkungan suportif untuk kuliah, organisasi, dan tumbuh bareng di kampus.</p>
                </div>
                <div class="card">
                    <h3>Portofolio nyata</h3>
                    <p>Handle event, media sosial, fundraising, dan project digital yang bisa kamu pajang.</p>
                </div>
                <div class="card">
                    <h3>Jaringan luas</h3>
                    <p>Terhubung dengan alumni, komunitas dakwah kampus, dan relasi eksternal.</p>
                </div>
                <div class="card">
                    <h3>Mentoring karier</h3>
                    <p>Belajar langsung dari kakak tingkat yang sudah magang, studi lanjut, atau kerja.</p>
                </div>
            </div>
        </section>

        <section id="divisi">
            <div class="section-title"><span class="pill">◎</span>Divisi kepengurusan 2026-2027</div>
            <div class="grid">
                <div class="card"><h3>Kaderisasi</h3><p>Merancang alur pembinaan anggota baru dan pelatihan berjenjang.</p></div>
                <div class="card"><h3>Mentring Agama Islam</h3><p>Fokus mentoring iman-ilmu, kajian tematik, dan pendampingan rohani.</p></div>
                <div class="card"><h3>Pembinaan Tilawatil Quran</h3><p>Kelas tahsin-tahfizh, halaqah rutin, dan penguatan literasi Quran.</p></div>
                <div class="card"><h3>Syiar Media</h3><p>Mengelola konten digital, desain, foto-video, dan siaran kampanye kebaikan.</p></div>
                <div class="card"><h3>Keputrian</h3><p>Program khusus muslimah: kelas, support system, dan kepemimpinan perempuan.</p></div>
                <div class="card"><h3>Ekonomi</h3><p>Inisiatif kewirausahaan, fundrising program, dan pengelolaan dana kegiatan.</p></div>
            </div>
        </section>

        <section id="daftar">
            <div class="section-title"><span class="pill">⚡</span>Jalur cepat daftar</div>
            <p class="lead">Pilih cara paling nyaman buat kamu. Kalau sudah punya link resmi, silahkan jalankan dengan baik.</p>
            <div class="steps">
                <div class="step">
                    <strong>Ikuti mentoring mingguan</strong>
                    <p>Gabung sesi mentoring, amati bagaimana UKMI berjalan, dan terlibat secara bertahap.</p>
                </div>
                <div class="step">
                    <strong>Tunggu proses seleksi</strong>
                    <p>Panitia akan memantau peserta mentoring; jika lolos, kamu akan dihubungi dan diundang untuk wawancara.</p>
                </div>
                <div class="step">
                    <strong>Isi form sebelum wawancara</strong>
                    <p>Sebelum wawancara kemungkinan diminta mengisi form data diri, divisi yang diincar, dan informasi pendukung lainnya.</p>
                </div>
            </div>

            <div class="cta-banner">
                <strong>Siap gas? Klik sekali untuk daftar.</strong>
                <div class="cta-row">
                    <a class="btn primary" href="https://forms.gle/e4e6egXHiRnyQVPV7" target="_blank" rel="noopener">Buka form pendaftaran</a>
                    <a class="btn ghost" href="https://instagram.com/ukmipolmed" target="_blank" rel="noopener">Tanya via DM IG</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Lihat dokumentasi kegiatan</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/what-they-said/" target="_blank" rel="noopener">Testimoni</a>
                </div>
                <small style="color: var(--muted);">Form utama mengarah ke Google Forms mentoring UKMI. Link cadangan tersedia di halaman testimoni.</small>
            </div>
        </section>

        <section id="dokumentasi">
            <div class="section-title"><span class="pill">📸</span>Dokumentasi kegiatan</div>
            <div class="grid">
                <div class="card">
                    <div class="tag">Highlight</div>
                    <h3>Arsip IG UKMI</h3>
                    <p>Galeri kegiatan 2016-2025 dalam format slide dan grid. Cocok untuk stalking suasana UKMI.</p>
                    <div class="cta-row" style="margin-top:12px;">
                        <a class="btn primary" href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Buka arsip IG</a>
                    </div>
                </div>
                <div class="card">
                    <div class="tag">PPI 2024</div>
                    <h3>Dokumentasi PPI 2024</h3>
                    <p>Index foto/video PPI 2024 lengkap dengan navigasi slide & grid view.</p>
                    <div class="cta-row" style="margin-top:12px;">
                        <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/dokumentasi-ppi-2024/">Buka dokumentasi PPI 2024</a>
                    </div>
                </div>
            </div>
        </section>

        <footer>
            <div>UKMI Politeknik Negeri Medan · Bersama bertumbuh dalam kebaikan.</div>
            <div class="socials">
                <a href="https://instagram.com/ukmipolmed" target="_blank" rel="noopener">Instagram</a>
                <a href="https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/">Arsip IG</a>
                <a href="https://krasyid822.github.io/ukmipolmed/dokumentasi-ppi-2024/">PPI 2024</a>
                <a href="#daftar">Daftar</a>
            </div>
        </footer>
    </main>

    <script>
        // Smooth scroll for in-page anchors
        document.querySelectorAll('a[href^="#"]').forEach(link => {
            link.addEventListener('click', (e) => {
                const targetId = link.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    window.scrollTo({
                        top: target.offsetTop - 60,
                        behavior: 'smooth'
                    });
                }
            });
        });

        // Structured data for SEO
        const orgSchema = {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            name: 'UKMI Polmed',
            url: './',
            logo: 'logo-ukmi.png',
            sameAs: [
                'https://instagram.com/ukmipolmed',
                'https://forms.gle/e4e6egXHiRnyQVPV7'
            ],
            contactPoint: [{
                '@type': 'ContactPoint',
                contactType: 'student affairs',
                url: 'https://instagram.com/ukmipolmed'
            }]
        };
        const ld = document.createElement('script');
        ld.type = 'application/ld+json';
        ld.textContent = JSON.stringify(orgSchema);
        document.head.appendChild(ld);

        // Mobile menu toggle
        const nav = document.getElementById('site-nav');
        const toggle = document.querySelector('.menu-toggle');
        const actions = document.getElementById('nav-actions');

        if (toggle && nav && actions) {
            toggle.addEventListener('click', () => {
                const isOpen = nav.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', String(isOpen));
            });
        }

        // Show header when scrolling up
        const headerEl = document.querySelector('.header');
        let lastY = window.scrollY;
        window.addEventListener('scroll', () => {
            if (!headerEl) return;
            const currentY = window.scrollY;
            const scrollingUp = currentY < lastY;
            if (scrollingUp || currentY < 10) {
                headerEl.classList.remove('hide');
            } else {
                headerEl.classList.add('hide');
            }
            lastY = currentY;
        });
    </script>
</body>
</html>