<?php
/**
 * src/report_pdf.php — Generator PDF Rekap Laporan Mentoring
 * Menulis PDF A4 tanpa dependensi eksternal. Setiap laporan ditampilkan utuh
 * pada halaman baru, dan foto dokumentasi dikompres ulang menjadi JPEG kecil
 * (lebar maks. 900px, kualitas 55) sebelum disematkan ke PDF.
 */

declare(strict_types=1);

namespace Mentoring;

class ReportPdf {
    private const PAGE_W = 595.28;
    private const PAGE_H = 841.89;
    private const MARGIN = 42.0;
    private const CONTENT_W = 511.28;
    private const BOTTOM = 48.0;

    /** @var array<int,string> */
    private array $objects = [];
    /** @var array<int,array{content:string,images:array<string,int>}> */
    private array $pages = [];

    private string $content = '';
    private float $y = 0.0;
    private bool $pageStarted = false;
    private bool $headerRendered = false;
    private string $currentTeam = '';
    /** @var array<string,int> */
    private array $pageImages = [];

    public function __construct(
        private string $docTitle,
        private string $docSubtitle = ''
    ) {
        // Objek 1 (catalog) & 2 (pages) diisi saat output.
        $this->objects[1] = '';
        $this->objects[2] = '';
        $this->objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $this->objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    }

    private function addObject(string $body): int {
        $id = count($this->objects) + 1;
        $this->objects[$id] = $body;
        return $id;
    }

    private function startPage(): void {
        if ($this->pageStarted) {
            $this->pages[] = ['content' => $this->content, 'images' => $this->pageImages];
        }

        $this->content = '';
        $this->pageImages = [];
        $this->y = self::PAGE_H - self::MARGIN;
        $this->pageStarted = true;
    }

    private function ensureSpace(float $height): void {
        if ($this->y - $height >= self::BOTTOM) {
            return;
        }

        $this->startPage();
        $this->line('Lanjutan — ' . ($this->currentTeam !== '' ? $this->currentTeam : 'Laporan'), 8, true, 10, 0, [0.4, 0.45, 0.5]);
    }

