<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Loading...</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600&display=swap" rel="stylesheet">
	<style>
		:root {
			--bg: #0b0c0f;
			--glass: rgba(0, 0, 0, 0.55);
			--accent: #c5ff6a;
			--muted: #9fb0c7;
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			background: var(--bg);
			color: #f6f8fb;
			font-family: 'Manrope', sans-serif;
			display: flex;
			align-items: center;
			justify-content: center;
			overflow: hidden;
		}

		.loading-screen {
			position: relative;
			width: 100%;
			height: 100vh;
			overflow: hidden;
		}

		.loading-screen::before {
			content: '';
			position: absolute;
			inset: 0;
			background: radial-gradient(circle at 20% 20%, rgba(197, 255, 106, 0.18), transparent 32%),
						radial-gradient(circle at 80% 30%, rgba(255, 255, 255, 0.15), transparent 36%),
						linear-gradient(135deg, rgba(0, 0, 0, 0.62), rgba(0, 0, 0, 0.8));
			z-index: 1;
		}

		.media-wrap {
			position: absolute;
			inset: 0;
			display: grid;
			place-items: center;
			padding: 32px;
			z-index: 2;
		}

		.media {
			position: relative;
			width: min(420px, 70vw);
			filter: drop-shadow(0 10px 28px rgba(0, 0, 0, 0.4));
		}

		.gif-frame {
			display: block;
			width: 100%;
			height: auto;
			border-radius: 18px;
			background: #11111100;
			object-fit: cover;
		}

		.logo {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			width: 40%;
			max-width: 170px;
			filter: drop-shadow(0 6px 14px rgba(0, 0, 0, 0.45));
		}

		.overlay-card {
			position: absolute;
			left: 50%;
			bottom: 8vh;
			transform: translateX(-50%);
			padding: 14px 18px 18px;
			border-radius: 14px;
			background: rgb(51 19 19 / 55%);
			backdrop-filter: blur(12px);
			border: 1px solid rgb(255 0 0);
			width: min(360px, 82vw);
			z-index: 3;
		}

		.status {
			margin: 0 0 10px;
			font-size: 1.05rem;
			letter-spacing: 0.01em;
			font-weight: 600;
		}

		.progress-track {
			position: relative;
			width: 100%;
			height: 10px;
			border-radius: 999px;
			background: rgba(255, 255, 255, 0.12);
			overflow: hidden;
		}

		.progress-bar {
			position: absolute;
			inset: 0;
			width: 38%;
			background: linear-gradient(90deg, rgba(197, 255, 106, 0.3), var(--accent));
			border-radius: inherit;
			animation: pulse 1.6s ease-in-out infinite;
		}

		.hint {
			margin: 10px 0 0;
			font-size: 0.9rem;
			color: var(--muted);
		}

		@keyframes pulse {
			0% { left: -45%; width: 32%; }
			50% { left: 30%; width: 45%; }
			100% { left: 100%; width: 32%; }
		}

		@media (max-width: 640px) {
			.overlay-card { bottom: 6vh; }
			.status { font-size: 1rem; }
			.hint { font-size: 0.85rem; }
		}
	</style>
</head>
<body>
	<div class="loading-screen">
		<div class="media-wrap">
			<div class="media">
				<img class="gif-frame" src="palestine.gif" alt="Loading animation">
				<img class="logo" src="logo-ukmi.png" alt="UKMI Logo">
			</div>
		</div>

		<div class="overlay-card">
			<p class="status">Terjadi kesalahan... !</p>
			<!-- <div class="progress-track">
				<div class="progress-bar" aria-label="Memuat"></div>
			</div> -->
            <?php
            $code = isset($_GET['code']) ? trim($_GET['code'], "\"' ") : '';
            $text = $code === '' ? 'Ngak tau kenapa nih :(' : htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ?>
            <p class="hint"><?php echo $text; ?></p>
            <!--cara penggunaan: ?code="hvebbveivb"-->
		</div>
	</div>
</body>
</html>
