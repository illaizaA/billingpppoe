<?php
session_start();

require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/pelanggan_detail_helper.php';

header('Content-Type: application/json; charset=utf-8');

salamRequireSuperAdmin();

const SALAM_IMPORT_EXCEL_MAX_BYTES = 5242880;
const SALAM_IMPORT_EXCEL_MAX_ROWS = 1000;

function salamImportExcelResponse(
    bool $success,
    string $message,
    int $imported = 0,
    int $skipped = 0,
    array $errors = [],
    int $httpCode = 200
): void {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => array_slice(array_values($errors), 0, 30),
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

function salamImportMapHeaders(array $headers): array
{
    $aliases = [
        'id_pelanggan' => ['idpelanggan', 'idpel', 'customerid', 'idcustomer'],
        'nama' => ['namalengkap', 'namapelanggan', 'nama', 'customername'],
        'paket' => ['paket', 'paketlayanan', 'package'],
        'nomor_pelanggan' => [
            'nomorwhatsapp', 'nowhatsapp', 'whatsapp', 'nomorhp',
            'nohp', 'nomortelepon', 'telepon', 'nomorpelanggan'
        ],
        'kode_pelanggan' => ['kodepelanggan', 'kode', 'customercode'],
        'alamat' => ['alamat', 'wilayah', 'address', 'lokasi'],
        'tagihan' => [
            'tariflangganan', 'tagihanawal', 'tagihan',
            'tarif', 'harga', 'nominal'
        ],
        'nama_ktp' => ['namaktp', 'namasesuaiktp', 'namaidentitas'],
        'nik' => ['nik', 'nomornik', 'noktp'],
    ];

    $lookup = [];
    foreach ($aliases as $field => $items) {
        foreach ($items as $item) {
            $lookup[$item] = $field;
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

function salamImportXlsx(string $path): array
{
    $sheetRaw = salamImportArchiveRead($path, 'xl/worksheets/sheet1.xml');

    if ($sheetRaw === false) {
        throw new RuntimeException(
            'File Excel tidak dapat dibaca. Pastikan format file .xlsx.'
        );
    }

    $shared = [];
    $sharedRaw = salamImportArchiveRead($path, 'xl/sharedStrings.xml');

    if ($sharedRaw !== false) {
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

            if (count($rows) > SALAM_IMPORT_EXCEL_MAX_ROWS + 1) {
                break;
            }
        }
    }

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    salamImportExcelResponse(false, 'Metode tidak valid.', 0, 0, [], 405);
}

if (!salamDetailPelangganTableReady($koneksi)) {
    salamImportExcelResponse(
        false,
        'Tabel pelanggan_detail_salam belum tersedia. Pasang database fitur profil pelanggan terlebih dahulu.',
        0,
        0,
        [],
        400
    );
}

if (!isset($_FILES['file_import']) || !is_array($_FILES['file_import'])) {
    salamImportExcelResponse(false, 'File Excel belum dipilih.', 0, 0, [], 400);
}

$file = $_FILES['file_import'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    salamImportExcelResponse(
        false,
        'Upload file gagal. Kode error: ' . (int) ($file['error'] ?? -1),
        0,
        0,
        [],
        400
    );
}

$fileSize = (int) ($file['size'] ?? 0);

if ($fileSize <= 0 || $fileSize > SALAM_IMPORT_EXCEL_MAX_BYTES) {
    salamImportExcelResponse(
        false,
        'Ukuran file harus lebih dari 0 dan maksimal 5 MB.',
        0,
        0,
        [],
        400
    );
}

$originalName = (string) ($file['name'] ?? '');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if ($extension !== 'xlsx') {
    salamImportExcelResponse(
        false,
        'Format file harus Excel .xlsx.',
        0,
        0,
        [],
        400
    );
}

try {
    $rows = salamImportXlsx((string) $file['tmp_name']);
} catch (Throwable $e) {
    salamImportExcelResponse(false, $e->getMessage(), 0, 0, [], 400);
}

if (count($rows) < 2) {
    salamImportExcelResponse(
        false,
        'File belum berisi data pelanggan. Gunakan template yang disediakan.',
        0,
        0,
        [],
        400
    );
}

if (count($rows) > SALAM_IMPORT_EXCEL_MAX_ROWS + 1) {
    salamImportExcelResponse(
        false,
        'Maksimal ' . SALAM_IMPORT_EXCEL_MAX_ROWS . ' pelanggan dalam sekali import.',
        0,
        0,
        [],
        400
    );
}

$headers = array_shift($rows);
$map = salamImportMapHeaders($headers);

$required = ['paket', 'alamat', 'tagihan'];
$missing = [];

foreach ($required as $field) {
    if (!isset($map[$field])) {
        $missing[] = $field;
    }
}

if (!isset($map['nama']) && !isset($map['nama_ktp'])) {
    $missing[] = 'nama_atau_ktp';
}

if ($missing) {
    $labels = [
        'paket' => 'Paket',
        'alamat' => 'Alamat',
        'tagihan' => 'Tarif Langganan',
        'nama_atau_ktp' => 'Nama Lengkap atau Nama KTP',
    ];

    $missingLabels = array_map(
        static fn(string $field): string => $labels[$field] ?? $field,
        $missing
    );

    salamImportExcelResponse(
        false,
        'Kolom wajib tidak ditemukan: ' . implode(', ', $missingLabels) . '. Gunakan template import.',
        0,
        0,
        [],
        400
    );
}

$insert = $koneksi->prepare(
    'INSERT INTO pelanggan_salam
     (id_pelanggan, kode_pelanggan, nama, nomor_pelanggan, alamat, paket, tarif_langganan, tagihan)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

$fix = $koneksi->prepare(
    'UPDATE pelanggan_salam
     SET id_pelanggan = ?, nomor_invoice = ?
     WHERE id = ?'
);

$checkId = $koneksi->prepare(
    'SELECT id
     FROM pelanggan_salam
     WHERE id_pelanggan = ?
     LIMIT 1'
);

if (!$insert || !$fix || !$checkId) {
    salamImportExcelResponse(
        false,
        'Gagal menyiapkan proses import database.',
        0,
        0,
        [],
        500
    );
}

$imported = 0;
$skipped = 0;
$errors = [];

foreach ($rows as $index => $row) {
    $excelRow = $index + 2;

    $nonEmpty = array_filter(
        $row,
        static fn($value): bool => trim((string) $value) !== ''
    );

    if (!$nonEmpty) {
        continue;
    }

    $idPelanggan = salamImportRowValue($row, $map, 'id_pelanggan');
    $kodePelanggan = salamImportRowValue($row, $map, 'kode_pelanggan');
    $nama = salamImportRowValue($row, $map, 'nama');
    $namaKtp = salamImportRowValue($row, $map, 'nama_ktp');

    // Jika file survey hanya memiliki Nama KTP, gunakan sebagai Nama Lengkap Billing.
    if ($nama === '') {
        $nama = $namaKtp;
    }
    // Jika Nama KTP kosong tetapi Nama Lengkap tersedia, gunakan nama tersebut sebagai default.
    if ($namaKtp === '') {
        $namaKtp = $nama;
    }

    $paket = salamImportRowValue($row, $map, 'paket');
    $nomor = salamImportRowValue($row, $map, 'nomor_pelanggan');
    $alamat = salamNormalisasiAlamatInput(
        salamImportRowValue($row, $map, 'alamat')
    );
    $tarif = salamImportNominal(
        salamImportRowValue($row, $map, 'tagihan')
    );

    $nik = preg_replace(
        '/\s+/',
        '',
        salamImportRowValue($row, $map, 'nik')
    ) ?? '';

    // Foto Rumah dan seluruh data teknis PPPoE sengaja TIDAK dibaca dari Excel.
    // Billing menyesuaikan otomatis ke PPPoE asli; foto hanya ditambahkan dari aplikasi Billing.

    if ($nama === '') {
        $skipped++;
        $errors[] = "Baris {$excelRow}: Nama Lengkap atau Nama KTP wajib diisi.";
        continue;
    }

    if ($paket === '') {
        $skipped++;
        $errors[] = "Baris {$excelRow}: Paket wajib diisi.";
        continue;
    }

    if ($alamat === '') {
        $skipped++;
        $errors[] = "Baris {$excelRow}: Alamat wajib diisi.";
        continue;
    }

    if ($tarif === null || $tarif < 0) {
        $skipped++;
        $errors[] = "Baris {$excelRow}: Tarif Langganan tidak valid.";
        continue;
    }

    if ($nik !== '' && !preg_match('/^[0-9]{8,32}$/', $nik)) {
        $skipped++;
        $errors[] = "Baris {$excelRow}: NIK hanya boleh berisi 8-32 angka.";
        continue;
    }

    if ($idPelanggan !== '') {
        $checkId->bind_param('s', $idPelanggan);
        $checkId->execute();
        $existingId = $checkId->get_result()->fetch_assoc();

        if ($existingId) {
            $skipped++;
            $errors[] = "Baris {$excelRow}: ID Pelanggan {$idPelanggan} sudah ada.";
            continue;
        }
    }

    $koneksi->begin_transaction();

    try {
        $insert->bind_param(
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

        if (!$insert->execute()) {
            throw new RuntimeException('Gagal menyimpan pelanggan: ' . $insert->error);
        }

        $id = (int) $insert->insert_id;
        $kodeWilayah = salamKodeWilayah($alamat);

        $generatedId = $idPelanggan !== ''
            ? $idPelanggan
            : $kodeWilayah . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);

        $invoice = $kodeWilayah
            . '/INV/'
            . str_pad((string) $id, 5, '0', STR_PAD_LEFT)
            . '/'
            . date('m/Y');

        $fix->bind_param('ssi', $generatedId, $invoice, $id);

        if (!$fix->execute()) {
            throw new RuntimeException('Gagal melengkapi ID pelanggan: ' . $fix->error);
        }

        salamUpsertPelangganDetailBilling(
            $koneksi,
            $id,
            $namaKtp,
            $nik,
            ''
        );

        $koneksi->commit();
        $imported++;
    } catch (Throwable $e) {
        $koneksi->rollback();
        $skipped++;
        $errors[] = "Baris {$excelRow}: " . $e->getMessage();
    }
}

$insert->close();
$fix->close();
$checkId->close();
$koneksi->close();

$message = $imported > 0
    ? "Import selesai. {$imported} pelanggan berhasil ditambahkan."
    : 'Tidak ada pelanggan yang berhasil diimpor.';

salamImportExcelResponse(
    true,
    $message,
    $imported,
    $skipped,
    $errors
);
?>
