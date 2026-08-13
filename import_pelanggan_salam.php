<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';

header('Content-Type: application/json; charset=utf-8');

salamRequireSuperAdmin();

const SALAM_IMPORT_EXCEL_MAX_BYTES = 5242880; // 5 MB
const SALAM_IMPORT_CUSTOMER_MAX_ROWS = 1000;
const SALAM_IMPORT_HISTORY_MAX_ROWS = 10000;

function salamImportExcelResponse(
    bool $success,
    string $message,
    int $imported = 0,
    int $skipped = 0,
    array $errors = [],
    int $historyImported = 0,
    int $historySkipped = 0,
    int $httpCode = 200
): void {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        // Tetap mempertahankan field lama agar dashboard lama tidak rusak.
        'imported' => $imported,
        'skipped' => $skipped,
        'history_imported' => $historyImported,
        'history_skipped' => $historySkipped,
        'errors' => array_slice(array_values($errors), 0, 40),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function salamImportHeaderKey($value): string
{
    $value = trim((string) $value);

    if (str_starts_with($value, "\xEF\xBB\xBF")) {
        $value = substr($value, 3);
    }

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }
    }

    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function salamImportMapHeaders(array $headers, array $aliases): array
{
    $lookup = [];
    foreach ($aliases as $field => $items) {
        foreach ($items as $item) {
            $lookup[salamImportHeaderKey($item)] = $field;
        }
    }

    $map = [];
    foreach ($headers as $index => $header) {
        $key = salamImportHeaderKey($header);
        if ($key !== '' && isset($lookup[$key]) && !isset($map[$lookup[$key]])) {
            $map[$lookup[$key]] = (int) $index;
        }
    }

    return $map;
}

function salamImportRowValue(array $row, array $map, string $field): string
{
    if (!isset($map[$field])) {
        return '';
    }

    return trim((string) ($row[$map[$field]] ?? ''));
}

function salamImportNominal($value): ?float
{
    $raw = trim((string) $value);

    if ($raw === '') {
        return null;
    }

    $raw = preg_replace('/[^0-9,.-]/', '', $raw) ?? '';

    if ($raw === '' || $raw === '-' || $raw === '.' || $raw === ',') {
        return null;
    }

    $hasDot = str_contains($raw, '.');
    $hasComma = str_contains($raw, ',');

    if ($hasDot && $hasComma) {
        $lastDot = strrpos($raw, '.');
        $lastComma = strrpos($raw, ',');

        if ($lastComma > $lastDot) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);
        }
    } elseif ($hasDot && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $raw)) {
        $raw = str_replace('.', '', $raw);
    } elseif ($hasComma && preg_match('/^-?\d{1,3}(,\d{3})+$/', $raw)) {
        $raw = str_replace(',', '', $raw);
    } elseif ($hasComma) {
        $raw = str_replace(',', '.', $raw);
    }

    return is_numeric($raw) ? (float) $raw : null;
}

