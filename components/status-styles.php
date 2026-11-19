		<style>
			:root {
				--bg: #050814;
				--panel: rgba(255,255,255,0.06);
				--stroke: rgba(255,255,255,0.12);
				--text: #e8edf7;
				--muted: #c4cede;
				--accent: #6cf7c5;
				--accent-2: #7cc9ff;
				--shadow: 0 18px 45px rgba(0,0,0,0.35);
			}
			* { box-sizing: border-box; }
			body {
				margin: 0;
				min-height: 100vh;
				font-family: 'Manrope','Space Grotesk',system-ui,sans-serif;
				background: radial-gradient(circle at 20% 20%, rgba(108,247,197,0.16), transparent 36%),
					radial-gradient(circle at 82% 10%, rgba(124,201,255,0.22), transparent 40%),
					linear-gradient(140deg, #050814, #0c1224 55%, #090f1f 100%);
				color: var(--text);
			}
			main { max-width: 1180px; margin: 0 auto; padding: 24px 20px 40px; }
			.grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); }
			.panel {
				background: var(--panel);
				border: 1px solid var(--stroke);
				border-radius: 16px;
				padding: 16px;
				box-shadow: var(--shadow);
			}
			.panel h3 { margin: 0 0 10px; font-size: 16px; letter-spacing: 0.2px; }
			.kv { display: grid; gap: 8px; }
			.kv div { display: flex; justify-content: space-between; gap: 10px; font-variant-numeric: tabular-nums; }
			.badge { padding: 4px 8px; border-radius: 10px; background: rgba(255,255,255,0.08); border: 1px solid var(--stroke); font-size: 12px; }
			.pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius: 999px; background: rgba(108,247,197,0.12); color: var(--accent); border:1px solid rgba(108,247,197,0.18); font-weight:700; }
			.cards-2 { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(300px,1fr)); }
			.chart-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(320px,1fr)); }
			.chart-box {
				padding: 16px;
				border-radius: 16px;
				background: linear-gradient(180deg, rgba(255,255,255,0.07), rgba(255,255,255,0.04));
				border: 1px solid var(--stroke);
				box-shadow: var(--shadow);
			}
			.chart-box h3 { margin: 0 0 10px; font-size: 16px; }
			.chart-wrap {
				position: relative;
				width: 100%;
				height: 280px;
				border-radius: 14px;
				background: rgba(255,255,255,0.03);
				overflow: hidden;
			}
			.chart-wrap canvas { width: 100%; height: 100%; display: block; }
			.chart-note { margin-top: 10px; color: var(--muted); font-size: 12px; }
			.table { width: 100%; border-collapse: collapse; }
			.table th, .table td { text-align: left; padding: 8px 6px; border-bottom: 1px solid rgba(255,255,255,0.07); }
			.table th { color: var(--muted); font-weight: 600; }
			small { color: var(--muted); }
			@media (max-width: 640px) {
				main { padding: 18px 14px 32px; }
			}
		</style>
