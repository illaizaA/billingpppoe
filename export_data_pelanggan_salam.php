<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
require_once __DIR__ . '/config_monitoring_pppoe.php';
require_once __DIR__ . '/pppoe_manual_helper.php';
salamRequireSuperAdmin();

date_default_timezone_set('Asia/Jakarta');

/**
 * Mengambil koordinat PPPoE untuk pelanggan Billing tanpa mengubah data apa pun.
 * Prioritas pasangan dibuat sama dengan halaman monitoring:
 * 1. pasangan manual (pppoe_user), 2. nama pelanggan + wilayah,
 * 3. nama KTP + wilayah, 4. koordinat Billing dalam radius 30 meter.
 *
 * Jika endpoint PPPoE sedang tidak dapat dihubungi, export tetap dilanjutkan
 * dan kolom koordinat PPPoE akan berisi tanda "-".
 */
function arsipKoordinatPppoePerPelanggan(mysqli $koneksi): array
{
    try {
        $pppoes = salamManualFetchPppoe();
    } catch (Throwable $e) {
        return [];
    }

    $result = $koneksi->query(
        "SELECT p.id, p.nama, p.alamat,
                COALESCE(d.nama_ktp, '') AS nama_ktp,
                COALESCE(d.pppoe_user, '') AS pppoe_user,
                d.koordinat_x, d.koordinat_y
         FROM pelanggan_salam p
         LEFT JOIN pelanggan_detail_salam d ON d.pelanggan_id = p.id
         ORDER BY p.id"
    );
    if (!$result) return [];

    $billingRows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) ($row['id'] ?? 0);
        if ($row['id'] > 0) $billingRows[] = $row;
    }
    $result->free();

    $pppoeIndexById = [];
    foreach ($pppoes as $pppoeIndex => $pppoeRow) {
        if (!is_array($pppoeRow)) continue;
        $pppoeId = salamManualPppoeId($pppoeRow);
        if ($pppoeId !== '') $pppoeIndexById[$pppoeId] = (int) $pppoeIndex;
    }

    $matches = [];
    $usedBillingIds = [];
    $usedPppoeIndexes = [];

    // Pasangan manual selalu menjadi prioritas pertama.
    foreach ($billingRows as $billingRow) {
        $billingId = (int) $billingRow['id'];
        $manualPppoeId = trim((string) ($billingRow['pppoe_user'] ?? ''));
        if ($manualPppoeId === '' || !isset($pppoeIndexById[$manualPppoeId])) continue;

        $pppoeIndex = $pppoeIndexById[$manualPppoeId];
        if (isset($usedPppoeIndexes[$pppoeIndex])) continue;

        $matches[$pppoeIndex] = $billingRow;
        $usedBillingIds[$billingId] = true;
        $usedPppoeIndexes[$pppoeIndex] = true;
    }

    // Index nama + wilayah untuk pelanggan yang belum mempunyai pasangan manual valid.
    $byCustomerNameAddress = [];
    $byKtpNameAddress = [];
    foreach ($billingRows as $billingRow) {
        $billingId = (int) $billingRow['id'];
        if (isset($usedBillingIds[$billingId])) continue;

        $address = salamManualNormalize($billingRow['alamat'] ?? '');
        if ($address === '') continue;

        $customerName = salamManualNormalize($billingRow['nama'] ?? '');
        if ($customerName !== '') {
            $byCustomerNameAddress[$customerName . '|' . $address][$billingId] = $billingRow;
        }

        $ktpName = salamManualNormalize($billingRow['nama_ktp'] ?? '');
        if ($ktpName !== '') {
            $byKtpNameAddress[$ktpName . '|' . $address][$billingId] = $billingRow;
        }
    }

    foreach ($pppoes as $pppoeIndex => $pppoeRow) {
        $pppoeIndex = (int) $pppoeIndex;
        if (!is_array($pppoeRow) || isset($usedPppoeIndexes[$pppoeIndex])) continue;

        $pairs = salamManualPairs($pppoeRow);
        if (!$pairs) continue;

        $candidates = [];
        foreach ($pairs as $pair) {
            $key = $pair['name'] . '|' . $pair['address'];
            foreach ($byCustomerNameAddress[$key] ?? [] as $billingId => $billingRow) {
                $candidates[(int) $billingId] = $billingRow;
            }
        }

        // Bila tahap nama pelanggan ambigu, jangan menebak dan jangan turun ke Nama KTP.
        if (count($candidates) > 1) continue;

        if (!$candidates) {
            foreach ($pairs as $pair) {
                $key = $pair['name'] . '|' . $pair['address'];
                foreach ($byKtpNameAddress[$key] ?? [] as $billingId => $billingRow) {
                    $candidates[(int) $billingId] = $billingRow;
                }
            }
        }

        if (count($candidates) !== 1) continue;

        $billingRow = array_values($candidates)[0];
        $billingId = (int) $billingRow['id'];
        if (isset($usedBillingIds[$billingId])) continue;

        $matches[$pppoeIndex] = $billingRow;
        $usedBillingIds[$billingId] = true;
        $usedPppoeIndexes[$pppoeIndex] = true;
    }

    // Fallback terakhir: satu-satunya titik PPPoE pada wilayah sama dalam radius 30 meter.
    $coordinateClaims = [];
    foreach ($billingRows as $billingRow) {
        $billingId = (int) $billingRow['id'];
        if (isset($usedBillingIds[$billingId])) continue;

        $billingLongitude = $billingRow['koordinat_x'] ?? null;
        $billingLatitude = $billingRow['koordinat_y'] ?? null;
        $billingAddress = salamManualNormalize($billingRow['alamat'] ?? '');
        if (!is_numeric($billingLongitude) || !is_numeric($billingLatitude) || $billingAddress === '') continue;

        $candidates = [];
        foreach ($pppoes as $pppoeIndex => $pppoeRow) {
            $pppoeIndex = (int) $pppoeIndex;
            if (!is_array($pppoeRow) || isset($usedPppoeIndexes[$pppoeIndex])) continue;

            $sameAddress = false;
            foreach (salamManualPairs($pppoeRow) as $pair) {
                if (($pair['address'] ?? '') === $billingAddress) {
                    $sameAddress = true;
                    break;
                }
            }
            if (!$sameAddress) continue;

            $pppoeLatitude = $pppoeRow['latitude'] ?? $pppoeRow['lat'] ?? null;
            $pppoeLongitude = $pppoeRow['longitude'] ?? $pppoeRow['lng'] ?? $pppoeRow['lon'] ?? null;
            if (!is_numeric($pppoeLatitude) || !is_numeric($pppoeLongitude)) continue;

            $distance = salamManualDistance(
                (float) $billingLatitude,
                (float) $billingLongitude,
                (float) $pppoeLatitude,
                (float) $pppoeLongitude
            );
            if ($distance <= 30.0) $candidates[] = $pppoeIndex;
        }

        if (count($candidates) === 1) {
            $coordinateClaims[$candidates[0]][$billingId] = $billingRow;
        }
    }

    foreach ($coordinateClaims as $pppoeIndex => $billingClaims) {
        $pppoeIndex = (int) $pppoeIndex;
        if (count($billingClaims) !== 1 || isset($usedPppoeIndexes[$pppoeIndex])) continue;
        $billingRow = array_values($billingClaims)[0];
        $billingId = (int) $billingRow['id'];
        if (isset($usedBillingIds[$billingId])) continue;

        $matches[$pppoeIndex] = $billingRow;
        $usedBillingIds[$billingId] = true;
        $usedPppoeIndexes[$pppoeIndex] = true;
    }

    $coordinates = [];
    foreach ($matches as $pppoeIndex => $billingRow) {
        $pppoeRow = $pppoes[(int) $pppoeIndex] ?? null;
        if (!is_array($pppoeRow)) continue;

        $latitude = $pppoeRow['latitude'] ?? $pppoeRow['lat'] ?? null;
        $longitude = $pppoeRow['longitude'] ?? $pppoeRow['lng'] ?? $pppoeRow['lon'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) continue;

        $coordinates[(int) $billingRow['id']] = [
            'longitude' => (string) $longitude,
            'latitude' => (string) $latitude,
        ];
    }

    return $coordinates;
}

