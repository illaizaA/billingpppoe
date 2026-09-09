<?php

/**
 * Helper XLSX ringan khusus export Dashboard Analitik.
 * Fokus pada tampilan formal, mudah dibaca, dan tetap memakai data analitik yang sudah ada.
 */

function analitikXlsxXmlEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function analitikXlsxColumnName(int $number): string
{
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + ($number % 26)) . $column;
        $number = intdiv($number, 26);
    }
    return $column;
}

function analitikXlsxSafeSheetName(string $name): string
{
    $name = preg_replace('~[\\/:*?\[\]]+~u', ' ', trim($name));
    $name = preg_replace('/\s+/', ' ', (string) $name);
    if ($name === '') $name = 'Data Billing';
    return substr($name, 0, 31);
}

function analitikXlsxUniqueSheetNames(array $sheets): array
{
    $used = [];
    foreach ($sheets as $index => &$sheet) {
        $candidate = trim((string) ($sheet['name'] ?? ''));
        if ($candidate === '' || preg_match('/^Analitik(?:\s+\d+)?$/i', $candidate)) {
            $candidate = trim((string) ($sheet['title'] ?? ''));
        }
        if ($candidate === '' || preg_match('/^Analitik(?:\s+\d+)?$/i', $candidate)) {
            $candidate = 'Data Billing ' . ($index + 1);
        }

        $base = analitikXlsxSafeSheetName($candidate);
        $name = $base;
        $n = 2;
        while (isset($used[strtolower($name)])) {
            $suffix = ' ' . $n++;
            $maxBaseLength = max(1, 31 - strlen($suffix));
            $name = substr($base, 0, $maxBaseLength) . $suffix;
        }
        $used[strtolower($name)] = true;
        $sheet['name'] = $name;
    }
    unset($sheet);
    return $sheets;
}

function analitikXlsxTextCell(string $cell, $value, int $style = 9): string
{
    return '<c r="' . $cell . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
        . analitikXlsxXmlEscape($value)
        . '</t></is></c>';
}

function analitikXlsxNumberCell(string $cell, $value, int $style = 10): string
{
    $number = is_numeric($value) ? (float) $value : 0.0;
    return '<c r="' . $cell . '" t="n" s="' . $style . '"><v>' . $number . '</v></c>';
}

function analitikXlsxCellByType(string $cell, $value, string $type = 'text'): string
{
    switch ($type) {
        case 'integer':
            return analitikXlsxNumberCell($cell, (int) $value, 10);
        case 'number':
            return analitikXlsxNumberCell($cell, $value, 10);
        case 'money':
            return analitikXlsxNumberCell($cell, $value, 11);
        case 'percent':
            return analitikXlsxNumberCell($cell, $value, 12);
        case 'status':
            $text = trim((string) $value);
            if (
                stripos($text, 'belum') !== false
                || stripos($text, 'perlu ditagih') !== false
                || stripos($text, 'tidak aktif') !== false
            ) {
                return analitikXlsxTextCell($cell, $text, 14);
            }
            if (
                stripos($text, 'lunas') !== false
                || stripos($text, 'baik') !== false
                || strcasecmp($text, 'aktif') === 0
            ) {
                return analitikXlsxTextCell($cell, $text, 13);
            }
            if (stripos($text, 'cukup') !== false || stripos($text, 'perhatian') !== false) {
                return analitikXlsxTextCell($cell, $text, 15);
            }
            return analitikXlsxTextCell($cell, $text, 9);
        default:
            return analitikXlsxTextCell($cell, $value, 9);
    }
}

/**
 * Style dibuat mengikuti gaya export laporan Billing:
 * judul navy, subtitle abu-abu, informasi filter rapi, ringkasan ringan,
 * header biru, border tipis, serta penanda status berwarna.
 */
function analitikXlsxStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        . '<numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0"/>'
        . '<numFmt numFmtId="165" formatCode="0.0&quot;%&quot;"/>'
        . '</numFmts>'
        . '<fonts count="5">'
        . '<font><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Arial"/></font>'
        . '<font><i/><color rgb="FF5B677A"/><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font>'
        . '<font><b/><color rgb="FF17324D"/><sz val="10"/><name val="Arial"/></font>'
        . '</fonts>'
        . '<fills count="10">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F2F4F"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF3FB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCEEFE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF2F80B7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4D6"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
        . '<left style="thin"><color rgb="FF9AA9B8"/></left>'
        . '<right style="thin"><color rgb="FF9AA9B8"/></right>'
        . '<top style="thin"><color rgb="FF9AA9B8"/></top>'
        . '<bottom style="thin"><color rgb="FF9AA9B8"/></bottom>'
        . '<diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="16">'
        // 0 normal
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        // 1 title
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 2 subtitle
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 3 meta label
        . '<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        // 4 meta value
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        // 5 summary label
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        // 6 summary numeric
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 7 summary money
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 8 header
        . '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 9 body text
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        // 10 body integer/number
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 11 body money
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 12 body percent / summary percent
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        // 13 positive status
        . '<xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 14 negative status
        . '<xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 15 attention status
        . '<xf numFmtId="0" fontId="4" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function analitikXlsxBuildSheetXml(array $sheet): string
{
    $columns = array_values($sheet['columns'] ?? []);
    if (!$columns) {
        $columns = [['label' => 'Informasi', 'type' => 'text', 'width' => 28]];
    }

    $rows = array_values($sheet['rows'] ?? []);
    $summary = array_values($sheet['summary'] ?? []);
    $meta = array_values($sheet['meta'] ?? []);

    // Minimal enam kolom agar area judul, periode, wilayah, dan waktu export tidak sempit.
    $columnCount = max(6, count($columns));
    $lastColumn = analitikXlsxColumnName($columnCount);
    $sheetRows = [];
    $mergeCells = [];

    $title = trim((string) ($sheet['title'] ?? 'Analitik Billing'));
    if ($title === '') $title = 'Analitik Billing';
    $subtitle = trim((string) ($sheet['subtitle'] ?? 'Ringkasan data Billing / UKOOMED'));

    $sheetRows[] = '<row r="1" ht="30" customHeight="1">'
        . analitikXlsxTextCell('A1', $title, 1)
        . '</row>';
    $sheetRows[] = '<row r="2" ht="20" customHeight="1">'
        . analitikXlsxTextCell('A2', $subtitle, 2)
        . '</row>';
    $mergeCells[] = 'A1:' . $lastColumn . '1';
    $mergeCells[] = 'A2:' . $lastColumn . '2';

    $metaMap = [
        'Periode' => '-',
        'Wilayah' => '-',
        'Diekspor' => date('d-m-Y H:i'),
    ];
    foreach ($meta as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') continue;
        $metaMap[$label] = (string) ($item['value'] ?? '-');
    }

    // Layout metadata sengaja dibuat tetap agar rapi dan tidak mudah membungkus.
    $sheetRows[] = '<row r="4" ht="22" customHeight="1">'
        . analitikXlsxTextCell('A4', 'Periode', 3)
        . analitikXlsxTextCell('B4', $metaMap['Periode'] ?? '-', 4)
        . analitikXlsxTextCell('D4', 'Wilayah', 3)
        . analitikXlsxTextCell('E4', $metaMap['Wilayah'] ?? '-', 4)
        . '</row>';
    $mergeCells[] = 'B4:C4';
    $mergeCells[] = 'E4:F4';

    $sheetRows[] = '<row r="5" ht="22" customHeight="1">'
        . analitikXlsxTextCell('A5', 'Diekspor', 3)
        . analitikXlsxTextCell('B5', $metaMap['Diekspor'] ?? '-', 4)
        . '</row>';
    $mergeCells[] = 'B5:F5';

    $summaryRow = 7;
    if ($summary) {
        foreach (array_chunk($summary, 3) as $chunk) {
            $cells = '';
            foreach ($chunk as $index => $item) {
                $labelCol = 1 + ($index * 2);
                $valueCol = $labelCol + 1;
                $labelCell = analitikXlsxColumnName($labelCol) . $summaryRow;
                $valueCell = analitikXlsxColumnName($valueCol) . $summaryRow;
                $cells .= analitikXlsxTextCell($labelCell, $item['label'] ?? '-', 5);
                $type = (string) ($item['type'] ?? 'text');
                if ($type === 'money') {
                    $cells .= analitikXlsxNumberCell($valueCell, $item['value'] ?? 0, 7);
                } elseif ($type === 'percent') {
                    $cells .= analitikXlsxNumberCell($valueCell, $item['value'] ?? 0, 12);
                } elseif ($type === 'integer' || $type === 'number') {
                    $cells .= analitikXlsxNumberCell($valueCell, $item['value'] ?? 0, 6);
                } else {
                    $cells .= analitikXlsxTextCell($valueCell, $item['value'] ?? '-', 4);
                }
            }
            $sheetRows[] = '<row r="' . $summaryRow . '" ht="22" customHeight="1">' . $cells . '</row>';
            $summaryRow++;
        }
    }

    // Beri satu baris kosong sebelum tabel agar struktur visual lebih jelas.
    $headerRow = $summary ? ($summaryRow + 1) : 7;
    $headerCells = '';
    foreach ($columns as $index => $column) {
        $headerCells .= analitikXlsxTextCell(
            analitikXlsxColumnName($index + 1) . $headerRow,
            $column['label'] ?? ('Kolom ' . ($index + 1)),
            8
        );
    }
    $sheetRows[] = '<row r="' . $headerRow . '" ht="34" customHeight="1">' . $headerCells . '</row>';

    $dataRow = $headerRow + 1;
    foreach ($rows as $row) {
        $cells = '';
        foreach ($columns as $index => $column) {
            $value = $row[$index] ?? null;
            $type = (string) ($column['type'] ?? 'text');
            $cells .= analitikXlsxCellByType(
                analitikXlsxColumnName($index + 1) . $dataRow,
                $value,
                $type
            );
        }
        $sheetRows[] = '<row r="' . $dataRow . '" ht="21" customHeight="1">' . $cells . '</row>';
        $dataRow++;
    }

    if (!$rows) {
        $sheetRows[] = '<row r="' . $dataRow . '" ht="22" customHeight="1">'
            . analitikXlsxTextCell(
                'A' . $dataRow,
                'Belum ada data pada pilihan periode dan wilayah ini.',
                9
            )
            . '</row>';
    }

    $lastDataRow = max($headerRow + 1, $dataRow - 1);

    // Lebar minimum enam kolom pertama dibuat cukup lega untuk metadata dan ringkasan.
    $formalMinimumWidths = [18, 24, 18, 24, 18, 24];
    $cols = '';
    for ($i = 0; $i < $columnCount; $i++) {
        if ($i < count($columns)) {
            $width = (float) ($columns[$i]['width'] ?? 18);
        } else {
            $width = 14;
        }
        if ($i < count($formalMinimumWidths)) {
            $width = max($width, $formalMinimumWidths[$i]);
        }
        $width = max(9, min(38, $width));
        $n = $i + 1;
        $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
    }

    $mergeXml = '';
    foreach ($mergeCells as $ref) {
        $mergeXml .= '<mergeCell ref="' . $ref . '"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/>'
        . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A' . $headerRow . ':' . analitikXlsxColumnName(count($columns)) . $lastDataRow . '"/>'
        . '<mergeCells count="' . count($mergeCells) . '">' . $mergeXml . '</mergeCells>'
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
}

function analitikXlsxCreate(array $sheets): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException(
            'Export Excel membutuhkan ekstensi PHP ZIP. Aktifkan extension=zip pada PHP Laragon/server lalu restart web server.'
        );
    }
    if (!$sheets) throw new RuntimeException('Tidak ada data analitik yang dapat diekspor.');

    $sheets = analitikXlsxUniqueSheetNames(array_values($sheets));
    $sheetXmlFiles = [];
    $workbookSheets = '';
    $workbookRels = '';
    $contentOverrides = '';

    foreach ($sheets as $index => $sheet) {
        $n = $index + 1;
        $sheetXmlFiles['xl/worksheets/sheet' . $n . '.xml'] = analitikXlsxBuildSheetXml($sheet);
        $workbookSheets .= '<sheet name="' . analitikXlsxXmlEscape($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $workbookRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $contentOverrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $styleRelId = count($sheets) + 1;
    $workbookRels .= '<Relationship Id="rId' . $styleRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $workbookSheets . '</sheets>'
        . '</workbook>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $contentOverrides
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
        . $workbookRels
        . '</Relationships>';

    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Billing Salam / UKOOMED</dc:creator>'
        . '<cp:lastModifiedBy>Billing Salam / UKOOMED</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Billing Salam / UKOOMED</Application>'
        . '</Properties>';

    $tempFile = tempnam(sys_get_temp_dir(), 'analitik_xlsx_');
    if ($tempFile === false) throw new RuntimeException('Gagal membuat file sementara untuk export Excel.');

    $zip = new ZipArchive();
    $opened = $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        @unlink($tempFile);
        throw new RuntimeException('Gagal membuat file Excel. Kode ZIP: ' . $opened);
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', analitikXlsxStylesXml());
    foreach ($sheetXmlFiles as $path => $xml) {
        $zip->addFromString($path, $xml);
    }
    $zip->close();

    return $tempFile;
}

function analitikXlsxDownload(array $sheets, string $filenameBase): void
{
    // Nama file dibuat formal: spasi dan tanda hubung boleh, underscore tidak dipakai.
    $filenameBase = preg_replace('~[\\/:*?"<>|]+~u', ' ', trim($filenameBase));
    $filenameBase = str_replace('_', ' ', (string) $filenameBase);
    $filenameBase = preg_replace('/\s+/', ' ', (string) $filenameBase);
    $filenameBase = trim((string) $filenameBase, " .-");
    if ($filenameBase === '') $filenameBase = 'Analitik Billing';

    // Jaga agar nama file tetap ringkas dan nyaman dilihat.
    if (strlen($filenameBase) > 90) {
        $filenameBase = rtrim(substr($filenameBase, 0, 90));
    }

    $downloadName = $filenameBase . '.xlsx';
    $asciiName = preg_replace('/[^A-Za-z0-9 .-]+/', '', $downloadName);
    $asciiName = preg_replace('/\s+/', ' ', (string) $asciiName);
    if ($asciiName === '' || $asciiName === '.xlsx') $asciiName = 'Analitik Billing.xlsx';

    $tempFile = analitikXlsxCreate($sheets);
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header(
        'Content-Disposition: attachment; filename="' . addcslashes($asciiName, '"\\')
        . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName)
    );
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($tempFile);
    @unlink($tempFile);
    exit;
}
