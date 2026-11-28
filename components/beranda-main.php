    <main>
        <div class="hero-split">
        <div class="hero-col">
        <section class="hero">
            <div class="hero-title-wrap hero-title-overlay" id="hero-title-overlay">
                <h2 class="section-title">Portofolio UKMI</h2>
                <p class="hero-subtitle">Geser untuk melihat kolase lain.</p>
            </div>
            <div class="hero-rotator">
                <div class="hero-carousel" id="hero-carousel" aria-label="Header card presentation">
                    <?php foreach ($heroCards as $card): ?>
                        <?php if (($card['type'] ?? '') === 'default'): ?>
                            <article class="hero-slide hero-slide-default">
                                <div class="hero-copy">
                                    <h1>Unit Kegiatan Mahasiswa Islam POLMED <?php echo e($divisionYearLabel); ?></h1>
                                    <p class="lead">Kamu mahasiswa baru Politeknik Negeri Medan? Beruntung, Kamu bisa temukan informasi open recruitment yang terdiri dari <?php echo e($divisionCount); ?> divisi, agenda kegiatan, kelas skill, jalur daftar tercepat, dan dokumentasi UKMI POLMED di sini.</p>
                                    <div class="metrics">
                                        <div class="metric-card">
                                            <strong><?php echo e($divisionCount); ?> divisi</strong>
                                            <span><?php echo e(implode(' · ', $divisionNames)); ?>.</span>
                                        </div>
                                        <div class="metric-card">
                                            <strong>Terbuka</strong>
                                            <span>Untuk semua jurusan & angkatan baru.</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- visual removed from default hero card as requested -->
                            </article>
                        <?php else: ?>
                            <article class="hero-slide hero-slide-collage">
                                <div class="hero-collage">
                                    <div class="hero-visual-frame<?php echo (($card['type'] ?? 'image') === 'embed') ? ' hero-embed' : ''; ?>">
                                        <?php if (($card['type'] ?? 'image') === 'embed'): ?>
                                            <?php echo $card['content']; ?>
                                        <?php else: ?>
                                            <img src="<?php echo e($card['content']); ?>" alt="<?php echo e($card['alt'] !== '' ? $card['alt'] : ($card['title'] ?? 'Visual kegiatan Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan')); ?>" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                    </div>
                                    <div class="hero-visual-copy">
                                        <?php if (!empty($card['title'])): ?><strong><?php echo e($card['title']); ?></strong><?php endif; ?>
                                        <?php if (!empty($card['caption'])): ?><p><?php echo e($card['caption']); ?></p><?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="button" class="hero-control hero-control-left" id="hero-prev" aria-label="Kartu sebelumnya">‹</button>
            <button type="button" class="hero-control hero-control-right" id="hero-next" aria-label="Kartu berikutnya">›</button>
        </section>
        <script>
        (() => {
            const ov = document.getElementById('hero-title-overlay');
            if (!ov) return;
            let done = false;
            const hide = () => {
                if (done) return;
                done = true;
                ov.classList.add('is-hidden');
                setTimeout(() => { ov.hidden = true; }, 450);
            };
            const timer = setTimeout(hide, 3800);
            ['hero-carousel', 'hero-prev', 'hero-next'].forEach((id) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('pointerdown', () => { clearTimeout(timer); hide(); }, { once: true });
                el.addEventListener('click', () => { clearTimeout(timer); hide(); }, { once: true });
            });
        })();
        </script>
        </div>

        <section id="shortcut">
            <h2 class="section-title">Baca ini..</h2>
            <div class="grid" id="shortcut-grid">
                <div class="card agenda-rotator">
                    <div class="tag agenda-swap" id="agenda-tag"><?php echo e($firstAgenda['tag']); ?></div>
                    <h3 class="agenda-swap" id="agenda-title"><?php echo e($firstAgenda['title']); ?></h3>
                    <p class="agenda-swap" id="agenda-detail"><?php echo e($firstAgenda['detail']); ?></p>
                </div>
                <?php foreach ($shortcutCards as $cardIdx => $shortcutCard): $firstSlide = $shortcutCard['slides'][0]; ?>
                    <div class="card agenda-rotator shortcut-rotator" data-slides="<?php echo e(json_encode($shortcutCard['slides'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
                        <div class="tag agenda-swap shortcut-tag"><?php echo e($firstSlide['tag']); ?></div>
                        <h3 class="agenda-swap shortcut-title"><?php echo e($firstSlide['title']); ?></h3>
                        <p class="agenda-swap shortcut-detail"><?php echo e($firstSlide['detail']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        </div>

        <?php // Disabled sementara: ukmi-container (Kenapa Gabung UKMI) ?>
        <?php if (false): ?>
        <section id="program" class="ukmi-kenapa">
            <div class="ukmi-container">
                <svg class="ornament-tl" viewBox="0 0 280 220" fill="none" aria-hidden="true">
                    <path d="M0,0 L260,0 C220,65 150,45 100,100 C55,155 35,165 0,185 Z" fill="#136F34" />
                    <path d="M0,0 L195,0 C165,45 110,35 75,78 C38,120 18,130 0,142 Z" fill="#4BA847" />
                    <path d="M0,208 C55,195 85,155 130,110 C175,65 230,75 282,0" stroke="#136F34" stroke-width="2.5" />
                </svg>
                <svg class="ornament-tr" viewBox="0 0 280 260" fill="none" aria-hidden="true">
                    <path d="M280,0 L35,0 C75,75 150,85 195,140 C235,185 252,205 280,225 Z" fill="#F5B81C" />
                    <path d="M15,0 C45,88 130,102 180,162 C225,210 248,230 280,248" stroke="#136F34" stroke-width="2.5" />
                    <g fill="#227A3B" transform="translate(165, 38) rotate(25)">
                        <path d="M0,0 Q38,-18 76,0 Q38,18 0,0 Z" />
                        <path d="M18,22 Q56,4 94,22 Q56,40 18,22 Z" />
                        <path d="M36,44 Q74,26 112,44 Q74,62 36,44 Z" />
                    </g>
                </svg>
                <svg class="ornament-bl" viewBox="0 0 320 260" fill="none" aria-hidden="true">
                    <path d="M0,260 L0,35 C65,72 85,138 150,175 C205,208 242,222 280,260 Z" fill="#136F34" />
                    <path d="M0,260 L0,100 C48,128 65,175 115,202 C158,226 185,236 212,260 Z" fill="#5BB543" />
                    <path d="M0,12 C80,55 102,125 172,162 C228,195 265,212 308,260" stroke="#136F34" stroke-width="2.5" />
                    <ellipse cx="135" cy="182" rx="25" ry="12" transform="rotate(-25 135 182)" fill="#F5B81C" />
                </svg>
                <svg class="ornament-br" viewBox="0 0 340 250" fill="none" aria-hidden="true">
                    <path d="M340,250 L340,35 C265,62 238,138 162,175 C105,202 68,222 30,250 Z" fill="#5BB543" />
                    <path d="M340,250 L340,110 C285,132 260,185 205,208 C162,226 132,236 105,250 Z" fill="#136F34" />
                    <path d="M340,12 C252,45 220,122 140,160 C82,188 45,212 2,250" stroke="#F5B81C" stroke-width="3" />
                </svg>

                <header class="ukmi-header">
                    <h2 class="ukmi-title">KENAPA GABUNG UKMI?</h2>
                    <div class="ukmi-divider"></div>
                    <p class="ukmi-subtitle">Ruang bertumbuh bareng di kampus</p>
                </header>

                <div class="ukmi-grid">
                    <article class="ukmi-card">
                        <div class="card-top">
                            <div class="icon-circle">
                                <svg viewBox="0 0 40 32" fill="#136F34" aria-hidden="true">
                                    <circle cx="20" cy="10" r="6" />
                                    <path d="M9,29 C9,21 31,21 31,29 Z" />
                                    <circle cx="7" cy="14" r="4.5" fill="#4BA847" />
                                    <path d="M0,29 C0,23 12,23 12,29 Z" fill="#4BA847" />
                                    <circle cx="33" cy="14" r="4.5" fill="#4BA847" />
                                    <path d="M28,29 C28,23 40,23 40,29 Z" fill="#4BA847" />
                                </svg>
                            </div>
                            <span class="badge-num">01</span>
                        </div>
                        <h3 class="card-title">Teman seperjuangan</h3>
                        <p class="card-desc">Lingkungan suportif untuk kuliah, organisasi, dan tumbuh bareng di kampus.</p>
                    </article>

                    <article class="ukmi-card accent-yellow">
                        <div class="card-top">
                            <div class="icon-circle">
                                <svg viewBox="0 0 36 34" fill="none" stroke="#136F34" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="2" y="9" width="32" height="22" rx="3" fill="#136F34" />
                                    <path d="M12,9 L12,5 C12,3 14,2 16,2 L20,2 C22,2 24,3 24,5 L24,9" />
                                    <line x1="2" y1="18" x2="34" y2="18" stroke="#F5B81C" stroke-width="2.5" />
                                    <circle cx="18" cy="18" r="2.5" fill="#F5B81C" stroke="none" />
                                </svg>
                            </div>
                            <span class="badge-num">02</span>
                        </div>
                        <h3 class="card-title">Portofolio nyata</h3>
                        <p class="card-desc">Handle event, media sosial, fundraising, dan project digital yang bisa kamu pajang.</p>
                    </article>

                    <article class="ukmi-card accent-yellow">
                        <div class="card-top">
                            <div class="icon-circle">
                                <svg viewBox="0 0 36 36" aria-hidden="true">
                                    <line x1="18" y1="18" x2="6" y2="8" stroke="#136F34" stroke-width="2.5" />
                                    <line x1="18" y1="18" x2="31" y2="9" stroke="#136F34" stroke-width="2.5" />
                                    <line x1="18" y1="18" x2="8" y2="30" stroke="#136F34" stroke-width="2.5" />
                                    <line x1="18" y1="18" x2="30" y2="29" stroke="#136F34" stroke-width="2.5" />
                                    <circle cx="18" cy="18" r="6.5" fill="#136F34" />
                                    <circle cx="6" cy="8" r="4" fill="#F5B81C" />
                                    <circle cx="31" cy="9" r="4" fill="#4BA847" />
                                    <circle cx="8" cy="30" r="4" fill="#4BA847" />
                                    <circle cx="30" cy="29" r="4" fill="#F5B81C" />
                                </svg>
                            </div>
                            <span class="badge-num">03</span>
                        </div>
                        <h3 class="card-title">Jaringan luas</h3>
                        <p class="card-desc">Terhubung dengan alumni, komunitas dakwah kampus, dan relasi eksternal.</p>
                    </article>

                    <article class="ukmi-card">
                        <div class="card-top">
                            <div class="icon-circle">
                                <svg viewBox="0 0 38 28" aria-hidden="true">
                                    <polygon points="19,3 2,11 19,19 36,11" fill="#136F34" />
                                    <path d="M9,15 L9,24 C9,27 29,27 29,24 L29,15" fill="#4BA847" />
                                    <line x1="34" y1="12" x2="34" y2="23" stroke="#F5B81C" stroke-width="2.5" stroke-linecap="round" />
                                    <circle cx="34" cy="24" r="2" fill="#F5B81C" />
                                </svg>
                            </div>
                            <span class="badge-num">04</span>
                        </div>
                        <h3 class="card-title">Mentoring karier</h3>
                        <p class="card-desc">Belajar langsung dari kakak tingkat yang sudah magang, studi lanjut, atau kerja.</p>
                    </article>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section id="divisi">
            <h2 class="section-title">Divisi kepengurusan <?php echo e($divisionYearLabel); ?></h2>
            <div class="grid">
                <?php foreach ($divisions as $divIdx => $div):
                    $avatar = s3_to_relative_url($div['avatar'] ?? '');
                    $progCount = is_array($div['programs'] ?? null) ? count($div['programs']) : 0;
                ?>
                    <button type="button" class="card division-card division-card-btn" data-division-index="<?php echo (int) $divIdx; ?>" aria-haspopup="dialog" aria-controls="division-program-modal">
                        <?php if ($avatar !== ''): ?>
                            <div class="division-avatar"><img src="<?php echo e($avatar); ?>" alt="Avatar divisi <?php echo e($div['name']); ?>" loading="lazy" decoding="async"></div>
                        <?php else: ?>
                            <div class="division-avatar division-avatar-fallback" aria-hidden="true"><?php echo e(function_exists('mb_substr') ? mb_substr($div['name'], 0, 1) : substr($div['name'], 0, 1)); ?></div>
                        <?php endif; ?>
                        <h3><?php echo e($div['name']); ?></h3>
                        <p><?php echo e($div['description']); ?></p>
                        <span class="division-card-hint"><?php echo $progCount > 0 ? 'Lihat ' . (int) $progCount . ' program kerja →' : 'Lihat program kerja →'; ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="dpm-overlay" id="division-program-modal" role="presentation" hidden>
            <div class="dpm-dialog" role="dialog" aria-modal="true" aria-labelledby="dpm-title">
                <div class="dpm-header">
                    <div class="dpm-header-info">
                        <div class="division-avatar dpm-avatar" id="dpm-avatar" aria-hidden="true"></div>
                        <div>
                            <h3 class="dpm-title" id="dpm-title">Program Kerja Divisi</h3>
                            <p class="dpm-desc" id="dpm-desc"></p>
                        </div>
                    </div>
                    <button type="button" class="dpm-close" id="dpm-close" aria-label="Tutup modal">&times;</button>
                </div>
                <div class="dpm-body" id="dpm-body"></div>
                <div class="dpm-footer">
                    <button type="button" class="btn ghost" id="dpm-close-footer">Tutup</button>
                </div>
            </div>
        </div>

        <section id="daftar">
            <h2 class="section-title">Jalur cepat daftar</h2>
            <p class="lead">Pilih cara paling nyaman buat kamu. Kalau sudah punya link resmi, silahkan jalankan dengan baik.</p>
            <div class="steps">
                <div class="step">
                    <strong>1. Ikuti mentoring mingguan</strong>
                    <p>Gabung sesi mentoring, amati bagaimana UKMI berjalan, dan terlibat secara bertahap.</p>
                </div>
                <div class="step">
                    <strong>2. Tunggu proses seleksi</strong>
                    <p>Panitia akan memantau peserta mentoring; jika lolos, kamu akan dihubungi dan diundang untuk wawancara.</p>
                </div>
                <div class="step">
                    <strong>3. Isi form sebelum wawancara</strong>
                    <p>Sebelum wawancara kemungkinan diminta mengisi form data diri, divisi yang diincar, dan informasi pendukung lainnya.</p>
                </div>
            </div>

            <div class="cta-banner">
                <strong>Siap gas? Klik sekali untuk daftar.</strong>
                <div class="cta-row">
                    <a class="btn primary" id="cta-daftar-primary" href="<?php echo e($registration['url']); ?>" target="_blank" rel="noopener" data-insight="cta_daftar_banner">Buka form pendaftaran</a>
                    <a class="btn ghost" href="#footer-instagram" data-scroll-cta="footer-instagram" data-scroll-fallback="#footer-instagram" data-insight="cta_dm_ig">Tanya via DM IG</a>
                    <a class="btn ghost" href="#dokumentasi" data-scroll-cta="doc-arsip-ig" data-scroll-fallback="#dokumentasi" data-insight="cta_dokumentasi">Lihat dokumentasi kegiatan</a>
                    <a class="btn ghost" href="https://krasyid822.github.io/ukmipolmed/what-they-said/" target="_blank" rel="noopener" data-insight="cta_testimoni">Testimoni</a>
                </div>
                <!-- <small style="color: var(--muted);">Form utama mengarah ke Google Forms mentoring UKMI. Link cadangan tersedia di halaman testimoni.</small> -->
            </div>
        </section>

        <section id="dokumentasi">
            <h2 class="section-title">Dokumentasi kegiatan</h2>
            <?php
            $docAutoScroll = !empty($docsSettings['auto_scroll']);
            $docSpeedPx = (int) ($docScrollSpeedPx ?? 52);
            ?>
            <?php if ($docAutoScroll): ?>
            <div class="docs-marquee" data-auto-scroll="1" data-speed="<?php echo e($docSpeedPx); ?>">
                <div class="docs-track">
            <?php else: ?>
            <div class="grid">
            <?php endif; ?>
                <?php foreach ($docs as $idx => $doc):
                    $docThumb = doc_thumbnail_url($doc);
                    $docUrl = trim((string) ($doc['url'] ?? ''));
                    $docAnchorId = $docUrl === 'https://krasyid822.github.io/ukmipolmed/ukmipolmed-ig/'
                        ? 'doc-arsip-ig'
                        : ($docUrl === 'https://krasyid822.github.io/ukmipolmed/dokumentasi-ppi-2024/'
                            ? 'doc-ppi-2024'
                            : 'doc-' . (int) $idx);
                ?>
                    <?php if ($docUrl !== ''): ?>
                    <a class="card doc-card" id="<?php echo e($docAnchorId); ?>" href="<?php echo e($docUrl); ?>" target="_blank" rel="noopener" data-insight="doc_<?php echo e($idx); ?>" aria-label="Buka dokumentasi: <?php echo e($doc['title']); ?>">
                        <?php if ($docThumb !== ''): ?>
                            <img class="doc-thumb" src="<?php echo e($docThumb); ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <div class="tag"><?php echo e($doc['tag']); ?></div>
                        <h3><?php echo e($doc['title']); ?></h3>
                        <p><?php echo e($doc['description']); ?></p>
                    </a>
                    <?php else: ?>
                    <article class="card doc-card doc-card--static" id="<?php echo e($docAnchorId); ?>">
                        <?php if ($docThumb !== ''): ?>
                            <img class="doc-thumb" src="<?php echo e($docThumb); ?>" alt="" aria-hidden="true" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <div class="tag"><?php echo e($doc['tag']); ?></div>
                        <h3><?php echo e($doc['title']); ?></h3>
                        <p><?php echo e($doc['description']); ?></p>
                    </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php if ($docAutoScroll): ?>
                </div>
            </div>
            <?php else: ?>
            </div>
            <?php endif; ?>
        </section>

        <footer>
            <div>UKMI Politeknik Negeri Medan · <?php echo e(($config['yel_yel'] ?? '') !== '' ? $config['yel_yel'] : 'Bersama bertumbuh dalam kebaikan.'); ?></div>
            <div class="footer-now" id="footer-sholat"
                 aria-label="Jadwal waktu sholat Masjid Baitul Ilmi POLMED, metode Kemenag/NU">
                <span class="fs-list">
                    <span class="fs-item" data-prayer="fajr"><span class="fs-name">Subuh</span> <span class="fs-time" data-time="fajr">--:--</span></span>
                    <span class="fs-item" data-prayer="dhuhr"><span class="fs-name">Dzuhur</span> <span class="fs-time" data-time="dhuhr">--:--</span></span>
                    <span class="fs-item" data-prayer="asr"><span class="fs-name">Ashar</span> <span class="fs-time" data-time="asr">--:--</span></span>
                    <span class="fs-item" data-prayer="maghrib"><span class="fs-name">Maghrib</span> <span class="fs-time" data-time="maghrib">--:--</span></span>
                    <span class="fs-item" data-prayer="isha"><span class="fs-name">Isya</span> <span class="fs-time" data-time="isha">--:--</span></span>
                </span>
                <span class="fs-next" id="fs-next" hidden></span>
            </div>
            <nav aria-label="Media sosial UKMI POLMED" style="margin-top:8px;">
                <div class="socials">
                    <a id="footer-instagram" href="https://www.instagram.com/ukmipolmed/" target="_blank" rel="noopener"><img alt="Ikon Instagram Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="/foto?key=ukmipolmed%2Fuploads%2F460e25a7e6dce46da1c7467a4f89fa575e36b598.webp" style="width: 24px; height: 24px; margin-right: 5px;">Instagram</a>
                    <a href="https://www.youtube.com/c/UKMIPolmedTV" target="_blank" rel="noopener"><img alt="Ikon YouTube Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="/foto?key=ukmipolmed%2Fuploads%2F17342d1f7b206c79f21e40eb6411c86ed82c8d5e.svg" style="width: 24px; height: 24px; margin-right: 5px;">YouTube</a>
                    <a href="https://web.facebook.com/ukmipolmedmedan/" target="_blank" rel="noopener"><img alt="Ikon Facebook Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 36 36' height='40' width='40'%3E%3Cpath fill='%230866FF' d='M20.181 35.87C29.094 34.791 36 27.202 36 18c0-9.941-8.059-18-18-18S0 8.059 0 18c0 8.442 5.811 15.526 13.652 17.471L14 34h5.5l.681 1.87Z'/%3E%3Cpath fill='%23FFFFFF' d='M13.651 35.471v-11.97H9.936V18h3.715v-2.37c0-6.127 2.772-8.964 8.784-8.964 1.138 0 3.103.223 3.91.446v4.983c-.425-.043-1.167-.065-2.081-.065-2.952 0-4.09 1.116-4.09 4.025V18h5.883l-1.008 5.5h-4.867v12.37a18.183 18.183 0 0 1-6.53-.399Z'/%3E%3C/svg%3E" style="width: 24px; height: 24px; margin-right: 5px;">Facebook</a>
                    <a href="https://x.com/ukmipolmed" target="_blank" rel="noopener"><img alt="Ikon X atau Twitter Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cg%3E%3Cpath fill='%23000000' d='M21.742 21.75l-7.563-11.179 7.056-8.321h-2.456l-5.691 6.714-4.54-6.714H2.359l7.29 10.776L2.25 21.75h2.456l6.035-7.118 4.818 7.118h6.191-.008zM7.739 3.818L18.81 20.182h-2.447L5.29 3.818h2.447z'/%3E%3C/g%3E%3C/svg%3E" style="width: 24px; height: 24px; margin-right: 5px;">X (Twitter)</a>
                    <a href="https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan" target="_blank" rel="noopener"><img alt="Ikon LinkedIn Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 34 34'%3E%3Cpath fill='%230a66c2' d='M34 2.5v29a2.5 2.5 0 0 1-2.5 2.5h-29A2.5 2.5 0 0 1 0 31.5v-29A2.5 2.5 0 0 1 2.5 0h29A2.5 2.5 0 0 1 34 2.5M10 13H5v16h5zm.45-5.5a2.88 2.88 0 0 0-2.86-2.9H7.5a2.9 2.9 0 0 0 0 5.8 2.88 2.88 0 0 0 2.95-2.81zM29 19.28c0-4.81-3.06-6.68-6.1-6.68a5.7 5.7 0 0 0-5.06 2.58h-.14V13H13v16h5v-8.51a3.32 3.32 0 0 1 3-3.58h.19c1.59 0 2.77 1 2.77 3.52V29h5z'/%3E%3C/svg%3E" style="width: 24px; height: 24px; margin-right: 5px;">LinkedIn</a>
                </div>
            </nav>
            <nav aria-label="Navigasi situs" style="margin-top:10px;">
                <div class="socials">
                    <a href="#daftar" data-scroll-cta="cta-daftar-primary" data-scroll-fallback="#daftar">Daftar</a>
                    <a href="/blog">Blog</a>
                    <a href="/faq">FAQ</a>
                    <a href="/status">System Status</a>
                </div>
            </nav>
            <nav aria-label="Sumber terkait" style="margin-top:10px;">
                <p style="font-size:13px; color:var(--muted); margin:0 0 6px;">Sumber & referensi terkait:</p>
                <div class="socials">
                    <a href="https://polmed.ac.id" target="_blank" rel="noopener"><img alt="Logo Politeknik Negeri Medan" src="/foto?key=ukmipolmed%2Fuploads%2Fcc5ff12bb15725123611ff26ff4c7a8e733d79cb.webp" style="width: 24px; height: 24px; margin-right: 5px;">Politeknik Negeri Medan</a>
                   <!-- <a href="https://ldii.or.id" target="_blank" rel="noopener"><img alt="Logo Lembaga Dakwah Islam Indonesia" src="/foto?key=ukmipolmed%2Fuploads%2F022395e1d85db34370ea4e49065537d777917bc6.webp" style="width: 24px; height: 24px; margin-right: 5px;">LDII</a> -->
                    <a href="https://masjidbaitulilmi.polmed.ac.id/" target="_blank" rel="noopener"><img alt="Logo Masjid Baitul Ilmi Politeknik Negeri Medan" src="/foto?key=ukmipolmed%2Fuploads%2F9502a6d477496e78d3a72eacdb15ae5b3e1f66bf.webp" style="width: 24px; height: 24px; margin-right: 5px;">Masjid Baitul Ilmi</a>
                    <a href="https://kemenag.go.id" target="_blank" rel="noopener"><img alt="Logo Kementerian Agama Republik Indonesia" src="/foto?key=ukmipolmed%2Fuploads%2Fd82408ad770460776fcf2e54f292ca311139136c.webp" style="width: 24px; height: 24px; margin-right: 5px;">Kemenag RI</a>
                    <a href="https://nu.or.id" target="_blank" rel="noopener"><img alt="Logo Nahdlatul Ulama" src="/foto?key=ukmipolmed%2Fuploads%2F24fff8245dd2eefbc5c45301cf2a2a4e0027ffc5.webp" style="width: 24px; height: 24px; margin-right: 5px;">NU Online</a>
                    <a href="https://muhammadiyah.or.id/" target="_blank" rel="noopener"><img alt="Logo Muhammadiyah" src="/foto?key=ukmipolmed%2Fuploads%2Fb225a66fbb3d679f7033ffdad9c593143f88ba18.webp" style="width: 24px; height: 24px; margin-right: 5px;">Muhammadiyah</a>
                    <a href="https://id.wikipedia.org/wiki/Ikhwanul_Muslimin" target="_blank" rel="noopener"><img alt="Ilustrasi referensi Ikhwanul Muslimin dari Wikipedia" src="/foto?key=ukmipolmed%2Fuploads%2Fd136a742ade3e82d924ec4d771cc95c0cfc5e5e7.webp" style="width: 24px; height: 24px; margin-right: 5px;">Wiki: Ikhwanul Muslimin</a>

                    <!-- <a href="" target="_blank" rel="noopener"><img src="" style="width: 24px; height: 24px; margin-right: 5px;"></a> -->
                </div>
            </nav>
            <div class="footer-theme-wrap">
                <button type="button" class="theme-switch" id="ukmi-theme-toggle" role="switch" aria-checked="false" aria-label="Ganti tema tampilan">
                    <span class="theme-switch-track" aria-hidden="true">
                        <span class="theme-switch-thumb">🌙</span>
                    </span>
                    <span class="theme-switch-label">Tema Gelap</span>
                </button>
            </div>
        </footer>
    </main>