function arsipBind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $refs = [$types];
    foreach ($params as $key => &$value) $refs[] = &$value;
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function arsipXml($value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function arsipHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function arsipKolom(int $number): string
{
    $result = '';
    while ($number > 0) {
        $number--;
        $result = chr(65 + ($number % 26)) . $result;
        $number = intdiv($number, 26);
    }
    return $result;
}

function arsipCell(string $ref, $value, int $style = 0): string
{
    return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
        . arsipXml($value) . '</t></is></c>';
}

function arsipNumberCell(string $ref, $value, int $style = 3): string
{
    $number = is_numeric($value) ? (float) $value : 0;
    return '<c r="' . $ref . '" t="n" s="' . $style . '"><v>' . $number . '</v></c>';
}

function arsipBuatSheet(array $headers, array $rows, array $numericIndexes = []): string
{
    $sheetRows = [];
    $headerCells = '';
    foreach ($headers as $index => $header) {
        $headerCells .= arsipCell(arsipKolom($index + 1) . '1', $header, 1);
    }
    $sheetRows[] = '<row r="1" ht="32" customHeight="1">' . $headerCells . '</row>';

    $rowNumber = 2;
    foreach ($rows as $row) {
        $cells = '';
        foreach (array_values($row) as $index => $value) {
            $ref = arsipKolom($index + 1) . $rowNumber;
            $cells .= in_array($index, $numericIndexes, true)
                ? arsipNumberCell($ref, $value)
                : arsipCell($ref, $value, 2);
        }
        $sheetRows[] = '<row r="' . $rowNumber . '" ht="24" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    if (!$rows) $sheetRows[] = '<row r="2">' . arsipCell('A2', 'Tidak ada data.', 2) . '</row>';
    $lastColumn = arsipKolom(max(1, count($headers)));
    $lastRow = max(2, $rowNumber - 1);

    $cols = '';
    foreach ($headers as $index => $header) {
        $width = min(34, max(12, strlen((string) $header) + 5));
        $col = $index + 1;
        $cols .= '<col min="' . $col . '" max="' . $col . '" width="' . $width . '" customWidth="1"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="20"/><cols>' . $cols . '</cols>'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '<pageMargins left="0.25" right="0.25" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
        . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';
}

function arsipBuatXlsx(array $pelanggan, array $transaksi): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Export Excel membutuhkan ekstensi PHP ZIP. Aktifkan extension=zip lalu restart web server.');
    }

    $pelangganHeaders = [
        'No', 'ID Pelanggan', 'Kode Pelanggan', 'Nama Pelanggan', 'Nama KTP', 'NIK',
        'Nomor WhatsApp', 'Alamat/Wilayah', 'Paket', 'Tarif Langganan', 'Status Pelanggan',
        'Tanggal Daftar', 'Koordinat X / Longitude PPPoE', 'Koordinat Y / Latitude PPPoE',
        'Koordinat X / Longitude Admin', 'Koordinat Y / Latitude Admin'
    ];
    $pelangganRows = [];
    foreach ($pelanggan as $index => $row) {
        $pelangganRows[] = [
            $index + 1,
            $row['id_pelanggan'] ?: '-',
            $row['kode_pelanggan'] ?: '-',
            $row['nama'] ?: '-',
            $row['nama_ktp'] ?: '-',
            $row['nik'] ?: '-',
            $row['nomor_pelanggan'] ?: '-',
            $row['alamat'] ?: '-',
            $row['paket'] ?: '-',
            $row['tarif_langganan'] ?? 0,
            $row['status_pelanggan'] ?: '-',
            $row['tanggal_daftar'] ?: '-',
            isset($row['pppoe_longitude']) && $row['pppoe_longitude'] !== '' ? $row['pppoe_longitude'] : '-',
            isset($row['pppoe_latitude']) && $row['pppoe_latitude'] !== '' ? $row['pppoe_latitude'] : '-',
            $row['koordinat_x'] !== null && $row['koordinat_x'] !== '' ? $row['koordinat_x'] : '-',
            $row['koordinat_y'] !== null && $row['koordinat_y'] !== '' ? $row['koordinat_y'] : '-',
        ];
    }

    $transaksiHeaders = [
        'No', 'ID Pelanggan', 'Nama Pelanggan', 'Alamat', 'Periode', 'Nominal Tagihan',
        'Status Bayar', 'Tanggal Jatuh Tempo', 'Tanggal Bayar', 'Nominal Dibayar', 'Nomor Invoice'
    ];
    $transaksiRows = [];
    foreach ($transaksi as $index => $row) {
        $transaksiRows[] = [
            $index + 1,
            $row['id_pelanggan'] ?: '-',
            $row['nama'] ?: '-',
            $row['alamat'] ?: '-',
            $row['periode'] ?: '-',
            $row['nominal_tagihan'] ?? 0,
            $row['status_bayar'] ?: '-',
            $row['tanggal_jatuh_tempo'] ?: '-',
            $row['tanggal_bayar'] ?: '-',
            $row['nominal_dibayar'] ?? 0,
            $row['nomor_invoice'] ?: '-',
        ];
    }

    $sheet1 = arsipBuatSheet($pelangganHeaders, $pelangganRows, [9]);
    $sheet2 = arsipBuatSheet($transaksiHeaders, $transaksiRows, [5, 9]);
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0"/></numFmts>'
        . '<fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2F80B7"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Data Pelanggan" sheetId="1" r:id="rId1"/><sheet name="Riwayat Transaksi" sheetId="2" r:id="rId2"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    $temp = tempnam(sys_get_temp_dir(), 'arsip_pelanggan_xlsx_');
    if ($temp === false) throw new RuntimeException('Gagal membuat file Excel sementara.');
    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temp);
        throw new RuntimeException('Gagal membentuk file Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
    $zip->close();
    return $temp;
}

function arsipKirimFile(string $path, string $contentType, string $filename): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    @unlink($path);
    exit;
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'excel')));
if (!in_array($format, ['excel', 'pdf'], true)) $format = 'excel';

