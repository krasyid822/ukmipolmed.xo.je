<?php
/**
 * src/excel_export.php — Ekspor Data Master Mentee ke Berkas Excel (.xlsx)
 *
 * Berkas XLSX dibangun langsung dengan ZipArchive (tanpa dependensi Composer),
 * sehingga tetap ringan di hosting InfinityFree. Setiap tim mendapatkan warna
 * baris yang berbeda agar mudah dibaca. Nilai sel disimpan sebagai teks agar
 * NIM dan nomor telepon tidak kehilangan angka 0 di depan.
 */

declare(strict_types=1);

namespace Mentoring;

class ExcelExport {
    /** Palet warna pastel baris per tim (RRGGBB). */
    private const TEAM_COLORS = [
        'FDE2E4', 'E2F0FB', 'E2FBE7', 'FDF6D8', 'F0E6FD', 'FBE6F1',
        'E6F7F4', 'FFF0E0', 'EDE8FF', 'F4F1E1', 'E1F3F9', 'FCE7D8',
        'E9F7DC', 'F7E4E4', 'E4EDF7', 'F3E8F7',
    ];

    private const HEADER_COLOR = '1E293B';

    /**
     * Kolom ekspor: [header, lebar].
     * Urutan mengikuti spesifikasi: NO NAMA T.T.L GENDER KELAS | NIM TELEPON
     * ALAMAT II ASAL KONDISI MENTOR TIM KELAS AMANAH
     */
    private const COLUMNS = [
        ['NO', 5],
        ['NAMA', 26],
        ['T.T.L', 30],
        ['GENDER', 12],
        ['KELAS', 12],
        ['NIM', 15],
        ['TELEPON', 16],
        ['ALAMAT II', 34],
        ['ASAL', 34],
        ['KONDISI', 14],
        ['MENTOR', 26],
        ['TIM', 22],
        ['KELAS', 12],
        ['AMANAH', 26],
    ];

    /**
     * Bangun isi berkas XLSX (binary) untuk data master tertentu.
     *
     * @param string $scope 'ikhwan' | 'akhwat' | 'semua'
     */
    public static function buildMenteeWorkbook(array $master, string $scope = 'semua'): string {
        $headers = array_column(self::COLUMNS, 0);
        $widths = array_column(self::COLUMNS, 1);

        $teams = array_values($master['teams'] ?? []);

        $mentorsById = [];
        foreach ($master['mentors'] ?? [] as $mentor) {
            $mentorsById[(string)($mentor['id'] ?? '')] = (string)($mentor['nama_lengkap'] ?? '');
        }

        // Warna & style per tim: ditentukan urutan tim pada data master
        $teamColors = [];
        $styleByTeam = [];
        $teamName = [];
        foreach ($teams as $i => $team) {
            $teamId = (string)($team['id'] ?? '');
            $teamColors[$i] = self::teamColor($i);
            $styleByTeam[$teamId] = 3 + $i;
            $teamName[$teamId] = (string)($team['nama_tim'] ?? '');
        }

        $mentees = array_values(array_filter(
            $master['mentees'] ?? [],
            static function (array $mentee) use ($scope): bool {
                $gender = (string)($mentee['gender'] ?? '');
                if ($scope === 'ikhwan') {
                    return $gender === 'Laki-laki';
                }
                if ($scope === 'akhwat') {
                    return $gender === 'Perempuan';
                }
                return true;
            }
        ));

        usort($mentees, static function (array $a, array $b): int {
            $teamCmp = strcmp((string)($a['tim_id'] ?? ''), (string)($b['tim_id'] ?? ''));
            return $teamCmp !== 0
                ? $teamCmp
                : strcmp((string)($a['nama_lengkap'] ?? ''), (string)($b['nama_lengkap'] ?? ''));
        });

        $rowsXml = '';
        $rowNumber = 2;
        $no = 1;

        foreach ($mentees as $mentee) {
            $teamId = (string)($mentee['tim_id'] ?? '');
            $mentorId = (string)($mentee['mentor_id'] ?? '');

            $values = [
                (string)$no++,
                (string)($mentee['nama_lengkap'] ?? ''),
                self::formatTtl($mentee),
                (string)($mentee['gender'] ?? ''),
                Academic::formatClass($mentee['kelas'] ?? '', $mentee['nim'] ?? ''),
                (string)($mentee['nim'] ?? ''),
                self::formatPhone((string)($mentee['nomor_whatsapp'] ?? '')),
                (string)($mentee['alamat_sekarang'] ?? ''),
                (string)($mentee['alamat_orang_tua'] ?? ''),
                (string)($mentee['status_keaktifan'] ?? 'Aktif'),
                $mentorsById[$mentorId] ?? '',
                $teamName[$teamId] ?? '',
                (string)($mentee['jadwal_kuliah'] ?? ''),
                (string)($mentee['amanah_organisasi'] ?? ''),
            ];

            $rowsXml .= self::rowXml($rowNumber, $values, $styleByTeam[$teamId] ?? 2);
            $rowNumber++;
        }

        $lastCol = self::columnLetter(count($headers) - 1);
        $lastRow = max(1, $rowNumber - 1);

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="17"/>'
            . self::colsXml($widths)
            . '<sheetData>'
            . self::rowXml(1, $headers, 1)
            . $rowsXml
            . '</sheetData>'
            . '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>'
            . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0" scale="70"/>'
            . '</worksheet>';

        return self::zipWorkbook($sheetXml, $teamColors);
    }