function salamImportXmlDecode(string $value): string
{
    $value = preg_replace('/<[^>]+>/', '', $value) ?? '';
    return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function salamImportArchiveRead(string $path, string $entry): string|false
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($path) === true) {
            $data = $zip->getFromName($entry);
            $zip->close();
            return $data;
        }
    }

    if (class_exists('PharData')) {
        try {
            $archive = new PharData($path);

            if (isset($archive[$entry])) {
                return $archive[$entry]->getContent();
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    return false;
}

function salamImportSharedStrings(string $path): array
{
    $shared = [];
    $sharedRaw = salamImportArchiveRead($path, 'xl/sharedStrings.xml');

    if ($sharedRaw === false) {
        return $shared;
    }

    preg_match_all(
        '/<(?:[A-Za-z0-9_]+:)?si\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?si>/si',
        $sharedRaw,
        $siMatches
    );

    foreach ($siMatches[1] ?? [] as $siXml) {
        preg_match_all(
            '/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/si',
            $siXml,
            $textMatches
        );

        $value = '';
        foreach ($textMatches[1] ?? [] as $text) {
            $value .= salamImportXmlDecode($text);
        }
        $shared[] = $value;
    }

    return $shared;
}

function salamImportWorkbookSheets(string $path): array
{
    $workbookRaw = salamImportArchiveRead($path, 'xl/workbook.xml');
    $relsRaw = salamImportArchiveRead($path, 'xl/_rels/workbook.xml.rels');

    if ($workbookRaw === false || $relsRaw === false) {
        // Kompatibilitas template XLSX lama yang hanya memiliki sheet pertama.
        return ['Import Pelanggan' => 'xl/worksheets/sheet1.xml'];
    }

    $rels = [];
    preg_match_all('/<Relationship\b([^>]*)\/?\s*>/si', $relsRaw, $relMatches, PREG_SET_ORDER);

    foreach ($relMatches as $match) {
        $attrs = (string) ($match[1] ?? '');
        if (
            preg_match('/\bId=["\']([^"\']+)["\']/i', $attrs, $idMatch)
            && preg_match('/\bTarget=["\']([^"\']+)["\']/i', $attrs, $targetMatch)
        ) {
            $target = str_replace('\\', '/', $targetMatch[1]);
            $target = ltrim($target, '/');
            if (!str_starts_with($target, 'xl/')) {
                $target = 'xl/' . ltrim($target, './');
            }
            $rels[$idMatch[1]] = $target;
        }
    }

    $sheets = [];
    preg_match_all('/<(?:[A-Za-z0-9_]+:)?sheet\b([^>]*)\/?\s*>/si', $workbookRaw, $sheetMatches, PREG_SET_ORDER);

    foreach ($sheetMatches as $match) {
        $attrs = (string) ($match[1] ?? '');
        if (
            preg_match('/\bname=["\']([^"\']+)["\']/i', $attrs, $nameMatch)
            && preg_match('/(?:\br:id|\bid)=["\']([^"\']+)["\']/i', $attrs, $ridMatch)
        ) {
            $name = html_entity_decode($nameMatch[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $rid = $ridMatch[1];
            if (isset($rels[$rid])) {
                $sheets[$name] = $rels[$rid];
            }
        }
    }

    if (!$sheets) {
        $sheets['Import Pelanggan'] = 'xl/worksheets/sheet1.xml';
    }

    return $sheets;
}

function salamImportReadSheetRows(
    string $path,
    string $entry,
    array $shared,
    int $maxRows
): array {
    $sheetRaw = salamImportArchiveRead($path, $entry);

    if ($sheetRaw === false) {
        return [];
    }

    $rows = [];
    preg_match_all(
        '/<(?:[A-Za-z0-9_]+:)?row\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?row>/si',
        $sheetRaw,
        $rowMatches
    );

    foreach ($rowMatches[1] ?? [] as $rowXml) {
        $row = [];

        preg_match_all(
            '/<(?:[A-Za-z0-9_]+:)?c\b([^>]*)>(.*?)<\/(?:[A-Za-z0-9_]+:)?c>|<(?:[A-Za-z0-9_]+:)?c\b([^>]*)\/>/si',
            $rowXml,
            $cellMatches,
            PREG_SET_ORDER
        );

        foreach ($cellMatches as $cellMatch) {
            $attrs = (string) (
                ($cellMatch[1] ?? '') !== ''
                    ? $cellMatch[1]
                    : ($cellMatch[3] ?? '')
            );
            $body = (string) ($cellMatch[2] ?? '');

            if (!preg_match('/\br=["\']([A-Z]+)\d+["\']/i', $attrs, $refMatch)) {
                continue;
            }

            $letters = strtoupper($refMatch[1]);
            $column = 0;
            for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
                $column = ($column * 26) + (ord($letters[$i]) - 64);
            }
            $index = $column - 1;

            $type = '';
            if (preg_match('/\bt=["\']([^"\']+)["\']/i', $attrs, $typeMatch)) {
                $type = strtolower($typeMatch[1]);
            }

            $value = '';
            if ($body !== '') {
                if ($type === 'inlinestr') {
                    preg_match_all(
                        '/<(?:[A-Za-z0-9_]+:)?t\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?t>/si',
                        $body,
                        $textMatches
                    );
                    foreach ($textMatches[1] ?? [] as $text) {
                        $value .= salamImportXmlDecode($text);
                    }
                } elseif (preg_match(
                    '/<(?:[A-Za-z0-9_]+:)?v\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?v>/si',
                    $body,
                    $valueMatch
                )) {
                    $raw = salamImportXmlDecode($valueMatch[1]);
                    if ($type === 's') {
                        $value = $shared[(int) $raw] ?? '';
                    } elseif ($type === 'b') {
                        $value = $raw === '1' ? '1' : '0';
                    } else {
                        $value = $raw;
                    }
                }
            }

            $row[$index] = $value;
        }

        if ($row) {
            ksort($row);
            $max = max(array_keys($row));
            $dense = array_fill(0, $max + 1, '');
            foreach ($row as $index => $value) {
                $dense[$index] = $value;
            }
            $rows[] = $dense;

            if (count($rows) > $maxRows + 1) {
                break;
            }
        }
    }

    return $rows;
}

function salamImportFindSheetEntry(array $sheets, array $acceptedNames): ?string
{
    $wanted = array_map('salamImportHeaderKey', $acceptedNames);

    foreach ($sheets as $name => $entry) {
        if (in_array(salamImportHeaderKey($name), $wanted, true)) {
            return $entry;
        }
    }

    return null;
}

function salamImportNormalizePeriod($value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    // Excel serial date.
    if (is_numeric($raw) && (float) $raw > 20000) {
        $timestamp = (int) round(((float) $raw - 25569) * 86400);
        return gmdate('Y-m-01', $timestamp);
    }

    if (preg_match('/^(\d{4})[-\/]([01]?\d)$/', $raw, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        if ($month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    if (preg_match('/^([01]?\d)[-\/](\d{4})$/', $raw, $m)) {
        $month = (int) $m[1];
        $year = (int) $m[2];
        if ($month >= 1 && $month <= 12) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-01', $year, $month);
        }
    }

    return null;
}

function salamImportNormalizeDate($value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw) && (float) $raw > 20000) {
        $timestamp = (int) round(((float) $raw - 25569) * 86400);
        return gmdate('Y-m-d', $timestamp);
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        return checkdate($month, $day, $year) ? $raw : null;
    }

    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $raw, $m)) {
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        return checkdate($month, $day, $year)
            ? sprintf('%04d-%02d-%02d', $year, $month, $day)
            : null;
    }

    return null;
}

function salamImportNormalizeStatus(string $value): ?string
{
    $key = salamImportHeaderKey($value);
    if ($key === 'lunas') {
        return 'Lunas';
    }
    if ($key === 'belumlunas' || $key === 'belumbayar') {
        return 'Belum Lunas';
    }
    return null;
}

function salamImportGenerateCustomerCode(string $alamat, string $nama): string
{
    $wilayah = salamKodeWilayah($alamat);
    $nama = trim($nama);

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nama);
        if ($ascii !== false) {
            $nama = $ascii;
        }
    }

    $nama = preg_replace('/[^A-Za-z0-9]+/', '', $nama) ?? '';
    if ($nama === '') {
        return '';
    }

    return strtoupper($wilayah) . '-' . $nama;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salamImportExcelResponse(false, 'Metode tidak valid.', 0, 0, [], 0, 0, 405);
}

