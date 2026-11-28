# COLOR PALETTE & DESIGN TOKENS

**Project / Organization:** Unit Kegiatan Mahasiswa Islam (UKMI) - Politeknik Negeri Medan

**Theme:** Modern, Fresh & Student-Friendly (Light & Dark Mode)

## 1. Color Tokens

### A. Light Mode (Default)

| Role | Token Name | HEX | RGB | Usage Guidelines | 
| :--- | :--- | :--- | :--- | :--- | 
| **Primary** | `primary` (Leaf Green) | `#1E824C` | `rgb(30, 130, 76)` | Main brand identity, primary buttons, headers, key graphic elements. | 
| **Secondary** | `secondary` (Sage Mint) | `#D8EFE2` | `rgb(216, 239, 226)` | Secondary backgrounds, cards, badges, subtle illustrations. | 
| **Background** | `background` (Warm Off-White) | `#FAF9F6` | `rgb(250, 249, 246)` | Primary canvas/page background. Warm tone to reduce eye strain. | 
| **Text / Dark** | `foreground` (Charcoal) | `#222825` | `rgb(34, 40, 37)` | Primary body text, subheadings, dark icons, borders. | 
| **Accent** | `accent` (Mustard Amber) | `#E6A817` | `rgb(230, 168, 23)` | Call-to-Action (CTA) highlights, active indicators, important badges. | 

### B. Dark Mode (Mode Gelap)

| Role | Token Name | HEX | RGB | Usage Guidelines | 
| :--- | :--- | :--- | :--- | :--- | 
| **Primary** | `primary` (Emerald Mint) | `#29A863` | `rgb(41, 168, 99)` | Lebih cerah dari versi light agar tetap kontras dan segar di latar gelap. | 
| **Secondary** | `secondary` (Deep Forest) | `#1A2B22` | `rgb(26, 43, 34)` | Permukaan kartu (*card*), *navbar*, dan elemen kontainer bertingkat. | 
| **Background** | `background` (Obsidian Forest) | `#111714` | `rgb(17, 23, 20)` | Latar belakang utama kanvas gelap dengan rona hijau sangat tipis (*tinted dark*). | 
| **Text / Light** | `foreground` (Soft Mint White) | `#F0F4F1` | `rgb(240, 244, 241)` | Teks utama dan ikon agar nyaman dibaca tanpa silau kontras berlebih. | 
| **Accent** | `accent` (Warm Gold) | `#F2B938` | `rgb(242, 185, 56)` | Tombol CTA, *highlight*, dan indikator aktif yang menonjol di layar gelap. | 

## 2. Vanilla CSS Variables & Implementation

Gunakan CSS Custom Properties berikut pada file CSS utama (`style.css` / `variables.css`). AI agent harus selalu menggunakan variabel `var(--color-*)` dan **tidak boleh melakukan *hardcode* kode HEX** di dalam komponen.

```css
/* =========================================
   1. LIGHT MODE (DEFAULT)
   ========================================= */
:root {
  --color-primary: #1E824C;
  --color-secondary: #D8EFE2;
  --color-background: #FAF9F6;
  --color-foreground: #222825;
  --color-accent: #E6A817;
  --color-border: #DCE5E0;
  --color-on-primary: #FFFFFF;
}

/* =========================================
   2. DARK MODE (MANUAL TOGGLE / SYSTEM)
   ========================================= */
[data-theme="dark"] {
  --color-primary: #29A863;
  --color-secondary: #1A2B22;
  --color-background: #111714;
  --color-foreground: #F0F4F1;
  --color-accent: #F2B938;
  --color-border: #263B30;
  --color-on-primary: #111714;
}

/* Opsional: Otomatis mengikuti pengaturan sistem perangkat jika atribut tidak diset */
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --color-primary: #29A863;
    --color-secondary: #1A2B22;
    --color-background: #111714;
    --color-foreground: #F0F4F1;
    --color-accent: #F2B938;
    --color-border: #263B30;
    --color-on-primary: #111714;
  }
}

/* =========================================
   3. CONTOH PENERAPAN DASAR (VANILLA CSS)
   ========================================= */
body {
  background-color: var(--color-background);
  color: var(--color-foreground);
  transition: background-color 0.25s ease, color 0.25s ease;
}

.btn-primary {
  background-color: var(--color-primary);
  color: var(--color-on-primary);
}

.btn-accent {
  background-color: var(--color-accent);
  color: #111714; /* Selalu gelap agar kontras di atas kuning/emas */
}

.card-secondary {
  background-color: var(--color-secondary);
  color: var(--color-foreground);
  border: 1px solid var(--color-border);
}
```

## 3. Proportion Rules (60-30-10)

* **60% Neutral Base:** Gunakan `--color-background` (`#FAF9F6` di Light Mode / `#111714` di Dark Mode) sebagai latar belakang dominan untuk menjaga kebersihan tata letak (*whitespace*).
* **30% Primary & Secondary:** Gunakan `--color-primary` untuk elemen utama/judul dan `--color-secondary` untuk pembagian blok konten (*cards/sections*).
* **10% Accent:** Gunakan `--color-accent` secara terbatas hanya untuk penekanan visual, tombol CTA, atau sorotan informasi penting.

## 4. Contrast & Accessibility Rules

* **Light Mode:**
  * Gunakan `--color-foreground` (`#222825`) untuk teks isi (*body text*) dan `--color-primary` (`#1E824C`) untuk judul.
  * **Larangan:** Jangan gunakan `--color-accent` (`#E6A817`) sebagai warna teks di atas latar terang (`#FAF9F6`) karena rasio kontrasnya rendah.
* **Dark Mode:**
  * Gunakan `--color-foreground` (`#F0F4F1`) untuk teks isi di atas latar `#111714` atau `#1A2B22`.
  * Warna `--color-accent` (`#F2B938`) dan `--color-primary` (`#29A863`) aman digunakan sebagai warna teks sorotan maupun ikon di atas latar gelap.
  * Teks di atas tombol `--color-accent` (`#F2B938`) wajib menggunakan warna gelap (`#111714`), bukan putih.
* **Logo Placement:**
  * Pertahankan warna asli logo UKMI Polmed. Baik pada Light Mode maupun Dark Mode (terutama di atas latar `#111714` atau `#1A2B22`), gunakan versi logo dengan lingkaran dasar putih solid agar detail grafis hijau tetap terbaca tajam.