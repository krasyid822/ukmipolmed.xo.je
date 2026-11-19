	<style>
		:root {
			--bg: #0b1020;
			--card: rgba(255, 255, 255, 0.04);
			--stroke: rgba(255, 255, 255, 0.08);
			--text: #e9eef7;
			--muted: #c3cddc;
			--accent: #38bdf8;
			--accent-2: #7cc9ff;
		}

		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
			min-height: 100vh;
			background: radial-gradient(circle at 25% 20%, rgba(56, 189, 248, 0.14), transparent 35%),
				radial-gradient(circle at 80% 0%, rgba(248, 113, 113, 0.12), transparent 32%),
				var(--bg);
			color: var(--text);
			padding: 0 0 40px;
		}

		.container {
			max-width: 1120px;
			margin: 0 auto;
			padding: 32px 18px 0;
			width: 100%;
		}

		h1 { margin: 0 0 8px; font-size: 28px; }
		h1 a { color: inherit; text-decoration: none; }
		h1 a:hover { text-decoration: underline; }

		/* Search form: make button visually match the search input */
		.search-form input[type="search"], .search-form .btn.ghost {
			padding: 8px 12px;
			border-radius: 8px;
			border: 1px solid rgba(255,255,255,0.06);
			background: transparent;
			color: var(--text);
			font-size: 14px;
			height: 40px;
			min-height: 40px;
		}

		.search-form .btn.ghost { display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
		p.lead { margin: 0 0 20px; color: var(--muted); }

			.grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
			gap: 16px;
		}

			.btn {
				display: inline-flex;
				align-items: center;
				gap: 10px;
				padding: 12px 16px;
				border-radius: 12px;
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

			/* Comment UI tweaks */
			.comment-list, .comment-replies { list-style: none; padding: 0; margin: 0 0 14px; }
			.comment-list li { position: relative; text-align: left; margin-bottom:10px; }
			.comment-list .reply-form { margin-top:8px; border-left:2px solid rgba(255,255,255,0.03); padding-left:10px; }
			.comment-list .reply-form .btn { min-height: auto; padding: 8px 12px; font-size: 13px; border-radius: 8px; }
			.comment-list .reply-btn { font-size:13px; color:var(--accent); }
			.delete-comment-form button { display: none; }
			/* Make delete button look like the reply link when visible */
			.delete-btn {
				background: none;
				border: none;
				color: var(--accent);
				font-size: 13px;
				padding: 0 6px;
				cursor: pointer;
			}
			.delete-btn:hover { text-decoration: underline; }

			/* Badge komentar yang disunting admin */
			.comment-edited-badge {
				position: absolute;
				right: 8px;
				bottom: 8px;
				z-index: 1;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border-radius: 50%;
				line-height: 0;
				transition: transform 0.18s ease, filter 0.18s ease;
			}
			.comment-edited-badge img {
				display: block;
				width: 42px;
				height: 42px;
				border-radius: 50%;
			}
			.comment-edited-badge:hover,
			.comment-edited-badge:focus-visible {
				transform: scale(1.06);
				filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.35));
			}
			@media (max-width: 640px) {
				.comment-edited-badge { right: 6px; bottom: 6px; }
				.comment-edited-badge img { width: 36px; height: 36px; }
			}

			/* Keyboard komentar: emoji + stiker GIF (S3) */
			.comment-keyboard { margin-top: 6px; }
			.comment-keyboard .ck-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
			.comment-keyboard .ck-btn {
				display:inline-flex; align-items:center; gap:6px;
				padding:6px 10px; border-radius:8px;
				border:1px solid var(--stroke); background:rgba(255,255,255,0.05);
				color:var(--text); font-size:13px; cursor:pointer;
			}
			.comment-keyboard .ck-btn:hover { border-color:rgba(56,189,248,0.5); }
			.comment-keyboard .ck-btn[aria-expanded="true"] { border-color:var(--accent); color:var(--accent); }
			.comment-keyboard .ck-btn:disabled { opacity:.55; cursor:progress; }
			.comment-keyboard .ck-panel {
				margin-top:8px; padding:8px; border-radius:10px;
				border:1px solid var(--stroke); background:rgba(0,0,0,0.25);
				max-height:190px; overflow:auto;
			}
			.comment-keyboard .ck-emoji-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(38px, 1fr)); gap:2px; }
			.comment-keyboard .ck-emoji {
				font-size:22px; line-height:1; padding:6px; border-radius:8px;
				border:none; background:transparent; cursor:pointer;
			}
			.comment-keyboard .ck-emoji:hover { background:rgba(255,255,255,0.08); }
			.comment-keyboard .ck-hint { color:var(--muted); font-size:12px; line-height:1.5; margin:0 0 8px; }
			.comment-keyboard .ck-embed-input {
				width:100%; min-height:96px; resize:vertical; padding:8px; border-radius:8px;
				border:1px solid rgba(255,255,255,0.06); background:rgba(0,0,0,0.2); color:var(--text);
				font-family:'SFMono-Regular', Consolas, monospace; font-size:12px; line-height:1.5;
			}
			.comment-keyboard .ck-paste {
				margin:0 0 8px; padding:16px 10px; border-radius:10px; text-align:center;
				border:1px dashed rgba(56,189,248,0.5); background:rgba(56,189,248,0.06);
				color:var(--muted); font-size:12px; line-height:1.5; cursor:text; outline:none;
			}
			.comment-keyboard .ck-paste:focus { border-color:var(--accent); color:var(--text); }
			.comment-keyboard .ck-paste-actions { display:flex; flex-wrap:wrap; gap:6px; }
			.comment-keyboard .ck-preview { margin-top:8px; display:none; align-items:flex-start; gap:10px; }
			.comment-keyboard .ck-preview.is-on { display:flex; }
			.comment-keyboard .ck-preview img { width:88px; height:88px; object-fit:contain; border-radius:10px; border:1px solid var(--stroke); background:rgba(255,255,255,0.03); }
			.comment-keyboard .ck-preview .ck-clear {
				background:none; border:none; color:var(--accent); font-size:13px; cursor:pointer; padding:0;
			}
			.comment-sticker-wrap { margin: 0 0 8px; }
			.comment-sticker-wrap img.comment-sticker { max-width:220px; max-height:220px; border-radius:10px; display:block; }
			.comment-embed {
				margin: 0 0 8px; max-width:100%;
				max-height: 360px; overflow-x:hidden; overflow-y:auto;
				overscroll-behavior: contain; border-radius:10px;
			}
			.comment-embed iframe, .comment-embed video, .comment-embed audio { max-width:100%; max-height:340px; }
			.comment-embed svg, .comment-embed canvas { max-width:100%; max-height:340px; }
			.comment-embed img { max-width:100%; max-height:340px; height:auto; object-fit:contain; border-radius:10px; }
			.ck-error { color:#f87171; font-size:12px; margin-top:6px; }

			.btn.ghost { background: rgba(255, 255, 255, 0.07); }
			.btn:hover { transform: translateY(-2px); box-shadow: 0 14px 32px rgba(0,0,0,0.35); }

			.card {
				background: var(--card);
				border: 1px solid var(--stroke);
				border-radius: 14px;
				padding: 16px 18px;
				box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
				transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
			}

			.card:hover {
				transform: translateY(-3px);
				border-color: rgba(56, 189, 248, 0.5);
				box-shadow: 0 22px 46px rgba(0, 0, 0, 0.4);
			}

			.card h3 { margin: 0 0 6px; font-size: 18px; }
			.card .meta { color: var(--muted); font-size: 13px; margin-bottom: 8px; }
			.card .summary { color: var(--text); font-size: 14px; line-height: 1.5; word-break: break-word; overflow-wrap: anywhere; white-space: normal; }

		.article {
			background: var(--card);
			border: 1px solid var(--stroke);
			border-radius: 14px;
			padding: 20px;
			box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
			margin-top: 16px;
		}

		.article h2 { margin: 0 0 8px; font-size: 24px; }
		.article .meta { color: var(--muted); font-size: 13px; margin-bottom: 12px; }
		.article .body { color: var(--text); font-size: 15px; line-height: 1.7; white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
		.article .body * { word-break: break-word; overflow-wrap: anywhere; }
		.article .body pre { background: rgba(255,255,255,0.04); border: 1px solid var(--stroke); padding: 10px; border-radius: 10px; overflow-x: auto; }
		.article .body code { background: rgba(255,255,255,0.04); padding: 2px 4px; border-radius: 6px; border: 1px solid var(--stroke); }
		.article .body p { margin: 0 0 12px; }
		.article .body ul, .article .body ol { margin: 0 0 12px 18px; }
		.embed-box { margin: 14px 0 0; padding: 14px; border: 1px solid var(--stroke); border-radius: 12px; background: rgba(255,255,255,0.03); }
		.embed-box iframe { width: 100%; }
		.instagram-embed-wrapper { max-width: 100%; overflow: hidden; }
		.instagram-embed-wrapper .instagram-media { margin: 0 !important; max-width: 100% !important; width: 100% !important; min-width: 0 !important; }
		.instagram-embed-wrapper iframe.instagram-media { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }
		.gdrive-embed-wrapper { position: relative; width: 100%; overflow: hidden; }
		.gdrive-embed-wrapper iframe { display: block; width: 100% !important; height: 600px !important; border: 0 !important; }
		@media (max-width: 600px) { .gdrive-embed-wrapper iframe { height: 70vh !important; } }

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
			width: min(380px, 70vw);
		}

		.loading-gif {
			display: block;
			width: 100%;
			height: auto;
			border-radius: 16px;
			background: #11111100;
			filter: drop-shadow(0 10px 28px rgba(0, 0, 0, 0.4));
			object-fit: cover;
		}

		.loading-logo {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 42%;
			max-width: 150px;
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
			font-family: 'Manrope', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
