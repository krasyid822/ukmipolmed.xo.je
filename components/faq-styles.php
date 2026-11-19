    <style>
        :root {
            --bg: #f5f6f8;
            --panel: #ffffff;
            --panel-soft: #f0f4ff;
            --line: #dce3f0;
            --text: #172132;
            --muted: #52627a;
            --accent: #0b5fff;
            --accent-soft: #dbe7ff;
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #eef3ff 0%, var(--bg) 260px);
            color: var(--text);
            min-height: 100vh;
        }

        .wrap {
            max-width: none;
            margin: 0 auto;
            padding: 0;
        }

        .topbar {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 0;
            padding: 14px 16px;
        }

        .topbar a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 0;
            box-shadow: 0 8px 24px rgba(17, 35, 64, 0.08);
            overflow: hidden;
        }

        .layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            min-height: calc(100vh - 60px);
        }

        .sidebar {
            border-right: 1px solid var(--line);
            background: var(--panel-soft);
            padding: 16px;
            min-height: inherit;
            display: flex;
            flex-direction: column;
        }

        .sidebar h1 {
            margin: 0 0 8px;
            font-size: 20px;
        }

        .sidebar p {
            margin: 0 0 14px;
            font-size: 14px;
            color: var(--muted);
            line-height: 1.5;
        }

        .search {
            width: 100%;
            border: 1px solid #c9d6f0;
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 14px;
            margin-bottom: 12px;
            background: #fff;
        }

        .faq-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 0 1 auto;
            min-height: 180px;
            max-height: clamp(220px, calc(100vh - 260px), 760px);
            overflow: auto;
            padding-right: 4px;
        }

        .faq-link {
            display: block;
            border: 1px solid #cfd9f2;
            border-radius: 10px;
            padding: 10px 11px;
            text-decoration: none;
            color: #1e2a3f;
            background: #fff;
            line-height: 1.4;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
            touch-action: manipulation;
        }

        .faq-link.active {
            background: var(--accent-soft);
            border-color: #96b5ff;
            color: #11367f;
            font-weight: 600;
        }

        .faq-link.hidden,
        .faq-item.hidden {
            display: none;
        }

        .content {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .empty {
            padding: 14px;
            border: 1px dashed #bfd0f8;
            border-radius: 10px;
            color: #385188;
            background: #f5f9ff;
            font-size: 14px;
        }

        .faq-item {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            background: #fff;
            display: none;
        }

        .faq-item.active {
            display: block;
        }

        .faq-item h2 {
            margin: 0 0 10px;
            font-size: 23px;
            line-height: 1.28;
        }

        .faq-meta {
            margin-bottom: 10px;
            font-size: 12px;
            color: var(--muted);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .faq-answer {
            color: var(--text);
            line-height: 1.7;
            font-size: 16px;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .sidebar {
                border-right: 0;
                border-bottom: 1px solid var(--line);
                min-height: auto;
            }

            .faq-nav {
                max-height: 240px;
            }

            .faq-item h2 {
                font-size: 20px;
            }
        }
    </style>
