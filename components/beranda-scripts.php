    <script>
        // Scroll-to-CTA helper, also reused by header.php.
        window.ukmiScrollToCta = function (targetId, sectionId) {
            const target = document.getElementById(targetId);
            if (!target) return false;
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const header = document.querySelector('.header');
            const offset = (header ? header.offsetHeight : 0) + 16;
            const top = target.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top: Math.max(0, top), behavior: reduced ? 'auto' : 'smooth' });

            if (!reduced) {
                setTimeout(() => {
                    target.classList.remove('cta-attention');
                    void target.offsetWidth;
                    target.classList.add('cta-attention');
                    setTimeout(() => target.classList.remove('cta-attention'), 2000);
                }, 600);
            }

            if (sectionId) {
                history.replaceState(null, '', '#' + sectionId);
            }
            target.setAttribute('tabindex', '-1');
            try {
                target.focus({ preventScroll: true });
            } catch (_) {
                target.focus();
            }
            return true;
        };

        // Delegated triggers for duplicate external links (nav header handled in header.php).
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-scroll-cta]');
            if (!trigger || trigger.closest('#nav-actions')) return;
            const targetId = trigger.getAttribute('data-scroll-cta');
            const fallback = trigger.getAttribute('data-scroll-fallback') || '';
            if (window.ukmiScrollToCta(targetId, fallback.replace(/^#/, ''))) {
                event.preventDefault();
                return;
            }
            // Target card removed by admin: fall back to its section, else keep default navigation.
            const fallbackTarget = fallback ? document.querySelector(fallback) : null;
            if (fallbackTarget) {
                event.preventDefault();
                const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const header = document.querySelector('.header');
                const offset = (header ? header.offsetHeight : 0) + 16;
                const top = fallbackTarget.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: Math.max(0, top), behavior: reduced ? 'auto' : 'smooth' });
                history.replaceState(null, '', fallback);
            }
        });

        // Pulse the canonical CTA when landing with a hash (e.g. nav from /blog).
        window.addEventListener('load', () => {
            const hashMap = { '#daftar': 'cta-daftar-primary', '#dokumentasi': 'doc-arsip-ig' };
            const pulseId = hashMap[window.location.hash];
            if (!pulseId) return;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const pulseTarget = document.getElementById(pulseId);
            if (!pulseTarget) return;
            setTimeout(() => {
                pulseTarget.classList.remove('cta-attention');
                void pulseTarget.offsetWidth;
                pulseTarget.classList.add('cta-attention');
                setTimeout(() => pulseTarget.classList.remove('cta-attention'), 2000);
            }, 900);
        });

        // Smooth scroll for in-page anchors (passive listener for INP)
        document.querySelectorAll('a[href^="#"]').forEach(link => {
            if (link.hasAttribute('data-scroll-cta')) return;
            link.addEventListener('click', (e) => {
                const targetId = link.getAttribute('href');
                const target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Structured data for SEO
        const orgSchema = {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            name: 'Unit Kegiatan Mahasiswa Islam Politeknik Negeri Medan',
            alternateName: 'UKMI Politeknik Negeri Medan',
            url: <?php echo json_encode($homeUrl, JSON_UNESCAPED_SLASHES); ?>,
            logo: <?php echo json_encode($logoAbsoluteUrl, JSON_UNESCAPED_SLASHES); ?>,
            sameAs: [
                'https://www.instagram.com/ukmipolmed/',
                'https://www.youtube.com/c/UKMIPolmedTV',
                'https://web.facebook.com/ukmipolmedmedan/',
                'https://x.com/ukmipolmed',
                'https://www.linkedin.com/company/unit-kegiatan-mahasiswa-islam-ukmi-politeknik-negeri-medan',
                'https://forms.gle/e4e6egXHiRnyQVPV7'
            ],
            contactPoint: [{
                '@type': 'ContactPoint',
                contactType: 'student affairs',
                url: 'https://www.instagram.com/ukmipolmed/'
            }],
            parentOrganization: {
                '@type': 'CollegeOrUniversity',
                name: 'Politeknik Negeri Medan',
                url: 'https://polmed.ac.id'
            }
        };
        const ld = document.createElement('script');
        ld.type = 'application/ld+json';
        ld.textContent = JSON.stringify(orgSchema);
        document.head.appendChild(ld);

        // Rotasi agenda pada kartu shortcut (satu kartu, bergantian jika lebih dari satu entri).
        const agendas = <?php echo json_encode($agendas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const tagEl = document.getElementById('agenda-tag');
        const titleEl = document.getElementById('agenda-title');
        const detailEl = document.getElementById('agenda-detail');

        let agendaIndex = 0;
        function swapAgenda(next) {
            if (!tagEl || !titleEl || !detailEl) return;
            sendInsight('agenda_view');
            [tagEl, titleEl, detailEl].forEach(el => el.classList.add('flipping'));
            setTimeout(() => {
                tagEl.textContent = next.tag || '';
                titleEl.textContent = next.title || '';
                detailEl.textContent = next.detail || '';
                [tagEl, titleEl, detailEl].forEach(el => el.classList.remove('flipping'));
            }, 230);
        }

        if (agendas.length > 1) {
            setInterval(() => {
                agendaIndex = (agendaIndex + 1) % agendas.length;
                swapAgenda(agendas[agendaIndex]);
            }, 5200);
        }

        // Thumbnail dokumentasi dari layanan eksternal: hilangkan gambar jika gagal dimuat.
        document.querySelectorAll('.doc-thumb').forEach((img) => {
            img.addEventListener('error', () => img.remove(), { once: true });
        });

        // Rotasi per kartu "Baca ini.." yang dibuat admin. Setiap kartu punya slide sendiri.
        document.querySelectorAll('.shortcut-rotator').forEach((card, cardIndex) => {
            let slides = [];
            try {
                slides = JSON.parse(card.dataset.slides || '[]');
            } catch (_) {
                slides = [];
            }
            if (!Array.isArray(slides) || slides.length < 2) return;
            const cardTag = card.querySelector('.shortcut-tag');
            const cardTitle = card.querySelector('.shortcut-title');
            const cardDetail = card.querySelector('.shortcut-detail');
            if (!cardTag || !cardTitle || !cardDetail) return;
            let slideIndex = 0;
            function swapSlide(next) {
                [cardTag, cardTitle, cardDetail].forEach(el => el.classList.add('flipping'));
                setTimeout(() => {
                    cardTag.textContent = next.tag || '';
                    cardTitle.textContent = next.title || '';
                    cardDetail.textContent = next.detail || '';
                    [cardTag, cardTitle, cardDetail].forEach(el => el.classList.remove('flipping'));
                }, 230);
            }
            setInterval(() => {
                slideIndex = (slideIndex + 1) % slides.length;
                swapSlide(slides[slideIndex]);
            }, 5200 + (cardIndex * 900));
        });

        const heroCarousel = document.getElementById('hero-carousel');
        const heroPrev = document.getElementById('hero-prev');
        const heroNext = document.getElementById('hero-next');
        const heroEl = document.querySelector('.hero');

        function updateHeroNav() {
            if (!heroCarousel) return;
            const maxScroll = heroCarousel.scrollWidth - heroCarousel.clientWidth;
            const current = Math.max(0, heroCarousel.scrollLeft);
            heroPrev && (heroPrev.disabled = current <= 4);
            heroNext && (heroNext.disabled = current >= (maxScroll - 4));
        }

        function scrollHero(direction) {
            if (!heroCarousel) return;
            const distance = Math.max(heroCarousel.clientWidth, 280);
            heroCarousel.scrollBy({ left: direction * distance, behavior: 'smooth' });
        }

        // Mark the slide closest to the viewport centre so the partially
        // visible neighbour slides can be rendered as frosted glass.
        function updateHeroActive() {
            if (!heroCarousel) return;
            const slides = heroCarousel.querySelectorAll('.hero-slide');
            if (slides.length < 2) return;
            const rect = heroCarousel.getBoundingClientRect();
            const centerX = rect.left + (rect.width / 2);
            let active = slides[0];
            let minDist = Infinity;
            slides.forEach(slide => {
                const r = slide.getBoundingClientRect();
                const mid = (r.left + r.right) / 2;
                const d = Math.abs(mid - centerX);
                if (d < minDist) { minDist = d; active = slide; }
            });
            slides.forEach(slide => slide.classList.toggle('is-active', slide === active));
            heroCarousel.classList.add('has-glass');
        }

        let heroScrollIdleTimer = 0;
        function markHeroScrolling() {
            if (!heroCarousel) return;
            heroCarousel.classList.add('is-scrolling');
            window.clearTimeout(heroScrollIdleTimer);
            heroScrollIdleTimer = window.setTimeout(() => {
                heroCarousel.classList.remove('is-scrolling');
            }, 180);
        }

        heroPrev?.addEventListener('click', () => scrollHero(-1));
        heroNext?.addEventListener('click', () => scrollHero(1));
        heroCarousel?.addEventListener('scroll', () => {
            markHeroScrolling();
            window.requestAnimationFrame(() => { updateHeroNav(); updateHeroActive(); updateHeroHeight(); });
        }, { passive: true });
        window.addEventListener('resize', () => { updateHeroNav(); updateHeroActive(); adaptHeroFrames(); updateHeroHeight(); });
        updateHeroNav();
        updateHeroActive();
        // set initial hero height to the active slide
        updateHeroHeight();

        // Make hero visual frames adapt to their actual content (image/iframe).
        function adaptHeroFrames() {
            const frames = document.querySelectorAll('.hero-visual-frame');
            if (!frames || frames.length === 0) return;
            frames.forEach(frame => {
                const img = frame.querySelector('img');
                const iframe = frame.querySelector('iframe');

                if (img) {
                    const applyImgRatio = () => {
                        const w = img.naturalWidth || img.width || 16;
                        const h = img.naturalHeight || img.height || 9;
                        if (w && h) {
                            frame.style.setProperty('aspect-ratio', `${w} / ${h}`);
                        }
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'contain';
                        window.requestAnimationFrame(updateHeroHeight);
                    };
                    if (img.complete) applyImgRatio();
                    else img.addEventListener('load', applyImgRatio, { once: true });
                } else if (iframe) {
                    // try to infer aspect from attributes or data-aspect, otherwise fallback to 16/9
                    const aw = parseInt(iframe.getAttribute('width') || iframe.width || '', 10) || 0;
                    const ah = parseInt(iframe.getAttribute('height') || iframe.height || '', 10) || 0;
                    if (aw > 0 && ah > 0) {
                        frame.style.setProperty('aspect-ratio', `${aw} / ${ah}`);
                    } else if (iframe.dataset && iframe.dataset.aspect) {
                        frame.style.setProperty('aspect-ratio', iframe.dataset.aspect);
                    } else {
                        frame.style.setProperty('aspect-ratio', '16 / 9');
                    }
                    iframe.style.width = '100%';
                    iframe.style.height = '100%';
                    iframe.style.display = 'block';
                    window.requestAnimationFrame(updateHeroHeight);
                } else {
                    // no media -> remove explicit ratio to let layout flow
                    frame.style.removeProperty('aspect-ratio');
                    window.requestAnimationFrame(updateHeroHeight);
                }
            });
            window.requestAnimationFrame(() => {
                updateHeroNav();
                updateHeroActive();
                updateHeroHeight();
            });
        }

        // run once and on resize to adapt frames to content
        adaptHeroFrames();
        window.addEventListener('resize', adaptHeroFrames);

        // adjust the .hero section height to match the currently visible slide
        function updateHeroHeight() {
            if (!heroCarousel || !heroEl) return;
            const slides = heroCarousel.querySelectorAll('.hero-slide');
            if (!slides || slides.length === 0) return;
            const carouselRect = heroCarousel.getBoundingClientRect();
            const centerX = carouselRect.left + (carouselRect.width / 2);
            let active = slides[0];
            let minDist = Infinity;
            slides.forEach(slide => {
                const rect = slide.getBoundingClientRect();
                if (rect.left <= centerX && rect.right >= centerX) {
                    active = slide;
                    minDist = 0;
                    return;
                }
                const mid = (rect.left + rect.right) / 2;
                const d = Math.abs(mid - centerX);
                if (d < minDist) { minDist = d; active = slide; }
            });

            const slideHeight = active.offsetHeight || active.getBoundingClientRect().height || 0;
            const style = window.getComputedStyle(heroEl);
            const pt = parseFloat(style.paddingTop) || 0;
            const pb = parseFloat(style.paddingBottom) || 0;
            const desired = Math.round(slideHeight);
            if (desired > 0) {
                heroCarousel.style.height = desired + 'px';
                heroEl.style.minHeight = Math.round(desired + pt + pb) + 'px';
            }
        }

        // Insight tracking for key interactions (deferred to avoid blocking main thread).
        function sendInsight(name) {
            if (!name) return;
            if ('requestIdleCallback' in window) {
                requestIdleCallback(() => {
                    fetch('/status?event=' + encodeURIComponent(name), {
                        method: 'POST',
                        keepalive: true,
                    }).catch(() => {});
                });
            } else {
                setTimeout(() => {
                    fetch('/status?event=' + encodeURIComponent(name), {
                        method: 'POST',
                        keepalive: true,
                    }).catch(() => {});
                }, 100);
            }
        }

        window.addEventListener('load', () => {
            sendInsight('home_view');
            sendInsight('agenda_view');
        });

        document.querySelectorAll('[data-insight]').forEach(el => {
            el.addEventListener('click', () => sendInsight(el.getAttribute('data-insight')));
        });

    </script>