$filterMode = trim((string) ($_GET['filter_mode'] ?? 'periode'));
if (!in_array($filterMode, ['periode', 'tanggal_bayar'], true)) $filterMode = 'periode';

$currentPeriod = date('Y-m');
$bulanAwal = trim((string) ($_GET['bulan_awal'] ?? substr($currentPeriod, 5, 2)));
$tahunAwal = trim((string) ($_GET['tahun_awal'] ?? substr($currentPeriod, 0, 4)));
$bulanAkhir = trim((string) ($_GET['bulan_akhir'] ?? substr($currentPeriod, 5, 2)));
$tahunAkhir = trim((string) ($_GET['tahun_akhir'] ?? substr($currentPeriod, 0, 4)));
$periodeAwal = preg_match('/^(0?[1-9]|1[0-2])$/', $bulanAwal) && preg_match('/^\d{4}$/', $tahunAwal)
    ? sprintf('%04d-%02d', (int) $tahunAwal, (int) $bulanAwal) : $currentPeriod;
$periodeAkhir = preg_match('/^(0?[1-9]|1[0-2])$/', $bulanAkhir) && preg_match('/^\d{4}$/', $tahunAkhir)
    ? sprintf('%04d-%02d', (int) $tahunAkhir, (int) $bulanAkhir) : $currentPeriod;
