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

$bulanOptions = [
    '01' => 'Januari',
    '02' => 'Februari',
    '03' => 'Maret',
    '04' => 'April',
    '05' => 'Mei',
    '06' => 'Juni',
    '07' => 'Juli',
    '08' => 'Agustus',
    '09' => 'September',
    '10' => 'Oktober',
    '11' => 'November',
    '12' => 'Desember',
];

$rawBulan = trim($_GET['bulan'] ?? '');
$rawTahun = trim($_GET['tahun'] ?? '');
if (preg_match('/^(0?[1-9]|1[0-2])$/', $rawBulan) && preg_match('/^\d{4}$/', $rawTahun)) {
    $periode = sprintf('%04d-%02d', (int) $rawTahun, (int) $rawBulan);
} else {
    $periode = trim($_GET['periode'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $periode)) {
        $periode = date('Y-m');
    }
}
$selectedTahun = (int) substr($periode, 0, 4);
$selectedBulan = substr($periode, 5, 2);
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

$currentYear = (int) date('Y');
$minYear = min($currentYear - 1, $selectedTahun);
$maxYear = max($currentYear + 1, $selectedTahun);
$yearResult = $koneksi->query("
    SELECT MIN(tahun) AS min_year, MAX(tahun) AS max_year
    FROM (
        SELECT YEAR(waktu) AS tahun
        FROM pelanggan_salam
        WHERE waktu IS NOT NULL

        UNION ALL

        SELECT YEAR(periode) AS tahun
        FROM tagihan_salam
        WHERE periode IS NOT NULL
    ) AS daftar_tahun
");
if ($yearResult instanceof mysqli_result) {
    $yearRow = $yearResult->fetch_assoc();
    if (!empty($yearRow['min_year'])) $minYear = min($minYear, (int) $yearRow['min_year']);
    if (!empty($yearRow['max_year'])) $maxYear = max($maxYear, (int) $yearRow['max_year']);
}
$yearOptions = range($maxYear, $minYear);

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

$totalPelanggan = count($rows);
$totalAktif = 0;
$totalTidakAktif = 0;
$totalLunas = 0;
$totalBelumLunas = 0;
$totalTagihan = 0.0;
$totalTarif = 0.0;
$totalDibayar = 0.0;

foreach ($rows as $row) {
    if (($row['status_pelanggan'] ?? '') === 'Aktif') $totalAktif++;
    if (($row['status_pelanggan'] ?? '') === 'Tidak Aktif') $totalTidakAktif++;
    if (($row['status_bayar'] ?? '') === 'Lunas') $totalLunas++;
    if (($row['status_bayar'] ?? '') === 'Belum Lunas') $totalBelumLunas++;
    $totalTagihan += (float) ($row['tagihan'] ?? 0);
    $totalTarif += (float) ($row['tarif_langganan'] ?? 0);
    $totalDibayar += (float) ($row['nominal_dibayar'] ?? 0);
}


$periodeLabel = salamBulananIndonesia($periode . '-01', false);

$allowedPerPages = [10, 25, 50, 100];
$perPageRaw = $_GET['per_page'] ?? '10';
if ($perPageRaw === 'all') {
    $perPage = 'all';
} else {
    $perPage = (int) $perPageRaw;
    if (!in_array($perPage, $allowedPerPages, true)) {
        $perPage = 10;
    }
}
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalRows = count($rows);

if ($perPage === 'all') {
    $totalPages = 1;
    $currentPage = 1;
    $pagedRows = $rows;
    $startNo = 1;
    $showingFrom = $totalRows > 0 ? 1 : 0;
    $showingTo = $totalRows;
} else {
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;
    $pagedRows = array_slice($rows, $offset, $perPage);
    $startNo = $offset + 1;
    $showingFrom = $totalRows > 0 ? $offset + 1 : 0;
    $showingTo = min($offset + $perPage, $totalRows);
}

$filterParams = [
    'periode' => $periode,
    'bulan' => $selectedBulan,
    'tahun' => $selectedTahun,
    'status_bayar' => $statusBayar,
    'status_pelanggan' => $statusPelanggan,
    'alamat' => $alamatFilter,
    'search' => $search,
    'per_page' => $perPage,
];
$pageUrl = function (int $page) use ($filterParams): string {
    return 'laporan_salam.php?' . http_build_query(array_merge($filterParams, ['page' => $page]));
};

$exportQuery = http_build_query([
    'periode' => $periode,
    'bulan' => $selectedBulan,
    'tahun' => $selectedTahun,
    'status_bayar' => $statusBayar,
    'status_pelanggan' => $statusPelanggan,
    'alamat' => $alamatFilter,
    'search' => $search,
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Billing Semua Wilayah / UKOOMED</title>
    <link rel="icon" href="logo_cleon.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary:#3498db;
            --primary-dark:#2980b9;
            --secondary:#2c3e50;
            --dark:#1f2937;
            --success:#27ae60;
            --danger:#e74c3c;
            --warning:#f39c12;
            --purple:#8e44ad;
            --light:#f5f7f9;
            --light-gray:#e8edf3;
            --gray:#718096;
            --text:#2d3748;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--light);
            color: var(--text);
        }
        .header {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2530 100%);
            color: #fff;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header h1 {
            margin: 0;
            font-size: 23px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .nav-btn {
            border: 0;
            text-decoration: none;
            color: #fff;
            padding: 9px 14px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-dashboard { background: var(--primary); }
        .btn-logout { background: var(--danger); }
        .container {
            padding: 26px 30px 34px;
            max-width: none;
            width: 100%;
            margin: 0 auto;
        }
        .page-title {
            margin-bottom: 14px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }
        .page-title h2 {
            margin: 0;
            color: var(--secondary);
            font-size: 24px;
            line-height: 1.25;
        }
        .page-title p {
            margin: 5px 0 0;
            color: var(--gray);
            font-weight: 500;
            font-size: 13px;
        }

        /* Filter laporan dibuat compact seperti dashboard, bukan kotak besar */
        .filter-panel {
            margin: 0 0 18px;
            padding: 0;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1.25fr .95fr .95fr 1fr 1.2fr auto;
            gap: 11px;
            align-items: end;
        }
        .period-selects {
            display: grid;
            grid-template-columns: 1.2fr .8fr;
            gap: 8px;
        }
        label {
            display: block;
            margin-bottom: 6px;
            color: #516274;
            font-size: 12px;
            font-weight: 700;
        }
        input,
        select {
            width: 100%;
            border: 1px solid var(--light-gray);
            border-radius: 9px;
            padding: 10px 12px;
            background: #fff;
            font-size: 13px;
            outline: none;
            min-height: 40px;
            box-shadow: none;
        }
        input:focus,
        select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52,152,219,.12);
        }
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            white-space: nowrap;
        }
        .btn,
        button.btn {
            border: 0;
            border-radius: 7px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            font-size: 13px;
            min-height: 40px;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-muted { background: #edf2f7; color: #334155; }
        .btn-excel { background: var(--success); color: #fff; }
        .btn-pdf { background: var(--purple); color: #fff; }

        /* Ringkasan dibuat satu baris seperti dashboard, compact dan bersih */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            padding: 18px 18px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,.05);
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 90px;
        }
        .stat-card .icon {
            width: 52px;
            height: 52px;
            font-size: 23px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex: 0 0 52px;
        }
        .icon.bg-primary { background: var(--primary); }
        .icon.bg-success { background: var(--success); }
        .icon.bg-danger { background: var(--danger); }
        .icon.bg-warning { background: var(--warning); }
        .icon.bg-purple { background: var(--purple); }
        .stat-card h3 {
            font-size: 22px;
            margin: 0;
            color: var(--secondary);
            line-height: 1.2;
            white-space: normal;
            word-break: break-word;
        }
        .stat-card p {
            color: var(--gray);
            font-weight: 500;
            margin: 3px 0 0;
            font-size: 13px;
        }

        .table-section {
            background: transparent;
            border-radius: 0;
            overflow: visible;
            box-shadow: none;
            margin-bottom: 30px;
        }
        .table-header {
            padding: 0 0 14px;
            border-bottom: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
        }
        .table-header h2 {
            margin: 0;
            font-size: 20px;
            color: var(--secondary);
        }
        .table-header .muted {
            margin: 5px 0 0;
            color: var(--gray);
            font-size: 13px;
        }
        .table-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .rows-control {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .rows-control label {
            margin: 0;
            white-space: nowrap;
        }
        .rows-control select {
            border-radius: 7px;
            min-height: 40px;
            width: auto;
            min-width: 86px;
            padding: 9px 12px;
        }
        .table-wrap {
            width: 100%;
            overflow-x: hidden;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .modern-table {
            width: 100%;
            min-width: 0 !important;
        }

        /*
           Perbaikan khusus tabel laporan:
           - tidak ada scroll kanan/bawah;
           - header tidak saling tabrakan;
           - nominal tidak turun ke bawah;
           - kolom tetap mengikuti gaya Dashboard Salam.
        */
        .modern-table thead th {
            background: var(--primary);
            color: white;
            font-weight: 800;
            padding: 10px 6px;
            text-align: left;
            text-transform: uppercase;
            font-size: 9.6px;
            line-height: 1.15;
            letter-spacing: 0;
            border-right: 1px solid rgba(255,255,255,.30);
            border-bottom: 2px solid var(--primary-dark);
            white-space: normal;
            word-break: normal;
            overflow-wrap: normal;
            vertical-align: middle;
        }
        .modern-table thead th:first-child { border-top-left-radius: 7px; text-align:center; }
        .modern-table thead th:last-child { border-right: 0; border-top-right-radius: 7px; }

        .modern-table col.col-no { width: 2.6%; }
        .modern-table col.col-id { width: 7.2%; }
        .modern-table col.col-kode { width: 6.5%; }
        .modern-table col.col-nama { width: 10.4%; }
        .modern-table col.col-alamat { width: 8%; }
        .modern-table col.col-wa { width: 8.6%; }
        .modern-table col.col-paket { width: 7%; }
        .modern-table col.col-periode { width: 5.7%; }
        .modern-table col.col-masa { width: 6.8%; }
        .modern-table col.col-tarif { width: 7.7%; }
        .modern-table col.col-status-bayar { width: 7.2%; }
        .modern-table col.col-status-pelanggan { width: 7.7%; }
        .modern-table col.col-tanggal { width: 6.6%; }
        .modern-table col.col-dibayar { width: 8%; }

        .modern-table td {
            padding: 12px 6px;
            border-bottom: 1px solid var(--light-gray);
            vertical-align: middle;
            color: #333;
            font-size: 10.8px;
            line-height: 1.25;
            word-break: normal;
            overflow-wrap: normal;
        }

        .modern-table td:nth-child(1) { text-align:center; }
        .modern-table td:nth-child(2),
        .modern-table td:nth-child(6),
        .modern-table td:nth-child(10),
        .modern-table td:nth-child(14) {
            white-space: nowrap;
        }

        .modern-table td:nth-child(10),
        .modern-table td:nth-child(14) {
            text-align: right;
            font-size: 11.2px;
        }

        .modern-table td:nth-child(11),
        .modern-table td:nth-child(12) {
            text-align: left;
        }

        .modern-table tbody tr:nth-child(even) td { background: #fbfdff; }
        .modern-table tbody tr:hover td { background: #f3f8fe; }

        .id-pill {
            display: inline-block;
            padding: 5px 7px;
            background: var(--light-gray);
            border-radius: 15px;
            color: var(--secondary);
            font-weight: 700;
            font-size: 10px;
            white-space: nowrap;
        }

        .name-cell { font-weight: 700; color: var(--dark); }
        .money {
            display: inline-block;
            font-weight: 800;
            white-space: nowrap !important;
            word-break: keep-all !important;
            overflow-wrap: normal !important;
            min-width: max-content;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 8px;
            border-radius: 15px;
            font-size: 9.5px;
            line-height: 1.1;
            font-weight: 700;
            color: white;
            white-space: nowrap;
            text-align: center;
            min-width: 0;
            max-width: 100%;
        }
        .badge-success { background: var(--success); }
        .badge-danger { background: var(--danger); }
        .badge-muted { background: #7f8c8d; }
        .empty-state {
            text-align: center;
            padding: 34px 20px !important;
            color: var(--gray);
            font-weight: 600;
        }

        /* Tampilan HP laporan dibuat seperti dashboard: clean, rapi, dan mudah dibaca */
        .mobile-report-list { display: none; }
        .report-card {
            position: relative;
            background: #ffffff;
            border: 1px solid #e4e9f0;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(31, 51, 74, .07);
            transition: transform .16s ease, box-shadow .16s ease;
        }
        .report-card::before { display: none; }
        .report-card:active {
            transform: scale(.992);
            box-shadow: 0 5px 14px rgba(44, 62, 80, .10);
        }
        .report-card + .report-card { margin-top: 13px; }
        .report-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 16px 12px;
            background: #ffffff;
            border-bottom: 1px solid #edf1f5;
        }
        .report-card-name {
            font-size: 15.5px;
            font-weight: 650;
            color: #223247;
            line-height: 1.28;
            letter-spacing: -.05px;
        }
        .report-card-id {
            display: inline-flex;
            align-items: center;
            margin-top: 7px;
            color: #596b7e;
            background: #f1f4f7;
            border: 1px solid #e0e6ed;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11.5px;
            font-weight: 600;
            line-height: 1.2;
        }
        .report-card-status {
            display: flex;
            flex-direction: column;
            gap: 7px;
            align-items: flex-end;
            flex: 0 0 auto;
        }
        .report-card-body {
            padding: 0;
            background: #ffffff;
        }
        .report-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 13px 16px;
            border-bottom: 1px solid #eef1f4;
        }
        .report-row:last-child { border-bottom: 0; }
        .report-row:nth-child(even) { background: #ffffff; }
        .report-row.money-row {
            background: #ffffff;
        }
        .report-info-label {
            color: #314258;
            font-size: 12.5px;
            font-weight: 600;
            text-transform: none;
            letter-spacing: 0;
            line-height: 1.35;
            flex: 0 0 44%;
        }
        .report-info-value {
            color: #263241;
            font-size: 13.2px;
            font-weight: 400;
            line-height: 1.35;
            text-align: right;
            max-width: 56%;
            word-break: break-word;
        }
        .report-info-value.money {
            color: #1f2937;
            font-size: 13.6px;
            font-weight: 700;
            white-space: nowrap !important;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 24px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .pagination a,
        .pagination span {
            min-width: 42px;
            height: 42px;
            padding: 0 13px;
            background-color: white;
            color: var(--dark);
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            cursor: pointer;
            transition: all .2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-weight: 700;
        }
        .pagination a:hover {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination .active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .pagination .disabled {
            opacity: .55;
            cursor: not-allowed;
        }
        @media (max-width: 1280px) {
            .container { padding-left: 18px; padding-right: 18px; }
            .modern-table thead th { font-size: 8.5px; padding: 9px 3px; }
            .modern-table td { font-size: 9.8px; padding: 10px 3px; }
            .modern-table td:nth-child(10),
            .modern-table td:nth-child(14) { font-size: 9.8px; }
            .badge { font-size: 8.2px; padding: 4px 5px; }
            .id-pill { font-size: 9px; padding: 4px 5px; }
        }
        @media (max-width: 1100px) {
            .filter-grid { grid-template-columns: repeat(3, minmax(160px, 1fr)); }
            .filter-actions { align-self: stretch; }
            .filter-actions .btn { flex: 1; }
            .stats-cards { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .modern-table thead th { font-size: 8px; padding: 8px 2px; }
            .modern-table td { font-size: 9px; padding: 9px 2px; }
            .modern-table td:nth-child(10),
            .modern-table td:nth-child(14) { font-size: 9px; }
            .badge { font-size: 7.8px; padding: 4px 4px; }
            .id-pill { font-size: 8.4px; padding: 4px 4px; }
        }
        @media (max-width: 700px) {
            .header { padding: 13px 14px; flex-wrap: wrap; }
            .header h1 { font-size: 17px; }
            .header-actions { width: 100%; justify-content: flex-end; }
            .container { padding: 16px 12px; }
            .page-title h2 { font-size: 21px; }
            .filter-grid { grid-template-columns: 1fr; gap: 10px; }
            .period-selects { grid-template-columns: 1fr 1fr; }
            input, select { min-height: 44px; border-radius: 12px; }
            .filter-actions { width: 100%; }
            .filter-actions .btn { flex: 1; }
            .stats-cards { grid-template-columns: 1fr; gap: 10px; margin-bottom: 20px; }
            .stat-card { min-height: 74px; padding: 14px; }
            .stat-card .icon { width: 46px; height: 46px; flex-basis: 46px; font-size: 20px; }
            .stat-card h3 { font-size: 20px; }
            .table-header { align-items: stretch; gap: 12px; }
            .table-header h2 { font-size: 18px; }
            .table-actions { justify-content: stretch; width: 100%; gap: 8px; }
            .table-actions .btn { flex: 1 1 calc(50% - 8px); padding-left: 9px; padding-right: 9px; }
            .rows-control { flex: 1 1 100%; display: grid; grid-template-columns: auto 1fr; align-items: center; }
            .rows-control select { width: 100%; }
            .table-wrap { display: none; }
            .mobile-report-list { display: block; }
            .page-title p { font-size: 12.5px; line-height: 1.45; }
            label { color: #31485f; font-size: 12.5px; }
            input, select {
                border-color: #d8e5f1;
                background-color: #fff;
                color: #1f2937;
                font-weight: 650;
                box-shadow: 0 4px 12px rgba(44,62,80,.05);
            }
            input::placeholder { color: #9aa8b6; font-weight: 500; }
            .filter-actions .btn,
            .table-actions .btn {
                border-radius: 12px;
                min-height: 46px;
                font-size: 13.2px;
                box-shadow: 0 6px 15px rgba(44,62,80,.08);
            }
            .stat-card {
                border: 1px solid #e4edf7;
                box-shadow: 0 8px 20px rgba(44,62,80,.07);
            }
            .stat-card p { color: #63748a; }
            .badge {
                font-size: 10px;
                padding: 5px 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,.08);
            }
            .pagination { gap: 7px; margin-top: 18px; }
            .pagination a, .pagination span { min-width: 38px; height: 38px; }
        }

        @media (max-width: 700px) {
            .report-card { border-radius: 14px; box-shadow: 0 5px 14px rgba(31, 51, 74, .07); }
            .report-card-name { font-weight: 650; }
            .report-card-head .badge { font-weight: 600; }
            .report-info-label { font-weight: 600; color: #314258; }
            .report-info-value { font-weight: 400; color: #263241; }
            .report-info-value.money { font-weight: 700; }
        }

        @media (max-width: 380px) {
            .report-card { border-radius: 14px; }
            .report-card-head { padding: 16px 14px 12px; }
            .report-row { padding: 11px 14px; gap: 10px; }
            .report-info-label { flex-basis: 43%; font-size: 11px; }
            .report-info-value { max-width: 57%; font-size: 13px; }
            .table-actions .btn { flex-basis: 100%; }
            .period-selects { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-file-invoice-dollar"></i> Laporan Billing Semua Wilayah</h1>
        <div class="header-actions">
            <a href="dashboard_salam.php" class="nav-btn btn-dashboard"><i class="fas fa-arrow-left"></i> Dashboard</a>
            <a href="logout.php" class="nav-btn btn-logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="page-title">
            <div>
                <h2>Laporan Billing Semua Wilayah / UKOOMED</h2>
                
            </div>
        </div>

        <div class="filter-panel">
            <form method="get" id="filterForm">
                <input type="hidden" name="page" value="1">
                <div class="filter-grid">
                    <div>
                        <label>Periode Bulan</label>
                        <div class="period-selects">
                            <select id="bulan" name="bulan" aria-label="Pilih bulan laporan">
                                <?php foreach ($bulanOptions as $bulanValue => $bulanName): ?>
                                    <option value="<?= htmlspecialchars($bulanValue); ?>" <?= $selectedBulan === $bulanValue ? 'selected' : ''; ?>><?= htmlspecialchars($bulanName); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="tahun" name="tahun" aria-label="Pilih tahun laporan">
                                <?php foreach ($yearOptions as $tahunOption): ?>
                                    <option value="<?= (int) $tahunOption; ?>" <?= $selectedTahun === (int) $tahunOption ? 'selected' : ''; ?>><?= (int) $tahunOption; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="status_bayar">Status Bayar</label>
                        <select id="status_bayar" name="status_bayar">
                            <option value="all" <?= $statusBayar === 'all' ? 'selected' : ''; ?>>Semua</option>
                            <option value="Lunas" <?= $statusBayar === 'Lunas' ? 'selected' : ''; ?>>Lunas</option>
                            <option value="Belum Lunas" <?= $statusBayar === 'Belum Lunas' ? 'selected' : ''; ?>>Belum Lunas</option>
                        </select>
                    </div>
                    <div>
                        <label for="status_pelanggan">Status Pelanggan</label>
                        <select id="status_pelanggan" name="status_pelanggan">
                            <option value="all" <?= $statusPelanggan === 'all' ? 'selected' : ''; ?>>Semua</option>
                            <option value="Aktif" <?= $statusPelanggan === 'Aktif' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="Tidak Aktif" <?= $statusPelanggan === 'Tidak Aktif' ? 'selected' : ''; ?>>Tidak Aktif</option>
                        </select>
                    </div>
                    <div>
                        <label for="alamat">Alamat</label>
                        <select id="alamat" name="alamat">
                            <option value="all" <?= $alamatFilter === 'all' ? 'selected' : ''; ?>>Semua alamat</option>
                            <?php foreach ($alamatOptions as $alamatOption): ?>
                                <option value="<?= htmlspecialchars($alamatOption); ?>" <?= $alamatFilter === $alamatOption ? 'selected' : ''; ?>><?= htmlspecialchars($alamatOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="search">Cari Pelanggan</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search); ?>" placeholder="Nama / ID / alamat / paket">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Tampilkan</button>
                        <a class="btn btn-muted" href="laporan_salam.php"><i class="fas fa-sync-alt"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="stats-cards">
            <div class="stat-card"><div class="icon bg-primary"><i class="fas fa-users"></i></div><div><h3><?= $totalPelanggan; ?></h3><p>Total data</p></div></div>
            <div class="stat-card"><div class="icon bg-success"><i class="fas fa-check-circle"></i></div><div><h3><?= $totalLunas; ?></h3><p>Lunas</p></div></div>
            <div class="stat-card"><div class="icon bg-danger"><i class="fas fa-clock"></i></div><div><h3><?= $totalBelumLunas; ?></h3><p>Belum lunas</p></div></div>
            <div class="stat-card"><div class="icon bg-purple"><i class="fas fa-money-bill-wave"></i></div><div><h3><?= salamRupiah($totalTagihan); ?></h3><p>Total tagihan berjalan</p></div></div>
            <div class="stat-card"><div class="icon bg-warning"><i class="fas fa-receipt"></i></div><div><h3><?= salamRupiah($totalDibayar); ?></h3><p>Total nominal dibayar</p></div></div>
        </div>

        <div class="table-section">
            <div class="table-header">
                <div>
                    <h2><i class="fas fa-list-ul"></i> Detail Laporan <?= htmlspecialchars($periodeLabel); ?></h2>
                </div>
                <div class="table-actions">
                    <form method="get" class="rows-control">
                        <input type="hidden" name="periode" value="<?= htmlspecialchars($periode); ?>">
                        <input type="hidden" name="bulan" value="<?= htmlspecialchars($selectedBulan); ?>">
                        <input type="hidden" name="tahun" value="<?= htmlspecialchars((string) $selectedTahun); ?>">
                        <input type="hidden" name="status_bayar" value="<?= htmlspecialchars($statusBayar); ?>">
                        <input type="hidden" name="status_pelanggan" value="<?= htmlspecialchars($statusPelanggan); ?>">
                        <input type="hidden" name="alamat" value="<?= htmlspecialchars($alamatFilter); ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
                        <input type="hidden" name="page" value="1">
                        <label for="per_page">Tampilkan</label>
                        <select id="per_page" name="per_page" onchange="this.form.submit()">
                            <option value="10" <?= $perPage === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?= $perPage === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?= $perPage === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?= $perPage === 100 ? 'selected' : ''; ?>>100</option>
                            <option value="all" <?= $perPage === 'all' ? 'selected' : ''; ?>>Semua</option>
                        </select>
                    </form>
                    <a class="btn btn-excel" href="export_laporan_salam.php?format=excel&<?= htmlspecialchars($exportQuery); ?>"><i class="fas fa-file-excel"></i> Export Excel</a>
                    <a class="btn btn-pdf" target="_blank" href="export_laporan_salam.php?format=pdf&<?= htmlspecialchars($exportQuery); ?>"><i class="fas fa-file-pdf"></i> Export PDF</a>
                </div>
            </div>

            <div class="table-wrap">
                <table class="modern-table">
                    <colgroup>
                        <col class="col-no">
                        <col class="col-id">
                        <col class="col-kode">
                        <col class="col-nama">
                        <col class="col-alamat">
                        <col class="col-wa">
                        <col class="col-paket">
                        <col class="col-periode">
                        <col class="col-masa">
                        <col class="col-tarif">
                        <col class="col-status-bayar">
                        <col class="col-status-pelanggan">
                        <col class="col-tanggal">
                        <col class="col-dibayar">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>ID<br>Pelanggan</th>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Alamat</th>
                            <th>No.<br>WhatsApp</th>
                            <th>Paket</th>
                            <th>Periode</th>
                            <th>Masa<br>Aktif</th>
                            <th>Tarif</th>
                            <th>Status<br>Bayar</th>
                            <th>Status<br>Pelanggan</th>
                            <th>Tanggal<br>Bayar</th>
                            <th>Nominal<br>Dibayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$pagedRows): ?>
                            <tr><td colspan="14" class="empty-state">Tidak ada data untuk filter ini.</td></tr>
                        <?php else: ?>
                            <?php $no = $startNo; foreach ($pagedRows as $row): ?>
                                <tr>
                                    <td><?= $no++; ?></td>
                                    <td><span class="id-pill"><?= htmlspecialchars($row['id_pelanggan'] ?: '-'); ?></span></td>
                                    <td><?= htmlspecialchars($row['kode_pelanggan'] ?: '-'); ?></td>
                                    <td><span class="name-cell"><?= htmlspecialchars($row['nama']); ?></span></td>
                                    <td><?= htmlspecialchars($row['alamat'] ?: '-'); ?></td>
                                    <td><?= htmlspecialchars($row['nomor_pelanggan'] ?: '-'); ?></td>
                                    <td><?= htmlspecialchars($row['paket']); ?></td>
                                    <td><?= htmlspecialchars(salamBulananIndonesia($row['waktu'] ?? null, false)); ?></td>
                                    <td><?= htmlspecialchars(salamBulananIndonesia($row['langganan_selesai'] ?? null, true)); ?></td>
                                    <td><span class="money"><?= htmlspecialchars(salamRupiah($row['tarif_langganan'] ?? 0)); ?></span></td>
                                    <td><span class="badge <?= ($row['status_bayar'] ?? '') === 'Lunas' ? 'badge-success' : 'badge-danger'; ?>"><?= htmlspecialchars($row['status_bayar'] ?? '-'); ?></span></td>
                                    <td><span class="badge <?= ($row['status_pelanggan'] ?? '') === 'Aktif' ? 'badge-success' : 'badge-muted'; ?>"><?= htmlspecialchars($row['status_pelanggan'] ?? '-'); ?></span></td>
                                    <td><?= htmlspecialchars(salamBulananIndonesia($row['tanggal_bayar'] ?? null, true)); ?></td>
                                    <td><span class="money"><?= htmlspecialchars(salamRupiah($row['nominal_dibayar'] ?? 0)); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mobile-report-list">
                <?php if (!$pagedRows): ?>
                    <div class="report-card empty-state">Tidak ada data untuk filter ini.</div>
                <?php else: ?>
                    <?php $mobileNo = $startNo; foreach ($pagedRows as $row): ?>
                        <div class="report-card">
                            <div class="report-card-head">
                                <div>
                                    <div class="report-card-name"><?= $mobileNo++; ?>. <?= htmlspecialchars($row['nama']); ?></div>
                                    <span class="report-card-id"><?= htmlspecialchars($row['id_pelanggan'] ?: '-'); ?> / <?= htmlspecialchars($row['kode_pelanggan'] ?: '-'); ?></span>
                                </div>
                                <div class="report-card-status">
                                    <span class="badge <?= ($row['status_bayar'] ?? '') === 'Lunas' ? 'badge-success' : 'badge-danger'; ?>"><?= htmlspecialchars($row['status_bayar'] ?? '-'); ?></span>
                                    <span class="badge <?= ($row['status_pelanggan'] ?? '') === 'Aktif' ? 'badge-success' : 'badge-muted'; ?>"><?= htmlspecialchars($row['status_pelanggan'] ?? '-'); ?></span>
                                </div>
                            </div>
                            <div class="report-card-body">
                                <div class="report-row"><span class="report-info-label">Alamat</span><span class="report-info-value"><?= htmlspecialchars($row['alamat'] ?: '-'); ?></span></div>
                                <div class="report-row"><span class="report-info-label">No. WhatsApp</span><span class="report-info-value"><?= htmlspecialchars($row['nomor_pelanggan'] ?: '-'); ?></span></div>
                                <div class="report-row"><span class="report-info-label">Paket</span><span class="report-info-value"><?= htmlspecialchars($row['paket']); ?></span></div>
                                <div class="report-row"><span class="report-info-label">Periode</span><span class="report-info-value"><?= htmlspecialchars(salamBulananIndonesia($row['waktu'] ?? null, false)); ?></span></div>
                                <div class="report-row"><span class="report-info-label">Masa Aktif</span><span class="report-info-value"><?= htmlspecialchars(salamBulananIndonesia($row['langganan_selesai'] ?? null, true)); ?></span></div>
                                <div class="report-row"><span class="report-info-label">Tanggal Bayar</span><span class="report-info-value"><?= htmlspecialchars(salamBulananIndonesia($row['tanggal_bayar'] ?? null, true)); ?></span></div>
                                <div class="report-row money-row"><span class="report-info-label">Tarif</span><span class="report-info-value money"><?= htmlspecialchars(salamRupiah($row['tarif_langganan'] ?? 0)); ?></span></div>
                                <div class="report-row money-row"><span class="report-info-label">Nominal Dibayar</span><span class="report-info-value money"><?= htmlspecialchars(salamRupiah($row['nominal_dibayar'] ?? 0)); ?></span></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($perPage !== 'all' && $totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= htmlspecialchars($pageUrl($currentPage - 1)); ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $currentPage + 2);
                        if ($startPage > 1) {
                            echo '<a href="' . htmlspecialchars($pageUrl(1)) . '">1</a>';
                            if ($startPage > 2) echo '<span class="disabled">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++) {
                            if ($i === $currentPage) {
                                echo '<span class="active">' . $i . '</span>';
                            } else {
                                echo '<a href="' . htmlspecialchars($pageUrl($i)) . '">' . $i . '</a>';
                            }
                        }
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span class="disabled">...</span>';
                            echo '<a href="' . htmlspecialchars($pageUrl($totalPages)) . '">' . $totalPages . '</a>';
                        }
                    ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= htmlspecialchars($pageUrl($currentPage + 1)); ?>"><i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>