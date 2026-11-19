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

        main {
            position: relative;
            max-width: 1080px;
            margin: 0 auto;
            padding: 26px 24px 80px;
            z-index: 1;
        }

        @media (min-width: 641px) {
            main {
                max-width: none;
                padding-left: 22px;
                padding-right: 22px;
            }

            .hero-rotator {
                max-width: 980px;
                margin-left: auto;
                margin-right: auto;
            }

            .hero-carousel {
                padding-left: clamp(24px, 4vw, 40px);
                padding-right: clamp(24px, 4vw, 40px);
                scroll-padding-left: clamp(24px, 4vw, 40px);
                scroll-padding-right: clamp(24px, 4vw, 40px);
            }

            .hero-slide {
                flex: 0 0 min(900px, calc(100% - clamp(88px, 12vw, 160px)));
                max-width: min(900px, calc(100% - clamp(88px, 12vw, 160px)));
            }
        }

        .hero {
            margin-top: 24px;
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            display: block;
            position: relative;
            transition: min-height 260ms ease;
            /* keep overflow visible so edge controls are not clipped */
            overflow: visible;
        }

        .hero-title-wrap {
            margin-top: 6px;
            margin-bottom: 10px;
        }

        .hero-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .hero-rotator {
            position: relative;
        }

        .hero-carousel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .hero-carousel-head h2 {
            margin: 4px 0 0;
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
            font-size: 18px;
            line-height: 1.25;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(124, 201, 255, 0.12);
            color: var(--accent-2);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .hero-controls {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .hero-control {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 1px solid var(--stroke);
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            font-size: 24px;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, opacity 0.2s ease;
        }

        .hero-control:hover { transform: translateY(-1px); background: rgba(255, 255, 255, 0.14); }
        .hero-control:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }

        /* Edge controls positioned outside the hero box */
        .hero-control.hero-control-left,
        .hero-control.hero-control-right {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 3;
        }

        .hero-control.hero-control-left { left: -10px; }
        .hero-control.hero-control-right { right: -10px; }

        @media (max-width: 640px) {
            .hero-control.hero-control-left { left: 12px; }
            .hero-control.hero-control-right { right: 12px; }
        }

        .hero-carousel {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            gap: 12px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y pinch-zoom;
            overscroll-behavior-x: contain;
            padding-bottom: 2px;
            align-items: flex-start; /* allow slides to size independently */
            cursor: grab;
            transition: height 260ms ease;
            --hero-edge-fade: clamp(14px, 2vw, 24px);
        }

        /* Soft glass fade at the carousel edges so neighbouring slides don't get hard-cut */
        .hero-carousel.has-glass.is-scrolling {
            -webkit-mask-image: linear-gradient(90deg, transparent 0, #000 var(--hero-edge-fade), #000 calc(100% - var(--hero-edge-fade)), transparent 100%);
            mask-image: linear-gradient(90deg, transparent 0, #000 var(--hero-edge-fade), #000 calc(100% - var(--hero-edge-fade)), transparent 100%);
        }

        .hero-carousel:active {
            cursor: grabbing;
        }

        .hero-carousel::-webkit-scrollbar { display: none; }

        .hero-slide {
            scroll-snap-align: start;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.03);
            min-width: 0;
            padding: 18px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            align-items: start; /* don't stretch children to match tallest slide */
            flex: 0 0 100%; /* each slide takes full carousel viewport width */
            max-width: 100%;
            transition: filter 340ms ease, opacity 340ms ease;
        }

        /* Frosted-glass treatment for the partially visible neighbour slides */
        .hero-carousel.has-glass .hero-slide {
            opacity: 0.38;
            filter: blur(8px) saturate(0.8);
        }

        .hero-carousel.has-glass .hero-slide.is-active {
            opacity: 1;
            filter: none;
        }

        @media (prefers-reduced-motion: reduce) {
            .hero-slide { transition: none; }
        }

        .hero-slide-collage {
            grid-template-columns: 1fr;
            align-items: start;
            padding: 14px;
            gap: 10px;
        }

        .hero-copy {
            display: grid;
            gap: 14px;
            align-content: start;
        }

        .hero-copy .lead { margin-bottom: 4px; }

        .hero-copy .metrics { margin-top: 2px; }

        .hero-visual {
            display: grid;
            gap: 12px;
            align-content: start;
        }

        .hero-collage {
            display: grid;
            gap: 8px;
        }

        .hero-visual-frame {
            position: relative;
            /* default fallback ratio; will be overridden by JS per-content */
            aspect-ratio: 16 / 11;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(108, 247, 197, 0.08), rgba(124, 201, 255, 0.08));
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .hero-visual-frame img,
        .hero-visual-frame iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
            object-fit: contain;
            background: transparent;
        }

        /* iframe aspect handled by container; keep iframe layout natural */
        .hero-visual-frame iframe { aspect-ratio: auto; }

        .hero-visual-copy {
            padding: 0 4px;
        }

        .hero-visual-copy strong {
            display: block;
            margin-bottom: 4px;
            font-size: 15px;
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
        }

        .hero-visual-copy p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .hero-default-note {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
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

        .footer-now {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px 10px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(108, 247, 197, 0.12);
            color: var(--accent);
            border: 1px solid rgba(108, 247, 197, 0.2);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            margin: 10px 0 0;
            max-width: 100%;
        }

        .footer-now .fs-list {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px 10px;
        }

        .footer-now .fs-item {
            display: inline-flex;
            align-items: baseline;
            gap: 4px;
            white-space: nowrap;
            color: var(--muted);
            font-weight: 600;
            transition: color .2s ease, background .2s ease;
        }

        .footer-now .fs-item .fs-name {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            opacity: .75;
        }

        .footer-now .fs-item .fs-time {
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }

        .footer-now .fs-item.is-active {
            color: #04160f;
            background: var(--accent);
            border-radius: 999px;
            padding: 2px 9px;
        }

        .footer-now .fs-item.is-active .fs-name,
        .footer-now .fs-item.is-active .fs-time { color: #04160f; opacity: 1; }

        .footer-now .fs-next {
            color: var(--accent);
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            opacity: .9;
        }

        @media (max-width: 640px) {
            .footer-now { width: 100%; justify-content: center; }
            .footer-now .fs-list { justify-content: center; }
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
            font-size: 15px;
            min-height: 48px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            backdrop-filter: blur(6px);
            -webkit-tap-highlight-color: transparent;
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
            margin: 0 0 12px;
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

        .agenda-rotator {
            position: relative;
            perspective: 1200px;
            overflow: hidden;
            isolation: isolate;
        }
        .agenda-swap {
            position: relative;
            z-index: 1;
            transform-origin: center;
            transition: transform 0.55s cubic-bezier(0.4, 0.2, 0.15, 1), opacity 0.35s ease;
        }
        .agenda-swap.flipping {
            transform: rotateY(90deg) translateZ(0);
            opacity: 0;
        }

        .shortcut-rotator { min-height: 120px; }

        .division-card {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        .division-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--stroke);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .division-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .division-avatar-fallback {
            font-size: 22px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
        }

        .doc-card {
            display: block;
            position: relative;
            overflow: hidden;
            isolation: isolate;
            background: rgba(255, 255, 255, 0.055);
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .doc-card:hover,
        .doc-card:focus-visible {
            transform: translateY(-3px);
            border-color: rgba(108, 247, 197, 0.4);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35);
        }
        .doc-card:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }
        .doc-card > img.doc-thumb {
            position: absolute;
            inset: -18px;
            width: calc(100% + 36px);
            height: calc(100% + 36px);
            z-index: -2;
            object-fit: cover;
            object-position: center;
            filter: blur(16px) saturate(1.25) brightness(0.7);
            opacity: 0.6;
            pointer-events: none;
        }
        .doc-card::after {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            background: linear-gradient(145deg, rgba(7, 11, 26, 0.55), rgba(7, 11, 26, 0.82));
            border-radius: inherit;
            pointer-events: none;
        }
        .doc-card > :not(.doc-thumb) { position: relative; z-index: 1; }

        /* Kenapa gabung UKMI — desain dari gabungukmi.txt */
        .ukmi-kenapa { margin-top: 40px; }
        .ukmi-kenapa .ukmi-container {
            container-type: inline-size;
            position: relative;
            width: 100%;
            background-color: #ffffff;
            color: #0E4D22;
            border-radius: clamp(14px, 2.5vmin, 24px);
            padding: clamp(22px, 4cqw, 48px) clamp(16px, 4cqw, 52px);
            overflow: hidden;
            box-shadow: 0 12px 32px rgba(14, 77, 34, 0.18);
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: clamp(14px, 3cqw, 32px);
        }
        .ukmi-kenapa .ornament-tl,
        .ukmi-kenapa .ornament-tr,
        .ukmi-kenapa .ornament-bl,
        .ukmi-kenapa .ornament-br {
            position: absolute;
            pointer-events: none;
            width: clamp(65px, min(18cqw, 160px), 220px);
            height: auto;
            z-index: 0;
        }
        .ukmi-kenapa .ornament-tl { top: 0; left: 0; }
        .ukmi-kenapa .ornament-tr { top: 0; right: 0; }
        .ukmi-kenapa .ornament-bl { bottom: 0; left: 0; }
        .ukmi-kenapa .ornament-br { bottom: 0; right: 0; }
        .ukmi-kenapa .ukmi-header {
            position: relative;
            z-index: 1;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: clamp(4px, 1cqw, 10px);
        }
        .ukmi-kenapa .ukmi-title {
            margin: 0;
            font-weight: 800;
            font-size: clamp(20px, 4.2cqw, 42px);
            color: #0E4D22;
            letter-spacing: 0.5px;
            line-height: 1.15;
        }
        .ukmi-kenapa .ukmi-divider {
            width: clamp(50px, 10cqw, 110px);
            height: clamp(3px, 0.7cqw, 6px);
            border-radius: 3px;
            background-color: #F5B81C;
        }
        .ukmi-kenapa .ukmi-subtitle {
            margin: 0;
            font-weight: 600;
            font-size: clamp(13px, 2cqw, 20px);
            color: #4A6B53;
        }
        .ukmi-kenapa .ukmi-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr;
            gap: clamp(10px, 2cqw, 24px);
            width: 100%;
        }
        @container (min-width: 540px) {
            .ukmi-kenapa .ukmi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @container (min-width: 900px) {
            .ukmi-kenapa .ukmi-grid { grid-template-columns: repeat(4, 1fr); }
        }
        .ukmi-kenapa .ukmi-card {
            background-color: #F7FBF8;
            border: 2px solid #CFE5D6;
            border-radius: clamp(12px, 2vmin, 20px);
            padding: clamp(14px, 2.2cqw, 24px);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(14, 77, 34, 0.06);
            display: flex;
            flex-direction: column;
            gap: clamp(6px, 1.4cqw, 14px);
        }
        .ukmi-kenapa .ukmi-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: clamp(4px, 0.9cqw, 8px);
            background-color: #136F34;
        }
        .ukmi-kenapa .ukmi-card.accent-yellow::before { background-color: #F5B81C; }
        .ukmi-kenapa .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2px;
        }
        .ukmi-kenapa .icon-circle {
            width: clamp(32px, 5cqw, 52px);
            height: clamp(32px, 5cqw, 52px);
            border-radius: 50%;
            background-color: #E1F2E7;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
        }
        .ukmi-kenapa .icon-circle svg { width: 56%; height: 56%; }
        .ukmi-kenapa .ukmi-card.accent-yellow .icon-circle { background-color: #FEF5DC; }
        .ukmi-kenapa .badge-num {
            width: clamp(22px, 3cqw, 32px);
            height: clamp(22px, 3cqw, 32px);
            border-radius: 50%;
            background-color: #F5B81C;
            color: #0E4D22;
            font-weight: 800;
            font-size: clamp(11px, 1.4cqw, 14px);
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .ukmi-kenapa .ukmi-card.accent-yellow .badge-num { background-color: #E1F2E7; }
        .ukmi-kenapa .card-title {
            margin: 0;
            font-weight: 800;
            font-size: clamp(14px, 2cqw, 22px);
            color: #0E4D22;
            line-height: 1.25;
        }
        .ukmi-kenapa .card-desc {
            margin: 0;
            font-weight: 500;
            font-size: clamp(12px, 1.45cqw, 16px);
            color: #2E4A36;
            line-height: 1.45;
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

        #daftar,
        #dokumentasi,
        #cta-daftar-primary,
        #footer-instagram,
        .doc-card {
            scroll-margin-top: 96px;
        }

        .cta-attention {
            animation: cta-ring 900ms ease-out 2;
            outline: 2px solid var(--accent);
            outline-offset: 4px;
            border-radius: inherit;
        }

        @keyframes cta-ring {
            0%   { box-shadow: 0 0 0 0 rgba(108, 247, 197, 0.55); }
            70%  { box-shadow: 0 0 0 16px rgba(108, 247, 197, 0); }
            100% { box-shadow: 0 0 0 0 rgba(108, 247, 197, 0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .cta-attention { animation: none; }
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
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--stroke);
            background: rgba(255, 255, 255, 0.05);
            font-size: 14px;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 1 1 160px;
        }

        @media (max-width: 640px) {
            main { padding: 20px 16px 64px; }
            .hero { padding: 0; }
            .hero-slide { padding: 14px; gap: 14px; grid-template-columns: 1fr; }
            .hero-carousel-head { flex-direction: column; }
            .hero-controls { align-self: flex-start; }
            .hero-control.hero-control-left,
            .hero-control.hero-control-right { display: none; }
            .cta-row { width: 100%; }
            .cta-row .btn { width: 100%; justify-content: center; }
            .metrics { grid-template-columns: 1fr; }
            .socials { flex-wrap: wrap; gap: 10px; }
            .socials a { flex: 1 1 48%; }
        }

        .loading-overlay {
            position: fixed;
            inset: 0;
            display: grid;
            place-items: center;
            background: radial-gradient(circle at 20% 20%, rgba(197, 255, 106, 0.18), transparent 32%),
                        radial-gradient(circle at 80% 30%, rgba(255, 255, 255, 0.15), transparent 36%),
                        linear-gradient(135deg, rgba(0, 0, 0, 0.75), rgba(0, 0, 0, 0.9));
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }

        .loading-overlay.fade-out {
            opacity: 0;
            visibility: hidden;
        }

        .loading-stack {
            position: relative;
            width: min(420px, 72vw);
        }

        .loading-gif {
            display: block;
            width: 100%;
            height: auto;
            border-radius: 18px;
            background: #11111100;
            filter: drop-shadow(0 10px 28px rgba(0, 0, 0, 0.4));
            object-fit: cover;
        }

        .loading-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40%;
            max-width: 170px;
            max-height: 170px;
            aspect-ratio: 1 / 1;
            object-fit: contain;
            filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.5));
        }

        .loading-card {
            margin-top: 12px;
            padding: 14px 16px 16px;
            border-radius: 14px;
            background: rgba(0, 0, 0, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            color: #f6f8fb;
            text-align: center;
            font-family: 'Manrope', 'Space Grotesk', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .loading-card .progress-track {
            margin-top: 10px;
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            overflow: hidden;
        }

        .loading-card .progress-bar {
            position: relative;
            display: block;
            height: 100%;
            width: 38%;
            background: linear-gradient(90deg, rgba(197, 255, 106, 0.3), #c5ff6a);
            border-radius: inherit;
            animation: loading-pulse 1.6s ease-in-out infinite;
        }

        @keyframes loading-pulse {
            0% { left: -45%; width: 32%; }
            50% { left: 30%; width: 45%; }
            100% { left: 100%; width: 32%; }
        }
    </style>