if ($periodeAwal > $periodeAkhir) [$periodeAwal, $periodeAkhir] = [$periodeAkhir, $periodeAwal];

$today = date('Y-m-d');
$rawTanggalAwal = trim((string) ($_GET['tanggal_awal'] ?? $today));
$rawTanggalAkhir = trim((string) ($_GET['tanggal_akhir'] ?? $today));
$validDate = static function (string $value): bool {
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date instanceof DateTime && $date->format('Y-m-d') === $value;
};
$tanggalAwal = $validDate($rawTanggalAwal) ? $rawTanggalAwal : $today;
$tanggalAkhir = $validDate($rawTanggalAkhir) ? $rawTanggalAkhir : $today;
if ($tanggalAwal > $tanggalAkhir) [$tanggalAwal, $tanggalAkhir] = [$tanggalAkhir, $tanggalAwal];

$statusPelanggan = (string) ($_GET['status_pelanggan'] ?? 'all');
$statusBayar = (string) ($_GET['status_bayar'] ?? 'all');
if ($filterMode === 'tanggal_bayar') $statusBayar = 'Lunas';
$alamat = trim((string) ($_GET['alamat'] ?? 'all'));
$search = trim((string) ($_GET['search'] ?? ''));
$conditions = ['1=1'];
$types = '';
$params = [];
if (in_array($statusPelanggan, ['Aktif', 'Tidak Aktif'], true)) {
    $conditions[] = 'p.status_pelanggan = ?';
    $types .= 's'; $params[] = $statusPelanggan;
} else $statusPelanggan = 'all';
if ($alamat !== '' && $alamat !== 'all') {
    $alamat = salamNormalisasiAlamatInput($alamat);
    $conditions[] = salamSqlNormalisasiAlamat('p.alamat') . ' = ?';
    $types .= 's'; $params[] = salamNormalisasiKunci($alamat);
} else $alamat = 'all';
if ($search !== '') {
    $like = '%' . $search . '%';
    $conditions[] = '(p.nama LIKE ? OR p.id_pelanggan LIKE ? OR COALESCE(p.kode_pelanggan,\'\') LIKE ? OR COALESCE(p.nomor_pelanggan,\'\') LIKE ? OR COALESCE(p.alamat,\'\') LIKE ? OR COALESCE(p.paket,\'\') LIKE ? OR COALESCE(d.nama_ktp,\'\') LIKE ? OR COALESCE(d.nik,\'\') LIKE ?)';
    $types .= 'ssssssss';
    for ($i = 0; $i < 8; $i++) $params[] = $like;
}