if (!salamDetailPelangganTableReady($koneksi)) {
    salamImportExcelResponse(
        false,
        'Tabel pelanggan_detail_salam belum tersedia.',
        0,
        0,
        [],
        0,
        0,
        400
    );
}

if (!isset($_FILES['file_import']) || !is_array($_FILES['file_import'])) {
    salamImportExcelResponse(false, 'File Excel belum dipilih.', 0, 0, [], 0, 0, 400);
}

$file = $_FILES['file_import'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    salamImportExcelResponse(
        false,
        'Upload file gagal. Kode error: ' . (int) ($file['error'] ?? -1),
        0,
        0,
        [],
        0,
        0,
        400
    );
}

$fileSize = (int) ($file['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > SALAM_IMPORT_EXCEL_MAX_BYTES) {
    salamImportExcelResponse(false, 'Ukuran file maksimal 5 MB.', 0, 0, [], 0, 0, 400);
}

$extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    salamImportExcelResponse(false, 'Format file harus Excel .xlsx.', 0, 0, [], 0, 0, 400);
}

$path = (string) $file['tmp_name'];
$shared = salamImportSharedStrings($path);
$sheets = salamImportWorkbookSheets($path);

$customerEntry = salamImportFindSheetEntry($sheets, ['Data Pelanggan', 'Import Pelanggan']);
$historyEntry = salamImportFindSheetEntry($sheets, ['Riwayat Tagihan', 'Riwayat Pembayaran']);