    /**
     * Nama berkas ekspor, mis. Data-Mentee-Ikhwan-20261121.xlsx
     */
    public static function fileName(string $scope): string {
        $label = ['ikhwan' => 'Ikhwan', 'akhwat' => 'Akhwat'][$scope] ?? 'Gabungan';
        return 'Data-Mentee-' . $label . '-' . date('Ymd') . '.xlsx';
    }

    private static function formatTtl(array $mentee): string {
        $tempat = trim((string)($mentee['tempat_lahir'] ?? ''));
        $tanggal = trim((string)($mentee['tanggal_lahir'] ?? ''));

        if ($tanggal !== '') {
            $ts = strtotime($tanggal);
            if ($ts !== false) {
                $bulan = [
                    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
                ];
                $tanggal = (int)date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
            }
        }

        if ($tempat !== '' && $tanggal !== '') {
            return $tempat . ', ' . $tanggal;
        }

        return $tempat !== '' ? $tempat : $tanggal;
    }

    private static function formatPhone(string $phone): string {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '62')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+62' . substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Warna baris untuk tim ke-$index. Unik per tim selama jumlah tim wajar:
     * palet tetap dipakai lebih dulu, sisanya dibangkitkan dari sebaran hue.
     */
    private static function teamColor(int $index): string {
        if ($index < count(self::TEAM_COLORS)) {
            return self::TEAM_COLORS[$index];
        }

        $hue = fmod($index * 137.508, 360.0);
        return self::hslToHex($hue, 0.55, 0.9);
    }

    private static function hslToHex(float $h, float $s, float $l): string {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        if ($h < 60) {
            [$r, $g, $b] = [$c, $x, 0];
        } elseif ($h < 120) {
            [$r, $g, $b] = [$x, $c, 0];
        } elseif ($h < 180) {
            [$r, $g, $b] = [0, $c, $x];
        } elseif ($h < 240) {
            [$r, $g, $b] = [0, $x, $c];
        } elseif ($h < 300) {
            [$r, $g, $b] = [$x, 0, $c];
        } else {
            [$r, $g, $b] = [$c, 0, $x];
        }

        return sprintf(
            '%02X%02X%02X',
            (int)round(($r + $m) * 255),
            (int)round(($g + $m) * 255),
            (int)round(($b + $m) * 255)
        );
    }

    private static function rowXml(int $rowNumber, array $values, int $style): string {
        $xml = '<row r="' . $rowNumber . '">';
        foreach (array_values($values) as $colIndex => $value) {
            $ref = self::columnLetter($colIndex) . $rowNumber;
            $text = self::escape((string)$value);
            $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
        }
        return $xml . '</row>';
    }

    private static function colsXml(array $widths): string {
        $xml = '<cols>';
        foreach ($widths as $i => $width) {
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        return $xml . '</cols>';
    }

    private static function columnLetter(int $index): string {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }

    private static function escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Rakit seluruh bagian XLSX ke dalam satu arsip ZIP.
     *
     * @param string[] $teamColors Warna baris (RRGGBB) sesuai urutan tim
     */
    private static function zipWorkbook(string $sheetXml, array $teamColors): string {
        $fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::HEADER_COLOR . '"/><bgColor indexed="64"/></patternFill></fill>';

        // xf 0 = default, 1 = header, 2 = baris tanpa tim, 3.. = baris per tim
        $cellXfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf>';

        foreach ($teamColors as $i => $color) {
            $fillId = 3 + $i;
            $fills .= '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $color . '"/><bgColor indexed="64"/></patternFill></fill>';
            $cellXfs .= '<xf numFmtId="0" fontId="0" fillId="' . $fillId . '" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">'
                . '<alignment vertical="top" wrapText="1"/></xf>';
        }

        $fillCount = 3 + count($teamColors);
        $xfCount = 3 + count($teamColors);

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="' . $fillCount . '">' . $fills . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right>'
            . '<top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . $xfCount . '">' . $cellXfs . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Data Mentee" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new \RuntimeException('Gagal membuat berkas sementara untuk ekspor Excel.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Gagal membuat arsip Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $binary = (string)file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }
}
