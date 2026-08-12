<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
salamRequireSuperAdmin();

date_default_timezone_set('Asia/Jakarta');

function salamBindParams(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$format = $_GET['format'] ?? 'excel';
$periode = trim($_GET['periode'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
    $periode = date('Y-m');
}
$statusBayar = $_GET['status_bayar'] ?? 'all';
$statusPelanggan = $_GET['status_pelanggan'] ?? 'all';
$alamatFilter = trim($_GET['alamat'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$alamatDefaultOptions = array_values(salamDaftarWilayahResmi());
$alamatOptions = [];
$alamatSeen = [];
$addAlamatOption = function ($value) use (&$alamatOptions, &$alamatSeen) {
    $value = salamNormalisasiAlamatInput($value);
    if ($value === '') {
        return;
    }
    $key = salamNormalisasiKunci($value);
    if (isset($alamatSeen[$key])) {
        return;
    }
    $alamatSeen[$key] = true;
    $alamatOptions[] = $value;
};
foreach ($alamatDefaultOptions as $alamatDefaultOption) {
    $addAlamatOption($alamatDefaultOption);
}
$alamatResult = $koneksi->query("
    SELECT alamat
    FROM (
        SELECT alamat
        FROM pelanggan_salam
        WHERE alamat IS NOT NULL AND TRIM(alamat) <> ''

        UNION

        SELECT alamat_snapshot AS alamat
        FROM tagihan_salam
        WHERE alamat_snapshot IS NOT NULL AND TRIM(alamat_snapshot) <> ''
    ) AS daftar_alamat
    ORDER BY alamat ASC
");
if ($alamatResult instanceof mysqli_result) {
    while ($alamatRow = $alamatResult->fetch_assoc()) {
        $addAlamatOption($alamatRow['alamat']);
    }
}

function salamNormalizeText(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function salamTextFuzzyMatch(string $needle, string $haystack): bool {
    $needle = salamNormalizeText($needle);
    $haystack = salamNormalizeText($haystack);
    if ($needle === '') return true;
    if ($haystack === '') return false;
    if (strpos($haystack, $needle) !== false) return true;

    $needleWords = array_filter(explode(' ', $needle));
    $haystackWords = array_filter(explode(' ', $haystack));
    foreach ($needleWords as $needleWord) {
        if (strlen($needleWord) < 4) continue;
        foreach ($haystackWords as $haystackWord) {
            if (abs(strlen($needleWord) - strlen($haystackWord)) > 2) continue;
            if (levenshtein($needleWord, $haystackWord) <= 2) return true;
            similar_text($needleWord, $haystackWord, $percent);
            if ($percent >= 78) return true;
        }
    }
    return false;
}

function salamRowMatchesSearch(array $row, string $search): bool {
    if ($search === '') return true;
    $fields = [
        $row['nama'] ?? '',
        $row['id_pelanggan'] ?? '',
        $row['kode_pelanggan'] ?? '',
        $row['nomor_pelanggan'] ?? '',
        $row['paket'] ?? '',
        $row['alamat'] ?? '',
    ];
    foreach ($fields as $field) {
        if (salamTextFuzzyMatch($search, (string) $field)) return true;
    }
    return false;
}

$baseLaporanSql = "
    SELECT
        p.id AS id,
        p.id AS pelanggan_id,
        p.id_pelanggan,
        COALESCE(p.kode_pelanggan, '') AS kode_pelanggan,
        p.nama,
        COALESCE(p.nomor_pelanggan, '') AS nomor_pelanggan,
        p.alamat,
        p.paket,
        p.tarif_langganan,
        p.tagihan,
        p.status_bayar,
        p.status_pelanggan,
        p.waktu,
        p.langganan_selesai,
        p.tanggal_daftar,
        p.tanggal_bayar,
        p.nominal_dibayar,
        p.nomor_invoice,
        'berjalan' AS sumber_data
    FROM pelanggan_salam p
    WHERE DATE_FORMAT(p.waktu, '%Y-%m') = ?

    UNION ALL

    SELECT
        t.id AS id,
        t.pelanggan_id,
        t.id_pelanggan_snapshot AS id_pelanggan,
        COALESCE(p.kode_pelanggan, '') AS kode_pelanggan,
        t.nama_snapshot AS nama,
        COALESCE(p.nomor_pelanggan, '') AS nomor_pelanggan,
        t.alamat_snapshot AS alamat,
        t.paket_snapshot AS paket,
        t.tarif_snapshot AS tarif_langganan,
        t.nominal_tagihan AS tagihan,
        t.status_bayar,
        COALESCE(p.status_pelanggan, 'Aktif') AS status_pelanggan,
        t.periode AS waktu,
        t.tanggal_jatuh_tempo AS langganan_selesai,
        p.tanggal_daftar,
        t.tanggal_bayar,
        t.nominal_dibayar,
        t.nomor_invoice,
        'riwayat' AS sumber_data
    FROM tagihan_salam t
    LEFT JOIN pelanggan_salam p ON p.id = t.pelanggan_id
    WHERE DATE_FORMAT(t.periode, '%Y-%m') = ?
      AND NOT EXISTS (
          SELECT 1
          FROM pelanggan_salam p_berjalan
          WHERE p_berjalan.id = t.pelanggan_id
            AND DATE_FORMAT(p_berjalan.waktu, '%Y-%m') = DATE_FORMAT(t.periode, '%Y-%m')
      )
";

$conditions = ['1 = 1'];
$types = 'ss';
$params = [$periode, $periode];

if (in_array($statusBayar, ['Lunas', 'Belum Lunas'], true)) {
    $conditions[] = 'status_bayar = ?';
    $types .= 's';
    $params[] = $statusBayar;
} else {
    $statusBayar = 'all';
}

if (in_array($statusPelanggan, ['Aktif', 'Tidak Aktif'], true)) {
    $conditions[] = 'status_pelanggan = ?';
    $types .= 's';
    $params[] = $statusPelanggan;
} else {
    $statusPelanggan = 'all';
}

if ($alamatFilter !== 'all' && $alamatFilter !== '') {
    $alamatFilter = salamNormalisasiAlamatInput($alamatFilter);
    $conditions[] = salamSqlNormalisasiAlamat('alamat') . ' = ?';
    $types .= 's';
    $params[] = salamNormalisasiKunci($alamatFilter);
} else {
    $alamatFilter = 'all';
}

$where = 'WHERE ' . implode(' AND ', $conditions);
$sql = "SELECT * FROM ({$baseLaporanSql}) AS laporan {$where} ORDER BY pelanggan_id ASC, id ASC";
$stmt = $koneksi->prepare($sql);
if (!$stmt) {
    die('Query laporan multiwilayah gagal disiapkan: ' . $koneksi->error);
}
salamBindParams($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

if ($search !== '') {
    $rows = array_values(array_filter($rows, function ($row) use ($search) {
        return salamRowMatchesSearch($row, $search);
    }));
}

$periodeLabel = salamBulananIndonesia($periode . '-01', false);
$filenameBase = 'laporan_billing_semua_wilayah_' . str_replace('-', '_', $periode);

function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function numberPlain($value): string { return number_format((float) $value, 0, ',', '.'); }

function salamXmlEscape($value): string {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function salamExcelColumn(int $number): string {
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + ($number % 26)) . $column;
        $number = intdiv($number, 26);
    }
    return $column;
}

function salamExcelTextCell(string $cell, $value, int $style = 0): string {
    return '<c r="' . $cell . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
        . salamXmlEscape($value)
        . '</t></is></c>';
}

function salamExcelNumberCell(string $cell, $value, int $style = 0): string {
    $number = is_numeric($value) ? (float) $value : 0;
    return '<c r="' . $cell . '" t="n" s="' . $style . '"><v>' . $number . '</v></c>';
}

function salamExportXlsx(
    array $rows,
    string $filenameBase,
    string $periodeLabel,
    string $statusBayar,
    string $statusPelanggan,
    string $alamatFilter
): void {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit(
            "Export XLSX membutuhkan ekstensi PHP ZIP.\n"
            . "Aktifkan extension=zip pada PHP Laragon/server, lalu restart web server."
        );
    }

    $headers = [
        'No', 'ID Pelanggan', 'Kode', 'Nama', 'Alamat', 'No. WhatsApp',
        'Paket', 'Periode', 'Masa Aktif', 'Tarif', 'Status Bayar',
        'Status Pelanggan', 'Tanggal Bayar', 'Nominal Dibayar',
    ];

    $totalTagihan = 0.0;
    $totalDibayar = 0.0;
    foreach ($rows as $row) {
        $totalTagihan += (float) ($row['tagihan'] ?? 0);
        $totalDibayar += (float) ($row['nominal_dibayar'] ?? 0);
    }

    $sheetRows = [];

    $sheetRows[] = '<row r="1" ht="28" customHeight="1">'
        . salamExcelTextCell('A1', 'Laporan Billing Semua Wilayah / UKOOMED', 1)
        . '</row>';
    $sheetRows[] = '<row r="2" ht="20" customHeight="1">'
        . salamExcelTextCell('A2', 'Rekap data pelanggan dan tagihan', 2)
        . '</row>';

    $sheetRows[] = '<row r="4">'
        . salamExcelTextCell('A4', 'Periode', 3)
        . salamExcelTextCell('B4', $periodeLabel, 4)
        . salamExcelTextCell('C4', 'Status Bayar', 3)
        . salamExcelTextCell('D4', $statusBayar === 'all' ? 'Semua' : $statusBayar, 4)
        . '</row>';
    $sheetRows[] = '<row r="5">'
        . salamExcelTextCell('A5', 'Status Pelanggan', 3)
        . salamExcelTextCell('B5', $statusPelanggan === 'all' ? 'Semua' : $statusPelanggan, 4)
        . salamExcelTextCell('C5', 'Alamat', 3)
        . salamExcelTextCell('D5', $alamatFilter === 'all' ? 'Semua' : $alamatFilter, 4)
        . '</row>';
    $sheetRows[] = '<row r="6">'
        . salamExcelTextCell('A6', 'Dicetak', 3)
        . salamExcelTextCell('B6', salamTanggalWaktuIndonesia(), 4)
        . '</row>';

    $sheetRows[] = '<row r="8" ht="22" customHeight="1">'
        . salamExcelTextCell('A8', 'Total Data', 5)
        . salamExcelNumberCell('B8', count($rows), 6)
        . salamExcelTextCell('C8', 'Total Tagihan Berjalan', 5)
        . salamExcelNumberCell('D8', $totalTagihan, 7)
        . salamExcelTextCell('E8', 'Total Dibayar', 5)
        . salamExcelNumberCell('F8', $totalDibayar, 7)
        . '</row>';

    $headerCells = '';
    foreach ($headers as $index => $header) {
        $headerCells .= salamExcelTextCell(salamExcelColumn($index + 1) . '10', $header, 8);
    }
    $sheetRows[] = '<row r="10" ht="34" customHeight="1">' . $headerCells . '</row>';

    $excelRow = 11;
    $no = 1;
    foreach ($rows as $row) {
        $statusBayarValue = (string) ($row['status_bayar'] ?? '-');
        $statusPelangganValue = (string) ($row['status_pelanggan'] ?? '-');

        $statusBayarStyle = $statusBayarValue === 'Lunas' ? 12 : 13;
        $statusPelangganStyle = $statusPelangganValue === 'Aktif' ? 14 : 15;

        $cells = '';
        $cells .= salamExcelNumberCell('A' . $excelRow, $no++, 9);
        $cells .= salamExcelTextCell('B' . $excelRow, $row['id_pelanggan'] ?: '-', 10);
        $cells .= salamExcelTextCell('C' . $excelRow, $row['kode_pelanggan'] ?: '-', 10);
        $cells .= salamExcelTextCell('D' . $excelRow, $row['nama'] ?? '-', 10);
        $cells .= salamExcelTextCell('E' . $excelRow, $row['alamat'] ?: '-', 10);
        $cells .= salamExcelTextCell('F' . $excelRow, $row['nomor_pelanggan'] ?: '-', 10);
        $cells .= salamExcelTextCell('G' . $excelRow, $row['paket'] ?? '-', 10);
        $cells .= salamExcelTextCell('H' . $excelRow, salamBulananIndonesia($row['waktu'] ?? null, false), 9);
        $cells .= salamExcelTextCell('I' . $excelRow, salamBulananIndonesia($row['langganan_selesai'] ?? null, true), 9);
        $cells .= salamExcelNumberCell('J' . $excelRow, $row['tarif_langganan'] ?? 0, 11);
        $cells .= salamExcelTextCell('K' . $excelRow, $statusBayarValue, $statusBayarStyle);
        $cells .= salamExcelTextCell('L' . $excelRow, $statusPelangganValue, $statusPelangganStyle);
        $cells .= salamExcelTextCell('M' . $excelRow, salamBulananIndonesia($row['tanggal_bayar'] ?? null, true), 9);
        $cells .= salamExcelNumberCell('N' . $excelRow, $row['nominal_dibayar'] ?? 0, 11);

        $sheetRows[] = '<row r="' . $excelRow . '" ht="21" customHeight="1">' . $cells . '</row>';
        $excelRow++;
    }

    if (!$rows) {
        $sheetRows[] = '<row r="11"><c r="A11" t="inlineStr" s="9"><is><t>Tidak ada data untuk filter ini.</t></is></c></row>';
    }

    $lastRow = max(11, $excelRow - 1);

    $columnWidths = [
        6, 17, 18, 24, 27, 18, 19, 15, 17, 15, 17, 19, 17, 19,
    ];
    $cols = '';
    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $cols .= '<col min="' . $columnNumber . '" max="' . $columnNumber
            . '" width="' . $width . '" customWidth="1"/>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="10" topLeftCell="A11" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A10:N' . $lastRow . '"/>'
        . '<mergeCells count="3">'
        . '<mergeCell ref="A1:N1"/><mergeCell ref="A2:N2"/><mergeCell ref="B6:D6"/>'
        . '</mergeCells>'
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0"/></numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Arial"/></font>'
        . '<font><i/><color rgb="FF5B677A"/><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="9">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F2F4F"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF3FB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCEEFE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2F80B7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF9AA9B8"/></left><right style="thin"><color rgb="FF9AA9B8"/></right><top style="thin"><color rgb="FF9AA9B8"/></top><bottom style="thin"><color rgb="FF9AA9B8"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="16">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyFont="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="8" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Laporan ' . salamXmlEscape($periodeLabel) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
        . 'xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
        . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Billing Salam</dc:creator><cp:lastModifiedBy>Billing Salam</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Billing Salam</Application>'
        . '</Properties>';

    $tempFile = tempnam(sys_get_temp_dir(), 'billing_salam_xlsx_');
    if ($tempFile === false) {
        http_response_code(500);
        exit('Gagal membuat file sementara untuk export XLSX.');
    }

    $zip = new ZipArchive();
    $opened = $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        @unlink($tempFile);
        http_response_code(500);
        exit('Gagal membuat arsip XLSX. Kode error: ' . $opened);
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($tempFile);
    @unlink($tempFile);
    exit;
}

if ($format === 'excel') {
    salamExportXlsx(
        $rows,
        $filenameBase,
        $periodeLabel,
        $statusBayar,
        $statusPelanggan,
        $alamatFilter
    );
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Billing Semua Wilayah <?= h($periodeLabel); ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            background: #ffffff;
        }
        h2, p { margin: 0 0 8px; }
        .report-title {
            font-size: 20px;
            font-weight: bold;
            color: #0f2f4f;
            margin-bottom: 4px;
        }
        .report-subtitle {
            font-size: 13px;
            color: #5b677a;
            margin-bottom: 14px;
        }
        .meta-table {
            width: auto;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 12px;
        }
        .meta-table td {
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
        }
        .meta-label {
            background: #eaf3fb;
            font-weight: bold;
            color: #183b5b;
            min-width: 135px;
        }
        table { width: 100%; border-collapse: collapse; font-size: 12px; color: #111827; }
        th, td { border: 1px solid #7f8fa3; padding: 7px; vertical-align: middle; color: #111827; }
        thead th {
            background: #d8ecff;
            color: #0f172a;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
            border: 1px solid #5f7892;
        }
        tbody tr:nth-child(even) td { background: #f7fbff; }
        tbody td { color: #111827; }
        tbody td.text { color: #0f172a; font-weight: 500; }
        .right { text-align: right; }
        .center { text-align: center; }
        .text { mso-number-format: "\@"; }
        .summary {
            margin: 12px 0 16px;
            border-collapse: collapse;
            width: 100%;
            font-size: 12px;
        }
        .summary td {
            border: 1px solid #8aa9c5;
            padding: 8px;
            font-weight: bold;
        }
        .summary-label {
            background: #dceefe;
            color: #123d63;
        }
        .summary-value {
            background: #ffffff;
            color: #111827;
            text-align: right;
        }
        .status-lunas {
            background: #dcfce7 !important;
            color: #166534;
            font-weight: bold;
            text-align: center;
        }
        .status-belum {
            background: #fee2e2 !important;
            color: #991b1b;
            font-weight: bold;
            text-align: center;
        }
        .status-aktif {
            background: #e0f2fe !important;
            color: #075985;
            font-weight: bold;
            text-align: center;
        }
        .status-nonaktif {
            background: #f3f4f6 !important;
            color: #4b5563;
            font-weight: bold;
            text-align: center;
        }
        .money { text-align: right; mso-number-format: "#,##0"; }
        .toolbar { margin: 0 0 18px; }
        .toolbar button { padding: 9px 14px; background:#8e44ad; color:#fff; border:0; border-radius:6px; cursor:pointer; font-weight:bold; }
        @media print {
            /* Mode hemat kertas A4: tetap terbaca, tapi muat lebih banyak baris per halaman. */
            @page { size: A4 landscape; margin: 6mm; }

            html, body {
                width: 285mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #111827 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            .no-print { display:none !important; }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            .report-title {
                font-size: 15px !important;
                margin-bottom: 2px !important;
            }
            .report-subtitle {
                font-size: 9px !important;
                margin-bottom: 6px !important;
            }
            .meta-table {
                font-size: 8.5px !important;
                margin-bottom: 5px !important;
            }
            .meta-table td {
                padding: 3px 4px !important;
            }
            .summary {
                font-size: 8.5px !important;
                margin: 5px 0 6px !important;
            }
            .summary td {
                padding: 4px 5px !important;
            }
            table {
                width: 100% !important;
                table-layout: fixed !important;
                font-size: 8px !important;
                line-height: 1.12 !important;
            }
            th, td {
                padding: 3px 3px !important;
                line-height: 1.12 !important;
                word-break: normal !important;
                overflow-wrap: break-word !important;
            }
            table, th, td {
                color: #111827 !important;
                border-color: #7f8fa3 !important;
            }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; break-inside: avoid; }

            /* Lebar kolom khusus saat dicetak A4 landscape */
            th:nth-child(1), td:nth-child(1) { width: 22px !important; }
            th:nth-child(2), td:nth-child(2) { width: 62px !important; }
            th:nth-child(3), td:nth-child(3) { width: 66px !important; }
            th:nth-child(4), td:nth-child(4) { width: 82px !important; }
            th:nth-child(5), td:nth-child(5) { width: 92px !important; }
            th:nth-child(6), td:nth-child(6) { width: 76px !important; }
            th:nth-child(7), td:nth-child(7) { width: 66px !important; }
            th:nth-child(8), td:nth-child(8) { width: 50px !important; }
            th:nth-child(9), td:nth-child(9) { width: 56px !important; }
            th:nth-child(10), td:nth-child(10) { width: 52px !important; }
            th:nth-child(11), td:nth-child(11) { width: 64px !important; }
            th:nth-child(12), td:nth-child(12) { width: 68px !important; }
            th:nth-child(13), td:nth-child(13) { width: 62px !important; }
            th:nth-child(14), td:nth-child(14) { width: 70px !important; }
            .report-title { color: #0f2f4f !important; }
            .report-subtitle { color: #5b677a !important; }
            .meta-label {
                background-color: #eaf3fb !important;
                color: #183b5b !important;
                font-weight: bold !important;
            }
            .summary-label {
                background-color: #dceefe !important;
                color: #123d63 !important;
                font-weight: bold !important;
            }
            .summary-value {
                background-color: #ffffff !important;
                color: #111827 !important;
                font-weight: bold !important;
            }
            thead th {
                background-color: #2f80b7 !important;
                color: #ffffff !important;
                border-color: #4f6f87 !important;
                font-weight: bold !important;
            }
            tbody tr:nth-child(even) td { background-color: #f7fbff !important; }
            tbody td.text { color: #0f172a !important; font-weight: 500 !important; }
            .status-lunas {
                background-color: #dcfce7 !important;
                color: #166534 !important;
                font-weight: bold !important;
            }
            .status-belum {
                background-color: #fee2e2 !important;
                color: #991b1b !important;
                font-weight: bold !important;
            }
            .status-aktif {
                background-color: #e0f2fe !important;
                color: #075985 !important;
                font-weight: bold !important;
            }
            .status-nonaktif {
                background-color: #f3f4f6 !important;
                color: #4b5563 !important;
                font-weight: bold !important;
            }
        }
    </style>
</head>
<body>
<?php if ($format !== 'excel'): ?>
    <div class="toolbar no-print"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<?php endif; ?>
    <div class="report-title">Laporan Billing Semua Wilayah / UKOOMED</div>
    <div class="report-subtitle">Rekap data pelanggan dan tagihan </div>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Periode</td>
            <td><?= h($periodeLabel); ?></td>
            <td class="meta-label">Status Bayar</td>
            <td><?= h($statusBayar === 'all' ? 'Semua' : $statusBayar); ?></td>
        </tr>
        <tr>
            <td class="meta-label">Status Pelanggan</td>
            <td><?= h($statusPelanggan === 'all' ? 'Semua' : $statusPelanggan); ?></td>
            <td class="meta-label">Alamat</td>
            <td><?= h($alamatFilter === 'all' ? 'Semua' : $alamatFilter); ?></td>
        </tr>
        <tr>
            <td class="meta-label">Dicetak</td>
            <td colspan="3"><?= h(salamTanggalWaktuIndonesia()); ?></td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td class="summary-label">Total Data</td>
            <td class="summary-value"><?= count($rows); ?></td>
            <td class="summary-label">Total Tagihan Berjalan</td>
            <td class="summary-value"><?php $sum=0; foreach($rows as $r) $sum+=(float)$r['tagihan']; echo h(salamRupiah($sum)); ?></td>
            <td class="summary-label">Total Dibayar</td>
            <td class="summary-value"><?php $sum=0; foreach($rows as $r) $sum+=(float)$r['nominal_dibayar']; echo h(salamRupiah($sum)); ?></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>ID Pelanggan</th>
                <th>Kode</th>
                <th>Nama</th>
                <th>Alamat</th>
                <th>No. WhatsApp</th>
                <th>Paket</th>
                <th>Periode</th>
                <th>Masa Aktif</th>
                <th>Tarif</th>
                <th>Status Bayar</th>
                <th>Status Pelanggan</th>
                <th>Tanggal Bayar</th>
                <th>Nominal Dibayar</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="14" class="center">Tidak ada data untuk filter ini.</td></tr>
            <?php else: ?>
                <?php $no = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td class="center"><?= $no++; ?></td>
                        <td class="text"><?= h($row['id_pelanggan'] ?: '-'); ?></td>
                        <td class="text"><?= h($row['kode_pelanggan'] ?: '-'); ?></td>
                        <td><?= h($row['nama']); ?></td>
                        <td><?= h($row['alamat'] ?: '-'); ?></td>
                        <td class="text"><?= h($row['nomor_pelanggan'] ?: '-'); ?></td>
                        <td><?= h($row['paket']); ?></td>
                        <td><?= h(salamBulananIndonesia($row['waktu'] ?? null, false)); ?></td>
                        <td><?= h(salamBulananIndonesia($row['langganan_selesai'] ?? null, true)); ?></td>
                        <td class="money"><?= h(numberPlain($row['tarif_langganan'] ?? 0)); ?></td>
                        <td class="<?= ($row['status_bayar'] ?? '') === 'Lunas' ? 'status-lunas' : 'status-belum'; ?>"><?= h($row['status_bayar'] ?? '-'); ?></td>
                        <td class="<?= ($row['status_pelanggan'] ?? '') === 'Aktif' ? 'status-aktif' : 'status-nonaktif'; ?>"><?= h($row['status_pelanggan'] ?? '-'); ?></td>
                        <td><?= h(salamBulananIndonesia($row['tanggal_bayar'] ?? null, true)); ?></td>
                        <td class="money"><?= h(numberPlain($row['nominal_dibayar'] ?? 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($format !== 'excel'): ?>
    <script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 500); });</script>
    <?php endif; ?>
</body>
</html>