if ($customerEntry === null) {
    // Untuk kompatibilitas file lama, gunakan sheet pertama sebagai Data Pelanggan.
    $customerEntry = reset($sheets) ?: null;
}

if ($customerEntry === null) {
    salamImportExcelResponse(false, 'Sheet Data Pelanggan tidak ditemukan.', 0, 0, [], 0, 0, 400);
}

$customerRows = salamImportReadSheetRows(
    $path,
    $customerEntry,
    $shared,
    SALAM_IMPORT_CUSTOMER_MAX_ROWS
);

$historyRows = $historyEntry !== null
    ? salamImportReadSheetRows($path, $historyEntry, $shared, SALAM_IMPORT_HISTORY_MAX_ROWS)
    : [];

if (count($customerRows) > SALAM_IMPORT_CUSTOMER_MAX_ROWS + 1) {
    salamImportExcelResponse(false, 'Maksimal 1.000 pelanggan per import.', 0, 0, [], 0, 0, 400);
}

if (count($historyRows) > SALAM_IMPORT_HISTORY_MAX_ROWS + 1) {
    salamImportExcelResponse(false, 'Maksimal 10.000 baris riwayat tagihan per import.', 0, 0, [], 0, 0, 400);
}

$errors = [];
$imported = 0;
$skipped = 0;
$historyImported = 0;
$historySkipped = 0;

// ============================================================
// 1. IMPORT DATA PELANGGAN
// ============================================================
$customerMap = [];
if ($customerRows) {
    $customerHeaders = array_shift($customerRows);
    $customerMap = salamImportMapHeaders($customerHeaders, [
        'id_pelanggan' => ['ID Pelanggan', 'IDPel', 'Customer ID'],
        'nama' => ['Nama Pelanggan', 'Nama Lengkap', 'Nama', 'Customer Name'],
        'nama_ktp' => ['Nama KTP', 'Nama Sesuai KTP', 'Nama Identitas'],
        'nik' => ['NIK', 'Nomor NIK', 'No KTP'],
        'nomor_pelanggan' => ['Nomor WhatsApp', 'No WhatsApp', 'WhatsApp', 'Nomor HP', 'No HP', 'Telepon'],
        'kode_pelanggan' => ['Kode Pelanggan', 'Kode', 'Customer Code'],
        'alamat' => ['Alamat', 'Wilayah', 'Address'],
        'paket' => ['Paket', 'Paket Layanan', 'Package'],
        'tagihan' => ['Tarif Langganan', 'Tarif', 'Tagihan', 'Harga', 'Nominal'],
    ]);

    $required = ['nama', 'alamat', 'paket', 'tagihan'];
    $missing = [];
    foreach ($required as $field) {
        if (!isset($customerMap[$field])) {
            $missing[] = $field;
        }
    }

    if ($missing) {
        $labels = [
            'nama' => 'Nama Pelanggan',
            'alamat' => 'Alamat/Wilayah',
            'paket' => 'Paket',
            'tagihan' => 'Tarif Langganan',
        ];
        $missingLabels = array_map(
            static fn(string $field): string => $labels[$field] ?? $field,
            $missing
        );
        salamImportExcelResponse(
            false,
            'Kolom wajib pada sheet Data Pelanggan tidak ditemukan: ' . implode(', ', $missingLabels) . '.',
            0,
            0,
            [],
            0,
            0,
            400
        );
    }
}