$pelangganSql = 'SELECT p.id, p.id_pelanggan, COALESCE(p.kode_pelanggan,\'\') kode_pelanggan,
    p.nama, COALESCE(p.nomor_pelanggan,\'\') nomor_pelanggan, COALESCE(p.alamat,\'\') alamat,
    p.paket, p.tarif_langganan, p.status_pelanggan, p.tanggal_daftar,
    COALESCE(d.nama_ktp,\'\') nama_ktp, COALESCE(d.nik,\'\') nik,
    d.koordinat_x, d.koordinat_y
    FROM pelanggan_salam p LEFT JOIN pelanggan_detail_salam d ON d.pelanggan_id=p.id
    WHERE ' . implode(' AND ', $conditions) . ' ORDER BY p.alamat, p.nama, p.id';
$stmt = $koneksi->prepare($pelangganSql);
if (!$stmt) { http_response_code(500); exit('Query data pelanggan gagal disiapkan.'); }
arsipBind($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();
$pelanggan = [];
while ($row = $result->fetch_assoc()) $pelanggan[] = $row;
$stmt->close();

// Gabungkan koordinat PPPoE secara read-only ke data yang akan diekspor.
// Kolom koordinat Billing (input admin) tetap disimpan terpisah dan tidak ditimpa.
$pppoeCoordinates = arsipKoordinatPppoePerPelanggan($koneksi);
foreach ($pelanggan as &$pelangganRow) {
    $coordinate = $pppoeCoordinates[(int) ($pelangganRow['id'] ?? 0)] ?? [];
    $pelangganRow['pppoe_longitude'] = $coordinate['longitude'] ?? null;
    $pelangganRow['pppoe_latitude'] = $coordinate['latitude'] ?? null;
}
unset($pelangganRow);

$transaksi = [];
$ids = array_map(fn($row) => (int) $row['id'], $pelanggan);
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statusBayarSql = in_array($statusBayar, ['Lunas', 'Belum Lunas'], true)
        ? ' AND x.status_bayar = ?' : '';
    $currentRangeSql = $filterMode === 'tanggal_bayar'
        ? 'p.tanggal_bayar BETWEEN ? AND ?'
        : "DATE_FORMAT(p.waktu,'%Y-%m') BETWEEN ? AND ?";
    $historyRangeSql = $filterMode === 'tanggal_bayar'
        ? 't.tanggal_bayar BETWEEN ? AND ?'
        : "DATE_FORMAT(t.periode,'%Y-%m') BETWEEN ? AND ?";
    $sql = "SELECT * FROM (
        SELECT p.id pelanggan_id, p.id_pelanggan, p.nama, p.alamat, p.waktu periode,
            p.tagihan nominal_tagihan, p.status_bayar, p.langganan_selesai tanggal_jatuh_tempo,
            p.tanggal_bayar, p.nominal_dibayar, p.nomor_invoice
        FROM pelanggan_salam p
        WHERE {$currentRangeSql}
        UNION ALL
        SELECT t.pelanggan_id, t.id_pelanggan_snapshot, t.nama_snapshot, t.alamat_snapshot,
            t.periode, t.nominal_tagihan, t.status_bayar, t.tanggal_jatuh_tempo,
            t.tanggal_bayar, t.nominal_dibayar, t.nomor_invoice
        FROM tagihan_salam t
        WHERE {$historyRangeSql}
          AND NOT EXISTS (SELECT 1 FROM pelanggan_salam p2 WHERE p2.id=t.pelanggan_id AND DATE_FORMAT(p2.waktu,'%Y-%m')=DATE_FORMAT(t.periode,'%Y-%m'))
    ) x WHERE x.pelanggan_id IN ($placeholders)$statusBayarSql ORDER BY x.periode, x.alamat, x.nama, x.pelanggan_id";
    $historyParams = $filterMode === 'tanggal_bayar'
        ? [$tanggalAwal, $tanggalAkhir, $tanggalAwal, $tanggalAkhir, ...$ids]
        : [$periodeAwal, $periodeAkhir, $periodeAwal, $periodeAkhir, ...$ids];
    $historyTypes = 'ssss' . str_repeat('i', count($ids));
    if ($statusBayarSql !== '') {
        $historyParams[] = $statusBayar;
        $historyTypes .= 's';
    }
    $historyStmt = $koneksi->prepare($sql);
    if (!$historyStmt) { http_response_code(500); exit('Query riwayat transaksi gagal disiapkan.'); }
    arsipBind($historyStmt, $historyTypes, $historyParams);
    $historyStmt->execute();
    $historyResult = $historyStmt->get_result();
    while ($row = $historyResult->fetch_assoc()) $transaksi[] = $row;
    $historyStmt->close();
}

if ($filterMode === 'tanggal_bayar') {
    // Pada mode tanggal bayar, sheet Data Pelanggan hanya memuat pelanggan
    // yang memang memiliki transaksi dalam rentang tanggal tersebut.
    $paidCustomerIds = [];
    foreach ($transaksi as $transactionRow) {
        $paidCustomerIds[(int) ($transactionRow['pelanggan_id'] ?? 0)] = true;
    }
    $pelanggan = array_values(array_filter($pelanggan, static function (array $row) use ($paidCustomerIds): bool {
        return isset($paidCustomerIds[(int) ($row['id'] ?? 0)]);
    }));
}

$stamp = date('Ymd_His');
try {
    if ($format === 'excel') {
        $xlsx = arsipBuatXlsx($pelanggan, $transaksi);
        arsipKirimFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'data_pelanggan_lengkap_' . $stamp . '.xlsx');
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Export gagal: ' . $e->getMessage());
}

$transactionByCustomer = [];
foreach ($transaksi as $row) $transactionByCustomer[(int) $row['pelanggan_id']][] = $row;
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Pelanggan Lengkap</title>
    <style>
        body{font-family:Arial,sans-serif;color:#172033;margin:18px;background:#f4f7fa}.toolbar{margin-bottom:14px}.toolbar button{background:#8e44ad;color:#fff;border:0;border-radius:7px;padding:10px 15px;font-weight:bold}.title{font-size:21px;font-weight:700;color:#173b5e}.subtitle{font-size:12px;color:#64748b;margin:4px 0 14px}.customer{background:#fff;border:1px solid #ccd7e3;border-radius:9px;padding:11px;margin-bottom:12px;break-inside:avoid}.info{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:5px 10px;font-size:10px}.item b{display:block;color:#526277;margin-bottom:2px}.history{width:100%;border-collapse:collapse;margin-top:10px;font-size:9px}.history th,.history td{border:1px solid #bdcad8;padding:4px}.history th{background:#2f80b7;color:#fff}.right{text-align:right}.empty{text-align:center;color:#718096}.privacy{font-size:10px;color:#9a3412;margin-bottom:10px}@media(max-width:700px){body{margin:8px}.info{grid-template-columns:repeat(2,minmax(0,1fr))}.history{display:block;overflow-x:auto;white-space:nowrap}}@media print{@page{size:A4 landscape;margin:7mm}body{margin:0;background:#fff}.toolbar{display:none}.customer{border-radius:0;page-break-inside:avoid}.info{grid-template-columns:repeat(4,minmax(0,1fr))}}
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<div class="title">Arsip Data Pelanggan Lengkap</div>
<div class="subtitle">Data pelanggan sesuai filter • <?= $filterMode === 'tanggal_bayar' ? 'Tanggal bayar ' . arsipHtml($tanggalAwal) . ' sampai ' . arsipHtml($tanggalAkhir) : 'Riwayat transaksi ' . arsipHtml($periodeAwal) . ' sampai ' . arsipHtml($periodeAkhir) ?> • Dibuat <?= arsipHtml(date('d-m-Y H:i:s')) ?> WIB</div>
<div class="privacy">Dokumen ini memuat NIK, nomor WhatsApp, dan koordinat. Simpan hanya pada perangkat yang aman.</div>
<?php if (!$pelanggan): ?><div class="customer empty">Tidak ada data pelanggan sesuai filter.</div><?php endif; ?>
<?php foreach ($pelanggan as $p): ?>
<section class="customer">
    <div class="info">
            <div class="item"><b>ID Pelanggan</b><?= arsipHtml($p['id_pelanggan'] ?: '-') ?></div>
            <div class="item"><b>Kode Pelanggan</b><?= arsipHtml($p['kode_pelanggan'] ?: '-') ?></div>
            <div class="item"><b>Nama Pelanggan</b><?= arsipHtml($p['nama'] ?: '-') ?></div>
            <div class="item"><b>Nama KTP</b><?= arsipHtml($p['nama_ktp'] ?: '-') ?></div>
            <div class="item"><b>NIK</b><?= arsipHtml($p['nik'] ?: '-') ?></div>
            <div class="item"><b>Nomor WhatsApp</b><?= arsipHtml($p['nomor_pelanggan'] ?: '-') ?></div>
            <div class="item"><b>Alamat/Wilayah</b><?= arsipHtml($p['alamat'] ?: '-') ?></div>
            <div class="item"><b>Paket</b><?= arsipHtml($p['paket'] ?: '-') ?></div>
            <div class="item"><b>Tarif</b><?= arsipHtml(salamRupiah($p['tarif_langganan'] ?? 0)) ?></div>
            <div class="item"><b>Status Pelanggan</b><?= arsipHtml($p['status_pelanggan'] ?: '-') ?></div>
            <div class="item"><b>Tanggal Daftar</b><?= arsipHtml($p['tanggal_daftar'] ?: '-') ?></div>
            <div class="item"><b>Koordinat X / Longitude</b><?= arsipHtml($p['koordinat_x'] ?? '-') ?></div>
            <div class="item"><b>Koordinat Y / Latitude</b><?= arsipHtml($p['koordinat_y'] ?? '-') ?></div>
    </div>
    <table class="history"><thead><tr><th>Periode</th><th>Tagihan</th><th>Status</th><th>Jatuh Tempo</th><th>Tanggal Bayar</th><th>Dibayar</th><th>Invoice</th></tr></thead><tbody>
    <?php $items = $transactionByCustomer[(int) $p['id']] ?? []; if (!$items): ?><tr><td colspan="7" class="empty">Tidak ada transaksi pada periode yang dipilih.</td></tr><?php else: foreach ($items as $t): ?>
        <tr><td><?= arsipHtml($t['periode']) ?></td><td class="right"><?= arsipHtml(salamRupiah($t['nominal_tagihan'] ?? 0)) ?></td><td><?= arsipHtml($t['status_bayar']) ?></td><td><?= arsipHtml($t['tanggal_jatuh_tempo']) ?></td><td><?= arsipHtml($t['tanggal_bayar'] ?: '-') ?></td><td class="right"><?= arsipHtml(salamRupiah($t['nominal_dibayar'] ?? 0)) ?></td><td><?= arsipHtml($t['nomor_invoice'] ?: '-') ?></td></tr>
    <?php endforeach; endif; ?></tbody></table>
</section>
<?php endforeach; ?>
<script>window.addEventListener('load',function(){setTimeout(function(){window.print()},500)});</script>
</body></html>