    private function encodeText(string $text): string {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /**
     * @return string[]
     */
    private function wrap(string $text, float $size, float $maxWidth): array {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }

        $charWidth = $size * 0.52;
        $maxChars = max(12, (int)floor($maxWidth / $charWidth));
        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) <= $maxChars) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            if (mb_strlen($word) <= $maxChars) {
                $current = $word;
                continue;
            }
            while (mb_strlen($word) > $maxChars) {
                $lines[] = mb_substr($word, 0, $maxChars);
                $word = mb_substr($word, $maxChars);
            }
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines !== [] ? $lines : [''];
    }

    /**
     * Tulis satu paragraf dengan pembungkusan otomatis dan page break.
     *
     * @param array{0:float,1:float,2:float}|null $color
     */
    private function line(
        string $text,
        float $size = 10,
        bool $bold = false,
        float $gap = 4,
        float $indent = 0,
        ?array $color = null
    ): void {
        $font = $bold ? 'F2' : 'F1';
        $lineHeight = $size * 1.38;
        $maxWidth = self::CONTENT_W - $indent;
        [$r, $g, $b] = $color ?? [0.08, 0.1, 0.14];

        foreach ($this->wrap($text, $size, $maxWidth) as $part) {
            $this->ensureSpace($lineHeight);
            $baseline = $this->y - $size;
            $this->content .= sprintf(
                "%.3F %.3F %.3F rg\nBT\n/%s %.2F Tf\n1 0 0 1 %.2F %.2F Tm\n(%s) Tj\nET\n",
                $r,
                $g,
                $b,
                $font,
                $size,
                self::MARGIN + $indent,
                $baseline,
                $this->encodeText($part)
            );
            $this->y -= $lineHeight;
        }

        $this->y -= $gap;
    }

    private function spacer(float $height): void {
        $this->y -= $height;
    }

    private function sectionTitle(string $text): void {
        $this->ensureSpace(26);
        $this->line($text, 11, true, 5, 0, [0.01, 0.35, 0.55]);
    }

    private function addImage(string $jpeg, int $width, int $height): void {
        $displayWidth = self::CONTENT_W;
        $displayHeight = $displayWidth * ($height / max(1, $width));
        $maxHeight = 235.0;
        if ($displayHeight > $maxHeight) {
            $displayHeight = $maxHeight;
            $displayWidth = $displayHeight * ($width / max(1, $height));
        }

        $this->ensureSpace($displayHeight + 16);

        $imageId = $this->addObject(
            '<< /Type /XObject /Subtype /Image /Width ' . $width . ' /Height ' . $height
            . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n"
            . $jpeg . "\nendstream"
        );

        $name = 'Im' . (count($this->pageImages) + 1);
        $this->pageImages[$name] = $imageId;

        $x = self::MARGIN + (self::CONTENT_W - $displayWidth) / 2;
        $bottom = $this->y - $displayHeight;
        $this->content .= sprintf(
            "q\n%.2F 0 0 %.2F %.2F %.2F cm\n/%s Do\nQ\n",
            $displayWidth,
            $displayHeight,
            $x,
            $bottom,
            $name
        );
        $this->y = $bottom - 16;
    }

    public function addReport(array $report, ?string $jpeg = null, int $imageWidth = 0, int $imageHeight = 0): void {
        $this->startPage();
        $this->currentTeam = (string)($report['nama_tim'] ?? 'Tanpa Tim');

        if (!$this->headerRendered) {
            $this->line($this->docTitle, 15, true, 3, 0, [0.02, 0.1, 0.18]);
            if ($this->docSubtitle !== '') {
                $this->line($this->docSubtitle, 8.5, false, 3, 0, [0.35, 0.4, 0.45]);
            }
            $this->line('Dicetak: ' . date('d/m/Y H:i'), 8, false, 12, 0, [0.5, 0.55, 0.6]);
            $this->headerRendered = true;
        }

        $this->line($this->currentTeam, 17, true, 2, 0, [0.05, 0.1, 0.18]);
        $this->line('Laporan Mentoring • ' . (string)($report['id'] ?? ''), 8.5, false, 10, 0, [0.4, 0.45, 0.5]);

        if ($jpeg !== null && $imageWidth > 0 && $imageHeight > 0) {
            $this->addImage($jpeg, $imageWidth, $imageHeight);
        }

        $tanggal = (string)($report['tanggal'] ?? '');
        $jamMulai = str_replace(':', '.', (string)($report['jam_mulai'] ?? ''));
        $jamSelesai = str_replace(':', '.', (string)($report['jam_selesai'] ?? ''));
        $waktu = ($tanggal !== '' ? Watobot::formatTanggalIndonesia($tanggal) : '-')
            . ($jamMulai !== '' ? ' (' . $jamMulai . ' sd ' . $jamSelesai . ')' : '');

        $this->sectionTitle('Informasi Kegiatan');
        $this->line('Waktu: ' . $waktu);
        $this->line('Tempat: ' . ((string)($report['tempat'] ?? '') ?: '-'));
        $this->line('Tema: ' . ((string)($report['tema'] ?? '') ?: '-'));
        $this->line('Mentor: ' . ((string)($report['mentor'] ?? '') ?: '-'));
        $this->line('Moderator: ' . ((string)($report['moderator'] ?? '') ?: '-'));
        $this->line('Tilawah: ' . ((string)($report['tilawah'] ?? '') ?: '-'));
        $this->spacer(4);

        $this->sectionTitle('Petugas & Materi');
        $tadabbur = trim((string)($report['tadabbur_surah_ayat'] ?? '') . ' - ' . (string)($report['tadabbur_inti'] ?? ''), ' -');
        $this->line('Tadabbur: ' . ((string)($report['tadabbur_petugas'] ?? '') ?: '-') . ($tadabbur !== '' ? ' [' . $tadabbur . ']' : ''));
        $this->line('Kultum: ' . ((string)($report['kultum_petugas'] ?? '') ?: '-') . (($report['kultum_judul_inti'] ?? '') !== '' ? ' [' . $report['kultum_judul_inti'] . ']' : ''));
        $this->line("Do'a: " . ((string)($report['doa_petugas'] ?? '') ?: '-'));
        $this->spacer(6);

        $hadir = array_values($report['hadir'] ?? []);
        $izin = array_values($report['izin'] ?? []);
        $absen = array_values($report['absen'] ?? []);
        $izinKeterangan = is_array($report['izin_keterangan'] ?? null) ? $report['izin_keterangan'] : [];

        $this->sectionTitle('Kehadiran (H: ' . count($hadir) . ' • I: ' . count($izin) . ' • A: ' . count($absen) . ')');
        $this->nameList('Hadir', $hadir);
        $this->nameList('Izin', $izin, $izinKeterangan);
        $this->nameList('Absen', $absen);
        $this->spacer(8);
    }

    /**
     * @param string[] $names
     * @param array<string,string> $notes
     */
    private function nameList(string $label, array $names, array $notes = []): void {
        $this->line($label . ':', 10, true, 2);
        if ($names === []) {
            $this->line('-', 9.5, false, 5, 14, [0.45, 0.5, 0.55]);
            return;
        }
        foreach ($names as $index => $name) {
            $note = trim((string)($notes[$name] ?? ''));
            $display = $name . ($note !== '' ? ' (' . $note . ')' : '');
            $this->line(($index + 1) . '. ' . $display, 9.5, false, 1.5, 14);
        }
        $this->spacer(4);
    }

    public function addNotice(string $text): void {
        $this->startPage();
        $this->line($this->docTitle, 15, true, 10, 0, [0.02, 0.1, 0.18]);
        if ($this->docSubtitle !== '') {
            $this->line($this->docSubtitle, 9, false, 14, 0, [0.35, 0.4, 0.45]);
        }
        $this->line($text, 10, false, 0, 0, [0.4, 0.45, 0.5]);
    }

    public function output(): string {
        if ($this->pageStarted) {
            $this->pages[] = ['content' => $this->content, 'images' => $this->pageImages];
        }

        $pageIds = [];
        foreach ($this->pages as $page) {
            $contentId = $this->addObject(
                '<< /Length ' . strlen($page['content']) . " >>\nstream\n" . $page['content'] . "endstream"
            );

            $resources = '/Font << /F1 3 0 R /F2 4 0 R >>';
            if ($page['images'] !== []) {
                $xobjects = [];
                foreach ($page['images'] as $name => $objectId) {
                    $xobjects[] = '/' . $name . ' ' . $objectId . ' 0 R';
                }
                $resources .= ' /XObject << ' . implode(' ', $xobjects) . ' >>';
            }

            $pageIds[] = $this->addObject(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_W . ' ' . self::PAGE_H . ']'
                . ' /Resources << ' . $resources . ' >> /Contents ' . $contentId . ' 0 R >>'
            );
        }

        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = array_map(static fn(int $id): string => $id . ' 0 R', $pageIds);
        $this->objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageIds) . ' >>';

        ksort($this->objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($this->objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $maxId = max(array_keys($this->objects));
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    /**
     * Kompres ulang gambar menjadi JPEG kecil agar PDF tidak membengkak.
     *
     * @return array{data:string,width:int,height:int}|null
     */
    public static function compressImage(string $binary, int $maxWidth = 900, int $quality = 55): ?array {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > $maxWidth) {
            $newHeight = max(1, (int)round($height * ($maxWidth / $width)));
            $scaled = imagescale($image, $maxWidth, $newHeight, IMG_BILINEAR_FIXED);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
                $width = $maxWidth;
                $height = $newHeight;
            }
        }

        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $jpeg = ob_get_clean();

        imagedestroy($image);
        imagedestroy($canvas);

        if (!is_string($jpeg) || $jpeg === '') {
            return null;
        }

        return ['data' => $jpeg, 'width' => $width, 'height' => $height];
    }
}
