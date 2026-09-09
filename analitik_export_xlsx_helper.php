<?php

/**
 * Helper XLSX ringan khusus export Dashboard Analitik.
 * Tidak mengubah data atau struktur database; hanya membentuk file Excel dari array hasil analitik.
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
        // Nama tab Excel WAJIB memakai nama isi analitik, bukan nama generik
        // seperti "Analitik", "Analitik 2", dan seterusnya.
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
            $name = substr($base, 0, max(1, 31 - strlen($suffix))) . $suffix;
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
            $text = (string) $value;
            if (stripos($text, 'belum') !== false || stripos($text, 'perlu ditagih') !== false) {
                return analitikXlsxTextCell($cell, $text, 14);
            }
            if (stripos($text, 'lunas') !== false || stripos($text, 'baik') !== false) {
                return analitikXlsxTextCell($cell, $text, 13);
            }
            return analitikXlsxTextCell($cell, $text, 9);
        default:
            return analitikXlsxTextCell($cell, $value, 9);
    }
}

function analitikXlsxStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        . '<numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0"/>'
        . '<numFmt numFmtId="165" formatCode="0.0&quot;%&quot;"/>'
        . '</numFmts>'
        . '<fonts count="4">'
        . '<font><sz val="10"/><name val="Aptos"/><family val="2"/></font>'
        . '<font><b/><sz val="16"/><color rgb="FF17324D"/><name val="Aptos Display"/><family val="2"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Aptos"/><family val="2"/></font>'
        . '<font><b/><sz val="10"/><color rgb="FF17324D"/><name val="Aptos"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="8">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF4FB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF3498DB"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF7FAFC"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF8F0"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFEEEE"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFDF6E3"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FFD9E3EC"/></left><right style="thin"><color rgb="FFD9E3EC"/></right><top style="thin"><color rgb="FFD9E3EC"/></top><bottom style="thin"><color rgb="FFD9E3EC"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="15">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
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

    $columnCount = max(4, count($columns));
    $lastColumn = analitikXlsxColumnName($columnCount);
    $sheetRows = [];

    $sheetRows[] = '<row r="1" ht="28" customHeight="1">' . analitikXlsxTextCell('A1', $sheet['title'] ?? 'Ringkasan Analitik', 1) . '</row>';
    $sheetRows[] = '<row r="2" ht="20" customHeight="1">' . analitikXlsxTextCell('A2', $sheet['subtitle'] ?? 'Data analitik Billing / UKOOMED', 2) . '</row>';

    $metaDefaults = [
        ['label' => 'Periode', 'value' => '-'],
        ['label' => 'Wilayah', 'value' => '-'],
        ['label' => 'Diekspor', 'value' => date('d-m-Y H:i')],
    ];
    $meta = array_merge($metaDefaults, $meta);
    $metaMap = [];
    foreach ($meta as $item) {
        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') continue;
        $metaMap[$label] = (string) ($item['value'] ?? '-');
    }
    $metaItems = [];
    foreach ($metaMap as $label => $value) $metaItems[] = ['label' => $label, 'value' => $value];

    $metaRow = 4;
    $pairCapacity = max(1, intdiv($columnCount, 2));
    foreach (array_chunk($metaItems, $pairCapacity) as $chunk) {
        $cells = '';
        $col = 1;
        foreach ($chunk as $item) {
            $labelCell = analitikXlsxColumnName($col) . $metaRow;
            $valueCell = analitikXlsxColumnName($col + 1) . $metaRow;
            $cells .= analitikXlsxTextCell($labelCell, $item['label'], 3);
            $cells .= analitikXlsxTextCell($valueCell, $item['value'], 4);
            $col += 2;
        }
        $sheetRows[] = '<row r="' . $metaRow . '" ht="20" customHeight="1">' . $cells . '</row>';
        $metaRow++;
    }

    $summaryStart = $metaRow + 1;
    $summaryRow = $summaryStart;
    if ($summary) {
        foreach (array_chunk($summary, $pairCapacity) as $chunk) {
            $cells = '';
            $col = 1;
            foreach ($chunk as $item) {
                $labelCell = analitikXlsxColumnName($col) . $summaryRow;
                $valueCell = analitikXlsxColumnName($col + 1) . $summaryRow;
                $cells .= analitikXlsxTextCell($labelCell, $item['label'] ?? '-', 5);
                $type = (string) ($item['type'] ?? 'text');
                if ($type === 'money') $cells .= analitikXlsxNumberCell($valueCell, $item['value'] ?? 0, 7);
                elseif ($type === 'percent') $cells .= analitikXlsxNumberCell($valueCell, $item['value'] ?? 0, 12);
                elseif ($type === 'integer' || $type === 'number') $cells .= analitikXlsxNumberCell($valueCell, $item['value'] ?? 0, 6);
                else $cells .= analitikXlsxTextCell($valueCell, $item['value'] ?? '-', 4);
                $col += 2;
            }
            $sheetRows[] = '<row r="' . $summaryRow . '" ht="21" customHeight="1">' . $cells . '</row>';
            $summaryRow++;
        }
    }

    $headerRow = ($summary ? $summaryRow : $summaryStart) + 1;
    $headerCells = '';
    foreach ($columns as $index => $column) {
        $headerCells .= analitikXlsxTextCell(analitikXlsxColumnName($index + 1) . $headerRow, $column['label'] ?? ('Kolom ' . ($index + 1)), 8);
    }
    $sheetRows[] = '<row r="' . $headerRow . '" ht="32" customHeight="1">' . $headerCells . '</row>';

    $dataRow = $headerRow + 1;
    foreach ($rows as $row) {
        $cells = '';
        foreach ($columns as $index => $column) {
            $value = $row[$index] ?? null;
            $type = (string) ($column['type'] ?? 'text');
            $cells .= analitikXlsxCellByType(analitikXlsxColumnName($index + 1) . $dataRow, $value, $type);
        }
        $sheetRows[] = '<row r="' . $dataRow . '" ht="21" customHeight="1">' . $cells . '</row>';
        $dataRow++;
    }
    if (!$rows) {
        $sheetRows[] = '<row r="' . $dataRow . '" ht="22" customHeight="1">' . analitikXlsxTextCell('A' . $dataRow, 'Belum ada data pada pilihan periode dan wilayah ini.', 9) . '</row>';
    }

    $lastDataRow = max($headerRow + 1, $dataRow - 1);
    $cols = '';
    for ($i = 0; $i < count($columns); $i++) {
        $width = (float) ($columns[$i]['width'] ?? 18);
        $width = max(9, min(38, $width));
        $n = $i + 1;
        $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $width . '" customWidth="1"/>';
    }
    for ($i = count($columns) + 1; $i <= $columnCount; $i++) {
        $cols .= '<col min="' . $i . '" max="' . $i . '" width="14" customWidth="1"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A' . $headerRow . ':' . analitikXlsxColumnName(count($columns)) . $lastDataRow . '"/>'
        . '<mergeCells count="2"><mergeCell ref="A1:' . $lastColumn . '1"/><mergeCell ref="A2:' . $lastColumn . '2"/></mergeCells>'
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
}

function analitikXlsxCreate(array $sheets): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Export Excel membutuhkan ekstensi PHP ZIP. Aktifkan extension=zip pada PHP Laragon/server lalu restart web server.');
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
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $workbookRels . '</Relationships>';

    $nowIso = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Billing Salam / UKOOMED</dc:creator><cp:lastModifiedBy>Billing Salam / UKOOMED</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
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
    foreach ($sheetXmlFiles as $path => $xml) $zip->addFromString($path, $xml);
    $zip->close();

    return $tempFile;
}

function analitikXlsxDownload(array $sheets, string $filenameBase): void
{
    $filenameBase = preg_replace('/[^A-Za-z0-9_\-]+/', '_', trim($filenameBase));
    $filenameBase = trim((string) $filenameBase, '_');
    if ($filenameBase === '') $filenameBase = 'Analitik_Billing';

    $tempFile = analitikXlsxCreate($sheets);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    header('Content-Length: ' . filesize($tempFile));
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');
    readfile($tempFile);
    @unlink($tempFile);
    exit;
}