$insertCustomer = $koneksi->prepare(
    'INSERT INTO pelanggan_salam
     (id_pelanggan, kode_pelanggan, nama, nomor_pelanggan, alamat, paket, tarif_langganan, tagihan)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$fixCustomer = $koneksi->prepare(
    'UPDATE pelanggan_salam SET id_pelanggan = ?, nomor_invoice = ? WHERE id = ?'
);
$checkCustomerId = $koneksi->prepare(
    'SELECT id FROM pelanggan_salam WHERE id_pelanggan = ? LIMIT 1'
);
$checkCustomerCode = $koneksi->prepare(
    'SELECT id FROM pelanggan_salam WHERE kode_pelanggan = ? AND alamat = ? LIMIT 1'
);

if (!$insertCustomer || !$fixCustomer || !$checkCustomerId || !$checkCustomerCode) {
    salamImportExcelResponse(false, 'Gagal menyiapkan proses import pelanggan.', 0, 0, [], 0, 0, 500);
}

foreach ($customerRows as $index => $row) {
    $excelRow = $index + 2;
    $nonEmpty = array_filter($row, static fn($value): bool => trim((string) $value) !== '');
    if (!$nonEmpty) {
        continue;
    }

    $idPelanggan = salamImportRowValue($row, $customerMap, 'id_pelanggan');
    $nama = salamImportRowValue($row, $customerMap, 'nama');
    $namaKtp = salamImportRowValue($row, $customerMap, 'nama_ktp');
    $nik = preg_replace('/\s+/', '', salamImportRowValue($row, $customerMap, 'nik')) ?? '';
    $nomor = preg_replace('/\s+/', '', salamImportRowValue($row, $customerMap, 'nomor_pelanggan')) ?? '';
    $alamat = salamNormalisasiAlamatInput(salamImportRowValue($row, $customerMap, 'alamat'));
    $paket = salamImportRowValue($row, $customerMap, 'paket');
    $tarif = salamImportNominal(salamImportRowValue($row, $customerMap, 'tagihan'));
    $kodePelanggan = salamImportRowValue($row, $customerMap, 'kode_pelanggan');

    if ($nama === '' && $namaKtp !== '') {
        $nama = $namaKtp;
    }
    if ($namaKtp === '') {
        $namaKtp = $nama;
    }

    if ($nama === '' || $alamat === '' || $paket === '' || $tarif === null || $tarif < 0) {
        $skipped++;
        $errors[] = "Data Pelanggan baris {$excelRow}: Nama, Alamat, Paket, dan Tarif wajib valid.";
        continue;
    }

    if ($nik !== '' && !preg_match('/^[0-9]{8,32}$/', $nik)) {
        $skipped++;
        $errors[] = "Data Pelanggan baris {$excelRow}: NIK hanya boleh 8-32 angka.";
        continue;
    }

    if ($kodePelanggan === '') {
        // Admin tidak wajib mengetahui kode teknis. Sistem membuat kode sederhana dari wilayah + nama layanan.
        $kodePelanggan = salamImportGenerateCustomerCode($alamat, $nama);
    }

    if ($idPelanggan !== '') {
        $checkCustomerId->bind_param('s', $idPelanggan);
        $checkCustomerId->execute();
        if ($checkCustomerId->get_result()->fetch_assoc()) {
            $skipped++;
            $errors[] = "Data Pelanggan baris {$excelRow}: ID {$idPelanggan} sudah ada, pelanggan tidak diduplikasi.";
            continue;
        }
    }

    if ($kodePelanggan !== '') {
        $checkCustomerCode->bind_param('ss', $kodePelanggan, $alamat);
        $checkCustomerCode->execute();
        if ($checkCustomerCode->get_result()->fetch_assoc()) {
            $skipped++;
            $errors[] = "Data Pelanggan baris {$excelRow}: Kode {$kodePelanggan} di wilayah {$alamat} sudah ada, pelanggan tidak diduplikasi.";
            continue;
        }
    }

    $koneksi->begin_transaction();
    try {
        $insertCustomer->bind_param(
            'ssssssdd',
            $idPelanggan,
            $kodePelanggan,
            $nama,
            $nomor,
            $alamat,
            $paket,
            $tarif,
            $tarif
        );

        if (!$insertCustomer->execute()) {
            throw new RuntimeException('Gagal menyimpan pelanggan: ' . $insertCustomer->error);
        }

        $pelangganDbId = (int) $insertCustomer->insert_id;
        $kodeWilayah = salamKodeWilayah($alamat);
        $generatedId = $idPelanggan !== ''
            ? $idPelanggan
            : $kodeWilayah . '-' . str_pad((string) $pelangganDbId, 5, '0', STR_PAD_LEFT);

        $invoice = $kodeWilayah
            . '/INV/'
            . str_pad((string) $pelangganDbId, 5, '0', STR_PAD_LEFT)
            . '/'
            . date('m/Y');

        $fixCustomer->bind_param('ssi', $generatedId, $invoice, $pelangganDbId);
        if (!$fixCustomer->execute()) {
            throw new RuntimeException('Gagal melengkapi ID pelanggan: ' . $fixCustomer->error);
        }

        salamUpsertPelangganDetailBilling(
            $koneksi,
            $pelangganDbId,
            $namaKtp,
            $nik,
            ''
        );

        $koneksi->commit();
        $imported++;
    } catch (Throwable $e) {
        $koneksi->rollback();
        $skipped++;
        $errors[] = "Data Pelanggan baris {$excelRow}: " . $e->getMessage();
    }
}

$insertCustomer->close();
$fixCustomer->close();
$checkCustomerId->close();
$checkCustomerCode->close();

// ============================================================
// 2. IMPORT RIWAYAT TAGIHAN (OPSIONAL)
// ============================================================
if ($historyRows) {
    $historyHeaders = array_shift($historyRows);
    $historyMap = salamImportMapHeaders($historyHeaders, [
        'id_pelanggan' => ['ID Pelanggan', 'IDPel', 'Customer ID'],
        'periode' => ['Periode', 'Bulan', 'Periode Tagihan'],
        'status_bayar' => ['Status Bayar', 'Status Pembayaran', 'Status'],
        'nominal_tagihan' => ['Nominal Tagihan', 'Tagihan', 'Nominal', 'Tarif'],
        'tanggal_bayar' => ['Tanggal Bayar', 'Tgl Bayar', 'Paid Date'],
    ]);

    $historyRequired = ['id_pelanggan', 'periode', 'status_bayar'];
    $historyMissing = [];
    foreach ($historyRequired as $field) {
        if (!isset($historyMap[$field])) {
            $historyMissing[] = $field;
        }
    }

    if ($historyMissing) {
        $labels = [
            'id_pelanggan' => 'ID Pelanggan',
            'periode' => 'Periode',
            'status_bayar' => 'Status Bayar',
        ];
        $historyMissingLabels = array_map(
            static fn(string $field): string => $labels[$field] ?? $field,
            $historyMissing
        );
        $errors[] = 'Sheet Riwayat Tagihan dilewati karena kolom wajib tidak lengkap: '
            . implode(', ', $historyMissingLabels) . '.';
        $historyRows = [];
    }

    $findCustomer = $koneksi->prepare(
        'SELECT id, id_pelanggan, nama, alamat, paket, tarif_langganan
         FROM pelanggan_salam
         WHERE id_pelanggan = ?
         LIMIT 1'
    );
    $checkHistory = $koneksi->prepare(
        'SELECT id FROM tagihan_salam WHERE pelanggan_id = ? AND periode = ? LIMIT 1'
    );
    $insertHistory = $koneksi->prepare(
        'INSERT INTO tagihan_salam
        (pelanggan_id, periode, id_pelanggan_snapshot, nama_snapshot, alamat_snapshot,
         paket_snapshot, tarif_snapshot, nominal_tagihan, status_bayar,
         tanggal_jatuh_tempo, tanggal_bayar, nominal_dibayar, nomor_invoice)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $updateRegistration = $koneksi->prepare(
        'UPDATE pelanggan_salam
         SET tanggal_daftar = CASE
             WHEN tanggal_daftar IS NULL OR tanggal_daftar > ? THEN ?
             ELSE tanggal_daftar END
         WHERE id = ?'
    );
    $syncCurrent = $koneksi->prepare(
        'UPDATE pelanggan_salam
         SET waktu = ?,
             langganan_selesai = ?,
             status_bayar = ?,
             tagihan = ?,
             tanggal_bayar = ?,
             nominal_dibayar = ?,
             nomor_invoice = ?
         WHERE id = ?'
    );

    if (!$findCustomer || !$checkHistory || !$insertHistory || !$updateRegistration || !$syncCurrent) {
        salamImportExcelResponse(
            false,
            'Gagal menyiapkan proses import riwayat tagihan.',
            $imported,
            $skipped,
            $errors,
            0,
            0,
            500
        );
    }

    foreach ($historyRows as $index => $row) {
        $excelRow = $index + 2;
        $nonEmpty = array_filter($row, static fn($value): bool => trim((string) $value) !== '');
        if (!$nonEmpty) {
            continue;
        }

        $idPelanggan = salamImportRowValue($row, $historyMap, 'id_pelanggan');
        $periode = salamImportNormalizePeriod(salamImportRowValue($row, $historyMap, 'periode'));
        $statusBayar = salamImportNormalizeStatus(salamImportRowValue($row, $historyMap, 'status_bayar'));
        $nominalInput = salamImportNominal(salamImportRowValue($row, $historyMap, 'nominal_tagihan'));
        $tanggalBayarRaw = salamImportRowValue($row, $historyMap, 'tanggal_bayar');
        $tanggalBayar = $tanggalBayarRaw !== '' ? salamImportNormalizeDate($tanggalBayarRaw) : null;

        if ($idPelanggan === '' || $periode === null || $statusBayar === null) {
            $historySkipped++;
            $errors[] = "Riwayat Tagihan baris {$excelRow}: ID Pelanggan, Periode, dan Status Bayar wajib valid.";
            continue;
        }

        if ($tanggalBayarRaw !== '' && $tanggalBayar === null) {
            $historySkipped++;
            $errors[] = "Riwayat Tagihan baris {$excelRow}: Tanggal Bayar harus YYYY-MM-DD atau DD/MM/YYYY.";
            continue;
        }

        $findCustomer->bind_param('s', $idPelanggan);
        $findCustomer->execute();
        $customer = $findCustomer->get_result()->fetch_assoc();

        if (!$customer) {
            $historySkipped++;
            $errors[] = "Riwayat Tagihan baris {$excelRow}: pelanggan {$idPelanggan} tidak ditemukan.";
            continue;
        }

        $pelangganDbId = (int) $customer['id'];
        $checkHistory->bind_param('is', $pelangganDbId, $periode);
        $checkHistory->execute();
        if ($checkHistory->get_result()->fetch_assoc()) {
            $historySkipped++;
            $errors[] = "Riwayat Tagihan baris {$excelRow}: {$idPelanggan} periode "
                . date('m/Y', strtotime($periode))
                . ' sudah ada, tidak diduplikasi.';
            continue;
        }

        $tarifSnapshot = (float) $customer['tarif_langganan'];
        $nominalTagihan = $nominalInput !== null ? $nominalInput : $tarifSnapshot;
        if ($nominalTagihan < 0) {
            $historySkipped++;
            $errors[] = "Riwayat Tagihan baris {$excelRow}: Nominal Tagihan tidak valid.";
            continue;
        }

        $jatuhTempo = date('Y-m-t', strtotime($periode));
        $nominalDibayar = $statusBayar === 'Lunas' ? $nominalTagihan : null;
        if ($statusBayar !== 'Lunas') {
            $tanggalBayar = null;
        }

        $kodeWilayah = salamKodeWilayah((string) $customer['alamat']);
        $invoice = $kodeWilayah
            . '/INV/'
            . str_pad((string) $pelangganDbId, 5, '0', STR_PAD_LEFT)
            . '/'
            . date('m/Y', strtotime($periode));

        $koneksi->begin_transaction();
        try {
            $historyIdSnapshot = (string) $customer['id_pelanggan'];
            $historyNamaSnapshot = (string) $customer['nama'];
            $historyAlamatSnapshot = (string) $customer['alamat'];
            $historyPaketSnapshot = (string) $customer['paket'];

            $insertHistory->bind_param(
                'isssssddsssds',
                $pelangganDbId,
                $periode,
                $historyIdSnapshot,
                $historyNamaSnapshot,
                $historyAlamatSnapshot,
                $historyPaketSnapshot,
                $tarifSnapshot,
                $nominalTagihan,
                $statusBayar,
                $jatuhTempo,
                $tanggalBayar,
                $nominalDibayar,
                $invoice
            );

            if (!$insertHistory->execute()) {
                throw new RuntimeException('Gagal menyimpan riwayat: ' . $insertHistory->error);
            }

            $updateRegistration->bind_param('ssi', $periode, $periode, $pelangganDbId);
            if (!$updateRegistration->execute()) {
                throw new RuntimeException('Gagal memperbarui tanggal daftar: ' . $updateRegistration->error);
            }

            // Jika riwayat yang diimpor adalah bulan berjalan, sinkronkan kondisi dashboard.
            if ($periode === date('Y-m-01')) {
                $currentEnd = date('Y-m-t', strtotime($periode));
                $currentTagihan = $statusBayar === 'Lunas' ? 0.0 : $nominalTagihan;
                $syncCurrent->bind_param(
                    'sssdsdsi',
                    $periode,
                    $currentEnd,
                    $statusBayar,
                    $currentTagihan,
                    $tanggalBayar,
                    $nominalDibayar,
                    $invoice,
                    $pelangganDbId
                );
                if (!$syncCurrent->execute()) {
                    throw new RuntimeException('Gagal menyinkronkan bulan berjalan: ' . $syncCurrent->error);
                }
            }

            $koneksi->commit();
            $historyImported++;
        } catch (Throwable $e) {
            $koneksi->rollback();
            $historySkipped++;
            $errors[] = "Riwayat Tagihan baris {$excelRow}: " . $e->getMessage();
        }
    }

    $findCustomer->close();
    $checkHistory->close();
    $insertHistory->close();
    $updateRegistration->close();
    $syncCurrent->close();
}

$koneksi->close();

if ($imported === 0 && $historyImported === 0 && $skipped === 0 && $historySkipped === 0) {
    salamImportExcelResponse(
        false,
        'Tidak ada data yang diisi. Isi sheet Data Pelanggan dan/atau Riwayat Tagihan.',
        0,
        0,
        $errors,
        0,
        0,
        400
    );
}

$message = "Import selesai. Pelanggan baru: {$imported}; riwayat tagihan: {$historyImported}.";
if ($skipped > 0 || $historySkipped > 0) {
    $message .= " Dilewati: {$skipped} pelanggan dan {$historySkipped} riwayat.";
}

salamImportExcelResponse(
    true,
    $message,
    $imported,
    $skipped,
    $errors,
    $historyImported,
    $historySkipped
);
?>
